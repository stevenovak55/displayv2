<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main extraction engine orchestrating the entire data extraction process
 * FIXED: Enhanced error handling and validation
 */
class BME_Extraction_Engine {
    
    private $api_client;
    private $data_processor;
    private $cache_manager;
    
    public function __construct(BME_API_Client $api_client, BME_Data_Processor $data_processor, BME_Cache_Manager $cache_manager) {
        $this->api_client = $api_client;
        $this->data_processor = $data_processor;
        $this->cache_manager = $cache_manager;
    }
    
    /**
     * Execute a single extraction profile with enhanced error handling
     */
    public function run_extraction($extraction_id, $is_resync = false) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage();
        
        // Validate extraction ID
        $extraction_id = absint($extraction_id);
        if (!$extraction_id) {
            throw new Exception('Invalid extraction ID provided');
        }
        
        try {
            // Validate API credentials first
            $this->api_client->validate_credentials();
            
            // Get extraction configuration
            $config = $this->get_extraction_config($extraction_id, $is_resync);
            
            // Clear existing data if resync
            if ($is_resync) {
                $cleared = $this->data_processor->clear_extraction_data($extraction_id);
                $this->log_extraction_step($extraction_id, 'info', "Cleared {$cleared} existing listings for resync");
                
                // Reset last modified timestamp for full resync
                update_post_meta($extraction_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
                $config['last_modified'] = '1970-01-01T00:00:00Z';
            }
            
            // Build API filter query
            $filter_query = $this->api_client->build_filter_query($config);
            
            // Log the filter being used
            $this->log_extraction_step($extraction_id, 'info', "Filter query: {$filter_query}");
            
            // Initialize extraction metrics
            $metrics = [
                'total_listings' => 0,
                'total_batches' => 0,
                'api_requests' => 0,
                'errors' => [],
                'last_modified' => $config['last_modified']
            ];
            
            // Create extraction callback
            $extraction_callback = function($batch_listings, $total_processed) use ($extraction_id, &$metrics) {
                return $this->process_listings_batch($extraction_id, $batch_listings, $metrics);
            };
            
            // Execute main extraction
            $total_processed = $this->api_client->fetch_listings($filter_query, $extraction_callback);
            
            // Update last modified timestamp if we processed listings
            if ($total_processed > 0 && !empty($metrics['last_modified'])) {
                update_post_meta($extraction_id, '_bme_last_modified', $metrics['last_modified']);
                $this->log_extraction_step($extraction_id, 'info', "Updated last modified to: {$metrics['last_modified']}");
            }
            
            // Calculate final metrics
            $duration = microtime(true) - $start_time;
            $memory_peak = memory_get_peak_usage() - $memory_start;
            
            // Determine final status
            $error_count = count($metrics['errors']);
            $status = 'Success';
            if ($error_count > 0) {
                $error_percentage = ($error_count / max($total_processed, 1)) * 100;
                if ($error_percentage > 50) {
                    $status = 'Failure';
                } else {
                    $status = 'Completed with errors';
                }
            }
            
            // Log completion
            $this->log_extraction_completion($extraction_id, [
                'status' => $status,
                'total_listings' => $total_processed,
                'duration' => $duration,
                'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
                'api_requests' => $metrics['api_requests'],
                'errors' => $metrics['errors'],
                'is_resync' => $is_resync,
                'error_count' => $error_count
            ]);
            
            // Clear relevant caches
            $this->cache_manager->invalidate_listing_caches();
            
            return $status === 'Success' || $status === 'Completed with errors';
            
        } catch (Exception $e) {
            $duration = microtime(true) - $start_time;
            
            $this->log_extraction_completion($extraction_id, [
                'status' => 'Failure',
                'error_message' => $e->getMessage(),
                'duration' => $duration,
                'memory_peak_mb' => round((memory_get_peak_usage() - $memory_start) / 1024 / 1024, 2),
                'is_resync' => $is_resync,
                'total_listings' => 0
            ]);
            
            return false;
        }
    }
    
