<?php
/**
 * Reads package lists from lock files.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Dependencies;

defined( 'ABSPATH' ) || exit;

/**
 * Parses composer.lock and package-lock.json / npm-shrinkwrap.json (lockfile versions 1–3).
 */
final class LockfileParser {

	const FILES = array( 'composer.lock', 'package-lock.json', 'npm-shrinkwrap.json' );

	/**
	 * Whether a file name is a supported lock file.
	 *
	 * @param string $basename File name.
	 * @return bool
	 */
	public static function supports( string $basename ): bool {
		return in_array( $basename, self::FILES, true );
	}

	/**
	 * Packages in a lock file.
	 *
	 * @param string $basename File name.
	 * @param string $contents JSON.
	 * @return array<int,array{ecosystem:string,name:string,version:string,dev:bool}>|null Null when the file is invalid.
	 */
	public static function parse( string $basename, string $contents ): ?array {
		$json = json_decode( $contents, true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		$packages = 'composer.lock' === $basename ? self::composer( $json ) : self::npm( $json );
		$unique   = array();
		foreach ( $packages as $package ) {
			if ( '' === $package['name'] || '' === $package['version'] ) {
				continue;
			}
			$key = $package['ecosystem'] . ':' . $package['name'] . '@' . $package['version'];
			if ( ! isset( $unique[ $key ] ) || ! $package['dev'] ) {
				$unique[ $key ] = $package;
			}
		}
		ksort( $unique );
		return array_values( $unique );
	}

	/**
	 * Composer packages.
	 *
	 * @param array $json Lock data.
	 * @return array
	 */
	private static function composer( array $json ): array {
		$out = array();
		foreach ( array(
			'packages'     => false,
			'packages-dev' => true,
		) as $key => $dev ) {
			foreach ( (array) ( $json[ $key ] ?? array() ) as $package ) {
				if ( ! is_array( $package ) || empty( $package['name'] ) || empty( $package['version'] ) ) {
					continue;
				}
				$version = ltrim( (string) $package['version'], 'vV' );
				// Branch checkouts (dev-main, 1.x-dev) have no release to look up.
				if ( 0 === strpos( $version, 'dev-' ) || '-dev' === substr( $version, -4 ) ) {
					continue;
				}
				$out[] = array(
					'ecosystem' => 'Packagist',
					'name'      => strtolower( (string) $package['name'] ),
					'version'   => $version,
					'dev'       => $dev,
				);
			}
		}
		return $out;
	}

	/**
	 * Packages of an npm lock file.
	 *
	 * @param array $json Lock data.
	 * @return array
	 */
	private static function npm( array $json ): array {
		$out = array();
		if ( isset( $json['packages'] ) && is_array( $json['packages'] ) ) {
			foreach ( $json['packages'] as $path => $package ) {
				$path = (string) $path;
				if ( '' === $path || ! is_array( $package ) || ! empty( $package['link'] ) ) {
					continue;
				}
				$pos   = strrpos( $path, 'node_modules/' );
				$name  = false === $pos ? (string) ( $package['name'] ?? '' ) : substr( $path, $pos + 13 );
				$out[] = array(
					'ecosystem' => 'npm',
					'name'      => $name,
					'version'   => (string) ( $package['version'] ?? '' ),
					'dev'       => ! empty( $package['dev'] ),
				);
			}
			return $out;
		}
		self::npm_v1( (array) ( $json['dependencies'] ?? array() ), $out );
		return $out;
	}

	/**
	 * Lockfile version 1 (nested dependencies).
	 *
	 * @param array $dependencies Dependencies.
	 * @param array $out          Output (by reference).
	 * @param int   $depth        Depth.
	 */
	private static function npm_v1( array $dependencies, array &$out, int $depth = 0 ): void {
		if ( $depth > 50 ) {
			return;
		}
		foreach ( $dependencies as $name => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$version = (string) ( $package['version'] ?? '' );
			if ( preg_match( '/^\d/', $version ) ) {
				$out[] = array(
					'ecosystem' => 'npm',
					'name'      => (string) $name,
					'version'   => $version,
					'dev'       => ! empty( $package['dev'] ),
				);
			}
			if ( ! empty( $package['dependencies'] ) && is_array( $package['dependencies'] ) ) {
				self::npm_v1( $package['dependencies'], $out, $depth + 1 );
			}
		}
	}
}
