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
    protected $valid_shipping_zone_codes = [
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
            'Price' => [
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
            'Price' => [
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
                    'label' => $rules['label'],
                    'data' => $data
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
        foreach ($this->valid_shipping_zone_codes as $zone) {
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

    /**
     * Validate shipping data
     *
     * @param array $data
     * @return true|WP_Error
     */
    protected function validate_shipping_data(array $data): bool|WP_Error {
        $start_time = microtime(true);

        if (!is_array($data)) {
            return new WP_Error(
                'invalid_shipping_data',
                'Shipping data must be an array'
            );
        }

        $required_fields = array_merge(['is_bulky_item'], $this->valid_shipping_zone_codes);

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                $this->log_error('Missing shipping zone', [
                    'zone' => $field
                ]);
                return new WP_Error(
                    'missing_shipping_zone',
                    sprintf('Missing shipping zone: %s', $field),
                    ['field' => $field]
                );
            }

            if ($field !== 'is_bulky_item' && !is_numeric($data[$field])) {
                return new WP_Error(
                    'invalid_shipping_cost',
                    sprintf('Invalid shipping cost for zone: %s', $field),
                    ['field' => $field]
                );
            }
        }

        $this->log_performance('shipping_validation', $start_time);

        return true;
    }

    /**
     * Validate category data
     *
     * @param string $category_path
     * @return true|WP_Error
     */
    protected function validate_category_data(string $category_path): bool|WP_Error {
        if (empty($category_path)) {
            $this->log_error('Empty category path provided');
            return new WP_Error(
                'empty_category',
                'Category path cannot be empty'
            );
        }

        $categories = array_map('trim', explode('>', $category_path));
        
        if (empty($categories)) {
            return new WP_Error(
                'invalid_category_format',
                'Invalid category path format'
            );
        }

        foreach ($categories as $category) {
            if (empty($category)) {
                $this->log_error('Invalid category in path', [
                    'path' => $category_path
                ]);
                return new WP_Error(
                    'invalid_category',
                    'Invalid category in path',
                    ['path' => $category_path]
                );
            }

            if (strlen($category) > 200) {
                return new WP_Error(
                    'category_too_long',
                    'Category name exceeds maximum length of 200 characters',
                    ['category' => $category]
                );
            }
        }

        return true;
    }

    /**
     * Apply additional validation rules
     *
     * @param array $data
     * @param array $rules
     * @return true|WP_Error
     */
    protected function apply_additional_rules(array $data, array $rules): bool|WP_Error {
        foreach ($rules as $field => $rule) {
            if (!isset($data[$field])) {
                continue;
            }

            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $data[$field])) {
                return new WP_Error(
                    'pattern_mismatch',
                    sprintf('%s does not match required pattern', $rule['label'] ?? $field),
                    ['field' => $field]
                );
            }

            if (isset($rule['callback']) && is_callable($rule['callback'])) {
                $result = call_user_func($rule['callback'], $data[$field]);
                if ($result !== true) {
                    return new WP_Error(
                        'custom_validation_failed',
                        $result,
                        ['field' => $field]
                    );
                }
            }
        }

        return true;
    }

    /**
     * Sanitize text field
     *
     * @param string $value
     * @return string
     */
    protected function sanitize_text_field(string $value): string {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize textarea field
     *
     * @param string $value
     * @return string
     */
    protected function sanitize_textarea_field(string $value): string {
        return sanitize_textarea_field($value);
    }

    /**
     * Sanitize key
     *
     * @param string $value
     * @return string
     */
    protected function sanitize_key(string $value): string {
        return sanitize_key($value);
    }

    /**
     * Sanitize boolean value
     *
     * @param mixed $value
     * @return bool|null
     */
    protected function sanitize_boolean($value): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['yes', 'true', '1', 'on'])) {
                return true;
            }
            if (in_array($value, ['no', 'false', '0', 'off'])) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (bool)$value;
        }

        return null;
    }

    /**
     * Validate email
     *
     * @param string $email
     * @return bool
     */
    protected function validate_email(string $email): bool {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Validate URL
     *
     * @param string $url
     * @return bool
     */
    protected function validate_url(string $url): bool {
        return (bool)filter_var($url, FILTER_VALIDATE_URL);
    }
}
