<?php
/**
 * Recently Viewed Properties Admin Page
 *
 * Provides admin dashboard for viewing property view analytics:
 * - Recent property views across all users
 * - Most viewed properties (view counts)
 * - Filterable by date range
 *
 * @package MLS_Listings_Display
 * @since 6.57.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MLD_Recently_Viewed_Admin {

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
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            __('Recently Viewed', 'mls-listings-display'),
            __('Recently Viewed', 'mls-listings-display'),
            'manage_options',
            'mld-recently-viewed',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue styles
     */
    public function enqueue_styles($hook) {
        if (strpos($hook, 'mld-recently-viewed') === false) {
            return;
        }

        wp_add_inline_style('wp-admin', $this->get_inline_styles());
    }

    /**
     * Get inline styles
     */
    private function get_inline_styles() {
        return '
            .mld-rv-dashboard { max-width: 1400px; }
            .mld-rv-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; }
            .mld-rv-card-header { padding: 15px 20px; border-bottom: 1px solid #ccd0d4; background: #f9f9f9; display: flex; justify-content: space-between; align-items: center; }
            .mld-rv-card-header h2 { margin: 0; font-size: 16px; }
            .mld-rv-card-body { padding: 20px; }
            .mld-rv-table { width: 100%; border-collapse: collapse; }
            .mld-rv-table th, .mld-rv-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
            .mld-rv-table th { background: #f5f5f5; font-weight: 600; }
            .mld-rv-table tr:hover { background: #f9f9f9; }
            .mld-rv-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
            .mld-rv-stat { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center; }
            .mld-rv-stat-value { font-size: 32px; font-weight: 700; color: #0073aa; margin-bottom: 5px; }
            .mld-rv-stat-label { font-size: 13px; color: #666; }
            .mld-rv-filters { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
            .mld-rv-filters label { font-weight: 600; margin-right: 5px; }
            .mld-rv-filters select, .mld-rv-filters input { padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; }
            .mld-rv-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .mld-rv-badge.ios { background: #e3f2fd; color: #1565c0; }
            .mld-rv-badge.web { background: #f3e5f5; color: #7b1fa2; }
            .mld-rv-badge.admin { background: #fce4ec; color: #c62828; }
            .mld-rv-property-link { color: #0073aa; text-decoration: none; }
            .mld-rv-property-link:hover { text-decoration: underline; }
            .mld-rv-user-link { color: #666; text-decoration: none; }
            .mld-rv-user-link:hover { color: #0073aa; }
            .mld-rv-tab-nav { display: flex; border-bottom: 1px solid #ccc; margin-bottom: 20px; }
            .mld-rv-tab-nav a { padding: 10px 20px; text-decoration: none; color: #666; border-bottom: 2px solid transparent; margin-bottom: -1px; }
            .mld-rv-tab-nav a.active { color: #0073aa; border-bottom-color: #0073aa; font-weight: 600; }
            .mld-rv-tab-content { display: none; }
            .mld-rv-tab-content.active { display: block; }
            .mld-rv-empty { text-align: center; padding: 40px; color: #666; }
            .mld-rv-pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
            .mld-rv-location { color: #666; font-size: 12px; white-space: nowrap; }
            .mld-rv-location .dashicons { color: #0073aa; margin-right: 2px; }
        ';
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        global $wpdb;

        // Get filter parameters
        $days = isset($_GET['days']) ? absint($_GET['days']) : 7;
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'recent';
        $page_num = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 50;
        $offset = ($page_num - 1) * $per_page;

        // Use WordPress timezone (Rule 13)
        $cutoff_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        $viewed_table = $wpdb->prefix . 'mld_recently_viewed_properties';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get summary statistics
        $stats = $this->get_stats($cutoff_date);

        // Get data based on tab
        if ($tab === 'most-viewed') {
            $data = $this->get_most_viewed_properties($cutoff_date, $per_page, $offset);
        } else {
            $data = $this->get_recent_views($cutoff_date, $per_page, $offset);
        }

        ?>
        <div class="wrap mld-rv-dashboard">
            <h1><?php _e('Recently Viewed Properties', 'mls-listings-display'); ?></h1>
            <p class="description"><?php _e('Track which properties users are viewing across the site.', 'mls-listings-display'); ?></p>

            <!-- Summary Stats -->
            <div class="mld-rv-summary">
                <div class="mld-rv-stat">
                    <div class="mld-rv-stat-value"><?php echo number_format($stats['total_views']); ?></div>
                    <div class="mld-rv-stat-label"><?php printf(__('Total Views (%d days)', 'mls-listings-display'), $days); ?></div>
                </div>
                <div class="mld-rv-stat">
                    <div class="mld-rv-stat-value"><?php echo number_format($stats['unique_users']); ?></div>
                    <div class="mld-rv-stat-label"><?php _e('Unique Users', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-rv-stat">
                    <div class="mld-rv-stat-value"><?php echo number_format($stats['unique_properties']); ?></div>
                    <div class="mld-rv-stat-label"><?php _e('Unique Properties', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-rv-stat">
                    <div class="mld-rv-stat-value"><?php echo $stats['unique_users'] > 0 ? number_format($stats['total_views'] / $stats['unique_users'], 1) : 0; ?></div>
                    <div class="mld-rv-stat-label"><?php _e('Avg Views/User', 'mls-listings-display'); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" class="mld-rv-filters">
                <input type="hidden" name="page" value="mld-recently-viewed">
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">

                <div>
                    <label for="days"><?php _e('Time Period:', 'mls-listings-display'); ?></label>
                    <select name="days" id="days" onchange="this.form.submit()">
                        <option value="1" <?php selected($days, 1); ?>><?php _e('Last 24 hours', 'mls-listings-display'); ?></option>
                        <option value="3" <?php selected($days, 3); ?>><?php _e('Last 3 days', 'mls-listings-display'); ?></option>
                        <option value="7" <?php selected($days, 7); ?>><?php _e('Last 7 days', 'mls-listings-display'); ?></option>
                        <option value="14" <?php selected($days, 14); ?>><?php _e('Last 14 days', 'mls-listings-display'); ?></option>
                        <option value="30" <?php selected($days, 30); ?>><?php _e('Last 30 days', 'mls-listings-display'); ?></option>
                    </select>
                </div>
            </form>

            <!-- Tab Navigation -->
            <div class="mld-rv-tab-nav">
                <a href="<?php echo admin_url('admin.php?page=mld-recently-viewed&tab=recent&days=' . $days); ?>"
                   class="<?php echo $tab === 'recent' ? 'active' : ''; ?>">
                    <?php _e('Recent Views', 'mls-listings-display'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=mld-recently-viewed&tab=most-viewed&days=' . $days); ?>"
                   class="<?php echo $tab === 'most-viewed' ? 'active' : ''; ?>">
                    <?php _e('Most Viewed Properties', 'mls-listings-display'); ?>
                </a>
            </div>

            <!-- Recent Views Tab -->
            <div class="mld-rv-tab-content <?php echo $tab === 'recent' ? 'active' : ''; ?>">
                <div class="mld-rv-card">
                    <div class="mld-rv-card-header">
                        <h2><?php _e('Recent Property Views', 'mls-listings-display'); ?></h2>
                        <span><?php printf(__('%d total records', 'mls-listings-display'), $data['total']); ?></span>
                    </div>
                    <div class="mld-rv-card-body">
                        <?php if (empty($data['items'])): ?>
                            <div class="mld-rv-empty">
                                <p><?php _e('No property views recorded in this time period.', 'mls-listings-display'); ?></p>
                            </div>
                        <?php else: ?>
                            <table class="mld-rv-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Viewed At', 'mls-listings-display'); ?></th>
                                        <th><?php _e('User', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Location', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Property', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Price', 'mls-listings-display'); ?></th>
                                        <th><?php _e('MLS #', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Platform', 'mls-listings-display'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['items'] as $item): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                // Format in WordPress timezone (Rule 13)
                                                // Database stores in WP timezone, so create DateTime with that timezone
                                                $date = new DateTime($item->viewed_at, wp_timezone());
                                                echo wp_date('M j, Y g:i A', $date->getTimestamp());
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($item->user_id > 0): ?>
                                                    <a href="<?php echo admin_url('user-edit.php?user_id=' . $item->user_id); ?>"
                                                       class="mld-rv-user-link">
                                                        <?php echo esc_html($item->display_name ?: 'User #' . $item->user_id); ?>
                                                    </a>
                                                <?php elseif (!empty($item->ip_address)): ?>
                                                    <span class="mld-rv-anonymous" title="<?php esc_attr_e('Anonymous visitor', 'mls-listings-display'); ?>">
                                                        <span class="dashicons dashicons-admin-users" style="opacity: 0.5;"></span>
                                                        <?php echo esc_html($item->ip_address); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="mld-rv-anonymous" style="color: #999;">
                                                        <?php _e('Anonymous', 'mls-listings-display'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                // Get location from IP for anonymous users, or user's last known location
                                                $location = $this->get_user_location($item);
                                                if (!empty($location)):
                                                ?>
                                                    <span class="mld-rv-location" title="<?php echo esc_attr($location['full']); ?>">
                                                        <span class="dashicons dashicons-location" style="font-size: 14px; vertical-align: middle;"></span>
                                                        <?php echo esc_html($location['short']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #999;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($item->street_address)): ?>
                                                    <a href="<?php echo home_url('/property/' . $item->listing_id . '/'); ?>"
                                                       target="_blank" class="mld-rv-property-link">
                                                        <?php echo esc_html($item->street_address . ', ' . $item->city); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #999;"><?php _e('Property data unavailable', 'mls-listings-display'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($item->list_price)): ?>
                                                    <strong>$<?php echo number_format($item->list_price); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: #999;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <code><?php echo esc_html($item->listing_id); ?></code>
                                            </td>
                                            <td>
                                                <span class="mld-rv-badge <?php echo esc_attr($item->platform); ?>">
                                                    <?php echo strtoupper(esc_html($item->platform)); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php $this->render_pagination($data['total'], $per_page, $page_num, $tab, $days); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Most Viewed Tab -->
            <div class="mld-rv-tab-content <?php echo $tab === 'most-viewed' ? 'active' : ''; ?>">
                <div class="mld-rv-card">
                    <div class="mld-rv-card-header">
                        <h2><?php _e('Most Viewed Properties', 'mls-listings-display'); ?></h2>
                        <span><?php printf(__('%d unique properties', 'mls-listings-display'), $data['total']); ?></span>
                    </div>
                    <div class="mld-rv-card-body">
                        <?php if (empty($data['items'])): ?>
                            <div class="mld-rv-empty">
                                <p><?php _e('No property views recorded in this time period.', 'mls-listings-display'); ?></p>
                            </div>
                        <?php else: ?>
                            <table class="mld-rv-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Rank', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Property', 'mls-listings-display'); ?></th>
                                        <th><?php _e('MLS #', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Price', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Status', 'mls-listings-display'); ?></th>
                                        <th><?php _e('View Count', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Unique Users', 'mls-listings-display'); ?></th>
                                        <th><?php _e('Last Viewed', 'mls-listings-display'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $rank = $offset + 1;
                                    foreach ($data['items'] as $item):
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $rank++; ?></strong></td>
                                            <td>
                                                <?php if (!empty($item->street_address)): ?>
                                                    <a href="<?php echo home_url('/property/' . $item->listing_id . '/'); ?>"
                                                       target="_blank" class="mld-rv-property-link">
                                                        <?php echo esc_html($item->street_address . ', ' . $item->city); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #999;"><?php _e('Property data unavailable', 'mls-listings-display'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo esc_html($item->listing_id); ?></code></td>
                                            <td>
                                                <?php if (!empty($item->list_price)): ?>
                                                    $<?php echo number_format($item->list_price); ?>
                                                <?php else: ?>
                                                    <span style="color: #999;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($item->standard_status ?: '-'); ?></td>
                                            <td><strong><?php echo number_format($item->view_count); ?></strong></td>
                                            <td><?php echo number_format($item->unique_users); ?></td>
                                            <td>
                                                <?php
                                                // Format in WordPress timezone (Rule 13)
                                                // Database stores in WP timezone, so create DateTime with that timezone
                                                $date = new DateTime($item->last_viewed, wp_timezone());
                                                echo wp_date('M j, Y g:i A', $date->getTimestamp());
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php $this->render_pagination($data['total'], $per_page, $page_num, $tab, $days); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get summary statistics
     */
    private function get_stats($cutoff_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_recently_viewed_properties';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_views,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT listing_id) as unique_properties
             FROM {$table}
             WHERE viewed_at >= %s",
            $cutoff_date
        ));

        return array(
            'total_views' => $stats ? (int) $stats->total_views : 0,
            'unique_users' => $stats ? (int) $stats->unique_users : 0,
            'unique_properties' => $stats ? (int) $stats->unique_properties : 0
        );
    }

    /**
     * Get recent views with user and property details
     */
    private function get_recent_views($cutoff_date, $limit, $offset) {
        global $wpdb;
        $viewed_table = $wpdb->prefix . 'mld_recently_viewed_properties';
        $users_table = $wpdb->users;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$viewed_table} WHERE viewed_at >= %s",
            $cutoff_date
        ));

        // Get recent views with joins
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT
                rv.id,
                rv.user_id,
                rv.listing_id,
                rv.viewed_at,
                rv.view_source,
                rv.platform,
                rv.ip_address,
                u.display_name,
                u.user_email,
                CONCAT(ls.street_number, ' ', ls.street_name) as street_address,
                ls.city,
                ls.list_price,
                ls.standard_status
             FROM {$viewed_table} rv
             LEFT JOIN {$users_table} u ON rv.user_id = u.ID
             LEFT JOIN {$summary_table} ls ON rv.listing_id = ls.listing_id
             WHERE rv.viewed_at >= %s
             ORDER BY rv.viewed_at DESC
             LIMIT %d OFFSET %d",
            $cutoff_date,
            $limit,
            $offset
        ));

        return array(
            'items' => $items,
            'total' => (int) $total
        );
    }

    /**
     * Get most viewed properties with aggregated stats
     */
    private function get_most_viewed_properties($cutoff_date, $limit, $offset) {
        global $wpdb;
        $viewed_table = $wpdb->prefix . 'mld_recently_viewed_properties';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get total count of unique properties
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT listing_id) FROM {$viewed_table} WHERE viewed_at >= %s",
            $cutoff_date
        ));

        // Get aggregated view counts with property details
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT
                rv.listing_id,
                COUNT(*) as view_count,
                COUNT(DISTINCT rv.user_id) as unique_users,
                MAX(rv.viewed_at) as last_viewed,
                CONCAT(ls.street_number, ' ', ls.street_name) as street_address,
                ls.city,
                ls.list_price,
                ls.standard_status
             FROM {$viewed_table} rv
             LEFT JOIN {$summary_table} ls ON rv.listing_id = ls.listing_id
             WHERE rv.viewed_at >= %s
             GROUP BY rv.listing_id
             ORDER BY view_count DESC, last_viewed DESC
             LIMIT %d OFFSET %d",
            $cutoff_date,
            $limit,
            $offset
        ));

        return array(
            'items' => $items,
            'total' => (int) $total
        );
    }

    /**
     * Get user location from IP address using ip-api.com
     * Results are cached in transients to avoid repeated API calls
     *
     * @param object $item The view record with user_id and ip_address
     * @return array|null Array with 'short' and 'full' location strings, or null
     */
    private function get_user_location($item) {
        // For logged-in users, we don't have IP - skip
        if ($item->user_id > 0 || empty($item->ip_address)) {
            return null;
        }

        $ip = $item->ip_address;

        // Skip private/local IPs
        if ($this->is_private_ip($ip)) {
            return array(
                'short' => 'Local',
                'full' => 'Local/Private Network'
            );
        }

        // Check transient cache first (cache for 24 hours)
        $cache_key = 'mld_ip_loc_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Call ip-api.com (free tier, 45 requests/minute)
        $response = wp_remote_get(
            'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,regionCode,country,countryCode',
            array(
                'timeout' => 3,
                'sslverify' => false
            )
        );

        if (is_wp_error($response)) {
            // Cache failure for 1 hour to avoid hammering
            set_transient($cache_key, null, HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || $body['status'] !== 'success') {
            set_transient($cache_key, null, HOUR_IN_SECONDS);
            return null;
        }

        // Build location strings
        $city = !empty($body['city']) ? $body['city'] : '';
        $region = !empty($body['regionCode']) ? $body['regionCode'] : '';
        $country = !empty($body['countryCode']) ? $body['countryCode'] : '';

        // Short version: "Boston, MA" or "London, GB"
        $short_parts = array_filter(array($city, $region ?: $country));
        $short = implode(', ', $short_parts) ?: 'Unknown';

        // Full version: "Boston, MA, United States"
        $full_parts = array_filter(array($city, $region, $body['country'] ?? ''));
        $full = implode(', ', $full_parts) ?: 'Unknown Location';

        $location = array(
            'short' => $short,
            'full' => $full
        );

        // Cache for 24 hours
        set_transient($cache_key, $location, DAY_IN_SECONDS);

        return $location;
    }

    /**
     * Check if IP is private/local
     *
     * @param string $ip IP address
     * @return bool True if private/local IP
     */
    private function is_private_ip($ip) {
        // Check for local/private ranges
        if (
            strpos($ip, '10.') === 0 ||
            strpos($ip, '192.168.') === 0 ||
            strpos($ip, '172.16.') === 0 ||
            strpos($ip, '172.17.') === 0 ||
            strpos($ip, '172.18.') === 0 ||
            strpos($ip, '172.19.') === 0 ||
            strpos($ip, '172.2') === 0 ||
            strpos($ip, '172.30.') === 0 ||
            strpos($ip, '172.31.') === 0 ||
            strpos($ip, '127.') === 0 ||
            $ip === '::1' ||
            strpos($ip, 'fe80:') === 0
        ) {
            return true;
        }
        return false;
    }

    /**
     * Render pagination
     */
    private function render_pagination($total, $per_page, $current_page, $tab, $days) {
        $total_pages = ceil($total / $per_page);

        if ($total_pages <= 1) {
            return;
        }

        $base_url = admin_url('admin.php?page=mld-recently-viewed&tab=' . $tab . '&days=' . $days);

        ?>
        <div class="mld-rv-pagination">
            <div>
                <?php
                printf(
                    __('Showing %d-%d of %d', 'mls-listings-display'),
                    (($current_page - 1) * $per_page) + 1,
                    min($current_page * $per_page, $total),
                    $total
                );
                ?>
            </div>
            <div>
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo esc_url($base_url . '&paged=' . ($current_page - 1)); ?>" class="button">
                        &laquo; <?php _e('Previous', 'mls-listings-display'); ?>
                    </a>
                <?php endif; ?>

                <span style="margin: 0 10px;">
                    <?php printf(__('Page %d of %d', 'mls-listings-display'), $current_page, $total_pages); ?>
                </span>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo esc_url($base_url . '&paged=' . ($current_page + 1)); ?>" class="button">
                        <?php _e('Next', 'mls-listings-display'); ?> &raquo;
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// Initialize the admin page
MLD_Recently_Viewed_Admin::get_instance();
