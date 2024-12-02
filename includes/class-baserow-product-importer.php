<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-logger.php';

class Baserow_Product_Importer {
    private $api_handler;
    private $product_validator;
    private $image_handler;
    private $shipping_zones = array(
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

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;
        $this->product_validator = new Baserow_Product_Validator();
        $this->image_handler = new Baserow_Product_Image_Handler();
        add_action('baserow_product_imported', array($this, 'update_shipping_zones'), 10, 2);
    }

    public function import_product($product_id) {
        try {
            Baserow_Logger::info("Starting import for product ID: {$product_id}");

            if (!class_exists('WC_Product_Simple')) {
                Baserow_Logger::error('WC_Product_Simple class not found');
                return new WP_Error('woocommerce_not_loaded', 'WooCommerce not properly loaded');
            }

            $product_data = $this->api_handler->get_product($product_id);
            if (is_wp_error($product_data)) {
                Baserow_Logger::error("Failed to get product data: " . $product_data->get_error_message());
                return $product_data;
            }

            Baserow_Logger::debug("Product data received: " . print_r($product_data, true));

            // Validate product data
            $validation_result = $this->product_validator->validate_complete_product($product_data);
            if (is_wp_error($validation_result)) {
                Baserow_Logger::error("Product validation failed: " . $validation_result->get_error_message());
                return $validation_result;
            }

            // Create or update WooCommerce product
            $existing_product_id = wc_get_product_id_by_sku($product_data['SKU']);
            if ($existing_product_id) {
                Baserow_Logger::info("Updating existing product with ID: {$existing_product_id}");
                $product = wc_get_product($existing_product_id);
            } else {
                Baserow_Logger::info("Creating new product");
                $product = new WC_Product_Simple();
            }

            if (!$product) {
                Baserow_Logger::error("Failed to create/get product object");
                return new WP_Error('product_creation_failed', 'Failed to create product');
            }

            // Set product data
            $this->set_product_data($product, $product_data);
            
            // Save product
            $woo_product_id = $product->save();
            
            if (!$woo_product_id) {
                Baserow_Logger::error("Failed to save product");
                return new WP_Error('product_save_failed', 'Failed to save product');
            }

            // Set cost price using the 'Price' field from Baserow
            if (!empty($product_data['Price']) && is_numeric($product_data['Price'])) {
                update_post_meta($woo_product_id, '_cost_price', $product_data['Price']);
                Baserow_Logger::debug("Set cost price from 'Price' field: " . $product_data['Price']);
            }

            // Set product source taxonomy
            wp_set_object_terms($woo_product_id, 'DSZ', 'product_source', false);
            Baserow_Logger::debug("Set product source taxonomy term to 'DSZ'");

            Baserow_Logger::info("Product saved with ID: {$woo_product_id}");

            // Handle images
            $image_urls = [];
            for ($i = 1; $i <= 5; $i++) {
                $field = "Image URL" . ($i > 1 ? " {$i}" : "");
                if (!empty($product_data[$field])) {
                    $image_urls[] = $product_data[$field];
                }
            }
            
            if (!empty($image_urls)) {
                $image_ids = $this->image_handler->process_product_images($image_urls, $woo_product_id);
                $this->image_handler->set_product_images($product, $image_ids);
                Baserow_Logger::debug("Image handling completed", [
                    'urls' => $image_urls,
                    'ids' => $image_ids
                ]);
            }

            // Track the imported product
            $this->track_imported_product($product_data['id'], $woo_product_id);

            // Trigger shipping zones update
            do_action('baserow_product_imported', $product_data, $woo_product_id);

            Baserow_Logger::info("Product import completed successfully");
            return array(
                'success' => true,
                'product_id' => $woo_product_id
            );

        } catch (Exception $e) {
            Baserow_Logger::error("Exception during product import: " . $e->getMessage());
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

    private function set_product_data($product, $product_data) {
        try {
            Baserow_Logger::debug("Setting basic product data");
            
            $product->set_name($product_data['Title']);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_sku($product_data['SKU']);
            
            // Set prices
            $regular_price = is_numeric($product_data['RrpPrice']) ? $product_data['RrpPrice'] : 0;
            $sale_price = is_numeric($product_data['Price']) ? $product_data['Price'] : 0;
            $product->set_regular_price($regular_price);
            $product->set_price($sale_price);
            $product->set_description($product_data['Description']);

            // Set Direct Import flag
            $product->update_meta_data('_direct_import', $product_data['DI'] === 'Yes' ? 'Yes' : 'No');

            // Set Free Shipping flag
            $product->update_meta_data('_free_shipping', $product_data['Free Shipping'] === 'Yes' ? 'Yes' : 'No');

            // Set shipping data
            $shipping_data = array(
                'is_bulky_item' => $product_data['bulky item'] === 'Yes',
                'ACT' => $product_data['ACT'],
                'NSW_M' => $product_data['NSW_M'],
                'NSW_R' => $product_data['NSW_R'],
                'NT_M' => $product_data['NT_M'],
                'NT_R' => $product_data['NT_R'],
                'QLD_M' => $product_data['QLD_M'],
                'QLD_R' => $product_data['QLD_R'],
                'REMOTE' => $product_data['REMOTE'],
                'SA_M' => $product_data['SA_M'],
                'SA_R' => $product_data['SA_R'],
                'TAS_M' => $product_data['TAS_M'],
                'TAS_R' => $product_data['TAS_R'],
                'VIC_M' => $product_data['VIC_M'],
                'VIC_R' => $product_data['VIC_R'],
                'WA_M' => $product_data['WA_M'],
                'WA_R' => $product_data['WA_R'],
                'NZ' => $product_data['NZ']
            );
            $product->update_meta_data('_dsz_shipping_data', $shipping_data);

            Baserow_Logger::debug("Setting categories");
            // Set categories
            if (!empty($product_data['Category'])) {
                $category_ids = $this->create_or_get_categories($product_data['Category']);
                $product->set_category_ids($category_ids);
            }

            Baserow_Logger::debug("Setting stock information");
            // Set stock
            $product->set_manage_stock(true);
            $stock_qty = is_numeric($product_data['Stock Qty']) ? intval($product_data['Stock Qty']) : 0;
            $product->set_stock_quantity($stock_qty);
            $stock_status = ($stock_qty > 0) ? 'instock' : 'outofstock';
            $product->set_stock_status($stock_status);
            $product->set_backorders('no');

            Baserow_Logger::debug("Setting dimensions");
            // Set dimensions
            if (!empty($product_data['Weight (kg)']) && is_numeric($product_data['Weight (kg)'])) {
                $product->set_weight($product_data['Weight (kg)']);
            }
            if (!empty($product_data['Carton Length (cm)']) && is_numeric($product_data['Carton Length (cm)'])) {
                $product->set_length($product_data['Carton Length (cm)']);
            }
            if (!empty($product_data['Carton Width (cm)']) && is_numeric($product_data['Carton Width (cm)'])) {
                $product->set_width($product_data['Carton Width (cm)']);
            }
            if (!empty($product_data['Carton Height (cm)']) && is_numeric($product_data['Carton Height (cm)'])) {
                $product->set_height($product_data['Carton Height (cm)']);
            }

            Baserow_Logger::info("Product data set successfully");
        } catch (Exception $e) {
            Baserow_Logger::error("Error setting product data: " . $e->getMessage());
            throw $e;
        }
    }

    public function update_shipping_zones($product_data, $product_id) {
        Baserow_Logger::info("Updating shipping zones");

        try {
            // Get current zone map
            $zone_map = get_option('dsz_zone_map', array());
            if (empty($zone_map)) {
                $zone_map = array(
                    'zone_map' => $this->shipping_zones,
                    'postcode_map' => array()
                );
            }

            // Update postcode map for regional areas
            foreach ($this->regional_postcodes as $zone => $ranges) {
                foreach ($ranges as $range) {
                    list($start, $end) = explode('-', $range);
                    for ($postcode = intval($start); $postcode <= intval($end); $postcode++) {
                        $zone_map['postcode_map'][str_pad($postcode, 4, '0', STR_PAD_LEFT)] = $zone;
                    }
                }
            }

            // Update WordPress option
            update_option('dsz_zone_map', $zone_map);
            Baserow_Logger::info("Shipping zones updated successfully");

        } catch (Exception $e) {
            Baserow_Logger::error("Error updating shipping zones: " . $e->getMessage());
        }
    }

    private function create_or_get_categories($category_path) {
        Baserow_Logger::debug("Processing categories: {$category_path}");
        
        $categories = explode('>', $category_path);
        $categories = array_map('trim', $categories);
        $parent_id = 0;
        $category_ids = array();

        foreach ($categories as $category_name) {
            $term = term_exists($category_name, 'product_cat', $parent_id);
            
            if (!$term) {
                Baserow_Logger::info("Creating new category: {$category_name}");
                $term = wp_insert_term($category_name, 'product_cat', array('parent' => $parent_id));
            }
            
            if (!is_wp_error($term)) {
                $parent_id = $term['term_id'];
                $category_ids[] = $term['term_id'];
            } else {
                Baserow_Logger::error("Error creating category {$category_name}: " . $term->get_error_message());
            }
        }

        return $category_ids;
    }

    private function track_imported_product($baserow_id, $woo_product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'baserow_imported_products';

        Baserow_Logger::debug("Tracking imported product - Baserow ID: {$baserow_id}, WooCommerce ID: {$woo_product_id}");

        $result = $wpdb->replace(
            $table_name,
            array(
                'baserow_id' => $baserow_id,
                'woo_product_id' => $woo_product_id,
                'last_sync' => current_time('mysql')
            ),
            array('%s', '%d', '%s')
        );

        if ($result === false) {
            Baserow_Logger::error("Failed to track imported product: " . $wpdb->last_error);
        } else {
            Baserow_Logger::info("Successfully tracked imported product");
        }
    }
}
