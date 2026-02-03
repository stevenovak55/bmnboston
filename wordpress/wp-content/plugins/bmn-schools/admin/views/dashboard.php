<?php
/**
 * Admin Dashboard View
 *
 * Comprehensive dashboard showing all school data statistics.
 *
 * @package BMN_Schools
 * @since 0.1.0
 * @updated 0.5.3 - Comprehensive dashboard with all data types
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get version info
$version_file = BMN_SCHOOLS_PLUGIN_DIR . 'version.json';
$version_data = file_exists($version_file) ? json_decode(file_get_contents($version_file), true) : [];
$phase_name = $version_data['phase_name'] ?? 'Platform Integration';
$phase_number = $version_data['phase'] ?? '5';

// Get geocoding stats
global $wpdb;
$schools_table = $wpdb->prefix . 'bmn_schools';
$total_schools = isset($data_stats['schools']) ? $data_stats['schools'] : 0;
$geocoded_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$schools_table} WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$geocoding_pct = $total_schools > 0 ? round(($geocoded_count / $total_schools) * 100, 1) : 0;
$pending_geocode = $total_schools - $geocoded_count;

// Get feature type breakdown
$features_table = $wpdb->prefix . 'bmn_school_features';
$feature_breakdown = $wpdb->get_results("
    SELECT feature_type, COUNT(*) as count
    FROM {$features_table}
    GROUP BY feature_type
    ORDER BY count DESC
");

// Get districts with spending data
$districts_table = $wpdb->prefix . 'bmn_school_districts';
$districts_with_spending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$districts_table} WHERE extra_data IS NOT NULL AND extra_data != ''");
$districts_with_boundaries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$districts_table} WHERE boundary_geojson IS NOT NULL AND boundary_geojson != ''");

// Get test score years
$test_scores_table = $wpdb->prefix . 'bmn_school_test_scores';
$test_score_years = $wpdb->get_results("SELECT year, COUNT(*) as count FROM {$test_scores_table} GROUP BY year ORDER BY year DESC LIMIT 5");

// Get demographics years
$demographics_table = $wpdb->prefix . 'bmn_school_demographics';
$demo_years = $wpdb->get_results("SELECT year, COUNT(DISTINCT school_id) as schools FROM {$demographics_table} GROUP BY year ORDER BY year DESC LIMIT 3");
?>
<div class="wrap bmn-schools-admin">
    <h1><?php esc_html_e('BMN Schools Dashboard', 'bmn-schools'); ?></h1>

    <div class="bmn-dashboard-header">
        <div class="bmn-version-info">
            <strong><?php esc_html_e('Version:', 'bmn-schools'); ?></strong>
            <?php echo esc_html(BMN_SCHOOLS_VERSION); ?>
            |
            <strong><?php esc_html_e('Phase:', 'bmn-schools'); ?></strong>
            <?php echo esc_html($phase_number); ?> - <?php echo esc_html($phase_name); ?>
            |
            <strong><?php esc_html_e('Status:', 'bmn-schools'); ?></strong>
            <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Complete', 'bmn-schools'); ?></span>
        </div>
    </div>

    <!-- Primary Stats Row -->
    <div class="bmn-stats-row">
        <div class="bmn-stat-card bmn-stat-primary">
            <span class="dashicons dashicons-building"></span>
            <div class="bmn-stat-content">
                <span class="bmn-stat-value"><?php echo esc_html(number_format($data_stats['schools'] ?? 0)); ?></span>
                <span class="bmn-stat-label"><?php esc_html_e('Schools', 'bmn-schools'); ?></span>
            </div>
        </div>
        <div class="bmn-stat-card bmn-stat-primary">
            <span class="dashicons dashicons-groups"></span>
            <div class="bmn-stat-content">
                <span class="bmn-stat-value"><?php echo esc_html(number_format($data_stats['districts'] ?? 0)); ?></span>
                <span class="bmn-stat-label"><?php esc_html_e('Districts', 'bmn-schools'); ?></span>
            </div>
        </div>
        <div class="bmn-stat-card bmn-stat-primary">
            <span class="dashicons dashicons-chart-bar"></span>
            <div class="bmn-stat-content">
                <span class="bmn-stat-value"><?php echo esc_html(number_format($data_stats['test_scores'] ?? 0)); ?></span>
                <span class="bmn-stat-label"><?php esc_html_e('MCAS Scores', 'bmn-schools'); ?></span>
            </div>
        </div>
        <div class="bmn-stat-card bmn-stat-primary">
            <span class="dashicons dashicons-location"></span>
            <div class="bmn-stat-content">
                <span class="bmn-stat-value"><?php echo esc_html($geocoding_pct); ?>%</span>
                <span class="bmn-stat-label"><?php esc_html_e('Geocoded', 'bmn-schools'); ?></span>
            </div>
        </div>
    </div>

    <div class="bmn-dashboard-grid">
        <!-- Data Overview Card -->
        <div class="bmn-card bmn-card-data">
            <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Data Overview', 'bmn-schools'); ?></h2>
            <table class="bmn-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Data Type', 'bmn-schools'); ?></th>
                        <th><?php esc_html_e('Records', 'bmn-schools'); ?></th>
                        <th><?php esc_html_e('Status', 'bmn-schools'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Schools', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['schools'] ?? 0)); ?></td>
                        <td><span class="bmn-badge bmn-badge-success"><?php esc_html_e('MassGIS', 'bmn-schools'); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Districts', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['districts'] ?? 0)); ?></td>
                        <td>
                            <span class="bmn-badge bmn-badge-success"><?php echo esc_html($districts_with_boundaries); ?> <?php esc_html_e('w/boundaries', 'bmn-schools'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('MCAS Test Scores', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['test_scores'] ?? 0)); ?></td>
                        <td><span class="bmn-badge bmn-badge-success"><?php esc_html_e('2017-2025', 'bmn-schools'); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Demographics', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['demographics'] ?? 0)); ?></td>
                        <td>
                            <?php if (($data_stats['demographics'] ?? 0) > 0) : ?>
                                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Imported', 'bmn-schools'); ?></span>
                            <?php else : ?>
                                <span class="bmn-badge bmn-badge-warning"><?php esc_html_e('Pending', 'bmn-schools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Features/Programs', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['features'] ?? 0)); ?></td>
                        <td>
                            <?php if (($data_stats['features'] ?? 0) > 0) : ?>
                                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('Multi-type', 'bmn-schools'); ?></span>
                            <?php else : ?>
                                <span class="bmn-badge bmn-badge-warning"><?php esc_html_e('Pending', 'bmn-schools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('District Spending', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html($districts_with_spending); ?> <?php esc_html_e('districts', 'bmn-schools'); ?></td>
                        <td>
                            <?php if ($districts_with_spending > 0) : ?>
                                <span class="bmn-badge bmn-badge-success"><?php esc_html_e('E2C Hub', 'bmn-schools'); ?></span>
                            <?php else : ?>
                                <span class="bmn-badge bmn-badge-warning"><?php esc_html_e('Pending', 'bmn-schools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Rankings', 'bmn-schools'); ?></strong></td>
                        <td><?php echo esc_html(number_format($data_stats['rankings'] ?? 0)); ?></td>
                        <td><span class="bmn-badge bmn-badge-secondary"><?php esc_html_e('Paid API', 'bmn-schools'); ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Feature Types Breakdown -->
        <div class="bmn-card bmn-card-features">
            <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Features Breakdown', 'bmn-schools'); ?></h2>
            <?php if (!empty($feature_breakdown)) : ?>
                <table class="bmn-table bmn-table-compact">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Type', 'bmn-schools'); ?></th>
                            <th><?php esc_html_e('Records', 'bmn-schools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feature_breakdown as $feature) : ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html($feature->feature_type); ?></code>
                                </td>
                                <td><?php echo esc_html(number_format($feature->count)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="bmn-notice bmn-notice-info"><?php esc_html_e('No features imported yet. Use Import Data to add AP courses, graduation rates, attendance, and staffing data.', 'bmn-schools'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Geocoding Status -->
        <div class="bmn-card bmn-card-geocode">
            <h2><span class="dashicons dashicons-location-alt"></span> <?php esc_html_e('Geocoding Status', 'bmn-schools'); ?></h2>
            <div class="bmn-geocode-progress">
                <div class="bmn-progress-bar">
                    <div class="bmn-progress-fill" style="width: <?php echo esc_attr($geocoding_pct); ?>%;"></div>
                </div>
                <div class="bmn-progress-label">
                    <strong><?php echo esc_html($geocoded_count); ?></strong> / <?php echo esc_html($total_schools); ?>
                    <?php esc_html_e('schools geocoded', 'bmn-schools'); ?>
                    (<?php echo esc_html($geocoding_pct); ?>%)
                </div>
            </div>
            <?php if ($pending_geocode > 0) : ?>
                <p class="bmn-geocode-pending">
                    <span class="dashicons dashicons-warning"></span>
                    <strong><?php echo esc_html($pending_geocode); ?></strong>
                    <?php esc_html_e('schools still need geocoding for map display.', 'bmn-schools'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-import')); ?>" class="button button-primary">
                        <?php esc_html_e('Run Geocoder', 'bmn-schools'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p class="bmn-geocode-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('All schools have coordinates!', 'bmn-schools'); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- MCAS Data by Year -->
        <div class="bmn-card bmn-card-years">
            <h2><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('MCAS Data by Year', 'bmn-schools'); ?></h2>
            <?php if (!empty($test_score_years)) : ?>
                <table class="bmn-table bmn-table-compact">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Year', 'bmn-schools'); ?></th>
                            <th><?php esc_html_e('Score Records', 'bmn-schools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($test_score_years as $year) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($year->year); ?></strong></td>
                                <td><?php echo esc_html(number_format($year->count)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="bmn-notice bmn-notice-info"><?php esc_html_e('No MCAS data imported yet.', 'bmn-schools'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Status -->
        <div class="bmn-card bmn-card-tables">
            <h2><span class="dashicons dashicons-editor-table"></span> <?php esc_html_e('Database Tables', 'bmn-schools'); ?></h2>
            <table class="bmn-table bmn-table-compact">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Table', 'bmn-schools'); ?></th>
                        <th><?php esc_html_e('Status', 'bmn-schools'); ?></th>
                        <th><?php esc_html_e('Records', 'bmn-schools'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_stats as $key => $status) : ?>
                        <tr>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td>
                                <?php if ($status['exists']) : ?>
                                    <span class="bmn-badge bmn-badge-success"><?php esc_html_e('OK', 'bmn-schools'); ?></span>
                                <?php else : ?>
                                    <span class="bmn-badge bmn-badge-danger"><?php esc_html_e('Missing', 'bmn-schools'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(isset($status['count']) ? number_format($status['count']) : '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Data Sources -->
        <div class="bmn-card bmn-card-sources">
            <h2><span class="dashicons dashicons-admin-site"></span> <?php esc_html_e('Data Sources', 'bmn-schools'); ?></h2>
            <?php if (empty($data_sources)) : ?>
                <p class="bmn-notice bmn-notice-warning">
                    <?php esc_html_e('No data sources configured.', 'bmn-schools'); ?>
                </p>
            <?php else : ?>
                <table class="bmn-table bmn-table-compact">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'bmn-schools'); ?></th>
                            <th><?php esc_html_e('Status', 'bmn-schools'); ?></th>
                            <th><?php esc_html_e('Last Sync', 'bmn-schools'); ?></th>
                            <th><?php esc_html_e('Records', 'bmn-schools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data_sources as $source) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($source->source_name); ?></strong></td>
                                <td><?php echo BMN_Schools_Admin::get_status_badge($source->status); ?></td>
                                <td>
                                    <?php
                                    if ($source->last_sync) {
                                        echo esc_html(human_time_diff(strtotime($source->last_sync), current_time('timestamp')) . ' ago');
                                    } else {
                                        esc_html_e('Never', 'bmn-schools');
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(number_format($source->records_synced)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-import')); ?>" class="button button-primary">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import Data', 'bmn-schools'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-sources')); ?>" class="button">
                    <?php esc_html_e('Manage Sources', 'bmn-schools'); ?>
                </a>
            </p>
        </div>

        <!-- Quick Actions -->
        <div class="bmn-card bmn-card-actions">
            <h2><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Quick Actions', 'bmn-schools'); ?></h2>
            <div class="bmn-actions-grid">
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-import')); ?>" class="bmn-action-btn">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Import Data', 'bmn-schools'); ?>
                </a>
                <a href="<?php echo esc_url(rest_url('bmn-schools/v1/health')); ?>" class="bmn-action-btn" target="_blank">
                    <span class="dashicons dashicons-heart"></span>
                    <?php esc_html_e('API Health', 'bmn-schools'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-logs')); ?>" class="bmn-action-btn">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e('Activity Log', 'bmn-schools'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-settings')); ?>" class="bmn-action-btn">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php esc_html_e('Settings', 'bmn-schools'); ?>
                </a>
            </div>
        </div>

        <!-- REST API Status -->
        <div class="bmn-card bmn-card-api">
            <h2><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e('REST API', 'bmn-schools'); ?></h2>
            <table class="bmn-table bmn-table-compact">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Namespace', 'bmn-schools'); ?></strong></td>
                        <td><code>bmn-schools/v1</code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Base URL', 'bmn-schools'); ?></strong></td>
                        <td><code><?php echo esc_url(rest_url('bmn-schools/v1/')); ?></code></td>
                    </tr>
                </tbody>
            </table>
            <h4><?php esc_html_e('Available Endpoints', 'bmn-schools'); ?></h4>
            <div class="bmn-endpoints">
                <code>/schools</code>
                <code>/schools/{id}</code>
                <code>/schools/nearby</code>
                <code>/schools/map</code>
                <code>/schools/compare</code>
                <code>/schools/top</code>
                <code>/property/schools</code>
                <code>/districts</code>
                <code>/districts/{id}</code>
                <code>/districts/for-point</code>
                <code>/search/autocomplete</code>
                <code>/health</code>
            </div>
        </div>

        <!-- Development Status -->
        <div class="bmn-card bmn-card-phase">
            <h2><span class="dashicons dashicons-flag"></span> <?php esc_html_e('Development Status', 'bmn-schools'); ?></h2>
            <div class="bmn-phase-list">
                <div class="bmn-phase bmn-phase-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('Phase 1: Foundation', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('Core plugin, database tables, REST API endpoints.', 'bmn-schools'); ?></p>
                </div>
                <div class="bmn-phase bmn-phase-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('Phase 2: Massachusetts Data', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('MassGIS schools, DESE MCAS scores (multi-year).', 'bmn-schools'); ?></p>
                </div>
                <div class="bmn-phase bmn-phase-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('Phase 3: District Boundaries', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('NCES EDGE GeoJSON district boundaries.', 'bmn-schools'); ?></p>
                </div>
                <div class="bmn-phase bmn-phase-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('Phase 4: Enhanced Features', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('Compare, trends, top schools, caching.', 'bmn-schools'); ?></p>
                </div>
                <div class="bmn-phase bmn-phase-complete">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('Phase 5: Platform Integration', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('iOS app integration, demographics, staffing, spending.', 'bmn-schools'); ?></p>
                </div>
                <div class="bmn-phase bmn-phase-pending">
                    <span class="dashicons dashicons-marker"></span>
                    <strong><?php esc_html_e('Phase 6: Optional Enhancements', 'bmn-schools'); ?></strong>
                    <p><?php esc_html_e('GreatSchools/SchoolDigger ratings (paid API), attendance zones.', 'bmn-schools'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.bmn-schools-admin {
    max-width: 1600px;
}

.bmn-dashboard-header {
    background: #fff;
    padding: 15px 20px;
    margin: 20px 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Stats Row */
