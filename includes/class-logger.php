<?php
/**
 * Logger class for MasterSpa WP Plugin
 *
 * Handles logging import activities and errors
 *
 * @package MasterSpaWpPlugin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MasterSpa Logger class
 */
class MasterSpa_Logger {
	
	/**
	 * Database table name
	 *
	 * @var string
	 */
	private static $table_name = 'masterspa_import_logs';
	
	/**
	 * Get table name with prefix
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}
	
	/**
	 * Create logs table
	 */
	public static function create_table() {
		global $wpdb;
		
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			import_date datetime NOT NULL,
			log_type varchar(20) NOT NULL,
			sku varchar(255) DEFAULT NULL,
			product_id bigint(20) UNSIGNED DEFAULT NULL,
			message text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY import_date (import_date),
			KEY log_type (log_type)
		) $charset_collate;";
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
	
	/**
	 * Log a message
	 *
	 * @param string $type    Log type: 'info', 'success', 'warning', 'error'
	 * @param string $message Message to log
	 * @param string $sku     Product SKU (optional)
	 * @param int    $product_id Product ID (optional)
	 * @param string $import_date Import date (optional, defaults to now)
	 */
	public static function log( $type, $message, $sku = null, $product_id = null, $import_date = null ) {
		global $wpdb;
		
		if ( empty( $import_date ) ) {
			$import_date = current_time( 'mysql' );
		}
		
		$wpdb->insert(
			self::get_table_name(),
			array(
				'import_date' => $import_date,
				'log_type'    => sanitize_text_field( $type ),
				'sku'         => $sku ? sanitize_text_field( $sku ) : null,
				'product_id'  => $product_id ? absint( $product_id ) : null,
				'message'     => sanitize_textarea_field( $message ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}
	
	/**
	 * Get recent logs
	 *
	 * @param int    $limit Number of logs to retrieve
	 * @param string $type  Filter by log type (optional)
	 * @return array
	 */
	public static function get_recent_logs( $limit = 100, $type = null ) {
		global $wpdb;
		
		$table_name = self::get_table_name();
		
		$sql = "SELECT * FROM $table_name";
		
		if ( $type ) {
			$sql .= $wpdb->prepare( " WHERE log_type = %s", $type );
		}
		
		$sql .= " ORDER BY created_at DESC LIMIT %d";
		
		return $wpdb->get_results( $wpdb->prepare( $sql, $limit ) );
	}
	
	/**
	 * Get logs for a specific import date
	 *
	 * @param string $import_date Import date
	 * @return array
	 */
	public static function get_logs_by_import_date( $import_date ) {
		global $wpdb;
		
		$table_name = self::get_table_name();
		
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE import_date = %s ORDER BY created_at ASC",
				$import_date
			)
		);
	}
	
	/**
	 * Get import summary statistics
	 *
	 * @param string $import_date Import date
	 * @return array
	 */
	public static function get_import_summary( $import_date ) {
		global $wpdb;
		
		$table_name = self::get_table_name();
		
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT log_type, COUNT(*) as count FROM $table_name WHERE import_date = %s GROUP BY log_type",
				$import_date
			)
		);
		
		$summary = array(
			'created' => 0,
			'updated' => 0,
			'errors'  => 0,
			'total'   => 0,
		);
		
		foreach ( $results as $row ) {
			if ( 'created' === $row->log_type ) {
				$summary['created'] = (int) $row->count;
			} elseif ( 'updated' === $row->log_type ) {
				$summary['updated'] = (int) $row->count;
			} elseif ( 'error' === $row->log_type ) {
				$summary['errors'] = (int) $row->count;
			}
			$summary['total'] += (int) $row->count;
		}
		
		return $summary;
	}
	
	/**
	 * Clear old logs
	 *
	 * @param int $days Number of days to keep
	 */
	public static function clear_old_logs( $days = 30 ) {
		global $wpdb;
		
		$table_name = self::get_table_name();
		$date       = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE created_at < %s",
				$date
			)
		);
	}
	
	/**
	 * Clear all logs
	 */
	public static function clear_all_logs() {
		global $wpdb;
		
		$table_name = self::get_table_name();
		$wpdb->query( "TRUNCATE TABLE $table_name" );
	}
}
