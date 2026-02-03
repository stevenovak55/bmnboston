<?php
/**
 * View for the half-map shortcode.
 *
 * @package MLS_Listings_Display
 * @version 5.0.0 - Added loading indicator (2025-11-26)
 */
?>
<div class="mld-fixed-wrapper">
    <div id="bme-half-map-wrapper">
        <div class="bme-map-ui-wrapper bme-map-half">
            <div id="bme-map-container">
                <!-- Loading indicator (v5.0) -->
                <div id="mld-map-loading" class="mld-map-loading" style="display: none;">
                    <div class="mld-loading-spinner"></div>
                    <span class="mld-loading-text">Loading properties...</span>
                </div>
            </div>
            <?php include MLD_PLUGIN_PATH . 'templates/partials/map-ui.php'; ?>
        </div>
        <div class="bme-resize-handle" id="bme-resize-handle">
            <div class="bme-resize-handle-bar"></div>
            <div class="bme-resize-handle-tooltip">Drag to resize</div>
        </div>
        <div id="bme-listings-list-container">
            <div class="bme-listings-grid">
                <p class="bme-list-placeholder">Use the search bar or move the map to see listings.</p>
            </div>
        </div>
    </div>
    <div id="bme-popup-container"></div>
    
    <!-- Mobile View Mode Toggle - Bottom Fixed -->
    <div id="bme-view-mode-toggle" class="bme-view-mode-toggle">
        <button class="bme-view-mode-btn active" data-mode="list">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <rect x="4" y="5" width="16" height="2" />
                <rect x="4" y="11" width="16" height="2" />
                <rect x="4" y="17" width="16" height="2" />
            </svg>
            <span>List View</span>
        </button>
        <button class="bme-view-mode-btn" data-mode="map">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
            </svg>
            <span>Map View</span>
        </button>
    </div>
</div>
