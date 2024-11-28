<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin functionality for the Baserow Importer
 */
class Baserow_Admin {
    /** @var Baserow_API_Handler */
    private $api_handler;

    /** @var object */
    private $product_importer;

    /** @var object */
    private $settings;

    /**
     * Constructor
     *
     * @param Baserow_API_Handler $api_handler The API handler instance
     * @param object $product_importer The product importer instance
     * @param object $settings The settings instance
     */
    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_test_baserow_connection', array($this, 'test_baserow_connection'));
        add_action('wp_ajax_search_products', array($this, 'search_products'));
        add_action('wp_ajax_import_product', array($this, 'import_product'));
        add_action('wp_ajax_delete_product', array($this, 'delete_product'));
        add_action('wp_ajax_get_categories', array($this, 'get_categories'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu(): void {
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

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page
     */
    public function enqueue_admin_scripts(string $hook): void {
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
            'confirm_delete' => __('Are you sure you want to delete this product? This will remove it from WooCommerce and update Baserow.', 'baserow-importer')
        ));
    }

    /**
     * Check if API configuration is complete
     *
     * @return bool True if configuration is complete, false otherwise
     */
    private function check_api_configuration(): bool {
        $api_url = get_option('baserow_api_url');
        $api_token = get_option('baserow_api_token');
        $table_id = get_option('baserow_table_id');
        
        return !empty($api_url) && !empty($api_token) && !empty($table_id);
    }

    /**
     * Render configuration notice
     */
    private function render_configuration_notice(): void {
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

    /**
     * Render import results
     */
    private function render_import_results(): void {
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

    /**
     * Render admin page
     */
    public function render_admin_page(): void {
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

    /**
     * AJAX handler for getting categories
     */
    public function get_categories(): void {
        try {
            if (!check_ajax_referer('baserow_importer_nonce', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            nocache_headers();
            
            $categories = $this->api_handler->get_categories();
            
            if (is_wp_error($categories)) {
                throw new Exception($categories->get_error_message());
            }

            wp_send_json_success(array('categories' => $categories));
        } catch (Exception $e) {
            Baserow_Logger::error("Failed to get categories: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler for testing Baserow connection
     */
    public function test_baserow_connection(): void {
        try {
            if (!check_ajax_referer('baserow_test_connection', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            nocache_headers();
            
            $result = $this->api_handler->test_connection();
            wp_send_json($result ? 
                array('success' => true, 'message' => 'Connection successful') : 
                array('success' => false, 'message' => 'Connection failed')
            );
        } catch (Exception $e) {
            Baserow_Logger::error("Connection test failed: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler for searching products
     */
    public function search_products(): void {
        try {
            if (!check_ajax_referer('baserow_importer_nonce', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            nocache_headers();

            // Validate and sanitize input parameters
            $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
            $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;

            // Perform search
            $result = $this->api_handler->search_products($search_term, $category, $page);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            if (!empty($result['results'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'baserow_imported_products';

                foreach ($result['results'] as &$product) {
                    // Get WooCommerce product ID from tracking table
                    $tracking_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT woo_product_id FROM $table_name WHERE baserow_id = %s",
                        $product['id']
                    ));

                    // Check if product exists in WooCommerce
                    $woo_product_id = $tracking_data ? $tracking_data->woo_product_id : null;
                    $woo_product = $woo_product_id ? wc_get_product($woo_product_id) : null;

                    // Set import statuses
                    $product['baserow_imported'] = !empty($product['imported_to_woo']);
                    $product['woo_exists'] = ($woo_product && $woo_product->get_status() !== 'trash');

                    if ($product['woo_exists']) {
                        $product['woo_product_id'] = $woo_product_id;
                        $product['woo_url'] = get_edit_post_link($woo_product_id, '');
                    } else if ($tracking_data) {
                        // Clean up stale tracking record
                        $wpdb->delete($table_name, array('baserow_id' => $product['id']));
                    }

                    // Get the first image URL
                    $product['image_url'] = !empty($product['Image 1']) ? $product['Image 1'] : '';

                    // Format price with $ symbol
                    $product['price'] = !empty($product['Price']) ? number_format((float)$product['Price'], 2, '.', '') : '0.00';
                    
                    // Add cost price if available
                    $product['cost_price'] = !empty($product['Cost Price']) ? number_format((float)$product['Cost Price'], 2, '.', '') : null;

                    // Add Direct Import status
                    $product['DI'] = !empty($product['DI']) ? $product['DI'] : 'No';

                    // Add Free Shipping status
                    $product['au_free_shipping'] = !empty($product['au_free_shipping']) ? $product['au_free_shipping'] : 'No';

                    // Add New Arrival status
                    $product['new_arrival'] = !empty($product['new_arrival']) ? $product['new_arrival'] : 'No';
                }
            }

            wp_send_json_success($result);
        } catch (Exception $e) {
            Baserow_Logger::error("Search failed: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler for deleting products
     */
    public function delete_product(): void {
        try {
            if (!check_ajax_referer('baserow_importer_nonce', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            nocache_headers();

            if (!isset($_POST['product_id'])) {
                throw new Exception('Product ID not provided');
            }

            $woo_product_id = intval($_POST['product_id']);
            $product = wc_get_product($woo_product_id);

            if (!$product) {
                throw new Exception('Product not found');
            }

            // The deletion hook will handle updating Baserow and the tracking table
            wp_delete_post($woo_product_id, true);

            wp_send_json_success(array(
                'message' => 'Product deleted successfully'
            ));
        } catch (Exception $e) {
            Baserow_Logger::error("Delete failed: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Validate import request
     *
     * @throws Exception If validation fails
     */
    private function validate_import_request(): void {
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

    /**
     * Update Baserow status
     *
     * @param string $product_id The product ID
     * @param int $woo_product_id The WooCommerce product ID
     * @return bool True if update successful, false otherwise
     */
    private function update_baserow_status(string $product_id, int $woo_product_id): bool {
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

    /**
     * AJAX handler for importing products
     */
    public function import_product(): void {
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
