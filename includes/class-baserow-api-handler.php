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
        Baserow_Logger::info("Fetching unique categories");

        // Try to get cached categories first
        $cached_categories = get_transient('baserow_categories');
        if ($cached_categories !== false) {
            Baserow_Logger::debug("Returning cached categories");
            return $cached_categories;
        }

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $categories = array();
        $page = 1;
        $has_more = true;

        while ($has_more) {
            // Request only the Category field to minimize data transfer
            $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&fields=Category&page={$page}&size=100";
            
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

            // Process categories from this page
            if (!empty($data['results'])) {
                foreach ($data['results'] as $product) {
                    if (!empty($product['Category'])) {
                        // Split category path and add each level
                        $category_path = explode(' > ', $product['Category']);
                        $current_path = '';
                        
                        foreach ($category_path as $level) {
                            $current_path = $current_path ? $current_path . ' > ' . $level : $level;
                            if (!in_array($current_path, $categories)) {
                                $categories[] = $current_path;
                            }
                        }
                    }
                }
            }

            // Check if there are more pages
            $total_pages = ceil($data['count'] / 100);
            $has_more = $page < $total_pages;
            $page++;

            // Log progress
            Baserow_Logger::debug("Processed page {$page} of {$total_pages}");
        }

        // Sort categories
        sort($categories);

        // Cache the results for 1 hour
        set_transient('baserow_categories', $categories, HOUR_IN_SECONDS);

        Baserow_Logger::info("Successfully retrieved " . count($categories) . " categories");
        return $categories;
    }

    public function get_product($product_id) {
        Baserow_Logger::info("Fetching product with ID: {$product_id}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
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
        
        // Format data for Baserow
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

        Baserow_Logger::debug("API Update URL: {$url}");
        Baserow_Logger::debug("Update data (formatted): " . print_r($formatted_data, true));

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

        Baserow_Logger::debug("Complete request: " . print_r($args, true));

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Baserow_Logger::error("API update failed: {$error_message}");
            return new WP_Error('api_error', $error_message);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        Baserow_Logger::debug("API Update Response Status: {$status_code}");
        Baserow_Logger::debug("API Update Response Headers: " . print_r($headers, true));
        Baserow_Logger::debug("API Update Response Body: " . $body);

        if ($status_code !== 200) {
            $error_message = "API returned status code {$status_code}. Response: " . $body;
            Baserow_Logger::error($error_message);
            return new WP_Error('api_error', $error_message);
        }

        $updated_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = "Failed to parse JSON response: " . json_last_error_msg();
            Baserow_Logger::error($error_message);
            return new WP_Error('json_error', $error_message);
        }

        if (!isset($updated_data['imported_to_woo']) || $updated_data['imported_to_woo'] !== true) {
            $error_message = "Update verification failed. Response data: " . print_r($updated_data, true);
            Baserow_Logger::error($error_message);
            return new WP_Error('update_verification_failed', $error_message);
        }

        // Clear categories cache after update
        delete_transient('baserow_categories');

        Baserow_Logger::info("Successfully updated product in Baserow");
        return $updated_data;
    }

    public function search_products($search_term = '', $category = '', $page = 1) {
        Baserow_Logger::info("Searching products - Term: {$search_term}, Category: {$category}, Page: {$page}");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Define required fields to minimize data transfer
        $required_fields = array(
            'id',
            'Title',
            'SKU',
            'Category',
            'Price',
            'Cost Price',
            'RrpPrice',
            'Image 1',
            'DI',
            'au_free_shipping',
            'new_arrival',
            'imported_to_woo',
            'woo_product_id'
        );

        // Build base URL with essential parameters
        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?";
        
        // Add query parameters
        $params = array(
            'user_field_names' => 'true',
            'size' => $this->per_page,
            'page' => max(1, intval($page)),
            'fields' => implode(',', $required_fields),
            'order_by' => 'Title'
        );

        // Add search filters
        if (!empty($search_term)) {
            // Search in both Title and SKU fields
            $params['filter_type'] = 'OR';
            $params['filter__Title__contains'] = urlencode($search_term);
            $params['filter__SKU__contains'] = urlencode($search_term);
        }

        // Add category filter if provided
        if (!empty($category)) {
            $params['filter__Category__equal'] = urlencode($category);
            // If both search and category are present, adjust the filter type
            if (!empty($search_term)) {
                $params['filter_type'] = 'AND';
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

        // Process and format the results
        if (!empty($data['results'])) {
            foreach ($data['results'] as &$product) {
                // Format prices
                $product['Price'] = !empty($product['Price']) ? number_format((float)$product['Price'], 2, '.', '') : '0.00';
                $product['Cost Price'] = !empty($product['Cost Price']) ? number_format((float)$product['Cost Price'], 2, '.', '') : '0.00';
                $product['RrpPrice'] = !empty($product['RrpPrice']) ? number_format((float)$product['RrpPrice'], 2, '.', '') : $product['Price'];

                // Format status fields
                $product['DI'] = !empty($product['DI']) && $product['DI'] !== 'No' ? 'Yes' : 'No';
                $product['au_free_shipping'] = !empty($product['au_free_shipping']) && $product['au_free_shipping'] !== 'No' ? 'Yes' : 'No';
                $product['new_arrival'] = !empty($product['new_arrival']) && $product['new_arrival'] !== 'No' ? 'Yes' : 'No';

                // Add WooCommerce URL if product is imported
                if (!empty($product['woo_product_id'])) {
                    $product['woo_url'] = get_edit_post_link($product['woo_product_id'], '');
                }

                // Ensure image URL is set
                $product['image_url'] = !empty($product['Image 1']) ? $product['Image 1'] : '';
            }
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

    public function test_connection() {
        Baserow_Logger::info("Testing API connection");

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return false;
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=1";
        Baserow_Logger::debug("Test connection URL: {$url}");
        
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
        Baserow_Logger::debug("Test connection status code: {$status_code}");

        $success = $status_code === 200;
        Baserow_Logger::info($success ? "Connection test successful" : "Connection test failed");
        
        return $success;
    }
}
