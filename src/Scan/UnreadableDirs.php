<?php
/**
 * Directories that could not be read in the last scan.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per target, which directories the last scan could not list. Files below them
 * were not seen, so they must not be reported as deleted, their findings must not be resolved,
 * and their baseline must not be approved.
 */
final class UnreadableDirs {

	const OPTION = 'keepitpeace_unreadable_dirs';

	/**
	 * Unreadable directories of a target in the last scan ('.' = the target root).
	 *
	 * @param string $scope Target ID.
	 * @return string[]
	 */
	public static function get( string $scope ): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $scope ] ) ? array_map( 'strval', (array) $all[ $scope ] ) : array();
	}

	/**
	 * Records the result of the last scan of a target (an empty list clears it).
	 *
	 * @param string   $scope Target ID.
	 * @param string[] $dirs  Relative directories.
	 */
	public static function set( string $scope, array $dirs ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( $dirs ) {
			$all[ $scope ] = array_values( array_unique( array_map( 'strval', $dirs ) ) );
		} elseif ( isset( $all[ $scope ] ) ) {
			unset( $all[ $scope ] );
		} else {
			return;
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a relative path is inside one of the directories.
	 *
	 * @param string   $rel_path Path relative to the target root.
	 * @param string[] $dirs     Directories ('.' = everything).
	 * @return bool
	 */
	public static function covers( string $rel_path, array $dirs ): bool {
		foreach ( $dirs as $dir ) {
			$dir = trim( (string) $dir, '/' );
			if ( '.' === $dir || '' === $dir || $rel_path === $dir || 0 === strpos( $rel_path, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
