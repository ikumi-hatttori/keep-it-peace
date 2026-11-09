<?php
/**
 * Public PHP API for add-ons.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace;

use KeepItPeace\Export\ReportExporter;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Install\Installer;
use KeepItPeace\Scan\Scan;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stable entry points for add-ons. Methods here follow KEEPITPEACE_API_VERSION (semver):
 * they are not removed or changed incompatibly without a major version bump.
 */
final class Api {

	/**
	 * Site UUID generated on activation (identifies the site without revealing its URL).
	 *
	 * @return string
	 */
	public static function get_site_uuid(): string {
		$uuid = (string) get_option( Installer::UUID_OPTION, '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( Installer::UUID_OPTION, $uuid, false );
		}
		return $uuid;
	}

	/**
	 * Exports a scan as an array (see docs/export-schema.json).
	 *
	 * @param int|null $scan_id Scan ID; null = latest completed scan.
	 * @return array|null Null when there is no such finished scan.
	 */
	public static function export_scan( ?int $scan_id = null ): ?array {
		return ( new ReportExporter( Plugin::instance() ) )->export( $scan_id );
	}

	/**
	 * Exports a scan as JSON.
	 *
	 * @param int|null $scan_id Scan ID; null = latest completed scan.
	 * @param int      $flags   Extra json_encode flags (e.g. JSON_PRETTY_PRINT).
	 * @return string|null
	 */
	public static function export_scan_json( ?int $scan_id = null, int $flags = 0 ): ?string {
		return ( new ReportExporter( Plugin::instance() ) )->export_json( $scan_id, $flags );
	}

	/**
	 * Latest completed scan.
	 *
	 * @return Scan|null
	 */
	public static function get_latest_scan(): ?Scan {
		return Plugin::instance()->scans()->latest_completed();
	}

	/**
	 * Queries findings.
	 *
	 * @param array $args status, severity, category, check_id, search, orderby, order, per_page, page.
	 * @return Finding[]
	 */
	public static function get_findings( array $args = array() ): array {
		$result = Plugin::instance()->findings()->query( $args );
		return $result['items'];
	}

	/**
	 * Starts a scan (or returns the running one). The scan runs in slices via WP-Cron.
	 *
	 * @param string $trigger Trigger label stored with the scan.
	 * @return int Scan ID.
	 */
	public static function start_scan( string $trigger = 'api' ): int {
		$plugin = Plugin::instance();
		$scan   = $plugin->scanner()->start( sanitize_key( $trigger ), get_current_user_id() );
		Scheduler::schedule_continuation( 0 );
		return $scan->id;
	}

	/**
	 * Current settings.
	 *
	 * @return Settings
	 */
	public static function get_settings(): Settings {
		return Plugin::instance()->settings();
	}
}
