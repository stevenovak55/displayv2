<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v4.0.0
 * - FEAT: Added `get_price_distribution` to calculate price histogram data for the new dynamic price slider filter.
 */
class MLD_BME_Query {

    public static function get_price_distribution( $filters = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
    
        // Exclude price filters from the context to get the full range
        $context_filters = $filters;
        unset( $context_filters['price_min'], $context_filters['price_max'] );
    
        $where_conditions = self::build_filter_conditions( $context_filters );
        $where_clause = ! empty( $where_conditions ) ? 'WHERE ' . implode( ' AND ', $where_conditions ) : '';
    
        // Get min and max price for the given context
        $price_range_query = "SELECT MIN(ListPrice) as min_price, MAX(ListPrice) as max_price FROM {$table_name} {$where_clause}";
        $price_range = $wpdb->get_row( $price_range_query );
    
        $min_price = $price_range ? (float) $price_range->min_price : 0;
        $max_price = $price_range ? (float) $price_range->max_price : 0;
    
        if ( $min_price == 0 && $max_price == 0 ) {
            return ['min' => 0, 'max' => 0, 'distribution' => []];
        }
    
        // Calculate histogram
        $num_buckets = 20;
        $bucket_size = ( $max_price - $min_price ) / $num_buckets;
        if ( $bucket_size == 0 ) $bucket_size = 1;

        $histogram_query = $wpdb->prepare(
            "SELECT 
                FLOOR((ListPrice - %f) / %f) AS bucket_index, 
                COUNT(*) AS count
             FROM {$table_name} {$where_clause}
             GROUP BY bucket_index
             ORDER BY bucket_index ASC",
            $min_price,
            $bucket_size
        );

        $results = $wpdb->get_results($histogram_query, ARRAY_A);

        $distribution = array_fill(0, $num_buckets, 0);
        foreach ($results as $row) {
            $index = (int) $row['bucket_index'];
            if ($index >= 0 && $index < $num_buckets) {
                $distribution[$index] = (int) $row['count'];
            }
        }
    
        return [
            'min'          => $min_price,
            'max'          => $max_price,
            'distribution' => $distribution,
        ];
    }

    /**
     * Fetches all unique, non-empty PropertySubType values from the database.
     */
    public static function get_all_distinct_subtypes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $query = "SELECT DISTINCT PropertySubType FROM {$table_name} WHERE PropertySubType IS NOT NULL AND PropertySubType != '' ORDER BY PropertySubType ASC";
        return $wpdb->get_col( $query );
    }

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
        if (!in_array('pool_only', $exclude_keys) && !empty($filters['pool_only'])) $conditions[] = "PoolPrivateYN = 1";
        if (!in_array('garage_only', $exclude_keys) && !empty($filters['garage_only'])) $conditions[] = "GarageYN = 1";
        if (!in_array('fireplace_only', $exclude_keys) && !empty($filters['fireplace_only'])) $conditions[] = "FireplaceYN = 1";
        if (!in_array('open_house_only', $exclude_keys) && !empty($filters['open_house_only'])) $conditions[] = "OpenHouseData IS NOT NULL AND OpenHouseData != '[]' AND OpenHouseData != ''";

        if (!in_array('available_by', $exclude_keys) && !empty($filters['available_by'])) {
            $date = $filters['available_by'];
            $conditions[] = $wpdb->prepare("(MLSPIN_AvailableNow = 1 OR (AvailabilityDate IS NOT NULL AND AvailabilityDate <= %s))", $date);
        }

        return $conditions;
    }

    public static function get_distinct_filter_options( $filters = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $options = [];
        $fields_to_fetch = [
            'PropertySubType' => ['home_type'],
            'StandardStatus'  => ['status'],
        ];

        foreach ($fields_to_fetch as $field => $exclude_keys) {
            $filter_conditions = self::build_filter_conditions($filters, $exclude_keys);

            $where_clause = '';
            if (!empty($filter_conditions)) {
                $where_clause = ' WHERE ' . implode(' AND ', $filter_conditions);
            }

            $field_where_clause = $where_clause . ($where_clause ? ' AND ' : ' WHERE ') . "`{$field}` IS NOT NULL AND `{$field}` != ''";
            $query = "SELECT DISTINCT `{$field}` FROM `{$table_name}`" . $field_where_clause . " ORDER BY `{$field}` ASC";
            
            $options[$field] = $wpdb->get_col($query);
        }
        
        return $options;
    }

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

        if ( ! $is_new_filter && !$count_only) {
            $polygon_wkt = sprintf(
                'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                $west, $north, $east, $north, $east, $south, $west, $south, $west, $north
            );
            $where_conditions[] = $wpdb->prepare("ST_Contains(ST_GeomFromText(%s), Coordinates)", $polygon_wkt);
        }
        
        if ( ! empty( $filters ) && is_array( $filters ) ) {
            $filter_conditions = self::build_filter_conditions($filters);
            $where_conditions = array_merge($where_conditions, $filter_conditions);
        }

        if (empty($where_conditions)) {
            $where_conditions[] = "StandardStatus = 'Active' AND PropertyType = 'Residential'";
        }

        $sql = $select_clause;
        if ( ! empty( $where_conditions ) ) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

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
