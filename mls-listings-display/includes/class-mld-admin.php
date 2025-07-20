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
        add_submenu_page('mls_listings_display', 'Agent Contacts', 'Agent Contacts', 'manage_options', 'mld_agent_contacts', [ $this, 'render_agent_contacts_page' ]);
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_assets( $hook_suffix ) {
        // Load assets on all plugin pages
        if ( strpos($hook_suffix, 'mls_listings_display') === false && strpos($hook_suffix, 'mld_icon_manager') === false && strpos($hook_suffix, 'mld_agent_contacts') === false) {
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
        // Main settings
        register_setting( 'mld_options_group', 'mld_settings' );
        add_settings_section('mld_api_keys_section', 'API Keys & Settings', null, 'mld_options_group');
        add_settings_field( 'mld_logo_url', 'Display Logo', [ $this, 'render_logo_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_map_provider', 'Map Provider', [ $this, 'render_map_provider_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_mapbox_api_key', 'Mapbox API Key', [ $this, 'render_mapbox_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_google_maps_api_key', 'Google Maps API Key', [ $this, 'render_google_maps_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );

        // Icon Manager settings
        register_setting( 'mld_icon_manager_group', 'mld_subtype_customizations' );
        
        // Agent Contact settings
        register_setting( 'mld_agent_contacts_group', 'mld_contact_settings', [$this, 'sanitize_contact_settings']);
        add_settings_section('mld_agents_section', 'Site Agent Contact Information', [$this, 'render_agents_section_text'], 'mld_agent_contacts_group');

        for ($i = 1; $i <= 5; $i++) {
            add_settings_field( "mld_agent_{$i}", "Agent {$i}", [$this, 'render_agent_fields'], 'mld_agent_contacts_group', 'mld_agents_section', ['agent_index' => $i - 1] );
        }
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
        include MLD_PLUGIN_PATH . 'admin/views/icon-manager-page.php';
    }

    /**
     * Render the new agent contacts page.
     */
    public function render_agent_contacts_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Agent Contact Information</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'mld_agent_contacts_group' );
                do_settings_sections( 'mld_agent_contacts_group' );
                submit_button( 'Save Agent Contacts' );
                ?>
            </form>
        </div>
        <?php
    }

    public function render_agents_section_text() {
        echo '<p>Enter the contact information for the agents you want to display on the property details page sidebar. Leave fields blank for unused agent slots.</p>';
    }

    /**
     * Render the fields for a single agent.
     */
    public function render_agent_fields($args) {
        $options = get_option('mld_contact_settings');
        $index = $args['agent_index'];

        $name = isset($options[$index]['name']) ? $options[$index]['name'] : '';
        $email = isset($options[$index]['email']) ? $options[$index]['email'] : '';
        $phone = isset($options[$index]['phone']) ? $options[$index]['phone'] : '';
        $photo = isset($options[$index]['photo']) ? $options[$index]['photo'] : '';
        ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
            <p>
                <label for="agent_name_<?php echo $index; ?>">Name:</label><br>
                <input type="text" id="agent_name_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][name]" value="<?php echo esc_attr($name); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_email_<?php echo $index; ?>">Email:</label><br>
                <input type="email" id="agent_email_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][email]" value="<?php echo esc_attr($email); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_phone_<?php echo $index; ?>">Phone:</label><br>
                <input type="text" id="agent_phone_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][phone]" value="<?php echo esc_attr($phone); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_photo_<?php echo $index; ?>">Photo URL:</label><br>
                <input type="text" id="agent_photo_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][photo]" value="<?php echo esc_url($photo); ?>" class="regular-text mld-icon-url-input">
                <button type="button" class="button mld-upload-button" data-target-input="#agent_photo_<?php echo $index; ?>" data-target-preview="#agent-photo-preview-<?php echo $index; ?>">Upload Photo</button>
                <div id="agent-photo-preview-<?php echo $index; ?>" class="mld-image-preview">
                    <?php if ($photo) echo '<img src="' . esc_url($photo) . '" />'; ?>
                </div>
            </p>
        </div>
        <?php
    }

    /**
     * Sanitize the agent contact settings.
     */
    public function sanitize_contact_settings($input) {
        $sanitized_input = [];
        if (is_array($input)) {
            foreach ($input as $index => $agent_data) {
                if (!empty($agent_data['name']) || !empty($agent_data['email'])) {
                    $sanitized_input[$index]['name'] = sanitize_text_field($agent_data['name']);
                    $sanitized_input[$index]['email'] = sanitize_email($agent_data['email']);
                    $sanitized_input[$index]['phone'] = sanitize_text_field($agent_data['phone']);
                    $sanitized_input[$index]['photo'] = esc_url_raw($agent_data['photo']);
                }
            }
        }
        return $sanitized_input;
    }

    // --- Field Render Callbacks for Main Settings ---
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
