<?php
/**
 * Admin bootstrap: menu, assets, form handlers, notices, dashboard widget.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Export\ReportExporter;
use KeepItPeace\Baseline\BaselineActions;
use KeepItPeace\Findings\FindingActions;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\CronHealth;
use KeepItPeace\Scan\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the admin UI.
 */
class Admin {

	const SLUG          = 'keep-it-peace';
	const FINDINGS_SLUG = 'keep-it-peace-findings';
	const SCANS_SLUG    = 'keep-it-peace-scans';
	const SETTINGS_SLUG = 'keep-it-peace-settings';
	const PRO_URL       = 'https://keep-it-peace.com/pro';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Hook suffixes of our pages.
	 *
	 * @var string[]
	 */
	private $hooks = array();

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'admin_post_keepitpeace_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_keepitpeace_finding_status', array( $this, 'handle_finding_status' ) );
		add_action( 'admin_post_keepitpeace_approve_baseline', array( $this, 'handle_approve_baseline' ) );
		add_action( 'admin_post_keepitpeace_test_notification', array( $this, 'handle_test_notification' ) );
		add_action( 'admin_post_keepitpeace_dismiss_pro', array( $this, 'handle_dismiss_pro' ) );
		add_action( 'admin_post_keepitpeace_export', array( $this, 'handle_export' ) );
		add_filter(
			'set_screen_option_keepitpeace_findings_per_page',
			static function ( $keep, $option, $value ) {
				return max( 1, min( 500, (int) $value ) );
			},
			10,
			3
		);
		add_filter( 'plugin_action_links_' . plugin_basename( KEEPITPEACE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Admin menu.
	 */
	public function menu(): void {
		$cap       = Plugin::capability();
		$dashboard = new DashboardPage( $this->plugin );
		$findings  = new FindingsPage( $this->plugin );
		$scans     = new ScansPage( $this->plugin );
		$settings  = new SettingsPage( $this->plugin );

		$this->hooks[] = add_menu_page( 'Keep It Peace', 'Keep It Peace', $cap, self::SLUG, array( $dashboard, 'render' ), 'dashicons-shield', 80 );
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Dashboard', 'keep-it-peace' ), __( 'Dashboard', 'keep-it-peace' ), $cap, self::SLUG, array( $dashboard, 'render' ) );
		$hook          = add_submenu_page( self::SLUG, __( 'Findings', 'keep-it-peace' ), __( 'Findings', 'keep-it-peace' ), $cap, self::FINDINGS_SLUG, array( $findings, 'render' ) );
		$this->hooks[] = $hook;
		add_action( 'load-' . $hook, array( $findings, 'load' ) );
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Scan history', 'keep-it-peace' ), __( 'Scan history', 'keep-it-peace' ), $cap, self::SCANS_SLUG, array( $scans, 'render' ) );
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Settings', 'keep-it-peace' ), __( 'Settings', 'keep-it-peace' ), $cap, self::SETTINGS_SLUG, array( $settings, 'render' ) );
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @return bool
	 */
	private function is_our_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, $this->hooks, true );
	}

	/**
	 * Assets on our screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) && 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'keepitpeace-admin', KEEPITPEACE_URL . 'assets/admin.css', array(), KEEPITPEACE_VERSION );
		if ( 'index.php' === $hook ) {
			return;
		}
		wp_enqueue_script( 'keepitpeace-admin', KEEPITPEACE_URL . 'assets/admin.js', array(), KEEPITPEACE_VERSION, true );
		wp_localize_script(
			'keepitpeace-admin',
			'keepitpeaceAdmin',
			array(
				'root'  => esc_url_raw( rest_url( 'keepitpeace/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'starting' => __( 'Starting scan…', 'keep-it-peace' ),
					/* translators: %d: progress percentage. */
					'running'  => __( 'Scanning… %d%%', 'keep-it-peace' ),
					'busy'     => __( 'Another scan step is running. Waiting…', 'keep-it-peace' ),
					'done'     => __( 'Scan completed. Reloading…', 'keep-it-peace' ),
					'failed'   => __( 'The scan failed. Check the scan history for details.', 'keep-it-peace' ),
					/* translators: %s: error message. */
					'error'    => __( 'Request failed: %s', 'keep-it-peace' ),
				),
			)
		);
	}

	/**
	 * Notices shown only on our screens.
	 */
	public function notices(): void {
		if ( ! $this->is_our_screen() || ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		$health = CronHealth::status( $this->plugin->settings() );
		if ( ! $health['healthy'] ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Scheduled scans may not be running.', 'keep-it-peace' ) . '</strong> ';
			esc_html_e( 'No scan has completed for longer than expected. WP-Cron only runs when the site receives visits; set up a server cron job (see Settings → Tools) to run scans reliably.', 'keep-it-peace' );
			echo '</p></div>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only message codes.
		if ( empty( $_GET['keepitpeace_msg'] ) ) {
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['keepitpeace_msg'] ) );
		$text = isset( $_GET['keepitpeace_text'] ) ? sanitize_text_field( wp_unslash( $_GET['keepitpeace_text'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'saved'        => array( 'success', __( 'Settings saved.', 'keep-it-peace' ) ),
			'saved_errors' => array( 'warning', __( 'Settings saved, but some values were not accepted:', 'keep-it-peace' ) ),
			'no_report'    => array( 'error', __( 'There is no completed scan to export yet.', 'keep-it-peace' ) ),
			'status'       => array( 'success', __( 'Status updated.', 'keep-it-peace' ) ),
			'bulk'         => array( 'success', __( 'Selected findings were updated.', 'keep-it-peace' ) ),
			'baseline'     => array( 'success', __( 'The baseline was approved.', 'keep-it-peace' ) ),
			'test_sent'    => array( 'success', __( 'Test notification sent.', 'keep-it-peace' ) ),
			'test_failed'  => array( 'error', __( 'Test notification failed.', 'keep-it-peace' ) ),
			'not_found'    => array( 'error', __( 'Item not found.', 'keep-it-peace' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] ),
			esc_html( $text )
		);
	}

	/**
	 * WP dashboard widget.
	 */
	public function dashboard_widget(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'keepitpeace_summary', 'Keep It Peace', array( new DashboardPage( $this->plugin ), 'render_widget' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'keep-it-peace' ) . '</a>' );
		return $links;
	}

	/**
	 * Admin URL of one of our pages.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Verifies capability and nonce for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'keep-it-peace' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back with a message.
	 *
	 * @param string $url  URL.
	 * @param string $code Message code.
	 * @param string $text Extra text.
	 */
	private function redirect( string $url, string $code, string $text = '' ): void {
		$args = array( 'keepitpeace_msg' => $code );
		if ( '' !== $text ) {
			$args['keepitpeace_text'] = rawurlencode( $text );
		}
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Handlers below verify the nonce in guard().

	/**
	 * Saves a settings tab.
	 */
	public function handle_save_settings(): void {
		$this->guard( 'keepitpeace_save_settings' );
		$tab       = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$input     = isset( $_POST['keepitpeace'] ) && is_array( $_POST['keepitpeace'] ) ? wp_unslash( $_POST['keepitpeace'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in SettingsSanitizer.
		$settings  = $this->plugin->settings();
		$old       = $settings->get( 'schedule' );
		$sanitizer = new SettingsSanitizer( $this->plugin );
		$values    = $sanitizer->sanitize_tab( $tab, $input, $settings->all() );
		$settings->save( $values );
		$this->plugin->reset_settings();
		if ( $old !== $this->plugin->settings()->get( 'schedule' ) ) {
			Scheduler::schedule( $this->plugin->settings() );
		}
		if ( $sanitizer->errors ) {
			$this->redirect( self::url( self::SETTINGS_SLUG, array( 'tab' => $tab ) ), 'saved_errors', implode( ' / ', $sanitizer->errors ) );
		}
		$this->redirect( self::url( self::SETTINGS_SLUG, array( 'tab' => $tab ) ), 'saved' );
	}

	/**
	 * Changes a finding's status from the detail screen.
	 */
	public function handle_finding_status(): void {
		$this->guard( 'keepitpeace_finding_status' );
		$id     = isset( $_POST['finding'] ) ? absint( $_POST['finding'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$url    = self::url( self::FINDINGS_SLUG, array( 'finding' => $id ) );
		if ( ! FindingStatus::is_valid( $status ) || null === FindingActions::change_status( $this->plugin, $id, $status, $note ) ) {
			$this->redirect( $url, 'not_found' );
		}
		$this->redirect( $url, 'status' );
	}

	/**
	 * Approves the baseline of one scope or all scopes.
	 */
	public function handle_approve_baseline(): void {
		$this->guard( 'keepitpeace_approve_baseline' );
		$scope  = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
		$scopes = array_keys( $this->plugin->targets()->get_targets( $this->plugin->settings() ) );
		if ( '__all__' !== $scope ) {
			$scopes = in_array( $scope, $scopes, true ) ? array( $scope ) : array();
		}
		if ( empty( $scopes ) ) {
			$this->redirect( self::url( self::SETTINGS_SLUG, array( 'tab' => 'tools' ) ), 'not_found' );
		}
		$total = 0;
		foreach ( $scopes as $item ) {
			$total += BaselineActions::approve( $this->plugin, $item, get_current_user_id() );
		}
		/* translators: %d: number of files. */
		$this->redirect( self::url( self::SETTINGS_SLUG, array( 'tab' => 'tools' ) ), 'baseline', sprintf( _n( '%d file recorded.', '%d files recorded.', $total, 'keep-it-peace' ), $total ) );
	}

	/**
	 * Sends a test notification.
	 */
	public function handle_test_notification(): void {
		$this->guard( 'keepitpeace_test_notification' );
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : 'email';
		$result  = $this->plugin->notifier()->send_test( $channel );
		$url     = self::url( self::SETTINGS_SLUG, array( 'tab' => 'notifications' ) );
		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'test_failed', $result->get_error_message() );
		}
		$this->redirect( $url, 'test_sent' );
	}

	/**
	 * Hides the Pro notice for the current user.
	 */
	public function handle_dismiss_pro(): void {
		$this->guard( 'keepitpeace_dismiss_pro' );
		update_user_meta( get_current_user_id(), 'keepitpeace_hide_pro_notice', 1 );
		wp_safe_redirect( self::url( self::SETTINGS_SLUG ) );
		exit;
	}

	/**
	 * Downloads the latest report as JSON.
	 */
	public function handle_export(): void {
		$this->guard( 'keepitpeace_export' );
		$scan = $this->plugin->scans()->latest_completed();
		$json = $scan ? ( new ReportExporter( $this->plugin ) )->export_json( $scan->id, JSON_PRETTY_PRINT ) : null;
		if ( null === $json ) {
			$this->redirect( self::url( self::SETTINGS_SLUG, array( 'tab' => 'tools' ) ), 'no_report' );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="keep-it-peace-report-' . (int) $scan->id . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	// phpcs:enable
}
