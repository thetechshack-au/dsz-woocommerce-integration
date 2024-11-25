<?php
if (!defined('ABSPATH')) {
    exit;
}

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
    <h1><?php _e('DSZ Order Status', 'baserow-importer'); ?></h1>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button action sync-failed-orders">
                <?php _e('Retry Failed Orders', 'baserow-importer'); ?>
            </button>
        </div>
    </div>

    <div class="order-status-summary card">
        <?php
        $success_count = 0;
        $pending_count = 0;
        $failed_count = 0;

        foreach ($orders as $order) {
            if ($order->status === 'success') {
                $success_count++;
            } elseif ($order->status === 'pending') {
                $pending_count++;
            } else {
                $failed_count++;
            }
        }
        ?>
        <h3><?php _e('Summary', 'baserow-importer'); ?></h3>
        <div class="status-counts">
            <div class="status-count success">
                <span class="count"><?php echo $success_count; ?></span>
                <span class="label"><?php _e('Successful', 'baserow-importer'); ?></span>
            </div>
            <div class="status-count pending">
                <span class="count"><?php echo $pending_count; ?></span>
                <span class="label"><?php _e('Pending', 'baserow-importer'); ?></span>
            </div>
            <div class="status-count failed">
                <span class="count"><?php echo $failed_count; ?></span>
                <span class="label"><?php _e('Failed', 'baserow-importer'); ?></span>
            </div>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Order', 'baserow-importer'); ?></th>
                <th><?php _e('DSZ Reference', 'baserow-importer'); ?></th>
                <th><?php _e('Status', 'baserow-importer'); ?></th>
                <th><?php _e('Tracking Number', 'baserow-importer'); ?></th>
                <th><?php _e('Sync Date', 'baserow-importer'); ?></th>
                <th><?php _e('Retry Count', 'baserow-importer'); ?></th>
                <th><?php _e('Last Error', 'baserow-importer'); ?></th>
                <th><?php _e('Actions', 'baserow-importer'); ?></th>
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
                    <td>
                        <span class="status-badge status-<?php echo sanitize_html_class($order->status); ?>">
                            <?php echo ucfirst($order->status); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($order->tracking_number ?: '-'); ?></td>
                    <td><?php echo esc_html($order->sync_date); ?></td>
                    <td><?php echo esc_html($order->retry_count); ?></td>
                    <td><?php echo esc_html($order->last_error ?: '-'); ?></td>
                    <td>
                        <?php if ($order->status !== 'success'): ?>
                            <button type="button" 
                                    class="button retry-sync" 
                                    data-order-id="<?php echo esc_attr($order->order_id); ?>">
                                <?php _e('Retry Sync', 'baserow-importer'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
