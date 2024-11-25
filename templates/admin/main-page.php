<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="card">
        <h2><?php _e('Product Import', 'baserow-importer'); ?></h2>
        <p><?php _e('Import or update products from Baserow to WooCommerce.', 'baserow-importer'); ?></p>
        <button type="button" class="button button-primary" id="import-products">
            <?php _e('Import Products', 'baserow-importer'); ?>
        </button>
        <span id="import-status" style="margin-left: 10px;"></span>
    </div>

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
</div>
