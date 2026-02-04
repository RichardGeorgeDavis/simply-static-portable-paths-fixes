<?php
/**
 * Plugin Name: Simply Static Portable Paths
 * Description: Rewrites wp-content/wp-includes URLs in exported HTML so static archives work from any folder depth.
 * Version: 0.8.4
 * Author: Lucidity / RGD
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Disabled: export outputs can be buggy. Use the post-export fixer instead.
if ( true ) { return; }

use Simply_Static\Util;

/**
 * MAIN HOOK: rewrite DOM before Simply Static saves HTML.
 *
 * Filter signature (newer Simply Static): ($dom, $url, $job_id)
 * We accept 3 args, but remain tolerant if only 2 are passed.
 */
add_filter( 'ss_dom_before_save', function( $dom, $url = null, $job_id = null ) {

	if ( ! ( $dom instanceof DOMDocument ) ) {
		return $dom;
	}
	if ( ! is_string( $url ) ) {
		$url = '';
	}

	$prefix = sspp_compute_prefix( $dom, $url );

	// Rewrite attributes (src/href/srcset/data-lazyload/etc).
	sspp_rewrite_dom_attributes( $dom, $prefix );

	// Rewrite inline style attributes and <style> blocks for url(/wp-content...) patterns.
	sspp_rewrite_inline_styles_in_dom( $dom, $prefix );

	return $dom;

}, 9999, 3 );

/**
 * Final string-level pass after download (catches JSON blobs and edge cases).
 */
add_filter( 'ss_html_after_download', function( $html, $url ) {

	if ( ! is_string( $html ) || $html === '' ) {
		return $html;
	}

	$prefix = null;
	if ( is_string( $url ) && $url !== '' ) {
		$prefix = sspp_prefix_for_url( $url );
	}
	if ( ! is_string( $prefix ) || $prefix === '' ) {
		$prefix = sspp_prefix_from_pingback_html( $html );
	}
	if ( ! is_string( $prefix ) || $prefix === '' ) {
		$prefix = './';
	}

	$html = sspp_normalize_double_slash_root_paths( $html );
	$html = sspp_strip_origin_host_anywhere( $html );
	$html = sspp_rewrite_root_paths_in_text( $html, $prefix );
	$html = sspp_rewrite_dot_slash_root_paths( $html, $prefix );
	$html = sspp_rewrite_json_escaped_paths( $html, $prefix );
	$html = sspp_rewrite_fb3d_client_data_payloads( $html, $prefix );
	$html = sspp_rewrite_css_urls( $html, $prefix );

	return $html;

}, 9999, 2 );

/**
 * Exclude common account/commerce and API paths from static export.
 */
add_filter( 'simply_static_excluded_paths', function ( $paths ) {
	return array_merge( $paths, [
		'/cart/',
		'/checkout/',
		'/my-account/',
		'/order-received/',
		'/wp-json/',
		'/wc-api/',
		'/author/',
	] );
} );

/**
 * Rewrites relevant attributes throughout the DOM.
 */
function sspp_rewrite_dom_attributes( DOMDocument $dom, $prefix ) {

	$attrs = array(
		// standard
		'src', 'href', 'srcset', 'poster',

		// common lazyload / builders
		'data-src', 'data-srcset', 'data-lazy-src', 'data-lazyload', 'data-original', 'data-bg', 'data-background',

		// Revolution Slider / other sliders
		'data-rs-ref', 'data-src-rs-ref', 'data-thumb', 'data-thumburl', 'data-bgset',

		// Divi multi-view JSON blob
		'data-et-multi-view',
	);

	$xpath = new DOMXPath( $dom );
	foreach ( $attrs as $attr ) {
		$nodes = $xpath->query( '//*[@' . $attr . ']' );
		if ( ! $nodes ) { continue; }

		foreach ( $nodes as $node ) {
			$val = $node->getAttribute( $attr );
			if ( $val === '' ) { continue; }

			if ( strtolower( $attr ) === 'srcset' || strtolower( $attr ) === 'data-srcset' ) {
				$new = sspp_rewrite_srcset( $val, $prefix );
			} elseif ( strtolower( $attr ) === 'data-et-multi-view' ) {
				$new = sspp_rewrite_json_escaped_paths( $val, $prefix );
			} else {
				$new = sspp_rewrite_path_value( $val, $prefix );
			}

			if ( $new !== $val ) {
				$node->setAttribute( $attr, $new );
			}
		}
	}
}

