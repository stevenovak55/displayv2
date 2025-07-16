<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized data processor for normalized database operations
 * FIXED: Spatial data validation and improved transaction handling
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
     * FIXED: Process a batch of listings with improved transaction handling
     */
    public function process_listings_batch($extraction_id, $listings, $related_data = []) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage();
        
        $processed = 0;
        $errors = [];
        
        global $wpdb;
        
        // FIXED: Better transaction handling
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($listings as $listing) {
                try {
                    // FIXED: Validate required fields before processing
                    $validation_errors = $this->validate_listing_data($listing);
                    if (!empty($validation_errors)) {
                        throw new Exception('Validation failed: ' . implode(', ', $validation_errors));
                    }
                    
                    $this->process_single_listing($extraction_id, $listing, $related_data);
                    $processed++;
                    
                } catch (Exception $e) {
                    $errors[] = [
                        'listing_id' => $listing['ListingId'] ?? 'Unknown',
                        'listing_key' => $listing['ListingKey'] ?? 'Unknown',
                        'error' => $e->getMessage(),
                        'timestamp' => current_time('mysql')
                    ];
                    
                    // Log individual listing errors but continue processing
                    error_log("BME Listing Error: " . $e->getMessage());
                }
            }
            
            // Only commit if we processed at least some listings successfully
            if ($processed > 0) {
                $wpdb->query('COMMIT');
            } else {
                $wpdb->query('ROLLBACK');
                throw new Exception('No listings were successfully processed in this batch');
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("BME Batch Error: " . $e->getMessage());
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
            'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
            'error_count' => count($errors)
        ];
    }
    
    /**
     * FIXED: Add comprehensive input validation
     */
    private function validate_listing_data($listing) {
        $errors = [];
        
        // Required fields
        $required_fields = ['ListingKey', 'ListingId', 'StandardStatus'];
        foreach ($required_fields as $field) {
            if (empty($listing[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Numeric validations
        $numeric_fields = ['ListPrice', 'OriginalListPrice', 'ClosePrice', 'BedroomsTotal', 'BathroomsTotalInteger', 'LivingArea', 'YearBuilt'];
        foreach ($numeric_fields as $field) {
            if (isset($listing[$field]) && !empty($listing[$field]) && !is_numeric($listing[$field])) {
                $errors[] = "Invalid {$field}: must be numeric";
            }
        }
        
        // Price validations
        if (isset($listing['ListPrice']) && is_numeric($listing['ListPrice'])) {
            $price = floatval($listing['ListPrice']);
            if ($price < 0 || $price > 999999999) {
                $errors[] = "Invalid ListPrice: must be between 0 and 999,999,999";
            }
        }
        
        // FIXED: Coordinate validations
        if (isset($listing['Latitude']) && !empty($listing['Latitude'])) {
            $lat = floatval($listing['Latitude']);
            if ($lat < -90 || $lat > 90) {
                $errors[] = "Invalid Latitude: must be between -90 and 90";
            }
        }
        
        if (isset($listing['Longitude']) && !empty($listing['Longitude'])) {
            $lon = floatval($listing['Longitude']);
            if ($lon < -180 || $lon > 180) {
                $errors[] = "Invalid Longitude: must be between -180 and 180";
            }
        }
        
        // Date validations
        $date_fields = ['CreationTimestamp', 'ModificationTimestamp', 'CloseDate', 'ListingContractDate'];
        foreach ($date_fields as $field) {
            if (!empty($listing[$field]) && !$this->validate_date_format($listing[$field])) {
                $errors[] = "Invalid date format for {$field}";
            }
        }
        
        // Year built validation
        if (isset($listing['YearBuilt']) && !empty($listing['YearBuilt'])) {
            $year = intval($listing['YearBuilt']);
            $current_year = date('Y');
            if ($year < 1600 || $year > ($current_year + 5)) {
                $errors[] = "Invalid YearBuilt: must be between 1600 and " . ($current_year + 5);
            }
        }
        
        // String length validations
        $string_limits = [
            'ListingId' => 50,
            'ListingKey' => 128,
            'StandardStatus' => 50,
            'PropertyType' => 50,
            'City' => 100,
            'StateOrProvince' => 50
        ];
        
        foreach ($string_limits as $field => $max_length) {
            if (isset($listing[$field]) && strlen($listing[$field]) > $max_length) {
                $errors[] = "{$field} exceeds maximum length of {$max_length} characters";
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate date format
     */
    private function validate_date_format($date_string) {
        // Common MLS date formats
        $formats = ['Y-m-d\TH:i:s\Z', 'Y-m-d H:i:s', 'Y-m-d', 'm/d/Y', 'm-d-Y'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $date_string);
            if ($date && $date->format($format) === $date_string) {
                return true;
            }
        }
        
        return false;
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
        
        // Validate numeric fields
        $numeric_fields = ['list_price', 'original_list_price', 'close_price', 'bedrooms_total', 'bathrooms_total_integer', 'living_area', 'year_built', 'photos_count'];
        foreach ($numeric_fields as $field) {
            if (isset($data[$field]) && !is_numeric($data[$field])) {
                $data[$field] = null; // Set invalid numeric values to null
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
     * FIXED: Process listing location with spatial data validation
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
        
        // FIXED: Validate and handle spatial coordinates
        $lat = $listing['Latitude'] ?? null;
        $lon = $listing['Longitude'] ?? null;
        $point_sql = 'POINT(0 0)'; // Default fallback
        
        // Validate coordinates
        if (is_numeric($lat) && is_numeric($lon)) {
            $lat = floatval($lat);
            $lon = floatval($lon);
            
            // Check if coordinates are within valid ranges
            if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
                // Additional check for obviously invalid coordinates (0,0)
                if (!($lat == 0 && $lon == 0)) {
                    $point_sql = $wpdb->prepare('ST_PointFromText(%s)', "POINT({$lon} {$lat})");
                } else {
                    error_log("BME Warning: Suspicious coordinates (0,0) for listing {$listing_id}, using default");
                }
            } else {
                error_log("BME Warning: Invalid coordinates for listing {$listing_id}: lat={$lat}, lon={$lon}");
            }
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
                
                // Validate numeric fields
                if (in_array($db_field, ['bathrooms_full', 'bathrooms_half', 'stories_total', 'rooms_total', 'fireplaces_total', 'garage_spaces', 'parking_total', 'tax_year'])) {
                    if (!is_numeric($value)) {
                        $value = null;
                    }
                }
                
                // Validate decimal fields
                if (in_array($db_field, ['above_grade_finished_area', 'below_grade_finished_area', 'building_area_total', 'lot_size_acres', 'lot_size_square_feet', 'tax_annual_amount', 'tax_assessed_value', 'association_fee', 'security_deposit'])) {
                    if (!is_numeric($value)) {
                        $value = null;
                    }
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
     * Sanitize field values based on type with improved validation
     */
    private function sanitize_field_value($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if (is_string($value)) {
            // Remove any control characters and excessive whitespace
            $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value));
            
            // Limit string length to prevent database overflow
            if (strlen($value) > 65535) {
                $value = substr($value, 0, 65535);
                error_log('BME Warning: Truncated field value that exceeded 65535 characters');
            }
            
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
                   lft.*,
                   ST_X(ll.coordinates) as longitude_calculated,
                   ST_Y(ll.coordinates) as latitude_calculated
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
     * Advanced search with filters across normalized tables with improved security
     */
    public function search_listings($filters, $limit = 30, $offset = 0) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        $where_clauses = [];
        $join_clauses = [];
        $params = [];
        
        // Validate and sanitize limit and offset
        $limit = max(1, min(1000, absint($limit)));
        $offset = max(0, absint($offset));
        
        // Handle sorting parameters
        $orderby = 'l.modification_timestamp';
        $order = 'DESC';
        
        if (!empty($filters['orderby'])) {
            $valid_columns = [
                'listing_id' => 'l.listing_id',
                'standard_status' => 'l.standard_status',
                'property_type' => 'l.property_type', 
                'list_price' => 'l.list_price',
                'bedrooms_total' => 'l.bedrooms_total',
                'bathrooms_total_integer' => 'l.bathrooms_total_integer',
                'living_area' => 'l.living_area',
                'year_built' => 'l.year_built',
                'modification_timestamp' => 'l.modification_timestamp'
            ];
            
            if (isset($valid_columns[$filters['orderby']])) {
                $orderby = $valid_columns[$filters['orderby']];
            }
        }
        
        if (!empty($filters['order']) && strtoupper($filters['order']) === 'ASC') {
            $order = 'ASC';
        }
        
        // Build base query
        $sql = "SELECT l.*, ll.city, ll.state_or_province, ll.postal_code, 
                       ll.street_number, ll.street_name, ll.latitude, ll.longitude";
        
        $from = " FROM {$tables['listings']} l";
        $join_clauses[] = "LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
        
        // Process filters with proper validation
        foreach ($filters as $field => $value) {
            if (empty($value) || in_array($field, ['orderby', 'order'])) continue;
            
            switch ($field) {
                case 'standard_status':
                    if (is_array($value)) {
                        $valid_statuses = ['Active', 'Active Under Contract', 'Pending', 'Closed', 'Expired', 'Withdrawn', 'Canceled'];
                        $value = array_intersect($value, $valid_statuses);
                        if (!empty($value)) {
                            $placeholders = implode(',', array_fill(0, count($value), '%s'));
                            $where_clauses[] = "l.standard_status IN ({$placeholders})";
                            $params = array_merge($params, $value);
                        }
                    } else {
                        $where_clauses[] = "l.standard_status = %s";
                        $params[] = sanitize_text_field($value);
                    }
                    break;
                    
                case 'property_type':
                    $where_clauses[] = "l.property_type = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'city':
                    $where_clauses[] = "ll.city = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'price_min':
                    $price = floatval($value);
                    if ($price > 0) {
                        $where_clauses[] = "l.list_price >= %f";
                        $params[] = $price;
                    }
                    break;
                    
                case 'price_max':
                    $price = floatval($value);
                    if ($price > 0) {
                        $where_clauses[] = "l.list_price <= %f";
                        $params[] = $price;
                    }
                    break;
                    
                case 'bedrooms_min':
                    $bedrooms = absint($value);
                    if ($bedrooms > 0) {
                        $where_clauses[] = "l.bedrooms_total >= %d";
                        $params[] = $bedrooms;
                    }
                    break;
                    
                case 'bathrooms_min':
                    $bathrooms = absint($value);
                    if ($bathrooms > 0) {
                        $where_clauses[] = "l.bathrooms_total_integer >= %d";
                        $params[] = $bathrooms;
                    }
                    break;
                    
                case 'year_built_min':
                    $year = absint($value);
                    if ($year >= 1600 && $year <= date('Y')) {
                        $where_clauses[] = "l.year_built >= %d";
                        $params[] = $year;
                    }
                    break;
                    
                case 'year_built_max':
                    $year = absint($value);
                    if ($year >= 1600 && $year <= date('Y')) {
                        $where_clauses[] = "l.year_built <= %d";
                        $params[] = $year;
                    }
                    break;
                    
                case 'listing_id':
                    $where_clauses[] = "l.listing_id = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'radius_search':
                    if (isset($value['lat']) && isset($value['lng']) && isset($value['radius'])) {
                        $lat = floatval($value['lat']);
                        $lng = floatval($value['lng']);
                        $radius = floatval($value['radius']);
                        
                        // Validate coordinates and radius
                        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && $radius > 0 && $radius <= 1000) {
                            $where_clauses[] = "ST_Distance_Sphere(ll.coordinates, ST_PointFromText(%s)) <= %d";
                            $params[] = "POINT({$lng} {$lat})";
                            $params[] = $radius * 1609.34; // Convert miles to meters
                        }
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
        $sql .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
    
    /**
     * Get search result count with the same validation as search_listings
     */
    public function get_search_count($filters) {
        global $wpdb;
        
        $tables = $this->db_manager->get_tables();
        $where_clauses = [];
        $params = [];
        
        // Use same filter logic as search_listings but for COUNT
        $sql = "SELECT COUNT(DISTINCT l.id) FROM {$tables['listings']} l";
        $sql .= " LEFT JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id";
        
        // Process filters (same validation logic as search_listings)
        foreach ($filters as $field => $value) {
            if (empty($value)) continue;
            
            switch ($field) {
                case 'standard_status':
                    if (is_array($value)) {
                        $valid_statuses = ['Active', 'Active Under Contract', 'Pending', 'Closed', 'Expired', 'Withdrawn', 'Canceled'];
                        $value = array_intersect($value, $valid_statuses);
                        if (!empty($value)) {
                            $placeholders = implode(',', array_fill(0, count($value), '%s'));
                            $where_clauses[] = "l.standard_status IN ({$placeholders})";
                            $params = array_merge($params, $value);
                        }
                    } else {
                        $where_clauses[] = "l.standard_status = %s";
                        $params[] = sanitize_text_field($value);
                    }
                    break;
                    
                case 'property_type':
                    $where_clauses[] = "l.property_type = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'city':
                    $where_clauses[] = "ll.city = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'price_min':
                    $price = floatval($value);
                    if ($price > 0) {
                        $where_clauses[] = "l.list_price >= %f";
                        $params[] = $price;
                    }
                    break;
                    
                case 'price_max':
                    $price = floatval($value);
                    if ($price > 0) {
                        $where_clauses[] = "l.list_price <= %f";
                        $params[] = $price;
                    }
                    break;
                    
                case 'bedrooms_min':
                    $bedrooms = absint($value);
                    if ($bedrooms > 0) {
                        $where_clauses[] = "l.bedrooms_total >= %d";
                        $params[] = $bedrooms;
                    }
                    break;
                    
                case 'bathrooms_min':
                    $bathrooms = absint($value);
                    if ($bathrooms > 0) {
                        $where_clauses[] = "l.bathrooms_total_integer >= %d";
                        $params[] = $bathrooms;
                    }
                    break;
                    
                case 'listing_id':
                    $where_clauses[] = "l.listing_id = %s";
                    $params[] = sanitize_text_field($value);
                    break;
                    
                case 'radius_search':
                    if (isset($value['lat']) && isset($value['lng']) && isset($value['radius'])) {
                        $lat = floatval($value['lat']);
                        $lng = floatval($value['lng']);
                        $radius = floatval($value['radius']);
                        
                        // Validate coordinates and radius
                        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && $radius > 0 && $radius <= 1000) {
                            $where_clauses[] = "ST_Distance_Sphere(ll.coordinates, ST_PointFromText(%s)) <= %d";
                            $params[] = "POINT({$lng} {$lat})";
                            $params[] = $radius * 1609.34; // Convert miles to meters
                        }
                    }
                    break;
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
     * Clear data for specific extraction with improved safety
     */
    public function clear_extraction_data($extraction_id) {
        global $wpdb;
        
        // Validate extraction_id
        $extraction_id = absint($extraction_id);
        if (!$extraction_id) {
            return 0;
        }
        
        $tables = $this->db_manager->get_tables();
        
        // Start transaction for data consistency
        $wpdb->query('START TRANSACTION');
        
        try {
            // Delete from listings table (cascading will handle related tables)
            $result = $wpdb->delete($tables['listings'], ['extraction_id' => $extraction_id]);
            
            if ($result === false) {
                throw new Exception('Failed to delete listings data');
            }
            
            $wpdb->query('COMMIT');
            
            // Clear related caches
            $this->cache_manager->invalidate_listing_caches();
            
            return $wpdb->rows_affected;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('BME Clear Data Error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get extraction statistics with better error handling
     */
    public function get_extraction_stats($extraction_id) {
        global $wpdb;
        
        // Validate extraction_id
        $extraction_id = absint($extraction_id);
        if (!$extraction_id) {
            return null;
        }
        
        $table = $this->db_manager->get_table('listings');
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_listings,
                COUNT(DISTINCT standard_status) as unique_statuses,
                COUNT(DISTINCT property_type) as unique_types,
                MIN(creation_timestamp) as oldest_listing,
                MAX(modification_timestamp) as newest_update,
                AVG(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as avg_price,
                MIN(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as min_price,
                MAX(CASE WHEN list_price > 0 THEN list_price ELSE NULL END) as max_price
            FROM {$table} 
            WHERE extraction_id = %d
        ", $extraction_id), ARRAY_A);
        
        // Convert numeric values properly
        if ($stats) {
            $stats['total_listings'] = intval($stats['total_listings']);
            $stats['unique_statuses'] = intval($stats['unique_statuses']);
            $stats['unique_types'] = intval($stats['unique_types']);
            $stats['avg_price'] = floatval($stats['avg_price']);
            $stats['min_price'] = floatval($stats['min_price']);
            $stats['max_price'] = floatval($stats['max_price']);
        }
        
        return $stats;
    }
    
    /**
     * Get listings by geographic bounds (for mapping applications)
     */
    public function get_listings_by_bounds($north, $south, $east, $west, $limit = 100) {
        global $wpdb;
        
        // Validate coordinates
        $north = floatval($north);
        $south = floatval($south);
        $east = floatval($east);
        $west = floatval($west);
        $limit = max(1, min(1000, absint($limit)));
        
        if ($north < -90 || $north > 90 || $south < -90 || $south > 90 || 
            $east < -180 || $east > 180 || $west < -180 || $west > 180) {
            return [];
        }
        
        $tables = $this->db_manager->get_tables();
        
        $sql = "
            SELECT l.id, l.listing_id, l.list_price, l.standard_status, l.property_type,
                   ll.latitude, ll.longitude, ll.city, ll.unparsed_address
            FROM {$tables['listings']} l
            JOIN {$tables['listing_location']} ll ON l.id = ll.listing_id
            WHERE ll.latitude BETWEEN %f AND %f
            AND ll.longitude BETWEEN %f AND %f
            AND l.standard_status = 'Active'
            AND l.list_price > 0
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($sql, $south, $north, $west, $east, $limit), ARRAY_A);
    }
}