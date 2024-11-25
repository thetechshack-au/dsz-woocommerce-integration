<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin_Core {
    private $api_handler;
    private $product_importer;
    private $settings;
    private $page_slug = 'baserow-importer';
    private $ajax_handler;
    private $order_actions;

    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;

        $this->load_dependencies();
        $this->initialize_components();
        $this->setup_hooks();
    }

    private function load_dependencies() {
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/admin/class-baserow-admin-ajax-handler.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/admin/class-baserow-admin-order-actions.php';
    }

    private function initialize_components() {
        $this->ajax_handler = new Baserow_Admin_Ajax_Handler();
        $this->order_actions = new Baserow_Admin_Order_Actions();
    }

    private function setup_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
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

    public function render_admin_page() {
        // Main admin page content
        include BASEROW_IMPORTER_PLUGIN_DIR . 'templates/admin/main-page.php';
    }

    public function render_order_status_page() {
        // Order status page content
        include BASEROW_IMPORTER_PLUGIN_DIR . 'templates/admin/order-status.php';
    }
}
