<?php
/**
 * Prolancer child theme — chat attachment uploader.
 *
 * Nothing in the parent theme is modified. Drop this folder in as the child
 * theme (or merge these two files into the existing child theme) and the chat
 * composer picks up the new uploader.
 *
 * @package Prolancer_Child
 */

defined( 'ABSPATH' ) || exit;

require_once get_stylesheet_directory() . '/inc/chat-uploader.php';

/**
 * Parent + child stylesheets, in that order.
 *
 * The child sheet declares the parent as a dependency, so WordPress can never
 * emit them out of order — that ordering is what causes the "one style loading
 * after another" flash.
 */
function prolancer_child_enqueue_styles() {
	$parent = 'prolancer-style';

	wp_enqueue_style(
		$parent,
		get_template_directory_uri() . '/style.css',
		array(),
		prolancer_child_asset_version( get_template_directory() . '/style.css' )
	);

	wp_enqueue_style(
		'prolancer-child-style',
		get_stylesheet_uri(),
		array( $parent ),
		prolancer_child_asset_version( get_stylesheet_directory() . '/style.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'prolancer_child_enqueue_styles' );

/**
 * Chat uploader assets — loaded ONLY on the chat screen.
 *
 * Loading them site-wide would put ~112 KB of Dropzone on every page for no
 * reason. Everywhere except the chat, this costs nothing.
 */
function prolancer_child_enqueue_chat_uploader() {
	if ( ! prolancer_child_is_chat_screen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	// One stylesheet. Dropzone's own CSS is deliberately NOT enqueued: every
	// one of its visuals is overridden below, so shipping it would only paint
	// styles we immediately replace.
	wp_enqueue_style(
		'pcu-chat-uploader',
		$uri . '/assets/css/chat-uploader.css',
		array( 'prolancer-child-style' ),
		prolancer_child_asset_version( $dir . '/assets/css/chat-uploader.css' )
	);

	wp_enqueue_script(
		'dropzone',
		$uri . '/assets/js/dropzone.min.js',
		array(),
		'5.9.3',
		true // footer
	);

	wp_enqueue_script(
		'pcu-chat-uploader',
		$uri . '/assets/js/chat-uploader.js',
		array( 'dropzone' ),
		prolancer_child_asset_version( $dir . '/assets/js/chat-uploader.js' ),
		true
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
add_action( 'wp_enqueue_scripts', 'prolancer_child_enqueue_chat_uploader' );

/**
 * Ship both scripts with `defer` so they stay off the critical render path.
 *
 * defer also preserves execution order, so chat-uploader.js can rely on
 * Dropzone already being defined.
 */
function prolancer_child_defer_scripts( $tag, $handle ) {
	if ( in_array( $handle, array( 'dropzone', 'pcu-chat-uploader' ), true )
		&& false === strpos( $tag, ' defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'prolancer_child_defer_scripts', 10, 2 );

/**
 * Cache-bust on file change rather than on every load.
 *
 * filemtime() means a browser re-downloads an asset only when it actually
 * changed, instead of us bumping a version by hand and forgetting.
 */
function prolancer_child_asset_version( $path ) {
	return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
}

/**
 * Is this the page that shows the chat?
 *
 * TODO: confirm against the live site. Prolancer's messages screen is usually a
 * page using a messages template, so this checks the template, the page slug and
 * a filter — whichever matches. Kept in one place so it is a one-line change
 * once we can see the real page.
 */
function prolancer_child_is_chat_screen() {
	$is_chat = is_page_template( 'template-messages.php' )
		|| is_page( array( 'messages', 'chat', 'inbox' ) );

	/**
	 * Filter the chat-screen check.
	 *
	 * @param bool $is_chat Whether the current request is the chat screen.
	 */
	return (bool) apply_filters( 'pcu_is_chat_screen', $is_chat );
}
