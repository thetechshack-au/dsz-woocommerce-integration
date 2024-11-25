<?php
/**
 * Class: Baserow Product Mapper
 * Description: Handles mapping of product data between Baserow and WooCommerce
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Mapper {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    private $shipping_data_fields = array(
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
    );

    public function map_to_woocommerce($baserow_data) {
        $this->log_debug("Starting product mapping", array(
            'baserow_id' => $baserow_data['id']
        ));

        // Validate the incoming data
        $validation_result = $this->validate_product_data($baserow_data);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        $product_data = array(
            'name' => $this->sanitize_text_field($baserow_data['Title']),
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'sku' => $this->sanitize_text_field($baserow_data['SKU']),
            'regular_price' => $this->sanitize_text_field($baserow_data['RrpPrice']),
            'sale_price' => $this->sanitize_text_field($baserow_data['price']),
            'description' => $this->sanitize_textarea_field($baserow_data['Description']),
            'meta_data' => $this->prepare_meta_data($baserow_data),
            'dimensions' => $this->prepare_dimensions($baserow_data),
            'shipping_data' => $this->prepare_shipping_data($baserow_data),
            'stock_data' => $this->prepare_stock_data($baserow_data),
            'images' => $this->prepare_image_data($baserow_data)
        );

        $this->log_debug("Product mapping completed", array(
            'baserow_id' => $baserow_data['id'],
            'mapped_data' => $product_data
        ));

        return $product_data;
    }

    private function prepare_meta_data($baserow_data) {
        return array(
            '_direct_import' => $baserow_data['DI'] === 'Yes' ? 'Yes' : 'No',
            '_free_shipping' => $baserow_data['Free Shipping'] === 'Yes' ? 'Yes' : 'No',
            '_cost_price' => $baserow_data['price'],
            '_baserow_id' => $baserow_data['id']
        );
    }

    private function prepare_dimensions($baserow_data) {
        return array(
            'length' => isset($baserow_data['Carton Length (cm)']) ? $baserow_data['Carton Length (cm)'] : '',
            'width' => isset($baserow_data['Carton Width (cm)']) ? $baserow_data['Carton Width (cm)'] : '',
            'height' => isset($baserow_data['Carton Height (cm)']) ? $baserow_data['Carton Height (cm)'] : '',
            'weight' => isset($baserow_data['Weight (kg)']) ? $baserow_data['Weight (kg)'] : ''
        );
    }

    private function prepare_shipping_data($baserow_data) {
        $shipping_data = array();
        foreach ($this->shipping_data_fields as $woo_key => $baserow_key) {
            $shipping_data[$woo_key] = isset($baserow_data[$baserow_key]) 
                ? ($baserow_key === 'bulky item' 
                    ? ($baserow_data[$baserow_key] === 'Yes') 
                    : $baserow_data[$baserow_key])
                : '';
        }
        return $shipping_data;
    }

    private function prepare_stock_data($baserow_data) {
        return array(
            'manage_stock' => true,
            'stock_quantity' => isset($baserow_data['Stock Qty']) ? intval($baserow_data['Stock Qty']) : 0,
            'stock_status' => isset($baserow_data['Stock Qty']) && intval($baserow_data['Stock Qty']) > 0 
                ? 'instock' 
                : 'outofstock',
            'backorders' => 'no'
        );
    }

    private function prepare_image_data($baserow_data) {
        $images = array();
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($baserow_data["Image {$i}"])) {
                $images[] = $baserow_data["Image {$i}"];
            }
        }
        return $images;
    }

    public function map_to_baserow($woo_product) {
        // Implementation for mapping WooCommerce product back to Baserow format
        // This will be implemented when needed for two-way sync
        return new WP_Error('not_implemented', 'Mapping to Baserow not implemented yet');
    }
}
