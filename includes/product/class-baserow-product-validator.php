<?php
/**
 * Class: Baserow Product Validator
 * Handles detailed validation of product data.
 * 
 * @version 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Validator {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    /**
     * Validates the complete product data set
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    public function validate_complete_product(array $product_data): bool|WP_Error {
        try {
            $validations = [
                'base' => $this->validate_product_data($product_data),
                'pricing' => $this->validate_pricing($product_data),
                'shipping' => $this->validate_shipping_data($product_data),
                'stock' => $this->validate_stock_data($product_data),
                'dimensions' => $this->validate_dimensions($product_data),
                'images' => $this->validate_images($product_data)
            ];

            foreach ($validations as $type => $result) {
                if (is_wp_error($result)) {
                    $this->log_error("Validation failed for {$type}", [
                        'error' => $result->get_error_message(),
                        'data' => $product_data
                    ]);
                    return $result;
                }
            }

            return true;

        } catch (Exception $e) {
            $this->log_exception($e, 'Error during product validation');
            return new WP_Error(
                'validation_error',
                'Product validation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validates product pricing
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_pricing(array $product_data): bool|WP_Error {
        // Check for RrpPrice (selling price)
        if (!isset($product_data['RrpPrice'])) {
            return new WP_Error(
                'invalid_pricing',
                'Selling price (RrpPrice) is required'
            );
        }

        // Check for Price (cost price) - Updated to match API response format
        if (!isset($product_data['Price'])) {
            $this->log_error("Missing Price field in product data", [
                'available_fields' => array_keys($product_data)
            ]);
            return new WP_Error(
                'invalid_pricing',
                'Cost price (Price) is required'
            );
        }

        // Remove any currency symbols and spaces for validation
        $selling_price = preg_replace('/[^0-9.]/', '', $product_data['RrpPrice']);
        $cost_price = preg_replace('/[^0-9.]/', '', $product_data['Price']);

        if (!is_numeric($selling_price)) {
            return new WP_Error(
                'invalid_pricing_format',
                'Selling price must be a numeric value'
            );
        }

        if (!is_numeric($cost_price)) {
            return new WP_Error(
                'invalid_pricing_format',
                'Cost price must be a numeric value'
            );
        }

        if (floatval($selling_price) < 0) {
            return new WP_Error(
                'negative_price',
                'Selling price cannot be negative'
            );
        }

        if (floatval($cost_price) < 0) {
            return new WP_Error(
                'negative_price',
                'Cost price cannot be negative'
            );
        }

        return true;
    }

    /**
     * Validates shipping data
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_shipping_data(array $product_data): bool|WP_Error {
        // Shipping validation is optional
        return true;
    }

    /**
     * Validates stock data
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_stock_data(array $product_data): bool|WP_Error {
        if (isset($product_data['Stock Qty'])) {
            if (!is_numeric($product_data['Stock Qty'])) {
                return new WP_Error(
                    'invalid_stock',
                    'Stock quantity must be a numeric value'
                );
            }

            if (floatval($product_data['Stock Qty']) < 0) {
                return new WP_Error(
                    'negative_stock',
                    'Stock quantity cannot be negative'
                );
            }
        }

        return true;
    }

    /**
     * Validates dimensions
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_dimensions(array $product_data): bool|WP_Error {
        $dimension_fields = [
            'Carton Length (cm)' => 'Length',
            'Carton Width (cm)' => 'Width',
            'Carton Height (cm)' => 'Height',
            'Weight (kg)' => 'Weight'
        ];

        foreach ($dimension_fields as $field => $label) {
            if (isset($product_data[$field]) && !empty($product_data[$field])) {
                if (!is_numeric($product_data[$field])) {
                    return new WP_Error(
                        'invalid_dimension',
                        sprintf('%s must be a numeric value', $label)
                    );
                }

                if (floatval($product_data[$field]) < 0) {
                    return new WP_Error(
                        'negative_dimension',
                        sprintf('%s cannot be negative', $label)
                    );
                }
            }
        }

        return true;
    }

    /**
     * Validates images
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_images(array $product_data): bool|WP_Error {
        // Image validation is optional
        if (isset($product_data['Image URL']) && !empty($product_data['Image URL'])) {
            if (!filter_var($product_data['Image URL'], FILTER_VALIDATE_URL)) {
                return new WP_Error(
                    'invalid_image_url',
                    'Main image URL is not valid'
                );
            }
        }

        // Check additional image URLs if present
        for ($i = 2; $i <= 5; $i++) {
            $field = "Image URL {$i}";
            if (isset($product_data[$field]) && !empty($product_data[$field])) {
                if (!filter_var($product_data[$field], FILTER_VALIDATE_URL)) {
                    return new WP_Error(
                        'invalid_image_url',
                        sprintf('Image URL %d is not valid', $i)
                    );
                }
            }
        }

        return true;
    }
}
