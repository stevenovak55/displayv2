<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Advanced listings list table with enhanced filtering and performance
 * FIXED: Sorting functionality and proper filter handling
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
            'ajax'     => false // Disable AJAX for now to fix sorting
        ]);
    }
    
    /**
     * Get list of columns
     */
    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />', // Bulk selection checkbox
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
            'list_price' => ['list_price', true], // Default descending for price
            'bedrooms_total' => ['bedrooms_total', false],
            'bathrooms_total_integer' => ['bathrooms_total_integer', false],
            'living_area' => ['living_area', true],
            'year_built' => ['year_built', true],
            'modification_timestamp' => ['modification_timestamp', true]
        ];
    }
    
    /**
     * Prepare items for display with proper sorting
     */
    public function prepare_items() {
        $per_page = 25;
        $current_page = $this->get_pagenum();
        
        // Process bulk actions first
        $this->process_bulk_action();
        
        // Get filters from request
        $filters = $this->get_filters_from_request();
        
        // Get sorting parameters
        $orderby = !empty($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'modification_timestamp';
        $order = !empty($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'asc' : 'desc';
        
        // Add sorting to filters for the data processor
        $filters['orderby'] = $orderby;
        $filters['order'] = $order;
        
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
        
        // Set column headers with sorting
        $this->_column_headers = [
            $this->get_columns(),
            [], // Hidden columns
            $this->get_sortable_columns(),
            $orderby // Primary column
        ];
    }
    
    /**
     * Get filters from request with validation
     */
    private function get_filters_from_request() {
        $filters = [];
        
        $filter_fields = [
            'standard_status' => 'text',
            'property_type' => 'text', 
            'city' => 'text',
            'listing_id' => 'text',
            'price_min' => 'number',
            'price_max' => 'number',
            'bedrooms_min' => 'number',
            'bathrooms_min' => 'number',
            'year_built_min' => 'number',
            'year_built_max' => 'number'
        ];
        
        foreach ($filter_fields as $field => $type) {
            $value = $_REQUEST['filter_' . $field] ?? '';
            if (!empty($value)) {
                if ($type === 'number') {
                    $filters[$field] = absint($value);
                } else {
                    $filters[$field] = sanitize_text_field($value);
                }
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
            'export_csv' => __('Export Selected to CSV', 'bridge-mls-extractor-pro'),
            'export_all_csv' => __('Export All Results to CSV', 'bridge-mls-extractor-pro')
        ];
    }
    
    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        $action = $this->current_action();
        
        if (!$action) {
            return;
        }
        
        // Verify nonce for security
        if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
            wp_die(__('Security check failed.', 'bridge-mls-extractor-pro'));
        }
        
        switch ($action) {
            case 'export_csv':
                $this->export_selected_to_csv();
                break;
            case 'export_all_csv':
                $this->export_all_to_csv();
                break;
        }
    }
    
    /**
     * Export selected listings to CSV
     */
    private function export_selected_to_csv() {
        $listing_ids = $_REQUEST['listing'] ?? [];
        
        if (empty($listing_ids)) {
            wp_die(__('No listings selected for export.', 'bridge-mls-extractor-pro'));
        }
        
        // Validate listing IDs
        $listing_ids = array_map('absint', $listing_ids);
        $listing_ids = array_filter($listing_ids);
        
        if (empty($listing_ids)) {
            wp_die(__('Invalid listing selection.', 'bridge-mls-extractor-pro'));
        }
        
        $this->generate_csv_export($listing_ids, 'selected');
    }
    
    /**
     * Export all filtered results to CSV
     */
    private function export_all_to_csv() {
        $filters = $this->get_filters_from_request();
        $this->generate_csv_export(null, 'all', $filters);
    }
    
    /**
     * Generate and download CSV file
     */
    private function generate_csv_export($listing_ids = null, $type = 'selected', $filters = []) {
        global $wpdb;
        
        // Get comprehensive listing data
        $tables = $this->plugin->get('db')->get_tables();
        
        // Build query based on export type
        if ($type === 'selected' && !empty($listing_ids)) {
            $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
            $where_clause = "WHERE l.id IN ({$placeholders})";
            $params = $listing_ids;
        } else {
            // Use the same filtering logic as the list table
            $where_conditions = [];
            $params = [];
            
            foreach ($filters as $field => $value) {
                if (empty($value) || in_array($field, ['orderby', 'order'])) continue;
                
                switch ($field) {
                    case 'standard_status':
                        $where_conditions[] = "l.standard_status = %s";
                        $params[] = sanitize_text_field($value);
                        break;
                    case 'property_type':
                        $where_conditions[] = "l.property_type = %s";
                        $params[] = sanitize_text_field($value);
                        break;
                    case 'city':
                        $where_conditions[] = "ll.city = %s";
                        $params[] = sanitize_text_field($value);
                        break;
                    case 'listing_id':
                        $where_conditions[] = "l.listing_id = %s";
                        $params[] = sanitize_text_field($value);
                        break;
                    case 'price_min':
                        $price = floatval($value);
                        if ($price > 0) {
                            $where_conditions[] = "l.list_price >= %f";
                            $params[] = $price;
                        }
                        break;
                    case 'price_max':
                        $price = floatval($value);
                        if ($price > 0) {
                            $where_conditions[] = "l.list_price <= %f";
                            $params[] = $price;
                        }
                        break;
                    case 'bedrooms_min':
                        $bedrooms = absint($value);
                        if ($bedrooms > 0) {
                            $where_conditions[] = "l.bedrooms_total >= %d";
                            $params[] = $bedrooms;
                        }
                        break;
                    case 'bathrooms_min':
                        $bathrooms = absint($value);
                        if ($bathrooms > 0) {
                            $where_conditions[] = "l.bathrooms_total_integer >= %d";
                            $params[] = $bathrooms;
                        }
                        break;
                }
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        }
        
        // Comprehensive SQL query to get all listing data
        $sql = "
            SELECT 
                l.listing_id,
                l.listing_key,
                l.standard_status,
                l.mls_status,
                l.property_type,
                l.property_sub_type,
                l.list_price,
                l.original_list_price,
                l.close_price,
                l.creation_timestamp,
                l.modification_timestamp,
                l.status_change_timestamp,
                l.close_date,
                l.listing_contract_date,
                l.bedrooms_total,
                l.bathrooms_total_integer,
                l.living_area,
                l.year_built,
                l.list_agent_mls_id,
                l.buyer_agent_mls_id,
                l.list_office_mls_id,
                l.buyer_office_mls_id,
                l.photos_count,
                l.open_house_yn,
                
                -- Location data
                ll.unparsed_address,
                ll.street_number,
                ll.street_name,
                ll.city,
                ll.state_or_province,
                ll.postal_code,
                ll.county_or_parish,
                ll.country,
                ll.latitude,
                ll.longitude,
                ll.mls_area_major,
                ll.mls_area_minor,
                ll.subdivision_name,
                ll.elementary_school,
                ll.middle_or_junior_school,
                ll.high_school,
                ll.school_district,
                
                -- Property details
                ld.bathrooms_full,
                ld.bathrooms_half,
                ld.above_grade_finished_area,
                ld.below_grade_finished_area,
                ld.building_area_total,
                ld.lot_size_acres,
                ld.lot_size_square_feet,
                ld.stories_total,
                ld.rooms_total,
                ld.structure_type,
                ld.architectural_style,
                ld.building_name,
                ld.fireplace_yn,
                ld.fireplaces_total,
                ld.garage_yn,
                ld.garage_spaces,
                ld.parking_total,
                ld.public_remarks,
                ld.showing_instructions,
                
                -- Financial data
                lf.tax_annual_amount,
                lf.tax_year,
                lf.tax_assessed_value,
                lf.association_yn,
                lf.association_name,
                lf.association_fee,
                lf.association_fee_frequency,
                
                -- Features
                lft.waterfront_yn,
                lft.pool_private_yn,
                lft.view_yn,
                lft.virtual_tour_url_unbranded,
                lft.virtual_tour_url_branded
                
            FROM {$tables['listings']} l
            LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id
            LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id
            LEFT JOIN {$tables['listing_financial']} lf ON l.id = lf.listing_id
            LEFT JOIN {$tables['listing_features']} lft ON l.id = lft.listing_id
            {$where_clause}
            ORDER BY l.modification_timestamp DESC
            LIMIT 10000
        ";
        
        // Execute query
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        } else {
            $results = $wpdb->get_results($sql, ARRAY_A);
        }
        
        if (empty($results)) {
            wp_die(__('No listings found to export.', 'bridge-mls-extractor-pro'));
        }
        
        // Generate filename
        $filename = 'mls-listings-' . date('Y-m-d-H-i-s') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create file handle
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8 Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        
        // Add CSV headers
        if (!empty($results)) {
            $headers = array_keys($results[0]);
            
            // Clean up header names for better readability
            $clean_headers = array_map(function($header) {
                return ucwords(str_replace('_', ' ', $header));
            }, $headers);
            
            fputcsv($output, $clean_headers);
        }
        
        // Add data rows
        foreach ($results as $row) {
            // Clean up data for CSV
            $clean_row = array_map(function($value) {
                if (is_null($value)) {
                    return '';
                }
                // Remove any potential CSV-breaking characters
                return str_replace(["\r", "\n"], ' ', (string)$value);
            }, $row);
            
            fputcsv($output, $clean_row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Checkbox column for bulk selection
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="listing[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * Override extra_tablenav to add search box
     */
    protected function extra_tablenav($which) {
        if ($which === 'top') {
            ?>
            <div class="alignleft actions">
                <?php $this->search_box(__('Search listings', 'bridge-mls-extractor-pro'), 'bme-listing'); ?>
            </div>
            <?php
        }
    }
    
    /**
     * Add search box with proper handling
     */
    public function search_box($text, $input_id) {
        $input_id = 'bme-listing-search';
        $search_query = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        
        if (strlen($search_query) == 0 && !$this->has_items()) {
            return;
        }
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">
                <?php echo esc_html($text); ?>:
            </label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" 
                   value="<?php echo esc_attr($search_query); ?>" 
                   placeholder="<?php esc_attr_e('Search MLS ID, address...', 'bridge-mls-extractor-pro'); ?>">
            <?php submit_button(__('Search', 'bridge-mls-extractor-pro'), '', '', false, ['id' => 'search-submit']); ?>
        </p>
        <?php
    }
    
    /**
     * Handle search functionality
     */
    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'bme-listings-table';
        return $classes;
    }
    
    /**
     * Override pagination to preserve filters and sorting
     */
    protected function pagination($which) {
        if (empty($this->_pagination_args)) {
            return;
        }

        $total_items = $this->_pagination_args['total_items'];
        $total_pages = $this->_pagination_args['total_pages'];
        $infinite_scroll = false;

        if (isset($this->_pagination_args['infinite_scroll'])) {
            $infinite_scroll = $this->_pagination_args['infinite_scroll'];
        }

        if ('top' === $which && $total_pages > 1) {
            $this->screen->render_screen_reader_content('heading_pagination');
        }

        $output = '<span class="displaying-num">' . sprintf(
            /* translators: %s: Number of items. */
            _n('%s item', '%s items', $total_items),
            number_format_i18n($total_items)
        ) . '</span>';

        $current = $this->get_pagenum();

        $removable_query_args = wp_removable_query_args();

        $current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        $current_url = remove_query_arg($removable_query_args, $current_url);

        $page_links = [];

        $total_pages_before = '<span class="paging-input">';
        $total_pages_after  = '</span></span>';

        $disable_first = false;
        $disable_last  = false;
        $disable_prev  = false;
        $disable_next  = false;

        if (1 == $current) {
            $disable_first = true;
            $disable_prev  = true;
        }
        if ($total_pages == $current) {
            $disable_last = true;
            $disable_next = true;
        }

        if ($disable_first) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='first-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(remove_query_arg('paged', $current_url)),
                __('First page'),
                '&laquo;'
            );
        }

        if ($disable_prev) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged', max(1, $current - 1), $current_url)),
                __('Previous page'),
                '&lsaquo;'
            );
        }

        $html_current_page = sprintf(
            "%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
            '<label for="current-page-selector" class="screen-reader-text">' . __('Current Page') . '</label>',
            $current,
            strlen($total_pages)
        );

        $html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
        $page_links[]     = $total_pages_before . sprintf(
            /* translators: 1: Current page, 2: Total pages. */
            _x('%1$s of %2$s', 'paging'),
            $html_current_page,
            $html_total_pages
        ) . $total_pages_after;

        if ($disable_next) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged', min($total_pages, $current + 1), $current_url)),
                __('Next page'),
                '&rsaquo;'
            );
        }

        if ($disable_last) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_url(add_query_arg('paged', $total_pages, $current_url)),
                __('Last page'),
                '&raquo;'
            );
        }

        $pagination_links_class = 'pagination-links';
        if (!empty($infinite_scroll)) {
            $pagination_links_class .= ' hide-if-js';
        }
        $output .= "\n<span class='$pagination_links_class'>" . implode("\n", $page_links) . '</span>';

        if ($total_pages) {
            $page_class = $total_pages < 2 ? ' one-page' : '';
        } else {
            $page_class = ' no-pages';
        }
        $this->_pagination = "<div class='tablenav-pages{$page_class}'>$output</div>";

        echo $this->_pagination;
    }
}