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

        // Check for price (cost price)
        if (!isset($product_data['price'])) {
            return new WP_Error(
                'invalid_pricing',
                'Cost price is required'
            );
        }

        // Remove any currency symbols and spaces for validation
        $selling_price = preg_replace('/[^0-9.]/', '', $product_data['RrpPrice']);
        $cost_price = preg_replace('/[^0-9.]/', '', $product_data['price']);

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

    // ... [rest of the methods remain unchanged]
}
