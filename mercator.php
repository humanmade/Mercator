<?php
/**
 * Mercator
 *
 * WordPress multisite domain mapping for the modern era.
 *
 * @package Mercator
 */

namespace Mercator;

/**
 * Current version of Mercator.
 */
const VERSION = '0.1';

require __DIR__ . '/class-mapping.php';
require __DIR__ . '/sso.php';

// Allow skipping bootstrap checks if you *really* know what you're doing.
// This lets Mercator run after muplugins_loaded, which you might need if you're
// doing unit tests.
if ( defined( 'MERCATOR_SKIP_CHECKS' ) && MERCATOR_SKIP_CHECKS ) {
	startup();
}
else {
	run_preflight();
}

/**
 * Perform preflight checks for Mercator
 *
 * Checks that we can actually run Mercator, then attaches the relevant actions
 * and filters to make it useful.
 */
function run_preflight() {
	// Are we installing? Bail if so.
	if ( defined( 'WP_INSTALLING' ) ) {
		return;
	}

	// Are we still in sunrise stage?
	if ( did_action( 'muplugins_loaded' ) ) {
		warn_with_message( 'Mercator must be loaded in your <code>sunrise.php</code>. Check out the <a href="https://github.com/humanmade/Mercator/wiki/Installation">installation instructions</a>.' );
		return;
	}

	// Are we actually on multisite?
	if ( ! is_multisite() ) {
		warn_with_message( 'Mercator requires WordPress to be in <a href="http://codex.wordpress.org/Create_A_Network">multisite mode</a>.' );
		return;
	}

	// Are we running a good version of WP?
	if ( ! function_exists( 'get_site_by_path' ) ) {
		warn_with_message( 'Mercator requires <a href="https://wordpress.org/download/">WordPress 3.9</a> or newer. Update now.' );
		return;
	}

	// M: We have clearance, Clarence.
	// O: Roger, Roger. What's our Vector Victor?
	startup();
}

/**
 * Attach Mercator into WordPress
 *
 * Imagine this as attaching the strings to the puppet.
 */
function startup() {
	// Define the table variables
	if ( empty( $GLOBALS['wpdb']->dmtable ) ) {
		$GLOBALS['wpdb']->dmtable = $GLOBALS['wpdb']->base_prefix . 'domain_mapping';
		$GLOBALS['wpdb']->ms_global_tables[] = 'domain_mapping';
	}

	// Ensure cache is shared
	wp_cache_add_global_groups( array( 'domain_mapping' ) );

	// Actually hook in!
	add_filter( 'pre_get_site_by_path', __NAMESPACE__ . '\\check_domain_mapping', 10, 2 );
	add_action( 'admin_init', __NAMESPACE__ . '\\load_admin', -100 );

	/**
	 * Fired after Mercator core has been loaded
	 *
	 * Hook into this to handle any add-on functionality.
	 */
	do_action( 'mercator_load' );
}

/**
 * Warn the user via the admin panels.
 *
 * @param string $message Message to use in the warning.
 */
function warn_with_message( $message ) {
	add_action( 'all_admin_notices', function () use ( $message ) {
		echo '<div class="error"><p>' . $message . '</p></div>';
	} );
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

	// Ignore non-active domains
	if ( ! $mapping->is_active() ) {
		return $site;
	}

	// Fetch the actual data for the site
	$mapped_site = $mapping->get_site();
	if ( empty( $mapped_site ) ) {
		return $site;
	}

	// Note: This is only for backwards compatibility with WPMU Domain Mapping,
	// do not rely on this constant in new code.
	defined( 'DOMAIN_MAPPING' ) or define( 'DOMAIN_MAPPING', 1 );
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
		KEY blog_id (blog_id,domain,active),
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

/**
 * Load administration functions
 *
 * We do this here rather than just including it to avoid extra load on
 * non-admin pages.
 */
function load_admin() {
	require_once __DIR__ . '/admin.php';
	require_once __DIR__ . '/inc/admin/class-alias-list-table.php';
}
