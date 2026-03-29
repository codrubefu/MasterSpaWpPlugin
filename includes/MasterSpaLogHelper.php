<?php
/**
 * MasterSpaLogHelper
 * Simple static helper for logging plugin events
 */
class MasterSpaLogHelper {
    const LOG_FILE = 'masterspa.log';

    /**
     * Write a message to the log file and database
     * @param string $message
     */
    public static function write($message) {
        global $wpdb;
        $date = date('Y-m-d H:i:s');
        // Try to pretty-print JSON blocks in the message
        $message = self::pretty_print_json_in_message($message);

        // Log to database only
        $table = $wpdb->prefix . 'masterspa_logs';
        // Create table if not exists (simple version)
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_time DATETIME NOT NULL,
            message LONGTEXT NOT NULL
        ) $charset_collate;";
        $wpdb->query($sql);
        // Insert log
        $wpdb->insert($table, [
            'log_time' => $date,
            'message' => $message
        ]);
    }

    /**
     * Pretty print JSON found in the message (for log readability)
     */
    private static function pretty_print_json_in_message($message) {
        // Find all JSON blocks and pretty print them
        return preg_replace_callback('/({.*?})/s', function($matches) {
            $json = $matches[1];
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            }
            return $json;
        }, $message);
    }

    /**
     * Get the log file path
     * @return string
     */
    public static function get_log_path() {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/masterspa-logs';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . self::LOG_FILE;
    }

    /**
     * Get the log contents
     * @return string
     */
    public static function get_log() {
        global $wpdb;
        $table = $wpdb->prefix . 'masterspa_logs';
        // Get last 1000 logs (or all if less)
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 1000");
        if (!$results) return '';
        $output = "";
        foreach (array_reverse($results) as $row) {
            $output .= "\n==================== [{$row->log_time}] ====================\n{$row->message}\n================================================\n";
        }
        return $output;
    }

    /**
     * Clear the log file
     */
    public static function clear_log() {
    global $wpdb;
    $table = $wpdb->prefix . 'masterspa_logs';
    $wpdb->query("TRUNCATE TABLE $table");
    }

    /**
     * Log a message with a specific level
     * @param string $level
     * @param string $message
     * @param mixed $context
     */
    public static function log($level, $message, $context = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'masterspa_logs';
        $wpdb->insert($table, [
            'log_time' => current_time('mysql'),
            'log_level' => $level,
            'log_message' => $message,
            'log_context' => $context ? maybe_serialize($context) : null
        ]);
    }

    /**
     * Get logs with pagination
     * @param int $paged
     * @param int $per_page
     * @return array
     */
    public static function get_logs($paged = 1, $per_page = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'masterspa_logs';
        $offset = ($paged - 1) * $per_page;
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        return [
            'logs' => $logs,
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $paged,
            'last_page' => ceil($total / $per_page)
        ];
    }
}
