<?php
/**
 * Class: Baserow Postcode Mapper
 * Description: Handles postcode mapping and validation
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Postcode_Mapper {
    use Baserow_Logger_Trait;

    private $state_ranges = array(
        'NSW' => array('2000' => '2999'),
        'VIC' => array('3000' => '3999'),
        'QLD' => array('4000' => '4999'),
        'SA'  => array('5000' => '5999'),
        'WA'  => array('6000' => '6999'),
        'TAS' => array('7000' => '7999'),
        'NT'  => array('0800' => '0999'),
        'ACT' => array('0200' => '0299', '2600' => '2618', '2900' => '2920')
    );

    /**
     * Validate a postcode
     */
    public function validate_postcode($postcode) {
        $this->log_debug("Validating postcode", array('postcode' => $postcode));

        // Basic format validation
        if (!preg_match('/^\d{4}$/', $postcode)) {
            return new WP_Error(
                'invalid_postcode_format',
                'Postcode must be exactly 4 digits'
            );
        }

        // Check if postcode exists in any state range
        $valid = false;
        foreach ($this->state_ranges as $state => $ranges) {
            foreach ($ranges as $start => $end) {
                if ($postcode >= $start && $postcode <= $end) {
                    $valid = true;
                    break 2;
                }
            }
        }

        if (!$valid) {
            return new WP_Error(
                'invalid_postcode_range',
                'Postcode is not within valid Australian ranges'
            );
        }

        return true;
    }

    /**
     * Get state for a postcode
     */
    public function get_state_for_postcode($postcode) {
        $this->log_debug("Getting state for postcode", array('postcode' => $postcode));

        foreach ($this->state_ranges as $state => $ranges) {
            foreach ($ranges as $start => $end) {
                if ($postcode >= $start && $postcode <= $end) {
                    return $state;
                }
            }
        }

        return null;
    }

    /**
     * Check if postcode is metropolitan
     */
    public function is_metropolitan($postcode) {
        $metro_ranges = array(
            'NSW' => array('2000' => '2249'), // Sydney
            'VIC' => array('3000' => '3207'), // Melbourne
            'QLD' => array('4000' => '4179'), // Brisbane
            'SA'  => array('5000' => '5199'), // Adelaide
            'WA'  => array('6000' => '6199'), // Perth
            'TAS' => array('7000' => '7099'), // Hobart
            'NT'  => array('0800' => '0820'), // Darwin
            'ACT' => array('2600' => '2618')  // Canberra
        );

        foreach ($metro_ranges as $ranges) {
            foreach ($ranges as $start => $end) {
                if ($postcode >= $start && $postcode <= $end) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all postcodes for a state
     */
    public function get_postcodes_for_state($state) {
        if (!isset($this->state_ranges[$state])) {
            return new WP_Error(
                'invalid_state',
                'Invalid state code provided'
            );
        }

        $postcodes = array();
        foreach ($this->state_ranges[$state] as $start => $end) {
            for ($i = intval($start); $i <= intval($end); $i++) {
                $postcodes[] = str_pad($i, 4, '0', STR_PAD_LEFT);
            }
        }

        return $postcodes;
    }

    /**
     * Get all metropolitan postcodes
     */
    public function get_metropolitan_postcodes() {
        $metro_postcodes = array();
        
        foreach ($this->state_ranges as $state => $ranges) {
            foreach ($ranges as $start => $end) {
                $postcode = $start;
                while ($postcode <= $end) {
                    if ($this->is_metropolitan($postcode)) {
                        $metro_postcodes[] = $postcode;
                    }
                    $postcode = str_pad(intval($postcode) + 1, 4, '0', STR_PAD_LEFT);
                }
            }
        }

        return $metro_postcodes;
    }

    /**
     * Format postcode
     */
    public function format_postcode($postcode) {
        // Remove any spaces or non-numeric characters
        $postcode = preg_replace('/[^0-9]/', '', $postcode);
        
        // Pad with leading zeros if necessary
        return str_pad($postcode, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate distance between postcodes (approximate)
     */
    public function calculate_distance_between_postcodes($postcode1, $postcode2) {
        // Approximate center points for each state
        $centers = array(
            'NSW' => array('lat' => -33.8688, 'lng' => 151.2093),
            'VIC' => array('lat' => -37.8136, 'lng' => 144.9631),
            'QLD' => array('lat' => -27.4698, 'lng' => 153.0251),
            'SA'  => array('lat' => -34.9285, 'lng' => 138.6007),
            'WA'  => array('lat' => -31.9505, 'lng' => 115.8605),
            'TAS' => array('lat' => -42.8821, 'lng' => 147.3272),
            'NT'  => array('lat' => -12.4634, 'lng' => 130.8456),
            'ACT' => array('lat' => -35.2809, 'lng' => 149.1300)
        );

        $state1 = $this->get_state_for_postcode($postcode1);
        $state2 = $this->get_state_for_postcode($postcode2);

        if (!$state1 || !$state2) {
            return new WP_Error(
                'invalid_postcodes',
                'One or both postcodes are invalid'
            );
        }

        if ($state1 === $state2) {
            return 0; // Same state
        }

        // Calculate approximate distance between state centers
        $lat1 = $centers[$state1]['lat'];
        $lng1 = $centers[$state1]['lng'];
        $lat2 = $centers[$state2]['lat'];
        $lng2 = $centers[$state2]['lng'];

        return $this->calculate_distance($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Calculate distance using Haversine formula
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $radius = 6371; // Earth's radius in kilometers

        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $radius * $c;

        return round($distance);
    }
}
