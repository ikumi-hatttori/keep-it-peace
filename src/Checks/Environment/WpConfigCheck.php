<?php
/**
 * Checks wp-config.php and debug settings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Environment;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\PathGuard;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * WP_DEBUG, WP_DEBUG_DISPLAY, debug.log, wp-config.php permissions, file editor.
 */
class WpConfigCheck extends AbstractCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'env.wp_config';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'WordPress configuration', 'keep-it-peace' );
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'environment';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks WP_DEBUG, WP_DEBUG_DISPLAY, debug.log, wp-config.php permissions and the built-in file editor.', 'keep-it-peace' );
	}

	/**
	 * Location of wp-config.php.
	 *
	 * @return string|null
	 */
	public static function config_path(): ?string {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $path ) {
			if ( PathGuard::is_within_open_basedir( $path ) && is_file( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * Path of debug.log when WP_DEBUG_LOG is enabled or the default file exists.
	 *
	 * @return string
	 */
	public static function debug_log_path(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Runs.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$findings = array();
		$debug    = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display  = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;

		if ( $debug && $display ) {
			$findings[] = $this->config_finding( 'wp_config.debug_display', Severity::HIGH, 'WP_DEBUG_DISPLAY', __( 'Debug messages are shown to visitors', 'keep-it-peace' ), __( 'WP_DEBUG is on and WP_DEBUG_DISPLAY is not off, so PHP notices and errors appear on pages and reveal file paths.', 'keep-it-peace' ), __( "Set define( 'WP_DEBUG', false ) on production, or at least define( 'WP_DEBUG_DISPLAY', false ).", 'keep-it-peace' ) );
		} elseif ( $debug ) {
			$findings[] = $this->config_finding( 'wp_config.debug', Severity::LOW, 'WP_DEBUG', __( 'WP_DEBUG is enabled', 'keep-it-peace' ), __( 'Debug mode is on. It is meant for development and can slow the site and fill the log.', 'keep-it-peace' ), __( "Set define( 'WP_DEBUG', false ) on production.", 'keep-it-peace' ) );
		}

		$log = self::debug_log_path();
		if ( PathGuard::is_within_open_basedir( $log ) && is_file( $log ) ) {
			$inside_content = 0 === strpos( PathGuard::normalize( $log ), PathGuard::normalize( WP_CONTENT_DIR ) . '/' );
			$findings[]     = $this->finding(
				array(
					'rule_id'        => 'wp_config.debug_log',
					'severity'       => $inside_content ? Severity::MEDIUM : Severity::LOW,
					'target_type'    => 'file',
					'target_scope'   => $inside_content ? 'content' : 'site',
					'target'         => $inside_content ? 'debug.log' : basename( $log ),
					'title'          => __( 'debug.log exists', 'keep-it-peace' ),
					'message'        => $inside_content
						? __( 'A debug log is stored inside wp-content, where it may be downloadable. Logs can contain paths, queries and personal data.', 'keep-it-peace' )
						: __( 'A debug log file exists.', 'keep-it-peace' ),
					'recommendation' => __( 'Delete the log when it is no longer needed, and set WP_DEBUG_LOG to a path outside the web root.', 'keep-it-peace' ),
					/* Size changes every day; keep the fingerprint stable. */
					'meta'           => array( 'size' => (int) filesize( $log ) ),
				)
			);
		}

		$config = self::config_path();
		if ( null !== $config && 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$perms = fileperms( $config );
			if ( false !== $perms ) {
				$perms = $perms & 0777;
				if ( $perms & 0002 ) {
					$findings[] = $this->config_finding( 'wp_config.permissions_writable', Severity::CRITICAL, 'wp-config.php', __( 'wp-config.php is world-writable', 'keep-it-peace' ), sprintf( /* translators: %s: permissions. */ __( 'wp-config.php has permissions %s. Anyone on the server can change it.', 'keep-it-peace' ), sprintf( '%03o', $perms ) ), __( 'Set the permissions to 400 or 440 (600/640 if your host requires write access).', 'keep-it-peace' ) );
				} elseif ( $perms & 0004 ) {
					$findings[] = $this->config_finding( 'wp_config.permissions_readable', Severity::LOW, 'wp-config.php', __( 'wp-config.php is readable by everyone on the server', 'keep-it-peace' ), sprintf( /* translators: %s: permissions. */ __( 'wp-config.php has permissions %s. On shared servers other accounts may be able to read your database password.', 'keep-it-peace' ), sprintf( '%03o', $perms ) ), __( 'Set the permissions to 400 or 440 (600/640 if your host requires write access).', 'keep-it-peace' ) );
				}
			}
		}

		if ( ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			$findings[] = $this->config_finding( 'wp_config.file_edit', Severity::LOW, 'DISALLOW_FILE_EDIT', __( 'Theme and plugin file editor is enabled', 'keep-it-peace' ), __( 'Administrators can edit PHP files from the dashboard. A stolen admin account can then run arbitrary code.', 'keep-it-peace' ), __( "Add define( 'DISALLOW_FILE_EDIT', true ); to wp-config.php.", 'keep-it-peace' ) );
		}

		return CheckResult::complete(
			$findings,
			array(
				'wp_debug'         => $debug,
				'wp_debug_display' => $debug && $display,
			)
		);
	}

	/**
	 * Finding about a configuration value.
	 *
	 * @param string $rule_id        Rule.
	 * @param int    $severity       Severity.
	 * @param string $target         Setting.
	 * @param string $title          Title.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @return \KeepItPeace\Findings\Finding
	 */
	private function config_finding( string $rule_id, int $severity, string $target, string $title, string $message, string $recommendation ) {
		return $this->finding(
			array(
				'rule_id'        => $rule_id,
				'severity'       => $severity,
				'target'         => $target,
				'title'          => $title,
				'message'        => $message,
				'recommendation' => $recommendation,
			)
		);
	}
}
