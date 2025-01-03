<?php
/**
 * Trait: Baserow Logger
 * Provides logging functionality for classes that need logging capabilities.
 * 
 * @version 1.4.0
 */

trait Baserow_Logger_Trait {
    /**
     * Log a debug message
     *
     * @param string|array $message
     * @param array $context
     * @return bool
     */
    protected function log_debug($message, array $context = []): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        if (class_exists('Baserow_Logger')) {
            return Baserow_Logger::debug($message, $context);
        }
        return false;
    }

    /**
     * Log an info message
     *
     * @param string|array $message
     * @param array $context
     * @return bool
     */
    protected function log_info($message, array $context = []): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        if (class_exists('Baserow_Logger')) {
            return Baserow_Logger::info($message, $context);
        }
        return false;
    }

    /**
     * Log an error message
     *
     * @param string|array $message
     * @param array $context
     * @return bool
     */
    protected function log_error($message, array $context = []): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        if (class_exists('Baserow_Logger')) {
            return Baserow_Logger::error($message, $context);
        }
        return false;
    }

    /**
     * Log a warning message
     *
     * @param string|array $message
     * @param array $context
     * @return bool
     */
    protected function log_warning($message, array $context = []): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        if (class_exists('Baserow_Logger')) {
            return Baserow_Logger::warning($message, $context);
        }
        return false;
    }

    /**
     * Log a critical message
     *
     * @param string|array $message
     * @param array $context
     * @return bool
     */
    protected function log_critical($message, array $context = []): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        if (class_exists('Baserow_Logger')) {
            return Baserow_Logger::critical($message, $context);
        }
        return false;
    }

    /**
     * Log context with exception details
     *
     * @param Throwable $exception
     * @return array
     */
    protected function get_exception_context(Throwable $exception): array {
        return [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'class' => get_class($exception)
        ];
    }

    /**
     * Log an exception with full context
     *
     * @param Throwable $exception
     * @param string $message Additional message
     * @param string $level Log level
     * @return bool
     */
    protected function log_exception(Throwable $exception, string $message = '', string $level = 'error'): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }
        $context = $this->get_exception_context($exception);
        $log_message = $message ? $message . ': ' . $exception->getMessage() : $exception->getMessage();
        
        if (class_exists('Baserow_Logger') && method_exists('Baserow_Logger', $level)) {
            return Baserow_Logger::$level($log_message, $context);
        }
        
        return false;
    }

    /**
     * Log with memory usage context
     *
     * @param string|array $message
     * @param string $level
     * @param array $context
     * @return bool
     */
    protected function log_with_memory(
        $message,
        string $level = 'debug',
        array $context = []
    ): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }

        if (!function_exists('size_format')) {
            $context['memory_usage'] = memory_get_usage(true);
            $context['peak_memory'] = memory_get_peak_usage(true);
        } else {
            $context['memory_usage'] = size_format(memory_get_usage(true));
            $context['peak_memory'] = size_format(memory_get_peak_usage(true));
        }
        
        if (class_exists('Baserow_Logger') && method_exists('Baserow_Logger', $level)) {
            return Baserow_Logger::$level($message, $context);
        }
        
        return false;
    }

    /**
     * Log performance metrics
     *
     * @param string $operation
     * @param float $start_time
     * @param array $additional_context
     * @return bool
     */
    protected function log_performance(
        string $operation,
        float $start_time,
        array $additional_context = []
    ): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }

        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time) * 1000, 2);

        $memory_usage = memory_get_usage(true);
        $peak_memory = memory_get_peak_usage(true);

        if (function_exists('size_format')) {
            $memory_usage = size_format($memory_usage);
            $peak_memory = size_format($peak_memory);
        }

        $context = array_merge([
            'operation' => $operation,
            'execution_time_ms' => $execution_time,
            'memory_usage' => $memory_usage,
            'peak_memory' => $peak_memory
        ], $additional_context);

        return $this->log_debug(
            sprintf('Performance metrics for %s: %sms', $operation, $execution_time),
            $context
        );
    }

    /**
     * Log a deprecated feature usage
     *
     * @param string $feature
     * @param string $replacement
     * @param string $version_deprecated
     * @return bool
     */
    protected function log_deprecated(
        string $feature,
        string $replacement = '',
        string $version_deprecated = ''
    ): bool {
        if (!did_action('plugins_loaded')) {
            return false;
        }

        $message = sprintf('Deprecated feature used: %s', $feature);
        $context = [
            'feature' => $feature,
            'replacement' => $replacement,
            'version_deprecated' => $version_deprecated
        ];

        if (function_exists('debug_backtrace')) {
            $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        }

        return $this->log_warning($message, $context);
    }
}
