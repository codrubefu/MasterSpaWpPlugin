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

		MasterSpaLogHelper::write(
			"POST $url\nHeaders: " . wp_json_encode( self::headers_for_log( $all_headers ) )
			. "\nData: " . self::describe_payload( $data, $body )
			. "\nResponse: " . self::truncate( $raw, 2000 )
			. "\nHTTP Code: $http_code\nError: $error\n"
		);

		return array(
			'response'  => $raw,
			'http_code' => $http_code,
			'code'      => $http_code,
			'error'     => '' !== $error ? $error : null,
		);
	}

	/**
	 * Header lines for the log, with secrets masked.
	 *
	 * @param array $headers name => value.
	 * @return string[]
	 */
	public static function headers_for_log( $headers ) {
		$secret_names = array( 'x-api-secret', 'authorization', 'cookie', 'idempotency-key' );
		$lines        = array();
		foreach ( $headers as $name => $value ) {
			if ( in_array( strtolower( $name ), $secret_names, true ) && '' !== (string) $value ) {
				$value = '***';
			}
			$lines[] = $name . ': ' . $value;
		}
		return $lines;
	}

	/**
	 * Short description of the payload for the log: no personal data.
	 *
	 * @param mixed  $data Payload.
	 * @param string $body Encoded body.
	 * @return string
	 */
	public static function describe_payload( $data, $body ) {
		$parts = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['id'] ) ) {
				$parts[] = 'order #' . $data['id'];
			}
			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$parts[] = count( $data['items'] ) . ' items';
			}
			if ( isset( $data['total'] ) ) {
				$parts[] = 'total ' . $data['total'];
			}
		}
		$parts[] = strlen( (string) $body ) . ' bytes';
		return implode( ', ', $parts );
	}

	/**
	 * Truncate long strings for the log.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function truncate( $text, $max ) {
		$text = (string) $text;
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '… [' . strlen( $text ) . ' bytes]' : $text;
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
