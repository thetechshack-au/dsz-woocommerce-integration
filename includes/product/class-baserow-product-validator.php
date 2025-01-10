<?php
/**
 * Class: Baserow Product Validator
 * Handles detailed validation of product data.
 * 
 * @version 1.6.0
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
        $start_time = microtime(true);

        try {
            $this->log_debug("Starting complete product validation", [
                'id' => $product_data['id'] ?? 'unknown',
                'sku' => $product_data['SKU'] ?? 'unknown',
                'available_fields' => array_keys($product_data)
            ]);

            $validations = [
                'base' => $this->validate_product_data($product_data),
                'pricing' => $this->validate_pricing($product_data),
                'shipping' => $this->validate_shipping_data($product_data),
                'stock' => $this->validate_stock_data($product_data),
                'dimensions' => $this->validate_dimensions($product_data),
                'images' => $this->validate_images($product_data),
                'ean' => $this->validate_ean_code($product_data),
                'brand' => $this->validate_brand($product_data)
            ];

            foreach ($validations as $type => $result) {
                if (is_wp_error($result)) {
                    $this->log_error("Validation failed", [
                        'type' => $type,
                        'error' => $result->get_error_message()
                    ]);
                    return $result;
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

    /**
     * Validates product pricing
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_pricing(array $product_data): bool|WP_Error {
        if (!isset($product_data['price']) || !isset($product_data['RrpPrice'])) {
            return new WP_Error(
                'invalid_pricing',
                'Price and RRP are required'
            );
        }

        if (!is_numeric($product_data['price']) || !is_numeric($product_data['RrpPrice'])) {
            return new WP_Error(
                'invalid_pricing_format',
                'Price and RRP must be numeric values'
            );
        }

        if (floatval($product_data['price']) < 0 || floatval($product_data['RrpPrice']) < 0) {
            return new WP_Error(
                'negative_price',
                'Prices cannot be negative'
            );
        }

        // Optional: Validate price is not greater than RRP
        if (floatval($product_data['price']) > floatval($product_data['RrpPrice'])) {
            $this->log_warning("Sale price is greater than RRP", [
                'sale_price' => $product_data['price'],
                'rrp' => $product_data['RrpPrice']
            ]);
        }

        return true;
    }

    /**
     * Validates stock data
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_stock_data(array $product_data): bool|WP_Error {
        if (!isset($product_data['Stock Qty'])) {
            return new WP_Error(
                'missing_stock',
                'Stock quantity is required'
            );
        }

        if (!is_numeric($product_data['Stock Qty'])) {
            return new WP_Error(
                'invalid_stock_format',
                'Stock quantity must be a number'
            );
        }

        if (intval($product_data['Stock Qty']) < 0) {
            return new WP_Error(
                'negative_stock',
                'Stock quantity cannot be negative'
            );
        }

        return true;
    }

    /**
     * Validates product dimensions
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_dimensions(array $product_data): bool|WP_Error {
        $dimension_fields = [
            'Weight (kg)' => 'Weight',
            'Carton Length (cm)' => 'Length',
            'Carton Width (cm)' => 'Width',
            'Carton Height (cm)' => 'Height'
        ];

        foreach ($dimension_fields as $field => $label) {
            if (isset($product_data[$field]) && !empty($product_data[$field])) {
                if (!is_numeric($product_data[$field])) {
                    return new WP_Error(
                        'invalid_dimension',
                        sprintf('%s must be a number', $label)
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
     * Validates product images
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_images(array $product_data): bool|WP_Error {
        // Check main image URL
        if (!empty($product_data['Image URL'])) {
            if (!filter_var($product_data['Image URL'], FILTER_VALIDATE_URL)) {
                return new WP_Error(
                    'invalid_image_url',
                    'Invalid main image URL'
                );
            }

            $ext = strtolower(pathinfo($product_data['Image URL'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return new WP_Error(
                    'invalid_image_type',
                    'Invalid main image type. Allowed types: jpg, jpeg, png, gif, webp'
                );
            }
        }

        // Check additional image URLs
        for ($i = 2; $i <= 5; $i++) {
            $field = "Image URL {$i}";
            if (!empty($product_data[$field])) {
                if (!filter_var($product_data[$field], FILTER_VALIDATE_URL)) {
                    return new WP_Error(
                        'invalid_image_url',
                        sprintf('Invalid URL for %s', $field)
                    );
                }

                $ext = strtolower(pathinfo($product_data[$field], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    return new WP_Error(
                        'invalid_image_type',
                        sprintf('Invalid image type for %s. Allowed types: jpg, jpeg, png, gif, webp', $field)
                    );
                }
            }
        }

        return true;
    }

    /**
     * Validates EAN code format
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_ean_code(array $product_data): bool|WP_Error {
        // Check for EAN Code with different case variations
        $ean_field_variations = ['EAN Code', 'EAN code', 'ean code', 'EANCode', 'eancode'];
        $ean_value = null;
        $found_field = null;

        foreach ($ean_field_variations as $field) {
            if (isset($product_data[$field])) {
                $ean_value = $product_data[$field];
                $found_field = $field;
                break;
            }
        }

        $this->log_debug("EAN validation check:", [
            'found_field' => $found_field,
            'value' => $ean_value
        ]);

        if (!empty($ean_value)) {
            // Remove any non-numeric characters
            $ean = preg_replace('/[^0-9]/', '', $ean_value);
            
            // EAN-13 should be exactly 13 digits
            if (strlen($ean) !== 13) {
                $this->log_warning("Invalid EAN length", [
                    'ean' => $ean,
                    'length' => strlen($ean)
                ]);
                // Don't return error, just log warning
                return true;
            }

            // Validate EAN-13 checksum
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ($i % 2 === 0) ? (int)$ean[$i] : (int)$ean[$i] * 3;
            }
            $checksum = (10 - ($sum % 10)) % 10;

            if ($checksum !== (int)$ean[12]) {
                $this->log_warning("Invalid EAN checksum", [
                    'ean' => $ean,
                    'calculated_checksum' => $checksum,
                    'provided_checksum' => (int)$ean[12]
                ]);
                // Don't return error, just log warning
                return true;
            }
        }

        return true;
    }

    /**
     * Validates SKU uniqueness
     *
     * @param string $sku
     * @param int|null $product_id
     * @return true|WP_Error
     */
    public function validate_sku_unique(string $sku, ?int $product_id = null): bool|WP_Error {
        global $wpdb;

        try {
            $this->log_debug("Validating SKU uniqueness", [
                'sku' => $sku,
                'product_id' => $product_id
            ]);

            $query = $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta 
                WHERE meta_key = '_sku' AND meta_value = %s",
                $sku
            );

            if ($product_id) {
                $query .= $wpdb->prepare(" AND post_id != %d", $product_id);
            }

            $existing_product = $wpdb->get_var($query);

            if ($existing_product) {
                $this->log_error("Duplicate SKU found", [
                    'sku' => $sku,
                    'existing_product_id' => $existing_product
                ]);
                return new WP_Error(
                    'duplicate_sku',
                    sprintf('SKU %s is already in use', $sku)
                );
            }

            return true;

        } catch (Exception $e) {
            $this->log_exception($e, 'Error during SKU validation');
            return new WP_Error(
                'sku_validation_error',
                'SKU validation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validates brand data
     *
     * @param array $product_data
     * @return true|WP_Error
     */
    private function validate_brand(array $product_data): bool|WP_Error {
        if (isset($product_data['Brand']) && !empty($product_data['Brand'])) {
            // Ensure brand is a string and not too long
            if (!is_string($product_data['Brand'])) {
                return new WP_Error(
                    'invalid_brand_format',
                    'Brand must be a text value'
                );
            }

            if (strlen($product_data['Brand']) > 255) {
                $this->log_warning("Brand name exceeds 255 characters", [
                    'brand' => $product_data['Brand'],
                    'length' => strlen($product_data['Brand'])
                ]);
            }
        }

        return true;
    }
}
