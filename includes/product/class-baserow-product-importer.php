<?php
/**
 * Class: Baserow Product Importer
 * Description: Main product import handler
 * Version: 1.6.3
 * Last Updated: 2024-01-16 14:00:00 UTC
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

        // Test logging is working
        error_log('Baserow Product Importer initialized - Testing error_log');
        
        // Check if logging is enabled
        if (class_exists('Baserow_Logger')) {
            $is_enabled = Baserow_Logger::is_logging_enabled();
            error_log('Baserow Logger enabled: ' . ($is_enabled ? 'true' : 'false'));
            
            if ($is_enabled) {
                $this->log_debug('Baserow Product Importer initialized - Testing debug logging');
                $this->log_info('Baserow Product Importer initialized - Testing info logging');
                $this->log_debug('Logger status check', [
                    'logger_enabled' => $is_enabled,
                    'log_file' => Baserow_Logger::get_log_file()
                ]);
            }
        }

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
                // Set SKU first to ensure we have a product ID
                $product->set_sku($product_data['SKU']);
                // Save to get an ID
                $product->save();
            }

            if (!$product || !$product->get_id()) {
                $this->log_error("Failed to create/get product object");
                return new WP_Error('product_creation_failed', 'Failed to create product');
            }

            $woo_product_id = $product->get_id();
            $this->log_debug("Working with product ID: " . $woo_product_id);

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

            // Set brand from mapped data
            if (!empty($woo_data['brand'])) {
                $this->log_debug("Setting brand from mapped data");
                $brand = $woo_data['brand'];
                
                // Create brand term if it doesn't exist
                $term = term_exists($brand, 'brands');
                if (!$term) {
                    $this->log_debug("Creating new brand term: " . $brand);
                    $term = wp_insert_term($brand, 'brands');
                }
                
                if (!is_wp_error($term)) {
                    // Set the brand term
                    $result = wp_set_object_terms($woo_product_id, (int)$term['term_id'], 'brands');
                    $this->log_debug("Brand term set result:", [
                        'brand' => $brand,
                        'term_id' => $term['term_id'],
                        'result' => $result
                    ]);
                } else {
                    $this->log_error("Failed to handle brand term", [
                        'brand' => $brand,
                        'error' => $term->get_error_message()
                    ]);
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

            // Set other meta data
            if (!empty($woo_data['meta_data'])) {
                foreach ($woo_data['meta_data'] as $meta_key => $meta_value) {
                    update_post_meta($woo_product_id, $meta_key, $meta_value);
                }
            }

            // Save product
            $product->save();

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