.bmn-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.bmn-stat-card {
    background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.bmn-stat-card .dashicons {
    font-size: 40px;
    width: 40px;
    height: 40px;
    opacity: 0.8;
}

.bmn-stat-content {
    display: flex;
    flex-direction: column;
}

.bmn-stat-card .bmn-stat-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.2;
}

.bmn-stat-card .bmn-stat-label {
    font-size: 14px;
    opacity: 0.9;
}

/* Dashboard Grid */
.bmn-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
    gap: 20px;
}

.bmn-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.bmn-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.bmn-card h2 .dashicons {
    color: #007cba;
}

/* Tables */
.bmn-table {
    width: 100%;
    border-collapse: collapse;
}

.bmn-table th,
.bmn-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.bmn-table th {
    background: #f6f7f7;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    color: #666;
}

.bmn-table-compact td {
    padding: 6px 10px;
    font-size: 13px;
}

/* Badges */
.bmn-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}

.bmn-badge-success {
    background: #d4edda;
    color: #155724;
}

.bmn-badge-warning {
    background: #fff3cd;
    color: #856404;
}

.bmn-badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.bmn-badge-info {
    background: #d1ecf1;
    color: #0c5460;
}

.bmn-badge-secondary {
    background: #e2e3e5;
    color: #383d41;
}

