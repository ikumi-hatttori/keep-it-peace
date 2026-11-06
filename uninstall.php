<?php
/**
 * Uninstall: drops tables, options, transients and scheduled events.
 *
 * @package KeepItPeace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes all plugin data for the current site.
 */
function keepitpeace_uninstall_site() {
	global $wpdb;

	foreach ( array( 'keepitpeace_scans', 'keepitpeace_findings', 'keepitpeace_finding_events', 'keepitpeace_files' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are fixed.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'keepitpeace_' ) . '%',
			$wpdb->esc_like( '_transient_keepitpeace_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_keepitpeace_' ) . '%'
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'keepitpeace_' ) . '%'
		)
	);

	foreach ( array( 'keepitpeace_scheduled_scan', 'keepitpeace_continue_scan', 'keepitpeace_daily_maintenance' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $keepitpeace_site_id ) {
		switch_to_blog( (int) $keepitpeace_site_id );
		keepitpeace_uninstall_site();
		restore_current_blog();
	}
} else {
	keepitpeace_uninstall_site();
}
