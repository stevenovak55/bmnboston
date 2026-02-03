<?php
/**
 * Admin Activity Log View
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$current_level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
$current_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
?>
<div class="wrap bmn-schools-admin">
    <h1><?php esc_html_e('Activity Log', 'bmn-schools'); ?></h1>

    <!-- Stats Summary -->
    <div class="bmn-log-stats">
        <div class="bmn-stat-box">
            <span class="bmn-stat-number"><?php echo esc_html(number_format($log_stats['total'])); ?></span>
            <span class="bmn-stat-label"><?php esc_html_e('Total Entries', 'bmn-schools'); ?></span>
        </div>
        <?php if (!empty($log_stats['by_level'])) : ?>
            <?php foreach ($log_stats['by_level'] as $level => $count) : ?>
                <div class="bmn-stat-box bmn-stat-<?php echo esc_attr($level); ?>">
                    <span class="bmn-stat-number"><?php echo esc_html(number_format($count)); ?></span>
                    <span class="bmn-stat-label"><?php echo esc_html(ucfirst($level)); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="bmn-stat-box bmn-stat-errors-today">
            <span class="bmn-stat-number"><?php echo esc_html($log_stats['errors_today']); ?></span>
            <span class="bmn-stat-label"><?php esc_html_e('Errors Today', 'bmn-schools'); ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bmn-log-filters">
        <form method="get">
            <input type="hidden" name="page" value="bmn-schools-logs">

            <label for="filter-level"><?php esc_html_e('Level:', 'bmn-schools'); ?></label>
            <select name="level" id="filter-level">
                <option value=""><?php esc_html_e('All Levels', 'bmn-schools'); ?></option>
                <option value="debug" <?php selected($current_level, 'debug'); ?>><?php esc_html_e('Debug', 'bmn-schools'); ?></option>
                <option value="info" <?php selected($current_level, 'info'); ?>><?php esc_html_e('Info', 'bmn-schools'); ?></option>
                <option value="warning" <?php selected($current_level, 'warning'); ?>><?php esc_html_e('Warning', 'bmn-schools'); ?></option>
                <option value="error" <?php selected($current_level, 'error'); ?>><?php esc_html_e('Error', 'bmn-schools'); ?></option>
            </select>

            <label for="filter-type"><?php esc_html_e('Type:', 'bmn-schools'); ?></label>
            <select name="type" id="filter-type">
                <option value=""><?php esc_html_e('All Types', 'bmn-schools'); ?></option>
                <option value="import" <?php selected($current_type, 'import'); ?>><?php esc_html_e('Import', 'bmn-schools'); ?></option>
                <option value="api_call" <?php selected($current_type, 'api_call'); ?>><?php esc_html_e('API Call', 'bmn-schools'); ?></option>
                <option value="sync" <?php selected($current_type, 'sync'); ?>><?php esc_html_e('Sync', 'bmn-schools'); ?></option>
                <option value="error" <?php selected($current_type, 'error'); ?>><?php esc_html_e('Error', 'bmn-schools'); ?></option>
                <option value="admin" <?php selected($current_type, 'admin'); ?>><?php esc_html_e('Admin', 'bmn-schools'); ?></option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'bmn-schools'); ?></button>

            <?php if ($current_level || $current_type) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bmn-schools-logs')); ?>" class="button">
                    <?php esc_html_e('Clear Filters', 'bmn-schools'); ?>
                </a>
            <?php endif; ?>
        </form>

        <div class="bmn-log-actions">
            <button type="button" class="button" id="bmn-clear-old-logs">
                <?php esc_html_e('Clear Logs Older Than 30 Days', 'bmn-schools'); ?>
            </button>
            <button type="button" class="button" id="bmn-refresh-logs">
                <?php esc_html_e('Refresh', 'bmn-schools'); ?>
            </button>
        </div>
    </div>

    <!-- Log Entries -->
    <div class="bmn-log-table-wrapper">
        <?php if (empty($logs)) : ?>
            <div class="bmn-notice bmn-notice-info">
                <p><?php esc_html_e('No log entries found.', 'bmn-schools'); ?></p>
            </div>
        <?php else : ?>
            <table class="bmn-table bmn-log-table">
                <thead>
                    <tr>
                        <th width="150"><?php esc_html_e('Timestamp', 'bmn-schools'); ?></th>
                        <th width="80"><?php esc_html_e('Level', 'bmn-schools'); ?></th>
                        <th width="80"><?php esc_html_e('Type', 'bmn-schools'); ?></th>
                        <th width="100"><?php esc_html_e('Source', 'bmn-schools'); ?></th>
                        <th><?php esc_html_e('Message', 'bmn-schools'); ?></th>
                        <th width="80"><?php esc_html_e('Duration', 'bmn-schools'); ?></th>
                        <th width="50"><?php esc_html_e('Details', 'bmn-schools'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) : ?>
                        <tr class="bmn-log-row bmn-log-level-<?php echo esc_attr($log->level); ?>">
                            <td>
                                <span class="bmn-log-time" title="<?php echo esc_attr($log->timestamp); ?>">
                                    <?php echo esc_html(date('M j, g:i:s A', strtotime($log->timestamp))); ?>
                                </span>
                            </td>
                            <td><?php echo BMN_Schools_Admin::get_level_badge($log->level); ?></td>
                            <td><code><?php echo esc_html($log->type); ?></code></td>
                            <td><?php echo esc_html($log->source ?: '-'); ?></td>
                            <td class="bmn-log-message">
                                <?php echo esc_html($log->message); ?>
                            </td>
                            <td>
                                <?php
                                if ($log->duration_ms) {
                                    if ($log->duration_ms >= 1000) {
                                        echo esc_html(number_format($log->duration_ms / 1000, 2) . 's');
                                    } else {
                                        echo esc_html($log->duration_ms . 'ms');
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($log->context) : ?>
                                    <button type="button" class="button button-small bmn-view-context"
                                            data-context="<?php echo esc_attr($log->context); ?>">
                                        <span class="dashicons dashicons-info-outline"></span>
                                    </button>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Context Modal -->
    <div id="bmn-context-modal" class="bmn-modal" style="display: none;">
        <div class="bmn-modal-content">
            <div class="bmn-modal-header">
                <h3><?php esc_html_e('Log Context', 'bmn-schools'); ?></h3>
                <button type="button" class="bmn-modal-close">&times;</button>
            </div>
            <div class="bmn-modal-body">
                <pre id="bmn-context-content"></pre>
            </div>
        </div>
    </div>
</div>

<style>
.bmn-log-stats {
    display: flex;
    gap: 15px;
    margin: 20px 0;
}

.bmn-stat-box {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 15px 25px;
    text-align: center;
}

.bmn-stat-number {
    display: block;
    font-size: 24px;
    font-weight: 600;
    color: #1e1e1e;
}

.bmn-stat-label {
    display: block;
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
}

.bmn-stat-error .bmn-stat-number,
.bmn-stat-errors-today .bmn-stat-number {
    color: #dc3545;
}

.bmn-stat-warning .bmn-stat-number {
    color: #ffc107;
}

.bmn-stat-info .bmn-stat-number {
    color: #17a2b8;
}

.bmn-log-filters {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 15px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-bottom: 20px;
}

.bmn-log-filters label {
    margin-right: 5px;
    margin-left: 15px;
}

.bmn-log-filters label:first-child {
    margin-left: 0;
}

.bmn-log-filters select {
    margin-right: 10px;
}

.bmn-log-table-wrapper {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.bmn-table {
    width: 100%;
    border-collapse: collapse;
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
    position: sticky;
    top: 0;
}

.bmn-log-row:hover {
    background: #f9f9f9;
}

.bmn-log-level-error {
    background: #fff5f5;
}

.bmn-log-level-warning {
    background: #fffdf5;
}

.bmn-log-message {
    max-width: 400px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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

.bmn-badge-info {
    background: #d1ecf1;
    color: #0c5460;
}

.bmn-badge-secondary {
    background: #e2e3e5;
    color: #383d41;
}

.bmn-notice {
    padding: 15px;
    margin: 15px;
}

.bmn-notice-info {
    background: #d1ecf1;
    color: #0c5460;
    border-radius: 4px;
}

.bmn-view-context {
    padding: 2px 5px !important;
}

.bmn-view-context .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
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
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.bmn-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
}

.bmn-modal-header h3 {
    margin: 0;
}

.bmn-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #646970;
}

.bmn-modal-close:hover {
    color: #1e1e1e;
}

.bmn-modal-body {
    padding: 20px;
    overflow: auto;
}

.bmn-modal-body pre {
    background: #f6f7f7;
    padding: 15px;
    border-radius: 4px;
    overflow: auto;
    max-height: 400px;
    font-size: 12px;
    line-height: 1.4;
}
</style>

<script>
jQuery(document).ready(function($) {
    // View context button
    $('.bmn-view-context').on('click', function() {
        var context = $(this).data('context');
        try {
            var formatted = JSON.stringify(JSON.parse(context), null, 2);
            $('#bmn-context-content').text(formatted);
        } catch (e) {
            $('#bmn-context-content').text(context);
        }
        $('#bmn-context-modal').show();
    });

    // Close modal
    $('.bmn-modal-close, .bmn-modal').on('click', function(e) {
        if (e.target === this) {
            $('#bmn-context-modal').hide();
        }
    });

    // Clear old logs
    $('#bmn-clear-old-logs').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Are you sure you want to clear logs older than 30 days?', 'bmn-schools')); ?>')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('<?php echo esc_js(__('Clearing...', 'bmn-schools')); ?>');

        $.post(ajaxurl, {
            action: 'bmn_schools_clear_logs',
            nonce: '<?php echo wp_create_nonce('bmn_schools_admin'); ?>',
            days: 30
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert('<?php echo esc_js(__('Error clearing logs.', 'bmn-schools')); ?>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('<?php echo esc_js(__('Clear Logs Older Than 30 Days', 'bmn-schools')); ?>');
        });
    });

    // Refresh logs
    $('#bmn-refresh-logs').on('click', function() {
        location.reload();
    });
});
</script>
