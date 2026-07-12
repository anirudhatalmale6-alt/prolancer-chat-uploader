<?php
/**
 * Create/Edit Service — stop the form wiping the values it is meant to save.
 *
 * THE BUG
 *
 * Every dropdown on the form opens with a placeholder written like this:
 *
 *     <option>Category</option>          <-- no value attribute
 *
 * An <option> with no value submits its own TEXT. So a dropdown the seller never
 * touched does not send "nothing" — it sends the literal string "Category", or
 * "Delivery Time", or "Locations".
 *
 * The plugin then does this with it (inc/ajax-actions.php):
 *
 *     $type_terms = array( (int) $params['service_locations'] );
 *     wp_set_post_terms( $service_id, $type_terms, 'service-locations', false );
 *
 * (int) "Locations" is 0. The final `false` means REPLACE, not append. So the
 * service's real location is replaced with an invalid term — i.e. wiped. And
 * once it is wiped there is nothing for the dropdown to preselect next time, so
 * it shows the placeholder again, and the next save wipes it again. It feeds
 * itself, which is why the client saw values "clearing" on edit.
 *
 * The proof was in his data: a service with its delivery time stored as the
 * literal text "Delivery Time", and every recently-edited service with no
 * delivery-time term at all while an untouched seeded one still had "1-3 Days".
 *
 * THE FIX — both halves are needed
 *
 *  1. The template override gives every placeholder `value=""`, so an untouched
 *     dropdown sends an empty string instead of its label. (patch_templates.py)
 *
 *  2. This file is the guarantee. It sanitises the payload BEFORE the plugin's
 *     handler sees it: a taxonomy field that is not a positive integer is
 *     REMOVED from the payload entirely. The plugin guards every one of those
 *     blocks with isset(), so a removed field means it skips the block and
 *     leaves the existing term alone — instead of replacing it with nothing.
 *
 *     Belt and braces on purpose. The template fix stops the junk being sent;
 *     this stops junk ever wiping a term again, whatever sends it — a stale
 *     cached page, a future edit to the markup, or a hand-rolled POST.
 *
 * The plugin is not modified: its handler is unhooked, ours runs, and it calls
 * the original. Same approach as the chat handlers.
 *
 * @package prolancer-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload fields that are a TERM ID and nothing else.
 *
 * Each of these ends up in wp_set_post_terms(..., false) — a replace — so a
 * junk value here does not merely fail to save, it destroys what was there.
 *
 * @return string[]
 */
function pcu_service_term_fields() {
	return array(
		// Create/edit service
		'service_category',
		'service_english_level',
		'service_locations',
		'delivery_time',

		// Create/edit project — the SAME defect, found while fixing the service
		// form. Its dropdowns carry the same valueless placeholders and its
		// handler does the same (int)-cast-then-replace, so a project's level,
		// duration, English level and location were being wiped on every edit
		// too. It is one bug in two places; fixing only half of it would leave
		// his projects still losing data.
		'project_category',
		'project_seller_type',
		'project_level',
		'project_duration',
		'english_level',
		'locations',

		// NOT `skills` or `languages`. Those are multi-selects with no
		// placeholder, so they never had this bug — and they are arrays, which a
		// seller is entitled to empty. Guarding them would quietly make "remove
		// all my skills" impossible.
	);
}

/**
 * Is this a real term id?
 *
 * @param mixed $value Raw payload value.
 * @return bool
 */
function pcu_is_term_id( $value ) {
	if ( is_array( $value ) ) {
		return false;
	}

	$value = trim( (string) $value );

	// ctype_digit, not is_numeric: "1e3" and "-4" are numeric and neither is a
	// term id. A leading zero is not one either.
	return '' !== $value && ctype_digit( $value ) && (int) $value > 0;
}

/**
 * Strip anything from a payload that would destroy data.
 *
 * The form arrives as one urlencoded string ($_POST['service_data'] or
 * ['project_data']), which the plugin parse_str()s. Clean it before it does.
 *
 * @param string $key Which POST field holds the serialised form.
 */
