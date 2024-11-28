<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
    private string $api_url;
    private string $api_token;
    private string $table_id;
    private int $per_page = 20;

    public function __construct() {
        $this->api_url = get_option('baserow_api_url');
        $this->api_token = get_option('baserow_api_token');
        $this->table_id = get_option('baserow_table_id');
    }

    /**
     * Search products in Baserow with various filters
     *
     * @param array $args {
     *     Optional. Array of search parameters.
     *     @type string $search      Search term for product name/description
     *     @type string $sku         Product SKU to search for
     *     @type string $category    Category to filter by
     *     @type int    $page        Page number for pagination
     *     @type string $sort_by     Field to sort by
     *     @type string $sort_order  Sort order (asc/desc)
     * }
     * @return array|WP_Error Array of products or WP_Error on failure
     */
    public function search_products(array $args = []): array|WP_Error {
        Baserow_Logger::info("Starting product search", ['args' => $args]);

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration missing");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        // Validate and sanitize input parameters
        $defaults = [
            'search' => '',
            'sku' => '',
            'category' => '',
            'page' => 1,
            'sort_by' => 'id',
            'sort_order' => 'asc'
        ];
        
        $args = wp_parse_args($args, $defaults);
        $args = $this->sanitize_search_args($args);

        // Build search query parameters
        $query_params = [
            'user_field_names' => 'true',
            'size' => $this->per_page,
            'page' => max(1, intval($args['page']))
        ];

        // Add search filters
        $filters = [];
        if (!empty($args['search'])) {
            $filters[] = $this->build_search_filter('Name', 'contains', $args['search']);
            $filters[] = $this->build_search_filter('Description', 'contains', $args['search']);
        }
        if (!empty($args['sku'])) {
            $filters[] = $this->build_search_filter('SKU', 'contains', $args['sku']);
        }
        if (!empty($args['category'])) {
            $filters[] = $this->build_search_filter('Category', 'contains', $args['category']);
        }

        // Combine filters if multiple exist
        if (count($filters) > 1) {
            $query_params['filter_type'] = 'OR';
            $query_params['filters'] = json_encode($filters);
        } elseif (count($filters) === 1) {
            $query_params['filters'] = json_encode($filters[0]);
        }

        // Add sorting
        if (!empty($args['sort_by'])) {
            $query_params['order_by'] = $args['sort_by'];
            $query_params['order_direction'] = strtoupper($args['sort_order']);
        }

        // Build URL with query parameters
        $url = add_query_arg(
            $query_params,
            trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/"
        );

        Baserow_Logger::debug("Search API Request URL: " . $url);

        // Make API request
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Baserow_Logger::error("API request failed", ['error' => $error_message]);
            return new WP_Error('api_error', $error_message);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        Baserow_Logger::debug("API Response", [
            'status' => $status_code,
            'body' => $body
        ]);

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

        // Format response
        $result = [
            'products' => $data['results'] ?? [],
            'total' => $data['count'] ?? 0,
            'page' => $args['page'],
            'pages' => ceil(($data['count'] ?? 0) / $this->per_page),
            'per_page' => $this->per_page
        ];

        Baserow_Logger::info("Search completed", [
            'total_results' => $result['total'],
            'page' => $result['page'],
            'total_pages' => $result['pages']
        ]);

        return $result;
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

    /**
     * Sanitize search arguments
     *
     * @param array $args Search arguments to sanitize
     * @return array Sanitized arguments
     */
    private function sanitize_search_args(array $args): array {
        return [
            'search' => sanitize_text_field($args['search']),
            'sku' => sanitize_text_field($args['sku']),
            'category' => sanitize_text_field($args['category']),
            'page' => max(1, intval($args['page'])),
            'sort_by' => in_array($args['sort_by'], ['id', 'Name', 'SKU', 'Category']) 
                ? $args['sort_by'] 
                : 'id',
            'sort_order' => in_array(strtolower($args['sort_order']), ['asc', 'desc']) 
                ? strtolower($args['sort_order']) 
                : 'asc'
        ];
    }

    /**
     * Build a search filter for Baserow API
     *
     * @param string $field Field to filter on
     * @param string $type Type of filter
     * @param string $value Filter value
     * @return array Filter array
     */
    private function build_search_filter(string $field, string $type, string $value): array {
        return [
            'field' => $field,
            'type' => $type,
            'value' => $value
        ];
    }
}
