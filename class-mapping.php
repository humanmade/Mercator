<?php
namespace Mercator;

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
	 * Get mapping ID
	 *
	 * @return int Mapping ID
	 */
	public function get_id() {
		return $this->data->id;
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
		return $this->site;
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
	 * Set the domain for the mapping
	 *
	 * @param string $domain Domain name
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function set_domain( $domain ) {
		global $wpdb;

		// Is this the current domain?
		if ( $this->data->domain === $domain ) {
			return false;
		}

		// Does this domain exist already?
		$existing = static::get_by_domain( $domain );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! empty( $existing ) ) {
			// Domain exists already and points to another site
			return new \WP_Error( 'mercator.mapping.domain_exists' );
		}

		$result = $wpdb->update(
			$wpdb->dmtable,
			array( 'domain' => $domain ),
			array( 'blog_id' => $this->site )
		);
		if ( empty( $result ) ) {
			return new \WP_Error( 'mercator.mapping.update_failed' );
		}

		$this->data->domain = $domain;
		wp_cache_set( 'id:' . $site, $this->data, 'domain_mapping' );
		wp_cache_set( 'domain:' . $domain, $this->data, 'domain_mapping' );

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
		foreach ( $domains as $domain ) {
			$data = wp_cache_get( 'domain:' . $domain, 'domain_mapping' );
			if ( ! empty( $data ) && $data !== 'notexists' ) {
				return new static( $data );
			}
			elseif ( $data === 'notexists' ) {
				return null;
			}
		}

		$placeholders = array_fill( 0, count( $domain ), '%s' );
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
	 * @param $site Site ID, or site object from {@see get_blog_details}
	 * @return bool
	 */
	public static function create( $site, $domain ) {
		global $wpdb;

		// Allow passing a site object in
		if ( is_object( $site ) && isset( $site->blog_id ) ) {
			$site = $site->blog_id;
		}

		if ( ! is_numeric( $site ) ) {
			return new \WP_Error( 'mercator.mapping.invalid_id' );
		}

		$site = absint( $site );

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
			if ( $site !== $mapping->get_site_id() ) {
				// ...and points to another site
				return new \WP_Error( 'mercator.mapping.domain_exists' );
			}

			// ...and points to this site, so nothing to do
			return $existing;
		}

		// Create the mapping!
		$result = $wpdb->insert(
			$wpdb->dmtable,
			array( 'blog_id' => $site, 'domain' => $domain ),
			array( '%d', '%s' )
		);
		if ( empty( $result ) ) {
			// Check that the table exists...
			if ( check_table() === 'created' ) {
				// Table created, try again
				return static::create( $site, $domain );
			}

			return new \WP_Error( 'mercator.mapping.insert_failed' );
		}

		// Ensure the cache is flushed
		wp_cache_delete( 'id:' . $site, 'domain_mapping' );

		return static::get( $wpdb->insert_id );
	}
}
