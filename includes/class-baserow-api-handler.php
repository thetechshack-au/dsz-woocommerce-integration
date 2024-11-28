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
        Baserow_Logger::info("Fetching categories");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Request only the Category field to minimize data transfer
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&fields=Category";
        
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

        // Extract unique categories
        $categories = array();
        if (!empty($data['results'])) {
            foreach ($data['results'] as $product) {
                if (!empty($product['Category'])) {
                    if (!in_array($product['Category'], $categories)) {
                        $categories[] = $product['Category'];
                    }
                }
            }
            sort($categories); // Sort alphabetically
        }

        Baserow_Logger::info("Successfully retrieved categories");
        return $categories;
    }

    public function search_products($search_term = '', $category = '', $page = 1) {
        Baserow_Logger::info("Searching products - Term: {$search_term}, Category: {$category}, Page: {$page}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Build base URL with essential parameters
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?";
        
        // Add query parameters
        $params = array(
            'user_field_names' => 'true',
            'size' => $this->per_page,
            'page' => max(1, intval($page))
        );

        // Add search filters if provided
        if (!empty($search_term)) {
            $params['filter_type'] = 'OR';
            $params['filter__Title__contains'] = $search_term;
            $params['filter__SKU__contains'] = $search_term;
        }

        // Add category filter if provided
        if (!empty($category)) {
            if (!empty($search_term)) {
                // If we have both search and category, use AND for the category
                $params['filter_type'] = 'AND';
                $params['filter__Category__contains'] = $category;
            } else {
                // If we only have category, just use contains
                $params['filter__Category__contains'] = $category;
            }
        }

        // Build the final URL
        $url .= http_build_query($params);

        Baserow_Logger::debug("API Request URL: {$url}");

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
        
        Baserow_Logger::debug("API Response Status: {$status_code}");
        Baserow_Logger::debug("API Response Body: {$body}");

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

        // Add pagination info to the response
        $data['pagination'] = array(
            'current_page' => $page,
            'per_page' => $this->per_page,
            'total_pages' => ceil($data['count'] / $this->per_page)
        );

        Baserow_Logger::info("Successfully retrieved search results");
        return $data;
    }

    public function get_product($product_id) {
        Baserow_Logger::info("Fetching product with ID: {$product_id}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
        
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

        if (empty($data)) {
            Baserow_Logger::error("Empty or invalid product data received");
            return new WP_Error('invalid_data', 'Invalid product data received');
        }

        Baserow_Logger::info("Successfully retrieved product data");
        return $data;
    }

    public function update_product($product_id, $data) {
        Baserow_Logger::info("Updating product with ID: {$product_id}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";

        $args = array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'timeout' => 30
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Baserow_Logger::error("API update failed: {$error_message}");
            return new WP_Error('api_error', $error_message);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_message = "API returned status code {$status_code}";
            Baserow_Logger::error($error_message);
            return new WP_Error('api_error', $error_message);
        }

        $updated_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = "Failed to parse JSON response: " . json_last_error_msg();
            Baserow_Logger::error($error_message);
            return new WP_Error('json_error', $error_message);
        }

        Baserow_Logger::info("Successfully updated product in Baserow");
        return $updated_data;
    }

    public function test_connection() {
        Baserow_Logger::info("Testing API connection");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return false;
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=1";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Connection test failed: " . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        $success = $status_code === 200;
        Baserow_Logger::info($success ? "Connection test successful" : "Connection test failed");
        
        return $success;
    }
}
