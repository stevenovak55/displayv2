<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 *
 * @package MLS_Listings_Display
 */
class MLD_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );

        add_action( 'wp_ajax_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );

        add_action( 'wp_ajax_get_filter_options', [ $this, 'get_filter_options_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filter_options', [ $this, 'get_filter_options_callback' ] );

        add_action( 'wp_ajax_get_price_distribution', [ $this, 'get_price_distribution_callback' ] );
        add_action( 'wp_ajax_nopriv_get_price_distribution', [ $this, 'get_price_distribution_callback' ] );

        add_action( 'wp_ajax_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );

        add_action( 'wp_ajax_get_listing_details', [ $this, 'get_listing_details_callback' ] );
        add_action( 'wp_ajax_nopriv_get_listing_details', [ $this, 'get_listing_details_callback' ] );

        add_action( 'wp_ajax_get_all_listings_for_cache', [ $this, 'get_all_listings_for_cache_callback' ] );
        add_action( 'wp_ajax_nopriv_get_all_listings_for_cache', [ $this, 'get_all_listings_for_cache_callback' ] );
    }

    public function get_all_listings_for_cache_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : null;
        if (!is_array($filters)) {
            $filters = null;
        }
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 500;

        try {
            $result = MLD_Query::get_all_listings_for_cache($filters, $page, $limit);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error('An error occurred while fetching all listings: ' . $e->getMessage());
        }
    }

    public function get_price_distribution_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
        if ( ! is_array( $filters ) ) {
            $filters = [];
        }
        try {
            $distribution = MLD_Query::get_price_distribution( $filters );
            wp_send_json_success( $distribution );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching price distribution: ' . $e->getMessage() );
        }
    }

    public function get_listing_details_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( wp_unslash( $_POST['listing_id'] ) ) : '';
        if ( empty( $listing_id ) ) {
            wp_send_json_error( 'No Listing ID provided.' );
        }
        try {
            $details = MLD_Query::get_listing_details( $listing_id );
            if ( $details ) {
                wp_send_json_success( $details );
            } else {
                wp_send_json_error( 'Listing not found.' );
            }
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching details: ' . $e->getMessage() );
        }
    }

    public function get_filtered_count_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : null;
        if ( ! is_array( $filters ) ) {
            $filters = null;
        }
        try {
            $count = MLD_Query::get_listings_for_map( 0, 0, 0, 0, $filters, true, true, false );
            wp_send_json_success( $count );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching count: ' . $e->getMessage() );
        }
    }

    public function get_filter_options_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : [];
        if ( ! is_array( $filters ) ) {
            $filters = [];
        }
        try {
            $options = MLD_Query::get_distinct_filter_options($filters);
            wp_send_json_success( $options );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching filter options: ' . $e->getMessage() );
        }
    }

    public function get_map_listings_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : 0;
        $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : 0;
        $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : 0;
        $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : 0;

        $is_new_filter = isset( $_POST['is_new_filter'] ) && $_POST['is_new_filter'] === 'true';
        $is_initial_load = isset( $_POST['is_initial_load'] ) && $_POST['is_initial_load'] === 'true';
        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : null;
        if (!is_array($filters)) {
            $filters = null;
        }

        try {
            $data = MLD_Query::get_listings_for_map( $north, $south, $east, $west, $filters, $is_new_filter, false, $is_initial_load );
            wp_send_json_success( $data );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching listings: ' . $e->getMessage() );
        }
    }

    public function get_autocomplete_suggestions_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $search_term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( strlen( $search_term ) < 2 ) {
            wp_send_json_success( [] );
            return;
        }
        try {
            $suggestions = MLD_Query::get_autocomplete_suggestions( $search_term );
            wp_send_json_success( $suggestions );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching suggestions: ' . $e->getMessage() );
        }
    }
}
