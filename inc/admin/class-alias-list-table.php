<?php
/**
 * List table for aliases
 *
 * @package Mercator
 */

namespace Mercator\Admin;

use Mercator\Mapping;
use WP_List_Table;

/**
 * List table for aliases
 */
class Alias_List_Table extends WP_List_Table {
	/**
	 * Prepare items for the list table
	 */
	public function prepare_items() {
		$this->items = array();

		if ( empty( $this->_args['site_id'] ) ) {
			return;
		}

		$id = $this->_args['site_id'];
		$mappings = Mapping::get_by_site( $id );
		if ( is_wp_error( $mappings ) ) {
			\Mercator\warn_with_message( __( 'Could not fetch aliases for the site. This may indicate a database error.', 'mercator' ) );
		}
		if ( ! empty( $mappings ) ) {
			$this->items = $mappings;
		}
	}

	/**
	 * Get columns for the table
	 *
	 * @return array Map of column ID => title
	 */
	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox" />',
			'domain' => _x( 'Domain', 'mercator' ),
			'active' => _x( 'Active', 'mercator' ),
		);
	}

	/**
	 * Get an associative array ( option_name => option_title ) with the list
	 * of bulk actions available on this table.
	 *
	 * @since 3.1.0
	 * @access protected
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'activate'   => _x( 'Activate', 'mercator' ),
			'deactivate' => _x( 'Deactivate', 'mercator' ),
			'delete'     => _x( 'Delete', 'mercator' ),
		);

		return apply_filters( 'mercator_alias_bulk_actions', $actions );
	}

	/**
	 * Display the bulk actions dropdown.
	 *
	 * @since 3.1.0
	 * @access protected
	 *
	 * @param string $which The location of the bulk actions: 'top' or 'bottom'.
	 *                      This is designated as optional for backwards-compatibility.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$no_new_actions = $this->_actions = $this->get_bulk_actions();
			/**
			 * Filter the list table Bulk Actions drop-down.
			 *
			 * The dynamic portion of the hook name, $this->screen->id, refers
			 * to the ID of the current screen, usually a string.
			 *
			 * This filter can currently only be used to remove bulk actions.
			 *
			 * @since 3.5.0
			 *
			 * @param array $actions An array of the available bulk actions.
			 */
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions );
			$this->_actions = array_intersect_assoc( $this->_actions, $no_new_actions );
			$two = '';
			echo '<input type="hidden" name="id" value="' . $this->_args['site_id'] . '" />';
			wp_nonce_field( 'mercator-aliases-bulk-' . $this->_args['site_id'] );
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) )
			return;

		echo "<label for='bulk-action-selector-" . esc_attr( $which ) . "' class='screen-reader-text'>" . __( 'Select bulk action' ) . "</label>";
		echo "<select name='bulk_action$two' id='bulk-action-selector-" . esc_attr( $which ) . "'>\n";
		echo "<option value='-1' selected='selected'>" . __( 'Bulk Actions' ) . "</option>\n";

		foreach ( $this->_actions as $name => $title ) {
			$class = 'edit' == $name ? ' class="hide-if-no-js"' : '';

			echo "\t<option value='$name'$class>$title</option>\n";
		}

		echo "</select>\n";

		submit_button( __( 'Apply' ), 'action', false, false, array( 'id' => "doaction$two" ) );
		echo "\n";
	}

	/**
	 * Get the current action selected from the bulk actions dropdown.
	 *
	 * @since 3.1.0
	 * @access public
	 *
	 * @return string|bool The action name or False if no action was selected
	 */
	public function current_action() {
		if ( isset( $_REQUEST['bulk_action'] ) && -1 != $_REQUEST['bulk_action'] )
			return $_REQUEST['bulk_action'];

		if ( isset( $_REQUEST['bulk_action2'] ) && -1 != $_REQUEST['bulk_action2'] )
			return $_REQUEST['bulk_action2'];

		return false;
	}

	/**
	 * Output extra "navigation" fields (section before/after the table)
	 *
	 * Outputs our Add New button above the table
	 *
	 * @param string $which Which tablenav to use (top/bottom)
	 */
	protected function extra_tablenav( $which ) {
		global $status;

		if ( $which !== 'top' )
			return;

		$add_link = add_query_arg(
			array(
				'action' => 'mercator-add',
				'id'     => $this->_args['site_id'],
			),
			network_admin_url( 'admin.php' )
		);
		echo '<div class="alignright actions">';
		echo '<a href="' . esc_url( $add_link ) . '" class="button-primary">' . esc_html_x( 'Add New', 'alias', 'mercator' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Display the table
	 *
	 * @since 3.1.0
	 * @access public
	 */
	public function display() {
		$singular = $this->_args['singular'];

		$this->display_tablenav( 'top' );

		$this->screen->render_screen_reader_content( 'heading_list' );
		?>
<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
	<thead>
	<tr>
		<?php $this->print_column_headers(); ?>
	</tr>
	</thead>

	<tbody id="the-list"<?php
	if ( $singular ) {
		echo " data-wp-lists='list:$singular'";
	} ?>>
		<?php $this->display_primary_domain_row(); ?>
		<?php $this->display_rows_or_placeholder(); ?>
	</tbody>

	<tfoot>
	<tr>
		<?php $this->print_column_headers( false ); ?>
	</tr>
	</tfoot>

</table>
		<?php
		$this->display_tablenav( 'bottom' );
	}

	/**
	 * Displays the current site information as the primary domain
	 */
	protected function display_primary_domain_row() {
		$site   = get_site( $this->_args['site_id'] );
		$domain = esc_html( $site->domain );
		if ( substr( $domain, 0, 4 ) === 'www.' ) {
			$domain = substr( $domain, 4 );
		}

		?>
		<tr class="mercator-primary-domain">
			<td></td>
			<td>
				<strong><?php echo esc_html( $domain ); ?></strong><br />
				<em><?php esc_html_e( 'Primary domain', 'mercator' ); ?></em>
			</td>
			<td></td>
		</tr>
		<?php
	}

	/**
	 * Get cell value for the checkbox column
	 *
	 * @param Mapping $mapping Current mapping item
	 * @return string HTML for the cell
	 */
	protected function column_cb( $mapping ) {
		return '<label class="screen-reader-text" for="cb-select-' . $mapping->get_id() . '">'
			. sprintf( __( 'Select %s' ), esc_html( $mapping->get_domain() ) ) . '</label>'
			. '<input type="checkbox" name="mappings[]" value="' . esc_attr( $mapping->get_id() )
			. '" id="cb-select-' . esc_attr( $mapping->get_id() ) . '" />';
	}

	/**
	 * Get cell value for the domain column
	 *
	 * @param Mapping $mapping Current mapping item
	 * @return string HTML for the cell
	 */
	protected function column_domain( $mapping ) {
		$domain = esc_html( $mapping->get_domain() );
		if ( substr( $domain, 0, 4 ) === 'www.' ) {
			$domain = substr( $domain, 4 );
		}

		$args = array(
			'action'   => 'mercator-aliases',
			'id'       => $mapping->get_site_id(),
			'mappings' => $mapping->get_id(),
			'_wpnonce' => wp_create_nonce( 'mercator-aliases-bulk-' . $this->_args['site_id'] ),
		);
		if ( ! $mapping->is_active() ) {
			$text = __( 'Activate', 'mercator' );
			$action = 'activate';
		}
		else {
			$text = __( 'Deactivate', 'mercator' );
			$action = 'deactivate';
		}
		$args['bulk_action'] = $action;

		$link = add_query_arg( $args, network_admin_url( 'admin.php' ) );

		$edit_link = add_query_arg(
			array(
				'action'  => 'mercator-edit',
				'id'      => $mapping->get_site_id(),
				'mapping' => $mapping->get_id(),
			),
			network_admin_url( 'admin.php' )
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'mercator' ) ),
			$action  => sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $text ) ),
		);

		if ( $mapping->is_active() ) {
			$primary_link = add_query_arg(
				wp_parse_args( array(
					'bulk_action' => 'make_primary',
				), $args ),
				network_admin_url( 'admin.php' )
			);
			$actions['make_primary'] = sprintf( '<a href="%s">%s</a>', esc_url( $primary_link ), esc_html__( 'Make primary', 'mercator' ) );
		}

		$delete_args = $args;
		$delete_args['bulk_action'] = 'delete';
		$delete_link = add_query_arg( $delete_args, network_admin_url( 'admin.php' ) );

		$actions['delete'] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_link ), esc_html__( 'Delete', 'mercator' ) );

		$actions = apply_filters( 'mercator_alias_actions', $actions, $mapping );
		$action_html = $this->row_actions( $actions, false );

		return '<strong>' . $domain . '</strong>' . $action_html;
	}

	/**
	 * Get cell value for the active column
	 *
	 * @param Mapping $mapping Current mapping item
	 * @return string HTML for the cell
	 */
	protected function column_active( $mapping ) {
		if ( $mapping->is_active() ) {
			return esc_html__( 'Active', 'mercator' );
		}
		return esc_html__( 'Inactive', 'mercator' );
	}

}
