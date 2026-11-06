<?php
/**
 * Scans persistence.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name comes from $wpdb->prefix.

/**
 * Stores scan history.
 */
class ScanRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'keepitpeace_scans';
	}

	/**
	 * Creates a running scan.
	 *
	 * @param string $trigger         Trigger.
	 * @param int    $user_id         User.
	 * @param array  $state           Initial state.
	 * @param string $ruleset_version Rule set version.
	 * @return int
	 */
	public function create( string $trigger, int $user_id, array $state, string $ruleset_version ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->table(),
			array(
				'status'          => Scan::RUNNING,
				'trigger_type'    => $trigger,
				'started_at'      => $now,
				'updated_at'      => $now,
				'progress'        => 0,
				'state'           => wp_json_encode( $state ),
				'summary'         => wp_json_encode( array() ),
				'ruleset_version' => $ruleset_version,
				'plugin_version'  => KEEPITPEACE_VERSION,
				'user_id'         => $user_id,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Gets a scan.
	 *
	 * @param int $id ID.
	 * @return Scan|null
	 */
	public function get( int $id ): ?Scan {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * The running scan, if any.
	 *
	 * @return Scan|null
	 */
	public function get_running(): ?Scan {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY id DESC LIMIT 1", Scan::RUNNING ) );
		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * Latest completed scan.
	 *
	 * @param int $before_id Only scans with a lower ID (0 = any).
	 * @return Scan|null
	 */
	public function latest_completed( int $before_id = 0 ): ?Scan {
		global $wpdb;
		if ( $before_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s AND id < %d ORDER BY id DESC LIMIT 1", Scan::COMPLETED, $before_id ) );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY id DESC LIMIT 1", Scan::COMPLETED ) );
		}
		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * Saves progress.
	 *
	 * @param int   $id       ID.
	 * @param array $state    State.
	 * @param int   $progress 0-100.
	 */
	public function save_state( int $id, array $state, int $progress ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'state'      => wp_json_encode( $state ),
				'progress'   => max( 0, min( 100, $progress ) ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Marks a scan finished.
	 *
	 * @param int    $id      ID.
	 * @param string $status  Final status.
	 * @param array  $summary Summary.
	 */
	public function finish( int $id, string $status, array $summary ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			$this->table(),
			array(
				'status'      => $status,
				'summary'     => wp_json_encode( $summary ),
				'state'       => null,
				'progress'    => Scan::COMPLETED === $status ? 100 : 0,
				'finished_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Aborts running scans not updated for a while.
	 *
	 * @param int $seconds Age.
	 * @return int[] Aborted IDs.
	 */
	public function abort_stale( int $seconds ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE status = %s AND updated_at < %s", Scan::RUNNING, $cutoff ) ) );
		foreach ( $ids as $id ) {
			$this->finish( $id, Scan::ABORTED, array( 'error' => 'stale' ) );
		}
		return $ids;
	}

	/**
	 * Lists scans newest first.
	 *
	 * @param int $per_page Per page.
	 * @param int $page     Page.
	 * @return Scan[]
	 */
	public function list( int $per_page, int $page ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) )
		);
		return array_map( array( Scan::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Total scans.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
	 * Deletes finished scans older than the cutoff, keeping the given one.
	 *
	 * @param string $cutoff  UTC datetime.
	 * @param int    $keep_id Scan to keep.
	 */
	public function delete_older_than( string $cutoff, int $keep_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE started_at < %s AND status <> %s AND id <> %d", $cutoff, Scan::RUNNING, $keep_id )
		);
	}
}
