jQuery(document).ready(function($) {
    $('#test-baserow-connection').on('click', function() {
        const button = $(this);
        const statusSpan = $('#connection-status');
        
        button.prop('disabled', true);
        statusSpan.html('<div class="testing-message">Testing connection...</div>');

        $.ajax({
            url: baserowSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'test_baserow_connection',
                nonce: baserowSettings.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<div class="success-message">✓ ' + response.data + '</div>');
                } else {
                    let errorMsg = response.data;
                    if (typeof errorMsg === 'object' && errorMsg.message) {
                        errorMsg = errorMsg.message;
                    }
                    
                    let errorHtml = '<div class="error-message">✗ ';
                    
                    // Split error message into main message and tips if they exist
                    if (errorMsg.includes('Troubleshooting tips:')) {
                        let [mainError, tips] = errorMsg.split('Troubleshooting tips:');
                        errorHtml += mainError;
                        
                        if (tips) {
                            errorHtml += '<div class="troubleshooting-tips">';
                            errorHtml += '<strong>Troubleshooting tips:</strong>';
                            errorHtml += '<ul>';
                            tips.split('\n- ').forEach(tip => {
                                if (tip.trim()) {
                                    errorHtml += '<li>' + tip.trim() + '</li>';
                                }
                            });
                            errorHtml += '</ul></div>';
                        }
                    } else {
                        errorHtml += errorMsg;
                    }
                    
                    errorHtml += '</div>';
                    
                    // Add debug info if available
                    if (response.data && response.data.debug) {
                        errorHtml += '<div class="debug-info">';
                        errorHtml += '<a href="#" class="toggle-debug-info">Show Debug Info</a>';
                        errorHtml += '<pre style="display: none;">';
                        errorHtml += JSON.stringify(response.data.debug, null, 2);
                        errorHtml += '</pre></div>';
                    }
                    
                    statusSpan.html(errorHtml);
                    
                    // Initialize toggle functionality
                    $('.toggle-debug-info').on('click', function(e) {
                        e.preventDefault();
                        const pre = $(this).next('pre');
                        pre.toggle();
                        $(this).text(pre.is(':visible') ? 'Hide Debug Info' : 'Show Debug Info');
                    });
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Connection test failed';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg += ': ' + xhr.responseJSON.data;
                } else if (error) {
                    errorMsg += ': ' + error;
                }
                statusSpan.html('<div class="error-message">✗ ' + errorMsg + '</div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});
