<?php
/**
 * Activation, deactivation and schema management.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Install;

use KeepItPeace\Scan\RuntimeEnvironment;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and options.
 */
class Installer {

	const DB_VERSION_OPTION = 'keepitpeace_db_version';
	const UUID_OPTION       = 'keepitpeace_site_uuid';

	/**
	 * Table names without prefix.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'keepitpeace_scans', 'keepitpeace_findings', 'keepitpeace_finding_events', 'keepitpeace_files' );
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				Scheduler::unschedule_all();
				restore_current_blog();
			}
			return;
		}
		Scheduler::unschedule_all();
	}

	/**
	 * Installs or upgrades the current site.
	 */
	public static function install_site(): void {
		self::create_tables();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		if ( ! get_option( self::UUID_OPTION ) ) {
			add_option( self::UUID_OPTION, wp_generate_uuid4(), '', false );
		}
		if ( ! get_option( 'keepitpeace_installed_at' ) ) {
			add_option( 'keepitpeace_installed_at', time(), '', false );
		}
		RuntimeEnvironment::record();
		update_option( self::DB_VERSION_OPTION, KEEPITPEACE_DB_VERSION, true );
		Scheduler::schedule( new Settings() );
	}

	/**
	 * Runs the installer when the DB schema is out of date (plugin updated without re-activation).
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < KEEPITPEACE_DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Creates or updates the custom tables with dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}keepitpeace_scans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'running',
  trigger_type varchar(20) NOT NULL DEFAULT 'cron',
  started_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  updated_at datetime NOT NULL,
  progress tinyint(3) unsigned NOT NULL DEFAULT 0,
  state longtext NULL,
  summary longtext NULL,
  ruleset_version varchar(32) NOT NULL DEFAULT '',
  plugin_version varchar(20) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY started_at (started_at)
) $charset;
CREATE TABLE {$p}keepitpeace_findings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fingerprint char(64) NOT NULL,
  check_id varchar(64) NOT NULL,
  rule_id varchar(64) NOT NULL DEFAULT '',
  category varchar(32) NOT NULL,
  severity tinyint(3) unsigned NOT NULL DEFAULT 1,
  target_type varchar(10) NOT NULL DEFAULT 'env',
  target_scope varchar(191) NOT NULL DEFAULT '',
  target text NOT NULL,
  line int(10) unsigned NULL DEFAULT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  message text NOT NULL,
  recommendation text NOT NULL,
  meta longtext NULL,
  file_hash char(64) NULL DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  status_note text NULL,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  first_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY fingerprint (fingerprint),
  KEY status_severity (status,severity),
  KEY category (category),
  KEY last_scan_id (last_scan_id)
) $charset;
CREATE TABLE {$p}keepitpeace_finding_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  finding_id bigint(20) unsigned NOT NULL,
  scan_id bigint(20) unsigned NULL DEFAULT NULL,
  event varchar(20) NOT NULL,
  from_status varchar(20) NULL DEFAULT NULL,
  to_status varchar(20) NULL DEFAULT NULL,
  user_id bigint(20) unsigned NULL DEFAULT NULL,
  note text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY finding_id (finding_id),
  KEY scan_event (scan_id,event),
  KEY created_at (created_at)
) $charset;
CREATE TABLE {$p}keepitpeace_files (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(191) NOT NULL,
  rel_path text NOT NULL,
  path_hash char(64) NOT NULL,
  baseline_hash char(64) NULL DEFAULT NULL,
  baseline_size bigint(20) unsigned NULL DEFAULT NULL,
  baseline_mtime bigint(20) unsigned NULL DEFAULT NULL,
  baseline_perms varchar(4) NULL DEFAULT NULL,
  current_hash char(64) NULL DEFAULT NULL,
  current_size bigint(20) unsigned NULL DEFAULT NULL,
  current_mtime bigint(20) unsigned NULL DEFAULT NULL,
  current_perms varchar(4) NULL DEFAULT NULL,
  last_seen_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL DEFAULT NULL,
  approved_by bigint(20) unsigned NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY scope_seen (scope,last_seen_scan_id)
) $charset;";

		dbDelta( $sql );
	}
}
