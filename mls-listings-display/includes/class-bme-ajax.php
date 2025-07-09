<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 *
 * v1.2.0
 * - Adds a new endpoint for autocomplete suggestions.
 * - Updates get_map_listings_callback to handle incoming filters.
 */
class MLD_BME_AJAX {

    /**
     * Constructor to hook into WordPress AJAX actions.
     */
    public function __construct() {
        // Existing action for fetching listings based on map view
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );

        // Action for handling autocomplete search suggestions
        add_action( 'wp_ajax_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
    }

    /**
     * The callback function that handles the AJAX request for map listings.
     *
     * This now checks for a 'filters' parameter. If present, it uses the
     * filter-based search. Otherwise, it falls back to the map boundary search.
     */
    public function get_map_listings_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        // --- NEW: Check for keyword search filters ---
        if ( isset( $_POST['filters'] ) && ! empty( $_POST['filters'] ) ) {
            // The 'filters' are expected to be a JSON string, so we decode it.
            // wp_unslash is used to handle potential backslashes added by WordPress.
            $filters = json_decode( wp_unslash( $_POST['filters'] ), true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $filters ) ) {
                try {
                    // Call the query function with the filters
                    $listings = MLD_BME_Query::get_listings_for_map( null, null, null, null, $filters );
                    wp_send_json_success( $listings );
                } catch ( Exception $e ) {
                    wp_send_json_error( 'An error occurred while fetching filtered listings: ' . $e->getMessage() );
                }
            } else {
                wp_send_json_error( 'Invalid filters format provided.' );
            }

        } else {
            // --- Fallback to original map boundary search ---
            $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : 0;
            $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : 0;
            $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : 0;
            $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : 0;

            if ( ! $north || ! $south || ! $east || ! $west ) {
                wp_send_json_error( 'Invalid map boundaries provided.' );
            }

            try {
                $listings = MLD_BME_Query::get_listings_for_map( $north, $south, $east, $west );
                wp_send_json_success( $listings );
            } catch ( Exception $e ) {
                wp_send_json_error( 'An error occurred while fetching listings: ' . $e->getMessage() );
            }
        }

        wp_die();
    }

    /**
     * Callback for autocomplete suggestions.
     */
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
