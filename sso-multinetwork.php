<?php

namespace Mercator\SSO\Multinetwork;

use Mercator\SSO;
use Mercator\Multinetwork;

// Add in to Mercator load
add_action( 'mercator_load', __NAMESPACE__ . '\\run_preflight' );

/**
 * Is SSO enabled for multinetwork?
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
	 * Defaults to the value of {@see Multinetwork\is_enabled()}.
	 *
	 * @param bool $enabled Should SSO be enabled? (True for on, false-ish for off.)
	 */
	return apply_filters( 'mercator.sso.multinetwork.enabled', Multinetwork\is_enabled() );
}

/**
 * Perform preflight checks for Mercator
 *
 * Checks that we can actually run SSO, then attaches the relevant actions
 * and filters to make it useful.
 */
function run_preflight() {
	if ( ! is_enabled() ) {
		return;
	}

	/**
	 * Should we skip checking for the `COOKIEHASH` constant?
	 *
	 * COOKIEHASH by default is based on the siteurl set for the network. When
	 * using single-sign-on, the detection is based on domains.
	 * 
	 * For
	 * single-sign-on to work correctly across multiple networks, this must be
	 * defined as a static value.
	 */
	// 
	$skip_cookiehash_check = apply_filters( 'mercator.sso.multinetwork.skip_cookiehash_check', false );
	if ( ! defined( 'COOKIEHASH' ) ) {
		// status_header( 500 );
		// header( 'X-Mercator: COOKIEHASH' );

		// wp_die( 'The constant <code>COOKIEHASH</code> is <strong>not</strong> defined. Please set this to a static value to share cookies across overlapping networks.' );
	}

	define( __NAMESPACE__ . '\\STATIC_COOKIEHASH', defined( 'COOKIEHASH' ) );

	// S : Mayday! Mayday!
	// Mc: What the heck is that?
	// J : Why, that's the Russian New Year. We can have a parade and serve hot
	//     hors d'oeuvres... 
	add_action( 'muplugins_loaded', __NAMESPACE__ . '\\bootstrap' );
}

/**
 * Attach SSO functions into WordPress.
 */
function bootstrap() {
	// We never need this for the main network
	if ( is_main_network() ) {
		return;
	}

	add_filter( 'mercator.sso.main_domain_network',   __NAMESPACE__ . '\\get_main_network' );
	add_filter( 'mercator.sso.is_main_domain',        __NAMESPACE__ . '\\correct_for_subdomain_networks', 10, 3 );
	add_filter( 'mercator.sso.main_site_for_actions', __NAMESPACE__ . '\\set_main_site_for_actions' );
	add_action( 'muplugins_loaded', __NAMESPACE__ . '\\initialize_cookie_domain', 11 );
}

/**
 * Get the main network
 *
 * @param stdClass $network Network being used
 * @return stdClass Corrected network to use
 */
function get_main_network() {
	// Use wp_get_main_network if it exists; see the WP Multi Network plugin
	if ( function_exists( 'wp_get_main_network' ) ) {
		return wp_get_main_network();
	}

	// No function, do it ourselves
	global $wpdb;

	if ( defined( 'PRIMARY_NETWORK_ID' ) )
		return wp_get_network( (int) PRIMARY_NETWORK_ID );

	$primary_network_id = (int) wp_cache_get( 'primary_network_id', 'site-options' );

	if ( $primary_network_id )
		return wp_get_network( $primary_network_id );

	$primary_network_id = (int) $wpdb->get_var( "SELECT id FROM $wpdb->site ORDER BY id LIMIT 1" );
	wp_cache_add( 'primary_network_id', $primary_network_id, 'site-options' );

	return wp_get_network( $primary_network_id );
}

/**
 * Correct {@see SSO\is_main_domain()} for multinetwork
 *
 * If you have a network with a URL that is a strict subset of the main network,
 * they can share cookies. However, the COOKIEHASH value for both must be set to
 * the same.
 *
 * @param boolean $is_main Is this the main domain?
 * @param string $domain Domain we checked against
 * @param stdClass $network Network we fetched the cookie domain from
 * @return boolean Corrected main domain status
 */
function correct_for_subdomain_networks( $is_main, $domain, $network ) {
	if ( ! $is_main ) {
		// This only applies to successful results, so bail
		return $is_main;
	}

	// Are we doing a cross-network check?
	$current_network = $GLOBALS['current_site'];
	if ( $network->id === $current_network->id ) {
		// Same network, skip
		return $is_main;
	}

	// Is the domain a subset of the network's domain?
	$current = SSO\get_cookie_domain( $current_network );
	$main_domain = SSO\get_cookie_domain( $network );

	if ( strlen( $current ) < strlen( $main_domain ) ) {
		$subset = false;
	}
	else {
		$subset = ( substr( $current, -strlen( $main_domain ) ) === $main_domain );
	}

	if ( ! $subset ) {
		// Not a subset, nothing to correct
		return $is_main;
	}

	// Down to nuts and bolts, we need to check that the cookie name is the
	// same across the networks.
	//
	// We base this on the STATIC_COOKIEHASH constant, calculated in
	// run_preflight()
	return STATIC_COOKIEHASH;
}

/**
 * Ensure we always run actions on the main site of the main network
 *
 * @param int $site_id Site ID for the current network
 * @return int Corrected site ID (main site on main network)
 */
function set_main_site_for_actions( $site_id ) {
	$main_network = get_main_network();

	return SSO\get_main_site( $main_network );
}

/**
 * Ensure COOKIE_DOMAIN is always set to the current domain
 */
function initialize_cookie_domain() {
	if ( empty( $GLOBALS['mercator_current_network_mapping'] ) || defined( 'COOKIE_DOMAIN' ) ) {
		return;
	}

	// Do the ms-settings dance, again.
	$current_mapping = $GLOBALS['mercator_current_network_mapping'];

	$cookie_domain = $current_mapping->get_domain();
	if ( substr( $cookie_domain, 0, 4 ) === 'www.' ) {
		$cookie_domain = substr( $cookie_domain, 4 );
	}

	define( 'COOKIE_DOMAIN', $cookie_domain );
}