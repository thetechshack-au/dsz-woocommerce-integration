<?php
/**
 * Plugin Name: DSZ WooCommerce Product Importer
 * Description: Import products from Baserow (DSZ) database into WooCommerce and sync orders with DSZ
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 * Author: Andrew Waite
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BASEROW_IMPORTER_VERSION', '1.4.0');
define('BASEROW_IMPORTER_LAST_UPDATED', '2024-01-09 14:00:00 UTC');
define('BASEROW_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BASEROW_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));

class Baserow_Woo_Importer {
    private $admin;
    private $api_handler;
    private $product_importer;
    private $order_handler;
    private $settings;

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_delete_post', array($this, 'handle_product_deletion'), 10, 1);
        add_action('admin_notices', array($this, 'show_version_info'));
    }

    public function show_version_info() {
        if (is_admin() && current_user_can('manage_options')) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><?php printf(
                    'DSZ WooCommerce Product Importer Version: %s (Last Updated: %s)',
                    BASEROW_IMPORTER_VERSION,
                    BASEROW_IMPORTER_LAST_UPDATED
                ); ?></p>
            </div>
            <?php
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

        // Create log file if it doesn't exist
        $log_file = BASEROW_IMPORTER_PLUGIN_DIR . 'baserow-importer.log';
        if (!file_exists($log_file)) {
            touch($log_file);
            chmod($log_file, 0666); // Make it writable
        }

        $this->create_tables();
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

        // Check log file permissions
        $log_file = BASEROW_IMPORTER_PLUGIN_DIR . 'baserow-importer.log';
        if (!is_writable($log_file)) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('Baserow Importer log file is not writable. Please check permissions.', 'baserow-importer'); ?></p>
                </div>
                <?php
            });
        }

        $this->load_dependencies();
        $this->initialize_components();
    }

    private function load_dependencies() {
        // Load traits first
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-logger.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-api-request.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-data-validator.php';

        // Load core classes
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-settings.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-api-handler.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-admin.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-auth-handler.php';

        // Load product related classes
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-mapper.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-validator.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-image-handler.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-tracker.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-importer.php';

        // Load order related classes
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/orders/class-baserow-order-handler.php';

        // Load shipping related classes
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-shipping-zone-manager.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-postcode-mapper.php';

        // Load category related classes
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/categories/class-baserow-category-manager.php';

        // Load AJAX handlers
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-product-ajax.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-shipping-ajax.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-category-ajax.php';
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-order-ajax.php';
    }

    private function initialize_components() {
        $this->api_handler = new Baserow_API_Handler();
        $this->settings = new Baserow_Settings();
        $this->product_importer = new Baserow_Product_Importer($this->api_handler);
        $this->order_handler = new Baserow_Order_Handler($this->api_handler);
        $this->admin = new Baserow_Admin($this->api_handler, $this->product_importer, $this->settings);

        // Initialize AJAX handlers
        $product_ajax = new Baserow_Product_Ajax();
        $product_ajax->set_dependencies($this->product_importer, new Baserow_Product_Tracker());

        $shipping_ajax = new Baserow_Shipping_Ajax();
        $shipping_ajax->set_dependencies(new Baserow_Shipping_Zone_Manager(), new Baserow_Postcode_Mapper());

        $category_ajax = new Baserow_Category_Ajax();
        $category_ajax->set_dependencies(new Baserow_Category_Manager());

        $order_ajax = new Baserow_Order_Ajax();
        $order_ajax->set_dependencies($this->order_handler);
    }
}

new Baserow_Woo_Importer();
