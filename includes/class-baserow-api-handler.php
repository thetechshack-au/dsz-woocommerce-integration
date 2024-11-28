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

        $categories = array();
        $page = 1;
        $has_more = true;

        while ($has_more) {
            $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&fields=Category&page={$page}&size=100";
            
            Baserow_Logger::debug("Category API Request URL (page {$page}): {$url}");

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

            // Process categories from this page
            if (!empty($data['results'])) {
                foreach ($data['results'] as $product) {
                    if (!empty($product['Category'])) {
                        Baserow_Logger::debug("Processing category: " . $product['Category']);
                        
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

            // Check if there are more pages
            $total_pages = ceil($data['count'] / 100);
            $has_more = $page < $total_pages;
            $page++;

            Baserow_Logger::debug("Processed page {$page} of {$total_pages}");
        }

        // Sort categories
        sort($categories);

        Baserow_Logger::info("Found " . count($categories) . " unique categories");
        Baserow_Logger::debug("Categories: " . print_r($categories, true));

        return $categories;
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
                $params['filter__Category__equal'] = $category;
            } else {
                // If we only have category, just use equals
                $params['filter__Category__equal'] = $category;
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
