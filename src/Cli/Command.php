<?php
/**
 * WP-CLI commands.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Cli;

use KeepItPeace\Export\ReportExporter;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Keep It Peace security scans and reads their results.
 *
 * Output of these commands is in English on purpose (scripts parse it).
 */
class Command {

	/**
	 * Runs a scan until it completes (resumes a scan that is already running).
	 *
	 * ## OPTIONS
	 *
	 * [--checks=<ids>]
	 * : Comma-separated check IDs to run (default: all enabled checks). See `wp keep-it-peace checks`.
	 *
	 * [--[no-]notify]
	 * : Send notifications as configured (default). Use --no-notify to skip them for this scan.
	 *
	 * [--format=<format>]
	 * : Output format of the summary.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # From the server cron (no output unless something fails)
	 *     wp keep-it-peace scan --quiet
	 *
	 *     # Only the external checks, without e-mail
	 *     wp keep-it-peace scan --checks=external.tls,external.headers --no-notify
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$plugin  = Plugin::instance();
		$quiet   = (bool) \WP_CLI::get_config( 'quiet' );
		$format  = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$options = array( 'notify' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'notify', true ) );
		$checks  = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'checks', '' );
		if ( '' !== $checks ) {
			$options['checks'] = array_filter( array_map( 'trim', explode( ',', $checks ) ) );
			$unknown           = array_diff( $options['checks'], array_keys( $plugin->checks()->all() ) );
			if ( $unknown ) {
				\WP_CLI::error( 'Unknown check: ' . implode( ', ', $unknown ) );
			}
		}

		$running = $plugin->scans()->get_running();
		if ( $running && ( '' !== $checks || ! $options['notify'] ) ) {
			\WP_CLI::warning( sprintf( 'Scan #%d is already running; --checks and --no-notify do not apply to it.', $running->id ) );
		}
		$scan    = $plugin->scanner()->start( 'cli', 0, $options );
		$verbose = ! $quiet && 'json' !== $format;
		if ( $verbose ) {
			\WP_CLI::log( sprintf( 'Scan #%d started.', $scan->id ) );
		}
		$status = $plugin->scanner()->run_to_completion(
			static function ( $status ) use ( $verbose ) {
				if ( $verbose && Scan::RUNNING === $status['status'] ) {
					\WP_CLI::log( sprintf( 'Progress: %d%%', $status['progress'] ) );
				}
				if ( 'busy' === $status['status'] ) {
					sleep( 2 );
				}
			}
		);
		if ( Scan::COMPLETED !== $status['status'] ) {
			\WP_CLI::error( sprintf( 'Scan ended with status: %s', $status['status'] ) );
		}
		if ( $quiet ) {
			return;
		}
		$done = $plugin->scans()->get( (int) $status['scan_id'] );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $this->summary( $done ) ) );
			return;
		}
		$this->status( array(), array() );
	}

	/**
	 * Shows open issues by severity and the last completed scan.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp keep-it-peace status
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$plugin = Plugin::instance();
		$latest = $plugin->scans()->latest_completed();
		$counts = $plugin->findings()->count_open_by_severity();
		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'last_scan' => $latest ? $this->summary( $latest ) : null,
						'open'      => $counts,
					)
				)
			);
			return;
		}
		$rows = array();
		foreach ( Severity::all() as $level ) {
			$rows[] = array(
				'severity' => Severity::slug( $level ),
				'open'     => $counts[ Severity::slug( $level ) ] ?? 0,
			);
		}
		\WP_CLI::log( 'Last completed scan: ' . ( $latest ? $latest->finished_at . ' UTC (#' . $latest->id . ')' : 'never' ) );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'severity', 'open' ) );
	}

	/**
	 * Lists findings.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses (new, acknowledged, ignored, resolved) or "open" (new + acknowledged) or "all".
	 * ---
	 * default: open
	 * ---
	 *
	 * [--severity=<severity>]
	 * : Minimum severity (critical, high, medium, low, info).
	 *
	 * [--category=<category>]
	 * : Category (php, environment, theme, external_path, external, dependency, files).
	 *
	 * [--check=<id>]
	 * : Check ID.
	 *
	 * [--fields=<fields>]
	 * : Fields to show.
	 * ---
	 * default: id,severity,status,check_id,location,title
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp keep-it-peace findings --severity=high
	 *     wp keep-it-peace findings --category=dependency --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function findings( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$status = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'open' );
		if ( 'open' === $status ) {
			$statuses = FindingStatus::open();
		} elseif ( 'all' === $status ) {
			$statuses = array( FindingStatus::NEW_FINDING, FindingStatus::ACKNOWLEDGED, FindingStatus::IGNORED, FindingStatus::RESOLVED );
		} else {
			$statuses = array_filter( array_map( 'trim', explode( ',', $status ) ) );
			foreach ( $statuses as $item ) {
				if ( ! FindingStatus::is_valid( $item ) ) {
					\WP_CLI::error( 'Unknown status: ' . $item );
				}
			}
		}
		$min = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'severity', '' );
		if ( '' !== $min && ! in_array( $min, Severity::SLUGS, true ) ) {
			\WP_CLI::error( 'Unknown severity: ' . $min );
		}
		$min_level = '' === $min ? 0 : Severity::from( $min );

		$result = Plugin::instance()->findings()->query(
			array(
				'status'   => $statuses,
				'category' => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'category', '' ),
				'check_id' => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'check', '' ),
				'per_page' => 100000,
			)
		);
		$items  = array();
		foreach ( $result['items'] as $finding ) {
			if ( $finding->severity < $min_level ) {
				continue;
			}
			$items[] = array(
				'id'             => $finding->id,
				'severity'       => Severity::slug( $finding->severity ),
				'status'         => $finding->status,
				'category'       => $finding->category,
				'check_id'       => $finding->check_id,
				'rule_id'        => $finding->rule_id,
				'scope'          => $finding->target_scope,
				'target'         => $finding->target,
				'line'           => $finding->line,
				'location'       => $finding->target_scope . ' ' . $finding->target . ( null !== $finding->line ? ':' . $finding->line : '' ),
				'title'          => $finding->title,
				'message'        => $finding->message,
				'recommendation' => $finding->recommendation,
				'first_seen_at'  => $finding->first_seen_at,
				'last_seen_at'   => $finding->last_seen_at,
			);
		}
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $items, 'id' ) ) );
			return;
		}
		$fields = explode( ',', (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'id,severity,status,check_id,location,title' ) );
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Prints the JSON report of a scan (see docs/export-schema.json).
	 *
	 * ## OPTIONS
	 *
	 * [--scan=<id>]
	 * : Scan ID. Default: the latest completed scan.
	 *
	 * [--pretty]
	 * : Pretty-print the JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keep-it-peace export --pretty > report.json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$scan_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'scan', null );
		$flags   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'pretty', false ) ? JSON_PRETTY_PRINT : 0;
		$json    = ( new ReportExporter( Plugin::instance() ) )->export_json( null === $scan_id ? null : absint( $scan_id ), $flags );
		if ( null === $json ) {
			\WP_CLI::error( null === $scan_id ? 'No completed scan yet.' : 'Scan not found or not finished.' );
		}
		\WP_CLI::line( $json );
	}

	/**
	 * Lists registered checks.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function checks( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();
		$rows     = array();
		foreach ( $plugin->checks()->all() as $id => $check ) {
			$rows[] = array(
				'id'       => $id,
				'category' => $check->get_category(),
				'type'     => $check instanceof \KeepItPeace\Contracts\FileCheckInterface ? 'file' : 'standalone',
				'enabled'  => $check->is_enabled( $settings ) ? 'yes' : 'no',
				'name'     => $check->get_name(),
			);
		}
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'id', 'category', 'type', 'enabled', 'name' ) );
	}

	/**
	 * Lists scan targets (themes and registered directories).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function targets( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$plugin = Plugin::instance();
		$counts = $plugin->files()->counts();
		$rows   = array();
		foreach ( $plugin->targets()->get_targets( $plugin->settings() ) as $id => $target ) {
			$rows[] = array(
				'scope' => $id,
				'label' => $target->label,
				'path'  => $target->root,
				'files' => $counts[ $id ] ?? 0,
			);
		}
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'scope', 'label', 'path', 'files' ) );
	}

	/**
	 * Machine-readable summary of a scan.
	 *
	 * @param Scan|null $scan Scan.
	 * @return array|null
	 */
	private function summary( ?Scan $scan ): ?array {
		if ( ! $scan ) {
			return null;
		}
		return array(
			'id'          => $scan->id,
			'status'      => $scan->status,
			'finished_at' => $scan->finished_at,
			'by_severity' => $scan->summary['by_severity'] ?? array(),
			'diff'        => $scan->summary['diff'] ?? array(),
		);
	}
}
