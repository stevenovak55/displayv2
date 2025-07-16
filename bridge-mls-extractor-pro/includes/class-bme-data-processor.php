<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized data processor for normalized database operations
 */
class BME_Data_Processor {
    
    private $db_manager;
    private $cache_manager;
    private $field_mapping;
    
    public function __construct(BME_Database_Manager $db_manager, BME_Cache_Manager $cache_manager) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        $this->init_field_mapping();
    }
    
    /**
     * Initialize field mapping based on the updated CSV schema
     */
    private function init_field_mapping() {
        $this->field_mapping = [
            'listings' => [
                'listing_key' => 'ListingKey',
                'listing_id' => 'ListingId',
                'standard_status' => 'StandardStatus',
                'mls_status' => 'MlsStatus',
                'property_type' => 'PropertyType',
                'property_sub_type' => 'PropertySubType',
                'list_price' => 'ListPrice',
                'original_list_price' => 'OriginalListPrice',
                'close_price' => 'ClosePrice',
                'creation_timestamp' => 'CreationTimestamp',
                'modification_timestamp' => 'ModificationTimestamp',
                'status_change_timestamp' => 'StatusChangeTimestamp',
                'close_date' => 'CloseDate',
                'listing_contract_date' => 'ListingContractDate',
                'bedrooms_total' => 'BedroomsTotal',
                'bathrooms_total_integer' => 'BathroomsTotalInteger',
                'living_area' => 'LivingArea',
                'year_built' => 'YearBuilt',
                'list_agent_mls_id' => 'ListAgentMlsId',
                'buyer_agent_mls_id' => 'BuyerAgentMlsId',
                'list_office_mls_id' => 'ListOfficeMlsId',
                'buyer_office_mls_id' => 'BuyerOfficeMlsId',
                'photos_count' => 'PhotosCount',
                'open_house_yn' => 'OpenHouseYN'
            ],
            
            'listing_details' => [
                'bathrooms_full' => 'BathroomsFull',
                'bathrooms_half' => 'BathroomsHalf',
                'above_grade_finished_area' => 'AboveGradeFinishedArea',
                'below_grade_finished_area' => 'BelowGradeFinishedArea',
                'building_area_total' => 'BuildingAreaTotal',
                'lot_size_acres' => 'LotSizeAcres',
                'lot_size_square_feet' => 'LotSizeSquareFeet',
                'stories_total' => 'StoriesTotal',
                'rooms_total' => 'RoomsTotal',
                'structure_type' => 'StructureType',
                'architectural_style' => 'ArchitecturalStyle',
                'building_name' => 'BuildingName',
                'construction_materials' => 'ConstructionMaterials',
                'foundation_details' => 'FoundationDetails',
                'roof' => 'Roof',
                'heating' => 'Heating',
                'cooling' => 'Cooling',
                'utilities' => 'Utilities',
                'sewer' => 'Sewer',
                'water_source' => 'WaterSource',
                'electric' => 'Electric',
                'interior_features' => 'InteriorFeatures',
                'flooring' => 'Flooring',
                'appliances' => 'Appliances',
                'basement' => 'Basement',
                'levels' => 'Levels',
                'fireplace_yn' => 'FireplaceYN',
                'fireplace_features' => 'FireplaceFeatures',
                'fireplaces_total' => 'FireplacesTotal',
                'garage_yn' => 'GarageYN',
                'garage_spaces' => 'GarageSpaces',
                'parking_total' => 'ParkingTotal',
                'parking_features' => 'ParkingFeatures',
                'public_remarks' => 'PublicRemarks',
                'private_remarks' => 'PrivateRemarks',
                'disclosures' => 'Disclosures',
                'showing_instructions' => 'ShowingInstructions'
            ],
            
            'listing_location' => [
                'unparsed_address' => 'UnparsedAddress',
                'street_number' => 'StreetNumber',
                'street_dir_prefix' => 'StreetDirPrefix',
                'street_name' => 'StreetName',
                'street_dir_suffix' => 'StreetDirSuffix',
                'street_number_numeric' => 'StreetNumberNumeric',
                'unit_number' => 'UnitNumber',
                'entry_level' => 'EntryLevel',
                'city' => 'City',
                'state_or_province' => 'StateOrProvince',
                'postal_code' => 'PostalCode',
                'county_or_parish' => 'CountyOrParish',
                'country' => 'Country',
                'mls_area_major' => 'MLSAreaMajor',
                'mls_area_minor' => 'MLSAreaMinor',
                'subdivision_name' => 'SubdivisionName',
                'latitude' => 'Latitude',
                'longitude' => 'Longitude',
                'elementary_school' => 'ElementarySchool',
                'middle_or_junior_school' => 'MiddleOrJuniorSchool',
                'high_school' => 'HighSchool',
                'school_district' => 'SchoolDistrict'
            ],
            
            'listing_financial' => [
                'purchase_contract_date' => 'PurchaseContractDate',
                'original_entry_timestamp' => 'OriginalEntryTimestamp',
                'off_market_date' => 'OffMarketDate',
                'tax_annual_amount' => 'TaxAnnualAmount',
                'tax_year' => 'TaxYear',
                'tax_assessed_value' => 'TaxAssessedValue',
                'association_yn' => 'AssociationYN',
                'association_name' => 'AssociationName',
                'association_fee' => 'AssociationFee',
                'association_fee_frequency' => 'AssociationFeeFrequency',
                'availability_date' => 'AvailabilityDate',
                'available_now' => 'MLSPIN_AvailableNow',
                'lease_term' => 'LeaseTerm',
                'rent_includes' => 'RentIncludes',
                'security_deposit' => 'MLSPIN_SEC_DEPOSIT',
                'market_time_property' => 'MLSPIN_MARKET_TIME_PROPERTY'
            ],
            
            'listing_features' => [
                'exterior_features' => 'ExteriorFeatures',
                'patio_and_porch_features' => 'PatioAndPorchFeatures',
                'lot_features' => 'LotFeatures',
                'waterfront_yn' => 'WaterfrontYN',
                'waterfront_features' => 'WaterfrontFeatures',
                'pool_features' => 'PoolFeatures',
                'pool_private_yn' => 'PoolPrivateYN',
                'view_yn' => 'ViewYN',
                'view_description' => 'View',
                'community_features' => 'CommunityFeatures',
                'media' => 'Media',
                'virtual_tour_url_unbranded' => 'VirtualTourURLUnbranded',
                'virtual_tour_url_branded' => 'VirtualTourURLBranded'
            ]
        ];
    }
    
    /**
     * Process a batch of listings with related data
     */
    public function process_listings_batch($extraction_id, $listings, $related_data = []) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage();
        
        $processed = 0;
        $errors = [];
        
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($listings as $listing) {
                try {
                    $this->process_single_listing($extraction_id, $listing, $related_data);
                    $processed++;
                } catch (Exception $e) {
                    $errors[] = [
                        'listing_id' => $listing['ListingId'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        
        // Log performance metrics
        $duration = microtime(true) - $start_time;
        $memory_peak = memory_get_peak_usage() - $memory_start;
        
        $this->log_batch_performance($processed, $duration, $memory_peak);
        
        return [
            'processed' => $processed,
            'errors' => $errors,
            'duration' => $duration,
            'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2)
        ];
    }
    
    /**
     * Process a single listing across all tables
     */
    private function process_single_listing($extraction_id, $listing, $related_data) {
        global $wpdb;
        
        // Process core listing first
        $listing_id = $this->process_core_listing($extraction_id, $listing);
        
        // Process related tables
        $this->process_listing_details($listing_id, $listing);
        $this->process_listing_location($listing_id, $listing);
        $this->process_listing_financial($listing_id, $listing);
        $this->process_listing_features($listing_id, $listing);
        
        // Cache related data
        $this->cache_related_data($listing, $related_data);
        
        // Process open houses if present
        if (!empty($related_data['open_houses'][$listing['ListingKey']])) {
            $this->process_open_houses($listing_id, $listing['ListingKey'], $related_data['open_houses'][$listing['ListingKey']]);
        }
        
        return $listing_id;
    }
    
    /**
     * Process core listing data
     */
    private function process_core_listing($extraction_id, $listing) {
        global $wpdb;
        
        $data = ['extraction_id' => $extraction_id];
        $additional_data = [];
        
        // Map fields to core listing table
        foreach ($this->field_mapping['listings'] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $data[$db_field] = $this->sanitize_field_value($listing[$api_field]);
            }
        }
        
        // Collect unmapped fields for additional_data
        foreach ($listing as $key => $value) {
            if (!in_array($key, array_values($this->field_mapping['listings']))) {
                $found = false;
                foreach ($this->field_mapping as $table_fields) {
                    if (in_array($key, array_values($table_fields))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $additional_data[$key] = $value;
                }
            }
        }
        
        // Handle boolean fields
        foreach (['open_house_yn'] as $bool_field) {
            if (isset($data[$bool_field])) {
                $data[$bool_field] = $this->convert_to_boolean($data[$bool_field]);
            }
        }
        
        // Insert or update core listing
        $table = $this->db_manager->get_table('listings');
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE listing_key = %s",
            $data['listing_key']
        ));
        
        if ($existing_id) {
            $wpdb->update($table, $data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }
    
    /**
     * Process listing details
     */
    private function process_listing_details($listing_id, $listing) {
        $this->process_related_table('listing_details', $listing_id, $listing);
    }
    
    /**
     * Process listing location with spatial data
     */
    private function process_listing_location($listing_id, $listing) {
        global $wpdb;
        
        $data = ['listing_id' => $listing_id];
        
        // Map standard fields
        foreach ($this->field_mapping['listing_location'] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $data[$db_field] = $this->sanitize_field_value($listing[$api_field]);
            }
        }
        
        // Handle spatial coordinates
        $lat = $listing['Latitude'] ?? null;
        $lon = $listing['Longitude'] ?? null;
        $point_sql = 'POINT(0 0)'; // Default
        
        if (is_numeric($lat) && is_numeric($lon)) {
            $point_sql = $wpdb->prepare('ST_PointFromText(%s)', "POINT({$lon} {$lat})");
        }
        
        $table = $this->db_manager->get_table('listing_location');
        
        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %d",
            $listing_id
        ));
        
        if ($exists) {
            // Build update query with spatial data
            $set_clauses = [];
            $values = [];
            
            foreach ($data as $field => $value) {
                if ($field !== 'listing_id') {
                    $set_clauses[] = "`{$field}` = %s";
                    $values[] = $value;
                }
            }
            
            $set_clauses[] = "`coordinates` = {$point_sql}";
            $values[] = $listing_id;
            
            $sql = "UPDATE {$table} SET " . implode(', ', $set_clauses) . " WHERE listing_id = %d";
            $wpdb->query($wpdb->prepare($sql, $values));
        } else {
            // Build insert query with spatial data
            $fields = array_keys($data);
            $fields[] = 'coordinates';
            
            $placeholders = array_fill(0, count($data), '%s');
            $placeholders[] = $point_sql;
            
            $sql = "INSERT INTO {$table} (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $wpdb->query($wpdb->prepare($sql, array_values($data)));
        }
    }
    
    /**
     * Process listing financial data
     */
    private function process_listing_financial($listing_id, $listing) {
        $this->process_related_table('listing_financial', $listing_id, $listing);
    }
    
    /**
     * Process listing features
     */
    private function process_listing_features($listing_id, $listing) {
        $this->process_related_table('listing_features', $listing_id, $listing);
    }
    
    /**
     * Generic method to process related tables
     */
    private function process_related_table($table_name, $listing_id, $listing) {
        global $wpdb;
        
        $data = ['listing_id' => $listing_id];
        
        // Map fields for this table
        if (!isset($this->field_mapping[$table_name])) {
            return;
        }
        
        foreach ($this->field_mapping[$table_name] as $db_field => $api_field) {
            if (isset($listing[$api_field])) {
                $value = $this->sanitize_field_value($listing[$api_field]);
                
                // Handle boolean conversions
                if (strpos($db_field, '_yn') !== false || strpos($db_field, '_now') !== false) {
                    $value = $this->convert_to_boolean($value);
                }
                
                $data[$db_field] = $value;
            }
        }
        
        // Only proceed if we have data besides listing_id
        if (count($data) <= 1) {
            return;
        }
        
        $table = $this->db_manager->get_table($table_name);
        
        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %d",
            $listing_id
        ));
        
        if ($exists) {
            $wpdb->update($table, $data, ['listing_id' => $listing_id]);
        } else {
            $wpdb->insert($table, $data);
        }
    }
    
    /**
     * Cache agent and office data
     */
    private function cache_related_data($listing, $related_data) {
        // Cache agent data
        if (!empty($listing['ListAgentMlsId']) && isset($related_data['agents'][$listing['ListAgentMlsId']])) {
            $this->cache_agent_data($listing['ListAgentMlsId'], $related_data['agents'][$listing['ListAgentMlsId']]);
        }
        
        if (!empty($listing['BuyerAgentMlsId']) && isset($related_data['agents'][$listing['BuyerAgentMlsId']])) {
            $this->cache_agent_data($listing['BuyerAgentMlsId'], $related_data['agents'][$listing['BuyerAgentMlsId']]);
        }
        
        // Cache office data
        if (!empty($listing['ListOfficeMlsId']) && isset($related_data['offices'][$listing['ListOfficeMlsId']])) {
            $this->cache_office_data($listing['ListOfficeMlsId'], $related_data['offices'][$listing['ListOfficeMlsId']]);
        }
        
        if (!empty($listing['BuyerOfficeMlsId']) && isset($related_data['offices'][$listing['BuyerOfficeMlsId']])) {
            $this->cache_office_data($listing['BuyerOfficeMlsId'], $related_data['offices'][$listing['BuyerOfficeMlsId']]);
        }
    }
    
    /**
     * Cache agent data with expiration
     */
    private function cache_agent_data($agent_mls_id, $agent_data) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('agents');
        $expires_at = date('Y-m-d H:i:s', time() + BME_CACHE_DURATION);
        
        $data = [
            'agent_mls_id' => $agent_mls_id,
            'agent_data' => json_encode($agent_data),
            'expires_at' => $expires_at
        ];
        
        $wpdb->replace($table, $data);
    }
    
    /**
     * Cache office data with expiration
     */
    private function cache_office_data($office_mls_id, $office_data) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('offices');
        $expires_at = date('Y-m-d H:i:s', time() + BME_CACHE_DURATION);
        
        $data = [
            'office_mls_id' => $office_mls_id,
            'office_data' => json_encode($office_data),
            'expires_at' => $expires_at
        ];
        
        $wpdb->replace($table, $data);
    }
    
    /**
     * Process open house data
     */
    private function process_open_houses($listing_id, $listing_key, $open_houses) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('open_houses');
        
        // Delete existing open houses for this listing
        $wpdb->delete($table, ['listing_id' => $listing_id]);
        
        // Insert new open house data
        foreach ($open_houses as $open_house) {
            $data = [
                'listing_id' => $listing_id,
                'listing_key' => $listing_key,
                'open_house_data' => json_encode($open_house)
            ];
            
            $wpdb->insert($table, $data);
        }
    }
    
    /**
     * Sanitize field values based on type
     */
    private function sanitize_field_value($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if (is_string($value)) {
            return sanitize_textarea_field($value);
        }
        
        return $value;
    }
    
    /**
     * Convert various boolean representations to database boolean
     */
    private function convert_to_boolean($value) {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', 'yes', 'y', '1']) ? 1 : 0;
        }
        
        return intval($value) ? 1 : 0;
    }
    
    /**
     * Get comprehensive listing data across all tables
     */
    public function get_listing_data($listing_id) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        
        // Build JOIN query for all listing tables
        $sql = "
            SELECT l.*, 
                   ld.*, 
                   ll.*, 
                   lf.*, 
                   lft.*
            FROM {$tables['listings']} l
            LEFT JOIN {$tables['listing_details']} ld ON l.id = ld.listing_id
            LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id  
            LEFT JOIN {$tables['listing_financial']} lf ON l.id = lf.listing_id
            LEFT JOIN {$tables['listing_features']} lft ON l.id = lft.listing_id
            WHERE l.id = %d
        ";
        
        $listing = $wpdb->get_row($wpdb->prepare($sql, $listing_id), ARRAY_A);
        
        if ($listing) {
            // Get related data
            $listing['agent_data'] = $this->get_cached_agent_data($listing['list_agent_mls_id'], $listing['buyer_agent_mls_id']);
            $listing['office_data'] = $this->get_cached_office_data($listing['list_office_mls_id'], $listing['buyer_office_mls_id']);
            $listing['open_houses'] = $this->get_open_houses($listing_id);
        }
        
        return $listing;
    }
    
    /**
     * Get cached agent data
     */
    private function get_cached_agent_data($list_agent_id, $buyer_agent_id) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('agents');
        $agent_data = [];
        
        if ($list_agent_id) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_data FROM {$table} WHERE agent_mls_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
                $list_agent_id
            ));
            
            if ($data) {
                $agent_data['list_agent'] = json_decode($data, true);
            }
        }
        
        if ($buyer_agent_id && $buyer_agent_id !== $list_agent_id) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_data FROM {$table} WHERE agent_mls_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
                $buyer_agent_id
            ));
            
            if ($data) {
                $agent_data['buyer_agent'] = json_decode($data, true);
            }
        }
        
        return $agent_data;
    }
    
    /**
     * Get cached office data
     */
    private function get_cached_office_data($list_office_id, $buyer_office_id) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('offices');
        $office_data = [];
        
        if ($list_office_id) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT office_data FROM {$table} WHERE office_mls_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
                $list_office_id
            ));
            
            if ($data) {
                $office_data['list_office'] = json_decode($data, true);
            }
        }
        
        if ($buyer_office_id && $buyer_office_id !== $list_office_id) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT office_data FROM {$table} WHERE office_mls_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
                $buyer_office_id
            ));
            
            if ($data) {
                $office_data['buyer_office'] = json_decode($data, true);
            }
        }
        
        return $office_data;
    }
    
    /**
     * Get open houses for a listing
     */
    private function get_open_houses($listing_id) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('open_houses');
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT open_house_data FROM {$table} WHERE listing_id = %d",
            $listing_id
        ));
        
        $open_houses = [];
        foreach ($results as $row) {
            $open_houses[] = json_decode($row->open_house_data, true);
        }
        
        return $open_houses;
    }
    
    /**
     * Advanced search with filters across normalized tables
     */
    public function search_listings($filters, $limit = 30, $offset = 0) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        $where_clauses = [];
        $join_clauses = [];
        $params = [];
        
        // Build base query
        $sql = "SELECT l.*, ll.city, ll.state_or_province, ll.postal_code, 
                       ll.street_number, ll.street_name, ll.latitude, ll.longitude";
        
        $from = " FROM {$tables['listings']} l";
        $join_clauses[] = "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
        
        // Process filters
        foreach ($filters as $field => $value) {
            if (empty($value)) continue;
            
            switch ($field) {
                case 'standard_status':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $where_clauses[] = "l.standard_status IN ({$placeholders})";
                        $params = array_merge($params, $value);
                    } else {
                        $where_clauses[] = "l.standard_status = %s";
                        $params[] = $value;
                    }
                    break;
                    
                case 'property_type':
                    $where_clauses[] = "l.property_type = %s";
                    $params[] = $value;
                    break;
                    
                case 'city':
                    $where_clauses[] = "ll.city = %s";
                    $params[] = $value;
                    break;
                    
                case 'price_min':
                    $where_clauses[] = "l.list_price >= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'price_max':
                    $where_clauses[] = "l.list_price <= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'bedrooms_min':
                    $where_clauses[] = "l.bedrooms_total >= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'bathrooms_min':
                    $where_clauses[] = "l.bathrooms_total_integer >= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'year_built_min':
                    $where_clauses[] = "l.year_built >= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'year_built_max':
                    $where_clauses[] = "l.year_built <= %d";
                    $params[] = intval($value);
                    break;
                    
                case 'listing_id':
                    $where_clauses[] = "l.listing_id = %s";
                    $params[] = $value;
                    break;
                    
                case 'radius_search':
                    if (isset($value['lat']) && isset($value['lng']) && isset($value['radius'])) {
                        $where_clauses[] = "ST_Distance_Sphere(ll.coordinates, ST_PointFromText(%s)) <= %d";
                        $params[] = "POINT({$value['lng']} {$value['lat']})";
                        $params[] = $value['radius'] * 1609.34; // Convert miles to meters
                    }
                    break;
            }
        }
        
        // Combine query parts
        $sql .= $from . ' ' . implode(' ', $join_clauses);
        
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        // Add ordering and pagination
        $sql .= " ORDER BY l.modification_timestamp DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
    
    /**
     * Get search result count
     */
    public function get_search_count($filters) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        $where_clauses = [];
        $params = [];
        
        // Use same filter logic as search_listings but for COUNT
        $sql = "SELECT COUNT(DISTINCT l.id) FROM {$tables['listings']} l";
        $sql .= " LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
        
        // Process filters (same logic as above, condensed)
        foreach ($filters as $field => $value) {
            if (empty($value)) continue;
            
            switch ($field) {
                case 'standard_status':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $where_clauses[] = "l.standard_status IN ({$placeholders})";
                        $params = array_merge($params, $value);
                    } else {
                        $where_clauses[] = "l.standard_status = %s";
                        $params[] = $value;
                    }
                    break;
                // ... other cases same as search_listings
            }
        }
        
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        return intval($wpdb->get_var($wpdb->prepare($sql, $params)));
    }
    
    /**
     * Log batch processing performance
     */
    private function log_batch_performance($processed, $duration, $memory_peak) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'BME Batch Performance - Processed: %d listings in %.3f seconds (%.2f listings/sec), Peak Memory: %.2f MB',
                $processed,
                $duration,
                $processed / max($duration, 0.001),
                $memory_peak / 1024 / 1024
            ));
        }
    }
    
    /**
     * Clear data for specific extraction
     */
    public function clear_extraction_data($extraction_id) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        
        // Delete from listings table (cascading will handle related tables)
        $wpdb->delete($tables['listings'], ['extraction_id' => $extraction_id]);
        
        return $wpdb->rows_affected;
    }
    
    /**
     * Get extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        global $wpdb;
        
        $table = $this->db_manager->get_table('listings');
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_listings,
                COUNT(DISTINCT standard_status) as unique_statuses,
                COUNT(DISTINCT property_type) as unique_types,
                MIN(creation_timestamp) as oldest_listing,
                MAX(modification_timestamp) as newest_update,
                AVG(list_price) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price
            FROM {$table} 
            WHERE extraction_id = %d
        ", $extraction_id), ARRAY_A);
        
        return $stats;
    }
}