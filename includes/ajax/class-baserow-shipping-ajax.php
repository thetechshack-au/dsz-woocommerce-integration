<?php
/**
 * Class: Baserow Shipping AJAX Handler
 * Description: Handles AJAX operations for shipping
 * Version: 1.6.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Shipping_Ajax {
    use Baserow_Logger_Trait;

    private $shipping_zone_manager;
    private $postcode_mapper;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_calculate_shipping', array($this, 'calculate_shipping'));
        add_action('wp_ajax_nopriv_calculate_shipping', array($this, 'calculate_shipping'));
        add_action('wp_ajax_validate_postcode', array($this, 'validate_postcode'));
        add_action('wp_ajax_nopriv_validate_postcode', array($this, 'validate_postcode'));
        add_action('wp_ajax_get_shipping_zones', array($this, 'get_shipping_zones'));
        add_action('wp_ajax_update_shipping_rates', array($this, 'update_shipping_rates'));
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($shipping_zone_manager, $postcode_mapper) {
        $this->shipping_zone_manager = $shipping_zone_manager;
        $this->postcode_mapper = $postcode_mapper;
    }

    /**
     * Handle shipping calculation AJAX request
     */
    public function calculate_shipping() {
        $this->verify_ajax_nonce();

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';

        if (!$product_id || empty($postcode)) {
            wp_send_json_error('Product ID and postcode are required');
            return;
        }

        $this->log_debug("Calculating shipping", array(
            'product_id' => $product_id,
            'postcode' => $postcode
        ));

        try {
            // Validate postcode
            $validation_result = $this->postcode_mapper->validate_postcode($postcode);
            if (is_wp_error($validation_result)) {
                wp_send_json_error($validation_result->get_error_message());
                return;
            }

            // Calculate shipping cost
            $cost = $this->shipping_zone_manager->calculate_shipping_cost($product_id, $postcode);
            
            if (is_wp_error($cost)) {
                wp_send_json_error($cost->get_error_message());
                return;
            }

            $response = array(
                'cost' => $cost,
                'formatted_cost' => wc_price($cost),
                'zone' => $this->shipping_zone_manager->get_zone_for_postcode($postcode)
            );

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Shipping calculation error", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle postcode validation AJAX request
     */
    public function validate_postcode() {
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';

        if (empty($postcode)) {
            wp_send_json_error('Postcode is required');
            return;
        }

        $this->log_debug("Validating postcode", array(
            'postcode' => $postcode
        ));

        try {
            $validation_result = $this->postcode_mapper->validate_postcode($postcode);
            
            if (is_wp_error($validation_result)) {
                wp_send_json_error($validation_result->get_error_message());
                return;
            }

            $response = array(
                'valid' => true,
                'state' => $this->postcode_mapper->get_state_for_postcode($postcode),
                'is_metropolitan' => $this->postcode_mapper->is_metropolitan($postcode)
            );

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Postcode validation error", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get shipping zones AJAX request
     */
    public function get_shipping_zones() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $zones = $this->shipping_zone_manager->get_all_zones();
            
            $response = array(
                'zones' => $zones,
                'regional_postcodes' => array()
            );

            // Add regional postcode ranges for each zone
            foreach ($zones as $zone) {
                if (strpos($zone, '_R') !== false) {
                    $response['regional_postcodes'][$zone] = 
                        $this->shipping_zone_manager->get_regional_postcodes($zone);
                }
            }

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Error getting shipping zones", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle update shipping rates AJAX request
     */
    public function update_shipping_rates() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $shipping_data = isset($_POST['shipping_data']) ? $_POST['shipping_data'] : array();

        if (!$product_id || empty($shipping_data)) {
            wp_send_json_error('Product ID and shipping data are required');
            return;
        }

        $this->log_debug("Updating shipping rates", array(
            'product_id' => $product_id
        ));

        try {
            // Validate shipping data
            $validation_result = $this->shipping_zone_manager->validate_product_shipping_data($shipping_data);
            if (is_wp_error($validation_result)) {
                wp_send_json_error($validation_result->get_error_message());
                return;
            }

            // Update product shipping data
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error('Product not found');
                return;
            }

            $product->update_meta_data('_dsz_shipping_data', $shipping_data);
            $product->save();

            wp_send_json_success(array(
                'message' => 'Shipping rates updated successfully'
            ));

        } catch (Exception $e) {
            $this->log_error("Error updating shipping rates", array(
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
