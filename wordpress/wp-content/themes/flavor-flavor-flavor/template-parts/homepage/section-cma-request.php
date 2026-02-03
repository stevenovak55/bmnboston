<?php
/**
 * Homepage Section: CMA Request Form
 *
 * Displays a form for users to request a Comparative Market Analysis
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if section is enabled
if (!get_theme_mod('bne_show_cma_section', true)) {
    return;
}

$section_title = get_theme_mod('bne_cma_title', 'Get Your Free Home Valuation');
$section_subtitle = get_theme_mod('bne_cma_subtitle', 'Request a Comparative Market Analysis to discover what your home is worth in today\'s market.');
$agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
?>

<section id="cma-request" class="bne-lead-section bg-gradient" aria-labelledby="cma-title">
    <div class="section-container">
        <div class="bne-two-col-layout">
            <!-- Left Column: Content -->
            <div class="bne-col-content">
                <h2 id="cma-title"><?php echo esc_html($section_title); ?></h2>
                <p><?php echo esc_html($section_subtitle); ?></p>

                <ul class="bne-benefits-list">
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>Detailed analysis of comparable sales in your area</span>
                    </li>
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>Current market trends and pricing insights</span>
                    </li>
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>Personalized report delivered within 24-48 hours</span>
                    </li>
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span>No obligation, completely free service</span>
                    </li>
                </ul>
            </div>

            <!-- Right Column: Form -->
            <div class="bne-col-form">
                <div class="bne-glass-card">
                    <form id="bne-cma-form" class="bne-lead-form" novalidate>
                        <!-- Property Type Selection -->
                        <div class="bne-form-group">
                            <label>Property Type</label>
                            <div class="bne-property-types">
                                <button type="button" class="bne-property-type-btn active" data-type="single_family">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                    </svg>
                                    <span>Single Family</span>
                                </button>
                                <button type="button" class="bne-property-type-btn" data-type="condo">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                                        <path d="M9 22v-4h6v4"></path>
                                        <path d="M8 6h.01"></path>
                                        <path d="M16 6h.01"></path>
                                        <path d="M12 6h.01"></path>
                                        <path d="M12 10h.01"></path>
                                        <path d="M12 14h.01"></path>
                                        <path d="M16 10h.01"></path>
                                        <path d="M16 14h.01"></path>
                                        <path d="M8 10h.01"></path>
                                        <path d="M8 14h.01"></path>
                                    </svg>
                                    <span>Condo</span>
                                </button>
                                <button type="button" class="bne-property-type-btn" data-type="townhouse">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 21h6V10H3z"></path>
                                        <path d="M9 21h6V6H9z"></path>
                                        <path d="M15 21h6V10h-6z"></path>
                                    </svg>
                                    <span>Townhouse</span>
                                </button>
                                <button type="button" class="bne-property-type-btn" data-type="multi_family">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"></path>
                                        <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"></path>
                                        <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"></path>
                                        <path d="M10 6h4"></path>
                                        <path d="M10 10h4"></path>
                                        <path d="M10 14h4"></path>
                                        <path d="M10 18h4"></path>
                                    </svg>
                                    <span>Multi-Family</span>
                                </button>
                            </div>
                            <input type="hidden" name="property_type" value="single_family">
                        </div>

                        <!-- Property Address -->
                        <div class="bne-form-group">
                            <label for="cma-address">Property Address <span class="required">*</span></label>
                            <textarea
                                id="cma-address"
                                name="property_address"
                                rows="2"
                                placeholder="Enter your full property address"
                                required
                            ></textarea>
                        </div>

                        <!-- Name Fields -->
                        <div class="bne-form-row">
                            <div class="bne-form-group">
                                <label for="cma-first-name">First Name <span class="required">*</span></label>
                                <input
                                    type="text"
                                    id="cma-first-name"
                                    name="first_name"
                                    placeholder="Your first name"
                                    required
                                >
                            </div>
                            <div class="bne-form-group">
                                <label for="cma-last-name">Last Name</label>
                                <input
                                    type="text"
                                    id="cma-last-name"
                                    name="last_name"
                                    placeholder="Your last name"
                                >
                            </div>
                        </div>

                        <!-- Contact Fields -->
                        <div class="bne-form-row">
                            <div class="bne-form-group">
                                <label for="cma-email">Email <span class="required">*</span></label>
                                <input
                                    type="email"
                                    id="cma-email"
                                    name="email"
                                    placeholder="your@email.com"
                                    required
                                >
                            </div>
                            <div class="bne-form-group">
                                <label for="cma-phone">Phone</label>
                                <input
                                    type="tel"
                                    id="cma-phone"
                                    name="phone"
                                    placeholder="(555) 555-5555"
                                >
                            </div>
                        </div>

                        <!-- Timeline -->
                        <div class="bne-form-group">
                            <label for="cma-timeline">When are you thinking of selling?</label>
                            <select id="cma-timeline" name="timeline">
                                <option value="">Select a timeframe</option>
                                <option value="asap">As soon as possible</option>
                                <option value="1-3months">1-3 months</option>
                                <option value="3-6months">3-6 months</option>
                                <option value="6-12months">6-12 months</option>
                                <option value="justcurious">Just curious about value</option>
                            </select>
                        </div>

                        <!-- Additional Notes -->
                        <div class="bne-form-group">
                            <label for="cma-message">Additional Information</label>
                            <textarea
                                id="cma-message"
                                name="message"
                                rows="3"
                                placeholder="Any recent upgrades, special features, or questions?"
                            ></textarea>
                        </div>

                        <!-- Honeypot -->
                        <div class="bne-hp-field" aria-hidden="true">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="bne-submit-btn">
                            <span>Request Free CMA</span>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>

                        <!-- Feedback Message -->
                        <div class="bne-form-feedback" role="alert" aria-live="polite"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
