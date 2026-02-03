<?php
/**
 * Homepage Section: Schedule Showing / Tour Request
 *
 * Displays a form for users to schedule property tours
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if section is enabled
if (!get_theme_mod('bne_show_tour_section', true)) {
    return;
}

$section_title = get_theme_mod('bne_tour_title', 'Schedule a Property Tour');
$section_subtitle = get_theme_mod('bne_tour_subtitle', 'Choose your preferred tour type and let us show you your next home.');
$agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
$agent_phone = get_theme_mod('bne_phone_number', '(617) 955-2224');
?>

<section id="schedule-tour" class="bne-lead-section" aria-labelledby="tour-title">
    <div class="section-container">
        <div class="section-header">
            <h2 id="tour-title" class="section-title"><?php echo esc_html($section_title); ?></h2>
            <p class="section-subtitle"><?php echo esc_html($section_subtitle); ?></p>
        </div>

        <div class="bne-glass-card" style="max-width: 800px; margin: 0 auto;">
            <form id="bne-tour-form" class="bne-lead-form" novalidate>
                <!-- Tour Type Selection -->
                <div class="bne-form-group">
                    <label>Select Tour Type</label>
                    <div class="bne-tour-types">
                        <div class="bne-tour-type-card selected" data-type="in_person">
                            <div class="icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            </div>
                            <h4>In-Person Tour</h4>
                            <p>Meet with an agent for a personal walkthrough</p>
                        </div>
                        <div class="bne-tour-type-card" data-type="video">
                            <div class="icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="23 7 16 12 23 17 23 7"></polygon>
                                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                                </svg>
                            </div>
                            <h4>Video Tour</h4>
                            <p>Live virtual tour from anywhere</p>
                        </div>
                        <div class="bne-tour-type-card" data-type="self_guided">
                            <div class="icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <h4>Self-Guided</h4>
                            <p>Explore at your own pace with lockbox access</p>
                        </div>
                    </div>
                    <input type="hidden" name="tour_type" value="in_person">
                </div>

                <!-- Property Info (Optional) -->
                <div class="bne-form-group">
                    <label for="tour-property">Property Address or MLS# (if known)</label>
                    <input
                        type="text"
                        id="tour-property"
                        name="property_address"
                        placeholder="Enter property address or MLS number"
                    >
                </div>

                <!-- Date and Time -->
                <div class="bne-form-row">
                    <div class="bne-form-group">
                        <label for="tour-date">Preferred Date <span class="required">*</span></label>
                        <input
                            type="date"
                            id="tour-date"
                            name="preferred_date"
                            required
                        >
                    </div>
                    <div class="bne-form-group">
                        <label>Preferred Time</label>
                        <div class="bne-time-slots">
                            <button type="button" class="bne-time-slot" data-time="morning">Morning</button>
                            <button type="button" class="bne-time-slot selected" data-time="afternoon">Afternoon</button>
                            <button type="button" class="bne-time-slot" data-time="evening">Evening</button>
                            <button type="button" class="bne-time-slot" data-time="flexible">Flexible</button>
                        </div>
                        <input type="hidden" name="preferred_time" value="afternoon">
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="bne-form-row">
                    <div class="bne-form-group">
                        <label for="tour-first-name">First Name <span class="required">*</span></label>
                        <input
                            type="text"
                            id="tour-first-name"
                            name="first_name"
                            placeholder="Your first name"
                            required
                        >
                    </div>
                    <div class="bne-form-group">
                        <label for="tour-last-name">Last Name</label>
                        <input
                            type="text"
                            id="tour-last-name"
                            name="last_name"
                            placeholder="Your last name"
                        >
                    </div>
                </div>

                <div class="bne-form-row">
                    <div class="bne-form-group">
                        <label for="tour-email">Email <span class="required">*</span></label>
                        <input
                            type="email"
                            id="tour-email"
                            name="email"
                            placeholder="your@email.com"
                            required
                        >
                    </div>
                    <div class="bne-form-group">
                        <label for="tour-phone">Phone</label>
                        <input
                            type="tel"
                            id="tour-phone"
                            name="phone"
                            placeholder="(555) 555-5555"
                        >
                    </div>
                </div>

                <!-- Message -->
                <div class="bne-form-group">
                    <label for="tour-message">Additional Notes</label>
                    <textarea
                        id="tour-message"
                        name="message"
                        rows="3"
                        placeholder="Any specific questions or accessibility requirements?"
                    ></textarea>
                </div>

                <!-- Honeypot -->
                <div class="bne-hp-field" aria-hidden="true">
                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <!-- Submit Button -->
                <button type="submit" class="bne-submit-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span>Request Tour</span>
                </button>

                <!-- Feedback Message -->
                <div class="bne-form-feedback" role="alert" aria-live="polite"></div>

                <!-- Direct Contact Option -->
                <div style="text-align: center; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                    <p style="color: #64748b; margin: 0; font-size: 0.9375rem;">
                        Need immediate assistance? Call
                        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $agent_phone)); ?>" style="color: var(--bne-color-primary); font-weight: 600;">
                            <?php echo esc_html($agent_phone); ?>
                        </a>
                    </p>
                </div>
            </form>
        </div>
    </div>
</section>
