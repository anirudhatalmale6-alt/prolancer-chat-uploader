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
	// Start from everything WordPress itself permits — images, video, audio,
	// Office, PDF, archives and so on — rather than curating a list by hand that
	// we'd have to keep extending every time someone wants a new format.
	$exts = array();

	foreach ( array_keys( get_allowed_mime_types() ) as $pattern ) {
		// WP keys look like "jpg|jpeg|jpe" — one entry per extension.
		foreach ( explode( '|', $pattern ) as $ext ) {
			$exts[] = $ext;
		}
	}

	// ...plus the types WordPress does not carry but a chat wants (see
	// pcu_extra_mime_types). get_allowed_mime_types() cannot see those here:
	// they are added for the upload request only, and this runs on a page render.
	$exts = array_merge( $exts, array_keys( pcu_extra_mime_types() ) );

	// ...then subtract anything that could be executed or scripted.
	$exts = array_values( array_diff( $exts, pcu_blocked_extensions() ) );
	$exts = array_unique( $exts );
	sort( $exts );

	$types = '.' . implode( ',.', $exts );

	/**
	 * Filter the accepted file types (an HTML `accept` list).
	 *
	 * @param string $types Comma-separated accept list.
	 */
	return (string) apply_filters( 'pcu_accepted_files', $types );
}

/**
 * Extensions we refuse regardless of what WordPress would otherwise allow.
 *
 * The client's rule: "allow most file types as long as they are not dangerous."
 * Dangerous here means anything that can execute — on the server, in a visitor's
 * browser, or on the machine of whoever downloads it.
 *
 *   - php/phtml/phar : would run ON THE SERVER if it ever landed somewhere
 *                      executable. The single worst case.
 *   - html/htm/xhtml : opens in the victim's browser on YOUR domain, so a
 *                      malicious one can steal the session of whoever clicks it.
 *   - svg            : an SVG is XML and can carry <script>. Same problem.
 *   - js / swf       : executable in the browser.
 *   - exe/msi/bat/cmd/com/scr/vbs/ps1/sh/jar/apk : executable on the machine of
 *                      whoever downloads it, which is the other user in the chat.
 *
 * WordPress already blocks most of these, but not all — it permits .swf, and it
 * permits .htm/.html for anyone who can post unfiltered HTML. This list does not
 * rely on WP getting it right.
 */
function pcu_blocked_extensions() {
	$blocked = array(
		// Executes on the server
		'php', 'php3', 'php4', 'php5', 'php7', 'phps', 'phtml', 'phar', 'cgi', 'pl', 'py',
		// Executes in the browser, on your domain
		'html', 'htm', 'xhtml', 'shtml', 'js', 'mjs', 'svg', 'svgz', 'swf', 'xml',
		// Executes on the downloader's machine
		'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'vbs', 'vbe', 'ws', 'wsf', 'wsh',
		'ps1', 'sh', 'bash', 'jar', 'apk', 'app', 'dll', 'so', 'hta', 'reg',
	);

	/**
	 * Filter the blocked extensions.
	 *
	 * @param string[] $blocked Extensions that are never accepted.
	 */
	return (array) apply_filters( 'pcu_blocked_extensions', $blocked );
}

/**
 * Extra types the CHAT accepts, on top of WordPress's own list.
 *
 * WordPress ships a deliberately narrow allow-list. It takes a .psd but not the
 * files that sit next to one (.ai, .eps, .indd); it takes .docx but not a plain
 * .md; it takes no ebook, no font source, no .json. None of these execute
 * anywhere — not on the server, not in a browser, not on the machine that
 * downloads them — so there is no reason a chat cannot carry them.
 *
 * Two rules hold this safe:
 *   1. pcu_blocked_extensions() is still the last word (enforced below), so
 *      nothing executable can be smuggled in by adding it here.
 *   2. It applies to CHAT UPLOADS ONLY. The media library, and every other
 *      uploader on the site, keep WordPress's default list untouched.
 *
 * @return array<string,string> extension => MIME type.
 */
