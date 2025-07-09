<?php
/**
 * Plugin Name:       MLS Listings Display
 * Plugin URI:        https://example.com/
 * Description:       Displays real estate listings from the Bridge MLS Extractor Pro plugin using shortcodes.
 * Version:           1.2.0
 * Author:            Your Name
 * Author URI:        https://example.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mls-listings-display
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants.
define( 'MLD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLD_VERSION', '1.2.0' );

// Include the class files.
require_once MLD_PLUGIN_PATH . 'includes/class-bme-query.php';
require_once MLD_PLUGIN_PATH . 'includes/class-bme-ajax.php';
require_once MLD_PLUGIN_PATH . 'includes/class-bme-shortcodes.php';

/**
 * Initializes the plugin by creating instances of the main classes.
 */
function mld_run_plugin() {
    new MLD_BME_Shortcodes();
    new MLD_BME_AJAX();
}
add_action( 'plugins_loaded', 'mld_run_plugin' );

// --- Admin Settings Page ---

function mld_add_admin_menu() {
    add_options_page(
        'MLS Display Settings',
        'MLS Display',
        'manage_options',
        'mls_listings_display',
        'mld_options_page_html'
    );
}
add_action( 'admin_menu', 'mld_add_admin_menu' );

/**
 * --- UPDATED: Enqueue media scripts for the logo uploader ---
 */
function mld_admin_enqueue_scripts( $hook_suffix ) {
    // Only load on our settings page
    if ( 'settings_page_mls_listings_display' !== $hook_suffix ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_script( 'mld-admin-js', MLD_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], MLD_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'mld_admin_enqueue_scripts' );


/**
 * --- UPDATED: Register the new logo setting ---
 */
function mld_settings_init() {
    register_setting( 'mld_options_group', 'mld_settings' );

    add_settings_section(
        'mld_api_keys_section',
        'API Keys & Settings',
        null,
        'mld_options_group'
    );

    add_settings_field(
        'mld_logo_url',
        'Display Logo',
        'mld_logo_url_callback',
        'mld_options_group',
        'mld_api_keys_section'
    );

    add_settings_field(
        'mld_map_provider',
        'Map Provider',
        'mld_map_provider_callback',
        'mld_options_group',
        'mld_api_keys_section'
    );

    add_settings_field(
        'mld_mapbox_api_key',
        'Mapbox API Key',
        'mld_mapbox_api_key_callback',
        'mld_options_group',
        'mld_api_keys_section'
    );

    add_settings_field(
        'mld_google_maps_api_key',
        'Google Maps API Key',
        'mld_google_maps_api_key_callback',
        'mld_options_group',
        'mld_api_keys_section'
    );
}
add_action( 'admin_init', 'mld_settings_init' );

/**
 * --- NEW: Callback for the logo uploader field ---
 */
function mld_logo_url_callback() {
    $options = get_option('mld_settings');
    $logo_url = isset($options['mld_logo_url']) ? esc_attr($options['mld_logo_url']) : '';
    ?>
    <input type="text" name="mld_settings[mld_logo_url]" id="mld_logo_url" value="<?php echo $logo_url; ?>" class="regular-text" />
    <button type="button" class="button" id="mld_upload_logo_button">Upload Logo</button>
    <p class="description">Upload or choose a logo to display next to the search bar.</p>
    <div id="mld-logo-preview">
        <?php if ($logo_url): ?>
            <img src="<?php echo $logo_url; ?>" style="max-width: 200px; max-height: 50px; margin-top: 10px;" />
        <?php endif; ?>
    </div>
    <?php
}


function mld_map_provider_callback() {
    $options = get_option( 'mld_settings' );
    $provider = isset( $options['mld_map_provider'] ) ? $options['mld_map_provider'] : 'mapbox';
    ?>
    <select name="mld_settings[mld_map_provider]">
        <option value="mapbox" <?php selected( $provider, 'mapbox' ); ?>>Mapbox</option>
        <option value="google" <?php selected( $provider, 'google' ); ?>>Google Maps</option>
    </select>
    <p class="description">Choose which mapping service to use for displaying listings.</p>
    <?php
}

function mld_mapbox_api_key_callback() {
    $options = get_option( 'mld_settings' );
    $mapbox_key = isset( $options['mld_mapbox_api_key'] ) ? esc_attr( $options['mld_mapbox_api_key'] ) : '';
    echo "<input type='text' name='mld_settings[mld_mapbox_api_key]' value='{$mapbox_key}' class='regular-text' />";
    echo "<p class='description'>Required if Map Provider is set to Mapbox.</p>";
}

function mld_google_maps_api_key_callback() {
    $options = get_option( 'mld_settings' );
    $google_key = isset( $options['mld_google_maps_api_key'] ) ? esc_attr( $options['mld_google_maps_api_key'] ) : '';
    echo "<input type='text' name='mld_settings[mld_google_maps_api_key]' value='{$google_key}' class='regular-text' />";
    echo "<p class='description'>Required if Map Provider is set to Google Maps.</p>";
}

function mld_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'mld_options_group' );
            do_settings_sections( 'mld_options_group' );
            submit_button( 'Save Settings' );
            ?>
        </form>
    </div>
    <?php
}
