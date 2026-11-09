<?php
/**
 * Directory listings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether the server lists the files of directories without an index page.
 */
class DirectoryListingCheck extends AbstractExternalCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'external.dir_listing';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Directory listing', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks whether wp-content/uploads and other directories show a list of their files ("Index of /").', 'keep-it-peace' );
	}

	/**
	 * Items.
	 *
	 * @param ScanContext $context Context.
	 * @return array[]
	 */
	protected function items( ScanContext $context ): array {
		$uploads = wp_upload_dir( null, false );
		$content = trailingslashit( content_url() );
		$urls    = array(
			trailingslashit( (string) $uploads['baseurl'] ),
			trailingslashit( (string) $uploads['baseurl'] ) . gmdate( 'Y' ) . '/',
			$content,
			$content . 'plugins/',
			$content . 'themes/',
			trailingslashit( includes_url() ),
		);
		foreach ( ExternalTargets::urls( $context->settings, $this->probe()->policy() ) as $target ) {
			if ( in_array( $target['kind'], array( 'extra', 'path' ), true ) ) {
				$urls[] = trailingslashit( (string) strtok( $target['url'], '?#' ) );
			}
		}

		/**
		 * Filters directory URLs checked for directory listings.
		 *
		 * @param string[] $urls URLs.
		 */
		$urls  = (array) apply_filters( 'keepitpeace_listing_urls', $urls );
		$items = array();
		foreach ( array_values( array_unique( array_map( 'strval', $urls ) ) ) as $url ) {
			if ( $this->probe()->policy()->is_allowed_url( $url ) ) {
				$items[] = array( 'url' => $url );
			}
		}
		return $items;
	}

	/**
	 * Processes one item.
	 *
	 * @param array       $item    Item.
	 * @param ScanContext $context Context.
	 * @param array       $data    Data.
	 * @return Finding[]
	 */
	protected function process( array $item, ScanContext $context, array &$data ): array {
		$url      = (string) $item['url'];
		$response = $this->probe()->get( $url, array( 'max_bytes' => 16384 ) );
		if ( '' !== $response['error'] ) {
			if ( empty( $data['error_reported'] ) ) {
				$data['error_reported'] = true;
				return array( $this->unreachable( $url, $response['error'], $context ) );
			}
			$context->keep( $this->get_id() );
			return array();
		}
		if ( 200 !== $response['code'] || ! self::is_listing( $response['body'] ) ) {
			return array();
		}
		return array(
			$this->url_finding(
				$url,
				array(
					'rule_id'        => 'listing.enabled',
					'severity'       => Severity::MEDIUM,
					'title'          => __( 'Directory listing is enabled', 'keep-it-peace' ),
					'message'        => __( 'Anyone can see the list of files in this directory, including backups and private uploads.', 'keep-it-peace' ),
					'recommendation' => __( 'Disable directory listings ("Options -Indexes" in .htaccess for Apache, "autoindex off" for nginx) or ask your host.', 'keep-it-peace' ),
				)
			),
		);
	}

	/**
	 * Whether an HTML body is an automatic directory listing.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	public static function is_listing( string $body ): bool {
		return (bool) preg_match( '#<title>\s*Index of\s*/|<h1>\s*Index of\s*/|<h2>\s*Index of\s*/|\[To Parent Directory\]|<title>\s*Directory listing for#i', $body );
	}
}
