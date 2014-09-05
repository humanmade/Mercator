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

bootstrap();

/**
 * Bootstrap Mercator up to run
 *
 * Checks that we can actually run Mercator, then attaches the relevant actions
 * and filters to make it useful.
 *
 * Imagine this as attaching the strings to the puppet.
 */
function bootstrap() {
	// Are we still in sunrise stage?
	if ( did_action( 'muplugins_loaded' ) ) {
		add_action( 'all_admin_notices', __NAMESPACE__ . '\\warn_late_load' );
		return;
	}

	// Check for COOKIE_DOMAIN definition
	//
	// Note that this can't be an admin notice, as you'd never be able to log in
	// to see it.
	if ( defined( 'COOKIE_DOMAIN' ) ) {
		status_header( 500 );
		header( 'X-Mercator: COOKIE_DOMAIN' );
		WP_DEBUG or exit;

		wp_die( 'The constant "COOKIE_DOMAIN" is defined (probably in wp-config.php). Please remove or comment out that define() line.' );
	}

	// Define the table variables
	if ( empty( $GLOBALS['wpdb']->dmtable ) ) {
		$GLOBALS['wpdb']->dmtable = $GLOBALS['wpdb']->base_prefix . 'domain_mapping';
		$GLOBALS['wpdb']->ms_global_tables[] = 'domain_mapping';
	}

	// Actually hook in!
	add_filter( 'pre_get_site_by_path', __NAMESPACE__ . '\\check_domain_mapping', 10, 2 );
}

/**
 * Warn the user that Mercator was loaded too late.
 */
function warn_late_load() {
	echo '<div class="error"><p>';
	printf(
		__(
			'Mercator must be loaded in your <code>sunrise.php</code>. Check out the <a href="%s">installation instructions</a>.',
			'mercator'
		),
		'https://github.com/humanmade/Mercator/wiki/Installation'
	);
	echo '</p></div>';
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

/**
 * Check the Mercator mapping table
 *
 * @return string|boolean One of 'exists' (table already existed), 'created' (table was created), or false if could not be created
 */
function check_table() {
	global $wpdb;

	$schema = "CREATE TABLE {$wpdb->dmtable} (
		id bigint(20) NOT NULL auto_increment,
		blog_id bigint(20) NOT NULL,
		domain varchar(255) NOT NULL,
		active tinyint(4) default 1,
		PRIMARY KEY  (id),
		KEY blog_id (blog_id,domain,active)
		KEY domain (domain)
	);";

	if ( ! function_exists( 'dbDelta' ) ) {
		if ( ! is_admin() ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	$result = dbDelta( $schema );

	if ( empty( $result ) ) {
		// No changes, database already exists and is up-to-date
		return 'exists';
	}

	return 'created';
}
