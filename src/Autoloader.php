<?php
/**
 * PSR-4 autoloader (no Composer at runtime).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader.
 */
final class Autoloader {

	/**
	 * Registers an autoloader for a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix with trailing backslash.
	 * @param string $base_dir Directory with trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( $class_name ) use ( $prefix, $base_dir ) {
				$len = strlen( $prefix );
				if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
					return;
				}
				$file = $base_dir . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
