<?php
/**
 * Daily maintenance: retention, stale scans, weekly digest.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on the `keepitpeace_daily_maintenance` event.
 */
class Maintenance {

	const DIGEST_OPTION = 'keepitpeace_last_digest';

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
	 * Runs maintenance.
	 */
	public function run(): void {
		$this->plugin->scans()->abort_stale( DAY_IN_SECONDS );
		$this->apply_retention();
		$this->maybe_send_digest();
	}

	/**
	 * Deletes history older than the retention period.
	 */
	public function apply_retention(): void {
		$days = $this->plugin->settings()->retention_days();
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$latest = $this->plugin->scans()->latest_completed();
		$this->plugin->scans()->delete_older_than( $cutoff, $latest ? $latest->id : 0 );
		$this->plugin->findings()->delete_older_than( $cutoff );
	}

	/**
	 * Sends the weekly digest when due.
	 */
	public function maybe_send_digest(): void {
		if ( ! $this->plugin->settings()->get( 'notify.digest', true ) ) {
			return;
		}
		$last = (int) get_option( self::DIGEST_OPTION, 0 );
		if ( 0 === $last ) {
			update_option( self::DIGEST_OPTION, time(), false );
			return;
		}
		if ( time() - $last < WEEK_IN_SECONDS - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::DIGEST_OPTION, time(), false );
		$this->plugin->notifier()->send_digest();
	}
}
