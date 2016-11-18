<?php
namespace Mercator;

use WP_Error;

/**
 * Mapping object
 *
 * @package Mercator
 */
class Mapping {
	/**
	 * Site ID
	 *
	 * @var int
	 */
	protected $site;

	/**
	 * Mapping data
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Constructor
	 *
	 * @param array $data Mapping data
	 */
	protected function __construct( $data ) {
		$this->site = $data->blog_id;
		$this->data = $data;
	}

	/**
	 * Clone magic method when clone( self ) is called.
	 *
	 * As the internal data is stored in an object, we have to make a copy
	 * when this object is cloned.
	 */
	public function __clone() {
		$this->data = clone( $this->data );
	}

	/**
	 * Get mapping ID
	 *
	 * @return int Mapping ID
	 */
	public function get_id() {
		return absint( $this->data->id );
	}

	/**
	 * Is the mapping active?
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->data->active == 1;
	}

	/**
	 * Get site object
	 *
	 * @return stdClass|boolean {@see get_blog_details}
	 */
	public function get_site() {
		return get_blog_details( $this->site, false );
	}

	/**
	 * Get site ID
	 *
	 * @return int Site ID
	 */
	public function get_site_id() {
		return absint( $this->site );
	}

	/**
	 * Get the domain from the mapping
	 *
	 * @return string
	 */
	public function get_domain() {
		return $this->data->domain;
	}

	/**
	 * Set whether the mapping is active
	 *
	 * @param bool $active Should the mapping be active? (True for active, false for inactive)
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function set_active( $active ) {
		$data = array(
			'active' => (bool) $active,
		);
		return $this->update( $data );
	}

	/**
	 * Make this mapping the primary domain, eg. set the homeurl for the site
	 *
	 * @return bool|\WP_Error True if we created the old mapping or WP_Error if an error occurred
	 */
	public function make_primary() {
		// Get current site details to update
		$site = $this->get_site();

		// Create a new mapping from the old canonical domain
		$mapping = self::create( $site->blog_id, $site->domain, true );

		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}

		// Set the new home and siteurl etc to the current mapping
		update_blog_details( $site->blog_id, array(
			'domain' => $this->get_domain(),
		) );

		// These are just a visual update for the site admin
		$url = esc_url( $this->get_domain() );
		update_blog_option( $site->blog_id, 'home', $url );
		update_blog_option( $site->blog_id, 'siteurl', $url );

		// Remove current mapping
		$this->delete();

		/**
		 * Fires after a mapping has set as primary.
		 *
		 * @param Mercator\Mapping $mapping The mapping object.
		 */
		do_action( 'mercator.mapping.made_primary', $this );

