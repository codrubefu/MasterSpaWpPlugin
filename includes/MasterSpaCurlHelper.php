<?php
/**
 * MasterSpaCurlHelper
 *
 * Sends JSON to the MasterSpa server. Uses the WordPress HTTP API (proxy, SSL and
 * debug hooks); the class name and return shape are kept for existing callers.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MasterSpaCurlHelper {

	/**
	 * Send JSON data to a URL using POST.
	 *
	 * @param string $url     URL.
	 * @param array  $data    Payload.
	 * @param array  $headers Extra headers: either "Name: value" strings or name => value pairs.
	 * @return array{response: string, http_code: int, code: int, error: string|null}
	 */
	public static function post_json( $url, $data, $headers = array() ) {
		$settings   = get_option( 'masterspa_settings', array() );
		$api_secret = isset( $settings['auth_header'] ) ? $settings['auth_header'] : '';

		$all_headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $api_secret ) ) {
			$all_headers['X-API-Secret'] = $api_secret;
		}
		$all_headers = array_merge( $all_headers, self::normalize_headers( $headers ) );

		// Timeouts: an unreachable server must not block the request for long.
		$timeout = isset( $settings['order_timeout'] ) ? (int) $settings['order_timeout'] : 15;
		$timeout = max( 5, min( 60, $timeout ) );

		$body     = wp_json_encode( $data );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $all_headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$http_code = 0;
			$raw       = '';
			$error     = $response->get_error_message();
		} else {
			$http_code = (int) wp_remote_retrieve_response_code( $response );
			$raw       = (string) wp_remote_retrieve_body( $response );
			$error     = '';
		}

		$log_header_lines = array();
		foreach ( $all_headers as $name => $value ) {
			$log_header_lines[] = $name . ': ' . $value;
		}
		MasterSpaLogHelper::write( "POST $url\nHeaders: " . wp_json_encode( $log_header_lines ) . "\nData: " . $body . "\nResponse: $raw\nHTTP Code: $http_code\nError: $error\n" );

		return array(
			'response'  => $raw,
			'http_code' => $http_code,
			'code'      => $http_code,
			'error'     => '' !== $error ? $error : null,
		);
	}

	/**
	 * Accept both "Name: value" strings and name => value pairs.
	 *
	 * @param array $headers Headers.
	 * @return array name => value
	 */
	public static function normalize_headers( $headers ) {
		$out = array();
		foreach ( (array) $headers as $name => $value ) {
			if ( is_int( $name ) ) {
				$parts = explode( ':', (string) $value, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				$name  = $parts[0];
				$value = $parts[1];
			}
			$name = trim( (string) $name );
			if ( '' !== $name ) {
				$out[ $name ] = trim( (string) $value );
			}
		}
		return $out;
	}
}
