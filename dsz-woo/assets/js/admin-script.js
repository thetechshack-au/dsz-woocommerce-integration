jQuery(document).ready(function($) {
    var loadingOverlay = $('#loading-overlay');
    var productsGrid = $('#baserow-products-grid');
    var searchInput = $('#baserow-search');
    var categoryFilter = $('#baserow-category-filter');
    var searchButton = $('#search-products');
    var paginationContainer = $('.baserow-pagination');
    var currentPage = 1;
    var totalPages = 1;

    // Get AJAX settings from data attributes
    var controls = $('.baserow-search-controls');
    var ajaxUrl = controls.data('ajax-url');
    var nonce = controls.data('nonce');

    console.log('Controls found:', controls.length);
    console.log('AJAX URL:', ajaxUrl);
    console.log('Nonce:', nonce);

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

    function loadCategories() {
        console.log('Loading categories...');
        return $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_categories',
                nonce: nonce
            },
            success: function(response) {
                console.log('Categories response:', response);
                if (response.success && response.data.categories) {
                    var categories = response.data.categories;
                    console.log('Number of categories:', categories.length);
                    console.log('Categories:', categories);

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
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to load categories:', error);
            }
        });
    }

    function renderProducts(products) {
        console.log('Rendering products:', products);
        if (!products || products.length === 0) {
            console.log('No products to display');
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
            console.log('Processing product:', product);
            var imageUrl = product.image_url || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2VlZSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTYiIGZpbGw9IiM5OTkiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5ObyBJbWFnZTwvdGV4dD48L3N2Zz4=';
            
            html += '<tr>';
            
            // Checkbox
            html += '<td><input type="checkbox" class="product-select" ' + 
                   (product.baserow_imported ? 'disabled' : '') +
                   ' data-product-id="' + product.id + '"></td>';
            
            // Image
            html += '<td class="product-image-cell">' +
                   '<img src="' + imageUrl + '" alt="' + (product.Title || '') + '"></td>';
            
            // Title
            html += '<td>' + (product.Title || '') + '</td>';
            
            // SKU
            html += '<td>' + (product.SKU || '') + '</td>';
            
            // Category
            html += '<td>' + (product.Category || '') + '</td>';
            
            // Cost Price
            html += '<td class="cost-price-col">$' + (product.cost_price || '0.00') + '</td>';
            
            // Sell Price
            html += '<td class="sell-price-col">$' + (product.price || '0.00') + '</td>';
            
            // Status Badges
            html += '<td><div class="status-badges">';
            if (product.baserow_imported && product.woo_exists) {
                html += '<span class="status-badge badge-wordpress"><span class="dashicons dashicons-wordpress"></span></span>';
            }
            if (product.DI === 'Yes') {
                html += '<span class="status-badge badge-di">DI</span>';
            }
            if (product.au_free_shipping === 'Yes') {
                html += '<span class="status-badge badge-fs">FS</span>';
            }
            if (product.new_arrival === 'Yes') {
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
        console.log('Setting HTML:', html.substring(0, 500) + '...');
        
        try {
            productsGrid.html(html);
            console.log('HTML set successfully');
            
            // Check if table is visible
            var table = productsGrid.find('.products-table');
            console.log('Table visibility:', {
                exists: table.length > 0,
                display: table.css('display'),
                visibility: table.css('visibility'),
                height: table.height(),
                width: table.width()
            });
        } catch (error) {
            console.error('Error setting HTML:', error);
            handleError('Failed to render products: ' + error.message);
        }

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
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'import_product',
                        nonce: nonce,
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

    function searchProducts(page) {
        console.log('Searching products...');
        console.log('Category:', categoryFilter.val());
        console.log('Search term:', searchInput.val());
        console.log('Page:', page);

        showLoading();
        currentPage = page || 1;
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'search_products',
                nonce: nonce,
                search: searchInput.val(),
                category: categoryFilter.val(),
                page: currentPage
            },
            cache: false,
            success: function(response) {
                console.log('Search response:', response);
                hideLoading();
                if (response.success) {
                    console.log('Found products:', response.data.results);
                    renderProducts(response.data.results);
                    if (response.data.pagination) {
                        totalPages = parseInt(response.data.pagination.total_pages);
                        currentPage = parseInt(response.data.pagination.current_page);
                        updatePaginationControls();
                    }
                } else {
                    handleError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('Search failed:', error);
                handleError('Failed to fetch products. Please try again.');
            }
        });
    }

    function importProduct(productId) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'import_product',
                nonce: nonce,
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
        if (!confirm('Are you sure you want to delete this product? This will remove it from WooCommerce and update Baserow.')) {
            return;
        }

        showLoading();
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'delete_product',
                nonce: nonce,
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
        console.log('Category selected:', $(this).val());
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
    loadCategories();
    searchProducts(1);
});
