<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 *
 * v3.1.0
 * - REFACTOR: Removes the `get_live_listing_details_callback` as it is obsolete. All data is now fetched from the local DB via `get_listing_details_callback`.
 * - FEAT: The `get_filter_options_callback` now accepts the current filters to provide context-aware options.
 */
class MLD_BME_AJAX {

    public function __construct() {
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );

        add_action( 'wp_ajax_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );

        add_action( 'wp_ajax_get_filter_options', [ $this, 'get_filter_options_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filter_options', [ $this, 'get_filter_options_callback' ] );

        add_action( 'wp_ajax_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );

        add_action( 'wp_ajax_get_listing_details', [ $this, 'get_listing_details_callback' ] );
        add_action( 'wp_ajax_nopriv_get_listing_details', [ $this, 'get_listing_details_callback' ] );
    }

    /**
     * Fetches full details for a single listing from the local database.
     * Used for the "Quick Details" view on the map.
     */
    public function get_listing_details_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( $_POST['listing_id'] ) : '';
        if ( empty( $listing_id ) ) {
            wp_send_json_error( 'No Listing ID provided.' );
        }
        try {
            $details = MLD_BME_Query::get_listing_details( $listing_id );
            if ( $details ) {
                wp_send_json_success( $details );
            } else {
                wp_send_json_error( 'Listing not found.' );
            }
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching details: ' . $e->getMessage() );
        }
        wp_die();
    }

    public function get_filtered_count_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : null;
        try {
            $count = MLD_BME_Query::get_listings_for_map( 0, 0, 0, 0, $filters, true, true );
            wp_send_json_success( $count );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching count: ' . $e->getMessage() );
        }
        wp_die();
    }

    public function get_filter_options_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : [];
        try {
            $options = MLD_BME_Query::get_distinct_filter_options($filters);
            wp_send_json_success( $options );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching filter options: ' . $e->getMessage() );
        }
        wp_die();
    }

    public function get_map_listings_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : 0;
        $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : 0;
        $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : 0;
        $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : 0;

        $is_new_filter = isset( $_POST['is_new_filter'] ) && $_POST['is_new_filter'] === 'true';

        $filters = null;
        if ( isset( $_POST['filters'] ) && ! empty( $_POST['filters'] ) ) {
            $decoded_filters = json_decode( wp_unslash( $_POST['filters'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_filters ) ) {
                $filters = $decoded_filters;
            }
        }

        try {
            $listings = MLD_BME_Query::get_listings_for_map( $north, $south, $east, $west, $filters, $is_new_filter );
            wp_send_json_success( $listings );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching listings: ' . $e->getMessage() );
        }

        wp_die();
    }

    public function get_autocomplete_suggestions_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $search_term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
        if ( strlen( $search_term ) < 2 ) {
            wp_send_json_success( [] );
            return;
        }
        try {
            $suggestions = MLD_BME_Query::get_autocomplete_suggestions( $search_term );
            wp_send_json_success( $suggestions );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching suggestions: ' . $e->getMessage() );
        }
        wp_die();
    }
}
