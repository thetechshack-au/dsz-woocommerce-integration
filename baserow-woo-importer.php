<?php
/**
 * Plugin Name: DSZ WooCommerce Product Importer
 * Description: Import products from Baserow (DSZ) database into WooCommerce and sync orders with DSZ
 * Version: 1.5.1
 * Author: Andrew Waite
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BASEROW_IMPORTER_VERSION', '1.5.1');
define('BASEROW_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BASEROW_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));

class Baserow_Woo_Importer {
    private $admin;
    private $api_handler;
    private $product_importer;
    private $order_handler;
    private $settings;
    private $image_settings;

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_delete_post', array($this, 'handle_product_deletion'), 10, 1);
        add_action('admin_notices', array($this, 'display_admin_notices'));
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
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_imported_products';

        $baserow_product = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT baserow_id FROM $table_name WHERE woo_product_id = %d",
                $post_id
            )
        );

        if (!$baserow_product) {
            return;
        }

        if ($this->api_handler && $this->api_handler->is_initialized()) {
            $this->api_handler->update_product($baserow_product->baserow_id, array(
                'imported_to_woo' => false,
                'woo_product_id' => null
            ));
        }

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

        $this->check_requirements();
        $this->load_dependencies();
        $this->initialize_components();
    }

    private function check_requirements() {
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

        // Load modular components
        if (defined('BASEROW_USE_NEW_STRUCTURE') && BASEROW_USE_NEW_STRUCTURE) {
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-logger.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-api-request.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/traits/trait-baserow-data-validator.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-mapper.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-validator.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-image-handler.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/product/class-baserow-product-tracker.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-shipping-zone-manager.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/shipping/class-baserow-postcode-mapper.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/categories/class-baserow-category-manager.php';
            
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-product-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-shipping-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-category-ajax.php';
            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/ajax/class-baserow-order-ajax.php';

            require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/admin/class-baserow-image-settings.php';
        }
    }

    private function initialize_components() {
        // Initialize settings first
        $this->settings = new Baserow_Settings();
        
        // Initialize API handler with validation
        $this->api_handler = new Baserow_API_Handler();
        if (!$this->api_handler->init()) {
            add_action('admin_notices', function() {
                ?>
                <div class="error">
                    <p><?php _e('Baserow Importer: API configuration is incomplete or invalid. Please check your settings.', 'baserow-importer'); ?></p>
                </div>
                <?php
            });
        }

        // Initialize other components
        $this->product_importer = new Baserow_Product_Importer($this->api_handler);
        $this->order_handler = new Baserow_Order_Handler($this->api_handler);
        $this->admin = new Baserow_Admin($this->api_handler, $this->product_importer, $this->settings);

        if (defined('BASEROW_USE_NEW_STRUCTURE') && BASEROW_USE_NEW_STRUCTURE) {
            $this->image_settings = new Baserow_Image_Settings();
        }
    }

    public function display_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_url = get_option('baserow_api_url');
        $api_token = get_option('baserow_api_token');
        $table_id = get_option('baserow_table_id');

        if (empty($api_url) || empty($api_token) || empty($table_id)) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php _e('Baserow Importer requires configuration. Please visit the ', 'baserow-importer'); ?>
                    <a href="<?php echo admin_url('admin.php?page=baserow-importer-settings'); ?>">
                        <?php _e('settings page', 'baserow-importer'); ?>
                    </a>
                    <?php _e(' to complete setup.', 'baserow-importer'); ?>
                </p>
            </div>
            <?php
        }
    }
}

new Baserow_Woo_Importer();
