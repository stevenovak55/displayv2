<?php
/**
 * Template part for the map user interface.
 *
 * @package MLS_Listings_Display
 */

$options = get_option('mld_settings');
$logo_url = !empty($options['mld_logo_url']) ? esc_url($options['mld_logo_url']) : '';

if ( is_ssl() && !empty($logo_url) ) {
    $logo_url = str_replace('http://', 'https://', $logo_url);
}

$filter_icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>';

$property_types = [
    'For Sale' => 'Residential',
    'For Rent' => 'Residential Lease',
    'Residential Income' => 'Residential Income',
    'Land' => 'Land',
    'Commercial Sale' => 'Commercial Sale',
    'Commercial Lease' => 'Commercial Lease',
    'Business Opportunity' => 'Business Opportunity'
];
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
                
                <div class="bme-nearby-toggle-container">
                    <label for="bme-nearby-toggle" class="bme-nearby-label">Nearby</label>
                    <label class="bme-switch">
                        <input type="checkbox" id="bme-nearby-toggle">
                        <span class="bme-slider"></span>
                    </label>
                </div>

                <div id="bme-autocomplete-suggestions"></div>
            </div>
        </div>
        
        <!-- This container is for the desktop view -->
        <div id="bme-property-type-desktop-container" class="bme-mode-select-wrapper">
             <select id="bme-property-type-select" class="bme-control-select">
                <?php foreach ($property_types as $label => $value): ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
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
            <button id="bme-filters-modal-close" aria-label="Close Filters Modal">&times;</button>
        </div>
        <div id="bme-filters-modal-body">
            
            <div class="bme-filter-group" id="bme-modal-search-group">
                <div id="bme-search-wrapper-modal">
                    <div id="bme-search-bar-wrapper-modal">
                        <input type="text" id="bme-search-input-modal" placeholder="City, Address, School, ZIP, Agent, ID">
                        <div id="bme-autocomplete-suggestions-modal"></div>
                    </div>
                </div>
            </div>

            <!-- This container is for the mobile view -->
            <div class="bme-filter-group" id="bme-property-type-mobile-container">
                <label>Property Type</label>
                <!-- The select dropdown will be moved here by JavaScript on mobile -->
            </div>

            <div id="bme-rental-filters" class="bme-filter-group" style="display: none;">
                <label for="bme-filter-available-by">Available By</label>
                <div class="bme-filter-row single-input">
                    <input type="date" id="bme-filter-available-by" class="bme-filter-input">
                </div>
            </div>

            <div class="bme-filter-group">
                <label>Price</label>
                <div id="bme-price-filter-container">
                    <div id="bme-price-histogram">
                        <div class="bme-placeholder">Loading price data...</div>
                    </div>
                    <div id="bme-price-slider">
                        <div id="bme-price-slider-track"></div>
                        <div id="bme-price-slider-range"></div>
                        <div id="bme-price-slider-handle-min" class="bme-price-slider-handle"></div>
                        <div id="bme-price-slider-handle-max" class="bme-price-slider-handle"></div>
                    </div>
                    <div class="bme-filter-row">
                        <input type="text" id="bme-filter-price-min" placeholder="Min" data-raw-value="">
                        <span>-</span>
                        <input type="text" id="bme-filter-price-max" placeholder="Max" data-raw-value="">
                    </div>
                    <p class="bme-input-note">Use these fields to set a price outside the slider's range.</p>
                </div>
            </div>

            <div class="bme-filter-group">
                <label>Beds</label>
                <div class="bme-button-group multi-select" id="bme-filter-beds">
                    <button data-value="0" class="active">Any</button>
                    <button data-value="1">1</button>
                    <button data-value="2">2</button>
                    <button data-value="3">3</button>
                    <button data-value="4">4</button>
                    <button data-value="5">5+</button>
                </div>
            </div>

            <div class="bme-filter-group">
                <label>Baths</label>
                <div class="bme-button-group min-select" id="bme-filter-baths">
                    <button data-value="0" class="active">Any</button>
                    <button data-value="1">1+</button>
                    <button data-value="1.5">1.5+</button>
                    <button data-value="2">2+</button>
                    <button data-value="2.5">2.5+</button>
                    <button data-value="3">3+</button>
                </div>
            </div>

            <div class="bme-filter-group" id="bme-home-type-group">
                <label>Home Type</label>
                <div class="bme-home-type-grid" id="bme-filter-home-type">
                    <div class="bme-placeholder">Loading...</div>
                </div>
            </div>
            
            <div class="bme-filter-group" id="bme-status-filter-group">
                <label>Status</label>
                <div class="bme-checkbox-group" id="bme-filter-status">
                    <div class="bme-placeholder">Loading...</div>
                </div>
            </div>

            <div class="bme-filter-group">
                <label>Property Details</label>
                <div class="bme-property-details-grid">
                    <label for="bme-filter-keywords">Keyword Search</label>
                    <div class="bme-filter-row single-input">
                        <input type="text" id="bme-filter-keywords" placeholder="e.g. 'corner lot', 'renovated kitchen'">
                    </div>

                    <label>Square Feet</label>
                    <div class="bme-filter-row">
                        <input type="number" id="bme-filter-sqft-min" placeholder="Min">
                        <span>-</span>
                        <input type="number" id="bme-filter-sqft-max" placeholder="Max">
                    </div>

                    <label>Year Built</label>
                     <div class="bme-filter-row">
                        <input type="number" id="bme-filter-year-built-min" placeholder="Min">
                        <span>-</span>
                        <input type="number" id="bme-filter-year-built-max" placeholder="Max">
                    </div>

                    <label>Stories</label>
                    <div class="bme-filter-row single-input">
                        <select id="bme-filter-stories">
                            <option value="">Any</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3+</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="bme-filter-group">
                <label>Amenities</label>
                <div class="bme-checkbox-group" id="bme-filter-amenities">
                    <label><input type="checkbox" value="WaterfrontYN"> Waterfront</label>
                    <label><input type="checkbox" value="pool_only"> Has Pool</label>
                    <label><input type="checkbox" value="GarageYN"> Has Garage</label>
                    <label><input type="checkbox" value="FireplaceYN"> Has Fireplace</label>
                    <label><input type="checkbox" value="open_house_only"> Has Open House</label>
                </div>
            </div>

        </div>
        <div id="bme-filters-modal-footer">
            <button id="bme-clear-filters-btn" class="button-secondary">Reset All</button>
            <button id="bme-apply-filters-btn" class="button-primary">See Listings</button>
        </div>
    </div>
</div>
