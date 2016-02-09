<?php
// Default mu-plugins directory if you haven't set it
defined( 'WPMU_PLUGIN_DIR' ) or define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
//uncomment the two lines below to disable SSO (single sign on)
//add_filter( 'mercator.sso.enabled', '__return_false' );
//add_action( 'muplugins_loaded', 'Mercator\\SSO\\initialize_cookie_domain' );
require WPMU_PLUGIN_DIR . '/mercator/mercator.php';