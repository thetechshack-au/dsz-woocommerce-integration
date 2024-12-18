<?php
/**
 * Class: Baserow Product Importer
 * Description: Main product import handler
 * Version: 1.6.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Importer {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    private $api_handler;
    private $product_mapper;
    private $product_validator;
    private $image_handler;
    private $product_tracker;
    private $category_manager;
    private $shipping_zone_manager;

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;
        $this->product_mapper = new Baserow_Product_Mapper();
        $this->product_validator = new Baserow_Product_Validator();
        $this->image_handler = new Baserow_Product_Image_Handler();
        $this->product_tracker = new Baserow_Product_Tracker();
        $this->category_manager = new Baserow_Category_Manager();
        $this->shipping_zone_manager = new Baserow_Shipping_Zone_Manager();

        add_action('baserow_product_imported', array($this->shipping_zone_manager, 'initialize_zones'), 10, 2);
    }

    public function import_product($product_id) {
        try {
            $this->log_info("Starting import for product ID: {$product_id}");

            if (!class_exists('WC_Product_Simple')) {
                $this->log_error('WC_Product_Simple class not found');
                return new WP_Error('woocommerce_not_loaded', 'WooCommerce not properly loaded');
            }

            // Get product data from API
            $product_data = $this->api_handler->get_product($product_id);
            if (is_wp_error($product_data)) {
                $this->log_error("Failed to get product data: " . $product_data->get_error_message());
                return $product_data;
            }

            $this->log_debug("Product data received:", $product_data);

            // Validate product data
            $validation_result = $this->product_validator->validate_complete_product($product_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Map product data to WooCommerce format
            $woo_data = $this->product_mapper->map_to_woocommerce($product_data);
            if (is_wp_error($woo_data)) {
                return $woo_data;
            }

            // Create or update WooCommerce product
            $existing_product_id = wc_get_product_id_by_sku($product_data['SKU']);
            if ($existing_product_id) {
                $this->log_info("Updating existing product with ID: {$existing_product_id}");
                $product = wc_get_product($existing_product_id);
            } else {
                $this->log_info("Creating new product");
                $product = new WC_Product_Simple();
            }

            if (!$product) {
                $this->log_error("Failed to create/get product object");
                return new WP_Error('product_creation_failed', 'Failed to create product');
            }

            $this->log_debug("Setting basic product data");

            // Set basic product data
            foreach ($woo_data as $key => $value) {
                if ($key !== 'meta_data') {
                    $setter = "set_{$key}";
                    if (method_exists($product, $setter)) {
                        $product->$setter($value);
                    }
                }
            }

            // Set categories
            if (!empty($product_data['Category'])) {
                $this->log_debug("Setting categories");
                $this->log_debug("Processing categories: " . $product_data['Category']);
                $category_ids = $this->category_manager->create_or_get_categories($product_data['Category']);
                if (!is_wp_error($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }

            // Set stock information
            $this->log_debug("Setting stock information");
            $product->set_manage_stock(true);
            $product->set_stock_quantity($product_data['Stock Qty']);
            $product->set_stock_status($product_data['Stock Qty'] > 0 ? 'instock' : 'outofstock');

            // Set dimensions
            $this->log_debug("Setting dimensions");
            if (!empty($product_data['Weight (kg)'])) {
                $product->set_weight($product_data['Weight (kg)']);
            }
            if (!empty($product_data['Carton Length (cm)'])) {
                $product->set_length($product_data['Carton Length (cm)']);
            }
            if (!empty($product_data['Carton Width (cm)'])) {
                $product->set_width($product_data['Carton Width (cm)']);
            }
            if (!empty($product_data['Carton Height (cm)'])) {
                $product->set_height($product_data['Carton Height (cm)']);
            }

            $this->log_info("Product data set successfully");

            // Set meta data
            if (!empty($woo_data['meta_data'])) {
                foreach ($woo_data['meta_data'] as $meta_key => $meta_value) {
                    $product->update_meta_data($meta_key, $meta_value);
                }
            }

            // Set EAN code explicitly
            if (!empty($product_data['EAN Code'])) {
                $ean = $this->sanitize_text_field($product_data['EAN Code']);
                $this->log_debug("Setting EAN code: " . $ean);
                
                // Set all possible EAN meta fields
                update_post_meta($product->get_id(), 'EAN', $ean);
                update_post_meta($product->get_id(), '_alg_ean', $ean);
                update_post_meta($product->get_id(), '_barcode', $ean);
                update_post_meta($product->get_id(), '_wpm_ean', $ean);
                
                // Also set via WooCommerce API
                $product->update_meta_data('EAN', $ean);
                $product->update_meta_data('_alg_ean', $ean);
                $product->update_meta_data('_barcode', $ean);
                $product->update_meta_data('_wpm_ean', $ean);
            }

            // Set cost price
            if (!empty($product_data['price'])) {
                $this->log_debug("Set cost price from 'price' field: " . $product_data['price']);
                update_post_meta($product->get_id(), '_cost_price', $product_data['price']);
                $product->update_meta_data('_cost_price', $product_data['price']);
            }

            // Set product source
            $this->log_debug("Set product source taxonomy term to 'DSZ'");
            update_post_meta($product->get_id(), '_product_source', 'DSZ');
            $product->update_meta_data('_product_source', 'DSZ');

            // Save product
            $woo_product_id = $product->save();
            if (!$woo_product_id) {
                $this->log_error("Failed to save product");
                return new WP_Error('product_save_failed', 'Failed to save product');
            }

            $this->log_info("Product saved with ID: " . $woo_product_id);

            // Verify EAN code was saved
            if (!empty($product_data['EAN Code'])) {
                $saved_ean = get_post_meta($woo_product_id, 'EAN', true);
                $saved_alg_ean = get_post_meta($woo_product_id, '_alg_ean', true);
                $saved_barcode = get_post_meta($woo_product_id, '_barcode', true);
                $saved_wpm_ean = get_post_meta($woo_product_id, '_wpm_ean', true);
                
                $this->log_debug("Verified saved EAN data:", [
                    'EAN' => $saved_ean,
                    '_alg_ean' => $saved_alg_ean,
                    '_barcode' => $saved_barcode,
                    '_wpm_ean' => $saved_wpm_ean
                ]);
            }

            // Handle images
            if (!empty($woo_data['images'])) {
                $this->log_debug("Starting image handling for product ID: " . $woo_product_id);
                $image_ids = $this->image_handler->process_product_images($woo_data['images'], $woo_product_id);
                $this->log_debug("Image handling result:", $image_ids);
                $this->image_handler->set_product_images($product, $image_ids);
                $product->save();
            }

            // Track the imported product
            $this->log_debug("Tracking imported product - Baserow ID: {$product_data['id']}, WooCommerce ID: {$woo_product_id}");
            $this->product_tracker->track_product($product_data['id'], $woo_product_id);
            $this->log_info("Successfully tracked imported product");

            // Trigger shipping zones update
            $this->log_info("Updating shipping zones");
            do_action('baserow_product_imported', $product_data, $woo_product_id);
            $this->log_info("Shipping zones updated successfully");

            $this->log_info("Product import completed successfully");

            // Update Baserow status
            $update_data = array(
                'imported_to_woo' => true,
                'woo_product_id' => $woo_product_id,
                'last_import_date' => current_time('Y-m-d')
            );
            $this->log_debug("Attempting to update Baserow with data:", $update_data);
            $update_result = $this->api_handler->update_product($product_id, $update_data);
            if (!is_wp_error($update_result)) {
                $this->log_info("Successfully updated Baserow status");
            }

            $this->log_info("Import completed successfully for product ID: " . $product_id);
            return array(
                'success' => true,
                'product_id' => $woo_product_id
            );

        } catch (Exception $e) {
            $this->log_error("Exception during product import: " . $e->getMessage());
            return new WP_Error('import_failed', $e->getMessage());
        }
    }
}
