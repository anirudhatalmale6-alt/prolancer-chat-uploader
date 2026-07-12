<?php
/**
 * Profile pictures — make the right one show up, everywhere.
 *
 * THE BUG
 *
 * Avatars load for some people and not others. The Messages page looks like
 * this (plugin's message.php):
 *
 *     $sender_posts = get_posts([ 'post_type' => 'sellers', 'author' => $user ]);
 *     $img = get_post_meta( $sender_posts[0]->ID, 'seller_profile_attachment' );
 *     ...
 *     else : echo get_avatar( $user, 60 );   // <- everyone else lands here
 *
 * It only ever asks for a SELLER picture. Talk to a buyer and there is no
 * `sellers` post to find, so it falls through to get_avatar() and WordPress
 * draws its default grey silhouette — even though that buyer has a perfectly
 * good picture saved under `buyer_profile_attachment`. Nothing is broken or
 * missing; the code simply never looks in the second place.
 *
 * THE FIX
 *
 * Not a patch to that one template. The same "sellers only" assumption is
 * repeated all over the plugin, so instead this teaches get_avatar() itself
 * where a ProLancer profile picture lives. Every fallback in every template —
 * the Messages page, the chat, reviews, listings, anything a plugin adds later —
 * starts resolving to the real picture, because they all end up at get_avatar().
 *
 * @package prolancer-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a ProLancer profile picture is kept.
 *
 * A user can be a seller, a buyer, or both — the profile post type holds the
 * picture's URL in meta. Sellers first: if someone is both, their seller profile
 * is the public-facing one.
 *
 * @return array<string,string> post type => meta key.
 */
function pcu_profile_sources() {
	return array(
		'sellers' => 'seller_profile_attachment',
		'buyers'  => 'buyer_profile_attachment',
	);
}

/**
 * The attachment ID of a user's profile picture, or 0.
 *
 * Cached per request: a thread of 40 messages between two people asks for the
 * same two avatars 40 times, and each miss would otherwise cost a get_posts()
 * plus a meta read plus a URL-to-ID lookup.
 *
 * @param int $user_id User ID.
 * @return int Attachment ID, 0 if they have no picture.
 */
function pcu_profile_image_id( $user_id ) {
	static $cache = array();

	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return 0;
	}

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$found = 0;

	foreach ( pcu_profile_sources() as $post_type => $meta_key ) {
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'author'      => $user_id,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			continue;
		}

		$url = get_post_meta( $posts[0], $meta_key, true );

		if ( ! $url ) {
			continue;
		}

		// The meta holds a URL, not an ID. The plugin's own helper looks it up by
		// guid; attachment_url_to_postid() is the WordPress way and copes with
		// scheme changes (http -> https), which the guid lookup does not.
		$id = attachment_url_to_postid( $url );

		if ( ! $id && function_exists( 'prolancer_get_image_id' ) ) {
			$id = (int) prolancer_get_image_id( $url );
		}

		if ( $id ) {
			$found = (int) $id;
			break;
		}
	}

	$cache[ $user_id ] = $found;

	return $found;
}

/**
 * Teach get_avatar() about ProLancer profile pictures.
 *
 * get_avatar_data() is where WordPress decides which image URL an avatar points
 * at. Filtering it here means every caller — get_avatar(), the REST API, comment
 * lists, and every `else : echo get_avatar(...)` fallback in the plugin — gets
 * the real picture, with no template needing to know.
 *
 * Falls through untouched when the user has no ProLancer profile picture, so
 * Gravatar and the site's default avatar keep working exactly as before.
 *
 * @param array $args        Avatar arguments (url, size, found_avatar…).
 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
 * @return array
 */
function pcu_avatar_data( $args, $id_or_email ) {
	// NOTE: do not early-return on $args['found_avatar']. WordPress core sets it
	// true (with the Gravatar URL) BEFORE the get_avatar_data filter runs, so a
	// guard on it would fire every time and this would never override anything.
	// Replacing the Gravatar with the real profile picture is the whole point.
	$user_id = pcu_avatar_user_id( $id_or_email );

	if ( ! $user_id ) {
		return $args;
	}

	$image_id = pcu_profile_image_id( $user_id );

	if ( ! $image_id ) {
		return $args;   // no ProLancer picture — leave WordPress to it
	}

	$size = isset( $args['size'] ) ? (int) $args['size'] : 96;

	// Ask for a registered size at least as big as the avatar, so a 60px avatar
	// does not download a 2500px original. 'thumbnail' is a 150x150 crop, which
	// is exactly the square an avatar wants.
	$src = wp_get_attachment_image_src( $image_id, $size > 150 ? 'medium' : 'thumbnail' );

	if ( ! $src ) {
		return $args;
	}

	$args['url']          = $src[0];
	$args['found_avatar'] = true;

	return $args;
}
add_filter( 'get_avatar_data', 'pcu_avatar_data', 10, 2 );

/**
 * Whose avatar is this? get_avatar() accepts five different things.
 *
 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
 * @return int User ID, or 0 if it does not resolve to one.
 */
function pcu_avatar_user_id( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}

	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}

	if ( $id_or_email instanceof WP_Post ) {
		return (int) $id_or_email->post_author;
	}

	if ( $id_or_email instanceof WP_Comment ) {
		return (int) $id_or_email->user_id;   // 0 for a logged-out commenter
	}

	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );

		return $user ? (int) $user->ID : 0;
	}

	return 0;
}
