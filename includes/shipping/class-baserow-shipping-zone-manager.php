<?php
/**
 * Class: Baserow Shipping Zone Manager
 * Description: Handles shipping zones and shipping cost calculations
 * Version: 1.6.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Shipping_Zone_Manager {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    private $zone_mapping = array(
        'NSW' => 'NSW_M',  // Metropolitan NSW
        'VIC' => 'VIC_M',  // Metropolitan VIC
        'QLD' => 'QLD_M',  // Metropolitan QLD
        'SA' => 'SA_M',    // Metropolitan SA
        'WA' => 'WA_M',    // Metropolitan WA
        'TAS' => 'TAS_M',  // Metropolitan TAS
        'NT' => 'NT_M',    // Metropolitan NT
        'ACT' => 'ACT',    // ACT
        'default' => 'REMOTE'  // Default to remote zone
    );

    private $regional_postcodes = array(
        'NSW_R' => array('2311-2312', '2328-2411', '2420-2490', '2500-2999'),
        'VIC_R' => array('3211-3334', '3340-3424', '3430-3649', '3658-3749', '3751-3999'),
        'QLD_R' => array('4124-4164', '4183-4299', '4400-4699', '4700-4805', '4807-4999'),
        'SA_R' => array('5211-5749'),
        'WA_R' => array('6208-6770'),
        'TAS_R' => array('7112-7150', '7155-7999'),
        'NT_R' => array('0822-0847', '0850-0899', '0900-0999')
    );

    /**
     * Initialize or update shipping zones
     */
    public function initialize_zones() {
        $this->log_debug("Initializing shipping zones");

        $zone_map = array(
            'zone_map' => $this->zone_mapping,
            'postcode_map' => array()
        );

        // Build postcode map for regional areas
        foreach ($this->regional_postcodes as $zone => $ranges) {
            foreach ($ranges as $range) {
                list($start, $end) = explode('-', $range);
                for ($postcode = intval($start); $postcode <= intval($end); $postcode++) {
                    $zone_map['postcode_map'][str_pad($postcode, 4, '0', STR_PAD_LEFT)] = $zone;
                }
            }
        }

        update_option('dsz_zone_map', $zone_map);
        $this->log_info("Shipping zones initialized successfully");

        return true;
    }

    /**
     * Get shipping zone for a postcode
     */
    public function get_zone_for_postcode($postcode) {
        $zone_map = get_option('dsz_zone_map', array());
        
        if (empty($zone_map)) {
            $this->initialize_zones();
            $zone_map = get_option('dsz_zone_map', array());
        }

        $postcode = str_pad($postcode, 4, '0', STR_PAD_LEFT);

        // Check if postcode is in regional areas
        if (isset($zone_map['postcode_map'][$postcode])) {
            return $zone_map['postcode_map'][$postcode];
        }

        // Get state from postcode
        $state = $this->get_state_from_postcode($postcode);
        
        // Return metropolitan zone for state or default to REMOTE
        return isset($zone_map['zone_map'][$state]) 
            ? $zone_map['zone_map'][$state] 
            : $zone_map['zone_map']['default'];
    }

    /**
     * Get state from postcode
     */
    private function get_state_from_postcode($postcode) {
        $first_digit = substr($postcode, 0, 1);
        
        switch ($first_digit) {
            case '2':
                return 'NSW';
            case '3':
                return 'VIC';
            case '4':
                return 'QLD';
            case '5':
                return 'SA';
            case '6':
                return 'WA';
            case '7':
                return 'TAS';
            case '0':
                return 'NT';
            default:
                return 'default';
        }
    }

    /**
     * Calculate shipping cost for a product
     */
    public function calculate_shipping_cost($product_id, $postcode) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error(
                'invalid_product',
                'Product not found'
            );
        }

        $shipping_data = $product->get_meta('_dsz_shipping_data');
        if (empty($shipping_data)) {
            return new WP_Error(
                'no_shipping_data',
                'No shipping data found for product'
            );
        }

        // Check if product has free shipping
        if ($product->get_meta('_free_shipping') === 'Yes') {
            return 0;
        }

        $zone = $this->get_zone_for_postcode($postcode);
        
        if (isset($shipping_data[$zone])) {
            $cost = floatval($shipping_data[$zone]);
            
            // Apply bulky item surcharge if applicable
            if (!empty($shipping_data['is_bulky_item']) && $shipping_data['is_bulky_item']) {
                $cost *= 1.5; // 50% surcharge for bulky items
            }
            
            return $cost;
        }

        return new WP_Error(
            'zone_not_found',
            'Shipping zone not found for postcode'
        );
    }

    /**
     * Validate shipping data for a product
     */
    public function validate_product_shipping_data($shipping_data) {
        if (!is_array($shipping_data)) {
            return new WP_Error(
                'invalid_shipping_data',
                'Shipping data must be an array'
            );
        }

        $required_zones = array_merge(
            array_values($this->zone_mapping),
            array_keys($this->regional_postcodes)
        );

        foreach ($required_zones as $zone) {
            if (!isset($shipping_data[$zone])) {
                return new WP_Error(
                    'missing_zone',
                    sprintf('Missing shipping cost for zone: %s', $zone)
                );
            }

            if (!is_numeric($shipping_data[$zone])) {
                return new WP_Error(
                    'invalid_cost',
                    sprintf('Invalid shipping cost for zone %s: must be numeric', $zone)
                );
            }

            if (floatval($shipping_data[$zone]) < 0) {
                return new WP_Error(
                    'negative_cost',
                    sprintf('Shipping cost cannot be negative for zone: %s', $zone)
                );
            }
        }

        return true;
    }

    /**
     * Get all available shipping zones
     */
    public function get_all_zones() {
        return array_merge(
            array_values($this->zone_mapping),
            array_keys($this->regional_postcodes)
        );
    }

    /**
     * Get regional postcodes for a zone
     */
    public function get_regional_postcodes($zone) {
        return isset($this->regional_postcodes[$zone]) 
            ? $this->regional_postcodes[$zone] 
            : array();
    }
}
