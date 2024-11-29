<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin {
    private $api_handler;
    private $product_importer;
    private $settings;

    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_test_baserow_connection', array($this, 'test_baserow_connection'));
        add_action('wp_ajax_search_products', array($this, 'search_products'));
        add_action('wp_ajax_import_product', array($this, 'import_product'));
        add_action('wp_ajax_delete_product', array($this, 'delete_product'));
        add_action('wp_ajax_get_categories', array($this, 'get_categories'));
        add_action('wp_ajax_test_api_call', array($this, 'test_api_call')); // New AJAX endpoint
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Baserow Importer',
            'Baserow Importer',
            'manage_options',
            'baserow-importer',
            array($this, 'render_admin_page'),
            'dashicons-database-import',
            56
        );

        add_submenu_page(
            'baserow-importer',
            'Baserow Settings',
            'Settings',
            'manage_options',
            'baserow-importer-settings',
            array($this->settings, 'render_settings_page')
        );

        // Temporary menu item for API testing
        add_submenu_page(
            'baserow-importer',
            'API Test',
            'API Test',
            'manage_options',
            'baserow-api-test',
            array($this, 'render_api_test_page')
        );
    }

    public function render_api_test_page() {
        $api_details = $this->api_handler->get_api_details();
        ?>
        <div class="wrap">
            <h1>Baserow API Test</h1>
            <div>
                <button id="test-api-call" class="button button-primary">Test API Call</button>
                <div id="api-response" style="margin-top: 20px; padding: 10px; background: #fff; border: 1px solid #ccc;">
                    <pre></pre>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#test-api-call').on('click', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_api_call',
                        nonce: '<?php echo wp_create_nonce('test_api_call'); ?>'
                    },
                    success: function(response) {
                        $('#api-response pre').text(JSON.stringify(response, null, 2));
                    },
                    error: function(xhr, status, error) {
                        $('#api-response pre').text('Error: ' + error);
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function test_api_call() {
        check_ajax_referer('test_api_call', 'nonce');

        $api_details = $this->api_handler->get_api_details();
        $url = trailingslashit($api_details['api_url']) . "api/database/rows/table/{$api_details['table_id']}/?user_field_names=true&fields=Category&size=1000";

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $api_details['api_token'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Failed to parse JSON response');
            return;
        }

        // Extract unique categories
        $categories = array();
        if (!empty($data['results'])) {
            foreach ($data['results'] as $product) {
                if (!empty($product['Category']) && !in_array($product['Category'], $categories)) {
                    $categories[] = $product['Category'];
                }
            }
            sort($categories);
        }

        wp_send_json_success(array(
            'total_rows' => $data['count'],
            'categories' => $categories,
            'raw_response' => $data
        ));
    }

    // Rest of the class remains unchanged...
}
