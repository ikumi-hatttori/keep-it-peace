<?php
/**
 * Theme targets.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\ScanTargetProviderInterface;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides selected themes (default: active theme and its parent).
 */
class ThemeTargetProvider implements ScanTargetProviderInterface {

	/**
	 * Theme slugs to scan.
	 *
	 * @param Settings $settings Settings.
	 * @return string[]
	 */
	public static function selected_slugs( Settings $settings ): array {
		$slugs = array_values( array_filter( array_map( 'strval', (array) $settings->get( 'themes', array() ) ) ) );
		if ( empty( $slugs ) ) {
			$slugs = array_unique( array( get_stylesheet(), get_template() ) );
		}
		return $slugs;
	}

	/**
	 * Targets.
	 *
	 * @param Settings $settings Settings.
	 * @return ScanTarget[]
	 */
	public function get_targets( Settings $settings ): array {
		$targets = array();
		foreach ( self::selected_slugs( $settings ) as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}
			$target               = new ScanTarget( 'theme:' . $slug, 'theme', $theme->get( 'Name' ), $theme->get_stylesheet_directory(), 'theme' );
			$target->url          = $theme->get_stylesheet_directory_uri();
			$target->meta['slug'] = $slug;
			$targets[]            = $target;
		}
		return $targets;
	}
}
