<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security Helper Class for Bridge MLS Extractor Pro
 * Provides secure methods for database operations and data validation
 */
class BME_Security_Helper {

    /**
     * Create safe IN clause for SQL queries using prepared statements
     * 
     * @param array $values Array of values for IN clause
     * @param string $type Type of placeholder ('%s' for strings, '%d' for integers)
     * @return array Array with 'placeholders' string and 'values' array
     */
    public static function prepare_in_clause($values, $type = '%s') {
        if (empty($values)) {
            return ['placeholders' => "''", 'values' => []];
        }

        $values = array_unique($values);
        $placeholders = array_fill(0, count($values), $type);
        
        return [
            'placeholders' => implode(',', $placeholders),
            'values' => $values
        ];
    }

    /**
     * Get safe table name from whitelist
     * 
     * @param string $table_key Key identifying the table
     * @param string $suffix Optional suffix ('_archive')
     * @return string Safe table name
     * @throws Exception if table key is not whitelisted
     */
    public static function get_safe_table_name($table_key, $suffix = '') {
        global $wpdb;
        
        $allowed_tables = [
            'listings' => 'bme_listings',
            'listing_details' => 'bme_listing_details',
            'listing_location' => 'bme_listing_location',
            'listing_financial' => 'bme_listing_financial',
            'listing_features' => 'bme_listing_features',
            'agents' => 'bme_agents',
            'offices' => 'bme_offices',
            'media' => 'bme_media',
            'rooms' => 'bme_rooms',
            'open_houses' => 'bme_open_houses',
            'virtual_tours' => 'bme_virtual_tours',
            'extraction_profiles' => 'bme_extraction_profiles',
            'security_log' => 'bme_security_log'
        ];

        if (!isset($allowed_tables[$table_key])) {
            throw new Exception('Invalid table reference: ' . esc_html($table_key));
        }

        $table_name = $wpdb->prefix . $allowed_tables[$table_key];
        
        // Only allow specific suffixes
        if ($suffix === '_archive') {
            $table_name .= '_archive';
        } elseif (!empty($suffix)) {
            throw new Exception('Invalid table suffix');
        }

        return $table_name;
    }

    /**
     * Sanitize and validate extraction profile data
     * 
     * @param array $data Raw input data
     * @return array Sanitized data
     */
    public static function sanitize_extraction_profile($data) {
        $sanitized = [];

        // Cities validation - only allow letters, spaces, hyphens
        if (!empty($data['bme_cities'])) {
            $cities = array_map('trim', explode(',', $data['bme_cities']));
            $valid_cities = [];
            
            foreach ($cities as $city) {
                if (preg_match('/^[a-zA-Z\s\-\']+$/', $city)) {
                    $valid_cities[] = sanitize_text_field($city);
                }
            }
            
            $sanitized['cities'] = implode(',', $valid_cities);
        } elseif (!empty($data['cities'])) {
            // Handle both field names for flexibility
            $cities = array_map('trim', explode(',', $data['cities']));
            $valid_cities = [];
            
            foreach ($cities as $city) {
                if (preg_match('/^[a-zA-Z\s\-\']+$/', $city)) {
                    $valid_cities[] = sanitize_text_field($city);
                }
            }
            
            $sanitized['cities'] = implode(',', $valid_cities);
        } else {
            $sanitized['cities'] = '';
        }

        // States validation - only allow 2-letter codes
        $states_field = isset($data['bme_states']) ? $data['bme_states'] : (isset($data['states']) ? $data['states'] : []);
        if (!empty($states_field)) {
            if (!is_array($states_field)) {
                $states_field = explode(',', $states_field);
            }
            $valid_states = [];
            
            foreach ($states_field as $state) {
                $state = trim($state);
                if (preg_match('/^[A-Z]{2}$/i', $state)) {
                    $valid_states[] = strtoupper(sanitize_text_field($state));
                }
            }
            
            $sanitized['states'] = implode(',', $valid_states);
        } else {
            $sanitized['states'] = '';
        }

        // Numeric validations
        $lookback_field = isset($data['bme_lookback_months']) ? $data['bme_lookback_months'] : (isset($data['lookback_months']) ? $data['lookback_months'] : 0);
        $sanitized['lookback_months'] = absint($lookback_field);
        $sanitized['lookback_months'] = min($sanitized['lookback_months'], 120); // Max 10 years

        // Status validation - whitelist only
        $allowed_statuses = [
            'Active', 'Closed', 'Expired', 'Withdrawn', 'Pending', 
            'Canceled', 'Active Under Contract', 'Coming Soon'
        ];
        
        $statuses_field = isset($data['bme_statuses']) ? $data['bme_statuses'] : (isset($data['statuses']) ? $data['statuses'] : []);
        if (!empty($statuses_field) && is_array($statuses_field)) {
            $sanitized['statuses'] = array_intersect($statuses_field, $allowed_statuses);
        } else {
            $sanitized['statuses'] = [];
        }

        // Boolean fields
        $sanitized['extract_sold'] = !empty($data['extract_sold']);
        $sanitized['enable_schedule'] = !empty($data['enable_schedule']);

        // Schedule frequency - whitelist
        if (!empty($data['schedule_frequency'])) {
            $allowed_frequencies = ['hourly', 'twicedaily', 'daily', 'weekly'];
            if (in_array($data['schedule_frequency'], $allowed_frequencies)) {
                $sanitized['schedule_frequency'] = $data['schedule_frequency'];
            }
        }

        return $sanitized;
    }

