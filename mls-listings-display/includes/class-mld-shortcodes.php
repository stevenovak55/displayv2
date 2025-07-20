<?php
/**
 * Defines the shortcodes used by the plugin.
 *
 * @package MLS_Listings_Display
 */
class MLD_Shortcodes {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_map_assets' ] );
        add_filter( 'script_loader_tag', [ $this, 'add_defer_attribute' ], 10, 2 );
        add_shortcode( 'bme_listings_map_view', [ $this, 'render_map_view' ] );
        add_shortcode( 'bme_listings_half_map_view', [ $this, 'render_half_map_view' ] );
    }

    /**
     * Enqueue scripts and styles for the map view.
     */
    public function enqueue_map_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'bme_listings_map_view' ) || has_shortcode( $post->post_content, 'bme_listings_half_map_view' ) ) ) {
            
            $options = get_option( 'mld_settings' );
            $provider = $options['mld_map_provider'] ?? 'mapbox';
            $mapbox_key = $options['mld_mapbox_api_key'] ?? '';
            $google_key = $options['mld_google_maps_api_key'] ?? '';

            if ( $provider === 'google' ) {
                $google_maps_url = "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=marker,geometry,drawing";
                wp_enqueue_script( 'google-maps-api', $google_maps_url, [], null, true );
            } else {
                wp_enqueue_script( 'mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.js', [], '2.9.1', true );
                wp_enqueue_style( 'mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.css', [], '2.9.1' );
            }

            wp_enqueue_style( 'mld-main-css', MLD_PLUGIN_URL . 'assets/css/main.css', [], MLD_VERSION );
            
            $dependencies = ['jquery'];
            if ($provider === 'google') {
                $dependencies[] = 'google-maps-api';
            } else {
                $dependencies[] = 'mapbox-gl';
            }

            wp_enqueue_script( 'mld-map-api', MLD_PLUGIN_URL . 'assets/js/map-api.js', $dependencies, MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-filters', MLD_PLUGIN_URL . 'assets/js/map-filters.js', ['mld-map-api'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-core', MLD_PLUGIN_URL . 'assets/js/map-core.js', ['mld-map-filters'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-markers', MLD_PLUGIN_URL . 'assets/js/map-markers.js', ['mld-map-core'], MLD_VERSION, true );

            $boolean_fields = [
                'open_house_only', 'SpaYN', 'WaterfrontYN', 'ViewYN', 'MLSPIN_WATERVIEW_FLAG', 
                'PropertyAttachedYN', 'MLSPIN_LENDER_OWNED', 'SeniorCommunityYN', 
                'MLSPIN_OUTDOOR_SPACE_AVAILABLE', 'MLSPIN_DPR_Flag', 'CoolingYN'
            ];
            $field_labels = [];
            foreach ($boolean_fields as $field) {
                $field_labels[$field] = MLD_Utils::get_field_label($field);
            }

            wp_localize_script( 'mld-map-api', 'bmeMapData', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'security'   => wp_create_nonce( 'bme_map_nonce' ),
                'provider'   => $provider,
                'mapbox_key' => $mapbox_key,
                'google_key' => $google_key,
                'subtype_customizations' => get_option('mld_subtype_customizations', []),
                'field_labels' => $field_labels
            ]);
        }
    }

    /**
     * Adds async and defer attributes to script tags for performance.
     */
    public function add_defer_attribute( $tag, $handle ) {
        if ( in_array($handle, ['mld-map-core', 'google-maps-api', 'mld-map-api', 'mld-map-markers', 'mld-map-filters']) ) {
            return str_replace( ' src', ' async defer src', $tag );
        }
        return $tag;
    }

    /**
     * Render the full-screen map view.
     */
    public function render_map_view() {
        ob_start();
        include MLD_PLUGIN_PATH . 'templates/views/full-map.php';
        return ob_get_clean();
    }

    /**
     * Render the half map, half list view.
     */
    public function render_half_map_view() {
        ob_start();
        include MLD_PLUGIN_PATH . 'templates/views/half-map.php';
        return ob_get_clean();
    }
}
