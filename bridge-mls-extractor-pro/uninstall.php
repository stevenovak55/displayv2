<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package   Bridge_MLS_Extractor_Pro
 */

// If uninstall.php is not called by WordPress, die.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

$delete_option = get_option('bme_delete_on_uninstall');

// Only proceed if the user has explicitly checked the option to delete data.
if ($delete_option === 'on') {
    global $wpdb;

    // 1. Delete Custom Post Type posts (Extractions and Logs).
    // This will also clean up all associated post meta automatically.
    $cpt_posts = get_posts([
        'post_type'   => ['bme_extraction', 'bme_log'],
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ]);

    if (!empty($cpt_posts)) {
        foreach ($cpt_posts as $post_id) {
            // true to force delete, bypassing trash.
            wp_delete_post($post_id, true);
        }
    }

    // 2. Delete the custom database table for listings.
    $listings_table_name = $wpdb->prefix . 'bme_listings';
    $wpdb->query("DROP TABLE IF EXISTS {$listings_table_name}");

    // 3. Delete plugin options from the options table.
    delete_option('bme_api_credentials');
    delete_option('bme_delete_on_uninstall');

    // 4. Clear any scheduled cron events to keep the cron system clean.
    wp_clear_scheduled_hook('bme_master_cron_hook');
}
