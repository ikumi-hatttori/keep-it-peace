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
	const ERROR_OPTION      = 'keepitpeace_install_error';

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
	 * Installs or upgrades the current site. Prints nothing (runs during activation).
	 *
	 * The DB version is recorded only when every table exists, so a failed install is retried
	 * by maybe_upgrade() and scans stay disabled until then.
	 */
	public static function install_site(): void {
		$ready = self::create_tables();
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
		if ( $ready ) {
			update_option( self::DB_VERSION_OPTION, KEEPITPEACE_DB_VERSION, true );
			delete_option( self::ERROR_OPTION );
		}
		Scheduler::schedule( new Settings() );
	}

	/**
	 * Runs the installer when the DB schema is out of date (plugin updated without re-activation,
	 * or a previous install could not create the tables).
	 *
	 * After a failed install, the retry happens on the next admin screen (or WP-CLI command)
	 * instead of on every front-end request.
	 */
	public static function maybe_upgrade(): void {
		if ( self::is_ready() ) {
			return;
		}
		$failed_before = false !== get_option( self::ERROR_OPTION, false );
		if ( $failed_before && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::install_site();
	}

	/**
	 * Whether the tables of the current schema version exist (the version is only recorded after
	 * they were verified). Scans, admin screens and REST endpoints check this before using the tables.
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return (int) get_option( self::DB_VERSION_OPTION, 0 ) >= KEEPITPEACE_DB_VERSION;
	}

	/**
	 * Details of the last failed install, or null.
	 *
	 * @return array{time:int,missing:string[],errors:array<string,string>}|null
	 */
	public static function last_error(): ?array {
		$error = get_option( self::ERROR_OPTION, false );
		return is_array( $error ) ? $error : null;
	}

	/**
	 * Creates or updates the custom tables and verifies that they exist.
	 *
	 * @return bool All tables exist.
	 */
	public static function create_tables(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$suppress = $wpdb->suppress_errors( true );
		self::upgrade_indexes( (int) get_option( self::DB_VERSION_OPTION, 0 ) );

		$errors = array();
		foreach ( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) as $table => $sql ) {
			$wpdb->last_error = '';
			dbDelta( $sql );
			if ( '' !== $wpdb->last_error ) {
				$errors[ $table ] = $wpdb->last_error;
			}
		}
		$missing = self::missing_tables();
		$wpdb->suppress_errors( $suppress );

		if ( $missing ) {
			update_option(
				self::ERROR_OPTION,
				array(
					'time'    => time(),
					'missing' => $missing,
					'errors'  => $errors,
				),
				false
			);
			return false;
		}
		return true;
	}

	/**
	 * Tables of the current schema that do not exist.
	 *
	 * @return string[] Table names without prefix.
	 */
	public static function missing_tables(): array {
		global $wpdb;
		$missing = array();
		foreach ( self::tables() as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Index changes dbDelta() cannot make by itself (it adds indexes but never changes existing ones).
	 *
	 * @param int $from Installed DB version.
	 */
	private static function upgrade_indexes( int $from ): void {
		global $wpdb;
		if ( $from < 1 || $from >= 2 ) {
			return;
		}
		// Version 1 indexed the full scope column (772 bytes in utf8mb4); version 2 uses a 150-character prefix.
		$table = $wpdb->prefix . 'keepitpeace_files';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed table name from $wpdb->prefix.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}
		$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s AND Column_name = %s", 'scope_seen', 'scope' ) );
		if ( $index && null === $index->Sub_part ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX scope_seen" );
		}
		// phpcs:enable
	}

	/**
	 * CREATE TABLE statements (dbDelta format) keyed by table name without prefix.
	 *
	 * Index sizes must stay within 767 bytes in utf8mb4 (4 bytes per character) so the tables can be
	 * created on MySQL 5.6 / MariaDB 10.1 without innodb_large_prefix; tests/Unit/SchemaTest.php checks this.
	 *
	 * @param string $p       Table prefix.
	 * @param string $charset Charset/collation clause.
	 * @return array<string,string>
	 */
	public static function schema( string $p, string $charset ): array {
		$tables = array();

		$tables['keepitpeace_scans'] = "CREATE TABLE {$p}keepitpeace_scans (
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
) $charset;";

		$tables['keepitpeace_findings'] = "CREATE TABLE {$p}keepitpeace_findings (
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
) $charset;";

		$tables['keepitpeace_finding_events'] = "CREATE TABLE {$p}keepitpeace_finding_events (
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
) $charset;";

		$tables['keepitpeace_files'] = "CREATE TABLE {$p}keepitpeace_files (
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
  KEY scope_seen (scope(150),last_seen_scan_id)
) $charset;";

		return $tables;
	}
}
