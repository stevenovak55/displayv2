<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 *
 * v1.4.0
 * - REFACTOR: The `get_map_listings_callback` now passes an `is_new_filter` flag to the backend query, allowing the backend to intelligently decide whether to perform a global "fit-to-bounds" search or a localized viewport search.
 */
class MLD_BME_AJAX {

    public function __construct() {
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );

        add_action( 'wp_ajax_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
    }

    public function get_map_listings_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        // Always get the boundaries, but they might be null if not provided by the client.
        $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : 0;
        $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : 0;
        $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : 0;
        $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : 0;

        // Get the new flag that indicates the user's intent.
        $is_new_filter = isset( $_POST['is_new_filter'] ) && $_POST['is_new_filter'] === 'true';

        // Process filters if they exist.
        $filters = null;
        if ( isset( $_POST['filters'] ) && ! empty( $_POST['filters'] ) ) {
            $decoded_filters = json_decode( wp_unslash( $_POST['filters'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_filters ) ) {
                $filters = $decoded_filters;
            }
        }

        try {
            // Pass all parameters to the query function.
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
