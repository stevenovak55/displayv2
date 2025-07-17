<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intelligent cache manager for API responses and database queries
 */
class BME_Cache_Manager {
    
    private $cache_group;
    private $default_ttl;
    
    public function __construct() {
        $this->cache_group = BME_CACHE_GROUP;
        $this->default_ttl = BME_CACHE_DURATION;
    }
    
    /**
     * Get cached data with fallback
     */
    public function get($key, $callback = null, $ttl = null) {
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);
        
        // Try to get from cache
        $cached_data = wp_cache_get($cache_key, $this->cache_group);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        // If callback provided, execute and cache result
        if ($callback && is_callable($callback)) {
            $data = $callback();
            $this->set($key, $data, $ttl);
            return $data;
        }
        
        return null;
    }
    
    /**
     * Set cache data
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);
        
        return wp_cache_set($cache_key, $data, $this->cache_group, $ttl);
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        $cache_key = $this->build_cache_key($key);
        return wp_cache_delete($cache_key, $this->cache_group);
    }
    
    /**
     * Clear all cache for the plugin
     */
    public function flush() {
        return wp_cache_flush_group($this->cache_group);
    }
    
    /**
     * Build standardized cache key
     */
    private function build_cache_key($key) {
        if (is_array($key)) {
            return md5(serialize($key));
        }
        return sanitize_key($key);
    }
    
    /**
     * Cache search results with smart invalidation
     */
    public function cache_search_results($filters, $results, $count) {
        $cache_key = 'search_' . md5(serialize($filters));
        
        $cache_data = [
            'results' => $results,
            'count' => $count,
            'timestamp' => time(),
            'filters' => $filters
        ];
        
        // Cache search results for shorter duration
        $this->set($cache_key, $cache_data, 300); // 5 minutes
        
        return $cache_key;
    }
    
    /**
     * Get cached search results
     */
    public function get_cached_search($filters) {
        $cache_key = 'search_' . md5(serialize($filters));
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && is_array($cached_data)) {
            // Check if cache is still valid (not older than 5 minutes)
            if ((time() - $cached_data['timestamp']) < 300) {
                return $cached_data;
            }
        }
        
        return null;
    }
    
    /**
     * Cache agent data with smart expiration
     */
    public function cache_agent_data($agent_mls_id, $agent_data) {
        $cache_key = 'agent_' . $agent_mls_id;
        
        $cache_data = [
            'data' => $agent_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached agent data
     */
    public function get_cached_agent($agent_mls_id) {
        $cache_key = 'agent_' . $agent_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }
    
    /**
     * Cache office data with smart expiration
     */
    public function cache_office_data($office_mls_id, $office_data) {
        $cache_key = 'office_' . $office_mls_id;
        
        $cache_data = [
            'data' => $office_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached office data
     */
    public function get_cached_office($office_mls_id) {
        $cache_key = 'office_' . $office_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }
    
    /**
     * Cache extraction statistics
     */
    public function cache_extraction_stats($extraction_id, $stats) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->set($cache_key, $stats, HOUR_IN_SECONDS); // 1 hour
    }
    
    /**
     * Get cached extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->get($cache_key);
    }
    
    /**
     * Cache distinct values for filters
     */
    public function cache_filter_values($field, $values) {
        $cache_key = 'filter_values_' . $field;
        return $this->set($cache_key, $values, 2 * HOUR_IN_SECONDS); // 2 hours
    }
    
    /**
     * Get cached filter values
     */
    public function get_filter_values($field) {
        $cache_key = 'filter_values_' . $field;
        return $this->get($cache_key);
    }
    
    /**
     * Invalidate related caches when data changes
     */
    public function invalidate_listing_caches($listing_id = null) {
        // Clear search result caches
        $this->delete_pattern('search_*');
        
        // Clear filter value caches
        $this->delete_pattern('filter_values_*');
        
        // Clear extraction stats
        $this->delete_pattern('extraction_stats_*');
        
        if ($listing_id) {
            $this->delete('listing_' . $listing_id);
        }
    }
    
    /**
     * Delete cache entries matching pattern
     *
     * IMPORTANT: WordPress's default object cache does not support pattern-based deletion.
     * This method will either flush the entire cache group (if supported by a persistent
     * object cache like Redis/Memcached) or fall back to flushing the entire WordPress
     * object cache. For optimal performance and granular control, it is highly
     * recommended to use a persistent object cache plugin (e.g., Redis Object Cache,
     * Memcached Object Cache) on your WordPress installation.
     */
    private function delete_pattern($pattern) {
        // Check if a persistent object cache with group flushing is active
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        } else {
            // Fallback: flush all cache (not ideal for performance on busy sites)
            // This will clear all cached data, not just plugin-specific data.
            wp_cache_flush();
        }
    }
    
    /**
     * Warm up frequently accessed caches
     */
    public function warm_up_caches() {
        // Cache commonly used filter values
        $this->warm_up_filter_caches();
        
        // Cache recent extraction stats
        $this->warm_up_extraction_stats();
    }
    
    /**
     * Warm up filter value caches
     */
    private function warm_up_filter_caches() {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        $listings_table = $db_manager->get_table('listings');
        $location_table = $db_manager->get_table('listing_location');
        
        $filter_fields = [
            'standard_status' => $listings_table,
            'property_type' => $listings_table,
            'city' => $location_table,
            'state_or_province' => $location_table,
            'postal_code' => $location_table
        ];
        
        foreach ($filter_fields as $field => $table) {
            $cache_key = 'filter_values_' . $field;
            
            if (!$this->get($cache_key)) {
                $values = $wpdb->get_col("
                    SELECT DISTINCT {$field} 
                    FROM {$table} 
                    WHERE {$field} IS NOT NULL 
                    AND {$field} != '' 
                    ORDER BY {$field} ASC
                ");
                
                $this->cache_filter_values($field, $values);
            }
        }
    }
    
    /**
     * Warm up extraction statistics
     */
    private function warm_up_extraction_stats() {
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);
        
        $data_processor = bme_pro()->get('processor');
        
        foreach ($extractions as $extraction_id) {
            $cache_key = 'extraction_stats_' . $extraction_id;
            
            if (!$this->get($cache_key)) {
                $stats = $data_processor->get_extraction_stats($extraction_id);
                $this->cache_extraction_stats($extraction_id, $stats);
            }
        }
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        $stats = [
            'cache_backend' => $this->get_cache_backend(),
            'group' => $this->cache_group,
            'default_ttl' => $this->default_ttl
        ];
        
        // Try to get hit/miss ratios if available
        if (function_exists('wp_cache_get_stats')) {
            $cache_stats = wp_cache_get_stats();
            if ($cache_stats) {
                $stats['cache_stats'] = $cache_stats;
            }
        }
        
        return $stats;
    }
    
    /**
     * Detect cache backend
     */
    private function get_cache_backend() {
        if (class_exists('Redis') && defined('WP_REDIS_HOST')) {
            return 'Redis';
        }
        
        if (class_exists('Memcached') && function_exists('wp_cache_add_global_groups')) {
            return 'Memcached';
        }
        
        if (function_exists('wp_cache_get_last_changed')) {
            return 'Object Cache (Plugin)';
        }
        
        return 'Database (Default)';
    }
    
    /**
     * Schedule cache cleanup
     */
    public function schedule_cache_cleanup() {
        if (!wp_next_scheduled('bme_pro_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bme_pro_cache_cleanup');
        }
    }
    
    /**
     * Cleanup expired cache entries
     */
    public function cleanup_expired_cache() {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        
        // Cleanup expired agents
        $agents_table = $db_manager->get_table('agents');
        $deleted_agents = $wpdb->query(
            "DELETE FROM {$agents_table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
        
        // Cleanup expired offices
        $offices_table = $db_manager->get_table('offices');
        $deleted_offices = $wpdb->query(
            "DELETE FROM {$offices_table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
        
        // Log cleanup results
        if ($deleted_agents > 0 || $deleted_offices > 0) {
            error_log("BME Cache Cleanup: Removed {$deleted_agents} expired agents and {$deleted_offices} expired offices");
        }
        
        return [
            'deleted_agents' => $deleted_agents,
            'deleted_offices' => $deleted_offices
        ];
    }
    
    /**
     * Memory-aware caching for large datasets
     */
    public function cache_large_dataset($key, $data, $ttl = null) {
        $data_size = strlen(serialize($data));
        $memory_limit = $this->get_memory_limit_bytes();
        
        // Don't cache if data is too large (more than 10% of memory limit)
        if ($data_size > ($memory_limit * 0.1)) {
            error_log("BME Cache: Dataset too large to cache ({$data_size} bytes)");
            return false;
        }
        
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $number = (int) $matches[1];
            $suffix = strtoupper($matches[2]);
            
            switch ($suffix) {
                case 'G':
                    return $number * 1024 * 1024 * 1024;
                case 'M':
                    return $number * 1024 * 1024;
                case 'K':
                    return $number * 1024;
                default:
                    return $number;
            }
        }
        
        return 128 * 1024 * 1024; // Default to 128MB
    }
}
