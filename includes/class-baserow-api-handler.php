<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
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
    }

    public function get_categories() {
        // Try CSV first
        Baserow_Logger::debug("Attempting to get categories from CSV");
        $categories = $this->get_categories_from_csv();
        
        // Fall back to API if CSV fails
        if (empty($categories)) {
            Baserow_Logger::debug("No categories from CSV, falling back to API");
            $categories = $this->get_categories_from_api();
        } else {
            Baserow_Logger::debug("Successfully loaded " . count($categories) . " categories from CSV");
            return $categories;
        }

        return $categories;
    }

    private function get_categories_from_csv() {
        if (!file_exists($this->csv_path)) {
            Baserow_Logger::debug("CSV file not found at: " . $this->csv_path);
            return array();
        }

        if (!is_readable($this->csv_path)) {
            Baserow_Logger::error("CSV file exists but is not readable: " . $this->csv_path);
            return array();
        }

        Baserow_Logger::debug("Opening CSV file: " . $this->csv_path);
        $handle = @fopen($this->csv_path, 'r');
        
        if ($handle === false) {
            Baserow_Logger::error("Failed to open CSV file");
            return array();
        }

        $categories = array();
        $line = 0;

        try {
            // Skip header row
            fgetcsv($handle);
            $line++;

            while (($data = fgetcsv($handle)) !== false) {
                $line++;
                if (isset($data[4])) { // Full Path column
                    $category = trim($data[4]);
                    if (!empty($category) && !in_array($category, $categories)) {
                        $categories[] = $category;
                    }
                } else {
                    Baserow_Logger::warning("Invalid CSV line format at line " . $line);
                }
            }
        } catch (Exception $e) {
            Baserow_Logger::error("Error reading CSV at line " . $line . ": " . $e->getMessage());
        } finally {
            fclose($handle);
        }

        if (!empty($categories)) {
            sort($categories);
            Baserow_Logger::debug("Successfully read " . count($categories) . " categories from CSV");
        } else {
            Baserow_Logger::debug("No categories found in CSV file");
        }

        return $categories;
    }

    private function get_categories_from_api() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            Baserow_Logger::error("API configuration is incomplete");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=200";
        
        $headers = [
            'Authorization' => 'Token ' . $this->api_token,
            'Content-Type' => 'application/json'
        ];

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error("Failed to get categories from API: " . $response->get_error_message());
            return array();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Baserow_Logger::error("API returned non-200 status: " . $status_code);
            return array();
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Baserow_Logger::error("Failed to decode API response: " . json_last_error_msg());
            return array();
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
            Baserow_Logger::debug("Found " . count($categories) . " categories from API");
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
            // Use the full category name for searching
            $encoded_category = str_replace("'", "''", $category); // Escape single quotes
            $url .= '&filter__Category__contains=' . urlencode($encoded_category);
            
            Baserow_Logger::debug("Search URL with category filter: " . $url);
            Baserow_Logger::debug("Category search term: " . $encoded_category);
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

        // Add pagination info to the response
        $data['pagination'] = array(
            'current_page' => $page,
            'total_pages' => ceil($data['count'] / $this->per_page),
            'total_items' => $data['count']
        );

        if (!empty($category)) {
            Baserow_Logger::debug("Search results for category '" . $category . "': " . count($data['results']) . " products found");
            if (!empty($data['results'])) {
                Baserow_Logger::debug("First product in results: " . print_r($data['results'][0], true));
            }
        }

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
