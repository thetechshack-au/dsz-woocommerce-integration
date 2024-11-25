<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin {
    private $api_handler;
    private $product_importer;
    private $settings;
    private $page_slug = 'baserow-importer';

    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_import_products', array($this, 'handle_import_ajax'));
        add_action('wp_ajax_test_connection', array($this, 'handle_connection_test'));
        
        // Add order actions
        add_action('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_sync_to_dsz', array($this, 'process_sync_to_dsz_action'));
        
        // Add order list bulk actions
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_order_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_order_bulk_actions'), 10, 3);
        
        // Add order sync AJAX handlers
        add_action('wp_ajax_retry_dsz_sync', array($this, 'handle_retry_sync'));
        add_action('wp_ajax_retry_all_failed_dsz_sync', array($this, 'handle_retry_all_failed_sync'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Baserow Importer',
            'Baserow Importer',
            'manage_options',
            $this->page_slug,
            array($this, 'render_admin_page'),
            'dashicons-database-import'
        );

        add_submenu_page(
            $this->page_slug,
            'Settings',
            'Settings',
            'manage_options',
            'baserow-importer-settings',
            array($this->settings, 'render_settings_page')
        );

        add_submenu_page(
            $this->page_slug,
            'DSZ Order Status',
            'DSZ Order Status',
            'manage_options',
            'baserow-dsz-orders',
            array($this, 'render_order_status_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include BASEROW_IMPORTER_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function render_order_status_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include BASEROW_IMPORTER_PLUGIN_DIR . 'templates/order-status.php';
    }

    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages or WooCommerce order pages
        if (strpos($hook, $this->page_slug) === false && 
            strpos($hook, 'woocommerce_page_wc-orders') === false && 
            strpos($hook, 'edit.php') === false && 
            $hook !== 'post.php') {
            return;
        }

        wp_enqueue_script(
            'baserow-admin',
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            BASEROW_IMPORTER_VERSION,
            true
        );

        wp_localize_script('baserow-admin', 'baserowAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('baserow_admin_nonce')
        ));

        wp_enqueue_style(
            'baserow-admin-style',
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            BASEROW_IMPORTER_VERSION
        );
    }

    public function handle_import_ajax() {
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

    public function add_order_actions($actions) {
        global $theorder;
        
        // Check if order has DSZ items
        $has_dsz_items = false;
        foreach ($theorder->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $terms = wp_get_object_terms($product->get_id(), 'product_source');
            foreach ($terms as $term) {
                if ($term->slug === 'dsz') {
                    $has_dsz_items = true;
                    break 2;
                }
            }
        }
        
        if ($has_dsz_items) {
            $actions['sync_to_dsz'] = __('Sync to DSZ', 'baserow-importer');
        }
        
        return $actions;
    }

    public function process_sync_to_dsz_action($order) {
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        $order_handler->handle_new_order($order->get_id());
    }

    public function add_order_bulk_actions($actions) {
        $actions['sync_to_dsz_bulk'] = __('Sync to DSZ', 'baserow-importer');
        return $actions;
    }

    public function handle_order_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'sync_to_dsz_bulk') {
            return $redirect_to;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        
        $synced = 0;
        foreach ($post_ids as $post_id) {
            $order_handler->handle_new_order($post_id);
            $synced++;
        }

        $redirect_to = add_query_arg('synced_to_dsz', $synced, $redirect_to);
        return $redirect_to;
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
