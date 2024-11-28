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

    // [Previous methods remain unchanged...]

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

        // Initialize filters array
        $filters = array();

        // Add search filters if provided
        if (!empty($search_term)) {
            $filters[] = array(
                'type' => 'OR',
                'filters' => array(
                    array('field' => 'Title', 'type' => 'contains', 'value' => $search_term),
                    array('field' => 'SKU', 'type' => 'contains', 'value' => $search_term)
                )
            );
        }

        // Add category filter if provided
        if (!empty($category)) {
            // For exact category match
            $filters[] = array(
                'field' => 'Category',
                'type' => 'equal',
                'value' => $category
            );
        }

        // Combine filters if both search and category are present
        if (count($filters) > 1) {
            $params['filter_type'] = 'AND';
            foreach ($filters as $index => $filter) {
                if (isset($filter['type']) && $filter['type'] === 'OR') {
                    // Handle OR filter group
                    $params['filter_type_' . $index] = 'OR';
                    foreach ($filter['filters'] as $subIndex => $subFilter) {
                        $params['filter__' . $subFilter['field'] . '__' . $subFilter['type'] . '_' . $index . '_' . $subIndex] = $subFilter['value'];
                    }
                } else {
                    // Handle single filter
                    $params['filter__' . $filter['field'] . '__' . $filter['type']] = $filter['value'];
                }
            }
        } elseif (count($filters) === 1) {
            // Handle single filter group or single filter
            $filter = $filters[0];
            if (isset($filter['type']) && $filter['type'] === 'OR') {
                $params['filter_type'] = 'OR';
                foreach ($filter['filters'] as $index => $subFilter) {
                    $params['filter__' . $subFilter['field'] . '__' . $subFilter['type']] = $subFilter['value'];
                }
            } else {
                $params['filter__' . $filter['field'] . '__' . $filter['type']] = $filter['value'];
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

    // [Rest of the class remains unchanged...]
}