function pcu_extra_mime_types() {
	$extra = array(
		// Text and data
		'md'     => 'text/markdown',
		'json'   => 'application/json',
		'yml'    => 'text/yaml',
		'yaml'   => 'text/yaml',
		'sql'    => 'text/plain',
		'ini'    => 'text/plain',
		'log'    => 'text/plain',
		// Design
		'ai'     => 'application/postscript',
		'eps'    => 'application/postscript',
		'indd'   => 'application/x-indesign',
		'fig'    => 'application/octet-stream',
		'sketch' => 'application/octet-stream',
		'xd'     => 'application/octet-stream',
		// Ebooks
		'epub'   => 'application/epub+zip',
		'mobi'   => 'application/x-mobipocket-ebook',
		'azw3'   => 'application/vnd.amazon.mobi8-ebook',
		// Archives and disc images
		'bz2'    => 'application/x-bzip2',
		'xz'     => 'application/x-xz',
		'iso'    => 'application/x-iso9660-image',
	);

	/**
	 * Filter the extra chat-only file types.
	 *
	 * @param array<string,string> $extra extension => MIME type.
	 */
	$extra = (array) apply_filters( 'pcu_extra_mime_types', $extra );

	// The block list wins, always — even against this list.
	return array_diff_key( $extra, array_flip( pcu_blocked_extensions() ) );
}

/**
 * Is the request in flight an upload from the chat composer?
 *
 * Everything this file relaxes is scoped through here, so nothing it does can
 * leak into the media library or any other uploader on the site.
 */
function pcu_is_chat_upload() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the plugin's
	// upload handler verifies its own nonce; this only narrows the scope.
	$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

	return 'prolancer_ajax_upload_message_attachment' === $action;
}

/**
 * Hand the extra types to WordPress for the length of a chat upload.
 *
 * @param array $mimes Allowed MIME types.
 * @return array
 */
function pcu_allow_extra_mimes( $mimes ) {
	if ( ! pcu_is_chat_upload() ) {
		return $mimes;
	}

	return array_merge( $mimes, pcu_extra_mime_types() );
}
add_filter( 'upload_mimes', 'pcu_allow_extra_mimes' );

/**
 * Stop WordPress's content sniff from discarding the extra types.
 *
 * wp_check_filetype_and_ext() reads the file's real MIME with finfo and throws
 * the upload out if it does not match the one the extension claims. That check
 * is built for WordPress's own list and cannot be satisfied by ours: libmagic
 * reports a .ai as PDF, a .sketch as ZIP, a .json as text/plain on one server
 * and application/json on the next. Every one of those is a mismatch, so every
 * one of them would be rejected.
 *
 * Sniffing is not what keeps the site safe here — the extension is. A .md file
 * full of PHP is still a .md file: Apache will not run it, and a browser will
 * not script it. The block list (enforced by extension, below) is the control
 * that matters, and it runs regardless of this.
 *
 * So: for a chat upload, of an extension WE added, that WordPress has just
 * rejected — put the extension and type back. Nothing else is touched.
 *
 * @param array  $data     ext/type/proper_filename, as WP decided them.
 * @param string $file     Path to the uploaded temp file.
 * @param string $filename Name it was uploaded under.
 * @return array
 */
function pcu_trust_extra_types( $data, $file, $filename ) {
	// Not a chat upload, or WordPress was happy with it anyway.
	if ( ! pcu_is_chat_upload() || ! empty( $data['ext'] ) ) {
		return $data;
	}

	$ext   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$extra = pcu_extra_mime_types();   // block list already subtracted

	if ( ! isset( $extra[ $ext ] ) ) {
		return $data;   // not one of ours — WP's rejection stands
	}

	$data['ext']  = $ext;
	$data['type'] = $extra[ $ext ];

	return $data;
}
add_filter( 'wp_check_filetype_and_ext', 'pcu_trust_extra_types', 10, 3 );

/**
 * Enforce the block list on the SERVER.
 *
 * The <input accept="…"> and the JavaScript check are conveniences — both are
 * trivially bypassed by anyone who wants to (edit the DOM, or POST straight to
 * admin-ajax). This runs inside WordPress's own upload pipeline, so it is the
 * check that actually counts.
 *
 * Only applies to chat uploads, so the media library and every other uploader on
 * the site behave exactly as before.
 *
 * @param array $file $_FILES entry being handled.
 * @return array
 */
