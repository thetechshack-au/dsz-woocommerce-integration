<?php
/**
 * Class: Baserow Product AJAX Handler
 * Description: Handles AJAX operations for products
 * Version: 1.5.0
 * Last Updated: 2024-01-15 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Ajax {
    use Baserow_Logger_Trait;

    private $product_importer;
    private $product_tracker;
    private $api_handler;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_import_baserow_product', array($this, 'import_product'));
        add_action('wp_ajax_sync_baserow_product', array($this, 'sync_product'));
        add_action('wp_ajax_get_product_status', array($this, 'get_product_status'));
        add_action('wp_ajax_get_import_stats', array($this, 'get_import_stats'));
        add_action('wp_ajax_search_baserow_products', array($this, 'search_products'));
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($product_importer, $product_tracker, $api_handler) {
        $this->product_importer = $product_importer;
        $this->product_tracker = $product_tracker;
        $this->api_handler = $api_handler;
    }

    /**
     * Handle product search AJAX request
     */
    public function search_products() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Validate and sanitize input parameters
        $search_args = [
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
            'sku' => isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : '',
            'category' => isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '',
            'page' => isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1,
            'sort_by' => isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'id',
            'sort_order' => isset($_GET['sort_order']) ? sanitize_text_field($_GET['sort_order']) : 'asc'
        ];

        $this->log_debug("Starting product search", [
            'search_args' => $search_args
        ]);

        try {
            // Perform search
            $results = $this->api_handler->search_products($search_args);

            if (is_wp_error($results)) {
                $this->log_error("Search failed", [
                    'error' => $results->get_error_message()
                ]);
                wp_send_json_error($results->get_error_message());
                return;
            }

            // Enhance results with WooCommerce data
            if (!empty($results['products'])) {
                foreach ($results['products'] as &$product) {
                    $woo_product_id = $this->product_tracker->get_woo_product_id($product['id']);
                    if ($woo_product_id) {
                        $woo_product = wc_get_product($woo_product_id);
                        if ($woo_product) {
                            $product['woo_status'] = [
                                'imported' => true,
                                'product_id' => $woo_product_id,
                                'status' => $woo_product->get_status(),
                                'stock' => $woo_product->get_stock_quantity(),
                                'price' => $woo_product->get_price(),
                                'last_sync' => get_post_meta($woo_product_id, '_last_baserow_sync', true)
                            ];
                        }
                    } else {
                        $product['woo_status'] = [
                            'imported' => false
                        ];
                    }
                }
            }

            $this->log_info("Search completed successfully", [
                'total_results' => $results['total'],
                'page' => $results['page']
            ]);

            wp_send_json_success($results);

        } catch (Exception $e) {
            $this->log_error("Search exception", [
                'error' => $e->getMessage()
            ]);
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle product import AJAX request
     */
    public function import_product() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required');
            return;
        }

        $this->log_debug("Starting AJAX product import", array(
            'product_id' => $product_id
        ));

        try {
            $result = $this->product_importer->import_product($product_id);

            if (is_wp_error($result)) {
                $this->log_error("AJAX import failed", array(
                    'error' => $result->get_error_message()
                ));
                wp_send_json_error($result->get_error_message());
                return;
            }

            $this->log_info("AJAX import successful", array(
                'result' => $result
            ));
            wp_send_json_success($result);

        } catch (Exception $e) {
            $this->log_error("AJAX import exception", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle product sync AJAX request
     */
    public function sync_product() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required');
            return;
        }

        $this->log_debug("Starting AJAX product sync", array(
            'product_id' => $product_id
        ));

        try {
            $result = $this->product_importer->import_product($product_id, true);

            if (is_wp_error($result)) {
                $this->log_error("AJAX sync failed", array(
                    'error' => $result->get_error_message()
                ));
                wp_send_json_error($result->get_error_message());
                return;
            }

            // Update sync time
            $this->product_tracker->update_sync_time($product_id);

            $this->log_info("AJAX sync successful", array(
                'result' => $result
            ));
            wp_send_json_success($result);

        } catch (Exception $e) {
            $this->log_error("AJAX sync exception", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get product status AJAX request
     */
    public function get_product_status() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_GET['product_id']) ? sanitize_text_field($_GET['product_id']) : '';
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required');
            return;
        }

        $this->log_debug("Getting product status", array(
            'product_id' => $product_id
        ));

        try {
            $woo_product_id = $this->product_tracker->get_woo_product_id($product_id);
            
            if (!$woo_product_id) {
                wp_send_json_success(array(
                    'imported' => false,
                    'message' => 'Product not imported'
                ));
                return;
            }

            $product = wc_get_product($woo_product_id);
            if (!$product) {
                wp_send_json_success(array(
                    'imported' => false,
                    'message' => 'WooCommerce product not found'
                ));
                return;
            }

            wp_send_json_success(array(
                'imported' => true,
                'woo_product_id' => $woo_product_id,
                'status' => $product->get_status(),
                'stock' => $product->get_stock_quantity(),
                'price' => $product->get_price(),
                'last_sync' => get_post_meta($woo_product_id, '_last_baserow_sync', true)
            ));

        } catch (Exception $e) {
            $this->log_error("Error getting product status", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get import stats AJAX request
     */
    public function get_import_stats() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $stats = $this->product_tracker->get_import_stats();
            wp_send_json_success($stats);

        } catch (Exception $e) {
            $this->log_error("Error getting import stats", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Verify AJAX nonce
     */
    private function verify_ajax_nonce() {
        if (!check_ajax_referer('baserow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
            exit;
        }
    }
}
