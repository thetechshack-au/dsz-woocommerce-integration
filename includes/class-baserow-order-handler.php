<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-auth-handler.php';
require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-logger.php';

class Baserow_Order_Handler {
    private $auth_handler;
    private $api_url = 'https://api.dropshipzone.com.au';

    public function __construct() {
        $this->auth_handler = new Baserow_Auth_Handler();
        
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_processing', array($this, 'handle_new_order'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'handle_new_order'), 10, 1);
    }

    /**
     * Handle new orders that need to be sent to DSZ
     *
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function handle_new_order($order_id) {
        Baserow_Logger::info("Processing order #{$order_id} for DSZ sync");

        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception("Could not load order #{$order_id}");
            }

            // Get all items from the order
            $items = $order->get_items();
            $dsz_items = array();

            // Filter items by product source
            foreach ($items as $item) {
                $product = $item->get_product();
                if (!$product) continue;

                $terms = wp_get_object_terms($product->get_id(), 'product_source');
                if (is_wp_error($terms)) continue;

                foreach ($terms as $term) {
                    if ($term->slug === 'dsz') {
                        $dsz_items[] = array(
                            'sku' => $product->get_sku(),
                            'qty' => $item->get_quantity()
                        );
                        break;
                    }
                }
            }

            // If no DSZ items, skip processing
            if (empty($dsz_items)) {
                Baserow_Logger::info("No DSZ items found in order #{$order_id}");
                return;
            }

            // Prepare order data for DSZ
            $shipping_address = $order->get_shipping_address_1();
            if (empty($shipping_address)) {
                $shipping_address = $order->get_billing_address_1();
            }

            $shipping_address_2 = $order->get_shipping_address_2();
            if (empty($shipping_address_2)) {
                $shipping_address_2 = $order->get_billing_address_2();
            }

            $order_data = array(
                'your_order_no' => $order->get_order_number(),
                'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
                'last_name' => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
                'address1' => $shipping_address,
                'address2' => $shipping_address_2,
                'suburb' => $order->get_shipping_city() ?: $order->get_billing_city(),
                'state' => $order->get_shipping_state() ?: $order->get_billing_state(),
                'postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                'telephone' => $order->get_billing_phone(),
                'comment' => $order->get_customer_note(),
                'order_items' => $dsz_items
            );

            // Submit order to DSZ
            $response = $this->submit_order_to_dsz($order_data);
            
            if (isset($response[0]['status']) && $response[0]['status'] === 1) {
                $serial_number = $response[0]['serial_number'];
                $order->add_order_note("Successfully submitted to DSZ. Reference: {$serial_number}");
                $order->update_meta_data('_dsz_order_reference', $serial_number);
                $order->save();
                Baserow_Logger::info("Order #{$order_id} successfully submitted to DSZ. Reference: {$serial_number}");
            } else {
                $error_message = isset($response[0]['errmsg']) ? $response[0]['errmsg'] : 'Unknown error';
                throw new Exception("DSZ order submission failed: {$error_message}");
            }

        } catch (Exception $e) {
            Baserow_Logger::error("Error processing order #{$order_id}: " . $e->getMessage());
            $order->add_order_note("Failed to submit to DSZ: " . $e->getMessage());
        }
    }

    /**
     * Submit order to DSZ API
     *
     * @param array $order_data Order data to submit
     * @return array Response from DSZ API
     * @throws Exception
     */
    private function submit_order_to_dsz($order_data) {
        $token = $this->auth_handler->get_token();
        if (is_wp_error($token)) {
            throw new Exception("Failed to get DSZ auth token: " . $token->get_error_message());
        }

        $response = wp_remote_post($this->api_url . '/placingOrder', array(
            'headers' => array(
                'Authorization' => "jwt {$token}",
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($order_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            throw new Exception("DSZ API request failed: " . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from DSZ API");
        }

        return $body;
    }
}

// Initialize the order handler
new Baserow_Order_Handler();