function pcu_block_dangerous_upload( $file ) {
	if ( ! pcu_is_chat_upload() ) {
		return $file;
	}

	$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

	if ( in_array( $ext, pcu_blocked_extensions(), true ) ) {
		$file['error'] = sprintf(
			/* translators: %s: file extension */
			esc_html__( '.%s files are not allowed for security reasons.', 'prolancer' ),
			$ext
		);
	}

	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'pcu_block_dangerous_upload' );

/**
 * Max size per file, in MB.
 */
function pcu_max_filesize_mb() {
	// ===== EDIT THIS NUMBER to change the size limit (MB per file) =====
	return (int) apply_filters( 'pcu_max_filesize_mb', 50 );
}

/**
 * Max number of files per message.
 */
function pcu_max_files() {
	// ===== EDIT THIS NUMBER to change how many files per message =====
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

	// Emoji picker. Depends on the uploader for the shared scrollbar.
	wp_enqueue_script(
		'pcu-chat-emoji',
		$uri . '/assets/js/chat-emoji.js',
		array( 'pcu-chat-uploader' ),
		pcu_asset_version( $dir . '/assets/js/chat-emoji.js' ),
		true
	);

	// Only the URL — the ~1,900 emoji themselves are fetched the first time the
	// picker is opened. Inlining them would put 11 KB of uncacheable characters
	// into the HTML of every chat page, including for everyone who never opens
	// it. As a file the browser caches it once and reuses it everywhere.
	wp_localize_script(
		'pcu-chat-emoji',
		'PCU_EMOJI',
		array(
			'url' => add_query_arg(
				'ver',
				pcu_asset_version( $dir . '/assets/emoji-data.json' ),
				$uri . '/assets/emoji-data.json'
			),
		)
	);

	// Media viewer. Stands alone — it only needs the markup in the chat.
	wp_enqueue_script(
		'pcu-chat-viewer',
		$uri . '/assets/js/chat-viewer.js',
		array(),
		pcu_asset_version( $dir . '/assets/js/chat-viewer.js' ),
		true
	);

	wp_localize_script( 'pcu-chat-viewer', 'PCU_VIEWER', array( 'spinner' => pcu_spinner_url() ) );

	// Enter-to-send, tooltips, and the other user's online dot.
	wp_enqueue_script(
		'pcu-chat-extras',
		$uri . '/assets/js/chat-extras.js',
		array(),
		pcu_asset_version( $dir . '/assets/js/chat-extras.js' ),
		true
	);

	wp_localize_script(
		'pcu-chat-extras',
		'PCU_CHAT',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pcu_presence' ),
			// How often to say "still here", and how long a user stays "online"
			// after their last word. The window is comfortably more than the
			// interval, so one dropped request does not blink someone offline.
			'everyMs'  => 45000,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_enqueue_assets' );

/**
 * The site's own loading spinner.
 *
 * The SAME FILE the rest of the site uses, referenced rather than copied —
 * the plugin's dashboard.css points .processing-loader at it. The client's
 * standing rule: "use the exact same spinner as the rest… same file… so we only
 * change once." Restyle that one gif and every loader on the site follows,
 * including this one.
 */
function pcu_spinner_url() {
	$url = plugins_url( 'prolancer-element/assets/images/loader.gif' );

	/**
	 * Filter the spinner URL (if the plugin ever moves its assets).
	 *
	 * @param string $url Spinner image URL.
	 */
	return (string) apply_filters( 'pcu_spinner_url', $url );
}

/**
 * `defer` keeps the script off the critical render path.
 */
function pcu_defer_script( $tag, $handle ) {
	$ours = array( 'pcu-chat-uploader', 'pcu-chat-emoji', 'pcu-chat-viewer', 'pcu-chat-extras' );

	if ( in_array( $handle, $ours, true ) && false === strpos( $tag, ' defer' ) ) {
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
function pcu_wrap_send_handlers() {
	// If the plugin is ever deactivated, leave everything alone rather than
	// swapping its handler for one that would then call a missing function.
	if ( ! function_exists( 'prolancer_ajax_send_service_message' ) ) {
		return;
	}

	remove_action( 'wp_ajax_prolancer_ajax_send_service_message', 'prolancer_ajax_send_service_message' );
	add_action( 'wp_ajax_prolancer_ajax_send_service_message', 'pcu_send_service_message' );

	remove_action( 'wp_ajax_prolancer_ajax_send_project_message', 'prolancer_ajax_send_project_message' );
	add_action( 'wp_ajax_prolancer_ajax_send_project_message', 'pcu_send_project_message' );

	// The inbox chat has no attachments, but it shares the payload bug below.
	remove_action( 'wp_ajax_prolancer_ajax_messages', 'prolancer_ajax_messages' );
	add_action( 'wp_ajax_prolancer_ajax_messages', 'pcu_send_inbox_message' );
}
add_action( 'init', 'pcu_wrap_send_handlers' );

/**
 * Service chat: allow attachments with no text, then defer to the plugin.
 */
function pcu_send_service_message() {
	pcu_normalize_message_payload();
	prolancer_ajax_send_service_message();
}

/**
 * Project chat: same.
 */
function pcu_send_project_message() {
	pcu_normalize_message_payload();
	prolancer_ajax_send_project_message();
}

/**
 * Inbox chat: no attachments here, so this only unslashes the payload.
 */
function pcu_send_inbox_message() {
	pcu_normalize_message_payload();
	prolancer_ajax_messages();
}

/**
 * Clean up the send payload before the plugin parses it.
 * ----------------------------------------------------------------------------
 * Two things happen here, both on $_POST['message_data'] — the URL-encoded
 * string the plugin feeds straight to parse_str().
 *
 * 1. UNSLASH.
 *    WordPress adds slashes to $_POST, and the plugin never takes them off.
 *    jQuery's serialize() leaves an apostrophe unencoded, so "Nando's" arrives
 *    as "Nando\'s" — and that backslash was being stored, and shown back on
 *    every reload. Undo WordPress's slashing once, here.
 *
 *    Only the apostrophe is affected in practice: encodeURIComponent() escapes
 *    the double quote and the backslash, so those reach us as %22 and %5C and
 *    addslashes() never sees them.
 *
 * 2. ALLOW A FILE-ONLY MESSAGE.
 *    The plugin's handlers refuse an empty message:
 *
 *        if ( $params['message'] != '' ) { ...insert... }
 *        else -> "The message field cannot be empty"
 *
 *    The Upload button now sends attachments on their own, so that check has to
 *    give way — but only when files are actually attached. A genuinely empty
 *    message with no files must still be rejected. Hand the plugin a single
 *    space so its check passes; its own sanitize_text_field() trims it back to
 *    '' before the insert, so the stored message really is empty.
 *
 * Rewriting the payload rather than reimplementing the insert keeps the
 * plugin's emails and notifications intact, and keeps the plugin updatable —
 * it is never edited.
 */
function pcu_normalize_message_payload() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the plugin
	// handler we hand off to verifies its own nonce.
	if ( empty( $_POST['message_data'] ) ) {
		return;
	}

	parse_str( wp_unslash( $_POST['message_data'] ), $params );

	$message    = isset( $params['message'] ) ? trim( $params['message'] ) : '';
	$attachment = isset( $params['attachment_id'] ) ? trim( $params['attachment_id'] ) : '';

	if ( '' === $message && '' !== $attachment ) {
		$params['message'] = ' ';
	}

	// http_build_query() URL-encodes every value, so the rebuilt string holds no
	// bare quote or backslash for wp_slash() to escape — it stays byte-identical
	// either way. Re-slash anyway: $_POST is slashed by contract, and the plugin
	// (or any other filter after us) is entitled to rely on that.
	$_POST['message_data'] = wp_slash( http_build_query( $params ) );
	// phpcs:enable
}

/**
 * Render a stored chat message exactly as it was typed.
 * ----------------------------------------------------------------------------
 * Two things are true of this column, and the plugin's esc_html() only copes
 * with one of them:
 *
 *  - sanitize_text_field() runs the text through esc_html() on the way IN
 *    (via wp_pre_kses_less_than), so a typed "2 < 4" is stored as "2 &lt; 4".
 *    Those entities have to be decoded again for display.
 *
 *  - esc_html() escapes with double_encode OFF, so on the way OUT it passes any
 *    entity straight through. Text someone literally typed as "&#xb6;" was
 *    handed to the browser as a live entity and painted as ¶ — the reloaded
 *    chat showed something the sender never wrote.
 *
 * So: decode what storage encoded, then escape the lot. htmlspecialchars_decode()
 * is the exact inverse of the esc_html() applied on the way in — it reverses
 * &amp; &lt; &gt; &quot; &#039; and touches nothing else, so "&#xb6;" survives
 * as the literal text it was. Escaping with double_encode ON then guarantees
 * the browser paints the characters rather than interpreting them.
 *
 * @param string $message Raw column value.
 * @return string Escaped for output.
 */
function pcu_message_text( $message ) {
	$message = htmlspecialchars_decode( (string) $message, ENT_QUOTES );

	return htmlspecialchars( $message, ENT_QUOTES, 'UTF-8', true );
}

/**
 * Video posters
 * ----------------------------------------------------------------------------
 * A video in the chat used to show a grey "MP4" tile. It now shows a real frame
 * from the video, grabbed with ffmpeg, which this host has (8.1.1).
 *
 * Server-side rather than in the browser: it works for videos that were already
 * uploaded before this existed, it does not depend on the sender's browser being
 * able to decode its own file, and it costs the user nothing.
 *
 * The frame is stored as an ordinary attachment and hung off the video as its
 * featured image — which is exactly what WordPress's own media library uses a
 * video poster for, so it shows up there too rather than being private to us.
 *
 * Generated ONCE. Every later request just reads the meta.
 */

/**
 * Where ffmpeg is, or '' if we cannot run it.
 *
 * shell_exec is disabled on plenty of shared hosts, so this must degrade to the
 * old file tile rather than fatal.
 */
function pcu_ffmpeg() {
	static $bin = null;

	if ( null !== $bin ) {
		return $bin;
	}

	$bin      = '';
	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

	if ( ! function_exists( 'shell_exec' ) || in_array( 'shell_exec', $disabled, true ) ) {
		return $bin;
	}

	$found = shell_exec( 'command -v ffmpeg 2>/dev/null' );

	if ( $found ) {
		$bin = trim( $found );
	}

	/**
	 * Filter the ffmpeg binary (set an absolute path if it is not on PATH).
	 *
	 * @param string $bin Path to ffmpeg, or '' to disable posters.
	 */
	return (string) apply_filters( 'pcu_ffmpeg', $bin );
}

/**
 * The size the chat tile asks WordPress for.
 *
 * NOT 'thumbnail'. WordPress's 'thumbnail' is a 150x150 hard CROP — it does not
 * scale a photo down, it cuts a square out of the middle and throws the rest
 * away. A wide product shot loses its ends before any CSS gets a say, which is
 * why the tile showed a cropped image while the pre-send preview (which reads
 * the original file) showed all of it.
 *
 * 'medium' is bounded, not cropped: the whole picture, longest side 300px. The
 * tile is 84x64, so that is more than enough resolution on a 2x screen, and
 * object-fit: contain then letterboxes it into the tile intact.
 */
function pcu_tile_size() {
	/**
	 * Filter the image size used for chat attachment tiles.
	 *
	 * @param string $size A registered image size. Must NOT be a cropped one.
	 */
	return (string) apply_filters( 'pcu_tile_size', 'medium' );
}

/**
 * The poster for a video attachment, generating it on first use.
 *
 * Two sizes matter and they are NOT interchangeable: the tile wants a small,
 * uncropped copy (pcu_tile_size), while behind a full-screen video anything
 * smaller than 'full' would show a 9:16 phone clip as a stretched thumbnail.
 *
 * @param int    $video_id Attachment ID of the video.
 * @param string $size     Any registered image size.
 * @return string Poster image URL, or '' if one could not be made.
 */
function pcu_video_poster_url( $video_id, $size = '' ) {
	if ( '' === $size ) {
		$size = pcu_tile_size();
	}

	$poster_id = (int) get_post_thumbnail_id( $video_id );

	if ( ! $poster_id ) {
		// Don't re-run ffmpeg on every page view for a file it already choked on.
		if ( get_post_meta( $video_id, '_pcu_poster_failed', true ) ) {
			return '';
		}

		$poster_id = pcu_make_video_poster( $video_id );

		if ( ! $poster_id ) {
			update_post_meta( $video_id, '_pcu_poster_failed', 1 );

			return '';
		}
	}

	return (string) wp_get_attachment_image_url( $poster_id, $size );
}

/**
 * Grab a frame and attach it to the video.
 *
 * @param int $video_id Attachment ID of the video.
 * @return int Poster attachment ID, or 0.
 */
function pcu_make_video_poster( $video_id ) {
	$ffmpeg = pcu_ffmpeg();
	$source = get_attached_file( $video_id );

	if ( ! $ffmpeg || ! $source || ! file_exists( $source ) ) {
		return 0;
	}

	$uploads = wp_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}

	$name = pathinfo( $source, PATHINFO_FILENAME ) . '-poster.jpg';
	$name = wp_unique_filename( $uploads['path'], $name );
	$dest = trailingslashit( $uploads['path'] ) . $name;

	// -ss BEFORE -i seeks by keyframe, which is near-instant even on a large
	// file; after -i it would decode every frame up to that point.
	//
	// One second in, not zero: the first frame of a phone video is very often
	// black or a blurred autofocus frame. If the clip is shorter than that the
	// seek lands past the end and ffmpeg writes nothing — so fall back to 0.
	//
	// `-threads 1` sits AFTER -i, which is what makes it an ENCODER option.
	// Without it this host fails every single time with
	//
	//     [mjpeg] ff_frame_thread_encoder_init failed
	//     Conversion failed!
	//
	// because shared hosting caps how many threads a process may spawn and the
	// JPEG encoder cannot start its frame-threading pool. One frame needs no
	// pool. (Putting -threads BEFORE -i sets the DECODER's thread count and
	// changes nothing — the encoder still fails.)
	//
	// `timeout` caps a pathological file rather than hanging the request.
	foreach ( array( 1, 0 ) as $seek ) {
		$cmd = sprintf(
			'timeout 20 %s -y -ss %d -i %s -threads 1 -frames:v 1 -vf %s -q:v 4 %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			$seek,
			escapeshellarg( $source ),
			escapeshellarg( 'scale=640:-2' ),   // keep the aspect; -2 keeps it even for jpeg
			escapeshellarg( $dest )
		);

		shell_exec( $cmd );

		if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
			break;
		}
	}

	if ( ! file_exists( $dest ) || ! filesize( $dest ) ) {
		return 0;
	}

	$poster_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => get_the_title( $video_id ),
			'post_status'    => 'inherit',
			'post_parent'    => $video_id,
		),
		$dest
	);

	if ( is_wp_error( $poster_id ) || ! $poster_id ) {
		wp_delete_file( $dest );

		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $poster_id, wp_generate_attachment_metadata( $poster_id, $dest ) );

	set_post_thumbnail( $video_id, $poster_id );

	return (int) $poster_id;
}

