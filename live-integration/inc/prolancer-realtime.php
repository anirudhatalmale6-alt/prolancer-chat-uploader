<?php
/**
 * ProLancer Realtime Chat (Pusher)
 * ---------------------------------
 * Adds real-time delivery to the ProLancer chats using Pusher Channels.
 * Covers:
 *   - Service order chat (Ongoing Service Details) incl. file attachments
 *   - Dashboard "Message" chat
 * All code lives in the child theme so plugin/theme updates cannot wipe it.
 *
 * Author: Anirudha T.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pusher account configuration (from the Pusher "Channels" app dashboard).
 */
function prolancer_pusher_config() {
	return array(
		'app_id'  => '1334403',
		'key'     => '66ee19e074e07582be85',
		'secret'  => '0e4010e7c287c66f9153',
		'cluster' => 'ap4',
	);
}

/**
 * True once real Pusher keys have been entered.
 */
function prolancer_pusher_is_configured() {
	$cfg = prolancer_pusher_config();
	foreach ( array( 'app_id', 'key', 'secret', 'cluster' ) as $k ) {
		if ( empty( $cfg[ $k ] ) || strpos( $cfg[ $k ], 'YOUR_' ) === 0 ) {
			return false;
		}
	}
	return true;
}

/**
 * Fire an event to a Pusher channel via the Pusher REST API (no SDK dependency).
 *
 * @param string $channel Channel name.
 * @param string $event   Event name.
 * @param array  $payload Data (JSON encoded before sending).
 * @return bool
 */
function prolancer_pusher_trigger( $channel, $event, $payload ) {
	if ( ! prolancer_pusher_is_configured() ) {
		return false;
	}

	$cfg = prolancer_pusher_config();

	$data = wp_json_encode( $payload );
	$body = wp_json_encode(
		array(
			'name'    => $event,
			'channel' => $channel,
			'data'    => $data,
		)
	);

	$path   = '/apps/' . $cfg['app_id'] . '/events';
	$params = array(
		'auth_key'       => $cfg['key'],
		'auth_timestamp' => time(),
		'auth_version'   => '1.0',
		'body_md5'       => md5( $body ),
	);
	ksort( $params );

	$query = array();
	foreach ( $params as $pk => $pv ) {
		$query[] = $pk . '=' . $pv;
	}
	$query = implode( '&', $query );

	$sign_str  = "POST\n" . $path . "\n" . $query;
	$signature = hash_hmac( 'sha256', $sign_str, $cfg['secret'] );

	$url = 'https://api-' . $cfg['cluster'] . '.pusher.com' . $path . '?' . $query . '&auth_signature=' . $signature;

	$resp = wp_remote_post(
		$url,
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $body,
			'timeout' => 8,
		)
	);

	if ( is_wp_error( $resp ) ) {
		return false;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	return ( $code >= 200 && $code < 300 );
}

/**
 * Avatar HTML for a WP user (Dashboard "Message" chat, sender_id = user ID).
 */
function prolancer_realtime_user_avatar( $user_id ) {
	$user_id = intval( $user_id );

	$posts = get_posts(
		array(
			'post_type'   => 'sellers',
			'author'      => $user_id,
			'numberposts' => 1,
		)
	);
	$pid = ! empty( $posts ) ? $posts[0]->ID : 0;
	$att = $pid ? get_post_meta( $pid, 'seller_profile_attachment', true ) : '';

	if ( $att && function_exists( 'prolancer_get_image_id' ) ) {
		$img = wp_get_attachment_image( prolancer_get_image_id( $att ), array( '50', '50' ), false );
		if ( $img ) {
			return $img;
		}
	}
	return get_avatar( $user_id, 50 );
}

/**
 * Avatar HTML for a profile post (service order chat, sender_id = seller/buyer profile ID).
 *
 * @param int    $profile_id seller_id or buyer_id (a post ID).
 * @param string $role       'seller' or 'buyer'.
 */
function prolancer_realtime_profile_avatar( $profile_id, $role ) {
	$profile_id = intval( $profile_id );
	$meta_key   = ( 'buyer' === $role ) ? 'buyer_profile_attachment' : 'seller_profile_attachment';
	$att        = $profile_id ? get_post_meta( $profile_id, $meta_key, true ) : '';

	if ( $att && function_exists( 'prolancer_get_image_id' ) ) {
		$img = wp_get_attachment_image( prolancer_get_image_id( $att ), array( '50', '50' ), false );
		if ( $img ) {
			return $img;
		}
	}
	// Fall back to the post author's avatar.
	$author = $profile_id ? get_post_field( 'post_author', $profile_id ) : 0;
	return get_avatar( $author, 50 );
}

