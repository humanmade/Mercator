<?php
/**
 * Administrative UI and helpers for Mercator
 *
 * @package Mercator
 */

namespace Mercator\Admin;

use Mercator\Mapping;
use WP_Error;

add_filter( 'wpmu_blogs_columns',            __NAMESPACE__ . '\\add_site_list_column' );
add_action( 'manage_sites_custom_column',    __NAMESPACE__ . '\\output_site_list_column', 10, 2 );
add_action( 'admin_footer',                  __NAMESPACE__ . '\\maybe_output_site_tab' );
add_action( 'admin_action_mercator-aliases', __NAMESPACE__ . '\\output_list_page' );
add_action( 'admin_action_mercator-add',     __NAMESPACE__ . '\\output_edit_page' );
add_action( 'admin_action_mercator-edit',    __NAMESPACE__ . '\\output_edit_page' );
add_filter( 'plugin_row_meta',               __NAMESPACE__ . '\\output_sunrise_dropin_note', -10, 4 );

/**
 * Add site list column to list
 *
 * @param array $columns Column map of ID => title
 * @return array
 */
function add_site_list_column( $columns ) {
	$columns['mercator_aliases'] = __( 'Aliases', 'mercator' );
	return $columns;
}

/**
 * Output the site list column
 *
 * @param string $column Column ID
 * @param int $site_id Site ID
 */
function output_site_list_column( $column, $site_id ) {
	switch ( $column ) {
		case 'mercator_aliases':
			$mappings = Mapping::get_by_site( $site_id );
			if ( ! empty( $mappings ) ) {
				foreach ( $mappings as $mapping ) {
					// Kinda horrible formatting, but matches the existing
					echo esc_html( $mapping->get_domain() ) . '<br />';
				}
			}

			break;
	}
}

/**
 * Output the site tab if we're on the right page
 *
 * Outputs the link, then moves it into place using JS, as there are no hooks to
 * speak of.
 */
function maybe_output_site_tab() {
	if ( ! is_network_admin() ) {
		return;
	}

	if ( $GLOBALS['parent_file'] !== 'sites.php' || $GLOBALS['submenu_file'] !== 'sites.php' ) {
		return;
	}

	$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
	if ( empty( $id ) ) {
		return;
	}

	$class = ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'mercator-aliases' ) ? ' nav-tab-active' : '';

?>
	<span id="mercator-aliases-nav-link" class="hide-if-no-js"><a href="<?php echo network_admin_url( 'admin.php?action=mercator-aliases' ) . '&id=' . $id ?>" class="nav-tab<?php echo $class ?>"><?php esc_html_e( 'Aliases', 'mercator' ) ?></a></span>
	<script>jQuery(function ($) {
		$( '#mercator-aliases-nav-link' ).appendTo( $( '.nav-tab-wrapper' ) );
	});</script>
<?php

}

/**
 * Output the admin page header
 *
 * @param int $id Site ID
 * @param array $messages Messages to display
 */
function output_page_header( $id, $messages = array() ) {
	$site_url_no_http = preg_replace( '#^http(s)?://#', '', get_blogaddress_by_id( $id ) );
	$title_site_url_linked = sprintf( __('Aliases: <a href="%1$s">%2$s</a>'), get_blogaddress_by_id( $id ), $site_url_no_http );

	// Load the page header
	global $title, $parent_file, $submenu_file;
	$title = sprintf( __( 'Aliases: %s', 'mercator' ), $site_url_no_http );
	$parent_file = 'sites.php';
	$submenu_file = 'sites.php';
	require_once(ABSPATH . 'wp-admin/admin-header.php');

	$add_link = add_query_arg(
		array(
			'action' => 'mercator-add',
			'id'     => $id,
		),
		network_admin_url( 'admin.php' )
	);
?>

<div class="wrap">
	<h2 id="edit-site">

		<?php echo $title_site_url_linked ?>

		<a href="<?php echo esc_url( $add_link ) ?>" class="add-new-h2"><?php echo esc_html_x( 'Add New', 'alias', 'mercator' ) ?></a>
	</h2>
	<h3 class="nav-tab-wrapper">
<?php
	$tabs = array(
		'site-info'     => array( 'label' => __( 'Info' ),     'url' => 'site-info.php'     ),
		'site-users'    => array( 'label' => __( 'Users' ),    'url' => 'site-users.php'    ),
		'site-themes'   => array( 'label' => __( 'Themes' ),   'url' => 'site-themes.php'   ),
		'site-settings' => array( 'label' => __( 'Settings' ), 'url' => 'site-settings.php' ),
	);
	foreach ( $tabs as $tab_id => $tab ) {
		$class = ( $tab['url'] == $pagenow ) ? ' nav-tab-active' : '';
		echo '<a href="' . $tab['url'] . '?id=' . $id .'" class="nav-tab' . $class . '">' . esc_html( $tab['label'] ) . '</a>';
	}
?>
	</h3>
<?php
	if ( ! empty( $messages ) ) {
		foreach ( $messages as $msg )
			echo '<div id="message" class="updated"><p>' . $msg . '</p></div>';
	}
}