/**
 * Make the poster as soon as the video is uploaded, so the sender is already
 * waiting on a request and nobody's page render pays for it later.
 *
 * Scoped to chat uploads: every other uploader on the site behaves as before.
 */
function pcu_poster_on_upload( $attachment_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the plugin's
	// upload handler verifies its own nonce; this only narrows the scope.
	$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

	if ( 'prolancer_ajax_upload_message_attachment' !== $action ) {
		return;
	}

	if ( 'video' === pcu_attachment_kind( $attachment_id ) ) {
		pcu_video_poster_url( $attachment_id );
	}
}
add_action( 'add_attachment', 'pcu_poster_on_upload' );

/**
 * Online / offline
 * ----------------------------------------------------------------------------
 * "Online" means "was doing something on this site in the last couple of
 * minutes". Every logged-in page view stamps the time, and an open chat keeps
 * stamping it on a timer so someone reading a long thread does not go grey
 * while they are plainly there.
 *
 * Deliberately not Pusher presence channels, even though Pusher is already
 * wired up here: presence would report someone as offline the moment they
 * closed the tab, but it needs an auth endpoint per channel, and it only knows
 * about users who happen to be on a chat screen. A last-seen stamp is simpler,
 * survives a page reload, and is right about the thing being asked.
 */
function pcu_online_window() {
	// A user is online if they were seen this recently.
	return (int) apply_filters( 'pcu_online_window', 2 * MINUTE_IN_SECONDS );
}

