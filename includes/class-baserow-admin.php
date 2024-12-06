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
        add_action('wp_ajax_search_products', array($this, 'search_products'));
        add_action('wp_ajax_import_product', array($this, 'import_product'));
        add_action('wp_ajax_delete_product', array($this, 'delete_product'));
        add_action('wp_ajax_get_categories', array($this, 'get_categories'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'DropshipZone Products',
            'DropshipZone Products',
            'manage_options',
            'baserow-importer',
            array($this, 'render_admin_page'),
            'dashicons-database-import',
            56
        );

        add_submenu_page(
            'baserow-importer',
            'Settings',
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
        $nonce = wp_create_nonce('baserow_importer_nonce');
        ?>
        <div class="wrap">
            <h1>DropshipZone Products</h1>

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
            <h1>DropshipZone Products</h1>
            <div class="notice notice-warning">
                <p>Please configure your settings before using the importer. 
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
        check_ajax_referer('baserow_importer_nonce', 'nonce');
        
        nocache_headers();
        
        $categories = $this->api_handler->get_categories();
        
        if (is_wp_error($categories)) {
            wp_send_json_error(array('message' => $categories->get_error_message()));
            return;
        }

        wp_send_json_success(array('categories' => $categories));
    }

    public function search_products() {
        check_ajax_referer('baserow_importer_nonce', 'nonce');
        
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

                $product['image_url'] = !empty($product['Image 1']) ? $product['Image 1'] : '';
                $product['price'] = !empty($product['Price']) ? number_format((float)$product['Price'], 2, '.', '') : '0.00';
                $product['cost_price'] = !empty($product['Cost Price']) ? number_format((float)$product['Cost Price'], 2, '.', '') : null;
                $product['DI'] = !empty($product['DI']) ? $product['DI'] : 'No';
                $product['au_free_shipping'] = !empty($product['au_free_shipping']) ? $product['au_free_shipping'] : 'No';
                $product['new_arrival'] = !empty($product['new_arrival']) ? $product['new_arrival'] : 'No';
            }
        }

        Baserow_Logger::debug("Search response - count: " . count($result['results']));
        wp_send_json_success($result);
    }

    public function delete_product() {
        check_ajax_referer('baserow_importer_nonce', 'nonce');
        
        nocache_headers();

        if (!isset($_POST['product_id'])) {
            wp_send_json_error(array('message' => 'Product ID not provided'));
            return;
        }

        $woo_product_id = intval($_POST['product_id']);
        $product = wc_get_product($woo_product_id);

        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
            return;
        }

        // The deletion hook will handle updating Baserow and the tracking table
        wp_delete_post($woo_product_id, true);

        wp_send_json_success(array(
            'message' => 'Product deleted successfully'
        ));
    }

    private function validate_import_request() {
        if (!check_ajax_referer('baserow_importer_nonce', 'nonce', false)) {
            throw new Exception("Security check failed");
        }

        if (!isset($_POST['product_id'])) {
            throw new Exception("Product ID not provided");
        }

        if (!class_exists('WooCommerce')) {
            throw new Exception("WooCommerce is not active");
        }
    }

    private function update_baserow_status($product_id, $woo_product_id) {
        $update_data = array(
            'imported_to_woo' => true,
            'woo_product_id' => $woo_product_id,
            'last_import_date' => date('Y-m-d')
        );

        Baserow_Logger::debug("Attempting to update Baserow with data: " . print_r($update_data, true));

        $update_result = $this->api_handler->update_product($product_id, $update_data);

        if (is_wp_error($update_result)) {
            Baserow_Logger::error("Failed to update Baserow status: " . $update_result->get_error_message());
            return false;
        }

        Baserow_Logger::info("Successfully updated Baserow status");
        return true;
    }

    public function import_product() {
        try {
            nocache_headers();
            
            Baserow_Logger::info("Import product AJAX handler started");

            $this->validate_import_request();

            $product_id = sanitize_text_field($_POST['product_id']);
            Baserow_Logger::info("Starting import for product ID: " . $product_id);

            // Get product data
            $product_data = $this->api_handler->get_product($product_id);
            if (is_wp_error($product_data)) {
                throw new Exception("Failed to get product data: " . $product_data->get_error_message());
            }

            $result = $this->product_importer->import_product($product_id);
            if (is_wp_error($result)) {
                throw new Exception("Import failed: " . $result->get_error_message());
            }

            if (!$result['success']) {
                throw new Exception("Import failed: Unknown error");
            }

            // Update Baserow status
            $this->update_baserow_status($product_id, $result['product_id']);

            // Store import results
            set_transient('baserow_import_results', array(
                'title' => $product_data['Title'],
                'sku' => $product_data['SKU'],
                'category' => $product_data['Category']
            ), 30);

            Baserow_Logger::info("Import completed successfully for product ID: " . $product_id);
            wp_send_json_success(array(
                'message' => 'Product imported successfully',
                'redirect' => true
            ));

        } catch (Exception $e) {
            Baserow_Logger::error("Import failed: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
