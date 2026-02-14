<?php
/**
 * MLD Open House Admin Page
 *
 * Admin dashboard for viewing all open house sign-ins across all agents.
 * Provides summary stats, filtering, detail views, and CSV export.
 *
 * @package MLS_Listings_Display
 * @subpackage Admin
 * @since 6.76.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Open_House_Admin {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Items per page
     */
    const ITEMS_PER_PAGE = 20;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_mld_admin_export_open_house_csv', array($this, 'ajax_export_csv'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'Open Houses',
            'Open Houses',
            'manage_options',
            'mld-open-houses',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'mld-open-houses') === false) {
            return;
        }

        wp_enqueue_style(
            'mld-open-house-admin',
            MLD_PLUGIN_URL . 'assets/css/admin/mld-open-house-admin.css',
            array(),
            MLD_VERSION
        );

        wp_enqueue_script(
            'mld-open-house-admin',
            MLD_PLUGIN_URL . 'assets/js/admin/mld-open-house-admin.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        wp_localize_script('mld-open-house-admin', 'mldOpenHouseAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_open_house_admin_nonce'),
        ));
    }

    /**
     * Render the admin page (router)
     */
    public function render_page() {
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';

        if ($view === 'detail' && !empty($_GET['oh_id'])) {
            $this->render_detail_page(intval($_GET['oh_id']));
        } else {
            $this->render_list_page();
        }
    }

    // =========================================================================
    // LIST PAGE
    // =========================================================================

    /**
     * Render the main list page with stats, filters, and table
     */
    private function render_list_page() {
        // Get filter parameters
        $agent_filter = isset($_GET['agent_id']) ? intval($_GET['agent_id']) : 0;
        $city_filter = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'event_date';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        // Validate
        $order = in_array($order, array('ASC', 'DESC')) ? $order : 'DESC';
        $valid_orderby = array('event_date', 'property_city', 'agent_name', 'attendee_count');
        $orderby = in_array($orderby, $valid_orderby) ? $orderby : 'event_date';

        $filters = array(
            'agent_id' => $agent_filter,
            'city' => $city_filter,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'status' => $status_filter,
        );

        // Get data
        $open_houses = $this->get_open_houses($filters, $orderby, $order, $paged);
        $total_items = $this->get_open_house_count($filters);
        $total_pages = ceil($total_items / self::ITEMS_PER_PAGE);
        $stats = $this->get_summary_stats($filters);
        $status_counts = $this->get_status_counts();
        $agents = $this->get_agents_with_open_houses();
        $cities = $this->get_unique_cities();

        ?>
        <div class="wrap mld-oh-admin">
            <h1>Open Houses</h1>
            <p class="description">View all open house events and sign-in data across all agents.</p>

            <!-- Summary Stats -->
            <div class="mld-oh-stats-row">
                <div class="mld-oh-stat-card">
                    <span class="mld-oh-stat-icon dashicons dashicons-location"></span>
                    <div class="mld-oh-stat-content">
                        <span class="mld-oh-stat-value"><?php echo esc_html(number_format($stats['total_open_houses'])); ?></span>
                        <span class="mld-oh-stat-label">Open Houses</span>
                    </div>
                </div>
                <div class="mld-oh-stat-card">
                    <span class="mld-oh-stat-icon dashicons dashicons-groups"></span>
                    <div class="mld-oh-stat-content">
                        <span class="mld-oh-stat-value"><?php echo esc_html(number_format($stats['total_attendees'])); ?></span>
                        <span class="mld-oh-stat-label">Total Attendees</span>
                    </div>
                </div>
                <div class="mld-oh-stat-card">
                    <span class="mld-oh-stat-icon dashicons dashicons-yes-alt"></span>
                    <div class="mld-oh-stat-content">
                        <span class="mld-oh-stat-value"><?php echo esc_html($stats['crm_conversion_rate']); ?>%</span>
                        <span class="mld-oh-stat-label">CRM Conversion</span>
                    </div>
                </div>
                <div class="mld-oh-stat-card">
                    <span class="mld-oh-stat-icon dashicons dashicons-chart-bar"></span>
                    <div class="mld-oh-stat-content">
                        <span class="mld-oh-stat-value"><?php echo esc_html($stats['avg_attendees']); ?></span>
                        <span class="mld-oh-stat-label">Avg per Event</span>
                    </div>
                </div>
            </div>

            <!-- Status Tabs -->
            <ul class="subsubsub">
                <?php
                $all_count = array_sum($status_counts);
                $statuses = array(
                    'all' => array('label' => 'All', 'count' => $all_count),
                    'scheduled' => array('label' => 'Scheduled', 'count' => $status_counts['scheduled'] ?? 0),
                    'active' => array('label' => 'Active', 'count' => $status_counts['active'] ?? 0),
                    'completed' => array('label' => 'Completed', 'count' => $status_counts['completed'] ?? 0),
                    'cancelled' => array('label' => 'Cancelled', 'count' => $status_counts['cancelled'] ?? 0),
                );
                $last_key = array_key_last($statuses);
                foreach ($statuses as $key => $data) :
                    $url = add_query_arg('status', $key, remove_query_arg(array('paged')));
                    $class = ($status_filter === $key) ? 'current' : '';
                ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($class); ?>">
                            <?php echo esc_html($data['label']); ?>
                            <span class="count">(<?php echo esc_html($data['count']); ?>)</span>
                        </a><?php echo ($key !== $last_key) ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Filter Form -->
            <form method="get" class="mld-oh-filter-form">
                <input type="hidden" name="page" value="mld-open-houses">
                <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">

                <div class="mld-oh-filters">
                    <label>
                        <span class="screen-reader-text">Filter by Agent</span>
                        <select name="agent_id">
                            <option value="0">All Agents</option>
                            <?php foreach ($agents as $agent) : ?>
                                <option value="<?php echo esc_attr($agent->ID); ?>" <?php selected($agent_filter, $agent->ID); ?>>
                                    <?php echo esc_html($agent->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span class="screen-reader-text">Filter by City</span>
                        <select name="city">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city) : ?>
                                <option value="<?php echo esc_attr($city); ?>" <?php selected($city_filter, $city); ?>>
                                    <?php echo esc_html($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span class="screen-reader-text">Date From</span>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="From">
                    </label>

                    <label>
                        <span class="screen-reader-text">Date To</span>
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="To">
                    </label>

                    <input type="submit" class="button" value="Filter">

                    <?php if ($agent_filter || $city_filter || $date_from || $date_to) : ?>
                        <a href="<?php echo esc_url(add_query_arg('status', $status_filter, admin_url('admin.php?page=mld-open-houses'))); ?>" class="button">Clear</a>
                    <?php endif; ?>

                    <button type="button" class="button mld-oh-export-csv" data-scope="list">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -2px;"></span> Export CSV
                    </button>
                </div>
            </form>

            <!-- Main Table -->
            <table class="wp-list-table widefat fixed striped mld-oh-table">
                <thead>
                    <tr>
                        <th scope="col" style="width: 140px;">
                            <?php echo $this->get_sortable_column_header('event_date', 'Date / Time', $orderby, $order); ?>
                        </th>
                        <th scope="col">Property</th>
                        <th scope="col" style="width: 120px;">
                            <?php echo $this->get_sortable_column_header('property_city', 'City', $orderby, $order); ?>
                        </th>
                        <th scope="col" style="width: 130px;">
                            <?php echo $this->get_sortable_column_header('agent_name', 'Agent', $orderby, $order); ?>
                        </th>
                        <th scope="col" style="width: 90px;">Status</th>
                        <th scope="col" style="width: 80px;">
                            <?php echo $this->get_sortable_column_header('attendee_count', 'Attendees', $orderby, $order); ?>
                        </th>
                        <th scope="col" style="width: 80px;">Hot Leads</th>
                        <th scope="col" style="width: 70px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($open_houses)) : ?>
                        <tr>
                            <td colspan="8" class="mld-oh-no-items">No open houses found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($open_houses as $oh) :
                            $event_date = new DateTime($oh->event_date, wp_timezone());
                            $detail_url = add_query_arg(array(
                                'page' => 'mld-open-houses',
                                'view' => 'detail',
                                'oh_id' => $oh->id,
                            ), admin_url('admin.php'));

                            $price_display = $oh->list_price ? '$' . number_format($oh->list_price) : '';
                        ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($event_date->format('M j, Y')); ?>
                                    <br><small><?php echo esc_html(substr($oh->start_time, 0, 5) . ' - ' . substr($oh->end_time, 0, 5)); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($oh->property_address ?: 'N/A'); ?></strong>
                                    <?php if ($price_display) : ?>
                                        <br><small><?php echo esc_html($price_display); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($oh->property_city ?: '—'); ?></td>
                                <td><?php echo esc_html($oh->agent_name ?: 'Unknown'); ?></td>
                                <td><?php echo $this->format_status_badge($oh->status); ?></td>
                                <td style="text-align: center;">
                                    <strong><?php echo esc_html($oh->attendee_count); ?></strong>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($oh->hot_lead_count > 0) : ?>
                                        <span class="mld-oh-priority-hot"><?php echo esc_html($oh->hot_lead_count); ?></span>
                                    <?php else : ?>
                                        <span style="color: #999;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($detail_url); ?>" class="button button-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // DETAIL PAGE
    // =========================================================================

    /**
     * Render the detail page for a single open house
     */
    private function render_detail_page($id) {
        $oh = $this->get_open_house_detail($id);

        if (!$oh) {
            echo '<div class="wrap"><h1>Open House Not Found</h1>';
            echo '<p>The open house you are looking for does not exist.</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=mld-open-houses')) . '">&larr; Back to Open Houses</a></p></div>';
            return;
        }

        $attendees = $this->get_attendees($id);
        $event_date = new DateTime($oh->event_date, wp_timezone());
        $list_url = admin_url('admin.php?page=mld-open-houses');
        $attendee_count = count($attendees);
        $hot_count = 0;
        $crm_count = 0;
        foreach ($attendees as $a) {
            if ($a->priority_score >= 80) $hot_count++;
            if ($a->auto_crm_processed) $crm_count++;
        }

        ?>
        <div class="wrap mld-oh-admin">
            <p><a href="<?php echo esc_url($list_url); ?>">&larr; Back to Open Houses</a></p>

            <!-- Detail Header -->
            <div class="mld-oh-detail-header">
                <?php if (!empty($oh->photo_url)) : ?>
                    <div class="mld-oh-detail-photo">
                        <img src="<?php echo esc_url($oh->photo_url); ?>" alt="Property photo">
                    </div>
                <?php endif; ?>
                <div class="mld-oh-detail-info">
                    <h1 style="margin-bottom: 5px;"><?php echo esc_html($oh->property_address ?: 'Open House #' . $oh->id); ?></h1>
                    <p class="description" style="margin: 0 0 10px;">
                        <?php
                        $details = array_filter(array(
                            $oh->property_city,
                            $oh->property_state,
                            $oh->property_zip,
                        ));
                        echo esc_html(implode(', ', $details));
                        if ($oh->list_price) {
                            echo ' &mdash; $' . esc_html(number_format($oh->list_price));
                        }
                        ?>
                    </p>
                    <p style="margin: 0 0 5px;">
                        <strong>Date:</strong> <?php echo esc_html($event_date->format('l, F j, Y')); ?>
                        &nbsp;|&nbsp;
                        <strong>Time:</strong> <?php echo esc_html(substr($oh->start_time, 0, 5) . ' - ' . substr($oh->end_time, 0, 5)); ?>
                    </p>
                    <p style="margin: 0 0 10px;">
                        <strong>Agent:</strong> <?php echo esc_html($oh->agent_name ?: 'Unknown'); ?>
                        &nbsp;|&nbsp;
                        <strong>Status:</strong> <?php echo $this->format_status_badge($oh->status); ?>
                    </p>

                    <!-- Detail Stats -->
                    <div class="mld-oh-detail-stats">
                        <span class="mld-oh-detail-stat"><?php echo esc_html($attendee_count); ?> attendees</span>
                        <span class="mld-oh-detail-stat mld-oh-priority-hot"><?php echo esc_html($hot_count); ?> hot leads</span>
                        <span class="mld-oh-detail-stat"><?php echo esc_html($crm_count); ?> added to CRM</span>
                    </div>
                </div>
            </div>

            <!-- Export Button -->
            <div style="margin: 15px 0;">
                <button type="button" class="button mld-oh-export-csv" data-scope="detail" data-oh-id="<?php echo esc_attr($id); ?>">
                    <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -2px;"></span> Export Attendees CSV
                </button>
            </div>

            <!-- Attendees Table (v6.76.1: expanded fields) -->
            <table class="wp-list-table widefat fixed striped mld-oh-attendee-table">
                <thead>
                    <tr>
                        <th scope="col" style="width: 40px;">#</th>
                        <th scope="col">Name</th>
                        <th scope="col">Contact</th>
                        <th scope="col" style="width: 80px;">Type</th>
                        <th scope="col" style="width: 70px;">Priority</th>
                        <th scope="col" style="width: 80px;">Interest</th>
                        <th scope="col" style="width: 100px;">Timeline</th>
                        <th scope="col" style="width: 100px;">Financing</th>
                        <th scope="col" style="width: 160px;">Agent Info</th>
                        <th scope="col" style="width: 80px;">Consent</th>
                        <th scope="col" style="width: 50px;">CRM</th>
                        <th scope="col" style="width: 100px;">Signed In</th>
                        <th scope="col">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendees)) : ?>
                        <tr>
                            <td colspan="13" class="mld-oh-no-items">No attendees have signed in yet.</td>
                        </tr>
                    <?php else : ?>
                        <?php $i = 1; foreach ($attendees as $a) :
                            $signed_in = $a->signed_in_at ? new DateTime($a->signed_in_at, wp_timezone()) : null;
                        ?>
                            <tr>
                                <td><?php echo esc_html($i++); ?></td>
                                <td>
                                    <strong><?php echo esc_html($a->first_name . ' ' . $a->last_name); ?></strong>
                                    <?php if (!empty($a->how_heard_about)) : ?>
                                        <br><small style="color: #888;">via <?php echo esc_html(ucwords(str_replace('_', ' ', $a->how_heard_about))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a->email) : ?>
                                        <a href="mailto:<?php echo esc_attr($a->email); ?>"><?php echo esc_html($a->email); ?></a><br>
                                    <?php endif; ?>
                                    <?php if ($a->phone) : ?>
                                        <small><?php echo esc_html($a->phone); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a->is_agent) : ?>
                                        <span class="mld-oh-badge mld-oh-badge-agent">Agent</span>
                                    <?php else : ?>
                                        <span class="mld-oh-badge mld-oh-badge-buyer">Buyer</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $this->format_priority_badge($a->priority_score); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($this->format_interest_level($a->interest_level)); ?>
                                </td>
                                <td><?php echo esc_html($this->format_timeline($a->buying_timeline)); ?></td>
                                <td>
                                    <?php // Financing: Pre-approved status + lender ?>
                                    <?php echo esc_html($this->format_pre_approved($a->pre_approved)); ?>
                                    <?php if (!empty($a->lender_name)) : ?>
                                        <br><small><?php echo esc_html($a->lender_name); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a->is_agent) : ?>
                                        <?php // Agent visitor: show brokerage + visit purpose ?>
                                        <?php if (!empty($a->agent_brokerage)) : ?>
                                            <small><?php echo esc_html($a->agent_brokerage); ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($a->agent_visit_purpose)) : ?>
                                            <small style="color: #666;"><?php echo esc_html(ucwords(str_replace('_', ' ', $a->agent_visit_purpose))); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($a->agent_has_buyer)) : ?>
                                            <br><small style="color: #16a34a;">Has buyer</small>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <?php // Buyer visitor: show working with agent + agent details ?>
                                        <?php echo esc_html($this->format_working_with_agent($a->working_with_agent)); ?>
                                        <?php if ($a->other_agent_name) : ?>
                                            <br><small><?php echo esc_html($a->other_agent_name); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($a->other_agent_brokerage)) : ?>
                                            <br><small style="color: #666;"><?php echo esc_html($a->other_agent_brokerage); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($a->other_agent_phone) || !empty($a->other_agent_email)) : ?>
                                            <br><small style="color: #888;">
                                                <?php echo esc_html(implode(' | ', array_filter(array($a->other_agent_phone, $a->other_agent_email)))); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php // Consent badges ?>
                                    <?php if ($a->consent_to_follow_up) : ?><span title="Follow-up OK" style="color: #16a34a;">&#x2713;</span><?php endif; ?>
                                    <?php if ($a->consent_to_email) : ?><span title="Email OK" style="color: #16a34a;">&#x2709;</span><?php endif; ?>
                                    <?php if ($a->consent_to_text) : ?><span title="Text OK" style="color: #16a34a;">&#x1F4F1;</span><?php endif; ?>
                                    <?php if ($a->ma_disclosure_acknowledged) : ?>
                                        <br><small title="MA Disclosure Acknowledged" style="color: #2563eb;">MA</small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($a->auto_crm_processed) : ?>
                                        <span class="dashicons dashicons-yes" style="color: #16a34a;"></span>
                                    <?php else : ?>
                                        <span style="color: #ccc;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($signed_in) : ?>
                                        <?php echo esc_html($signed_in->format('g:i A')); ?>
                                    <?php else : ?>
                                        <span style="color: #999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a->agent_notes) : ?>
                                        <small><?php echo esc_html(wp_trim_words($a->agent_notes, 15)); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // =========================================================================
    // DATA METHODS
    // =========================================================================

    /**
     * Get summary statistics (optionally filtered)
     */
    private function get_summary_stats($filters = array()) {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $att_table = $wpdb->prefix . 'mld_open_house_attendees';

        $where = array('1=1');
        $params = array();
        $this->apply_filters($where, $params, $filters);

        $where_sql = implode(' AND ', $where);

        // Total open houses
        $sql = "SELECT COUNT(*) FROM {$oh_table} oh WHERE {$where_sql}";
        $total_oh = empty($params) ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $params));

        // Total attendees
        $sql = "SELECT COUNT(*) FROM {$att_table} a INNER JOIN {$oh_table} oh ON a.open_house_id = oh.id WHERE {$where_sql}";
        $total_att = empty($params) ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $params));

        // CRM conversion
        $sql = "SELECT COUNT(*) FROM {$att_table} a INNER JOIN {$oh_table} oh ON a.open_house_id = oh.id WHERE a.auto_crm_processed = 1 AND {$where_sql}";
        $crm_count = empty($params) ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $params));

        $crm_rate = $total_att > 0 ? round(($crm_count / $total_att) * 100, 1) : 0;
        $avg_att = $total_oh > 0 ? round($total_att / $total_oh, 1) : 0;

        return array(
            'total_open_houses' => (int) $total_oh,
            'total_attendees' => (int) $total_att,
            'crm_conversion_rate' => $crm_rate,
            'avg_attendees' => $avg_att,
        );
    }

    /**
     * Get open houses with attendee/hot-lead counts
     */
    private function get_open_houses($filters, $orderby, $order, $paged) {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $att_table = $wpdb->prefix . 'mld_open_house_attendees';
        $users_table = $wpdb->users;

        $where = array('1=1');
        $params = array();
        $this->apply_filters($where, $params, $filters);

        $where_sql = implode(' AND ', $where);

        // Map orderby to SQL
        $orderby_map = array(
            'event_date' => 'oh.event_date',
            'property_city' => 'oh.property_city',
            'agent_name' => 'u.display_name',
            'attendee_count' => 'attendee_count',
        );
        $order_col = $orderby_map[$orderby] ?? 'oh.event_date';

        $offset = ($paged - 1) * self::ITEMS_PER_PAGE;

        $sql = "SELECT oh.*,
                    u.display_name AS agent_name,
                    (SELECT COUNT(*) FROM {$att_table} WHERE open_house_id = oh.id) AS attendee_count,
                    (SELECT COUNT(*) FROM {$att_table} WHERE open_house_id = oh.id AND priority_score >= 80) AS hot_lead_count
                FROM {$oh_table} oh
                LEFT JOIN {$users_table} u ON oh.agent_user_id = u.ID
                WHERE {$where_sql}
                ORDER BY {$order_col} {$order}
                LIMIT %d OFFSET %d";

        $params[] = self::ITEMS_PER_PAGE;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Get total count for pagination
     */
    private function get_open_house_count($filters) {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';

        $where = array('1=1');
        $params = array();
        $this->apply_filters($where, $params, $filters);

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM {$oh_table} oh WHERE {$where_sql}";
        return empty($params) ? (int) $wpdb->get_var($sql) : (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Get single open house with agent info
     */
    private function get_open_house_detail($id) {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $users_table = $wpdb->users;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT oh.*, u.display_name AS agent_name
             FROM {$oh_table} oh
             LEFT JOIN {$users_table} u ON oh.agent_user_id = u.ID
             WHERE oh.id = %d",
            $id
        ));
    }

    /**
     * Get all attendees for an open house
     */
    private function get_attendees($open_house_id) {
        global $wpdb;
        $att_table = $wpdb->prefix . 'mld_open_house_attendees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$att_table}
             WHERE open_house_id = %d
             ORDER BY priority_score DESC, signed_in_at ASC",
            $open_house_id
        ));
    }

    /**
     * Get agents who have created open houses
     */
    private function get_agents_with_open_houses() {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';

        $user_ids = $wpdb->get_col("SELECT DISTINCT agent_user_id FROM {$oh_table} WHERE agent_user_id > 0");

        if (empty($user_ids)) {
            return array();
        }

        return get_users(array(
            'include' => $user_ids,
            'orderby' => 'display_name',
        ));
    }

    /**
     * Get unique cities from open houses
     */
    private function get_unique_cities() {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';

        return $wpdb->get_col(
            "SELECT DISTINCT property_city FROM {$oh_table}
             WHERE property_city IS NOT NULL AND property_city != ''
             ORDER BY property_city ASC"
        );
    }

    /**
     * Get counts per status
     */
    private function get_status_counts() {
        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$oh_table} GROUP BY status",
            OBJECT_K
        );

        $counts = array();
        foreach (array('scheduled', 'active', 'completed', 'cancelled') as $status) {
            $counts[$status] = isset($results[$status]) ? (int) $results[$status]->cnt : 0;
        }

        return $counts;
    }

    /**
     * Apply filter conditions to WHERE clause
     */
    private function apply_filters(&$where, &$params, $filters) {
        if (!empty($filters['agent_id'])) {
            $where[] = 'oh.agent_user_id = %d';
            $params[] = $filters['agent_id'];
        }

        if (!empty($filters['city'])) {
            $where[] = 'oh.property_city = %s';
            $params[] = $filters['city'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'oh.event_date >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'oh.event_date <= %s';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 'oh.status = %s';
            $params[] = $filters['status'];
        }
    }

    // =========================================================================
    // CSV EXPORT
    // =========================================================================

    /**
     * AJAX handler: export CSV
     */
    public function ajax_export_csv() {
        check_ajax_referer('mld_open_house_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        global $wpdb;
        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $att_table = $wpdb->prefix . 'mld_open_house_attendees';
        $users_table = $wpdb->users;

        $scope = sanitize_text_field($_POST['scope'] ?? 'list');

        if ($scope === 'detail') {
            // Single open house export
            $oh_id = intval($_POST['oh_id'] ?? 0);
            if (!$oh_id) {
                wp_send_json_error(array('message' => 'Invalid open house ID'));
                return;
            }

            $oh = $wpdb->get_row($wpdb->prepare(
                "SELECT oh.*, u.display_name AS agent_name
                 FROM {$oh_table} oh
                 LEFT JOIN {$users_table} u ON oh.agent_user_id = u.ID
                 WHERE oh.id = %d",
                $oh_id
            ));

            if (!$oh) {
                wp_send_json_error(array('message' => 'Open house not found'));
                return;
            }

            $attendees = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$att_table} WHERE open_house_id = %d ORDER BY priority_score DESC, signed_in_at ASC",
                $oh_id
            ));

            $event_date = new DateTime($oh->event_date, wp_timezone());
            $filename = 'open-house-' . $event_date->format('Y-m-d') . '-' . sanitize_title($oh->property_address ?: $oh->id) . '.csv';

            // v6.76.1: Expanded CSV headers with all attendee fields
            $headers = array('First Name', 'Last Name', 'Email', 'Phone', 'Type', 'Priority Score',
                'Interest Level', 'Timeline', 'Pre-Approved', 'Lender Name',
                'Working with Agent', 'Other Agent', 'Other Agent Brokerage', 'Other Agent Phone', 'Other Agent Email',
                'Is Agent', 'Agent Brokerage', 'Agent Visit Purpose', 'Agent Has Buyer', 'Agent Buyer Timeline', 'Agent Network Interest',
                'How Heard About',
                'Consent to Follow-Up', 'Consent to Email', 'Consent to Text', 'MA Disclosure',
                'CRM Processed', 'Signed In', 'Notes');

            $rows = array();
            foreach ($attendees as $a) {
                $signed_in = $a->signed_in_at ? (new DateTime($a->signed_in_at, wp_timezone()))->format('Y-m-d H:i') : '';
                $rows[] = array(
                    $this->csv_escape($a->first_name),
                    $this->csv_escape($a->last_name),
                    $this->csv_escape($a->email),
                    $this->csv_escape($a->phone),
                    $a->is_agent ? 'Agent' : 'Buyer',
                    $a->priority_score,
                    $a->interest_level ?: 'unknown',
                    $this->format_timeline($a->buying_timeline),
                    $this->format_pre_approved($a->pre_approved),
                    $this->csv_escape($a->lender_name ?: ''),
                    $this->format_working_with_agent($a->working_with_agent),
                    $this->csv_escape($a->other_agent_name ?: ''),
                    $this->csv_escape($a->other_agent_brokerage ?: ''),
                    $this->csv_escape($a->other_agent_phone ?: ''),
                    $this->csv_escape($a->other_agent_email ?: ''),
                    $a->is_agent ? 'Yes' : 'No',
                    $this->csv_escape($a->agent_brokerage ?? ''),
                    $this->csv_escape($a->agent_visit_purpose ?? ''),
                    !empty($a->agent_has_buyer) ? 'Yes' : 'No',
                    $this->csv_escape($a->agent_buyer_timeline ?? ''),
                    !empty($a->agent_network_interest) ? 'Yes' : 'No',
                    $this->csv_escape($a->how_heard_about ?? ''),
                    $a->consent_to_follow_up ? 'Yes' : 'No',
                    $a->consent_to_email ? 'Yes' : 'No',
                    $a->consent_to_text ? 'Yes' : 'No',
                    $a->ma_disclosure_acknowledged ? 'Yes' : 'No',
                    $a->auto_crm_processed ? 'Yes' : 'No',
                    $signed_in,
                    $this->csv_escape($a->agent_notes ?: ''),
                );
            }
        } else {
            // List export with filters
            $filters = array(
                'agent_id' => intval($_POST['agent_id'] ?? 0),
                'city' => sanitize_text_field($_POST['city'] ?? ''),
                'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
                'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
                'status' => sanitize_text_field($_POST['status'] ?? 'all'),
            );

            $where = array('1=1');
            $params = array();
            $this->apply_filters($where, $params, $filters);
            $where_sql = implode(' AND ', $where);

            $sql = "SELECT a.*, oh.property_address, oh.property_city, oh.event_date, oh.list_price,
                        u.display_name AS agent_name
                    FROM {$att_table} a
                    INNER JOIN {$oh_table} oh ON a.open_house_id = oh.id
                    LEFT JOIN {$users_table} u ON oh.agent_user_id = u.ID
                    WHERE {$where_sql}
                    ORDER BY oh.event_date DESC, a.priority_score DESC";

            $results = empty($params) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, $params));

            $filename = 'open-house-attendees-' . wp_date('Y-m-d') . '.csv';

            // v6.76.1: Expanded list CSV headers with all attendee fields
            $headers = array('Event Date', 'Property Address', 'City', 'Agent', 'First Name', 'Last Name',
                'Email', 'Phone', 'Type', 'Priority Score',
                'Interest Level', 'Timeline', 'Pre-Approved', 'Lender Name',
                'Working with Agent', 'Other Agent', 'Other Agent Brokerage', 'Other Agent Phone', 'Other Agent Email',
                'Is Agent', 'Agent Brokerage', 'Agent Visit Purpose', 'Agent Has Buyer', 'Agent Buyer Timeline', 'Agent Network Interest',
                'How Heard About',
                'Consent to Follow-Up', 'Consent to Email', 'Consent to Text', 'MA Disclosure',
                'CRM Processed', 'Signed In', 'Notes');

            $rows = array();
            foreach ($results as $r) {
                $event_date = new DateTime($r->event_date, wp_timezone());
                $signed_in = $r->signed_in_at ? (new DateTime($r->signed_in_at, wp_timezone()))->format('Y-m-d H:i') : '';
                $rows[] = array(
                    $event_date->format('Y-m-d'),
                    $this->csv_escape($r->property_address ?: ''),
                    $this->csv_escape($r->property_city ?: ''),
                    $this->csv_escape($r->agent_name ?: ''),
                    $this->csv_escape($r->first_name),
                    $this->csv_escape($r->last_name),
                    $this->csv_escape($r->email),
                    $this->csv_escape($r->phone),
                    $r->is_agent ? 'Agent' : 'Buyer',
                    $r->priority_score,
                    $r->interest_level ?: 'unknown',
                    $this->format_timeline($r->buying_timeline),
                    $this->format_pre_approved($r->pre_approved),
                    $this->csv_escape($r->lender_name ?: ''),
                    $this->format_working_with_agent($r->working_with_agent),
                    $this->csv_escape($r->other_agent_name ?: ''),
                    $this->csv_escape($r->other_agent_brokerage ?: ''),
                    $this->csv_escape($r->other_agent_phone ?: ''),
                    $this->csv_escape($r->other_agent_email ?: ''),
                    $r->is_agent ? 'Yes' : 'No',
                    $this->csv_escape($r->agent_brokerage ?? ''),
                    $this->csv_escape($r->agent_visit_purpose ?? ''),
                    !empty($r->agent_has_buyer) ? 'Yes' : 'No',
                    $this->csv_escape($r->agent_buyer_timeline ?? ''),
                    !empty($r->agent_network_interest) ? 'Yes' : 'No',
                    $this->csv_escape($r->how_heard_about ?? ''),
                    $r->consent_to_follow_up ? 'Yes' : 'No',
                    $r->consent_to_email ? 'Yes' : 'No',
                    $r->consent_to_text ? 'Yes' : 'No',
                    $r->ma_disclosure_acknowledged ? 'Yes' : 'No',
                    $r->auto_crm_processed ? 'Yes' : 'No',
                    $signed_in,
                    $this->csv_escape($r->agent_notes ?: ''),
                );
            }
        }

        // Build CSV string
        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        wp_send_json_success(array(
            'csv' => $csv,
            'filename' => $filename,
        ));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get sortable column header HTML
     */
    private function get_sortable_column_header($column, $label, $current_orderby, $current_order) {
        $is_current = ($current_orderby === $column);
        $new_order = ($is_current && $current_order === 'ASC') ? 'DESC' : 'ASC';
        $url = add_query_arg(array('orderby' => $column, 'order' => $new_order));

        $class = 'sortable';
        if ($is_current) {
            $class .= ' sorted ' . strtolower($current_order);
        }

        $indicator = '';
        if ($is_current) {
            $indicator = $current_order === 'ASC' ? ' &#9650;' : ' &#9660;';
        }

        return '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . $indicator . '</a>';
    }

    /**
     * Format status badge
     */
    private function format_status_badge($status) {
        $badges = array(
            'scheduled' => '<span class="mld-oh-badge mld-oh-badge-scheduled">Scheduled</span>',
            'active'    => '<span class="mld-oh-badge mld-oh-badge-active">Active</span>',
            'completed' => '<span class="mld-oh-badge mld-oh-badge-completed">Completed</span>',
            'cancelled' => '<span class="mld-oh-badge mld-oh-badge-cancelled">Cancelled</span>',
        );
        return $badges[$status] ?? '<span class="mld-oh-badge">' . esc_html(ucfirst($status)) . '</span>';
    }

    /**
     * Format priority score badge
     */
    private function format_priority_badge($score) {
        if ($score >= 80) {
            return '<span class="mld-oh-priority-hot">' . esc_html($score) . '</span>';
        } elseif ($score >= 50) {
            return '<span class="mld-oh-priority-warm">' . esc_html($score) . '</span>';
        } else {
            return '<span class="mld-oh-priority-cool">' . esc_html($score) . '</span>';
        }
    }

    /**
     * Format timeline value for display
     */
    private function format_timeline($value) {
        $map = array(
            'just_browsing' => 'Just Browsing',
            'within_3_months' => '< 3 Months',
            'within_6_months' => '3-6 Months',
            'within_12_months' => '6-12 Months',
            'over_12_months' => '12+ Months',
        );
        return $map[$value] ?? ucwords(str_replace('_', ' ', $value ?: ''));
    }

    /**
     * Format pre-approved value for display
     */
    private function format_pre_approved($value) {
        $map = array(
            'yes' => 'Yes',
            'no' => 'No',
            'not_sure' => 'Not Sure',
            'in_process' => 'In Process',
        );
        return $map[$value] ?? ucwords(str_replace('_', ' ', $value ?: ''));
    }

    /**
     * Format working with agent value for display
     */
    private function format_working_with_agent($value) {
        $map = array(
            'no' => 'No Agent',
            'yes_our_brokerage' => 'Our Brokerage',
            'yes_other' => 'Other Agent',
        );
        return $map[$value] ?? ucwords(str_replace('_', ' ', $value ?: ''));
    }

    /**
     * v6.76.1: Format interest level for display
     */
    private function format_interest_level($value) {
        $map = array(
            'very_interested' => 'Very Interested',
            'somewhat' => 'Somewhat',
            'not_interested' => 'Not Interested',
            'unknown' => 'Unknown',
        );
        return $map[$value] ?? ucwords(str_replace('_', ' ', $value ?: 'Unknown'));
    }

    /**
     * Escape CSV field value with formula injection prevention
     */
    private function csv_escape($value) {
        $value = str_replace('"', '""', $value);
        // Prevent formula injection
        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        return '"' . $value . '"';
    }
}

// Initialize
add_action('plugins_loaded', function() {
    MLD_Open_House_Admin::get_instance();
}, 25);