/**
 * Stamp the current user as seen, on any page they load.
 */
function pcu_touch_last_seen() {
	$uid = get_current_user_id();

	if ( ! $uid ) {
		return;
	}

	// Only write when the value would actually change — this runs on every page
	// load of every logged-in user, and an UPDATE per request would be silly.
	$last = (int) get_user_meta( $uid, '_pcu_last_seen', true );

	if ( time() - $last > 30 ) {
		update_user_meta( $uid, '_pcu_last_seen', time() );
	}
}
add_action( 'init', 'pcu_touch_last_seen' );

/**
 * Is this user online?
 *
 * @param int $user_id WP user ID.
 * @return bool
 */
function pcu_user_is_online( $user_id ) {
	$last = (int) get_user_meta( (int) $user_id, '_pcu_last_seen', true );

	return $last > 0 && ( time() - $last ) < pcu_online_window();
}

/**
 * AJAX: "I'm still here — and is the person I'm talking to?"
 *
 * The chat sends the OTHER party's profile post ID, which is already in the
 * page: the composer's send button carries it as data-receiver-id. Mapping that
 * to a WP user is get_post_field('post_author'), so there is nothing to look up
 * in the plugin's tables and nothing to keep in step with its four different
 * chat templates.
 */
function pcu_ajax_presence() {
	check_ajax_referer( 'pcu_presence', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error();
	}

	// The ping itself is what keeps the sender online.
	update_user_meta( get_current_user_id(), '_pcu_last_seen', time() );

	$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
	$author     = $profile_id ? (int) get_post_field( 'post_author', $profile_id ) : 0;

	wp_send_json_success(
		array(
			'online' => $author ? pcu_user_is_online( $author ) : false,
		)
	);
}
add_action( 'wp_ajax_pcu_presence', 'pcu_ajax_presence' );

