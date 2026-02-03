<?php
/**
 * View for the full-screen map shortcode.
 *
 * @package MLS_Listings_Display
 * @version 5.0.0 - Added loading indicator (2025-11-26)
 */
?>
<div class='mld-fixed-wrapper'>
    <div class='bme-map-ui-wrapper'>
        <div id='bme-map-container'>
            <!-- Loading indicator (v5.0) -->
            <div id="mld-map-loading" class="mld-map-loading" style="display: none;">
                <div class="mld-loading-spinner"></div>
                <span class="mld-loading-text">Loading properties...</span>
            </div>
        </div>
        <?php include MLD_PLUGIN_PATH . 'templates/partials/map-ui.php'; ?>
    </div>
    <div id='bme-popup-container'></div>
</div>
