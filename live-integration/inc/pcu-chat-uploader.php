<?php
/**
 * ProLancer — Chat attachment uploader (server side)
 * --------------------------------------------------
 * Replaces the plain "Choose File" input in the order chat with an attach icon
 * that opens a modal drag-and-drop uploader, and lets a single message carry
 * SEVERAL attachments instead of one.
 *
 * Everything lives in the child theme. The plugin and parent theme are not
 * modified, so both stay safe to update.
 *
 * How multiple attachments work without a database change:
 *   `attachment_id` in prolancer_service_messages / prolancer_project_messages
 *   is varchar(300), not an int, and the plugin saves it with
 *   sanitize_text_field(). So a comma-separated list of IDs ("12,13,14") stores
 *   and round-trips as-is. Old rows hold a single ID and still parse correctly.
 *
 * Uploads reuse the plugin's own AJAX endpoint
 * (prolancer_ajax_upload_message_attachment) — no second uploader to maintain.
 *
 * Author: Anirudha T.
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the chat accepts, and how big.
 *
 * ONE source of truth: the <input accept="…">, the JS validation and the footer
 * hint all read from here, so the three can never drift apart.
 *
 * The server is the real gate. WordPress already permits video and zip, and this
 * host allows 256 MB uploads (upload_max_filesize / post_max_size), so 50 MB is a
 * deliberate policy ceiling, not a technical one: it comfortably covers a phone
 * video without letting anyone drop a 200 MB file into a chat thread.
 */
function pcu_accepted_files() {
	$types = 'image/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.zip';

	/**
	 * Filter the accepted file types (an HTML `accept` list).
	 *
	 * @param string $types Comma-separated accept list.
	 */
	return (string) apply_filters( 'pcu_accepted_files', $types );
}

/**
 * Max size per file, in MB.
 */
function pcu_max_filesize_mb() {
	return (int) apply_filters( 'pcu_max_filesize_mb', 50 );
}

/**
 * Max number of files per message.
 */
function pcu_max_files() {
	return (int) apply_filters( 'pcu_max_files', 10 );
}

/**
 * Dashboard screens that show a chat with attachments.
 */
function pcu_chat_screens() {
	return array(
		'ongoing-service-details',
		'ongoing-project-details',
		'completed-service-details',
		'completed-project-details',
	);
}

/**
 * Is the current request one of those screens?
 *
 * The dashboard selects its screen with ?fed=… (see the plugin's
 * prolancer-dashboard.php).
 */
function pcu_is_chat_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
	$fed = isset( $_GET['fed'] ) ? sanitize_key( wp_unslash( $_GET['fed'] ) ) : '';

	return in_array( $fed, pcu_chat_screens(), true );
}

/**
 * Assets — chat screens only, so no other page pays for them.
 */
