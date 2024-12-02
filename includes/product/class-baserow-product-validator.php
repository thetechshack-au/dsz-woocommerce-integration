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
            $this->log_debug("Starting complete product validation", [
                'product_data' => $product_data
            ]);

            // Base validation using trait's validate_product_data method
            $base_validation = $this->validate_product_data($product_data);
            if (is_wp_error($base_validation)) {
                $this->log_error("Base validation failed", [
                    'error' => $base_validation->get_error_message(),
                    'data' => $product_data
                ]);
                return $base_validation;
            }

            // Shipping validation using trait's validate_shipping_data method
            $shipping_validation = $this->validate_shipping_data($product_data);
            if (is_wp_error($shipping_validation)) {
                $this->log_error("Shipping validation failed", [
                    'error' => $shipping_validation->get_error_message(),
                    'data' => $product_data
                ]);
                return $shipping_validation;
            }

            // Category validation if present
            if (!empty($product_data['Category'])) {
                $category_validation = $this->validate_category_data($product_data['Category']);
                if (is_wp_error($category_validation)) {
                    $this->log_error("Category validation failed", [
                        'error' => $category_validation->get_error_message(),
                        'data' => $product_data
                    ]);
                    return $category_validation;
                }
            }

            // Image URL validation if present
            if (!empty($product_data['Image URL'])) {
                if (!$this->validate_url($product_data['Image URL'])) {
                    $this->log_error("Invalid main image URL", [
                        'url' => $product_data['Image URL']
                    ]);
                    return new WP_Error(
                        'invalid_image_url',
                        'Main image URL is not valid'
                    );
                }
            }

            // Additional image URLs validation
            for ($i = 2; $i <= 5; $i++) {
                $field = "Image URL {$i}";
                if (!empty($product_data[$field])) {
                    if (!$this->validate_url($product_data[$field])) {
                        $this->log_error("Invalid additional image URL", [
                            'field' => $field,
                            'url' => $product_data[$field]
                        ]);
                        return new WP_Error(
                            'invalid_image_url',
                            sprintf('Image URL %d is not valid', $i)
                        );
                    }
                }
            }

            $this->log_debug("Product validation completed successfully");
            return true;

        } catch (Exception $e) {
            $this->log_exception($e, 'Error during product validation');
            return new WP_Error(
                'validation_error',
                'Product validation failed: ' . $e->getMessage()
            );
        }
    }
}
