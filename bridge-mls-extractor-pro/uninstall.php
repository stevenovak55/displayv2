<?php
/**
 * Uninstall script for Bridge MLS Extractor Pro
 * 
 * This file is executed when the plugin is deleted from the WordPress admin.
 * It will only run if the user has explicitly opted to delete all data.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user wants to delete data on uninstall
$delete_data = get_option('bme_pro_delete_on_uninstall', false);

if (!$delete_data) {
    // User chose to preserve data, exit without doing anything
    return;
}

global $wpdb;

try {
    // 1. Delete all custom post type posts
    $post_types = ['bme_extraction'];
    
    foreach ($post_types as $post_type) {
        $posts = get_posts([
            'post_type' => $post_type,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ]);
        
        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true); // Force delete, skip trash
        }
    }
    
    // 2. Drop all custom database tables
    $tables_to_drop = [
        $wpdb->prefix . 'bme_listings',
        $wpdb->prefix . 'bme_listing_details',
        $wpdb->prefix . 'bme_listing_location',
        $wpdb->prefix . 'bme_listing_financial',
        $wpdb->prefix . 'bme_listing_features',
        $wpdb->prefix . 'bme_agents',
        $wpdb->prefix . 'bme_offices',
        $wpdb->prefix . 'bme_open_houses',
        $wpdb->prefix . 'bme_extraction_logs'
    ];
    
    foreach ($tables_to_drop as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
    
    // 3. Delete all plugin options
    $options_to_delete = [
        'bme_pro_api_credentials',
        'bme_pro_performance_settings',
        'bme_pro_delete_on_uninstall',
        'bme_pro_activated',
        'bme_pro_version',
        'bme_pro_cron_stats',
        'bme_pro_cron_activity',
        'bme_pro_last_cron_check'
    ];
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // 4. Clear scheduled cron events
    $cron_hooks = [
        'bme_pro_cron_hook',
        'bme_pro_cleanup_hook',
        'bme_pro_cache_cleanup'
    ];
    
    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    
    // 5. Clean up user meta (if any plugin-specific user meta exists)
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'bme_pro_%'"
    );
    
    // 6. Clean up transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_pro_%' OR option_name LIKE '_transient_timeout_bme_pro_%'"
    );
    
    // 7. Clear any cached data
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('bme_pro');
    }
    
    // 8. Log the uninstall (optional, for debugging)
    error_log('Bridge MLS Extractor Pro: Uninstall completed successfully');
    
} catch (Exception $e) {
    // Log any errors during uninstall
    error_log('Bridge MLS Extractor Pro: Uninstall error - ' . $e->getMessage());
}