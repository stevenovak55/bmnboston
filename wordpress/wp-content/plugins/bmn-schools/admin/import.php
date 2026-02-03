<?php
/**
 * Admin Import View
 *
 * @package BMN_Schools
 * @since 0.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap bmn-schools-admin">
    <h1><?php esc_html_e('Import School Data', 'bmn-schools'); ?></h1>

    <p><?php esc_html_e('Import school data from external sources. Imports may take several minutes depending on the data source.', 'bmn-schools'); ?></p>

    <div class="bmn-import-grid">
        <!-- MassGIS Import -->
        <div class="bmn-card bmn-import-card" data-provider="massgis">
            <div class="bmn-import-header">
                <h3>MassGIS Schools</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import school locations with coordinates from MassGIS. Includes public and private schools in Massachusetts.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('School names and addresses', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('GPS coordinates (latitude/longitude)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('School type (public, private, charter)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Grade levels', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-status">
                <span class="bmn-status-label"><?php esc_html_e('Status:', 'bmn-schools'); ?></span>
                <span class="bmn-status-value" data-source="massgis">
                    <?php echo isset($source_status['massgis']) ? esc_html($source_status['massgis']) : 'pending'; ?>
                </span>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="massgis">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import MassGIS Data', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- MA DESE Import -->
        <div class="bmn-card bmn-import-card" data-provider="ma_dese">
            <div class="bmn-import-header">
                <h3>MA DESE / MCAS</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import MCAS test scores from the Massachusetts Department of Elementary and Secondary Education.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('MCAS test scores (ELA, Math, Science)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Achievement levels and percentages', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Historical data (2017-present)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('School and district-level data', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="dese-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="dese-years" multiple style="width: 100%; height: 80px;">
                    <?php
                    $current_year = date('Y');
                    for ($y = $current_year; $y >= 2017; $y--) {
                        $selected = ($y >= $current_year - 2) ? 'selected' : '';
                        echo '<option value="' . esc_attr($y) . '" ' . $selected . '>' . esc_html($y) . '</option>';
                    }
                    ?>
                </select>
                <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple years.', 'bmn-schools'); ?></p>
            </div>
            <div class="bmn-import-status">
                <span class="bmn-status-label"><?php esc_html_e('Status:', 'bmn-schools'); ?></span>
                <span class="bmn-status-value" data-source="ma_dese">
                    <?php echo isset($source_status['ma_dese']) ? esc_html($source_status['ma_dese']) : 'pending'; ?>
                </span>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="ma_dese">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import MCAS Data', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- NCES EDGE Import -->
        <div class="bmn-card bmn-import-card" data-provider="nces_edge">
            <div class="bmn-import-header">
                <h3>NCES EDGE</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import school district boundaries from the National Center for Education Statistics.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('District boundary polygons (GeoJSON)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('District types (local, regional, charter)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Grade ranges served', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Point-in-polygon lookup support', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label>
                    <input type="checkbox" id="nces-include-geometry" checked>
                    <?php esc_html_e('Include boundary geometry (recommended)', 'bmn-schools'); ?>
                </label>
                <p class="description"><?php esc_html_e('Boundary data enables "find district for address" lookups.', 'bmn-schools'); ?></p>
            </div>
            <div class="bmn-import-status">
                <span class="bmn-status-label"><?php esc_html_e('Status:', 'bmn-schools'); ?></span>
                <span class="bmn-status-value" data-source="nces_edge">
                    <?php echo isset($source_status['nces_edge']) ? esc_html($source_status['nces_edge']) : 'pending'; ?>
                </span>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="nces_edge">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import District Boundaries', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Boston Open Data Import -->
        <div class="bmn-card bmn-import-card" data-provider="boston_open_data">
            <div class="bmn-import-header">
                <h3>Boston Open Data</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import Boston Public Schools data from the City of Boston Open Data portal.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('BPS school locations with coordinates', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('School addresses and contact info', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Grade levels (elementary, middle, high)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('School programs and features', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-status">
                <span class="bmn-status-label"><?php esc_html_e('Status:', 'bmn-schools'); ?></span>
                <span class="bmn-status-value" data-source="boston_open_data">
                    <?php echo isset($source_status['boston_open_data']) ? esc_html($source_status['boston_open_data']) : 'pending'; ?>
                </span>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="boston_open_data">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import BPS Schools', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Demographics Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_demographics">
            <div class="bmn-import-header">
                <h3>Demographics</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import enrollment demographics from MA DESE E2C Hub.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('Total enrollment by school', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Race/ethnicity breakdown', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Free/reduced lunch percentage', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('English learners & special education', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="demo-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="demo-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2025" selected>2025</option>
                    <option value="2024" selected>2024</option>
                    <option value="2023" selected>2023</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_demographics" data-years-select="demo-years">
                    <span class="dashicons dashicons-groups"></span>
                    <?php esc_html_e('Import Demographics', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- AP Data Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_ap">
            <div class="bmn-import-header">
                <h3>AP Courses</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import Advanced Placement course performance data.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('AP courses offered by school', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Tests taken per subject', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Pass rates (score 3+)', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="ap-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="ap-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2025" selected>2025</option>
                    <option value="2024" selected>2024</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_ap" data-years-select="ap-years">
                    <span class="dashicons dashicons-awards"></span>
                    <?php esc_html_e('Import AP Data', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Graduation Rates Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_graduation">
            <div class="bmn-import-header">
                <h3>Graduation Rates</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import 4-year graduation rates for high schools.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('4-year adjusted cohort graduation rate', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Dropout rates', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Cohort counts', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="grad-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="grad-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2025" selected>2025</option>
                    <option value="2024" selected>2024</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_graduation" data-years-select="grad-years">
                    <span class="dashicons dashicons-mortarboard"></span>
                    <?php esc_html_e('Import Graduation Rates', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Attendance Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_attendance">
            <div class="bmn-import-header">
                <h3>Attendance</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import student attendance and chronic absence data.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('Average attendance rate', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Chronic absence percentage', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Average absences per student', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="attend-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="attend-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2025" selected>2025</option>
                    <option value="2024" selected>2024</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_attendance" data-years-select="attend-years">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <?php esc_html_e('Import Attendance', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Staffing Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_staffing">
            <div class="bmn-import-header">
                <h3>Staffing</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import school staffing data (teacher FTE counts).', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('Teacher FTE count per school', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Admin staff FTE', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Support staff FTE', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="staff-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="staff-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2025" selected>2025</option>
                    <option value="2024" selected>2024</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_staffing" data-years-select="staff-years">
                    <span class="dashicons dashicons-businessman"></span>
                    <?php esc_html_e('Import Staffing', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- District Spending Import -->
        <div class="bmn-card bmn-import-card" data-provider="dese_spending">
            <div class="bmn-import-header">
                <h3>District Spending</h3>
                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Free', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Import district-level spending and teacher salary data.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('Average teacher salary', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Per-pupil expenditure', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Student-teacher ratio', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-import-options">
                <label for="spend-years"><?php esc_html_e('Years to import:', 'bmn-schools'); ?></label>
                <select id="spend-years" class="import-years" multiple style="width: 100%; height: 60px;">
                    <option value="2024" selected>2024</option>
                </select>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary bmn-run-import" data-provider="dese_spending" data-years-select="spend-years">
                    <span class="dashicons dashicons-money-alt"></span>
                    <?php esc_html_e('Import District Spending', 'bmn-schools'); ?>
                </button>
            </div>
        </div>

        <!-- Geocoding Card -->
        <div class="bmn-card bmn-import-card" data-provider="geocode">
            <div class="bmn-import-header">
                <h3>Geocode Schools</h3>
                <span class="bmn-badge bmn-badge-info"><?php esc_html_e('Utility', 'bmn-schools'); ?></span>
            </div>
            <p><?php esc_html_e('Add coordinates (latitude/longitude) to schools that are missing them. Uses OpenStreetMap Nominatim API.', 'bmn-schools'); ?></p>
            <ul class="bmn-import-features">
                <li><?php esc_html_e('Geocodes addresses to coordinates', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Rate limited (1 request/second)', 'bmn-schools'); ?></li>
                <li><?php esc_html_e('Required for map display', 'bmn-schools'); ?></li>
            </ul>
            <div class="bmn-geocode-status" id="geocode-status-box">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span><?php esc_html_e('Schools with coordinates:', 'bmn-schools'); ?></span>
                    <strong id="geocode-with-coords">Loading...</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span><?php esc_html_e('Schools pending:', 'bmn-schools'); ?></span>
                    <strong id="geocode-pending">Loading...</strong>
                </div>
                <div class="bmn-progress-bar" style="margin-bottom: 5px;">
                    <div class="bmn-progress-fill" id="geocode-progress" style="width: 0%"></div>
                </div>
                <div style="text-align: center; font-size: 12px; color: #646970;">
                    <span id="geocode-percent">0</span>% complete
                </div>
            </div>
            <div class="bmn-import-options">
                <label for="geocode-limit"><?php esc_html_e('Schools per batch:', 'bmn-schools'); ?></label>
                <select id="geocode-limit" style="width: 100%;">
                    <option value="25">25 schools (~25 seconds)</option>
                    <option value="50" selected>50 schools (~50 seconds)</option>
                    <option value="100">100 schools (~2 minutes)</option>
                    <option value="200">200 schools (~4 minutes)</option>
                </select>
                <p class="description" style="margin-top: 10px;">
                    <label for="geocode-city"><?php esc_html_e('Or geocode specific city:', 'bmn-schools'); ?></label>
                    <input type="text" id="geocode-city" placeholder="e.g., Reading" style="width: 100%; margin-top: 5px;">
                </p>
            </div>
            <div class="bmn-import-actions">
                <button type="button" class="button button-primary" id="bmn-run-geocode">
                    <span class="dashicons dashicons-location-alt"></span>
                    <?php esc_html_e('Run Geocoding', 'bmn-schools'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Import Progress Modal -->
    <div id="bmn-import-modal" class="bmn-modal" style="display: none;">
        <div class="bmn-modal-content">
            <div class="bmn-modal-header">
                <h3 id="bmn-import-modal-title"><?php esc_html_e('Importing Data...', 'bmn-schools'); ?></h3>
            </div>
            <div class="bmn-modal-body">
                <div class="bmn-progress-container">
                    <div class="bmn-progress-bar">
                        <div class="bmn-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="bmn-progress-status" id="bmn-import-status">
                        <?php esc_html_e('Starting import...', 'bmn-schools'); ?>
                    </div>
                </div>
                <div id="bmn-import-log" class="bmn-import-log"></div>
            </div>
            <div class="bmn-modal-footer" style="display: none;">
                <button type="button" class="button button-primary" id="bmn-import-close">
                    <?php esc_html_e('Close', 'bmn-schools'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.bmn-import-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.bmn-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.bmn-import-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.bmn-import-header h3 {
    margin: 0;
}

.bmn-import-card p {
    color: #646970;
    margin-bottom: 15px;
}

.bmn-import-features {
    margin: 0 0 15px 20px;
    color: #646970;
    font-size: 13px;
}

.bmn-import-features li {
    margin-bottom: 5px;
}

.bmn-import-options {
    margin-bottom: 15px;
    padding: 10px;
    background: #f6f7f7;
    border-radius: 4px;
}

.bmn-import-options label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.bmn-import-status {
    margin-bottom: 15px;
    padding: 10px;
    background: #f6f7f7;
    border-radius: 4px;
}

.bmn-import-disabled {
    opacity: 0.6;
}

.bmn-import-actions .button {
    width: 100%;
    justify-content: center;
}

.bmn-import-actions .dashicons {
    margin-right: 5px;
}

.bmn-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.bmn-badge-success {
    background: #d4edda;
    color: #155724;
}

.bmn-badge-info {
    background: #d1ecf1;
    color: #0c5460;
}

/* Modal styles */
.bmn-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bmn-modal-content {
    background: #fff;
    border-radius: 4px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.bmn-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
}