    /**
     * Process a batch of listings with related data and enhanced error handling
     */
    private function process_listings_batch($extraction_id, $batch_listings, &$metrics) {
        try {
            $batch_start_time = microtime(true);
            
            // Validate batch data
            if (empty($batch_listings) || !is_array($batch_listings)) {
                $this->log_extraction_step($extraction_id, 'warning', 'Received empty or invalid batch data');
                return [];
            }
            
            // Extract IDs for related data fetching
            $agent_ids = [];
            $office_ids = [];
            $listing_keys = [];
            
            foreach ($batch_listings as $listing) {
                if (!is_array($listing)) {
                    continue;
                }
                
                if (!empty($listing['ListAgentMlsId'])) {
                    $agent_ids[] = $listing['ListAgentMlsId'];
                }
                if (!empty($listing['BuyerAgentMlsId'])) {
                    $agent_ids[] = $listing['BuyerAgentMlsId'];
                }
                if (!empty($listing['ListOfficeMlsId'])) {
                    $office_ids[] = $listing['ListOfficeMlsId'];
                }
                if (!empty($listing['BuyerOfficeMlsId'])) {
                    $office_ids[] = $listing['BuyerOfficeMlsId'];
                }
                if (!empty($listing['ListingKey'])) {
                    $listing_keys[] = $listing['ListingKey'];
                }
                
                // Track latest modification timestamp
                if (!empty($listing['ModificationTimestamp'])) {
                    $metrics['last_modified'] = $listing['ModificationTimestamp'];
                }
            }
            
            // Fetch related data concurrently if we have IDs
            $related_data = ['agents' => [], 'offices' => [], 'open_houses' => []];
            
            if (!empty($agent_ids) || !empty($office_ids) || !empty($listing_keys)) {
                try {
                    $related_data = $this->api_client->fetch_related_data_concurrent(
                        $agent_ids, 
                        $office_ids, 
                        $listing_keys
                    );
                    
                    // Update API request count
                    $metrics['api_requests'] += 1; // Main listings request
                    $metrics['api_requests'] += ceil(count(array_unique($agent_ids)) / 50);
                    $metrics['api_requests'] += ceil(count(array_unique($office_ids)) / 50);
                    $metrics['api_requests'] += ceil(count(array_unique($listing_keys)) / 50);
                    
                } catch (Exception $e) {
                    $this->log_extraction_step($extraction_id, 'warning', 'Failed to fetch related data: ' . $e->getMessage());
                    // Continue processing without related data
                }
            } else {
                $metrics['api_requests'] += 1; // Just the main listings request
            }
            
            // Process the batch
            $result = $this->data_processor->process_listings_batch(
                $extraction_id, 
                $batch_listings, 
                $related_data
            );
            
            // Update metrics
            $metrics['total_listings'] += $result['processed'];
            $metrics['total_batches']++;
            $metrics['errors'] = array_merge($metrics['errors'], $result['errors']);
            
            $batch_duration = microtime(true) - $batch_start_time;
            
            // Log batch progress with more detail
            $this->log_extraction_step(
                $extraction_id, 
                'info', 
                sprintf(
                    'Batch %d completed: %d/%d listings processed, %d errors, %.2f seconds, %.2f MB peak memory',
                    $metrics['total_batches'],
                    $result['processed'],
                    count($batch_listings),
                    count($result['errors']),
                    $batch_duration,
                    $result['memory_peak_mb']
                )
            );
            
            // Log errors if any
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->log_extraction_step(
                        $extraction_id, 
                        'error', 
                        "Listing {$error['listing_id']}: {$error['error']}"
                    );
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Batch processing failed: ' . $e->getMessage());
            $metrics['errors'][] = [
                'batch_error' => true,
                'error' => $e->getMessage(),
                'timestamp' => current_time('mysql')
            ];
            throw $e;
        }
    }
    