/**
 * Icon for a non-media file type.
 * ----------------------------------------------------------------------------
 * The icons live in assets/icons/ and are NAMED AFTER THE EXTENSION — zip.png,
 * docx.png — so the filename is the lookup and there is no map to keep in sync.
 * Adding a type later is dropping a PNG in the folder; nothing here changes.
 * Anything with no icon of its own falls back to generic.png.
 *
 * @param string $ext File extension, any case.
 * @return string Icon URL.
 */
function pcu_file_icon_url( $ext ) {
	$ext = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) );
	$dir = get_stylesheet_directory() . '/assets/icons/';
	$uri = get_stylesheet_directory_uri() . '/assets/icons/';

	if ( '' !== $ext && file_exists( $dir . $ext . '.png' ) ) {
		return $uri . $ext . '.png';
	}

	return $uri . 'generic.png';
}

/**
 * The name to SHOW for an attachment.
 *
 * Not the filename on disk. WordPress sanitises an upload's name — spaces become
 * hyphens, punctuation is stripped — so "Half of the dot, outside the image.png"
 * is stored as "Half-of-the-dot-outside-the-image.png", and reading it back to
 * the user is showing them plumbing. The post title keeps the name they actually
 * chose, so display that, with the extension put back on the end.
 *
 * The real filename is still what the download uses (data-file) — the save
 * dialogue has to write a file that exists.
 *
 * @param int    $id  Attachment ID.
 * @param string $ext Extension.
 * @return string
 */
