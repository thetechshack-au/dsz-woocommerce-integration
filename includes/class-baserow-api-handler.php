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

        // Original URL that was getting all 35 categories
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=200";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status code {$status_code}");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
        }

        return $categories;
    }

    public function get_product($product_id) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
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
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status code {$status_code}");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status code {$status_code}");
        }

        $updated_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
            // Try 'equal' filter type instead of 'equals'
            $url .= '&filter_type=AND&filter__Category__equal=' . urlencode($category);
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
            return new WP_Error('api_error', "API returned status code {$status_code}");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Search JSON parse error: " . json_last_error_msg());
            return new WP_Error('json_error', "Failed to parse JSON response");
        }

        if (!empty($category)) {
            Baserow_Logger::debug("Search results for category '" . $category . "': " . count($data['results']) . " products found");
        }

        // Add pagination info to the response
        $data['pagination'] = array(
            'current_page' => $page,
            'per_page' => $this->per_page,
            'total_pages' => ceil($data['count'] / $this->per_page)
        );

        return $data;
    }

    public function test_connection() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
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
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code === 200;
    }
}
