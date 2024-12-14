<?php
/**
 * Class: Baserow Stock Handler
 * Description: Handles automatic stock synchronization between Baserow and WooCommerce
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Stock_Handler {
    use Baserow_Logger_Trait;

    private $api_handler;
    private $product_tracker;
    private $batch_size = 50;
    private $max_retries = 3;

    public function __construct($api_handler, $product_tracker) {
        $this->api_handler = $api_handler;
        $this->product_tracker = $product_tracker;

        // Register cron hook
        add_action('baserow_stock_sync', array($this, 'sync_stock_levels'));
    }

    /**
     * Schedule stock synchronization
     */
    public function schedule_sync() {
        if (!wp_next_scheduled('baserow_stock_sync')) {
            wp_schedule_event(time(), 'hourly', 'baserow_stock_sync');
            $this->log_info('Stock sync scheduled');
        }
    }

    /**
     * Unschedule stock synchronization
     */
    public function unschedule_sync() {
        $timestamp = wp_next_scheduled('baserow_stock_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'baserow_stock_sync');
            $this->log_info('Stock sync unscheduled');
        }
    }

    /**
     * Main stock synchronization method
     */
    public function sync_stock_levels() {
        $this->log_info('Starting stock synchronization');
        
        try {
            // Get products needing sync
            $products = $this->product_tracker->get_products_needing_sync(1); // Check products not synced in last hour
            
            if (empty($products)) {
                $this->log_info('No products need stock sync');
                return;
            }

            $this->log_info('Found products for stock sync', array('count' => count($products)));

            // Process in batches
            $batches = array_chunk($products, $this->batch_size);
            
            foreach ($batches as $batch) {
                $this->process_batch($batch);
            }

            $this->log_info('Stock synchronization completed');

        } catch (Exception $e) {
            $this->log_error('Stock sync failed', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }

    /**
     * Process a batch of products
     */
    private function process_batch($products) {
        foreach ($products as $product) {
            $this->sync_single_product_stock($product);
        }
    }

    /**
     * Sync stock for a single product
     */
    private function sync_single_product_stock($product) {
        try {
            // Get current WooCommerce product
            $woo_product = wc_get_product($product->woo_product_id);
            if (!$woo_product) {
                $this->log_error('WooCommerce product not found', array(
                    'woo_product_id' => $product->woo_product_id
                ));
                return;
            }

            // Get Baserow product data
            $baserow_data = $this->api_handler->get_product($product->baserow_id);
            if (is_wp_error($baserow_data)) {
                $this->log_error('Failed to get Baserow product data', array(
                    'baserow_id' => $product->baserow_id,
                    'error' => $baserow_data->get_error_message()
                ));
                return;
            }

            // Compare and update stock if needed
            $current_stock = $woo_product->get_stock_quantity();
            $baserow_stock = isset($baserow_data['stock_quantity']) ? intval($baserow_data['stock_quantity']) : null;

            if ($baserow_stock !== null && $current_stock !== $baserow_stock) {
                $woo_product->set_stock_quantity($baserow_stock);
                $woo_product->save();

                $this->log_info('Stock updated', array(
                    'product_id' => $woo_product->get_id(),
                    'old_stock' => $current_stock,
                    'new_stock' => $baserow_stock
                ));
            }

            // Update sync time
            $this->product_tracker->update_sync_time($product->baserow_id);

        } catch (Exception $e) {
            $this->log_error('Failed to sync product stock', array(
                'product' => $product,
                'error' => $e->getMessage()
            ));
        }
    }
}
