<?php
/**
 * File hash baseline.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Baseline;

use KeepItPeace\Scan\FileInfo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name comes from $wpdb->prefix.

/**
 * Stores baseline and current values per file.
 */
class FileIndexRepository {

	const SCOPES_OPTION = 'keepitpeace_baseline_scopes';

	/**
	 * Table.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'keepitpeace_files';
	}

	/**
	 * Path hash used as unique key.
	 *
	 * @param string $scope Scope.
	 * @param string $rel   Relative path.
	 * @return string
	 */
	public static function path_hash( string $scope, string $rel ): string {
		return hash( 'sha256', $scope . "\0" . $rel );
	}

	/**
	 * Whether the scope already has a baseline.
	 *
	 * @param string $scope Scope.
	 * @return bool
	 */
	public function is_scope_initialized( string $scope ): bool {
		$scopes = get_option( self::SCOPES_OPTION, array() );
		return is_array( $scopes ) && isset( $scopes[ $scope ] );
	}

	/**
	 * Marks the scope as having a baseline.
	 *
	 * @param string $scope   Scope.
	 * @param int    $user_id User (0 = automatic).
	 */
	public function mark_scope_initialized( string $scope, int $user_id = 0 ): void {
		$scopes           = get_option( self::SCOPES_OPTION, array() );
		$scopes           = is_array( $scopes ) ? $scopes : array();
		$scopes[ $scope ] = array(
			'approved_at' => time(),
			'approved_by' => $user_id,
		);
		update_option( self::SCOPES_OPTION, $scopes, false );
	}

	/**
	 * Initialized scopes.
	 *
	 * @return array<string,array{approved_at:int,approved_by:int}>
	 */
	public function scopes(): array {
		$scopes = get_option( self::SCOPES_OPTION, array() );
		return is_array( $scopes ) ? $scopes : array();
	}

	/**
	 * Records the current state of a file and returns its baseline.
	 *
	 * @param string   $scope   Scope.
	 * @param FileInfo $file    File.
	 * @param string   $hash    sha256.
	 * @param int      $scan_id Scan.
	 * @return array{baseline_hash:?string,baseline_size:?int,baseline_perms:?string}
	 */
	public function record( string $scope, FileInfo $file, string $hash, int $scan_id ): array {
		global $wpdb;
		$path_hash = self::path_hash( $scope, $file->rel_path );
		$row       = $wpdb->get_row( $wpdb->prepare( "SELECT id, baseline_hash, baseline_size, baseline_perms FROM {$this->table()} WHERE path_hash = %s", $path_hash ) );
		$data      = array(
			'current_hash'      => $hash,
			'current_size'      => $file->size,
			'current_mtime'     => $file->mtime,
			'current_perms'     => $file->perms_octal(),
			'last_seen_scan_id' => $scan_id,
		);
		if ( $row ) {
			$wpdb->update( $this->table(), $data, array( 'id' => (int) $row->id ) );
			return array(
				'baseline_hash'  => $row->baseline_hash,
				'baseline_size'  => null === $row->baseline_size ? null : (int) $row->baseline_size,
				'baseline_perms' => $row->baseline_perms,
			);
		}
		$data['scope']     = $scope;
		$data['rel_path']  = $file->rel_path;
		$data['path_hash'] = $path_hash;
		$wpdb->insert( $this->table(), $data );
		return array(
			'baseline_hash'  => null,
			'baseline_size'  => null,
			'baseline_perms' => null,
		);
	}

	/**
	 * Copies current values to the baseline for one file.
	 *
	 * @param string $scope   Scope.
	 * @param string $rel     Relative path.
	 * @param int    $user_id User.
	 */
	public function approve_file( string $scope, string $rel, int $user_id = 0 ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET baseline_hash = current_hash, baseline_size = current_size, baseline_mtime = current_mtime, baseline_perms = current_perms, approved_at = %s, approved_by = %d WHERE path_hash = %s",
				current_time( 'mysql', true ),
				$user_id,
				self::path_hash( $scope, $rel )
			)
		);
	}

	/**
	 * Files of a scope with a baseline that were not seen in the scan (deleted).
	 *
	 * @param string $scope   Scope.
	 * @param int    $scan_id Scan.
	 * @param int    $limit   Limit.
	 * @return array Rows with rel_path, baseline_hash.
	 */
	public function missing( string $scope, int $scan_id, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rel_path, baseline_hash FROM {$this->table()} WHERE scope = %s AND last_seen_scan_id < %d AND baseline_hash IS NOT NULL ORDER BY rel_path LIMIT %d",
				$scope,
				$scan_id,
				$limit
			)
		);
	}

	/**
	 * Removes rows of files that disappeared and were never part of the baseline.
	 *
	 * @param string $scope   Scope.
	 * @param int    $scan_id Scan.
	 */
	public function purge_unbaselined_missing( string $scope, int $scan_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE scope = %s AND last_seen_scan_id < %d AND baseline_hash IS NULL", $scope, $scan_id )
		);
	}

	/**
	 * Approves the latest scanned state of a scope as the new baseline.
	 *
	 * @param string $scope   Scope.
	 * @param int    $user_id User.
	 * @return int Files in the new baseline.
	 */
	public function approve_scope( string $scope, int $user_id ): int {
		global $wpdb;
		$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(last_seen_scan_id) FROM {$this->table()} WHERE scope = %s", $scope ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE scope = %s AND last_seen_scan_id < %d", $scope, $latest ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET baseline_hash = current_hash, baseline_size = current_size, baseline_mtime = current_mtime, baseline_perms = current_perms, approved_at = %s, approved_by = %d WHERE scope = %s",
				current_time( 'mysql', true ),
				$user_id,
				$scope
			)
		);
		$this->mark_scope_initialized( $scope, $user_id );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE scope = %s", $scope ) );
	}

	/**
	 * Baseline rows of a scope (paged by ID).
	 *
	 * @param string $scope    Scope.
	 * @param int    $after_id Last ID of the previous page.
	 * @param int    $limit    Limit.
	 * @return array
	 */
	public function rows( string $scope, int $after_id, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, rel_path, baseline_hash FROM {$this->table()} WHERE scope = %s AND id > %d ORDER BY id LIMIT %d", $scope, $after_id, $limit )
		);
	}

	/**
	 * File counts per scope.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT scope, COUNT(*) AS c FROM {$this->table()} WHERE baseline_hash IS NOT NULL GROUP BY scope" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->scope ] = (int) $row->c;
		}
		return $out;
	}
}
