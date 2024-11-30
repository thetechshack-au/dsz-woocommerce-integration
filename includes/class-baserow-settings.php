<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Settings {
    private $options_group = 'baserow_importer_settings';
    private $options_page = 'baserow-importer-settings';
    private $api_handler;

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_test_dsz_connection', array($this, 'test_dsz_connection'));
        add_action('wp_ajax_test_baserow_connection', array($this, 'test_baserow_connection'));
        
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-api-handler.php';
        $this->api_handler = new Baserow_API_Handler();
    }

    public function test_baserow_connection() {
        check_ajax_referer('baserow_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $result = $this->api_handler->test_connection();
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();
            
            // Format troubleshooting tips if available
            $response_message = $error_message;
            if (!empty($error_data['troubleshooting_tips'])) {
                $response_message .= "\n\nTroubleshooting tips:";
                foreach ($error_data['troubleshooting_tips'] as $tip) {
                    $response_message .= "\n- " . $tip;
                }
            }
            
            // Add debug information
            $debug_info = array(
                'error_code' => $result->get_error_code(),
                'status_code' => $error_data['status_code'] ?? null,
                'api_error' => $error_data['api_error'] ?? null
            );
            
            wp_send_json_error(array(
                'message' => $response_message,
                'debug' => $debug_info
            ));
            return;
        }

        if ($result === true) {
            wp_send_json_success('Successfully connected to Baserow API');
        } else {
            wp_send_json_error('Failed to connect to Baserow API. Please check your settings and ensure your API token has the correct permissions.');
        }
    }

    public function test_dsz_connection() {
        check_ajax_referer('baserow_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-auth-handler.php';
        $auth_handler = new Baserow_Auth_Handler();

        $token = $auth_handler->get_token();
        if (is_wp_error($token)) {
            wp_send_json_error($token->get_error_message());
            return;
        }

        wp_send_json_success('Successfully authenticated with Dropshipzone API');
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'baserow-importer_page_baserow-importer-settings') {
            return;
        }

        wp_enqueue_script(
            'baserow-settings', 
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/settings.js',
            array('jquery'),
            BASEROW_IMPORTER_VERSION,
            true
        );

        wp_enqueue_script(
            'dsz-settings', 
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/dsz-settings.js',
            array('jquery'),
            BASEROW_IMPORTER_VERSION,
            true
        );

        wp_localize_script('baserow-settings', 'baserowSettings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('baserow_test_connection')
        ));
    }

    // ... [rest of the class methods remain unchanged]
}
