<?php
/**
 * Class: Baserow Product Status
 * Description: Adds stock status indicators to WooCommerce product list
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Status {
    
    public function __construct() {
        // Add filter to modify the product status display in admin
        add_action('admin_head', array($this, 'add_stock_status_styles'));
        add_filter('woocommerce_admin_stock_html', array($this, 'modify_stock_status_display'), 10, 2);
    }

    /**
     * Add CSS styles for stock status badges
     */
    public function add_stock_status_styles() {
        ?>
        <style>
            .stock-status {
                display: inline-block;
                padding: 3px 5px;
                border-radius: 3px;
                color: white;
                font-size: 11px;
                font-weight: bold;
                margin-right: 5px;
            }
            .stock-status.out-of-stock {
                background-color: #e2401c;
            }
            .stock-status.in-stock {
                background-color: #7ad03a;
            }
        </style>
        <?php
    }

    /**
     * Modify stock status display to show out-of-stock badge
     */
    public function modify_stock_status_display($stock_html, $product) {
        $stock_status = $product->get_stock_status();
        $stock_qty = $product->get_stock_quantity();
        
        // Add stock status badge
        if ($stock_status === 'outofstock' || $stock_qty === 0) {
            $stock_html .= '<span class="stock-status out-of-stock">OUT OF STOCK</span>';
        }
        
        return $stock_html;
    }
}

// Initialize the class
function baserow_product_status_init() {
    new Baserow_Product_Status();
}
add_action('init', 'baserow_product_status_init');
