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
use KeepItPeace\Install\Installer;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\CronHealth;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Settings\RemoteConsent;

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
		add_action( 'admin_post_keepitpeace_remote_consent', array( $this, 'handle_remote_consent' ) );
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

		$this->hooks[] = add_menu_page( 'Keep It Peace', 'Keep It Peace', $cap, self::SLUG, $this->screen( $dashboard ), 'dashicons-shield', 80 );
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Dashboard', 'keep-it-peace' ), __( 'Dashboard', 'keep-it-peace' ), $cap, self::SLUG, $this->screen( $dashboard ) );
		$hook          = add_submenu_page( self::SLUG, __( 'Findings', 'keep-it-peace' ), __( 'Findings', 'keep-it-peace' ), $cap, self::FINDINGS_SLUG, $this->screen( $findings ) );
		$this->hooks[] = $hook;
		add_action(
			'load-' . $hook,
			static function () use ( $findings ) {
				if ( Installer::is_ready() ) {
					$findings->load();
				}
			}
		);
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Scan history', 'keep-it-peace' ), __( 'Scan history', 'keep-it-peace' ), $cap, self::SCANS_SLUG, $this->screen( $scans ) );
		$this->hooks[] = add_submenu_page( self::SLUG, __( 'Settings', 'keep-it-peace' ), __( 'Settings', 'keep-it-peace' ), $cap, self::SETTINGS_SLUG, $this->screen( $settings ) );
	}

	/**
	 * Page callback that renders only the installation notice while the tables are missing.
	 *
	 * @param object $page Page object with a render() method.
	 * @return callable
	 */
	private function screen( $page ): callable {
		return static function () use ( $page ) {
			if ( ! Installer::is_ready() ) {
				echo '<div class="wrap kip-wrap"><h1>Keep It Peace</h1></div>';
				return;
			}
			$page->render();
		};
	}

	/**
	 * Explains a failed table installation (shown on our screens only, never during activation).
	 */
	private function install_notice(): void {
		$error = Installer::last_error();
		?>
		<div class="notice notice-error kip-install-error">
			<p><strong><?php esc_html_e( 'Keep It Peace could not create its database tables.', 'keep-it-peace' ); ?></strong></p>
			<p><?php esc_html_e( 'Scans are disabled until the tables exist. Creating them is retried each time you open a Keep It Peace screen. If this keeps failing, ask your host to check the database user\'s CREATE and ALTER privileges.', 'keep-it-peace' ); ?></p>
			<?php if ( $error ) : ?>
				<p>
					<?php
					/* translators: %s: table names. */
					echo esc_html( sprintf( __( 'Missing tables: %s', 'keep-it-peace' ), implode( ', ', (array) $error['missing'] ) ) );
					?>
				</p>
				<?php foreach ( (array) $error['errors'] as $table => $message ) : ?>
					<p><code><?php echo esc_html( $table . ': ' . $message ); ?></code></p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
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
		$is_wp_dashboard = 'index.php' === $hook && current_user_can( Plugin::capability() );
		if ( ! in_array( $hook, $this->hooks, true ) && ! $is_wp_dashboard ) {
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
	 * Asks once whether third-party vulnerability databases may be used (opt-in).
	 */
	private function consent_notice(): void {
		?>
		<div class="notice notice-info kip-consent">
			<p><strong><?php esc_html_e( 'Use external vulnerability databases?', 'keep-it-peace' ); ?></strong></p>
			<p><?php esc_html_e( 'Keep It Peace can look up support periods and known vulnerabilities online. It then sends your PHP version, your database type and version, and the names and versions of Composer/npm packages found in scanned folders to endoflife.date, php.net, NVD (NIST) and OSV.dev. Your site URL, file contents and personal data are never sent.', 'keep-it-peace' ); ?></p>
			<p><?php esc_html_e( 'Until you agree, nothing is sent and support periods are checked with data bundled with the plugin. You can change this at any time in Settings → External services.', 'keep-it-peace' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kip-consent-actions">
				<input type="hidden" name="action" value="keepitpeace_remote_consent">
				<?php wp_nonce_field( 'keepitpeace_remote_consent' ); ?>
				<button type="submit" name="choice" value="grant" class="button button-primary"><?php esc_html_e( 'Use external databases', 'keep-it-peace' ); ?></button>
				<button type="submit" name="choice" value="decline" class="button"><?php esc_html_e( 'Do not use', 'keep-it-peace' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Notices shown only on our screens.
	 */
	public function notices(): void {
		if ( ! $this->is_our_screen() || ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		if ( ! Installer::is_ready() ) {
			$this->install_notice();
			return;
		}
		if ( RemoteConsent::PENDING === RemoteConsent::status() ) {
			$this->consent_notice();
		}

		$health = CronHealth::status( $this->plugin->settings() );
		if ( ! $health['healthy'] ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Scheduled scans may not be running.', 'keep-it-peace' ) . '</strong> ';
			esc_html_e( 'No scan has completed for longer than expected. WP-Cron only runs when the site receives visits; set up a server cron job (see Settings → Tools) to run scans reliably.', 'keep-it-peace' );
			echo '</p></div>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		// phpcs:enable
		// The General tab already shows the full baseline section.
		if ( ! ( self::SETTINGS_SLUG === $page && 'general' === $tab ) ) {
			( new BaselinePanel( $this->plugin ) )->render_notice();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only message codes.
		if ( empty( $_GET['keepitpeace_msg'] ) ) {
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['keepitpeace_msg'] ) );
		$text = isset( $_GET['keepitpeace_text'] ) ? sanitize_text_field( wp_unslash( $_GET['keepitpeace_text'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'saved'            => array( 'success', __( 'Settings saved.', 'keep-it-peace' ) ),
			'baseline_blocked' => array( 'error', __( 'The baseline was not approved because the last scan could not read some directories of the target. Fix the permissions, run a scan, then approve.', 'keep-it-peace' ) ),
			'consent_granted'  => array( 'success', __( 'External vulnerability databases will be used from the next scan.', 'keep-it-peace' ) ),
			'consent_declined' => array( 'success', __( 'External databases will not be used. You can change this in Settings → External services.', 'keep-it-peace' ) ),
			'saved_errors'     => array( 'warning', __( 'Settings saved, but some values were not accepted:', 'keep-it-peace' ) ),
			'no_report'        => array( 'error', __( 'There is no completed scan to export yet.', 'keep-it-peace' ) ),
			'status'           => array( 'success', __( 'Status updated.', 'keep-it-peace' ) ),
			'bulk'             => array( 'success', __( 'Selected findings were updated.', 'keep-it-peace' ) ),
			'baseline'         => array( 'success', __( 'The baseline was approved.', 'keep-it-peace' ) ),
			'test_sent'        => array( 'success', __( 'Test notification sent.', 'keep-it-peace' ) ),
			'test_failed'      => array( 'error', __( 'Test notification failed.', 'keep-it-peace' ) ),
			'not_found'        => array( 'error', __( 'Item not found.', 'keep-it-peace' ) ),
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
		if ( ! current_user_can( Plugin::capability() ) || ! Installer::is_ready() ) {
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
	 * @param string $action      Nonce action.
	 * @param bool   $uses_tables Whether the handler needs the plugin's tables.
	 */
	private function guard( string $action, bool $uses_tables = true ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'keep-it-peace' ), 403 );
		}
		check_admin_referer( $action );
		if ( $uses_tables && ! Installer::is_ready() ) {
			wp_safe_redirect( self::url( self::SLUG ) );
			exit;
		}
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
		$this->guard( 'keepitpeace_save_settings', false );
		$tab       = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$input     = isset( $_POST['keepitpeace'] ) && is_array( $_POST['keepitpeace'] ) ? wp_unslash( $_POST['keepitpeace'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in SettingsSanitizer.
		$settings  = $this->plugin->settings();
		$old       = $settings->get( 'schedule' );
		$sanitizer = new SettingsSanitizer( $this->plugin );
		$values    = $sanitizer->sanitize_tab( $tab, $input, $settings->all() );
		$settings->save( $values );
		$this->plugin->reset_settings();
		if ( 'remote' === $tab ) {
			// Saving this tab is an explicit choice about the third-party services.
			update_option( RemoteConsent::OPTION, ! empty( $values['remote']['enabled'] ) ? RemoteConsent::GRANTED : RemoteConsent::DECLINED, false );
		}
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
		if ( '__pending__' === $scope ) {
			$scopes = array_keys( ( new BaselinePanel( $this->plugin ) )->pending() );
		} elseif ( '__all__' !== $scope ) {
			$scopes = in_array( $scope, $scopes, true ) ? array( $scope ) : array();
		}
		$referer = wp_get_referer();
		$back    = $referer ? remove_query_arg( array( 'keepitpeace_msg', 'keepitpeace_text' ), $referer ) : BaselinePanel::section_url();
		if ( empty( $scopes ) ) {
			$this->redirect( $back, 'not_found' );
		}
		$total   = 0;
		$blocked = array();
		foreach ( $scopes as $item ) {
			$count = BaselineActions::approve( $this->plugin, $item, get_current_user_id() );
			if ( $count < 0 ) {
				$blocked[] = $item;
				continue;
			}
			$total += $count;
		}
		if ( $blocked && 0 === $total && count( $blocked ) === count( $scopes ) ) {
			/* translators: %s: target IDs. */
			$this->redirect( $back, 'baseline_blocked', sprintf( __( 'Not approved: %s.', 'keep-it-peace' ), implode( ', ', $blocked ) ) );
		}
		/* translators: %d: number of files. */
		$text = sprintf( _n( '%d file recorded.', '%d files recorded.', $total, 'keep-it-peace' ), $total );
		if ( $blocked ) {
			/* translators: %s: target IDs. */
			$text .= ' ' . sprintf( __( 'Not approved because some directories could not be read: %s.', 'keep-it-peace' ), implode( ', ', $blocked ) );
		}
		$this->redirect( $back, 'baseline', $text );
	}

	/**
	 * Sends a test notification.
	 */
	public function handle_test_notification(): void {
		$this->guard( 'keepitpeace_test_notification', false );
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
		$this->guard( 'keepitpeace_dismiss_pro', false );
		update_user_meta( get_current_user_id(), 'keepitpeace_hide_pro_notice', 1 );
		wp_safe_redirect( self::url( self::SETTINGS_SLUG ) );
		exit;
	}

	/**
	 * Records the answer to the external database question.
	 */
	public function handle_remote_consent(): void {
		$this->guard( 'keepitpeace_remote_consent', false );
		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		if ( ! in_array( $choice, array( 'grant', 'decline' ), true ) ) {
			$this->redirect( self::url( self::SLUG ), 'not_found' );
		}
		RemoteConsent::record( 'grant' === $choice );
		$this->plugin->reset_settings();
		$back = wp_get_referer();
		$this->redirect( $back ? $back : self::url( self::SLUG ), 'grant' === $choice ? 'consent_granted' : 'consent_declined' );
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
