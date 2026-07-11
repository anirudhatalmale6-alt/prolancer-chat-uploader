<?php
/**
 * ============================================================================
 * CHAT ATTACHMENT UPLOADER
 * ----------------------------------------------------------------------------
 * APPEND the code below to the END of your EXISTING child theme's
 * functions.php:  wp-content/themes/prolancer-child/functions.php
 *
 * Do NOT replace that file — you already have a working child theme and this is
 * purely additive.
 *
 * Deliberately absent: any re-enqueue of the parent stylesheet. Your child
 * theme already does that, and doing it twice is exactly what produces the
 * "one style loading after another" flash.
 * ============================================================================
 */

require_once get_stylesheet_directory() . '/inc/chat-uploader.php';

/**
 * Chat uploader assets — loaded ONLY on the chat screen.
 *
 * Site-wide loading would put this on every page for no reason. Everywhere
 * except the chat, it costs nothing at all.
 */
function pcu_enqueue_chat_uploader() {
	if ( ! pcu_is_chat_screen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	// One stylesheet, one script. No Dropzone, no Bootstrap, no jQuery — the
	// drag-and-drop is native, which is what keeps this to ~10 KB over the wire.
	wp_enqueue_style(
		'pcu-chat-uploader',
		$uri . '/assets/css/chat-uploader.css',
		array(),
		pcu_asset_version( $dir . '/assets/css/chat-uploader.css' )
	);

	wp_enqueue_script(
		'pcu-chat-uploader',
		$uri . '/assets/js/chat-uploader.js',
		array(),
		pcu_asset_version( $dir . '/assets/js/chat-uploader.js' ),
		true // footer
	);

	wp_localize_script(
		'pcu-chat-uploader',
		'PCU_CONFIG',
		array(
			'uploadUrl'     => admin_url( 'admin-ajax.php' ),
			'maxFiles'      => 10,
			'maxFilesize'   => 10, // MB
			'acceptedFiles' => 'image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.zip',
			'params'        => array(
				'action'   => 'pcu_upload_attachment',
				'_wpnonce' => wp_create_nonce( 'pcu_upload' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_enqueue_chat_uploader' );

/**
 * `defer` keeps the script off the critical render path, so the chat page
 * paints without waiting for it.
 */
function pcu_defer_script( $tag, $handle ) {
	if ( 'pcu-chat-uploader' === $handle && false === strpos( $tag, ' defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'pcu_defer_script', 10, 2 );

/**
 * Cache-bust on file change rather than on every load, so a browser
 * re-downloads an asset only when it actually changed.
 */
function pcu_asset_version( $path ) {
	return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
}

/**
 * Is this the screen that shows the chat?
 *
 * TODO: confirm against the real messages page — I have not been able to see it
 * yet. Kept in one function so it is a one-line change once confirmed.
 */
function pcu_is_chat_screen() {
	$is_chat = is_page_template( 'template-messages.php' )
		|| is_page( array( 'messages', 'chat', 'inbox' ) );

	/**
	 * Filter the chat-screen check.
	 *
	 * @param bool $is_chat Whether this request is the chat screen.
	 */
	return (bool) apply_filters( 'pcu_is_chat_screen', $is_chat );
}
