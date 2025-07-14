<?php
/**
 * Handles all admin-facing functionality.
 *
 * @package MLS_Listings_Display
 */
class MLD_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu() {
        add_menu_page('MLS Display Settings', 'MLS Display', 'manage_options', 'mls_listings_display', [ $this, 'render_settings_page' ], 'dashicons-admin-home', 25);
        add_submenu_page('mls_listings_display', 'Icon & Label Manager', 'Icon & Label Manager', 'manage_options', 'mld_icon_manager', [ $this, 'render_icon_manager_page' ]);
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( 'toplevel_page_mls_listings_display' !== $hook_suffix && 'mls-display_page_mld_icon_manager' !== $hook_suffix ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'mld-admin-js', MLD_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], MLD_VERSION, true );
        wp_enqueue_style( 'mld-admin-css', MLD_PLUGIN_URL . 'assets/css/admin.css', [], MLD_VERSION );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( 'mld_options_group', 'mld_settings' );
        register_setting( 'mld_icon_manager_group', 'mld_subtype_customizations' );
        add_settings_section('mld_api_keys_section', 'API Keys & Settings', null, 'mld_options_group');
        add_settings_field( 'mld_logo_url', 'Display Logo', [ $this, 'render_logo_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_map_provider', 'Map Provider', [ $this, 'render_map_provider_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_mapbox_api_key', 'Mapbox API Key', [ $this, 'render_mapbox_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_google_maps_api_key', 'Google Maps API Key', [ $this, 'render_google_maps_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );
    }

    /**
     * Render the main settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        include MLD_PLUGIN_PATH . 'admin/views/settings-page.php';
    }

    /**
     * Render the icon manager page.
     */
    public function render_icon_manager_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        // Data is fetched here and passed to the view
        $all_subtypes = MLD_Query::get_all_distinct_subtypes();
        $customizations = get_option('mld_subtype_customizations', []);
        include MLD_PLUGIN_PATH . 'admin/views/icon-manager-page.php';
    }

    // --- Field Render Callbacks ---
    public function render_logo_url_field() {
        $options = get_option( 'mld_settings' );
        $logo_url = isset( $options['mld_logo_url'] ) ? esc_url( $options['mld_logo_url'] ) : '';
        echo '<input type="text" name="mld_settings[mld_logo_url]" id="mld_logo_url" value="' . $logo_url . '" class="regular-text" />';
        echo '<button type="button" class="button mld-upload-button" data-target-input="#mld_logo_url" data-target-preview="#mld-logo-preview">Upload Logo</button>';
        echo '<p class="description">Upload or choose a logo to display next to the search bar.</p>';
        echo '<div id="mld-logo-preview" class="mld-image-preview">';
        if ( $logo_url ) echo '<img src="' . $logo_url . '" />';
        echo '</div>';
    }

    public function render_map_provider_field() {
        $options = get_option( 'mld_settings' );
        $provider = $options['mld_map_provider'] ?? 'mapbox';
        echo '<select name="mld_settings[mld_map_provider]">';
        echo '<option value="mapbox"' . selected( $provider, 'mapbox', false ) . '>Mapbox</option>';
        echo '<option value="google"' . selected( $provider, 'google', false ) . '>Google Maps</option>';
        echo '</select><p class="description">Choose which mapping service to use.</p>';
    }

    public function render_mapbox_api_key_field() {
        $options = get_option( 'mld_settings' );
        $key = $options['mld_mapbox_api_key'] ?? '';
        echo "<input type='text' name='mld_settings[mld_mapbox_api_key]' value='" . esc_attr( $key ) . "' class='regular-text' />";
        echo "<p class='description'>Required if Map Provider is set to Mapbox.</p>";
    }

    public function render_google_maps_api_key_field() {
        $options = get_option( 'mld_settings' );
        $key = $options['mld_google_maps_api_key'] ?? '';
        echo "<input type='text' name='mld_settings[mld_google_maps_api_key]' value='" . esc_attr( $key ) . "' class='regular-text' />";
        echo "<p class='description'>Required if Map Provider is set to Google Maps.</p>";
    }
}
