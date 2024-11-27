<?php
/**
 * Class: Baserow Image Settings
 * Handles image processing settings in admin panel.
 * 
 * @version 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Image_Settings {
    /**
     * Initialize the settings
     */
    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page'], 20);
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting(
            'baserow_image_settings',
            'baserow_image_settings',
            [$this, 'sanitize_settings']
        );

        add_settings_section(
            'baserow_image_settings_section',
            'Image Processing Settings',
            [$this, 'settings_section_callback'],
            'baserow_image_settings'
        );

        // Image Size Settings
        add_settings_field(
            'image_dimensions',
            'Maximum Image Dimensions',
            [$this, 'render_dimensions_field'],
            'baserow_image_settings',
            'baserow_image_settings_section'
        );

        // Image Quality Settings
        add_settings_field(
            'image_quality',
            'Image Quality Settings',
            [$this, 'render_quality_field'],
            'baserow_image_settings',
            'baserow_image_settings_section'
        );

        // Processing Settings
        add_settings_field(
            'processing_options',
            'Processing Options',
            [$this, 'render_processing_field'],
            'baserow_image_settings',
            'baserow_image_settings_section'
        );

        // Storage Settings
        add_settings_field(
            'storage_options',
            'Storage Options',
            [$this, 'render_storage_field'],
            'baserow_image_settings',
            'baserow_image_settings_section'
        );
    }

    /**
     * Add settings page to menu
     */
    public function add_settings_page(): void {
        add_submenu_page(
            'baserow-settings',
            'Image Settings',
            'Image Settings',
            'manage_options',
            'baserow-image-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form was submitted
        if (isset($_POST['baserow_image_settings'])) {
            check_admin_referer('baserow_image_settings');
            $settings = $this->sanitize_settings($_POST['baserow_image_settings']);
            update_option('baserow_image_settings', $settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }

        $settings = get_option('baserow_image_settings', Baserow_Product_Image_Handler::get_default_settings());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="" method="post">
                <?php
                wp_nonce_field('baserow_image_settings');
                settings_fields('baserow_image_settings');
                do_settings_sections('baserow_image_settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Settings section callback
     */
    public function settings_section_callback(): void {
        echo '<p>Configure image processing settings for product imports.</p>';
    }

    /**
     * Render dimensions field
     */
    public function render_dimensions_field(): void {
        $settings = get_option('baserow_image_settings', Baserow_Product_Image_Handler::get_default_settings());
        ?>
        <fieldset>
            <label>
                Maximum Width:
                <input type="number" 
                       name="baserow_image_settings[image][max_width]" 
                       value="<?php echo esc_attr($settings['image']['max_width']); ?>"
                       min="0"
                       max="9999"
                > pixels
            </label>
            <br>
            <label>
                Maximum Height:
                <input type="number" 
                       name="baserow_image_settings[image][max_height]" 
                       value="<?php echo esc_attr($settings['image']['max_height']); ?>"
                       min="0"
                       max="9999"
                > pixels
            </label>
            <br>
            <label>
                <input type="checkbox" 
                       name="baserow_image_settings[image][maintain_aspect_ratio]" 
                       <?php checked($settings['image']['maintain_aspect_ratio']); ?>
                >
                Maintain Aspect Ratio
            </label>
            <p class="description">Set maximum dimensions for imported product images.</p>
        </fieldset>
        <?php
    }

    /**
     * Render quality field
     */
    public function render_quality_field(): void {
        $settings = get_option('baserow_image_settings', Baserow_Product_Image_Handler::get_default_settings());
        ?>
        <fieldset>
            <label>
                WebP Quality:
                <input type="number" 
                       name="baserow_image_settings[image][webp_quality]" 
                       value="<?php echo esc_attr($settings['image']['webp_quality']); ?>"
                       min="0"
                       max="100"
                > (0-100)
            </label>
            <br>
            <label>
                JPEG Quality:
                <input type="number" 
                       name="baserow_image_settings[image][jpeg_quality]" 
                       value="<?php echo esc_attr($settings['image']['jpeg_quality']); ?>"
                       min="0"
                       max="100"
                > (0-100)
            </label>
            <p class="description">Set quality levels for image compression.</p>
        </fieldset>
        <?php
    }

    /**
     * Render processing field
     */
    public function render_processing_field(): void {
        $settings = get_option('baserow_image_settings', Baserow_Product_Image_Handler::get_default_settings());
        ?>
        <fieldset>
            <label>
                <input type="checkbox" 
                       name="baserow_image_settings[processing][prefer_webp]" 
                       <?php checked($settings['processing']['prefer_webp']); ?>
                >
                Prefer WebP Format
            </label>
            <br>
            <label>
                <input type="checkbox" 
                       name="baserow_image_settings[processing][keep_original]" 
                       <?php checked($settings['processing']['keep_original']); ?>
                >
                Keep Original Files
            </label>
            <br>
            <label>
                Maximum Retries:
                <input type="number" 
                       name="baserow_image_settings[processing][max_retries]" 
                       value="<?php echo esc_attr($settings['processing']['max_retries']); ?>"
                       min="1"
                       max="10"
                >
            </label>
            <p class="description">Configure image processing behavior.</p>
        </fieldset>
        <?php
    }

    /**
     * Render storage field
     */
    public function render_storage_field(): void {
        $settings = get_option('baserow_image_settings', Baserow_Product_Image_Handler::get_default_settings());
        ?>
        <fieldset>
            <label>
                <input type="checkbox" 
                       name="baserow_image_settings[storage][organize_by_date]" 
                       <?php checked($settings['storage']['organize_by_date']); ?>
                >
                Organize Files by Date
            </label>
            <br>
            <label>
                <input type="checkbox" 
                       name="baserow_image_settings[storage][unique_filename]" 
                       <?php checked($settings['storage']['unique_filename']); ?>
                >
                Ensure Unique Filenames
            </label>
            <p class="description">Configure how processed images are stored.</p>
        </fieldset>
        <?php
    }

    /**
     * Sanitize settings
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];

        // Image settings
        $sanitized['image']['max_width'] = absint($input['image']['max_width']);
        $sanitized['image']['max_height'] = absint($input['image']['max_height']);
        $sanitized['image']['maintain_aspect_ratio'] = isset($input['image']['maintain_aspect_ratio']);
        $sanitized['image']['webp_quality'] = min(100, max(0, absint($input['image']['webp_quality'])));
        $sanitized['image']['jpeg_quality'] = min(100, max(0, absint($input['image']['jpeg_quality'])));

        // Processing settings
        $sanitized['processing']['prefer_webp'] = isset($input['processing']['prefer_webp']);
        $sanitized['processing']['keep_original'] = isset($input['processing']['keep_original']);
        $sanitized['processing']['max_retries'] = min(10, max(1, absint($input['processing']['max_retries'])));
        $sanitized['processing']['timeout'] = 30; // Fixed value for now

        // Storage settings
        $sanitized['storage']['organize_by_date'] = isset($input['storage']['organize_by_date']);
        $sanitized['storage']['unique_filename'] = isset($input['storage']['unique_filename']);

        return $sanitized;
    }
}

// Initialize settings
new Baserow_Image_Settings();
