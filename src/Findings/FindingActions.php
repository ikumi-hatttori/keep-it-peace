<?php
/**
 * User actions on findings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

use KeepItPeace\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Status changes initiated by users (admin screens, REST, CLI).
 */
final class FindingActions {

	/**
	 * Changes the status of a finding and fires the hook.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param int    $id     Finding.
	 * @param string $status New status.
	 * @param string $note   Note (e.g. reason for ignoring).
	 * @return Finding|null
	 */
	public static function change_status( Plugin $plugin, int $id, string $status, string $note ): ?Finding {
		$user_id = get_current_user_id();
		$result  = $plugin->findings()->change_status( $id, $status, $note, $user_id );
		if ( null === $result ) {
			return null;
		}
		list( $old, $finding ) = $result;

		/**
		 * Fires when a user changes a finding's status.
		 *
		 * @param Finding $finding Finding.
		 * @param string  $old     Old status.
		 * @param string  $new     New status.
		 * @param int     $user_id User.
		 * @param string  $note    Note.
		 */
		do_action( 'keepitpeace_finding_status_changed', $finding, $old, $status, $user_id, $note );
		return $finding;
	}
}
