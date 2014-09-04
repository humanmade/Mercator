<?php
/**
 * Mercator
 *
 * WordPress multisite domain mapping for the modern era.
 *
 * @package Mercator
 */

namespace Mercator;

require __DIR__ . '/class-mapping.php';

add_filter( 'pre_get_site_by_path', __NAMESPACE__ . '\\check_domain_mapping', 10, 2 );

if ( empty( $GLOBALS['wpdb']->dmtable ) ) {
	$GLOBALS['wpdb']->dmtable = $GLOBALS['wpdb']->base_prefix . 'domain_mapping';
}

/**
 * Check if a domain has a mapping available
 *
 * @param stdClass|null $site Site object if already found, null otherwise
 * @param string $domain Domain we're looking for
 * @return stdClass|null Site object if already found, null otherwise
 */
function check_domain_mapping( $site, $domain ) {
	// Have we already matched? (Allows other plugins to match first)
	if ( ! empty( $site ) ) {
		return $site;
	}

	global $wpdb;

	// Grab both WWW and no-WWW
	if ( strpos( $domain, 'www.' ) === 0 ) {
		$www = $domain;
		$nowww = substr( $domain, 4 );
	}
	else {
		$nowww = $domain;
		$www = 'www.' . $domain;
	}

	$mapping = Mapping::get_by_domain( array( $www, $nowww ) );
	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
		return $site;
	}

	// Fetch the actual data for the site
	$mapped_site = $mapping->get_site();
	if ( empty( $mapped_site ) ) {
		return $site;
	}

	define( 'DOMAIN_MAPPING', 1 );
	return $mapped_site;
}
