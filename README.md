# DSZ WooCommerce Product Importer

A WordPress plugin that seamlessly integrates Baserow (DSZ) with WooCommerce, enabling automated product imports and order synchronization.

## Features

### Product Management
- Automated product imports from Baserow
- Smart image handling with WebP optimization
- Category mapping and management
- Shipping zone configuration
- Stock level synchronization
- Advanced product search functionality:
  - Multi-parameter search (name, SKU, category)
  - Pagination and sorting capabilities
  - Real-time WooCommerce status integration
  - Efficient filtering and result handling

### Image Processing
- WebP conversion with JPEG fallback
- Configurable image dimensions
- Quality optimization settings
- Automatic file integrity checks
- Efficient memory management

### Order Handling
- Order status synchronization
- Reference tracking
- Error recovery and retry logic

## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.2 or higher
- GD or ImageMagick for image processing

## Installation

1. Upload the plugin files to `/wp-content/plugins/dsz-woocommerce-integration`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings under 'DSZ Settings' in the admin menu

## Configuration

### Image Settings
Configure image processing options under DSZ Settings > Image Settings:
- Maximum dimensions
- Quality settings
- Format preferences
- Storage options

See [Image Settings Documentation](docs/image-settings.md) for detailed information.

### API Configuration
Set up your Baserow API connection:
1. Navigate to DSZ Settings > API Settings
2. Enter your API credentials
3. Test the connection
4. Save settings

## Usage

### Importing Products
Products are automatically imported based on your configuration:
1. New products are created
2. Existing products are updated
3. Images are optimized and processed
4. Categories are mapped
5. Stock levels are synchronized

### Searching Products
The plugin provides advanced search capabilities:
```php
// Example: Search products using AJAX
jQuery.ajax({
    url: ajaxurl,
    type: 'GET',
    data: {
        action: 'search_baserow_products',
        nonce: baserow_ajax_nonce,
        search: 'keyword',
        sku: 'SKU123',
        category: 'Electronics',
        page: 1,
        sort_by: 'Name',
        sort_order: 'asc'
    },
    success: function(response) {
        // Handle search results
        console.log(response.data.products);
    }
});
```

Search parameters:
- `search`: Search term for product name/description
- `sku`: Product SKU to search for
- `category`: Category to filter by
- `page`: Page number for pagination
- `sort_by`: Field to sort by (id, Name, SKU, Category)
- `sort_order`: Sort direction (asc/desc)

### Managing Orders
Orders are automatically synchronized:
1. New orders are sent to Baserow
2. Order status updates are tracked
3. References are maintained
4. Errors are logged and retried

## Development

### Directory Structure
```
dsz-woocommerce-integration/
├── assets/
│   ├── css/
│   └── js/
├── docs/
├── includes/
│   ├── admin/
│   ├── ajax/
│   ├── categories/
│   ├── orders/
│   ├── product/
│   ├── shipping/
│   └── traits/
└── tests/
```

### Key Components
- Product Mapper: Handles data transformation
- Image Handler: Manages image processing
- Order Handler: Manages order synchronization
- API Handler: Manages API communication
- Search Handler: Manages product search and filtering

### Extending the Plugin
The plugin provides several filters and actions for customization:

```php
// Modify image settings
add_filter('baserow_image_handler_settings', function($settings) {
    $settings['image']['max_width'] = 1600;
    return $settings;
});

// Customize product mapping
add_filter('baserow_product_mapping', function($product_data) {
    // Modify product data
    return $product_data;
});

// Modify search results
add_filter('baserow_search_results', function($results) {
    // Customize search results
    return $results;
});
```

See [Developer Documentation](docs/image-settings.md) for more details.

## Troubleshooting

### Common Issues
1. Image Processing Fails
   - Check PHP memory limit
   - Verify GD/ImageMagick installation
   - Check file permissions

2. API Connection Issues
   - Verify API credentials
   - Check network connectivity
   - Review error logs

3. Import Failures
   - Check data format
   - Verify required fields
   - Review error logs

4. Search Issues
   - Verify API connectivity
   - Check search parameters
   - Review error logs

### Logging
Logs are stored in `wp-content/plugins/dsz-woocommerce-integration/baserow-importer.log`

## Support

For support:
1. Check the documentation
2. Review error logs
3. Contact support team

## Contributing

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

## License

This plugin is licensed under the GPL v2 or later.

See [CHANGELOG.md](CHANGELOG.md) for version history and updates.
