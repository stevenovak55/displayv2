<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v1.2.0
 * - Adds get_autocomplete_suggestions for the keyword search feature.
 * - Overhauls get_listings_for_map to support complex AND/OR filtering.
 */
class MLD_BME_Query {

    /**
     * Fetches listings for the map.
     *
     * This function now has two modes:
     * 1. Boundary Mode: If no $filters are provided, it fetches listings within the map's geographic boundaries.
     * 2. Filter Mode: If $filters are provided, it ignores boundaries and builds a complex WHERE clause
     * to fetch all matching listings for the "fit to bounds" feature.
     *
     * @param float|null $north The northern latitude of the map boundary.
     * @param float|null $south The southern latitude of the map boundary.
     * @param float|null $east  The eastern longitude of the map boundary.
     * @param float|null $west  The western longitude of the map boundary.
     * @param array|null $filters An associative array of filters, e.g., ['City' => ['Boston', 'Cambridge']].
     * @return array The list of matching listing objects.
     */
    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        // Define the columns to be selected for every query.
        $select_clause = "SELECT 
            ListingId, Latitude, Longitude, ListPrice, StandardStatus, PropertyType, 
            StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
            BedroomsTotal, BathroomsTotalInteger, LivingArea, Media
            FROM {$table_name}";

        // --- FILTER MODE ---
        // If filters are provided and not empty, build the dynamic WHERE clause.
        if ( ! empty( $filters ) && is_array( $filters ) ) {
            
            $where_groups = []; // Each element will be a string like "(City = 'Boston' OR City = 'Cambridge')"
            
            // A map to securely translate filter types from the frontend to database columns.
            $filter_to_column_map = [
                'City'           => 'City',
                'Building Name'  => 'BuildingName',
                'MLS Area Major' => 'MLSAreaMajor',
                'MLS Area Minor' => 'MLSAreaMinor',
                'Postal Code'    => 'PostalCode',
                'Street Name'    => 'StreetName',
                'MLS Number'     => 'ListingId',
                'Address'        => "CONCAT_WS(' ', StreetNumber, StreetName, ',', City)",
            ];

            foreach ( $filters as $type => $values ) {
                if ( ! isset( $filter_to_column_map[$type] ) || empty( $values ) ) {
                    continue; // Skip unknown or empty filters
                }

                $column = $filter_to_column_map[$type];
                $or_conditions = [];

                foreach ( $values as $value ) {
                    // Prepare a single condition, e.g., "City = 'Boston'"
                    $or_conditions[] = $wpdb->prepare( "{$column} = %s", $value );
                }

                if ( ! empty( $or_conditions ) ) {
                    // Join the conditions for the same type with OR, e.g., "(City = 'Boston' OR City = 'Cambridge')"
                    $where_groups[] = '( ' . implode( ' OR ', $or_conditions ) . ' )';
                }
            }

            if ( ! empty( $where_groups ) ) {
                // Join the different filter groups with AND
                $sql = $select_clause . ' WHERE ' . implode( ' AND ', $where_groups );
                // No LIMIT is applied in filter mode, as the frontend needs all results to calculate the bounds.
                return $wpdb->get_results( $sql );
            }
        }

        // --- BOUNDARY MODE (Fallback/Default) ---
        // If no filters were processed, use the original map boundary logic.
        $polygon_wkt = sprintf(
            'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
            $west, $north,
            $east, $north,
            $east, $south,
            $west, $south,
            $west, $north
        );

        $sql = $wpdb->prepare(
            "{$select_clause} WHERE ST_Contains(ST_GeomFromText(%s), Coordinates) LIMIT 250",
            $polygon_wkt
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Fetches autocomplete suggestions from multiple fields.
     */
    public static function get_autocomplete_suggestions( $term ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $term_like = '%' . $wpdb->esc_like( $term ) . '%';

        $fields_to_search = [
            'City'          => 'City',
            'BuildingName'  => 'Building Name',
            'MLSAreaMajor'  => 'MLS Area Major',
            'MLSAreaMinor'  => 'MLS Area Minor',
            'PostalCode'    => 'Postal Code',
            'StreetName'    => 'Street Name',
            'ListingId'     => 'MLS Number',
        ];

        $sql_parts = [];

        foreach ( $fields_to_search as $field_name => $type_label ) {
            $sql_parts[] = $wpdb->prepare(
                "(SELECT DISTINCT %s AS type, `$field_name` AS value FROM `$table_name` WHERE `$field_name` LIKE %s)",
                $type_label,
                $term_like
            );
        }

        $sql_parts[] = $wpdb->prepare(
            "(SELECT 'Address' AS type, CONCAT_WS(' ', StreetNumber, StreetName, ',', City) AS value 
             FROM `$table_name` 
             WHERE CONCAT_WS(' ', StreetNumber, StreetName, City) LIKE %s)",
            $term_like
        );

        $full_sql = implode( ' UNION ', $sql_parts ) . " LIMIT 15";
        $results = $wpdb->get_results( $full_sql );
        return array_filter($results, fn($item) => !empty($item->value));
    }


    /**
     * Fetches a standard list of properties with all necessary fields.
     */
    public static function get_listings( $args = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $defaults = [ 'status' => 'Active', 'limit' => 24, 'offset' => 0, 'orderby' => 'ModificationTimestamp', 'order' => 'DESC' ];
        $args = wp_parse_args( $args, $defaults );
        $where_clauses = [];
        if ( ! empty( $args['status'] ) ) {
            $where_clauses[] = $wpdb->prepare( "StandardStatus = %s", $args['status'] );
        }
        $sql = "SELECT ListingId, ListPrice, StandardStatus, PropertyType, StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode, BedroomsTotal, BathroomsTotalInteger, LivingArea, Media FROM {$table_name}";
        if ( ! empty( $where_clauses ) ) {
            $sql .= " WHERE " . implode( ' AND ', $where_clauses );
        }
        $sql .= $wpdb->prepare( " ORDER BY %s %s LIMIT %d OFFSET %d", esc_sql($args['orderby']), esc_sql($args['order']), $args['limit'], $args['offset'] );
        return $wpdb->get_results( $sql );
    }
}
