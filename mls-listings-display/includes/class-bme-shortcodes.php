<?php
/**
 * Defines the shortcodes used by the plugin.
 *
 * v1.6.0 
 * - FEAT: Builds out the full HTML structure for the "More Filters" modal.
 * - FIX: Ensures the logo URL is served over HTTPS to prevent mixed content warnings.
 * - FIX: Introduces a wrapper div to correctly scope the floating UI over the map.
 */
class MLD_BME_Shortcodes {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_map_assets' ] );
        add_shortcode( 'bme_listings_map_view', [ $this, 'render_map_view' ] );
        add_shortcode( 'bme_listings_half_map_view', [ $this, 'render_half_map_view' ] );
        add_shortcode( 'bme_listings_list_view', [ $this, 'render_list_view' ] );
    }

    public function enqueue_map_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'bme_listings_map_view' ) || has_shortcode( $post->post_content, 'bme_listings_half_map_view' ) ) ) {
            
            $options = get_option( 'mld_settings' );
            $provider = $options['mld_map_provider'] ?? 'mapbox';
            $mapbox_key = $options['mld_mapbox_api_key'] ?? '';
            $google_key = $options['mld_google_maps_api_key'] ?? '';

            if ( $provider === 'google' ) {
                wp_enqueue_script( 'google-maps', "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=marker,geometry", [], null, true );
            } else {
                wp_enqueue_script( 'mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.js', [], '2.9.1', true );
                wp_enqueue_style( 'mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v2.9.1/mapbox-gl.css', [], '2.9.1' );
            }

            wp_enqueue_style( 'mld-main-css', MLD_PLUGIN_URL . 'assets/css/main.css', [], '1.6.0' );
            wp_enqueue_script( 'mld-map-view-js', MLD_PLUGIN_URL . 'assets/js/map-view.js', [ 'jquery' ], '1.6.0', true );

            wp_localize_script( 'mld-map-view-js', 'bmeMapData', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'security'   => wp_create_nonce( 'bme_map_nonce' ),
                'provider'   => $provider,
                'mapbox_key' => $mapbox_key,
                'google_key' => $google_key,
            ]);
        }
    }

    public function render_map_view() {
        $ui_html = $this->get_map_ui_html();
        return "
        <div class='mld-fixed-wrapper'>
            <div class='bme-map-ui-wrapper'>
                <div id='bme-map-container'></div>
                {$ui_html}
            </div>
            <div id='bme-popup-container'></div>
        </div>";
    }

    public function render_half_map_view() {
        $ui_html = $this->get_map_ui_html();
        ob_start();
        ?>
        <div class="mld-fixed-wrapper">
            <div id="bme-half-map-wrapper">
                <div class="bme-map-ui-wrapper bme-map-half">
                    <div id="bme-map-container"></div>
                    <?php echo $ui_html; ?>
                </div>
                <div id="bme-listings-list-container">
                    <div class="bme-listings-grid">
                        <p class="bme-list-placeholder">Use the search bar or move the map to see listings.</p>
                    </div>
                </div>
            </div>
            <div id="bme-popup-container"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function get_map_ui_html() {
        $options = get_option('mld_settings');
        $logo_url = !empty($options['mld_logo_url']) ? esc_url($options['mld_logo_url']) : '';
        
        if ( is_ssl() && !empty($logo_url) ) {
            $logo_url = str_replace('http://', 'https://', $logo_url);
        }

        $filter_icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>';

        ob_start();
        ?>
        <div id="bme-top-bar">
            <?php if ($logo_url): ?>
            <div id="bme-logo-container">
                <img src="<?php echo $logo_url; ?>" alt="Company Logo">
            </div>
            <?php endif; ?>
            
            <div id="bme-search-controls-container">
                <div id="bme-search-wrapper">
                    <div id="bme-search-bar-wrapper">
                        <input type="text" id="bme-search-input" placeholder="City, Address, School, ZIP, Agent, ID">
                        <div id="bme-autocomplete-suggestions"></div>
                    </div>
                </div>
                <button id="bme-filters-button" class="bme-control-button" aria-label="More Filters">
                    <?php echo $filter_icon_svg; ?>
                </button>
            </div>
        </div>

        <div id="bme-filter-tags-container"></div>

        <div id="bme-filters-modal-overlay">
            <div id="bme-filters-modal-content">
                <div id="bme-filters-modal-header">
                    <h2>More Filters</h2>
                    <button id="bme-filters-modal-close" aria-label="Close Filters Modal">&times;</button>
                </div>
                <div id="bme-filters-modal-body">
                    
                    <div class="bme-filter-group">
                        <label>Price Range</label>
                        <div class="bme-filter-row">
                            <input type="number" id="bme-filter-price-min" placeholder="Min Price" min="0" step="10000">
                            <span>-</span>
                            <input type="number" id="bme-filter-price-max" placeholder="Max Price" min="0" step="10000">
                        </div>
                    </div>

                    <div class="bme-filter-group">
                        <label>Beds</label>
                        <div class="bme-button-group" id="bme-filter-beds">
                            <button data-value="0" class="active">Any</button>
                            <button data-value="1">1+</button>
                            <button data-value="2">2+</button>
                            <button data-value="3">3+</button>
                            <button data-value="4">4+</button>
                            <button data-value="5">5+</button>
                        </div>
                    </div>

                    <div class="bme-filter-group">
                        <label>Baths</label>
                        <div class="bme-button-group" id="bme-filter-baths">
                            <button data-value="0" class="active">Any</button>
                            <button data-value="1">1+</button>
                            <button data-value="2">2+</button>
                            <button data-value="3">3+</button>
                            <button data-value="4">4+</button>
                        </div>
                    </div>

                    <div class="bme-filter-group">
                        <label>Home Type</label>
                        <div class="bme-checkbox-group" id="bme-filter-home-type">
                            <label><input type="checkbox" value="Residential"> Residential</label>
                            <label><input type="checkbox" value="Residential Lease"> Residential Lease</label>
                            <label><input type="checkbox" value="Residential Income"> Residential Income</label>
                            <label><input type="checkbox" value="Land"> Land</label>
                            <label><input type="checkbox" value="Commercial Sale"> Commercial Sale</label>
                            <label><input type="checkbox" value="Commercial Lease"> Commercial Lease</label>
                        </div>
                    </div>
                    
                    <div class="bme-filter-group">
                        <label>Status</label>
                        <div class="bme-checkbox-group" id="bme-filter-status">
                            <label><input type="checkbox" value="Active" checked> Active</label>
                            <label><input type="checkbox" value="Active Under Contract"> Active Under Contract</label>
                            <label><input type="checkbox" value="Pending"> Pending</label>
                            <label><input type="checkbox" value="Closed"> Closed</label>
                        </div>
                    </div>

                </div>
                <div id="bme-filters-modal-footer">
                    <button id="bme-clear-filters-btn" class="button-secondary">Clear All</button>
                    <button id="bme-apply-filters-btn" class="button-primary">Apply Filters</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_list_view() {
        // This function remains unchanged
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
                            </div>
                            <div class="bme-card-details">
                                <div class="bme-card-price">$<?php echo number_format( (float)$listing->ListPrice ); ?></div>
                                <p class="bme-card-address"><?php echo esc_html($address); ?></p>
                                <p class="bme-card-city"><?php echo esc_html($listing->City); ?>, <?php echo esc_html($listing->StateOrProvince); ?> <?php echo esc_html($listing->PostalCode); ?></p>
                                <div class="bme-card-specs">
                                    <span><strong><?php echo (int)$listing->BedroomsTotal; ?></strong> bds</span>
                                    <span class="bme-spec-divider">|</span>
                                    <span><strong><?php echo (int)$listing->BathroomsTotalInteger; ?></strong> ba</span>
                                    <span class="bme-spec-divider">|</span>
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
