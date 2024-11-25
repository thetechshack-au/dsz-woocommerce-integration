<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Order_Display {
    public function __construct() {
        // Hook into order display
        add_action('woocommerce_admin_order_items_after_line_items', array($this, 'add_supplier_grouping'), 10, 1);
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_frontend_supplier_grouping'), 10, 1);
        
        // Add meta box to order page
        add_action('add_meta_boxes', array($this, 'add_supplier_meta_box'));
    }

    /**
     * Add meta box to order page showing supplier grouping
     */
    public function add_supplier_meta_box() {
        add_meta_box(
            'wc_supplier_grouping',
            __('Order Items by Supplier', 'baserow-importer'),
            array($this, 'render_supplier_meta_box'),
            'shop_order',
            'normal',
            'default'
        );
    }

    /**
     * Render the supplier meta box content
     */
    public function render_supplier_meta_box($post) {
        $order = wc_get_order($post->ID);
        if (!$order) return;

        $grouped_items = $this->group_order_items($order);
        $this->render_grouped_items($grouped_items, $order);
    }

    /**
     * Group order items by supplier
     */
    private function group_order_items($order) {
        $grouped_items = array(
            'dsz' => array(),
            'eleganter' => array(),
            'self_warehoused' => array()
        );

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $source = 'self_warehoused'; // Default source
            $terms = wp_get_object_terms($product->get_id(), 'product_source');
            
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if ($term->slug === 'dsz') {
                        $source = 'dsz';
                        break;
                    } elseif ($term->slug === 'eleganter') {
                        $source = 'eleganter';
                        break;
                    }
                }
            }

            $grouped_items[$source][] = array(
                'item' => $item,
                'product' => $product
            );
        }

        return $grouped_items;
    }

    /**
     * Render grouped items
     */
    private function render_grouped_items($grouped_items, $order) {
        $supplier_names = array(
            'dsz' => 'Dropshipzone',
            'eleganter' => 'Eleganter',
            'self_warehoused' => 'Warehouse'
        );

        foreach ($grouped_items as $source => $items) {
            if (empty($items)) continue;

            $tracking_number = '';
            if ($source === 'dsz') {
                $tracking_number = get_post_meta($order->get_id(), '_dsz_tracking_number', true);
            }
            ?>
            <div class="supplier-group" style="margin-bottom: 20px; padding: 10px; background: #f8f9fa; border: 1px solid #ddd;">
                <h3 style="margin: 0 0 10px;">
                    <?php echo esc_html($supplier_names[$source]); ?>
                    <?php if ($tracking_number): ?>
                        <span style="font-size: 0.9em; color: #666; margin-left: 10px;">
                            Tracking: <?php echo esc_html($tracking_number); ?>
                        </span>
                    <?php endif; ?>
                </h3>

                <table class="wc-order-items" style="width: 100%;">
                    <thead>
                        <tr>
                            <th class="item" colspan="2">Item</th>
                            <th class="quantity">Quantity</th>
                            <th class="price">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item_data): 
                            $item = $item_data['item'];
                            $product = $item_data['product'];
                            ?>
                            <tr>
                                <td class="thumb">
                                    <?php echo $product ? $product->get_image(array(38, 38)) : ''; ?>
                                </td>
                                <td class="name">
                                    <?php 
                                    echo $product ? $product->get_name() : $item->get_name();
                                    echo '<br><small>SKU: ' . ($product ? $product->get_sku() : 'N/A') . '</small>';
                                    ?>
                                </td>
                                <td class="quantity">
                                    <?php echo $item->get_quantity(); ?>
                                </td>
                                <td class="price">
                                    <?php echo wc_price($item->get_total()); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }

    /**
     * Add supplier grouping to admin order page
     */
    public function add_supplier_grouping($order) {
        // The meta box will handle this display
    }

    /**
     * Add supplier grouping to frontend order page
     */
    public function add_frontend_supplier_grouping($order) {
        if (!$order) return;

        $grouped_items = $this->group_order_items($order);
        ?>
        <h2>Order Items by Location</h2>
        <?php
        $this->render_grouped_items($grouped_items, $order);
    }
}

// Initialize the display handler
new Baserow_Order_Display();
