<?php
/**
 * Registers the sample add-on with Keep It Peace.
 *
 * @package KeepItPeaceSample
 */

namespace KeepItPeaceSample;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Scan\ScanReport;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the check, the channel and the settings tab into the core plugin's filters and actions.
 */
class Addon {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		// 1. A check. Instances of CheckInterface (or FileCheckInterface for per-file checks).
		add_filter(
			'keepitpeace_checks',
			static function ( array $checks ) {
				$checks[] = new AdminUsernameCheck();
				return $checks;
			}
		);

		// 2. A notification channel.
		add_filter(
			'keepitpeace_notification_channels',
			static function ( array $channels ) {
				$channels[] = new LogChannel();
				return $channels;
			}
		);

		// 3. A settings tab: register it, render it, sanitize its fields.
		add_filter( 'keepitpeace_settings_tabs', array( $this, 'add_tab' ) );
		add_action( 'keepitpeace_settings_tab_sample', array( $this, 'render_tab' ) );
		add_filter( 'keepitpeace_default_settings', array( $this, 'defaults' ) );
		add_filter( 'keepitpeace_sanitize_settings', array( $this, 'sanitize' ), 10, 3 );

		// 4. Extras: data in the JSON export, a panel on the finding detail screen, a reaction to completed scans.
		add_filter( 'keepitpeace_export_data', array( $this, 'export' ), 10, 2 );
		add_action( 'keepitpeace_finding_detail_panels', array( $this, 'detail_panel' ) );
		add_action( 'keepitpeace_scan_completed', array( $this, 'on_scan_completed' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function add_tab( array $tabs ): array {
		$tabs['sample'] = __( 'Sample add-on', 'kip-sample-addon' );
		return $tabs;
	}

	/**
	 * Default values stored inside keepitpeace_settings.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public function defaults( array $defaults ): array {
		$defaults['sample'] = array( 'note' => '' );
		return $defaults;
	}

	/**
	 * Sanitizes the tab. $values is the full settings array; change only your keys.
	 *
	 * @param array  $values Sanitized settings.
	 * @param array  $input  Raw input of the submitted tab.
	 * @param string $tab    Tab ID.
	 * @return array
	 */
	public function sanitize( array $values, array $input, string $tab ): array {
		if ( 'sample' === $tab ) {
			$values['sample'] = array( 'note' => sanitize_text_field( (string) ( $input['sample']['note'] ?? '' ) ) );
		}
		return $values;
	}

	/**
	 * Renders the tab. The form posts to the core settings handler (capability and nonce are checked there).
	 *
	 * @param Settings $settings Settings.
	 */
	public function render_tab( Settings $settings ): void {
		$log = array_reverse( (array) get_option( LogChannel::LOG_OPTION, array() ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="keepitpeace_save_settings">
			<input type="hidden" name="tab" value="sample">
			<?php wp_nonce_field( 'keepitpeace_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kipsample-note"><?php esc_html_e( 'Note', 'kip-sample-addon' ); ?></label></th>
					<td><input type="text" id="kipsample-note" name="keepitpeace[sample][note]" value="<?php echo esc_attr( (string) $settings->get( 'sample.note', '' ) ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Saved in the core settings under "sample" and added to the JSON export.', 'kip-sample-addon' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Notification log', 'kip-sample-addon' ); ?></h2>
		<?php if ( ! $log ) : ?>
			<p><?php esc_html_e( 'Nothing recorded yet. Enable "Log (sample)" in the Notifications tab and send a test.', 'kip-sample-addon' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<?php foreach ( $log as $entry ) : ?>
					<tr><td style="width:180px"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) ); ?></td><td><?php echo esc_html( (string) $entry['message'] ); ?></td></tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Adds data to the JSON export under extensions.<slug>.
	 *
	 * @param array $data    Export.
	 * @param int   $scan_id Scan ID.
	 * @return array
	 */
	public function export( array $data, int $scan_id ): array {
		if ( ! is_array( $data['extensions'] ?? null ) ) {
			$data['extensions'] = array();
		}
		$data['extensions']['sample-addon'] = array(
			'version' => '1.0.0',
			'note'    => (string) \KeepItPeace\Api::get_settings()->get( 'sample.note', '' ),
			'scan_id' => $scan_id,
		);
		return $data;
	}

	/**
	 * Panel on the finding detail screen (Pro could show a diff or an AI explanation here).
	 *
	 * @param Finding $finding Finding.
	 */
	public function detail_panel( Finding $finding ): void {
		?>
		<div class="kip-card">
			<h2><?php esc_html_e( 'Sample add-on', 'kip-sample-addon' ); ?></h2>
			<p>
				<?php
				/* translators: %s: fingerprint. */
				echo esc_html( sprintf( __( 'Fingerprint: %s', 'kip-sample-addon' ), $finding->fingerprint ) );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Called once per completed scan.
	 *
	 * @param ScanReport $report Report.
	 */
	public function on_scan_completed( ScanReport $report ): void {
		update_option( 'kipsample_last_scan', (int) $report->scan->id, false );
	}
}
