<?php
/**
 * Plugin Name: DropshipZone Products
 * Description: Import products from Baserow (DSZ) database into WooCommerce and sync orders with DSZ
 * Version: 1.6.11
 * Last Updated: 2024-01-16 14:00:00 UTC
 * Author: Andrew Waite
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BASEROW_IMPORTER_VERSION', '1.6.11');
define('BASEROW_IMPORTER_LAST_UPDATED', '2024-01-16 14:00:00 UTC');
define('BASEROW_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BASEROW_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BASEROW_USE_NEW_STRUCTURE', true);

class Baserow_Woo_Importer {
    private $admin;
    private $api_handler;
    private $product_importer;
    private $order_handler;
    private $settings;
    private $product_ajax;
    private $product_tracker;
    private $stock_handler;

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_delete_post', array($this, 'handle_product_deletion'), 10, 1);
        add_action('rest_api_init', array($this, 'register_rest_fields'));

        // Register AJAX actions
        add_action('wp_ajax_import_baserow_product', array($this, 'handle_import_product'));
        add_action('wp_ajax_sync_baserow_product', array($this, 'handle_sync_product'));
        add_action('wp_ajax_get_product_status', array($this, 'handle_get_product_status'));
        add_action('wp_ajax_get_import_stats', array($this, 'handle_get_import_stats'));
    }

    /**
     * Register GTIN field with WooCommerce REST API
     */
    public function register_rest_fields() {
        register_rest_field('product', 'global_unique_id', array(
            'get_callback' => function($post) {
                return get_post_meta($post['id'], '_global_unique_id', true);
            },
            'update_callback' => function($value, $post) {
                return update_post_meta($post->ID, '_global_unique_id', $value);
            },
            'schema' => array(
                'description' => 'Global Trade Item Number (GTIN)',
                'type' => 'string',
                'context' => array('view', 'edit')
            )
        ));
    }

    public function handle_import_product() {
        Baserow_Logger::debug("Main plugin handling import_baserow_product");
        if ($this->product_ajax) {
            $this->product_ajax->import_product();
        } else {
            Baserow_Logger::error("Product AJAX handler not initialized");
            wp_send_json_error('Product AJAX handler not initialized');
        }
    }

    public function handle_sync_product() {
        Baserow_Logger::debug("Main plugin handling sync_baserow_product");
        if ($this->product_ajax) {
            $this->product_ajax->sync_product();
        } else {
            Baserow_Logger::error("Product AJAX handler not initialized");
            wp_send_json_error('Product AJAX handler not initialized');
        }
    }

    public function handle_get_product_status() {
        Baserow_Logger::debug("Main plugin handling get_product_status");
        if ($this->product_ajax) {
            $this->product_ajax->get_product_status();
        } else {
            Baserow_Logger::error("Product AJAX handler not initialized");
            wp_send_json_error('Product AJAX handler not initialized');
        }
    }

    public function handle_get_import_stats() {
        Baserow_Logger::debug("Main plugin handling get_import_stats");
        if ($this->product_ajax) {
            $this->product_ajax->get_import_stats();
        } else {
            Baserow_Logger::error("Product AJAX handler not initialized");
            wp_send_json_error('Product AJAX handler not initialized');
        }
    }

    public function activate() {
        if (version_compare(PHP_VERSION, '7.2', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires PHP version 7.2 or higher.');
        }

        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires WooCommerce to be installed and activated.');
        }

        $this->load_dependencies();
        
        // Initialize logger after dependencies are loaded
        Baserow_Logger::init();
        
        // Verify logger is working
        error_log('Baserow Logger initialized: ' . (Baserow_Logger::is_logging_enabled() ? 'true' : 'false'));
        error_log('Baserow Logger file: ' . Baserow_Logger::get_log_file());
        
        $this->create_tables();
        $this->initialize_components();
        
        // Schedule stock sync
        if ($this->stock_handler) {
            $this->stock_handler->schedule_sync();
        }
    }

    public function deactivate() {
        // Unschedule stock sync
        if ($this->stock_handler) {
            $this->stock_handler->unschedule_sync();
        }
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Products tracking table
        $products_table = $wpdb->prefix . 'baserow_imported_products';
        $products_sql = "CREATE TABLE IF NOT EXISTS $products_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            baserow_id varchar(255) NOT NULL,
            woo_product_id bigint(20) NOT NULL,
            import_date datetime DEFAULT CURRENT_TIMESTAMP,
            last_sync datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY baserow_id (baserow_id)
        ) $charset_collate;";

        // Orders tracking table
        $orders_table = $wpdb->prefix . 'baserow_dsz_orders';
        $orders_sql = "CREATE TABLE IF NOT EXISTS $orders_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            dsz_reference varchar(255) NOT NULL,
            sync_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(50) NOT NULL DEFAULT 'pending',
            last_error text,
            retry_count int DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($products_sql);
        dbDelta($orders_sql);
    }

    public function handle_product_deletion($post_id) {
        // Check if this is a product
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_imported_products';

        // Get the Baserow ID for this product
        $baserow_product = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT baserow_id FROM $table_name WHERE woo_product_id = %d",
                $post_id
            )
        );

        if (!$baserow_product) {
            return;
        }

        // Update Baserow to mark the product as not imported
        $this->api_handler->update_product($baserow_product->baserow_id, array(
            'imported_to_woo' => false,
            'woo_product_id' => null
        ));

        // Remove from tracking table
        $wpdb->delete(
            $table_name,
            array('woo_product_id' => $post_id),
            array('%d')
        );
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('Baserow Importer requires WooCommerce to be installed and activated.', 'baserow-importer'); ?></p>
                </div>
                <?php
            });
            return;
        }

        $this->load_dependencies();
        
        // Initialize and verify logger
        Baserow_Logger::init();
        
        // Log initialization status
        error_log('Baserow Logger init status - Enabled: ' . (Baserow_Logger::is_logging_enabled() ? 'true' : 'false'));
        error_log('Baserow Logger init status - File: ' . Baserow_Logger::get_log_file());
        
        if (!Baserow_Logger::is_logging_enabled()) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('Baserow Importer log file is not writable. Please check permissions.', 'baserow-importer'); ?></p>
                </div>
                <?php
            });
        }

        $this->initialize_components();
    }

    private function load_dependencies() {
        // Load core files first
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-logger.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-settings.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-api-handler.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-admin.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-auth-handler.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-product-importer.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';

        // Load new modular components
        if (defined('BASEROW_USE_NEW_STRUCTURE') && BASEROW_USE_NEW_STRUCTURE) {
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-logger.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-api-request.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-data-validator.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-mapper.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-validator.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-image-handler.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-tracker.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-stock-handler.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-shipping-zone-manager.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-postcode-mapper.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/categories/class-baserow-category-manager.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-product-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-shipping-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-category-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-order-ajax.php';
        }
    }

    private function initialize_components() {
        // Initialize core components
        $this->api_handler = new Baserow_API_Handler();
        $this->settings = new Baserow_Settings();
        $this->product_importer = new Baserow_Product_Importer($this->api_handler);
        $this->order_handler = new Baserow_Order_Handler($this->api_handler);
        
        // Initialize product tracker
        $this->product_tracker = new Baserow_Product_Tracker();
        
        // Initialize stock handler
        $this->stock_handler = new Baserow_Stock_Handler($this->api_handler, $this->product_tracker);
        
        // Initialize AJAX handlers
        $this->product_ajax = new Baserow_Product_Ajax();
        $this->product_ajax->set_dependencies($this->product_importer, $this->product_tracker);
        
        // Initialize admin last since it depends on other components
        $this->admin = new Baserow_Admin($this->api_handler, $this->product_importer, $this->settings);
    }
}

new Baserow_Woo_Importer();