/**
 * Rewrites a single attribute value containing URLs/paths.
 *
 * Handles:
 * - same-origin absolute URLs (http(s)://origin/wp-content/...)
 * - scheme-relative (//origin/wp-content/...)
 * - root-relative (/wp-content/...)
 * - dot-prefixed (.//wp-content/... or ./wp-content/...) that some builders generate
 * - JSON-escaped variants (\/wp-content\/) used in data attributes
 */
function sspp_rewrite_path_value( $value, $prefix ) {

	if ( ! is_string( $value ) || $value === '' ) {
		return $value;
	}

	// Strip origin host if present, so same-origin absolute URLs become root-relative first.
	$value = sspp_strip_origin_host_anywhere( $value );
	$value = sspp_normalize_double_slash_root_paths( $value );
	// Normalize escaped slashes in wp-content/wp-includes for attribute values.
	$value = preg_replace( '#\\\\/(wp-content|wp-includes)#', '/$1', $value );
	$value = preg_replace( '#(wp-content|wp-includes)\\\\/#', '$1/', $value );

	// Skip external absolute URLs.
	if ( preg_match( '#^https?:\/\/#i', $value ) || preg_match( '#^\/\/#', $value ) ) {
		return $value;
	}
	if ( preg_match( '#^(data:|mailto:|tel:|javascript:)#i', $value ) ) {
		return $value;
	}

	// Rewrite root-relative /wp-content/ and /wp-includes/ in any context (including srcset).
	$value = sspp_rewrite_root_paths_in_text( $value, $prefix );

	// Also handle ".//wp-content" & "./wp-content" forms explicitly.
	$value = sspp_rewrite_dot_slash_root_paths( $value, $prefix );

	// Collapse accidental ".//" to "./" (but keep "../" sequences).
	$value = preg_replace( '#\./{2,}#', './', $value );

	return $value;
}

/**
 * Rewrites root-relative "/wp-content/" and "/wp-includes/" occurrences inside arbitrary text.
 * Also rewrites JSON-escaped "\/wp-content\/" etc.
 */
function sspp_rewrite_root_paths_in_text( $text, $prefix ) {

	$dirs = array(
		'/wp-content/',
		'/wp-includes/',
	);

	$text = sspp_normalize_double_slash_root_paths( $text );

	foreach ( $dirs as $dir ) {
		$portable = $prefix . ltrim( $dir, '/' );

		// Standard root-relative, but avoid already-relative paths like ../wp-content/
		// and avoid rewriting absolute URLs (only rewrite when preceded by a delimiter).
		$pattern = '#(^|[\'"(\s=])' . preg_quote( $dir, '#' ) . '#';
		$text = preg_replace( $pattern, '$1' . $portable, $text );

		// JSON-escaped, also avoid already-relative paths like ..\/wp-content\/
		// and absolute URLs like https:\/\/example.com\/wp-content\/...
		$dir_escaped      = str_replace( '/', '\/', $dir );
		$portable_escaped = str_replace( '/', '\/', $portable );
		$pattern_escaped  = '#(^|[\'"(\s=])' . preg_quote( $dir_escaped, '#' ) . '#';
		$text = preg_replace( $pattern_escaped, '$1' . $portable_escaped, $text );
	}

	return $text;
}

/**
 * Rewrites dot-prefixed root paths (.//wp-content/, ./wp-content/, .//wp-includes/, ./wp-includes/)
 * into the correct portable prefix for the given page depth.
 */
function sspp_rewrite_dot_slash_root_paths( $text, $prefix ) {

	$maps = array(
		'wp-content'  => $prefix . 'wp-content',
		'wp-includes' => $prefix . 'wp-includes',
	);

	foreach ( $maps as $dir => $replacement ) {

		// .//wp-content/...  OR ./wp-content/...
		$text = preg_replace( '#\.(?:\/){1,2}' . preg_quote( $dir, '#' ) . '(\/)#i', $replacement . '$1', $text );

		// Defensive: ".//wp-content" without trailing slash
		$text = preg_replace( '#\.(?:\/){1,2}' . preg_quote( $dir, '#' ) . '\b#i', $replacement, $text );

		// Broken dot-runs like "./.... /wp-content" -> correct prefix.
		$text = preg_replace( '#\./\.+/' . preg_quote( $dir, '#' ) . '(\/)#i', $replacement . '$1', $text );
		$text = preg_replace( '#\./\.+/' . preg_quote( $dir, '#' ) . '\b#i', $replacement, $text );
	}

	return $text;
}

/**
 * Compute relative prefix (e.g. "./", "../", "../../") for a given page URL.
 */
