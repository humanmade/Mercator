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
	public function __construct( $data ) {
		$this->site = $data->blog_id;
		$this->data = $data;
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
	 * Set the domain for the mapping
	 *
	 * @param string $domain Domain name
	 * @return bool|WP_Error True if we updated, false if we didn't need to, or WP_Error if an error occurred
	 */
	public function set_domain( $domain ) {
		global $wpdb;

		// Is this the current domain?
		if ( $this->data['domain'] === $domain ) {
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

		$this->data['domain'] = $domain;
		wp_cache_set( 'id:' . $site, $this->data, 'domain_mapping' );
		wp_cache_set( 'domain:' . $domain, $this->data, 'domain_mapping' );

		return true;
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
		$mapping = wp_cache_get( 'id:' . $site, 'domain_mapping' );
		if ( ! empty( $mapping ) ) {
			return new static( $mapping );
		}

		// Cache missed, fetch from DB
		$mapping = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->dmtable . ' WHERE blog_id = %d', $site ) );
		if ( ! $mapping ) {
			return null;
		}

		wp_cache_set( 'id:' . $site, $mapping, 'domain_mapping' );
		return new static( $mapping );
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

		$mapping = $wpdb->get_row( $query );

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
	public function create( $site, $domain ) {
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

		// Does this blog have a mapping already?
		$existing = static::get_by_site( $site );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! empty( $existing ) ) {
			// Mapping exists, update domain
			$result = $existing->set_domain( $domain );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $existing;
		}

		// Create the mapping!
		$result = $wpdb->insert(
			$wpdb->dmtable,
			array( 'blog_id' => $site, 'domain' => $domain ),
			array( '%d', '%s' )
		);
		if ( empty( $result ) ) {
			return new \WP_Error( 'mercator.mapping.insert_failed' );
		}

		// Ensure the cache is flushed
		wp_cache_delete( 'id:' . $site, 'domain_mapping' );

		return static::get_by_site( $site );
	}
}
