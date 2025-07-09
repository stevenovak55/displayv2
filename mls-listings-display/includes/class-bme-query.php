<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v1.9.0
 * - REFACTOR: `get_listings_for_map` now uses the `$is_new_filter` flag to determine the query type.
 * - If true, it ignores map boundaries to fetch all matching listings for a global "fit-to-bounds" search.
 * - If false, it uses the map boundaries to fetch listings only within the current viewport, with or without additional filters.
 * This restores all intended functionality and improves logical clarity.
 */
class MLD_BME_Query {

    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null, $is_new_filter = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $select_clause = "SELECT 
            ListingId, Latitude, Longitude, ListPrice, StandardStatus, PropertyType, 
            StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
            BedroomsTotal, BathroomsTotalInteger, LivingArea, Media
            FROM {$table_name}";

        $where_conditions = [];
        $has_filters = ! empty( $filters ) && is_array( $filters );

        // If this is NOT a new filter action, we MUST use the map boundaries.
        // This applies to initial load, panning, and zooming.
        if ( ! $is_new_filter ) {
            $polygon_wkt = sprintf(
                'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                $west, $north,
                $east, $north,
                $east, $south,
                $west, $south,
                $west, $north
            );
            $where_conditions[] = $wpdb->prepare("ST_Contains(ST_GeomFromText(%s), Coordinates)", $polygon_wkt);
        }
        
        // If filters are present, they are ALWAYS added to the query.
        if ( $has_filters ) {
            $filter_where_group = [];
            
            $keyword_filter_map = [
                'City' => 'City', 'Building Name' => 'BuildingName', 'MLS Area Major' => 'MLSAreaMajor',
                'MLS Area Minor' => 'MLSAreaMinor', 'Postal Code' => 'PostalCode', 'Street Name' => 'StreetName',
                'MLS Number' => 'ListingId', 'Address' => "CONCAT_WS(' ', StreetNumber, StreetName, ',', City)",
            ];

            foreach ( $keyword_filter_map as $type => $column ) {
                if ( ! empty( $filters[$type] ) && is_array( $filters[$type] ) ) {
                    $or_conditions = [];
                    foreach ( $filters[$type] as $value ) {
                        $or_conditions[] = $wpdb->prepare( "TRIM({$column}) = %s", trim($value) );
                    }
                    if ( ! empty( $or_conditions ) ) {
                        $filter_where_group[] = '( ' . implode( ' OR ', $or_conditions ) . ' )';
                    }
                }
            }

            if ( ! empty( $filters['price_min'] ) ) $filter_where_group[] = $wpdb->prepare( "ListPrice >= %d", intval( $filters['price_min'] ) );
            if ( ! empty( $filters['price_max'] ) ) $filter_where_group[] = $wpdb->prepare( "ListPrice <= %d", intval( $filters['price_max'] ) );
            if ( ! empty( $filters['beds_min'] ) ) $filter_where_group[] = $wpdb->prepare( "BedroomsTotal >= %d", intval( $filters['beds_min'] ) );
            if ( ! empty( $filters['baths_min'] ) ) $filter_where_group[] = $wpdb->prepare( "BathroomsTotalInteger >= %d", intval( $filters['baths_min'] ) );
            if ( ! empty( $filters['home_type'] ) ) $filter_where_group[] = $wpdb->prepare( "PropertyType IN (" . implode( ', ', array_fill(0, count($filters['home_type']), '%s') ) . ")", $filters['home_type'] );
            if ( ! empty( $filters['status'] ) ) $filter_where_group[] = $wpdb->prepare( "StandardStatus IN (" . implode( ', ', array_fill(0, count($filters['status']), '%s') ) . ")", $filters['status'] );

            if ( ! empty( $filter_where_group ) ) {
                $where_conditions[] = implode(' AND ', $filter_where_group);
            }
        } 
        // If there are no filters, default to Active status.
        else {
             $where_conditions[] = "StandardStatus = 'Active'";
        }

        // Build the final query
        $sql = $select_clause;
        if ( ! empty( $where_conditions ) ) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

        // Apply a random limit ONLY when exploring the map within bounds (not a new global filter search).
        if ( ! $is_new_filter && ! $has_filters ) {
            $sql .= " ORDER BY RAND() LIMIT 325";
        }

        return $wpdb->get_results( $sql );
    }

    public static function get_autocomplete_suggestions( $term ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $term_like = '%' . $wpdb->esc_like( $term ) . '%';

        $fields_to_search = [
            'City' => 'City', 'BuildingName' => 'Building Name', 'MLSAreaMajor' => 'MLS Area Major',
            'MLSAreaMinor' => 'MLS Area Minor', 'PostalCode' => 'Postal Code', 'StreetName' => 'Street Name',
            'ListingId' => 'MLS Number',
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
             WHERE CONCAT_WS(' ', StreetNumber, StreetName, ',', City) LIKE %s)",
            $term_like
        );

        $full_sql = implode( ' UNION ', $sql_parts ) . " LIMIT 15";
        $results = $wpdb->get_results( $full_sql );
        return array_filter($results, fn($item) => !empty($item->value));
    }
}
