<?php
/**
 * Card Generator Tab - Shortcode Generator for [mld_listing_cards]
 *
 * Provides an admin UI for building shortcodes with all available filter options.
 *
 * @package MLS_Listings_Display
 * @since 6.11.21
 */

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Get dynamic filter options
$property_types = ['Residential', 'Residential Lease', 'Land', 'Commercial Sale', 'Commercial Lease', 'Business Opportunity'];
// Status values must match database exactly (standard_status column)
$status_options = ['Active', 'Pending', 'Closed', 'Active Under Contract', 'Expired', 'Withdrawn', 'Canceled'];
$sort_options = [
    'newest' => 'Newest First',
    'oldest' => 'Oldest First',
    'price_asc' => 'Price: Low to High',
    'price_desc' => 'Price: High to Low'
];

// Get home types from database if available
$home_types = [];
if (class_exists('MLD_Query')) {
    $home_types = MLD_Query::get_all_distinct_subtypes();
}

// Get structure types and architectural styles from database
$structure_types = [];
$architectural_styles = [];
if (class_exists('MLD_BME_Data_Provider')) {
    $provider = MLD_BME_Data_Provider::get_instance();
    if ($provider && $provider->is_available()) {
        $structure_types = $provider->get_distinct_values('structure_type');
        $architectural_styles = $provider->get_distinct_values('architectural_style');
    }
}

// Enqueue admin assets
wp_enqueue_style('mld-card-generator', MLD_PLUGIN_URL . 'assets/css/mld-card-generator.css', [], MLD_VERSION);
wp_enqueue_script('mld-card-generator', MLD_PLUGIN_URL . 'assets/js/mld-card-generator.js', ['jquery'], MLD_VERSION, true);

wp_localize_script('mld-card-generator', 'mldGeneratorData', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mld_generator_nonce'),
    'homeTypes' => $home_types,
    'structureTypes' => $structure_types,
    'architecturalStyles' => $architectural_styles,
    'defaults' => [
        'status' => 'Active',
        'per_page' => 12,
        'columns' => 3,
        'sort_by' => 'newest',
        'infinite_scroll' => 'yes',
        'show_count' => 'yes',
        'show_sort' => 'yes'
    ]
]);
?>