/**
 * The current user's role + profile id + avatar for the service chat.
 */
function prolancer_realtime_current_profile() {
	$uid  = get_current_user_id();
	$role = get_user_meta( $uid, 'visit_as', true );
	$role = ( 'buyer' === $role ) ? 'buyer' : 'seller';
	$pid  = intval( get_user_meta( $uid, ( 'buyer' === $role ) ? 'buyer_id' : 'seller_id', true ) );
	return array(
		'role'   => $role,
		'id'     => $pid,
		'avatar' => prolancer_realtime_profile_avatar( $pid, $role ),
	);
}

/**
 * AJAX: relay a just-saved message to the other participant(s) in real time.
 * The message is already stored by the plugin's own handler; this only pushes.
 *
 * Two scopes:
 *   - order (service order chat): channel prolancer-order-{order_id}
 *   - user  (dashboard message chat): channel prolancer-user-{receiver_id}
 */
/**
 * Expand a stored attachment_id ("12" or "12,13,14") into renderable data.
 *
 * @param string $stored Raw column / POST value.
 * @return array[] List of {id, url, thumb, name, is_image}.
 */
function prolancer_realtime_attachments( $stored ) {
	$out = array();

	if ( empty( $stored ) ) {
		return $out;
	}

	foreach ( explode( ',', (string) $stored ) as $raw ) {
		$id  = intval( trim( $raw ) );
		$url = $id ? wp_get_attachment_url( $id ) : '';

		if ( ! $url ) {
			continue;   // deleted from the media library
		}

		$is_image = wp_attachment_is_image( $id );
		$kind     = function_exists( 'pcu_attachment_kind' ) ? pcu_attachment_kind( $id ) : 'file';
		$is_video = ( 'video' === $kind && function_exists( 'pcu_video_poster_url' ) );

		// Full size sits behind the video in the viewer; the square crop is the
		// tile in the chat. Swapping them puts a 150x150 crop on a full screen.
		$poster = $is_video ? pcu_video_poster_url( $id, 'full' ) : '';
		$tile   = $is_video && $poster ? pcu_video_poster_url( $id, 'thumbnail' ) : '';

		// A video shows the frame we grabbed from it, exactly as it does after a
		// reload — so a clip that arrives live is not a bare "MP4" tile until the
		// page is refreshed.
		$thumb = $is_image ? wp_get_attachment_image_url( $id, 'thumbnail' ) : $tile;

		if ( is_ssl() ) {
			// Keep links on https so they don't trigger mixed-content blocking.
			$url    = set_url_scheme( $url, 'https' );
			$thumb  = $thumb ? set_url_scheme( $thumb, 'https' ) : '';
			$poster = $poster ? set_url_scheme( $poster, 'https' ) : '';
		}

		$out[] = array(
			'id'       => $id,
			'url'      => $url,
			'thumb'    => $thumb ? $thumb : '',
			'name'     => get_the_title( $id ),
			'is_image' => (bool) $is_image,

			// The viewer needs the same two facts the server-rendered markup
			// carries, or an attachment that arrives live would open blank.
			// 'file' is also the only place the real extension survives —
			// get_the_title() has already stripped it.
			'kind'     => $kind,
			'file'     => wp_basename( $url ),
			'poster'   => $poster,
		);
	}

	return $out;
}

