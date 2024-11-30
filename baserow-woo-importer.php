<?php
/**
 * Plugin Name: DSZ WooCommerce Integration
 * Description: Integrates Baserow with WooCommerce for product management
 * Version: 1.5.0
 * Author: DSZ
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BASEROW_IMPORTER_VERSION', '1.5.0');
define('BASEROW_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BASEROW_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Load required files in order of dependency
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-logger.php';

// Load traits
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-api-request.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-data-validator.php';

// Load category management
require_once plugin_dir_path(__FILE__) . 'includes/categories/class-baserow-category-manager.php';

// Load core classes
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-admin.php';

// Load product management
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-validator.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-image-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-tracker.php';

// Load shipping management
require_once plugin_dir_path(__FILE__) . 'includes/shipping/class-baserow-shipping-zone-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/shipping/class-baserow-postcode-mapper.php';

// Load AJAX handlers
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-category-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-product-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-shipping-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-order-ajax.php';

// Initialize logger
Baserow_Logger::init();

/**
 * Initialize the plugin
 */
function baserow_woo_init() {
    // Create data directory if it doesn't exist
    $data_dir = plugin_dir_path(__FILE__) . 'data';
    if (!file_exists($data_dir)) {
        mkdir($data_dir, 0755, true);
    }

    // Initialize core classes
    $api_handler = new Baserow_API_Handler();
    $product_importer = new Baserow_Product_Importer($api_handler);

    // Initialize admin interface
    new Baserow_Admin($api_handler, $product_importer, null);

    // Initialize AJAX handlers
    new Baserow_Category_Ajax();
    new Baserow_Product_Ajax();
    new Baserow_Shipping_Ajax();
    new Baserow_Order_Ajax();
}

// Register activation hook
register_activation_hook(__FILE__, 'baserow_woo_activate');

function baserow_woo_activate() {
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'baserow-importer/logs';
    
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    // Create data directory
    $data_dir = plugin_dir_path(__FILE__) . 'data';
    if (!file_exists($data_dir)) {
        mkdir($data_dir, 0755, true);
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'baserow_woo_deactivate');

function baserow_woo_deactivate() {
    // Cleanup if needed
}

// Initialize the plugin
add_action('plugins_loaded', 'baserow_woo_init');