    /**
     * Validate extraction profile data
     * 
     * @param array $data Sanitized data to validate
     * @return array Array of error messages (empty if valid)
     */
    public static function validate_extraction_profile($data) {
        $errors = [];
        
        // Check if at least one status is selected
        if (empty($data['statuses'])) {
            $errors[] = __('Please select at least one listing status.', 'bridge-mls-extractor-pro');
        }
        
        // If archived statuses are selected, lookback months is required
        $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];
        if (!empty(array_intersect($data['statuses'], $archived_statuses))) {
            if (empty($data['lookback_months']) || $data['lookback_months'] <= 0) {
                $errors[] = __('Lookback months is required when selecting archived statuses.', 'bridge-mls-extractor-pro');
            }
        }
        
        // Validate city names if provided
        if (!empty($data['cities'])) {
            $cities = explode(',', $data['cities']);
            foreach ($cities as $city) {
                $city = trim($city);
                if (!empty($city) && !preg_match('/^[a-zA-Z\s\-\']+$/', $city)) {
                    $errors[] = sprintf(__('Invalid city name: %s', 'bridge-mls-extractor-pro'), $city);
                }
            }
        }
        
        // Validate state codes if provided
        if (!empty($data['states'])) {
            $states = explode(',', $data['states']);
            foreach ($states as $state) {
                $state = trim($state);
                if (!empty($state) && !preg_match('/^[A-Z]{2}$/', $state)) {
                    $errors[] = sprintf(__('Invalid state code: %s. Must be 2 letters.', 'bridge-mls-extractor-pro'), $state);
                }
            }
        }
        
