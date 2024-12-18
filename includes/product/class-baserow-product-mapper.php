<?php
/**
 * Class: Baserow Product Mapper
 * Handles mapping of product data between Baserow and WooCommerce.
 * 
 * @version 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Mapper {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    /** @var array */
    private $shipping_data_fields = [
        'is_bulky_item' => 'bulky item',
        'ACT' => 'ACT',
        'NSW_M' => 'NSW_M',
        'NSW_R' => 'NSW_R',
        'NT_M' => 'NT_M',
        'NT_R' => 'NT_R',
        'QLD_M' => 'QLD_M',
        'QLD_R' => 'QLD_R',
        'REMOTE' => 'REMOTE',
        'SA_M' => 'SA_M',
        'SA_R' => 'SA_R',
        'TAS_M' => 'TAS_M',
        'TAS_R' => 'TAS_R',
        'VIC_M' => 'VIC_M',
        'VIC_R' => 'VIC_R',
        'WA_M' => 'WA_M',
        'WA_R' => 'WA_R',
        'NZ' => 'NZ'
    ];

    /**
     * Map Baserow data to WooCommerce format
     *
     * @param array $baserow_data
     * @return array|WP_Error
     */
    public function map_to_woocommerce(array $baserow_data): array|WP_Error {
        $start_time = microtime(true);

        try {
            // Log all available fields for debugging
            $this->log_debug("Available Baserow fields:", array_keys($baserow_data));

            // Check for EAN Code with different case variations
            $ean_field_variations = ['EAN Code', 'EAN code', 'ean code', 'EANCode', 'eancode'];
            $ean_value = null;
            $found_field = null;

            foreach ($ean_field_variations as $field) {
                if (isset($baserow_data[$field])) {
                    $ean_value = $baserow_data[$field];
                    $found_field = $field;
                    break;
                }
            }

            $this->log_debug("EAN field check result:", [
                'found_field' => $found_field,
                'value' => $ean_value
            ]);

            // Validate the incoming data
            $validation_result = $this->validate_product_data($baserow_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Get prices with proper error handling
            $regular_price = $this->get_price_value($baserow_data, 'RrpPrice');
            $cost_price = $this->get_price_value($baserow_data, 'price');

            $product_data = [
                'name' => $this->sanitize_text_field($baserow_data['Title']),
                'status' => 'publish',
                'catalog_visibility' => 'visible',
                'sku' => $this->sanitize_text_field($baserow_data['SKU']),
                'regular_price' => $regular_price,
                'sale_price' => $cost_price,
                'description' => $this->sanitize_textarea_field($baserow_data['Description'] ?? ''),
                'meta_data' => $this->prepare_meta_data($baserow_data, $cost_price, $ean_value),
                'dimensions' => $this->prepare_dimensions($baserow_data),
                'shipping_data' => $this->prepare_shipping_data($baserow_data),
                'stock_data' => $this->prepare_stock_data($baserow_data),
                'images' => $this->prepare_image_data($baserow_data)
            ];

            $this->log_debug("Product mapping completed", [
                'baserow_id' => $baserow_data['id'] ?? 'unknown',
                'execution_time' => microtime(true) - $start_time,
                'ean_data' => [
                    'found_field' => $found_field,
                    'value' => $ean_value,
                    'meta_fields' => array_intersect_key($product_data['meta_data'], array_flip(['EAN', '_alg_ean', '_barcode', '_wpm_ean']))
                ]
            ]);

            return $product_data;

        } catch (Exception $e) {
            $this->log_error("Error during product mapping: " . $e->getMessage());
            return new WP_Error(
                'mapping_error',
                'Failed to map product data: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get price value with proper formatting
     *
     * @param array $baserow_data
     * @param string $field_name
     * @return string
     */
    private function get_price_value(array $baserow_data, string $field_name): string {
        if (!isset($baserow_data[$field_name])) {
            return '';
        }

        $price = $baserow_data[$field_name];
        
        // Remove any currency symbols and spaces
        $price = preg_replace('/[^0-9.]/', '', $price);
        
        // Ensure it's a valid number
        if (!is_numeric($price)) {
            return '';
        }

        // Format to 2 decimal places
        return number_format((float)$price, 2, '.', '');
    }

    /**
     * Prepare meta data for WooCommerce product
     *
     * @param array $baserow_data
     * @param string $cost_price
     * @param string|null $ean_value
     * @return array
     */
    private function prepare_meta_data(array $baserow_data, string $cost_price, ?string $ean_value): array {
        $meta_data = [
            '_direct_import' => $baserow_data['DI'] === 'Yes' ? 'Yes' : 'No',
            '_free_shipping' => $baserow_data['Free Shipping'] === 'Yes' ? 'Yes' : 'No',
            '_cost_price' => $cost_price,
            '_baserow_id' => $baserow_data['id'] ?? '',
            '_last_baserow_sync' => current_time('mysql'),
            '_product_source' => 'DSZ'
        ];

        // Add EAN code if available
        if (!empty($ean_value)) {
            $ean = $this->sanitize_text_field($ean_value);
            $meta_data['EAN'] = $ean;
            $meta_data['_alg_ean'] = $ean;
            $meta_data['_barcode'] = $ean;
            $meta_data['_wpm_ean'] = $ean;
            
            $this->log_debug("Added EAN to meta data", [
                'original' => $ean_value,
                'sanitized' => $ean,
                'meta_fields' => array_intersect_key($meta_data, array_flip(['EAN', '_alg_ean', '_barcode', '_wpm_ean']))
            ]);
        }

        return $meta_data;
    }

    /**
     * Prepare dimensions data
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_dimensions(array $baserow_data): array {
        return [
            'length' => $baserow_data['Carton Length (cm)'] ?? '',
            'width' => $baserow_data['Carton Width (cm)'] ?? '',
            'height' => $baserow_data['Carton Height (cm)'] ?? '',
            'weight' => $baserow_data['Weight (kg)'] ?? ''
        ];
    }

    /**
     * Prepare shipping data
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_shipping_data(array $baserow_data): array {
        $shipping_data = [];
        foreach ($this->shipping_data_fields as $field => $baserow_field) {
            $shipping_data[$field] = $baserow_data[$baserow_field] ?? '';
        }
        return $shipping_data;
    }

    /**
     * Prepare stock data
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_stock_data(array $baserow_data): array {
        return [
            'manage_stock' => true,
            'stock_quantity' => isset($baserow_data['Stock Qty']) ? (int)$baserow_data['Stock Qty'] : 0,
            'stock_status' => isset($baserow_data['Stock Qty']) && (int)$baserow_data['Stock Qty'] > 0 ? 'instock' : 'outofstock',
            'backorders' => 'no'
        ];
    }

    /**
     * Prepare image data
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_image_data(array $baserow_data): array {
        $images = [];
        
        // Add main image if exists
        if (!empty($baserow_data['Image URL'])) {
            $images[] = $baserow_data['Image URL'];
        }

        // Add gallery images if they exist
        for ($i = 2; $i <= 5; $i++) {
            $field = "Image URL {$i}";
            if (!empty($baserow_data[$field])) {
                $images[] = $baserow_data[$field];
            }
        }

        return $images;
    }
}
