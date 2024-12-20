<?php
/**
 * Class: Baserow Product AJAX Handler
 * Description: Handles AJAX operations for products
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Ajax {
    use Baserow_Logger_Trait;

    private $product_importer;
    private $product_tracker;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_import_baserow_product', array($this, 'import_product'));
        add_action('wp_ajax_sync_baserow_product', array($this, 'sync_product'));
        add_action('wp_ajax_get_product_status', array($this, 'get_product_status'));
        add_action('wp_ajax_get_import_stats', array($this, 'get_import_stats'));
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($product_importer, $product_tracker) {
        $this->product_importer = $product_importer;
        $this->product_tracker = $product_tracker;
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
