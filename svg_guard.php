<?php
/**
 * Attack suite for inc/pcu-svg-guard.php.
 *
 * Runs the real sanitiser (no WordPress needed — the two WP functions it touches
 * are stubbed) against the payloads people actually use, plus the legitimate
 * files it must NOT break.
 *
 *   php demo/svg_guard.php
 */

define( 'ABSPATH', __DIR__ );
function esc_html__( $s, $d = '' ) { return $s; }
function add_filter( ...$a ) {}

require_once __DIR__ . '/../build/inc/pcu-svg-guard.php';

$pass = $fail = 0;

/**
 * @param string   $label What is being tested.
 * @param string   $svg   Input.
 * @param callable $check Given the sanitised output (or false), is it right?
 */
function t( $label, $svg, $check ) {
	global $pass, $fail;

	$out = pcu_svg_sanitize( $svg );
	$ok  = $check( $out );

	printf( "%-6s %s\n", $ok ? 'PASS' : 'FAIL', $label );
	if ( ! $ok ) {
		echo "         got: " . ( false === $out ? '(rejected)' : str_replace( "\n", ' ', $out ) ) . "\n";
	}

	$ok ? $pass++ : $fail++;
}

/** The output must not contain this string, in any casing. */
function gone( $needle ) {
	return function ( $out ) use ( $needle ) {
		return false === $out || false === stripos( $out, $needle );
	};
}

/** The file must be thrown out entirely. */
function rejected() {
	return function ( $out ) { return false === $out; };
}

/** The output must still contain this (we did not break a real drawing). */
function kept( $needle ) {
	return function ( $out ) use ( $needle ) {
		return is_string( $out ) && false !== stripos( $out, $needle );
	};
}

echo "=== SCRIPT EXECUTION ===\n";

t( '<script> block',
	'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>',
	gone( 'alert' ) );

t( 'onload on the root element',
	'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>',
	gone( 'onload' ) );

t( 'onclick on a shape',
	'<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="5" onclick="alert(1)"/></svg>',
	gone( 'onclick' ) );

t( 'onmouseover, mixed case (ONMouseOver)',
	'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" ONMouseOver="alert(1)"/></svg>',
	gone( 'alert' ) );

t( 'CDATA-wrapped script',
	'<svg xmlns="http://www.w3.org/2000/svg"><script><![CDATA[alert(1)]]></script></svg>',
	gone( 'alert' ) );

t( '<handler> element',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:ev="http://www.w3.org/2001/xml-events">'
	. '<handler ev:event="load">alert(1)</handler></svg>',
	gone( 'alert' ) );

echo "\n=== SMUGGLED HTML AND FRAMES ===\n";

t( 'foreignObject carrying HTML',
	'<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100">'
	. '<body xmlns="http://www.w3.org/1999/xhtml"><img src=x onerror="alert(1)"/></body>'
	. '</foreignObject></svg>',
	gone( 'onerror' ) );

t( 'iframe inside the SVG',
	'<svg xmlns="http://www.w3.org/2000/svg"><iframe src="javascript:alert(1)"/></svg>',
	gone( 'iframe' ) );

t( 'embed / object',
	'<svg xmlns="http://www.w3.org/2000/svg"><embed src="evil.swf"/><object data="evil.swf"/></svg>',
	gone( 'evil.swf' ) );

echo "\n=== ANIMATION ELEMENTS (assign a handler without an on* attribute) ===\n";

t( '<set attributeName="onload">',
	'<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="onload" to="alert(1)"/></svg>',
	gone( 'alert' ) );

t( '<animate> writing into href',
	'<svg xmlns="http://www.w3.org/2000/svg"><a><animate attributeName="href" to="javascript:alert(1)"/>'
	. '<text x="0" y="10">click</text></a></svg>',
	gone( 'javascript' ) );

echo "\n=== URLS ===\n";

t( 'javascript: in an <a href>',
	'<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>',
	gone( 'javascript' ) );

t( 'javascript: obfuscated with a newline (java\\nscript:)',
	"<svg xmlns=\"http://www.w3.org/2000/svg\"><a xlink:href=\"java\nscript:alert(1)\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><rect width=\"1\" height=\"1\"/></a></svg>",
	gone( 'script:' ) );

t( 'data:text/html in an href',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
	. '<image xlink:href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="/></svg>',
	gone( 'text/html' ) );

t( 'nested data:image/svg+xml (script inside the inner SVG)',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
	. '<image xlink:href="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9ImFsZXJ0KDEpIi8+"/></svg>',
	gone( 'svg+xml' ) );

