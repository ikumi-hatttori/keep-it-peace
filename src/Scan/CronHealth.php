<?php
/**
 * Detects scans that stopped running.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cron health status.
 */
class CronHealth {

	/**
	 * Status.
	 *
	 * @param Settings $settings Settings.
	 * @return array{healthy:bool,last_completed:int|null,next_run:int|null,wp_cron_disabled:bool,overdue_seconds:int}
	 */
	public static function status( Settings $settings ): array {
		$options   = Scheduler::frequency_options();
		$frequency = (string) $settings->get( 'schedule', 'daily' );
		$interval  = isset( $options[ $frequency ] ) ? (int) $options[ $frequency ]['interval'] : DAY_IN_SECONDS;
		$last      = get_option( Scanner::LAST_COMPLETED_OPTION );
		$last_time = is_array( $last ) && ! empty( $last['time'] ) ? (int) $last['time'] : null;
		$reference = $last_time ? $last_time : (int) get_option( 'keepitpeace_installed_at', time() );
		$threshold = $interval * 2 + DAY_IN_SECONDS;
		$overdue   = time() - $reference - $threshold;
		$next      = wp_next_scheduled( Scheduler::SCAN_HOOK );

		return array(
			'healthy'          => $overdue <= 0 && false !== $next,
			'last_completed'   => $last_time,
			'next_run'         => false === $next ? null : (int) $next,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'overdue_seconds'  => max( 0, $overdue ),
		);
	}
}
