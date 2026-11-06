<?php
// Safe patterns that must not be reported.

echo esc_html( $_GET['name'] );
echo esc_attr( sanitize_text_field( wp_unslash( $_POST['title'] ) ) );
$id = absint( $_GET['id'] );
echo (int) $_GET['page'];
if ( isset( $_GET['tab'] ) ) {
	$tab = sanitize_key( $_GET['tab'] );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID = %d", $_POST['id'] ) );
$sql = $wpdb->prepare( 'SELECT * FROM t WHERE a = %s', $a );
$rows = $wpdb->get_results( $sql );
$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
$this->callback = 'x';
$obj->$method();
$closure = function ( $value ) {
	return $value;
};
// eval( $_POST['x'] ) inside a comment is ignored.

add_action( 'wp_ajax_kip_ok', 'kip_ajax_ok' );
function kip_ajax_ok() {
	check_ajax_referer( 'kip_ok' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die();
	}
	update_option( 'x', 1 );
}
add_action( 'wp_ajax_kip_method', array( $this, 'handle' ) );
class Kip_Handler {
	public function handle() {
		check_ajax_referer( 'kip' );
		if ( current_user_can( 'edit_posts' ) ) {
			update_option( 'y', 1 );
		}
	}
}
register_rest_route( 'kip/v1', '/secure', array( 'methods' => 'POST', 'callback' => 'kip_cb', 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
if ( isset( $_POST['save'] ) && check_admin_referer( 'kip_save' ) ) {
	update_option( 'z', 1 );
}
