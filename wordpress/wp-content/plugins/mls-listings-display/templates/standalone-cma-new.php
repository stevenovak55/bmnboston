<?php
/**
 * Standalone CMA - New Entry Page Template
 *
 * Displays a form for users to create a new standalone CMA
 * by entering property details manually.
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Get Google Maps API key using proper method
$google_maps_key = '';
if (class_exists('MLD_Settings')) {
    $google_maps_key = MLD_Settings::get_google_maps_api_key();
}
?>

<div class="mld-standalone-cma-wrapper mld-standalone-cma-new-page">
    <div class="mld-scma-container">

        <!-- Header -->
        <header class="mld-scma-header">
            <div class="mld-scma-header-content">
                <h1>Create Standalone CMA</h1>
                <p class="mld-scma-subtitle">Enter property details to generate a Comparative Market Analysis</p>
            </div>
        </header>

        <!-- Entry Form -->
        <div class="mld-scma-form-wrapper">
            <form id="mld-standalone-cma-form" class="mld-scma-form">

                <!-- Section 1: Address -->
                <div class="mld-scma-form-section">
                    <h2>Property Address</h2>
                    <p class="section-description">Start typing to search for an address, or enter manually below.</p>

                    <div class="mld-scma-field-group">
                        <label for="scma-address-autocomplete">Property Address <span class="required">*</span></label>
                        <input type="text"
                               id="scma-address-autocomplete"
                               class="mld-scma-input mld-scma-address-input"
                               placeholder="Start typing address..."
                               autocomplete="off"
                               required>
                        <p class="field-hint">Search will auto-fill city, state, and coordinates</p>
                    </div>

                    <!-- Hidden fields for geocoded data -->
                    <input type="hidden" id="scma-address" name="address" value="">
                    <input type="hidden" id="scma-lat" name="lat" value="">
                    <input type="hidden" id="scma-lng" name="lng" value="">
                    <input type="hidden" id="scma-city" name="city" value="">
                    <input type="hidden" id="scma-state" name="state" value="MA">
                    <input type="hidden" id="scma-postal-code" name="postal_code" value="">

                    <!-- Manual entry fallback -->
                    <div id="scma-manual-entry" class="mld-scma-manual-entry" style="display: none;">
                        <p class="manual-entry-note">Could not auto-detect location. Please enter details manually:</p>

                        <div class="mld-scma-field-row">
                            <div class="mld-scma-field-group">
                                <label for="scma-manual-address">Street Address <span class="required">*</span></label>
                                <input type="text" id="scma-manual-address" class="mld-scma-input" placeholder="123 Main Street">
                            </div>
                        </div>

                        <div class="mld-scma-field-row mld-scma-field-row-3">
                            <div class="mld-scma-field-group">
                                <label for="scma-manual-city">City <span class="required">*</span></label>
                                <input type="text" id="scma-manual-city" class="mld-scma-input" placeholder="Boston">
                            </div>
                            <div class="mld-scma-field-group">
                                <label for="scma-manual-state">State <span class="required">*</span></label>
                                <select id="scma-manual-state" class="mld-scma-select">
                                    <option value="MA" selected>Massachusetts</option>
                                    <option value="NH">New Hampshire</option>
                                    <option value="RI">Rhode Island</option>
                                    <option value="CT">Connecticut</option>
                                    <option value="ME">Maine</option>
                                    <option value="VT">Vermont</option>
                                </select>
                            </div>
                            <div class="mld-scma-field-group">
                                <label for="scma-manual-zip">ZIP Code</label>
                                <input type="text" id="scma-manual-zip" class="mld-scma-input" placeholder="02101">
                            </div>
                        </div>

                        <button type="button" id="scma-geocode-btn" class="mld-scma-btn mld-scma-btn-secondary">
                            Verify Address & Get Coordinates
                        </button>
                    </div>

                    <!-- Address verification status -->
                    <div id="scma-address-status" class="mld-scma-address-status" style="display: none;">
                        <span class="status-icon"></span>
                        <span class="status-text"></span>
                    </div>
                </div>

                <!-- Section 2: Property Details -->
                <div class="mld-scma-form-section">
                    <h2>Property Details</h2>
                    <p class="section-description">Enter the key characteristics of the property.</p>

                    <div class="mld-scma-field-row mld-scma-field-row-3">
                        <div class="mld-scma-field-group">
                            <label for="scma-beds">Bedrooms <span class="required">*</span></label>
                            <input type="number" id="scma-beds" name="beds" class="mld-scma-input"
                                   min="0" max="20" value="3" required>
                        </div>
                        <div class="mld-scma-field-group">
                            <label for="scma-baths">Bathrooms <span class="required">*</span></label>
                            <input type="number" id="scma-baths" name="baths" class="mld-scma-input"
                                   min="0" max="20" step="0.5" value="2" required>
                        </div>
                        <div class="mld-scma-field-group">
                            <label for="scma-sqft">Square Footage <span class="required">*</span></label>
                            <input type="number" id="scma-sqft" name="sqft" class="mld-scma-input"
                                   min="100" max="100000" value="1800" required>
                        </div>
                    </div>

                    <div class="mld-scma-field-row mld-scma-field-row-3">
                        <div class="mld-scma-field-group">
                            <label for="scma-property-type">Property Type <span class="required">*</span></label>
                            <select id="scma-property-type" name="property_type" class="mld-scma-select" required>
                                <option value="Single Family Residence" selected>Single Family</option>
                                <option value="Condominium">Condominium</option>
                                <option value="Townhouse">Townhouse</option>
                                <option value="Multi Family">Multi Family (2-4 units)</option>
                                <option value="Land">Land/Lot</option>
                            </select>
                        </div>
                        <div class="mld-scma-field-group">
                            <label for="scma-year-built">Year Built</label>
                            <input type="number" id="scma-year-built" name="year_built" class="mld-scma-input"
                                   min="1800" max="2030" placeholder="e.g., 1995">
                        </div>
                        <div class="mld-scma-field-group">
                            <label for="scma-garage">Garage Spaces</label>
                            <input type="number" id="scma-garage" name="garage_spaces" class="mld-scma-input"
                                   min="0" max="10" value="1">
                        </div>
                    </div>

                    <div class="mld-scma-field-row">
                        <div class="mld-scma-field-group mld-scma-field-large">
                            <label for="scma-price">Estimated Value / Price <span class="required">*</span></label>
                            <div class="mld-scma-price-input-wrapper">
                                <span class="price-prefix">$</span>
                                <input type="number" id="scma-price" name="price" class="mld-scma-input"
                                       min="10000" max="100000000" placeholder="500000" required>
                            </div>
                            <p class="field-hint">Used for filtering comparable properties by price range</p>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Features & Condition -->
                <div class="mld-scma-form-section">
                    <h2>Features & Condition</h2>
                    <p class="section-description">Optional details that help refine comparable property matching.</p>

                    <div class="mld-scma-field-row mld-scma-field-row-2">
                        <div class="mld-scma-field-group">
                            <label for="scma-road-type">Road Type</label>
                            <select id="scma-road-type" name="road_type" class="mld-scma-select">
                                <option value="unknown" selected>Unknown</option>
                                <option value="main_road">Main Road</option>
                                <option value="neighborhood_road">Neighborhood Road</option>
                                <option value="private_road">Private Road</option>
                                <option value="dirt_road">Dirt/Gravel Road</option>
                            </select>
                        </div>
                        <div class="mld-scma-field-group">
                            <label for="scma-condition">Property Condition</label>
                            <select id="scma-condition" name="property_condition" class="mld-scma-select">
                                <option value="unknown" selected>Unknown</option>
                                <option value="new">New Construction</option>
                                <option value="fully_renovated">Fully Renovated</option>
                                <option value="some_updates">Some Updates</option>
                                <option value="needs_updating">Needs Updating</option>
                                <option value="distressed">Distressed/Fixer-Upper</option>
                            </select>
                        </div>
                    </div>

                    <div class="mld-scma-field-row mld-scma-checkboxes">
                        <div class="mld-scma-checkbox-group">
                            <label class="mld-scma-checkbox-label">
                                <input type="checkbox" id="scma-pool" name="pool" value="1">
                                <span class="checkbox-text">Has Pool</span>
                            </label>
                        </div>
                        <div class="mld-scma-checkbox-group">
                            <label class="mld-scma-checkbox-label">
                                <input type="checkbox" id="scma-waterfront" name="waterfront" value="1">
                                <span class="checkbox-text">Waterfront Property</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Section 4: CMA Name (Optional) -->
                <div class="mld-scma-form-section">
                    <h2>CMA Details (Optional)</h2>

                    <div class="mld-scma-field-row">
                        <div class="mld-scma-field-group mld-scma-field-large">
                            <label for="scma-session-name">CMA Name</label>
                            <input type="text" id="scma-session-name" name="session_name" class="mld-scma-input"
                                   placeholder="e.g., Investment Property Analysis">
                            <p class="field-hint">Leave blank to auto-generate from address</p>
                        </div>
                    </div>

                    <div class="mld-scma-field-row">
                        <div class="mld-scma-field-group mld-scma-field-large">
                            <label for="scma-description">Notes / Description</label>
                            <textarea id="scma-description" name="description" class="mld-scma-textarea"
                                      rows="3" placeholder="Add any notes about this CMA..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="mld-scma-form-actions">
                    <button type="submit" id="scma-submit-btn" class="mld-scma-btn mld-scma-btn-primary" disabled>
                        <span class="btn-text">Create CMA</span>
                        <span class="btn-loading" style="display: none;">Creating...</span>
                    </button>
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="mld-scma-btn mld-scma-btn-secondary">Cancel</a>
                </div>

                <!-- Error display -->
                <div id="scma-form-error" class="mld-scma-form-error" style="display: none;"></div>

            </form>
        </div>

        <!-- Login prompt for saving -->
        <?php if (!is_user_logged_in()) : ?>
        <div class="mld-scma-login-notice">
            <div class="notice-icon">&#x1F511;</div>
            <div class="notice-content">
                <strong>Want to save this CMA to your account?</strong>
                <p>Your CMA will still be created with a shareable URL, but logging in allows you to:</p>
                <ul>
                    <li>Access your CMAs from "My CMAs" on any property page</li>
                    <li>Edit and update your saved CMAs</li>
                    <li>Mark favorites for quick access</li>
                </ul>
                <?php
                $login_url = wp_login_url(home_url('/cma/'));
                ?>
                <a href="<?php echo esc_url($login_url); ?>" class="mld-scma-login-link">Log in now</a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php get_footer(); ?>
