<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced logging functionality for the Baserow Importer plugin.
 * Implements PSR-3 inspired logging with WordPress integration.
 * 
 * @version 1.6.0
 */
class Baserow_Logger {
    /** @var bool */
    private static $log_enabled = false;
    
    /** @var string */
    private static $log_file;
    
    /** @var string */
    private static $log_dir;
    
    /** @var bool */
    private static $init_complete = false;
    
    /** @var int Max log file size in bytes (5MB) */
    private static $max_log_size = 5242880;
    
    /** @var string */
    private static $log_level = 'debug';

    /** @var array */
    private static $valid_levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * Initialize the logger
     *
     * @return void
     */
    public static function init(): void {
        if (self::$init_complete || !function_exists('wp_upload_dir')) {
            return;
        }

        try {
            self::setup_log_directory();
            self::check_permissions();
            
            if (did_action('init')) {
                self::setup_hooks();
            } else {
                add_action('init', [__CLASS__, 'setup_hooks']);
            }
            
            self::$init_complete = true;
            self::$log_enabled = true;
        } catch (Exception $e) {
            self::$log_enabled = false;
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html(sprintf('Baserow Logger initialization failed: %s', $e->getMessage()))
                    );
                });
            }
        }
    }

    /**
     * Set up hooks
     *
     * @return void
     */
    public static function setup_hooks(): void {
        if (!self::$log_enabled) {
            return;
        }

        add_action('admin_notices', [__CLASS__, 'display_write_permission_error']);
        add_action('admin_init', [__CLASS__, 'maybe_rotate_log']);
    }

    /**
     * Set up log directory and file
     *
     * @throws Exception If directory creation fails
     * @return void
     */
    private static function setup_log_directory(): void {
        if (!function_exists('wp_upload_dir')) {
            throw new Exception('WordPress not fully loaded');
        }

        $upload_dir = wp_upload_dir();
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            throw new Exception('Failed to get WordPress upload directory');
        }

        self::$log_dir = trailingslashit($upload_dir['basedir']) . 'baserow-importer/logs';
        self::$log_file = trailingslashit(self::$log_dir) . 'baserow-importer.log';

        if (!file_exists(self::$log_dir) && !wp_mkdir_p(self::$log_dir)) {
            throw new Exception('Failed to create log directory');
        }

        if (!file_exists(self::$log_file) && !touch(self::$log_file)) {
            throw new Exception('Failed to create log file');
        }
    }

    /**
     * Check and verify permissions
     *
     * @return bool
     */
    private static function check_permissions(): bool {
        if (!self::$log_dir || !self::$log_file) {
            return false;
        }

        if (!is_writable(self::$log_dir) || !is_writable(self::$log_file)) {
            self::$log_enabled = false;
            return false;
        }

        self::$log_enabled = true;
        return true;
    }

    /**
     * Display permission error notice
     *
     * @return void
     */
    public static function display_write_permission_error(): void {
        if (!self::check_permissions()) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(sprintf(
                    'Baserow Importer log file is not writable. Please check permissions for: %s',
                    self::$log_file
                ))
            );
        }
    }

    /**
     * Rotate log file if it exceeds max size
     *
     * @return void
     */
    public static function maybe_rotate_log(): void {
        if (!self::$log_enabled || !self::$log_file || !file_exists(self::$log_file)) {
            return;
        }

        $size = filesize(self::$log_file);
        if ($size > self::$max_log_size) {
            $backup_file = self::$log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename(self::$log_file, $backup_file);
            touch(self::$log_file);
            
            // Clean up old backup files (keep last 5)
            $backup_files = glob(self::$log_file . '.*.bak');
            if ($backup_files && count($backup_files) > 5) {
                array_map('unlink', array_slice($backup_files, 0, -5));
            }
        }
    }

    /**
     * Main logging method
     *
     * @param string|array $message Message to log
     * @param string $level Log level
     * @param array $context Additional context
     * @return bool
     */
    public static function log($message, string $level = 'info', array $context = []): bool {
        if (!self::$init_complete && did_action('plugins_loaded')) {
            self::init();
        }

        if (!self::$log_enabled || !in_array($level, self::$valid_levels)) {
            return false;
        }

        // Check if we should log based on level
        if (array_search($level, self::$valid_levels) > array_search(self::$log_level, self::$valid_levels)) {
            return true;
        }

        try {
            $formatted_message = self::format_message($message, $level, $context);
            return error_log($formatted_message . PHP_EOL, 3, self::$log_file);
        } catch (Exception $e) {
            self::$log_enabled = false;
            return false;
        }
    }

    /**
     * Format log message
     *
     * @param mixed $message
     * @param string $level
     * @param array $context
     * @return string
     */
    private static function format_message($message, string $level, array $context): string {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $message = self::interpolate($message, $context);

        return sprintf(
            '[%s] [%s] [memory: %s] %s',
            current_time('mysql'),
            strtoupper($level),
            size_format(memory_get_usage(true)),
            $message
        );
    }

    /**
     * Interpolate context values into message placeholders
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private static function interpolate(string $message, array $context): string {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            } elseif (is_array($val)) {
                $replace['{' . $key . '}'] = json_encode($val);
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Set logging level
     *
     * @param string $level
     * @return void
     */
    public static function set_log_level(string $level): void {
        if (in_array($level, self::$valid_levels)) {
            self::$log_level = $level;
        }
    }

    // Convenience methods for different log levels
    public static function emergency($message, array $context = []): bool {
        return self::log($message, 'emergency', $context);
    }

    public static function alert($message, array $context = []): bool {
        return self::log($message, 'alert', $context);
    }

    public static function critical($message, array $context = []): bool {
        return self::log($message, 'critical', $context);
    }

    public static function error($message, array $context = []): bool {
        return self::log($message, 'error', $context);
    }

    public static function warning($message, array $context = []): bool {
        return self::log($message, 'warning', $context);
    }

    public static function notice($message, array $context = []): bool {
        return self::log($message, 'notice', $context);
    }

    public static function info($message, array $context = []): bool {
        return self::log($message, 'info', $context);
    }

    public static function debug($message, array $context = []): bool {
        return self::log($message, 'debug', $context);
    }

    // Getter methods
    public static function get_log_file(): string {
        if (!self::$init_complete && did_action('plugins_loaded')) {
            self::init();
        }
        return self::$log_file ?? '';
    }

    public static function get_log_dir(): string {
        if (!self::$init_complete && did_action('plugins_loaded')) {
            self::init();
        }
        return self::$log_dir ?? '';
    }

    public static function is_logging_enabled(): bool {
        return self::$log_enabled;
    }
}
