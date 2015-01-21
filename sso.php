<?php

namespace Mercator\SSO;

const ACTION_JS    = 'mercator-sso-js';
const ACTION_LOGIN = 'mercator-sso-login';

// Add in to Mercator load
add_action( 'mercator_load', __NAMESPACE__ . '\\run_preflight' );

/**
 * Is SSO enabled?
 *
 * @return boolean
 */
function is_enabled() {
	/**
	 * Enable/disable cross-domain single-sign-on capability.
	 *
	 * Filter this value to turn single-sign-on off completely, or conditionally
	 * enable it instead.
	 *
	 * @param bool $enabled Should SSO be enabled? (True for on, false-ish for off.)
	 */
	return apply_filters( 'mercator.sso.enabled', true );
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

	// Check for COOKIE_DOMAIN definition
	//
	// Note that this can't be an admin notice, as you'd never be able to log in
	// to see it.
	if ( defined( 'COOKIE_DOMAIN' ) ) {
		status_header( 500 );
		header( 'X-Mercator: COOKIE_DOMAIN' );

		wp_die( 'The constant <code>COOKIE_DOMAIN</code> is defined (probably in <code>wp-config.php</code>). Please remove or comment out that <code>define()</code> line.' );
	}

	// E: There's no reason to become alarmed, and we hope you'll enjoy the
	//    rest of your flight.
	//
	// E: By the way, is there anyone on board who knows how to fly a plane?
	bootstrap();
}

/**
 * Attach SSO functions into WordPress.
 */
function bootstrap() {
	add_action( 'wp_head',          __NAMESPACE__ . '\\head_js', -100 );
	add_action( 'muplugins_loaded', __NAMESPACE__ . '\\initialize_cookie_domain' );

	// Callback handlers
	add_action( 'wp_ajax_'        . ACTION_JS,        __NAMESPACE__ . '\\output_javascript_priv' );
	add_action( 'wp_ajax_nopriv_' . ACTION_JS,        __NAMESPACE__ . '\\output_javascript_nopriv' );
	add_action( 'wp_ajax_'        . ACTION_LOGIN,     __NAMESPACE__ . '\\handle_login' );
	add_action( 'wp_ajax_nopriv_' . ACTION_LOGIN,     __NAMESPACE__ . '\\handle_login' );
}

/**
 * Ensure COOKIE_DOMAIN is always set to the current domain
 */
function initialize_cookie_domain() {
	if ( empty( $GLOBALS['mercator_current_mapping'] ) ) {
		return;
	}

	// Do the ms-settings dance, again.
	$current_mapping = $GLOBALS['mercator_current_mapping'];

	$cookie_domain = $current_mapping->get_domain();
	if ( substr( $cookie_domain, 0, 4 ) === 'www.' ) {
		$cookie_domain = substr( $cookie_domain, 4 );
	}

	define( 'COOKIE_DOMAIN', $cookie_domain );
}

/**
 * Get the cookie domain for a network
 *
 * Correctly handles custom cookie domains, falling back to main domains,
 * stripping WWW prefixes, etc.
 *
 * @param stdClass $network Network object
 * @return string Cookie domain (with leading .)
 */
function get_cookie_domain( $network ) {
	if ( ! empty( $network->cookie_domain ) ) {
		$cookie_domain = '.' . $network->cookie_domain;
	}
	else {
		$cookie_domain = '.' . $network->domain;

		// Remove WWW if the domain has it
		if ( '.www.' === substr( $cookie_domain, 0, 5 ) ) {
			$cookie_domain = substr( $cookie_domain, 4 );
		}
	}

	return $cookie_domain;
}

/**
 * Is this on the main domain for the network?
 *
 * @param string $domain Domain to check, defaults to the current host
 * @param stdClass $network Network object, defaults to the current network
 * @return boolean Is this the main domain?
 */
