jQuery(document).ready(function($) {
    var loadingOverlay = $('#loading-overlay');
    var productsGrid = $('#baserow-products-grid');
    var searchInput = $('#baserow-search');
    var categoryFilter = $('#baserow-category-filter');
    var searchButton = $('#search-products');
    var paginationContainer = $('.baserow-pagination');
    var currentPage = 1;
    var totalPages = 1;
    var categoryLoadRetries = 0;
    var maxRetries = 3;

    function showLoading() {
        loadingOverlay.show();
    }

    function hideLoading() {
        loadingOverlay.hide();
    }

    function handleError(message) {
        hideLoading();
        productsGrid.html('<div class="notice notice-error"><p>' + message + '</p></div>');
    }

    function updatePaginationControls() {
        paginationContainer.find('.current-page').text(currentPage);
        paginationContainer.find('.total-pages').text(totalPages);
        
        paginationContainer.find('.prev-page').prop('disabled', currentPage <= 1);
        paginationContainer.find('.next-page').prop('disabled', currentPage >= totalPages);
        
        paginationContainer.find('.prev-page').attr('aria-disabled', currentPage <= 1);
        paginationContainer.find('.next-page').attr('aria-disabled', currentPage >= totalPages);

        if (totalPages > 1) {
            paginationContainer.show();
        } else {
            paginationContainer.hide();
        }
    }

    function loadCategories(isRetry = false) {
        console.log('Loading categories... Attempt:', categoryLoadRetries + 1);
        
        if (!isRetry) {
            categoryLoadRetries = 0;
        }

        return $.ajax({
            url: baserowImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'get_categories',
                nonce: baserowImporter.nonce
            },
            timeout: 30000, // 30 second timeout
            success: function(response) {
                console.log('Categories response:', response);
                if (response.success && response.data && response.data.categories) {
                    var categories = response.data.categories;
                    console.log('Found categories:', categories);
                    
                    var currentCategory = categoryFilter.val();
                    categoryFilter.find('option:not(:first)').remove();
                    
                    categories.forEach(function(category) {
                        categoryFilter.append($('<option>', {
                            value: category,
                            text: category
                        }));
                    });
                    
                    if (currentCategory) {
                        categoryFilter.val(currentCategory);
                    }
                    
                    console.log('Category dropdown updated. Current value:', categoryFilter.val());
                    categoryLoadRetries = 0; // Reset retry counter on success
                } else {
                    console.error('Invalid categories response:', response);
                    handleCategoryError(response.data ? response.data.message : 'Failed to load categories');
                }
            },
            error: function(xhr, status, error) {
                console.error('Categories AJAX error:', status, error);
                handleCategoryError(status === 'timeout' ? baserowImporter.timeout_error : baserowImporter.network_error);
            }
        });
    }

    function handleCategoryError(message) {
        categoryLoadRetries++;
        console.log('Category load failed. Attempt:', categoryLoadRetries);
        
        if (categoryLoadRetries < maxRetries) {
            console.log('Retrying category load...');
            setTimeout(function() {
                loadCategories(true);
            }, categoryLoadRetries * 2000); // Exponential backoff
        } else {
            console.error('Max retries reached for category loading');
            // Show error but don't disable functionality
            var errorHtml = '<div class="notice notice-error inline"><p>' + message + '</p></div>';
            categoryFilter.before(errorHtml);
        }
    }

    function searchProducts(page, isRetry = false) {
        showLoading();
        currentPage = page || 1;
        
        console.log('Searching products - Page:', currentPage, 'Category:', categoryFilter.val());
        
        $.ajax({
            url: baserowImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'search_products',
                nonce: baserowImporter.nonce,
                search: searchInput.val(),
                category: categoryFilter.val(),
                page: currentPage
            },
            timeout: 30000, // 30 second timeout
            cache: false,
            success: function(response) {
                console.log('Search response:', response);
                hideLoading();
                if (response.success) {
                    if (!response.data.results || response.data.results.length === 0) {
                        productsGrid.html('<div class="notice notice-info"><p>No products found.</p></div>');
                        return;
                    }
                    renderProducts(response.data.results);
                    if (response.data.pagination) {
                        totalPages = parseInt(response.data.pagination.total_pages);
                        currentPage = parseInt(response.data.pagination.current_page);
                        updatePaginationControls();
                    }
                } else {
                    handleError(response.data.message || 'Failed to fetch products');
                }
            },
            error: function(xhr, status, error) {
                console.error('Search AJAX error:', status, error);
                if (status === 'timeout' && !isRetry) {
                    // Retry once on timeout
                    console.log('Search timed out, retrying...');
                    setTimeout(function() {
                        searchProducts(page, true);
                    }, 2000);
                } else {
                    handleError(status === 'timeout' ? baserowImporter.timeout_error : baserowImporter.network_error);
                }
            }
        });
    }

    function renderProducts(products) {
        if (!products || products.length === 0) {
            productsGrid.html('<div class="notice notice-info"><p>No products found.</p></div>');
            return;
        }

        var html = '<div class="bulk-actions">' +
            '<input type="checkbox" id="select-all-products"> <label for="select-all-products">Select All</label>' +
            '<button class="button bulk-import" disabled>Import Selected</button>' +
            '</div>';

        html += '<table class="products-table">' +
            '<thead><tr>' +
            '<th><input type="checkbox" id="select-all-header"></th>' +
            '<th>Image</th>' +
            '<th>Title</th>' +
            '<th>SKU</th>' +
            '<th>Category</th>' +
            '<th class="cost-price-col">Cost Price</th>' +
            '<th class="sell-price-col">Sell Price</th>' +
            '<th>Status</th>' +
            '<th class="actions-col">Actions</th>' +
            '</tr></thead><tbody>';

        products.forEach(function(product) {
            var imageUrl = product.image_url || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2VlZSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTYiIGZpbGw9IiM5OTkiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5ObyBJbWFnZTwvdGV4dD48L3N2Zz4=';
            
            html += '<tr>';
            
            // Checkbox
            html += '<td><input type="checkbox" class="product-select" ' + 
                   (product.baserow_imported ? 'disabled' : '') +
                   ' data-product-id="' + product.id + '"></td>';
            
            // Image
            html += '<td class="product-image-cell">' +
                   '<img src="' + imageUrl + '" alt="' + product.Title + '"></td>';
            
            // Title
            html += '<td>' + product.Title + '</td>';
            
            // SKU
            html += '<td>' + product.SKU + '</td>';
            
            // Category
            html += '<td>' + product.Category + '</td>';
            
            // Cost Price
            html += '<td class="cost-price-col">$' + (product['Cost Price'] || '0.00') + '</td>';
            
            // Sell Price
            html += '<td class="sell-price-col">$' + (product.RrpPrice || '0.00') + '</td>';
            
            // Status Badges
            html += '<td><div class="status-badges">';
            if (product.baserow_imported && product.woo_exists) {
                html += '<span class="status-badge badge-wordpress"><span class="dashicons dashicons-wordpress"></span></span>';
            }
            if (product.DI === 'Yes') {
                html += '<span class="status-badge badge-di">DI</span>';
            }
            if (product['au_free_shipping'] === 'Yes') {
                html += '<span class="status-badge badge-fs">FS</span>';
            }
            if (product['new_arrival'] === 'Yes') {
                html += '<span class="status-badge badge-new">NEW</span>';
            }
            html += '</div></td>';

            // Actions
            html += '<td class="actions-col"><div class="action-buttons">';
            if (product.baserow_imported && product.woo_exists) {
                html += '<a href="' + product.woo_url + '" class="button action-button" target="_blank">' +
                        '<span class="dashicons dashicons-visibility"></span></a>' +
                        '<button class="button action-button delete-product" data-product-id="' + product.woo_product_id + '">' +
                        '<span class="dashicons dashicons-trash"></span></button>';
            } else {
                html += '<button class="button action-button import-product" data-product-id="' + product.id + '">' +
                        '<span class="dashicons dashicons-download"></span></button>';
            }
            html += '</div></td></tr>';
        });

        html += '</tbody></table>';
        productsGrid.html(html);

        initBulkSelectionHandlers();
    }

    function initBulkSelectionHandlers() {
        $('#select-all-products, #select-all-header').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('.product-select:not(:disabled)').prop('checked', isChecked);
            $('#select-all-products, #select-all-header').prop('checked', isChecked);
            updateBulkActionButton();
        });

        $('.product-select').on('change', function() {
            updateBulkActionButton();
        });

        $('.bulk-import').on('click', function() {
            var selectedIds = [];
            $('.product-select:checked').each(function() {
                selectedIds.push($(this).data('product-id'));
            });
            if (selectedIds.length > 0) {
                bulkImportProducts(selectedIds);
            }
        });
    }

    function updateBulkActionButton() {
        var selectedCount = $('.product-select:checked').length;
        $('.bulk-import').prop('disabled', selectedCount === 0)
            .text('Import Selected (' + selectedCount + ')');
    }

    function bulkImportProducts(productIds) {
        if (!confirm('Are you sure you want to import ' + productIds.length + ' products?')) {
            return;
        }

        showLoading();
        var importPromises = productIds.map(function(id) {
            return new Promise(function(resolve) {
                $.ajax({
                    url: baserowImporter.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'import_product',
                        nonce: baserowImporter.nonce,
                        product_id: id
                    }
                }).always(resolve);
            });
        });

        Promise.all(importPromises).then(function() {
            hideLoading();
            loadCategories().always(function() {
                window.location.reload();
            });
        });
    }

    function importProduct(productId) {
        showLoading();
        $.ajax({
            url: baserowImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'import_product',
                nonce: baserowImporter.nonce,
                product_id: productId
            },
            success: function(response) {
                hideLoading();
                if (response.success && response.data.redirect) {
                    loadCategories().always(function() {
                        window.location.reload();
                    });
                } else if (!response.success) {
                    handleError(response.data.message);
                }
            },
            error: function() {
                handleError('Failed to import product. Please try again.');
            }
        });
    }

    function deleteProduct(productId) {
        if (!confirm(baserowImporter.confirm_delete)) {
            return;
        }

        showLoading();
        $.ajax({
            url: baserowImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_product',
                nonce: baserowImporter.nonce,
                product_id: productId
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    loadCategories().always(function() {
                        window.location.reload();
                    });
                } else {
                    handleError(response.data.message);
                }
            },
            error: function() {
                handleError('Failed to delete product. Please try again.');
            }
        });
    }

    // Event Handlers
    searchButton.on('click', function() {
        searchProducts(1);
    });

    searchInput.on('keypress', function(e) {
        if (e.which === 13) {
            searchProducts(1);
        }
    });

    categoryFilter.on('change', function() {
        searchProducts(1);
    });

    $(document).on('click', '.prev-page:not([disabled])', function(e) {
        e.preventDefault();
        if (currentPage > 1) {
            searchProducts(currentPage - 1);
        }
    });

    $(document).on('click', '.next-page:not([disabled])', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) {
            searchProducts(currentPage + 1);
        }
    });

    productsGrid.on('click', '.import-product', function() {
        var productId = $(this).data('product-id');
        importProduct(productId);
    });

    productsGrid.on('click', '.delete-product', function() {
        var productId = $(this).data('product-id');
        deleteProduct(productId);
    });

    // Initial load
    console.log('Initializing...');
    loadCategories();
    searchProducts(1);

    // Refresh categories periodically with exponential backoff
    var refreshInterval = 30000; // Start with 30 seconds
    function scheduleRefresh() {
        setTimeout(function() {
            loadCategories().always(function() {
                refreshInterval = Math.min(refreshInterval * 1.5, 300000); // Max 5 minutes
                scheduleRefresh();
            });
        }, refreshInterval);
    }
    scheduleRefresh();
});
