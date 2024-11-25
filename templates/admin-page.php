<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="card">
        <h2><?php _e('Import Products from Baserow', 'baserow-importer'); ?></h2>
        
        <div id="baserow-products-list">
            <div class="loading-products">
                <?php _e('Loading products...', 'baserow-importer'); ?>
            </div>
            <table class="wp-list-table widefat fixed striped products-table" style="display: none;">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="select-all-products">
                        </th>
                        <th><?php _e('SKU', 'baserow-importer'); ?></th>
                        <th><?php _e('Title', 'baserow-importer'); ?></th>
                        <th><?php _e('Price', 'baserow-importer'); ?></th>
                        <th><?php _e('Stock', 'baserow-importer'); ?></th>
                        <th><?php _e('Status', 'baserow-importer'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="tablenav bottom">
            <div class="alignleft actions">
                <button type="button" class="button button-primary" id="import-selected-products" style="display: none;">
                    <?php _e('Import Selected Products', 'baserow-importer'); ?>
                </button>
                <span id="import-status" style="margin-left: 10px;"></span>
            </div>
        </div>
    </div>

    <?php if (current_user_can('manage_woocommerce')): ?>
    <div class="card">
        <h2><?php _e('DSZ Order Sync Status', 'baserow-importer'); ?></h2>
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_dsz_orders';
        
        $stats = $wpdb->get_results("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) as failed
            FROM {$table_name}
        ");

        if ($stats): 
            $stat = $stats[0];
        ?>
            <p>
                <strong><?php _e('Total Orders:', 'baserow-importer'); ?></strong> <?php echo $stat->total; ?><br>
                <strong><?php _e('Successful:', 'baserow-importer'); ?></strong> <?php echo $stat->successful; ?><br>
                <strong><?php _e('Failed:', 'baserow-importer'); ?></strong> <?php echo $stat->failed; ?>
            </p>
            <a href="<?php echo admin_url('admin.php?page=baserow-dsz-orders'); ?>" class="button">
                <?php _e('View Order Status', 'baserow-importer'); ?>
            </a>
        <?php else: ?>
            <p><?php _e('No order sync data available.', 'baserow-importer'); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script type="text/template" id="product-row-template">
    <tr>
        <td class="check-column">
            <input type="checkbox" name="products[]" value="<%= id %>">
        </td>
        <td><%= sku %></td>
        <td><%= title %></td>
        <td><%= price %></td>
        <td><%= stock %></td>
        <td>
            <% if (imported) { %>
                <span class="status-imported"><?php _e('Imported', 'baserow-importer'); ?></span>
            <% } else { %>
                <span class="status-not-imported"><?php _e('Not Imported', 'baserow-importer'); ?></span>
            <% } %>
        </td>
    </tr>
</script>
