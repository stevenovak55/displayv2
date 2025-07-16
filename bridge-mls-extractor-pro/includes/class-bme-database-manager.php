<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized database manager with normalized schema
 * FIXED: Improved spatial index handling and table verification
 */
class BME_Database_Manager {
    
    private $wpdb;
    private $charset_collate;
    private $tables = [];
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
        $this->init_table_names();
    }
    
    /**
     * Initialize table names
     */
    private function init_table_names() {
        $this->tables = [
            'listings' => $this->wpdb->prefix . 'bme_listings',
            'listing_details' => $this->wpdb->prefix . 'bme_listing_details', 
            'listing_location' => $this->wpdb->prefix . 'bme_listing_location',
            'listing_financial' => $this->wpdb->prefix . 'bme_listing_financial',
            'listing_features' => $this->wpdb->prefix . 'bme_listing_features',
            'agents' => $this->wpdb->prefix . 'bme_agents',
            'offices' => $this->wpdb->prefix . 'bme_offices',
            'open_houses' => $this->wpdb->prefix . 'bme_open_houses',
            'extraction_logs' => $this->wpdb->prefix . 'bme_extraction_logs'
        ];
    }
    
    /**
     * Create all database tables with better error handling
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        try {
            $this->create_listings_table();
            $this->create_listing_details_table();
            $this->create_listing_location_table();
            $this->create_listing_financial_table();
            $this->create_listing_features_table();
            $this->create_agents_table();
            $this->create_offices_table();
            $this->create_open_houses_table();
            $this->create_extraction_logs_table();
            
            $this->create_indexes();
            
            // Verify all tables were created successfully
            $this->verify_installation();
            
        } catch (Exception $e) {
            error_log('BME Database Creation Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Core listings table - essential fields only
     */
    private function create_listings_table() {
        $sql = "CREATE TABLE {$this->tables['listings']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            
            -- Status & Type
            standard_status VARCHAR(50) NOT NULL,
            mls_status VARCHAR(50),
            property_type VARCHAR(50) NOT NULL,
            property_sub_type VARCHAR(50),
            
            -- Core Pricing
            list_price DECIMAL(20,2),
            original_list_price DECIMAL(20,2),
            close_price DECIMAL(20,2),
            
            -- Key Timestamps  
            creation_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            modification_timestamp DATETIME,
            status_change_timestamp DATETIME,
            close_date DATETIME,
            listing_contract_date DATE,
            
            -- Basic Property Info
            bedrooms_total INT,
            bathrooms_total_integer INT,
            living_area DECIMAL(14,2),
            year_built INT,
            
            -- Agent References
            list_agent_mls_id VARCHAR(50),
            buyer_agent_mls_id VARCHAR(50),
            list_office_mls_id VARCHAR(50),
            buyer_office_mls_id VARCHAR(50),
            
            -- Quick Access Fields
            photos_count INT DEFAULT 0,
            open_house_yn BOOLEAN DEFAULT FALSE,
            
            -- Administrative
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_listing_key (listing_key),
            KEY idx_extraction (extraction_id),
            KEY idx_listing_id (listing_id),
            KEY idx_status (standard_status),
            KEY idx_type (property_type),
            KEY idx_price (list_price),
            KEY idx_status_type (standard_status, property_type),
            KEY idx_timestamps (modification_timestamp, creation_timestamp),
            KEY idx_agents (list_agent_mls_id, buyer_agent_mls_id)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Extended listing details
     */
    private function create_listing_details_table() {
        $sql = "CREATE TABLE {$this->tables['listing_details']} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Extended Property Characteristics
            bathrooms_full INT,
            bathrooms_half INT,
            above_grade_finished_area DECIMAL(14,2),
            below_grade_finished_area DECIMAL(14,2),
            building_area_total DECIMAL(14,2),
            lot_size_acres DECIMAL(20,4),
            lot_size_square_feet DECIMAL(20,2),
            stories_total INT,
            rooms_total INT,
            
            -- Construction Details
            structure_type VARCHAR(100),
            architectural_style VARCHAR(100),
            building_name VARCHAR(100),
            construction_materials LONGTEXT,
            foundation_details LONGTEXT,
            roof LONGTEXT,
            
            -- Utilities & Systems
            heating LONGTEXT,
            cooling LONGTEXT,
            utilities LONGTEXT,
            sewer LONGTEXT,
            water_source LONGTEXT,
            electric LONGTEXT,
            
            -- Interior Features
            interior_features LONGTEXT,
            flooring LONGTEXT,
            appliances LONGTEXT,
            basement LONGTEXT,
            levels LONGTEXT,
            
            -- Fireplace
            fireplace_yn BOOLEAN DEFAULT FALSE,
            fireplace_features LONGTEXT,
            fireplaces_total INT,
            
            -- Parking
            garage_yn BOOLEAN DEFAULT FALSE,
            garage_spaces INT,
            parking_total INT,
            parking_features LONGTEXT,
            
            -- Remarks
            public_remarks LONGTEXT,
            private_remarks LONGTEXT,
            disclosures LONGTEXT,
            showing_instructions TEXT,
            
            PRIMARY KEY (listing_id),
            FOREIGN KEY (listing_id) REFERENCES {$this->tables['listings']}(id) ON DELETE CASCADE,
            FULLTEXT KEY ft_remarks (public_remarks, private_remarks),
            KEY idx_structure_type (structure_type),
            KEY idx_building_name (building_name)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * FIXED: Location and geographic data with improved spatial handling
     */
    private function create_listing_location_table() {
        // Check if MySQL version supports spatial features
        $mysql_version = $this->wpdb->get_var("SELECT VERSION()");
        $supports_spatial = version_compare($mysql_version, '5.7.0', '>=');
        
        $spatial_field = $supports_spatial ? 
            'coordinates POINT NOT NULL DEFAULT (POINT(0, 0))' : 
            'coordinates_lat DECIMAL(10,8), coordinates_lng DECIMAL(11,8)';
            
        $spatial_index = $supports_spatial ? 
            'SPATIAL KEY spatial_coordinates (coordinates)' : 
            'KEY idx_coordinates (coordinates_lat, coordinates_lng)';
        
        $sql = "CREATE TABLE {$this->tables['listing_location']} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Address Components
            unparsed_address VARCHAR(255),
            street_number VARCHAR(50),
            street_dir_prefix VARCHAR(20),
            street_name VARCHAR(100),
            street_dir_suffix VARCHAR(20),
            street_number_numeric INT,
            unit_number VARCHAR(30),
            entry_level VARCHAR(100),
            
            -- Geographic
            city VARCHAR(100),
            state_or_province VARCHAR(50),
            postal_code VARCHAR(20),
            county_or_parish VARCHAR(100),
            country VARCHAR(5) DEFAULT 'US',
            
            -- MLS Areas
            mls_area_major VARCHAR(100),
            mls_area_minor VARCHAR(100),
            subdivision_name VARCHAR(100),
            
            -- Coordinates
            latitude DOUBLE,
            longitude DOUBLE,
            {$spatial_field},
            
            -- Schools
            elementary_school VARCHAR(100),
            middle_or_junior_school VARCHAR(100),
            high_school VARCHAR(100),
            school_district VARCHAR(100),
            
            PRIMARY KEY (listing_id),
            FOREIGN KEY (listing_id) REFERENCES {$this->tables['listings']}(id) ON DELETE CASCADE,
            KEY idx_city (city),
            KEY idx_postal_code (postal_code),
            KEY idx_street_name (street_name),
            KEY idx_mls_areas (mls_area_major, mls_area_minor),
            KEY idx_subdivision (subdivision_name),
            {$spatial_index}
        ) {$this->charset_collate};";
        
        dbDelta($sql);
        
        // Store spatial capability for future reference
        update_option('bme_pro_spatial_support', $supports_spatial);
    }
    
    /**
     * Financial information
     */
    private function create_listing_financial_table() {
        $sql = "CREATE TABLE {$this->tables['listing_financial']} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Pricing Details
            purchase_contract_date DATETIME,
            original_entry_timestamp DATETIME,
            off_market_date DATETIME,
            
            -- Tax Information
            tax_annual_amount DECIMAL(20,2),
            tax_year INT,
            tax_assessed_value DECIMAL(20,2),
            
            -- HOA Information
            association_yn BOOLEAN DEFAULT FALSE,
            association_name VARCHAR(100),
            association_fee DECIMAL(20,2),
            association_fee_frequency VARCHAR(20),
            
            -- Rental Specific
            availability_date DATE,
            available_now BOOLEAN DEFAULT FALSE,
            lease_term VARCHAR(100),
            rent_includes TEXT,
            security_deposit DECIMAL(20,2),
            
            -- Market Analysis
            market_time_property INT,
            
            PRIMARY KEY (listing_id),
            FOREIGN KEY (listing_id) REFERENCES {$this->tables['listings']}(id) ON DELETE CASCADE,
            KEY idx_tax_year (tax_year),
            KEY idx_association_fee (association_fee),
            KEY idx_availability (availability_date, available_now)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Property features and amenities
     */
    private function create_listing_features_table() {
        $sql = "CREATE TABLE {$this->tables['listing_features']} (
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Exterior Features
            exterior_features LONGTEXT,
            patio_and_porch_features LONGTEXT,
            lot_features LONGTEXT,
            
            -- Water Features
            waterfront_yn BOOLEAN DEFAULT FALSE,
            waterfront_features LONGTEXT,
            pool_features LONGTEXT,
            pool_private_yn BOOLEAN DEFAULT FALSE,
            
            -- Views
            view_yn BOOLEAN DEFAULT FALSE,
            view_description LONGTEXT,
            
            -- Community
            community_features LONGTEXT,
            
            -- Media
            media LONGTEXT,
            virtual_tour_url_unbranded VARCHAR(255),
            virtual_tour_url_branded VARCHAR(255),
            
            -- Additional JSON Data
            additional_data LONGTEXT,
            
            PRIMARY KEY (listing_id),
            FOREIGN KEY (listing_id) REFERENCES {$this->tables['listings']}(id) ON DELETE CASCADE,
            KEY idx_waterfront (waterfront_yn),
            KEY idx_pool (pool_private_yn),
            KEY idx_view (view_yn),
            FULLTEXT KEY ft_features (exterior_features, lot_features, community_features)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Agents cache table
     */
    private function create_agents_table() {
        $sql = "CREATE TABLE {$this->tables['agents']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_mls_id VARCHAR(50) NOT NULL,
            
            -- Agent Details
            agent_data LONGTEXT,
            
            -- Cache Management
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_agent_mls_id (agent_mls_id),
            KEY idx_expires (expires_at)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Offices cache table
     */
    private function create_offices_table() {
        $sql = "CREATE TABLE {$this->tables['offices']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            office_mls_id VARCHAR(50) NOT NULL,
            
            -- Office Details
            office_data LONGTEXT,
            
            -- Cache Management
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_office_mls_id (office_mls_id),
            KEY idx_expires (expires_at)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Open houses table
     */
    private function create_open_houses_table() {
        $sql = "CREATE TABLE {$this->tables['open_houses']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT(20) UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            
            -- Open House Data
            open_house_data LONGTEXT,
            
            -- Cache Management
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_listing_id (listing_id),
            KEY idx_listing_key (listing_key),
            FOREIGN KEY (listing_id) REFERENCES {$this->tables['listings']}(id) ON DELETE CASCADE
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Extraction logs table
     */
    private function create_extraction_logs_table() {
        $sql = "CREATE TABLE {$this->tables['extraction_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Log Details
            status VARCHAR(50) NOT NULL,
            message TEXT,
            listings_processed INT DEFAULT 0,
            
            -- Performance Metrics
            duration_seconds DECIMAL(10,3),
            memory_peak_mb DECIMAL(10,2),
            api_requests_count INT DEFAULT 0,
            
            -- Error Details
            error_details LONGTEXT,
            
            -- Timestamps
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_extraction_id (extraction_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Create additional performance indexes with error handling
     */
    private function create_indexes() {
        // Composite indexes for common queries
        $indexes = [
            "CREATE INDEX idx_listings_filter ON {$this->tables['listings']} (standard_status, property_type, list_price)",
            "CREATE INDEX idx_listings_search ON {$this->tables['listings']} (property_type, bedrooms_total, bathrooms_total_integer)",
            "CREATE INDEX idx_location_search ON {$this->tables['listing_location']} (city, state_or_province, postal_code)",
        ];
        
        foreach ($indexes as $sql) {
            $result = $this->wpdb->query($sql);
            if ($result === false) {
                // Index might already exist, which is okay
                error_log('BME Database: Index creation skipped (may already exist): ' . $sql);
            }
        }
    }
    
    /**
     * FIXED: Verify database installation with comprehensive checks
     */
    public function verify_installation() {
        $missing_tables = [];
        $table_issues = [];
        
        foreach ($this->tables as $name => $table_name) {
            // Check if table exists
            if ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                $missing_tables[] = $name;
                continue;
            }
            
            // Check table structure for critical fields
            $table_structure = $this->wpdb->get_results("DESCRIBE {$table_name}", ARRAY_A);
            $columns = array_column($table_structure, 'Field');
            
            // Verify key columns exist
            switch ($name) {
                case 'listings':
                    $required_columns = ['id', 'extraction_id', 'listing_key', 'listing_id', 'standard_status'];
                    break;
                case 'listing_location':
                    $required_columns = ['listing_id', 'latitude', 'longitude'];
                    break;
                case 'extraction_logs':
                    $required_columns = ['id', 'extraction_id', 'status', 'created_at'];
                    break;
                default:
                    $required_columns = ['listing_id'];
                    break;
            }
            
            $missing_columns = array_diff($required_columns, $columns);
            if (!empty($missing_columns)) {
                $table_issues[] = "Table {$name} missing columns: " . implode(', ', $missing_columns);
            }
        }
        
        if (!empty($missing_tables)) {
            throw new Exception('Missing database tables: ' . implode(', ', $missing_tables));
        }
        
        if (!empty($table_issues)) {
            throw new Exception('Database structure issues: ' . implode('; ', $table_issues));
        }
        
        // Check MySQL version and capabilities
        $mysql_version = $this->wpdb->get_var("SELECT VERSION()");
        $spatial_support = version_compare($mysql_version, '5.7.0', '>=');
        
        // Log database verification success
        error_log("BME Database verification successful. MySQL version: {$mysql_version}, Spatial support: " . ($spatial_support ? 'Yes' : 'No'));
        
        return true;
    }
    
    /**
     * Get table name with validation
     */
    public function get_table($table) {
        if (!isset($this->tables[$table])) {
            throw new Exception("Table {$table} not found in schema");
        }
        return $this->tables[$table];
    }
    
    /**
     * Get all table names
     */
    public function get_tables() {
        return $this->tables;
    }
    
    /**
     * Clean up expired cache entries with better error handling
     */
    public function cleanup_cache() {
        $cleaned = 0;
        $errors = [];
        
        try {
            $now = current_time('mysql');
            
            // Clean expired agents
            $result = $this->wpdb->delete(
                $this->tables['agents'],
                ['expires_at <' => $now],
                ['%s']
            );
            
            if ($result !== false) {
                $cleaned += $result;
            } else {
                $errors[] = 'Failed to clean expired agents';
            }
            
            // Clean expired offices
            $result = $this->wpdb->delete(
                $this->tables['offices'],
                ['expires_at <' => $now],
                ['%s']
            );
            
            if ($result !== false) {
                $cleaned += $result;
            } else {
                $errors[] = 'Failed to clean expired offices';
            }
            
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        
        if (!empty($errors)) {
            error_log('BME Cache Cleanup Errors: ' . implode(', ', $errors));
        }
        
        return $cleaned;
    }
    
    /**
     * Get comprehensive database statistics
     */
    public function get_stats() {
        $stats = [];
        
        foreach ($this->tables as $name => $table) {
            try {
                $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                $stats[$name] = intval($count);
            } catch (Exception $e) {
                $stats[$name] = 0;
                error_log("BME Database Stats Error for table {$name}: " . $e->getMessage());
            }
        }
        
        // Add additional statistics
        $stats['mysql_version'] = $this->wpdb->get_var("SELECT VERSION()");
        $stats['charset'] = $this->wpdb->charset;
        $stats['collate'] = $this->wpdb->collate;
        $stats['spatial_support'] = get_option('bme_pro_spatial_support', false);
        
        return $stats;
    }
    
    /**
     * Get database table sizes for monitoring
     */
    public function get_table_sizes() {
        $sizes = [];
        
        foreach ($this->tables as $name => $table) {
            try {
                $result = $this->wpdb->get_row($this->wpdb->prepare("
                    SELECT 
                        table_name,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                        table_rows
                    FROM information_schema.TABLES 
                    WHERE table_schema = DATABASE() 
                    AND table_name = %s
                ", $table));
                
                if ($result) {
                    $sizes[$name] = [
                        'size_mb' => floatval($result->size_mb),
                        'rows' => intval($result->table_rows)
                    ];
                }
            } catch (Exception $e) {
                error_log("BME Database Size Error for table {$name}: " . $e->getMessage());
                $sizes[$name] = ['size_mb' => 0, 'rows' => 0];
            }
        }
        
        return $sizes;
    }
    
    /**
     * Optimize database tables for better performance
     */
    public function optimize_tables() {
        $optimized = [];
        $errors = [];
        
        foreach ($this->tables as $name => $table) {
            try {
                $result = $this->wpdb->query("OPTIMIZE TABLE {$table}");
                if ($result !== false) {
                    $optimized[] = $name;
                } else {
                    $errors[] = "Failed to optimize table: {$name}";
                }
            } catch (Exception $e) {
                $errors[] = "Error optimizing table {$name}: " . $e->getMessage();
            }
        }
        
        return [
            'optimized' => $optimized,
            'errors' => $errors
        ];
    }
    
    /**
     * Check database health and performance
     */
    public function health_check() {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => []
        ];
        
        try {
            // Check table existence and basic structure
            $this->verify_installation();
            
            // Check for large tables that might need optimization
            $sizes = $this->get_table_sizes();
            foreach ($sizes as $table => $info) {
                if ($info['size_mb'] > 1000) { // Tables larger than 1GB
                    $health['recommendations'][] = "Consider optimizing large table: {$table} ({$info['size_mb']} MB)";
                }
            }
            
            // Check MySQL version
            $mysql_version = $this->wpdb->get_var("SELECT VERSION()");
            if (version_compare($mysql_version, '5.7.0', '<')) {
                $health['issues'][] = "MySQL version {$mysql_version} is older than recommended (5.7+)";
                $health['status'] = 'warning';
            }
            
            // Check for spatial support
            if (!get_option('bme_pro_spatial_support', false)) {
                $health['recommendations'][] = 'Consider upgrading MySQL for spatial index support';
            }
            
            // Check for recent errors
            $recent_errors = get_option('bme_pro_db_errors', []);
            if (!empty($recent_errors)) {
                $health['issues'] = array_merge($health['issues'], array_slice($recent_errors, -5));
                if (count($recent_errors) > 10) {
                    $health['status'] = 'warning';
                }
            }
            
        } catch (Exception $e) {
            $health['status'] = 'error';
            $health['issues'][] = $e->getMessage();
        }
        
        return $health;
    }
    
    /**
     * Log database errors for monitoring
     */
    public function log_error($error_message, $context = '') {
        $errors = get_option('bme_pro_db_errors', []);
        
        $errors[] = [
            'message' => $error_message,
            'context' => $context,
            'timestamp' => current_time('mysql'),
            'mysql_error' => $this->wpdb->last_error
        ];
        
        // Keep only last 50 errors
        $errors = array_slice($errors, -50);
        
        update_option('bme_pro_db_errors', $errors);
        
        // Also log to PHP error log
        error_log("BME Database Error [{$context}]: {$error_message}");
    }
    
    /**
     * Export database schema for backup/migration
     */
    public function export_schema() {
        $schema = [];
        
        foreach ($this->tables as $name => $table) {
            try {
                $create_table = $this->wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
                if ($create_table) {
                    $schema[$name] = $create_table[1];
                }
            } catch (Exception $e) {
                error_log("BME Schema Export Error for table {$name}: " . $e->getMessage());
            }
        }
        
        return $schema;
    }
    
    /**
     * Get database migration information
     */
    public function get_migration_info() {
        return [
            'current_version' => BME_PRO_VERSION,
            'schema_version' => get_option('bme_pro_schema_version', '1.0'),
            'last_migration' => get_option('bme_pro_last_migration', 'Never'),
            'tables' => array_keys($this->tables),
            'spatial_support' => get_option('bme_pro_spatial_support', false)
        ];
    }
    
    /**
     * Update schema version after migrations
     */
    public function update_schema_version($version) {
        update_option('bme_pro_schema_version', $version);
        update_option('bme_pro_last_migration', current_time('mysql'));
    }
}