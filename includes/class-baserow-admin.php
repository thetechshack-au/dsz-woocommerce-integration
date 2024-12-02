<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin {
    private $api_handler;
    private $product_importer;
    private $settings;
    private $ajax_handler;
    private $product_tracker;

    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;
        $this->product_tracker = new Baserow_Product_Tracker();
        
        // Initialize AJAX handler with dependencies
        $this->ajax_handler = new Baserow_Product_Ajax();
        $this->ajax_handler->set_dependencies($this->product_importer, $this->product_tracker);
        
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_test_baserow_connection', array($this, 'test_baserow_connection'));
        add_action('wp_ajax_search_products', array($this, 'search_products'));
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
            BASEROW_IMPORTER_VERSION . '.' . time(),
            true
        );

        // Add localization for price labels
        wp_localize_script('baserow-importer-js', 'baserowLabels', array(
            'price' => 'Price',
            'costPrice' => 'Cost Price'
        ));
    }

    private function check_api_configuration() {
        $api_url = get_option('baserow_api_url');
        $api_token = get_option('baserow_api_token');
        $table_id = get_option('baserow_table_id');
        
        return !empty($api_url) && !empty($api_token) && !empty($table_id);
    }

    public function render_admin_page() {
        if (!$this->check_api_configuration()) {
            $this->render_configuration_notice();
            return;
        }

        $this->render_import_results();

        // Create nonce
        $nonce = wp_create_nonce('baserow_ajax_nonce');
        ?>
        <div class="wrap">
            <h1>Baserow Product Importer</h1>

            <div class="baserow-search-container">
                <div class="baserow-search-controls" 
                     data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
                     data-nonce="<?php echo esc_attr($nonce); ?>">
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

    public function get_categories() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');
        
        nocache_headers();
        
        $categories = $this->api_handler->get_categories();
        
        if (is_wp_error($categories)) {
            wp_send_json_error(array('message' => $categories->get_error_message()));
            return;
        }

        wp_send_json_success(array('categories' => $categories));
    }

    public function search_products() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');
        
        nocache_headers();
        
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;

        Baserow_Logger::debug("Search request - term: " . $search_term . ", category: " . $category . ", page: " . $page);
        
        $result = $this->api_handler->search_products($search_term, $category, $page);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        if (!empty($result['results'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'baserow_imported_products';

            foreach ($result['results'] as &$product) {
                $tracking_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT woo_product_id FROM $table_name WHERE baserow_id = %s",
                    $product['id']
                ));

                $woo_product_id = $tracking_data ? $tracking_data->woo_product_id : null;
                $woo_product = $woo_product_id ? wc_get_product($woo_product_id) : null;

                $product['baserow_imported'] = !empty($product['imported_to_woo']);
                $product['woo_exists'] = ($woo_product && $woo_product->get_status() !== 'trash');

                if ($product['woo_exists']) {
                    $product['woo_product_id'] = $woo_product_id;
                    $product['woo_url'] = get_edit_post_link($woo_product_id, '');
                } else if ($tracking_data) {
                    $wpdb->delete($table_name, array('baserow_id' => $product['id']));
                }

                // Process product data
                $product['image_url'] = !empty($product['Image URL']) ? $product['Image URL'] : '';
                
                // Fix price field mapping
                $product['price'] = !empty($product['RrpPrice']) ? 
                    number_format((float)$product['RrpPrice'], 2, '.', '') : '0.00';
                $product['cost_price'] = !empty($product['Price']) ? 
                    number_format((float)$product['Price'], 2, '.', '') : '0.00';

                // Process flags
                $product['DI'] = !empty($product['DI']) ? $product['DI'] : 'No';
                $product['au_free_shipping'] = !empty($product['au_free_shipping']) ? $product['au_free_shipping'] : 'No';
                $product['new_arrival'] = !empty($product['new_arrival']) ? $product['new_arrival'] : 'No';

                Baserow_Logger::debug("Processed product prices", [
                    'id' => $product['id'],
                    'RrpPrice' => $product['RrpPrice'] ?? 'not set',
                    'Price' => $product['Price'] ?? 'not set',
                    'mapped_price' => $product['price'],
                    'mapped_cost_price' => $product['cost_price']
                ]);
            }
        }

        Baserow_Logger::debug("Search response - count: " . count($result['results']));
        wp_send_json_success($result);
    }

    public function test_baserow_connection() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');
        
        nocache_headers();
        
        $result = $this->api_handler->test_connection();
        
        if ($result) {
            wp_send_json_success(array('message' => 'Connection successful'));
        } else {
            wp_send_json_error(array('message' => 'Connection failed'));
        }
    }
}
