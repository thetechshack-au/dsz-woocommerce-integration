<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'categories/class-baserow-category-manager.php';

/**
 * Handles AJAX requests for category operations
 */
class Baserow_Category_Ajax {
    /** @var Baserow_Category_Manager */
    private $category_manager;

    public function __construct() {
        $this->category_manager = new Baserow_Category_Manager();
        
        // Register AJAX actions
        add_action('wp_ajax_get_categories', array($this, 'get_categories'));
        add_action('wp_ajax_get_category_tree', array($this, 'get_category_tree'));
        add_action('wp_ajax_get_parent_categories', array($this, 'get_parent_categories'));
        add_action('wp_ajax_get_child_categories', array($this, 'get_child_categories'));
        add_action('wp_ajax_update_categories', array($this, 'update_categories'));
    }

    /**
     * Get all categories
     */
    public function get_categories() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            $categories = $this->category_manager->get_categories();
            wp_send_json_success($categories);
        } catch (Exception $e) {
            Baserow_Logger::error("Error getting categories: " . $e->getMessage());
            wp_send_json_error("Failed to get categories: " . $e->getMessage());
        }
    }

    /**
     * Get category tree
     */
    public function get_category_tree() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            $tree = $this->category_manager->get_category_tree();
            wp_send_json_success($tree);
        } catch (Exception $e) {
            Baserow_Logger::error("Error getting category tree: " . $e->getMessage());
            wp_send_json_error("Failed to get category tree: " . $e->getMessage());
        }
    }

    /**
     * Get parent categories
     */
    public function get_parent_categories() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            $parents = $this->category_manager->get_parent_categories();
            wp_send_json_success($parents);
        } catch (Exception $e) {
            Baserow_Logger::error("Error getting parent categories: " . $e->getMessage());
            wp_send_json_error("Failed to get parent categories: " . $e->getMessage());
        }
    }

    /**
     * Get child categories
     */
    public function get_child_categories() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $parent = isset($_POST['parent']) ? sanitize_text_field($_POST['parent']) : '';
        if (empty($parent)) {
            wp_send_json_error('Parent category is required');
        }

        try {
            $children = $this->category_manager->get_child_categories($parent);
            wp_send_json_success($children);
        } catch (Exception $e) {
            Baserow_Logger::error("Error getting child categories: " . $e->getMessage());
            wp_send_json_error("Failed to get child categories: " . $e->getMessage());
        }
    }

    /**
     * Update categories from Baserow
     */
    public function update_categories() {
        check_ajax_referer('baserow_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            // Get API credentials from options
            $api_url = get_option('baserow_api_url');
            $table_id = get_option('baserow_table_id');
            $api_token = get_option('baserow_api_token');

            if (empty($api_url) || empty($table_id) || empty($api_token)) {
                throw new Exception('API configuration is incomplete');
            }

            // Path to Python script
            $script_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'scripts/get_baserow_categories.py';

            $result = $this->category_manager->update_categories(
                $script_path,
                $api_url,
                $table_id,
                $api_token
            );

            if ($result) {
                wp_send_json_success('Categories updated successfully');
            } else {
                throw new Exception('Failed to update categories');
            }
        } catch (Exception $e) {
            Baserow_Logger::error("Error updating categories: " . $e->getMessage());
            wp_send_json_error("Failed to update categories: " . $e->getMessage());
        }
    }
}

// Initialize the class
new Baserow_Category_Ajax();