.bmn-modal-header h3 {
    margin: 0;
}

.bmn-modal-body {
    padding: 20px;
    overflow: auto;
    flex-grow: 1;
}

.bmn-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #eee;
    text-align: right;
}

.bmn-progress-container {
    margin-bottom: 20px;
}

.bmn-progress-bar {
    height: 20px;
    background: #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 10px;
}

.bmn-progress-fill {
    height: 100%;
    background: #007cba;
    transition: width 0.3s ease;
}

.bmn-progress-status {
    text-align: center;
    color: #646970;
}

.bmn-import-log {
    max-height: 200px;
    overflow-y: auto;
    background: #f6f7f7;
    padding: 10px;
    border-radius: 4px;
    font-size: 12px;
    font-family: monospace;
}

.bmn-import-log .log-entry {
    margin-bottom: 5px;
    padding: 3px 0;
    border-bottom: 1px solid #e0e0e0;
}

.bmn-import-log .log-entry:last-child {
    border-bottom: none;
}

.bmn-import-log .log-success {
    color: #155724;
}

.bmn-import-log .log-error {
    color: #721c24;
}

.bmn-geocode-status {
    margin-bottom: 15px;
    padding: 15px;
    background: #f6f7f7;
    border-radius: 4px;
}

.bmn-badge-info {
    background: #cce5ff;
    color: #004085;
}
</style>

