<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Settings {
    private $options_group = 'baserow_importer_settings';
    private $options_page = 'baserow-importer-settings';

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'baserow-importer_page_baserow-importer-settings') {
            return;
        }

        wp_enqueue_script(
            'baserow-settings', 
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/settings.js',
            array('jquery'),
            BASEROW_IMPORTER_VERSION,
            true
        );

        wp_localize_script('baserow-settings', 'baserowSettings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('baserow_test_connection')
        ));
    }

    public function register_settings() {
        // Register settings with sanitization
        register_setting($this->options_group, 'baserow_api_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting($this->options_group, 'baserow_api_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting($this->options_group, 'baserow_table_id', array(
            'sanitize_callback' => 'absint'
        ));

        // Add settings section
        add_settings_section(
            'baserow_api_settings',
            'Baserow API Settings',
            array($this, 'render_section_info'),
            $this->options_page
        );

        // Add settings fields
        add_settings_field(
            'baserow_api_url',
            'Baserow URL',
            array($this, 'render_api_url_field'),
            $this->options_page,
            'baserow_api_settings'
        );

        add_settings_field(
            'baserow_api_token',
            'API Token',
            array($this, 'render_api_token_field'),
            $this->options_page,
            'baserow_api_settings'
        );

        add_settings_field(
            'baserow_table_id',
            'Table ID',
            array($this, 'render_table_id_field'),
            $this->options_page,
            'baserow_api_settings'
        );
    }

    public function render_section_info() {
        echo '<p>Enter your Baserow connection details below. After saving, use the test connection button to verify your settings.</p>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            // Show settings updated message
            if (isset($_GET['settings-updated'])) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully.</p>
                </div>
                <?php
            }
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields($this->options_group);
                do_settings_sections($this->options_page);
                submit_button('Save Settings');
                ?>
            </form>

            <div class="baserow-connection-test" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Connection Test</h2>
                <p>Click the button below to test your Baserow connection:</p>
                
                <button type="button" class="button button-secondary" id="test-baserow-connection">
                    Test Connection
                </button>
                
                <span id="connection-status" style="margin-left: 10px; display: inline-block; padding: 5px;">
                </span>
            </div>

            <div class="baserow-current-settings" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Current Settings</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Baserow URL:</th>
                        <td><?php echo esc_html(get_option('baserow_api_url', 'Not set')); ?></td>
                    </tr>
                    <tr>
                        <th>API Token:</th>
                        <td><?php echo get_option('baserow_api_token') ? '********' : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Table ID:</th>
                        <td><?php echo esc_html(get_option('baserow_table_id', 'Not set')); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_api_url_field() {
        $value = get_option('baserow_api_url');
        ?>
        <input type="url"
               id="baserow_api_url"
               name="baserow_api_url"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="https://baserow.yourdomain.com"
        />
        <p class="description">Your Baserow instance URL (e.g., https://baserow.yourdomain.com)</p>
        <?php
    }

    public function render_api_token_field() {
        $value = get_option('baserow_api_token');
        ?>
        <input type="password"
               id="baserow_api_token"
               name="baserow_api_token"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
        />
        <p class="description">Your Baserow API token</p>
        <?php
    }

    public function render_table_id_field() {
        $value = get_option('baserow_table_id');
        ?>
        <input type="number"
               id="baserow_table_id"
               name="baserow_table_id"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
        />
        <p class="description">Your Baserow table ID</p>
        <?php
    }
}