        return $errors;
    }

    /**
     * Validate coordinate data
     * 
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public static function validate_coordinates($latitude, $longitude) {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $lat = floatval($latitude);
        $lon = floatval($longitude);

        // Valid latitude range: -90 to 90
        // Valid longitude range: -180 to 180
        return ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180);
    }

    /**
     * Escape output for JavaScript
     * 
     * @param mixed $data
     * @return string
     */
    public static function esc_js_object($data) {
        return wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /**
     * Check user capability with logging
     * 
     * @param string $capability
     * @param string $action Action being performed
     * @return bool
     */
    public static function check_capability($capability, $action = '') {
        $has_cap = current_user_can($capability);
        
        if (!$has_cap && !empty($action)) {
            BME_Security_Logger::log('unauthorized_access', [
                'action' => $action,
                'capability_required' => $capability
            ]);
        }

        return $has_cap;
    }

    /**
     * Verify AJAX request with nonce and capability
     * 
     * @param string $nonce_action
     * @param string $capability
     * @return bool
     */
    public static function verify_ajax_request($nonce_action, $capability = 'manage_options') {
        // Check nonce
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'bridge-mls-extractor-pro'));
            return false;
        }

        // Check capability
        if (!current_user_can($capability)) {
            wp_send_json_error(__('Insufficient permissions', 'bridge-mls-extractor-pro'));
            return false;
        }

        return true;
    }

    /**
     * Sanitize HTML output while preserving safe tags
     * 
     * @param string $html
     * @return string
     */
    public static function sanitize_html_output($html) {
        $allowed_tags = [
            'a' => ['href' => [], 'title' => [], 'target' => [], 'rel' => []],
            'br' => [],
            'em' => [],
            'strong' => [],
            'p' => ['class' => []],
            'span' => ['class' => []],
            'div' => ['class' => []],
            'ul' => ['class' => []],
            'ol' => ['class' => []],
            'li' => [],
            'h1' => ['class' => []],
            'h2' => ['class' => []],
            'h3' => ['class' => []],
            'h4' => ['class' => []],
            'h5' => ['class' => []],
            'h6' => ['class' => []],
        ];

        return wp_kses($html, $allowed_tags);
    }

    /**
     * Generate secure random token
     * 
     * @param int $length
     * @return string
     */
    public static function generate_secure_token($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
}

/**
 * Security Logger Class
 */
class BME_Security_Logger {
    
    /**
     * Log security-related events
     * 
     * @param string $event_type
     * @param array $details
     */
    public static function log($event_type, $details = []) {
        global $wpdb;
        
        try {
            $table = BME_Security_Helper::get_safe_table_name('security_log');
            
            $data = [
                'event_type' => sanitize_text_field($event_type),
                'user_id' => get_current_user_id(),
                'ip_address' => self::get_client_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'details' => wp_json_encode($details),
                'timestamp' => current_time('mysql')
            ];

            $wpdb->insert($table, $data);
        } catch (Exception $e) {
            error_log('BME Security Logger Error: ' . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     * 
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = filter_var($_SERVER[$key], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/**
 * Credential Manager for secure storage
 */
class BME_Credential_Manager {
    
    private $encryption_key;
    private $cipher = 'AES-256-CBC';
    
    public function __construct() {
        $this->encryption_key = $this->get_or_create_key();
    }
    
    /**
     * Get or create encryption key
     */
    private function get_or_create_key() {
        $key = get_option('bme_encryption_key');
        
        if (empty($key)) {
            $key = wp_generate_password(64, true, true);
            update_option('bme_encryption_key', $key, false);
        }
        
        return $key;
    }
    
    /**
     * Encrypt data
     * 
     * @param string $data
     * @return string
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt($data, $this->cipher, $this->encryption_key, 0, $iv);
        
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }
        
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt data
     * 
     * @param string $data
     * @return string
     */
    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        $parts = explode('::', base64_decode($data), 2);
        
        if (count($parts) !== 2) {
            return '';
        }
        
        list($encrypted_data, $iv) = $parts;
        
        $decrypted = openssl_decrypt($encrypted_data, $this->cipher, $this->encryption_key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    /**
     * Store encrypted API credentials
     * 
     * @param array $credentials
     */
    public function store_api_credentials($credentials) {
        $encrypted = [
            'token' => $this->encrypt($credentials['token'] ?? ''),
            'endpoint' => sanitize_url($credentials['endpoint'] ?? ''),
            'stored_at' => current_time('mysql')
        ];
        
        update_option('bme_api_credentials_encrypted', $encrypted, false);
        
        // Log credential update
        BME_Security_Logger::log('api_credentials_updated', [
            'endpoint' => $encrypted['endpoint']
        ]);
    }
    
    /**
     * Retrieve decrypted API credentials
     * 
     * @return array
     */
    public function get_api_credentials() {
        $encrypted = get_option('bme_api_credentials_encrypted', []);
        
        if (empty($encrypted)) {
            return [];
        }
        
        return [
            'token' => $this->decrypt($encrypted['token'] ?? ''),
            'endpoint' => $encrypted['endpoint'] ?? ''
        ];
    }
}