<?php

namespace Mercator;

use WP_Error;

/**
 * Mapping object
 *
 * @package Mercator
 */
class Network_Mapping {
	/**
	 * Prefix on meta keys
	 */
	const KEY_PREFIX = 'mercator_';

	/**
	 * Mapping ID
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Site ID
	 *
	 * @var int
	 */
	protected $network;

	/**
	 * Mapping data
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Constructor
	 *
	 * @param int $id Mapping ID
	 * @param int $network Network ID
	 * @param array $data Mapping data
	 */
	protected function __construct( $id, $network, $data ) {
		$this->id = $id;
		$this->network = $network;
		$this->data = (object) $data;
	}

	/**
	 * Get mapping ID
	 *
	 * @return int Mapping ID
	 */
	public function get_id() {
		return $this->id;
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
	 * Get network object
	 *
	 * @return stdClass|boolean {@see get_blog_details}
	 */
	public function get_network() {
		return wp_get_network( $this->network );
	}

	/**
	 * Get network ID
	 *
	 * @return int Network ID
	 */
	public function get_network_id() {
		return $this->network;
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

		// Were we given a domain (and is it not the current one)?
		if ( ! empty( $data['domain'] ) && $this->data->domain !== $data['domain'] ) {
			// Did we get a full URL?
			if ( strpos( $data['domain'], '://' ) !== false ) {
				// Parse just the domain out
				$data['domain'] = parse_url( $data['domain'], PHP_URL_HOST );
			}

			// Does this domain exist already?
			$existing = static::get_by_domain( $data['domain'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( ! empty( $existing ) ) {
				// Domain exists already and points to another site
				return new WP_Error( 'mercator.mapping.domain_exists' );
			}

			$fields['domain'] = $data['domain'];
		}

		// Were we given an active flag (and is it not current)?
		if ( isset( $data['active'] ) && $this->is_active() !== (bool) $data['active'] ) {
			$fields['active'] = (bool) $data['active'];
		}

		// Do we have things to update?
		if ( empty( $fields ) ) {
			return false;
		}

		$current_data = (array) $this->data;
		$new_data = (object) array_merge( $current_data, $fields );
		$fields = array(
			'meta_key'   => static::key_for_domain( $new_data->domain ),
			'meta_value' => serialize( $new_data ),
		);
		$field_formats = array( '%s', '%s' );

		$where = array( 'meta_id' => $this->get_id() );
		$result = $wpdb->update( $wpdb->sitemeta, $fields, $where, $field_formats, array( '%d' ) );
		if ( empty( $result ) && ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'mercator.mapping.update_failed' );
		}

		// Update internal state
		$this->data = $new_data;

		// Update the cache
		wp_cache_delete( 'id:' . $this->get_network_id(), 'network_mapping' );
		wp_cache_set( 'domain:' . $fields['meta_key'], $this->data, 'network_mapping' );

		return true;
	}

	/**
	 * Delete the mapping
	 *
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function delete() {
		global $wpdb;

		$where = array( 'meta_id' => $this->get_id() );
		$where_format = array( '%d' );
		$result = $wpdb->delete( $wpdb->sitemeta, $where, $where_format );
		if ( empty( $result ) ) {
			return new WP_Error( 'mercator.mapping.delete_failed' );
		}

		// Update the cache
		wp_cache_delete( 'id:' . $this->get_network_id(), 'network_mapping' );
		wp_cache_delete( 'domain:' . static::key_for_domain( $this->get_domain() ), 'network_mapping' );

		return true;
	}

	/**
	 * Convert data to Mapping instance
	 *
	 * Allows use as a callback, such as in `array_map`
	 *
	 * @param stdClass $row Raw mapping row
	 * @return Mapping
	 */
	protected static function to_instance( $row ) {
		$data = unserialize( $row->meta_value );
		return new static( $row->meta_id, $row->site_id, $data );
	}

	/**
	 * Convert list of data to Mapping instances
	 *
	 * @param stdClass[] $rows Raw mapping rows
	 * @return Mapping[]
	 */
	protected static function to_instances( $rows ) {
		return array_map( array( get_called_class(), 'to_instance' ), $rows );
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
			return new WP_Error( 'mercator.mapping.invalid_id' );
		}

		$mapping = absint( $mapping );

		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->sitemeta . ' WHERE meta_id = %d', $mapping ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! $row ) {
			return null;
		}

		// Double-check that this is a Mercator field
		if ( substr( $row->meta_key, 0, strlen( static::KEY_PREFIX ) ) !== static::KEY_PREFIX ) {
			return new WP_Error( 'mercator.mapping.invalid_id' );
		}

		return static::to_instance( $row );
	}

	/**
	 * Get mapping by network ID
	 *
	 * @param int|stdClass $network Network ID, or network object from {@see wp_get_network}
	 * @return Mapping|WP_Error|null Mapping on success, WP_Error if error occurred, or null if no mapping found
	 */
	public static function get_by_network( $network ) {
		global $wpdb;

		// Allow passing a network object in
		if ( is_object( $network ) && isset( $network->id ) ) {
			$network = $network->id;
		}

		if ( ! is_numeric( $network ) ) {
			return new WP_Error( 'mercator.mapping.invalid_id' );
		}

		$network = absint( $network );

		// Check cache first
		$mappings = wp_cache_get( 'id:' . $network, 'network_mapping' );
		if ( ! empty( $mappings ) ) {
			return static::to_instances( $mappings );
		}

		// Cache missed, fetch from DB
		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->sitemeta . ' WHERE site_id = %d AND meta_key LIKE "' . static::KEY_PREFIX . '%%"', $network ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! $rows ) {
			return null;
		}

