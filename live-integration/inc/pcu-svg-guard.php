<?php
/**
 * SVG guard — sanitise every SVG the site accepts.
 *
 * WHY THIS EXISTS
 *
 * An SVG is not a picture. It is an XML document, and the SVG spec lets that
 * document carry <script>, event handlers (onload=, onclick=), javascript: URLs
 * and embedded HTML. WordPress refuses SVG uploads by default for exactly this
 * reason — but this site does accept them, because the Redux Framework plugin
 * (its Custom Fonts extension, a dependency of the parent theme) adds
 * image/svg+xml to the allowed upload types for the whole site.
 *
 * That matters here more than it would on a brochure site: this is a
 * marketplace. Buyers and sellers — not just administrators — upload profile
 * pictures, cover images, project attachments and service attachments through
 * the front-end dashboard, and those uploaders take whatever the site's allowed
 * list permits. A booby-trapped SVG uploaded as a profile picture runs its
 * script on THIS domain the moment another user opens the file: it can read
 * their session, and act as them.
 *
 * WordPress will not catch it. On upload it sniffs a file's real bytes and
 * rejects anything whose content contradicts its extension — but SVG is text,
 * and a file full of JavaScript is still perfectly valid XML. It sails through.
 *
 * WHAT THIS DOES
 *
 * Every SVG is rewritten before it is stored: parsed as XML, then rebuilt from
 * an allow-list of the elements and attributes a drawing actually needs. Scripts,
 * event handlers, embedded HTML, external references, entity tricks and
 * javascript: URLs are not "detected and removed" — they simply have no way to
 * survive, because nothing outside the allow-list is copied over. An SVG that
 * cannot be parsed at all is rejected.
 *
 * Genuine SVG logos and icons keep working, for everyone who could upload one
 * before. Nothing is taken away.
 *
 * The chat is stricter still and is untouched by this: it refuses SVG outright
 * (pcu_blocked_extensions), and that stays.
 *
 * Redux and the parent theme are NOT modified — this is a child-theme filter on
 * WordPress's own upload pipeline, so a plugin or theme update cannot undo it.
 *
 * @package prolancer-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elements an SVG drawing is allowed to contain.
 *
 * An allow-list, not a block-list. A block-list has to anticipate every trick;
 * this only has to know what a drawing legitimately needs, and everything else
 * — <script>, <foreignObject> (which smuggles in raw HTML), <iframe>, <handler>,
 * the <animate>/<set> family (which can assign an event handler through
 * attributeName) — is dropped for the simple reason that it is not on the list.
 *
 * @return string[]
 */
function pcu_svg_allowed_elements() {
	return array(
		// Structure
		'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'view',
		'title', 'desc', 'metadata', 'style',
		// Shapes
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		// Text
		'text', 'tspan', 'textpath',
		// Paint
		'lineargradient', 'radialgradient', 'stop', 'pattern',
		'clippath', 'mask', 'marker', 'image', 'a',
		// Filters
		'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix',
		'fecomposite', 'feflood', 'femerge', 'femergenode', 'femorphology',
		'fedropshadow', 'feturbulence', 'fedisplacementmap', 'fetile',
		'feconvolvematrix', 'fediffuselighting', 'fespecularlighting',
		'fepointlight', 'fedistantlight', 'fespotlight',
		'fecomponenttransfer', 'fefuncr', 'fefuncg', 'fefuncb', 'fefunca',
	);
}

/**
 * Attributes those elements are allowed to carry.
 *
 * Presentation, geometry and paint. No event handlers can appear here — and any
 * attribute whose name begins with "on" is refused separately, so a new one
 * invented by a future SVG spec cannot slip through this list either.
 *
 * @return string[]
 */