function pcu_attachment_name( $id, $ext ) {
	$title = get_the_title( $id );
	$ext   = strtolower( $ext );

	if ( '' === $title ) {
		return wp_basename( (string) wp_get_attachment_url( $id ) );
	}

	// Don't end up with "report.pdf.pdf" if the title already carries it.
	if ( $ext && strtolower( substr( $title, -( strlen( $ext ) + 1 ) ) ) === '.' . $ext ) {
		return $title;
	}

	return $ext ? $title . '.' . $ext : $title;
}

/**
 * The tooltip for an attachment: full name, then type and size.
 *
 * Two lines, because the name is the thing that gets truncated in the tile and
 * the size is what people actually want to know before they click a 40 MB
 * download. Rendered by our own tooltip, not the browser's title bubble.
 *
 * @param int    $id  Attachment ID.
 * @param string $ext Extension.
 * @return string
 */
function pcu_attachment_tip( $id, $ext ) {
	$file = get_attached_file( $id );
	$size = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 0 ) : '';

	$line2 = trim( strtoupper( $ext ) . ( $size ? ' · ' . $size : '' ) );

	return pcu_attachment_name( $id, $ext ) . "\n" . $line2;
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
/**
 * How the viewer should present an attachment.
 *
 * Only image, video and audio can actually be shown — everything else (pdf,
 * zip, docx…) has no preview, so the viewer draws a file tile instead.
 *
 * @param int $id Attachment ID.
 * @return string 'image' | 'video' | 'audio' | 'file'
 */
