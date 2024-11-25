jQuery(document).ready(function($) {
    $('#test-baserow-connection').on('click', function() {
        const button = $(this);
        const statusSpan = $('#connection-status');
        
        button.prop('disabled', true);
        statusSpan.html('<span style="color: #666;">Testing connection...</span>');

        $.ajax({
            url: baserowSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'test_baserow_connection',
                nonce: baserowSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: #46b450;">✓ Connection successful!</span>');
                } else {
                    statusSpan.html('<span style="color: #dc3232;">✗ Connection failed: ' + response.data.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: #dc3232;">✗ Connection test failed</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});
