# DSZ WooCommerce Product Importer

A WordPress plugin that enables seamless integration between Baserow and WooCommerce, allowing you to import and synchronize products from your Baserow database into your WooCommerce store.

## Requirements

- WordPress
- WooCommerce
- PHP 7.2 or higher
- Write permissions for plugin directory (for logging)

## Installation

1. Download the plugin files
2. Upload the plugin folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure the plugin settings in the WordPress admin panel

## Features

- Import products from Baserow database to WooCommerce
- Automatic product synchronization
- Product deletion handling (automatically updates Baserow when products are deleted in WooCommerce)
- Detailed logging system for troubleshooting
- Admin interface for easy configuration
- Tracks imported products to maintain data consistency

## Configuration

1. Navigate to the plugin settings in WordPress admin panel
2. Configure your Baserow API credentials
3. Set up the mapping between Baserow fields and WooCommerce product fields
4. Configure synchronization settings

## Components

The plugin consists of several key components:

- **Admin Interface**: Manages the plugin's settings and provides the user interface
- **API Handler**: Handles all communication with the Baserow API
- **Product Importer**: Manages the product import and synchronization process
- **Settings Manager**: Handles plugin configuration and options
- **Logger**: Provides detailed logging for troubleshooting

## File Structure

```
dsz-woocommerce-integration/
├── assets/
│   ├── css/
│   │   └── admin-style.css
│   └── js/
│       ├── admin-script.js
│       └── settings.js
├── includes/
│   ├── class-baserow-admin.php
│   ├── class-baserow-api-handler.php
│   ├── class-baserow-logger.php
│   ├── class-baserow-product-importer.php
│   └── class-baserow-settings.php
└── baserow-woo-importer.php
```

## Technical Details

- The plugin creates a custom database table to track imported products
- Automatically handles product deletions by updating the corresponding Baserow records
- Includes error handling and validation
- Implements WordPress and WooCommerce best practices
- Uses proper security measures including ABSPATH checks and capability verification

## Version

Current Version: 1.2.19

## Author

Andrew Waite