function pcu_attachment_kind( $id ) {
	if ( wp_attachment_is_image( $id ) ) {
		return 'image';
	}

	foreach ( array( 'video', 'audio' ) as $kind ) {
		if ( wp_attachment_is( $kind, $id ) ) {
			return $kind;
		}
	}

	return 'file';
}

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

		$name   = get_the_title( $id );
		$kind   = pcu_attachment_kind( $id );
		$ext    = strtoupper( pathinfo( $url, PATHINFO_EXTENSION ) );
		// Full size behind the video in the viewer; the small uncropped copy on the tile.
		$poster = 'video' === $kind ? pcu_video_poster_url( $id, 'full' ) : '';

		// A video shows a frame from itself; an image shows itself; anything
		// else has no preview and shows its type.
		if ( 'image' === $kind ) {
			$thumb = wp_get_attachment_image_url( $id, pcu_tile_size() );
		} elseif ( $poster ) {
			$thumb = pcu_video_poster_url( $id, pcu_tile_size() );   // already generated
		} else {
			$thumb = '';
		}

		// The viewer reads these: what to render the file AS, the real filename
		// to hand the browser's save dialogue (the post title has had its
		// extension stripped), and the frame to show before a video is played.
		//
		// data-tip, NOT title: the browser's own title bubble cannot be styled
		// and cannot hold two lines. chat-extras.js draws ours instead.
		$icon = $thumb ? '' : pcu_file_icon_url( $ext );

		printf(
			'<a class="pcu-chat-thumb" href="%s" target="_blank" rel="noopener" data-tip="%s" data-name="%s" data-kind="%s" data-file="%s"%s%s>',
			esc_url( $url ),
			esc_attr( pcu_attachment_tip( $id, $ext ) ),
			esc_attr( pcu_attachment_name( $id, $ext ) ),   // shown to the reader
			esc_attr( $kind ),
			esc_attr( wp_basename( $url ) ),                // used by the save dialogue
			$poster ? sprintf( ' data-poster="%s"', esc_url( $poster ) ) : '',
			$icon ? sprintf( ' data-icon="%s"', esc_url( $icon ) ) : ''
		);

		if ( $thumb ) {
			// Explicit width/height so the row cannot shift as images decode.
			printf(
				'<img src="%s" alt="%s" width="84" height="64" loading="lazy">',
				esc_url( $thumb ),
				esc_attr( $name )
			);
		} else {
			// No preview possible — show what kind of file it is.
			printf(
				'<img class="pcu-chat-icon" src="%s" alt="%s" width="84" height="64" loading="lazy">',
				esc_url( $icon ),
				esc_attr( $ext )
			);
		}

		// A frame from a video looks exactly like a photo without this.
		if ( 'video' === $kind && $thumb ) {
			echo '<span class="pcu-chat-play" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>'
				. '</span>';
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

	<?php // The wrapper is what the popup is positioned against. Rendered here,
	      // not created in JS, so the composer's layout is final before paint. ?>
	<span class="pcu-emoji-wrap">
		<button type="button" class="pcu-emoji-btn" aria-label="<?php esc_attr_e( 'Insert emoji', 'prolancer' ); ?>"
				aria-expanded="false">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
				 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="12" cy="12" r="9"/>
				<path d="M8.5 14.5a4.2 4.2 0 0 0 7 0"/>
				<path d="M9 9.5h.01M15 9.5h.01"/>
			</svg>
		</button>
	</span>
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
