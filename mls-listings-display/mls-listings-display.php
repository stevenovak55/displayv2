<?php
/**
 * Plugin Name:       MLS Listings Display
 * Plugin URI:        https://example.com/
 * Description:       Displays real estate listings from the Bridge MLS Extractor Pro plugin using shortcodes.
 * Version:           2.0.1
 * Author:            Your Name
 * Author URI:        https://example.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mls-listings-display
 *
 * @package           MLS_Listings_Display
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants.
define( 'MLD_VERSION', '2.0.1' );
define( 'MLD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include the files needed for activation/deactivation hooks.
// This must be done here because activation hooks run before 'plugins_loaded'.
require_once MLD_PLUGIN_PATH . 'includes/class-mld-rewrites.php';

// Include the main plugin class to run the plugin.
require_once MLD_PLUGIN_PATH . 'includes/class-mld-main.php';

/**
 * Begins execution of the plugin.
 */
function mld_run_plugin() {
    new MLD_Main();
}
add_action( 'plugins_loaded', 'mld_run_plugin' );

/**
 * The code that runs during plugin activation.
 */
register_activation_hook( __FILE__, [ 'MLD_Rewrites', 'activate' ] );

/**
 * The code that runs during plugin deactivation.
 */
register_deactivation_hook( __FILE__, [ 'MLD_Rewrites', 'deactivate' ] );
