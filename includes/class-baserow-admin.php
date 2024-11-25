<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Admin {
    private $api_handler;
    private $product_importer;
    private $settings;
    private $page_slug = 'baserow-importer';

    public function __construct($api_handler, $product_importer, $settings) {
        $this->api_handler = $api_handler;
        $this->product_importer = $product_importer;
        $this->settings = $settings;

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_import_products', array($this, 'handle_import_ajax'));
        add_action('wp_ajax_test_connection', array($this, 'handle_connection_test'));
        
        // Add order actions
        add_action('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_sync_to_dsz', array($this, 'process_sync_to_dsz_action'));
        
        // Add order list bulk actions
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_order_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_order_bulk_actions'), 10, 3);
        
        // Add admin pages
        add_action('admin_menu', array($this, 'add_order_status_page'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Baserow Importer',
            'Baserow Importer',
            'manage_options',
            $this->page_slug,
            array($this, 'render_admin_page'),
            'dashicons-database-import'
        );

        add_submenu_page(
            $this->page_slug,
            'Settings',
            'Settings',
            'manage_options',
            'baserow-importer-settings',
            array($this->settings, 'render_settings_page')
        );
    }

    public function add_order_status_page() {
        add_submenu_page(
            $this->page_slug,
            'DSZ Order Status',
            'DSZ Order Status',
            'manage_options',
            'baserow-dsz-orders',
            array($this, 'render_order_status_page')
        );
    }

    public function render_order_status_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_dsz_orders';
        
        // Get orders with DSZ items
        $orders = $wpdb->get_results("
            SELECT o.*, p.post_status as order_status 
            FROM {$table_name} o
            JOIN {$wpdb->posts} p ON o.order_id = p.ID
            ORDER BY o.sync_date DESC
        ");

        ?>
        <div class="wrap">
            <h1>DSZ Order Status</h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <button type="button" class="button action sync-failed-orders">Retry Failed Orders</button>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>DSZ Reference</th>
                        <th>Status</th>
                        <th>Sync Date</th>
                        <th>Retry Count</th>
                        <th>Last Error</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <a href="<?php echo get_edit_post_link($order->order_id); ?>">
                                    #<?php echo $order->order_id; ?>
                                </a>
                                <span class="order-status status-<?php echo sanitize_html_class($order->order_status); ?>">
                                    (<?php echo ucfirst($order->order_status); ?>)
                                </span>
                            </td>
                            <td><?php echo esc_html($order->dsz_reference); ?></td>
                            <td><?php echo esc_html(ucfirst($order->status)); ?></td>
                            <td><?php echo esc_html($order->sync_date); ?></td>
                            <td><?php echo esc_html($order->retry_count); ?></td>
                            <td><?php echo esc_html($order->last_error); ?></td>
                            <td>
                                <?php if ($order->status !== 'success'): ?>
                                    <button type="button" 
                                            class="button retry-sync" 
                                            data-order-id="<?php echo esc_attr($order->order_id); ?>">
                                        Retry Sync
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <style>
            .order-status {
                display: inline-block;
                margin-left: 5px;
                color: #777;
            }
            .status-wc-processing { color: #5b841b; }
            .status-wc-completed { color: #2e4453; }
            .status-wc-failed { color: #761919; }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('.retry-sync').on('click', function() {
                    const button = $(this);
                    const orderId = button.data('order-id');
                    
                    button.prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'retry_dsz_sync',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('retry_dsz_sync'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Sync failed: ' + response.data);
                                button.prop('disabled', false);
                            }
                        }
                    });
                });

                $('.sync-failed-orders').on('click', function() {
                    const button = $(this);
                    button.prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'retry_all_failed_dsz_sync',
                            nonce: '<?php echo wp_create_nonce('retry_all_failed_dsz_sync'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Bulk sync failed: ' + response.data);
                            }
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
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

    // ... rest of the existing class methods ...
}
