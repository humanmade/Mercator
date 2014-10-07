<?php

namespace Mercator\Multinetwork;

use Mercator\Network_Mapping;

// Add in to Mercator load
add_action( 'mercator_load', __NAMESPACE__ . '\\run_preflight' );

/**
 * Is mapping enabled for networks?
 *
 * @return boolean
 */
function is_enabled() {
	/**
	 * Enable/disable cross-domain single-sign-on capability for multinetwork.
	 *
	 * Filter this value to turn single-sign-on on/off completely, or
	 * conditionally enable it instead.
	 *
	 * @param bool $enabled Should SSO be enabled? (True for on, false-ish for off.)
	 */
	return apply_filters( 'mercator.multinetwork.enabled', false );
}

/**
 * Perform preflight checks for Mercator
 *
 * Checks that we actually want multinetwork support, then attaches the
 * relevant actions and filters to make it useful.
 */
function run_preflight() {
	if ( ! is_enabled() ) {
		return;
	}

	// X: There's a problem in the cockpit!
	// Y: What's that?
	// X: It's the room at the front of the airplane where they control it, but
	//    that's not important right now.
	add_action( 'muplugins_loaded', __NAMESPACE__ . '\\bootstrap' );
}

/**
 * Attach multinetwork functions into WordPress
 */
function bootstrap() {
	add_filter( 'pre_get_site_by_path',    __NAMESPACE__ . '\\check_mappings_for_site',    20, 4 );
	add_filter( 'pre_get_network_by_path', __NAMESPACE__ . '\\check_mappings_for_network', 10, 2 );
}

/**
 * Check if a domain belongs to a mapped network
 *
 * @param stdClass|null $network Site object if already found, null otherwise
 * @param string $domain Domain we're looking for
 * @return stdClass|null Site object if already found, null otherwise
 */
function check_mappings_for_site( $site, $domain, $path, $path_segments ) {
	// Have we already matched? (Allows other plugins to match first)
	if ( ! empty( $site ) ) {
		return $site;
	}

	/**
	 * Filter the number of segments to consider in the domain.
	 *
	 * The requested domain is split into dot-delimited parts, then we check
	 * against these. By default, this is set to two segments, meaning that we
	 * will check the specified domain and one level deeper (e.g. one-level
	 * subdomain; "a.b.c" will be checked against "a.b.c" and "b.c"). 
	 *
	 * @param int $segments Number of segments to check
	 * @param string $domain Domain we're checking against
	 */
	$segments = apply_filters( 'mercator.multinetwork.host_parts_segments_count', 2, $domain );
	$host_segments = explode( '.', trim( $domain, '.' ), $segments );

	// Determine what domains to search for. Grab as many segments of the host
	// as asked for.
	$domains = array();
	while ( count( $host_segments ) > 1 ) {
		$domains[] = array_shift( $host_segments ) . '.' . implode( '.', $host_segments );
	}

	// Add the last part, avoiding trailing dot
	$domains[] = array_shift( $host_segments );

	$mapping = Network_Mapping::get_by_domain( $domains );
	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
		return $site;
	}

	// Ignore non-active domains
	if ( ! $mapping->is_active() ) {
		return $site;
	}

	// Fetch the actual data for the site
	$mapped_network = $mapping->get_network();
	if ( empty( $mapped_network ) ) {
		return $site;
	}

	// We found a network, now check for the site. Replace mapped domain with
	// network's original to find.
	$subdomain = substr( $domain, 0, -strlen( $mapped_network->domain ) );
	return get_site_by_path( $subdomain . $mapped_network->domain, $path, $path_segments );
}

/**
 * Check if a domain has a network mapping available
 *
 * @param stdClass|null $network Site object if already found, null otherwise
 * @param string $domain Domain we're looking for
 * @return stdClass|null Site object if already found, null otherwise
 */
function check_mappings_for_network( $network, $domain ) {
	// Have we already matched? (Allows other plugins to match first)
	if ( ! empty( $network ) ) {
		return $network;
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

	$mapping = Network_Mapping::get_by_domain( array( $www, $nowww ) );
	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
		return $network;
	}

	// Ignore non-active domains
	if ( ! $mapping->is_active() ) {
		return $network;
	}

	// Fetch the actual data for the site
	$mapped_network = $mapping->get_network();
	if ( empty( $mapped_network ) ) {
		return $network;
	}

	// Note: This is only for backwards compatibility with WPMU Domain Mapping,
	// do not rely on this constant in new code.
	defined( 'DOMAIN_MAPPING' ) or define( 'DOMAIN_MAPPING', 1 );
	return $mapped_network;
}
