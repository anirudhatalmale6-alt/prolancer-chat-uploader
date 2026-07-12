<?php
/**
 * Extras & FAQ library — each seller's own reusable list.
 *
 * A seller kept retyping the same extras and the same FAQs on every service.
 * Now they build the list once, under Services → Extras and Services → FAQ, and
 * tick what they want when posting. They can still type brand-new ones straight
 * into the service form, exactly as before — the library is an addition, not a
 * replacement.
 *
 * HOW THIS GETS TWO NEW DASHBOARD SCREENS WITHOUT TOUCHING THE PLUGIN
 *
 * The dashboard routes on ?fed=… through a hard-coded if/elseif chain in the
 * plugin, so there is no hook to register a new screen with. But the chain's
 * final `else` loads `prolancer-templates/dashboard/404.php` through
 * locate_template() — which means the CHILD THEME can override it. So the child
 * theme's 404 template answers for the screens we added and falls back to the
 * real "not found" for anything else. The plugin stays updatable.
 *
 * STORAGE
 *
 * One user meta key per list, holding a list of rows. User meta, not a custom
 * table: the volume is tiny (a seller's own extras), it is deleted with the
 * user for free, and it needs no migration.
 *
 *   pcu_extras_library : [ { id, title, price }, … ]
 *   pcu_faqs_library   : [ { id, title, description }, … ]
 *
 * The id is what a service stores when a seller ticks a row, so renaming a
 * library entry later does not orphan the services that used it.
 *
 * @package prolancer-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PCU_EXTRAS_KEY = 'pcu_extras_library';
const PCU_FAQS_KEY   = 'pcu_faqs_library';

/**
 * The screens this file owns.
 *
 * @return string[]
 */
function pcu_library_screens() {
	return array( 'extras', 'faqs' );
}

/**
 * Which library screen is being asked for, if any.
 *
 * @return string 'extras' | 'faqs' | ''
 */
function pcu_library_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
	$fed = isset( $_GET['fed'] ) ? sanitize_key( wp_unslash( $_GET['fed'] ) ) : '';

	return in_array( $fed, pcu_library_screens(), true ) ? $fed : '';
}

/**
 * Read a seller's library.
 *
 * @param string $which 'extras' | 'faqs'
 * @param int    $user_id Defaults to the current user.
 * @return array<int,array>
 */
