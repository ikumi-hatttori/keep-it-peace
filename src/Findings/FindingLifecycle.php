<?php
/**
 * Status transitions when a finding is detected again.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic deciding what happens to an existing finding during a scan.
 */
final class FindingLifecycle {

	const OUTCOME_CREATED      = 'created';
	const OUTCOME_UNCHANGED    = 'unchanged';
	const OUTCOME_REOPENED     = 'reopened';
	const OUTCOME_IGNORE_RESET = 'ignore_reset';

	/**
	 * Decides the outcome of a detection.
	 *
	 * @param string|null $existing_status Current status, or null when the finding is new.
	 * @param string|null $existing_hash   File hash stored with the finding.
	 * @param string|null $detected_hash   File hash now.
	 * @return array{0:string,1:string} [outcome, new status]
	 */
	public static function on_detected( ?string $existing_status, ?string $existing_hash, ?string $detected_hash ): array {
		if ( null === $existing_status ) {
			return array( self::OUTCOME_CREATED, FindingStatus::NEW_FINDING );
		}
		if ( FindingStatus::RESOLVED === $existing_status ) {
			return array( self::OUTCOME_REOPENED, FindingStatus::NEW_FINDING );
		}
		if ( FindingStatus::IGNORED === $existing_status
			&& null !== $existing_hash && null !== $detected_hash
			&& ! hash_equals( $existing_hash, $detected_hash ) ) {
			return array( self::OUTCOME_IGNORE_RESET, FindingStatus::NEW_FINDING );
		}
		return array( self::OUTCOME_UNCHANGED, $existing_status );
	}

	/**
	 * Whether a finding not seen in the latest scan becomes resolved.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function resolves_when_missing( string $status ): bool {
		return FindingStatus::RESOLVED !== $status;
	}
}
