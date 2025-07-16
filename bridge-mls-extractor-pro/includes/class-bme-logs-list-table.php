<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Class BME_Logs_List_Table
 *
 * Renders the activity log list table.
 */
class BME_Logs_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Log', 'bridge-mls-extractor'),
            'plural'   => __('Logs', 'bridge-mls-extractor'),
            'ajax'     => false
        ]);
    }

    /**
     * Define the columns for the list table.
     *
     * @return array
     */
    public function get_columns() {
        return [
            'log_title'          => __('Details', 'bridge-mls-extractor'),
            'extraction_profile' => __('Extraction Profile', 'bridge-mls-extractor'),
            'listings_count'     => __('Listings Processed', 'bridge-mls-extractor'),
            'log_date'           => __('Date', 'bridge-mls-extractor'),
        ];
    }

    /**
     * Prepare the items for the table to be displayed.
     */
    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $paged = $this->get_pagenum();
        $per_page = 20;

        $query = new WP_Query([
            'post_type'      => 'bme_log',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ]);

        $this->items = $query->posts;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => $query->max_num_pages
        ]);
    }

    /**
     * Render the content for each column.
     *
     * @param object $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'log_title':
                return $this->render_log_title_column($item);
            case 'extraction_profile':
                $extraction_id = get_post_meta($item->ID, '_bme_log_extraction_id', true);
                if ($extraction_id && get_post($extraction_id)) {
                    return '<a href="' . get_edit_post_link($extraction_id) . '">' . get_the_title($extraction_id) . '</a>';
                }
                return 'N/A';
            case 'listings_count':
                return get_post_meta($item->ID, '_bme_log_listings_count', true);
            case 'log_date':
                return get_the_date('Y-m-d H:i:s', $item);
            default:
                return '';
        }
    }

    /**
     * Renders the main 'Details' column with the expandable section.
     *
     * @param object $item The log post object.
     * @return string HTML content for the column.
     */
    private function render_log_title_column($item) {
        $status = get_post_meta($item->ID, '_bme_log_status', true);
        $color = 'green'; // Default to green for Success
        if ($status === 'Failure') {
            $color = 'red';
        } elseif ($status === 'Completed with errors') {
            $color = '#f59e0b'; // Amber/Orange for partial success
        }

        $html = "<strong><span style='color:{$color}'>" . esc_html($item->post_title) . "</span></strong>";
        $html .= "<div class='row-actions'><span><a href='#' class='bme-view-details'>View Details</a></span></div>";
        
        $details_content = '<p>' . esc_html($item->post_content) . '</p>';
        
        // Get database insertion errors
        $db_errors = get_post_meta($item->ID, '_bme_log_db_errors', true);
        if (!empty($db_errors) && is_array($db_errors)) {
            $details_content .= '<h4>' . __('Database Insertion Errors:', 'bridge-mls-extractor') . '</h4>';
            $details_content .= '<ul style="max-height: 200px; overflow-y: auto; border: 1px solid #fca5a5; padding: 10px; background: #fff1f2;">';
            foreach ($db_errors as $error) {
                $details_content .= '<li>';
                $details_content .= '<strong>' . __('MLS#:', 'bridge-mls-extractor') . '</strong> ' . esc_html($error['mls_number']);
                $details_content .= ' - <strong style="color: #dc2626;">' . __('Error:', 'bridge-mls-extractor') . '</strong> ' . esc_html($error['error']);
                $details_content .= '</li>';
            }
            $details_content .= '</ul>';
        }

        // Get the processed listings info
        $processed_listings = get_post_meta($item->ID, '_bme_log_processed_listings', true);
        if (!empty($processed_listings) && is_array($processed_listings)) {
            $details_content .= '<h4>' . __('Successfully Processed Listings:', 'bridge-mls-extractor') . '</h4>';
            $details_content .= '<ul style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">';
            foreach ($processed_listings as $listing) {
                $details_content .= '<li>';
                $details_content .= '<strong>' . __('MLS#:', 'bridge-mls-extractor') . '</strong> ' . esc_html($listing['mls_number']);
                $details_content .= ' - ' . esc_html($listing['address']);
                $details_content .= '</li>';
            }
            $details_content .= '</ul>';
        }

        $html .= "<div class='bme-log-details' style='display:none; margin-top: 10px; padding: 15px; background: #f9f9f9; border-left: 3px solid #ccc;'>{$details_content}</div>";
        
        return $html;
    }

    /**
     * Add the JavaScript for the 'View Details' toggle.
     */
    public function display() {
        parent::display();
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                $('.bme-view-details').on('click', function(e){
                    e.preventDefault();
                    $(this).closest('td').find('.bme-log-details').slideToggle('fast');
                });
            });
        </script>
        <?php
    }
}
