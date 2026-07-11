<?php
	add_action( 'wp_enqueue_scripts', 'prolancer_child_enqueue_styles' );
	function prolancer_child_enqueue_styles() {
 		wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css', array('bootstrap'));
 	}

	// Real-time chat (Pusher) — added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/prolancer-realtime.php';

	// Chat attachment uploader (icon -> modal -> drag & drop, multiple files
	// per message) — added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/pcu-chat-uploader.php';
