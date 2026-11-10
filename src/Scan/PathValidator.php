<?php
/**
 * Validates directories outside WordPress registered as scan targets.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that a path exists, is readable, is inside open_basedir and does not overlap WordPress itself.
 */
final class PathValidator {

	const OVERLAPS_WORDPRESS = 'overlaps_wordpress';
	const CONTAINS_WORDPRESS = 'contains_wordpress';
	const IN_PLUGINS         = 'in_plugins';
	const IN_THEMES          = 'in_themes';

	/**
	 * Validates and canonicalizes a path.
	 *
	 * @param string $path Raw path entered by the user.
	 * @return array{path:string,error:string} error is '' when valid.
	 */
	public static function validate( string $path ): array {
		$path   = PathGuard::normalize( trim( $path ) );
		$status = PathGuard::check_directory( $path );
		if ( PathGuard::OK !== $status ) {
			return array(
				'path'  => $path,
				'error' => $status,
			);
		}
		$real = realpath( $path );
		if ( false !== $real ) {
			$path = PathGuard::normalize( $real );
			if ( ! PathGuard::is_within_open_basedir( $path ) ) {
				return array(
					'path'  => $path,
					'error' => PathGuard::OUTSIDE_BASEDIR,
				);
			}
		}
		return array(
			'path'  => $path,
			'error' => self::overlap( $path, self::wordpress_dirs() ),
		);
	}

	/**
	 * WordPress directories that must not be registered (scanned by other checks or too large).
	 *
	 * @return array{root:string,protected:string[],plugins:string[],themes:string[]}
	 */
	public static function wordpress_dirs(): array {
		$root    = self::canonical( ABSPATH );
		$plugins = array( self::canonical( WP_PLUGIN_DIR ) );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$plugins[] = self::canonical( WPMU_PLUGIN_DIR );
		}
		global $wp_theme_directories;
		$themes = array( self::canonical( WP_CONTENT_DIR . '/themes' ) );
		foreach ( (array) $wp_theme_directories as $theme_dir ) {
			$themes[] = self::canonical( (string) $theme_dir );
		}
		return array(
			'root'      => $root,
			'plugins'   => array_values( array_unique( $plugins ) ),
			'themes'    => array_values( array_unique( $themes ) ),
			'protected' => array_values(
				array_unique(
					array(
						$root . '/wp-admin',
						$root . '/' . WPINC,
						self::canonical( WP_CONTENT_DIR ),
					)
				)
			),
		);
	}

	/**
	 * Whether a path overlaps WordPress. Subdirectories of the WordPress root that are not
	 * WordPress itself (e.g. a separate app in /public_html/lp) are allowed.
	 *
	 * @param string $path Canonical path.
	 * @param array  $dirs Result of wordpress_dirs().
	 * @return string '' or an error code.
	 */
	public static function overlap( string $path, array $dirs ): string {
		$path = PathGuard::normalize( $path );
		$root = PathGuard::normalize( (string) $dirs['root'] );
		if ( $path === $root || self::is_inside( $root, $path ) ) {
			return self::CONTAINS_WORDPRESS;
		}
		// A plugin or theme folder: point to the dedicated selection instead of a generic error.
		foreach ( array(
			self::IN_PLUGINS => (array) ( $dirs['plugins'] ?? array() ),
			self::IN_THEMES  => (array) ( $dirs['themes'] ?? array() ),
		) as $code => $parents ) {
			foreach ( $parents as $parent ) {
				$parent = PathGuard::normalize( (string) $parent );
				if ( $path === $parent || self::is_inside( $path, $parent ) ) {
					return $code;
				}
			}
		}
		foreach ( (array) $dirs['protected'] as $dir ) {
			$dir = PathGuard::normalize( (string) $dir );
			if ( $path === $dir || self::is_inside( $path, $dir ) || self::is_inside( $dir, $path ) ) {
				return self::OVERLAPS_WORDPRESS;
			}
		}
		return '';
	}

	/**
	 * Whether $child is strictly inside $ancestor.
	 *
	 * @param string $child    Path.
	 * @param string $ancestor Path.
	 * @return bool
	 */
	public static function is_inside( string $child, string $ancestor ): bool {
		$ancestor = '/' === $ancestor ? '' : $ancestor;
		return 0 === strpos( $child, $ancestor . '/' ) && $child !== $ancestor;
	}

	/**
	 * Translated description of an error code.
	 *
	 * @param string $error Error.
	 * @return string
	 */
	public static function describe( string $error ): string {
		switch ( $error ) {
			case self::CONTAINS_WORDPRESS:
				return __( 'This is the WordPress folder or one of its parent folders, so WordPress itself would be scanned. Register the folder of the other program instead (e.g. …/public_html/lp).', 'keep-it-peace' );
			case self::IN_PLUGINS:
				return __( 'This is a plugin folder. Plugins are not registered here: select custom plugins under "Plugins to scan" above.', 'keep-it-peace' );
			case self::IN_THEMES:
				return __( 'This is a theme folder. Themes are not registered here: select them under "Themes to scan" above.', 'keep-it-peace' );
			case self::OVERLAPS_WORDPRESS:
				return __( 'This folder is part of WordPress (wp-admin, wp-includes or wp-content such as uploads), which cannot be registered here. Select themes and custom plugins above; uploads are checked automatically.', 'keep-it-peace' );
		}
		return PathGuard::describe( $error );
	}

	/**
	 * Canonical form of a WordPress path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function canonical( string $path ): string {
		$real = PathGuard::is_within_open_basedir( $path ) ? realpath( $path ) : false;
		return PathGuard::normalize( false === $real ? $path : $real );
	}
}
