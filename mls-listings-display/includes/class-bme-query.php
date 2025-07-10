<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v3.0.0
 * - FEAT: Adds filtering for open houses in `get_listings_for_map` by checking for non-empty `OpenHouseData`.
 * - REFACTOR: Removes the `get_live_details_from_api` and `make_bridge_api_call` methods as they are now obsolete. All data, including agent and office details, is queried directly from the local database.
 */
class MLD_BME_Query {

    /**
     * Fetches all columns for a single listing by its ID from the local database.
     *
     * @param string $listing_id The MLS Number (ListingId) of the property.
     * @return array|null The listing data as an associative array, or null if not found.
     */
    public static function get_listing_details( $listing_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE ListingId = %s", $listing_id );
        $details = $wpdb->get_row( $query, ARRAY_A );
        
        return $details;
    }

    /**
     * Fetches distinct values for filter dropdowns.
     *
     * @param string $listing_mode 'For Sale' or 'For Rent'.
     * @return array An array of options for the filters.
     */
    public static function get_distinct_filter_options( $listing_mode = 'For Sale' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $property_type = ($listing_mode === 'For Rent') ? 'Residential Lease' : 'Residential';

        $options = [];
        $fields_to_fetch = [
            'PropertySubType',
        ];

        foreach ($fields_to_fetch as $field) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT `{$field}` FROM `{$table_name}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '' AND `PropertyType` = %s ORDER BY `{$field}` ASC",
                $property_type
            );
            $options[$field] = $wpdb->get_col($query);
        }
        
        return $options;
    }

    /**
     * Fetches listings for the map view based on geographic bounds and filters.
     *
     * @param float $north The northern latitude bound.
     * @param float $south The southern latitude bound.
     * @param float $east The eastern longitude bound.
     * @param float $west The western longitude bound.
     * @param array|null $filters An array of filter criteria.
     * @param bool $is_new_filter True if this is a new filter action, false if it's a map pan/zoom.
     * @param bool $count_only True to return only the count of matching listings.
     * @return array|int The listing data or the count.
     */
    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null, $is_new_filter = false, $count_only = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $select_clause = $count_only 
            ? "SELECT COUNT(id) FROM {$table_name}"
            : "SELECT 
                ListingId, Latitude, Longitude, ListPrice, StandardStatus, PropertyType, PropertySubType,
                StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
                BedroomsTotal, BathroomsFull, BathroomsHalf, BathroomsTotalInteger, LivingArea, LotSizeAcres, YearBuilt, Media,
                OpenHouseData
              FROM {$table_name}";

        $where_conditions = [];
        $has_filters = ! empty( $filters ) && is_array( $filters );

        if ( ! $is_new_filter && !$count_only) {
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

            if ( ! empty( $filters['PropertyType'] ) ) {
                $filter_where_group[] = $wpdb->prepare( "PropertyType IN (" . implode( ', ', array_fill(0, count($filters['PropertyType']), '%s') ) . ")", $filters['PropertyType'] );
            }

            if ( ! empty( $filters['price_min'] ) ) $filter_where_group[] = $wpdb->prepare( "ListPrice >= %d", intval( $filters['price_min'] ) );
            if ( ! empty( $filters['price_max'] ) ) $filter_where_group[] = $wpdb->prepare( "ListPrice <= %d", intval( $filters['price_max'] ) );
            
            if ( ! empty( $filters['beds_min'] ) ) {
                if ( ! empty( $filters['beds_max'] ) && $filters['beds_max'] >= $filters['beds_min'] ) {
                    $filter_where_group[] = $wpdb->prepare( "BedroomsTotal BETWEEN %d AND %d", intval( $filters['beds_min'] ), intval( $filters['beds_max'] ) );
                } else {
                    $filter_where_group[] = $wpdb->prepare( "BedroomsTotal >= %d", intval( $filters['beds_min'] ) );
                }
            }
            
            if ( ! empty( $filters['baths_min'] ) ) {
                $bath_calc = "(BathroomsFull + (BathroomsHalf * 0.5))";
                 if ( ! empty( $filters['baths_max'] ) && $filters['baths_max'] >= $filters['baths_min'] ) {
                    $filter_where_group[] = $wpdb->prepare( "{$bath_calc} BETWEEN %f AND %f", floatval( $filters['baths_min'] ), floatval( $filters['baths_max'] ) );
                } else {
                    $filter_where_group[] = $wpdb->prepare( "{$bath_calc} >= %f", floatval( $filters['baths_min'] ) );
                }
            }

            if ( ! empty( $filters['home_type'] ) ) $filter_where_group[] = $wpdb->prepare( "PropertySubType IN (" . implode( ', ', array_fill(0, count($filters['home_type']), '%s') ) . ")", $filters['home_type'] );
            if ( ! empty( $filters['status'] ) ) $filter_where_group[] = $wpdb->prepare( "StandardStatus IN (" . implode( ', ', array_fill(0, count($filters['status']), '%s') ) . ")", $filters['status'] );
            if ( ! empty( $filters['sqft_min'] ) ) $filter_where_group[] = $wpdb->prepare( "LivingArea >= %d", intval( $filters['sqft_min'] ) );
            if ( ! empty( $filters['sqft_max'] ) ) $filter_where_group[] = $wpdb->prepare( "LivingArea <= %d", intval( $filters['sqft_max'] ) );
            if ( ! empty( $filters['year_built_min'] ) ) $filter_where_group[] = $wpdb->prepare( "YearBuilt >= %d", intval( $filters['year_built_min'] ) );
            if ( ! empty( $filters['year_built_max'] ) ) $filter_where_group[] = $wpdb->prepare( "YearBuilt <= %d", intval( $filters['year_built_max'] ) );
            if ( ! empty( $filters['lot_size_min'] ) ) $filter_where_group[] = $wpdb->prepare( "LotSizeAcres >= %f", floatval( $filters['lot_size_min'] ) );
            if ( ! empty( $filters['lot_size_max'] ) ) $filter_where_group[] = $wpdb->prepare( "LotSizeAcres <= %f", floatval( $filters['lot_size_max'] ) );
            if ( ! empty( $filters['waterfront_only'] ) && $filters['waterfront_only'] ) $filter_where_group[] = "WaterfrontYN = 1";
            
            // New filter for Open Houses
            if ( ! empty( $filters['open_house_only'] ) && $filters['open_house_only'] ) {
                $filter_where_group[] = "OpenHouseData IS NOT NULL AND OpenHouseData != '[]' AND OpenHouseData != ''";
            }

            if ( ! empty( $filter_where_group ) ) {
                $where_conditions[] = implode(' AND ', $filter_where_group);
            }
        } 
        else if (!$count_only) {
             $where_conditions[] = "PropertyType = 'Residential'";
        }

        $sql = $select_clause;
        if ( ! empty( $where_conditions ) ) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

        if ( ! $is_new_filter && ! $has_filters && !$count_only) {
            $sql .= " ORDER BY RAND() LIMIT 325";
        } else if (!$count_only) {
            $sql .= " LIMIT 1000";
        }

        return $count_only ? $wpdb->get_var( $sql ) : $wpdb->get_results( $sql );
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
