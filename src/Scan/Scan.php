<?php
/**
 * Scan record.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the scans table.
 */
class Scan {

	const RUNNING   = 'running';
	const COMPLETED = 'completed';
	const FAILED    = 'failed';
	const ABORTED   = 'aborted';

	/**
	 * Id.
	 *
	 * @var int
	 */
	public $id = 0;
	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status = self::RUNNING;
	/**
	 * Cron|manual|cli|api.
	 *
	 * @var string
	 */
	public $trigger_type = 'cron';
	/**
	 * UTC.
	 *
	 * @var string
	 */
	public $started_at = '';
	/**
	 * UTC.
	 *
	 * @var string|null
	 */
	public $finished_at = null;
	/**
	 * UTC.
	 *
	 * @var string
	 */
	public $updated_at = '';
	/**
	 * Progress.
	 *
	 * @var int
	 */
	public $progress = 0;
	/**
	 * State.
	 *
	 * @var array
	 */
	public $state = array();
	/**
	 * Summary.
	 *
	 * @var array
	 */
	public $summary = array();
	/**
	 * Ruleset version.
	 *
	 * @var string
	 */
	public $ruleset_version = '';
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $plugin_version = '';
	/**
	 * User id.
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Builds from a row.
	 *
	 * @param object|array $row Row.
	 * @return self
	 */
	public static function from_row( $row ): self {
		$row  = (array) $row;
		$scan = new self();

		$scan->id              = (int) $row['id'];
		$scan->status          = (string) $row['status'];
		$scan->trigger_type    = (string) $row['trigger_type'];
		$scan->started_at      = (string) $row['started_at'];
		$scan->finished_at     = $row['finished_at'] ? (string) $row['finished_at'] : null;
		$scan->updated_at      = (string) $row['updated_at'];
		$scan->progress        = (int) $row['progress'];
		$scan->ruleset_version = (string) $row['ruleset_version'];
		$scan->plugin_version  = (string) $row['plugin_version'];
		$scan->user_id         = (int) $row['user_id'];

		$state         = json_decode( (string) $row['state'], true );
		$summary       = json_decode( (string) $row['summary'], true );
		$scan->state   = is_array( $state ) ? $state : array();
		$scan->summary = is_array( $summary ) ? $summary : array();
		return $scan;
	}

	/**
	 * Duration in seconds, if finished.
	 *
	 * @return int|null
	 */
	public function duration(): ?int {
		if ( ! $this->finished_at ) {
			return null;
		}
		return max( 0, strtotime( $this->finished_at . ' UTC' ) - strtotime( $this->started_at . ' UTC' ) );
	}
}
