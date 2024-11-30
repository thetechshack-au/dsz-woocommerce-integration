<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_API_Handler {
    use Baserow_Logger_Trait;

    private $api_url;
    private $api_token;
    private $table_id;
    private $per_page = 20;
    private $csv_path;
    private $data_dir;
    private $cache_key = 'baserow_categories_cache';
    private $cache_expiry = 3600; // 1 hour
    private const EXPECTED_CSV_HEADERS = array(
        'Category ID',
        'Category Name',
        'Parent Category',
        'Top Category',
        'Full Path'
    );

    public function __construct() {
        $this->api_url = get_option('baserow_api_url');
        $this->api_token = get_option('baserow_api_token');
        $this->table_id = get_option('baserow_table_id');
        $this->data_dir = BASEROW_IMPORTER_PLUGIN_DIR . 'data';
        $this->csv_path = $this->data_dir . '/dsz-categories.csv';
        $this->ensure_data_directory();
    }

    private function ensure_data_directory() {
        if (!file_exists($this->data_dir)) {
            $this->log_debug("Creating data directory: " . $this->data_dir);
            if (!wp_mkdir_p($this->data_dir)) {
                $this->log_error("Failed to create data directory", array('path' => $this->data_dir));
                return false;
            }
        }

        if (!is_writable($this->data_dir)) {
            $this->log_error("Data directory is not writable", array('path' => $this->data_dir));
            return false;
        }

        return true;
    }

    public function get_categories() {
        // Try to get from cache first
        $cached_categories = get_transient($this->cache_key);
        if ($cached_categories !== false) {
            $this->log_debug("Retrieved categories from cache");
            return $cached_categories;
        }

        // Try to get categories from CSV
        $categories = $this->get_categories_from_csv();
        
        // If CSV fails or is empty, fall back to API
        if (empty($categories)) {
            $this->log_debug("No categories found in CSV, falling back to API");
            $categories = $this->get_categories_from_api();
        } else {
            $this->log_debug("Successfully loaded " . count($categories) . " categories from CSV");
        }

        // Cache the results if we have categories
        if (!empty($categories) && !is_wp_error($categories)) {
            set_transient($this->cache_key, $categories, $this->cache_expiry);
            $this->log_debug("Categories cached for " . $this->cache_expiry . " seconds");
        }

        return $categories;
    }

    private function validate_csv_structure($handle) {
        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            $this->log_error("Invalid CSV header format");
            return false;
        }

        $missing_headers = array_diff(self::EXPECTED_CSV_HEADERS, $headers);
        if (!empty($missing_headers)) {
            $this->log_error("Missing required CSV headers", array('missing' => $missing_headers));
            return false;
        }

        return true;
    }

    public function refresh_categories() {
        $this->log_debug("Manually refreshing categories");
        
        // Clear the cache
        delete_transient($this->cache_key);

        // Get fresh data from API
        $categories = $this->get_categories_from_api();
        
        if (!is_wp_error($categories) && !empty($categories)) {
            // Cache the new results
            set_transient($this->cache_key, $categories, $this->cache_expiry);
            $this->log_debug("Categories refreshed and cached");
            return true;
        }

        $this->log_error("Failed to refresh categories");
        return false;
    }

    private function get_categories_from_csv() {
        if (!file_exists($this->csv_path)) {
            $this->log_debug("CSV file not found", array('path' => $this->csv_path));
            return array();
        }

        if (!is_readable($this->csv_path)) {
            $this->log_error("CSV file is not readable", array('path' => $this->csv_path));
            return array();
        }

        $handle = fopen($this->csv_path, 'r');
        if ($handle === false) {
            $this->log_error("Failed to open CSV file", array('path' => $this->csv_path));
            return array();
        }

        $categories = array();
        $line = 1;

        try {
            // Validate CSV structure
            if (!$this->validate_csv_structure($handle)) {
                $this->log_error("Invalid CSV structure");
                return array();
            }

            while (($data = fgetcsv($handle)) !== false) {
                $line++;
                if (isset($data[4])) { // Full Path column
                    $category = trim($data[4]);
                    if (!empty($category) && !in_array($category, $categories)) {
                        $categories[] = $category;
                    }
                } else {
                    $this->log_warning("Invalid CSV line format", array(
                        'line' => $line,
                        'data' => $data
                    ));
                }
            }
        } catch (Exception $e) {
            $this->log_error("Error reading CSV file", array(
                'error' => $e->getMessage(),
                'line' => $line
            ));
        } finally {
            fclose($handle);
        }

        if (!empty($categories)) {
            sort($categories);
        }

        return $categories;
    }

    private function get_categories_from_api() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("API configuration is incomplete");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=200";
        
        $this->log_debug("Fetching categories from API", array('url' => $url));

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $this->log_error("API request failed", array('error' => $response->get_error_message()));
            return new WP_Error('api_error', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $this->log_error("API returned non-200 status", array(
                'status' => $status_code,
                'body' => $body
            ));
            return new WP_Error('api_error', "API returned status code {$status_code}");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error("Failed to parse API response", array('error' => json_last_error_msg()));
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
            $this->log_debug("Found " . count($categories) . " categories from API");
        }

        return $categories;
    }

    // ... [rest of the class methods remain unchanged]
}
