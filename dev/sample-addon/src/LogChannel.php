<?php
/**
 * Example notification channel.
 *
 * @package KeepItPeaceSample
 */

namespace KeepItPeaceSample;

use KeepItPeace\Contracts\NotificationChannelInterface;
use KeepItPeace\Scan\ScanReport;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores notifications in an option instead of sending them, so you can see what a real channel
 * (Slack, Chatwork, ...) would receive. Shown in the "Sample add-on" settings tab.
 */
class LogChannel implements NotificationChannelInterface {

	const LOG_OPTION = 'kipsample_notification_log';

	/**
	 * Channel ID; its settings live in keepitpeace_settings['channels'][ID].
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'sample_log';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Log (sample)', 'kip-sample-addon' );
	}

	/**
	 * Enabled when switched on in the notification settings.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool {
		return (bool) $settings->get( 'channels.sample_log.enabled', false );
	}

	/**
	 * "Sends" a report. A real channel would call wp_remote_post() here.
	 * Never include file paths, code or URLs paths in messages sent outside the site.
	 *
	 * @param ScanReport $report Report.
	 * @return true|\WP_Error
	 */
	public function send( ScanReport $report ) {
		return $this->log( $this->format( $report ) );
	}

	/**
	 * Test message.
	 *
	 * @return true|\WP_Error
	 */
	public function send_test() {
		return $this->log( __( 'Test message from the sample add-on.', 'kip-sample-addon' ) );
	}

	/**
	 * Fields in Settings → Notifications (inside the form table).
	 *
	 * @param Settings $settings Settings.
	 */
	public function render_settings( Settings $settings ): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Log (sample)', 'kip-sample-addon' ); ?></th>
			<td>
				<label><input type="checkbox" name="keepitpeace[channels][sample_log][enabled]" value="1" <?php checked( (bool) $settings->get( 'channels.sample_log.enabled', false ) ); ?>> <?php esc_html_e( 'Record notifications in the sample add-on log', 'kip-sample-addon' ); ?></label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitizes the fields rendered above.
	 *
	 * @param array $input   Raw input.
	 * @param array $current Current values.
	 * @return array
	 */
	public function sanitize_settings( array $input, array $current ): array {
		return array( 'enabled' => ! empty( $input['enabled'] ) );
	}

	/**
	 * Plain-text summary, the kind of message a chat channel would send.
	 *
	 * @param ScanReport $report Report.
	 * @return string
	 */
	public function format( ScanReport $report ): string {
		$counts = array();
		foreach ( $report->by_severity as $slug => $count ) {
			$counts[] = $slug . ': ' . (int) $count;
		}
		return sprintf(
			'%1$s %2$s — %3$s | new %4$d, resolved %5$d | %6$s',
			$report->is_digest ? '[digest]' : '[scan #' . (int) $report->scan->id . ']',
			$report->site_name,
			implode( ', ', $counts ),
			(int) $report->diff['new'],
			(int) $report->diff['resolved'],
			$report->admin_url
		);
	}

	/**
	 * Appends to the log (last 20 entries).
	 *
	 * @param string $message Message.
	 * @return true
	 */
	private function log( string $message ) {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'message' => $message,
		);
		update_option( self::LOG_OPTION, array_slice( $log, -20 ), false );
		return true;
	}
}
