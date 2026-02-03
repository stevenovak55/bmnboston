<?php
/**
 * Database Verification Admin Page
 *
 * @package MLS_Listings_Display
 * @since 5.2.9
 * @updated 6.20.12 - Added column verification and repair functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get the verification tool instance
$verifier = MLD_Database_Verify::get_instance();

// Handle repair all action (tables + columns)
$repair_message = '';
if (isset($_POST['repair_all']) && wp_verify_nonce($_POST['_wpnonce'], 'mld_repair_all')) {
    $repair_results = $verifier->repair_all();
    $summary = $repair_results['summary'];

    $repair_message = '<div class="notice notice-success"><p><strong>Repair Results:</strong></p>';
    $repair_message .= '<ul>';
    $repair_message .= "<li>Tables created: {$summary['tables_created']}</li>";
    $repair_message .= "<li>Columns added: {$summary['columns_added']}</li>";
    $repair_message .= "<li>Errors: {$summary['errors']}</li>";
    $repair_message .= '</ul>';

    // Show details if columns were added
    if ($summary['columns_added'] > 0) {
        $repair_message .= '<p><strong>Columns Added:</strong></p><ul>';
        foreach ($repair_results['columns'] as $table => $info) {
            if (!empty($info['columns_added'])) {
                $repair_message .= "<li>{$table}: " . implode(', ', $info['columns_added']) . "</li>";
            }
        }
        $repair_message .= '</ul>';
    }
    $repair_message .= '</div>';
}

// Handle legacy repair tables action (kept for backwards compatibility)
if (isset($_POST['repair_tables']) && wp_verify_nonce($_POST['_wpnonce'], 'mld_repair_tables')) {
    $repair_results = $verifier->repair_tables();
    $repair_message = '<div class="notice notice-success"><p><strong>Table Repair Results:</strong></p><ul>';
    foreach ($repair_results as $table => $result) {
        $status_text = $result['status'] === 'created' ? '&#10004; Created' :
                      ($result['status'] === 'already_exists' ? '&#10004; Already exists' : '&#10008; Failed');
        $repair_message .= "<li>{$table}: {$status_text}</li>";
    }
    $repair_message .= '</ul></div>';
}

// Handle cleanup action
$cleanup_message = '';
if (isset($_POST['cleanup_orphaned']) && wp_verify_nonce($_POST['_wpnonce'], 'mld_cleanup_orphaned')) {
    $cleanup_results = $verifier->cleanup_orphaned_records();
    $cleanup_message = '<div class="notice notice-success"><p><strong>Cleanup Results:</strong></p><ul>';
    $cleanup_message .= "<li>Orphaned saved searches removed: {$cleanup_results['saved_searches']}</li>";
    $cleanup_message .= "<li>Expired cache entries removed: {$cleanup_results['expired_cache']}</li>";
    $cleanup_message .= "<li>Expired CMA cache removed: {$cleanup_results['expired_cma_cache']}</li>";
    $cleanup_message .= '</ul></div>';
}

// Run full health check (includes column verification)
$health_check = $verifier->health_check_full();
$table_status = $verifier->verify_tables();
$column_status = $verifier->verify_columns();
$version_info = $verifier->check_version();

// Count issues
$missing_tables = 0;
$tables_with_missing_columns = 0;
foreach ($table_status as $info) {
    if (!$info['exists']) $missing_tables++;
}
foreach ($column_status as $info) {
    if ($info['status'] === 'missing_columns') $tables_with_missing_columns++;
}

?>

<div class="wrap">
    <h1>MLS Listings Display - Database Verification</h1>

    <?php echo $repair_message; ?>
    <?php echo $cleanup_message; ?>

    <div class="card">
        <h2>System Health Score: <?php echo $health_check['health_score']; ?>%</h2>

        <div style="margin: 20px 0;">
            <div style="background: #e0e0e0; height: 30px; border-radius: 5px; overflow: hidden;">
                <div style="background: <?php echo $health_check['health_score'] >= 80 ? '#4caf50' : ($health_check['health_score'] >= 60 ? '#ff9800' : '#f44336'); ?>;
                           width: <?php echo $health_check['health_score']; ?>%;
                           height: 100%;
                           text-align: center;
                           line-height: 30px;
                           color: white;
                           font-weight: bold;">
                    <?php echo $health_check['health_score']; ?>%
                </div>
            </div>
        </div>

        <p>
            <strong>Status:</strong>
            <?php
            $status_colors = ['healthy' => 'green', 'warning' => 'orange', 'critical' => 'red'];
            $status_icons = ['healthy' => '&#10004;', 'warning' => '&#9888;', 'critical' => '&#10008;'];
            $status = $health_check['status'];
            echo '<span style="color: ' . $status_colors[$status] . ';">' . $status_icons[$status] . ' ' . ucfirst($status) . '</span>';
            ?>
        </p>

        <?php if (!empty($health_check['issues'])): ?>
            <div class="notice notice-warning" style="margin: 10px 0;">
                <p><strong>Issues Found (<?php echo count($health_check['issues']); ?>):</strong></p>
                <ul style="margin-left: 20px;">
                    <?php foreach ($health_check['issues'] as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($health_check['recommendations'])): ?>
            <div class="notice notice-info" style="margin: 10px 0;">
                <p><strong>Recommendations:</strong></p>
                <ul style="margin-left: 20px;">
                    <?php foreach ($health_check['recommendations'] as $recommendation): ?>
                        <li><?php echo esc_html($recommendation); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Version Information</h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <th>Plugin Version</th>
                    <td><?php echo esc_html($version_info['plugin_version']); ?></td>
                    <td><?php echo $version_info['versions_match'] ? '<span style="color:green;">&#10004;</span>' : '<span style="color:orange;">&#9888;</span>'; ?></td>
                </tr>
                <tr>
                    <th>Database Version</th>
                    <td><?php echo esc_html($version_info['database_version']); ?></td>
                    <td><?php echo $version_info['versions_match'] ? '<span style="color:green;">&#10004;</span>' : '<span style="color:orange;">&#9888; Mismatch</span>'; ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Database Tables & Columns Status</h2>
        <p>
            <strong>Summary:</strong>
            <?php echo count($table_status); ?> tables checked,
            <?php echo $missing_tables; ?> missing tables,
            <?php echo $tables_with_missing_columns; ?> tables with missing columns
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Purpose</th>
                    <th>Table Status</th>
                    <th>Column Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table_status as $table => $info): ?>
                    <?php
                    $col_info = isset($column_status[$table]) ? $column_status[$table] : null;
                    $has_missing_columns = $col_info && !empty($col_info['missing_columns']);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($info['table_name']); ?></code></td>
                        <td><?php echo esc_html($info['purpose']); ?></td>
                        <td>
                            <?php if ($info['exists']): ?>
                                <span style="color: green;">&#10004; Exists</span>
                            <?php else: ?>
                                <span style="color: red;">&#10008; Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$info['exists']): ?>
                                <span style="color: gray;">N/A</span>
                            <?php elseif ($has_missing_columns): ?>
                                <span style="color: orange;" title="Missing: <?php echo esc_attr(implode(', ', $col_info['missing_columns'])); ?>">
                                    &#9888; <?php echo count($col_info['missing_columns']); ?> missing
                                </span>
                                <br><small style="color: #666;">
                                    <?php echo esc_html(implode(', ', $col_info['missing_columns'])); ?>
                                </small>
                            <?php else: ?>
                                <span style="color: green;">&#10004; All columns OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Actions</h2>

        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('mld_repair_all'); ?>
            <button type="submit" name="repair_all" class="button button-primary">
                &#128295; Fix All Issues (Tables + Columns)
            </button>
        </form>

        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('mld_cleanup_orphaned'); ?>
            <button type="submit" name="cleanup_orphaned" class="button">
                &#128465; Clean Orphaned Records
            </button>
        </form>

        <form method="get" style="display: inline-block;">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
            <button type="submit" class="button">
                &#128260; Refresh Status
            </button>
        </form>
    </div>

    <div class="card">
        <h2>Documentation</h2>
        <p>
            The Database Verification Tool helps maintain database integrity for the MLS Listings Display plugin.
            It can:
        </p>
        <ul>
            <li><strong>Verify all required tables exist</strong> - Checks all <?php echo count($table_status); ?> plugin tables</li>
            <li><strong>Verify all columns exist</strong> - NEW in v6.20.12! Checks every column in every table</li>
            <li><strong>Create missing tables automatically</strong> - Uses WordPress dbDelta for safe table creation</li>
            <li><strong>Add missing columns</strong> - NEW! Automatically adds any columns missing from existing tables</li>
            <li><strong>Check version compatibility</strong> - Ensures plugin and database versions match</li>
            <li><strong>Clean orphaned records</strong> - Removes stale data and expired cache entries</li>
            <li><strong>Provide health score and recommendations</strong> - Quick overview of system health</li>
        </ul>
        <p>
            <strong>Recommended Usage:</strong> Run this tool after plugin updates, when experiencing issues,
            or as part of regular maintenance. The "Fix All Issues" button will safely repair both missing
            tables and missing columns.
        </p>
    </div>
</div>

<style>
.card {
    background: white;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}
.widefat th {
    font-weight: 600;
}
code {
    background: #f1f1f1;
    padding: 2px 5px;
    border-radius: 3px;
    font-size: 12px;
}
.widefat td {
    vertical-align: top;
}
</style>
