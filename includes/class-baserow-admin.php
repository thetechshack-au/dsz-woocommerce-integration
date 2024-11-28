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
                    <select id="baserow-category-filter" class="baserow-select" style="min-width: 300px;">
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
            
            $categories = $this->api_handler->get_categories();
            
            if (is_wp_error($categories)) {
                throw new Exception($categories->get_error_message());
            }

            // Ensure we have categories
            if (empty($categories)) {
                throw new Exception('No categories found');
            }

            // Format categories for display
            $formatted_categories = array();
            foreach ($categories as $category) {
                if (!empty($category)) {
                    $formatted_categories[] = array(
                        'value' => $category,
                        'label' => $category
                    );
                }
            }

            wp_send_json_success(array('categories' => $formatted_categories));
        } catch (Exception $e) {
            Baserow_Logger::error("Failed to get categories: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function search_products() {
        try {
            check_ajax_referer('baserow_importer_nonce', 'nonce');
            
            nocache_headers();
            
            $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
            $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
            
            $result = $this->api_handler->search_products($search_term, $category, $page);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
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

            wp_send_json_success($result);
        } catch (Exception $e) {
            Baserow_Logger::error("Search failed: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
