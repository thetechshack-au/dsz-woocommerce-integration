<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-logger.php';

class Baserow_Product_Importer {
    private $api_handler;
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
        Baserow_Logger::debug("Initializing Product Importer");
        $this->api_handler = $api_handler;
        add_action('baserow_product_imported', array($this, 'update_shipping_zones'), 10, 2);
    }

    public function import_product($product_id) {
        try {
            Baserow_Logger::info("Starting import for product ID: {$product_id}");

            if (!class_exists('WC_Product_Simple')) {
                Baserow_Logger::error('WC_Product_Simple class not found. Is WooCommerce active?');
                return new WP_Error('woocommerce_not_loaded', 'WooCommerce not properly loaded');
            }

            Baserow_Logger::debug("Fetching product data from API");
            $product_data = $this->api_handler->get_product($product_id);
            if (is_wp_error($product_data)) {
                Baserow_Logger::error("Failed to get product data", [
                    'error' => $product_data->get_error_message(),
                    'product_id' => $product_id
                ]);
                return $product_data;
            }

            Baserow_Logger::debug("Product data received", [
                'product_id' => $product_id,
                'sku' => isset($product_data['SKU']) ? $product_data['SKU'] : 'Not set',
                'title' => isset($product_data['Title']) ? $product_data['Title'] : 'Not set'
            ]);

            // Create or update WooCommerce product
            $existing_product_id = wc_get_product_id_by_sku($product_data['SKU']);
            if ($existing_product_id) {
                Baserow_Logger::info("Updating existing product", [
                    'woo_id' => $existing_product_id,
                    'sku' => $product_data['SKU']
                ]);
                $product = wc_get_product($existing_product_id);
            } else {
                Baserow_Logger::info("Creating new product", [
                    'sku' => $product_data['SKU']
                ]);
                $product = new WC_Product_Simple();
            }

            if (!$product) {
                Baserow_Logger::error("Failed to create/get product object", [
                    'sku' => $product_data['SKU']
                ]);
                return new WP_Error('product_creation_failed', 'Failed to create product');
            }

            // Set product data
            Baserow_Logger::debug("Setting product data");
            $this->set_product_data($product, $product_data);
            
            // Save product
            Baserow_Logger::debug("Saving product");
            $woo_product_id = $product->save();
            
            if (!$woo_product_id) {
                Baserow_Logger::error("Failed to save product", [
                    'sku' => $product_data['SKU']
                ]);
                return new WP_Error('product_save_failed', 'Failed to save product');
            }

            // Set cost price using the 'price' field from Baserow
            if (!empty($product_data['price']) && is_numeric($product_data['price'])) {
                update_post_meta($woo_product_id, '_cost_price', $product_data['price']);
                Baserow_Logger::debug("Set cost price", [
                    'price' => $product_data['price']
                ]);
            }

            // Set product source taxonomy
            wp_set_object_terms($woo_product_id, 'DSZ', 'product_source', false);
            Baserow_Logger::debug("Set product source taxonomy to 'DSZ'");

            Baserow_Logger::info("Product saved successfully", [
                'woo_id' => $woo_product_id,
                'sku' => $product_data['SKU']
            ]);

            // Handle images
            Baserow_Logger::debug("Starting image handling");
            $image_result = $this->handle_product_images($product, $product_data, $woo_product_id);
            Baserow_Logger::debug("Image handling completed", [
                'result' => $image_result
            ]);

            // Track the imported product
            Baserow_Logger::debug("Tracking imported product");
            $this->track_imported_product($product_data['id'], $woo_product_id);

            // Trigger shipping zones update
            Baserow_Logger::debug("Triggering shipping zones update");
            do_action('baserow_product_imported', $product_data, $woo_product_id);

            Baserow_Logger::info("Product import completed successfully", [
                'woo_id' => $woo_product_id,
                'sku' => $product_data['SKU']
            ]);
            return array(
                'success' => true,
                'product_id' => $woo_product_id
            );

        } catch (Exception $e) {
            Baserow_Logger::error("Exception during product import", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

    private function set_product_data($product, $product_data) {
        try {
            Baserow_Logger::debug("Setting basic product data", [
                'sku' => $product_data['SKU']
            ]);
            
            $product->set_name($product_data['Title']);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_sku($product_data['SKU']);
            
            // Set prices
            $regular_price = is_numeric($product_data['RrpPrice']) ? $product_data['RrpPrice'] : 0;
            $sale_price = is_numeric($product_data['price']) ? $product_data['price'] : 0;
            $product->set_regular_price($regular_price);
            $product->set_price($sale_price);
            $product->set_description($product_data['Description']);

            Baserow_Logger::debug("Set prices", [
                'regular' => $regular_price,
                'sale' => $sale_price
            ]);

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

            Baserow_Logger::debug("Set shipping data");

            // Set categories
            if (!empty($product_data['Category'])) {
                Baserow_Logger::debug("Setting categories", [
                    'category' => $product_data['Category']
                ]);
                $category_ids = $this->create_or_get_categories($product_data['Category']);
                $product->set_category_ids($category_ids);
            }

            // Set stock
            $stock_qty = is_numeric($product_data['Stock Qty']) ? intval($product_data['Stock Qty']) : 0;
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_qty);
            $stock_status = ($stock_qty > 0) ? 'instock' : 'outofstock';
            $product->set_stock_status($stock_status);
            $product->set_backorders('no');

            Baserow_Logger::debug("Set stock information", [
                'quantity' => $stock_qty,
                'status' => $stock_status
            ]);

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

            Baserow_Logger::debug("Set dimensions");
            Baserow_Logger::info("Product data set successfully");
        } catch (Exception $e) {
            Baserow_Logger::error("Error setting product data", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // ... rest of the class methods remain the same ...
}
