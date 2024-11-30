<?php
/**
 * Class: Baserow Product Mapper
 * Handles mapping of product data between Baserow and WooCommerce.
 * 
 * @version 1.4.0
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
        try {
            // Validate the incoming data
            $validation_result = $this->validate_product_data($baserow_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Get prices with proper error handling
            // RrpPrice is the selling price
            $regular_price = $this->get_price_value($baserow_data, 'RrpPrice');
            // Price is the cost price
            $cost_price = $this->get_price_value($baserow_data, 'Price');

            $product_data = [
                'name' => $this->sanitize_text_field($baserow_data['Title']),
                'status' => 'publish',
                'catalog_visibility' => 'visible',
                'sku' => $this->sanitize_text_field($baserow_data['SKU']),
                'regular_price' => $regular_price,
                'description' => $this->sanitize_textarea_field($baserow_data['Description'] ?? ''),
                'meta_data' => $this->prepare_meta_data($baserow_data, $cost_price),
                'dimensions' => $this->prepare_dimensions($baserow_data),
                'shipping_data' => $this->prepare_shipping_data($baserow_data),
                'stock_data' => $this->prepare_stock_data($baserow_data),
                'images' => $this->prepare_image_data($baserow_data)
            ];

            return $product_data;

        } catch (Exception $e) {
            $this->log_exception($e, 'Error during product mapping');
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

    // ... [rest of the methods remain unchanged]
}
