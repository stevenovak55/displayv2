<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 */
class MLD_BME_Query {

    /**
     * Fetches listings for the map view, now including all necessary fields for rich cards.
     */
    public static function get_listings_for_map( $north, $south, $east, $west ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $polygon_wkt = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $west, $north,
            $east, $north,
            $east, $south,
            $west, $south,
            $west, $north
        );

        // Fetch all the fields needed for the rich display cards, now with a limit of 250.
        $sql = $wpdb->prepare(
            "SELECT 
                ListingId, Latitude, Longitude, ListPrice, StandardStatus, PropertyType, 
                StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
                BedroomsTotal, BathroomsTotalInteger, LivingArea, Media
             FROM {$table_name} 
             WHERE ST_Contains(ST_GeomFromText(%s), Coordinates)
             LIMIT 250",
            $polygon_wkt
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Fetches a standard list of properties with all necessary fields.
     */
    public static function get_listings( $args = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $defaults = [
            'status'     => 'Active',
            'city'       => '',
            'price_min'  => 0,
            'price_max'  => 0,
            'limit'      => 24,
            'offset'     => 0,
            'orderby'    => 'ModificationTimestamp',
            'order'      => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where_clauses = [];
        
        if ( ! empty( $args['status'] ) ) {
            $where_clauses[] = $wpdb->prepare( "StandardStatus = %s", $args['status'] );
        }

        $sql = "SELECT 
                    ListingId, ListPrice, StandardStatus, PropertyType, 
                    StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
                    BedroomsTotal, BathroomsTotalInteger, LivingArea, Media
                FROM {$table_name}";

        if ( ! empty( $where_clauses ) ) {
            $sql .= " WHERE " . implode( ' AND ', $where_clauses );
        }

        $sql .= $wpdb->prepare( " ORDER BY %s %s", esc_sql($args['orderby']), esc_sql($args['order']) );
        $sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );

        return $wpdb->get_results( $sql );
    }
}
