<?php
/**
 * Trait: Baserow Data Validator
 * Provides robust data validation and sanitization functionality.
 * 
 * @version 1.4.0
 */

trait Baserow_Data_Validator_Trait {
    use Baserow_Logger_Trait;

    /** @var array */
    protected $validation_rules = [];

    /** @var array */
    protected $shipping_zones = [
        'ACT', 'NSW_M', 'NSW_R', 'NT_M', 'NT_R',
        'QLD_M', 'QLD_R', 'REMOTE', 'SA_M', 'SA_R',
        'TAS_M', 'TAS_R', 'VIC_M', 'VIC_R', 'WA_M',
        'WA_R', 'NZ'
    ];

    /**
     * Validate product data
     *
     * @param array $data
     * @param array $additional_rules
     * @return true|WP_Error
     */
    protected function validate_product_data(array $data, array $additional_rules = []) {
        $start_time = microtime(true);

        $required_fields = [
            'SKU' => [
                'label' => 'Product SKU',
                'type' => 'string',
                'required' => true
            ],
            'Title' => [
                'label' => 'Product Title',
                'type' => 'string',
                'required' => true,
                'min_length' => 3
            ],
            'Price' => [  // Changed from 'price' to 'Price'
                'label' => 'Product Price',
                'type' => 'numeric',
                'required' => true,
                'min' => 0
            ],
            'RrpPrice' => [
                'label' => 'RRP Price',
                'type' => 'numeric',
                'required' => true,
                'min' => 0
            ]
        ];

        $numeric_fields = [
            'Price' => [  // Changed from 'price' to 'Price'
                'label' => 'Product Price',
                'min' => 0
            ],
            'RrpPrice' => [
                'label' => 'RRP Price',
                'min' => 0
            ],
            'Stock Qty' => [
                'label' => 'Stock Quantity',
                'min' => 0
            ],
            'Weight (kg)' => [
                'label' => 'Weight',
                'min' => 0
            ],
            'Carton Length (cm)' => [
                'label' => 'Length',
                'min' => 0
            ],
            'Carton Width (cm)' => [
                'label' => 'Width',
                'min' => 0
            ],
            'Carton Height (cm)' => [
                'label' => 'Height',
                'min' => 0
            ]
        ];

        $boolean_fields = [
            'DI' => 'Direct Import',
            'Free Shipping' => 'Free Shipping',
            'bulky item' => 'Bulky Item'
        ];

        // Validate required fields
        foreach ($required_fields as $field => $rules) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->log_error('Missing required product field', [
                    'field' => $field,
                    'label' => $rules['label']
                ]);
                return new WP_Error(
                    'missing_field',
                    sprintf('Missing required field: %s', $rules['label']),
                    ['field' => $field]
                );
            }

            if (isset($rules['min_length']) && strlen($data[$field]) < $rules['min_length']) {
                return new WP_Error(
                    'invalid_length',
                    sprintf('%s must be at least %d characters', $rules['label'], $rules['min_length']),
                    ['field' => $field]
                );
            }
        }

        // Validate numeric fields
        foreach ($numeric_fields as $field => $rules) {
            if (isset($data[$field]) && !empty($data[$field])) {
                if (!is_numeric($data[$field])) {
                    $this->log_error('Invalid numeric field', [
                        'field' => $field,
                        'label' => $rules['label'],
                        'value' => $data[$field]
                    ]);
                    return new WP_Error(
                        'invalid_numeric',
                        sprintf('%s must be a number', $rules['label']),
                        ['field' => $field]
                    );
                }

                if (isset($rules['min']) && floatval($data[$field]) < $rules['min']) {
                    return new WP_Error(
                        'invalid_value',
                        sprintf('%s cannot be less than %d', $rules['label'], $rules['min']),
                        ['field' => $field]
                    );
                }
            }
        }

        // Validate shipping zones
        foreach ($this->shipping_zones as $zone) {
            if (isset($data[$zone]) && !empty($data[$zone])) {
                if (!is_numeric($data[$zone])) {
                    $this->log_error('Invalid shipping zone value', [
                        'zone' => $zone,
                        'value' => $data[$zone]
                    ]);
                    return new WP_Error(
                        'invalid_shipping_zone',
                        sprintf('Invalid shipping cost for zone: %s', $zone),
                        ['zone' => $zone]
                    );
                }

                if (floatval($data[$zone]) < 0) {
                    return new WP_Error(
                        'invalid_shipping_cost',
                        sprintf('Shipping cost for zone %s cannot be negative', $zone),
                        ['zone' => $zone]
                    );
                }
            }
        }

        // Validate boolean fields
        foreach ($boolean_fields as $field => $label) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $value = $this->sanitize_boolean($data[$field]);
                if ($value === null) {
                    $this->log_error('Invalid boolean field', [
                        'field' => $field,
                        'label' => $label,
                        'value' => $data[$field]
                    ]);
                    return new WP_Error(
                        'invalid_boolean',
                        sprintf('%s must be Yes or No', $label),
                        ['field' => $field]
                    );
                }
            }
        }

        // Apply additional validation rules
        if (!empty($additional_rules)) {
            $validation_result = $this->apply_additional_rules($data, $additional_rules);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }
        }

        $this->log_performance('product_validation', $start_time, [
            'data_size' => strlen(json_encode($data))
        ]);

        return true;
    }

    // ... [rest of the methods remain unchanged]
}
