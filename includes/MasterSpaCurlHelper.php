<?php
// MasterSpaCurlHelper.php
// Helper for sending JSON data via cURL (used in MasterSpaWpPlugin)

class MasterSpaCurlHelper {
    /**
     * Send JSON data to a URL using POST
     * @param string $url
     * @param array $data
     * @return array|WP_Error
     */
    public static function post_json($url, $data) {
        if (empty($url)) return new WP_Error('no_url', 'No URL provided');
        $args = array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($data),
            'timeout' => 30,
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        return array(
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        );
    }
}
