<?php
/**
 * MLS Listings Display Health Dashboard
 *
 * Provides a comprehensive system health overview in WP Admin including:
 * - Plugin version consistency checks
 * - Database table status
 * - Summary table sync status
 * - Cron job health
 * - Docker/environment status (dev only)
 *
 * @package MLS_Listings_Display
 * @since 6.10.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Health_Dashboard {

    /**
     * Instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_ajax_mld_health_refresh', array($this, 'ajax_refresh_health'));
        add_action('wp_ajax_mld_fix_summary_table', array($this, 'ajax_fix_summary_table'));
        add_action('wp_ajax_mld_run_cron', array($this, 'ajax_run_cron'));
        add_action('wp_ajax_mld_run_all_overdue_crons', array($this, 'ajax_run_all_overdue_crons'));
        add_action('wp_ajax_mld_process_retry_queue', array($this, 'ajax_process_retry_queue'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            __('System Health', 'mls-listings-display'),
            __('System Health', 'mls-listings-display'),
            'manage_options',
            'mld-health-dashboard',
            array($this, 'render_dashboard')
        );
    }

    /**
     * Enqueue styles
     */
    public function enqueue_styles($hook) {
        if (strpos($hook, 'mld-health-dashboard') === false) {
            return;
        }

        wp_add_inline_style('wp-admin', $this->get_inline_styles());
    }

    /**
     * Get inline styles
     */
    private function get_inline_styles() {
        return '
            .mld-health-dashboard { max-width: 1200px; }
            .mld-health-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; }
            .mld-health-card-header { padding: 15px 20px; border-bottom: 1px solid #ccd0d4; background: #f9f9f9; }
            .mld-health-card-header h2 { margin: 0; font-size: 16px; }
            .mld-health-card-body { padding: 20px; }
            .mld-health-table { width: 100%; border-collapse: collapse; }
            .mld-health-table th, .mld-health-table td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #eee; }
            .mld-health-table th { background: #f5f5f5; font-weight: 600; }
            .mld-status-ok { color: #46b450; }
            .mld-status-warning { color: #ffb900; }
            .mld-status-error { color: #dc3232; }
            .mld-status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
            .mld-status-badge.ok { background: #d4edda; color: #155724; }
            .mld-status-badge.warning { background: #fff3cd; color: #856404; }
            .mld-status-badge.error { background: #f8d7da; color: #721c24; }
            .mld-health-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
            .mld-health-stat { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; text-align: center; }
            .mld-health-stat-value { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
            .mld-health-stat-label { font-size: 13px; color: #666; }
            .mld-action-button { margin-top: 10px; }
        ';
    }

    /**
     * Render dashboard
     */
    public function render_dashboard() {
        $health_data = $this->get_health_data();
        ?>
        <div class="wrap mld-health-dashboard">
            <h1><?php _e('System Health Dashboard', 'mls-listings-display'); ?></h1>
            <p class="description"><?php _e('Monitor the health of your MLS plugin ecosystem.', 'mls-listings-display'); ?></p>

            <!-- Quick Summary -->
            <div class="mld-health-summary">
                <div class="mld-health-stat">
                    <div class="mld-health-stat-value <?php echo $health_data['overall_status'] === 'ok' ? 'mld-status-ok' : ($health_data['overall_status'] === 'warning' ? 'mld-status-warning' : 'mld-status-error'); ?>">
                        <?php echo $health_data['overall_status'] === 'ok' ? '✓' : ($health_data['overall_status'] === 'warning' ? '⚠' : '✗'); ?>
                    </div>
                    <div class="mld-health-stat-label"><?php _e('Overall Health', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-health-stat">
                    <div class="mld-health-stat-value"><?php echo number_format($health_data['listings']['active']); ?></div>
                    <div class="mld-health-stat-label"><?php _e('Active Listings', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-health-stat">
                    <div class="mld-health-stat-value"><?php echo number_format($health_data['listings']['summary']); ?></div>
                    <div class="mld-health-stat-label"><?php _e('Summary Table', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-health-stat">
                    <div class="mld-health-stat-value"><?php echo $health_data['issues_count']; ?></div>
                    <div class="mld-health-stat-label"><?php _e('Issues Found', 'mls-listings-display'); ?></div>
                </div>
            </div>

            <!-- Version Status -->
            <div class="mld-health-card">
                <div class="mld-health-card-header">
                    <h2><?php _e('Plugin Version Status', 'mls-listings-display'); ?></h2>
                </div>
                <div class="mld-health-card-body">
                    <table class="mld-health-table">
                        <thead>
                            <tr>
                                <th><?php _e('Plugin', 'mls-listings-display'); ?></th>
                                <th><?php _e('Installed Version', 'mls-listings-display'); ?></th>
                                <th><?php _e('DB Version', 'mls-listings-display'); ?></th>
                                <th><?php _e('Status', 'mls-listings-display'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($health_data['versions'] as $plugin => $version_info): ?>
                            <tr>
                                <td><strong><?php echo esc_html($version_info['name']); ?></strong></td>
                                <td><?php echo esc_html($version_info['installed']); ?></td>
                                <td><?php echo esc_html($version_info['db_version']); ?></td>
                                <td>
                                    <span class="mld-status-badge <?php echo $version_info['status']; ?>">
                                        <?php echo $version_info['status'] === 'ok' ? __('OK', 'mls-listings-display') : __('Mismatch', 'mls-listings-display'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Database Status -->
            <div class="mld-health-card">
                <div class="mld-health-card-header">
                    <h2><?php _e('Database Table Status', 'mls-listings-display'); ?></h2>
                </div>
                <div class="mld-health-card-body">
                    <table class="mld-health-table">
                        <thead>
                            <tr>
                                <th><?php _e('Table', 'mls-listings-display'); ?></th>
                                <th><?php _e('Records', 'mls-listings-display'); ?></th>
                                <th><?php _e('Status', 'mls-listings-display'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($health_data['tables'] as $table => $table_info): ?>
                            <tr>
                                <td><code><?php echo esc_html($table); ?></code></td>
                                <td><?php echo number_format($table_info['count']); ?></td>
                                <td>
                                    <span class="mld-status-badge <?php echo $table_info['status']; ?>">
                                        <?php echo $table_info['status'] === 'ok' ? __('OK', 'mls-listings-display') : ($table_info['status'] === 'warning' ? __('Warning', 'mls-listings-display') : __('Missing', 'mls-listings-display')); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($health_data['summary_sync']['needs_refresh']): ?>
                    <div class="mld-action-button" style="background: #fff3cd; padding: 15px; border-radius: 4px; margin-top: 15px;">
                        <p class="description" style="color: #856404; margin-bottom: 10px;">
                            <strong><?php _e('⚠️ Summary table is out of sync!', 'mls-listings-display'); ?></strong><br>
                            <?php printf(__('Active listings: %d, Summary table: %d (difference: %d)', 'mls-listings-display'),
                                $health_data['listings']['active'],
                                $health_data['listings']['summary'],
                                abs($health_data['listings']['active'] - $health_data['listings']['summary'])
                            ); ?>
                        </p>
                        <p class="description" style="color: #666; margin-bottom: 10px; font-size: 12px;">
                            <?php _e('Since BME 4.0.14, the summary table is populated in real-time during extraction. If out of sync, run a new extraction in BME to rebuild the data.', 'mls-listings-display'); ?>
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="button" class="button button-secondary" id="mld-diagnose-sync">
                                <?php _e('🔍 Diagnose Issue', 'mls-listings-display'); ?>
                            </button>
                            <button type="button" class="button button-primary" id="mld-fix-summary-table">
                                <?php _e('🔧 Emergency Repair', 'mls-listings-display'); ?>
                            </button>
                        </div>
                        <div id="mld-sync-diagnostic-results" style="margin-top: 15px; display: none;"></div>
                    </div>
                    <script>
                    jQuery(document).ready(function($) {
                        var nonce = '<?php echo wp_create_nonce('mld_health_nonce'); ?>';

                        // Diagnose button
                        $('#mld-diagnose-sync').on('click', function() {
                            var $btn = $(this);
                            var $results = $('#mld-sync-diagnostic-results');
                            $btn.prop('disabled', true).text('<?php _e('Diagnosing...', 'mls-listings-display'); ?>');

                            $.post(ajaxurl, {
                                action: 'mld_diagnose_summary_sync',
                                nonce: nonce
                            }, function(response) {
                                $btn.prop('disabled', false).text('<?php _e('🔍 Diagnose Issue', 'mls-listings-display'); ?>');

                                if (response.success) {
                                    var data = response.data;
                                    var html = '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">';
                                    html += '<h4 style="margin-top: 0;">Diagnostic Results:</h4>';
                                    html += '<ul style="margin: 0; padding-left: 20px;">';
                                    html += '<li>Missing from summary: <strong>' + data.missing_count + '</strong></li>';
                                    html += '<li>Orphaned summary entries: <strong>' + data.orphaned_count + '</strong></li>';
                                    html += '<li>Missing location data: <strong>' + data.missing_location_count + '</strong></li>';
                                    html += '<li>Missing details data: <strong>' + data.missing_details_count + '</strong></li>';
                                    html += '</ul>';

                                    if (data.potential_causes && data.potential_causes.length > 0) {
                                        html += '<h4>Potential Causes:</h4>';
                                        html += '<ul style="margin: 0; padding-left: 20px;">';
                                        data.potential_causes.forEach(function(cause) {
                                            html += '<li>' + cause + '</li>';
                                        });
                                        html += '</ul>';
                                    }

                                    if (data.missing_listings && data.missing_listings.length > 0) {
                                        html += '<h4>Sample Missing Listings (first 10):</h4>';
                                        html += '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
                                        html += '<tr><th style="text-align: left; padding: 4px; border-bottom: 1px solid #ddd;">ID</th>';
                                        html += '<th style="text-align: left; padding: 4px; border-bottom: 1px solid #ddd;">MLS#</th>';
                                        html += '<th style="text-align: left; padding: 4px; border-bottom: 1px solid #ddd;">Price</th>';
                                        html += '<th style="text-align: left; padding: 4px; border-bottom: 1px solid #ddd;">Type</th></tr>';
                                        data.missing_listings.forEach(function(listing) {
                                            html += '<tr>';
                                            html += '<td style="padding: 4px;">' + listing.listing_id + '</td>';
                                            html += '<td style="padding: 4px;">' + listing.listing_key + '</td>';
                                            html += '<td style="padding: 4px;">$' + parseFloat(listing.list_price).toLocaleString() + '</td>';
                                            html += '<td style="padding: 4px;">' + listing.property_type + '</td>';
                                            html += '</tr>';
                                        });
                                        html += '</table>';
                                    }

                                    html += '</div>';
                                    $results.html(html).show();
                                } else {
                                    $results.html('<p style="color: #dc3232;">Error: ' + response.data + '</p>').show();
                                }
                            });
                        });

                        // Emergency Repair button
                        $('#mld-fix-summary-table').on('click', function() {
                            var $btn = $(this);
                            $btn.prop('disabled', true).text('<?php _e('Repairing...', 'mls-listings-display'); ?>');

                            $.post(ajaxurl, {
                                action: 'mld_fix_summary_table',
                                nonce: nonce
                            }, function(response) {
                                if (response.success) {
                                    alert('<?php _e('Summary table repaired successfully. Reloading page...', 'mls-listings-display'); ?>');
                                    location.reload();
                                } else {
                                    alert('<?php _e('Error: ', 'mls-listings-display'); ?>' + response.data);
                                    $btn.prop('disabled', false).text('<?php _e('🔧 Emergency Repair', 'mls-listings-display'); ?>');
                                }
                            });
                        });
                    });
                    </script>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cron Status -->
            <div class="mld-health-card">
                <div class="mld-health-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h2><?php _e('Cron Job Status', 'mls-listings-display'); ?></h2>
                    <button type="button" class="button button-secondary" id="mld-run-all-overdue-crons">
                        <?php _e('Run All Overdue', 'mls-listings-display'); ?>
                    </button>
                </div>
                <div class="mld-health-card-body">
                    <table class="mld-health-table">
                        <thead>
                            <tr>
                                <th><?php _e('Cron Job', 'mls-listings-display'); ?></th>
                                <th><?php _e('Next Run', 'mls-listings-display'); ?></th>
                                <th><?php _e('Status', 'mls-listings-display'); ?></th>
                                <th><?php _e('Actions', 'mls-listings-display'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($health_data['cron_jobs'] as $label => $cron_info): ?>
                            <tr data-hook="<?php echo esc_attr($cron_info['hook']); ?>">
                                <td><code><?php echo esc_html($label); ?></code></td>
                                <td class="cron-next-run"><?php echo esc_html($cron_info['next_run']); ?></td>
                                <td class="cron-status-cell">
                                    <span class="mld-status-badge <?php echo $cron_info['status']; ?>">
                                        <?php
                                        if ($cron_info['status'] === 'ok') {
                                            _e('Scheduled', 'mls-listings-display');
                                        } elseif ($cron_info['status'] === 'error') {
                                            _e('Overdue', 'mls-listings-display');
                                        } else {
                                            _e('Not Scheduled', 'mls-listings-display');
                                        }
                                        ?>
                                    </span>
                                    <?php if (!empty($cron_info['next_run_relative'])): ?>
                                    <small class="cron-relative-time" style="color:#666; margin-left:5px;">(<?php echo esc_html($cron_info['next_run_relative']); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small mld-run-cron-btn" data-hook="<?php echo esc_attr($cron_info['hook']); ?>">
                                        <?php _e('Run Now', 'mls-listings-display'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cron Controls JavaScript -->
            <script>
            jQuery(document).ready(function($) {
                var cronNonce = '<?php echo wp_create_nonce('mld_health_nonce'); ?>';

                // Run individual cron button
                $('.mld-run-cron-btn').on('click', function() {
                    var $btn = $(this);
                    var hook = $btn.data('hook');
                    var $row = $btn.closest('tr');
                    var originalText = $btn.text();

                    $btn.prop('disabled', true).text('<?php _e('Running...', 'mls-listings-display'); ?>');

                    $.post(ajaxurl, {
                        action: 'mld_run_cron',
                        hook: hook,
                        nonce: cronNonce
                    }, function(response) {
                        $btn.prop('disabled', false).text(originalText);

                        if (response.success) {
                            // Update the row with new schedule info
                            $row.find('.cron-next-run').text(response.data.next_run);
                            $row.find('.cron-relative-time').text('(' + response.data.next_run_relative + ')');

                            // Update status badge
                            var $badge = $row.find('.mld-status-badge');
                            $badge.removeClass('ok error warning').addClass(response.data.status);
                            if (response.data.status === 'ok') {
                                $badge.text('<?php _e('Scheduled', 'mls-listings-display'); ?>');
                            } else if (response.data.status === 'error') {
                                $badge.text('<?php _e('Overdue', 'mls-listings-display'); ?>');
                            } else {
                                $badge.text('<?php _e('Not Scheduled', 'mls-listings-display'); ?>');
                            }

                            // Flash success
                            $row.css('background-color', '#d4edda');
                            setTimeout(function() { $row.css('background-color', ''); }, 1500);
                        } else {
                            alert('<?php _e('Error: ', 'mls-listings-display'); ?>' + response.data);
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false).text(originalText);
                        alert('<?php _e('Request failed. Please try again.', 'mls-listings-display'); ?>');
                    });
                });

                // Run all overdue crons button
                $('#mld-run-all-overdue-crons').on('click', function() {
                    var $btn = $(this);
                    var originalText = $btn.text();

                    $btn.prop('disabled', true).text('<?php _e('Running...', 'mls-listings-display'); ?>');

                    $.post(ajaxurl, {
                        action: 'mld_run_all_overdue_crons',
                        nonce: cronNonce
                    }, function(response) {
                        $btn.prop('disabled', false).text(originalText);

                        if (response.success) {
                            alert(response.data.message);
                            if (response.data.executed > 0) {
                                location.reload();
                            }
                        } else {
                            alert('<?php _e('Error: ', 'mls-listings-display'); ?>' + response.data);
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false).text(originalText);
                        alert('<?php _e('Request failed. Please try again.', 'mls-listings-display'); ?>');
                    });
                });
            });
            </script>

            <!-- Push Notification Queue Status (v6.49.3) -->
            <?php
            $push_stats = $this->get_push_notification_stats();
            if (!empty($push_stats)):
            ?>
            <div class="mld-health-card">
                <div class="mld-health-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h2><?php _e('Push Notification Queue', 'mls-listings-display'); ?></h2>
                    <button type="button" class="button button-secondary" id="mld-process-retry-queue">
                        <?php _e('Process Queue Now', 'mls-listings-display'); ?>
                    </button>
                </div>
                <div class="mld-health-card-body">
                    <!-- Queue Stats Summary -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: <?php echo $push_stats['retry_queue']['pending'] > 100 ? '#dc3232' : ($push_stats['retry_queue']['pending'] > 20 ? '#ffb900' : '#46b450'); ?>;">
                                <?php echo number_format($push_stats['retry_queue']['pending']); ?>
                            </div>
                            <div style="font-size: 11px; color: #666;"><?php _e('Pending', 'mls-listings-display'); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #17a2b8;">
                                <?php echo number_format($push_stats['retry_queue']['processing']); ?>
                            </div>
                            <div style="font-size: 11px; color: #666;"><?php _e('Processing', 'mls-listings-display'); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #46b450;">
                                <?php echo number_format($push_stats['retry_queue']['completed']); ?>
                            </div>
                            <div style="font-size: 11px; color: #666;"><?php _e('Completed', 'mls-listings-display'); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #dc3232;">
                                <?php echo number_format($push_stats['retry_queue']['failed']); ?>
                            </div>
                            <div style="font-size: 11px; color: #666;"><?php _e('Failed', 'mls-listings-display'); ?></div>
                        </div>
                    </div>

                    <!-- Delivery Stats (24h) -->
                    <h4 style="margin-bottom: 10px;"><?php _e('Last 24 Hours', 'mls-listings-display'); ?></h4>
                    <table class="mld-health-table">
                        <tr>
                            <td><?php _e('Total Sent', 'mls-listings-display'); ?></td>
                            <td><strong><?php echo number_format($push_stats['delivery']['total']); ?></strong></td>
                            <td><?php _e('Success Rate', 'mls-listings-display'); ?></td>
                            <td>
                                <span class="mld-status-badge <?php echo $push_stats['delivery']['success_rate'] >= 95 ? 'ok' : ($push_stats['delivery']['success_rate'] >= 80 ? 'warning' : 'error'); ?>">
                                    <?php echo $push_stats['delivery']['success_rate']; ?>%
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Successful', 'mls-listings-display'); ?></td>
                            <td><span style="color: #46b450;"><?php echo number_format($push_stats['delivery']['sent']); ?></span></td>
                            <td><?php _e('Failed', 'mls-listings-display'); ?></td>
                            <td><span style="color: #dc3232;"><?php echo number_format($push_stats['delivery']['failed']); ?></span></td>
                        </tr>
                    </table>

                    <?php if ($push_stats['retry_queue']['pending'] > 50): ?>
                    <div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 15px;">
                        <span class="mld-status-warning">⚠</span>
                        <?php _e('Retry queue is backing up. Consider investigating APNs connectivity or rate limiting.', 'mls-listings-display'); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Rate Limiting Stats -->
                    <?php if (!empty($push_stats['rate_limit'])): ?>
                    <h4 style="margin-top: 15px; margin-bottom: 10px;"><?php _e('Rate Limiting', 'mls-listings-display'); ?></h4>
                    <table class="mld-health-table">
                        <tr>
                            <td><?php _e('Current Window Usage', 'mls-listings-display'); ?></td>
                            <td>
                                <?php echo $push_stats['rate_limit']['current_window_count']; ?> / <?php echo $push_stats['rate_limit']['limit_per_second']; ?> req/sec
                                (<?php echo $push_stats['rate_limit']['utilization_percent']; ?>%)
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e('Throttling Active', 'mls-listings-display'); ?></td>
                            <td>
                                <span class="mld-status-badge <?php echo $push_stats['rate_limit']['is_throttling'] ? 'warning' : 'ok'; ?>">
                                    <?php echo $push_stats['rate_limit']['is_throttling'] ? __('Yes', 'mls-listings-display') : __('No', 'mls-listings-display'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#mld-process-retry-queue').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php _e('Processing...', 'mls-listings-display'); ?>');

                    $.post(ajaxurl, {
                        action: 'mld_process_retry_queue',
                        nonce: '<?php echo wp_create_nonce('mld_health_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('<?php _e('Error: ', 'mls-listings-display'); ?>' + response.data);
                            $btn.prop('disabled', false).text('<?php _e('Process Queue Now', 'mls-listings-display'); ?>');
                        }
                    });
                });
            });
            </script>
            <?php endif; ?>

            <!-- Issues -->
            <?php if (!empty($health_data['issues'])): ?>
            <div class="mld-health-card">
                <div class="mld-health-card-header">
                    <h2><?php _e('Issues & Recommendations', 'mls-listings-display'); ?></h2>
                </div>
                <div class="mld-health-card-body">
                    <ul>
                        <?php foreach ($health_data['issues'] as $issue): ?>
                        <li>
                            <span class="mld-status-<?php echo $issue['severity']; ?>">
                                <?php echo $issue['severity'] === 'error' ? '✗' : '⚠'; ?>
                            </span>
                            <?php echo esc_html($issue['message']); ?>
                            <?php if (!empty($issue['action'])): ?>
                            <br><small><em><?php echo esc_html($issue['action']); ?></em></small>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Health Alert Configuration (v6.58.0) -->
            <?php $this->render_alert_configuration(); ?>

            <p class="description">
                <?php printf(__('Last checked: %s', 'mls-listings-display'), current_time('mysql')); ?>
                <a href="" class="button button-secondary" style="margin-left: 10px;"><?php _e('Refresh', 'mls-listings-display'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Render alert configuration section (v6.58.0)
     */
    private function render_alert_configuration() {
        // Handle form submission
        if (isset($_POST['mld_save_alert_settings']) && check_admin_referer('mld_alert_settings')) {
            // Load alerts class if not already loaded
            if (!class_exists('MLD_Health_Alerts')) {
                $path = MLD_PLUGIN_PATH . 'includes/health/class-mld-health-alerts.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }

            if (class_exists('MLD_Health_Alerts')) {
                $alerts = MLD_Health_Alerts::get_instance();
                $alerts->save_settings(array(
                    'enabled' => isset($_POST['mld_alerts_enabled']),
                    'recipients' => sanitize_text_field($_POST['mld_alert_recipients'] ?? ''),
                    'throttle_minutes' => absint($_POST['mld_alert_throttle'] ?? 60),
                    'min_severity' => sanitize_text_field($_POST['mld_alert_min_severity'] ?? 'warning'),
                ));
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Alert settings saved.', 'mls-listings-display') . '</p></div>';
            }

            // Handle test alert
            if (isset($_POST['mld_test_alert'])) {
                if (class_exists('MLD_Health_Alerts')) {
                    $alerts = MLD_Health_Alerts::get_instance();
                    $result = $alerts->send_test_alert();
                    if ($result) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . __('Test alert sent successfully!', 'mls-listings-display') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to send test alert. Check email settings.', 'mls-listings-display') . '</p></div>';
                    }
                }
            }
        }

        // Get current settings
        $settings = array(
            'enabled' => get_option('mld_health_alerts_enabled', false),
            'recipients' => get_option('mld_health_alert_recipients', get_option('admin_email')),
            'throttle_minutes' => get_option('mld_health_alert_throttle', 60),
            'min_severity' => get_option('mld_health_alert_min_severity', 'warning'),
            'last_alert' => get_option('mld_health_last_alert_time', null),
            'last_status' => get_option('mld_health_last_status', 'healthy'),
        );
        ?>
        <div class="mld-health-card">
            <div class="mld-health-card-header">
                <h2><?php _e('Health Alert Configuration', 'mls-listings-display'); ?></h2>
            </div>
            <div class="mld-health-card-body">
                <form method="post" action="">
                    <?php wp_nonce_field('mld_alert_settings'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Enable Email Alerts', 'mls-listings-display'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mld_alerts_enabled" value="1" <?php checked($settings['enabled']); ?>>
                                    <?php _e('Send email alerts when system health degrades', 'mls-listings-display'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Alert Recipients', 'mls-listings-display'); ?></th>
                            <td>
                                <input type="text" name="mld_alert_recipients" value="<?php echo esc_attr($settings['recipients']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                <p class="description"><?php _e('Comma-separated email addresses. Leave empty to use admin email.', 'mls-listings-display'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Throttle Duration', 'mls-listings-display'); ?></th>
                            <td>
                                <select name="mld_alert_throttle">
                                    <option value="30" <?php selected($settings['throttle_minutes'], 30); ?>><?php _e('30 minutes', 'mls-listings-display'); ?></option>
                                    <option value="60" <?php selected($settings['throttle_minutes'], 60); ?>><?php _e('1 hour', 'mls-listings-display'); ?></option>
                                    <option value="120" <?php selected($settings['throttle_minutes'], 120); ?>><?php _e('2 hours', 'mls-listings-display'); ?></option>
                                    <option value="240" <?php selected($settings['throttle_minutes'], 240); ?>><?php _e('4 hours', 'mls-listings-display'); ?></option>
                                </select>
                                <p class="description"><?php _e('Minimum time between alert emails to prevent spam.', 'mls-listings-display'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Minimum Severity', 'mls-listings-display'); ?></th>
                            <td>
                                <select name="mld_alert_min_severity">
                                    <option value="warning" <?php selected($settings['min_severity'], 'warning'); ?>><?php _e('Warning (degraded or unhealthy)', 'mls-listings-display'); ?></option>
                                    <option value="critical" <?php selected($settings['min_severity'], 'critical'); ?>><?php _e('Critical only (unhealthy)', 'mls-listings-display'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="mld_save_alert_settings" class="button button-primary" value="<?php _e('Save Alert Settings', 'mls-listings-display'); ?>">
                        <input type="submit" name="mld_test_alert" class="button button-secondary" value="<?php _e('Send Test Alert', 'mls-listings-display'); ?>">
                    </p>

                    <?php if ($settings['last_alert']): ?>
                    <p class="description">
                        <?php
                        $last_alert_date = new DateTime('@' . $settings['last_alert']);
                        $last_alert_date->setTimezone(wp_timezone());
                        printf(
                            __('Last alert sent: %s | Last known status: %s', 'mls-listings-display'),
                            $last_alert_date->format('Y-m-d H:i:s'),
                            strtoupper($settings['last_status'])
                        );
                        ?>
                    </p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Get health data
     */
    private function get_health_data() {
        global $wpdb;

        $data = array(
            'overall_status' => 'ok',
            'issues' => array(),
            'issues_count' => 0,
            'versions' => array(),
            'tables' => array(),
            'listings' => array(),
            'summary_sync' => array(),
            'cron_jobs' => array(),
        );

        // Version checks
        $data['versions'] = $this->check_versions();

        // Table checks
        $data['tables'] = $this->check_tables();

        // Listing counts
        $data['listings'] = $this->get_listing_counts();

        // Summary sync check
        $data['summary_sync'] = array(
            'needs_refresh' => $data['listings']['active'] !== $data['listings']['summary'],
        );

        // Cron checks
        $data['cron_jobs'] = $this->check_cron_jobs();

        // Collect issues
        $data['issues'] = $this->collect_issues($data);
        $data['issues_count'] = count($data['issues']);

        // Determine overall status
        if ($data['issues_count'] > 0) {
            $has_errors = false;
            foreach ($data['issues'] as $issue) {
                if ($issue['severity'] === 'error') {
                    $has_errors = true;
                    break;
                }
            }
            $data['overall_status'] = $has_errors ? 'error' : 'warning';
        }

        return $data;
    }

    /**
     * Check plugin versions
     */
    private function check_versions() {
        $versions = array();

        // MLD version
        $mld_installed = defined('MLD_VERSION') ? MLD_VERSION : 'Unknown';
        $mld_db = get_option('mld_db_version', 'Not set');
        $versions['mld'] = array(
            'name' => 'MLS Listings Display',
            'installed' => $mld_installed,
            'db_version' => $mld_db,
            'status' => ($mld_installed === $mld_db || $mld_db === 'Not set') ? 'ok' : 'warning',
        );

        // BME version
        if (function_exists('bme_pro')) {
            $bme_installed = defined('BME_VERSION') ? BME_VERSION : 'Unknown';
            $bme_db = get_option('bme_db_version', 'Not set');
            $versions['bme'] = array(
                'name' => 'Bridge MLS Extractor Pro',
                'installed' => $bme_installed,
                'db_version' => $bme_db,
                'status' => ($bme_installed === $bme_db || $bme_db === 'Not set') ? 'ok' : 'warning',
            );
        } else {
            $versions['bme'] = array(
                'name' => 'Bridge MLS Extractor Pro',
                'installed' => 'Not Installed',
                'db_version' => 'N/A',
                'status' => 'error',
            );
        }

        // v6.68.15: BMN Schools version
        if (defined('BMN_SCHOOLS_VERSION')) {
            $bmn_installed = BMN_SCHOOLS_VERSION;
            $bmn_db = get_option('bmn_schools_db_version', 'Not set');
            $versions['bmn_schools'] = array(
                'name' => 'BMN Schools',
                'installed' => $bmn_installed,
                'db_version' => $bmn_db,
                'status' => ($bmn_installed === $bmn_db || $bmn_db === 'Not set') ? 'ok' : 'warning',
            );
        } else {
            $versions['bmn_schools'] = array(
                'name' => 'BMN Schools',
                'installed' => 'Not Installed',
                'db_version' => 'N/A',
                'status' => 'warning', // Optional plugin
            );
        }

        // v6.68.15: SN Appointment Booking version
        if (defined('SNAB_VERSION')) {
            $snab_installed = SNAB_VERSION;
            $snab_db = get_option('snab_db_version', 'Not set');
            $versions['snab'] = array(
                'name' => 'SN Appointments',
                'installed' => $snab_installed,
                'db_version' => $snab_db,
                'status' => ($snab_installed === $snab_db || $snab_db === 'Not set') ? 'ok' : 'warning',
            );
        } else {
            $versions['snab'] = array(
                'name' => 'SN Appointments',
                'installed' => 'Not Installed',
                'db_version' => 'N/A',
                'status' => 'warning', // Optional plugin
            );
        }

        return $versions;
    }

    /**
     * Check database tables
     */
    private function check_tables() {
        global $wpdb;

        $tables = array();

        // Core BME tables
        $critical_tables = array(
            'bme_listings' => 'BME Listings',
            'bme_listing_summary' => 'BME Summary',
            'bme_media' => 'BME Media',
        );

        // Core MLD tables
        $mld_core_tables = array(
            'mld_saved_searches' => 'Saved Searches',
            'mld_cma_reports' => 'CMA Reports',
            'mld_chat_sessions' => 'Chat Sessions',
        );

        // Agent-Client tables (v6.34+)
        $agent_tables = array(
            'mld_agent_profiles' => 'Agent Profiles',
            'mld_agent_client_relationships' => 'Agent-Client Relations',
            'mld_shared_properties' => 'Shared Properties',
            'mld_user_types' => 'User Types',
        );

        // Public Analytics tables (v6.39+)
        $analytics_tables = array(
            'mld_public_sessions' => 'Analytics Sessions',
            'mld_public_events' => 'Analytics Events',
            'mld_analytics_hourly' => 'Analytics Hourly',
            'mld_analytics_daily' => 'Analytics Daily',
            'mld_realtime_presence' => 'Realtime Presence',
        );

        // Agent Notification tables (v6.43+)
        $notification_tables = array(
            'mld_agent_notification_preferences' => 'Agent Notif Prefs',
            'mld_agent_notification_log' => 'Agent Notif Log',
            'mld_client_app_opens' => 'Client App Opens',
        );

        // v6.68.15: Push Notification tables (v6.48+)
        $push_tables = array(
            'mld_device_tokens' => 'Device Tokens',
            'mld_push_notification_log' => 'Push Log',
            'mld_push_retry_queue' => 'Push Retry Queue',
        );

        // v6.68.15: Health Monitoring tables (v6.58+)
        $health_tables = array(
            'mld_health_history' => 'Health History',
            'mld_health_alerts' => 'Health Alerts',
        );

        // v6.68.15: BMN Schools tables (optional)
        $schools_tables = array(
            'bmn_schools' => 'BMN Schools',
            'bmn_school_districts' => 'BMN Districts',
        );

        // Combine all tables
        $all_tables = array_merge(
            $critical_tables,
            $mld_core_tables,
            $agent_tables,
            $analytics_tables,
            $notification_tables,
            $push_tables,
            $health_tables,
            $schools_tables
        );

        foreach ($all_tables as $table_suffix => $label) {
            $table_name = $wpdb->prefix . $table_suffix;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
                $tables[$label] = array(
                    'count' => (int) $count,
                    'status' => 'ok',
                );
            } else {
                // For newer optional tables, use warning instead of error
                // v6.68.15: Added push_tables, health_tables, schools_tables
                $is_optional = isset($agent_tables[$table_suffix]) ||
                               isset($analytics_tables[$table_suffix]) ||
                               isset($notification_tables[$table_suffix]) ||
                               isset($push_tables[$table_suffix]) ||
                               isset($health_tables[$table_suffix]) ||
                               isset($schools_tables[$table_suffix]);

                $tables[$label] = array(
                    'count' => 0,
                    'status' => $is_optional ? 'warning' : 'error',
                );
            }
        }

        return $tables;
    }

    /**
     * Get listing counts
     */
    private function get_listing_counts() {
        global $wpdb;

        $active = 0;
        $summary = 0;

        // Active listings count
        $listings_table = $wpdb->prefix . 'bme_listings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$listings_table}'")) {
            $active = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$listings_table}` WHERE standard_status = 'Active'"
            );
        }

        // Summary table count
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'")) {
            $summary = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$summary_table}` WHERE standard_status = 'Active'"
            );
        }

        return array(
            'active' => $active,
            'summary' => $summary,
        );
    }

    /**
     * Check cron jobs
     */
    private function check_cron_jobs() {
        $cron_hooks = array(
            // BME Crons
            // Note: bme_refresh_summary_hook removed in BME 4.0.14 - summary table now written in real-time
            'bme_cleanup_cache_hook' => 'BME Cache Cleanup',

            // Saved Search Alert Crons (Unified System)
            'mld_saved_search_instant' => 'Saved Search Alerts (Instant - 5 min)',
            'mld_saved_search_fifteen_min' => 'Saved Search Alerts (15 min)',
            'mld_saved_search_hourly' => 'Saved Search Alerts (Hourly)',
            'mld_saved_search_daily' => 'Saved Search Alerts (Daily - 9 AM)',
            'mld_saved_search_weekly' => 'Saved Search Alerts (Weekly - Mon 9 AM)',

            // Other MLD Crons
            'mld_chatbot_cache_cleanup' => 'MLD Chatbot Cache Cleanup',
            'mld_regenerate_sitemaps' => 'MLD Sitemap Regeneration',
            'mld_analytics_hourly_refresh' => 'MLD Analytics Refresh',
        );

        $cron_jobs = array();

        foreach ($cron_hooks as $hook => $label) {
            $next = wp_next_scheduled($hook);

            // Determine status - check if overdue (more than 5 minutes past scheduled time)
            $status = 'ok';
            if (!$next) {
                $status = 'warning';
            } elseif ($next < (time() - 300)) {
                // Overdue by more than 5 minutes
                $status = 'error';
            }

            $cron_jobs[$label] = array(
                'hook' => $hook,
                // Use wp_date() for proper timezone conversion (date_i18n doesn't convert cron timestamps correctly)
                'next_run' => $next ? wp_date('Y-m-d H:i:s', $next) : __('Not scheduled', 'mls-listings-display'),
                'next_run_relative' => $next ? human_time_diff($next) . ' ' . ($next > time() ? 'from now' : 'ago') : '-',
                'status' => $status,
            );
        }

        return $cron_jobs;
    }

    /**
     * Collect issues
     */
    private function collect_issues($data) {
        $issues = array();

        // BME not installed
        if (isset($data['versions']['bme']) && $data['versions']['bme']['status'] === 'error') {
            $issues[] = array(
                'severity' => 'error',
                'message' => __('Bridge MLS Extractor Pro is not installed or not activated.', 'mls-listings-display'),
                'action' => __('Install and activate BME plugin first.', 'mls-listings-display'),
            );
        }

        // Summary table out of sync
        if ($data['summary_sync']['needs_refresh']) {
            $issues[] = array(
                'severity' => 'warning',
                'message' => sprintf(
                    __('Summary table is out of sync (Active: %d, Summary: %d)', 'mls-listings-display'),
                    $data['listings']['active'],
                    $data['listings']['summary']
                ),
                'action' => __('Run a new BME extraction to rebuild the summary table, or use Emergency Repair below.', 'mls-listings-display'),
            );
        }

        // Missing tables
        foreach ($data['tables'] as $table => $info) {
            if ($info['status'] === 'error') {
                $issues[] = array(
                    'severity' => 'error',
                    'message' => sprintf(__('Database table "%s" is missing.', 'mls-listings-display'), $table),
                    'action' => __('Deactivate and reactivate the plugin to recreate tables.', 'mls-listings-display'),
                );
            }
        }

        // Cron not scheduled
        foreach ($data['cron_jobs'] as $job => $info) {
            if ($info['status'] === 'warning') {
                $issues[] = array(
                    'severity' => 'warning',
                    'message' => sprintf(__('Cron job "%s" is not scheduled.', 'mls-listings-display'), $job),
                    'action' => __('Deactivate and reactivate the plugin to reschedule cron jobs.', 'mls-listings-display'),
                );
            }
        }

        return $issues;
    }

    /**
     * Get push notification statistics for dashboard
     *
     * @since 6.49.3
     * @return array|null Push notification stats or null if not available
     */
    private function get_push_notification_stats() {
        // Check if push notifications class is available
        if (!class_exists('MLD_Push_Notifications')) {
            $push_path = MLD_PLUGIN_PATH . 'includes/notifications/class-mld-push-notifications.php';
            if (file_exists($push_path)) {
                require_once $push_path;
            } else {
                return null;
            }
        }

        $stats = array(
            'retry_queue' => array(
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
            ),
            'delivery' => array(
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'success_rate' => 0,
            ),
            'rate_limit' => null,
        );

        // Get retry queue stats
        if (method_exists('MLD_Push_Notifications', 'get_retry_queue_stats')) {
            $queue_stats = MLD_Push_Notifications::get_retry_queue_stats();
            if ($queue_stats) {
                $stats['retry_queue'] = $queue_stats;
            }
        }

        // Get delivery stats (last 24 hours)
        if (method_exists('MLD_Push_Notifications', 'get_delivery_stats')) {
            $delivery_stats = MLD_Push_Notifications::get_delivery_stats('day');
            if ($delivery_stats) {
                $stats['delivery'] = $delivery_stats;
            }
        }

        // Get rate limit stats
        if (method_exists('MLD_Push_Notifications', 'get_rate_limit_stats')) {
            $rate_stats = MLD_Push_Notifications::get_rate_limit_stats();
            if ($rate_stats) {
                $stats['rate_limit'] = $rate_stats;
            }
        }

        return $stats;
    }

    /**
     * AJAX: Process the push notification retry queue
     *
     * @since 6.49.3
     */
    public function ajax_process_retry_queue() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'mls-listings-display'));
        }

        // Check if push notifications class is available
        if (!class_exists('MLD_Push_Notifications')) {
            $push_path = MLD_PLUGIN_PATH . 'includes/notifications/class-mld-push-notifications.php';
            if (file_exists($push_path)) {
                require_once $push_path;
            } else {
                wp_send_json_error(__('Push notifications class not available.', 'mls-listings-display'));
                return;
            }
        }

        if (!method_exists('MLD_Push_Notifications', 'process_retry_queue')) {
            wp_send_json_error(__('Retry queue processing not available.', 'mls-listings-display'));
            return;
        }

        // Process the queue
        $result = MLD_Push_Notifications::process_retry_queue(100); // Process up to 100 items

        wp_send_json_success(array(
            'message' => sprintf(
                __('Processed %d items: %d succeeded, %d failed, %d requeued.', 'mls-listings-display'),
                $result['processed'],
                $result['succeeded'],
                $result['failed'],
                $result['requeued']
            ),
            'result' => $result,
        ));
    }

    /**
     * AJAX: Fix summary table
     *
     * Enhanced version with fallback for managed hosts (Kinsta) where
     * stored procedures may not work reliably.
     *
     * @since 6.13.20
     */
    public function ajax_fix_summary_table() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'mls-listings-display'));
        }

        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listings_table = $wpdb->prefix . 'bme_listings';

        // Check if BME plugin is available
        if (!function_exists('bme_pro')) {
            wp_send_json_error(__('BME plugin not available.', 'mls-listings-display'));
            return;
        }

        // Get count before refresh
        $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");
        $active_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$listings_table} WHERE standard_status = 'Active'");

        // Method 1: Try the standard BME stored procedure approach
        $plugin = bme_pro();
        $db = $plugin ? $plugin->get('db') : null;

        if ($db && method_exists($db, 'refresh_listing_summary')) {
            $result = $db->refresh_listing_summary();

            // Check if it worked
            $count_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");

            if ($count_after > 0 && $count_after >= ($active_count * 0.9)) {
                wp_send_json_success(sprintf(
                    __('Summary table refreshed successfully via stored procedure. %d records populated.', 'mls-listings-display'),
                    $count_after
                ));
                return;
            }

        }

        // Method 2: Direct SQL fallback (for Kinsta and managed hosts)
        $fallback_result = $this->refresh_summary_table_fallback();

        if ($fallback_result['success']) {
            wp_send_json_success(sprintf(
                __('Summary table refreshed via fallback method. %d records populated.', 'mls-listings-display'),
                $fallback_result['count']
            ));
        } else {
            wp_send_json_error(sprintf(
                __('Summary refresh failed: %s', 'mls-listings-display'),
                $fallback_result['error']
            ));
        }
    }

    /**
     * Fallback method to refresh summary table without stored procedures
     *
     * This is used on managed hosts like Kinsta where stored procedures
     * may not be reliable or may have permission issues.
     *
     * @since 6.13.20
     * @return array ['success' => bool, 'count' => int, 'error' => string]
     */
    private function refresh_summary_table_fallback() {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listings_table = $wpdb->prefix . 'bme_listings';
        $listings_archive = $wpdb->prefix . 'bme_listings_archive';
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $details_archive = $wpdb->prefix . 'bme_listing_details_archive';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $location_archive = $wpdb->prefix . 'bme_listing_location_archive';
        $financial_table = $wpdb->prefix . 'bme_listing_financial';
        $financial_archive = $wpdb->prefix . 'bme_listing_financial_archive';
        $features_table = $wpdb->prefix . 'bme_listing_features';
        $features_archive = $wpdb->prefix . 'bme_listing_features_archive';
        $media_table = $wpdb->prefix . 'bme_media';
        $virtual_tours_table = $wpdb->prefix . 'bme_virtual_tours';

        try {
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Clear the summary table
            $wpdb->query("TRUNCATE TABLE {$summary_table}");

            // Insert active listings from main tables
            $sql_active = "INSERT INTO {$summary_table} (
                listing_id, listing_key, mls_id, property_type, property_sub_type,
                standard_status, list_price, original_list_price, close_price, price_per_sqft,
                bedrooms_total, bathrooms_total, bathrooms_full, bathrooms_half,
                building_area_total, lot_size_acres, year_built,
                street_number, street_name, unit_number, city, state_or_province,
                postal_code, county, latitude, longitude,
                garage_spaces, has_pool, has_fireplace, has_basement, has_hoa, pet_friendly,
                main_photo_url, photo_count, virtual_tour_url,
                listing_contract_date, close_date, days_on_market, modification_timestamp
            )
            SELECT
                l.listing_id,
                l.listing_key,
                l.listing_key as mls_id,
                l.property_type,
                l.property_sub_type,
                l.standard_status,
                l.list_price,
                l.original_list_price,
                l.close_price,
                CASE WHEN d.building_area_total > 0 THEN l.list_price / d.building_area_total ELSE NULL END,
                d.bedrooms_total,
                d.bathrooms_total_decimal,
                d.bathrooms_full,
                d.bathrooms_half,
                d.building_area_total,
                d.lot_size_acres,
                d.year_built,
                loc.street_number,
                loc.street_name,
                loc.unit_number,
                loc.city,
                loc.state_or_province,
                loc.postal_code,
                loc.county_or_parish,
                loc.latitude,
                loc.longitude,
                d.garage_spaces,
                CASE WHEN f.pool_private_yn = 1 THEN 1 ELSE 0 END,
                CASE WHEN d.fireplace_yn = 1 THEN 1 ELSE 0 END,
                CASE WHEN d.basement IS NOT NULL AND d.basement != '' THEN 1 ELSE 0 END,
                CASE WHEN fin.association_yn = 1 THEN 1 ELSE 0 END,
                0,
                (SELECT media_url FROM {$media_table} m WHERE m.listing_id = l.listing_id AND m.media_category = 'Photo' ORDER BY m.order_index ASC LIMIT 1),
                (SELECT COUNT(*) FROM {$media_table} m WHERE m.listing_id = l.listing_id AND m.media_category = 'Photo'),
                (SELECT virtual_tour_link_1 FROM {$virtual_tours_table} vt WHERE vt.listing_id = l.listing_id LIMIT 1),
                l.listing_contract_date,
                l.close_date,
                COALESCE(l.mlspin_market_time_property, DATEDIFF(IFNULL(l.close_date, NOW()), l.listing_contract_date)),
                l.modification_timestamp
            FROM {$listings_table} l
            LEFT JOIN {$details_table} d ON l.listing_id = d.listing_id
            LEFT JOIN {$location_table} loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$financial_table} fin ON l.listing_id = fin.listing_id
            LEFT JOIN {$features_table} f ON l.listing_id = f.listing_id
            WHERE l.standard_status = 'Active'";

            $wpdb->query($sql_active);
            $active_inserted = $wpdb->rows_affected;

            if ($wpdb->last_error) {
                throw new Exception('Active listings insert failed: ' . $wpdb->last_error);
            }

            // Insert archive listings (Closed, Pending, Active Under Contract)
            $sql_archive = "INSERT INTO {$summary_table} (
                listing_id, listing_key, mls_id, property_type, property_sub_type,
                standard_status, list_price, original_list_price, close_price, price_per_sqft,
                bedrooms_total, bathrooms_total, bathrooms_full, bathrooms_half,
                building_area_total, lot_size_acres, year_built,
                street_number, street_name, unit_number, city, state_or_province,
                postal_code, county, latitude, longitude,
                garage_spaces, has_pool, has_fireplace, has_basement, has_hoa, pet_friendly,
                main_photo_url, photo_count, virtual_tour_url,
                listing_contract_date, close_date, days_on_market, modification_timestamp
            )
            SELECT
                l.listing_id,
                l.listing_key,
                l.listing_key as mls_id,
                l.property_type,
                l.property_sub_type,
                l.standard_status,
                l.list_price,
                l.original_list_price,
                l.close_price,
                CASE WHEN d.building_area_total > 0 THEN l.list_price / d.building_area_total ELSE NULL END,
                d.bedrooms_total,
                d.bathrooms_total_decimal,
                d.bathrooms_full,
                d.bathrooms_half,
                d.building_area_total,
                d.lot_size_acres,
                d.year_built,
                loc.street_number,
                loc.street_name,
                loc.unit_number,
                loc.city,
                loc.state_or_province,
                loc.postal_code,
                loc.county_or_parish,
                loc.latitude,
                loc.longitude,
                d.garage_spaces,
                CASE WHEN f.pool_private_yn = 1 THEN 1 ELSE 0 END,
                CASE WHEN d.fireplace_yn = 1 THEN 1 ELSE 0 END,
                CASE WHEN d.basement IS NOT NULL AND d.basement != '' THEN 1 ELSE 0 END,
                CASE WHEN fin.association_yn = 1 THEN 1 ELSE 0 END,
                0,
                (SELECT media_url FROM {$media_table} m WHERE m.listing_id = l.listing_id AND m.media_category = 'Photo' ORDER BY m.order_index ASC LIMIT 1),
                (SELECT COUNT(*) FROM {$media_table} m WHERE m.listing_id = l.listing_id AND m.media_category = 'Photo'),
                (SELECT virtual_tour_link_1 FROM {$virtual_tours_table} vt WHERE vt.listing_id = l.listing_id LIMIT 1),
                l.listing_contract_date,
                l.close_date,
                COALESCE(l.mlspin_market_time_property, DATEDIFF(IFNULL(l.close_date, NOW()), l.listing_contract_date)),
                l.modification_timestamp
            FROM {$listings_archive} l
            LEFT JOIN {$details_archive} d ON l.listing_id = d.listing_id
            LEFT JOIN {$location_archive} loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$financial_archive} fin ON l.listing_id = fin.listing_id
            LEFT JOIN {$features_archive} f ON l.listing_id = f.listing_id
            WHERE l.standard_status IN ('Closed', 'Pending', 'Active Under Contract')";

            $wpdb->query($sql_archive);
            $archive_inserted = $wpdb->rows_affected;

            if ($wpdb->last_error) {
                throw new Exception('Archive listings insert failed: ' . $wpdb->last_error);
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            $total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");

            return array(
                'success' => true,
                'count' => $total_count,
                'active' => $active_inserted,
                'archive' => $archive_inserted,
                'error' => ''
            );

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            return array(
                'success' => false,
                'count' => 0,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * AJAX: Refresh health data
     */
    public function ajax_refresh_health() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'mls-listings-display'));
        }

        $data = $this->get_health_data();
        wp_send_json_success($data);
    }

    /**
     * AJAX: Run a specific cron event
     */
    public function ajax_run_cron() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'mls-listings-display'));
        }

        $hook = isset($_POST['hook']) ? sanitize_text_field($_POST['hook']) : '';

        if (empty($hook)) {
            wp_send_json_error(__('No cron hook specified.', 'mls-listings-display'));
        }

        // Whitelist of allowed cron hooks
        $allowed_hooks = array(
            'bme_refresh_summary_hook',
            'bme_cleanup_cache_hook',
            'mld_saved_search_instant',
            'mld_saved_search_fifteen_min',
            'mld_saved_search_hourly',
            'mld_saved_search_daily',
            'mld_saved_search_weekly',
            'mld_chatbot_cache_cleanup',
            'mld_regenerate_sitemaps',
            'mld_analytics_hourly_refresh',
        );

        if (!in_array($hook, $allowed_hooks)) {
            wp_send_json_error(__('Invalid cron hook.', 'mls-listings-display'));
        }

        // Define recurrence schedules for each hook
        // Note: bme_refresh_summary_hook removed in BME 4.0.14 - summary table now written in real-time
        $hook_schedules = array(
            'bme_cleanup_cache_hook' => 'daily',
            'mld_saved_search_instant' => 'mld_five_minutes',
            'mld_saved_search_fifteen_min' => 'mld_fifteen_minutes',
            'mld_saved_search_hourly' => 'hourly',
            'mld_saved_search_daily' => 'daily',
            'mld_saved_search_weekly' => 'weekly',
            'mld_chatbot_cache_cleanup' => 'daily',
            'mld_regenerate_sitemaps' => 'daily',
            'mld_analytics_hourly_refresh' => 'hourly',
        );

        // Get current schedule info
        $current_timestamp = wp_next_scheduled($hook);
        $recurrence = isset($hook_schedules[$hook]) ? $hook_schedules[$hook] : 'hourly';

        // Unschedule the current event if it exists
        if ($current_timestamp) {
            wp_unschedule_event($current_timestamp, $hook);
        }

        // Run the cron event
        do_action($hook);

        // Reschedule the event for the future based on its recurrence
        $schedule_intervals = array(
            'mld_five_minutes' => 5 * MINUTE_IN_SECONDS,
            'mld_fifteen_minutes' => 15 * MINUTE_IN_SECONDS,
            'hourly' => HOUR_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            'weekly' => WEEK_IN_SECONDS,
        );

        $interval = isset($schedule_intervals[$recurrence]) ? $schedule_intervals[$recurrence] : HOUR_IN_SECONDS;
        $next_run = time() + $interval;

        // Schedule the next occurrence
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event($next_run, $recurrence, $hook);
        }

        // Get the new next scheduled time
        $new_next = wp_next_scheduled($hook);
        $next_run_local = $new_next ? wp_date('Y-m-d H:i:s', $new_next) : __('Not scheduled', 'mls-listings-display');
        $next_run_relative = $new_next ? human_time_diff($new_next) . ' ' . ($new_next > time() ? 'from now' : 'ago') : '-';

        wp_send_json_success(array(
            'message' => sprintf(__('Cron "%s" executed successfully.', 'mls-listings-display'), $hook),
            'next_run' => $next_run_local,
            'next_run_relative' => $next_run_relative,
            'status' => $new_next && $new_next > time() ? 'ok' : 'warning'
        ));
    }

    /**
     * AJAX: Run all overdue cron events
     */
    public function ajax_run_all_overdue_crons() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'mls-listings-display'));
        }

        $cron_hooks = array(
            // Note: bme_refresh_summary_hook removed in BME 4.0.14 - summary table now written in real-time
            'bme_cleanup_cache_hook',
            'mld_saved_search_instant',
            'mld_saved_search_fifteen_min',
            'mld_saved_search_hourly',
            'mld_saved_search_daily',
            'mld_saved_search_weekly',
            'mld_chatbot_cache_cleanup',
            'mld_regenerate_sitemaps',
            'mld_analytics_hourly_refresh',
        );

        $executed = 0;
        $current_time = time();

        foreach ($cron_hooks as $hook) {
            $next = wp_next_scheduled($hook);
            // Run if scheduled and overdue (more than 60 seconds past)
            if ($next && $next < ($current_time - 60)) {
                $this->run_and_reschedule_cron($hook);
                $executed++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Executed %d overdue cron events.', 'mls-listings-display'), $executed),
            'executed' => $executed
        ));
    }

    /**
     * Helper: Run a cron event and reschedule it
     *
     * @param string $hook The cron hook to run
     * @return bool True on success
     */
    private function run_and_reschedule_cron($hook) {
        // Define recurrence schedules for each hook
        // Note: bme_refresh_summary_hook removed in BME 4.0.14 - summary table now written in real-time
        $hook_schedules = array(
            'bme_cleanup_cache_hook' => 'daily',
            'mld_saved_search_instant' => 'mld_five_minutes',
            'mld_saved_search_fifteen_min' => 'mld_fifteen_minutes',
            'mld_saved_search_hourly' => 'hourly',
            'mld_saved_search_daily' => 'daily',
            'mld_saved_search_weekly' => 'weekly',
            'mld_chatbot_cache_cleanup' => 'daily',
            'mld_regenerate_sitemaps' => 'daily',
            'mld_analytics_hourly_refresh' => 'hourly',
        );

        // Get current schedule info
        $current_timestamp = wp_next_scheduled($hook);
        $recurrence = isset($hook_schedules[$hook]) ? $hook_schedules[$hook] : 'hourly';

        // Unschedule the current event if it exists
        if ($current_timestamp) {
            wp_unschedule_event($current_timestamp, $hook);
        }

        // Run the cron event
        do_action($hook);

        // Reschedule the event for the future based on its recurrence
        $schedule_intervals = array(
            'mld_five_minutes' => 5 * MINUTE_IN_SECONDS,
            'mld_fifteen_minutes' => 15 * MINUTE_IN_SECONDS,
            'hourly' => HOUR_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            'weekly' => WEEK_IN_SECONDS,
        );

        $interval = isset($schedule_intervals[$recurrence]) ? $schedule_intervals[$recurrence] : HOUR_IN_SECONDS;
        $next_run = time() + $interval;

        // Schedule the next occurrence
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event($next_run, $recurrence, $hook);
        }

        return true;
    }
}

// Initialize - use 'init' hook since this file is loaded after plugins_loaded
add_action('init', function() {
    if (is_admin()) {
        MLD_Health_Dashboard::get_instance();
    }
});
