<?php
/**
 * Differences between a scan and the previous state.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * New / resolved / ongoing counts for a scan.
 */
final class ScanDiff {

	/**
	 * New.
	 *
	 * @var int
	 */
	public $new = 0;
	/**
	 * Resolved.
	 *
	 * @var int
	 */
	public $resolved = 0;
	/**
	 * Ongoing.
	 *
	 * @var int
	 */
	public $ongoing = 0;

	/**
	 * Builds from event counts of a scan.
	 *
	 * @param array<string,int> $event_counts Event => count for the scan.
	 * @param int               $seen_open    Open findings seen in the scan (including new ones).
	 * @return self
	 */
	public static function from_events( array $event_counts, int $seen_open ): self {
		$diff           = new self();
		$diff->new      = (int) ( $event_counts['detected'] ?? 0 ) + (int) ( $event_counts['reopened'] ?? 0 ) + (int) ( $event_counts['ignore_reset'] ?? 0 );
		$diff->resolved = (int) ( $event_counts['resolved'] ?? 0 );
		$diff->ongoing  = max( 0, $seen_open - $diff->new );
		return $diff;
	}

	/**
	 * Compares two sets of fingerprints.
	 *
	 * @param string[] $previous Fingerprints open before the scan.
	 * @param string[] $current  Fingerprints detected by the scan.
	 * @return array{new:string[],resolved:string[],ongoing:string[]}
	 */
	public static function compare( array $previous, array $current ): array {
		$previous = array_values( array_unique( $previous ) );
		$current  = array_values( array_unique( $current ) );
		return array(
			'new'      => array_values( array_diff( $current, $previous ) ),
			'resolved' => array_values( array_diff( $previous, $current ) ),
			'ongoing'  => array_values( array_intersect( $current, $previous ) ),
		);
	}

	/**
	 * Array form.
	 *
	 * @return array{new:int,resolved:int,ongoing:int}
	 */
	public function to_array(): array {
		return array(
			'new'      => $this->new,
			'resolved' => $this->resolved,
			'ongoing'  => $this->ongoing,
		);
	}
}
