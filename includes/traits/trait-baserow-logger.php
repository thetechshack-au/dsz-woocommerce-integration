<?php
/**
 * Trait: Baserow Logger
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

trait Baserow_Logger_Trait {
    protected function log_debug($message, $context = array()) {
        if (class_exists('Baserow_Logger')) {
            Baserow_Logger::debug($message, $context);
        }
    }

    protected function log_info($message, $context = array()) {
        if (class_exists('Baserow_Logger')) {
            Baserow_Logger::info($message, $context);
        }
    }

    protected function log_error($message, $context = array()) {
        if (class_exists('Baserow_Logger')) {
            Baserow_Logger::error($message, $context);
        }
    }

    protected function log_warning($message, $context = array()) {
        if (class_exists('Baserow_Logger')) {
            Baserow_Logger::warning($message, $context);
        }
    }
}