t( 'remote http reference (phones home on every view)',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
	. '<image xlink:href="https://evil.example.com/track.png"/></svg>',
	gone( 'evil.example.com' ) );

echo "\n=== CSS ===\n";

t( 'expression() in a style attribute',
	'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" style="width:expression(alert(1))"/></svg>',
	gone( 'expression' ) );

t( '@import in a <style> block',
	'<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("//evil.example.com/x.css");</style><rect width="1" height="1"/></svg>',
	gone( 'evil.example.com' ) );

t( '-moz-binding',
	'<svg xmlns="http://www.w3.org/2000/svg"><style>rect{-moz-binding:url("//evil.example.com/x.xml#e")}</style><rect width="1" height="1"/></svg>',
	gone( 'moz-binding' ) );

echo "\n=== XML-LEVEL ATTACKS ===\n";

t( 'XXE — read /etc/passwd into the image',
	'<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
	. '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
	rejected() );

t( 'billion laughs — entity expansion bomb',
	'<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
	. '<svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>',
	rejected() );

t( 'xml-stylesheet processing instruction',
	'<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="//evil.example.com/x.xsl"?>'
	. '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
	gone( 'evil.example.com' ) );

t( 'not XML at all (a PHP payload named .svg)',
	'<?php system($_GET["c"]); ?>',
	rejected() );

t( 'root element is not <svg>',
	'<html><body><script>alert(1)</script></body></html>',
	rejected() );

t( 'gzipped bytes under a .svg name',
	"\x1f\x8b\x08\x00" . str_repeat( "\x00", 20 ),
	rejected() );

t( 'empty file',
	'',
	rejected() );

echo "\n=== LEGITIMATE SVGS MUST STILL WORK ===\n";

t( 'a normal icon survives intact',
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
	. '<path d="M3 12h18M12 3v18"/></svg>',
	function ( $out ) {
		return is_string( $out )
			&& false !== strpos( $out, 'M3 12h18M12 3v18' )
			&& false !== strpos( $out, 'viewBox' )
			&& false !== strpos( $out, 'stroke-width' );
	} );

t( 'gradients, defs and internal #references survive',
	'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
	. '<defs><linearGradient id="g"><stop offset="0%" stop-color="#f00"/><stop offset="100%" stop-color="#00f"/></linearGradient></defs>'
	. '<rect width="100" height="100" fill="url(#g)"/></svg>',
	function ( $out ) {
		return is_string( $out )
			&& false !== strpos( $out, 'linearGradient' )
			&& false !== strpos( $out, 'stop-color' )
			&& false !== strpos( $out, 'url(#g)' );
	} );

t( '<use xlink:href="#..."> internal reference survives',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
	. '<symbol id="s"><circle cx="5" cy="5" r="5"/></symbol><use xlink:href="#s"/></svg>',
	kept( '#s' ) );

t( 'clip paths, masks and filters survive',
	'<svg xmlns="http://www.w3.org/2000/svg"><defs>'
	. '<clipPath id="c"><rect width="10" height="10"/></clipPath>'
	. '<filter id="f"><feGaussianBlur stdDeviation="2"/></filter>'
	. '</defs><rect width="20" height="20" clip-path="url(#c)" filter="url(#f)"/></svg>',
	function ( $out ) {
		return is_string( $out )
			&& false !== strpos( $out, 'clipPath' )
			&& false !== strpos( $out, 'feGaussianBlur' );
	} );

t( 'text with fonts survives',
	'<svg xmlns="http://www.w3.org/2000/svg"><text x="10" y="20" font-family="Arial" font-size="14" text-anchor="middle">Hello</text></svg>',
	kept( 'Hello' ) );

t( 'an inline base64 PNG survives',
	'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
	. '<image xlink:href="data:image/png;base64,iVBORw0KGgo=" width="10" height="10"/></svg>',
	kept( 'data:image/png;base64' ) );

t( 'harmless <style> block survives',
	'<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:#333}</style><rect class="a" width="1" height="1"/></svg>',
	kept( 'fill:#333' ) );

t( 'a clean SVG is returned byte-for-byte unchanged where possible',
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#abc"/></svg>',
	kept( '#abc' ) );

printf( "\n%s  %d passed, %d failed\n", $fail ? '*** FAILURES ***' : 'ALL PASS', $pass, $fail );
exit( $fail ? 1 : 0 );
