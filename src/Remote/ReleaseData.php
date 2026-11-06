<?php
/**
 * Release cycle data (endoflife.date, php.net, bundled snapshot).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches release information.
 *
 * Data sent: only the product name (php, mysql, mariadb) and the PHP branch (e.g. 8.1).
 */
class ReleaseData {

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
	 * Cycles for a product.
	 *
	 * @param string $product php|mysql|mariadb.
	 * @return array{cycles:array,source:string,error:?string}
	 */
	public function cycles( string $product ): array {
		$response = $this->http->get_json( 'endoflife', 'https://endoflife.date/api/' . rawurlencode( $product ) . '.json', WEEK_IN_SECONDS );
		if ( is_array( $response['data'] ) && ! empty( $response['data'] ) ) {
			return array(
				'cycles' => $response['data'],
				'source' => 'endoflife.date',
				'error'  => $response['error'],
			);
		}
		return array(
			'cycles' => self::snapshot( $product ),
			'source' => 'bundled',
			'error'  => $response['error'],
		);
	}

	/**
	 * Latest PHP release of a branch from php.net.
	 *
	 * @param string $branch e.g. 8.1.
	 * @return string|null
	 */
	public function latest_php_patch( string $branch ): ?string {
		$response = $this->http->get_json( 'phpnet', 'https://www.php.net/releases/index.php?json&version=' . rawurlencode( $branch ), DAY_IN_SECONDS );
		$data     = $response['data'];
		if ( is_array( $data ) && ! empty( $data['version'] ) && is_string( $data['version'] ) ) {
			return $data['version'];
		}
		return null;
	}

	/**
	 * Bundled snapshot.
	 *
	 * @param string $product Product.
	 * @return array
	 */
	public static function snapshot( string $product ): array {
		static $data = null;
		if ( null === $data ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled file.
			$json = file_get_contents( KEEPITPEACE_DIR . 'data/eol-snapshot.json' );
			$data = is_string( $json ) ? json_decode( $json, true ) : array();
			$data = is_array( $data ) ? $data : array();
		}
		return isset( $data[ $product ] ) && is_array( $data[ $product ] ) ? $data[ $product ] : array();
	}
}
