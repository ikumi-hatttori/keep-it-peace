<?php
/**
 * Example standalone check.
 *
 * @package KeepItPeaceSample
 */

namespace KeepItPeaceSample;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Reports administrator accounts with easily guessed user names.
 *
 * Extending AbstractCheck gives is_enabled() (on/off in Settings → Checks) and the finding() helper.
 * A check only has to return findings; the scanner stores them, detects new/resolved ones and notifies.
 */
class AdminUsernameCheck extends AbstractCheck {

	/**
	 * Guessable names.
	 */
	const NAMES = array( 'admin', 'administrator', 'root', 'test', 'user', 'webmaster' );

	/**
	 * Unique ID: use your own prefix.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'sample.admin_username';
	}

	/**
	 * Name shown in Settings → Checks.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Guessable administrator user names (sample)', 'kip-sample-addon' );
	}

	/**
	 * Category. Use an existing one (environment, php, ...) or your own slug.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'environment';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Example check from the sample add-on: finds administrators named admin, root, test and similar.', 'kip-sample-addon' );
	}

	/**
	 * Runs the check.
	 *
	 * Long-running checks can return CheckResult::partial( $findings, $cursor, $progress ) and continue
	 * in the next slice; $cursor is passed back in. Respect $context->has_time().
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor (empty on the first call).
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$findings = array();
		$users    = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login' ),
			)
		);
		foreach ( $users as $user ) {
			if ( ! in_array( strtolower( $user->user_login ), self::NAMES, true ) ) {
				continue;
			}
			$findings[] = $this->finding(
				array(
					// rule_id + target make the finding's identity: the same account is the same finding in every scan.
					'rule_id'        => 'sample.guessable_admin',
					'severity'       => Severity::MEDIUM,
					'target'         => 'user #' . (int) $user->ID,
					/* translators: %s: user name. */
					'title'          => sprintf( __( 'Administrator with a guessable user name: %s', 'kip-sample-addon' ), $user->user_login ),
					'message'        => __( 'Attackers try common user names first when guessing passwords.', 'kip-sample-addon' ),
					'recommendation' => __( 'Create a new administrator with a unique name, move the content to it and delete this account.', 'kip-sample-addon' ),
				)
			);
		}

		// The second argument is dashboard data, available to channels and the export as summary.data[check id].
		return CheckResult::complete( $findings, array( 'guessable_admins' => count( $findings ) ) );
	}
}
