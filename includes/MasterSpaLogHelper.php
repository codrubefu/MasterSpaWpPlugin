<?php
/**
 * MasterSpaLogHelper
 * Helper for retrieving and clearing plugin logs stored by MasterSpa_Logger
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MasterSpaLogHelper {
    public static function get_logs( $paged = 1, $per_page = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'masterspa_import_logs';
        $offset = max(0, ($paged - 1) * $per_page);
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        return array(
            'logs' => $logs,
            'total' => intval( $total ),
            'per_page' => intval( $per_page ),
            'current_page' => intval( $paged ),
            'last_page' => $per_page > 0 ? intval( ceil( $total / $per_page ) ) : 1,
        );
    }

    public static function clear_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'masterspa_import_logs';
        $wpdb->query( "TRUNCATE TABLE $table" );
    }
}
