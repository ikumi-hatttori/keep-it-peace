<?php
/**
 * Outgoing HTTP for third-party APIs (switchable, cached).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_* with on/off switches, caching and a neutral User-Agent (no site URL).
 */
class HttpClient {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a service may be contacted.
	 *
	 * @param string $service Service ID.
	 * @return bool
	 */
	public function is_enabled( string $service ): bool {
		return $this->settings->is_remote_enabled( $service );
	}

	/**
	 * GET a JSON document.
	 *
	 * @param string $service Service ID (endoflife, phpnet, nvd, osv).
	 * @param string $url     URL.
	 * @param int    $ttl     Freshness in seconds.
	 * @return array{data:mixed,error:?string,cached:bool,stale:bool,disabled:bool}
	 */
	public function get_json( string $service, string $url, int $ttl ): array {
		return $this->request( $service, 'GET', $url, null, $ttl );
	}

	/**
	 * POST JSON and decode the JSON response.
	 *
	 * @param string $service Service ID.
	 * @param string $url     URL.
	 * @param array  $body    Body.
	 * @param int    $ttl     Freshness in seconds.
	 * @return array{data:mixed,error:?string,cached:bool,stale:bool,disabled:bool}
	 */
	public function post_json( string $service, string $url, array $body, int $ttl ): array {
		return $this->request( $service, 'POST', $url, $body, $ttl );
	}

	/**
	 * Performs a cached request.
	 *
	 * @param string     $service Service.
	 * @param string     $method  Method.
	 * @param string     $url     URL.
	 * @param array|null $body    Body.
	 * @param int        $ttl     TTL.
	 * @return array
	 */
	private function request( string $service, string $method, string $url, ?array $body, int $ttl ): array {
		$result  = array(
			'data'     => null,
			'error'    => null,
			'cached'   => false,
			'stale'    => false,
			'disabled' => false,
		);
		$encoded = null === $body ? '' : (string) wp_json_encode( $body );
		$key     = 'keepitpeace_rc_' . md5( $method . $url . $encoded );
		$cached  = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['t'] ) && time() - (int) $cached['t'] < $ttl ) {
			$result['data']   = $cached['d'];
			$result['cached'] = true;
			return $result;
		}
		if ( ! $this->is_enabled( $service ) ) {
			$result['disabled'] = true;
			return $result;
		}

		$args = array(
			'timeout'            => 10,
			'redirection'        => 2,
			'reject_unsafe_urls' => true,
			'user-agent'         => 'KeepItPeace/' . KEEPITPEACE_VERSION,
			'headers'            => array( 'Accept' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $encoded;
			$response                        = wp_safe_remote_post( $url, $args );
		} else {
			$response = wp_safe_remote_get( $url, $args );
		}

		$error = null;
		$data  = null;
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 ) {
				/* translators: %d: HTTP status code. */
				$error = sprintf( __( 'HTTP status %d', 'keep-it-peace' ), $code );
				$data  = null;
			} elseif ( null === $data ) {
				$error = __( 'Invalid JSON response', 'keep-it-peace' );
			}
		}

		if ( null === $error ) {
			set_transient(
				$key,
				array(
					't' => time(),
					'd' => $data,
				),
				max( $ttl * 4, WEEK_IN_SECONDS )
			);
			$result['data'] = $data;
			return $result;
		}

		$result['error'] = $error;
		if ( is_array( $cached ) && array_key_exists( 'd', $cached ) ) {
			$result['data']  = $cached['d'];
			$result['stale'] = true;
		}
		return $result;
	}
}
