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
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_baserow-importer' && $hook !== 'baserow-importer_page_baserow-importer-settings') {
            return;
        }

        wp_enqueue_style(
            'baserow-importer-css',
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            BASEROW_IMPORTER_VERSION
        );

        wp_enqueue_script(
            'baserow-importer-js',
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            BASEROW_IMPORTER_VERSION,
            true
        );

        wp_localize_script('baserow-importer-js', 'baserowImporter', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('baserow_importer_nonce'),
            'confirm_delete' => __('Are you sure you want to delete this product? This will remove it from WooCommerce and update Baserow.', 'baserow-importer'),
            'timeout_error' => __('The request timed out. Please try again.', 'baserow-importer'),
            'network_error' => __('A network error occurred. Please check your connection and try again.', 'baserow-importer')
        ));
    }

    private function check_api_configuration() {
        $api_url = get_option('baserow_api_url');
        $api_token = get_option('baserow_api_token');
        $table_id = get_option('baserow_table_id');
        
        return !empty($api_url) && !empty($api_token) && !empty($table_id);
    }

    private function render_configuration_notice() {
        ?>
        <div class="wrap">
            <h1>Baserow Product Importer</h1>
            <div class="notice notice-warning">
                <p>Please configure your Baserow settings before using the importer. 
                   <a href="<?php echo admin_url('admin.php?page=baserow-importer-settings'); ?>">Go to Settings</a>
                </p>
            </div>
        </div>
        <?php
    }

    private function render_import_results() {
        $import_results = get_transient('baserow_import_results');
        if ($import_results) {
            delete_transient('baserow_import_results');
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Product imported successfully:</p>
                <ul>
                    <li>Title: <?php echo esc_html($import_results['title']); ?></li>
                    <li>SKU: <?php echo esc_html($import_results['sku']); ?></li>
                    <li>Category: <?php echo esc_html($import_results['category']); ?></li>
                </ul>
            </div>
            <?php
        }
    }

    public function render_admin_page() {
        if (!$this->check_api_configuration()) {
            $this->render_configuration_notice();
            return;
        }

        $this->render_import_results();
        ?>
        <div class="wrap">
            <h1>Baserow Product Importer</h1>

            <div class="baserow-search-container">
                <div class="baserow-search-controls">
                    <input type="text" id="baserow-search" placeholder="Search products..." class="regular-text">
                    <select id="baserow-category-filter">
                        <option value="">All Categories</option>
                    </select>
                    <button class="button button-primary" id="search-products">Search Products</button>
                </div>
            </div>

            <div id="baserow-products-grid" class="products-grid">
                <div class="loading">Loading products...</div>
            </div>

            <div class="baserow-pagination">
                <div class="tablenav-pages">
                    <span class="pagination-links">
                        <button class="button prev-page" aria-disabled="true" disabled>&lsaquo;</button>
                        <span class="paging-input">
                            <span class="current-page">1</span>
                            <span class="total-pages"></span>
                        </span>
                        <button class="button next-page">&rsaquo;</button>
                    </span>
                </div>
            </div>

            <div id="loading-overlay" class="loading-overlay" style="display: none;">
                <div class="loading-content">
                    <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
                    Processing...
                </div>
            </div>
        </div>
        <?php
    }

    public function get_categories() {
        try {
            check_ajax_referer('baserow_importer_nonce', 'nonce');
            nocache_headers();
            
            Baserow_Logger::info("AJAX: Getting categories");
            
            $categories = $this->api_handler->get_categories();
            
            if (is_wp_error($categories)) {
                $error_message = $categories->get_error_message();
                Baserow_Logger::error("AJAX: Category error - " . $error_message);
                
                if (strpos($error_message, 'timed out') !== false) {
                    wp_send_json_error(array(
                        'message' => 'The request timed out. Please try again.',
                        'code' => 'timeout'
                    ));
                } else {
                    wp_send_json_error(array(
                        'message' => $error_message,
                        'code' => 'api_error'
                    ));
                }
                return;
            }

            if (empty($categories)) {
                Baserow_Logger::warning("AJAX: No categories found");
                wp_send_json_error(array(
                    'message' => 'No categories found.',
                    'code' => 'no_categories'
                ));
                return;
            }

            Baserow_Logger::debug("AJAX: Categories response - " . print_r($categories, true));
            wp_send_json_success(array('categories' => $categories));

        } catch (Exception $e) {
            Baserow_Logger::error("AJAX: Category exception - " . $e->getMessage());
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'exception'
            ));
        }
    }

    // [Rest of the class methods remain unchanged...]
}
