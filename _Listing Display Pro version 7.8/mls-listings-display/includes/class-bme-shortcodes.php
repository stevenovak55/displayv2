<?php
/**
 * Defines the shortcodes used by the plugin.
 */
class MLD_BME_Shortcodes {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_map_assets' ] );
        add_shortcode( 'bme_listings_map_view', [ $this, 'render_map_view' ] );
        add_shortcode( 'bme_listings_half_map_view', [ $this, 'render_half_map_view' ] );
        add_shortcode( 'bme_listings_list_view', [ $this, 'render_list_view' ] );
    }

    /**
     * Enqueues scripts and styles, conditionally loading the chosen map provider.
     */
    public function enqueue_map_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'bme_listings_map_view' ) || has_shortcode( $post->post_content, 'bme_listings_half_map_view' ) ) ) {
            
            $options = get_option( 'mld_settings' );
            $provider = isset( $options['mld_map_provider'] ) ? $options['mld_map_provider'] : 'mapbox';
            $mapbox_key = isset( $options['mld_mapbox_api_key'] ) ? $options['mld_mapbox_api_key'] : '';
            $google_key = isset( $options['mld_google_maps_api_key'] ) ? $options['mld_google_maps_api_key'] : '';

            if ( $provider === 'google' ) {
                wp_enqueue_script( 'google-maps', "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=marker", [], null, true );
            } else {
                wp_enqueue_script( 'mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.js', [], '2.9.1', true );
                wp_enqueue_style( 'mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.css', [], '2.9.1' );
            }

            wp_enqueue_style( 'mld-main-css', MLD_PLUGIN_URL . 'assets/css/main.css', [], MLD_VERSION );
            wp_enqueue_script( 'mld-map-view-js', MLD_PLUGIN_URL . 'assets/js/map-view.js', [ 'jquery' ], MLD_VERSION, true );

            wp_localize_script( 'mld-map-view-js', 'bmeMapData', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'security'   => wp_create_nonce( 'bme_map_nonce' ),
                'provider'   => $provider,
                'mapbox_key' => $mapbox_key,
                'google_key' => $google_key,
            ]);
        }
    }

    /**
     * Renders the full-page map view, now with containers for popups.
     */
    public function render_map_view() {
        return '<div class="mld-fixed-wrapper"><div id="bme-map-container"></div><div id="bme-popup-container"></div></div>';
    }

    /**
     * Renders the half-map, half-list view, now with containers for popups.
     */
    public function render_half_map_view() {
        ob_start();
        ?>
        <div class="mld-fixed-wrapper">
            <div id="bme-half-map-wrapper">
                <div id="bme-map-container" class="bme-map-half"></div>
                <div id="bme-listings-list-container">
                    <div class="bme-listings-grid">
                        <p class="bme-list-placeholder">Move the map to see listings.</p>
                    </div>
                </div>
            </div>
            <div id="bme-popup-container"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the standard list view.
     */
    public function render_list_view() {
        $listings = MLD_BME_Query::get_listings();
        ob_start();
        ?>
        <div class="bme-list-view-wrapper">
            <div class="bme-listings-grid">
                <?php if ( ! empty( $listings ) ) : ?>
                    <?php foreach ( $listings as $listing ) : 
                        $media = json_decode($listing->Media, true);
                        $photo_url = !empty($media[0]['MediaURL']) ? $media[0]['MediaURL'] : 'https://via.placeholder.com/400x300.png?text=No+Image';
                        $address = trim(sprintf('%s %s %s', $listing->StreetNumber, $listing->StreetName, $listing->UnitNumber));
                    ?>
                        <div class="bme-listing-card">
                            <div class="bme-card-image">
                                <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($address); ?>">
                                <div class="bme-card-price">$<?php echo number_format( (float)$listing->ListPrice ); ?></div>
                            </div>
                            <div class="bme-card-details">
                                <p class="bme-card-address"><?php echo esc_html($address); ?></p>
                                <p class="bme-card-city"><?php echo esc_html($listing->City); ?>, <?php echo esc_html($listing->StateOrProvince); ?> <?php echo esc_html($listing->PostalCode); ?></p>
                                <div class="bme-card-specs">
                                    <span><strong><?php echo (int)$listing->BedroomsTotal; ?></strong> bds</span>
                                    <span><strong><?php echo (int)$listing->BathroomsTotalInteger; ?></strong> ba</span>
                                    <span><strong><?php echo number_format((float)$listing->LivingArea); ?></strong> sqft</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p>No listings found.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
