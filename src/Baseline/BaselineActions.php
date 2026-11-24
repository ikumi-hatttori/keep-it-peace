<?php
/**
 * Baseline approval.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Baseline;

use KeepItPeace\Checks\Files\IntegrityCheck;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\UnreadableDirs;

defined( 'ABSPATH' ) || exit;

/**
 * Approves the state recorded by the latest scan as the new baseline.
 */
final class BaselineActions {

	/**
	 * Directories the last scan could not read in a target. While there are any, the target cannot
	 * be approved: the plugin cannot vouch for files it could not read, and approving would drop
	 * their baseline rows.
	 *
	 * @param string $scope Target ID.
	 * @return string[]
	 */
	public static function blocked_dirs( string $scope ): array {
		return UnreadableDirs::get( $scope );
	}

	/**
	 * Approves a scope.
	 *
	 * @param Plugin $plugin  Plugin.
	 * @param string $scope   Scope (target ID).
	 * @param int    $user_id User.
	 * @return int Files in the baseline, or -1 when approval is blocked (see blocked_dirs()).
	 */
	public static function approve( Plugin $plugin, string $scope, int $user_id ): int {
		if ( self::blocked_dirs( $scope ) ) {
			return -1;
		}
		$files = $plugin->files();
		$count = $files->approve_scope( $scope, $user_id );

		if ( has_action( 'keepitpeace_baseline_file_recorded' ) ) {
			$root = $plugin->targets()->resolve_scope_root( $scope, $plugin->settings() );
			if ( null !== $root ) {
				$after = 0;
				do {
					$rows = $files->rows( $scope, $after, 500 );
					foreach ( $rows as $row ) {
						$after = (int) $row->id;
						IntegrityCheck::fire_recorded( $root . '/' . $row->rel_path, $scope, (string) $row->rel_path, (string) $row->baseline_hash );
					}
					$more = 500 === count( $rows );
				} while ( $more );
			}
		}

		$plugin->findings()->resolve_scope( 'files.integrity', $scope, $user_id );

		/**
		 * Fires after a baseline was approved.
		 *
		 * @param string $scope   Scope.
		 * @param int    $count   Files in the baseline.
		 * @param int    $user_id User.
		 */
		do_action( 'keepitpeace_baseline_approved', $scope, $count, $user_id );
		return $count;
	}
}
