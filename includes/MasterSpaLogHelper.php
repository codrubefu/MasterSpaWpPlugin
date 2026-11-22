<?php
/**
 * MasterSpaLogHelper
 *
 * Logs HTTP exchanges with the MasterSpa server. Kept as a thin wrapper so existing
 * callers keep working; entries go to the unified MasterSpa_Logger table (type "http").
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MasterSpaLogHelper {

	/**
	 * Write a message to the unified log.
	 *
	 * @param string $message Message.
	 * @param string $type    Log type.
	 */
	public static function write( $message, $type = 'http' ) {
		if ( ! class_exists( 'MasterSpa_Logger' ) ) {
			require_once dirname( __FILE__ ) . '/class-logger.php';
		}
		MasterSpa_Logger::log( $type, self::pretty_print_json_in_message( (string) $message ) );
	}

	/**
	 * Pretty print JSON found in the message (for log readability)
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function pretty_print_json_in_message( $message ) {
		$result = preg_replace_callback(
			'/({.*?})/s',
			function ( $matches ) {
				$decoded = json_decode( $matches[1], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					return json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
				return $matches[1];
			},
			$message
		);
		return null === $result ? $message : $result;
	}
}
