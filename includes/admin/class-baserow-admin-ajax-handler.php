<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin_Ajax_Handler {
    private $api_handler;
    private $product_importer;

    public function __construct($api_handler, $product_importer) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;

        // Product import actions
        add_action('wp_ajax_get_baserow_products', array($this, 'handle_get_products'));
        add_action('wp_ajax_import_products', array($this, 'handle_import_products'));
        add_action('wp_ajax_test_connection', array($this, 'handle_connection_test'));

        // Order sync actions
        add_action('wp_ajax_retry_dsz_sync', array($this, 'handle_retry_sync'));
        add_action('wp_ajax_retry_all_failed_dsz_sync', array($this, 'handle_retry_all_failed_sync'));
    }

    public function handle_get_products() {
        check_ajax_referer('baserow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $products = $this->api_handler->get_products();
            if (is_wp_error($products)) {
                throw new Exception($products->get_error_message());
            }

            wp_send_json_success($products);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_import_products() {
        check_ajax_referer('baserow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_ids = isset($_POST['products']) ? array_map('intval', $_POST['products']) : array();
        if (empty($product_ids)) {
            wp_send_json_error('No products selected');
            return;
        }

        $results = array(
            'success' => array(),
            'errors' => array()
        );

        foreach ($product_ids as $product_id) {
            try {
                $result = $this->product_importer->import_product($product_id);
                if (is_wp_error($result)) {
                    $results['errors'][] = array(
                        'id' => $product_id,
                        'message' => $result->get_error_message()
                    );
                } else {
                    $results['success'][] = $product_id;
                }
            } catch (Exception $e) {
                $results['errors'][] = array(
                    'id' => $product_id,
                    'message' => $e->getMessage()
                );
            }
        }

        wp_send_json_success($results);
    }

    public function handle_connection_test() {
        check_ajax_referer('baserow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $result = $this->api_handler->test_connection();
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            wp_send_json_success('Connection successful');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_retry_sync() {
        check_ajax_referer('baserow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
            return;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        
        try {
            $result = $order_handler->handle_new_order($order_id);
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success();
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_retry_all_failed_sync() {
        check_ajax_referer('baserow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_dsz_orders';
        
        $failed_orders = $wpdb->get_results(
            "SELECT order_id FROM {$table_name} WHERE status != 'success'"
        );

        if (empty($failed_orders)) {
            wp_send_json_success('No failed orders to retry');
            return;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        
        $success_count = 0;
        $errors = array();

        foreach ($failed_orders as $order) {
            try {
                $result = $order_handler->handle_new_order($order->order_id);
                if (!is_wp_error($result)) {
                    $success_count++;
                } else {
                    $errors[] = "Order #{$order->order_id}: " . $result->get_error_message();
                }
            } catch (Exception $e) {
                $errors[] = "Order #{$order->order_id}: " . $e->getMessage();
            }
        }

        if (empty($errors)) {
            wp_send_json_success("Successfully resynced {$success_count} orders");
        } else {
            wp_send_json_error(array(
                'message' => "Completed with errors. Successful: {$success_count}, Failed: " . count($errors),
                'errors' => $errors
            ));
        }
    }
}
