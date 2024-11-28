<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
    private $api_url;
    private $api_token;
    private $table_id;
    private $per_page = 20; // Number of items per page

    public function __construct() {
        $this->api_url = get_option('baserow_api_url');
        $this->api_token = get_option('baserow_api_token');
        $this->table_id = get_option('baserow_table_id');
    }

    public function get_categories() {
        Baserow_Logger::info("Fetching categories - Starting");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Build URL without field restriction to get all data
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=100";
        
        Baserow_Logger::debug("Category API Request URL: " . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Baserow_Logger::error("API request failed: {$error_message}");
            return new WP_Error('api_error', $error_message);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        Baserow_Logger::debug("API Response Status: " . $status_code);
        Baserow_Logger::debug("API Response Body: " . $body);

        if ($status_code !== 200) {
            $error_message = "API returned status code {$status_code}";
            Baserow_Logger::error($error_message);
            return new WP_Error('api_error', $error_message);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = "Failed to parse JSON response: " . json_last_error_msg();
            Baserow_Logger::error($error_message);
            return new WP_Error('json_error', $error_message);
        }

        // Extract categories
        $categories = array();
        if (!empty($data['results'])) {
            foreach ($data['results'] as $product) {
                if (!empty($product['Category'])) {
                    Baserow_Logger::debug("Processing category: " . $product['Category']);
                    
                    // Add the full category path
                    if (!in_array($product['Category'], $categories)) {
                        $categories[] = $product['Category'];
                    }

                    // Split the category path and add each level
                    $parts = explode(' > ', $product['Category']);
                    $current_path = '';
                    foreach ($parts as $part) {
                        $current_path = $current_path ? $current_path . ' > ' . $part : $part;
                        if (!in_array($current_path, $categories)) {
                            $categories[] = $current_path;
                        }
                    }
                }
            }
        }

        // Sort categories
        sort($categories);

        Baserow_Logger::info("Found " . count($categories) . " unique categories");
        Baserow_Logger::debug("Categories: " . print_r($categories, true));

        return $categories;
    }

    // [Rest of the class remains unchanged...]
