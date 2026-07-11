<?php
/**
 * Chat attachment uploader — server side.
 *
 * Receives one file per request from Dropzone, validates it, stores it through
 * the WordPress media pipeline and returns JSON the front end turns into a
 * chat thumbnail.
 *
 * @package Prolancer_Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle a single chat attachment upload.
 */
function pcu_handle_upload() {
	// Logged-in users only — the chat is not public.
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'error' => 'You must be logged in to upload.' ), 401 );
	}

	if ( ! check_ajax_referer( 'pcu_upload', '_wpnonce', false ) ) {
		wp_send_json_error( array( 'error' => 'Your session expired. Please reload the page.' ), 403 );
	}

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'error' => 'You are not allowed to upload files.' ), 403 );
	}

	if ( empty( $_FILES['file'] ) ) {
		wp_send_json_error( array( 'error' => 'No file received.' ), 400 );
	}

	$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! empty( $file['error'] ) ) {
		wp_send_json_error( array( 'error' => pcu_upload_error_message( (int) $file['error'] ) ), 400 );
	}

	// Size ceiling. Mirrors maxFilesize in PCU_CONFIG; the client-side check is
	// a convenience, this one is the actual limit.
	$max_bytes = (int) apply_filters( 'pcu_max_upload_bytes', 10 * MB_IN_BYTES );

	if ( (int) $file['size'] > $max_bytes ) {
		wp_send_json_error(
			array( 'error' => sprintf( 'File is too large. Maximum is %s.', size_format( $max_bytes ) ) ),
			400
		);
	}

	// Trust the sniffed type, never the browser-supplied one.
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );

	if ( empty( $check['type'] ) || ! pcu_is_allowed_type( $check['type'] ) ) {
		wp_send_json_error( array( 'error' => 'That file type is not allowed.' ), 400 );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	// media_handle_upload() runs the full pipeline: wp_handle_upload, attachment
	// post, and thumbnail generation.
	$attachment_id = media_handle_upload( 'file', 0 );

	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( array( 'error' => $attachment_id->get_error_message() ), 400 );
	}

	$url   = wp_get_attachment_url( $attachment_id );
	$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

	/**
	 * Fires once a chat attachment is stored — hook the message record here.
	 *
	 * @param int $attachment_id Newly created attachment.
	 */
	do_action( 'pcu_attachment_uploaded', $attachment_id );

	wp_send_json_success(
		array(
			'id'    => $attachment_id,
			'url'   => $url,
			'thumb' => $thumb ? $thumb : $url,
			'name'  => get_the_title( $attachment_id ),
			'type'  => get_post_mime_type( $attachment_id ),
		)
	);
}
add_action( 'wp_ajax_pcu_upload_attachment', 'pcu_handle_upload' );

/**
 * Whitelist of accepted MIME types.
 *
 * @param string $type Sniffed MIME type.
 * @return bool
 */
function pcu_is_allowed_type( $type ) {
	$allowed = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'video/mp4',
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/zip',
	);

	/**
	 * Filter the allowed chat attachment MIME types.
	 *
	 * @param string[] $allowed Allowed MIME types.
	 */
	$allowed = (array) apply_filters( 'pcu_allowed_mime_types', $allowed );

	return in_array( $type, $allowed, true );
}

/**
 * Turn a PHP upload error code into something a human can act on.
 *
 * @param int $code PHP UPLOAD_ERR_* constant.
 * @return string
 */
function pcu_upload_error_message( $code ) {
	switch ( $code ) {
		case UPLOAD_ERR_INI_SIZE:
		case UPLOAD_ERR_FORM_SIZE:
			return 'File is too large.';
		case UPLOAD_ERR_PARTIAL:
			return 'The file was only partially uploaded. Please try again.';
		case UPLOAD_ERR_NO_FILE:
			return 'No file was uploaded.';
		case UPLOAD_ERR_NO_TMP_DIR:
		case UPLOAD_ERR_CANT_WRITE:
			return 'The server could not save the file.';
		default:
			return 'Upload failed. Please try again.';
	}
}
