<?php
/**
 * Admin Data Sources View
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap bmn-schools-admin">
    <h1><?php esc_html_e('Data Sources', 'bmn-schools'); ?></h1>

    <p><?php esc_html_e('Manage external data sources for school information. Data providers will be enabled in Phase 2.', 'bmn-schools'); ?></p>

    <?php if (empty($data_sources)) : ?>
        <div class="bmn-notice bmn-notice-warning">
            <p><?php esc_html_e('No data sources found. Deactivate and reactivate the plugin to initialize data sources.', 'bmn-schools'); ?></p>
        </div>
    <?php else : ?>
        <div class="bmn-sources-grid">
            <?php foreach ($data_sources as $source) : ?>
                <div class="bmn-card bmn-source-card" data-status="<?php echo esc_attr($source->status); ?>">
                    <div class="bmn-source-header">
                        <h3><?php echo esc_html(ucwords(str_replace('_', ' ', $source->source_name))); ?></h3>
                        <?php echo BMN_Schools_Admin::get_status_badge($source->status); ?>
                    </div>

                    <div class="bmn-source-details">
                        <table>
                            <tr>
                                <td><strong><?php esc_html_e('Type:', 'bmn-schools'); ?></strong></td>
                                <td><?php echo esc_html($source->source_type); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('URL:', 'bmn-schools'); ?></strong></td>
                                <td>
                                    <?php if ($source->source_url) : ?>
                                        <a href="<?php echo esc_url($source->source_url); ?>" target="_blank">
                                            <?php echo esc_html(wp_trim_words($source->source_url, 5)); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Last Sync:', 'bmn-schools'); ?></strong></td>
                                <td>
                                    <?php
                                    if ($source->last_sync) {
                                        echo esc_html(date('M j, Y g:i A', strtotime($source->last_sync)));
                                    } else {
                                        esc_html_e('Never', 'bmn-schools');
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Records:', 'bmn-schools'); ?></strong></td>
                                <td><?php echo esc_html(number_format($source->records_synced)); ?></td>
                            </tr>
                            <?php if ($source->api_key_option) : ?>
                                <tr>
                                    <td><strong><?php esc_html_e('API Key:', 'bmn-schools'); ?></strong></td>
                                    <td>
                                        <?php
                                        $credentials = get_option('bmn_schools_api_credentials', []);
                                        $key_name = str_replace(['bmn_schools_api_credentials[', ']'], '', $source->api_key_option);
                                        if (!empty($credentials[$key_name])) {
                                            echo '<span class="bmn-badge bmn-badge-success">' . esc_html__('Configured', 'bmn-schools') . '</span>';
                                        } else {
                                            echo '<span class="bmn-badge bmn-badge-warning">' . esc_html__('Not Set', 'bmn-schools') . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>

                        <?php if ($source->error_message) : ?>
                            <div class="bmn-source-error">
                                <strong><?php esc_html_e('Error:', 'bmn-schools'); ?></strong>
                                <?php echo esc_html($source->error_message); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bmn-source-actions">
                        <button type="button" class="button" disabled title="<?php esc_attr_e('Available in Phase 2', 'bmn-schools'); ?>">
                            <?php esc_html_e('Sync Now', 'bmn-schools'); ?>
                        </button>
                        <button type="button" class="button" disabled title="<?php esc_attr_e('Available in Phase 2', 'bmn-schools'); ?>">
                            <?php esc_html_e('Configure', 'bmn-schools'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bmn-card bmn-info-card">
        <h2><?php esc_html_e('Data Sources Overview', 'bmn-schools'); ?></h2>

        <h3><?php esc_html_e('Free Data Sources (Phase 2)', 'bmn-schools'); ?></h3>
        <table class="bmn-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Source', 'bmn-schools'); ?></th>
                    <th><?php esc_html_e('Data Provided', 'bmn-schools'); ?></th>
                    <th><?php esc_html_e('Format', 'bmn-schools'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>NCES CCD</strong></td>
                    <td><?php esc_html_e('School directory, districts, enrollment, demographics', 'bmn-schools'); ?></td>
                    <td>CSV/API</td>
                </tr>
                <tr>
                    <td><strong>NCES EDGE</strong></td>
                    <td><?php esc_html_e('School district boundaries', 'bmn-schools'); ?></td>
                    <td>GeoJSON REST API</td>
                </tr>
                <tr>
                    <td><strong>MA DESE</strong></td>
                    <td><?php esc_html_e('MCAS scores, accountability, demographics (all years)', 'bmn-schools'); ?></td>
                    <td>CSV Download</td>
                </tr>
                <tr>
                    <td><strong>MassGIS</strong></td>
                    <td><?php esc_html_e('School locations, district boundaries', 'bmn-schools'); ?></td>
                    <td>GeoJSON/Shapefile</td>
                </tr>
                <tr>
                    <td><strong>Boston Open Data</strong></td>
                    <td><?php esc_html_e('BPS schools, locations', 'bmn-schools'); ?></td>
                    <td>REST API</td>
                </tr>
            </tbody>
        </table>

        <h3><?php esc_html_e('Premium Data Sources (Optional)', 'bmn-schools'); ?></h3>
        <table class="bmn-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Source', 'bmn-schools'); ?></th>
                    <th><?php esc_html_e('Data Provided', 'bmn-schools'); ?></th>
                    <th><?php esc_html_e('Starting Price', 'bmn-schools'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>SchoolDigger</strong></td>
                    <td><?php esc_html_e('Rankings, test scores, autocomplete', 'bmn-schools'); ?></td>
                    <td>$19.90/month</td>
                </tr>
                <tr>
                    <td><strong>GreatSchools</strong></td>
                    <td><?php esc_html_e('1-10 ratings, rating bands', 'bmn-schools'); ?></td>
                    <td>$52.50/month</td>
                </tr>
                <tr>
                    <td><strong>ATTOM Data</strong></td>
                    <td><?php esc_html_e('Individual school attendance zone boundaries', 'bmn-schools'); ?></td>
                    <td>Enterprise pricing</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.bmn-sources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.bmn-source-card {
    border-left: 4px solid #ccc;
}

.bmn-source-card[data-status="active"] {
    border-left-color: #28a745;
}

.bmn-source-card[data-status="pending"] {
    border-left-color: #ffc107;
}

.bmn-source-card[data-status="disabled"] {
    border-left-color: #6c757d;
}

.bmn-source-card[data-status="error"] {
    border-left-color: #dc3545;
}

.bmn-source-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.bmn-source-header h3 {
    margin: 0;
}

.bmn-source-details table {
    width: 100%;
    font-size: 13px;
}

.bmn-source-details td {
    padding: 4px 0;
}

.bmn-source-error {
    margin-top: 10px;
    padding: 8px;
    background: #f8d7da;
    border-radius: 4px;
    font-size: 12px;
    color: #721c24;
}

.bmn-source-actions {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.bmn-source-actions .button {
    margin-right: 5px;
}

.bmn-info-card {
    margin-top: 30px;
}

.bmn-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.bmn-card h2,
.bmn-card h3 {
    margin-top: 0;
}

.bmn-card h3 {
    margin-top: 20px;
}

.bmn-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.bmn-table th,
.bmn-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.bmn-table th {
    background: #f6f7f7;
    font-weight: 600;
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

.bmn-badge-warning {
    background: #fff3cd;
    color: #856404;
}

.bmn-badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.bmn-badge-secondary {
    background: #e2e3e5;
    color: #383d41;
}

.bmn-notice {
    padding: 15px;
    border-radius: 4px;
}

.bmn-notice-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
}
</style>
