<?php
/**
 * Class: Baserow Category Manager
 * Description: Handles creation and management of product categories
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Category_Manager {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    /**
     * Create or get categories from path
     */
    public function create_or_get_categories($category_path) {
        $this->log_debug("Processing category path", array('path' => $category_path));

        // Validate category path
        $validation_result = $this->validate_category_data($category_path);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        $categories = explode('>', $category_path);
        $categories = array_map('trim', $categories);
        $parent_id = 0;
        $category_ids = array();

        foreach ($categories as $category_name) {
            $result = $this->create_or_get_category($category_name, $parent_id);
            if (is_wp_error($result)) {
                return $result;
            }

            $parent_id = $result['term_id'];
            $category_ids[] = $result['term_id'];
        }

        return $category_ids;
    }

    /**
     * Create or get single category
     */
    private function create_or_get_category($category_name, $parent_id = 0) {
        $this->log_debug("Processing category", array(
            'name' => $category_name,
            'parent' => $parent_id
        ));

        // Check if category exists
        $term = term_exists($category_name, 'product_cat', $parent_id);
        
        if (!$term) {
            $this->log_info("Creating new category", array(
                'name' => $category_name,
                'parent' => $parent_id
            ));

            $term = wp_insert_term(
                $category_name,
                'product_cat',
                array(
                    'parent' => $parent_id,
                    'slug' => $this->generate_unique_slug($category_name)
                )
            );

            if (is_wp_error($term)) {
                $this->log_error("Failed to create category", array(
                    'name' => $category_name,
                    'error' => $term->get_error_message()
                ));
                return $term;
            }
        }

        return $term;
    }

    /**
     * Generate unique category slug
     */
    private function generate_unique_slug($category_name) {
        $slug = sanitize_title($category_name);
        $original_slug = $slug;
        $counter = 1;

        while (term_exists($slug, 'product_cat')) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get category hierarchy
     */
    public function get_category_hierarchy($category_id) {
        $hierarchy = array();
        $term = get_term($category_id, 'product_cat');

        if (is_wp_error($term) || !$term) {
            return new WP_Error(
                'invalid_category',
                'Category not found'
            );
        }

        $hierarchy[] = $term;

        while ($term->parent != 0) {
            $term = get_term($term->parent, 'product_cat');
            if (is_wp_error($term)) {
                break;
            }
            array_unshift($hierarchy, $term);
        }

        return $hierarchy;
    }

    /**
     * Get category path string
     */
    public function get_category_path($category_id) {
        $hierarchy = $this->get_category_hierarchy($category_id);
        
        if (is_wp_error($hierarchy)) {
            return $hierarchy;
        }

        return implode(' > ', array_map(function($term) {
            return $term->name;
        }, $hierarchy));
    }

    /**
     * Get all child categories
     */
    public function get_child_categories($parent_id = 0) {
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => $parent_id
        );

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            $this->log_error("Failed to get child categories", array(
                'parent_id' => $parent_id,
                'error' => $terms->get_error_message()
            ));
            return $terms;
        }

        return $terms;
    }

    /**
     * Update category meta
     */
    public function update_category_meta($category_id, $meta_data) {
        foreach ($meta_data as $key => $value) {
            update_term_meta($category_id, $key, $value);
        }

        $this->log_debug("Updated category meta", array(
            'category_id' => $category_id,
            'meta_data' => $meta_data
        ));

        return true;
    }

    /**
     * Clean up empty categories
     */
    public function cleanup_empty_categories() {
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'ids'
        );

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            return $terms;
        }

        $deleted = 0;
        foreach ($terms as $term_id) {
            $products = get_posts(array(
                'post_type' => 'product',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $term_id
                    )
                ),
                'posts_per_page' => 1
            ));

            if (empty($products)) {
                $result = wp_delete_term($term_id, 'product_cat');
                if (!is_wp_error($result)) {
                    $deleted++;
                }
            }
        }

        $this->log_info("Cleaned up empty categories", array(
            'deleted_count' => $deleted
        ));

        return $deleted;
    }

    /**
     * Merge categories
     */
    public function merge_categories($source_id, $target_id) {
        // Get products from source category
        $products = get_posts(array(
            'post_type' => 'product',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $source_id
                )
            ),
            'posts_per_page' => -1
        ));

        // Move products to target category
        foreach ($products as $product) {
            wp_set_object_terms(
                $product->ID,
                array($target_id),
                'product_cat',
                true // Append to existing terms
            );
        }

        // Delete source category
        $result = wp_delete_term($source_id, 'product_cat');

        if (is_wp_error($result)) {
            $this->log_error("Failed to merge categories", array(
                'source_id' => $source_id,
                'target_id' => $target_id,
                'error' => $result->get_error_message()
            ));
            return $result;
        }

        $this->log_info("Categories merged successfully", array(
            'source_id' => $source_id,
            'target_id' => $target_id,
            'products_moved' => count($products)
        ));

        return true;
    }
}
