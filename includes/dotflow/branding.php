<?php
/**
 * DenFlow customer-facing brand layer.
 *
 * The Groundhogg text domain and internal APIs are intentionally retained for
 * compatibility with the inherited GPL code and existing translations.
 *
 * @package DenFlow
 */

namespace Groundhogg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace inherited product-name references in customer-facing translated UI.
 *
 * Legal attribution, copyright and licence text is deliberately left unchanged.
 *
 * @param string $translated Translated string.
 * @param string $original   Original source string.
 * @param string $domain     Translation domain.
 *
 * @return string
 */
function denflow_branding_replace_product_name( $translated, $original = '', $domain = '' ) {

	if ( ! in_array( $domain, [ 'groundhogg', 'dotflow', 'denflow' ], true ) ) {
		return $translated;
	}

	$legal_text = $original . ' ' . $translated;

	if ( preg_match( '/copyright|©|\bGPL\b|licen[cs]e|warranty|redistribut|attribution|source code/i', $legal_text ) ) {
		return $translated;
	}

	// Keep the original developer/company name and vendor domains intact.
	return preg_replace( '/\bGroundhogg\b(?!\s+Inc\.?|\.io)/i', 'DenFlow', (string) $translated );
}

/**
 * Context-aware translation wrapper.
 *
 * @param string $translated Translated string.
 * @param string $original   Original source string.
 * @param string $context    Translation context.
 * @param string $domain     Translation domain.
 *
 * @return string
 */
function denflow_branding_replace_product_name_with_context( $translated, $original = '', $context = '', $domain = '' ) {
	return denflow_branding_replace_product_name( $translated, $original, $domain );
}

add_filter( 'gettext', __NAMESPACE__ . '\\denflow_branding_replace_product_name', 99, 3 );
add_filter( 'gettext_with_context', __NAMESPACE__ . '\\denflow_branding_replace_product_name_with_context', 99, 4 );
