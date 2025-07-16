<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Bridge_MLS_Extractor_Pro_Plugin
 *
 * The main plugin controller class that orchestrates the plugin's functionality.
 */
final class Bridge_MLS_Extractor_Pro_Plugin {

    /**
     * The single instance of the class.
     * @var Bridge_MLS_Extractor_Pro_Plugin
     */
    private static $instance = null;

    /**
     * Main Plugin Instance.
     * Ensures only one instance of the plugin is loaded or can be loaded.
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone() {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?', 'bridge-mls-extractor'), '2.1');
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?', 'bridge-mls-extractor'), '2.1');
    }

    /**
     * Plugin constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        register_activation_hook(BME_PLUGIN_DIR . 'bridge-mls-extractor-pro.php', [$this, 'on_activation']);
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        add_action('before_delete_post', [$this, 'on_delete_extraction']);
    }

    /**
     * Handles plugin activation.
     * Creates tables and sets up a verification flag for admin notices.
     */
    public function on_activation() {
        BME_DB::create_tables();

        if (!BME_DB::verify_table_exists()) {
            set_transient('bme_db_verification_failed', 'true', 60);
        }
    }

    /**
     * On plugins_loaded, initialize the plugin components.
     * This is where we safely load admin-only files.
     */
    public function on_plugins_loaded() {
        // Register CPTs which are needed on both admin and front-end
        $cpt = new BME_CPT();
        $cpt->register();

        // Initialize cron jobs
        $cron = new BME_Cron();
        $cron->init_hooks();

        // Only load admin classes if we are in the admin area
        if (is_admin()) {
            require_once BME_PLUGIN_DIR . 'includes/class-bme-logs-list-table.php';
            require_once BME_PLUGIN_DIR . 'includes/class-bme-admin.php';
            require_once BME_PLUGIN_DIR . 'includes/class-bme-ajax.php';

            $admin = new BME_Admin();
            $admin->init_hooks();

            $ajax = new BME_Ajax();
            $ajax->init_hooks();
        }
    }

    /**
     * When an extraction profile post is deleted, also delete its associated data.
     *
     * @param int $post_id The post ID.
     */
    public function on_delete_extraction($post_id) {
        if (get_post_type($post_id) !== 'bme_extraction') {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $wpdb->delete($table_name, ['source_extraction_id' => $post_id], ['%d']);
    }

    /**
     * The main run method for the plugin.
     */
    public function run() {
        // The constructor and hooks handle everything.
    }
}