function prolancer_realtime_push() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in' ) );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'prolancer_realtime' ) ) {
		wp_send_json_error( array( 'message' => 'Bad nonce' ) );
	}

	$uid         = get_current_user_id();
	$sender_id   = isset( $_POST['sender_id'] ) ? intval( $_POST['sender_id'] ) : 0;
	$receiver_id = isset( $_POST['receiver_id'] ) ? intval( $_POST['receiver_id'] ) : 0;
	$order_id    = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
	// A message can now carry SEVERAL attachments, sent as "12,13,14".
	// intval() would silently keep only the first one.
	$attach_raw  = isset( $_POST['attachment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['attachment_id'] ) ) : '';
	$message     = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';

	$attachments = prolancer_realtime_attachments( $attach_raw );

	// Files on their own are a valid message — the Upload button sends them with
	// no text typed. Reject only when there is neither text nor an attachment.
	if ( '' === $message && empty( $attachments ) ) {
		wp_send_json_error( array( 'message' => 'Empty' ) );
	}

	// Kept for backward compatibility with anything still reading a single URL.
	$attachment_url = ! empty( $attachments ) ? $attachments[0]['url'] : '';

	if ( $order_id > 0 ) {
		// ---- Service order chat ----
		$prof = prolancer_realtime_current_profile();
		// Security: the sender must be one of the current user's own profiles.
		$my_seller = intval( get_user_meta( $uid, 'seller_id', true ) );
		$my_buyer  = intval( get_user_meta( $uid, 'buyer_id', true ) );
		if ( $sender_id !== $my_seller && $sender_id !== $my_buyer ) {
			wp_send_json_error( array( 'message' => 'Sender mismatch' ) );
		}

		$avatar = prolancer_realtime_profile_avatar( $sender_id, ( $sender_id === $my_buyer ) ? 'buyer' : 'seller' );

		$payload = array(
			'scope'          => 'order',
			'order_id'       => $order_id,
			'sender_id'      => $sender_id,
			'receiver_id'    => $receiver_id,
			'message'        => $message,
			'attachment_url' => $attachment_url ? $attachment_url : '',   // legacy
			'attachments'    => $attachments,
			'avatar'         => $avatar,
			'timestamp'      => current_time( 'mysql' ),
		);
		$ok = prolancer_pusher_trigger( 'prolancer-order-' . $order_id, 'new-message', $payload );

		wp_send_json_success(
			array(
				'pushed'         => (bool) $ok,
				'avatar'         => $avatar,
				'attachment_url' => $attachment_url ? $attachment_url : '',   // legacy
				'attachments'    => $attachments,
			)
		);
	} else {
		// ---- Dashboard message chat ----
		if ( $sender_id !== $uid ) {
			wp_send_json_error( array( 'message' => 'Sender mismatch' ) );
		}
		if ( $receiver_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'No receiver' ) );
		}
		$avatar  = prolancer_realtime_user_avatar( $sender_id );
		$payload = array(
			'scope'       => 'user',
			'sender_id'   => $sender_id,
			'receiver_id' => $receiver_id,
			'message'     => $message,
			'avatar'      => $avatar,
			'timestamp'   => current_time( 'mysql' ),
		);
		$ok = prolancer_pusher_trigger( 'prolancer-user-' . $receiver_id, 'new-message', $payload );

		wp_send_json_success(
			array(
				'pushed' => (bool) $ok,
				'avatar' => $avatar,
			)
		);
	}
}
add_action( 'wp_ajax_prolancer_realtime_push', 'prolancer_realtime_push' );

/**
 * Enqueue Pusher JS + our real-time chat script, and pass config to the browser.
 */
function prolancer_realtime_enqueue() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	wp_enqueue_script( 'pusher-js', 'https://js.pusher.com/8.2.0/pusher.min.js', array(), '8.2.0', true );

	$realtime_js = get_stylesheet_directory() . '/assets/js/realtime-chat.js';

	wp_enqueue_script(
		'prolancer-realtime',
		get_stylesheet_directory_uri() . '/assets/js/realtime-chat.js',
		array( 'jquery', 'prolancer-app', 'pusher-js' ),
		// filemtime, NOT a hand-written version string. A fixed '1.1.0' meant
		// that editing this file changed nothing for anyone: browsers kept
		// serving the cached copy, so users ran old chat code until they cleared
		// their cache. Now the version changes whenever the file does.
		file_exists( $realtime_js ) ? (string) filemtime( $realtime_js ) : '1.1.0',
		true
	);

	$cfg  = prolancer_pusher_config();
	$prof = prolancer_realtime_current_profile();

	wp_localize_script(
		'prolancer-realtime',
		'prolancerRealtime',
		array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'enabled'      => prolancer_pusher_is_configured() ? 1 : 0,
			'key'          => prolancer_pusher_is_configured() ? $cfg['key'] : '',
			'cluster'      => prolancer_pusher_is_configured() ? $cfg['cluster'] : '',
			'userId'       => get_current_user_id(),
			'profileId'    => $prof['id'],
			'pushNonce'    => wp_create_nonce( 'prolancer_realtime' ),
			'myUserAvatar' => prolancer_realtime_user_avatar( get_current_user_id() ),
			'downloadText' => esc_html__( 'Download', 'prolancer' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'prolancer_realtime_enqueue', 20 );
