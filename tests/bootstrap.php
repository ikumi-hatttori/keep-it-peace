<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs for pure unit tests.
 *
 * @package KeepItPeace
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'KEEPITPEACE_VERSION', '0.0.0-test' );
define( 'KEEPITPEACE_API_VERSION', '1.0.0' );
define( 'KEEPITPEACE_EXPORT_SCHEMA_VERSION', '1.0.0' );
define( 'KEEPITPEACE_RULESET_SCHEMA_VERSION', 1 );
define( 'KEEPITPEACE_DIR', dirname( __DIR__ ) . '/' );
define( 'KEEPITPEACE_FILE', KEEPITPEACE_DIR . 'keep-it-peace.php' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'HOUR_IN_SECONDS', 3600 );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/src/Autoloader.php';
\KeepItPeace\Autoloader::register( 'KeepItPeace\\', dirname( __DIR__ ) . '/src/' );

$GLOBALS['kip_test_filters'] = array();

function __( $text, $domain = 'default' ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['kip_test_filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['kip_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
}
function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}
function get_option( $name, $default = false ) {
	return $default;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
