<?php
/**
 * WP-CLI commands.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Cli;

use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Keep It Peace security scans.
 */
class Command {

	/**
	 * Runs a full scan (resumes a running one).
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 *     wp keep-it-peace scan
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$plugin = Plugin::instance();
		$quiet  = (bool) \WP_CLI::get_config( 'quiet' );
		$scan   = $plugin->scanner()->start( 'cli' );
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Scan #%d started.', $scan->id ) );
		}
		$status = $plugin->scanner()->run_to_completion(
			static function ( $status ) use ( $quiet ) {
				if ( ! $quiet && Scan::RUNNING === $status['status'] ) {
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
		if ( ! $quiet ) {
			$this->status( array(), array() );
		}
	}

	/**
	 * Shows open issues by severity and the last scan.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$plugin = Plugin::instance();
		$latest = $plugin->scans()->latest_completed();
		$counts = $plugin->findings()->count_open_by_severity();
		$rows   = array();
		foreach ( Severity::all() as $level ) {
			$rows[] = array(
				'severity' => Severity::slug( $level ),
				'open'     => $counts[ Severity::slug( $level ) ] ?? 0,
			);
		}
		\WP_CLI::log( 'Last completed scan: ' . ( $latest ? $latest->finished_at . ' UTC (#' . $latest->id . ')' : 'never' ) );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'severity', 'open' ) );
	}
}
