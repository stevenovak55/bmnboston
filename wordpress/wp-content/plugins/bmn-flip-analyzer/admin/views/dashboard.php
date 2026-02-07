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

    <!-- Report Context Bar (shown when viewing a saved report) -->
    <div id="flip-report-context" class="flip-report-context" style="display:none;"></div>

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

    <!-- Report Name Prompt (shown before running analysis) -->
    <div id="flip-report-name-prompt" class="flip-report-name-prompt" style="display:none;">
        <label for="flip-report-name">Report Name:</label>
        <input type="text" id="flip-report-name" placeholder="Report name..." class="regular-text">
        <button id="flip-report-run-confirm" class="button button-primary">Run & Save</button>
        <button id="flip-report-run-cancel" class="button">Cancel</button>
    </div>

    <!-- Saved Reports Panel -->
    <div class="flip-card flip-reports-card">
        <div class="flip-card-header flip-reports-header" id="flip-reports-toggle">
            <h2>
                <span class="dashicons dashicons-portfolio"></span> Saved Reports
                <span id="flip-reports-count" class="flip-badge">0</span>
            </h2>
            <span class="flip-reports-arrow dashicons dashicons-arrow-down-alt2"></span>
        </div>
        <div class="flip-card-body" id="flip-reports-body" style="display:none;">
            <div id="flip-reports-list" class="flip-reports-list"></div>
            <div class="flip-reports-actions">
                <button id="flip-create-monitor-btn" class="button button-small">
                    <span class="dashicons dashicons-visibility"></span> Create Monitor
                </button>
            </div>
        </div>
    </div>

    <!-- Monitor Creation Dialog -->
    <div id="flip-monitor-dialog" class="flip-monitor-dialog" style="display:none;">
        <h3>Create Monitor</h3>
        <p class="description">Uses current cities and filters. Only NEW listings will be analyzed.
           Viable properties trigger photo analysis + PDF + email automatically.</p>
        <div class="flip-monitor-fields">
            <div>
                <label for="flip-monitor-name">Monitor Name</label>
                <input type="text" id="flip-monitor-name" placeholder="Monitor name..." class="regular-text">
            </div>
            <div>
                <label for="flip-monitor-frequency">Check Frequency</label>
                <select id="flip-monitor-frequency">
                    <option value="daily">Daily</option>
                    <option value="twice_daily">Twice Daily</option>
                    <option value="weekly">Weekly</option>
                </select>
            </div>
            <div>
                <label for="flip-monitor-email">Notification Email</label>
                <input type="email" id="flip-monitor-email" placeholder="email@example.com" class="regular-text">
            </div>
        </div>
        <div class="flip-monitor-actions">
            <button id="flip-monitor-confirm" class="button button-primary">Create Monitor</button>
            <button id="flip-monitor-cancel" class="button">Cancel</button>
        </div>
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

    <!-- Analysis Filters -->
    <div class="flip-card flip-af-card">
        <div class="flip-card-header flip-af-header" id="flip-af-toggle">
            <h2><span class="dashicons dashicons-filter"></span> Analysis Filters</h2>
            <span class="flip-af-arrow dashicons dashicons-arrow-down-alt2"></span>
        </div>
        <div class="flip-card-body flip-af-body" id="flip-af-body" style="display:none;">
            <div class="flip-af-grid">

                <!-- Row 1: Property Type + Status -->
                <div class="flip-af-field">
                    <label>Property Sub Type</label>
                    <div id="flip-af-subtypes" class="flip-af-checks"></div>
                </div>
                <div class="flip-af-field">
                    <label>Status</label>
                    <div class="flip-af-checks">
                        <label><input type="checkbox" name="af-status" value="Active"> Active</label>
                        <label><input type="checkbox" name="af-status" value="Active Under Contract"> Under Contract</label>
                        <label><input type="checkbox" name="af-status" value="Pending"> Pending</label>
                        <label><input type="checkbox" name="af-status" value="Closed"> Closed</label>
                    </div>
                </div>

                <!-- Row 2: Price + Sqft + Year Built -->
                <div class="flip-af-field">
                    <label>Price Range</label>
                    <div class="flip-af-range">
                        <input type="number" id="af-min-price" placeholder="Min" step="10000">
                        <span>to</span>
                        <input type="number" id="af-max-price" placeholder="Max" step="10000">
                    </div>
                </div>
                <div class="flip-af-field">
                    <label>Sqft Range</label>
                    <div class="flip-af-range">
                        <input type="number" id="af-min-sqft" placeholder="Min">
                        <span>to</span>
                        <input type="number" id="af-max-sqft" placeholder="Max">
                    </div>
                </div>
                <div class="flip-af-field">
                    <label>Year Built</label>
                    <div class="flip-af-range">
                        <input type="number" id="af-year-min" placeholder="Min" min="1800" max="2030">
                        <span>to</span>
                        <input type="number" id="af-year-max" placeholder="Max" min="1800" max="2030">
                    </div>
                </div>

                <!-- Row 3: DOM + List Date + Beds/Baths -->
                <div class="flip-af-field">
                    <label>Days on Market</label>
                    <div class="flip-af-range">
                        <input type="number" id="af-min-dom" placeholder="Min" min="0">
                        <span>to</span>
                        <input type="number" id="af-max-dom" placeholder="Max">
                    </div>
                </div>
                <div class="flip-af-field">
                    <label>List Date Range</label>
                    <div class="flip-af-range">
                        <input type="date" id="af-list-from">
                        <span>to</span>
                        <input type="date" id="af-list-to">
                    </div>
                </div>
                <div class="flip-af-field">
                    <label>Min Beds / Baths</label>
                    <div class="flip-af-range">
                        <input type="number" id="af-min-beds" placeholder="Beds" min="0" max="10">
                        <input type="number" id="af-min-baths" placeholder="Baths" min="0" max="10" step="0.5">
                    </div>
                </div>

                <!-- Row 4: Checkboxes + Lot -->
                <div class="flip-af-field">
                    <label>Additional</label>
                    <div class="flip-af-checks">
                        <label><input type="checkbox" id="af-sewer-public"> Public Sewer Only</label>
                        <label><input type="checkbox" id="af-has-garage"> Has Garage</label>
                    </div>
                </div>
                <div class="flip-af-field">
                    <label>Min Lot Size (acres)</label>
                    <input type="number" id="af-min-lot" placeholder="Min acres" step="0.01" min="0">
                </div>
            </div>

            <div class="flip-af-actions">
                <button id="flip-save-filters" class="button button-primary">
                    <span class="dashicons dashicons-saved"></span> Save Filters
                </button>
                <button id="flip-reset-filters" class="button">Reset to Defaults</button>
                <span id="flip-af-status" class="flip-af-status"></span>
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
