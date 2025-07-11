<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v3.6.0
 * - FEAT: Added more fields to the map query to support the redesigned property cards, including pricing, HOA, and garage data.
 * - REFACTOR: `get_listings_for_map` and `build_where_clause_from_filters` were completely refactored to simplify the SQL query assembly. This fixes a critical bug where the `PropertyType` filter was not being applied correctly in all scenarios.
 * - FEAT: `get_distinct_filter_options` now accepts a `$filters` array to return context-aware `PropertySubType` values.
 */
class MLD_BME_Query {

    /**
     * Fetches all columns for a single listing by its ID from the local database.
     */
    public static function get_listing_details( $listing_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE ListingId = %s", $listing_id );
        $details = $wpdb->get_row( $query, ARRAY_A );
        
        return $details;
    }

    /**
     * Builds a reusable array of WHERE conditions from a filters array.
     * @param array $filters The filters to apply.
     * @param array $exclude_keys Keys from the filter array to ignore.
     * @return array The generated array of SQL WHERE conditions.
     */
    private static function build_filter_conditions($filters, $exclude_keys = []) {
        global $wpdb;
        $conditions = [];

        if (empty($filters) || !is_array($filters)) {
            return $conditions;
        }

        $keyword_filter_map = [
            'City' => 'City', 'Building Name' => 'BuildingName', 'MLS Area Major' => 'MLSAreaMajor',
            'MLS Area Minor' => 'MLSAreaMinor', 'Postal Code' => 'PostalCode', 'Street Name' => 'StreetName',
            'MLS Number' => 'ListingId', 'Address' => "CONCAT_WS(' ', StreetNumber, StreetName, ',', City)",
        ];

        foreach ($keyword_filter_map as $type => $column) {
            if (!in_array($type, $exclude_keys) && !empty($filters[$type]) && is_array($filters[$type])) {
                $or_conditions = [];
                foreach ($filters[$type] as $value) {
                    $or_conditions[] = $wpdb->prepare("TRIM({$column}) = %s", trim($value));
                }
                if (!empty($or_conditions)) {
                    $conditions[] = '( ' . implode(' OR ', $or_conditions) . ' )';
                }
            }
        }

        if (!in_array('PropertyType', $exclude_keys) && !empty($filters['PropertyType'])) {
             $conditions[] = $wpdb->prepare("PropertyType = %s", $filters['PropertyType']);
        }
        if (!in_array('price_min', $exclude_keys) && !empty($filters['price_min'])) $conditions[] = $wpdb->prepare("ListPrice >= %d", intval($filters['price_min']));
        if (!in_array('price_max', $exclude_keys) && !empty($filters['price_max'])) $conditions[] = $wpdb->prepare("ListPrice <= %d", intval($filters['price_max']));
        
        if (!in_array('beds_min', $exclude_keys) && !empty($filters['beds_min'])) {
            if (!empty($filters['beds_max']) && $filters['beds_max'] >= $filters['beds_min']) {
                $conditions[] = $wpdb->prepare("BedroomsTotal BETWEEN %d AND %d", intval($filters['beds_min']), intval($filters['beds_max']));
            } else {
                $conditions[] = $wpdb->prepare("BedroomsTotal >= %d", intval($filters['beds_min']));
            }
        }
        
        if (!in_array('baths_min', $exclude_keys) && !empty($filters['baths_min'])) {
            $bath_calc = "(BathroomsFull + (BathroomsHalf * 0.5))";
            if (!empty($filters['baths_max']) && $filters['baths_max'] >= $filters['baths_min']) {
                $conditions[] = $wpdb->prepare("{$bath_calc} BETWEEN %f AND %f", floatval($filters['baths_min']), floatval($filters['baths_max']));
            } else {
                $conditions[] = $wpdb->prepare("{$bath_calc} >= %f", floatval($filters['baths_min']));
            }
        }

        if (!in_array('home_type', $exclude_keys) && !empty($filters['home_type'])) $conditions[] = $wpdb->prepare("PropertySubType IN (" . implode(', ', array_fill(0, count($filters['home_type']), '%s')) . ")", $filters['home_type']);
        if (!in_array('status', $exclude_keys) && !empty($filters['status'])) $conditions[] = $wpdb->prepare("StandardStatus IN (" . implode(', ', array_fill(0, count($filters['status']), '%s')) . ")", $filters['status']);
        if (!in_array('sqft_min', $exclude_keys) && !empty($filters['sqft_min'])) $conditions[] = $wpdb->prepare("LivingArea >= %d", intval($filters['sqft_min']));
        if (!in_array('sqft_max', $exclude_keys) && !empty($filters['sqft_max'])) $conditions[] = $wpdb->prepare("LivingArea <= %d", intval($filters['sqft_max']));
        if (!in_array('year_built_min', $exclude_keys) && !empty($filters['year_built_min'])) $conditions[] = $wpdb->prepare("YearBuilt >= %d", intval($filters['year_built_min']));
        if (!in_array('year_built_max', $exclude_keys) && !empty($filters['year_built_max'])) $conditions[] = $wpdb->prepare("YearBuilt <= %d", intval($filters['year_built_max']));
        
        if (!in_array('keywords', $exclude_keys) && !empty($filters['keywords'])) $conditions[] = $wpdb->prepare("PublicRemarks LIKE %s", '%' . $wpdb->esc_like($filters['keywords']) . '%');
        if (!in_array('stories', $exclude_keys) && !empty($filters['stories'])) {
            if ($filters['stories'] === '3+') {
                $conditions[] = $wpdb->prepare("StoriesTotal >= %d", 3);
            } else {
                $conditions[] = $wpdb->prepare("StoriesTotal = %d", intval($filters['stories']));
            }
        }
        if (!in_array('waterfront_only', $exclude_keys) && !empty($filters['waterfront_only'])) $conditions[] = "WaterfrontYN = 1";
        if (!in_array('pool_only', $exclude_keys) && !empty($filters['pool_only'])) $conditions[] = "PoolYN = 1";
        if (!in_array('garage_only', $exclude_keys) && !empty($filters['garage_only'])) $conditions[] = "GarageYN = 1";
        if (!in_array('fireplace_only', $exclude_keys) && !empty($filters['fireplace_only'])) $conditions[] = "FireplaceYN = 1";
        if (!in_array('open_house_only', $exclude_keys) && !empty($filters['open_house_only'])) $conditions[] = "OpenHouseData IS NOT NULL AND OpenHouseData != '[]' AND OpenHouseData != ''";

        if (!in_array('available_by', $exclude_keys) && !empty($filters['available_by'])) {
            $date = $filters['available_by'];
            $conditions[] = $wpdb->prepare("(MLSPIN_AvailableNow = 1 OR (AvailabilityDate IS NOT NULL AND AvailabilityDate <= %s))", $date);
        }

        return $conditions;
    }

