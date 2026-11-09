<?php
/**
 * WP-Cron integration.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Plugin;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules scans, continuation slices and daily maintenance.
 */
class Scheduler {

	const SCAN_HOOK        = 'keepitpeace_scheduled_scan';
	const CONTINUE_HOOK    = 'keepitpeace_continue_scan';
	const MAINTENANCE_HOOK = 'keepitpeace_daily_maintenance';
	const CONTINUE_DELAY   = 60;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( self::SCAN_HOOK, array( $this, 'on_scheduled_scan' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'on_continue' ) );
		add_action( self::MAINTENANCE_HOOK, array( $this->plugin->maintenance(), 'run' ), 5 );
	}

	/**
	 * Frequency options (filterable so add-ons can add e.g. hourly).
	 *
	 * @return array<string,array{label:string,interval:int}>
	 */
	public static function frequency_options(): array {
		$options = array(
			'daily'  => array(
				'label'    => __( 'Daily', 'keep-it-peace' ),
				'interval' => DAY_IN_SECONDS,
			),
			'weekly' => array(
				'label'    => __( 'Weekly', 'keep-it-peace' ),
				'interval' => WEEK_IN_SECONDS,
			),
		);

		/**
		 * Filters scan frequency options.
		 *
		 * @param array $options key => [ label, interval (seconds) ].
		 */
		$options = (array) apply_filters( 'keepitpeace_schedule_options', $options );
		foreach ( $options as $key => $option ) {
			if ( ! is_array( $option ) || empty( $option['interval'] ) ) {
				unset( $options[ $key ] );
			}
		}
		return $options;
	}

	/**
	 * Adds our recurrences to WP-Cron. Hooked at file load so activation can schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		foreach ( self::frequency_options() as $key => $option ) {
			$schedules[ 'keepitpeace_' . $key ] = array(
				'interval' => (int) $option['interval'],
				'display'  => $option['label'],
			);
		}
		return $schedules;
	}

	/**
	 * Ensures events are scheduled with the configured frequency.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function schedule( Settings $settings ): void {
		$options   = self::frequency_options();
		$frequency = (string) $settings->get( 'schedule', 'daily' );
		if ( ! isset( $options[ $frequency ] ) ) {
			$frequency = 'daily';
		}
		$recurrence = 'keepitpeace_' . $frequency;
		$event      = wp_get_scheduled_event( self::SCAN_HOOK );
		if ( ! $event || $event->schedule !== $recurrence ) {
			wp_clear_scheduled_hook( self::SCAN_HOOK );
			wp_schedule_event( self::next_run_time( (int) $options[ $frequency ]['interval'] ), $recurrence, self::SCAN_HOOK );
		}
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}
	}

	/**
	 * First run: next 03:00 in the site's timezone for daily or longer, otherwise soon.
	 *
	 * @param int $interval Interval.
	 * @return int
	 */
	private static function next_run_time( int $interval ): int {
		if ( $interval < DAY_IN_SECONDS ) {
			return time() + 5 * MINUTE_IN_SECONDS;
		}
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$next = $now->setTime( 3, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Removes all events.
	 */
	public static function unschedule_all(): void {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
	}

	/**
	 * Recurring event: starts a scan.
	 */
	public function on_scheduled_scan(): void {
		RuntimeEnvironment::record();
		$this->plugin->scanner()->start( 'cron' );
		$this->on_continue();
	}

	/**
	 * Runs one slice and schedules the next one if needed.
	 */
	public function on_continue(): void {
		$status = $this->plugin->scanner()->run_slice( Scanner::time_budget( 'cron' ) );
		if ( in_array( $status['status'], array( Scan::RUNNING, 'busy' ), true ) ) {
			self::schedule_continuation();
		}
	}

	/**
	 * Schedules the next slice of the running scan.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function schedule_continuation( int $delay = self::CONTINUE_DELAY ): void {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CONTINUE_HOOK );
		}
	}
}
