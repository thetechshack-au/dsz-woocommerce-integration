<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Baserow category operations and caching
 */
class Baserow_Category_Manager {
    /** @var string */
    private $categories_file;
    
    /** @var array */
    private $categories = array();
    
    /** @var array */
    private $category_tree = array();
    
    /** @var bool */
    private $is_initialized = false;

    /**
     * Constructor
     */
    public function __construct() {
        $this->categories_file = plugin_dir_path(dirname(dirname(__FILE__))) . 'data/dsz-categories.csv';
    }

    /**
     * Initialize the category manager
     */
    public function init() {
        if ($this->is_initialized) {
            return;
        }

        $this->load_categories();
        $this->build_category_tree();
        $this->is_initialized = true;
    }

    /**
     * Load categories from CSV file
     */
    private function load_categories() {
        if (!file_exists($this->categories_file)) {
            Baserow_Logger::error("Categories file not found: " . $this->categories_file);
            return;
        }

        $handle = fopen($this->categories_file, "r");
        if ($handle !== false) {
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 5) {
                    $category = array(
                        'id' => $data[0],
                        'name' => $data[1],
                        'parent' => $data[2],
                        'top' => $data[3],
                        'full_path' => $data[4]
                    );
                    
                    $this->categories[$category['id']] = $category;
                }
            }
            fclose($handle);
        }
    }

    /**
     * Build hierarchical category tree
     */
    private function build_category_tree() {
        foreach ($this->categories as $category) {
            if (!isset($this->category_tree[$category['top']])) {
                $this->category_tree[$category['top']] = array();
            }
            
            if (!isset($this->category_tree[$category['top']][$category['parent']])) {
                $this->category_tree[$category['top']][$category['parent']] = array();
            }
            
            $this->category_tree[$category['top']][$category['parent']][] = $category;
        }
    }

    /**
     * Get all categories
     *
     * @return array Array of all categories
     */
    public function get_categories() {
        $this->init();
        return $this->categories;
    }

    /**
     * Get category tree
     *
     * @return array Hierarchical category structure
     */
    public function get_category_tree() {
        $this->init();
        return $this->category_tree;
    }

    /**
     * Get category by ID
     *
     * @param string $id Category ID
     * @return array|null Category data or null if not found
     */
    public function get_category_by_id($id) {
        $this->init();
        return isset($this->categories[$id]) ? $this->categories[$id] : null;
    }

    /**
     * Get category by full path
     *
     * @param string $path Full category path
     * @return array|null Category data or null if not found
     */
    public function get_category_by_path($path) {
        $this->init();
        foreach ($this->categories as $category) {
            if ($category['full_path'] === $path) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Get category by name
     *
     * @param string $name Category name
     * @return array|null Category data or null if not found
     */
    public function get_category_by_name($name) {
        $this->init();
        foreach ($this->categories as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Get child categories
     *
     * @param string $parent_name Parent category name
     * @return array Array of child categories
     */
    public function get_child_categories($parent_name) {
        $this->init();
        $children = array();
        
        foreach ($this->categories as $category) {
            if ($category['parent'] === $parent_name) {
                $children[] = $category;
            }
        }
        
        return $children;
    }

    /**
     * Get parent categories
     *
     * @return array Array of unique parent categories
     */
    public function get_parent_categories() {
        $this->init();
        $parents = array();
        
        foreach ($this->categories as $category) {
            if (!in_array($category['parent'], $parents)) {
                $parents[] = $category['parent'];
            }
        }
        
        sort($parents);
        return $parents;
    }

    /**
     * Validate category path
     *
     * @param string $path Category path to validate
     * @return bool True if valid category path
     */
    public function is_valid_category($path) {
        $this->init();
        foreach ($this->categories as $category) {
            if ($category['full_path'] === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get formatted category path
     *
     * @param string $category_name Category name or path
     * @return string|null Full category path or null if not found
     */
    public function get_formatted_path($category_name) {
        $this->init();
        
        // First try exact match
        foreach ($this->categories as $category) {
            if ($category['full_path'] === $category_name) {
                return $category['full_path'];
            }
        }
        
        // Then try matching by name
        foreach ($this->categories as $category) {
            if ($category['name'] === $category_name) {
                return $category['full_path'];
            }
        }
        
        return null;
    }

    /**
     * Update the categories CSV file
     * 
     * @param string $python_script Path to Python script
     * @param string $api_url Baserow API URL
     * @param string $table_id Baserow table ID
     * @param string $api_token Baserow API token
     * @return bool True if update successful
     */
    public function update_categories($python_script, $api_url, $table_id, $api_token) {
        if (!file_exists($python_script)) {
            Baserow_Logger::error("Python script not found: " . $python_script);
            return false;
        }

        $output = array();
        $return_var = 0;
        
        $command = sprintf(
            'python %s %s %s %s',
            escapeshellarg($python_script),
            escapeshellarg($api_url),
            escapeshellarg($table_id),
            escapeshellarg($api_token)
        );
        
        exec($command, $output, $return_var);
        
        if ($return_var !== 0) {
            Baserow_Logger::error("Failed to update categories: " . implode("\n", $output));
            return false;
        }

        // Reset initialization to reload categories
        $this->is_initialized = false;
        $this->categories = array();
        $this->category_tree = array();
        
        return true;
    }
}
