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

            // Log the raw product data for debugging
            $this->log_debug("Raw product data from Baserow:", [
                'id' => $product_data['id'] ?? 'unknown',
                'sku' => $product_data['SKU'] ?? 'unknown',
                'ean' => $product_data['EAN Code'] ?? 'not set'
            ]);

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
                // Set SKU first to ensure we have a product ID
                $product->set_sku($product_data['SKU']);
                // Save to get an ID
                $product->save();
            }

            if (!$product || !$product->get_id()) {
                $this->log_error("Failed to create/get product object");
                return new WP_Error('product_creation_failed', 'Failed to create product');
            }

            $product_id = $product->get_id();
            $this->log_debug("Working with product ID: " . $product_id);

            // Set basic product data
            foreach ($woo_data as $key => $value) {
                if ($key !== 'meta_data') {
                    $setter = "set_{$key}";
                    if (method_exists($product, $setter)) {
                        $product->$setter($value);
                    }
                }
            }

            // Handle EAN code first
            if (!empty($product_data['EAN Code'])) {
                $ean = $this->sanitize_text_field($product_data['EAN Code']);
                
                $this->log_debug("Setting EAN code", [
                    'product_id' => $product_id,
                    'ean_value' => $ean
                ]);

                // Try multiple methods to ensure it's set
                $product->update_meta_data('EAN', $ean);
                $product->update_meta_data('_alg_ean', $ean);
                $product->update_meta_data('_barcode', $ean);
                $product->update_meta_data('_wpm_ean', $ean);
                
                // Also try direct post meta
                update_post_meta($product_id, 'EAN', $ean);
                update_post_meta($product_id, '_alg_ean', $ean);
                update_post_meta($product_id, '_barcode', $ean);
                update_post_meta($product_id, '_wpm_ean', $ean);

                // Force a save after EAN
                $product->save();

                // Verify EAN was saved
                $saved_ean = get_post_meta($product_id, 'EAN', true);
                $saved_alg_ean = get_post_meta($product_id, '_alg_ean', true);
                $saved_barcode = get_post_meta($product_id, '_barcode', true);
                $saved_wpm_ean = get_post_meta($product_id, '_wpm_ean', true);

                $this->log_debug("Verified saved EAN data:", [
                    'EAN' => $saved_ean,
                    '_alg_ean' => $saved_alg_ean,
                    '_barcode' => $saved_barcode,
                    '_wpm_ean' => $saved_wpm_ean
                ]);
            }

            // Set other meta data
            foreach ($woo_data['meta_data'] as $meta_key => $meta_value) {
                // Skip EAN-related fields as we handled them above
                if (!in_array($meta_key, ['EAN', '_alg_ean', '_barcode', '_wpm_ean'])) {
                    $product->update_meta_data($meta_key, $meta_value);
                    update_post_meta($product_id, $meta_key, $meta_value);
                    
                    $this->log_debug("Setting meta data", [
                        'key' => $meta_key,
                        'value' => $meta_value
                    ]);
                }
            }

            // Set categories
            if (!empty($product_data['Category'])) {
                $category_ids = $this->category_manager->create_or_get_categories($product_data['Category']);
                if (!is_wp_error($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }

            // Save product again
            $woo_product_id = $product->save();
            if (!$woo_product_id) {
                $this->log_error("Failed to save product");
                return new WP_Error('product_save_failed', 'Failed to save product');
            }

            // Handle images
            if (!empty($woo_data['images'])) {
                $image_ids = $this->image_handler->process_product_images($woo_data['images'], $woo_product_id);
                $this->image_handler->set_product_images($product, $image_ids);
                $product->save();
            }

            // Track the imported product
            $this->product_tracker->track_product($product_data['id'], $woo_product_id);

            // Trigger shipping zones update
            do_action('baserow_product_imported', $product_data, $woo_product_id);

            $this->log_info("Product import completed successfully");
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