function pcu_library_get( $which, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	$rows = get_user_meta( $user_id, 'extras' === $which ? PCU_EXTRAS_KEY : PCU_FAQS_KEY, true );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Write a seller's library.
 *
 * @param string $which   'extras' | 'faqs'
 * @param array  $rows    Rows to store.
 * @param int    $user_id Defaults to the current user.
 */
function pcu_library_save( $which, $rows, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	// Do not trust the caller. pcu_library_clean_row() returns NULL for a row
	// with no title, and a caller that maps it over its input without filtering
	// would hand us a list with holes in it — which would then be stored, and a
	// blank extra would turn up in the seller's list. The plugin's own save has
	// exactly that bug, which is why the client's services carry empty extras.
	// One guard here means no caller can reintroduce it.
	$rows = array_values(
		array_filter(
			(array) $rows,
			function ( $row ) {
				return is_array( $row ) && ! empty( $row['title'] );
			}
		)
	);

	update_user_meta( $user_id, 'extras' === $which ? PCU_EXTRAS_KEY : PCU_FAQS_KEY, $rows );
}

/**
 * Clean one submitted row.
 *
 * Prices are stored as a plain number. An empty title means an empty row, and an
 * empty row is not worth storing — the plugin's own save has exactly that bug
 * and the client's database is full of blank extras because of it.
 *
 * @param string $which 'extras' | 'faqs'
 * @param array  $row   Raw row.
 * @return array|null Clean row, or null if it is empty.
 */
function pcu_library_clean_row( $which, $row ) {
	$title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
	$title = trim( $title );

	if ( '' === $title ) {
		return null;   // don't store blank rows
	}

	$clean = array(
		'id'    => ! empty( $row['id'] ) ? sanitize_key( $row['id'] ) : uniqid( 'r', true ),
		'title' => $title,
	);

	if ( 'extras' === $which ) {
		// Digits and one decimal point, matching the form's own numeric field.
		$price = isset( $row['price'] ) ? preg_replace( '/[^\d.]/', '', (string) $row['price'] ) : '';
		$parts = explode( '.', $price );

		if ( count( $parts ) > 2 ) {
			$price = array_shift( $parts ) . '.' . implode( '', $parts );
		}

		$clean['price'] = $price;
	} else {
		$clean['description'] = isset( $row['description'] ) ? sanitize_textarea_field( $row['description'] ) : '';
	}

	return $clean;
}

/**
 * Save a library from its form.
 *
 * The whole table is posted and the whole table is written — no per-row
 * add/edit/delete endpoints to keep in step. It is a short list; this is simpler
 * and cannot drift out of sync.
 */
function pcu_library_ajax_save() {
	check_ajax_referer( 'pcu_library', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please log in.', 'prolancer' ) ) );
	}

	$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';

	if ( ! in_array( $which, pcu_library_screens(), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown library.', 'prolancer' ) ) );
	}

	$raw = isset( $_POST['rows'] ) ? json_decode( wp_unslash( $_POST['rows'] ), true ) : array();

	if ( ! is_array( $raw ) ) {
		$raw = array();
	}

	// A seller's own list. Cap it so a runaway script can't fill the meta table.
	$raw = array_slice( $raw, 0, 200 );

	$rows = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$clean = pcu_library_clean_row( $which, $row );

		if ( $clean ) {
			$rows[] = $clean;
		}
	}

	pcu_library_save( $which, $rows );

	wp_send_json_success(
		array(
			'message' => 'extras' === $which
				? __( 'Extras saved.', 'prolancer' )
				: __( 'FAQs saved.', 'prolancer' ),
			'rows'    => $rows,
		)
	);
}
add_action( 'wp_ajax_pcu_library_save', 'pcu_library_ajax_save' );

/**
 * Assets for the library screens and for the service form (which reads from it).
 */
function pcu_library_assets() {
	$on_library = (bool) pcu_library_screen();
	$on_form    = function_exists( 'pcu_is_service_form_screen' ) && pcu_is_service_form_screen();

	if ( ! $on_library && ! $on_form ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	$ver = function_exists( 'pcu_asset_version' ) ? 'pcu_asset_version' : 'filemtime';

	// The library's Price field carries data-num, and the thing that ENFORCES
	// data-num (digits and one decimal point, on typing AND pasting) lives in
	// pcu-service-form.js. Without this the field would look numeric and quietly
	// accept "abc99.5x" — the server strips it, but the seller would be typing
	// into a field that lies to them. Reuse the one implementation rather than
	// writing a second copy that can drift from it.
	if ( $on_library && ! $on_form ) {
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
	}

	wp_enqueue_style(
		'pcu-library',
		$uri . '/assets/css/pcu-library.css',
		array( 'pcu-service-form' ),
		call_user_func( $ver, $dir . '/assets/css/pcu-library.css' )
	);

	wp_enqueue_script(
		'pcu-library',
		$uri . '/assets/js/pcu-library.js',
		array( 'pcu-service-form' ),
		call_user_func( $ver, $dir . '/assets/js/pcu-library.js' ),
		true
	);

	wp_localize_script(
		'pcu-library',
		'PCU_LIB',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pcu_library' ),
			'extras'   => pcu_library_get( 'extras' ),
			'faqs'     => pcu_library_get( 'faqs' ),
			'currency' => class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'pcu_library_assets' );

/**
 * Render a library screen.
 *
 * Called from the child theme's 404 override — see the note at the top.
 */
function pcu_library_render( $which ) {
	$is_extras = ( 'extras' === $which );
	$rows      = pcu_library_get( $which );
	$currency  = class_exists( 'WooCommerce' ) ? get_woocommerce_currency_symbol() : '$';
	?>
	<div class="white-padding mb-4">
		<h2 class="mb-0">
			<?php echo $is_extras ? esc_html__( 'Extras', 'prolancer' ) : esc_html__( 'FAQ', 'prolancer' ); ?>
		</h2>
	</div>

	<div class="white-padding">
		<p class="pcu-lib-intro">
			<?php
			echo $is_extras
				? esc_html__( 'Build your list of extras once. When you post a service you can tick the ones you want, instead of typing them again.', 'prolancer' )
				: esc_html__( 'Build your list of FAQs once. When you post a service you can tick the ones you want, instead of typing them again.', 'prolancer' );
			?>
		</p>

		<div class="pcu-lib" data-library="<?php echo esc_attr( $which ); ?>">
			<?php
			/*
			 * `table-responsive` > `prolancer-table` — the SAME wrapper and class
			 * the Services, Projects and every other dashboard list already uses.
			 *
			 * Not a lookalike of its own: the client's rule is that a restyle
			 * should hit every table at once. A private `.pcu-lib-table` would
			 * have to be found and restyled separately, and would drift the day
			 * someone forgot. The pcu-* classes below are only for the parts the
			 * site's table has no opinion about — the checkbox column and the
			 * price column widths.
			 */
			?>
			<div class="table-responsive">
			<table class="prolancer-table">
				<thead>
					<tr>
						<th scope="col" class="pcu-lib-check">
							<?php // Select-all. Its own label, so a screen reader says what it does. ?>
							<input type="checkbox" class="pcu-lib-all"
								aria-label="<?php esc_attr_e( 'Select all', 'prolancer' ); ?>">
						</th>
						<th scope="col"><?php echo $is_extras ? esc_html__( 'Name of extra', 'prolancer' ) : esc_html__( 'FAQ name', 'prolancer' ); ?></th>
						<?php if ( $is_extras ) : ?>
							<th scope="col" class="pcu-lib-price"><?php esc_html_e( 'Price', 'prolancer' ); ?></th>
						<?php endif; ?>
						<th scope="col" class="pcu-lib-actions"><?php esc_html_e( 'Action', 'prolancer' ); ?></th>
					</tr>
				</thead>
				<tbody class="pcu-lib-rows">
					<?php if ( empty( $rows ) ) : ?>
						<tr class="pcu-lib-empty">
							<td colspan="<?php echo $is_extras ? 4 : 3; ?>">
								<?php esc_html_e( 'Nothing here yet. Add your first one below.', 'prolancer' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr data-id="<?php echo esc_attr( $row['id'] ); ?>">
								<td class="pcu-lib-check">
									<input type="checkbox" class="pcu-lib-row-check"
										aria-label="<?php echo esc_attr( $row['title'] ); ?>">
								</td>
								<td>
									<input type="text" class="form-control pcu-lib-title"
										value="<?php echo esc_attr( $row['title'] ); ?>"
										placeholder="<?php echo $is_extras ? esc_attr__( 'Name of extra', 'prolancer' ) : esc_attr__( 'FAQ name', 'prolancer' ); ?>">
									<?php if ( ! $is_extras ) : ?>
										<textarea class="form-control pcu-lib-desc"
											placeholder="<?php esc_attr_e( 'Answer', 'prolancer' ); ?>"><?php echo esc_textarea( isset( $row['description'] ) ? $row['description'] : '' ); ?></textarea>
									<?php endif; ?>
								</td>
								<?php if ( $is_extras ) : ?>
									<td class="pcu-lib-price">
										<div class="input-group">
											<span class="input-group-text"><?php echo esc_html( $currency ); ?></span>
											<input type="text" inputmode="decimal" data-num="1"
												class="form-control mb-0 pcu-lib-price-input"
												value="<?php echo esc_attr( isset( $row['price'] ) ? $row['price'] : '' ); ?>"
												placeholder="<?php esc_attr_e( 'Price', 'prolancer' ); ?>">
										</div>
									</td>
								<?php endif; ?>
								<td class="pcu-lib-actions">
									<i class="fas fa-trash pcu-lib-delete" role="button" tabindex="0"
										aria-label="<?php esc_attr_e( 'Remove', 'prolancer' ); ?>"></i>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			</div>

			<div class="pcu-lib-foot">
				<a href="#" class="pcu-lib-add prolancer-btn">
					<i class="fal fa-plus"></i>
					<?php echo $is_extras ? esc_html__( 'Add Extra', 'prolancer' ) : esc_html__( 'Add FAQ', 'prolancer' ); ?>
				</a>

				<div class="pcu-lib-right">
					<button type="button" class="pcu-lib-remove prolancer-btn" disabled>
						<?php esc_html_e( 'Remove selected', 'prolancer' ); ?>
					</button>
					<button type="button" class="pcu-lib-save prolancer-btn">
						<?php esc_html_e( 'Save', 'prolancer' ); ?>
					</button>
				</div>
			</div>

			<p class="pcu-lib-status" role="status"></p>
		</div>
	</div>
	<?php
}
