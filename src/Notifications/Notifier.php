<?php
/**
 * Sends reports to channels.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Notifications;

use KeepItPeace\Plugin;
use KeepItPeace\Scan\ScanReport;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches scan reports and digests.
 */
class Notifier {

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
	 * Sends a report to every enabled channel whose condition matches.
	 *
	 * @param ScanReport $report Report.
	 * @return array<string,true|\WP_Error> Results per channel that was attempted.
	 */
	public function notify( ScanReport $report ): array {
		$settings = $this->plugin->settings();
		$notify   = (array) $settings->get( 'notify', array() );
		$results  = array();
		foreach ( $this->plugin->channels()->all() as $id => $channel ) {
			if ( ! $channel->is_enabled( $settings ) || ! NotificationPolicy::should_send( $report, $notify, $id ) ) {
				continue;
			}
			try {
				$result = $channel->send( $report );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'keepitpeace_channel_error', $e->getMessage() );
			}
			$results[ $id ] = $result;

			/**
			 * Fires after a notification was sent (or failed).
			 *
			 * @param string          $channel_id Channel.
			 * @param ScanReport      $report     Report.
			 * @param true|\WP_Error  $result     Result.
			 */
			do_action( 'keepitpeace_notification_sent', $id, $report, $result );
		}
		return $results;
	}

	/**
	 * Sends the weekly digest based on the latest completed scan.
	 *
	 * @return array
	 */
	public function send_digest(): array {
		$scan = $this->plugin->scans()->latest_completed();
		if ( ! $scan ) {
			return array();
		}
		$report              = ScanReport::from_scan( $scan, true );
		$report->by_severity = array_merge( $report->by_severity, $this->plugin->findings()->count_open_by_severity() );
		return $this->notify( $report );
	}

	/**
	 * Sends a test message through one channel.
	 *
	 * @param string $channel_id Channel.
	 * @return true|\WP_Error
	 */
	public function send_test( string $channel_id ) {
		$channel = $this->plugin->channels()->get( $channel_id );
		if ( ! $channel ) {
			return new \WP_Error( 'keepitpeace_unknown_channel', __( 'Unknown notification channel.', 'keep-it-peace' ) );
		}
		return $channel->send_test();
	}
}
