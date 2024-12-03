<?php
/**
 * Class: Baserow Order Handler
 * Description: Handles immediate order synchronization with Dropshipzone
 * Version: 1.6.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Order_Handler {
    use Baserow_Logger_Trait;
    use Baserow_API_Request_Trait;
    use Baserow_Data_Validator_Trait;

    private $api_handler;

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;

        // Hook into WooCommerce order creation
        add_action('woocommerce_checkout_order_processed', array($this, 'process_new_order'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Add order notes for sync status
        add_action('woocommerce_new_order_note', array($this, 'maybe_sync_order_note'), 10, 2);
    }

    /**
     * Process new order immediately after checkout
     */
    public function process_new_order($order_id, $posted_data, $order) {
        $this->log_debug("Processing new order for DSZ sync", array(
            'order_id' => $order_id
        ));

        try {
            // Check if order contains DSZ products
            if (!$this->has_dsz_products($order)) {
                $this->log_debug("Order has no DSZ products, skipping sync", array(
                    'order_id' => $order_id
                ));
                return;
            }

            // Prepare order data for DSZ
            $dsz_order_data = $this->prepare_order_data($order);
            
            // Send to DSZ immediately
            $result = $this->api_handler->create_dsz_order($dsz_order_data);

            if (is_wp_error($result)) {
                $this->log_error("Failed to sync order with DSZ", array(
                    'order_id' => $order_id,
                    'error' => $result->get_error_message()
                ));
                
                $order->add_order_note(
                    sprintf(__('Failed to sync order with DSZ: %s', 'baserow-importer'), 
                    $result->get_error_message())
                );
                
                // Track failed sync
                $this->track_order_sync($order_id, 'failed', $result->get_error_message());
                return;
            }

            // Add DSZ reference to order
            if (!empty($result['dsz_reference'])) {
                $order->update_meta_data('_dsz_reference', $result['dsz_reference']);
                $order->save();
            }

            $order->add_order_note(
                sprintf(__('Order successfully synced with DSZ. Reference: %s', 'baserow-importer'), 
                $result['dsz_reference'])
            );

            // Track successful sync
            $this->track_order_sync($order_id, 'success', '', $result['dsz_reference']);

            $this->log_info("Order successfully synced with DSZ", array(
                'order_id' => $order_id,
                'dsz_reference' => $result['dsz_reference']
            ));

        } catch (Exception $e) {
            $this->log_error("Exception during order sync", array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));
            
            $order->add_order_note(
                sprintf(__('Error syncing order with DSZ: %s', 'baserow-importer'), 
                $e->getMessage())
            );
            
            // Track failed sync
            $this->track_order_sync($order_id, 'failed', $e->getMessage());
        }
    }

    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        $this->log_debug("Order status changed", array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status
        ));

        // Check if order is synced with DSZ
        $dsz_reference = $order->get_meta('_dsz_reference');
        if (empty($dsz_reference)) {
            return;
        }

        try {
            // Map WooCommerce status to DSZ status
            $dsz_status = $this->map_order_status($new_status);
            
            // Update DSZ order status
            $result = $this->api_handler->update_dsz_order_status($dsz_reference, $dsz_status);

            if (is_wp_error($result)) {
                $this->log_error("Failed to update DSZ order status", array(
                    'order_id' => $order_id,
                    'error' => $result->get_error_message()
                ));
                
                $order->add_order_note(
                    sprintf(__('Failed to update DSZ order status: %s', 'baserow-importer'), 
                    $result->get_error_message())
                );
                return;
            }

            $order->add_order_note(
                sprintf(__('DSZ order status updated to: %s', 'baserow-importer'), 
                $dsz_status)
            );

        } catch (Exception $e) {
            $this->log_error("Exception updating DSZ order status", array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));
            
            $order->add_order_note(
                sprintf(__('Error updating DSZ order status: %s', 'baserow-importer'), 
                $e->getMessage())
            );
        }
    }

    /**
     * Check if order contains DSZ products
     */
    private function has_dsz_products($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $this->is_dsz_product($product)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if product is from DSZ
     */
    private function is_dsz_product($product) {
        $terms = wp_get_object_terms($product->get_id(), 'product_source');
        foreach ($terms as $term) {
            if ($term->slug === 'dsz') {
                return true;
            }
        }
        return false;
    }

    /**
     * Prepare order data for DSZ
     */
    private function prepare_order_data($order) {
        $data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $this->map_order_status($order->get_status()),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'customer' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone()
            ),
            'shipping_address' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country()
            ),
            'items' => array()
        );

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $this->is_dsz_product($product)) {
                $data['items'][] = array(
                    'product_id' => $product->get_meta('_baserow_id'),
                    'quantity' => $item->get_quantity(),
                    'sku' => $product->get_sku(),
                    'name' => $item->get_name(),
                    'price' => $item->get_total()
                );
            }
        }

        return $data;
    }

    /**
     * Map WooCommerce order status to DSZ status
     */
    private function map_order_status($wc_status) {
        $status_map = array(
            'pending' => 'pending',
            'processing' => 'processing',
            'on-hold' => 'on-hold',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed'
        );

        return isset($status_map[$wc_status]) ? $status_map[$wc_status] : 'pending';
    }

    /**
     * Track order sync status
     */
    private function track_order_sync($order_id, $status, $error = '', $dsz_reference = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_dsz_orders';

        $data = array(
            'order_id' => $order_id,
            'status' => $status,
            'last_error' => $error,
            'sync_date' => current_time('mysql')
        );

        if (!empty($dsz_reference)) {
            $data['dsz_reference'] = $dsz_reference;
        }

        $wpdb->replace(
            $table_name,
            $data,
            array('%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Handle order notes that might trigger a sync
     */
    public function maybe_sync_order_note($comment_id, $order) {
        $comment = get_comment($comment_id);
        
        // Check if note contains sync trigger text
        if (stripos($comment->comment_content, 'sync with dsz') !== false) {
            $this->process_new_order($order->get_id(), array(), $order);
        }
    }
}
