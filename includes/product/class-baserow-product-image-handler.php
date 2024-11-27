<?php
/**
 * Class: Baserow Product Image Handler
 * Handles all product image operations with configurable settings.
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

    /** @var array */
    private $settings;

    /** @var array */
    private $temp_files = [];

    /** @var int */
    private $max_retries = 3;

    /**
     * Constructor
     *
     * @param array $settings Optional override settings
     */
    public function __construct(array $settings = []) {
        $this->init_settings($settings);
    }

    /**
     * Initialize settings with defaults, stored options, and overrides
     *
     * @param array $settings
     * @return void
     */
    private function init_settings(array $settings = []): void {
        // Default settings
        $defaults = [
            'max_file_size' => 10485760, // 10MB
            'image' => [
                'max_width' => 1200,
                'max_height' => 1200,
                'maintain_aspect_ratio' => true,
                'webp_quality' => 85,
                'jpeg_quality' => 90,
                'png_compression' => 9
            ],
            'processing' => [
                'prefer_webp' => true,
                'keep_original' => false,
                'max_retries' => 3,
                'timeout' => 30
            ],
            'storage' => [
                'organize_by_date' => true,
                'unique_filename' => true
            ]
        ];

        // Get stored options
        $stored_settings = get_option('baserow_image_settings', []);

        // Merge settings in order of precedence: defaults < stored < overrides
        $merged_settings = wp_parse_args(
            $settings,
            wp_parse_args(
                $stored_settings,
                $defaults
            )
        );

        // Allow filtering of settings
        $this->settings = apply_filters(
            'baserow_image_handler_settings',
            $merged_settings
        );

        // Update class properties that depend on settings
        $this->max_retries = $this->settings['processing']['max_retries'];
    }

    /**
     * Get current settings
     *
     * @return array
     */
    public function get_settings(): array {
        return $this->settings;
    }

    /**
     * Update settings
     *
     * @param array $new_settings
     * @param bool $persist Save to database
     * @return void
     */
    public function update_settings(array $new_settings, bool $persist = false): void {
        $this->init_settings($new_settings);

        if ($persist) {
            update_option('baserow_image_settings', $this->settings);
        }
    }

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
            'image_count' => count($image_urls),
            'settings' => $this->settings
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

        $this->cleanup_temp_files();
        return $image_ids;
    }

    /**
     * Process a single image with safety checks
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
                return $existing_image;
            }

            // Download and verify the image
            $download_result = $this->download_and_verify_image($image_url);
            if (is_wp_error($download_result)) {
                return $download_result;
            }
            [$temp_file, $response] = $download_result;
            $this->temp_files[] = $temp_file;

            // Keep original if configured
            if ($this->settings['processing']['keep_original']) {
                $original_file = $temp_file . '_original';
                copy($temp_file, $original_file);
                $this->temp_files[] = $original_file;
            }

            // Validate the downloaded file
            $validation_result = $this->validate_image_file($temp_file, $response);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Process the image with configured settings
            $processed_file = $this->optimize_image_safely($temp_file);
            if (is_wp_error($processed_file)) {
                if ($this->settings['processing']['keep_original']) {
                    $this->log_warning("Using original file after optimization failure");
                    $processed_file = $original_file;
                } else {
                    $this->log_warning("Using unoptimized file after optimization failure");
                    $processed_file = $temp_file;
                }
            } else {
                $this->temp_files[] = $processed_file;
            }

            // Verify processed file integrity
            if (!$this->verify_file_integrity($processed_file)) {
                return new WP_Error(
                    'file_integrity_error',
                    'Processed file integrity check failed'
                );
            }

            // Upload to media library with verification
            $attachment_id = $this->upload_to_media_library_safely($processed_file, $image_url, $product_id);

            return $attachment_id;

        } catch (Exception $e) {
            $this->log_exception($e, "Error processing image");
            return new WP_Error(
                'image_processing_error',
                'Failed to process image: ' . $e->getMessage()
            );
        } finally {
            $this->cleanup_temp_files();
        }
    }

    /**
     * Optimize image safely with configured settings
     *
     * @param string $file_path
     * @return string|WP_Error
     */
    private function optimize_image_safely(string $file_path) {
        try {
            $image = wp_get_image_editor($file_path);
            if (is_wp_error($image)) {
                throw new Exception($image->get_error_message());
            }

            // Resize image
            $resize_result = $image->resize(
                $this->settings['image']['max_width'],
                $this->settings['image']['max_height'],
                $this->settings['image']['maintain_aspect_ratio']
            );
            if (is_wp_error($resize_result)) {
                throw new Exception($resize_result->get_error_message());
            }

            // Try WebP if preferred
            if ($this->settings['processing']['prefer_webp']) {
                $webp_result = $this->convert_to_webp_safely($image);
                if (!is_wp_error($webp_result)) {
                    return $webp_result;
                }
            }

            // Fallback to JPEG
            $this->log_warning("Using JPEG format");
            return $this->optimize_as_jpeg_safely($image);

        } catch (Exception $e) {
            return new WP_Error(
                'optimization_failed',
                'Failed to optimize image: ' . $e->getMessage()
            );
        }
    }

    /**
     * Convert to WebP safely
     *
     * @param WP_Image_Editor $image
     * @return string|WP_Error
     */
    private function convert_to_webp_safely(WP_Image_Editor $image) {
        $temp_file = wp_tempnam('image_webp_');
        $this->temp_files[] = $temp_file;

        try {
            $image->set_quality($this->settings['image']['webp_quality']);
            $result = $image->save($temp_file, 'image/webp');

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Verify conversion result
            if (!$this->verify_file_integrity($result['path'])) {
                throw new Exception('WebP conversion verification failed');
            }

            return $result['path'];

        } catch (Exception $e) {
            return new WP_Error(
                'webp_conversion_failed',
                'WebP conversion failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Optimize as JPEG safely
     *
     * @param WP_Image_Editor $image
     * @return string|WP_Error
     */
    private function optimize_as_jpeg_safely(WP_Image_Editor $image) {
        $temp_file = wp_tempnam('image_jpeg_');
        $this->temp_files[] = $temp_file;

        try {
            $image->set_quality($this->settings['image']['jpeg_quality']);
            $result = $image->save($temp_file, 'image/jpeg');

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Verify optimization result
            if (!$this->verify_file_integrity($result['path'])) {
                throw new Exception('JPEG optimization verification failed');
            }

            return $result['path'];

        } catch (Exception $e) {
            return new WP_Error(
                'jpeg_optimization_failed',
                'JPEG optimization failed: ' . $e->getMessage()
            );
        }
    }

    // ... [rest of the existing methods remain unchanged] ...

    /**
     * Get default settings
     *
     * @return array
     */
    public static function get_default_settings(): array {
        return [
            'max_file_size' => 10485760, // 10MB
            'image' => [
                'max_width' => 1200,
                'max_height' => 1200,
                'maintain_aspect_ratio' => true,
                'webp_quality' => 85,
                'jpeg_quality' => 90,
                'png_compression' => 9
            ],
            'processing' => [
                'prefer_webp' => true,
                'keep_original' => false,
                'max_retries' => 3,
                'timeout' => 30
            ],
            'storage' => [
                'organize_by_date' => true,
                'unique_filename' => true
            ]
        ];
    }
}
