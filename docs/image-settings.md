# Baserow Image Settings

## Overview
The image processing system in the Baserow WooCommerce integration provides configurable settings for handling product images. Settings can be configured through the admin UI or programmatically using filters.

## Default Settings
```php
$default_settings = [
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
```

## Available Filters

### baserow_image_handler_settings
Modify all image processing settings.

```php
add_filter('baserow_image_handler_settings', function($settings) {
    // Modify settings
    $settings['image']['max_width'] = 1600;
    $settings['image']['webp_quality'] = 90;
    return $settings;
});
```

### baserow_image_max_dimensions
Modify maximum image dimensions.

```php
add_filter('baserow_image_max_dimensions', function($dimensions) {
    return [
        'width' => 1600,
        'height' => 1600
    ];
});
```

### baserow_image_quality_settings
Modify image quality settings.

```php
add_filter('baserow_image_quality_settings', function($quality) {
    return [
        'webp' => 90,
        'jpeg' => 85,
        'png' => 9
    ];
});
```

## Usage Examples

### Modifying Settings Programmatically

```php
// Change image dimensions
add_filter('baserow_image_handler_settings', function($settings) {
    $settings['image']['max_width'] = 1600;
    $settings['image']['max_height'] = 1600;
    return $settings;
});

// Enable original file backup
add_filter('baserow_image_handler_settings', function($settings) {
    $settings['processing']['keep_original'] = true;
    return $settings;
});

// Adjust quality settings
add_filter('baserow_image_quality_settings', function($quality) {
    return [
        'webp' => 90,  // Higher quality WebP
        'jpeg' => 85,  // Balanced JPEG quality
        'png' => 9     // Maximum PNG compression
    ];
});
```

### Using Custom Image Handler Instance

```php
// Create instance with custom settings
$image_handler = new Baserow_Product_Image_Handler([
    'image' => [
        'max_width' => 2048,
        'max_height' => 2048,
        'webp_quality' => 90
    ],
    'processing' => [
        'prefer_webp' => true,
        'keep_original' => true
    ]
]);

// Process images with custom settings
$image_ids = $image_handler->process_product_images($image_urls, $product_id);
```

## Best Practices

1. **Image Dimensions**
   - Consider your theme's maximum content width
   - Account for retina displays if needed
   - Balance quality vs. file size

2. **Format Selection**
   - WebP offers better compression than JPEG
   - Enable WebP with JPEG fallback for older browsers
   - Keep originals if storage space allows

3. **Quality Settings**
   - WebP: 80-90 for good balance
   - JPEG: 85-90 for product images
   - PNG: 9 for maximum lossless compression

4. **Error Handling**
   - Monitor logs for conversion failures
   - Implement fallback strategies
   - Consider retry settings for unreliable connections

## Troubleshooting

1. **WebP Conversion Fails**
   - Check PHP version (7.2+ required)
   - Verify GD or ImageMagick support
   - System memory limits

2. **Performance Issues**
   - Adjust max dimensions
   - Fine-tune quality settings
   - Check server resources

3. **Storage Problems**
   - Enable organize_by_date
   - Monitor disk space
   - Implement cleanup routines

## Notes

- Settings are cached for performance
- Changes require cache flush
- Monitor error logs for issues
- Test thoroughly after changes