/* Geocoding Progress */
.bmn-geocode-progress {
    margin: 15px 0;
}

.bmn-progress-bar {
    background: #e0e0e0;
    height: 20px;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 8px;
}

.bmn-progress-fill {
    background: linear-gradient(90deg, #28a745 0%, #34ce57 100%);
    height: 100%;
    transition: width 0.3s ease;
}

.bmn-progress-label {
    font-size: 13px;
    color: #666;
}

.bmn-geocode-pending {
    color: #856404;
    background: #fff3cd;
    padding: 10px 15px;
    border-radius: 4px;
    margin: 15px 0;
}

.bmn-geocode-pending .dashicons {
    color: #856404;
    margin-right: 5px;
}

.bmn-geocode-complete {
    color: #155724;
    background: #d4edda;
    padding: 10px 15px;
    border-radius: 4px;
}

.bmn-geocode-complete .dashicons {
    color: #155724;
    margin-right: 5px;
}

/* Actions Grid */
.bmn-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.bmn-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 15px;
    background: #f6f7f7;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    color: #1e1e1e;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}

.bmn-action-btn:hover {
    background: #007cba;
    color: white;
    border-color: #007cba;
}

.bmn-action-btn .dashicons {
    color: #007cba;
}

.bmn-action-btn:hover .dashicons {
    color: white;
}

/* API Endpoints */
.bmn-endpoints {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.bmn-endpoints code {
    padding: 4px 8px;
    background: #f0f0f0;
    border-radius: 3px;
    font-size: 11px;
}

/* Development Phases */
.bmn-phase-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.bmn-phase {
    padding: 12px 15px;
    border-left: 4px solid #ccc;
    background: #f9f9f9;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 10px;
}

.bmn-phase .dashicons {
    margin-top: 2px;
}

.bmn-phase strong {
    flex: 1;
    min-width: 200px;
}

.bmn-phase p {
    width: 100%;
    margin: 5px 0 0 30px;
    color: #666;
    font-size: 12px;
}

.bmn-phase-complete {
    border-left-color: #28a745;
    background: #f0fff4;
}

.bmn-phase-complete .dashicons {
    color: #28a745;
}

.bmn-phase-pending {
    border-left-color: #6c757d;
}

.bmn-phase-pending .dashicons {
    color: #6c757d;
}

/* Notices */
.bmn-notice {
    padding: 10px 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.bmn-notice-info {
    background: #d1ecf1;
    color: #0c5460;
}

.bmn-notice-warning {
    background: #fff3cd;
    color: #856404;
}

/* Responsive */
@media (max-width: 1200px) {
    .bmn-stats-row {
        grid-template-columns: repeat(2, 1fr);
    }
    .bmn-dashboard-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .bmn-stats-row {
        grid-template-columns: 1fr;
    }
}
</style>