<script>
jQuery(document).ready(function($) {
    var importInProgress = false;

    // Run import button
    $('.bmn-run-import').on('click', function() {
        if (importInProgress) {
            return;
        }

        var provider = $(this).data('provider');
        var options = {};

        // Get provider-specific options
        if (provider === 'ma_dese') {
            options.years = $('#dese-years').val();
        } else if (provider === 'nces_edge') {
            options.include_geometry = $('#nces-include-geometry').is(':checked');
        }

        // Handle years select for DESE sub-imports
        var yearsSelectId = $(this).data('years-select');
        if (yearsSelectId) {
            options.years = $('#' + yearsSelectId).val();
        }

        runImport(provider, options);
    });

    // Close modal
    $('#bmn-import-close').on('click', function() {
        $('#bmn-import-modal').hide();
        location.reload();
    });

    function runImport(provider, options) {
        importInProgress = true;

        // Show modal
        $('#bmn-import-modal').show();
        $('#bmn-import-modal-title').text('Importing ' + provider.replace('_', ' ').toUpperCase() + ' Data...');
        $('#bmn-import-status').text('Starting import...');
        $('#bmn-import-log').html('');
        $('.bmn-progress-fill').css('width', '10%');
        $('.bmn-modal-footer').hide();

        // Disable the button
        $('.bmn-run-import[data-provider="' + provider + '"]').prop('disabled', true);

        addLog('Starting import from ' + provider + '...');
        $('.bmn-progress-fill').css('width', '20%');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bmn_schools_run_import',
                nonce: '<?php echo wp_create_nonce('bmn_schools_admin'); ?>',
                provider: provider,
                options: JSON.stringify(options)
            },
            timeout: 600000, // 10 minutes
            success: function(response) {
                $('.bmn-progress-fill').css('width', '100%');

                if (response.success) {
                    $('#bmn-import-status').text('Import completed successfully!');
                    addLog('Import completed: ' + response.data.message, 'success');
                    addLog('Records imported: ' + response.data.count, 'success');
                    addLog('Duration: ' + (response.data.duration_ms / 1000).toFixed(2) + ' seconds', 'success');
                } else {
                    $('#bmn-import-status').text('Import failed');
                    addLog('Import failed: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                $('.bmn-progress-fill').css('width', '100%');
                $('#bmn-import-status').text('Import failed');
                addLog('Error: ' + error, 'error');
            },
            complete: function() {
                importInProgress = false;
                $('.bmn-run-import[data-provider="' + provider + '"]').prop('disabled', false);
                $('.bmn-modal-footer').show();
            }
        });
    }

    function addLog(message, type) {
        type = type || 'info';
        var $log = $('#bmn-import-log');
        var timestamp = new Date().toLocaleTimeString();
        $log.append('<div class="log-entry log-' + type + '">[' + timestamp + '] ' + message + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    // Geocoding functionality
    function loadGeocodeStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bmn_schools_geocode_status',
                nonce: '<?php echo wp_create_nonce('bmn_schools_admin'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var d = response.data;
                    $('#geocode-with-coords').text(d.with_coords + ' / ' + d.total);
                    $('#geocode-pending').text(d.pending);
                    $('#geocode-progress').css('width', d.percent + '%');
                    $('#geocode-percent').text(d.percent);
                }
            }
        });
    }

    // Load status on page load
    loadGeocodeStatus();

    // Geocode button
    $('#bmn-run-geocode').on('click', function() {
        if (importInProgress) {
            return;
        }

        var limit = $('#geocode-limit').val();
        var city = $('#geocode-city').val().trim();

        importInProgress = true;
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Geocoding...');

        // Show modal
        $('#bmn-import-modal').show();
        $('#bmn-import-modal-title').text('Geocoding Schools...');
        $('#bmn-import-status').text(city ? 'Geocoding schools in ' + city + '...' : 'Geocoding ' + limit + ' schools...');
        $('#bmn-import-log').html('');
        $('.bmn-progress-fill').css('width', '20%');
        $('.bmn-modal-footer').hide();

        addLog('Starting geocoding...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bmn_schools_run_geocode',
                nonce: '<?php echo wp_create_nonce('bmn_schools_admin'); ?>',
                limit: limit,
                city: city
            },
            timeout: 600000,
            success: function(response) {
                $('.bmn-progress-fill').css('width', '100%');
                if (response.success) {
                    $('#bmn-import-status').text('Geocoding complete!');
                    addLog(response.data.message, 'success');
                    addLog('Success: ' + response.data.stats.success + ', Failed: ' + response.data.stats.failed, 'success');
                    if (response.data.remaining) {
                        addLog('Remaining: ' + response.data.remaining + ' schools', 'info');
                    }
                    loadGeocodeStatus();
                } else {
                    $('#bmn-import-status').text('Geocoding failed');
                    addLog(response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                $('.bmn-progress-fill').css('width', '100%');
                $('#bmn-import-status').text('Geocoding failed');
                addLog('Error: ' + error, 'error');
            },
            complete: function() {
                importInProgress = false;
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-location-alt"></span> Run Geocoding');
                $('.bmn-modal-footer').show();
            }
        });
    });
});
</script>

<style>
.dashicons.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    100% { transform: rotate(360deg); }
}
</style>
