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
        
        // Add admin pages
        add_action('admin_menu', array($this, 'add_order_status_page'));
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

        // Enqueue admin styles
        wp_enqueue_style(
            'baserow-admin-style',
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            BASEROW_IMPORTER_VERSION
        );
    }
}
