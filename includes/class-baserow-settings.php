<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Settings {
    private $options_group = 'baserow_importer_settings';
    private $options_page = 'baserow-importer-settings';
    private $api_handler;

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_test_dsz_connection', array($this, 'test_dsz_connection'));
        add_action('wp_ajax_test_baserow_connection', array($this, 'test_baserow_connection'));
        
        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-api-handler.php';
        $this->api_handler = new Baserow_API_Handler();
    }

    public function test_baserow_connection() {
        check_ajax_referer('baserow_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $result = $this->api_handler->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }

        if ($result === true) {
            wp_send_json_success('Successfully connected to Baserow API');
        } else {
            wp_send_json_error('Failed to connect to Baserow API');
        }
    }

    public function test_dsz_connection() {
        check_ajax_referer('baserow_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        require_once BASEROW_IMPORTER_PLUGIN_DIR . 'includes/class-baserow-auth-handler.php';
        $auth_handler = new Baserow_Auth_Handler();

        $token = $auth_handler->get_token();
        if (is_wp_error($token)) {
            wp_send_json_error($token->get_error_message());
            return;
        }

        wp_send_json_success('Successfully authenticated with Dropshipzone API');
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

        wp_enqueue_script(
            'dsz-settings', 
            BASEROW_IMPORTER_PLUGIN_URL . 'assets/js/dsz-settings.js',
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
        register_setting($this->options_group, 'baserow_api_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting($this->options_group, 'baserow_api_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting($this->options_group, 'baserow_table_id', array(
            'sanitize_callback' => 'absint'
        ));

        register_setting($this->options_group, 'baserow_dsz_api_email', array(
            'sanitize_callback' => 'sanitize_email'
        ));
        register_setting($this->options_group, 'baserow_dsz_api_password', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));

        add_settings_section(
            'baserow_api_settings',
            'Baserow API Settings',
            array($this, 'render_section_info'),
            $this->options_page
        );

        add_settings_section(
            'dsz_api_settings',
            'Dropshipzone API Settings',
            array($this, 'render_dsz_section_info'),
            $this->options_page
        );

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

        add_settings_field(
            'baserow_dsz_api_email',
            'DSZ API Email',
            array($this, 'render_dsz_api_email_field'),
            $this->options_page,
            'dsz_api_settings'
        );

        add_settings_field(
            'baserow_dsz_api_password',
            'DSZ API Password',
            array($this, 'render_dsz_api_password_field'),
            $this->options_page,
            'dsz_api_settings'
        );
    }

    public function render_section_info() {
        echo '<p>Enter your Baserow connection details below. After saving, use the test connection button to verify your settings.</p>';
    }

    public function render_dsz_section_info() {
        echo '<p>Enter your Dropshipzone API credentials below. These are used for order synchronization.</p>';
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

    public function render_dsz_api_email_field() {
        $value = get_option('baserow_dsz_api_email');
        ?>
        <input type="email"
               id="baserow_dsz_api_email"
               name="baserow_dsz_api_email"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="api@example.com"
        />
        <p class="description">Your Dropshipzone API email address</p>
        <?php
    }

    public function render_dsz_api_password_field() {
        $value = get_option('baserow_dsz_api_password');
        ?>
        <input type="password"
               id="baserow_dsz_api_password"
               name="baserow_dsz_api_password"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
        />
        <p class="description">Your Dropshipzone API password</p>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
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
                <h2>Connection Tests</h2>
                <div style="margin-bottom: 20px;">
                    <p>Click the button below to test your Baserow connection:</p>
                    <button type="button" class="button button-secondary" id="test-baserow-connection">
                        Test Baserow Connection
                    </button>
                    <span id="connection-status" style="margin-left: 10px; display: inline-block; padding: 5px;">
                    </span>
                </div>

                <div>
                    <p>Click the button below to test your Dropshipzone API connection:</p>
                    <button type="button" class="button button-secondary" id="test-dsz-connection">
                        Test DSZ Connection
                    </button>
                    <span id="dsz-connection-status" style="margin-left: 10px; display: inline-block; padding: 5px;">
                    </span>
                </div>
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
                    <tr>
                        <th>DSZ API Email:</th>
                        <td><?php echo esc_html(get_option('baserow_dsz_api_email', 'Not set')); ?></td>
                    </tr>
                    <tr>
                        <th>DSZ API Password:</th>
                        <td><?php echo get_option('baserow_dsz_api_password') ? '********' : 'Not set'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}