		wp_cache_set( 'id:' . $network, $rows, 'network_mapping' );
		return static::to_instances( $rows );
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
		$keys = array();

		// Check cache first
		$not_exists = 0;
		foreach ( $domains as $domain ) {
			$key = static::key_for_domain( $domain );
			$row = wp_cache_get( 'domain:' . $key, 'network_mapping' );
			if ( ! empty( $data ) && $data !== 'notexists' ) {
				return static::to_instance( $row );
			}
			elseif ( $row === 'notexists' ) {
				$not_exists++;
			}

			$keys[] = $key; 
		}
		if ( $not_exists === count( $domains ) ) {
			// Every domain we checked was found in the cache, but doesn't exist
			// so skip the query
			return null;
		}

		$placeholders = array_fill( 0, count( $keys ), '%s' );
		$placeholders_in = implode( ',', $placeholders );

		// Prepare the query
		$query = "SELECT * FROM {$wpdb->sitemeta} WHERE meta_key IN ($placeholders_in) ORDER BY CHAR_LENGTH(meta_value) DESC LIMIT 1";
		$query = $wpdb->prepare( $query, $keys );

		// Suppress errors in case the table doesn't exist
		$suppress = $wpdb->suppress_errors();
		$rows = $wpdb->get_results( $query );
		$wpdb->suppress_errors( $suppress );

		if ( empty( $rows ) ) {
			// Cache that it doesn't exist
			foreach ( $domains as $domain ) {
				$key = static::key_for_domain( $domain );
				wp_cache_set( 'domain:' . $key, 'notexists', 'network_mapping' );
			}

			return null;
		}

		// Grab the longest domain we can
		usort( $rows, array( get_called_class(), 'sort_rows_by_domain_length' ) );
		$row = array_pop( $rows );

		wp_cache_set( 'domain:' . $row->meta_key, $row, 'network_mapping' );

		return static::to_instance( $row );
	}

	/**
	 * Get mapping by domain, but filter to ensure only active mapped domains are returned
	 *
	 * @param string|array $domains Domain(s) to match against
	 * @return Mapping|null Mapping on success, or null if no mapping found
	 */
	public static function get_active_by_domain( $domains ) {
		$mapped = array();

		foreach ( $domains as $domain ) {
			$single_mapped = self::get_by_domain( array( $domain ) );

			if ( $single_mapped && ! is_wp_error( $single_mapped ) && $single_mapped->is_active() ) {
				$mapped[] = $single_mapped;
			}
		}

		// Grab the longest domain we can
		usort( $mapped, function( $a, $b ) {
			return strlen( $a->get_domain() ) - strlen( $b->get_domain() );
		} );

		return array_pop( $mapped );
	}

	/**
	 * Compare mapping rows by domain length
	 *
	 * Comparison callback for `usort`, matches result format of `strcmp`
	 * @param stdClass $a First row
	 * @param stdClass $b Second row
	 * @return int <0 if $a is "less" (shorter), 0 if equal, >0 if $a is "more" (longer)
	 */
	protected static function sort_rows_by_domain_length( $a, $b ) {
		$a_data = unserialize( $a->meta_value );
		$b_data = unserialize( $b->meta_value );

		// Compare by string length; return <0 if $a is shorter, 0 if equal, >0
		// if $a is longer
		return strlen( $a_data->domain ) - strlen( $b_data->domain );
	}

	/**
	 * Create a new domain mapping
	 *
	 * @param $site Site ID, or site object from {@see get_blog_details}
	 * @return bool
	 */
	public static function create( $network, $domain, $active = false ) {
		global $wpdb;

		// Allow passing a site object in
		if ( is_object( $network ) && isset( $network->network_id ) ) {
			$network = $network->network_id;
		}

		if ( ! is_numeric( $network ) ) {
			return new WP_Error( 'mercator.mapping.invalid_id' );
		}

		$network = absint( $network );
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
			if ( $network !== $existing->get_network_id() ) {
				// ...and points to another site
				return new WP_Error( 'mercator.mapping.domain_exists' );
			}

			// ...and points to this site, so nothing to do
			return $existing;
		}

		// Create the mapping!
		$key = static::key_for_domain( $domain );
		$data = (object) array(
			'domain' => $domain,
			'active' => $active,
		);
		$result = $wpdb->insert(
			$wpdb->sitemeta,
			array( 'site_id' => $network, 'meta_key' => $key, 'meta_value' => serialize( $data ) ),
			array( '%d', '%s', '%s' )
		);
		if ( empty( $result ) ) {
			// Check that the table exists...
			if ( check_table() === 'created' ) {
				// Table created, try again
				return static::create( $network, $domain, $active );
			}

			return new WP_Error( 'mercator.mapping.insert_failed' );
		}

		// Ensure the cache is flushed
		wp_cache_delete( 'id:' . $network, 'network_mapping' );
		wp_cache_delete( 'domain:' . $key, 'network_mapping' );

		return static::get( $wpdb->insert_id );
	}

	/**
	 * Get the meta key for a given domain
	 *
	 * @param string $domain Domain name
	 * @return string Meta key corresponding to the domain
	 */
	protected static function key_for_domain( $domain ) {
		return static::KEY_PREFIX . sha1( $domain );
	}
}
