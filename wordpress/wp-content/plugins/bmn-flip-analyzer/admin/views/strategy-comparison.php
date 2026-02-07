<?php
/**
 * Strategy Comparison Sub-Page View.
 *
 * Side-by-side comparison of Flip vs Rental Hold vs BRRRR
 * for a selected property from the current report.
 *
 * v0.16.0: Initial implementation.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap flip-dashboard flip-comparison-page">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-chart-area"></span>
        Strategy Comparison
    </h1>
    <p class="description">Compare Flip, Rental Hold, and BRRRR strategies side-by-side for any analyzed property.</p>

    <!-- Property Selector -->
    <div class="flip-card">
        <div class="flip-card-header">
            <h2>Select Property</h2>
        </div>
        <div class="flip-card-body">
            <select id="flip-comparison-property" class="regular-text" style="width:400px;max-width:100%">
                <option value="">-- Select a property --</option>
            </select>
        </div>
    </div>

    <!-- Comparison Table -->
    <div id="flip-comparison-content" style="display:none;">
        <div class="flip-card">
            <div class="flip-card-header">
                <h2 id="flip-comparison-title">Strategy Comparison</h2>
            </div>
            <div class="flip-card-body flip-card-body-table">
                <table class="flip-comparison-table" id="flip-comparison-table">
                    <thead>
                        <tr>
                            <th style="width:25%">Metric</th>
                            <th style="width:25%">Flip</th>
                            <th style="width:25%">Rental Hold</th>
                            <th style="width:25%">BRRRR</th>
                        </tr>
                    </thead>
                    <tbody id="flip-comparison-body"></tbody>
                </table>
            </div>
        </div>

        <!-- Strategy Recommendation -->
        <div class="flip-card">
            <div class="flip-card-header">
                <h2>Recommendation</h2>
            </div>
            <div class="flip-card-body">
                <div id="flip-comparison-recommendation"></div>
            </div>
        </div>
    </div>

    <div id="flip-comparison-empty" class="flip-empty">
        Select a property above to see strategy comparison. Properties must have rental analysis data (run analysis with v0.16.0+).
    </div>
</div>
