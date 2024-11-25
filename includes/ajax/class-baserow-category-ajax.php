<?php
/**
 * Class: Baserow Category AJAX Handler
 * Description: Handles AJAX operations for categories
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Category_Ajax {
    use Baserow_Logger_Trait;

    private $category_manager;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_create_category', array($this, 'create_category'));
        add_action('wp_ajax_get_category_hierarchy', array($this, 'get_category_hierarchy'));
        add_action('wp_ajax_merge_categories', array($this, 'merge_categories'));
        add_action('wp_ajax_cleanup_categories', array($this, 'cleanup_categories'));
        add_action('wp_ajax_get_child_categories', array($this, 'get_child_categories'));
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($category_manager) {
        $this->category_manager = $category_manager;
    }

    /**
     * Handle category creation AJAX request
     */
    public function create_category() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $category_path = isset($_POST['category_path']) ? sanitize_text_field($_POST['category_path']) : '';
        
        if (empty($category_path)) {
            wp_send_json_error('Category path is required');
            return;
        }

        $this->log_debug("Creating category from path", array(
            'path' => $category_path
        ));

        try {
            $result = $this->category_manager->create_or_get_categories($category_path);

            if (is_wp_error($result)) {
                $this->log_error("Category creation failed", array(
                    'error' => $result->get_error_message()
                ));
                wp_send_json_error($result->get_error_message());
                return;
            }

            $response = array(
                'category_ids' => $result,
                'message' => 'Categories created successfully'
            );

            // Add category paths to response
            $paths = array();
            foreach ($result as $category_id) {
                $paths[$category_id] = $this->category_manager->get_category_path($category_id);
            }
            $response['category_paths'] = $paths;

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Category creation error", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get category hierarchy AJAX request
     */
    public function get_category_hierarchy() {
        $this->verify_ajax_nonce();

        $category_id = isset($_GET['category_id']) ? absint($_GET['category_id']) : 0;
        
        if (!$category_id) {
            wp_send_json_error('Category ID is required');
            return;
        }

        try {
            $hierarchy = $this->category_manager->get_category_hierarchy($category_id);

            if (is_wp_error($hierarchy)) {
                wp_send_json_error($hierarchy->get_error_message());
                return;
            }

            $response = array(
                'hierarchy' => $hierarchy,
                'path' => $this->category_manager->get_category_path($category_id)
            );

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Error getting category hierarchy", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle merge categories AJAX request
     */
    public function merge_categories() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $target_id = isset($_POST['target_id']) ? absint($_POST['target_id']) : 0;

        if (!$source_id || !$target_id) {
            wp_send_json_error('Source and target category IDs are required');
            return;
        }

        $this->log_debug("Merging categories", array(
            'source_id' => $source_id,
            'target_id' => $target_id
        ));

        try {
            $result = $this->category_manager->merge_categories($source_id, $target_id);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
                return;
            }

            wp_send_json_success(array(
                'message' => 'Categories merged successfully'
            ));

        } catch (Exception $e) {
            $this->log_error("Error merging categories", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle cleanup categories AJAX request
     */
    public function cleanup_categories() {
        $this->verify_ajax_nonce();

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $deleted = $this->category_manager->cleanup_empty_categories();

            if (is_wp_error($deleted)) {
                wp_send_json_error($deleted->get_error_message());
                return;
            }

            wp_send_json_success(array(
                'deleted_count' => $deleted,
                'message' => sprintf('Cleaned up %d empty categories', $deleted)
            ));

        } catch (Exception $e) {
            $this->log_error("Error cleaning up categories", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle get child categories AJAX request
     */
    public function get_child_categories() {
        $this->verify_ajax_nonce();

        $parent_id = isset($_GET['parent_id']) ? absint($_GET['parent_id']) : 0;

        try {
            $children = $this->category_manager->get_child_categories($parent_id);

            if (is_wp_error($children)) {
                wp_send_json_error($children->get_error_message());
                return;
            }

            $response = array(
                'categories' => array()
            );

            foreach ($children as $category) {
                $response['categories'][] = array(
                    'term_id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'count' => $category->count,
                    'path' => $this->category_manager->get_category_path($category->term_id)
                );
            }

            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->log_error("Error getting child categories", array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Verify AJAX nonce
     */
    private function verify_ajax_nonce() {
        if (!check_ajax_referer('baserow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
            exit;
        }
    }
}
