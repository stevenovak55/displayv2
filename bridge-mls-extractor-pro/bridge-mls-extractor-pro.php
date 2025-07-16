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

/**
 * Main plugin class implementing singleton pattern
 */
final class Bridge_MLS_Extractor_Pro {
    
    private static $instance = null;
    private $container = [];
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_autoloader();
        $this->init_hooks();
        $this->init_services();
    }
    
    /**
     * Simple autoloader for plugin classes
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
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('plugins_loaded', [$this, 'load_plugin']);
        add_action('init', [$this, 'init']);
    }
    
    /**
     * Initialize dependency injection container
     */
    private function init_services() {
        // Database service
        $this->container['db'] = function() {
            return new BME_Database_Manager();
        };
        
        // API client service
        $this->container['api'] = function() {
            return new BME_API_Client();
        };
        
        // Cache service
        $this->container['cache'] = function() {
            return new BME_Cache_Manager();
        };
        
        // Data processor service
        $this->container['processor'] = function() {
            return new BME_Data_Processor(
                $this->get('db'),
                $this->get('cache')
            );
        };
        
        // Extraction engine
        $this->container['extractor'] = function() {
            return new BME_Extraction_Engine(
                $this->get('api'),
                $this->get('processor'),
                $this->get('cache')
            );
        };
    }
    
    /**
     * Get service from container
     */
    public function get($service) {
        if (!isset($this->container[$service])) {
            throw new Exception("Service {$service} not found");
        }
        
        if (is_callable($this->container[$service])) {
            $this->container[$service] = $this->container[$service]();
        }
        
        return $this->container[$service];
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        try {
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
     * Load plugin components
     */
    public function load_plugin() {
        if (is_admin()) {
            new BME_Admin($this);
        }
        
        // Initialize cron handler
        new BME_Cron_Manager($this->get('extractor'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Register custom post types
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

// Initialize plugin
function bme_pro() {
    return Bridge_MLS_Extractor_Pro::instance();
}

// Start the plugin
bme_pro();