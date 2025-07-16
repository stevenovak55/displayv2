<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Advanced listings list table with enhanced filtering and performance
 */
class BME_Advanced_Listings_List_Table extends WP_List_Table {
    
    private $plugin;
    private $data_processor;
    
    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->data_processor = $plugin->get('processor');
        
        parent::__construct([
            'singular' => __('Listing', 'bridge-mls-extractor-pro'),
            'plural'   => __('Listings', 'bridge-mls-extractor-pro'),
            'ajax'     => true
        ]);
    }
    
    /**
     * Get list of columns
     */
    public function get_columns() {
        return [
            'listing_id' => __('MLS #', 'bridge-mls-extractor-pro'),
            'address' => __('Address', 'bridge-mls-extractor-pro'),
            'standard_status' => __('Status', 'bridge-mls-extractor-pro'),
            'property_type' => __('Type', 'bridge-mls-extractor-pro'),
            'list_price' => __('Price', 'bridge-mls-extractor-pro'),
            'bedrooms_total' => __('Beds', 'bridge-mls-extractor-pro'),
            'bathrooms_total_integer' => __('Baths', 'bridge-mls-extractor-pro'),
            'living_area' => __('Sq Ft', 'bridge-mls-extractor-pro'),
            'year_built' => __('Year', 'bridge-mls-extractor-pro'),
            'modification_timestamp' => __('Updated', 'bridge-mls-extractor-pro')
        ];
    }
    
    /**
     * Get sortable columns
     */
    public function get_sortable_columns() {
        return [
            'listing_id' => ['listing_id', false],
            'standard_status' => ['standard_status', false],
            'property_type' => ['property_type', false],
            'list_price' => ['list_price', true],
            'bedrooms_total' => ['bedrooms_total', false],
            'bathrooms_total_integer' => ['bathrooms_total_integer', false],
            'living_area' => ['living_area', true],
            'year_built' => ['year_built', true],
            'modification_timestamp' => ['modification_timestamp', true]
        ];
    }
    
    /**
     * Prepare items for display
     */
    public function prepare_items() {
        $per_page = 25;
        $current_page = $this->get_pagenum();
        
        // Get filters from request
        $filters = $this->get_filters_from_request();
        
        // Get data from processor
        $total_items = $this->data_processor->get_search_count($filters);
        $offset = ($current_page - 1) * $per_page;
        $this->items = $this->data_processor->search_listings($filters, $per_page, $offset);
        
        // Set pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
        
        // Set column headers
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns()
        ];
    }
    
    /**
     * Get filters from request
     */
    private function get_filters_from_request() {
        $filters = [];
        
        $filter_fields = [
            'standard_status',
            'property_type', 
            'city',
            'listing_id',
            'price_min',
            'price_max',
            'bedrooms_min',
            'bathrooms_min',
            'year_built_min',
            'year_built_max'
        ];
        
        foreach ($filter_fields as $field) {
            $value = $_REQUEST['filter_' . $field] ?? '';
            if (!empty($value)) {
                $filters[$field] = sanitize_text_field($value);
            }
        }
        
        return $filters;
    }
    
    /**
     * Default column display
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'listing_id':
                return '<strong>' . esc_html($item[$column_name] ?? '') . '</strong>';
                
            case 'address':
                $parts = array_filter([
                    $item['street_number'] ?? '',
                    $item['street_name'] ?? '',
                    $item['city'] ?? '',
                    $item['state_or_province'] ?? ''
                ]);
                return esc_html(implode(' ', $parts));
                
            case 'standard_status':
                $status = $item[$column_name] ?? '';
                $class = $this->get_status_class($status);
                return "<span class='bme-status {$class}'>" . esc_html($status) . "</span>";
                
            case 'list_price':
                $price = floatval($item[$column_name] ?? 0);
                return $price > 0 ? '$' . number_format($price) : '—';
                
            case 'living_area':
                $area = floatval($item[$column_name] ?? 0);
                return $area > 0 ? number_format($area) : '—';
                
            case 'modification_timestamp':
                $timestamp = $item[$column_name] ?? '';
                if ($timestamp) {
                    return '<abbr title="' . esc_attr($timestamp) . '">' . 
                           esc_html(human_time_diff(strtotime($timestamp))) . ' ago</abbr>';
                }
                return '—';
                
            default:
                return esc_html($item[$column_name] ?? '—');
        }
    }
    
    /**
     * Get CSS class for status
     */
    private function get_status_class($status) {
        switch (strtolower($status)) {
            case 'active':
                return 'status-active';
            case 'pending':
            case 'active under contract':
                return 'status-pending';
            case 'closed':
                return 'status-closed';
            case 'expired':
            case 'withdrawn':
            case 'canceled':
                return 'status-inactive';
            default:
                return 'status-unknown';
        }
    }
    
    /**
     * Display when no items found
     */
    public function no_items() {
        _e('No listings found matching your criteria.', 'bridge-mls-extractor-pro');
    }
    
    /**
     * Generate bulk actions dropdown
     */
    public function get_bulk_actions() {
        return [
            'export_csv' => __('Export to CSV', 'bridge-mls-extractor-pro')
        ];
    }
    
    /**
     * Add search box
     */
    public function search_box($text = '', $input_id = '') {
        $input_id = 'bme-listing-search';
        $search_query = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">
                <?php echo esc_html($text); ?>:
            </label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" 
                   value="<?php echo esc_attr($search_query); ?>" 
                   placeholder="<?php esc_attr_e('Search listings...', 'bridge-mls-extractor-pro'); ?>">
            <?php submit_button(__('Search', 'bridge-mls-extractor-pro'), '', '', false, ['id' => 'search-submit']); ?>
        </p>
        <?php
    }
}