<?php
/**
 * Targets outside WordPress.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\ScanTargetProviderInterface;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the directories registered in Settings → Targets (`paths` setting).
 */
class PathTargetProvider implements ScanTargetProviderInterface {

	/**
	 * Stable ID of a registered path (changes when the path changes, so a new baseline is recorded).
	 *
	 * @param string $path Canonical path.
	 * @return string
	 */
	public static function id_for( string $path ): string {
		return substr( hash( 'sha256', PathGuard::normalize( $path ) ), 0, 10 );
	}

	/**
	 * Targets.
	 *
	 * @param Settings $settings Settings.
	 * @return ScanTarget[]
	 */
	public function get_targets( Settings $settings ): array {
		$targets = array();
		foreach ( (array) $settings->get( 'paths', array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['path'] ) || empty( $entry['id'] ) ) {
				continue;
			}
			$label            = '' !== (string) ( $entry['label'] ?? '' ) ? (string) $entry['label'] : basename( (string) $entry['path'] );
			$target           = new ScanTarget( 'path:' . $entry['id'], 'path', $label, (string) $entry['path'], 'external_path' );
			$target->url      = (string) ( $entry['url'] ?? '' );
			$target->excludes = array_map( 'strval', (array) ( $entry['excludes'] ?? array() ) );
			$target->checks   = array_map( 'boolval', (array) ( $entry['checks'] ?? array() ) );
			$targets[]        = $target;
		}
		return $targets;
	}
}
