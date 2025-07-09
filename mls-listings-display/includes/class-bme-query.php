<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 *
 * v2.7.0
 * - FEAT: `get_distinct_filter_options` now accepts a `$listing_mode` and filters options accordingly.
 * - FEAT: Adds `get_listing_details` to fetch all columns for a single listing by its ID.
 * - REFACTOR: `get_live_details_from_api` is completely rebuilt to make separate, correct API calls for Property, Member, Office, and OpenHouse data, then combines the results. This fixes the "Could not load" error.
 */
class MLD_BME_Query {

    /**
     * Fetches live, expanded data for a single listing by making multiple calls to the Bridge API.
     *
     * @param string $listing_id The MLS Number (ListingId) of the property.
     * @return array|null The combined API response data or null on failure.
     */
    public static function get_live_details_from_api( $listing_id ) {
        $extractor_options = get_option('bme_api_credentials');
        $api_token = isset($extractor_options['server_token']) ? $extractor_options['server_token'] : null;
        $api_url_base = isset($extractor_options['endpoint_url']) ? $extractor_options['endpoint_url'] : null;

        if ( ! $api_token || ! $api_url_base ) {
            throw new Exception('Bridge API credentials are not configured in the Extractor plugin.');
        }

        // --- Step 1: Fetch the core Property data ---
        $property_data = self::make_bridge_api_call($api_url_base, $api_token, ['$filter' => "ListingId eq '" . esc_attr($listing_id) . "'"]);
        if ( empty($property_data) ) {
            // If the main property isn't found, we can't proceed.
            return null;
        }
        
        // The result is the first item in the 'value' array.
        $listing_details = $property_data[0];

        // --- Step 2: Dynamically build URLs for other resources ---
        // This replaces '/Property' at the end of the URL with the correct resource name.
        $member_url = str_replace('/Property', '/Member', $api_url_base);
        $office_url = str_replace('/Property', '/Office', $api_url_base);
        $openhouse_url = str_replace('/Property', '/OpenHouse', $api_url_base);

        // --- Step 3: Fetch related data if IDs exist ---
        
        // List Agent
        if ( !empty($listing_details['ListAgentMlsId']) ) {
            $agent_data = self::make_bridge_api_call($member_url, $api_token, ['$filter' => "MemberMlsId eq '" . esc_attr($listing_details['ListAgentMlsId']) . "'"]);
            if (!empty($agent_data)) $listing_details['ListAgent'] = $agent_data[0];
        }

        // List Office
        if ( !empty($listing_details['ListOfficeMlsId']) ) {
            $office_data = self::make_bridge_api_call($office_url, $api_token, ['$filter' => "OfficeMlsId eq '" . esc_attr($listing_details['ListOfficeMlsId']) . "'"]);
            if (!empty($office_data)) $listing_details['ListOffice'] = $office_data[0];
        }

        // Buyer Agent
        if ( !empty($listing_details['BuyerAgentMlsId']) ) {
            $buyer_agent_data = self::make_bridge_api_call($member_url, $api_token, ['$filter' => "MemberMlsId eq '" . esc_attr($listing_details['BuyerAgentMlsId']) . "'"]);
            if (!empty($buyer_agent_data)) $listing_details['BuyerAgent'] = $buyer_agent_data[0];
        }

        // Buyer Office
        if ( !empty($listing_details['BuyerOfficeMlsId']) ) {
            $buyer_office_data = self::make_bridge_api_call($office_url, $api_token, ['$filter' => "OfficeMlsId eq '" . esc_attr($listing_details['BuyerOfficeMlsId']) . "'"]);
            if (!empty($buyer_office_data)) $listing_details['BuyerOffice'] = $buyer_office_data[0];
        }
        
        // Open Houses (can be multiple)
        $open_house_data = self::make_bridge_api_call($openhouse_url, $api_token, ['$filter' => "ListingId eq '" . esc_attr($listing_id) . "'"]);
        if (!empty($open_house_data)) {
            $listing_details['OpenHouse'] = $open_house_data;
        }

        return $listing_details;
    }

    /**
     * A helper function to make a single, reusable call to the Bridge API.
     *
     * @param string $api_url The full URL for the resource.
     * @param string $api_token The server token.
     * @param array $query_args OData query args like '$filter', '$top'.
     * @return array|null The 'value' array from the response or null on error.
     */
    private static function make_bridge_api_call($api_url, $api_token, $query_args = []) {
        $default_args = ['access_token' => $api_token, '$top' => 10]; // Get up to 10 open houses, etc.
        $final_args = array_merge($default_args, $query_args);

        $request_url = add_query_arg($final_args, $api_url);
        $response = wp_remote_get($request_url, ['timeout' => 20]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Don't throw an exception here, just return null so the main function can continue.
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['value']) ? $data['value'] : null;
    }


    public static function get_listing_details( $listing_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        
        $query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE ListingId = %s", $listing_id );
        $details = $wpdb->get_row( $query, ARRAY_A );
        
        return $details;
    }

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

    public static function get_listings_for_map( $north, $south, $east, $west, $filters = null, $is_new_filter = false, $count_only = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';

        $select_clause = $count_only 
            ? "SELECT COUNT(id) FROM {$table_name}"
            : "SELECT 
                ListingId, Latitude, Longitude, ListPrice, StandardStatus, PropertyType, PropertySubType,
                StreetNumber, StreetName, UnitNumber, City, StateOrProvince, PostalCode,
                BedroomsTotal, BathroomsFull, BathroomsHalf, BathroomsTotalInteger, LivingArea, LotSizeAcres, YearBuilt, Media
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