    /**
     * Fetches distinct values for filter dropdowns based on current filters.
     */
    public static function get_distinct_filter_options( $filters = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $options = [];
        $fields_to_fetch = [
            'PropertySubType',
        ];

        $filter_conditions = self::build_filter_conditions($filters, ['home_type']);

        $where_clause = '';
        if (!empty($filter_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $filter_conditions);
        }

        foreach ($fields_to_fetch as $field) {
            $field_where_clause = $where_clause . ($where_clause ? ' AND ' : ' WHERE ') . "`{$field}` IS NOT NULL AND `{$field}` != ''";
            $query = "SELECT DISTINCT `{$field}` FROM `{$table_name}`" . $field_where_clause . " ORDER BY `{$field}` ASC";
            $options[$field] = $wpdb->get_col($query);
        }
        
        return $options;
    }

    /**
     * Fetches listings for the map view based on geographic bounds and filters.
     */
    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null, $is_new_filter = false, $count_only = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $select_clause = $count_only 
            ? "SELECT COUNT(id) FROM {$table_name}"
            : "SELECT 
                ListingId, Latitude, Longitude, ListPrice, OriginalListPrice, StandardStatus, PropertyType, PropertySubType,
                StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
                BedroomsTotal, BathroomsFull, BathroomsHalf, BathroomsTotalInteger, LivingArea, LotSizeAcres, YearBuilt, Media,
                OpenHouseData, AssociationFee, AssociationFeeFrequency, GarageSpaces
              FROM {$table_name}";

        $where_conditions = [];

        // Add spatial filter if it's a map pan/zoom action.
        if ( ! $is_new_filter && !$count_only) {
            $polygon_wkt = sprintf(
                'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                $west, $north, $east, $north, $east, $south, $west, $south, $west, $north
            );
            $where_conditions[] = $wpdb->prepare("ST_Contains(ST_GeomFromText(%s), Coordinates)", $polygon_wkt);
        }
        
        // Add other filters from the UI.
        if ( ! empty( $filters ) && is_array( $filters ) ) {
            $filter_conditions = self::build_filter_conditions($filters);
            $where_conditions = array_merge($where_conditions, $filter_conditions);
        }

        // If after all that, there are still no conditions, apply a default.
        if (empty($where_conditions)) {
            $where_conditions[] = "StandardStatus = 'Active' AND PropertyType = 'Residential'";
        }

        // Assemble the final query.
        $sql = $select_clause;
        if ( ! empty( $where_conditions ) ) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

        // Add limits for performance.
        if ( ! $is_new_filter && empty($filters) && !$count_only) {
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