function pcu_sanitize_form_payload( $key ) {
	if ( empty( $_POST[ $key ] ) ) {
		return;
	}

	// WordPress slashes $_POST; parse the raw thing the browser sent.
	parse_str( wp_unslash( $_POST[ $key ] ), $params ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the plugin's handler verifies its own nonce.

	$dropped = array();

	foreach ( pcu_service_term_fields() as $field ) {
		if ( ! isset( $params[ $field ] ) ) {
			continue;
		}

		if ( ! pcu_is_term_id( $params[ $field ] ) ) {
			// Not a term id — the placeholder's own label, or empty. Remove it,
			// so the plugin's isset() guard skips that block and KEEPS the term
			// the post already has, instead of replacing it with nothing.
			$dropped[ $field ] = $params[ $field ];
			unset( $params[ $field ] );
		}
	}

	if ( ! $dropped ) {
		return;   // nothing to clean; leave the payload byte-for-byte alone
	}

	$_POST[ $key ] = wp_slash( http_build_query( $params ) );

	/**
	 * Fires when junk taxonomy values were removed from a save.
	 *
	 * @param array  $dropped field => the value that was thrown away.
	 * @param string $key     Which payload it came from.
	 */
	do_action( 'pcu_form_payload_cleaned', $dropped, $key );
}

/**
 * The "Add Extra Service" block, rebuilt.
 *
 * WHY THIS REPLACES THE PLUGIN'S
 *
 * The plugin's version of this AJAX response ships two things that do not work
 * on the front end:
 *
 *   <i class="dashicons dashicons-trash"></i>   and   <input type="number">
 *
 * dashicons is the WordPress ADMIN icon font. It is not loaded on the public
 * side of the site, so that icon renders as nothing — or, once something else
 * gives it a size, as an empty box. Either way it cannot be seen or clicked,
 * which is exactly why "Add Additional Service" had no working Delete while FAQ
 * did: the FAQ block is built in JS with Font Awesome, which IS loaded.
 *
 * Trying to redraw the dashicon as Font Awesome from CSS meant guessing the
 * font-family the theme registered, and the guess was wrong — it rendered a
 * tofu box. So do not guess. Emit the SAME icon classes the FAQ block uses and
 * is demonstrably rendering: `fa fa-bars` and `fas fa-trash`.
 *
 * The price field gets the same treatment as the rest of the form: type="text"
 * with data-num, so it has no spinner arrows and accepts numbers only. The
 * plugin's copy still said type="number", which is why a newly-added block kept
 * its spinner even after the template was fixed — the template is not where
 * this markup comes from.
 *
 * The plugin's own file is untouched; its handler is unhooked and this runs in
 * its place.
 */
function pcu_ajax_get_additional_service() {
	if ( empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'additional_service_nonce' ) ) {
		wp_die( esc_html__( 'Nonce validation failed', 'prolancer' ) );
	}

	$currency = class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$';

	?>
	<div class="row mb-4">
		<div class="col-sm-1">
			<i class="fa fa-bars"></i>
		</div>
		<div class="col-sm-10 my-auto">
			<input type="text" name="additional_service_title[]" class="form-control"
				placeholder="<?php esc_attr_e( 'Title', 'prolancer' ); ?>">
			<textarea name="additional_service_description[]" class="form-control"
				placeholder="<?php esc_attr_e( 'Description', 'prolancer' ); ?>"></textarea>
			<div class="input-group mb-3">
				<span class="input-group-text"><?php echo esc_html( $currency ); ?></span>
				<input type="text" inputmode="decimal" data-num="1" name="additional_service_price[]"
					class="form-control mb-0" placeholder="<?php esc_attr_e( 'Price', 'prolancer' ); ?>">
			</div>
		</div>
		<div class="col-sm-1">
			<i class="fas fa-trash"></i>
		</div>
	</div>
	<?php

	wp_die();
}

/**
 * Wrap the plugin's create/update handlers — service AND project.
 */
function pcu_wrap_form_handlers() {
	$handlers = array(
		'prolancer_ajax_create_service' => 'pcu_create_service',
		'prolancer_ajax_create_project' => 'pcu_create_project',
		// The block returned by "Add Extra Service" — see above.
		'prolancer_ajax_get_additional_service' => 'pcu_ajax_get_additional_service',
	);

	foreach ( $handlers as $original => $ours ) {
		if ( ! function_exists( $original ) ) {
			continue;   // plugin deactivated — leave it alone
		}

		remove_action( 'wp_ajax_' . $original, $original );
		remove_action( 'wp_ajax_nopriv_' . $original, $original );

		add_action( 'wp_ajax_' . $original, $ours );
		add_action( 'wp_ajax_nopriv_' . $original, $ours );
	}
}
add_action( 'init', 'pcu_wrap_form_handlers' );

/**
 * Clean the payload, then let the plugin do its job.
 */