function sspp_prefix_for_url( $url ) {

	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! is_string( $path ) ) { return './'; }

	$path = trim( $path, '/' );

	$segments = $path === '' ? array() : explode( '/', $path );
	$depth    = count( $segments );

	// If last segment looks like a file, treat it as within the folder depth.
	if ( $depth > 0 && preg_match( '/\.[a-z0-9]{2,5}$/i', end( $segments ) ) ) {
		$depth--;
	}

	if ( $depth <= 0 ) {
		return './';
	}

	return str_repeat( '../', $depth );
}

/**
 * Compute the correct prefix for the current page.
 *
 * Some Simply Static versions pass a non-page-specific $url to ss_dom_before_save.
 * Prefer DOM-derived depth when available, then fall back to URL-derived depth.
 */
function sspp_compute_prefix( DOMDocument $dom, $url = null ) {

	$from_dom = sspp_prefix_from_pingback( $dom );
	$from_url = null;

	if ( is_string( $url ) && $url !== '' ) {
		$from_url = sspp_prefix_for_url( $url );
	}

	// If pingback suggests root but URL shows deeper, trust URL.
	if ( $from_dom === './' && is_string( $from_url ) && $from_url !== './' ) {
		return $from_url;
	}

	if ( is_string( $from_dom ) && $from_dom !== '' ) {
		return $from_dom;
	}

	if ( is_string( $from_url ) && $from_url !== '' ) {
		return $from_url;
	}

	return './';
}

/**
 * Derive the prefix from the pingback link (if present).
 *
 * Examples:
 * - "./xmlrpc.php"     => "./"
 * - "./../xmlrpc.php"  => "../"
 * - "../../xmlrpc.php" => "../../"
 */
function sspp_prefix_from_pingback( DOMDocument $dom ) {

	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//link[@rel="pingback"]' );
	if ( ! $nodes || $nodes->length < 1 ) {
		return null;
	}

	$href = $nodes->item( 0 )->getAttribute( 'href' );
	if ( ! is_string( $href ) || $href === '' ) {
		return null;
	}

	// Normalise to root-relative first (in case it's still same-origin absolute).
	$href = sspp_strip_origin_host_anywhere( $href );

	return sspp_normalize_prefix_from_pingback_href( $href );
}

/**
 * Derive the prefix from pingback HTML when DOM is not available.
 */
function sspp_prefix_from_pingback_html( $html ) {

	if ( ! is_string( $html ) || $html === '' ) {
		return null;
	}

	if ( ! preg_match( '#<link[^>]+rel=[\"\\\']pingback[\"\\\'][^>]*>#i', $html, $m ) ) {
		return null;
	}

	if ( ! preg_match( '#href=[\"\\\']([^\"\\\']+)[\"\\\']#i', $m[0], $href_match ) ) {
		return null;
	}

	$href = $href_match[1];
	if ( ! is_string( $href ) || $href === '' ) {
		return null;
	}

	$href = sspp_strip_origin_host_anywhere( $href );

	return sspp_normalize_prefix_from_pingback_href( $href );
}

/**
 * Normalize prefix from a pingback href like "./../xmlrpc.php".
 */
function sspp_normalize_prefix_from_pingback_href( $href ) {

	if ( ! is_string( $href ) || $href === '' ) {
		return null;
	}

	if ( stripos( $href, 'xmlrpc.php' ) === false ) {
		return null;
	}

	$prefix = substr( $href, 0, stripos( $href, 'xmlrpc.php' ) );

	// Trim query strings, fragments, whitespace.
	$prefix = preg_replace( '#[?#].*$#', '', $prefix );
	$prefix = trim( $prefix );

	if ( $prefix === '' || $prefix === '/' ) {
		return './';
	}

	// Convert "./../" => "../" but keep "./" as "./"
	if ( $prefix !== './' && substr( $prefix, 0, 2 ) === './' ) {
		$prefix = substr( $prefix, 2 );
	}

	// Ensure trailing slash.
	if ( substr( $prefix, -1 ) !== '/' ) {
		$prefix .= '/';
	}

	// Collapse accidental multiple slashes like ".///"
	$prefix = preg_replace( '#\./{2,}#', './', $prefix );

	return $prefix;
}

/**
 * Return an array of possible origin hosts (sanitised), without protocol.
 */
