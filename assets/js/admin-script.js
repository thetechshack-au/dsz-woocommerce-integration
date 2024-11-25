jQuery(document).ready(function($) {
    // Product import functionality
    var productRowTemplate = _.template($('#product-row-template').html());
    var $productsList = $('#baserow-products-list');
    var $productsTable = $productsList.find('.products-table');
    var $loadingProducts = $productsList.find('.loading-products');
    var $importButton = $('#import-selected-products');
    var $importStatus = $('#import-status');

    // Load products on page load
    loadProducts();

    // Handle select all products
    $('#select-all-products').on('change', function() {
        var isChecked = $(this).prop('checked');
        $productsTable.find('input[type="checkbox"]').prop('checked', isChecked);
        updateImportButton();
    });

    // Handle individual product selection
    $productsTable.on('change', 'input[type="checkbox"]', function() {
        updateImportButton();
    });

    // Handle import button click
    $importButton.on('click', function() {
        var selectedProducts = [];
        $productsTable.find('input[type="checkbox"]:checked').each(function() {
            selectedProducts.push($(this).val());
        });

        if (selectedProducts.length === 0) {
            alert('Please select products to import');
            return;
        }

        importProducts(selectedProducts);
    });

    function loadProducts() {
        $loadingProducts.show();
        $productsTable.hide();
        $importButton.hide();

        $.ajax({
            url: baserowAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'get_baserow_products',
                nonce: baserowAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderProducts(response.data);
                } else {
                    alert('Failed to load products: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to load products. Please try again.');
            },
            complete: function() {
                $loadingProducts.hide();
            }
        });
    }

    function renderProducts(products) {
        var $tbody = $productsTable.find('tbody');
        $tbody.empty();

        products.forEach(function(product) {
            $tbody.append(productRowTemplate(product));
        });

        $productsTable.show();
        updateImportButton();
    }

    function updateImportButton() {
        var hasSelected = $productsTable.find('input[type="checkbox"]:checked').length > 0;
        $importButton.toggle(hasSelected);
    }

    function importProducts(productIds) {
        $importButton.prop('disabled', true);
        $importStatus.html('Importing products...');

        $.ajax({
            url: baserowAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'import_products',
                nonce: baserowAdmin.nonce,
                products: productIds
            },
            success: function(response) {
                if (response.success) {
                    var results = response.data;
                    var message = 'Successfully imported ' + results.success.length + ' products.';
                    if (results.errors.length > 0) {
                        message += '\nFailed to import ' + results.errors.length + ' products.';
                        results.errors.forEach(function(error) {
                            message += '\n- Product ' + error.id + ': ' + error.message;
                        });
                    }
                    alert(message);
                    loadProducts(); // Reload the products list
                } else {
                    alert('Import failed: ' + response.data);
                }
            },
            error: function() {
                alert('Import failed. Please try again.');
            },
            complete: function() {
                $importButton.prop('disabled', false);
                $importStatus.html('');
            }
        });
    }

    // Order sync functionality
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
