<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * @package MLS_Listings_Display
 */
class MLD_Query {

    public static function get_all_listings_for_cache($filters = null, $page = 1, $limit = 500) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $offset = ($page - 1) * $limit;

        $select_clause = "SELECT 
            ListingId, Latitude, Longitude, ListPrice, OriginalListPrice, StandardStatus, PropertyType, PropertySubType,
            StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
            BedroomsTotal, BathroomsFull, BathroomsHalf, BathroomsTotalInteger, LivingArea, LotSizeAcres, YearBuilt, Media,
            OpenHouseData, AssociationFee, AssociationFeeFrequency, GarageSpaces
          FROM {$table_name}";
        
        $count_sql = "SELECT COUNT(id) FROM {$table_name}";

        $where_conditions = self::build_filter_conditions($filters ?: []);

        $sql = $select_clause;
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
            $count_sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

        $total_listings = $wpdb->get_var($count_sql);

        $sql .= $wpdb->prepare(" ORDER BY ModificationTimestamp DESC LIMIT %d OFFSET %d", $limit, $offset);
        
        $listings = $wpdb->get_results($sql);

        return ['total' => (int)$total_listings, 'listings' => $listings];
    }

    public static function get_price_distribution( $filters = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
    
        $context_filters = $filters;
        unset( $context_filters['price_min'], $context_filters['price_max'] );
    
        $where_conditions = self::build_filter_conditions( $context_filters );
        $where_clause = ! empty( $where_conditions ) ? 'WHERE ' . implode( ' AND ', $where_conditions ) : '';
    
        $all_prices_query = "SELECT ListPrice FROM {$table_name} {$where_clause} AND ListPrice > 0 ORDER BY ListPrice ASC";
        $prices = $wpdb->get_col( $all_prices_query );
    
        if ( empty( $prices ) ) {
            return ['min' => 0, 'display_max' => 0, 'distribution' => [], 'outlier_count' => 0];
        }
    
        $min_price = (float) $prices[0];
        $price_count = count( $prices );
    
        $percentile_index = floor( $price_count * 0.95 );
        $percentile_index = max(0, min($price_count - 1, $percentile_index));
        $display_max_price = (float) $prices[ $percentile_index ];
        
        if ($display_max_price <= $min_price && $price_count > 0) {
            $display_max_price = (float) end($prices);
        }
    
        $num_buckets = 20;
        $bucket_size = ( $display_max_price - $min_price ) / $num_buckets;
        if ( $bucket_size <= 0 ) $bucket_size = 1;
    
        $histogram_where_conditions = $where_conditions;
        $histogram_where_conditions[] = $wpdb->prepare("ListPrice BETWEEN %f AND %f", $min_price, $display_max_price);
        $histogram_where_clause = 'WHERE ' . implode(' AND ', $histogram_where_conditions);

        $histogram_query = $wpdb->prepare(
            "SELECT FLOOR((ListPrice - %f) / %f) AS bucket_index, COUNT(*) AS count
             FROM {$table_name} {$histogram_where_clause}
             GROUP BY bucket_index ORDER BY bucket_index ASC",
            $min_price, $bucket_size
        );
    
        $results = $wpdb->get_results($histogram_query, ARRAY_A);
    
        $distribution = array_fill(0, $num_buckets, 0);
        foreach ($results as $row) {
            $index = (int) $row['bucket_index'];
            if ($index >= 0 && $index < $num_buckets) {
                $distribution[$index] = (int) $row['count'];
            }
        }

        $outlier_where_conditions = $where_conditions;
        $outlier_where_conditions[] = $wpdb->prepare("ListPrice > %f", $display_max_price);
        $outlier_where_clause = 'WHERE ' . implode(' AND ', $outlier_where_conditions);
        $outlier_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} {$outlier_where_clause}");
    
        return [
            'min'           => $min_price,
            'display_max'   => $display_max_price,
            'distribution'  => $distribution,
            'outlier_count' => $outlier_count,
        ];
    }

    public static function get_all_distinct_subtypes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $query = "SELECT DISTINCT PropertySubType FROM {$table_name} WHERE PropertySubType IS NOT NULL AND PropertySubType != '' ORDER BY PropertySubType ASC";
        return $wpdb->get_col( $query );
    }

    public static function get_listing_details( $listing_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE ListingId = %s", $listing_id );
        return $wpdb->get_row( $query, ARRAY_A );
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

        if (!in_array('PropertyType', $exclude_keys) && !empty($filters['PropertyType'])) $conditions[] = $wpdb->prepare("PropertyType = %s", $filters['PropertyType']);
        if (!in_array('price_min', $exclude_keys) && !empty($filters['price_min'])) $conditions[] = $wpdb->prepare("ListPrice >= %d", intval($filters['price_min']));
        if (!in_array('price_max', $exclude_keys) && !empty($filters['price_max'])) $conditions[] = $wpdb->prepare("ListPrice <= %d", intval($filters['price_max']));
        if (!in_array('beds', $exclude_keys) && !empty($filters['beds']) && is_array($filters['beds'])) {
            $bed_conditions = [];
            $has_plus = false;
            foreach ($filters['beds'] as $bed) {
                if (strpos($bed, '+') !== false) {
                    $bed_conditions[] = $wpdb->prepare("BedroomsTotal >= %d", intval($bed));
                    $has_plus = true;
                } else {
                    $bed_conditions[] = $wpdb->prepare("BedroomsTotal = %d", intval($bed));
                }
            }
            if (!empty($bed_conditions)) {
                 if(count($bed_conditions) > 1 && $has_plus) {
                    $min_bed = min(array_map('intval', $filters['beds']));
                    $conditions[] = $wpdb->prepare("BedroomsTotal >= %d", $min_bed);
                 } else {
                    $conditions[] = '( ' . implode(' OR ', $bed_conditions) . ' )';
                 }
            }
        }
        if (!in_array('baths_min', $exclude_keys) && !empty($filters['baths_min'])) $conditions[] = $wpdb->prepare("(BathroomsFull + (BathroomsHalf * 0.5)) >= %f", floatval($filters['baths_min']));
        if (!in_array('home_type', $exclude_keys) && !empty($filters['home_type']) && is_array($filters['home_type'])) $conditions[] = $wpdb->prepare("PropertySubType IN (" . implode(', ', array_fill(0, count($filters['home_type']), '%s')) . ")", $filters['home_type']);
        if (!in_array('status', $exclude_keys) && !empty($filters['status']) && is_array($filters['status'])) $conditions[] = $wpdb->prepare("StandardStatus IN (" . implode(', ', array_fill(0, count($filters['status']), '%s')) . ")", $filters['status']);
        if (!in_array('sqft_min', $exclude_keys) && !empty($filters['sqft_min'])) $conditions[] = $wpdb->prepare("LivingArea >= %d", intval($filters['sqft_min']));
        if (!in_array('sqft_max', $exclude_keys) && !empty($filters['sqft_max'])) $conditions[] = $wpdb->prepare("LivingArea <= %d", intval($filters['sqft_max']));
        if (!in_array('year_built_min', $exclude_keys) && !empty($filters['year_built_min'])) $conditions[] = $wpdb->prepare("YearBuilt >= %d", intval($filters['year_built_min']));
        if (!in_array('year_built_max', $exclude_keys) && !empty($filters['year_built_max'])) $conditions[] = $wpdb->prepare("YearBuilt <= %d", intval($filters['year_built_max']));
        if (!in_array('keywords', $exclude_keys) && !empty($filters['keywords'])) $conditions[] = $wpdb->prepare("PublicRemarks LIKE %s", '%' . $wpdb->esc_like($filters['keywords']) . '%');
        if (!in_array('stories', $exclude_keys) && !empty($filters['stories'])) $conditions[] = $filters['stories'] === '3+' ? $wpdb->prepare("StoriesTotal >= %d", 3) : $wpdb->prepare("StoriesTotal = %d", intval($filters['stories']));
        if (!in_array('waterfront_only', $exclude_keys) && !empty($filters['waterfront_only'])) $conditions[] = "WaterfrontYN = 1";
        if (!in_array('pool_only', $exclude_keys) && !empty($filters['pool_only'])) $conditions[] = "PoolPrivateYN = 1";
        if (!in_array('garage_only', $exclude_keys) && !empty($filters['garage_only'])) $conditions[] = "GarageYN = 1";
        if (!in_array('fireplace_only', $exclude_keys) && !empty($filters['fireplace_only'])) $conditions[] = "FireplaceYN = 1";
        if (!in_array('open_house_only', $exclude_keys) && !empty($filters['open_house_only'])) $conditions[] = "OpenHouseData IS NOT NULL AND OpenHouseData != '[]' AND OpenHouseData != ''";
        if (!in_array('available_by', $exclude_keys) && !empty($filters['available_by']) && preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $filters['available_by'])) $conditions[] = $wpdb->prepare("(MLSPIN_AvailableNow = 1 OR (AvailabilityDate IS NOT NULL AND AvailabilityDate <= %s))", $filters['available_by']);

        return $conditions;
    }

    public static function get_distinct_filter_options( $filters = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $options = [];
        $fields_to_fetch = [ 'PropertySubType' => ['home_type'], 'StandardStatus'  => ['status'] ];

        foreach ($fields_to_fetch as $field => $exclude_keys) {
            $filter_conditions = self::build_filter_conditions($filters, $exclude_keys);
            $where_clause = !empty($filter_conditions) ? ' WHERE ' . implode(' AND ', $filter_conditions) : '';
            $field_where_clause = $where_clause ? $where_clause . ' AND ' : ' WHERE ';
            $field_where_clause .= "`{$field}` IS NOT NULL AND `{$field}` != ''";
            $query = "SELECT DISTINCT `{$field}` FROM `{$table_name}`" . $field_where_clause . " ORDER BY `{$field}` ASC";
            $options[$field] = $wpdb->get_col($query);
        }
        
        return $options;
    }

    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null, $is_new_filter = false, $count_only = false, $is_initial_load = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $boston_lat = 42.3601;
        $boston_lng = -71.0589;
        $radius_miles = 3;

        $select_fields = "ListingId, Latitude, Longitude, ListPrice, OriginalListPrice, StandardStatus, PropertyType, PropertySubType, StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode, BedroomsTotal, BathroomsFull, BathroomsHalf, BathroomsTotalInteger, LivingArea, LotSizeAcres, YearBuilt, Media, OpenHouseData, AssociationFee, AssociationFeeFrequency, GarageSpaces";
        $distance_calc = "( 3959 * acos( cos( radians({$boston_lat}) ) * cos( radians( Latitude ) ) * cos( radians( Longitude ) - radians({$boston_lng}) ) + sin( radians({$boston_lat}) ) * sin( radians( Latitude ) ) ) )";
        
        // --- Build Filter Conditions ---
        $filter_conditions = self::build_filter_conditions($filters ?: []);
        if (empty($filter_conditions) && $is_initial_load) {
            $filter_conditions[] = "StandardStatus = 'Active' AND PropertyType = 'Residential'";
        }
        $filter_where_clause = !empty($filter_conditions) ? " WHERE " . implode(' AND ', $filter_conditions) : "";

        // --- Get Total Count for Filters ---
        $total_count_sql = "SELECT COUNT(id) FROM {$table_name}{$filter_where_clause}";
        $total_for_filters = (int) $wpdb->get_var($total_count_sql);

        if ($count_only) {
            return $total_for_filters;
        }

        // --- Get Listings for Map View ---
        $view_conditions = $filter_conditions;
        $bounds_are_valid = ($north != 0 || $south != 0 || $east != 0 || $west != 0);

        if ($is_initial_load) {
            $view_conditions[] = $wpdb->prepare("({$distance_calc}) < %f", $radius_miles);
        } else if (!$is_new_filter && $bounds_are_valid) {
            $polygon_wkt = sprintf('POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))', $west, $north, $east, $north, $east, $south, $west, $south, $west, $north);
            $view_conditions[] = $wpdb->prepare("ST_Contains(ST_GeomFromText(%s), Coordinates)", $polygon_wkt);
        }

        $view_where_clause = !empty($view_conditions) ? " WHERE " . implode(' AND ', $view_conditions) : "";
        
        $select_clause = "SELECT {$select_fields}";
        if ($is_initial_load) {
            $select_clause .= ", {$distance_calc} AS distance";
        }
        
        $sql = "{$select_clause} FROM {$table_name}{$view_where_clause}";

        if ($is_initial_load) {
            $limit = 250;
            $order_by = "distance ASC";
        } else {
            $limit = ($is_new_filter || empty($filters)) ? 1000 : 325;
            $order_by = "ModificationTimestamp DESC";
        }
        $sql .= " ORDER BY {$order_by} LIMIT {$limit}";

        $listings = $wpdb->get_results( $sql );

        return ['listings' => $listings, 'total' => $total_for_filters];
    }

    public static function get_autocomplete_suggestions( $term ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $term_like = '%' . $wpdb->esc_like( $term ) . '%';

        $fields_to_search = [
            'City' => 'City',
            'BuildingName' => 'Building Name',
            'MLSAreaMajor' => 'MLS Area Major',
            'MLSAreaMinor' => 'MLS Area Minor',
            'PostalCode' => 'Postal Code', // Corrected key
            'StreetName' => 'Street Name',
            'ListingId' => 'MLS Number',
        ];

        $sql_parts = [];
        foreach ( $fields_to_search as $field_name => $type_label ) {
            $sql_parts[] = $wpdb->prepare(
                "(SELECT %s AS type, TRIM(`$field_name`) AS value FROM `$table_name` WHERE TRIM(`$field_name`) LIKE %s AND `$field_name` IS NOT NULL AND `$field_name` != '')",
                $type_label,
                $term_like
            );
        }
        $sql_parts[] = $wpdb->prepare(
            "(SELECT 'Address' AS type, TRIM(CONCAT_WS(' ', StreetNumber, StreetName, ',', City)) AS value FROM `$table_name` WHERE TRIM(CONCAT_WS(' ', StreetNumber, StreetName, ',', City)) LIKE %s)",
            $term_like
        );

        $full_sql = implode( ' UNION ', $sql_parts ) . " LIMIT 15";
        $results = $wpdb->get_results( $full_sql );
        return array_filter($results, fn($item) => !empty($item->value));
    }
}
