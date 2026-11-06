<?php
/**
 * Finding statuses.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Status constants.
 */
final class FindingStatus {

	const NEW_FINDING  = 'new';
	const ACKNOWLEDGED = 'acknowledged';
	const IGNORED      = 'ignored';
	const RESOLVED     = 'resolved';

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::NEW_FINDING, self::ACKNOWLEDGED, self::IGNORED, self::RESOLVED );
	}

	/**
	 * Statuses that count as open problems.
	 *
	 * @return string[]
	 */
	public static function open(): array {
		return array( self::NEW_FINDING, self::ACKNOWLEDGED );
	}

	/**
	 * Translated label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::NEW_FINDING:
				return __( 'New', 'keep-it-peace' );
			case self::ACKNOWLEDGED:
				return __( 'Acknowledged', 'keep-it-peace' );
			case self::IGNORED:
				return __( 'Ignored', 'keep-it-peace' );
			case self::RESOLVED:
				return __( 'Resolved', 'keep-it-peace' );
		}
		return $status;
	}

	/**
	 * Whether the value is valid.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
