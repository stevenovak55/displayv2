<?php
/**
 * Plugin Name: Bridge MLS Extractor Pro
 * Description: A robust tool to extract MLS data into a custom WordPress database table with advanced data management.
 * Version: 2.1
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: bridge-mls-extractor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('BME_PRO_VERSION', '2.1');
define('BME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BME_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load core files that are safe to run everywhere
require_once BME_PLUGIN_DIR . 'includes/class-bme-db.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cpt.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cron.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-extraction.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-plugin.php'; // The main controller

/**
 * Begins execution of the plugin.
 *
 * @since    2.0
 */
function run_bridge_mls_extractor_pro() {
    $plugin = new Bridge_MLS_Extractor_Pro_Plugin();
    $plugin->run();
}

run_bridge_mls_extractor_pro();
