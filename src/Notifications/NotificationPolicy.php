<?php
/**
 * Decides whether a report is sent.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Notifications;

use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\ScanReport;

defined( 'ABSPATH' ) || exit;

/**
 * Notification conditions.
 */
final class NotificationPolicy {

	const ALWAYS       = 'always';
	const ISSUES_ONLY  = 'issues_only';
	const NEW_ONLY     = 'new_only';
	const MIN_SEVERITY = 'min_severity';

	/**
	 * Condition labels.
	 *
	 * @return array<string,string>
	 */
	public static function conditions(): array {
		return array(
			self::ALWAYS       => __( 'After every scan', 'keep-it-peace' ),
			self::ISSUES_ONLY  => __( 'Only when there are open issues', 'keep-it-peace' ),
			self::NEW_ONLY     => __( 'Only when new issues were found', 'keep-it-peace' ),
			self::MIN_SEVERITY => __( 'Only when issues at or above a severity exist', 'keep-it-peace' ),
		);
	}

	/**
	 * Pure decision.
	 *
	 * @param ScanReport $report       Report.
	 * @param string     $condition    Condition.
	 * @param string     $min_severity Severity slug for MIN_SEVERITY.
	 * @return bool
	 */
	public static function evaluate( ScanReport $report, string $condition, string $min_severity ): bool {
		switch ( $condition ) {
			case self::ALWAYS:
				return true;
			case self::NEW_ONLY:
				return (int) $report->diff['new'] > 0;
			case self::MIN_SEVERITY:
				return $report->max_severity() >= Severity::from( $min_severity );
			case self::ISSUES_ONLY:
			default:
				return $report->has_issues();
		}
	}

	/**
	 * Decision with the settings and filter applied.
	 *
	 * @param ScanReport $report     Report.
	 * @param array      $notify     `notify` settings.
	 * @param string     $channel_id Channel.
	 * @return bool
	 */
	public static function should_send( ScanReport $report, array $notify, string $channel_id ): bool {
		$send = $report->is_digest ? true : self::evaluate( $report, (string) ( $notify['condition'] ?? self::ISSUES_ONLY ), (string) ( $notify['min_severity'] ?? 'high' ) );

		/**
		 * Filters whether a report is sent to a channel.
		 *
		 * @param bool       $send       Decision.
		 * @param ScanReport $report     Report.
		 * @param string     $channel_id Channel ID.
		 */
		return (bool) apply_filters( 'keepitpeace_should_notify', $send, $report, $channel_id );
	}
}