/**
 * Output the admin page footer
 */
function output_page_footer() {
	echo '</div>';

	require_once(ABSPATH . 'wp-admin/admin-footer.php');
}

/**
 * Handle submission of the list page
 *
 * Handles bulk actions for the list page. Redirects back to itself after
 * processing, and exits.
 *
 * @param int $id Site ID
 * @param string $action Action to perform
 */
function handle_list_page_submit( $id, $action ) {
	check_admin_referer( 'mercator-aliases-bulk-' . $id );

	$sendback = remove_query_arg( array( 'did_action', 'mappings', '_wpnonce' ), wp_get_referer() );
	if ( ! $sendback )
		$sendback = admin_url( $parent_file );

	$mappings = empty( $_REQUEST['mappings'] ) ? array() : (array) $_REQUEST['mappings'];
	$mappings = array_map( 'absint', $mappings );

	if ( ! isset( $mappings ) ) {
		wp_redirect( $sendback );
		exit;
	}

	$processed = 0;
	$args = array(
		'did_action' => $action,
		'mappings'   => join( ',', $mappings ),
	);
	switch ( $action ) {
		case 'activate':
			foreach ( $mappings as $id ) {
				$mapping = Mapping::get( $id );
				if ( is_wp_error( $mapping ) ) {
					continue;
				}

				if ( $mapping->set_active( true ) ) {
					$processed++;
				}
			}
			break;

		case 'deactivate':
			foreach ( $mappings as $id ) {
				$mapping = Mapping::get( $id );
				if ( is_wp_error( $mapping ) ) {
					continue;
				}

				if ( $mapping->set_active( false ) ) {
					$processed++;
				}
			}
			break;

		case 'delete':
			$args['domains'] = array();
			foreach ( $mappings as $id ) {
				$mapping = Mapping::get( $id );
				if ( is_wp_error( $mapping ) ) {
					continue;
				}

				if ( $mapping->delete() ) {
					// Mappings don't exist after we delete them, so pass the
					// domain for messages and such
					$args['domains'][] = $mapping->get_domain();
					$processed++;
				}
			}
			break;

		case 'make_primary':
			foreach ( $mappings as $id ) {
				$mapping = Mapping::get( $id );
				if ( is_wp_error( $mapping ) ) {
					continue;
				}

				// Make this mapping primary
				if ( $mapping->make_primary() ) {
					$processed++;
				}
			}
			break;

		default:
			do_action_ref_array( "mercator_aliases_bulk_action-{$action}", array( $mappings, &$processed, $action ) );
			break;
	}

	$args['processed'] = $processed;
	$sendback = add_query_arg( $args, $sendback );

	wp_safe_redirect( $sendback );
	exit();
}

/**
 * Output alias editing page
 */
