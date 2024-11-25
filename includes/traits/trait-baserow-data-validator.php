<?php
/**
 * Trait: Baserow Data Validator
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

trait Baserow_Data_Validator_Trait {
    use Baserow_Logger_Trait;

    protected function validate_product_data($data) {
        $required_fields = array(
            'SKU' => 'Product SKU',
            'Title' => 'Product Title',
            'price' => 'Product Price',
            'RrpPrice' => 'RRP Price'
        );

        foreach ($required_fields as $field => $label) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->log_error("Missing required product field", array(
                    'field' => $field,
                    'label' => $label
                ));
                return new WP_Error(
                    'missing_field',
                    sprintf('Missing required field: %s', $label)
                );
            }
        }

        // Validate numeric fields
        $numeric_fields = array(
            'price' => 'Product Price',
            'RrpPrice' => 'RRP Price',
            'Stock Qty' => 'Stock Quantity',
            'Weight (kg)' => 'Weight',
            'Carton Length (cm)' => 'Length',
            'Carton Width (cm)' => 'Width',
            'Carton Height (cm)' => 'Height'
        );

        foreach ($numeric_fields as $field => $label) {
            if (isset($data[$field]) && !empty($data[$field]) && !is_numeric($data[$field])) {
                $this->log_error("Invalid numeric field", array(
                    'field' => $field,
                    'label' => $label,
                    'value' => $data[$field]
                ));
                return new WP_Error(
                    'invalid_numeric',
                    sprintf('%s must be a number', $label)
                );
            }
        }

        // Validate shipping zones
        $shipping_zones = array(
            'ACT', 'NSW_M', 'NSW_R', 'NT_M', 'NT_R',
            'QLD_M', 'QLD_R', 'REMOTE', 'SA_M', 'SA_R',
            'TAS_M', 'TAS_R', 'VIC_M', 'VIC_R', 'WA_M',
            'WA_R', 'NZ'
        );

        foreach ($shipping_zones as $zone) {
            if (isset($data[$zone]) && !is_numeric($data[$zone])) {
                $this->log_error("Invalid shipping zone value", array(
                    'zone' => $zone,
                    'value' => $data[$zone]
                ));
                return new WP_Error(
                    'invalid_shipping_zone',
                    sprintf('Invalid shipping cost for zone: %s', $zone)
                );
            }
        }

        // Validate boolean fields
        $boolean_fields = array(
            'DI' => 'Direct Import',
            'Free Shipping' => 'Free Shipping',
            'bulky item' => 'Bulky Item'
        );

        foreach ($boolean_fields as $field => $label) {
            if (isset($data[$field]) && !in_array($data[$field], array('Yes', 'No', '', null))) {
                $this->log_error("Invalid boolean field", array(
                    'field' => $field,
                    'label' => $label,
                    'value' => $data[$field]
                ));
                return new WP_Error(
                    'invalid_boolean',
                    sprintf('%s must be Yes or No', $label)
                );
            }
        }

        return true;
    }

    protected function validate_shipping_data($data) {
        if (!is_array($data)) {
            return new WP_Error(
                'invalid_shipping_data',
                'Shipping data must be an array'
            );
        }

        $required_fields = array(
            'is_bulky_item',
            'ACT',
            'NSW_M',
            'NSW_R',
            'NT_M',
            'NT_R',
            'QLD_M',
            'QLD_R',
            'REMOTE',
            'SA_M',
            'SA_R',
            'TAS_M',
            'TAS_R',
            'VIC_M',
            'VIC_R',
            'WA_M',
            'WA_R',
            'NZ'
        );

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                $this->log_error("Missing shipping zone", array(
                    'zone' => $field
                ));
                return new WP_Error(
                    'missing_shipping_zone',
                    sprintf('Missing shipping zone: %s', $field)
                );
            }
        }

        return true;
    }

    protected function validate_category_data($category_path) {
        if (empty($category_path)) {
            $this->log_error("Empty category path provided");
            return new WP_Error(
                'empty_category',
                'Category path cannot be empty'
            );
        }

        $categories = explode('>', $category_path);
        $categories = array_map('trim', $categories);

        foreach ($categories as $category) {
            if (empty($category)) {
                $this->log_error("Invalid category in path", array(
                    'path' => $category_path
                ));
                return new WP_Error(
                    'invalid_category',
                    'Invalid category in path'
                );
            }
        }

        return true;
    }

    protected function sanitize_text_field($value) {
        return sanitize_text_field($value);
    }

    protected function sanitize_textarea_field($value) {
        return sanitize_textarea_field($value);
    }

    protected function sanitize_key($value) {
        return sanitize_key($value);
    }
}
