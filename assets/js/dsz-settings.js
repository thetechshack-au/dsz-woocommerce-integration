jQuery(document).ready(function($) {
    $('#test-dsz-connection').on('click', function() {
        const button = $(this);
        const statusSpan = $('#dsz-connection-status');
        
        // Disable button and show loading state
        button.prop('disabled', true);
        statusSpan.html('Testing connection...');
        statusSpan.css('color', '#666');

        // Make AJAX request to test connection
        $.ajax({
            url: baserowSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'test_dsz_connection',
                nonce: baserowSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('✓ Connection successful');
                    statusSpan.css('color', '#46b450');
                } else {
                    statusSpan.html('✗ ' + (response.data || 'Connection failed'));
                    statusSpan.css('color', '#dc3232');
                }
            },
            error: function() {
                statusSpan.html('✗ Connection failed');
                statusSpan.css('color', '#dc3232');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});
