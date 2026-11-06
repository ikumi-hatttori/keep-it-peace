<?php
/**
 * Safe path checks that never trigger PHP warnings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Checks open_basedir, existence and readability.
 */
final class PathGuard {

	const OK              = 'ok';
	const NOT_ABSOLUTE    = 'not_absolute';
	const OUTSIDE_BASEDIR = 'outside_open_basedir';
	const NOT_FOUND       = 'not_found';
	const NOT_DIRECTORY   = 'not_directory';
	const NOT_READABLE    = 'not_readable';

	/**
	 * Normalizes slashes and removes trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '#/+#', '/', $path );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Whether the path is allowed by open_basedir (without touching the filesystem).
	 *
	 * @param string      $path     Absolute path.
	 * @param string|null $basedir  Value of open_basedir (null reads the ini setting).
	 * @return bool
	 */
	public static function is_within_open_basedir( string $path, ?string $basedir = null ): bool {
		if ( null === $basedir ) {
			$basedir = (string) ini_get( 'open_basedir' );
		}
		if ( '' === trim( $basedir ) ) {
			return true;
		}
		$path = self::normalize( $path );
		foreach ( explode( PATH_SEPARATOR, $basedir ) as $base ) {
			$base = trim( $base );
			if ( '' === $base ) {
				continue;
			}
			if ( '.' === $base ) {
				$cwd = getcwd();
				if ( false === $cwd ) {
					continue;
				}
				$base = $cwd;
			}
			$base_norm = self::normalize( $base );
			$is_dir    = '/' === substr( str_replace( '\\', '/', $base ), -1 );
			if ( $is_dir ) {
				if ( $path === $base_norm || 0 === strpos( $path . '/', rtrim( $base_norm, '/' ) . '/' ) ) {
					return true;
				}
			} elseif ( 0 === strpos( $path, $base_norm ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validates a directory.
	 *
	 * @param string $path Absolute path.
	 * @return string One of the class constants.
	 */
	public static function check_directory( string $path ): string {
		$path = self::normalize( $path );
		if ( '' === $path || ( '/' !== $path[0] && ! preg_match( '#^[A-Za-z]:/#', $path ) ) ) {
			return self::NOT_ABSOLUTE;
		}
		if ( ! self::is_within_open_basedir( $path ) ) {
			return self::OUTSIDE_BASEDIR;
		}
		if ( ! file_exists( $path ) ) {
			return self::NOT_FOUND;
		}
		if ( ! is_dir( $path ) ) {
			return self::NOT_DIRECTORY;
		}
		if ( ! is_readable( $path ) ) {
			return self::NOT_READABLE;
		}
		return self::OK;
	}

	/**
	 * Translated explanation of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function describe( string $status ): string {
		switch ( $status ) {
			case self::NOT_ABSOLUTE:
				return __( 'The path is not absolute.', 'keep-it-peace' );
			case self::OUTSIDE_BASEDIR:
				return __( 'The path is outside open_basedir, so PHP cannot read it.', 'keep-it-peace' );
			case self::NOT_FOUND:
				return __( 'The path does not exist.', 'keep-it-peace' );
			case self::NOT_DIRECTORY:
				return __( 'The path is not a directory.', 'keep-it-peace' );
			case self::NOT_READABLE:
				return __( 'The directory is not readable by PHP.', 'keep-it-peace' );
		}
		return __( 'OK', 'keep-it-peace' );
	}
}