function pcu_create_service() {
	pcu_sanitize_form_payload( 'service_data' );

	prolancer_ajax_create_service();
}

function pcu_create_project() {
	pcu_sanitize_form_payload( 'project_data' );

	prolancer_ajax_create_project();
}

/**
 * The wizard's steps.
 *
 * ONE source of truth: the template's step wrappers are generated from this same
 * list (patch_templates.py), and the stepper below is printed from it. The two
 * cannot drift apart into a nav that says "Pricing" over a pane full of FAQs.
 *
 * @return array<int,array{title:string,hint:string}>
 */
function pcu_wizard_steps() {
	return array(
		array(
			'title' => __( 'About service', 'prolancer' ),
			'hint'  => __( 'Title, category and description', 'prolancer' ),
		),
		array(
			'title' => __( 'Media', 'prolancer' ),
			'hint'  => __( 'Your featured images', 'prolancer' ),
		),
		array(
			'title' => __( 'Pricing', 'prolancer' ),
			'hint'  => __( 'Packages and extras', 'prolancer' ),
		),
		array(
			'title' => __( 'FAQ', 'prolancer' ),
			'hint'  => __( 'Answer the usual questions', 'prolancer' ),
		),
	);
}

/**
 * The numbered vertical stepper, down the left of the form.
 *
 * Printed server-side so the wizard is whole before the first paint. Building it
 * with JS after load would flash the entire form and then collapse it — the page
 * would visibly jump, which is precisely the client's rule 2.
 */
function pcu_wizard_nav() {
	?>
	<div class="pcu-wizard-nav col-12 col-lg-3">
		<ol class="pcu-steps">
			<?php foreach ( pcu_wizard_steps() as $i => $step ) : ?>
				<li class="pcu-step-item<?php echo 0 === $i ? ' is-current' : ''; ?>"
					data-goto="<?php echo esc_attr( $i + 1 ); ?>">
					<span class="pcu-step-num"><?php echo esc_html( $i + 1 ); ?></span>
					<span class="pcu-step-text">
						<span class="pcu-step-title"><?php echo esc_html( $step['title'] ); ?></span>
						<span class="pcu-step-hint"><?php echo esc_html( $step['hint'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
	<?php
}

/**
 * Previous / Next, under the form.
 *
 * The plugin's own Create/Update button lives in the last step and is left
 * exactly where it is — the wizard never touches how the service is saved.
 */
function pcu_wizard_controls() {
	// offset-lg-3 pushes these past the stepper column, so they sit under the
	// FORM — which is what they act on. Without it they landed under the
	// navigation, which reads as if they belonged to the step list.
	?>
	<div class="pcu-wizard-controls col-12 col-lg-9 offset-lg-3">
		<button type="button" class="pcu-wiz-prev prolancer-btn" hidden>
			<?php esc_html_e( 'Previous', 'prolancer' ); ?>
		</button>

		<button type="button" class="pcu-wiz-next prolancer-btn">
			<?php esc_html_e( 'Next', 'prolancer' ); ?>
		</button>
	</div>
	<?php
}

/**
 * Is this the Create/Edit Service screen?
 */
function pcu_is_service_form_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
	$fed = isset( $_GET['fed'] ) ? sanitize_key( wp_unslash( $_GET['fed'] ) ) : '';

	return 'create-service' === $fed;
}

/**
 * Numeric fields and the delete icon — that screen only.
 */
function pcu_service_form_assets() {
	if ( ! pcu_is_service_form_screen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$ver = function_exists( 'pcu_asset_version' ) ? 'pcu_asset_version' : 'filemtime';

	wp_enqueue_style(
		'pcu-service-form',
		$uri . '/assets/css/pcu-service-form.css',
		array(),
		call_user_func( $ver, $dir . '/assets/css/pcu-service-form.css' )
	);

	wp_enqueue_script(
		'pcu-service-form',
		$uri . '/assets/js/pcu-service-form.js',
		array(),
		call_user_func( $ver, $dir . '/assets/js/pcu-service-form.js' ),
		true
	);

	// The wizard.
	wp_enqueue_style(
		'pcu-wizard',
		$uri . '/assets/css/pcu-wizard.css',
		array( 'pcu-service-form' ),
		call_user_func( $ver, $dir . '/assets/css/pcu-wizard.css' )
	);

	wp_enqueue_script(
		'pcu-wizard',
		$uri . '/assets/js/pcu-wizard.js',
		array(),
		call_user_func( $ver, $dir . '/assets/js/pcu-wizard.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_service_form_assets' );
