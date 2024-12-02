<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the traits are available
if (!trait_exists('Baserow_Logger_Trait')) {
    require_once dirname(__FILE__) . '/traits/trait-baserow-logger.php';
}

if (!trait_exists('Baserow_API_Request_Trait')) {
    require_once dirname(__FILE__) . '/traits/trait-baserow-api-request.php';
}

class Baserow_API_Handler {
    use Baserow_Logger_Trait;
    use Baserow_API_Request_Trait;

    private $api_url;
    private $api_token;
    private $table_id;
    private $per_page = 20;
    private $csv_path;
    private $data_dir;

    public function __construct() {
        $this->api_url = get_option('baserow_api_url');
        $this->api_token = get_option('baserow_api_token');
        $this->table_id = get_option('baserow_table_id');
        
        // Initialize paths
        $this->data_dir = plugin_dir_path(dirname(__FILE__)) . 'data';
        $this->csv_path = $this->data_dir . '/dsz-categories.csv';
        
        $this->log_debug("API Handler initialized with:", [
            'api_url' => $this->api_url,
            'table_id' => $this->table_id,
            'csv_path' => $this->csv_path
        ]);
    }

    /**
     * Get a single product by ID
     *
     * @param string $product_id
     * @return array|WP_Error
     */
    public function get_product($product_id) {
        $this->log_debug("Starting get_product for ID: {$product_id}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $missing = [];
            if (empty($this->api_url)) $missing[] = 'api_url';
            if (empty($this->api_token)) $missing[] = 'api_token';
            if (empty($this->table_id)) $missing[] = 'table_id';
            
            $this->log_error("API configuration is incomplete. Missing: " . implode(', ', $missing));
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
        
        $this->log_debug("Making API request to: {$url}");

        $headers = [
            'Authorization' => 'Token ' . $this->api_token
        ];

        $result = $this->make_api_request($url, 'GET', null, $headers);

        if (is_wp_error($result)) {
            $this->log_error("API request failed", [
                'error' => $result->get_error_message(),
                'url' => $url
            ]);
            return $result;
        }

        $this->log_debug("API request successful", [
            'product_id' => $product_id,
            'data' => $result
        ]);

        return $result;
    }

    public function make_api_request($url, $method = 'GET', $body = null, $headers = array()) {
        $this->log_debug("Making API request", [
            'url' => $url,
            'method' => $method
        ]);

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );

        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error("API request failed", [
                'error' => $response->get_error_message(),
                'url' => $url
            ]);
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_debug("API response received", [
            'status_code' => $status_code,
            'body_length' => strlen($body)
        ]);

        if ($status_code !== 200) {
            $this->log_error("API request returned non-200 status", [
                'status_code' => $status_code,
                'response' => $body
            ]);
            return new WP_Error(
                'api_error',
                "API request failed with status {$status_code}",
                array('status' => $status_code)
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error("Failed to decode API response", [
                'json_error' => json_last_error_msg(),
                'body' => substr($body, 0, 1000) // Log first 1000 chars of response
            ]);
            return new WP_Error(
                'json_decode_error',
                'Failed to decode API response: ' . json_last_error_msg()
            );
        }

        return $data;
    }

    // ... rest of the class methods remain the same ...
}
