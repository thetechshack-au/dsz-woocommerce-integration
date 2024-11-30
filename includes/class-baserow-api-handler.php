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
        
        // Initialize paths
        $this->data_dir = plugin_dir_path(dirname(__FILE__)) . 'data';
        $this->csv_path = $this->data_dir . '/dsz-categories.csv';
    }

    public function test_connection() {
        if (empty($this->api_url) || empty($this->api_token) || empty($this->table_id)) {
            $missing = array();
            if (empty($this->api_url)) $missing[] = 'API URL';
            if (empty($this->api_token)) $missing[] = 'API Token';
            if (empty($this->table_id)) $missing[] = 'Table ID';
            
            return new WP_Error(
                'missing_config',
                'Missing required settings: ' . implode(', ', $missing)
            );
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
            return new WP_Error(
                'connection_failed',
                'Connection failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            $error = 'API returned status ' . $status_code;
            if ($status_code === 401) {
                $error = 'Invalid API token';
            } elseif ($status_code === 404) {
                $error = 'Invalid table ID or API URL';
            }
            return new WP_Error('api_error', $error);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid response from API');
        }

        if (!isset($data['count'])) {
            return new WP_Error('invalid_response', 'Invalid response format from API');
        }

        return true;
    }

    // ... [rest of the methods remain unchanged]
}
