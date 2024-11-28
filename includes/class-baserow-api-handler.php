<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
    use Baserow_API_Request_Trait;
    use Baserow_Logger_Trait;

    /** @var string */
    private $api_url;
    
    /** @var string */
    private $api_token;
    
    /** @var string */
    private $table_id;
    
    /** @var int */
    private $per_page = 20;
    
    /** @var bool */
    private $is_initialized = false;

    public function __construct() {
        $this->init();
    }

    /**
     * Initialize the API handler with credentials
     *
     * @return bool True if initialization successful, false otherwise
     */
    public function init() {
        $this->api_url = get_option('baserow_api_url', '');
        $this->api_token = get_option('baserow_api_token', '');
        $this->table_id = get_option('baserow_table_id', '');

        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("API configuration missing", array(
                'url_set' => !empty($this->api_url),
                'token_set' => !empty($this->api_token),
                'table_id_set' => !empty($this->table_id)
            ));
            $this->is_initialized = false;
            return false;
        }

        // Validate API URL format
        if (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            $this->log_error("Invalid API URL format", array(
                'url' => $this->api_url
            ));
            $this->is_initialized = false;
            return false;
        }

        // Remove trailing slashes from API URL
        $this->api_url = rtrim($this->api_url, '/');

        $this->is_initialized = true;
        return true;
    }

    /**
     * Check if the API handler is properly initialized
     *
     * @return bool
     */
    public function is_initialized() {
        return $this->is_initialized;
    }

    /**
     * Check if the API handler is properly initialized
     *
     * @return bool
     */
    private function check_initialization() {
        if (!$this->is_initialized) {
            $this->log_error("API Handler not properly initialized");
            return false;
        }
        return true;
    }

    /**
     * Search products in Baserow with various filters
     *
     * @param array $args Search parameters
     * @return array|WP_Error Array of products or WP_Error on failure
     */
    public function search_products($args = array()) {
        if (!$this->check_initialization()) {
            return new WP_Error('not_initialized', 'API Handler not properly initialized. Please check your API settings.');
        }

        $this->log_info("Starting product search", array('args' => $args));

        // Validate and sanitize input parameters
        $defaults = array(
            'search' => '',
            'sku' => '',
            'category' => '',
            'page' => 1,
            'sort_by' => 'id',
            'sort_order' => 'asc'
        );
        
        $args = wp_parse_args($args, $defaults);
        $args = $this->sanitize_search_args($args);

        // Build search query parameters
        $query_params = array(
            'user_field_names' => 'true',
            'size' => $this->per_page,
            'page' => max(1, intval($args['page']))
        );

        // Add search filters
        $filters = array();
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
            "{$this->api_url}/api/database/rows/table/{$this->table_id}/"
        );

        // Make API request
        $response = $this->make_api_request($url, 'GET', null, array(
            'Authorization' => "Token {$this->api_token}"
        ));

        if (is_wp_error($response)) {
            $this->log_error("Search request failed", array(
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        // Format response
        $result = array(
            'products' => isset($response['results']) ? $response['results'] : array(),
            'total' => isset($response['count']) ? $response['count'] : 0,
            'page' => $args['page'],
            'pages' => ceil((isset($response['count']) ? $response['count'] : 0) / $this->per_page),
            'per_page' => $this->per_page
        );

        $this->log_info("Search completed", array(
            'total_results' => $result['total'],
            'page' => $result['page'],
            'total_pages' => $result['pages']
        ));

        return $result;
    }

    /**
     * Get categories from Baserow
     *
     * @return array|WP_Error Array of categories or WP_Error on failure
     */
    public function get_categories() {
        if (!$this->check_initialization()) {
            return new WP_Error('not_initialized', 'API Handler not properly initialized. Please check your API settings.');
        }

        $this->log_info("Fetching categories - Starting");

        $url = "{$this->api_url}/api/database/rows/table/{$this->table_id}/?user_field_names=true&size=100";
        
        $this->log_debug("Category API Request URL: " . $url);

        $response = $this->make_api_request($url, 'GET', null, array(
            'Authorization' => "Token {$this->api_token}"
        ));

        if (is_wp_error($response)) {
            $this->log_error("Category request failed", array(
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        // Extract categories
        $categories = array();
        if (!empty($response['results'])) {
            foreach ($response['results'] as $product) {
                if (!empty($product['Category'])) {
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

        sort($categories);

        $this->log_info("Categories fetched successfully", array(
            'count' => count($categories)
        ));

        return $categories;
    }

    /**
     * Sanitize search arguments
     *
     * @param array $args Search arguments to sanitize
     * @return array Sanitized arguments
     */
    private function sanitize_search_args($args) {
        return array(
            'search' => sanitize_text_field($args['search']),
            'sku' => sanitize_text_field($args['sku']),
            'category' => sanitize_text_field($args['category']),
            'page' => max(1, intval($args['page'])),
            'sort_by' => in_array($args['sort_by'], array('id', 'Name', 'SKU', 'Category')) 
                ? $args['sort_by'] 
                : 'id',
            'sort_order' => in_array(strtolower($args['sort_order']), array('asc', 'desc')) 
                ? strtolower($args['sort_order']) 
                : 'asc'
        );
    }

    /**
     * Build a search filter for Baserow API
     *
     * @param string $field Field to filter on
     * @param string $type Type of filter
     * @param string $value Filter value
     * @return array Filter array
     */
    private function build_search_filter($field, $type, $value) {
        return array(
            'field' => $field,
            'type' => $type,
            'value' => $value
        );
    }
}
