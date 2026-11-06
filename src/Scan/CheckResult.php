<?php
/**
 * Result of one check call.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Findings\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Findings plus resume information.
 */
class CheckResult {

	/**
	 * Findings.
	 *
	 * @var Finding[]
	 */
	public $findings = array();
	/**
	 * Complete.
	 *
	 * @var bool
	 */
	public $complete = true;
	/**
	 * Cursor for the next call when incomplete.
	 *
	 * @var array
	 */
	public $cursor = array();
	/**
	 * 0-1.
	 *
	 * @var float
	 */
	public $progress = 1.0;
	/**
	 * Values for the dashboard, report and export (e.g. PHP status).
	 *
	 * @var array
	 */
	public $data = array();
	/**
	 * Rule IDs whose existing findings must not be auto-resolved ('*' = all), e.g. when an API was unreachable.
	 *
	 * @var string[]
	 */
	public $keep_rules = array();

	/**
	 * Completed result.
	 *
	 * @param Finding[] $findings Findings.
	 * @param array     $data     Data.
	 * @return self
	 */
	public static function complete( array $findings = array(), array $data = array() ): self {
		$result           = new self();
		$result->findings = $findings;
		$result->data     = $data;
		return $result;
	}

	/**
	 * Incomplete result.
	 *
	 * @param Finding[] $findings Findings so far in this call.
	 * @param array     $cursor   Cursor.
	 * @param float     $progress 0-1.
	 * @return self
	 */
	public static function partial( array $findings, array $cursor, float $progress ): self {
		$result           = new self();
		$result->findings = $findings;
		$result->complete = false;
		$result->cursor   = $cursor;
		$result->progress = max( 0.0, min( 1.0, $progress ) );
		return $result;
	}
}
