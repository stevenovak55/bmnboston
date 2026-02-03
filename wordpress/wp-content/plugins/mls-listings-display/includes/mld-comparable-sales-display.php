<?php
/**
 * Comparable Sales Display Component
 *
 * Provides the HTML template for enhanced comparable sales with filters
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the enhanced comparable sales section
 *
 * @param array $subject_property Subject property data
 * @param array $session_data Optional saved CMA session data (for standalone CMAs)
 * @since 6.20.2 Added $session_data parameter for standalone CMA support
 */
function mld_render_comparable_sales($subject_property, $session_data = null) {
    // Prepare subject property data for JavaScript
    $subject_data = array(
        'listing_id' => $subject_property['mlsNumber'] ?? '',
        'lat' => floatval($subject_property['lat'] ?? 0),
        'lng' => floatval($subject_property['lng'] ?? 0),
        'price' => floatval($subject_property['price'] ?? 0),
        'beds' => intval($subject_property['beds'] ?? 0),
        'baths' => floatval($subject_property['baths'] ?? 0),
        'sqft' => intval($subject_property['sqft'] ?? 0),
        'property_type' => $subject_property['propertyType'] ?? '',
        'year_built' => intval($subject_property['yearBuilt'] ?? 0),
        'garage_spaces' => intval($subject_property['garageSpaces'] ?? 0),
        'pool' => !empty($subject_property['pool']),
        'waterfront' => !empty($subject_property['waterfront']),
        // Default to 'unknown' if not set (allows users to flag actual road type/condition)
        'road_type' => $subject_property['roadType'] ?? 'unknown',
        'property_condition' => $subject_property['propertyCondition'] ?? 'unknown',
        'city' => $subject_property['city'] ?? '',
        'state' => $subject_property['state'] ?? 'MA'
    );
    ?>

    <!-- Enhanced Comparable Sales Section -->
    <div class="mld-comparable-sales-wrapper" id="mld-comparable-sales">

        <!-- Filter Panel -->
        <div class="mld-comp-filter-panel" id="mld-comp-filters">
            <div class="mld-comp-filter-header">
                <h3>Customize Comparable Search</h3>
                <button class="mld-comp-filter-toggle" aria-expanded="false">
                    <span class="filter-label">Show Filters</span>
                    <svg class="filter-icon" width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M3 5h14M6 10h8M9 15h2"/>
                    </svg>
                </button>
            </div>

            <div class="mld-comp-filter-content" style="display: none;">
                <form id="mld-comp-filter-form">

                    <!-- Distance & Location -->
                    <div class="mld-filter-section">
                        <h4>Distance & Location</h4>
                        <div class="mld-filter-row">
                            <label for="comp-radius">Search Radius: <span id="radius-value">3</span> miles</label>
                            <input type="range" id="comp-radius" name="radius" min="0.5" max="25" step="0.5" value="3">
                        </div>
                        <div class="mld-filter-row">
                            <label>
                                <input type="checkbox" name="same_city_only" value="1">
                                Same city only (<?php echo esc_html($subject_data['city']); ?>)
                            </label>
                        </div>
                    </div>

                    <!-- Property Characteristics -->
                    <div class="mld-filter-section">
                        <h4>Property Characteristics</h4>
                        <div class="mld-filter-row">
                            <label>Bedrooms:</label>
                            <div class="mld-filter-inline">
                                <label>
                                    <input type="checkbox" name="beds_exact" value="1">
                                    Exact match (<?php echo $subject_data['beds']; ?>)
                                </label>
                                <span class="filter-separator">OR</span>
                                <input type="number" name="beds_min" placeholder="Min" min="0" max="10">
                                <span>to</span>
                                <input type="number" name="beds_max" placeholder="Max" min="0" max="10">
                            </div>
                        </div>

                        <div class="mld-filter-row">
                            <label>Bathrooms:</label>
                            <div class="mld-filter-inline">
                                <input type="number" name="baths_min" placeholder="Min" min="0" max="10" step="0.5">
                                <span>to</span>
                                <input type="number" name="baths_max" placeholder="Max" min="0" max="10" step="0.5">
                            </div>
                        </div>

                        <div class="mld-filter-row">
                            <label for="comp-sqft-range">Square Footage Range: ±<span id="sqft-value">20</span>%</label>
                            <input type="range" id="comp-sqft-range" name="sqft_range_pct" min="10" max="50" step="5" value="20">
                        </div>

                        <div class="mld-filter-row">
                            <label>Garage Spaces:</label>
                            <div class="mld-filter-inline">
                                <label>
                                    <input type="checkbox" name="garage_exact" value="1">
                                    Exact match (<?php echo $subject_data['garage_spaces']; ?>)
                                </label>
                                <span class="filter-separator">OR</span>
                                <input type="number" name="garage_min" placeholder="Min" min="0" max="6">
                            </div>
                        </div>
                    </div>

                    <!-- Price & Value -->
                    <div class="mld-filter-section">
                        <h4>Price & Value</h4>
                        <div class="mld-filter-row">
                            <label for="comp-price-range">Price Range: ±<span id="price-value">15</span>%</label>
                            <input type="range" id="comp-price-range" name="price_range_pct" min="10" max="50" step="5" value="15">
                        </div>
                        <div class="mld-filter-row">
                            <label>
                                <input type="checkbox" name="exclude_hoa" value="1">
                                Exclude HOA properties
                            </label>
                            <span class="filter-separator">OR</span>
                            <input type="number" name="hoa_max" placeholder="Max HOA/month" min="0" step="50">
                        </div>
                    </div>

                    <!-- Property Features -->
                    <div class="mld-filter-section">
                        <h4>Property Features</h4>
                        <div class="mld-filter-row">
                            <label for="comp-year-range">Year Built Range: ±<span id="year-value">10</span> years</label>
                            <input type="range" id="comp-year-range" name="year_built_range" min="5" max="30" step="5" value="10">
                        </div>
                        <div class="mld-filter-row">
                            <label>Lot Size (acres):</label>
                            <div class="mld-filter-inline">
                                <input type="number" name="lot_size_min" placeholder="Min" min="0" step="0.1">
                                <span>to</span>
                                <input type="number" name="lot_size_max" placeholder="Max" min="0" step="0.1">
                            </div>
                        </div>
                        <div class="mld-filter-row">
                            <label>Pool:</label>
                            <div class="mld-filter-inline">
                                <label><input type="radio" name="pool_required" value="" checked> Any</label>
                                <label><input type="radio" name="pool_required" value="1"> Required</label>
                                <label><input type="radio" name="pool_required" value="0"> No Pool</label>
                            </div>
                        </div>
                        <div class="mld-filter-row">
                            <label>
                                <input type="checkbox" name="waterfront_only" value="1">
                                Waterfront only
                            </label>
                        </div>
                    </div>

                    <!-- Market Filters -->
                    <div class="mld-filter-section">
                        <h4>Market Filters</h4>
                        <div class="mld-filter-row">
                            <label>Status:</label>
                            <div class="mld-filter-inline">
                                <label><input type="checkbox" name="statuses[]" value="Closed" checked> Sold</label>
                                <label><input type="checkbox" name="statuses[]" value="Pending"> Pending</label>
                                <label><input type="checkbox" name="statuses[]" value="Active Under Contract"> Under Agreement</label>
                                <label><input type="checkbox" name="statuses[]" value="Active"> Active</label>
                            </div>
                        </div>
                        <div class="mld-filter-row">
                            <label for="comp-months-back">Time Range:</label>
                            <select id="comp-months-back" name="months_back">
                                <option value="3">Last 3 months</option>
                                <option value="6">Last 6 months</option>
                                <option value="12" selected>Last 12 months</option>
                                <option value="24">Last 24 months</option>
                            </select>
                        </div>
                        <div class="mld-filter-row">
                            <label>Max Days on Market (Active/Pending only):</label>
                            <input type="number" name="max_dom" placeholder="e.g., 90" min="0">
                        </div>
                    </div>

                    <!-- Display Options -->
                    <div class="mld-filter-section">
                        <h4>Display Options</h4>
                        <div class="mld-filter-row">
                            <label for="comp-sort">Sort By:</label>
                            <select id="comp-sort" name="sort_by">
                                <option value="similarity">Best Match (Similarity Score)</option>
                                <option value="price_asc">Price: Low to High</option>
                                <option value="price_desc">Price: High to Low</option>
                                <option value="distance">Distance: Nearest First</option>
                                <option value="date_desc">Most Recent Sales</option>
                            </select>
                        </div>
                        <div class="mld-filter-row">
                            <label for="comp-limit">Results Per Page:</label>
                            <select id="comp-limit" name="limit">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="30">30</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="mld-filter-actions">
                        <button type="submit" class="mld-btn-primary">Apply Filters</button>
                        <button type="reset" class="mld-btn-secondary">Reset to Defaults</button>
                    </div>

                </form>
            </div>
        </div>

        <!-- Quick Filters & Sort Bar -->
        <div class="mld-comp-toolbar" id="mld-comp-toolbar" style="display: none;">
            <div class="mld-comp-toolbar-section">
                <div class="mld-comp-toolbar-label">Quick Filters:</div>
                <div class="mld-comp-quick-filters" id="mld-quick-filters">
                    <button class="mld-quick-filter" data-filter="grade-a">
                        <span class="filter-icon">⭐</span> A-Grade Only
                    </button>
                    <button class="mld-quick-filter" data-filter="nearby">
                        <span class="filter-icon">📍</span> Within 1 Mile
                    </button>
                    <button class="mld-quick-filter" data-filter="recent">
                        <span class="filter-icon">🕒</span> Sold Last 3 Months
                    </button>
                    <button class="mld-quick-filter" data-filter="pool">
                        <span class="filter-icon">🏊</span> With Pool
                    </button>
                    <button class="mld-quick-filter" data-filter="reset">
                        <span class="filter-icon">↻</span> Clear All
                    </button>
                </div>
            </div>
            <div class="mld-comp-toolbar-section">
                <div class="mld-comp-toolbar-label">Sort:</div>
                <div class="mld-comp-sort-buttons" id="mld-sort-buttons">
                    <button class="mld-sort-btn active" data-sort="similarity">Best Match</button>
                    <button class="mld-sort-btn" data-sort="price_asc">Price ↑</button>
                    <button class="mld-sort-btn" data-sort="price_desc">Price ↓</button>
                    <button class="mld-sort-btn" data-sort="distance">Distance</button>
                    <button class="mld-sort-btn" data-sort="date_desc">Newest</button>
                </div>
            </div>
            <div class="mld-comp-toolbar-section mld-comp-toolbar-actions">
                <button class="mld-comp-arv-btn" id="mld-arv-btn" title="Adjust Property Details for ARV">
                    <span class="arv-icon">🔧</span> Adjust Subject Property Details
                </button>
                <?php if (is_user_logged_in()) : ?>
                <button class="mld-comp-save-btn" id="mld-save-cma-btn" title="Save This CMA">
                    <span class="save-icon">💾</span> Save
                </button>
                <button class="mld-comp-load-btn" id="mld-load-cma-btn" title="My Saved CMAs">
                    <span class="load-icon">📂</span> My CMAs
                </button>
                <?php else : ?>
                <?php
                $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $login_url = wp_login_url($current_url);
                ?>
                <a href="<?php echo esc_url($login_url); ?>" class="mld-comp-login-btn" title="Log in to save your CMA analysis">
                    <span class="login-icon">🔐</span> Log in to Save CMA
                </a>
                <?php endif; ?>
                <button class="mld-comp-compare-btn" id="mld-compare-btn" disabled>
                    <span class="compare-icon">⚖️</span> Compare Selected (<span id="compare-count">0</span>)
                </button>
                <button class="mld-comp-share-btn" id="mld-share-btn" title="Share or Print">
                    <span class="share-icon">📤</span> Share
                </button>
                <button class="mld-comp-map-toggle-btn" id="mld-map-toggle-btn" title="Toggle Map View">
                    <span class="map-icon">🗺️</span> Map
                </button>
            </div>
        </div>

        <!-- Interactive Map Container -->
        <div class="mld-comp-map-container" id="mld-comp-map-container" style="display: none;">
            <div class="mld-comp-map-header">
                <h4>📍 Comparable Properties Map</h4>
                <div class="mld-map-legend">
                    <span class="legend-item">
                        <span class="legend-price-pin legend-subject">$XXX,XXX</span> Subject Property
                    </span>
                    <span class="legend-item">
                        <span class="legend-price-pin legend-comparable">$XXX,XXX</span> Comparable Sale
                    </span>
                    <span class="legend-item" style="font-size: 0.65rem; color: #6c757d;">
                        💡 Click any price pin to view property details
                    </span>
                </div>
            </div>
            <div id="mld-comp-map" style="height: 450px; width: 100%;"></div>
        </div>

        <!-- Results Container -->
        <div class="mld-comp-results" id="mld-comp-results">
            <!-- Skeleton Loading States -->
            <div class="mld-comp-skeleton-container" style="display: none;">
                <div class="mld-skeleton-summary">
                    <div class="skeleton-line skeleton-title"></div>
                    <div class="skeleton-stats">
                        <div class="skeleton-stat"></div>
                        <div class="skeleton-stat"></div>
                        <div class="skeleton-stat"></div>
                        <div class="skeleton-stat"></div>
                    </div>
                </div>
                <div class="mld-skeleton-grid">
                    <?php for ($i = 0; $i < 10; $i++): ?>
                    <div class="mld-skeleton-card">
                        <div class="skeleton-image"></div>
                        <div class="skeleton-content">
                            <div class="skeleton-line skeleton-address"></div>
                            <div class="skeleton-line skeleton-price"></div>
                            <div class="skeleton-line skeleton-details"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Spinner Loading (Fallback) -->
            <div class="mld-comp-loading">
                <div class="spinner"></div>
                <p>Finding comparable properties...</p>
            </div>
        </div>

        <!-- Pagination Controls -->
        <div class="mld-comp-pagination" id="mld-comp-pagination" style="display: none;">
            <button class="mld-page-btn mld-page-prev" id="mld-page-prev" disabled>
                ← Previous
            </button>
            <div class="mld-page-info" id="mld-page-info">
                Page <span id="current-page">1</span> of <span id="total-pages">1</span>
            </div>
            <button class="mld-page-btn mld-page-next" id="mld-page-next">
                Next →
            </button>
        </div>

    </div>

    <!-- ARV Adjustment Modal -->
    <div class="mld-modal mld-arv-modal" id="mld-arv-modal" style="display: none;">
        <div class="mld-modal-overlay"></div>
        <div class="mld-modal-content mld-arv-modal-content">
            <div class="mld-modal-header mld-arv-modal-header">
                <div class="mld-arv-header-text">
                    <h3>Adjust Property Details</h3>
                    <p class="mld-arv-subtitle">Edit values below to calculate After Repair Value (ARV)</p>
                </div>
                <button class="mld-modal-close" id="mld-arv-close">&times;</button>
            </div>
            <div class="mld-modal-body mld-arv-modal-body">
                <div class="mld-arv-mode-indicator" id="mld-arv-indicator" style="display: none;">
                    <span class="arv-badge">ARV Mode Active</span>
                    <span class="arv-note">Values have been modified from original</span>
                </div>

                <div class="mld-arv-fields">
                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-beds">Bedrooms</label>
                            <span class="mld-arv-original" id="arv-beds-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <input type="number" id="arv-beds" min="0" max="20" step="1">
                            <button class="mld-arv-edit-btn" data-field="beds" title="Edit">✎</button>
                        </div>
                    </div>

                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-baths">Bathrooms</label>
                            <span class="mld-arv-original" id="arv-baths-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <input type="number" id="arv-baths" min="0" max="20" step="0.5">
                            <button class="mld-arv-edit-btn" data-field="baths" title="Edit">✎</button>
                        </div>
                    </div>

                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-sqft">Square Footage</label>
                            <span class="mld-arv-original" id="arv-sqft-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <input type="number" id="arv-sqft" min="0" max="100000" step="100">
                            <button class="mld-arv-edit-btn" data-field="sqft" title="Edit">✎</button>
                        </div>
                    </div>

                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-year-built">Year Built</label>
                            <span class="mld-arv-original" id="arv-year-built-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <input type="number" id="arv-year-built" min="1800" max="2030" step="1">
                            <button class="mld-arv-edit-btn" data-field="year_built" title="Edit">✎</button>
                        </div>
                    </div>

                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-garage">Garage Spaces</label>
                            <span class="mld-arv-original" id="arv-garage-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <input type="number" id="arv-garage" min="0" max="10" step="1">
                            <button class="mld-arv-edit-btn" data-field="garage_spaces" title="Edit">✎</button>
                        </div>
                    </div>

                    <div class="mld-arv-field">
                        <div class="mld-arv-field-header">
                            <label for="arv-pool">Pool</label>
                            <span class="mld-arv-original" id="arv-pool-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input mld-arv-toggle">
                            <label class="mld-toggle">
                                <input type="checkbox" id="arv-pool">
                                <span class="mld-toggle-slider"></span>
                            </label>
                            <span class="mld-toggle-label" id="arv-pool-label">No</span>
                        </div>
                    </div>

                    <div class="mld-arv-field mld-arv-field-full">
                        <div class="mld-arv-field-header">
                            <label for="arv-condition">Property Condition</label>
                            <span class="mld-arv-original" id="arv-condition-original">Original: --</span>
                        </div>
                        <div class="mld-arv-field-input">
                            <select id="arv-condition">
                                <option value="unknown">Unknown</option>
                                <option value="new">New Construction</option>
                                <option value="fully_renovated">Fully Renovated</option>
                                <option value="some_updates">Some Updates</option>
                                <option value="needs_updating">Needs Updating</option>
                                <option value="distressed">Distressed/Major Repairs</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mld-arv-actions">
                    <button type="button" class="mld-btn-secondary" id="arv-reset">
                        <span>↻</span> Reset to Original
                    </button>
                    <button type="button" class="mld-btn-primary" id="arv-apply">
                        <span>✓</span> Apply & Recalculate
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (is_user_logged_in()) : ?>
    <!-- Save CMA Modal -->
    <div class="mld-modal mld-save-cma-modal" id="mld-save-cma-modal" style="display: none;">
        <div class="mld-modal-overlay"></div>
        <div class="mld-modal-content mld-save-cma-modal-content">
            <div class="mld-modal-header">
                <h3>Save CMA Analysis</h3>
                <button class="mld-modal-close" id="mld-save-cma-close">&times;</button>
            </div>
            <div class="mld-modal-body mld-save-cma-modal-body">
                <form id="mld-save-cma-form">
                    <div class="mld-form-group">
                        <label for="cma-session-name">Session Name *</label>
                        <input type="text" id="cma-session-name" name="session_name" placeholder="e.g., 123 Main St - Renovation Analysis" required>
                    </div>
                    <div class="mld-form-group">
                        <label for="cma-session-description">Description (optional)</label>
                        <textarea id="cma-session-description" name="description" placeholder="Notes about this analysis..." rows="3"></textarea>
                    </div>
                    <div class="mld-save-cma-summary">
                        <h4>Session Summary</h4>
                        <div class="mld-save-summary-item">
                            <span class="summary-label">Subject Property:</span>
                            <span class="summary-value" id="save-summary-address">--</span>
                        </div>
                        <div class="mld-save-summary-item">
                            <span class="summary-label">Comparables Found:</span>
                            <span class="summary-value" id="save-summary-comps">--</span>
                        </div>
                        <div class="mld-save-summary-item">
                            <span class="summary-label">Estimated Value:</span>
                            <span class="summary-value" id="save-summary-value">--</span>
                        </div>
                        <div class="mld-save-summary-item mld-arv-mode-note" style="display: none;">
                            <span class="summary-label">ARV Mode:</span>
                            <span class="summary-value summary-arv-active">Active (with adjustments)</span>
                        </div>
                    </div>
                    <div class="mld-save-cma-actions">
                        <button type="button" class="mld-btn-secondary" id="save-cma-cancel">Cancel</button>
                        <button type="submit" class="mld-btn-primary" id="save-cma-submit">
                            <span class="save-icon">💾</span> Save CMA
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- My Saved CMAs Modal -->
    <div class="mld-modal mld-my-cmas-modal" id="mld-my-cmas-modal" style="display: none;">
        <div class="mld-modal-overlay"></div>
        <div class="mld-modal-content mld-my-cmas-modal-content">
            <div class="mld-modal-header">
                <h3>My Saved CMAs</h3>
                <button class="mld-modal-close" id="mld-my-cmas-close">&times;</button>
            </div>
            <div class="mld-modal-body mld-my-cmas-modal-body">
                <div class="mld-my-cmas-loading" style="display: none;">
                    <div class="spinner"></div>
                    <p>Loading your saved CMAs...</p>
                </div>
                <div class="mld-my-cmas-empty" style="display: none;">
                    <div class="empty-icon">📋</div>
                    <h4>No Saved CMAs Yet</h4>
                    <p>Save your first CMA analysis to access it here later.</p>
                </div>
                <div class="mld-my-cmas-list" id="mld-my-cmas-list">
                    <!-- Populated dynamically -->
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pass subject property data to JavaScript -->
    <script type="text/javascript">
        window.mldSubjectProperty = <?php echo json_encode($subject_data); ?>;
        window.mldUserLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
        <?php if ($session_data) : ?>
        // Preloaded session data for standalone CMA (v6.20.2)
        window.mldPreloadedSession = <?php echo json_encode(array(
            'id' => $session_data['id'] ?? null,
            'session_name' => $session_data['session_name'] ?? '',
            'is_standalone' => !empty($session_data['is_standalone']),
            'standalone_slug' => $session_data['standalone_slug'] ?? '',
            'cma_filters' => $session_data['cma_filters'] ?? array(),
            'comparables_data' => $session_data['comparables_data'] ?? array(),
            'summary_statistics' => $session_data['summary_statistics'] ?? array(),
            'subject_overrides' => $session_data['subject_overrides'] ?? array(),
            'estimated_value_mid' => $session_data['estimated_value_mid'] ?? null,
            'comparables_count' => $session_data['comparables_count'] ?? 0,
        )); ?>;
        <?php endif; ?>
    </script>

    <style>
        /* Comparable Sales Styles - Scaled down 30% */
        .mld-comparable-sales-wrapper {
            margin: 1.4rem 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 0.85rem;
        }

        /* Filter Panel */
        .mld-comp-filter-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 1.4rem;
            overflow: hidden;
            opacity: 0;
            animation: fadeIn 0.5s ease-out forwards;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease;
        }

        .mld-comp-filter-panel:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .mld-comp-filter-header {
            padding: 0.7rem 1.1rem;
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mld-comp-filter-header h3 {
            margin: 0;
            font-size: 0.9rem;
            color: #2c5aa0;
        }

        .mld-comp-filter-toggle {
            background: #2c5aa0;
            color: white;
            border: none;
            padding: 0.36rem 0.7rem;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.36rem;
            font-size: 0.69rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(44, 90, 160, 0.2);
        }

        .mld-comp-filter-toggle:hover {
            background: #1e4278;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(44, 90, 160, 0.3);
        }

        .mld-comp-filter-toggle:active {
            transform: translateY(0);
        }

        .mld-comp-filter-content {
            padding: 1.1rem;
        }

        .mld-filter-section {
            margin-bottom: 1.4rem;
            padding-bottom: 1.1rem;
            border-bottom: 1px solid #dee2e6;
        }

        .mld-filter-section:last-of-type {
            border-bottom: none;
        }

        .mld-filter-section h4 {
            margin: 0 0 0.7rem 0;
            font-size: 0.8rem;
            color: #495057;
            font-weight: 600;
        }

        .mld-filter-row {
            margin-bottom: 0.7rem;
        }

        .mld-filter-row label {
            display: block;
            margin-bottom: 0.36rem;
            font-weight: 500;
            color: #495057;
            font-size: 0.75rem;
        }

        .mld-filter-inline {
            display: flex;
            align-items: center;
            gap: 0.54rem;
            flex-wrap: wrap;
        }

        .mld-filter-inline input[type="number"] {
            width: 58px;
            padding: 0.27rem 0.36rem;
            border: 1px solid #ced4da;
            border-radius: 2px;
            font-size: 0.75rem;
        }

        .filter-separator {
            color: #6c757d;
            font-size: 0.65rem;
        }

        input[type="range"] {
            width: 100%;
            height: 5px;
            border-radius: 2px;
            background: #dee2e6;
            outline: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #2c5aa0;
            cursor: pointer;
        }

        input[type="range"]::-moz-range-thumb {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #2c5aa0;
            cursor: pointer;
            border: none;
        }

        select {
            width: 100%;
            padding: 0.36rem;
            border: 1px solid #ced4da;
            border-radius: 2px;
            background: white;
            font-size: 0.75rem;
        }

        .mld-filter-actions {
            display: flex;
            gap: 0.7rem;
            padding-top: 0.7rem;
        }

        .mld-btn-primary,
        .mld-btn-secondary {
            padding: 0.54rem 1.1rem;
            border: none;
            border-radius: 2px;
            font-size: 0.72rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .mld-btn-primary {
            background: #2c5aa0;
            color: white;
            flex: 1;
            box-shadow: 0 2px 4px rgba(44, 90, 160, 0.2);
        }

        .mld-btn-primary:hover {
            background: #1e4278;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(44, 90, 160, 0.3);
        }

        .mld-btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(44, 90, 160, 0.2);
        }

        .mld-btn-secondary {
            background: white;
            color: #6c757d;
            border: 1px solid #ced4da;
        }

        .mld-btn-secondary:hover {
            background: #f8f9fa;
            border-color: #2c5aa0;
            color: #2c5aa0;
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .mld-btn-secondary:active {
            transform: translateY(0);
        }

        /* Toolbar - Quick Filters & Sort */
        .mld-comp-toolbar {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 0.9rem;
            margin-bottom: 1.4rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            opacity: 0;
            animation: fadeIn 0.5s ease-out 0.3s forwards;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .mld-comp-toolbar-section {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .mld-comp-toolbar-section.mld-comp-toolbar-actions {
            margin-left: auto;
        }

        .mld-comp-toolbar-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .mld-comp-quick-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .mld-quick-filter {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 0.35rem 0.7rem;
            border-radius: 20px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 500;
            color: #495057;
        }

        .mld-quick-filter .filter-icon {
            font-size: 0.8rem;
        }

        .mld-quick-filter:hover {
            background: #e7f1ff;
            border-color: #2c5aa0;
            color: #2c5aa0;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(44, 90, 160, 0.2);
        }

        .mld-quick-filter.active {
            background: #2c5aa0;
            border-color: #2c5aa0;
            color: white;
        }

        .mld-quick-filter.active:hover {
            background: #1e4278;
            border-color: #1e4278;
        }

        .mld-quick-filter[data-filter="reset"] {
            background: #fff;
            border-color: #dc3545;
            color: #dc3545;
        }

        .mld-quick-filter[data-filter="reset"]:hover {
            background: #dc3545;
            color: white;
        }

        .mld-comp-sort-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .mld-sort-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 0.35rem 0.6rem;
            border-radius: 3px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #495057;
        }

        .mld-sort-btn:hover {
            background: #e7f1ff;
            border-color: #2c5aa0;
            color: #2c5aa0;
        }

        .mld-sort-btn.active {
            background: #2c5aa0;
            border-color: #2c5aa0;
            color: white;
            box-shadow: 0 2px 4px rgba(44, 90, 160, 0.3);
        }

        .mld-comp-compare-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }

        .mld-comp-compare-btn:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            box-shadow: none;
        }

        .mld-comp-compare-btn:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }

        .mld-comp-compare-btn .compare-icon {
            font-size: 0.9rem;
        }

        /* Results Container */
        .mld-comp-results {
            min-height: 400px;
        }

        .mld-comp-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e7f1ff;
            border-top: 4px solid #2c5aa0;
            border-radius: 50%;
            animation: spin 0.8s linear infinite, pulse 2s ease-in-out infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.05) rotate(180deg);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .mld-comp-loading p {
            margin-top: 1rem;
            color: #6c757d;
            animation: fadeIn 1s ease-in-out infinite alternate;
        }

        /* Comparable Card Styles - Scaled down 30% */
        .mld-comp-summary {
            background: linear-gradient(135deg, #2c5aa0 0%, #1e4278 100%);
            color: white;
            padding: 1.1rem;
            border-radius: 5px;
            margin-bottom: 1.4rem;
            opacity: 0;
            animation: fadeIn 0.6s ease-out 0.2s forwards;
            box-shadow: 0 4px 12px rgba(44, 90, 160, 0.3);
        }

        .mld-comp-summary h3 {
            margin: 0 0 0.7rem 0;
            font-size: 1.09rem;
        }

        .mld-comp-summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 0.7rem;
        }

        .mld-comp-stat {
            background: rgba(255,255,255,0.1);
            padding: 0.7rem;
            border-radius: 2px;
            transition: all 0.3s ease;
            cursor: default;
        }

        .mld-comp-stat:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
        }

        .mld-comp-stat-label {
            font-size: 0.61rem;
            opacity: 0.9;
            margin-bottom: 0.18rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .mld-comp-stat-value {
            font-size: 1.09rem;
            font-weight: 600;
        }

        .mld-comp-stat-sublabel {
            font-size: 0.55rem;
            opacity: 0.8;
            margin-top: 0.15rem;
        }

        /* Confidence Bar */
        .mld-comp-confidence-bar {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            margin-top: 0.3rem;
            overflow: hidden;
        }

        .mld-comp-confidence-fill {
            height: 100%;
            transition: width 0.6s ease;
            border-radius: 2px;
        }

        /* Secondary Summary Stats */
        .mld-comp-summary-secondary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.5rem;
            margin-top: 0.85rem;
            padding-top: 0.85rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .mld-comp-stat-secondary {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .mld-stat-sec-label {
            font-size: 0.55rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .mld-stat-sec-value {
            font-size: 0.75rem;
            font-weight: 600;
        }

        .mld-comp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
            gap: 1.1rem;
        }

        .mld-comp-card {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .mld-comp-card:hover {
            box-shadow: 0 8px 24px rgba(44, 90, 160, 0.15), 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-4px) scale(1.02);
            border-color: #2c5aa0;
        }

        .mld-comp-card.grade-A {
            border-color: #28a745;
        }

        .mld-comp-card.grade-A:hover {
            box-shadow: 0 8px 24px rgba(40, 167, 69, 0.2), 0 4px 8px rgba(0,0,0,0.1);
            border-color: #28a745;
        }

        .mld-comp-card.grade-B {
            border-color: #5cb85c;
        }

        .mld-comp-card.grade-B:hover {
            box-shadow: 0 8px 24px rgba(92, 184, 92, 0.2), 0 4px 8px rgba(0,0,0,0.1);
            border-color: #5cb85c;
        }

        .mld-comp-card.grade-C {
            border-color: #ffc107;
        }

        .mld-comp-card.grade-C:hover {
            box-shadow: 0 8px 24px rgba(255, 193, 7, 0.2), 0 4px 8px rgba(0,0,0,0.1);
            border-color: #ffc107;
        }

        /* Staggered animation for cards */
        .mld-comp-card:nth-child(1) { animation-delay: 0.05s; }
        .mld-comp-card:nth-child(2) { animation-delay: 0.1s; }
        .mld-comp-card:nth-child(3) { animation-delay: 0.15s; }
        .mld-comp-card:nth-child(4) { animation-delay: 0.2s; }
        .mld-comp-card:nth-child(5) { animation-delay: 0.25s; }
        .mld-comp-card:nth-child(6) { animation-delay: 0.3s; }
        .mld-comp-card:nth-child(7) { animation-delay: 0.35s; }
        .mld-comp-card:nth-child(8) { animation-delay: 0.4s; }
        .mld-comp-card:nth-child(9) { animation-delay: 0.45s; }
        .mld-comp-card:nth-child(10) { animation-delay: 0.5s; }
        .mld-comp-card:nth-child(n+11) { animation-delay: 0.55s; }

        .mld-comp-grade {
            position: absolute;
            top: 0.7rem;
            right: 0.7rem;
            width: 36px;
            height: 36px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.09rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 1;
            transition: all 0.3s ease;
        }

        .mld-comp-card:hover .mld-comp-grade {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        /* Compare Checkbox */
        .mld-comp-checkbox {
            position: absolute;
            top: 0.7rem;
            left: 0.7rem;
            z-index: 2;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .mld-comp-card:hover .mld-comp-checkbox {
            opacity: 1;
        }

        .mld-comp-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #28a745;
        }

        .mld-comp-checkbox.checked {
            opacity: 1;
        }

        .mld-comp-card.selected {
            border-color: #28a745;
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
        }

        .mld-comp-grade.grade-A { color: #28a745; }
        .mld-comp-grade.grade-B { color: #5cb85c; }
        .mld-comp-grade.grade-C { color: #ffc107; }
        .mld-comp-grade.grade-D { color: #fd7e14; }
        .mld-comp-grade.grade-F { color: #dc3545; }

        .mld-comp-image-link {
            display: block;
            width: 100%;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .mld-comp-image-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(44, 90, 160, 0);
            transition: background 0.3s ease;
            z-index: 1;
        }

        .mld-comp-image-link:hover::before {
            background: rgba(44, 90, 160, 0.1);
        }

        .mld-comp-image-link::after {
            content: '👁 View Property';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            background: rgba(44, 90, 160, 0.95);
            color: white;
            padding: 0.54rem 1.09rem;
            border-radius: 2px;
            font-weight: 600;
            font-size: 0.69rem;
            white-space: nowrap;
            pointer-events: none;
            z-index: 2;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .mld-comp-image-link:hover::after {
            transform: translate(-50%, -50%) scale(1);
        }

        .mld-comp-image {
            width: 100%;
            height: 145px;
            object-fit: cover;
            background: #e9ecef;
            display: block;
            transition: transform 0.3s ease;
        }

        .mld-comp-image-link:hover .mld-comp-image {
            transform: scale(1.05);
        }

        .mld-comp-content {
            padding: 0.9rem;
        }

        .mld-comp-address {
            font-size: 0.8rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 0.36rem;
            transition: color 0.3s ease;
        }

        .mld-comp-card:hover .mld-comp-address {
            color: #2c5aa0;
        }

        .mld-comp-price {
            font-size: 1.09rem;
            font-weight: 700;
            color: #2c5aa0;
            margin-bottom: 0.7rem;
            transition: all 0.3s ease;
        }

        .mld-comp-card:hover .mld-comp-price {
            transform: scale(1.05);
            color: #1e4278;
        }

        /* Price Per Sqft Display */
        .mld-comp-price-per-sqft {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
        }

        .mld-ppsf-label {
            color: #495057;
            font-weight: 500;
        }

        .mld-ppsf-diff {
            padding: 0.15rem 0.4rem;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .mld-ppsf-diff.positive {
            background: #fff3cd;
            color: #856404;
        }

        .mld-ppsf-diff.negative {
            background: #d1ecf1;
            color: #0c5460;
        }

        .mld-comp-details {
            display: flex;
            gap: 0.7rem;
            color: #6c757d;
            font-size: 0.69rem;
            margin-bottom: 0.7rem;
            flex-wrap: wrap;
        }

        .mld-comp-adjustments {
            background: #f8f9fa;
            padding: 0.7rem;
            border-radius: 2px;
            margin-top: 0.7rem;
            transition: all 0.3s ease;
        }

        .mld-comp-adjustments:hover {
            background: #e9ecef;
        }

        .mld-comp-adjustments-header {
            font-weight: 600;
            margin-bottom: 0.54rem;
            color: #495057;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: color 0.3s ease;
        }

        .mld-comp-adjustments-header:hover {
            color: #2c5aa0;
        }

        .mld-adjustment-item {
            display: flex;
            justify-content: space-between;
            padding: 0.36rem 0;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.65rem;
        }

        .mld-adjustment-item:last-child {
            border-bottom: none;
            padding-top: 0.54rem;
            margin-top: 0.18rem;
            border-top: 2px solid #495057;
            font-weight: 600;
        }

        .mld-adjustment-positive {
            color: #28a745;
        }

        .mld-adjustment-negative {
            color: #dc3545;
        }

        .mld-comp-distance {
            display: inline-block;
            background: #e7f1ff;
            color: #0c5aa6;
            padding: 0.18rem 0.54rem;
            border-radius: 8px;
            font-size: 0.61rem;
            margin-top: 0.36rem;
            transition: all 0.3s ease;
        }

        .mld-comp-card:hover .mld-comp-distance {
            background: #2c5aa0;
            color: white;
            transform: scale(1.05);
        }

        /* Comparison Modal */
        .mld-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 10000;
        }

        .mld-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
        }

        .mld-modal-content {
            position: relative;
            max-width: 1200px;
            max-height: 90vh;
            margin: 5vh auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .mld-modal-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #2c5aa0 0%, #1e4278 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mld-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .mld-modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 2rem;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .mld-modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .mld-modal-body {
            padding: 2rem;
            overflow-y: auto;
            flex: 1;
        }

        .mld-comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .mld-comparison-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }

        .mld-comparison-header {
            position: relative;
            height: 180px;
        }

        .mld-comparison-header img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mld-comparison-grade {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 45px;
            height: 45px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .mld-comparison-grade.A { color: #28a745; }
        .mld-comparison-grade.B { color: #5cb85c; }
        .mld-comparison-grade.C { color: #ffc107; }
        .mld-comparison-grade.D { color: #fd7e14; }
        .mld-comparison-grade.F { color: #dc3545; }

        .mld-comparison-details {
            padding: 1.5rem;
        }

        .mld-comparison-details h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
            color: #212529;
        }

        .mld-comparison-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c5aa0;
            margin-bottom: 1rem;
        }

        .mld-comparison-table {
            width: 100%;
            font-size: 0.85rem;
        }

        .mld-comparison-table tr {
            border-bottom: 1px solid #f0f0f0;
        }

        .mld-comparison-table td {
            padding: 0.5rem 0;
        }

        .mld-comparison-table td:first-child {
            font-weight: 600;
            color: #6c757d;
            width: 40%;
        }

        .mld-comparison-table td:last-child {
            text-align: right;
            color: #212529;
        }

        /* Share and Map Toggle Buttons */
        .mld-comp-share-btn,
        .mld-comp-map-toggle-btn {
            background: white;
            border: 1px solid #dee2e6;
            color: #495057;
            padding: 0.4rem 0.7rem;
            border-radius: 3px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .mld-comp-share-btn:hover,
        .mld-comp-map-toggle-btn:hover {
            background: #2c5aa0;
            border-color: #2c5aa0;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(44, 90, 160, 0.3);
        }

        .mld-comp-map-toggle-btn.active {
            background: #2c5aa0;
            border-color: #2c5aa0;
            color: white;
        }

        /* Map Container */
        .mld-comp-map-container {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 1.4rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            animation: fadeIn 0.5s ease-out;
        }

        .mld-comp-map-header {
            background: #f8f9fa;
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.7rem;
        }

        .mld-comp-map-header h4 {
            margin: 0;
            font-size: 0.85rem;
            color: #2c5aa0;
        }

        .mld-map-legend {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: #495057;
        }

        /* Legend price pin samples (miniature versions of actual pins) */
        .legend-price-pin {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: bold;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            white-space: nowrap;
        }

        .legend-price-pin.legend-subject {
            background-color: var(--brand-color, #007bff);
            color: #fff;
            border: 2px solid white;
        }

        .legend-price-pin.legend-comparable {
            background-color: #4A5568;
            color: #fff;
            border: 1px solid #2D3748;
        }

        #mld-comp-map {
            position: relative;
        }

        /* Leaflet popup customization */
        .leaflet-popup-content-wrapper {
            border-radius: 5px;
            font-size: 0.75rem;
        }

        .mld-map-popup {
            min-width: 180px;
        }

        .mld-map-popup-address {
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: #212529;
        }

        .mld-map-popup-price {
            font-size: 0.9rem;
            font-weight: 700;
            color: #2c5aa0;
            margin-bottom: 0.3rem;
        }

        .mld-map-popup-details {
            font-size: 0.7rem;
            color: #6c757d;
        }

        .mld-map-popup-grade {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 700;
            margin-top: 0.3rem;
        }

        .mld-map-popup-grade.A { background: #d4edda; color: #155724; }
        .mld-map-popup-grade.B { background: #d1ecf1; color: #0c5460; }
        .mld-map-popup-grade.C { background: #fff3cd; color: #856404; }
        .mld-map-popup-grade.subject {
            background: #f8d7da;
            color: #721c24;
            font-weight: 700;
        }

        /* Price Pin Markers - Copied from main.css since it's not loaded on property pages */
        .bme-price-marker {
            background-color: #4A5568;
            color: #fff;
            padding: 4px 7px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            border: 1px solid #2D3748;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .bme-price-marker-archive {
            background-color: #D3D3D3 !important;
            color: #4A4A4A !important;
            border: 1px solid #A9A9A9 !important;
        }

        .bme-unit-cluster-marker {
            background-color: var(--brand-color, #007bff);
            color: white;
            padding: 4px 7px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            border: 2px solid white;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .bme-unit-cluster-marker-archive {
            background-color: #D3D3D3 !important;
            color: #4A4A4A !important;
            border: 2px solid #A9A9A9 !important;
        }

        .bme-unit-cluster-marker:hover {
            transform: scale(1.1);
            background-color: #0056b3;
        }

        .bme-price-marker.highlighted-hover,
        .bme-unit-cluster-marker.highlighted-hover {
            background-color: var(--brand-color, #007bff);
            color: #fff;
            border-color: #0056b3;
            transform: scale(1.15);
            z-index: 10 !important;
        }

        .bme-price-marker.highlighted-active,
        .bme-unit-cluster-marker.highlighted-active {
            background-color: #d9002c;
            color: #fff;
            border-color: #a30021;
            transform: scale(1.2);
            z-index: 20 !important;
        }

        /* Skeleton Loading States */
        .mld-comp-skeleton-container {
            animation: fadeIn 0.3s ease-out;
        }

        .mld-skeleton-summary {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            padding: 1.1rem;
            border-radius: 5px;
            margin-bottom: 1.4rem;
        }

        .skeleton-line {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 3px;
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        .skeleton-title {
            width: 220px;
            height: 22px;
            margin-bottom: 0.7rem;
        }

        .skeleton-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 0.7rem;
        }

        .skeleton-stat {
            background: rgba(255,255,255,0.3);
            height: 60px;
            border-radius: 3px;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        .mld-skeleton-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
            gap: 1.1rem;
        }

        .mld-skeleton-card {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            overflow: hidden;
        }

        .skeleton-image {
            width: 100%;
            height: 145px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }

        .skeleton-content {
            padding: 0.9rem;
        }

        .skeleton-address {
            width: 85%;
            height: 14px;
            margin-bottom: 0.5rem;
        }

        .skeleton-price {
            width: 60%;
            height: 18px;
            margin-bottom: 0.5rem;
        }

        .skeleton-details {
            width: 75%;
            height: 12px;
        }

        /* Pagination Controls */
        .mld-comp-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1.5rem;
            padding: 1.5rem 0;
            margin-top: 1.4rem;
            border-top: 1px solid #dee2e6;
            animation: fadeIn 0.5s ease-out;
        }

        .mld-page-btn {
            background: #2c5aa0;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mld-page-btn:hover:not(:disabled) {
            background: #1e4278;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(44, 90, 160, 0.3);
        }

        .mld-page-btn:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
        }

        .mld-page-info {
            font-size: 0.75rem;
            color: #495057;
            font-weight: 500;
        }

        .mld-page-info span {
            font-weight: 700;
            color: #2c5aa0;
        }

        /* Favorites Star Button */
        .mld-comp-favorite {
            position: absolute;
            top: 0.4rem;
            right: 2.8rem;
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid #dee2e6;
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(0,0,0,0.3);
            opacity: 0;
        }

        .mld-comp-card:hover .mld-comp-favorite {
            opacity: 1;
        }

        .mld-comp-favorite.favorited {
            opacity: 1;
        }

        .mld-comp-favorite .star-icon {
            font-size: 2.2rem;
            transition: all 0.3s ease;
            line-height: 1;
        }

        .mld-comp-favorite:not(.favorited) .star-icon {
            filter: grayscale(1) brightness(0.6);
            opacity: 0.7;
        }

        .mld-comp-favorite:not(.favorited):hover .star-icon {
            filter: none;
            opacity: 1;
        }

        .mld-comp-favorite.favorited .star-icon {
            animation: starPop 0.5s ease;
        }

        @keyframes starPop {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.3) rotate(10deg);
            }
        }

        .mld-comp-favorite:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }

        /* Share Modal */
        .mld-share-modal-body {
            padding: 1.5rem;
        }

        .mld-share-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .mld-share-option {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            padding: 1rem;
            border-radius: 5px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mld-share-option:hover {
            background: #e7f1ff;
            border-color: #2c5aa0;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(44, 90, 160, 0.2);
        }

        .mld-share-option-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .mld-share-option-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #495057;
        }

        .mld-share-link-container {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .mld-share-link-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 0.8rem;
            font-family: monospace;
            margin-bottom: 0.7rem;
        }

        .mld-share-copy-btn {
            background: #2c5aa0;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
        }

        .mld-share-copy-btn:hover {
            background: #1e4278;
        }

        .mld-share-copy-btn.copied {
            background: #28a745;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .mld-comp-grid {
                grid-template-columns: 1fr;
            }

            .mld-filter-actions {
                flex-direction: column;
            }

            .mld-comp-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .mld-comp-toolbar-section {
                flex-direction: column;
                align-items: stretch;
            }

            .mld-comparison-grid {
                grid-template-columns: 1fr;
            }

            .mld-map-legend {
                font-size: 0.65rem;
            }

            .mld-skeleton-grid {
                grid-template-columns: 1fr;
            }

            .mld-share-options {
                grid-template-columns: 1fr;
            }
        }

        /* ARV, Save, Load Buttons */
        .mld-comp-arv-btn,
        .mld-comp-save-btn,
        .mld-comp-load-btn {
            background: white;
            border: 1px solid #dee2e6;
            color: #495057;
            padding: 0.4rem 0.7rem;
            border-radius: 3px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .mld-comp-arv-btn:hover {
            background: #fd7e14;
            border-color: #fd7e14;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(253, 126, 20, 0.3);
        }

        .mld-comp-arv-btn.arv-active {
            background: #fd7e14;
            border-color: #fd7e14;
            color: white;
        }

        .mld-comp-save-btn:hover {
            background: #28a745;
            border-color: #28a745;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }

        .mld-comp-load-btn:hover {
            background: #6c757d;
            border-color: #6c757d;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(108, 117, 125, 0.3);
        }

        /* Login to Save CMA Button */
        .mld-comp-login-btn {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: 1px solid #0056b3;
            color: white;
            padding: 0.4rem 0.7rem;
            border-radius: 3px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
        }

        .mld-comp-login-btn:hover {
            background: linear-gradient(135deg, #0056b3 0%, #003d82 100%);
            border-color: #003d82;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 2px 6px rgba(0, 123, 255, 0.4);
            text-decoration: none;
        }

        .mld-comp-login-btn .login-icon {
            font-size: 0.85rem;
        }

        /* ARV Modal Styles */
        .mld-arv-modal-content {
            max-width: 600px;
        }

        .mld-arv-modal-header {
            background: linear-gradient(135deg, #fd7e14 0%, #e65c00 100%);
        }

        .mld-arv-header-text {
            flex: 1;
        }

        .mld-arv-subtitle {
            margin: 0.3rem 0 0 0;
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .mld-arv-modal-body {
            padding: 1.5rem;
        }

        .mld-arv-mode-indicator {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .arv-badge {
            background: #fd7e14;
            color: white;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .arv-note {
            font-size: 0.75rem;
            color: #856404;
        }

        .mld-arv-fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .mld-arv-field {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 1rem;
            transition: all 0.3s ease;
        }

        .mld-arv-field:hover {
            background: #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .mld-arv-field-full {
            grid-column: span 2;
        }

        .mld-arv-field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .mld-arv-field-header label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #495057;
            margin: 0;
        }

        .mld-arv-original {
            font-size: 0.65rem;
            color: #6c757d;
            font-style: italic;
        }

        .mld-arv-field-input {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mld-arv-field-input input[type="number"],
        .mld-arv-field-input select {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .mld-arv-field-input input[type="number"]:focus,
        .mld-arv-field-input select:focus {
            border-color: #fd7e14;
            box-shadow: 0 0 0 3px rgba(253, 126, 20, 0.15);
            outline: none;
        }

        .mld-arv-field-input.modified input,
        .mld-arv-field-input.modified select {
            border-color: #fd7e14;
            background: #fff3cd;
        }

        .mld-arv-edit-btn {
            background: white;
            border: 1px solid #ced4da;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .mld-arv-edit-btn:hover {
            background: #fd7e14;
            border-color: #fd7e14;
            color: white;
            transform: scale(1.1);
        }

        /* Toggle Switch for Pool */
        .mld-arv-toggle {
            justify-content: flex-start;
        }

        .mld-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }

        .mld-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .mld-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 26px;
        }

        .mld-toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }

        .mld-toggle input:checked + .mld-toggle-slider {
            background-color: #28a745;
        }

        .mld-toggle input:checked + .mld-toggle-slider:before {
            transform: translateX(24px);
        }

        .mld-toggle-label {
            font-size: 0.8rem;
            color: #495057;
            font-weight: 500;
        }

        .mld-arv-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dee2e6;
        }

        .mld-arv-actions .mld-btn-secondary,
        .mld-arv-actions .mld-btn-primary {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
        }

        .mld-arv-actions .mld-btn-primary {
            background: linear-gradient(135deg, #fd7e14 0%, #e65c00 100%);
        }

        .mld-arv-actions .mld-btn-primary:hover {
            background: linear-gradient(135deg, #e65c00 0%, #cc5200 100%);
        }

        /* Save CMA Modal Styles */
        .mld-save-cma-modal-content {
            max-width: 500px;
        }

        .mld-save-cma-modal-body {
            padding: 1.5rem;
        }

        .mld-form-group {
            margin-bottom: 1.25rem;
        }

        .mld-form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.8rem;
            color: #495057;
            margin-bottom: 0.4rem;
        }

        .mld-form-group input,
        .mld-form-group textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .mld-form-group input:focus,
        .mld-form-group textarea:focus {
            border-color: #2c5aa0;
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.15);
            outline: none;
        }

        .mld-save-cma-summary {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }

        .mld-save-cma-summary h4 {
            margin: 0 0 0.75rem 0;
            font-size: 0.85rem;
            color: #495057;
        }

        .mld-save-summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.75rem;
        }

        .mld-save-summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: #6c757d;
        }

        .summary-value {
            font-weight: 600;
            color: #212529;
        }

        .summary-arv-active {
            color: #fd7e14;
        }

        .mld-save-cma-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .mld-save-cma-actions .mld-btn-secondary,
        .mld-save-cma-actions .mld-btn-primary {
            flex: 1;
            padding: 0.65rem 1rem;
        }

        /* My Saved CMAs Modal Styles */
        .mld-my-cmas-modal-content {
            max-width: 700px;
        }

        .mld-my-cmas-modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .mld-my-cmas-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 3rem;
        }

        .mld-my-cmas-empty {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .mld-my-cmas-empty .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .mld-my-cmas-empty h4 {
            margin: 0 0 0.5rem 0;
            color: #495057;
        }

        .mld-my-cmas-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .mld-cma-session-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .mld-cma-session-item:hover {
            background: #e7f1ff;
            border-color: #2c5aa0;
            box-shadow: 0 2px 8px rgba(44, 90, 160, 0.15);
        }

        .mld-cma-session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .mld-cma-session-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #212529;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mld-cma-session-favorite {
            color: #ffc107;
            font-size: 1rem;
        }

        .mld-cma-session-actions {
            display: flex;
            gap: 0.5rem;
        }

        .mld-cma-session-actions button {
            background: white;
            border: 1px solid #ced4da;
            padding: 0.3rem 0.5rem;
            border-radius: 3px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #495057;
        }

        .mld-cma-load-session-btn:hover {
            background: #2c5aa0;
            border-color: #2c5aa0;
            color: white;
        }

        .mld-cma-delete-session-btn:hover {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .mld-cma-toggle-favorite-btn:hover {
            background: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }

        .mld-cma-session-meta {
            display: flex;
            gap: 1.5rem;
            font-size: 0.7rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .mld-cma-session-description {
            font-size: 0.75rem;
            color: #495057;
            font-style: italic;
        }

        .mld-cma-session-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #dee2e6;
            font-size: 0.7rem;
        }

        .mld-cma-session-stat {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .mld-cma-session-stat-label {
            color: #6c757d;
            text-transform: uppercase;
            font-size: 0.6rem;
            letter-spacing: 0.3px;
        }

        .mld-cma-session-stat-value {
            font-weight: 600;
            color: #2c5aa0;
        }

        /* Responsive ARV Modal */
        @media (max-width: 768px) {
            .mld-arv-fields {
                grid-template-columns: 1fr;
            }

            .mld-arv-field-full {
                grid-column: span 1;
            }

            .mld-arv-actions {
                flex-direction: column;
            }

            .mld-save-cma-actions {
                flex-direction: column;
            }

            .mld-cma-session-header {
                flex-direction: column;
                gap: 0.75rem;
            }

            .mld-cma-session-meta {
                flex-direction: column;
                gap: 0.25rem;
            }
        }
    </style>

    <?php
}
