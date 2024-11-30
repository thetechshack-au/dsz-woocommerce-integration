<?php
/**
 * Class: Baserow Category Manager
 * Description: Handles creation and management of product categories
 * Version: 1.4.2
 * Last Updated: 2024-01-15 15:00:00 UTC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Category_Manager {
    use Baserow_Logger_Trait;
    use Baserow_Data_Validator_Trait;

    /**
     * Expected CSV headers
     */
    private const EXPECTED_CSV_HEADERS = [
        'Category ID',
        'Category Name',
        'Parent Category',
        'Top Category',
        'Full Path'
    ];

    /**
     * Compare CSV categories with existing WordPress categories
     *
     * @param array $csv_categories Array of categories from CSV
     * @return array Analysis of categories to add/update
     */
    public function analyze_category_differences($csv_categories) {
        $this->log_debug("Starting category analysis", array(
            'csv_count' => count($csv_categories)
        ));

        $differences = array(
            'to_create' => array(),
            'to_update' => array(),
            'existing' => array(),
            'invalid' => array()
        );

        foreach ($csv_categories as $csv_category) {
            try {
                // Validate category data
                if (empty($csv_category['Full Path']) || empty($csv_category['Category Name'])) {
                    $differences['invalid'][] = $csv_category;
                    continue;
                }

                // Check if category exists by full path
                $existing_term = $this->find_category_by_path($csv_category['Full Path']);

                if ($existing_term) {
                    // Category exists - check if it needs updates
                    if ($this->category_needs_update($existing_term, $csv_category)) {
                        $csv_category['term_id'] = $existing_term->term_id;
                        $differences['to_update'][] = $csv_category;
                    } else {
                        $differences['existing'][] = $csv_category;
                    }
                } else {
                    // New category
                    $differences['to_create'][] = $csv_category;
                }
            } catch (Exception $e) {
                $this->log_error("Error analyzing category", array(
                    'category' => $csv_category,
                    'error' => $e->getMessage()
                ));
                $differences['invalid'][] = $csv_category;
            }
        }

        $this->log_info("Category analysis completed", array(
            'to_create' => count($differences['to_create']),
            'to_update' => count($differences['to_update']),
            'existing' => count($differences['existing']),
            'invalid' => count($differences['invalid'])
        ));

        return $differences;
    }

    /**
     * Find category by its full path
     *
     * @param string $path Full category path
     * @return WP_Term|null Found term or null
     */
    private function find_category_by_path($path) {
        $path_parts = array_map('trim', explode('>', $path));
        $current_parent_id = 0;
        $final_term = null;

        foreach ($path_parts as $part) {
            $term = term_exists($part, 'product_cat', $current_parent_id);
            if (!$term) {
                return null;
            }
            $current_parent_id = $term['term_id'];
            $final_term = get_term($term['term_id'], 'product_cat');
        }

        return $final_term;
    }

    /**
     * Check if category needs update
     *
     * @param WP_Term $existing_term Existing WordPress term
     * @param array $csv_category Category data from CSV
     * @return bool Whether category needs update
     */
    private function category_needs_update($existing_term, $csv_category) {
        // Check name difference
        if ($existing_term->name !== $csv_category['Category Name']) {
            return true;
        }

        // Check parent category
        $current_path = $this->get_category_path($existing_term->term_id);
        return $current_path !== $csv_category['Full Path'];
    }

    /**
     * Validate and read categories from CSV file
     *
     * @param string $file_path Path to the CSV file
     * @return array|WP_Error Array of category data or WP_Error on failure
     */
    public function validate_csv_file($file_path) {
        if (!file_exists($file_path)) {
            $this->log_error("CSV file not found", array('path' => $file_path));
            return new WP_Error('csv_not_found', 'Category CSV file not found');
        }

        if (!is_readable($file_path)) {
            $this->log_error("CSV file not readable", array('path' => $file_path));
            return new WP_Error('csv_not_readable', 'Category CSV file is not readable');
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_error("Failed to open CSV file", array('path' => $file_path));
            return new WP_Error('csv_open_failed', 'Failed to open category CSV file');
        }

        // Read and validate headers
        $headers = fgetcsv($handle);
        if (!$this->validate_csv_headers($headers)) {
            fclose($handle);
            return new WP_Error('invalid_csv_format', 'Invalid CSV format: missing required headers');
        }

        $categories = [];
        $line = 2; // Start from line 2 as line 1 is headers

        while (($data = fgetcsv($handle)) !== false) {
            $category = array_combine(self::EXPECTED_CSV_HEADERS, $data);
            
            // Validate required fields
            if (empty($category['Category Name']) || empty($category['Full Path'])) {
                $this->log_error("Invalid category data", array(
                    'line' => $line,
                    'data' => $category
                ));
                continue;
            }

            $categories[] = $category;
            $line++;
        }

        fclose($handle);

        if (empty($categories)) {
            $this->log_error("No valid categories found in CSV");
            return new WP_Error('no_categories', 'No valid categories found in CSV file');
        }

        $this->log_debug("Successfully validated CSV file", array(
            'categories_count' => count($categories)
        ));

        return $categories;
    }

    /**
     * Validate CSV headers
     *
     * @param array $headers Headers from CSV file
     * @return bool Whether headers are valid
     */
    private function validate_csv_headers($headers) {
        if (!is_array($headers)) {
            $this->log_error("Invalid CSV headers", array('headers' => $headers));
            return false;
        }

        $missing_headers = array_diff(self::EXPECTED_CSV_HEADERS, $headers);
        
        if (!empty($missing_headers)) {
            $this->log_error("Missing CSV headers", array('missing' => $missing_headers));
            return false;
        }

        return true;
    }

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
