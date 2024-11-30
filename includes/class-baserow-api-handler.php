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
        
        // Initialize paths but don't create directories yet
        $this->data_dir = BASEROW_IMPORTER_PLUGIN_DIR . 'data';
        $this->csv_path = $this->data_dir . '/dsz-categories.csv';
    }

    public function get_categories() {
        // Try API first as it's more reliable
        $categories = $this->get_categories_from_api();
        
        // Only try CSV if API fails and CSV exists
        if ((is_wp_error($categories) || empty($categories)) && file_exists($this->csv_path)) {
            try {
                $categories = $this->get_categories_from_csv();
            } catch (Exception $e) {
                // Log error but don't break functionality
                if (method_exists($this, 'log_error')) {
                    $this->log_error("Error reading CSV: " . $e->getMessage());
                }
                return array();
            }
        }

        return $categories;
    }

    private function get_categories_from_csv() {
        if (!file_exists($this->csv_path)) {
            return array();
        }

        $categories = array();
        $handle = @fopen($this->csv_path, 'r');
        
        if ($handle === false) {
            return array();
        }

        // Skip header row
        fgetcsv($handle);

        while (($data = fgetcsv($handle)) !== false) {
            if (isset($data[4])) { // Full Path column
                $category = trim($data[4]);
                if (!empty($category) && !in_array($category, $categories)) {
                    $categories[] = $category;
                }
            }
        }

        fclose($handle);

        if (!empty($categories)) {
            sort($categories);
        }

        return $categories;
    }

    private function get_categories_from_api() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            return new WP_Error('config_error', 'API configuration is incomplete');
        }

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

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size={$this->per_page}&page={$page}";
        
        if (!empty($search_term)) {
            $url .= '&search=' . urlencode($search_term);
        }

        if (!empty($category)) {
            $category_parts = explode(' > ', $category);
            $search_term = end($category_parts);
            $url .= '&filter__Category__contains=' . rawurlencode($search_term);
        }

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

        $data['pagination'] = array(
            'current_page' => $page,
            'total_pages' => ceil($data['count'] / $this->per_page),
            'total_items' => $data['count']
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
