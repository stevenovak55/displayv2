<?php
/**
 * Plugin Name: Bridge MLS Extractor Pro - Optimized
 * Description: High-performance MLS data extraction with normalized database architecture and concurrent API processing
 * Version: 3.0
 * Author: Professional Developer
 * Text Domain: bridge-mls-extractor-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BME_PRO_VERSION', '3.0');
define('BME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BME_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BME_CACHE_GROUP', 'bme_pro');
define('BME_API_TIMEOUT', 60);
define('BME_BATCH_SIZE', 100);
define('BME_CACHE_DURATION', 3600); // 1 hour

// Explicitly require core class files to ensure they are loaded before instantiation.
// This helps prevent 'class not found' issues during service initialization.
require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cache-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-api-client.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-data-processor.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-extraction-engine.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-admin.php'; // Also ensure admin class is loaded
require_once BME_PLUGIN_DIR . 'includes/class-bme-cron-manager.php'; // Ensure cron manager is loaded
require_once BME_PLUGIN_DIR . 'includes/class-bme-post-types.php'; // Ensure post types are loaded


/**
 * Main plugin class implementing singleton pattern
 */
final class Bridge_MLS_Extractor_Pro {
    
    private static $instance = null;
    private $container = []; // This will now hold actual service instances
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Autoloader is still useful for other classes, but core services are now explicitly required.
        $this->init_autoloader(); 
        $this->init_services(); // Initialize services first
        $this->init_hooks();    // Then initialize hooks (activation/deactivation, and the 'init' hook)
        
        // Removed direct instantiation of BME_Admin and BME_Cron_Manager from here.
        // They will now be instantiated via the bme_pro_components_init() function hooked to plugins_loaded.
    }
    
    /**
     * Simple autoloader for plugin classes (for non-core classes)
     */
    private function init_autoloader() {
        spl_autoload_register(function($class) {
            if (strpos($class, 'BME_') === 0) {
                $file = BME_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $class)) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }
    
    /**
     * Initialize WordPress hooks (primarily activation/deactivation and the 'init' action)
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('init', [$this, 'init']); // Hook the main init method here
    }
    
    /**
     * Initialize dependency injection container by eagerly loading services.
     * This ensures all services are ready immediately after the main plugin class is constructed.
     */
    private function init_services() {
        // Instantiate and store services directly
        $this->container['db'] = new BME_Database_Manager();
        $this->container['cache'] = new BME_Cache_Manager();
        $this->container['api'] = new BME_API_Client();
        
        // Data processor depends on db and cache
        $this->container['processor'] = new BME_Data_Processor(
            $this->container['db'], 
            $this->container['cache']
        );
        
        // Extraction engine depends on api, processor, and cache
        $this->container['extractor'] = new BME_Extraction_Engine(
            $this->container['api'],
            $this->container['processor'],
            $this->container['cache']
        );
    }
    
    /**
     * Get service from container.
     * Since services are eagerly loaded, this simply returns the instance.
     */
    public function get($service) {
        if (!isset($this->container[$service])) {
            error_log("BME Error: Attempted to get service '{$service}' but it was not found in the container.");
            throw new Exception("Service {$service} not found");
        }
        
        return $this->container[$service];
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Services are already initialized in the constructor (when bme_pro() is first called)
            $this->get('db')->create_tables();
            $this->get('db')->verify_installation();
            
            // Schedule cron
            if (!wp_next_scheduled('bme_pro_cron_hook')) {
                wp_schedule_event(time(), 'every_15_minutes', 'bme_pro_cron_hook');
            }
            
            // Set activation flag
            update_option('bme_pro_activated', true);
            
        } catch (Exception $e) {
            error_log('BME Pro Activation Error: ' . $e->getMessage());
            set_transient('bme_pro_activation_error', $e->getMessage(), 60);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('bme_pro_cron_hook');
        wp_cache_flush_group(BME_CACHE_GROUP);
    }
    
    /**
     * Initialize plugin (runs on 'init' action)
     */
    public function init() {
        // Register custom post types
        // This is now handled by an instance of BME_Post_Types
        $cpt = new BME_Post_Types();
        $cpt->register();
        
        // Load text domain
        load_plugin_textdomain('bridge-mls-extractor-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Prevent cloning
     */
    public function __clone() {
        _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION);
    }
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION);
    }
}

// Global function to return the main plugin instance
function bme_pro() {
    return Bridge_MLS_Extractor_Pro::instance();
}

/**
 * Initializes all other plugin components after the main plugin instance
 * and its services are guaranteed to be ready.
 * This function is hooked to 'plugins_loaded'.
 */
function bme_pro_components_init() {
    $plugin_instance = bme_pro(); // Get the single, fully initialized plugin instance

    // Instantiate core plugin components here
    if (is_admin()) {
        new BME_Admin($plugin_instance);
    }
    // Cron Manager is needed in both admin and non-admin contexts for cron jobs to run.
    // Ensure it's always initialized after the main plugin instance is ready.
    new BME_Cron_Manager($plugin_instance->get('extractor'));
}

// Hook the component initialization to 'plugins_loaded'.
// This is the most reliable way to ensure all parts of the plugin
// are set up correctly and have access to the main plugin's services.
add_action('plugins_loaded', 'bme_pro_components_init');
