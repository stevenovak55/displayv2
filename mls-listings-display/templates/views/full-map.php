<?php
/**
 * View for the full-screen map shortcode.
 *
 * @package MLS_Listings_Display
 */
?>
<div class='mld-fixed-wrapper'>
    <div class='bme-map-ui-wrapper'>
        <div id='bme-map-container'></div>
        <?php include MLD_PLUGIN_PATH . 'templates/partials/map-ui.php'; ?>
    </div>
    <div id='bme-popup-container'></div>
</div>
