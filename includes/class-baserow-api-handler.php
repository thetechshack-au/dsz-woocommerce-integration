<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
    private $api_url;
    private $api_token;
    private $table_id;
    private $per_page = 20;

    public function __construct() {
        $this->api_url = get_option('baserow_api_url');
        $this->api_token = get_option('baserow_api_token');
        $this->table_id = get_option('baserow_table_id');
    }

    public function get_categories() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Ensure URL has trailing slash and build endpoint
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=100";
        
        Baserow_Logger::debug("Making categories request to: " . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Categories API error: " . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("Categories API status error: " . $status_code);
            Baserow_Logger::error("Response body: " . $body);
            return new WP_Error('api_error', "API returned status code {$status_code}: " . $body);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Categories JSON parse error: " . json_last_error_msg());
            return new WP_Error('json_error', "Failed to parse JSON response");
        }

        $categories = array();
        if (!empty($data['results'])) {
            foreach ($data['results'] as $product) {
                if (!empty($product['Category'])) {
                    $category = trim($product['Category']);
                    if (!in_array($category, $categories)) {
                        $categories[] = $category;
                    }
                }
            }
            sort($categories);
            Baserow_Logger::debug("Found " . count($categories) . " unique categories");
        }

        return $categories;
    }

    public function get_product($product_id) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
        
        Baserow_Logger::debug("Making product request to: " . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Product API error: " . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("Product API status error: " . $status_code);
            Baserow_Logger::error("Response body: " . $body);
            return new WP_Error('api_error', "API returned status code {$status_code}: " . $body);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Product JSON parse error: " . json_last_error_msg());
            return new WP_Error('json_error', "Failed to parse JSON response");
        }

        return $data;
    }

    public function update_product($product_id, $data) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
        
        $formatted_data = array();
        foreach ($data as $key => $value) {
            if ($key === 'imported_to_woo') {
                $formatted_data[$key] = $value ? 'true' : 'false';
            } else if ($key === 'woo_product_id') {
                $formatted_data[$key] = (int)$value;
            } else {
                $formatted_data[$key] = $value;
            }
        }

        Baserow_Logger::debug("Making update request to: " . $url, array('data' => $formatted_data));

        $args = array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($formatted_data),
            'timeout' => 30,
            'data_format' => 'body'
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Baserow_Logger::error("Update API error: " . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("Update API status error: " . $status_code);
            Baserow_Logger::error("Response body: " . $body);
            return new WP_Error('api_error', "API returned status code {$status_code}: " . $body);
        }

        $updated_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Update JSON parse error: " . json_last_error_msg());
            return new WP_Error('json_error', "Failed to parse JSON response");
        }

        return $updated_data;
    }

    public function search_products($search_term = '', $category = '', $page = 1) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Build base URL with page-based pagination
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size={$this->per_page}&page={$page}";
        
        // Add search parameter if provided
        if (!empty($search_term)) {
            $url .= '&search=' . urlencode($search_term);
        }

        // Add category filter if provided
        if (!empty($category)) {
            // Extract the last part of the category path for contains search
            $category_parts = explode(' > ', $category);
            $search_term = end($category_parts);
            
            // Use contains filter with proper encoding
            $url .= '&filter__Category__contains=' . rawurlencode($search_term);
            Baserow_Logger::debug("Search URL with category filter: " . $url);
        }

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Search API error: " . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("Search API status error: " . $status_code);
            Baserow_Logger::error("Response body: " . $body);
            return new WP_Error('api_error', "API returned status code {$status_code}: " . $body);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Search JSON parse error: " . json_last_error_msg());
            return new WP_Error('json_error', "Failed to parse JSON response");
        }

        // Add pagination info to the response
        $data['pagination'] = array(
            'current_page' => $page,
            'total_pages' => ceil($data['count'] / $this->per_page),
            'total_items' => $data['count']
        );

        if (!empty($category)) {
            Baserow_Logger::debug("Search results for category '" . $category . "': " . count($data['results']) . " products found");
        }

        return $data;
    }

    public function test_connection() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("Test connection failed: Missing configuration");
            return false;
        }

        // Use a minimal request to test the connection
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=1";
        
        Baserow_Logger::debug("Testing connection with URL: " . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Test connection error: " . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("Test connection failed with status: " . $status_code);
            Baserow_Logger::error("Response body: " . $body);
            return false;
        }

        Baserow_Logger::debug("Test connection successful");
        return true;
    }
}
