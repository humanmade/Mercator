<?php

namespace Mercator\Utils;

use WP_Error;

/**
 * Verify a custom domain
 *
 * @param string          $host Hostname of the domain to check
 * @param string|string[] $base Base domain(s) or IPs to allow
 * @param string          $type Whether to check CNAME or A record, accepts 'CNAME' or 'A'
 * @return boolean|WP_Error True on succcess, WP_Error otherwise
 */
function verify_domain( $host, $base, $type = 'CNAME' ) {
	$type = ( 'CNAME' === strtoupper( $type ) ) ? 'CNAME' : 'A';

	$dns_resp = $record = dns_get_record( $host, 'CNAME' === $type ? DNS_CNAME : DNS_A );
	$record   = ! empty( $record[0]['target'] ) ? $record[0]['target'] : '';
	$base     = (array) $base;
	
	$expected_record = sprintf(
		_n( 'You need one %1$s record set to %2$s', 'You need one %1$s record set to any of the following: %2$s', count( $base ) ),
		$type,
		implode( ', ', $base )
	);

	if ( ! $record ) {
		return new WP_Error(
			4,
			sprintf(
				__( 'Your domain does not have the %1$s record set.', 'mercator' ),
				$type
			) . "\n\n$expected_record"
		);
	}

	$is_valid = false;
	foreach ( $base as $allowed ) {
		if ( strpos( $record, $allowed ) !== false ) {
			$is_valid = true;
		}
	}
	if ( ! $is_valid ) {
		return new WP_Error(
			4,
			sprintf(
				__( 'The %1$s for your domain is set incorrectly. The %1$s record is currently %2$s.', 'mercator' ),
				$type,
				$record
			) . "\n\n$expected_record"
		);
	}

	$url = sprintf( 'http://%s/', $host );

	$http_check = wp_remote_get( esc_url( $url . '?_mercator_check=' . wp_create_nonce( 'mercator-api' ) ) );

	if ( is_wp_error( $http_check ) ) {
		return new WP_Error(
			1,
			sprintf(
				__( 'We could not access your domain: %s', 'mercator' ),
				'"' . $http_check->get_error_message() . '"'
			)
		);
	}

	if ( empty( $http_check['response']['code'] ) ) {
		return new WP_Error(
			2,
			__( 'Your domain is not responding to requests.', 'mercator' )
		);
	}

	if ( (int) $http_check['response']['code'] != 200 ) {
		return new WP_Error(
			3,
			sprintf(
				__( "Your domain responded with a %d header.", 'mercator' ),
				$http_check['response']['code']
			)
		);
	}

	if ( trim( wp_remote_retrieve_body( $http_check ) ) !== 'mercator' ) {
		return new WP_Error(
			3,
			__( "This domain does not appear to be linked to the network.", 'mercator' )
		);
	}

	return true;
}

function sanitize_domain( $domain ) {
	$segments = explode( '.', $domain );
	if ( empty( $segments ) ) {
		return null;
	}

	foreach ( $segments as $segment ) {
		$segment = sanitize_domain_segment( $segment );

		if ( empty( $segment ) ) {
			return null;
		}
	}

	$sanitized = implode( '.', $domain );

	// We can't safely sanitize this
	if ( strlen( $sanitized ) > 255 ) {
		return null;
	}

	return $sanitized;
}

function sanitize_domain_segment( $segment ) {
	// First, strip control characters
	$segment = wp_kses_no_null( $segment );

	// Convert down to ASCII
	$segment = remove_accents( $segment );
	$segment = sanitize_title_with_dashes( $segment, null, 'save' );

	if ( strlen( $segment ) < 4 ) {
		return null;
	}

	// Truncate subdomains that are too long
	$segment = trim_segment_length( $segment, 63 );

	return $segment;
}

/**
 * Trim a domain segment down to size
 *
 * @param string  $segment Segment to trim
 * @param integer $length  Required length (63 by default, maximum for subdomains)
 * @return string Trimmed segment (if longer than `$length`)
 */
function trim_segment_length( $segment, $length = 63 ) {
	if ( strlen( $segment ) > $length ) {
		$segment = substr( $segment, 0, $length );
		$segment = rtrim( $segment, '-' );
	}

	return $segment;
}