function sspp_origin_hosts() {

	$hosts = array();

	// Simply Static origin host (may be host or full URL depending on version/config).
	if ( class_exists( '\Simply_Static\Util' ) && method_exists( '\Simply_Static\Util', 'origin_host' ) ) {
		$hosts[] = Util::origin_host();
	}

	// WordPress home/site host.
	$hosts[] = home_url();
	$hosts[] = site_url();

	// Allow manual extension when assets come from an additional same-origin host (e.g. staging/prod swap).
	$extra = apply_filters( 'sspp_extra_origin_hosts', array() );
	if ( is_array( $extra ) ) {
		$hosts = array_merge( $hosts, $extra );
	}

	$clean = array();

	foreach ( $hosts as $h ) {
		if ( empty( $h ) || ! is_string( $h ) ) { continue; }

		// Ensure parseable.
		if ( ! preg_match( '#^https?://#i', $h ) ) {
			$h = 'https://' . ltrim( $h, '/' );
		}

		$parsed_host = wp_parse_url( $h, PHP_URL_HOST );
		$parsed_port = wp_parse_url( $h, PHP_URL_PORT );

		if ( $parsed_host ) {
			$clean[] = strtolower( $parsed_host );
			if ( $parsed_port ) {
				$clean[] = strtolower( $parsed_host . ':' . $parsed_port );
			}
		}
	}

	return array_values( array_unique( array_filter( $clean ) ) );
}

/**
 * Strip any occurrence of same-origin absolute host to root-relative.
 */
function sspp_strip_origin_host_anywhere( $text ) {

	if ( ! is_string( $text ) || $text === '' ) {
		return $text;
	}

	foreach ( sspp_origin_hosts() as $host ) {
		$host_quoted = preg_quote( $host, '#' );

		// http(s)://host/
		$text = preg_replace( '#https?:\/\/' . $host_quoted . '\/#i', '/', $text );

		// //host/
		$text = preg_replace( '#\/\/' . $host_quoted . '\/#i', '/', $text );

		// JSON-escaped forms (http:\/\/host\/ and \/\/host\/)
		$text = preg_replace( '#https?:\\\\\/\\\\\/' . $host_quoted . '\\\\\/#i', '\\/', $text );
		$text = preg_replace( '#\\\\\/\\\\\/' . $host_quoted . '\\\\\/#i', '\\/', $text );
	}

	return $text;
}

/**
 * Normalize double-slash root paths (//wp-content/, //wp-includes/) to single-slash root paths.
 * Also handles JSON-escaped forms (\\/\\/wp-content\\/).
 */
function sspp_normalize_double_slash_root_paths( $text ) {

	if ( ! is_string( $text ) || $text === '' ) {
		return $text;
	}

	$text = str_replace( '//wp-content/', '/wp-content/', $text );
	$text = str_replace( '//wp-includes/', '/wp-includes/', $text );

	$text = str_replace( '\\/\\/wp-content\\/', '\\/wp-content\\/', $text );
	$text = str_replace( '\\/\\/wp-includes\\/', '\\/wp-includes\\/', $text );

	return $text;
}

/**
 * Rewrite srcset lists (comma-separated URLs with descriptors).
 */
function sspp_rewrite_srcset( $srcset, $prefix ) {
	$srcset = trim( $srcset );
	if ( $srcset === '' ) { return $srcset; }

	$parts = array_map( 'trim', explode( ',', $srcset ) );
	$out   = array();

	foreach ( $parts as $part ) {
		if ( $part === '' ) { continue; }

		$tokens = preg_split( '/\s+/', $part );
		$img    = array_shift( $tokens );

		$new_img = sspp_rewrite_path_value( $img, $prefix );

		$out[] = trim( $new_img . ( $tokens ? ' ' . implode( ' ', $tokens ) : '' ) );
	}

	return implode( ', ', $out );
}

/**
 * Rewrite JSON-escaped paths like \/wp-content\/... to ..\/wp-content\/...
 */
