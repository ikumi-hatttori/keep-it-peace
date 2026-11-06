<?php
/**
 * Plain-text email. Variables: $data (see EmailChannel::view_data()).
 *
 * @package KeepItPeace
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email body, not HTML.
echo $data['heading'] . "\n";
echo str_repeat( '=', 40 ) . "\n\n";
echo __( 'Site', 'keep-it-peace' ) . ': ' . $data['site_name'] . ' (' . $data['site_url'] . ")\n";
echo __( 'Checked at', 'keep-it-peace' ) . ': ' . $data['date'] . "\n\n";

echo __( 'Open issues by severity', 'keep-it-peace' ) . "\n";
foreach ( $data['counts'] as $keepitpeace_row ) {
	echo '  ' . str_pad( $keepitpeace_row['label'], 10 ) . ' ' . $keepitpeace_row['count'] . "\n";
}
echo "\n";
if ( ! $data['is_digest'] ) {
	echo __( 'Since the previous scan', 'keep-it-peace' ) . ': ';
	/* translators: 1: new count, 2: resolved count. */
	echo sprintf( __( '%1$d new, %2$d resolved', 'keep-it-peace' ), $data['new'], $data['resolved'] ) . "\n\n";
}
if ( '' !== $data['php'] ) {
	echo 'PHP: ' . $data['php'] . "\n";
}
if ( '' !== $data['database'] ) {
	echo __( 'Database', 'keep-it-peace' ) . ': ' . $data['database'] . "\n";
}
if ( '' !== $data['tls'] ) {
	echo __( 'SSL certificate', 'keep-it-peace' ) . ': ' . $data['tls'] . "\n";
}
echo "\n" . __( 'Details are available only in the dashboard (they are not included in email for security reasons):', 'keep-it-peace' ) . "\n";
echo $data['admin_url'] . "\n\n";
echo "-- \nKeep It Peace\n";