function pcu_svg_allowed_attributes() {
	return array(
		// Identity / structure
		'id', 'class', 'style', 'lang', 'tabindex',
		'xmlns', 'xmlns:xlink', 'xmlns:svg', 'version', 'baseprofile',
		// Geometry
		'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
		'width', 'height', 'd', 'points', 'transform', 'viewbox',
		'preserveaspectratio', 'patternunits', 'patterncontentunits',
		'gradientunits', 'gradienttransform', 'spreadmethod',
		'clippathunits', 'maskunits', 'maskcontentunits',
		'markerwidth', 'markerheight', 'markerunits', 'refx', 'refy', 'orient',
		'pathlength', 'offset',
		// Paint
		'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width',
		'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
		'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
		'opacity', 'color', 'stop-color', 'stop-opacity', 'display',
		'visibility', 'overflow', 'clip-path', 'clip-rule', 'mask',
		'filter', 'mix-blend-mode', 'isolation', 'paint-order',
		'vector-effect', 'shape-rendering', 'color-interpolation-filters',
		// Text
		'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
		'text-anchor', 'dominant-baseline', 'alignment-baseline',
		'letter-spacing', 'word-spacing', 'text-decoration', 'dx', 'dy',
		'writing-mode', 'direction', 'unicode-bidi',
		// Filter primitives
		'in', 'in2', 'result', 'stddeviation', 'mode', 'type', 'values',
		'operator', 'k1', 'k2', 'k3', 'k4', 'radius', 'flood-color',
		'flood-opacity', 'surfacescale', 'specularconstant',
		'specularexponent', 'diffuseconstant', 'kernelmatrix', 'order',
		'basefrequency', 'numoctaves', 'seed', 'stitchtiles', 'scale',
		'xchannelselector', 'ychannelselector', 'azimuth', 'elevation',
		'pointsatx', 'pointsaty', 'pointsatz', 'limitingconeangle',
		'primitiveunits', 'filterunits', 'tablevalues', 'slope', 'intercept',
		'amplitude', 'exponent',
		// References — the value is validated separately (pcu_svg_safe_url).
		'href', 'xlink:href',
		// Accessibility
		'role', 'aria-label', 'aria-labelledby', 'aria-hidden', 'aria-describedby',
		'xml:space', 'xml:lang',
	);
}

/**
 * Is this attribute value a URL we are willing to keep?
 *
 * Only two kinds are ever needed by a drawing:
 *   - a fragment ("#gradient-3") pointing inside the same file, and
 *   - an inline raster image (data:image/png;base64,…).
 *
 * Everything else goes — javascript: for the obvious reason, but also
 * data:text/html and data:image/svg+xml (both of which can carry script), and
 * any remote http(s) reference, which would turn every view of the image into a
 * callback to somebody else's server.
 *
 * @param string $url Attribute value.
 * @return bool
 */
function pcu_svg_safe_url( $url ) {
	// Strip whitespace and control characters first: "java\nscript:alert(1)" and
	// "java\0script:" are both read as javascript: by a browser but would slip
	// past a naive string comparison.
	$clean = preg_replace( '/[\x00-\x20\x7F]/', '', (string) $url );
	$clean = strtolower( html_entity_decode( $clean, ENT_QUOTES, 'UTF-8' ) );

	if ( '' === $clean ) {
		return false;
	}

	// A reference to something inside this same file.
	if ( '#' === $clean[0] ) {
		return true;
	}

	// An inline raster image. Note svg+xml is deliberately NOT in this list.
	if ( preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#', $clean ) ) {
		return true;
	}

	return false;
}

/**
 * Rewrite an SVG so that only the allow-list survives.
 *
 * @param string $svg Raw file contents.
 * @return string|false Sanitised markup, or false if it is not usable XML.
 */
