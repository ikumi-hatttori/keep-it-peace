<?php
/**
 * Severity levels.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Severity constants and helpers.
 */
final class Severity {

	const CRITICAL = 5;
	const HIGH     = 4;
	const MEDIUM   = 3;
	const LOW      = 2;
	const INFO     = 1;

	const SLUGS = array(
		self::CRITICAL => 'critical',
		self::HIGH     => 'high',
		self::MEDIUM   => 'medium',
		self::LOW      => 'low',
		self::INFO     => 'info',
	);

	/**
	 * Converts a slug or number to a level (unknown values become INFO).
	 *
	 * @param string|int $value Slug or level.
	 * @return int
	 */
	public static function from( $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$value = (int) $value;
			return isset( self::SLUGS[ $value ] ) ? $value : self::INFO;
		}
		$level = array_search( strtolower( (string) $value ), self::SLUGS, true );
		return false === $level ? self::INFO : (int) $level;
	}

	/**
	 * Slug for a level.
	 *
	 * @param int $level Level.
	 * @return string
	 */
	public static function slug( int $level ): string {
		return self::SLUGS[ $level ] ?? 'info';
	}

	/**
	 * Translated label.
	 *
	 * @param int $level Level.
	 * @return string
	 */
	public static function label( int $level ): string {
		switch ( $level ) {
			case self::CRITICAL:
				return __( 'Critical', 'keep-it-peace' );
			case self::HIGH:
				return __( 'High', 'keep-it-peace' );
			case self::MEDIUM:
				return __( 'Medium', 'keep-it-peace' );
			case self::LOW:
				return __( 'Low', 'keep-it-peace' );
			default:
				return __( 'Info', 'keep-it-peace' );
		}
	}

	/**
	 * Levels from highest to lowest.
	 *
	 * @return int[]
	 */
	public static function all(): array {
		return array_keys( self::SLUGS );
	}
}
