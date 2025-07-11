<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Class BME_Listings_List_Table
 *
 * Renders the searchable and sortable table for browsing listings.
 */
class BME_Listings_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Listing', 'bridge-mls-extractor'),
            'plural'   => __('Listings', 'bridge-mls-extractor'),
            'ajax'     => false
        ]);
    }

    /**
     * Get the list of columns.
     */
    public function get_columns() {
        return [
            'CreationTimestamp'     => __('Created (EST)', 'bridge-mls-extractor'),
            'ModificationTimestamp' => __('Modified (EST)', 'bridge-mls-extractor'),
            'ListingId'             => __('MLS #', 'bridge-mls-extractor'),
            'address'               => __('Address', 'bridge-mls-extractor'),
            'StandardStatus'        => __('Status', 'bridge-mls-extractor'),
            'ListPrice'             => __('Price', 'bridge-mls-extractor'),
            'BedroomsTotal'         => __('Beds', 'bridge-mls-extractor'),
            'BathroomsTotalInteger' => __('Baths', 'bridge-mls-extractor'),
            'LivingArea'            => __('Sq. Ft.', 'bridge-mls-extractor'),
            'YearBuilt'             => __('Year Built', 'bridge-mls-extractor'),
            'PropertyType'          => __('Type', 'bridge-mls-extractor'),
            'PropertySubType'       => __('Sub Type', 'bridge-mls-extractor'),
        ];
    }

    /**
     * Get the sortable columns.
     */
    public function get_sortable_columns() {
        return [
            'CreationTimestamp'     => ['CreationTimestamp', false],
            'ModificationTimestamp' => ['ModificationTimestamp', false],
            'ListingId'             => ['ListingId', false],
            'StandardStatus'        => ['StandardStatus', false],
            'ListPrice'             => ['ListPrice', false],
            'YearBuilt'             => ['YearBuilt', false],
            'PropertyType'          => ['PropertyType', false],
            'PropertySubType'       => ['PropertySubType', false],
        ];
    }

    /**
     * Prepare the items for the table to be displayed.
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $per_page = 30;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Build WHERE clause from individual filter fields
        $where_clauses = [];
        $filter_fields = [
            // OPTIMIZATION: Changed ListingId from 'like' to 'exact' for performance.
            'ListingId'       => 'exact',
            'StandardStatus'  => 'exact',
            'City'            => 'exact',
            'YearBuilt'       => 'exact',
            'BuildingName'    => 'exact',
            'PropertyType'    => 'exact',
            'PropertySubType' => 'exact',
            'PostalCode'      => 'exact',
            'StreetName'      => 'exact',
            'MLSAreaMajor'    => 'exact',
            'MLSAreaMinor'    => 'exact',
            'StructureType'   => 'exact',
            'BuyerAgentMlsId' => 'like',
            'ListOfficeMlsId' => 'like',
            'BuyerOfficeMlsId'=> 'like',
        ];

        foreach ($filter_fields as $field => $match_type) {
            $param_name = 'filter_' . $field;
            if (!empty($_REQUEST[$param_name])) {
                $value = sanitize_text_field($_REQUEST[$param_name]);
                if ($match_type === 'exact') {
                    $where_clauses[] = $wpdb->prepare("`$field` = %s", $value);
                } else {
                    $where_clauses[] = $wpdb->prepare("`$field` LIKE %s", '%' . $wpdb->esc_like($value) . '%');
                }
            }
        }

        $sql_where = '';
        if (!empty($where_clauses)) {
            $sql_where = ' WHERE ' . implode(' AND ', $where_clauses);
        }

        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM `$table_name` $sql_where");

        $paged = $this->get_pagenum();
        $orderby = !empty($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns())) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'ModificationTimestamp';
        $order = !empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC']) ? strtoupper($_REQUEST['order']) : 'DESC';

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `$table_name` $sql_where ORDER BY `$orderby` $order LIMIT %d OFFSET %d",
                $per_page,
                ($paged - 1) * $per_page
            ),
            ARRAY_A
        );

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Default column rendering.
     */
    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }
    
    /**
     * Formats a UTC datetime string to America/New_York time.
     */
    private function format_est_time($datetime_string) {
        if (empty($datetime_string)) {
            return '';
        }
        try {
            $utc_date = new DateTime($datetime_string, new DateTimeZone('UTC'));
            $est_zone = new DateTimeZone('America/New_York');
            $utc_date->setTimezone($est_zone);
            return $utc_date->format('Y-m-d g:i A');
        } catch (Exception $e) {
            return $datetime_string; // Return original string on error
        }
    }

    /**
     * Render the CreationTimestamp column with a safety check.
     */
    public function column_CreationTimestamp($item) {
        return isset($item['CreationTimestamp']) ? $this->format_est_time($item['CreationTimestamp']) : '';
    }

    /**
     * Render the ModificationTimestamp column with a safety check.
     */
    public function column_ModificationTimestamp($item) {
        return isset($item['ModificationTimestamp']) ? $this->format_est_time($item['ModificationTimestamp']) : '';
    }

    /**
     * Render the address column with a safety check.
     */
    public function column_address($item) {
        $address_parts = [
            $item['StreetNumber'] ?? '',
            $item['StreetName'] ?? '',
            $item['City'] ?? '',
            $item['StateOrProvince'] ?? '',
            $item['PostalCode'] ?? '',
        ];
        return esc_html(implode(' ', array_filter($address_parts)));
    }

    /**
     * Render the price column with a safety check.
     */
    public function column_ListPrice($item) {
        if (!isset($item['ListPrice'])) {
            return '';
        }
        $price = (float) $item['ListPrice'];
        return '$' . number_format($price);
    }
    
    /**
     * Render the Sq. Ft. column with a safety check.
     */
    public function column_LivingArea($item) {
        if (!isset($item['LivingArea'])) {
            return '';
        }
        $area = (float) $item['LivingArea'];
        return number_format($area);
    }

    /**
     * Message to display when no listings are found.
     */
    public function no_items() {
        _e('No listings found.', 'bridge-mls-extractor');
    }
}