function pcu_svg_sanitize( $svg ) {
	$svg = (string) $svg;

	if ( '' === trim( $svg ) ) {
		return false;
	}

	// A gzipped SVG (.svgz) served under a .svg name. We do not accept those:
	// there is nothing to gain and it hides the payload from every other check.
	if ( 0 === strncmp( $svg, "\x1f\x8b", 2 ) ) {
		return false;
	}

	// Refuse the document outright if it declares entities. This is not fussiness:
	// an entity can read a file off the server and paste it into the image (XXE),
	// or expand exponentially until the process runs out of memory (billion
	// laughs). Neither belongs anywhere near an uploaded logo, so there is no
	// version of this we want to "clean up" and keep.
	if ( preg_match( '/<!ENTITY/i', $svg ) ) {
		return false;
	}

	$prev = libxml_use_internal_errors( true );

	$dom                     = new DOMDocument();
	$dom->preserveWhiteSpace = false;
	$dom->strictErrorHandling = false;

	// LIBXML_NONET: never fetch anything over the network while parsing.
	// LIBXML_NOENT is deliberately NOT set — we do not want entities expanded.
	$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOCDATA );

	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	if ( ! $loaded || ! $dom->documentElement ) {
		return false;   // not parseable as XML — not an SVG we can vouch for
	}

	if ( 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
		return false;   // the root element is not <svg>
	}

	// Drop the DTD if one survived the entity check above.
	if ( $dom->doctype ) {
		$dom->doctype->parentNode->removeChild( $dom->doctype );
	}

	$allowed_elements   = array_flip( pcu_svg_allowed_elements() );
	$allowed_attributes = array_flip( pcu_svg_allowed_attributes() );

	$xpath = new DOMXPath( $dom );

	// Walk backwards: removing a node while iterating forwards over a live
	// DOMNodeList skips its neighbour.
	$nodes = $xpath->query( '//*' );
	for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
		$node = $nodes->item( $i );
		$name = strtolower( $node->localName );

		if ( ! isset( $allowed_elements[ $name ] ) ) {
			$node->parentNode->removeChild( $node );
			continue;
		}

		if ( ! $node->hasAttributes() ) {
			continue;
		}

		for ( $a = $node->attributes->length - 1; $a >= 0; $a-- ) {
			$attr  = $node->attributes->item( $a );
			$aname = strtolower( $attr->nodeName );

			// Every event handler, including ones that do not exist yet.
			if ( 0 === strpos( $aname, 'on' ) ) {
				$node->removeAttributeNode( $attr );
				continue;
			}

			if ( ! isset( $allowed_attributes[ $aname ] ) ) {
				$node->removeAttributeNode( $attr );
				continue;
			}

			// href / xlink:href must point somewhere harmless.
			if ( 'href' === $aname || 'xlink:href' === $aname ) {
				if ( ! pcu_svg_safe_url( $attr->nodeValue ) ) {
					$node->removeAttributeNode( $attr );
				}
				continue;
			}

			// A style attribute can execute in older engines (expression(),
			// -moz-binding) and can pull in a remote stylesheet (@import).
			if ( 'style' === $aname && pcu_svg_css_is_dangerous( $attr->nodeValue ) ) {
				$node->removeAttributeNode( $attr );
			}
		}
	}

	// <style> blocks get the same treatment as style attributes; if the CSS is
	// doing something it has no business doing, the block goes.
	$styles = $xpath->query( '//*[local-name()="style"]' );
	for ( $i = $styles->length - 1; $i >= 0; $i-- ) {
		$style = $styles->item( $i );
		if ( pcu_svg_css_is_dangerous( $style->textContent ) ) {
			$style->parentNode->removeChild( $style );
		}
	}

	// Processing instructions can carry a stylesheet reference.
	$pis = $xpath->query( '//processing-instruction()' );
	for ( $i = $pis->length - 1; $i >= 0; $i-- ) {
		$pi = $pis->item( $i );
		$pi->parentNode->removeChild( $pi );
	}

	$out = $dom->saveXML( $dom->documentElement );

	return is_string( $out ) && '' !== trim( $out ) ? $out : false;
}

/**
 * Does this CSS try to do something other than style a drawing?
 *
 * @param string $css Declaration list or stylesheet body.
 * @return bool
 */
function pcu_svg_css_is_dangerous( $css ) {
	$css = strtolower( preg_replace( '/[\x00-\x20\x7F]/', '', (string) $css ) );

	foreach ( array( 'javascript:', 'expression(', '-moz-binding', '@import', 'behavior:', 'vbscript:' ) as $needle ) {
		if ( false !== strpos( $css, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Clean every SVG on its way in — whoever uploaded it, from wherever.
 *
 * This sits on WordPress's own upload pipeline rather than on any one uploader,
 * so it covers the media library, the marketplace dashboard (profile pictures,
 * cover images, project and service attachments) and anything a plugin adds
 * later, without those having to know it exists.
 *
 * @param array $file $_FILES entry being handled.
 * @return array
 */
function pcu_svg_sanitize_upload( $file ) {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
		return $file;
	}

	$ext = strtolower( pathinfo( isset( $file['name'] ) ? $file['name'] : '', PATHINFO_EXTENSION ) );

	if ( 'svg' !== $ext && 'svgz' !== $ext ) {
		return $file;
	}

	if ( 'svgz' === $ext ) {
		$file['error'] = esc_html__( 'Compressed SVG (.svgz) files are not allowed.', 'prolancer' );
		return $file;
	}

	$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$clean = pcu_svg_sanitize( $raw );

	if ( false === $clean ) {
		$file['error'] = esc_html__( 'That SVG could not be read as a valid image and was not uploaded.', 'prolancer' );
		return $file;
	}

	// Only rewrite the file if sanitising actually changed it, so an SVG that was
	// already clean keeps its exact bytes.
	if ( $clean !== $raw ) {
		file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$file['size'] = filesize( $file['tmp_name'] );
	}

	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'pcu_svg_sanitize_upload' );
add_filter( 'wp_handle_sideload_prefilter', 'pcu_svg_sanitize_upload' );