function output_list_page() {

	$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

	if ( ! $id )
		wp_die( __('Invalid site ID.') );

	$id = absint( $id );

	$details = get_blog_details( $id );
	if ( ! can_edit_network( $details->site_id ) || (int) $details->blog_id !== $id )
		wp_die( __( 'You do not have permission to access this page.' ) );

	$wp_list_table = new Alias_List_Table( array(
		'site_id' => $id,
	) );

	$messages = array();

	$bulk_action = $wp_list_table->current_action();
	if ( $bulk_action ) {
		$messages = handle_list_page_submit( $id, $bulk_action );
	}

	$pagenum = $wp_list_table->get_pagenum();

	$wp_list_table->prepare_items( $id );

	// Add messages for bulk actions
	if ( ! empty( $_REQUEST['did_action'] ) ) {
		$processed  = empty( $_REQUEST['processed'] ) ? 0 : absint( $_REQUEST['processed'] );
		$did_action = $_REQUEST['did_action'];

		$mappings = empty( $_REQUEST['mappings'] ) ? array() : wp_parse_id_list( $_REQUEST['mappings'] );
		$mappings = array_map( 'absint', $mappings );

		// Special case for single, as it's not really a "bulk" action
		if ( $processed === 1 ) {
			$bulk_messages = array(
				'activate'     => __( 'Activated %s',   'mercator' ),
				'deactivate'   => __( 'Deactivated %s', 'mercator' ),
				'delete'       => __( 'Deleted %s',     'mercator' ),
				'add'          => __( 'Created %s',     'mercator' ),
				'edit'         => __( 'Updated %s',     'mercator' ),
				'make_primary' => __( '%s set as primary domain', 'mercator' ),
			);
			if ( $did_action === 'make_primary' ) {
				$domain = parse_url( get_blog_option( $id, 'home' ), PHP_URL_HOST );
			}
			elseif ( $did_action !== 'delete' ) {
				$mapping = Mapping::get( $mappings[0] );
				$domain = $mapping->get_domain();
			}
			else {
				$domain = empty( $_REQUEST['domains'] ) ? array() : $_REQUEST['domains'][0];
			}
			$placeholder = '<code>' . $domain . '</code>';
		}
		else {
			// Note: we still use _n for languages which have special cases on
			// e.g. 3, 5, 10, etc
			$bulk_messages = array(
				'activate'   => _n( '%s alias activated.',   '%s aliases activated.',  $processed ),
				'deactivate' => _n( '%s alias deactivated.', '%s aliases deactiaved.', $processed ),
				'delete'     => _n( '%s alias deleted.',     '%s aliases deleted.',    $processed ),
				'add'        => _n( '%s alias created.',     '%s aliases created.',    $processed ),
				'edit'       => _n( '%s alias updated.',     '%s aliases updated.',    $processed ),
			);
			$placeholder = number_format_i18n( $processed );
		}
		$bulk_messages = apply_filters( 'mercator_aliases_bulk_messages', $bulk_messages, $processed );

		if ( ! empty( $bulk_messages[ $did_action ] ) ) {
			$messages[] = sprintf( $bulk_messages[ $did_action ], $placeholder );
		}
	}

	output_page_header( $id, $messages );

?>
	<form method="post" action="admin.php?action=mercator-aliases">
		<?php $wp_list_table->display(); ?>
	</form>
<?php
	output_page_footer();

}

/**
 * Validate alias parameters
 *
 * @param array $params Raw input parameters
 * @param boolean $check_permission Should we check that the user can edit the network?
 * @return array|WP_Error Validated parameters on success, WP_Error otherwise
 */
function validate_alias_parameters( $params, $check_permission = true ) {
	$valid = array();

	// Validate domain
	if ( empty( $params['domain'] ) ) {
		return new WP_Error( 'mercator.params.no_domain', __( 'Aliases require a domain name', 'mercator' ) );
	}

	if ( ! preg_match( '#^[a-z0-9\-.]+$#i', $params['domain'] ) ) {
		return new WP_Error( 'mercator.params.domain_invalid_chars', __( 'Domains can only contain alphanumeric characters, dashes (-) and periods (.)', 'mercator' ) );
	}

	$valid['domain'] = $params['domain'];

	// Validate site ID
	$valid['site']   = absint( $params['id'] );
	if ( empty( $valid['site'] ) ) {
		return new WP_Error( 'mercator.params.invalid_site', __( 'Invalid site ID', 'mercator' ) );
	}

	if ( $check_permission ) {
		$details = get_blog_details( $valid['site'] );

		// Note: site_id is old terminology, referring to the network ID
		if ( ! can_edit_network( $details->site_id ) ) {
			return new WP_Error( 'mercator.params.cannot_edit', __( 'You do not have permission to edit this site', 'mercator' ) );
		}
	}

	// Validate active flag
	$valid['active'] = empty( $params['active'] ) ? false : true;

	return $valid;
}

/**
 * Handle submission of the add page
 *
 * @return array|null List of errors. Issues a redirect and exits on success.
 */
