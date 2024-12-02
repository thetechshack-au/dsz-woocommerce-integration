<?php
/**
 * Class: Baserow Product Tracker
 * Description: Handles tracking and logging of product imports and synchronization
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Tracker {
    use Baserow_Logger_Trait;

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'baserow_imported_products';
    }

    /**
     * Track a newly imported or updated product
     */
    public function track_product($baserow_id, $woo_product_id) {
        global $wpdb;

        $this->log_debug("Tracking product", array(
            'baserow_id' => $baserow_id,
            'woo_product_id' => $woo_product_id
        ));

        $result = $wpdb->replace(
            $this->table_name,
            array(
                'baserow_id' => $baserow_id,
                'woo_product_id' => $woo_product_id,
                'last_sync' => current_time('mysql')
            ),
            array('%s', '%d', '%s')
        );

        if ($result === false) {
            $this->log_error("Failed to track product", array(
                'error' => $wpdb->last_error
            ));
            return new WP_Error(
                'tracking_failed',
                'Failed to track product: ' . $wpdb->last_error
            );
        }

        $this->log_info("Product tracked successfully", array(
            'baserow_id' => $baserow_id,
            'woo_product_id' => $woo_product_id
        ));

        return true;
    }

    /**
     * Get WooCommerce product ID from Baserow ID
     */
    public function get_woo_product_id($baserow_id) {
        global $wpdb;

        $woo_product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT woo_product_id FROM {$this->table_name} WHERE baserow_id = %s",
            $baserow_id
        ));

        $this->log_debug("Retrieved WooCommerce product ID", array(
            'baserow_id' => $baserow_id,
            'woo_product_id' => $woo_product_id
        ));

        return $woo_product_id ? intval($woo_product_id) : null;
    }

    /**
     * Get Baserow ID from WooCommerce product ID
     */
    public function get_baserow_id($woo_product_id) {
        global $wpdb;

        $baserow_id = $wpdb->get_var($wpdb->prepare(
            "SELECT baserow_id FROM {$this->table_name} WHERE woo_product_id = %d",
            $woo_product_id
        ));

        $this->log_debug("Retrieved Baserow ID", array(
            'woo_product_id' => $woo_product_id,
            'baserow_id' => $baserow_id
        ));

        return $baserow_id;
    }

    /**
     * Get products that need synchronization
     */
    public function get_products_needing_sync($hours = 24) {
        global $wpdb;

        $cutoff_time = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT baserow_id, woo_product_id, last_sync 
            FROM {$this->table_name} 
            WHERE last_sync < %s",
            $cutoff_time
        ));

        $this->log_debug("Retrieved products needing sync", array(
            'count' => count($products),
            'cutoff_time' => $cutoff_time
        ));

        return $products;
    }

    /**
     * Update last sync time for a product
     */
    public function update_sync_time($baserow_id) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            array('last_sync' => current_time('mysql')),
            array('baserow_id' => $baserow_id),
            array('%s'),
            array('%s')
        );

        if ($result === false) {
            $this->log_error("Failed to update sync time", array(
                'baserow_id' => $baserow_id,
                'error' => $wpdb->last_error
            ));
            return new WP_Error(
                'sync_update_failed',
                'Failed to update sync time: ' . $wpdb->last_error
            );
        }

        $this->log_debug("Updated sync time", array(
            'baserow_id' => $baserow_id
        ));

        return true;
    }

    /**
     * Remove tracking for a product
     */
    public function remove_tracking($baserow_id = null, $woo_product_id = null) {
        global $wpdb;

        if (!$baserow_id && !$woo_product_id) {
            return new WP_Error(
                'invalid_params',
                'Either baserow_id or woo_product_id must be provided'
            );
        }

        $where = array();
        $where_format = array();

        if ($baserow_id) {
            $where['baserow_id'] = $baserow_id;
            $where_format[] = '%s';
        }

        if ($woo_product_id) {
            $where['woo_product_id'] = $woo_product_id;
            $where_format[] = '%d';
        }

        $result = $wpdb->delete($this->table_name, $where, $where_format);

        if ($result === false) {
            $this->log_error("Failed to remove tracking", array(
                'baserow_id' => $baserow_id,
                'woo_product_id' => $woo_product_id,
                'error' => $wpdb->last_error
            ));
            return new WP_Error(
                'tracking_removal_failed',
                'Failed to remove tracking: ' . $wpdb->last_error
            );
        }

        $this->log_info("Removed product tracking", array(
            'baserow_id' => $baserow_id,
            'woo_product_id' => $woo_product_id
        ));

        return true;
    }

    /**
     * Get import statistics
     */
    public function get_import_stats() {
        global $wpdb;

        $stats = array(
            'total_products' => 0,
            'recent_imports' => 0,
            'needs_sync' => 0,
            'last_import' => null
        );

        // Total products
        $stats['total_products'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        // Recent imports (last 24 hours)
        $stats['recent_imports'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE import_date > %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));

        // Products needing sync
        $stats['needs_sync'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE last_sync < %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));

        // Last import
        $stats['last_import'] = $wpdb->get_var(
            "SELECT import_date FROM {$this->table_name} ORDER BY import_date DESC LIMIT 1"
        );

        $this->log_debug("Retrieved import statistics", $stats);

        return $stats;
    }
}