function sspp_rewrite_json_escaped_paths( $text, $prefix ) {

	if ( ! is_string( $text ) || $text === '' ) {
		return $text;
	}

	// Normalize and strip same-origin hosts first.
	$text = sspp_normalize_double_slash_root_paths( $text );
	$text = sspp_strip_origin_host_anywhere( $text );

	// Convert ../ into ..\/ for JSON strings
	$json_prefix = str_replace( '/', '\\/', $prefix );

	// Root-relative JSON-escaped (avoid already-relative ..\/wp-content\/).
	$text = preg_replace_callback(
		'#(^|[^.])\\\\/wp-content\\\\/#',
		function( $m ) use ( $json_prefix ) {
			return $m[1] . $json_prefix . 'wp-content\\/';
		},
		$text
	);
	$text = preg_replace_callback(
		'#(^|[^.])\\\\/wp-includes\\\\/#',
		function( $m ) use ( $json_prefix ) {
			return $m[1] . $json_prefix . 'wp-includes\\/';
		},
		$text
	);

	// Dot-slash JSON-escaped (.\/wp-content\/ or .\/.\/wp-content\/).
	$text = preg_replace( '#\\\.(?:\\\\/){1,2}wp-content\\\\/#i', $json_prefix . 'wp-content\\/', $text );
	$text = preg_replace( '#\\\.(?:\\\\/){1,2}wp-includes\\\\/#i', $json_prefix . 'wp-includes\\/', $text );

	return $text;
}

/**
 * Rewrite url(...) patterns in inline styles and <style> blocks.
 */
function sspp_rewrite_inline_styles_in_dom( DOMDocument $dom, $prefix ) {

	$xpath = new DOMXPath( $dom );

	// Inline style attributes.
	$nodes = $xpath->query( '//*[@style]' );
	if ( $nodes ) {
		foreach ( $nodes as $node ) {
			$style = $node->getAttribute( 'style' );
			if ( $style === '' ) { continue; }

			$style_new = sspp_rewrite_css_urls( $style, $prefix );

			if ( $style_new !== $style ) {
				$node->setAttribute( 'style', $style_new );
			}
		}
	}

	// <style> tags.
	$styles = $xpath->query( '//style' );
	if ( $styles ) {
		foreach ( $styles as $style_node ) {
			$css = $style_node->nodeValue;
			if ( ! is_string( $css ) || $css === '' ) { continue; }

			$css_new = sspp_rewrite_css_urls( $css, $prefix );

			if ( $css_new !== $css ) {
				$style_node->nodeValue = $css_new;
			}
		}
	}
}

/**
 * Rewrite CSS url() references that point at wp-content/wp-includes (root-relative).
 */
function sspp_rewrite_css_urls( $css, $prefix ) {

	if ( ! is_string( $css ) || $css === '' ) {
		return $css;
	}

	// Strip same-origin hosts inside CSS url("http://host/wp-content/...")
	$css = sspp_strip_origin_host_anywhere( $css );
	$css = sspp_normalize_double_slash_root_paths( $css );

	return preg_replace_callback(
		'#url\(\s*([\'"]?)(\/wp-content\/|\/wp-includes\/)([^\)\'"]*)\1\s*\)#i',
		function( $m ) use ( $prefix ) {
			$q    = $m[1];
			$base = $m[2];
			$rest = $m[3];

			$portable = $prefix . ltrim( $base, '/' ) . $rest;
			return 'url(' . $q . $portable . $q . ')';
		},
		$css
	);
}

/**
 * 3D FlipBook (FB3D) stores PDF URLs inside base64 JSON strings:
 *   FB3D_CLIENT_DATA.push('<base64>');
 */
function sspp_rewrite_fb3d_client_data_payloads( $html, $prefix ) {

	if ( ! is_string( $html ) || $html === '' ) {
		return $html;
	}

	return preg_replace_callback(
		'#FB3D_CLIENT_DATA\.push\(\s*[\'"]([A-Za-z0-9+\/=]+)[\'"]\s*\)#',
		function( $m ) use ( $prefix ) {

			$b64 = $m[1];

			$decoded = base64_decode( $b64, true );
			if ( $decoded === false ) {
				return $m[0];
			}

			$data = json_decode( $decoded, true );
			if ( ! is_array( $data ) ) {
				return $m[0];
			}

			$data = sspp_deep_rewrite_strings( $data, $prefix );

			$rejson = json_encode( $data, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $rejson ) || $rejson === '' ) {
				return $m[0];
			}

			$reb64 = base64_encode( $rejson );

			return str_replace( $b64, $reb64, $m[0] );
		},
		$html
	);
}

/**
 * Deep-walk array/object and rewrite string fields that contain same-origin paths.
 */
function sspp_deep_rewrite_strings( $value, $prefix ) {

	if ( is_string( $value ) ) {
		$value = sspp_normalize_double_slash_root_paths( $value );
		$value = sspp_strip_origin_host_anywhere( $value );
		$value = sspp_rewrite_root_paths_in_text( $value, $prefix );
		$value = sspp_rewrite_dot_slash_root_paths( $value, $prefix );
		return $value;
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sspp_deep_rewrite_strings( $v, $prefix );
		}
		return $value;
	}

	return $value;
}
