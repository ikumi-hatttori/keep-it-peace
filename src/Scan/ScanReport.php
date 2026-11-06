<?php
/**
 * Summary of a completed scan (passed to notification channels and hooks).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Findings\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Report without attacker-useful details (no paths, no code).
 */
class ScanReport {

	/**
	 * Scan.
	 *
	 * @var Scan
	 */
	public $scan;
	/**
	 * Site name.
	 *
	 * @var string
	 */
	public $site_name = '';
	/**
	 * Site url.
	 *
	 * @var string
	 */
	public $site_url = '';
	/**
	 * Link to the plugin dashboard.
	 *
	 * @var string
	 */
	public $admin_url = '';
	/**
	 * Open findings by severity slug.
	 *
	 * @var array<string,int>
	 */
	public $by_severity = array();
	/**
	 * Diff.
	 *
	 * @var array{new:int,resolved:int,ongoing:int}
	 */
	public $diff = array(
		'new'      => 0,
		'resolved' => 0,
		'ongoing'  => 0,
	);
	/**
	 * PHP status (see PhpVersionCheck).
	 *
	 * @var array|null
	 */
	public $php = null;
	/**
	 * Database status.
	 *
	 * @var array|null
	 */
	public $database = null;
	/**
	 * TLS status.
	 *
	 * @var array|null
	 */
	public $tls = null;
	/**
	 * Weekly digest instead of a per-scan report.
	 *
	 * @var bool
	 */
	public $is_digest = false;

	/**
	 * Builds a report from a finished scan.
	 *
	 * @param Scan $scan      Scan.
	 * @param bool $is_digest Digest.
	 * @return self
	 */
	public static function from_scan( Scan $scan, bool $is_digest = false ): self {
		$report              = new self();
		$report->scan        = $scan;
		$report->is_digest   = $is_digest;
		$report->site_name   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$report->site_url    = home_url( '/' );
		$report->admin_url   = admin_url( 'admin.php?page=keep-it-peace' );
		$summary             = $scan->summary;
		$report->by_severity = array_merge( array_fill_keys( array_values( Severity::SLUGS ), 0 ), (array) ( $summary['by_severity'] ?? array() ) );
		if ( isset( $summary['diff'] ) && is_array( $summary['diff'] ) ) {
			$report->diff = array_merge( $report->diff, $summary['diff'] );
		}
		$data             = (array) ( $summary['data'] ?? array() );
		$report->php      = isset( $data['php.version'] ) ? $data['php.version'] : null;
		$report->database = isset( $data['server.database'] ) ? $data['server.database'] : null;
		$report->tls      = isset( $data['external.tls'] ) ? $data['external.tls'] : null;
		return $report;
	}

	/**
	 * Highest severity among open findings (Info excluded). 0 when none.
	 *
	 * @return int
	 */
	public function max_severity(): int {
		foreach ( Severity::all() as $level ) {
			if ( Severity::INFO !== $level && ! empty( $this->by_severity[ Severity::slug( $level ) ] ) ) {
				return $level;
			}
		}
		return 0;
	}

	/**
	 * Whether there are open problems (Low or higher).
	 *
	 * @return bool
	 */
	public function has_issues(): bool {
		return $this->max_severity() >= Severity::LOW;
	}

	/**
	 * Total open findings (Info excluded).
	 *
	 * @return int
	 */
	public function total_issues(): int {
		$total = 0;
		foreach ( $this->by_severity as $slug => $count ) {
			if ( 'info' !== $slug ) {
				$total += (int) $count;
			}
		}
		return $total;
	}
}
