<?php
/**
 * Homepage Section: Mortgage Calculator
 *
 * Displays an interactive mortgage calculator
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if section is enabled
if (!get_theme_mod('bne_show_mortgage_section', true)) {
    return;
}

$section_title = get_theme_mod('bne_mortgage_title', 'Mortgage Calculator');
$section_subtitle = get_theme_mod('bne_mortgage_subtitle', 'Estimate your monthly payment and see how much home you can afford.');
$default_rate = floatval(get_theme_mod('bne_default_mortgage_rate', 6.5));
$default_tax_rate = floatval(get_theme_mod('bne_default_property_tax_rate', 1.2));
$default_insurance = intval(get_theme_mod('bne_default_home_insurance', 1200));
?>

<section id="mortgage-calculator" class="bne-lead-section bg-gradient" aria-labelledby="mortgage-title">
    <div class="section-container">
        <div class="section-header">
            <h2 id="mortgage-title" class="section-title"><?php echo esc_html($section_title); ?></h2>
            <p class="section-subtitle"><?php echo esc_html($section_subtitle); ?></p>
        </div>

        <div class="bne-glass-card">
            <div id="bne-mortgage-calculator" class="bne-mortgage-calculator">
                <!-- Left Column: Inputs -->
                <div class="bne-calc-inputs">
                    <!-- Home Price -->
                    <div class="bne-calc-group">
                        <label for="bne-home-price">
                            Home Price
                            <span class="value-display">$500,000</span>
                        </label>
                        <div class="bne-currency-input">
                            <input
                                type="number"
                                id="bne-home-price"
                                value="500000"
                                min="50000"
                                max="10000000"
                                step="10000"
                            >
                        </div>
                    </div>

                    <!-- Down Payment -->
                    <div class="bne-calc-group">
                        <label for="bne-down-payment">
                            Down Payment
                            <span id="bne-down-payment-percent" class="value-display">20%</span>
                        </label>
                        <div class="bne-calc-input-row">
                            <div class="bne-currency-input" style="flex: 1;">
                                <input
                                    type="number"
                                    id="bne-down-payment"
                                    value="100000"
                                    min="0"
                                    step="5000"
                                >
                            </div>
                        </div>
                        <input
                            type="range"
                            id="bne-down-payment-slider"
                            class="bne-down-payment-slider"
                            value="20"
                            min="0"
                            max="100"
                            step="1"
                        >
                    </div>

                    <!-- Loan Term -->
                    <div class="bne-calc-group">
                        <label>Loan Term</label>
                        <div class="bne-term-buttons">
                            <button type="button" class="bne-term-btn" data-term="15">15 years</button>
                            <button type="button" class="bne-term-btn" data-term="20">20 years</button>
                            <button type="button" class="bne-term-btn active" data-term="30">30 years</button>
                        </div>
                        <input type="hidden" id="bne-loan-term" value="30">
                    </div>

                    <!-- Interest Rate -->
                    <div class="bne-calc-group">
                        <label for="bne-interest-rate">Interest Rate</label>
                        <div class="bne-percent-input">
                            <input
                                type="number"
                                id="bne-interest-rate"
                                value="<?php echo esc_attr($default_rate); ?>"
                                min="0"
                                max="20"
                                step="0.125"
                            >
                        </div>
                    </div>

                    <!-- Property Tax -->
                    <div class="bne-calc-group">
                        <label for="bne-property-tax">Annual Property Tax Rate</label>
                        <div class="bne-percent-input">
                            <input
                                type="number"
                                id="bne-property-tax"
                                value="<?php echo esc_attr($default_tax_rate); ?>"
                                min="0"
                                max="5"
                                step="0.1"
                            >
                        </div>
                    </div>

                    <!-- Home Insurance -->
                    <div class="bne-calc-group">
                        <label for="bne-insurance">Annual Home Insurance</label>
                        <div class="bne-currency-input">
                            <input
                                type="number"
                                id="bne-insurance"
                                value="<?php echo esc_attr($default_insurance); ?>"
                                min="0"
                                max="20000"
                                step="100"
                            >
                        </div>
                    </div>

                    <!-- HOA (Optional) -->
                    <div class="bne-calc-group">
                        <label for="bne-hoa">Monthly HOA Dues (if any)</label>
                        <div class="bne-currency-input">
                            <input
                                type="number"
                                id="bne-hoa"
                                value="0"
                                min="0"
                                max="5000"
                                step="25"
                            >
                        </div>
                    </div>
                </div>

                <!-- Right Column: Results -->
                <div class="bne-calc-results">
                    <!-- Monthly Payment Display -->
                    <div class="bne-payment-display">
                        <div class="bne-payment-label">Estimated Monthly Payment</div>
                        <div class="bne-monthly-payment">$2,684</div>
                        <div class="bne-payment-period">per month</div>
                    </div>

                    <!-- Payment Breakdown Chart -->
                    <div class="bne-payment-chart-container">
                        <div class="bne-payment-chart" aria-hidden="true"></div>
                        <div class="bne-payment-legend">
                            <div class="bne-legend-item">
                                <span class="bne-legend-color principal"></span>
                                <span class="bne-legend-label">Principal</span>
                                <span class="bne-legend-value bne-payment-principal">$675</span>
                            </div>
                            <div class="bne-legend-item">
                                <span class="bne-legend-color interest"></span>
                                <span class="bne-legend-label">Interest</span>
                                <span class="bne-legend-value bne-payment-interest">$1,667</span>
                            </div>
                            <div class="bne-legend-item">
                                <span class="bne-legend-color taxes"></span>
                                <span class="bne-legend-label">Property Tax</span>
                                <span class="bne-legend-value bne-payment-taxes">$500</span>
                            </div>
                            <div class="bne-legend-item">
                                <span class="bne-legend-color insurance"></span>
                                <span class="bne-legend-label">Insurance</span>
                                <span class="bne-legend-value bne-payment-insurance">$100</span>
                            </div>
                            <div class="bne-legend-item">
                                <span class="bne-legend-color hoa"></span>
                                <span class="bne-legend-label">HOA</span>
                                <span class="bne-legend-value bne-payment-hoa">$0</span>
                            </div>
                        </div>
                    </div>

                    <!-- Loan Summary -->
                    <div class="bne-loan-info">
                        <div class="bne-loan-info-item">
                            <div class="bne-loan-info-label">Loan Amount</div>
                            <div class="bne-loan-info-value bne-loan-amount">$400,000</div>
                        </div>
                        <div class="bne-loan-info-item">
                            <div class="bne-loan-info-label">Total Interest</div>
                            <div class="bne-loan-info-value bne-total-payment">$566,280</div>
                        </div>
                    </div>

                    <!-- CTA -->
                    <div style="margin-top: 24px; width: 100%;">
                        <a href="#cma-request" class="bne-submit-btn" style="text-decoration: none; display: inline-flex;">
                            <span>Get Pre-Approved Today</span>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Disclaimer -->
        <p style="text-align: center; color: #94a3b8; font-size: 0.8125rem; margin-top: 24px; max-width: 700px; margin-left: auto; margin-right: auto;">
            * This calculator provides estimates for informational purposes only. Actual payment amounts may vary based on lender requirements, credit score, and other factors. Contact a mortgage professional for accurate quotes.
        </p>
    </div>
</section>