function is_main_domain( $domain = null, $network = null ) {
	if ( empty( $domain ) ) {
		$domain = $_SERVER['HTTP_HOST'];
	}

	$supplied_network = $network;
	if ( empty( $network ) ) {
		$network = $GLOBALS['current_site'];
	}

	/**
	 * Change the network used to check main domain.
	 *
	 * For multinetwork sites, this allows using only the main network rather
	 * than network-local.
	 *
	 * @param stdClass $network Network object to be used
	 * @param string $domain Domain to check
	 * @param stdClass|null $supplied_network Original network object provided as an argument
	 */
	$network = apply_filters( 'mercator.sso.main_domain_network', $network, $domain, $supplied_network );

	$cookie_domain = get_cookie_domain( $network );
	$cookie_domain_length = strlen( $cookie_domain );

	// INTERNAL NOTE: While I typically hate this pattern of nested-ifs, and I'd
	// typically change this to return-early, it makes it more complicated to
	// document the filter. Sorry.

	if ( $cookie_domain_length > strlen( $domain ) ) {
		// Check if the domain is $cookie_domain without the initial .
		// (i.e. are we on the base domain?)
		if ( substr( $cookie_domain, 0, 1 ) === '.' && substr( $cookie_domain, 1 ) === $domain ) {
			$is_main = true;
		}
		else {
			// Cookie domain is longer than the domain, and not the base domain.
			// Boop.
			$is_main = false;
		}
	}
	elseif ( substr( $domain, -$cookie_domain_length ) !== $cookie_domain ) {
		// Domain isn't a strict prefix of the cookie domain
		$is_main = false;
	}
	else {
		// Welcome to the main domain.
		$is_main = true;
	}

	/**
	 * Is this domain the main domain?
	 *
	 * @param boolean $is_main Is this the main domain?
	 * @param string $domain Domain we checked against
	 * @param stdClass $network Network we fetched the cookie domain from
	 */
	return apply_filters( 'mercator.sso.is_main_domain', $is_main, $domain, $network );
}

/**
 * Get main site for a network
 *
 * @param int $network_id Network ID, null for current network
 * @return int Main site ("blog" in old terminology) ID
 */
function get_main_site( $network_id = null ) {
	global $wpdb;

	if ( empty( $network_id ) ) {
		$network = $GLOBALS['current_site'];
	}
	else {
		$network = wp_get_network( $network_id );
	}

	if ( ! $primary_id = wp_cache_get( 'network:' . $network->id . ':main_site', 'site-options' ) ) {
		$primary_id = $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs WHERE domain = %s AND path = %s",
			$network->domain, $network->path ) );
		wp_cache_add( 'network:' . $network->id . ':main_site', $primary_id, 'site-options' );
	}

	return (int) $primary_id;
}

/**
 * Get an SSO action URL
 * @param string $action SSO action to perform (ACTION_JS/ACTION_LOGIN)
 * @param array $args Arguments to be added to the URL (unencoded)
 *
 * @return string URL for the given action
 */
function get_action_url( $action, $args = array() ) {
	/**
	 * Main site used for action URLs
	 *
	 * @param int Site ID
	 */
	$main_site = apply_filters( 'mercator.sso.main_site_for_actions', get_main_site() );

	$script_url = get_admin_url( $main_site, 'admin-ajax.php' );

	$defaults = array(
		'action' => $action,
	);
	$args = wp_parse_args( $args, $defaults );
	$url = add_query_arg( urlencode_deep( $args ), $script_url );

	return apply_filters( 'mercator.sso.action_url', $url, $action, $args );
}

/**
 * Create shared nonce token
 *
 * WP's tokens are linked to the current user. Due to the nature of what we're
 * doing here, we need to make a user-independent nonce. The user we're working
 * on can instead be part of the action.
 *
 * @param string $action Scalar value to add context to the nonce.
 * @return string Nonce token.
 */
function create_shared_nonce( $action ) {
	$i = wp_nonce_tick();
	return substr( wp_hash( $i . '|' . $action, 'nonce' ), -12, 10 );
}

/**
 * Verify that correct shared nonce was used with time limit.
 *
 * Uses nonces not linked to the current user. See {@see create_shared_nonce()}
 * for more about why this exists.
 *
 * @param string $nonce Nonce that was used in the form to verify
 * @param string|int $action Should give context to what is taking place and be the same when nonce was created.
 * @return bool Whether the nonce check passed or failed.
 */
function verify_shared_nonce( $nonce, $action ) {
	if ( empty( $nonce ) ) {
		return false;
	}

	$i = wp_nonce_tick();

	// Nonce generated 0-12 hours ago
	$expected = substr( wp_hash( $i . '|' . $action, 'nonce'), -12, 10 );
	if ( hash_equals( $expected, $nonce ) ) {
		return 1;
	}

	// Nonce generated 12-24 hours ago
	$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action, 'nonce' ), -12, 10 );
	if ( hash_equals( $expected, $nonce ) ) {
		return 2;
	}

	// Invalid nonce
	return false;
}

