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

	// SVG sanitiser. Redux Framework allows SVG uploads site-wide, and an SVG
	// can carry JavaScript — this strips it. See the file for the full why.
	// Added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/pcu-svg-guard.php';

	// Profile pictures: teach get_avatar() where ProLancer keeps them, so a
	// buyer's photo shows instead of the grey default. Added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/pcu-profile.php';

	// Create/edit service & project: stop an untouched dropdown from wiping the
	// category/location/delivery-time it was meant to save. Added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/pcu-service-form.php';

	// Extras & FAQ library: each seller's own reusable list, under the Services
	// menu, and a tick-list for it on Create Service. Added by Anirudha.
	require_once get_stylesheet_directory() . '/inc/pcu-library.php';
