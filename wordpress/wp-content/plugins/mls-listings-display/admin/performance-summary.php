<?php
/**
 * Performance Summary Dashboard
 * Quick overview of optimization status
 */

// Load WordPress
$wp_load_path = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
if (!file_exists($wp_load_path)) {
    wp_die('Error: Cannot find wp-load.php', 'Configuration Error', ['response' => 500]);
}
require_once($wp_load_path);

// Check admin permission
if (!current_user_can('manage_options')) {
    wp_die('You must be logged in as an administrator.', 'Access Denied', ['response' => 403]);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>MLD Performance Summary</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; color: white; margin-bottom: 30px; }
        .header h1 { font-size: 2.5em; margin: 0; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .header p { font-size: 1.2em; opacity: 0.95; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,0,0,0.15); }
        .metric { display: flex; justify-content: space-between; align-items: center; margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 5px; }
        .metric-label { font-weight: 600; color: #495057; }
        .metric-value { font-size: 1.2em; font-weight: bold; }
        .status-excellent { color: #28a745; }
        .status-good { color: #17a2b8; }
        .status-warning { color: #ffc107; }
        .status-poor { color: #dc3545; }
        .progress-bar { width: 100%; height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.5s; }
        .feature { padding: 8px 12px; margin: 5px; display: inline-block; background: #e7f3ff; color: #007cba; border-radius: 5px; font-size: 0.9em; }
        .feature.enabled { background: #d4edda; color: #155724; }
        .feature.disabled { background: #f8d7da; color: #721c24; }
        h2 { color: #333; border-bottom: 2px solid #007cba; padding-bottom: 10px; margin-top: 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
        .summary-item { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .summary-number { font-size: 2em; font-weight: bold; color: #007cba; }
        .summary-label { color: #6c757d; margin-top: 5px; }
        .action-buttons { margin-top: 20px; text-align: center; }
        .button { display: inline-block; padding: 12px 30px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px; transition: background 0.3s; }
        .button:hover { background: #005a87; }
        .score-circle { width: 150px; height: 150px; margin: 20px auto; position: relative; }
        .score-circle svg { transform: rotate(-90deg); }
        .score-circle .score-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 2.5em; font-weight: bold; }
        .score-circle .score-label { position: absolute; top: 65%; left: 50%; transform: translateX(-50%); font-size: 0.9em; color: #6c757d; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🚀 MLS Performance Dashboard</h1>
        <p>Real-time optimization status and metrics</p>
    </div>

    <?php
    global $wpdb;

    // Calculate overall performance score
    $score = 0;
    $max_score = 100;

    // Check cache status (20 points)
    $cache_enabled = defined('MLD_ENABLE_QUERY_CACHE') && MLD_ENABLE_QUERY_CACHE;
    if ($cache_enabled) $score += 20;

    // Check monitoring status (10 points)
    $monitoring_enabled = defined('MLD_PERFORMANCE_MONITORING') && MLD_PERFORMANCE_MONITORING;
    if ($monitoring_enabled) $score += 10;

    // Check indexes (30 points)
    $bme_indexes = $wpdb->get_var("SELECT COUNT(DISTINCT Key_name) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = '{$wpdb->prefix}bme_listings'");
    if ($bme_indexes > 15) $score += 30;
    elseif ($bme_indexes > 10) $score += 20;
    elseif ($bme_indexes > 5) $score += 10;

    // Check memory usage (20 points)
    $memory_usage = memory_get_usage(true) / 1048576;
    if ($memory_usage < 50) $score += 20;
    elseif ($memory_usage < 100) $score += 15;
    elseif ($memory_usage < 150) $score += 10;

    // Check database size (20 points)
    $db_size = $wpdb->get_var("SELECT SUM(data_length + index_length) / 1048576 FROM information_schema.tables WHERE table_schema = DATABASE()");
    if ($db_size < 500) $score += 20;
    elseif ($db_size < 1000) $score += 15;
    elseif ($db_size < 2000) $score += 10;

    // Determine status
    if ($score >= 90) {
        $status = 'Excellent';
        $status_class = 'status-excellent';
        $status_emoji = '🎯';
    } elseif ($score >= 70) {
        $status = 'Good';
        $status_class = 'status-good';
        $status_emoji = '✅';
    } elseif ($score >= 50) {
        $status = 'Fair';
        $status_class = 'status-warning';
        $status_emoji = '⚠️';
    } else {
        $status = 'Needs Attention';
        $status_class = 'status-poor';
        $status_emoji = '🔧';
    }
    ?>

    <div class="grid">
        <!-- Overall Score Card -->
        <div class="card">
            <h2>Performance Score</h2>
            <div class="score-circle">
                <svg width="150" height="150">
                    <circle cx="75" cy="75" r="65" stroke="#e9ecef" stroke-width="10" fill="none"/>
                    <circle cx="75" cy="75" r="65" stroke="<?php echo esc_attr($score >= 70 ? '#28a745' : ($score >= 50 ? '#ffc107' : '#dc3545')); ?>"
                            stroke-width="10" fill="none"
                            stroke-dasharray="<?php echo 408 * $score / 100; ?> 408"
                            stroke-linecap="round"/>
                </svg>
                <div class="score-text <?php echo esc_attr($status_class); ?>"><?php echo esc_html($score); ?></div>
                <div class="score-label"><?php echo esc_html($status_emoji . ' ' . $status); ?></div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo esc_attr($score); ?>%"></div>
            </div>
        </div>

        <!-- System Metrics Card -->
        <div class="card">
            <h2>System Metrics</h2>
            <div class="metric">
                <span class="metric-label">Memory Usage:</span>
                <span class="metric-value <?php echo esc_attr($memory_usage < 50 ? 'status-excellent' : ($memory_usage < 100 ? 'status-good' : 'status-warning')); ?>">
                    <?php echo esc_html(number_format($memory_usage, 1)); ?> MB
                </span>
            </div>
            <div class="metric">
                <span class="metric-label">Database Size:</span>
                <span class="metric-value <?php echo esc_attr($db_size < 500 ? 'status-excellent' : ($db_size < 1000 ? 'status-good' : 'status-warning')); ?>">
                    <?php echo esc_html(number_format($db_size, 1)); ?> MB
                </span>
            </div>
            <div class="metric">
                <span class="metric-label">PHP Version:</span>
                <span class="metric-value status-good"><?php echo PHP_VERSION; ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">MySQL Version:</span>
                <span class="metric-value status-good"><?php echo esc_html($wpdb->db_version()); ?></span>
            </div>
        </div>

        <!-- Features Status Card -->
        <div class="card">
            <h2>Optimization Features</h2>
            <div style="margin: 20px 0;">
                <span class="feature <?php echo esc_attr($cache_enabled ? 'enabled' : 'disabled'); ?>">
                    <?php echo esc_html($cache_enabled ? '✅' : '❌'); ?> Query Cache
                </span>
                <span class="feature <?php echo esc_attr($monitoring_enabled ? 'enabled' : 'disabled'); ?>">
                    <?php echo esc_html($monitoring_enabled ? '✅' : '❌'); ?> Performance Monitoring
                </span>
                <span class="feature enabled">✅ Lazy Loading</span>
                <span class="feature enabled">✅ Database Indexes</span>
                <span class="feature <?php echo defined('WP_DEBUG') && !WP_DEBUG ? 'enabled' : 'disabled'; ?>">
                    <?php echo defined('WP_DEBUG') && !WP_DEBUG ? '✅' : '⚠️'; ?> Production Mode
                </span>
            </div>
        </div>
    </div>

    <!-- Database Statistics -->
    <div class="card" style="margin-top: 20px;">
        <h2>Database Statistics</h2>
        <div class="summary-grid">
            <?php
            $listings_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings");
            $archive_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings_archive");
            $total_indexes = $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(table_name, '.', index_name)) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name LIKE '%bme%'");
            ?>
            <div class="summary-item">
                <div class="summary-number"><?php echo esc_html(number_format($listings_count)); ?></div>
                <div class="summary-label">Active Listings</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?php echo esc_html(number_format($archive_count)); ?></div>
                <div class="summary-label">Archived Listings</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?php echo esc_html($total_indexes); ?></div>
                <div class="summary-label">Database Indexes</div>
            </div>
        </div>
    </div>

    <!-- Cache Performance -->
    <?php if (class_exists('MLD_Query_Cache')): ?>
    <div class="card" style="margin-top: 20px;">
        <h2>Cache Performance</h2>
        <?php
        $cache_stats = MLD_Query_Cache::getStats();
        $hit_ratio = MLD_Query_Cache::getHitRatio();
        ?>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-number <?php echo esc_attr($hit_ratio > 80 ? 'status-excellent' : ($hit_ratio > 60 ? 'status-good' : 'status-warning')); ?>">
                    <?php echo esc_html(number_format($hit_ratio, 1)); ?>%
                </div>
                <div class="summary-label">Hit Ratio</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?php echo esc_html($cache_stats['hits']); ?></div>
                <div class="summary-label">Cache Hits</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?php echo esc_html($cache_stats['misses']); ?></div>
                <div class="summary-label">Cache Misses</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recommendations -->
    <div class="card" style="margin-top: 20px;">
        <h2>Optimization Recommendations</h2>
        <?php if ($score >= 90): ?>
            <p style="color: #28a745; font-size: 1.2em;">✨ Your system is fully optimized! No actions needed.</p>
        <?php else: ?>
            <ul style="line-height: 1.8;">
                <?php if (!$cache_enabled): ?>
                    <li>Enable query caching by adding <code>define('MLD_ENABLE_QUERY_CACHE', true);</code> to wp-config.php</li>
                <?php endif; ?>
                <?php if (!$monitoring_enabled): ?>
                    <li>Enable performance monitoring by adding <code>define('MLD_PERFORMANCE_MONITORING', true);</code> to wp-config.php</li>
                <?php endif; ?>
                <?php if ($memory_usage > 100): ?>
                    <li>Memory usage is high. Consider optimizing plugins or increasing PHP memory limit.</li>
                <?php endif; ?>
                <?php if ($db_size > 1000): ?>
                    <li>Database is large. Consider archiving old data or optimizing tables.</li>
                <?php endif; ?>
                <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                    <li>Disable debug mode in production by setting <code>define('WP_DEBUG', false);</code> in wp-config.php</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="<?php echo admin_url('admin.php?page=mld-performance'); ?>" class="button">Open Admin Dashboard</a>
    </div>
</div>

<script>
// Animate score on load
document.addEventListener('DOMContentLoaded', function() {
    const scoreText = document.querySelector('.score-text');
    const targetScore = <?php echo intval($score); ?>;
    let currentScore = 0;

    const interval = setInterval(function() {
        if (currentScore < targetScore) {
            currentScore += 2;
            if (currentScore > targetScore) currentScore = targetScore;
            scoreText.textContent = currentScore;
        } else {
            clearInterval(interval);
        }
    }, 20);
});
</script>
</body>
</html>