<?php
/**
 * Class: Baserow Product Image Handler
 * Handles all product image operations.
 * 
 * @version 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Product_Image_Handler {
    use Baserow_Logger_Trait;

    /** @var array */
    private $allowed_mime_types = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'webp' => 'image/webp'
    ];

    /** @var int Max image size in bytes (10MB) */
    private $max_image_size = 10485760;

    /**
     * Process all images for a product
     *
     * @param array $image_urls
     * @param int $product_id
     * @return array
     */
    public function process_product_images(array $image_urls, int $product_id): array {
        $this->log_debug("Processing product images", [
            'product_id' => $product_id,
            'image_count' => count($image_urls)
        ]);

        $image_ids = [];

        foreach ($image_urls as $index => $url) {
            try {
                $result = $this->process_single_image($url, $product_id);
                if (!is_wp_error($result)) {
                    $image_ids[] = $result;
                } else {
                    $this->log_error("Failed to process image", [
                        'url' => $url,
                        'error' => $result->get_error_message()
                    ]);
                }
            } catch (Exception $e) {
                $this->log_exception($e, "Unexpected error processing image");
            }
        }

        return $image_ids;
    }

    /**
     * Process a single image
     *
     * @param string $image_url
     * @param int $product_id
     * @return int|WP_Error
     */
    private function process_single_image(string $image_url, int $product_id) {
        $this->log_debug("Processing single image", [
            'url' => $image_url,
            'product_id' => $product_id
        ]);

        try {
            // Check if image already exists
            $existing_image = $this->get_existing_image($image_url);
            if ($existing_image) {
                $this->log_debug("Image already exists in media library", [
                    'attachment_id' => $existing_image
                ]);
                return $existing_image;
            }

            // Download the image
            $temp_file = $this->download_image($image_url);
            if (is_wp_error($temp_file)) {
                return $temp_file;
            }

            // Validate the downloaded file
            $validation_result = $this->validate_image_file($temp_file);
            if (is_wp_error($validation_result)) {
                @unlink($temp_file);
                return $validation_result;
            }

            // Upload to media library
            $attachment_id = $this->upload_to_media_library($temp_file, $image_url, $product_id);
            @unlink($temp_file);

            return $attachment_id;

        } catch (Exception $e) {
            $this->log_exception($e, "Error processing image");
            return new WP_Error(
                'image_processing_error',
                'Failed to process image: ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if image already exists in media library
     *
     * @param string $image_url
     * @return int|false
     */
    private function get_existing_image(string $image_url) {
        global $wpdb;

        $existing_attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_baserow_image_url' 
            AND meta_value = %s",
            $image_url
        ));

        return $existing_attachment ? intval($existing_attachment) : false;
    }

    /**
     * Download image from URL
     *
     * @param string $url
     * @return string|WP_Error Path to temporary file
     */
    private function download_image(string $url) {
        $this->log_debug("Downloading image", ['url' => $url]);

        require_once(ABSPATH . 'wp-admin/includes/file.php');

        $temp_file = download_url($url);
        
        if (is_wp_error($temp_file)) {
            $this->log_error("Failed to download image", [
                'url' => $url,
                'error' => $temp_file->get_error_message()
            ]);
            return $temp_file;
        }

        return $temp_file;
    }

    /**
     * Validate downloaded image file
     *
     * @param string $file_path
     * @return true|WP_Error
     */
    private function validate_image_file(string $file_path): bool|WP_Error {
        // Check file size
        $file_size = filesize($file_path);
        if ($file_size > $this->max_image_size) {
            return new WP_Error(
                'image_too_large',
                sprintf(
                    'Image file size (%s) exceeds maximum allowed size (%s)',
                    size_format($file_size),
                    size_format($this->max_image_size)
                )
            );
        }

        // Check mime type
        $file_type = wp_check_filetype($file_path, $this->allowed_mime_types);
        if (!$file_type['type']) {
            return new WP_Error(
                'invalid_image_type',
                'Invalid image type. Allowed types: ' . implode(', ', array_keys($this->allowed_mime_types))
            );
        }

        // Verify it's a valid image
        if (!getimagesize($file_path)) {
            return new WP_Error(
                'invalid_image',
                'File is not a valid image'
            );
        }

        return true;
    }

    /**
     * Upload image to media library
     *
     * @param string $file_path
     * @param string $source_url
     * @param int $product_id
     * @return int|WP_Error
     */
    private function upload_to_media_library(
        string $file_path,
        string $source_url,
        int $product_id
    ) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $file_array = [
            'name' => basename($source_url),
            'tmp_name' => $file_path
        ];

        // Upload the file
        $attachment_id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($attachment_id)) {
            $this->log_error("Failed to upload image to media library", [
                'error' => $attachment_id->get_error_message()
            ]);
            return $attachment_id;
        }

        // Store the source URL as meta data
        update_post_meta($attachment_id, '_baserow_image_url', $source_url);

        $this->log_debug("Image uploaded successfully", [
            'attachment_id' => $attachment_id
        ]);

        return $attachment_id;
    }

    /**
     * Set product images
     *
     * @param WC_Product $product
     * @param array $image_ids
     * @return void
     */
    public function set_product_images(WC_Product $product, array $image_ids): void {
        if (empty($image_ids)) {
            return;
        }

        try {
            // Set featured image
            $product->set_image_id($image_ids[0]);

            // Set gallery images
            if (count($image_ids) > 1) {
                $gallery_ids = array_slice($image_ids, 1);
                $product->set_gallery_image_ids($gallery_ids);
            }

            $this->log_debug("Product images set", [
                'product_id' => $product->get_id(),
                'featured_image' => $image_ids[0],
                'gallery_images' => array_slice($image_ids, 1)
            ]);

        } catch (Exception $e) {
            $this->log_exception($e, "Error setting product images");
        }
    }

    /**
     * Clean up orphaned images
     *
     * @return int Number of images cleaned up
     */
    public function cleanup_orphaned_images(): int {
        global $wpdb;

        $this->log_debug("Starting orphaned images cleanup");

        try {
            $orphaned_images = $wpdb->get_results(
                "SELECT p.ID, p.post_title 
                FROM {$wpdb->posts} p 
                LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_value = p.ID 
                AND pm.meta_key IN ('_thumbnail_id', '_product_image_gallery') 
                WHERE p.post_type = 'attachment' 
                AND p.post_mime_type LIKE 'image/%' 
                AND pm.post_id IS NULL"
            );

            $cleaned_count = 0;
            foreach ($orphaned_images as $image) {
                if (wp_delete_attachment($image->ID, true)) {
                    $cleaned_count++;
                    $this->log_debug("Deleted orphaned image", [
                        'image_id' => $image->ID,
                        'title' => $image->post_title
                    ]);
                }
            }

            return $cleaned_count;

        } catch (Exception $e) {
            $this->log_exception($e, "Error during image cleanup");
            return 0;
        }
    }
}