function pcu_enqueue_assets() {
	if ( ! pcu_is_chat_screen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	// One stylesheet, one script. No Dropzone, no Bootstrap, no jQuery.
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
			// The plugin's own upload endpoint. The nonce and post_id come from
			// the (now hidden) file input the plugin already renders.
			'uploadUrl'     => admin_url( 'admin-ajax.php' ),
			'action'        => 'prolancer_ajax_upload_message_attachment',
			'maxFiles'      => pcu_max_files(),
			'maxFilesize'   => pcu_max_filesize_mb(),
			'acceptedFiles' => pcu_accepted_files(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_enqueue_assets' );

/**
 * `defer` keeps the script off the critical render path.
 */
function pcu_defer_script( $tag, $handle ) {
	if ( 'pcu-chat-uploader' === $handle && false === strpos( $tag, ' defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'pcu_defer_script', 10, 2 );

/**
 * Cache-bust on file change, so browsers only re-download what actually changed.
 */
function pcu_asset_version( $path ) {
	return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
}

/**
 * Let a message carry files with no text.
 * ----------------------------------------------------------------------------
 * The plugin's send handlers refuse an empty message:
 *
 *     if ( $params['message'] != '' ) { ...insert... }
 *     else -> "The message field cannot be empty"
 *
 * The Upload button now sends attachments on their own, so that check has to
 * give way — but only when files are actually attached. A genuinely empty
 * message with no files must still be rejected.
 *
 * Rather than reimplement the plugin's insert (and its emails and
 * notifications), we wrap its handler: if the message is blank but attachments
 * are present, hand it a single space so its check passes, then call it. The
 * plugin runs the value through sanitize_text_field(), which trims — so what
 * lands in the database is a genuinely empty string, not a fake placeholder.
 *
 * The plugin itself is never edited, so it stays updatable.
 */
function pcu_allow_attachment_only_messages() {
	// If the plugin is ever deactivated, leave everything alone rather than
	// swapping its handler for one that would then call a missing function.
	if ( ! function_exists( 'prolancer_ajax_send_service_message' ) ) {
		return;
	}

	remove_action( 'wp_ajax_prolancer_ajax_send_service_message', 'prolancer_ajax_send_service_message' );
	add_action( 'wp_ajax_prolancer_ajax_send_service_message', 'pcu_send_service_message' );

	remove_action( 'wp_ajax_prolancer_ajax_send_project_message', 'prolancer_ajax_send_project_message' );
	add_action( 'wp_ajax_prolancer_ajax_send_project_message', 'pcu_send_project_message' );
}
add_action( 'init', 'pcu_allow_attachment_only_messages' );

/**
 * Service chat: allow attachments with no text, then defer to the plugin.
 */
function pcu_send_service_message() {
	pcu_soften_empty_message();
	prolancer_ajax_send_service_message();
}

/**
 * Project chat: same.
 */
function pcu_send_project_message() {
	pcu_soften_empty_message();
	prolancer_ajax_send_project_message();
}

/**
 * If the message is blank but files are attached, make it pass the plugin's
 * non-empty check. Touches $_POST ONLY in that exact case — a real message is
 * left completely alone, so nothing can mangle a user's text.
 */
function pcu_soften_empty_message() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the plugin
	// handler we hand off to verifies its own nonce.
	if ( empty( $_POST['message_data'] ) ) {
		return;
	}

	parse_str( $_POST['message_data'], $params );

	$message    = isset( $params['message'] ) ? trim( $params['message'] ) : '';
	$attachment = isset( $params['attachment_id'] ) ? trim( $params['attachment_id'] ) : '';

	// Text present, or no files: leave it exactly as it came in.
	if ( '' !== $message || '' === $attachment ) {
		return;
	}

	// A single space clears `!= ''`; sanitize_text_field() then trims it away,
	// so the stored message really is empty.
	$params['message'] = ' ';

	$_POST['message_data'] = http_build_query( $params );
	// phpcs:enable
}

/**
 * Parse the stored attachment_id into a list of IDs.
 *
 * Accepts both the new "12,13,14" and the legacy single "12".
 *
 * @param string $stored Raw column value.
 * @return int[]
 */
function pcu_parse_attachment_ids( $stored ) {
	if ( empty( $stored ) ) {
		return array();
	}

	$ids = array_map( 'intval', explode( ',', (string) $stored ) );

	return array_values( array_filter( $ids ) );
}

/**
 * Render a message's attachments: a thumbnail for images, a labelled tile for
 * everything else. Both link to the file.
 *
 * @param string $stored Raw attachment_id column value.
 */
function pcu_render_attachments( $stored ) {
	$ids = pcu_parse_attachment_ids( $stored );

	if ( empty( $ids ) ) {
		return;
	}

	echo '<div class="pcu-chat-attachments">';

	foreach ( $ids as $id ) {
		$url = wp_get_attachment_url( $id );

		if ( ! $url ) {
			continue;   // attachment was deleted from the media library
		}

		$name    = get_the_title( $id );
		$is_img  = wp_attachment_is_image( $id );
		$thumb   = $is_img ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		$ext     = strtoupper( pathinfo( $url, PATHINFO_EXTENSION ) );

		printf(
			'<a class="pcu-chat-thumb" href="%s" target="_blank" rel="noopener" title="%s">',
			esc_url( $url ),
			esc_attr( $name )
		);

		if ( $is_img && $thumb ) {
			// Explicit width/height so the row cannot shift as images decode.
			printf(
				'<img src="%s" alt="%s" width="84" height="64" loading="lazy">',
				esc_url( $thumb ),
				esc_attr( $name )
			);
		} else {
			printf( '<span class="pcu-chat-file">%s</span>', esc_html( $ext ) );
		}

		printf( '<span class="pcu-chat-caption">%s</span>', esc_html( $name ) );
		echo '</a>';
	}

	echo '</div>';
}

/**
 * The attach icon + the upload modal, rendered into the composer.
 *
 * Printed server-side rather than injected by JS: markup that appears after
 * load would push the Send button and cause a layout shift.
 *
 * The plugin's own <input id="upload-message-attachments"> is left in the page
 * (hidden by CSS) because it carries the post_id and nonce the upload endpoint
 * needs — the JS reads them straight off it. Nothing binds to its change event
 * any more, so the plugin's old uploader (and its "Successfully uploaded!"
 * popup) never fires.
 */
function pcu_attach_button() {
	static $modal_done = false;
	?>
	<button type="button" class="pcu-attach-btn" aria-label="<?php esc_attr_e( 'Attach files', 'prolancer' ); ?>">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
			 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
		</svg>
		<span class="pcu-attach-count">0</span>
	</button>
	<?php

	// One modal per page, however many composers there are.
	if ( $modal_done ) {
		return;
	}
	$modal_done = true;

	pcu_render_modal();
}

/**
 * The upload modal itself.
 */
function pcu_render_modal() {
	?>
	<div id="pcu-uploader">
		<div class="pcu-modal" role="dialog" aria-modal="true" aria-labelledby="pcu-title" aria-hidden="true">
			<div class="pcu-modal-dialog">

				<div class="pcu-modal-header">
					<h5 class="pcu-modal-title" id="pcu-title"><?php esc_html_e( 'Attach files', 'prolancer' ); ?></h5>
				</div>

				<div class="pcu-modal-body">

					<?php
					// OUTSIDE .pcu-dropzone on purpose. As a child of the dropzone,
					// the synthetic click from input.click() bubbles straight back
					// into the dropzone's own click handler, which calls
					// input.click() again — Chrome sees the re-entrancy and simply
					// never opens the file dialog.
					?>
					<input type="file" class="pcu-input" multiple hidden
						   accept="<?php echo esc_attr( pcu_accepted_files() ); ?>">

					<div class="pcu-dropzone">
						<div class="pcu-dz-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
								 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
								<path d="M12 16V9M9 12l3-3 3 3"/>
							</svg>
						</div>

						<div class="pcu-dz-title"><?php esc_html_e( 'Drop files here or click to upload.', 'prolancer' ); ?></div>
						<div class="pcu-dz-sub"><?php esc_html_e( 'You can drag files here, or browse files via the button below.', 'prolancer' ); ?></div>
						<button type="button" class="pcu-dz-browse"><?php esc_html_e( 'Browse files', 'prolancer' ); ?></button>
					</div>

					<?php
					// ONLY the file list scrolls. The dropzone above stays put, so
					// it never scrolls out of reach as files pile up.
					?>
					<div class="pcu-scroll-wrap">
						<div class="pcu-list-scroll pcu-scroll">
							<div class="pcu-file-list"></div>
						</div>

						<div class="pcu-sb-track is-idle" aria-hidden="true">
							<div class="pcu-sb-thumb"></div>
						</div>
					</div>
				</div>

				<div class="pcu-modal-footer">
					<span class="pcu-footer-hint">
						<?php
						printf(
							/* translators: 1: max file count, 2: max size in MB */
							esc_html__( 'Max %1$d files · %2$d MB each', 'prolancer' ),
							pcu_max_files(),
							pcu_max_filesize_mb()
						);
						?>
					</span>
					<button type="button" class="pcu-btn-close"><?php esc_html_e( 'Close', 'prolancer' ); ?></button>
					<button type="button" class="pcu-btn-upload" disabled>
						<span class="pcu-btn-label"><?php esc_html_e( 'Upload', 'prolancer' ); ?></span>
					</button>
				</div>

			</div>
		</div>
	</div>
	<?php
}