		return true;
	}

	/**
	 * Set the domain for the mapping
	 *
	 * @param string $domain Domain name
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function set_domain( $domain ) {
		$data = array(
			'domain' => $domain,
		);
		return $this->update( $data );
	}

	/**
	 * Update the mapping
	 *
	 * See also, {@see set_domain} and {@see set_active} as convenience methods.
	 *
	 * @param array|stdClass $data Mapping fields (associative array or object properties)
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function update( $data ) {
		global $wpdb;

		$data = (array) $data;
		$fields = array();
		$formats = array();

		// Were we given a domain (and is it not the current one)?
		if ( ! empty( $data['domain'] ) && $this->data->domain !== $data['domain'] ) {
			// Does this domain exist already?
			$existing = static::get_by_domain( $data['domain'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( ! empty( $existing ) && $existing->get_site_id() !== $data['site'] ) {
				// Domain exists already and points to another site
				return new \WP_Error( 'mercator.mapping.domain_exists' );
			}

			$fields['domain'] = $data['domain'];
			$formats[] = '%s';
		}

		// Were we given an active flag (and is it not current)?
		if ( isset( $data['active'] ) && $this->is_active() !== (bool) $data['active'] ) {
			$fields['active'] = (bool) $data['active'];
			$formats[] = '%d';
		}

		// Do we have things to update?
		if ( empty( $fields ) ) {
			return false;
		}

		$where = array( 'id' => $this->get_id() );
		$where_format = array( '%d' );
		$result = $wpdb->update( $wpdb->dmtable, $fields, $where, $formats, $where_format );
		if ( empty( $result ) && ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'mercator.mapping.update_failed' );
		}

		$old_mapping = clone( $this );
		// Update internal state
		foreach ( $fields as $key => $val ) {
			$this->data->$key = $val;
		}

		// Update the cache
		wp_cache_delete( 'id:' . $this->get_site_id(), 'domain_mapping' );
		wp_cache_delete( 'domain:' . $old_mapping->get_domain(), 'domain_mapping' );
		wp_cache_set( 'domain:' . $this->get_domain(), $this->data, 'domain_mapping' );

		/**
		 * Fires after a mapping has been updated.
		 *
		 * @param Mercator\Mapping $mapping         The mapping object.
		 * @param Mercator\Mapping $mapping         The previous mapping object.
		 */
		do_action( 'mercator.mapping.updated', $this, $old_mapping );

		return true;
	}

	/**
	 * Delete the mapping
	 *
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function delete() {
		global $wpdb;

		$where = array( 'id' => $this->get_id() );
		$where_format = array( '%d' );
		$result = $wpdb->delete( $wpdb->dmtable, $where, $where_format );
		if ( empty( $result ) ) {
			return new \WP_Error( 'mercator.mapping.delete_failed' );
		}

		// Update the cache
		wp_cache_delete( 'id:' . $this->get_site_id(), 'domain_mapping' );
		wp_cache_delete( 'domain:' . $this->get_domain(), 'domain_mapping' );

		/**
		 * Fires after a mapping has been delete.
		 *
		 * @param Mercator\Mapping $mapping         The mapping object.
		 */
		do_action( 'mercator.mapping.deleted', $this );

		return true;
	}

	/**
	 * Convert data to Mapping instance
	 *
	 * Allows use as a callback, such as in `array_map`
	 *
	 * @param stdClass $data Raw mapping data
	 * @return Mapping
	 */
	protected static function to_instance( $data ) {
		return new static( $data );
	}

	/**
	 * Convert list of data to Mapping instances
	 *
	 * @param stdClass[] $data Raw mapping rows
	 * @return Mapping[]
	 */
	protected static function to_instances( $data ) {
		return array_map( array( get_called_class(), 'to_instance' ), $data );
	}

	/**
	 * Get mapping by mapping ID
	 *
	 * @param int|Mapping $mapping Mapping ID or instance
	 * @return Mapping|WP_Error|null Mapping on success, WP_Error if error occurred, or null if no mapping found
	 */
	public static function get( $mapping ) {
		global $wpdb;

		// Allow passing a site object in
		if ( $mapping instanceof Mapping ) {
			return $mapping;
		}

		if ( ! is_numeric( $mapping ) ) {
			return new \WP_Error( 'mercator.mapping.invalid_id' );
		}

		$mapping = absint( $mapping );

		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$mapping = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->dmtable . ' WHERE id = %d', $mapping ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! $mapping ) {
			return null;
		}

		return new static( $mapping );
	}

	/**
	 * Get mapping by site ID
	 *
	 * @param int|stdClass $site Site ID, or site object from {@see get_blog_details}
	 * @return Mapping|WP_Error|null Mapping on success, WP_Error if error occurred, or null if no mapping found
	 */
	public static function get_by_site( $site ) {
		global $wpdb;

		// Allow passing a site object in
		if ( is_object( $site ) && isset( $site->blog_id ) ) {
			$site = $site->blog_id;
		}

		if ( ! is_numeric( $site ) ) {
			return new \WP_Error( 'mercator.mapping.invalid_id' );
		}

		$site = absint( $site );

		// Check cache first
		$mappings = wp_cache_get( 'id:' . $site, 'domain_mapping' );
		if ( ! empty( $mappings ) ) {
			return static::to_instances( $mappings );
		}

		// Cache missed, fetch from DB
		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$mappings = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->dmtable . ' WHERE blog_id = %d', $site ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! $mappings ) {
			return null;
		}

		wp_cache_set( 'id:' . $site, $mappings, 'domain_mapping' );
		return static::to_instances( $mappings );
	}

	/**
	 * Get mapping by domain(s)
	 *
	 * @param string|array $domains Domain(s) to match against
	 * @return Mapping|WP_Error|null Mapping on success, WP_Error if error occurred, or null if no mapping found
	 */
	public static function get_by_domain( $domains ) {
		global $wpdb;

		$domains = (array) $domains;

		// Check cache first
		$not_exists = 0;
		foreach ( $domains as $domain ) {
			$data = wp_cache_get( 'domain:' . $domain, 'domain_mapping' );
			if ( ! empty( $data ) && $data !== 'notexists' ) {
				return new static( $data );
			}
			elseif ( $data === 'notexists' ) {
				$not_exists++;
			}
		}
		if ( $not_exists === count( $domains ) ) {
			// Every domain we checked was found in the cache, but doesn't exist
			// so skip the query
			return null;
		}

		$placeholders = array_fill( 0, count( $domains ), '%s' );
		$placeholders_in = implode( ',', $placeholders );

		// Prepare the query
		$query = "SELECT * FROM {$wpdb->dmtable} WHERE domain IN ($placeholders_in) ORDER BY CHAR_LENGTH(domain) DESC LIMIT 1";
		$query = $wpdb->prepare( $query, $domains );

		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$mapping = $wpdb->get_row( $query );
		$wpdb->suppress_errors( $suppress );

		if ( empty( $mapping ) ) {
			// Cache that it doesn't exist
			foreach ( $domains as $domain ) {
				wp_cache_set( 'domain:' . $domain, 'notexists', 'domain_mapping' );
			}

			return null;
		}

		wp_cache_set( 'domain:' . $mapping->domain, $mapping, 'domain_mapping' );

		return new static( $mapping );
	}

	/**
	 * Create a new domain mapping
	 *
	 * @param string $site   Site ID, or site object from {@see get_blog_details}
	 * @param string $domain
	 * @param bool   $active
	 * @return Mapping|\WP_Error
	 */
	public static function create( $site, $domain, $active = false ) {
		global $wpdb;

		// Allow passing a site object in
		if ( is_object( $site ) && isset( $site->blog_id ) ) {
			$site = $site->blog_id;
		}

		if ( ! is_numeric( $site ) ) {
			return new \WP_Error( 'mercator.mapping.invalid_id' );
		}

		$site = absint( $site );
		$active = (bool) $active;

		// Did we get a full URL?
		if ( strpos( $domain, '://' ) !== false ) {
			// Parse just the domain out
			$domain = parse_url( $domain, PHP_URL_HOST );
		}

		// Does this domain exist already?
		$existing = static::get_by_domain( $domain );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! empty( $existing ) ) {
			// Domain exists already...
			if ( $site !== $existing->get_site_id() ) {
				// ...and points to another site
				return new \WP_Error( 'mercator.mapping.domain_exists' );
			}

			// ...and points to this site, so nothing to do
			return $existing;
		}

		// Create the mapping!
		$prev_errors = ! empty( $GLOBALS['EZSQL_ERROR'] ) ? $GLOBALS['EZSQL_ERROR'] : array();
		$suppress = $wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			$wpdb->dmtable,
			array( 'blog_id' => $site, 'domain' => $domain, 'active' => $active ),
			array( '%d', '%s', '%d' )
		);
		$wpdb->suppress_errors( $suppress );

		if ( empty( $result ) ) {
			// Check that the table exists...
			if ( check_table() === 'created' ) {
				// Table created, try again
				return static::create( $site, $domain );
			}

			// Other error. We suppressed errors before, so we need to make sure
			// we handle that now.
			$recent_errors = array_diff_key( $GLOBALS['EZSQL_ERROR'], $prev_errors );
			while ( count( $recent_errors ) > 0 ) {
				$error = array_shift( $recent_errors );
				$wpdb->print_error( $error['error_str'] );
			}

			return new \WP_Error( 'mercator.mapping.insert_failed' );
		}

		// Ensure the cache is flushed
		wp_cache_delete( 'id:' . $site, 'domain_mapping' );
		wp_cache_delete( 'domain:' . $domain, 'domain_mapping' );

		$mapping = static::get( $wpdb->insert_id );

		/**
		 * Fires after a mapping has been created.
		 *
		 * @param Mercator\Mapping $mapping         The mapping object.
		 */
		do_action( 'mercator.mapping.created', $mapping );
		return $mapping;
	}
}
