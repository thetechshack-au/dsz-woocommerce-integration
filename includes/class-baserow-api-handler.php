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
                'Missing configuration: ' . implode(', ', $missing),
                ['missing_fields' => $missing]
            );
        }

        $url = trailingslashit($this->api_url) . "api/database/rows/table/{$this->table_id}/?user_field_names=true&size=1";
        
        $this->log_debug("Testing connection", [
            'url' => $url,
            'table_id' => $this->table_id
        ]);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $this->log_error("Connection failed", [
                'error' => $response->get_error_message(),
                'code' => $response->get_error_code()
            ]);
            
            return new WP_Error(
                'connection_failed',
                'Failed to connect to Baserow API: ' . $response->get_error_message(),
                [
                    'error_code' => $response->get_error_code(),
                    'error_data' => $response->get_error_data()
                ]
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_message = "API returned status {$status_code}";
            $error_details = [];
            
            if (!empty($body)) {
                $json_body = json_decode($body, true);
                if (isset($json_body['error'])) {
                    $error_message .= ": " . $json_body['error'];
                    $error_details['api_error'] = $json_body['error'];
                }
            }
            
            // Add specific troubleshooting tips based on status code
            $tips = [];
            switch ($status_code) {
                case 401:
                    $tips[] = "Verify your API token is correct";
                    $tips[] = "Check if your API token has expired";
                    $tips[] = "Ensure your API token has the necessary permissions";
                    break;
                case 404:
                    $tips[] = "Verify your Table ID is correct";
                    $tips[] = "Check if the table still exists in Baserow";
                    $tips[] = "Ensure your API URL points to the correct Baserow instance";
                    break;
                case 403:
                    $tips[] = "Your API token doesn't have permission to access this table";
                    $tips[] = "Check if you have the correct access rights in Baserow";
                    break;
                default:
                    $tips[] = "Check if your Baserow instance is accessible";
                    $tips[] = "Verify all your API settings are correct";
            }
            
            $error_details['troubleshooting_tips'] = $tips;
            $error_details['status_code'] = $status_code;
            $error_details['response_body'] = $body;
            
            $this->log_error($error_message, $error_details);
            
            return new WP_Error(
                'api_error',
                $error_message,
                $error_details
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = "Failed to parse API response: " . json_last_error_msg();
            $this->log_error($error);
            return new WP_Error('json_error', $error);
        }

        if (!isset($data['count'])) {
            $error = "Invalid API response format";
            $this->log_error($error, ['response' => $data]);
            return new WP_Error('invalid_response', $error);
        }

        $this->log_debug("Connection test successful", [
            'response_count' => $data['count']
        ]);
        
        return true;
    }

    // ... [rest of the methods remain unchanged]
}
