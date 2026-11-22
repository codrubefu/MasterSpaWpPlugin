<?php
/**
 * Runs when the plugin is deleted from WordPress (not on deactivation).
 *
 * Removes the plugin's options, log tables, scheduled events and background jobs.
 * Products, orders and their meta are kept.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'masterspa_settings', 'masterspa_last_import', 'masterspa_import_queue', 'masterspa_db_version' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'masterspa_scheduled_import' );
wp_clear_scheduled_hook( 'masterspa_retry_order_sync' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'masterspa' );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masterspa_import_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masterspa_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
