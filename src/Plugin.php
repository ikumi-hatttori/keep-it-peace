<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace;

use KeepItPeace\Admin\Admin;
use KeepItPeace\Baseline\FileIndexRepository;
use KeepItPeace\Checks\Dependencies\DependencyCheck;
use KeepItPeace\Checks\Environment\UploadsPhpCheck;
use KeepItPeace\Checks\Environment\WpConfigCheck;
use KeepItPeace\Checks\External\DirectoryListingCheck;
use KeepItPeace\Checks\External\ExposedFilesCheck;
use KeepItPeace\Checks\External\SecurityHeadersCheck;
use KeepItPeace\Checks\External\TlsCheck;
use KeepItPeace\Checks\Files\CodePatternCheck;
use KeepItPeace\Checks\Files\IntegrityCheck;
use KeepItPeace\Checks\Files\PermissionCheck;
use KeepItPeace\Checks\Files\SuspiciousFileCheck;
use KeepItPeace\Checks\Php\DatabaseVersionCheck;
use KeepItPeace\Checks\Php\PhpIniCheck;
use KeepItPeace\Checks\Php\PhpVersionCheck;
use KeepItPeace\Findings\FindingRepository;
use KeepItPeace\Install\Installer;
use KeepItPeace\Notifications\ChannelRegistry;
use KeepItPeace\Notifications\EmailChannel;
use KeepItPeace\Notifications\Notifier;
use KeepItPeace\Remote\HttpClient;
use KeepItPeace\Rest\RestController;
use KeepItPeace\Rules\BundledRuleProvider;
use KeepItPeace\Rules\Detectors\ObfuscationDetector;
use KeepItPeace\Rules\Detectors\RequestGuardDetector;
use KeepItPeace\Rules\Detectors\SuperglobalDetector;
use KeepItPeace\Rules\Detectors\VariableFunctionDetector;
use KeepItPeace\Rules\Detectors\WpdbDetector;
use KeepItPeace\Rules\RuleRepository;
use KeepItPeace\Scan\CheckRegistry;
use KeepItPeace\Scan\Maintenance;
use KeepItPeace\Scan\PathTargetProvider;
use KeepItPeace\Scan\RuntimeEnvironment;
use KeepItPeace\Scan\ScanRepository;
use KeepItPeace\Scan\Scanner;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Scan\TargetRegistry;
use KeepItPeace\Scan\ThemeTargetProvider;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class. Services are created lazily.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boots on plugins_loaded.
	 */
	public static function boot(): void {
		$plugin = self::instance();
		$plugin->register();

		/**
		 * Fires when Keep It Peace is ready. Add-ons should hook here and check KEEPITPEACE_API_VERSION.
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'keepitpeace_loaded', $plugin );
	}

	/**
	 * Registers hooks.
	 */
	private function register(): void {
		Installer::maybe_upgrade();
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( RuntimeEnvironment::class, 'record' ) );
		( new Scheduler( $this ) )->register();
		add_action( 'rest_api_init', array( new RestController( $this ), 'register_routes' ) );
		if ( is_admin() ) {
			( new Admin( $this ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'keep-it-peace', \KeepItPeace\Cli\Command::class );
		}
	}

	/**
	 * Loads bundled translations (wp.org language packs take precedence when present).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'keep-it-peace', false, dirname( plugin_basename( KEEPITPEACE_FILE ) ) . '/languages' );
	}

	/**
	 * Capability required for every screen and action.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required to use the plugin.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'keepitpeace_capability', 'manage_options' );
	}

	/**
	 * Returns a lazily created service.
	 *
	 * @param string   $id      ID.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/**
	 * Settings (reloaded after saving with reset_settings()).
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Drops cached settings-dependent services.
	 */
	public function reset_settings(): void {
		unset( $this->services['settings'], $this->services['http'] );
	}

	/**
	 * Scan repository.
	 *
	 * @return ScanRepository
	 */
	public function scans(): ScanRepository {
		return $this->service(
			'scans',
			static function () {
				return new ScanRepository();
			}
		);
	}

	/**
	 * Finding repository.
	 *
	 * @return FindingRepository
	 */
	public function findings(): FindingRepository {
		return $this->service(
			'findings',
			static function () {
				return new FindingRepository();
			}
		);
	}

	/**
	 * File index.
	 *
	 * @return FileIndexRepository
	 */
	public function files(): FileIndexRepository {
		return $this->service(
			'files',
			static function () {
				return new FileIndexRepository();
			}
		);
	}

	/**
	 * HTTP client for third-party APIs.
	 *
	 * @return HttpClient
	 */
	public function http(): HttpClient {
		$settings = $this->settings();
		return $this->service(
			'http',
			static function () use ( $settings ) {
				return new HttpClient( $settings );
			}
		);
	}

	/**
	 * Rules.
	 *
	 * @return RuleRepository
	 */
	public function rules(): RuleRepository {
		return $this->service(
			'rules',
			static function () {
				return new RuleRepository(
					array( new BundledRuleProvider( KEEPITPEACE_DIR . 'rules/core.json' ) ),
					array(
						new VariableFunctionDetector(),
						new SuperglobalDetector(),
						new WpdbDetector(),
						new RequestGuardDetector(),
						new ObfuscationDetector(),
					)
				);
			}
		);
	}

	/**
	 * Checks.
	 *
	 * @return CheckRegistry
	 */
	public function checks(): CheckRegistry {
		return $this->service(
			'checks',
			static function () {
				return new CheckRegistry(
					static function () {
						return array(
							new PhpVersionCheck(),
							new PhpIniCheck(),
							new DatabaseVersionCheck(),
							new WpConfigCheck(),
							new UploadsPhpCheck(),
							new TlsCheck(),
							new SecurityHeadersCheck(),
							new ExposedFilesCheck(),
							new DirectoryListingCheck(),
							new IntegrityCheck(),
							new CodePatternCheck(),
							new SuspiciousFileCheck(),
							new PermissionCheck(),
							new DependencyCheck(),
						);
					}
				);
			}
		);
	}

	/**
	 * Scan targets.
	 *
	 * @return TargetRegistry
	 */
	public function targets(): TargetRegistry {
		return $this->service(
			'targets',
			static function () {
				return new TargetRegistry( array( new ThemeTargetProvider(), new PathTargetProvider() ) );
			}
		);
	}

	/**
	 * Notification channels.
	 *
	 * @return ChannelRegistry
	 */
	public function channels(): ChannelRegistry {
		$plugin = $this;
		return $this->service(
			'channels',
			static function () use ( $plugin ) {
				return new ChannelRegistry( array( new EmailChannel( $plugin ) ) );
			}
		);
	}

	/**
	 * Notifier.
	 *
	 * @return Notifier
	 */
	public function notifier(): Notifier {
		$plugin = $this;
		return $this->service(
			'notifier',
			static function () use ( $plugin ) {
				return new Notifier( $plugin );
			}
		);
	}

	/**
	 * Scanner.
	 *
	 * @return Scanner
	 */
	public function scanner(): Scanner {
		$plugin = $this;
		return $this->service(
			'scanner',
			static function () use ( $plugin ) {
				return new Scanner( $plugin );
			}
		);
	}

	/**
	 * Maintenance.
	 *
	 * @return Maintenance
	 */
	public function maintenance(): Maintenance {
		$plugin = $this;
		return $this->service(
			'maintenance',
			static function () use ( $plugin ) {
				return new Maintenance( $plugin );
			}
		);
	}
}
