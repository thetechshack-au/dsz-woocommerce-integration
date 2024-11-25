jQuery(document).ready(function($) {
    // Handle retry sync button clicks
    $('.retry-sync').on('click', function() {
        const button = $(this);
        const orderId = button.data('order-id');
        
        button.prop('disabled', true);
        
        $.ajax({
            url: baserowAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'retry_dsz_sync',
                order_id: orderId,
                nonce: baserowAdmin.nonce
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

    // Handle bulk retry of failed orders
    $('.sync-failed-orders').on('click', function() {
        const button = $(this);
        button.prop('disabled', true);
        
        $.ajax({
            url: baserowAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'retry_all_failed_dsz_sync',
                nonce: baserowAdmin.nonce
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
