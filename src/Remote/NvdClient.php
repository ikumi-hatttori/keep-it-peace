<?php
/**
 * NVD API 2.0 client.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up CVEs for a product version.
 *
 * Data sent: the CPE name containing only the product and version (e.g. cpe:2.3:a:php:php:8.1.2).
 * Results are cached for 24 hours to respect the keyless rate limit (5 requests / 30 seconds).
 */
class NvdClient {

	const ENDPOINT = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

	/**
	 * Http.
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
	 * CVEs affecting a version.
	 *
	 * @param string $vendor  CPE vendor.
	 * @param string $product CPE product.
	 * @param string $version Version (x.y.z).
	 * @return array{cves:array,error:?string,disabled:bool,stale:bool}
	 */
	public function cves( string $vendor, string $product, string $version ): array {
		$cpe = sprintf( 'cpe:2.3:a:%s:%s:%s:*:*:*:*:*:*:*', $vendor, $product, $version );
		$url = add_query_arg(
			array(
				'cpeName'        => rawurlencode( $cpe ),
				'resultsPerPage' => 2000,
			),
			self::ENDPOINT
		) . '&isVulnerable';

		$response = $this->http->get_json( 'nvd', $url, DAY_IN_SECONDS );
		return array(
			'cves'     => is_array( $response['data'] ) ? self::parse( $response['data'] ) : array(),
			'error'    => $response['error'],
			'disabled' => $response['disabled'],
			'stale'    => $response['stale'],
		);
	}

	/**
	 * Parses an NVD response.
	 *
	 * @param array $data Response.
	 * @return array[] Items: id, score, severity, published, summary.
	 */
	public static function parse( array $data ): array {
		$out = array();
		foreach ( (array) ( $data['vulnerabilities'] ?? array() ) as $item ) {
			$cve = $item['cve'] ?? null;
			if ( ! is_array( $cve ) || empty( $cve['id'] ) ) {
				continue;
			}
			$score = 0.0;
			foreach ( array( 'cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2' ) as $key ) {
				if ( ! empty( $cve['metrics'][ $key ][0]['cvssData']['baseScore'] ) ) {
					$score = (float) $cve['metrics'][ $key ][0]['cvssData']['baseScore'];
					break;
				}
			}
			$summary = '';
			foreach ( (array) ( $cve['descriptions'] ?? array() ) as $description ) {
				if ( isset( $description['lang'], $description['value'] ) && 'en' === $description['lang'] ) {
					$summary = (string) $description['value'];
					break;
				}
			}
			$out[] = array(
				'id'        => (string) $cve['id'],
				'score'     => $score,
				'severity'  => VersionEvaluator::cvss_to_severity( $score ),
				'published' => isset( $cve['published'] ) ? substr( (string) $cve['published'], 0, 10 ) : '',
				'summary'   => $summary,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return $out;
	}
}
