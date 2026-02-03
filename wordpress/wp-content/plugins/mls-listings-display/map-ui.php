<?php
/**
 * Template part for the map user interface.
 * v4.4.0
 * - NEW: Added navigation menu drawer for search pages (v6.25.0)
 * - FIX: Corrected HTML structure to resolve major layout bug in half-map view.
 * - FIX: Ensured new filters use original CSS classes for consistent styling.
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
    'Land' => 'Land',
    'Commercial Sale' => 'Commercial Sale',
    'Commercial Lease' => 'Commercial Lease',
    'Business Opportunity' => 'Business Opportunity'
];
?>
<div id="bme-top-bar">
    <!-- Navigation Menu Button (v6.25.0) -->
    <button id="mld-nav-toggle" class="mld-nav-toggle" aria-controls="mld-nav-drawer" aria-expanded="false" aria-label="<?php esc_attr_e('Open navigation menu', 'mls-listings-display'); ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
        </svg>
    </button>

    <?php if ($logo_url): ?>
    <div id="bme-logo-container">
        <a href="<?php echo esc_url(home_url('/')); ?>" title="Go to Home Page">
            <img src="<?php echo $logo_url; ?>" alt="Company Logo">
        </a>
    </div>
    <?php endif; ?>
    
    <div id="bme-search-controls-container">
        <div id="bme-search-wrapper">
            <div id="bme-search-bar-wrapper">
                <input type="text" id="bme-search-input" placeholder="City, Address, Neighborhood, Building, MLS#">
                <div id="bme-autocomplete-suggestions"></div>
            </div>
        </div>
        
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

<!-- Map Controls Panel -->
<div id="bme-map-controls-panel" class="bme-map-controls-panel">
    <div class="bme-controls-header">
        <span class="bme-controls-title">
            <svg class="bme-controls-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18M3 6h18M3 18h18"/>
            </svg>
            Map Options
        </span>
        <button class="bme-controls-toggle" aria-label="Toggle map options">
            <svg class="bme-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 9l6 6 6-6"/>
            </svg>
        </button>
    </div>

    <div class="bme-controls-content">
        <!-- Satellite Toggle -->
        <div class="bme-control-item">
            <div class="bme-control-label">
                <span class="bme-control-icon">🛰️</span>
                <span>Satellite</span>
            </div>
            <label class="bme-toggle-switch">
                <input type="checkbox" id="bme-satellite-toggle">
                <span class="bme-toggle-slider"></span>
            </label>
        </div>

        <!-- Nearby Toggle -->
        <div class="bme-control-item">
            <div class="bme-control-label">
                <span class="bme-control-icon">📍</span>
                <span>Nearby</span>
            </div>
            <label class="bme-toggle-switch">
                <input type="checkbox" id="bme-nearby-toggle">
                <span class="bme-toggle-slider"></span>
            </label>
        </div>

        <!-- Draw Toggle -->
        <div class="bme-control-item">
            <div class="bme-control-label">
                <span class="bme-control-icon">✏️</span>
                <span>Draw Area</span>
            </div>
            <div id="bme-draw-toggle" class="bme-toggle-custom">
                <div class="bme-toggle-custom-slider"></div>
            </div>
        </div>

        <!-- Schools Toggle (placeholder - will be added by JS) -->
        <div id="bme-schools-control-placeholder"></div>
    </div>
</div>

<!-- Schools Types Container - appears below control panel when schools toggle is active -->
<div id="bme-schools-types-container" class="bme-schools-types-container" style="display: none;">
    <div class="bme-schools-types">
        <div class="bme-school-type-item">
            <label>
                <input type="checkbox" value="elementary" checked>
                <span class="bme-school-icon elementary">🎒</span>
                <span>Elementary</span>
            </label>
        </div>
        <div class="bme-school-type-item">
            <label>
                <input type="checkbox" value="middle" checked>
                <span class="bme-school-icon middle">📚</span>
                <span>Middle</span>
            </label>
        </div>
        <div class="bme-school-type-item">
            <label>
                <input type="checkbox" value="high" checked>
                <span class="bme-school-icon high">🎓</span>
                <span>High</span>
            </label>
        </div>
        <div class="bme-school-type-item">
            <label>
                <input type="checkbox" value="private" checked>
                <span class="bme-school-icon private">🏫</span>
                <span>Private</span>
            </label>
        </div>
        <div class="bme-school-type-item">
            <label>
                <input type="checkbox" value="preschool" checked>
                <span class="bme-school-icon preschool">🧸</span>
                <span>Preschool</span>
            </label>
        </div>
    </div>
</div>

<!-- Separate action buttons that appear when needed -->
<div id="bme-map-action-buttons" class="bme-map-action-buttons">
    <button id="bme-reset-button" class="bme-action-button bme-reset-button" style="display: none;">Reset</button>
    <button id="bme-complete-shape-button" class="bme-action-button bme-complete-shape" style="display: none;">Complete Shape</button>
</div>

<!-- Drawing Panel -->
<div id="bme-drawing-panel" class="bme-drawing-panel">
    <div class="bme-drawing-panel-header">
        <h3>Draw Search Area</h3>
        <span class="bme-drawing-instructions">Click on the map to draw a custom search area</span>
    </div>
    <div class="bme-drawing-panel-body">
        <div id="bme-polygon-list" class="bme-polygon-list">
            <p class="bme-no-polygons">No shapes drawn yet</p>
        </div>
    </div>
</div>

<div id="bme-listings-count-indicator"></div>

<!-- Mobile Draw Button Container - Removed as draw functionality is only in map view -->

<div id="bme-filters-modal-overlay">
    <div id="bme-filters-modal-content">
        <div id="bme-filters-modal-header">
            <button id="bme-filters-modal-close" aria-label="Close Filters Modal">&times;</button>
        </div>
        <div id="bme-filters-modal-body">
            
            <div class="bme-filter-group" id="bme-modal-search-group">
                <div id="bme-search-wrapper-modal">
                    <div id="bme-search-bar-wrapper-modal">
                        <input type="text" id="bme-search-input-modal" placeholder="City, Address, Neighborhood, Building, MLS#">
                        <div id="bme-autocomplete-suggestions-modal"></div>
                    </div>
                </div>
            </div>

            <!-- Filter tags container for modal -->
            <div id="bme-filter-tags-container-modal" class="bme-filter-tags-modal"></div>

            <div class="bme-filter-group bme-filter-row-split">
                <div class="bme-filter-half" id="bme-property-type-mobile-container">
                    <label>Property Type</label>
                </div>
                <div class="bme-filter-half" id="bme-status-group">
                    <label id="bme-status-label">Status</label>
                    <div class="bme-custom-select" id="bme-filter-status-wrapper" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="bme-status-label">
                        <div class="bme-select-display" id="bme-filter-status-display">
                            <span class="bme-select-text">Active</span>
                            <span class="bme-select-arrow">▼</span>
                        </div>
                        <div class="bme-select-dropdown" id="bme-filter-status-dropdown" role="listbox" aria-labelledby="bme-status-label" style="display: none;">
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Active'); ?>" checked aria-label="Active status">
                                    <span><?php echo esc_html('Active'); ?></span>
                                    <span class="bme-filter-count" data-status="Active"></span>
                                </label>
                            </div>
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Under Agreement'); ?>" aria-label="Under Agreement status">
                                    <span><?php echo esc_html('Under Agreement'); ?></span>
                                    <span class="bme-filter-count" data-status="Under Agreement"></span>
                                </label>
                            </div>
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Sold'); ?>" aria-label="Sold status">
                                    <span><?php echo esc_html('Sold'); ?></span>
                                    <span class="bme-filter-count" data-status="Sold"></span>
                                </label>
                            </div>
                            <?php if (current_user_can('administrator')): ?>
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Canceled'); ?>" aria-label="Canceled status">
                                    <span><?php echo esc_html('Canceled'); ?></span>
                                    <span class="bme-filter-count" data-status="Canceled"></span>
                                </label>
                            </div>
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Expired'); ?>" aria-label="Expired status">
                                    <span><?php echo esc_html('Expired'); ?></span>
                                    <span class="bme-filter-count" data-status="Expired"></span>
                                </label>
                            </div>
                            <div class="bme-select-option" role="option">
                                <label>
                                    <input type="checkbox" value="<?php echo esc_attr('Withdrawn'); ?>" aria-label="Withdrawn status">
                                    <span><?php echo esc_html('Withdrawn'); ?></span>
                                    <span class="bme-filter-count" data-status="Withdrawn"></span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bme-filter-group">
                <label>Special Filters</label>
                <div class="bme-checkbox-single">
                    <label>
                        <input type="checkbox" id="bme-filter-open-house-only" value="open_house_only">
                        <span class="bme-label-text">Open House Only</span>
                        <span class="bme-filter-count" id="bme-open-house-count"></span>
                    </label>
                </div>
            </div>

            <!-- School Quality Filters (v6.30.3) - iOS-matching toggle design -->
            <div class="bme-filter-group bme-collapsible" id="bme-school-quality-group">
                <div class="bme-filter-header" data-toggle="school-quality">
                    <label>
                        <svg class="bme-section-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                        </svg>
                        Schools
                    </label>
                    <span class="bme-toggle-icon">+</span>
                </div>
                <div class="bme-filter-content" id="bme-school-quality-content" style="display: none;">
                    <p class="bme-school-description">Filter properties by school district quality or proximity to top-rated schools</p>

                    <!-- Minimum District Rating (v6.30.6) -->
                    <div class="bme-school-level-section bme-district-rating-section">
                        <div class="bme-school-level-header">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
                            </svg>
                            <span>Minimum District Rating</span>
                        </div>
                        <div class="bme-district-rating-picker">
                            <button type="button" class="bme-district-grade-btn active" data-grade="">Any</button>
                            <button type="button" class="bme-district-grade-btn" data-grade="A">A</button>
                            <button type="button" class="bme-district-grade-btn" data-grade="B+">B+</button>
                            <button type="button" class="bme-district-grade-btn" data-grade="B">B</button>
                            <button type="button" class="bme-district-grade-btn" data-grade="C+">C+</button>
                        </div>
                        <input type="hidden" id="school_grade" name="school_grade" value="">
                    </div>

                    <div class="bme-school-divider"></div>

                    <!-- Elementary School (K-4) -->
                    <div class="bme-school-level-section">
                        <div class="bme-school-level-header">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72L12 15l5-2.73v3.72z"/>
                            </svg>
                            <span>Elementary School (K-4)</span>
                        </div>
                        <div class="bme-school-toggles">
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_a_elementary" name="near_a_elementary">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A-rated school (within 1 mi)</span>
                            </label>
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_ab_elementary" name="near_ab_elementary">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A or B-rated school (within 1 mi)</span>
                            </label>
                        </div>
                    </div>

                    <div class="bme-school-divider"></div>

                    <!-- Middle School (5-8) -->
                    <div class="bme-school-level-section">
                        <div class="bme-school-level-header">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1zm0 13.5c-1.1-.35-2.3-.5-3.5-.5-1.7 0-4.15.65-5.5 1.5V8c1.35-.85 3.8-1.5 5.5-1.5 1.2 0 2.4.15 3.5.5v11.5z"/>
                            </svg>
                            <span>Middle School (5-8)</span>
                        </div>
                        <div class="bme-school-toggles">
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_a_middle" name="near_a_middle">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A-rated school (within 1 mi)</span>
                            </label>
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_ab_middle" name="near_ab_middle">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A or B-rated school (within 1 mi)</span>
                            </label>
                        </div>
                    </div>

                    <div class="bme-school-divider"></div>

                    <!-- High School (9-12) -->
                    <div class="bme-school-level-section">
                        <div class="bme-school-level-header">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/>
                            </svg>
                            <span>High School (9-12)</span>
                        </div>
                        <div class="bme-school-toggles">
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_a_high" name="near_a_high">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A-rated school (within 1 mi)</span>
                            </label>
                            <label class="bme-toggle-switch">
                                <input type="checkbox" id="near_ab_high" name="near_ab_high">
                                <span class="bme-toggle-slider"></span>
                                <span class="bme-toggle-label">Near A or B-rated school (within 1 mi)</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bme-filter-group bme-collapsible">
                <div class="bme-filter-header" data-toggle="agents">
                    <label>Agents</label>
                    <span class="bme-toggle-icon">+</span>
                </div>
                <div class="bme-filter-content" id="bme-agents-content" style="display: none;">
                    <div class="bme-agent-search-container">
                        <input type="text"
                               id="bme-agent-search-input"
                               class="bme-filter-input"
                               placeholder="Search by agent name or MLS ID..."
                               autocomplete="off">
                        <div id="bme-agent-suggestions" class="bme-autocomplete-dropdown" style="display: none;"></div>
                    </div>
                    <div id="bme-selected-agents" class="bme-selected-agents-container">
                        <!-- Selected agents will appear here as chips -->
                    </div>
                </div>
            </div>

            <div id="bme-rental-filters" class="bme-filter-group" style="display: none;">
                <label for="bme-filter-available-by">Available By</label>
                <div class="bme-filter-row">
                    <input type="date" id="bme-filter-available-by" class="bme-filter-input">
                    <label class="bme-inline-checkbox"><input type="checkbox" id="bme-filter-available-now"> Now</label>
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
                <div class="bme-button-group min-select" id="bme-filter-beds">
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
                <div class="bme-button-group min-select" id="bme-filter-baths">
                    <button data-value="0" class="active">Any</button>
                    <button data-value="1">1+</button>
                    <button data-value="1.5">1.5+</button>
                    <button data-value="2">2+</button>
                    <button data-value="2.5">2.5+</button>
                    <button data-value="3">3+</button>
                </div>
            </div>

            <div class="bme-filter-group bme-collapsible">
                <div class="bme-filter-header" data-toggle="property-details">
                    <label>Property Details</label>
                    <span class="bme-toggle-icon">-</span>
                </div>
                <div class="bme-filter-content" id="bme-property-details-content" style="display: block;">
                    <div class="bme-property-details-grid">
                        <label for="bme-filter-sqft-min">Square Feet</label>
                        <div class="bme-filter-row">
                            <input type="number" id="bme-filter-sqft-min" placeholder="Min">
                            <span>-</span>
                            <input type="number" id="bme-filter-sqft-max" placeholder="Max">
                        </div>

                        <label for="bme-filter-lot-size-min">Lot Size (sq ft)</label>
                        <div class="bme-filter-row">
                            <input type="number" id="bme-filter-lot-size-min" placeholder="Min">
                            <span>-</span>
                            <input type="number" id="bme-filter-lot-size-max" placeholder="Max">
                        </div>

                        <label for="bme-filter-year-built-min">Year Built</label>
                         <div class="bme-filter-row">
                            <input type="number" id="bme-filter-year-built-min" placeholder="Min">
                            <span>-</span>
                            <input type="number" id="bme-filter-year-built-max" placeholder="Max">
                        </div>
                        
                        <label for="bme-filter-entry-level-min">Unit Level</label>
                         <div class="bme-filter-row">
                            <input type="number" id="bme-filter-entry-level-min" placeholder="Min Level">
                            <span>-</span>
                            <input type="number" id="bme-filter-entry-level-max" placeholder="Max Level">
                        </div>
                    </div>
                </div>
            </div>

            <div class="bme-filter-group" id="bme-home-type-group">
                <label>Home Type</label>
                <div class="bme-home-type-grid" id="bme-filter-home-type">
                    <div class="bme-placeholder">Loading...</div>
                </div>
            </div>

            
            <div class="bme-filter-group bme-collapsible" id="bme-structure-type-group">
                <div class="bme-filter-header" data-toggle="structure-type">
                    <label>Structure Type</label>
                    <span class="bme-toggle-icon">+</span>
                </div>
                <div class="bme-filter-content" id="bme-structure-type-content" style="display: none;">
                    <div class="bme-checkbox-group" id="bme-filter-structure-type">
                        <div class="bme-placeholder">Loading...</div>
                    </div>
                </div>
            </div>
            
            <div class="bme-filter-group bme-collapsible" id="bme-style-group">
                <div class="bme-filter-header" data-toggle="style">
                    <label>Style</label>
                    <span class="bme-toggle-icon">+</span>
                </div>
                <div class="bme-filter-content" id="bme-style-content" style="display: none;">
                    <div class="bme-checkbox-group" id="bme-filter-architectural-style">
                        <div class="bme-placeholder">Loading...</div>
                    </div>
                </div>
            </div>

            
            <div class="bme-filter-group">
                 <label>Parking</label>
                 <div class="bme-button-group min-select" id="bme-filter-garage-spaces">
                    <button data-value="0" class="active">Any Garage</button>
                    <button data-value="1">1+</button>
                    <button data-value="2">2+</button>
                    <button data-value="3">3+</button>
                </div>
                <div class="bme-button-group min-select" id="bme-filter-parking-total" style="margin-top: 10px;">
                    <button data-value="0" class="active">Any Parking</button>
                    <button data-value="1">1+</button>
                    <button data-value="2">2+</button>
                    <button data-value="3">3+</button>
                </div>
            </div>

            <div class="bme-filter-group bme-collapsible">
                <div class="bme-filter-header" data-toggle="features-amenities">
                    <label>Features & Amenities</label>
                    <span class="bme-toggle-icon">+</span>
                </div>
                <div class="bme-filter-content" id="bme-features-amenities-content" style="display: none;">
                    <div class="bme-checkbox-group" id="bme-filter-amenities">
                        <div class="bme-placeholder">Loading...</div>
                    </div>
                </div>
            </div>

        </div>
        <div id="bme-filters-modal-footer">
            <button id="bme-clear-filters-btn" class="button-secondary">Reset All</button>
            <span class="mld-filter-count" style="display:none;"></span>
            <button id="bme-apply-filters-btn" class="button-primary">See Listings</button>
        </div>
    </div>
</div>

<!-- Navigation Drawer Overlay (v6.25.0) -->
<div id="mld-nav-overlay" class="mld-nav-overlay" aria-hidden="true"></div>

<!-- Navigation Drawer (v6.25.0) -->
<aside id="mld-nav-drawer"
       class="mld-nav-drawer"
       role="dialog"
       aria-modal="true"
       aria-label="<?php esc_attr_e('Site Navigation', 'mls-listings-display'); ?>"
       aria-hidden="true"
       tabindex="-1">

    <!-- Drawer Header -->
    <div class="mld-nav-drawer__header">
        <?php
        $drawer_logo = !empty($options['mld_logo_url']) ? esc_url($options['mld_logo_url']) : '';
        if ($drawer_logo):
        ?>
            <div class="mld-nav-drawer__logo">
                <img src="<?php echo $drawer_logo; ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </div>
        <?php elseif (has_custom_logo()): ?>
            <div class="mld-nav-drawer__logo">
                <?php the_custom_logo(); ?>
            </div>
        <?php else: ?>
            <div class="mld-nav-drawer__site-title">
                <?php bloginfo('name'); ?>
            </div>
        <?php endif; ?>

        <button class="mld-nav-drawer__close" aria-label="<?php esc_attr_e('Close menu', 'mls-listings-display'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>
    </div>

    <!-- Drawer Navigation -->
    <nav class="mld-nav-drawer__nav" aria-label="<?php esc_attr_e('Main Menu', 'mls-listings-display'); ?>">
        <?php
        wp_nav_menu(array(
            'theme_location' => 'primary',
            'menu_id'        => 'mld-drawer-menu',
            'menu_class'     => 'mld-nav-drawer__menu',
            'container'      => false,
            'fallback_cb'    => 'mld_nav_drawer_fallback_menu',
        ));
        ?>
    </nav>

    <!-- Drawer User Menu (Collapsible v6.44.0) -->
    <div class="mld-nav-drawer__user">
        <?php if (is_user_logged_in()) :
            $mld_drawer_user = wp_get_current_user();
            $mld_avatar_url = '';
            // Try to get custom avatar from agent profiles
            if (function_exists('bne_get_user_avatar_url')) {
                $mld_avatar_url = bne_get_user_avatar_url($mld_drawer_user->ID, 48);
            } else {
                $mld_avatar_url = get_avatar_url($mld_drawer_user->ID, array('size' => 48));
            }
            $mld_display_name = $mld_drawer_user->display_name ?: $mld_drawer_user->user_login;
        ?>
            <!-- Collapsible User Toggle -->
            <button type="button" class="mld-nav-drawer__user-toggle" aria-expanded="false" aria-controls="mld-drawer-user-menu">
                <img src="<?php echo esc_url($mld_avatar_url); ?>"
                     alt="<?php echo esc_attr($mld_display_name); ?>"
                     class="mld-nav-drawer__user-avatar">
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php echo esc_html($mld_display_name); ?></span>
                </div>
                <svg class="mld-nav-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                </svg>
            </button>
            <!-- Collapsible User Menu Items -->
            <nav id="mld-drawer-user-menu" class="mld-nav-drawer__user-nav mld-nav-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'mls-listings-display'); ?>">
                <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span><?php esc_html_e('My Dashboard', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-dashboard/#favorites')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                    </svg>
                    <span><?php esc_html_e('Favorites', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-dashboard/#searches')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                    </svg>
                    <span><?php esc_html_e('Saved Searches', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(get_edit_profile_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <span><?php esc_html_e('Edit Profile', 'mls-listings-display'); ?></span>
                </a>
                <?php if (current_user_can('manage_options')) : ?>
                <a href="<?php echo esc_url(admin_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                    </svg>
                    <span><?php esc_html_e('Admin', 'mls-listings-display'); ?></span>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--logout">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                    </svg>
                    <span><?php esc_html_e('Log Out', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php else : ?>
            <!-- Guest User - Collapsible Login/Register -->
            <button type="button" class="mld-nav-drawer__user-toggle mld-nav-drawer__user-toggle--guest" aria-expanded="false" aria-controls="mld-drawer-user-menu">
                <span class="mld-nav-drawer__user-avatar mld-nav-drawer__user-avatar--guest">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </span>
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php esc_html_e('Login / Register', 'mls-listings-display'); ?></span>
                </div>
                <svg class="mld-nav-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                </svg>
            </button>
            <nav id="mld-drawer-user-menu" class="mld-nav-drawer__user-nav mld-nav-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'mls-listings-display'); ?>">
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--primary">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/>
                    </svg>
                    <span><?php esc_html_e('Log In', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(wp_registration_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <span><?php esc_html_e('Create Account', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php endif; ?>
    </div>
</aside>