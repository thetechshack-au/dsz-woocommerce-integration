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

// Load required files
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-api-request.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-baserow-data-validator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-baserow-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-validator.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-image-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/product/class-baserow-product-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/shipping/class-baserow-shipping-zone-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/shipping/class-baserow-postcode-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-product-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-shipping-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax/class-baserow-order-ajax.php';

// Initialize logger
Baserow_Logger::init();

// Register activation hook
register_activation_hook(__FILE__, 'baserow_woo_activate');

function baserow_woo_activate() {
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'baserow-importer/logs';
    
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'baserow_woo_deactivate');

function baserow_woo_deactivate() {
    // Cleanup if needed
}
