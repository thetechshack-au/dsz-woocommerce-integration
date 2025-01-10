# DropshipZone Products

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
├── data/
│   └── dsz-categories.csv
├── includes/
│   ├── ajax/
│   │   ├── class-baserow-category-ajax.php
│   │   ├── class-baserow-order-ajax.php
│   │   ├── class-baserow-product-ajax.php
│   │   └── class-baserow-shipping-ajax.php
│   ├── categories/
│   │   └── class-baserow-category-manager.php
│   ├── product/
│   │   ├── class-baserow-product-image-handler.php
│   │   ├── class-baserow-product-importer.php
│   │   ├── class-baserow-product-mapper.php
│   │   ├── class-baserow-product-tracker.php
│   │   ├── class-baserow-product-validator.php
│   │   └── class-baserow-stock-handler.php
│   ├── shipping/
│   │   ├── class-baserow-postcode-mapper.php
│   │   └── class-baserow-shipping-zone-manager.php
│   ├── traits/
│   │   ├── trait-baserow-api-request.php
│   │   ├── trait-baserow-data-validator.php
│   │   └── trait-baserow-logger.php
│   ├── class-baserow-admin.php
│   ├── class-baserow-api-handler.php
│   ├── class-baserow-auth-handler.php
│   ├── class-baserow-logger.php
│   ├── class-baserow-order-handler.php
│   └── class-baserow-settings.php
├── scripts/
│   └── get_baserow_categories.py
└── baserow-woo-importer.php
```

## Technical Details

- The plugin creates a custom database table to track imported products
- Automatically handles product deletions by updating the corresponding Baserow records
- Includes error handling and validation
- Implements WordPress and WooCommerce best practices
- Uses proper security measures including ABSPATH checks and capability verification

## Version

Current Version: 1.6.14

### Recent Changes

- Fixed EAN code meta field from `_agl_ean` to `_alg_ean` for WooCommerce API compatibility
- Added Python script for fetching and cleaning category names from Baserow
- Enhanced category name formatting (removes apostrophes, standardizes separators)
- Improved file structure with modular components and traits

## Scripts

### Category Management

The plugin includes a Python script (`scripts/get_baserow_categories.py`) for managing product categories:

- Fetches categories from Baserow
- Cleans category names by removing apostrophes and standardizing formatting
- Saves categorization data to CSV in the data directory
- Maintains hierarchical category structure

Usage:
```bash
python scripts/get_baserow_categories.py <api_url> <table_id> <api_token>
```

Requirements:
- Python 3
- requests library (install in virtual environment: `python -m venv venv && source venv/bin/activate && pip install requests`)

## Author

Andrew Waite
