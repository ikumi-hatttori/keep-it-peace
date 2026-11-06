<?php
// Intentionally vulnerable: processes POST without a nonce.
if ( isset( $_POST['title'] ) ) {
	update_post_meta( 1, 'title', sanitize_text_field( wp_unslash( $_POST['title'] ) ) );
}