/**
 * Output Javascript for anonymous users
 *
 * Short and sweet, nothing to do here.
 */
function output_javascript_nopriv() {
	header( 'Content-Type: application/javascript' );

	exit;
}

/**
 * Output Javascript for logged-in viewers
 *
 * This is where the redirection magic happens.
 */
function output_javascript_priv() {
	header( 'Content-Type: application/javascript' );

	// Double-check user, in case an enterprising plugin decided to pretend our
	// action was authenticated
	if ( ! is_user_logged_in() ) {
		exit;
	}

	$host = preg_replace( '#[^a-z0-9.\-]+#i', '', wp_unslash( $_GET['host'] ) );
	$back = wp_unslash( $_GET['back'] );
	$site = absint( wp_unslash( $_GET['site'] ) );

	// Verify nonce
	$nonce_action = 'mercator-sso|' . $site . '|' . $host . '|' . $back;
	if ( empty( $_GET['nonce'] ) || ! verify_shared_nonce( $_GET['nonce'], $nonce_action ) ) {
		exit;
	}

	$args = array(
		'host' => $host,
		'back' => $back,
		'site' => $site,

		// Recreate nonce, just in case we hit the 12/24 hour boundary
		'nonce' => create_shared_nonce( $nonce_action ),
	);

	$url = get_action_url( ACTION_LOGIN, $args );
?>
window.MercatorSSO = function() {
	if ( typeof document.location.host != 'undefined' && document.location.host != '<?php echo addslashes( $host ) ?>' ) {
		return;
	}

	document.write('<body>');
	document.body.style.display='none';
	window.location = '<?php echo addslashes( $url ) ?>&fragment='+encodeURIComponent(document.location.hash);
};
<?php

	exit;
}

/**
 * Output Javascript into the header of the page
 *
 * This should be the first asset loaded to reduce loading time.
 */
function head_js() {
	if ( is_user_logged_in() || is_main_domain() ) {
		return;
	}

	$args = array(
		'host' => $_SERVER['HTTP_HOST'],
		'back' => set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ),
		'site' => get_current_blog_id(),
	);
	$args['nonce'] = create_shared_nonce( 'mercator-sso|' . $args['site'] . '|' . $args['host'] . '|' . $args['back'] );

	$script_url = get_action_url( ACTION_JS, $args );
?>
	<script src="<?php echo esc_url( $script_url ) ?>"></script>
	<script type="text/javascript">
		/* <![CDATA[ */
			if ( 'function' === typeof MercatorSSO ) {
				document.cookie = "<?php echo esc_js( TEST_COOKIE ) ?>=WP Cookie check; path=/";
				if ( document.cookie.match( /(;|^)\s*<?php echo esc_js( TEST_COOKIE ) ?>\=/ ) ) {
					MercatorSSO();
				}
			}
		/* ]]> */
	</script>
<?php
}

/**
 * Handle a login request/response from admin-ajax.php
 */
function handle_login() {
	// Are we the central server?
	if ( is_main_domain() ) {
		return handle_login_request();
	}
	else {
		return handle_login_response();
	}
}

/**
 * Handle request from mapped host
 *
 * The mapped host has just directed the user to here on the main host. Generate
 * a response and token.
 */
function handle_login_request() {
	$arg_keys = array( 'host', 'back', 'nonce', 'fragment', 'site' );
	$args = array();
	foreach ( $arg_keys as $key ) {
		$args[ $key ] = empty( $_GET[ $key ] ) ? '' : wp_unslash( $_GET[ $key ] );
	}

	$args['site'] = absint( $args['site'] );

	$nonce_action = 'mercator-sso|' . $args['site'] . '|' . $args['host'] . '|' . $args['back'];
	if ( empty( $args['nonce'] ) || ! verify_shared_nonce( $args['nonce'], $nonce_action ) ) {
		status_header( 403 );
		exit;
	}

	if ( ! is_user_logged_in() ) {
		status_header( 401 );
		exit;
	}

	// Combine fragment into back so we can drop the arg
	if ( ! empty( $args['fragment'] ) ) {
		// Don't double-hash
		if ( substr( $args['fragment'], 0, 1 ) !== '#' ) {
			 $args['back'] .= '#';
		}
		$args['back'] .= $args['fragment'];
	}

	// Logged in, pass it on
	$url = get_login_url( get_current_user_id(), $args );
	if ( is_wp_error( $url ) ) {
		status_header( 500 );
		exit;
	}

	wp_redirect( $url );
	exit;
}

