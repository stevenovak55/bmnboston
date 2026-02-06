<?php
/**
 * Flip Analyzer Dashboard View.
 *
 * Available via wp_localize_script as flipData.data:
 *   - summary: {total, viable, avg_score, avg_roi, disqualified, last_run, cities}
 *   - results: array of property result objects
 *   - cities: array of target city names
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap flip-dashboard">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-chart-bar"></span>
        Flip Analyzer
    </h1>

    <!-- Action Buttons -->
    <div class="flip-actions">
        <button id="flip-run-analysis" class="button button-primary button-large">
            <span class="dashicons dashicons-update"></span> Run Analysis
        </button>
        <button id="flip-run-photos" class="button button-large">
            <span class="dashicons dashicons-camera"></span> Run Photo Analysis
        </button>
        <button id="flip-export-csv" class="button button-large">
            <span class="dashicons dashicons-download"></span> Export CSV
        </button>
        <span id="flip-last-run" class="flip-last-run"></span>
    </div>

    <!-- Summary Stats -->
    <div class="flip-stats-row">
        <div class="flip-stat-card">
            <div class="flip-stat-value" id="stat-total">--</div>
            <div class="flip-stat-label">Properties Analyzed</div>
        </div>
        <div class="flip-stat-card flip-stat-viable">
            <div class="flip-stat-value" id="stat-viable">--</div>
            <div class="flip-stat-label">Viable (Score 60+)</div>
        </div>
        <div class="flip-stat-card">
            <div class="flip-stat-value" id="stat-avg-score">--</div>
            <div class="flip-stat-label">Avg Score</div>
        </div>
        <div class="flip-stat-card">
            <div class="flip-stat-value" id="stat-avg-roi">--</div>
            <div class="flip-stat-label">Avg ROI</div>
        </div>
        <div class="flip-stat-card flip-stat-near">
            <div class="flip-stat-value" id="stat-near-viable">--</div>
            <div class="flip-stat-label">Near-Viable</div>
        </div>
        <div class="flip-stat-card flip-stat-dq">
            <div class="flip-stat-value" id="stat-disqualified">--</div>
            <div class="flip-stat-label">Disqualified</div>
        </div>
    </div>

    <!-- Target Cities -->
    <div class="flip-card flip-cities-card">
        <div class="flip-card-header">
            <h2>Target Cities</h2>
        </div>
        <div class="flip-card-body">
            <div id="flip-city-tags" class="flip-city-tags"></div>
            <div class="flip-city-add">
                <input type="text" id="flip-city-input" placeholder="Add city name..." class="regular-text" style="width:200px">
                <button id="flip-city-add-btn" class="button button-small">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;vertical-align:middle"></span> Add
                </button>
                <span id="flip-city-status" style="margin-left:8px;font-size:12px;color:#666"></span>
            </div>
        </div>
    </div>

    <!-- City Breakdown Chart -->
    <div class="flip-card">
        <div class="flip-card-header">
            <h2>City Breakdown</h2>
        </div>
        <div class="flip-card-body">
            <div id="flip-chart-container">
                <canvas id="flip-city-chart"></canvas>
            </div>
            <div id="flip-chart-empty" class="flip-empty" style="display:none;">
                No analysis data available. Click "Run Analysis" to score properties.
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="flip-filters">
        <div class="flip-filter-group">
            <label for="filter-city">City</label>
            <select id="filter-city">
                <option value="">All Cities</option>
            </select>
        </div>
        <div class="flip-filter-group flip-filter-score">
            <label for="filter-score">Min Score: <strong id="score-display">0</strong></label>
            <input type="range" id="filter-score" min="0" max="100" value="0" step="5">
        </div>
        <div class="flip-filter-group">
            <label for="filter-sort">Sort By</label>
            <select id="filter-sort">
                <option value="total_score">Total Score</option>
                <option value="estimated_profit">Profit</option>
                <option value="annualized_roi">Annualized ROI</option>
                <option value="estimated_roi">Cash-on-Cash ROI</option>
                <option value="list_price">List Price</option>
                <option value="estimated_arv">ARV</option>
            </select>
        </div>
        <div class="flip-filter-group">
            <label for="filter-show">Show</label>
            <select id="filter-show">
                <option value="all">All Results</option>
                <option value="viable">Viable Only</option>
                <option value="near_viable">Near-Viable</option>
                <option value="disqualified">Disqualified Only</option>
            </select>
        </div>
    </div>

    <!-- Results Table -->
    <div class="flip-card">
        <div class="flip-card-header">
            <h2>Results <span id="result-count" class="flip-result-count"></span></h2>
        </div>
        <div class="flip-card-body flip-card-body-table">
            <table class="flip-table" id="flip-results-table">
                <thead>
                    <tr>
                        <th class="flip-col-toggle"></th>
                        <th>Property</th>
                        <th>City</th>
                        <th class="flip-col-num">Score</th>
                        <th>Risk</th>
                        <th class="flip-col-num">List Price</th>
                        <th class="flip-col-num">ARV</th>
                        <th class="flip-col-num">Profit</th>
                        <th class="flip-col-num">Ann. ROI</th>
                        <th>Road</th>
                        <th class="flip-col-num">DOM</th>
                        <th class="flip-col-num">Photo</th>
                    </tr>
                </thead>
                <tbody id="flip-results-body">
                </tbody>
            </table>
            <div id="flip-table-empty" class="flip-empty" style="display:none;">
                No results match the current filters.
            </div>
        </div>
    </div>

    <!-- Progress Modal -->
    <div id="flip-modal-overlay" class="flip-modal-overlay" style="display:none;">
        <div class="flip-modal">
            <div class="flip-modal-icon">
                <span class="dashicons dashicons-update flip-spin"></span>
            </div>
            <h3>Running Analysis...</h3>
            <p>Scoring properties across all target cities.<br>
               This may take 1-3 minutes depending on the number of properties.</p>
            <p class="flip-modal-note">Do not close this page.</p>
        </div>
    </div>
</div>
