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
 * Wrap the plugin's create/update handlers — service AND project.
 */
function pcu_wrap_form_handlers() {
	$handlers = array(
		'prolancer_ajax_create_service' => 'pcu_create_service',
		'prolancer_ajax_create_project' => 'pcu_create_project',
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
