<?php
/**
 * Homepage Section: Property Alerts Signup
 *
 * Displays a form for users to sign up for property alerts
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if section is enabled
if (!get_theme_mod('bne_show_alerts_section', true)) {
    return;
}

$section_title = get_theme_mod('bne_alerts_title', 'Never Miss a New Listing');
$section_subtitle = get_theme_mod('bne_alerts_subtitle', 'Get instant notifications when properties matching your criteria hit the market.');
$suggested_cities = get_theme_mod('bne_alerts_cities', 'Boston, Cambridge, Somerville, Newton, Brookline');
$cities_array = array_map('trim', explode(',', $suggested_cities));
?>

<section id="property-alerts" class="bne-lead-section bg-primary" aria-labelledby="alerts-title">
    <div class="section-container">
        <div class="section-header">
            <h2 id="alerts-title" class="section-title"><?php echo esc_html($section_title); ?></h2>
            <p class="section-subtitle"><?php echo esc_html($section_subtitle); ?></p>
        </div>

        <div class="bne-glass-card" style="max-width: 700px; margin: 0 auto;">
            <form id="bne-alerts-form" class="bne-lead-form" novalidate>
                <!-- Name and Email -->
                <div class="bne-form-row">
                    <div class="bne-form-group">
                        <label for="alerts-name">Your Name</label>
                        <input
                            type="text"
                            id="alerts-name"
                            name="first_name"
                            placeholder="First name"
                        >
                    </div>
                    <div class="bne-form-group">
                        <label for="alerts-email">Email Address <span class="required">*</span></label>
                        <input
                            type="email"
                            id="alerts-email"
                            name="email"
                            placeholder="your@email.com"
                            required
                        >
                    </div>
                </div>

                <!-- Cities Selection -->
                <div class="bne-form-group">
                    <label for="alerts-city-input">Cities/Towns (type and press Enter)</label>
                    <div class="bne-city-input-wrapper">
                        <input
                            type="text"
                            id="alerts-city-input"
                            class="bne-city-input"
                            placeholder="e.g., Boston, Cambridge..."
                            list="suggested-cities"
                        >
                        <datalist id="suggested-cities">
                            <?php foreach ($cities_array as $city) : ?>
                                <option value="<?php echo esc_attr($city); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="bne-city-tags"></div>
                    <input type="hidden" name="cities" value="">
                </div>

                <!-- Price Range -->
                <div class="bne-form-group">
                    <label>Price Range</label>
                    <div class="bne-price-range">
                        <div class="bne-range-labels">
                            <span class="bne-min-price-display">No min</span>
                            <span class="bne-max-price-display">No max</span>
                        </div>
                        <div class="bne-range-track">
                            <input
                                type="range"
                                id="bne-min-price"
                                name="min_price"
                                min="0"
                                max="2000000"
                                step="50000"
                                value="0"
                            >
                            <input
                                type="range"
                                id="bne-max-price"
                                name="max_price"
                                min="0"
                                max="5000000"
                                step="50000"
                                value="0"
                            >
                        </div>
                    </div>
                </div>

                <!-- Bedrooms -->
                <div class="bne-form-row">
                    <div class="bne-form-group">
                        <label for="alerts-bedrooms">Minimum Bedrooms</label>
                        <select id="alerts-bedrooms" name="bedrooms">
                            <option value="0">Any</option>
                            <option value="1">1+</option>
                            <option value="2">2+</option>
                            <option value="3">3+</option>
                            <option value="4">4+</option>
                            <option value="5">5+</option>
                        </select>
                    </div>
                    <div class="bne-form-group">
                        <label>Property Type</label>
                        <select name="property_types[]" multiple style="height: auto; min-height: 44px;">
                            <option value="single_family" selected>Single Family</option>
                            <option value="condo">Condo</option>
                            <option value="townhouse">Townhouse</option>
                            <option value="multi_family">Multi-Family</option>
                        </select>
                    </div>
                </div>

                <!-- Notification Frequency -->
                <div class="bne-form-group">
                    <label>How often do you want to be notified?</label>
                    <div class="bne-frequency-selector">
                        <div class="bne-frequency-option">
                            <input type="radio" id="freq-instant" name="frequency" value="instant" checked>
                            <label for="freq-instant">Instant</label>
                        </div>
                        <div class="bne-frequency-option">
                            <input type="radio" id="freq-daily" name="frequency" value="daily">
                            <label for="freq-daily">Daily</label>
                        </div>
                        <div class="bne-frequency-option">
                            <input type="radio" id="freq-weekly" name="frequency" value="weekly">
                            <label for="freq-weekly">Weekly</label>
                        </div>
                    </div>
                </div>

                <!-- Honeypot -->
                <div class="bne-hp-field" aria-hidden="true">
                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <!-- Submit Button -->
                <button type="submit" class="bne-submit-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span>Start Getting Alerts</span>
                </button>

                <!-- Feedback Message -->
                <div class="bne-form-feedback" role="alert" aria-live="polite"></div>
            </form>
        </div>
    </div>
</section>
