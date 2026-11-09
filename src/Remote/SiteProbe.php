<?php
/**
 * Requests to the site itself for the external checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches pages of the site as a visitor would see them. Redirects are followed manually and
 * only to hosts allowed by HostPolicy. Responses are cached for the current request.
 */
class SiteProbe {

	/**
	 * Responses of this request.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Host policy.
	 *
	 * @var HostPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param HostPolicy|null $policy Policy (null = current site).
	 */
	public function __construct( ?HostPolicy $policy = null ) {
		$this->policy = $policy ? $policy : HostPolicy::for_site();
	}

	/**
	 * Policy.
	 *
	 * @return HostPolicy
	 */
	public function policy(): HostPolicy {
		return $this->policy;
	}

	/**
	 * Requests a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $args follow (max redirects, default 0), max_bytes (default 65536).
	 * @return array{url:string,final_url:string,code:int,headers:array<string,string>,body:string,error:string,redirects:string[]}
	 */
	public function get( string $url, array $args = array() ): array {
		$args = array_merge(
			array(
				'follow'    => 0,
				'max_bytes' => 65536,
			),
			$args
		);
		$key  = md5( $url . '|' . (int) $args['follow'] . '|' . (int) $args['max_bytes'] );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$result  = array(
			'url'       => $url,
			'final_url' => $url,
			'code'      => 0,
			'headers'   => array(),
			'body'      => '',
			'error'     => '',
			'redirects' => array(),
		);
		$current = $url;
		for ( $hop = 0; $hop <= (int) $args['follow']; $hop++ ) {
			if ( ! $this->policy->is_allowed_url( $current ) ) {
				$result['error'] = __( 'The URL is not on this site\'s domain.', 'keep-it-peace' );
				break;
			}
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => self::timeout(),
					'redirection'         => 0,
					'limit_response_size' => (int) $args['max_bytes'],
					'user-agent'          => 'KeepItPeace/' . KEEPITPEACE_VERSION . ' (security self-check)',
					'headers'             => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				break;
			}
			$result['final_url'] = $current;
			$result['code']      = (int) wp_remote_retrieve_response_code( $response );
			$result['headers']   = self::headers( wp_remote_retrieve_headers( $response ) );
			$result['body']      = (string) wp_remote_retrieve_body( $response );
			$result['error']     = '';

			$location = $result['headers']['location'] ?? '';
			if ( $result['code'] < 300 || $result['code'] >= 400 || '' === $location || $hop >= (int) $args['follow'] ) {
				break;
			}
			$next = self::absolute( $location, $current );
			if ( '' === $next || ! $this->policy->is_allowed_url( $next ) ) {
				break;
			}
			$result['redirects'][] = $next;
			$current               = $next;
		}

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Timeout for one request in seconds.
	 *
	 * @return int
	 */
	public static function timeout(): int {
		/**
		 * Filters the timeout of requests made by the external checks.
		 *
		 * @param int $seconds Seconds.
		 */
		return max( 1, (int) apply_filters( 'keepitpeace_probe_timeout', 5 ) );
	}

	/**
	 * Clears the request cache (tests).
	 */
	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * Converts response headers to a lowercase array.
	 *
	 * @param mixed $headers Headers object or array.
	 * @return array<string,string>
	 */
	public static function headers( $headers ): array {
		$out = array();
		if ( is_array( $headers ) || $headers instanceof \Traversable ) {
			foreach ( $headers as $name => $value ) {
				$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Resolves a Location header against the current URL.
	 *
	 * @param string $location Location.
	 * @param string $base     Base URL.
	 * @return string
	 */
	public static function absolute( string $location, string $base ): string {
		$location = trim( $location );
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) {
			return $parts['scheme'] . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
		return $origin . $path . $location;
	}
}
