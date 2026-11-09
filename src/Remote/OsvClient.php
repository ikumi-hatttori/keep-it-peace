<?php
/**
 * OSV.dev vulnerability database client.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Sends package names and versions only (https://google.github.io/osv.dev/api/).
 */
class OsvClient {

	const BATCH_URL  = 'https://api.osv.dev/v1/querybatch';
	const VULN_URL   = 'https://api.osv.dev/v1/vulns/';
	const BATCH_SIZE = 500;

	/**
	 * Vulnerability details fetched in this request (to stay polite with large lock files).
	 *
	 * @var int
	 */
	private static $details_fetched = 0;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http HTTP client.
	 */
	public function __construct( HttpClient $http ) {
		$this->http = $http;
	}

	/**
	 * Vulnerability IDs per package.
	 *
	 * @param array $packages Items with ecosystem, name, version.
	 * @return array{ids:array<int,string[]>,error:string,stale:bool} ids keyed by the package index.
	 */
	public function query( array $packages ): array {
		$out = array(
			'ids'   => array(),
			'error' => '',
			'stale' => false,
		);
		foreach ( array_chunk( $packages, self::BATCH_SIZE, true ) as $chunk ) {
			$queries = array();
			$indexes = array();
			foreach ( $chunk as $index => $package ) {
				$queries[] = array(
					'package' => array(
						'name'      => $package['name'],
						'ecosystem' => $package['ecosystem'],
					),
					'version' => $package['version'],
				);
				$indexes[] = $index;
			}
			$response = $this->http->post_json( 'osv', self::BATCH_URL, array( 'queries' => $queries ), DAY_IN_SECONDS );
			if ( null === $response['data'] ) {
				$out['error'] = (string) ( $response['error'] ?? '' );
				if ( '' === $out['error'] ) {
					$out['error'] = __( 'OSV.dev is disabled in the settings.', 'keep-it-peace' );
				}
				return $out;
			}
			if ( $response['stale'] ) {
				$out['stale'] = true;
			}
			foreach ( (array) ( $response['data']['results'] ?? array() ) as $position => $result ) {
				$ids = array();
				foreach ( (array) ( $result['vulns'] ?? array() ) as $vuln ) {
					if ( ! empty( $vuln['id'] ) ) {
						$ids[] = (string) $vuln['id'];
					}
				}
				if ( $ids && isset( $indexes[ $position ] ) ) {
					$out['ids'][ $indexes[ $position ] ] = $ids;
				}
			}
		}
		return $out;
	}

	/**
	 * Details of one vulnerability, or null when unavailable or over the per-request limit.
	 *
	 * @param string $id OSV ID.
	 * @return array|null
	 */
	public function details( string $id ): ?array {
		/**
		 * Filters how many vulnerability details are fetched per scan slice.
		 *
		 * @param int $limit Limit.
		 */
		$limit = (int) apply_filters( 'keepitpeace_osv_details_limit', 40 );
		if ( self::$details_fetched >= $limit ) {
			return null;
		}
		++self::$details_fetched;
		$response = $this->http->get_json( 'osv', self::VULN_URL . rawurlencode( $id ), DAY_IN_SECONDS );
		return is_array( $response['data'] ) ? $response['data'] : null;
	}

	/**
	 * Severity slug from a vulnerability record (GitHub advisories carry database_specific.severity).
	 *
	 * @param array $vuln Record.
	 * @return string critical|high|medium|low
	 */
	public static function severity( array $vuln ): string {
		$value = strtoupper( (string) ( $vuln['database_specific']['severity'] ?? '' ) );
		switch ( $value ) {
			case 'CRITICAL':
				return 'critical';
			case 'HIGH':
				return 'high';
			case 'LOW':
				return 'low';
		}
		foreach ( (array) ( $vuln['affected'] ?? array() ) as $affected ) {
			$value = strtoupper( (string) ( $affected['ecosystem_specific']['severity'] ?? '' ) );
			if ( in_array( $value, array( 'CRITICAL', 'HIGH', 'LOW' ), true ) ) {
				return strtolower( $value );
			}
		}
		return 'medium';
	}

	/**
	 * Lowest fixed version newer than the installed one.
	 *
	 * @param array  $vuln      Record.
	 * @param string $ecosystem Ecosystem.
	 * @param string $name      Package.
	 * @param string $version   Installed version.
	 * @return string '' when unknown.
	 */
	public static function fixed_version( array $vuln, string $ecosystem, string $name, string $version ): string {
		$best = '';
		foreach ( (array) ( $vuln['affected'] ?? array() ) as $affected ) {
			$package = (array) ( $affected['package'] ?? array() );
			if ( strtolower( (string) ( $package['name'] ?? '' ) ) !== strtolower( $name ) || (string) ( $package['ecosystem'] ?? '' ) !== $ecosystem ) {
				continue;
			}
			foreach ( (array) ( $affected['ranges'] ?? array() ) as $range ) {
				foreach ( (array) ( $range['events'] ?? array() ) as $event ) {
					if ( empty( $event['fixed'] ) ) {
						continue;
					}
					$fixed = ltrim( (string) $event['fixed'], 'vV' );
					if ( version_compare( $fixed, $version, '>' ) && ( '' === $best || version_compare( $fixed, $best, '<' ) ) ) {
						$best = $fixed;
					}
				}
			}
		}
		return $best;
	}
}
