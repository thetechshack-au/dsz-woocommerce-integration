<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin_Order_Actions {
    public function __construct() {
        add_action('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_sync_to_dsz', array($this, 'process_sync_to_dsz_action'));
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_order_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_order_bulk_actions'), 10, 3);
    }

    public function add_order_actions($actions) {
        global $theorder;
        
        // Check if order has DSZ items
        $has_dsz_items = false;
        foreach ($theorder->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $terms = wp_get_object_terms($product->get_id(), 'product_source');
            foreach ($terms as $term) {
                if ($term->slug === 'dsz') {
                    $has_dsz_items = true;
                    break 2;
                }
            }
        }
        
        if ($has_dsz_items) {
            $actions['sync_to_dsz'] = __('Sync to DSZ', 'baserow-importer');
        }
        
        return $actions;
    }

    public function process_sync_to_dsz_action($order) {
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        $order_handler->handle_new_order($order->get_id());
    }

    public function add_order_bulk_actions($actions) {
        $actions['sync_to_dsz_bulk'] = __('Sync to DSZ', 'baserow-importer');
        return $actions;
    }

    public function handle_order_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'sync_to_dsz_bulk') {
            return $redirect_to;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-order-handler.php';
        $order_handler = new Baserow_Order_Handler();
        
        $synced = 0;
        foreach ($post_ids as $post_id) {
            $order_handler->handle_new_order($post_id);
            $synced++;
        }

        $redirect_to = add_query_arg('synced_to_dsz', $synced, $redirect_to);
        return $redirect_to;
    }
}
