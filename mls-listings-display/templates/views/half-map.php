<?php
/**
 * View for the half-map shortcode.
 *
 * @package MLS_Listings_Display
 */
?>
<div class="mld-fixed-wrapper">
    <div id="bme-half-map-wrapper">
        <div class="bme-map-ui-wrapper bme-map-half">
            <div id="bme-map-container"></div>
            <?php include MLD_PLUGIN_PATH . 'templates/partials/map-ui.php'; ?>
        </div>
        <div id="bme-listings-list-container">
            <div class="bme-listings-grid">
                <p class="bme-list-placeholder">Use the search bar or move the map to see listings.</p>
            </div>
        </div>
    </div>
    <div id="bme-popup-container"></div>
</div>
