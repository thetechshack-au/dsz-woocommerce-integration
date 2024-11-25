<?php
/**
 * Class: Baserow Order AJAX Handler
 * Description: Handles AJAX operations for orders
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Order_Ajax {
    use Baserow_Logger_Trait;

    private $order_handler;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_check_order_sync_status', array($this, 'check_sync_status'));
        add_action('wp_ajax_retry_order_sync', array($this, 'retry_sync'));
        add_action('wp_ajax_get_dsz_order_details', array($this, 'get_dsz_order_details'));
        add_action('wp_ajax_get_sync_statistics', array($this, 'get_sync_statistics'));
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($order_handler) {
        $this->order_handler = $order_handler;
    }

    /**
     * Handle check sync status AJAX request
     */
    public function check_sync_status() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error('Order ID is required');
            return;
        }

        $this->log_debug("Checking order sync status", array(
            'order_id' => $order_id
        ));

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'baserow_dsz_orders';

            $sync_status = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %d",
                $order_id
            ));

            if (!$sync_status) {
                wp_send_json_success(array(
                    'synced' => false,
                    'message' => 'Order has not been synced with DSZ'
                ));
                return;
            }

            $response = array(
                'synced' => true,
                'status' => $sync_status->status,
                'dsz_reference' => $sync_status->dsz_reference,
                'last_sync' => $sync_status->sync_date,
                'error' => $sync_status->last_error
            );

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Error checking sync status", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle retry sync AJAX request
     */
    public function retry_sync() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error('Order ID is required');
            return;
        }

        $this->log_debug("Retrying order sync", array(
            'order_id' => $order_id
        ));

        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error('Order not found');
                return;
            }

            // Process the order again
            $result = $this->order_handler->process_new_order($order_id, array(), $order);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
                return;
            }

            wp_send_json_success(array(
                'message' => 'Order sync retry initiated successfully'
            ));

        } catch (Exception $e) {
            $this->log_error("Error retrying order sync", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get DSZ order details AJAX request
     */
    public function get_dsz_order_details() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $dsz_reference = isset($_GET['dsz_reference']) ? sanitize_text_field($_GET['dsz_reference']) : '';
        
        if (empty($dsz_reference)) {
            wp_send_json_error('DSZ reference is required');
            return;
        }

        $this->log_debug("Getting DSZ order details", array(
            'reference' => $dsz_reference
        ));

        try {
            $details = $this->order_handler->get_dsz_order_details($dsz_reference);

            if (is_wp_error($details)) {
                wp_send_json_error($details->get_error_message());
                return;
            }

            wp_send_json_success($details);

        } catch (Exception $e) {
            $this->log_error("Error getting DSZ order details", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get sync statistics AJAX request
     */
    public function get_sync_statistics() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'baserow_dsz_orders';

            $stats = array(
                'total_orders' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
                'successful_syncs' => $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE status = %s",
                        'success'
                    )
                ),
                'failed_syncs' => $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE status = %s",
                        'failed'
                    )
                ),
                'recent_syncs' => $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE sync_date > %s",
                        date('Y-m-d H:i:s', strtotime('-24 hours'))
                    )
                )
            );

            wp_send_json_success($stats);

        } catch (Exception $e) {
            $this->log_error("Error getting sync statistics", array(
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