<div class="mld-card-generator-wrap">
    <div class="mld-generator-intro">
        <h2>Listing Cards Shortcode Generator</h2>
        <p>Build a custom shortcode to display property listing cards on any page. Select your filters below and copy the generated shortcode.</p>
    </div>

    <div class="mld-generator-container">
        <!-- Left Panel: Filters -->
        <div class="mld-generator-filters">
            <!-- Basic Filters Section (Open by default) -->
            <div class="mld-accordion-section" data-section="basic">
                <button type="button" class="mld-accordion-header" aria-expanded="true">
                    <span class="mld-accordion-title">Basic Filters</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content" style="display: block;">
                    <!-- Status -->
                    <div class="mld-field">
                        <label>Status</label>
                        <div class="mld-checkbox-group" id="mld-status-checkboxes">
                            <?php foreach ($status_options as $status): ?>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" name="status[]" value="<?php echo esc_attr($status); ?>"
                                       <?php echo $status === 'Active' ? 'checked' : ''; ?>>
                                <?php echo esc_html($status); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Property Type -->
                    <div class="mld-field">
                        <label for="mld-property-type">Property Type</label>
                        <select id="mld-property-type" data-filter-key="property_type">
                            <option value="">All Types</option>
                            <?php foreach ($property_types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Price Range -->
                    <div class="mld-field mld-field-row">
                        <div class="mld-field-half">
                            <label for="mld-price-min">Min Price</label>
                            <input type="text" id="mld-price-min" placeholder="$0" data-filter-key="price_min" class="mld-price-input">
                        </div>
                        <div class="mld-field-half">
                            <label for="mld-price-max">Max Price</label>
                            <input type="text" id="mld-price-max" placeholder="No Max" data-filter-key="price_max" class="mld-price-input">
                        </div>
                    </div>

                    <!-- Beds -->
                    <div class="mld-field">
                        <label>Bedrooms</label>
                        <div class="mld-chip-group" data-filter-key="beds">
                            <button type="button" class="mld-chip" data-value="">Any</button>
                            <button type="button" class="mld-chip" data-value="1">1+</button>
                            <button type="button" class="mld-chip" data-value="2">2+</button>
                            <button type="button" class="mld-chip" data-value="3">3+</button>
                            <button type="button" class="mld-chip" data-value="4">4+</button>
                            <button type="button" class="mld-chip" data-value="5">5+</button>
                        </div>
                    </div>

                    <!-- Baths -->
                    <div class="mld-field">
                        <label for="mld-baths-min">Minimum Bathrooms</label>
                        <select id="mld-baths-min" data-filter-key="baths_min">
                            <option value="">Any</option>
                            <option value="1">1+</option>
                            <option value="1.5">1.5+</option>
                            <option value="2">2+</option>
                            <option value="2.5">2.5+</option>
                            <option value="3">3+</option>
                            <option value="4">4+</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Location Filters Section -->
            <div class="mld-accordion-section" data-section="location">
                <button type="button" class="mld-accordion-header" aria-expanded="false">
                    <span class="mld-accordion-title">Location Filters</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content">
                    <!-- City -->
                    <div class="mld-field">
                        <label for="mld-city">City</label>
                        <input type="text" id="mld-city" placeholder="e.g., Boston, Cambridge" data-filter-key="city">
                        <p class="mld-field-hint">Comma-separated for multiple cities</p>
                    </div>

                    <!-- Postal Code -->
                    <div class="mld-field">
                        <label for="mld-postal-code">ZIP Code</label>
                        <input type="text" id="mld-postal-code" placeholder="e.g., 02108, 02109" data-filter-key="postal_code">
                        <p class="mld-field-hint">Comma-separated for multiple ZIP codes</p>
                    </div>

                    <!-- Neighborhood -->
                    <div class="mld-field">
                        <label for="mld-neighborhood">Neighborhood</label>
                        <input type="text" id="mld-neighborhood" placeholder="e.g., Back Bay, Beacon Hill" data-filter-key="neighborhood">
                    </div>

                    <!-- Street Name -->
                    <div class="mld-field">
                        <label for="mld-street-name">Street Name</label>
                        <input type="text" id="mld-street-name" placeholder="e.g., Main Street" data-filter-key="street_name">
                    </div>

                    <!-- MLS Number -->
                    <div class="mld-field">
                        <label for="mld-listing-id">MLS Number(s)</label>
                        <input type="text" id="mld-listing-id" placeholder="e.g., 12345678" data-filter-key="listing_id">
                        <p class="mld-field-hint">Comma-separated for multiple MLS numbers</p>
                    </div>
                </div>
            </div>

            <!-- Agent Filters Section -->
            <div class="mld-accordion-section" data-section="agents">
                <button type="button" class="mld-accordion-header" aria-expanded="false">
                    <span class="mld-accordion-title">Agent Filters</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content">
                    <!-- Agent ID (Combined - shows both buy side and sell side) -->
                    <div class="mld-field">
                        <label for="mld-agent-ids">Agent MLS ID(s)</label>
                        <input type="text" id="mld-agent-ids" placeholder="e.g., CT004645, TM356160" data-filter-key="agent_ids">
                        <p class="mld-field-hint"><strong>Recommended:</strong> Shows all deals where agent was listing agent OR buyer's agent. Supports multiple IDs separated by commas for team listings.</p>
                    </div>

                    <div class="mld-field-divider">
                        <span>— OR use specific filters below —</span>
                    </div>

                    <!-- Listing Agent (Seller's Agent) -->
                    <div class="mld-field">
                        <label for="mld-listing-agent-id">Listing Agent Only (Seller's Agent)</label>
                        <input type="text" id="mld-listing-agent-id" placeholder="Enter agent MLS ID" data-filter-key="listing_agent_id">
                        <p class="mld-field-hint">Only shows listings where agent represented the seller</p>
                    </div>

                    <!-- Buyer Agent -->
                    <div class="mld-field">
                        <label for="mld-buyer-agent-id">Buyer's Agent Only</label>
                        <input type="text" id="mld-buyer-agent-id" placeholder="Enter agent MLS ID" data-filter-key="buyer_agent_id">
                        <p class="mld-field-hint">Only shows listings where agent represented the buyer</p>
                    </div>
                </div>
            </div>

            <!-- Property Details Section -->
            <div class="mld-accordion-section" data-section="details">
                <button type="button" class="mld-accordion-header" aria-expanded="false">
                    <span class="mld-accordion-title">Property Details</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content">
                    <!-- Home Type (Multi-select) -->
                    <div class="mld-field">
                        <label for="mld-home-type">Home Type</label>
                        <div class="mld-multiselect-wrapper" data-filter-key="home_type">
                            <div class="mld-multiselect-selected" id="mld-home-type-selected">
                                <span class="mld-multiselect-placeholder">Select home types...</span>
                            </div>
                            <div class="mld-multiselect-dropdown" id="mld-home-type-dropdown" style="display: none;">
                                <input type="text" class="mld-multiselect-search" placeholder="Search home types...">
                                <div class="mld-multiselect-options">
                                    <?php if (!empty($home_types)): ?>
                                        <?php foreach ($home_types as $type): ?>
                                        <label class="mld-multiselect-option">
                                            <input type="checkbox" value="<?php echo esc_attr($type); ?>">
                                            <?php echo esc_html($type); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="mld-multiselect-empty">No home types available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <p class="mld-field-hint">Select multiple home types</p>
                    </div>

                    <!-- Square Footage -->
                    <div class="mld-field mld-field-row">
                        <div class="mld-field-half">
                            <label for="mld-sqft-min">Min Sq Ft</label>
                            <input type="number" id="mld-sqft-min" placeholder="0" data-filter-key="sqft_min">
                        </div>
                        <div class="mld-field-half">
                            <label for="mld-sqft-max">Max Sq Ft</label>
                            <input type="number" id="mld-sqft-max" placeholder="No Max" data-filter-key="sqft_max">
                        </div>
                    </div>

                    <!-- Lot Size -->
                    <div class="mld-field mld-field-row">
                        <div class="mld-field-half">
                            <label for="mld-lot-min">Min Lot (acres)</label>
                            <input type="number" id="mld-lot-min" step="0.01" placeholder="0" data-filter-key="lot_size_min">
                        </div>
                        <div class="mld-field-half">
                            <label for="mld-lot-max">Max Lot (acres)</label>
                            <input type="number" id="mld-lot-max" step="0.01" placeholder="No Max" data-filter-key="lot_size_max">
                        </div>
                    </div>

                    <!-- Year Built -->
                    <div class="mld-field mld-field-row">
                        <div class="mld-field-half">
                            <label for="mld-year-min">Year Built (Min)</label>
                            <input type="number" id="mld-year-min" placeholder="1900" data-filter-key="year_built_min">
                        </div>
                        <div class="mld-field-half">
                            <label for="mld-year-max">Year Built (Max)</label>
                            <input type="number" id="mld-year-max" placeholder="<?php echo date('Y'); ?>" data-filter-key="year_built_max">
                        </div>
                    </div>

                    <!-- Structure Type (Multi-select) -->
                    <div class="mld-field">
                        <label for="mld-structure-type">Structure Type</label>
                        <div class="mld-multiselect-wrapper" data-filter-key="structure_type">
                            <div class="mld-multiselect-selected" id="mld-structure-type-selected">
                                <span class="mld-multiselect-placeholder">Select structure types...</span>
                            </div>
                            <div class="mld-multiselect-dropdown" id="mld-structure-type-dropdown" style="display: none;">
                                <input type="text" class="mld-multiselect-search" placeholder="Search structure types...">
                                <div class="mld-multiselect-options">
                                    <?php if (!empty($structure_types)): ?>
                                        <?php foreach ($structure_types as $type): ?>
                                        <label class="mld-multiselect-option">
                                            <input type="checkbox" value="<?php echo esc_attr($type); ?>">
                                            <?php echo esc_html($type); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="mld-multiselect-empty">No structure types available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Architectural Style (Multi-select) -->
                    <div class="mld-field">
                        <label for="mld-arch-style">Architectural Style</label>
                        <div class="mld-multiselect-wrapper" data-filter-key="architectural_style">
                            <div class="mld-multiselect-selected" id="mld-arch-style-selected">
                                <span class="mld-multiselect-placeholder">Select architectural styles...</span>
                            </div>
                            <div class="mld-multiselect-dropdown" id="mld-arch-style-dropdown" style="display: none;">
                                <input type="text" class="mld-multiselect-search" placeholder="Search styles...">
                                <div class="mld-multiselect-options">
                                    <?php if (!empty($architectural_styles)): ?>
                                        <?php foreach ($architectural_styles as $style): ?>
                                        <label class="mld-multiselect-option">
                                            <input type="checkbox" value="<?php echo esc_attr($style); ?>">
                                            <?php echo esc_html($style); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="mld-multiselect-empty">No styles available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Features & Amenities Section -->
            <div class="mld-accordion-section" data-section="features">
                <button type="button" class="mld-accordion-header" aria-expanded="false">
                    <span class="mld-accordion-title">Features & Amenities</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content">
                    <!-- Garage -->
                    <div class="mld-field">
                        <label for="mld-garage-min">Minimum Garage Spaces</label>
                        <select id="mld-garage-min" data-filter-key="garage_spaces_min">
                            <option value="">Any</option>
                            <option value="1">1+</option>
                            <option value="2">2+</option>
                            <option value="3">3+</option>
                        </select>
                    </div>

                    <!-- Amenity Checkboxes -->
                    <div class="mld-field">
                        <label>Property Features</label>
                        <div class="mld-checkbox-group mld-checkbox-grid">
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="has_pool" value="yes">
                                Pool
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="has_fireplace" value="yes">
                                Fireplace
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="has_basement" value="yes">
                                Basement
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="pet_friendly" value="yes">
                                Pet Friendly
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="waterfront" value="yes">
                                Waterfront
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="view" value="yes">
                                View
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="spa" value="yes">
                                Spa
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="has_hoa" value="yes">
                                HOA
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="senior_community" value="yes">
                                Senior Community
                            </label>
                            <label class="mld-checkbox-label">
                                <input type="checkbox" data-filter-key="horse_property" value="yes">
                                Horse Property
                            </label>
                        </div>
                    </div>

                    <!-- Open House Only -->
                    <div class="mld-field">
                        <label class="mld-checkbox-label mld-checkbox-single">
                            <input type="checkbox" data-filter-key="open_house_only" value="yes">
                            Show Open Houses Only
                        </label>
                    </div>
                </div>
            </div>

            <!-- Display Options Section -->
            <div class="mld-accordion-section" data-section="display">
                <button type="button" class="mld-accordion-header" aria-expanded="false">
                    <span class="mld-accordion-title">Display Options</span>
                    <span class="mld-filter-badge" style="display: none;"></span>
                    <span class="mld-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="mld-accordion-content">
                    <!-- Columns -->
                    <div class="mld-field">
                        <label>Cards Per Row</label>
                        <div class="mld-radio-group">
                            <label class="mld-radio-label">
                                <input type="radio" name="columns" value="2" data-filter-key="columns">
                                2
                            </label>
                            <label class="mld-radio-label">
                                <input type="radio" name="columns" value="3" data-filter-key="columns" checked>
                                3
                            </label>
                            <label class="mld-radio-label">
                                <input type="radio" name="columns" value="4" data-filter-key="columns">
                                4
                            </label>
                        </div>
                    </div>

                    <!-- Per Page -->
                    <div class="mld-field">
                        <label for="mld-per-page">Initial Cards to Show</label>
                        <input type="number" id="mld-per-page" value="12" min="1" max="50" data-filter-key="per_page">
                    </div>

                    <!-- Sort By -->
                    <div class="mld-field">
                        <label for="mld-sort-by">Sort Order</label>
                        <select id="mld-sort-by" data-filter-key="sort_by">
                            <?php foreach ($sort_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php echo $value === 'newest' ? 'selected' : ''; ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Infinite Scroll -->
                    <div class="mld-field">
                        <label class="mld-checkbox-label mld-checkbox-single">
                            <input type="checkbox" data-filter-key="infinite_scroll" value="yes" checked>
                            Enable Infinite Scroll
                        </label>
                    </div>

                    <!-- Show Count -->
                    <div class="mld-field">
                        <label class="mld-checkbox-label mld-checkbox-single">
                            <input type="checkbox" data-filter-key="show_count" value="yes" checked>
                            Show Property Count
                        </label>
                    </div>

                    <!-- Show Sort -->
                    <div class="mld-field">
                        <label class="mld-checkbox-label mld-checkbox-single">
                            <input type="checkbox" data-filter-key="show_sort" value="yes" checked>
                            Show Sort Dropdown
                        </label>
                    </div>
                </div>
            </div>

            <button type="button" id="mld-generate-shortcode" class="button button-primary button-large">
                <span class="dashicons dashicons-shortcode"></span> Generate Shortcode
            </button>
        </div>

        <!-- Right Panel: Output & Preview -->
        <div class="mld-generator-output">
            <!-- Generated Shortcode Box -->
            <div class="mld-output-section">
                <h3>Generated Shortcode</h3>
                <div class="mld-shortcode-box">
                    <code id="mld-generated-shortcode">[mld_listing_cards]</code>
                </div>
                <div class="mld-output-actions">
                    <button type="button" id="mld-copy-shortcode" class="button">
                        <span class="dashicons dashicons-clipboard"></span> Copy to Clipboard
                    </button>
                    <button type="button" id="mld-reset-filters" class="button">
                        <span class="dashicons dashicons-image-rotate"></span> Reset
                    </button>
                </div>
                <div id="mld-copy-notice" class="mld-notice" style="display: none;"></div>
            </div>

            <!-- Preview Section -->
            <div class="mld-preview-section">
                <div class="mld-preview-header">
                    <h3>Live Preview</h3>
                    <label class="mld-toggle">
                        <input type="checkbox" id="mld-preview-toggle">
                        <span class="mld-toggle-slider"></span>
                    </label>
                </div>
                <div id="mld-preview-container" style="display: none;">
                    <p class="mld-preview-count">
                        <span id="mld-preview-shown">0</span> of <span id="mld-preview-total">0</span> matching properties
                    </p>
                    <div id="mld-preview-cards" class="mld-preview-grid">
                        <div class="mld-preview-loading">
                            <span class="spinner is-active"></span>
                            <p>Loading preview...</p>
                        </div>
                    </div>
                    <p class="mld-preview-disclaimer">
                        <em>Preview shows sample results. Actual display may vary based on available data.</em>
                    </p>
                </div>
            </div>

            <!-- Shortcode Usage Tips -->
            <div class="mld-tips-section">
                <h4>Usage Tips</h4>
                <ul>
                    <li>Copy the shortcode and paste it into any page or post</li>
                    <li>Only non-default values are included in the shortcode</li>
                    <li>Use multiple cities or ZIP codes by separating with commas</li>
                    <li>For beds, use "3+" format for minimum or "2,3,4" for specific values</li>
                </ul>
            </div>
        </div>
    </div>
</div>
