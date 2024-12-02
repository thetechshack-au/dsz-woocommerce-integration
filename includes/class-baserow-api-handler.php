<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the logger trait is available
if (!trait_exists('Baserow_Logger_Trait')) {
    require_once dirname(__FILE__) . '/traits/trait-baserow-logger.php';
}

class Baserow_API_Handler {
    use Baserow_Logger_Trait;
    use Baserow_API_Request_Trait;

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
        
        $this->log_debug("Initialized with CSV path: " . $this->csv_path);
    }

    /**
     * Get a single product by ID
     *
     * @param string $product_id
     * @return array|WP_Error
     */
    public function get_product($product_id) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("API configuration is incomplete");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/{$product_id}/?user_field_names=true";
        
        $this->log_debug("Fetching product from API: " . $url);

        $headers = [
            'Authorization' => 'Token ' . $this->api_token
        ];

        $result = $this->make_api_request($url, 'GET', null, $headers);

        if (is_wp_error($result)) {
            $this->log_error("Failed to fetch product: " . $result->get_error_message());
            return $result;
        }

        $this->log_debug("Successfully retrieved product data", [
            'product_id' => $product_id
        ]);

        return $result;
    }

    public function get_categories() {
        // Try CSV first
        $this->log_debug("Attempting to get categories from CSV");
        $categories = $this->get_categories_from_csv();
        
        // Fall back to API if CSV fails
        if (empty($categories)) {
            $this->log_debug("No categories from CSV, falling back to API");
            $categories = $this->get_categories_from_api();
        } else {
            $this->log_debug("Successfully loaded " . count($categories) . " categories from CSV");
            return $categories;
        }

        return $categories;
    }

    private function get_categories_from_csv() {
        if (!file_exists($this->csv_path)) {
            $this->log_debug("CSV file not found at: " . $this->csv_path);
            return array();
        }

        if (!is_readable($this->csv_path)) {
            $this->log_error("CSV file exists but is not readable: " . $this->csv_path);
            return array();
        }

        $this->log_debug("Opening CSV file: " . $this->csv_path);
        $handle = @fopen($this->csv_path, 'r');
        
        if ($handle === false) {
            $this->log_error("Failed to open CSV file");
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
                    $this->log_warning("Invalid CSV line format at line " . $line);
                }
            }
        } catch (Exception $e) {
            $this->log_error("Error reading CSV at line " . $line . ": " . $e->getMessage());
        } finally {
            fclose($handle);
        }

        if (!empty($categories)) {
            sort($categories);
            $this->log_debug("Successfully read " . count($categories) . " categories from CSV");
        } else {
            $this->log_debug("No categories found in CSV file");
        }

        return $categories;
    }

    private function get_categories_from_api() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("API configuration is incomplete");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=200";
        
        $headers = [
            'Authorization' => 'Token ' . $this->api_token
        ];

        $result = $this->make_api_request($url, 'GET', null, $headers);

        if (is_wp_error($result)) {
            return $result;
        }

        $categories = array();
        if (!empty($result['results'])) {
            foreach ($result['results'] as $product) {
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

    public function search_products($search_term = '', $category = '', $page = 1) {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("API configuration is incomplete");
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size={$this->per_page}&page={$page}";
        
        if (!empty($search_term)) {
            $url .= '&search=' . urlencode($search_term);
        }

        if (!empty($category)) {
            $category_parts = explode(' > ', $category);
            $search_term = end($category_parts);
            $url .= '&filter__Category__contains=' . rawurlencode($search_term);
        }

        $headers = [
            'Authorization' => 'Token ' . $this->api_token
        ];

        $result = $this->make_api_request($url, 'GET', null, $headers);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!isset($result['results']) || !isset($result['count'])) {
            $this->log_error("Invalid API response format");
            return new WP_Error('invalid_response', "Invalid API response format");
        }

        return array(
            'results' => $result['results'],
            'count' => $result['count'],
            'pagination' => array(
                'current_page' => $page,
                'total_pages' => ceil($result['count'] / $this->per_page),
                'total_items' => $result['count']
            )
        );
    }

    public function test_connection() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $this->log_error("Test connection failed: Missing configuration");
            return false;
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=1";
        
        $headers = [
            'Authorization' => 'Token ' . $this->api_token
        ];

        $result = $this->make_api_request($url, 'GET', null, $headers);

        return !is_wp_error($result);
    }
}