function handle_edit_page_submit( $id, $mapping ) {
	$messages = array();
	if ( empty( $mapping ) ) {
		$did_action = 'add';
		check_admin_referer( 'mercator-add-' . $id );
	}
	else {
		$did_action = 'edit';
		check_admin_referer( 'mercator-edit-' . $mapping->get_id() );
	}

	// Check that the parameters are correct first
	$params = validate_alias_parameters( wp_unslash( $_POST ) );
	if ( is_wp_error( $params ) ) {
		$messages[] = $params->get_error_message();

		if ( $params->get_error_code() === 'mercator.params.domain_invalid_chars' ) {
			$messages[] = __( '<strong>Note</strong>: for internationalized domain names, use the ASCII form (e.g, <code>xn--bcher-kva.example</code>)', 'mercator' );
		}

		return $messages;
	}
	if ( empty( $mapping ) ) {
		// Create the actual mapping
		$result = $mapping = Mapping::create( $params['site'], $params['domain'], $params['active'] );
	}
	else {
		// Update our existing
		$result = $mapping->update( $params );
	}

	if ( is_wp_error( $result ) ) {
		$messages[] = $result->get_error_message();

		return $messages;
	}

	// Success, redirect to alias page
	$location = add_query_arg(
		array(
			'action'     => 'mercator-aliases',
			'id'         => $id,
			'did_action' => $did_action,
			'mappings'   => $mapping->get_id(),
			'processed'  => 1,
			'_wpnonce'   => wp_create_nonce( 'mercator-alias-added-' . $mapping->get_id() ),
		),
		network_admin_url( 'admin.php' )
	);
	wp_safe_redirect( $location );
	exit;
}

/**
 * Output alias editing page
 */
function output_edit_page() {

	$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

	if ( ! $id )
		wp_die( __('Invalid site ID.') );

	$id = absint( $id );

	$details = get_blog_details( $id );
	if ( ! can_edit_network( $details->site_id ) || (int) $details->blog_id !== $id )
		wp_die( __( 'You do not have permission to access this page.' ) );

	// Are we editing?
	$mapping = null;
	$form_action = network_admin_url( 'admin.php?action=mercator-add' );
	if ( ! empty( $_REQUEST['mapping'] ) ) {
		$mapping_id = absint( $_REQUEST['mapping'] );
		$mapping = Mapping::get( $mapping_id );
		if ( is_wp_error( $mapping ) || empty( $mapping ) ) {
			wp_die( __( 'Invalid alias ID.', 'mercator' ) );
		}

		$form_action = network_admin_url( 'admin.php?action=mercator-edit' );
	}

	// Handle form submission
	$messages = array();
	if ( ! empty( $_POST['submit'] ) ) {
		$messages = handle_edit_page_submit( $id, $mapping );
	}

	output_page_header( $id, $messages );

	if ( empty( $mapping ) || ! empty( $_POST['_wpnonce'] ) ) {
		$domain = empty( $_POST['domain'] ) ? '' : wp_unslash( $_POST['domain'] );
		$active = ! empty( $_POST['active'] );
	}
	else {
		$domain = $mapping->get_domain();
		$active = $mapping->is_active();
	}

?>
	<form method="post" action="<?php echo esc_url( $form_action ) ?>">
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="mercator-domain"><?php echo esc_html_x( 'Domain Name', 'field name', 'mercator' ) ?></label>
				</th>
				<td>
					<input type="text" class="regular-text code"
						name="domain" id="mercator-domain"
						value="<?php echo esc_attr( $domain ) ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php echo esc_html_x( 'Active', 'field name', 'mercator' ) ?>
				</th>
				<td>
					<label>
						<input type="checkbox"
							name="active" <?php checked( $active ) ?> />

						<?php esc_html_e( 'Mark alias as active', 'mercator' ) ?>
					</label>
				</td>
			</tr>
		</table>

		<input type="hidden" name="id" value="<?php echo esc_attr( $id ) ?>" />
		<?php

		if ( empty( $mapping ) ) {
			wp_nonce_field( 'mercator-add-' . $id );
			submit_button( __( 'Add Alias', 'mercator' ) );
		}
		else {
			echo '<input type="hidden" name="mapping" value="' . esc_attr( $mapping->get_id() ) . '" />';
			wp_nonce_field( 'mercator-edit-' . $mapping->get_id() );
			submit_button( __( 'Save Alias', 'mercator' ) );
		}

		?>
	</form>
<?php
	output_page_footer();

}

/**
 * Add note to sunrise.php on dropins list about Mercator
 *
 * @param array $meta Meta links
 * @param string $file Plugin filename (sunrise.php for sunrise)
 * @param array $data Data from the plugin header
 * @param string $status Status of the plugin
 * @return array Modified meta links
 */
function output_sunrise_dropin_note( $meta, $file, $data, $status ) {
	if ( $file !== 'sunrise.php' || $status !== 'dropins' ) {
		return $meta;
	}

	$note = '<em>' . sprintf(
		__( 'Enhanced by <a href="%s" title="%s">Mercator</a>', 'mercator' ),
		'https://github.com/humanmade/Mercator',
		sprintf(
			__( 'Version %s', 'mercator' ),
			\Mercator\VERSION
		)
	) . '</em>';
	array_unshift( $meta, $note );
	return $meta;
}
