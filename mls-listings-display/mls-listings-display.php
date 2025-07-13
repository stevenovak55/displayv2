<?php
/**
 * Plugin Name:       MLS Listings Display
 * Plugin URI:        https://example.com/
 * Description:       Displays real estate listings from the Bridge MLS Extractor Pro plugin using shortcodes.
 * Version:           1.4.0
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
define( 'MLD_VERSION', '1.4.0' );

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

// --- Custom Rewrite Rules for Single Property Page ---

function mld_add_rewrite_rules() {
    add_rewrite_rule(
        '^property/([^/]+)/?$',
        'index.php?post_type=page&mls_number=$matches[1]',
        'top'
    );
}
add_action( 'init', 'mld_add_rewrite_rules' );

function mld_add_query_vars( $vars ) {
    $vars[] = 'mls_number';
    return $vars;
}
add_filter( 'query_vars', 'mld_add_query_vars' );

function mld_template_redirect() {
    if ( get_query_var( 'mls_number' ) ) {
        add_action( 'wp_enqueue_scripts', function() {
            wp_enqueue_style( 'mld-single-property-css', MLD_PLUGIN_URL . 'assets/css/single-property.css', [], MLD_VERSION );
        });
        
        $template = MLD_PLUGIN_PATH . 'templates/single-property.php';
        if ( file_exists( $template ) ) {
            include( $template );
            exit;
        }
    }
}
add_action( 'template_redirect', 'mld_template_redirect' );

function mld_activate_plugin() {
    mld_add_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mld_activate_plugin' );

function mld_deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mld_deactivate_plugin' );


// --- Admin Settings Pages ---

function mld_add_admin_menu() {
    add_menu_page(
        'MLS Display Settings',
        'MLS Display',
        'manage_options',
        'mls_listings_display',
        'mld_options_page_html',
        'dashicons-admin-home',
        25
    );

    add_submenu_page(
        'mls_listings_display',
        'Icon & Label Manager',
        'Icon & Label Manager',
        'manage_options',
        'mld_icon_manager',
        'mld_icon_manager_page_html'
    );
}
add_action( 'admin_menu', 'mld_add_admin_menu' );

function mld_admin_enqueue_scripts( $hook_suffix ) {
    // Only load on our settings pages
    if ( 'toplevel_page_mls_listings_display' !== $hook_suffix && 'mls-display_page_mld_icon_manager' !== $hook_suffix ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_script( 'mld-admin-js', MLD_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], MLD_VERSION, true );
    wp_enqueue_style('mld-admin-css', MLD_PLUGIN_URL . 'assets/css/admin.css', [], MLD_VERSION);
}
add_action( 'admin_enqueue_scripts', 'mld_admin_enqueue_scripts' );

function mld_settings_init() {
    register_setting( 'mld_options_group', 'mld_settings' );
    register_setting( 'mld_icon_manager_group', 'mld_subtype_customizations' );

    add_settings_section(
        'mld_api_keys_section',
        'API Keys & Settings',
        null,
        'mld_options_group'
    );

    add_settings_field('mld_logo_url', 'Display Logo', 'mld_logo_url_callback', 'mld_options_group', 'mld_api_keys_section');
    add_settings_field('mld_map_provider', 'Map Provider', 'mld_map_provider_callback', 'mld_options_group', 'mld_api_keys_section');
    add_settings_field('mld_mapbox_api_key', 'Mapbox API Key', 'mld_mapbox_api_key_callback', 'mld_options_group', 'mld_api_keys_section');
    add_settings_field('mld_google_maps_api_key', 'Google Maps API Key', 'mld_google_maps_api_key_callback', 'mld_options_group', 'mld_api_keys_section');
}
add_action( 'admin_init', 'mld_settings_init' );

// Callbacks for the main settings page
function mld_logo_url_callback() {
    $options = get_option('mld_settings');
    $logo_url = isset($options['mld_logo_url']) ? esc_attr($options['mld_logo_url']) : '';
    ?>
    <input type="text" name="mld_settings[mld_logo_url]" id="mld_logo_url" value="<?php echo $logo_url; ?>" class="regular-text" />
    <button type="button" class="button mld-upload-button" data-target-input="#mld_logo_url" data-target-preview="#mld-logo-preview">Upload Logo</button>
    <p class="description">Upload or choose a logo to display next to the search bar.</p>
    <div id="mld-logo-preview" class="mld-image-preview">
        <?php if ($logo_url): ?>
            <img src="<?php echo $logo_url; ?>" />
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
    if ( ! current_user_can( 'manage_options' ) ) return;
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

// HTML for the new Icon & Label Manager page
function mld_icon_manager_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $all_subtypes = MLD_BME_Query::get_all_distinct_subtypes();
    $customizations = get_option('mld_subtype_customizations', []);
    ?>
    <div class="wrap mld-icon-manager">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p>Customize the display labels and icons for each property subtype found in your database. These customizations will appear in the "Home Type" filter on the map.</p>
        
        <form action="options.php" method="post">
            <?php settings_fields( 'mld_icon_manager_group' ); ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 25%;">Original Subtype (from MLS)</th>
                        <th scope="col" style="width: 30%;">Custom Display Label</th>
                        <th scope="col" style="width: 45%;">Custom Icon (32x32 recommended)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_subtypes)): ?>
                        <tr>
                            <td colspan="3">No property subtypes found in the database yet. Run an extraction first.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($all_subtypes as $subtype): 
                            $subtype_slug = sanitize_key($subtype);
                            $label = isset($customizations[$subtype_slug]['label']) ? $customizations[$subtype_slug]['label'] : '';
                            $icon = isset($customizations[$subtype_slug]['icon']) ? $customizations[$subtype_slug]['icon'] : '';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($subtype); ?></strong></td>
                            <td>
                                <input type="text" 
                                       name="mld_subtype_customizations[<?php echo esc_attr($subtype_slug); ?>][label]" 
                                       value="<?php echo esc_attr($label); ?>" 
                                       placeholder="<?php echo esc_attr($subtype); ?>"
                                       class="regular-text">
                            </td>
                            <td>
                                <div class="mld-icon-uploader-wrapper">
                                    <div class="mld-image-preview" id="preview-<?php echo esc_attr($subtype_slug); ?>">
                                        <?php if ($icon): ?>
                                            <img src="<?php echo esc_url($icon); ?>" />
                                        <?php endif; ?>
                                    </div>
                                    <input type="text" 
                                           name="mld_subtype_customizations[<?php echo esc_attr($subtype_slug); ?>][icon]" 
                                           id="icon-<?php echo esc_attr($subtype_slug); ?>" 
                                           value="<?php echo esc_attr($icon); ?>" 
                                           class="regular-text mld-icon-url-input">
                                    <button type="button" 
                                            class="button mld-upload-button" 
                                            data-target-input="#icon-<?php echo esc_attr($subtype_slug); ?>" 
                                            data-target-preview="#preview-<?php echo esc_attr($subtype_slug); ?>">Upload Icon</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php submit_button( 'Save Customizations' ); ?>
        </form>
    </div>
    <?php
}