    /**
     * FIXED: Get extraction configuration from post meta with validation
     */
    private function get_extraction_config($extraction_id, $is_resync) {
        // Verify the extraction post exists and is published
        $extraction_post = get_post($extraction_id);
        if (!$extraction_post || $extraction_post->post_type !== 'bme_extraction' || $extraction_post->post_status !== 'publish') {
            throw new Exception('Extraction profile not found or not published');
        }
        
        $config = [
            'extraction_id' => $extraction_id,
            'is_resync' => $is_resync,
            'statuses' => get_post_meta($extraction_id, '_bme_statuses', true) ?: [],
            'cities' => get_post_meta($extraction_id, '_bme_cities', true),
            'states' => get_post_meta($extraction_id, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($extraction_id, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($extraction_id, '_bme_buyer_agent_id', true),
            'closed_lookback_months' => get_post_meta($extraction_id, '_bme_closed_lookback_months', true) ?: 12,
            'last_modified' => get_post_meta($extraction_id, '_bme_last_modified', true) ?: '1970-01-01T00:00:00Z'
        ];
        
        // Debug logging
        error_log('BME Debug - Extraction config for ID ' . $extraction_id . ': ' . print_r($config, true));
        
        // Validate required configuration
        if (empty($config['statuses'])) {
            throw new Exception('No listing statuses configured for extraction. Please edit the extraction and select at least one status.');
        }
        
        // Validate statuses against allowed values
        $valid_statuses = ['Active', 'Active Under Contract', 'Pending', 'Closed', 'Expired', 'Withdrawn', 'Canceled'];
        $config['statuses'] = array_intersect($config['statuses'], $valid_statuses);
        
        if (empty($config['statuses'])) {
            throw new Exception('No valid listing statuses found in configuration.');
        }
        
        // Validate states against allowed values
        if (!empty($config['states'])) {
            $valid_states = ['MA', 'NH', 'RI', 'VT', 'CT', 'ME'];
            $config['states'] = array_intersect($config['states'], $valid_states);
        }
        
        // Validate closed lookback months
        $config['closed_lookback_months'] = max(1, min(120, absint($config['closed_lookback_months'])));
        
        // Validate agent IDs (alphanumeric only)
        if (!empty($config['list_agent_id']) && !preg_match('/^[a-zA-Z0-9]+$/', $config['list_agent_id'])) {
            error_log("BME Warning - Invalid list agent ID format, ignoring: " . $config['list_agent_id']);
            $config['list_agent_id'] = '';
        }
        
        if (!empty($config['buyer_agent_id']) && !preg_match('/^[a-zA-Z0-9]+$/', $config['buyer_agent_id'])) {
            error_log("BME Warning - Invalid buyer agent ID format, ignoring: " . $config['buyer_agent_id']);
            $config['buyer_agent_id'] = '';
        }
        
        // Safety check - if no filters are set, require confirmation
        $has_filters = !empty($config['cities']) || 
                      !empty($config['states']) || 
                      !empty($config['list_agent_id']) || 
                      !empty($config['buyer_agent_id']);
        
        if (!$has_filters && !$is_resync) {
            // Log warning but don't prevent extraction
            error_log('BME Warning - Extraction ' . $extraction_id . ' has no geographic or agent filters. This may pull a large dataset.');
        }
        
        return $config;
    }
    
    /**
     * Log extraction step for debugging
     */
    private function log_extraction_step($extraction_id, $level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = sprintf('[BME Extraction %d]', $extraction_id);
            error_log("{$prefix} [{$level}] {$message}");
        }
        
        // Also store recent activity for admin display
        $recent_activity = get_option('bme_pro_extraction_activity', []);
        
        $recent_activity[] = [
            'extraction_id' => $extraction_id,
            'level' => $level,
            'message' => $message,
            'timestamp' => time()
        ];
        
        // Keep only last 100 entries
        $recent_activity = array_slice($recent_activity, -100);
        update_option('bme_pro_extraction_activity', $recent_activity);
    }
    
    /**
     * Log extraction completion with detailed metrics
     */
    private function log_extraction_completion($extraction_id, $metrics) {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');
        
        $log_data = [
            'extraction_id' => $extraction_id,
            'status' => $metrics['status'],
            'listings_processed' => $metrics['total_listings'] ?? 0,
            'duration_seconds' => round($metrics['duration'], 3),
            'memory_peak_mb' => $metrics['memory_peak_mb'],
            'api_requests_count' => $metrics['api_requests'] ?? 0,
            'started_at' => date('Y-m-d H:i:s', time() - $metrics['duration']),
            'completed_at' => current_time('mysql')
        ];
        
        // Build message based on status
        if ($metrics['status'] === 'Success') {
            $run_type = $metrics['is_resync'] ? 'Full Re-sync' : 'Standard Run';
            $log_data['message'] = sprintf(
                '%s completed successfully. Processed %d listings in %.2f seconds using %d API requests.',
                $run_type,
                $metrics['total_listings'] ?? 0,
                $metrics['duration'],
                $metrics['api_requests'] ?? 0
            );
        } elseif ($metrics['status'] === 'Completed with errors') {
            $error_count = $metrics['error_count'] ?? count($metrics['errors'] ?? []);
            $log_data['message'] = sprintf(
                'Extraction completed with %d errors. Successfully processed %d listings.',
                $error_count,
                $metrics['total_listings'] ?? 0
            );
            $log_data['error_details'] = json_encode(array_slice($metrics['errors'] ?? [], 0, 10)); // Limit error details
        } else {
            $log_data['message'] = $metrics['error_message'] ?? 'Unknown error occurred';
            if (!empty($metrics['error_message'])) {
                $log_data['error_details'] = json_encode(['message' => $metrics['error_message']]);
            }
        }
        
        // Insert log record
        $wpdb->insert($table, $log_data);
        
        // Update extraction post meta
        update_post_meta($extraction_id, '_bme_last_run_status', $metrics['status']);
        update_post_meta($extraction_id, '_bme_last_run_time', time());
        
        // Update performance metrics
        if (!empty($metrics['duration'])) {
            update_post_meta($extraction_id, '_bme_last_run_duration', round($metrics['duration'], 2));
        }
        
        if (!empty($metrics['total_listings'])) {
            update_post_meta($extraction_id, '_bme_last_run_count', $metrics['total_listings']);
        }
        
        // Clear cached extraction stats
        $this->cache_manager->delete('extraction_stats_' . $extraction_id);
    }
    
    /**
     * Get extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        return $this->data_processor->get_extraction_stats($extraction_id);
    }
    
    /**
     * Run multiple extractions (for cron) with improved error handling
     */
    public function run_scheduled_extractions() {
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_bme_schedule',
                    'value' => 'none',
                    'compare' => '!='
                ]
            ]
        ]);
        
        if (empty($extractions)) {
            return 0;
        }
        
        $executed = 0;
        $schedules = wp_get_schedules();
        
        foreach ($extractions as $extraction) {
            $schedule = get_post_meta($extraction->ID, '_bme_schedule', true);
            $last_run = get_post_meta($extraction->ID, '_bme_last_run_time', true) ?: 0;
            
            if (!isset($schedules[$schedule])) {
                continue;
            }
            
            $interval = $schedules[$schedule]['interval'];
            
            // Check if it's time to run this extraction
            if (time() > ($last_run + $interval)) {
                try {
                    $success = $this->run_extraction($extraction->ID, false);
                    if ($success) {
                        $executed++;
                    }
                    error_log("BME Scheduled extraction {$extraction->ID} completed with result: " . ($success ? 'success' : 'failure'));
                } catch (Exception $e) {
                    error_log('BME Scheduled extraction failed for ID ' . $extraction->ID . ': ' . $e->getMessage());
                }
                
                // Add small delay between extractions to prevent overwhelming the system
                sleep(2);
            }
        }
        
        return $executed;
    }
    
    /**
     * Test extraction configuration without running full extraction
     */
    public function test_extraction_config($extraction_id) {
        try {
            // Validate API credentials
            $this->api_client->validate_credentials();
            
            // Get extraction configuration
            $config = $this->get_extraction_config($extraction_id, false);
            
            // Build filter query
            $filter_query = $this->api_client->build_filter_query($config);
            
            // Test the filter with a small sample
            $test_result = $this->api_client->test_filter_query($filter_query, 5);
            
            if ($test_result['success']) {
                return [
                    'success' => true,
                    'config' => $config,
                    'filter_query' => $filter_query,
                    'sample_count' => $test_result['count'],
                    'total_available' => $test_result['total_available'],
                    'sample_data' => $test_result['sample_data'],
                    'message' => 'Configuration is valid and ready for extraction'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $test_result['error'],
                    'config' => $config,
                    'filter_query' => $filter_query
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get recent extraction logs
     */
    public function get_recent_logs($extraction_id = null, $limit = 20) {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');
        
        $sql = "SELECT * FROM {$table}";
        $params = [];
        
        if ($extraction_id) {
            $sql .= " WHERE extraction_id = %d";
            $params[] = absint($extraction_id);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT %d";
        $params[] = absint($limit);
        
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
    
    /**
     * Clear old extraction logs
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');
        
        $cutoff_date = date('Y-m-d H:i:s', time() - ($days_to_keep * DAY_IN_SECONDS));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff_date
        ));
        
        return $deleted;
    }
    
    /**
     * Get system performance metrics
     */
    public function get_performance_metrics() {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        $logs_table = $db_manager->get_table('extraction_logs');
        
        // Get metrics for the last 24 hours
        $metrics = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_runs,
                SUM(listings_processed) as total_listings,
                AVG(duration_seconds) as avg_duration,
                AVG(memory_peak_mb) as avg_memory,
                SUM(api_requests_count) as total_api_requests,
                SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as successful_runs,
                MIN(duration_seconds) as min_duration,
                MAX(duration_seconds) as max_duration
            FROM {$logs_table} 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", ARRAY_A);
        
        // Calculate success rate and performance metrics
        if ($metrics && $metrics['total_runs'] > 0) {
            $metrics['success_rate'] = round(($metrics['successful_runs'] / $metrics['total_runs']) * 100, 2);
            $metrics['avg_listings_per_run'] = round($metrics['total_listings'] / $metrics['total_runs'], 2);
            $metrics['avg_listings_per_second'] = $metrics['avg_duration'] > 0 ? 
                round($metrics['avg_listings_per_run'] / $metrics['avg_duration'], 2) : 0;
        } else {
            $metrics = [
                'total_runs' => 0,
                'total_listings' => 0,
                'avg_duration' => 0,
                'avg_memory' => 0,
                'total_api_requests' => 0,
                'successful_runs' => 0,
                'success_rate' => 0,
                'avg_listings_per_run' => 0,
                'avg_listings_per_second' => 0,
                'min_duration' => 0,
                'max_duration' => 0
            ];
        }
        
        // Get database statistics
        $db_stats = $db_manager->get_stats();
        
        return [
            'extraction_metrics' => $metrics,
            'database_stats' => $db_stats
        ];
    }
    
    /**
     * Get extraction activity for admin display
     */
    public function get_recent_activity($limit = 50) {
        $activity = get_option('bme_pro_extraction_activity', []);
        
        // Sort by timestamp descending and limit
        usort($activity, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return array_slice($activity, 0, $limit);
    }
    
    /**
     * Force stop a running extraction (emergency function)
     */
    public function force_stop_extraction($extraction_id) {
        // This would be used in emergency situations
        // For now, we'll just log the stop request
        $this->log_extraction_step($extraction_id, 'warning', 'Extraction stop requested by administrator');
        
        // In a more advanced implementation, this could set a flag that
        // the extraction process checks periodically to gracefully stop
        update_post_meta($extraction_id, '_bme_stop_requested', time());
        
        return true;
    }
}