<?php
/**
 * PHP runtime of the web server, also available to CLI scans.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * On shared hosts the CLI often runs a different PHP version and php.ini than the web server.
 * Web requests record a snapshot; scans started from WP-CLI evaluate that snapshot instead
 * of the CLI runtime.
 */
final class RuntimeEnvironment {

	const OPTION  = 'keepitpeace_web_runtime';
	const REFRESH = 43200;

	const INI_KEYS = array( 'display_errors', 'expose_php', 'allow_url_include', 'allow_url_fopen', 'log_errors', 'session.cookie_httponly', 'disable_functions' );

	/**
	 * Captures the current runtime.
	 *
	 * @return array{php_version:string,sapi:string,ini:array,captured_at:int}
	 */
	public static function capture(): array {
		$ini = array();
		foreach ( self::INI_KEYS as $key ) {
			$ini[ $key ] = ini_get( $key );
		}
		return array(
			'php_version' => PHP_VERSION,
			'sapi'        => PHP_SAPI,
			'ini'         => $ini,
			'captured_at' => time(),
		);
	}

	/**
	 * Stores a snapshot from web requests (at most every 12 hours or when the version changes).
	 */
	public static function record(): void {
		if ( 'cli' === PHP_SAPI ) {
			return;
		}
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ( $stored['php_version'] ?? '' ) === PHP_VERSION && time() - (int) ( $stored['captured_at'] ?? 0 ) < self::REFRESH ) {
			return;
		}
		update_option( self::OPTION, self::capture(), false );
	}

	/**
	 * Runtime to evaluate: the live one on the web, the web snapshot on the CLI.
	 *
	 * @return array{php_version:string,sapi:string,ini:array,captured_at:int}
	 */
	public static function current(): array {
		if ( 'cli' === PHP_SAPI ) {
			$stored = get_option( self::OPTION );
			if ( is_array( $stored ) && ! empty( $stored['php_version'] ) && isset( $stored['ini'] ) && is_array( $stored['ini'] ) ) {
				return $stored;
			}
		}
		return self::capture();
	}
}