/**
 * Get the URL to initiate login
 *
 * Accessing this URL will give the user access to the site. Make sure the user
 * is definitely authenticated, as this will log them in.
 *
 * @param int $user User ID
 * @param array $args {
 *     Arguments for the login URL
 *
 *     @type string $host Host to authenticate for
 *     @type string $back URL to return to after authentication
 *     @type int $site Site ID to authenticate for, defaults to the current site
 * }
 * @return string|WP_Error Login URL for the given domain
 */
function get_login_url( $user, $args ) {
	$defaults = array(
		'host' => '',
		'back' => '',
		'site' => get_current_blog_id(),
	);
	$args = wp_parse_args( $args, $defaults );

	// Create the token
	$token_data = array(
		'back'   => $args['back'],
		'site'   => $args['site'],
		'user'   => $user,
		'time'   => time(),
	);
	$key = wp_hash( serialize( $token_data ) );
	$mid = add_user_meta( $user, 'mercator_sso_' . $key, $token_data );
	if ( empty( $mid ) ) {
		return new WP_Error( 'mercator.sso.meta_failed', __( 'Could not save token to database', 'mercator' ), array( 'data' => $token_data, 'key' => $key ) );
	}

	$url_args = array(
		'action' => ACTION_LOGIN,
		'key'    => $key,
		'nonce'  => create_shared_nonce( 'mercator-sso-login|' . $key ),
	);
	$admin_url = get_admin_url( $args['site'], 'admin-ajax.php', 'relative' );
	$admin_url = add_query_arg( urlencode_deep( $url_args ), $admin_url );

	// SSL will carry through the whole way, so set_url_scheme should still work
	// at this point
	$url = set_url_scheme( 'http://' . $args['host'] . $admin_url );

	return apply_filters( 'mercator.sso.login_url', $url, $args );
}

/**
 * Handle response from main host
 *
 * This is called when the user gets redirected back to the original host, now
 * with an authentication token.
 */
function handle_login_response() {
	$arg_keys = array( 'nonce', 'key' );
	$args = array();
	foreach ( $arg_keys as $key ) {
		$args[ $key ] = empty( $_GET[ $key ] ) ? '' : wp_unslash( $_GET[ $key ] );
	}

	$nonce_action = 'mercator-sso-login|' . $args['key'];
	if ( empty( $args['nonce'] ) || ! verify_shared_nonce( $args['nonce'], $nonce_action ) ) {
		status_header( 403 );
		exit;
	}

	// Fetch using the token
	$users = get_users( array(
		'meta_key'     => 'mercator_sso_' . $args['key'],

		// Check that the value exists (WP doesn't support EXISTS, so use a
		// dummy value that will never match)
		'meta_value'   => 'dummy_value',
		'meta_compare' => '!=',

		// Skip capability check
		'blog_id'      => 0,
	) );
	if ( empty( $users ) ) {
		status_header( 404 );
		exit;
	}

	$user = $users[0];

	// Grab the rest of the data back
	$token = get_user_meta( $user->ID, 'mercator_sso_' . $args['key'], true );
	if ( empty( $token ) ) {
		// Token has already been used, bail
		status_header( 404 );
		exit;
	}
	// Remove the token to avoid replay attacks
	delete_user_meta( $user->ID, 'mercator_sso_' . $args['key'] );

	/**
	 * How long should the SSO tokens last?
	 *
	 * @param int $duration Session duration in seconds
	 */
	$duration = apply_filters( 'mercator.sso.expiration', 5 * MINUTE_IN_SECONDS );
	if ( time() >= ( $token['time'] + $duration ) ) {
		status_header( 403 );
		exit;
	}

	if ( $token['site'] !== get_current_blog_id() ) {
		status_header( 400 );
		exit;
	}

	// Verified, let's boop.
	if ( is_user_logged_in() && get_current_user_id() === $token['user'] ) {
		// Nothing to do.
		wp_redirect( $token['back'] );
		exit;
	}

	wp_set_current_user( $token['user'] );
	wp_set_auth_cookie( $token['user'], true );

	// Logged in, return to sender.
	wp_redirect( $token['back'] );
	exit;
}
