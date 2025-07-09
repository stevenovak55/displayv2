<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 *
 * Class name is changed to MLD_BME_AJAX to prevent conflicts.
 */
class MLD_BME_AJAX {

    /**
     * Constructor to hook into WordPress AJAX actions.
     */
    public function __construct() {
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );
    }

    /**
     * The callback function that handles the AJAX request for map listings.
     */
    public function get_map_listings_callback() {
        check_ajax_referer( 'bme_map_nonce', 'security' );

        $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : 0;
        $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : 0;
        $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : 0;
        $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : 0;

        if ( ! $north || ! $south || ! $east || ! $west ) {
            wp_send_json_error( 'Invalid map boundaries provided.' );
        }

        try {
            // Call the renamed query class: MLD_BME_Query
            $listings = MLD_BME_Query::get_listings_for_map( $north, $south, $east, $west );
            wp_send_json_success( $listings );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching listings: ' . $e->getMessage() );
        }

        wp_die();
    }
}
