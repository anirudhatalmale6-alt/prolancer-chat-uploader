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
 * The circular profile-picture uploader.
 *
 * Replaces the plugin's big "Choose a Profile Picture" dropzone with the round
 * camera-icon control from the reference. It renders the CURRENT picture inline,
 * so the control is complete before the page paints — nothing pops in or shifts
 * on load. The camera icon is a translucent overlay that stays on top of the
 * photo, so a picture can always be changed.
 *
 * The hidden <input name="{$meta_key}"> is what the profile form already reads
 * to save the picture; the cropper writes the new attachment id into it, so the
 * existing save path is untouched.
 *
 * @param int    $post_id  Seller/buyer profile post id (the form's data-post-id).
 * @param string $meta_key seller_profile_attachment | buyer_profile_attachment.
 */
function pcu_avatar_uploader( $post_id, $meta_key ) {
	$post_id = (int) $post_id;

	$url = '';
	$id  = 0;

	$saved = get_post_meta( $post_id, $meta_key, true );

	if ( $saved ) {
		$id  = function_exists( 'prolancer_get_image_id' ) ? (int) prolancer_get_image_id( $saved ) : 0;
		$id  = $id ? $id : attachment_url_to_postid( $saved );
		$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : $saved;
	}

	$camera = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" '
		. 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		. '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>'
		. '<circle cx="12" cy="13" r="4"/></svg>';

	?>
	<div class="pcu-avatar-upload<?php echo $url ? ' has-image' : ''; ?>"
		data-post-id="<?php echo esc_attr( $post_id ); ?>"
		data-name="<?php echo esc_attr( $meta_key ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'upload_file_nonce' ) ); ?>">

		<button type="button" class="pcu-avatar-ring" aria-label="<?php esc_attr_e( 'Change profile picture', 'prolancer' ); ?>">
			<img class="pcu-avatar-photo" src="<?php echo esc_url( $url ); ?>" alt=""
				<?php echo $url ? '' : 'hidden'; ?>>
			<span class="pcu-avatar-overlay"><?php echo $camera; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
			<span class="pcu-avatar-spin" hidden></span>
		</button>

		<!-- The plugin's file input carried the nonce/post-id; ours carries them on
		     the wrapper instead. accept: images only. -->
		<input type="file" class="pcu-avatar-file" accept="image/png,image/jpeg,image/webp" hidden>

		<!-- What the profile form saves. Seeded with the current id so saving
		     without changing the picture keeps it. -->
		<input type="hidden" name="<?php echo esc_attr( $meta_key ); ?>" class="pcu-avatar-id"
			value="<?php echo esc_attr( $id ); ?>">
	</div>
	<?php
}

/**
 * The cropper modal + its markup, printed once in the dashboard footer.
 *
 * One modal for the page, reused — not one per uploader. Hidden until a file is
 * chosen. Closes only by its own Close button (the JS does not bind a backdrop
 * click), per the brief.
 */
function pcu_avatar_modal() {
	if ( ! pcu_is_profile_screen() ) {
		return;
	}
	?>
	<div class="pcu-crop-modal" hidden>
		<div class="pcu-crop-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Crop your profile picture', 'prolancer' ); ?>">
			<div class="pcu-crop-head">
				<h3><?php esc_html_e( 'Crop your profile picture', 'prolancer' ); ?></h3>
			</div>
			<div class="pcu-crop-body">
				<img class="pcu-crop-image" alt="">
			</div>
			<div class="pcu-crop-foot">
				<button type="button" class="pcu-crop-close prolancer-btn"><?php esc_html_e( 'Close', 'prolancer' ); ?></button>
				<button type="button" class="pcu-crop-save prolancer-btn"><?php esc_html_e( 'Upload', 'prolancer' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'pcu_avatar_modal' );

/**
 * Is this the profile-edit screen? (?fed=profile)
 */
function pcu_is_profile_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
	$fed = isset( $_GET['fed'] ) ? sanitize_key( wp_unslash( $_GET['fed'] ) ) : '';

	return 'profile' === $fed;
}

/**
 * Cropper library + our glue, on the profile screen only.
 */
function pcu_avatar_enqueue() {
	if ( ! pcu_is_profile_screen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$ver = function_exists( 'pcu_asset_version' ) ? 'pcu_asset_version' : 'filemtime';

	wp_enqueue_style(
		'pcu-cropper',
		$uri . '/assets/vendor/cropper.min.css',
		array(),
		call_user_func( $ver, $dir . '/assets/vendor/cropper.min.css' )
	);

	wp_enqueue_style(
		'pcu-avatar',
		$uri . '/assets/css/pcu-avatar.css',
		array( 'pcu-cropper' ),
		call_user_func( $ver, $dir . '/assets/css/pcu-avatar.css' )
	);

	wp_enqueue_script(
		'pcu-cropper',
		$uri . '/assets/vendor/cropper.min.js',
		array(),
		call_user_func( $ver, $dir . '/assets/vendor/cropper.min.js' ),
		true
	);

	wp_enqueue_script(
		'pcu-avatar',
		$uri . '/assets/js/pcu-avatar.js',
		array( 'pcu-cropper' ),
		call_user_func( $ver, $dir . '/assets/js/pcu-avatar.js' ),
		true
	);

	wp_localize_script(
		'pcu-avatar',
		'PCU_AVATAR',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => 'prolancer_ajax_upload_file',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_avatar_enqueue' );

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
