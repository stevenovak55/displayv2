<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced cron management with better scheduling and monitoring
 */
class BME_Cron_Manager {
    
    const MASTER_CRON_HOOK = 'bme_pro_cron_hook';
    const CLEANUP_HOOK = 'bme_pro_cleanup_hook';
    
    private $extraction_engine;
    
    public function __construct(BME_Extraction_Engine $extraction_engine) {
        $this->extraction_engine = $extraction_engine;
        $this->init_hooks();
    }
    
    /**
     * Initialize cron hooks
     */
    private function init_hooks() {
        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        
        // Register cron handlers
        add_action(self::MASTER_CRON_HOOK, [$this, 'run_scheduled_extractions']);
        add_action(self::CLEANUP_HOOK, [$this, 'run_cleanup_tasks']);
        
        // Schedule master cron if not already scheduled
        if (!wp_next_scheduled(self::MASTER_CRON_HOOK)) {
            wp_schedule_event(time(), 'every_15_minutes', self::MASTER_CRON_HOOK);
        }
        
        // Schedule cleanup tasks
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CLEANUP_HOOK);
        }
        
        // Admin hooks for cron management
        if (is_admin()) {
            add_action('admin_init', [$this, 'maybe_reschedule_cron']);
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_15_minutes'] = [
            'interval' => 900, // 15 minutes
            'display'  => __('Every 15 Minutes', 'bridge-mls-extractor-pro')
        ];
        
        $schedules['every_30_minutes'] = [
            'interval' => 1800, // 30 minutes
            'display'  => __('Every 30 Minutes', 'bridge-mls-extractor-pro')
        ];
        
        $schedules['every_2_hours'] = [
            'interval' => 7200, // 2 hours
            'display'  => __('Every 2 Hours', 'bridge-mls-extractor-pro')
        ];
        
        $schedules['every_6_hours'] = [
            'interval' => 21600, // 6 hours
            'display'  => __('Every 6 Hours', 'bridge-mls-extractor-pro')
        ];
        
        return $schedules;
    }
    
    /**
     * Run scheduled extractions
     */
    public function run_scheduled_extractions() {
        $start_time = microtime(true);
        
        try {
            // Get all scheduled extractions
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
                $this->log_cron_activity('No scheduled extractions found');
                return;
            }
            
            $executed = 0;
            $failed = 0;
            $skipped = 0;
            $schedules = wp_get_schedules();
            
            foreach ($extractions as $extraction) {
                $extraction_id = $extraction->ID;
                $schedule = get_post_meta($extraction_id, '_bme_schedule', true);
                $last_run = get_post_meta($extraction_id, '_bme_last_run_time', true) ?: 0;
                
                // Skip if schedule is not valid
                if (!isset($schedules[$schedule])) {
                    $skipped++;
                    continue;
                }
                
                $interval = $schedules[$schedule]['interval'];
                $next_run = $last_run + $interval;
                
                // Check if it's time to run
                if (time() >= $next_run) {
                    try {
                        $this->log_cron_activity("Starting scheduled extraction: {$extraction->post_title}");
                        
                        $success = $this->extraction_engine->run_extraction($extraction_id, false);
                        
                        if ($success) {
                            $executed++;
                            $this->log_cron_activity("Completed extraction: {$extraction->post_title}");
                        } else {
                            $failed++;
                            $this->log_cron_activity("Failed extraction: {$extraction->post_title}");
                        }
                        
                    } catch (Exception $e) {
                        $failed++;
                        $this->log_cron_activity("Exception in extraction {$extraction->post_title}: " . $e->getMessage());
                    }
                } else {
                    $skipped++;
                }
            }
            
            $duration = microtime(true) - $start_time;
            
            $this->log_cron_activity(sprintf(
                'Cron run completed: %d executed, %d failed, %d skipped in %.2f seconds',
                $executed, $failed, $skipped, $duration
            ));
            
            // Update cron statistics
            $this->update_cron_stats($executed, $failed, $skipped, $duration);
            
        } catch (Exception $e) {
            $this->log_cron_activity('Critical error in cron: ' . $e->getMessage());
        }
    }
    
    /**
     * Run cleanup tasks
     */
    public function run_cleanup_tasks() {
        $start_time = microtime(true);
        
        try {
            $cache_manager = bme_pro()->get('cache');
            $db_manager = bme_pro()->get('db');
            $data_processor = bme_pro()->get('processor');
            
            // Clean up expired cache entries
            $cache_cleanup = $cache_manager->cleanup_expired_cache();
            
            // Clean up old extraction logs (keep 30 days)
            $log_cleanup = $this->extraction_engine->cleanup_old_logs(30);
            
            // Clean up database cache tables
            $db_manager->cleanup_cache();
            
            // ** NEW: Clean up past open houses **
            $open_house_cleanup = $data_processor->delete_past_open_houses();
            
            $duration = microtime(true) - $start_time;
            
            $this->log_cron_activity(sprintf(
                'Hourly cleanup completed. Removed %d past open houses in %.2f seconds.',
                $open_house_cleanup ?? 0,
                $duration
            ));
            
        } catch (Exception $e) {
            $this->log_cron_activity('Error in cleanup: ' . $e->getMessage());
        }
    }
    
    /**
     * Maybe reschedule cron if settings changed
     */
    public function maybe_reschedule_cron() {
        $last_check = get_option('bme_pro_last_cron_check', 0);
        
        // Only check once per hour
        if ((time() - $last_check) < 3600) {
            return;
        }
        
        update_option('bme_pro_last_cron_check', time());
        
        // Check if master cron is still scheduled
        $next_run = wp_next_scheduled(self::MASTER_CRON_HOOK);
        
        if (!$next_run) {
            wp_schedule_event(time(), 'every_15_minutes', self::MASTER_CRON_HOOK);
            $this->log_cron_activity('Rescheduled missing master cron');
        }
        
        // Check cleanup cron
        $next_cleanup = wp_next_scheduled(self::CLEANUP_HOOK);
        
        if (!$next_cleanup) {
            wp_schedule_event(time(), 'hourly', self::CLEANUP_HOOK);
            $this->log_cron_activity('Rescheduled missing cleanup cron');
        }
    }
    
    /**
     * Get cron status information
     */
    public function get_cron_status() {
        $status = [
            'master_cron' => [
                'scheduled' => wp_next_scheduled(self::MASTER_CRON_HOOK),
                'next_run' => wp_next_scheduled(self::MASTER_CRON_HOOK) ? 
                    human_time_diff(wp_next_scheduled(self::MASTER_CRON_HOOK)) : 'Not scheduled'
            ],
            'cleanup_cron' => [
                'scheduled' => wp_next_scheduled(self::CLEANUP_HOOK),
                'next_run' => wp_next_scheduled(self::CLEANUP_HOOK) ? 
                    human_time_diff(wp_next_scheduled(self::CLEANUP_HOOK)) : 'Not scheduled'
            ],
            'wordpress_cron' => [
                'enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
                'constant_defined' => defined('DISABLE_WP_CRON')
            ]
        ];
        
        // Get recent cron statistics
        $stats = get_option('bme_pro_cron_stats', []);
        if (!empty($stats)) {
            $status['recent_stats'] = $stats;
        }
        
        return $status;
    }
    
    /**
     * Get scheduled extractions info
     */
    public function get_scheduled_extractions() {
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
        
        $scheduled = [];
        $schedules = wp_get_schedules();
        
        foreach ($extractions as $extraction) {
            $schedule = get_post_meta($extraction->ID, '_bme_schedule', true);
            $last_run = get_post_meta($extraction->ID, '_bme_last_run_time', true) ?: 0;
            
            if (isset($schedules[$schedule])) {
                $interval = $schedules[$schedule]['interval'];
                $next_run = $last_run + $interval;
                
                $scheduled[] = [
                    'id' => $extraction->ID,
                    'title' => $extraction->post_title,
                    'schedule' => $schedules[$schedule]['display'],
                    'last_run' => $last_run ? human_time_diff($last_run) . ' ago' : 'Never',
                    'next_run' => $next_run > time() ? 
                        'In ' . human_time_diff($next_run) : 
                        'Due now',
                    'is_due' => $next_run <= time()
                ];
            }
        }
        
        return $scheduled;
    }
    
    /**
     * Force run all due extractions (manual trigger)
     */
    public function force_run_due_extractions() {
        $executed = $this->extraction_engine->run_scheduled_extractions();
        
        $this->log_cron_activity("Manual trigger executed {$executed} extractions");
        
        return $executed;
    }
    
    /**
     * Clear all cron schedules (for deactivation)
     */
    public function clear_all_schedules() {
        wp_clear_scheduled_hook(self::MASTER_CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        
        $this->log_cron_activity('Cleared all scheduled cron jobs');
    }
    
    /**
     * Update cron execution statistics
     */
    private function update_cron_stats($executed, $failed, $skipped, $duration) {
        $stats = get_option('bme_pro_cron_stats', [
            'total_runs' => 0,
            'total_executed' => 0,
            'total_failed' => 0,
            'total_skipped' => 0,
            'avg_duration' => 0,
            'last_run' => 0
        ]);
        
        $stats['total_runs']++;
        $stats['total_executed'] += $executed;
        $stats['total_failed'] += $failed;
        $stats['total_skipped'] += $skipped;
        $stats['last_run'] = time();
        
        // Calculate rolling average duration
        if ($stats['avg_duration'] == 0) {
            $stats['avg_duration'] = $duration;
        } else {
            $stats['avg_duration'] = ($stats['avg_duration'] + $duration) / 2;
        }
        
        update_option('bme_pro_cron_stats', $stats);
    }
    
    /**
     * Log cron activity
     */
    private function log_cron_activity($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BME Pro Cron] ' . $message);
        }
        
        // Store recent activity for admin display
        $recent_activity = get_option('bme_pro_cron_activity', []);
        
        $recent_activity[] = [
            'timestamp' => time(),
            'message' => $message
        ];
        
        // Keep only last 50 entries
        $recent_activity = array_slice($recent_activity, -50);
        
        update_option('bme_pro_cron_activity', $recent_activity);
    }
    
    /**
     * Get recent cron activity
     */
    public function get_recent_activity($limit = 20) {
        $activity = get_option('bme_pro_cron_activity', []);
        
        // Sort by timestamp descending and limit
        usort($activity, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return array_slice($activity, 0, $limit);
    }
    
    /**
     * Check if WordPress cron is working properly
     */
    public function test_wp_cron() {
        $test_hook = 'bme_pro_cron_test';
        $test_time = time() + 60; // 1 minute from now
        
        // Clear any existing test
        wp_clear_scheduled_hook($test_hook);
        
        // Schedule test event
        wp_schedule_single_event($test_time, $test_hook);
        
        // Verify it was scheduled
        $scheduled = wp_next_scheduled($test_hook);
        
        return [
            'scheduled' => $scheduled !== false,
            'scheduled_time' => $scheduled,
            'wp_cron_enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
            'server_time' => time(),
            'gmt_offset' => get_option('gmt_offset')
        ];
    }
}
