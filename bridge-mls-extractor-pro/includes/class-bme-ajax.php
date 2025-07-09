<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_Ajax
 *
 * Handles admin-post actions which are essentially non-AJAX form submissions.
 */
class BME_Ajax {

    public function __construct() {
        // No-op
    }

    /**
     * Register admin action hooks.
     */
    public function init_hooks() {
        add_action('admin_post_bme_run_now', [$this, 'handle_run_now']);
        add_action('admin_post_bme_run_resync', [$this, 'handle_run_resync']);
        add_action('admin_post_bme_clear_data', [$this, 'handle_clear_data']);
        add_action('admin_post_bme_clear_all_data', [$this, 'handle_clear_all_data']);
    }

    public function handle_run_now() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id) || !check_admin_referer('bme_run_now_' . $post_id)) {
            wp_die('Invalid request.');
        }
        
        $extraction = new BME_Extraction();
        $success = $extraction->run_single_extraction($post_id, false);
        
        $message_code = $success ? 100 : 200;
        wp_redirect(admin_url('edit.php?post_type=bme_extraction&message=' . $message_code));
        exit;
    }

    public function handle_run_resync() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id) || !check_admin_referer('bme_run_resync_' . $post_id)) {
            wp_die('Invalid request.');
        }

        $extraction = new BME_Extraction();
        $success = $extraction->run_single_extraction($post_id, true);
        
        $message_code = $success ? 101 : 200;
        wp_redirect(admin_url('edit.php?post_type=bme_extraction&message=' . $message_code));
        exit;
    }

    public function handle_clear_data() {
        global $wpdb;
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (!$post_id || !check_admin_referer('bme_clear_data_' . $post_id) || !current_user_can('edit_post', $post_id)) {
            wp_die('Invalid request or security check failed.');
        }

        $table_name = $wpdb->prefix . 'bme_listings';
        $wpdb->delete($table_name, ['source_extraction_id' => $post_id], ['%d']);

        update_post_meta($post_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
        // Manually log this action
        $log_post = [
            'post_title'   => sprintf('Extraction "%s" - Manual Action', get_the_title($post_id)),
            'post_content' => 'Cleared all data for this extraction profile.',
            'post_type'    => 'bme_log',
            'post_status'  => 'publish',
        ];
        $log_id = wp_insert_post($log_post);
        if ($log_id) {
            update_post_meta($log_id, '_bme_log_extraction_id', $post_id);
            update_post_meta($log_id, '_bme_log_status', 'Success');
            update_post_meta($log_id, '_bme_log_listings_count', 0);
        }

        wp_redirect(admin_url('edit.php?post_type=bme_extraction&message=102'));
        exit;
    }

    public function handle_clear_all_data() {
        global $wpdb;

        if (!check_admin_referer('bme_clear_all_data_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed or insufficient permissions.');
        }

        $table_name = $wpdb->prefix . 'bme_listings';
        $wpdb->query("TRUNCATE TABLE $table_name");

        // Reset the last modified timestamp for all extraction profiles
        $all_extractions = get_posts(['post_type' => 'bme_extraction', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']);
        foreach ($all_extractions as $extraction_id) {
            update_post_meta($extraction_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
        }

        wp_redirect(admin_url('admin.php?page=bme-settings&message=cleared'));
        exit;
    }
}
