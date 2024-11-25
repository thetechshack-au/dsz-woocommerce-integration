<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Logger {
    private static $log_enabled = true;
    private static $log_file;
    private static $log_dir;
    private static $init_complete = false;

    public static function init() {
        if (self::$init_complete) {
            return;
        }

        // Get WordPress uploads directory
        $upload_dir = wp_upload_dir();
        self::$log_dir = trailingslashit($upload_dir['basedir']) . 'baserow-importer/logs';

        // Create logs directory if it doesn't exist
        if (!file_exists(self::$log_dir)) {
            wp_mkdir_p(self::$log_dir);
        }

        // Set log file path
        self::$log_file = trailingslashit(self::$log_dir) . 'baserow-importer.log';

        // Create log file if it doesn't exist
        if (!file_exists(self::$log_file)) {
            touch(self::$log_file);
        }

        self::check_permissions();
        
        // Add notice hook regardless of current permission state
        add_action('admin_notices', array(__CLASS__, 'display_write_permission_error'));
        
        self::$init_complete = true;
    }

    private static function check_permissions() {
        // Check both directory and file permissions
        if (!is_writable(self::$log_dir) || !is_writable(self::$log_file)) {
            self::$log_enabled = false;
            return false;
        }
        self::$log_enabled = true;
        return true;
    }

    public static function display_write_permission_error() {
        // Recheck permissions every time we might display the error
        self::check_permissions();
        
        if (!self::$log_enabled) {
            $message = sprintf(
                'Baserow Importer log file is not writable. Please check permissions for: %s',
                self::$log_file
            );
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public static function log($message, $type = 'info') {
        if (!isset(self::$log_file) || !self::$init_complete) {
            self::init();
        }

        // Recheck permissions before attempting to write
        if (!self::check_permissions()) {
            return false;
        }

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $log_message = sprintf(
            '[%s] [%s] %s' . PHP_EOL,
            current_time('mysql'),
            strtoupper($type),
            $message
        );

        // Try to write to log file
        $result = error_log($log_message, 3, self::$log_file);
        
        // If writing fails, update permissions status
        if (!$result) {
            self::$log_enabled = false;
            return false;
        }

        return true;
    }

    public static function info($message) {
        return self::log($message, 'info');
    }

    public static function error($message) {
        return self::log($message, 'error');
    }

    public static function debug($message) {
        return self::log($message, 'debug');
    }

    public static function get_log_file() {
        if (!isset(self::$log_file)) {
            self::init();
        }
        return self::$log_file;
    }

    public static function get_log_dir() {
        if (!isset(self::$log_dir)) {
            self::init();
        }
        return self::$log_dir;
    }

    public static function is_logging_enabled() {
        return self::$log_enabled;
    }
}

// Initialize the logger
Baserow_Logger::init();
