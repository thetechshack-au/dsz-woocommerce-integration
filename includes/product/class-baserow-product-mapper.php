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
        $start_time = microtime(true);

        try {
            $this->log_debug("Starting product mapping", [
                'baserow_id' => $baserow_data['id'] ?? 'unknown'
            ]);

            // Validate the incoming data
            $validation_result = $this->validate_product_data($baserow_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            $product_data = [
                'name' => $this->sanitize_text_field($baserow_data['Title']),
                'status' => 'publish',
                'catalog_visibility' => 'visible',
                'sku' => $this->sanitize_text_field($baserow_data['SKU']),
                'regular_price' => $this->sanitize_text_field($baserow_data['RrpPrice']),
                'sale_price' => $this->sanitize_text_field($baserow_data['price']),
                'description' => $this->sanitize_textarea_field($baserow_data['Description'] ?? ''),
                'meta_data' => $this->prepare_meta_data($baserow_data),
                'dimensions' => $this->prepare_dimensions($baserow_data),
                'shipping_data' => $this->prepare_shipping_data($baserow_data),
                'stock_data' => $this->prepare_stock_data($baserow_data),
                'images' => $this->prepare_image_data($baserow_data)
            ];

            $this->log_debug("Product mapping completed", [
                'baserow_id' => $baserow_data['id'] ?? 'unknown',
                'execution_time' => microtime(true) - $start_time
            ]);

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
     * Prepare meta data for WooCommerce product
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_meta_data(array $baserow_data): array {
        return [
            '_direct_import' => $baserow_data['DI'] === 'Yes' ? 'Yes' : 'No',
            '_free_shipping' => $baserow_data['Free Shipping'] === 'Yes' ? 'Yes' : 'No',
            '_cost_price' => $baserow_data['price'],
            '_baserow_id' => $baserow_data['id'] ?? ''
        ];
    }

    /**
     * Prepare dimensions data
     *
     * @param array $baserow_data
     * @return array
     */
    private function prepare_dimensions(array $baserow_data): array {
        return [
            'length' => isset($baserow_data['Carton Length (cm)']) ? $baserow_data['Carton Length (cm)'] : '',
            'width' => isset($baserow_data['Carton Width (cm)']) ? $baserow_data['Carton Width (cm)'] : '',
            'height' => isset($baserow_data['Carton Height (cm)']) ? $baserow_data['Carton Height (cm)'] : '',
            'weight' => isset($baserow_data['Weight (kg)']) ? $baserow_data['Weight (kg)'] : ''
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
        foreach ($this->shipping_data_fields as $woo_key => $baserow_key) {
            $shipping_data[$woo_key] = isset($baserow_data[$baserow_key]) 
                ? ($baserow_key === 'bulky item' 
                    ? ($baserow_data[$baserow_key] === 'Yes') 
                    : $baserow_data[$baserow_key])
                : '';
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
        $stock_qty = isset($baserow_data['Stock Qty']) ? intval($baserow_data['Stock Qty']) : 0;
        return [
            'manage_stock' => true,
            'stock_quantity' => $stock_qty,
            'stock_status' => $stock_qty > 0 ? 'instock' : 'outofstock',
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
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($baserow_data["Image {$i}"])) {
                $images[] = $baserow_data["Image {$i}"];
            }
        }
        return $images;
    }
}
