<?php
/**
 * WP-CLI baseline commands.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Cli;

use KeepItPeace\Baseline\BaselineActions;
use KeepItPeace\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the trusted file baseline used by the integrity check.
 */
class BaselineCommand {

	/**
	 * Approves the current files as the trusted baseline (e.g. after a deployment).
	 *
	 * Uses the file state recorded by the last scan, so run `wp keep-it-peace scan` first.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Target to approve, e.g. theme:twentytwentyfive, plugin:my-plugin or path:<id>. See `wp keep-it-peace targets`.
	 *
	 * [--all]
	 * : Approve all targets.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keep-it-peace scan --no-notify && wp keep-it-peace baseline approve --all --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function approve( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$plugin = Plugin::instance();
		$scopes = array_keys( $plugin->targets()->get_targets( $plugin->settings() ) );
		$scope  = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', '' );
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			if ( '' === $scope ) {
				\WP_CLI::error( 'Pass --scope=<scope> or --all.' );
			}
			if ( ! in_array( $scope, $scopes, true ) ) {
				\WP_CLI::error( 'Unknown scope: ' . $scope . '. Known: ' . implode( ', ', $scopes ) );
			}
			$scopes = array( $scope );
		}
		\WP_CLI::confirm( 'Approve the current files of ' . implode( ', ', $scopes ) . ' as trusted?', $assoc_args );
		$user  = get_current_user_id();
		$total = 0;
		foreach ( $scopes as $item ) {
			$count  = BaselineActions::approve( $plugin, $item, $user );
			$total += $count;
			\WP_CLI::log( sprintf( '%s: %d files recorded.', $item, $count ) );
		}
		\WP_CLI::success( sprintf( 'Baseline approved (%d files).', $total ) );
	}
}
