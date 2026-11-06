<?php
/**
 * Findings persistence.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables; table names come from \$wpdb->prefix.

/**
 * Stores findings and their history.
 */
class FindingRepository {

	/**
	 * Findings table.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'keepitpeace_findings';
	}

	/**
	 * Events table.
	 *
	 * @return string
	 */
	public function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'keepitpeace_finding_events';
	}

	/**
	 * Gets a finding.
	 *
	 * @param int $id ID.
	 * @return Finding|null
	 */
	public function get( int $id ): ?Finding {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		return $row ? Finding::from_row( $row ) : null;
	}

	/**
	 * Finds by fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return Finding|null
	 */
	public function find_by_fingerprint( string $fingerprint ): ?Finding {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE fingerprint = %s", $fingerprint ) );
		return $row ? Finding::from_row( $row ) : null;
	}

	/**
	 * Inserts or refreshes a detected finding.
	 *
	 * @param Finding $finding Detected finding.
	 * @param int     $scan_id Scan ID.
	 * @return array{0:string,1:Finding} [outcome, stored finding]
	 */
	public function upsert( Finding $finding, int $scan_id ): array {
		global $wpdb;
		$now      = current_time( 'mysql', true );
		$existing = $this->find_by_fingerprint( $finding->fingerprint );

		list( $outcome, $status ) = FindingLifecycle::on_detected(
			$existing ? $existing->status : null,
			$existing ? $existing->file_hash : null,
			$finding->file_hash
		);

		$data = array(
			'check_id'       => $finding->check_id,
			'rule_id'        => $finding->rule_id,
			'category'       => $finding->category,
			'severity'       => $finding->severity,
			'target_type'    => $finding->target_type,
			'target_scope'   => $finding->target_scope,
			'target'         => $finding->target,
			'line'           => $finding->line,
			'title'          => $finding->title,
			'message'        => $finding->message,
			'recommendation' => $finding->recommendation,
			'meta'           => wp_json_encode( $finding->meta ),
			'last_seen_at'   => $now,
			'last_scan_id'   => $scan_id,
			'status'         => $status,
		);

		if ( ! $existing ) {
			$data['fingerprint']   = $finding->fingerprint;
			$data['file_hash']     = $finding->file_hash;
			$data['first_seen_at'] = $now;
			$data['first_scan_id'] = $scan_id;
			$data['status_note']   = '';
			$wpdb->insert( $this->table(), $data );
			$finding->id = (int) $wpdb->insert_id;
			$this->add_event( $finding->id, $scan_id, 'detected', null, $status );
		} else {
			$keep_hash = FindingLifecycle::OUTCOME_UNCHANGED === $outcome && FindingStatus::IGNORED === $existing->status;
			if ( ! $keep_hash ) {
				$data['file_hash'] = $finding->file_hash;
			}
			if ( FindingLifecycle::OUTCOME_UNCHANGED !== $outcome ) {
				$data['resolved_at'] = null;
			}
			$wpdb->update( $this->table(), $data, array( 'id' => $existing->id ) );
			$finding->id = $existing->id;
			if ( FindingLifecycle::OUTCOME_UNCHANGED !== $outcome ) {
				$this->add_event( $existing->id, $scan_id, $outcome, $existing->status, $status );
			}
		}

		$stored = $this->get( $finding->id );
		return array( $outcome, $stored ? $stored : $finding );
	}

	/**
	 * Marks findings not seen in the scan as resolved.
	 *
	 * @param int      $scan_id   Scan ID.
	 * @param string[] $check_ids Checks that ran to completion in this scan.
	 * @param array    $keep        List of [check_id, rule_id] pairs whose findings must be kept ('*' = all rules).
	 * @param string[] $keep_scopes Target scopes that were not fully scanned.
	 * @return Finding[] Resolved findings.
	 */
	public function resolve_missing( int $scan_id, array $check_ids, array $keep, array $keep_scopes = array() ): array {
		global $wpdb;
		if ( empty( $check_ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $check_ids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated.
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated.
				"SELECT * FROM {$this->table()} WHERE status <> %s AND last_scan_id < %d AND check_id IN ($placeholders)",
				array_merge( array( FindingStatus::RESOLVED, $scan_id ), $check_ids )
			)
		);

		$resolved = array();
		$now      = current_time( 'mysql', true );
		foreach ( (array) $rows as $row ) {
			$finding = Finding::from_row( $row );
			if ( self::is_kept( $finding, $keep ) || in_array( $finding->target_scope, $keep_scopes, true ) ) {
				continue;
			}
			$wpdb->update(
				$this->table(),
				array(
					'status'      => FindingStatus::RESOLVED,
					'resolved_at' => $now,
				),
				array( 'id' => $finding->id )
			);
			$this->add_event( $finding->id, $scan_id, 'resolved', $finding->status, FindingStatus::RESOLVED );
			$finding->status      = FindingStatus::RESOLVED;
			$finding->resolved_at = $now;
			$resolved[]           = $finding;
		}
		return $resolved;
	}

	/**
	 * Whether a finding is protected from auto-resolution.
	 *
	 * @param Finding $finding Finding.
	 * @param array   $keep    Pairs.
	 * @return bool
	 */
	private static function is_kept( Finding $finding, array $keep ): bool {
		foreach ( $keep as $pair ) {
			if ( $pair[0] === $finding->check_id && ( '*' === $pair[1] || $pair[1] === $finding->rule_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves open findings of a check within a scope (used after approving a baseline).
	 *
	 * @param string $check_id Check ID.
	 * @param string $scope    Target scope.
	 * @param int    $user_id  User.
	 * @return int Number resolved.
	 */
	public function resolve_scope( string $check_id, string $scope, int $user_id ): int {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE check_id = %s AND target_scope = %s AND status <> %s",
				$check_id,
				$scope,
				FindingStatus::RESOLVED
			)
		);
		foreach ( $ids as $id ) {
			$this->change_status( (int) $id, FindingStatus::RESOLVED, __( 'Baseline approved.', 'keep-it-peace' ), $user_id );
		}
		return count( $ids );
	}

	/**
	 * Changes the status of a finding.
	 *
	 * @param int    $id      ID.
	 * @param string $status  New status.
	 * @param string $note    Note.
	 * @param int    $user_id User.
	 * @return array{0:string,1:Finding}|null [old status, updated finding]
	 */
	public function change_status( int $id, string $status, string $note, int $user_id ): ?array {
		global $wpdb;
		$finding = $this->get( $id );
		if ( ! $finding || ! FindingStatus::is_valid( $status ) ) {
			return null;
		}
		$old  = $finding->status;
		$data = array(
			'status'      => $status,
			'status_note' => $note,
			'resolved_at' => FindingStatus::RESOLVED === $status ? current_time( 'mysql', true ) : null,
		);
		$wpdb->update( $this->table(), $data, array( 'id' => $id ) );
		$this->add_event( $id, null, 'status_changed', $old, $status, $user_id, $note );
		return array( $old, $this->get( $id ) );
	}

	/**
	 * Records an event.
	 *
	 * @param int         $finding_id Finding.
	 * @param int|null    $scan_id    Scan.
	 * @param string      $event      Event.
	 * @param string|null $from       Old status.
	 * @param string|null $to         New status.
	 * @param int|null    $user_id    User.
	 * @param string      $note       Note.
	 */
	public function add_event( int $finding_id, ?int $scan_id, string $event, ?string $from, ?string $to, ?int $user_id = null, string $note = '' ): void {
		global $wpdb;
		$wpdb->insert(
			$this->events_table(),
			array(
				'finding_id'  => $finding_id,
				'scan_id'     => $scan_id,
				'event'       => $event,
				'from_status' => $from,
				'to_status'   => $to,
				'user_id'     => $user_id,
				'note'        => $note,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Events of a finding, newest first.
	 *
	 * @param int $finding_id Finding.
	 * @param int $limit      Limit.
	 * @return array
	 */
	public function events_for( int $finding_id, int $limit = 100 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->events_table()} WHERE finding_id = %d ORDER BY id DESC LIMIT %d", $finding_id, $limit )
		);
	}

	/**
	 * Event counts of a scan.
	 *
	 * @param int $scan_id Scan.
	 * @return array<string,int>
	 */
	public function event_counts_for_scan( int $scan_id ): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT event, COUNT(*) AS c FROM {$this->events_table()} WHERE scan_id = %d GROUP BY event", $scan_id )
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->event ] = (int) $row->c;
		}
		return $counts;
	}

	/**
	 * Open findings seen in a scan.
	 *
	 * @param int $scan_id Scan.
	 * @return int
	 */
	public function count_open_seen_in_scan( int $scan_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE last_scan_id = %d AND status IN (%s, %s)",
				$scan_id,
				FindingStatus::NEW_FINDING,
				FindingStatus::ACKNOWLEDGED
			)
		);
	}

	/**
	 * Counts open findings by severity slug.
	 *
	 * @return array<string,int>
	 */
	public function count_open_by_severity(): array {
		global $wpdb;
		$counts = array_fill_keys( array_values( Severity::SLUGS ), 0 );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, COUNT(*) AS c FROM {$this->table()} WHERE status IN (%s, %s) GROUP BY severity",
				FindingStatus::NEW_FINDING,
				FindingStatus::ACKNOWLEDGED
			)
		);
		foreach ( (array) $rows as $row ) {
			$counts[ Severity::slug( (int) $row->severity ) ] = (int) $row->c;
		}
		return $counts;
	}

	/**
	 * Distinct categories.
	 *
	 * @return string[]
	 */
	public function categories(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT category FROM {$this->table()} ORDER BY category" ) );
	}

	/**
	 * Queries findings.
	 *
	 * @param array $args status (string|string[]), severity (int), category, check_id, search, orderby, order, per_page, page.
	 * @return array{items:Finding[],total:int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'status'   => FindingStatus::open(),
				'severity' => 0,
				'category' => '',
				'check_id' => '',
				'search'   => '',
				'orderby'  => 'severity',
				'order'    => 'DESC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$statuses = array_values( array_filter( (array) $args['status'], array( FindingStatus::class, 'is_valid' ) ) );
		if ( $statuses ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}
		if ( $args['severity'] ) {
			$where[]  = 'severity = %d';
			$params[] = (int) $args['severity'];
		}
		if ( '' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}
		if ( '' !== $args['check_id'] ) {
			$where[]  = 'check_id = %s';
			$params[] = $args['check_id'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR target LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$columns = array(
			'severity'      => 'severity',
			'category'      => 'category',
			'status'        => 'status',
			'last_seen_at'  => 'last_seen_at',
			'first_seen_at' => 'first_seen_at',
			'title'         => 'title',
		);
		$orderby = $columns[ $args['orderby'] ] ?? 'severity';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$limit   = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );

		$where_sql = implode( ' AND ', $where );
		$table     = $this->table();

		// Clauses are built from placeholders and an allowlist.
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows      = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				"SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			)
		);

		return array(
			'items' => array_map( array( Finding::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Deletes resolved findings and events older than the cutoff.
	 *
	 * @param string $cutoff UTC datetime.
	 */
	public function delete_older_than( string $cutoff ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE e FROM {$this->events_table()} e INNER JOIN {$this->table()} f ON f.id = e.finding_id WHERE f.status = %s AND f.resolved_at < %s",
				FindingStatus::RESOLVED,
				$cutoff
			)
		);
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE status = %s AND resolved_at < %s", FindingStatus::RESOLVED, $cutoff )
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->events_table()} WHERE created_at < %s", $cutoff ) );
	}
}
