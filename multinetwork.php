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
	bootstrap();
}

/**
 * Attach multinetwork functions into WordPress
 */
function bootstrap() {
	add_filter( 'pre_get_site_by_path',    __NAMESPACE__ . '\\check_mappings_for_site',    20, 4 );
	add_filter( 'pre_get_network_by_path', __NAMESPACE__ . '\\check_mappings_for_network', 10, 2 );
	add_action( 'muplugins_loaded', __NAMESPACE__ . '\\register_mapped_filters', -10 );
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

	$domains = get_possible_mapped_domains( $domain );

	$mapping = Network_Mapping::get_active_by_domain( $domains );

	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
		return $site;
	}

	// Fetch the actual data for the site
	$mapped_network = $mapping->get_network();
	if ( empty( $mapped_network ) ) {
		return $site;
	}

	// We found a network, now check for the site. Replace mapped domain with
	// network's original to find.
	$mapped_domain = $mapping->get_domain();
	if ( substr( $mapped_domain, 0, 4 ) === 'www.' ) {
		$mapped_domain = substr( $mapped_domain, 4 );
	}
	$subdomain = substr( $domain, 0, -strlen( $mapped_domain ) );

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

	$domains = get_possible_mapped_domains( $domain );

	$mapping = Network_Mapping::get_active_by_domain( $domains );

	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
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

/**
 * Register filters for URLs, if we've mapped
 */
function register_mapped_filters() {
	$current_site = $GLOBALS['current_blog'];
	$real_domain = $current_site->domain;
	$domain = $_SERVER['HTTP_HOST'];

	if ( $domain === $real_domain ) {
		// Domain hasn't been mapped
		return;
	}

	$domains = get_possible_mapped_domains( $domain );

	$mapping = Network_Mapping::get_active_by_domain( $domains );

	if ( empty( $mapping ) || is_wp_error( $mapping ) ) {
		return;
	}

	$GLOBALS['mercator_current_network_mapping'] = $mapping;

	add_filter( 'site_url', __NAMESPACE__ . '\\mangle_url', -11, 4 );
	add_filter( 'home_url', __NAMESPACE__ . '\\mangle_url', -11, 4 );
}

/**
 * Mangle the home URL to give our primary domain
 *
 * @param string $url The complete home URL including scheme and path.
 * @param string $path Path relative to the home URL. Blank string if no path is specified.
 * @param string|null $orig_scheme Scheme to give the home URL context. Accepts 'http', 'https', 'relative' or null.
 * @param int|null $site_id Blog ID, or null for the current blog.
 * @return string Mangled URL
 */
function mangle_url( $url, $path, $orig_scheme, $site_id ) {
	if ( empty( $site_id ) ) {
		$site_id = get_current_blog_id();
	}

	$current_mapping = $GLOBALS['mercator_current_network_mapping'];
	$current_network = get_current_site();

	if ( empty( $current_mapping ) || (int) $current_network->id !== (int) $current_mapping->get_network_id() ) {
		return $url;
	}

	$mapped_network = $current_mapping->get_network();

	// Replace the domain
	$domain = parse_url( $url, PHP_URL_HOST );
	$regex = '#(://|\.)' . preg_quote( $mapped_network->domain, '#' ) . '$#i';
	$mapped_domain = $current_mapping->get_domain();
	if ( substr( $mapped_domain, 0, 4 ) === 'www.' ) {
		$mapped_domain = substr( $mapped_domain, 4 );
	}
	$mangled_domain = preg_replace( $regex, '\1' . $mapped_domain, $domain );

	// Then correct the URL
	$regex = '#^(\w+://)' . preg_quote( $domain, '#' ) . '#i';
	$mangled = preg_replace( $regex, '\1' . $mangled_domain, $url );

	return $mangled;
}

/**
 * Get all possible mappings which may be in use and apply to the supplied domain
 *
 * This will return an array of domains which might have been mapped but also apply to the current domain
 * i.e. a given url of site.network.com should return both site.network.com and network.com
 *
 * @param $domain
 * @return array
 */
function get_possible_mapped_domains( $domain ) {

	$no_www = ( strpos( $domain, 'www.' ) === 0 ) ? substr( $domain, 4 ) : $domain;

	// Explode domain on tld and return an array element for each explode point
	// Ensures subdomains of a mapped network are matched
	$domains   = explode_domain( $no_www );
	$additions = array();

	// Also look for www variant of each possible domain
	foreach ( $domains as $current ) {
		$additions[] = 'www.' . $current ;
	}

	$domains = array_merge( $domains, $additions );

	return $domains;
}

/**
 * Explode a given domain into an array of domains with decreasing number of segments
 *
 * site.network.com should return site.network.com and network.com
 *
 * @param $domain - A url to explode, i.e. site.example.com
 * @param int $segments - Number of segments to explode and return
 * @return array - Exploded urls
 */
function explode_domain( $domain, $segments = 2 ) {

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
	$segments = apply_filters( 'mercator.multinetwork.host_parts_segments_count', $segments, $domain );

	$host_segments = explode( '.', trim( $domain, '.' ), (int) $segments );

	// Determine what domains to search for. Grab as many segments of the host
	// as asked for.
	$domains = array();

	while ( count( $host_segments ) > 1 ) {
		$domains[] = array_shift( $host_segments ) . '.' . implode( '.', $host_segments );
	}

	// Add the last part, avoiding trailing dot
	$domains[] = array_shift( $host_segments );

	return $domains;
}