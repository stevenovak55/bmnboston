<?php
/**
 * Analytics Class
 *
 * Handles appointment analytics and reporting.
 *
 * @package SN_Appointment_Booking
 * @since 1.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics class.
 *
 * @since 1.4.0
 */
class SNAB_Analytics {

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_snab_get_analytics', array($this, 'ajax_get_analytics'));
        add_action('wp_ajax_snab_export_analytics', array($this, 'ajax_export_analytics'));
    }

    /**
     * Get key metrics for a date range.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Key metrics.
     */
    public function get_key_metrics($start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        // Total appointments in period
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s",
            $start_date, $end_date
        ));

        // Completed appointments
        $completed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             AND status = 'completed'",
            $start_date, $end_date
        ));

        // No-shows
        $no_shows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             AND status = 'no_show'",
            $start_date, $end_date
        ));

        // Cancelled
        $cancelled = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             AND status = 'cancelled'",
            $start_date, $end_date
        ));

        // Pending/Confirmed (upcoming)
        $upcoming = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             AND status IN ('pending', 'confirmed')",
            $start_date, $end_date
        ));

        // Average lead time (days between booking and appointment)
        $avg_lead_time = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(DATEDIFF(appointment_date, DATE(created_at)))
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s",
            $start_date, $end_date
        ));

        // Unique clients
        $unique_clients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT client_email) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s",
            $start_date, $end_date
        ));

        // Repeat clients (clients with more than 1 appointment ever)
        $repeat_clients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT client_email) FROM {$table} a
             WHERE a.appointment_date BETWEEN %s AND %s
             AND (SELECT COUNT(*) FROM {$table} b WHERE b.client_email = a.client_email) > 1",
            $start_date, $end_date
        ));

        // Rescheduled appointments
        $rescheduled = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             AND reschedule_count > 0",
            $start_date, $end_date
        ));

        // Calculate rates (avoid division by zero)
        $total_int = (int) $total;
        $total_for_rate = max(1, $total_int);
        $completion_rate = $total_int > 0 ? round(($completed / $total_for_rate) * 100, 1) : 0;
        $no_show_rate = $total_int > 0 ? round(($no_shows / $total_for_rate) * 100, 1) : 0;
        $cancellation_rate = $total_int > 0 ? round(($cancelled / $total_for_rate) * 100, 1) : 0;
        $repeat_rate = $unique_clients > 0 ? round(($repeat_clients / $unique_clients) * 100, 1) : 0;

        return array(
            'total_appointments' => $total_int,
            'completed' => (int) $completed,
            'no_shows' => (int) $no_shows,
            'cancelled' => (int) $cancelled,
            'upcoming' => (int) $upcoming,
            'rescheduled' => (int) $rescheduled,
            'completion_rate' => $completion_rate,
            'no_show_rate' => $no_show_rate,
            'cancellation_rate' => $cancellation_rate,
            'avg_lead_time' => round((float) $avg_lead_time, 1),
            'unique_clients' => (int) $unique_clients,
            'repeat_clients' => (int) $repeat_clients,
            'repeat_rate' => $repeat_rate,
        );
    }

    /**
     * Get appointments by day of week.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Day of week data.
     */
    public function get_by_day_of_week($start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DAYOFWEEK(appointment_date) as day_num, COUNT(*) as count
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY DAYOFWEEK(appointment_date)
             ORDER BY day_num",
            $start_date, $end_date
        ), ARRAY_A);

        // Initialize all days
        $days = array(
            1 => array('name' => 'Sunday', 'short' => 'Sun', 'count' => 0),
            2 => array('name' => 'Monday', 'short' => 'Mon', 'count' => 0),
            3 => array('name' => 'Tuesday', 'short' => 'Tue', 'count' => 0),
            4 => array('name' => 'Wednesday', 'short' => 'Wed', 'count' => 0),
            5 => array('name' => 'Thursday', 'short' => 'Thu', 'count' => 0),
            6 => array('name' => 'Friday', 'short' => 'Fri', 'count' => 0),
            7 => array('name' => 'Saturday', 'short' => 'Sat', 'count' => 0),
        );

        foreach ($results as $row) {
            $days[$row['day_num']]['count'] = (int) $row['count'];
        }

        return array_values($days);
    }

    /**
     * Get appointments by hour of day.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Hour data.
     */
    public function get_by_hour($start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT HOUR(start_time) as hour, COUNT(*) as count
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY HOUR(start_time)
             ORDER BY hour",
            $start_date, $end_date
        ), ARRAY_A);

        // Initialize hours 6am - 9pm (typical business hours)
        $hours = array();
        for ($h = 6; $h <= 21; $h++) {
            $hours[$h] = array(
                'hour' => $h,
                'label' => date('g A', strtotime("{$h}:00")),
                'count' => 0
            );
        }

        foreach ($results as $row) {
            $hour = (int) $row['hour'];
            if (isset($hours[$hour])) {
                $hours[$hour]['count'] = (int) $row['count'];
            }
        }

        return array_values($hours);
    }

    /**
     * Get appointments by type.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Type data.
     */
    public function get_by_type($start_date, $end_date) {
        global $wpdb;
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name, t.color, COUNT(a.id) as count,
                    SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) as no_shows,
                    SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
             FROM {$appointments_table} a
             JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE a.appointment_date BETWEEN %s AND %s
             GROUP BY a.appointment_type_id, t.name, t.color
             ORDER BY count DESC",
            $start_date, $end_date
        ), ARRAY_A);

        return $results;
    }

    /**
     * Get appointment trend over time.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @param string $interval   Interval: 'day', 'week', or 'month'.
     * @return array Trend data.
     */
    public function get_trend($start_date, $end_date, $interval = 'day') {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        switch ($interval) {
            case 'month':
                $date_format = '%%Y-%%m';
                $php_format = 'Y-m';
                $label_format = 'M Y';
                break;
            case 'week':
                $date_format = '%%x-%%v'; // ISO week
                $php_format = 'Y-W';
                $label_format = '\WW Y';
                break;
            default:
                $date_format = '%%Y-%%m-%%d';
                $php_format = 'Y-m-d';
                $label_format = 'M j';
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(appointment_date, '{$date_format}') as period,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY DATE_FORMAT(appointment_date, '{$date_format}')
             ORDER BY period",
            $start_date, $end_date
        ), ARRAY_A);

        // Convert periods to labels
        $data = array();
        foreach ($results as $row) {
            $label = $row['period'];
            if ($interval === 'day') {
                $label = date($label_format, strtotime($row['period']));
            } elseif ($interval === 'month') {
                $label = date($label_format, strtotime($row['period'] . '-01'));
            }

            $data[] = array(
                'period' => $row['period'],
                'label' => $label,
                'total' => (int) $row['total'],
                'completed' => (int) $row['completed'],
                'cancelled' => (int) $row['cancelled'],
            );
        }

        return $data;
    }

    /**
     * Get status breakdown.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @return array Status data.
     */
    public function get_status_breakdown($start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY status",
            $start_date, $end_date
        ), ARRAY_A);

        $status_colors = array(
            'pending' => '#f59e0b',
            'confirmed' => '#3b82f6',
            'completed' => '#10b981',
            'cancelled' => '#ef4444',
            'no_show' => '#6b7280',
        );

        $status_labels = array(
            'pending' => __('Pending', 'sn-appointment-booking'),
            'confirmed' => __('Confirmed', 'sn-appointment-booking'),
            'completed' => __('Completed', 'sn-appointment-booking'),
            'cancelled' => __('Cancelled', 'sn-appointment-booking'),
            'no_show' => __('No Show', 'sn-appointment-booking'),
        );

        $data = array();
        foreach ($results as $row) {
            $status = $row['status'];
            $data[] = array(
                'status' => $status,
                'label' => isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status),
                'count' => (int) $row['count'],
                'color' => isset($status_colors[$status]) ? $status_colors[$status] : '#9ca3af',
            );
        }

        return $data;
    }

    /**
     * Get top clients.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @param int    $limit      Number of clients to return.
     * @return array Client data.
     */
    public function get_top_clients($start_date, $end_date, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT client_name, client_email, COUNT(*) as appointment_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    MAX(CONCAT(appointment_date, ' ', start_time)) as last_appointment
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY client_email, client_name
             ORDER BY appointment_count DESC
             LIMIT %d",
            $start_date, $end_date, $limit
        ), ARRAY_A);

        return $results;
    }

    /**
     * Get busiest time slots.
     *
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     * @param int    $limit      Number of slots to return.
     * @return array Time slot data.
     */
    public function get_popular_slots($start_date, $end_date, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DAYNAME(appointment_date) as day_name,
                    TIME_FORMAT(start_time, '%%h:%%i %%p') as time_slot,
                    COUNT(*) as count
             FROM {$table}
             WHERE appointment_date BETWEEN %s AND %s
             GROUP BY DAYOFWEEK(appointment_date), HOUR(start_time), MINUTE(start_time)
             ORDER BY count DESC
             LIMIT %d",
            $start_date, $end_date, $limit
        ), ARRAY_A);

        return $results;
    }

    /**
     * AJAX handler to get analytics data.
     */
    public function ajax_get_analytics() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : wp_date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : wp_date('Y-m-d');

        // Validate dates
        if (!$this->validate_date($start_date) || !$this->validate_date($end_date)) {
            wp_send_json_error(__('Invalid date format.', 'sn-appointment-booking'));
        }

        // Determine interval based on date range
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / DAY_IN_SECONDS;
        if ($days_diff > 90) {
            $interval = 'month';
        } elseif ($days_diff > 30) {
            $interval = 'week';
        } else {
            $interval = 'day';
        }

        $data = array(
            'metrics' => $this->get_key_metrics($start_date, $end_date),
            'by_day' => $this->get_by_day_of_week($start_date, $end_date),
            'by_hour' => $this->get_by_hour($start_date, $end_date),
            'by_type' => $this->get_by_type($start_date, $end_date),
            'by_status' => $this->get_status_breakdown($start_date, $end_date),
            'trend' => $this->get_trend($start_date, $end_date, $interval),
            'top_clients' => $this->get_top_clients($start_date, $end_date, 5),
            'popular_slots' => $this->get_popular_slots($start_date, $end_date, 5),
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date,
                'interval' => $interval,
            ),
        );

        wp_send_json_success($data);
    }

    /**
     * AJAX handler to export analytics data.
     */
    public function ajax_export_analytics() {
        // Accept both GET and POST for nonce
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field($_REQUEST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'snab_admin_nonce')) {
            wp_die(__('Security check failed.', 'sn-appointment-booking'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'sn-appointment-booking'));
        }

        $start_date = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : wp_date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : wp_date('Y-m-d');

        // Get all appointments for export
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';

        $appointments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.name as type_name
             FROM {$table} a
             LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE a.appointment_date BETWEEN %s AND %s
             ORDER BY a.appointment_date, a.start_time",
            $start_date, $end_date
        ), ARRAY_A);

        // Output CSV directly
        $filename = 'appointments-' . $start_date . '-to-' . $end_date . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Headers
        fputcsv($output, array(
            'ID',
            'Type',
            'Client Name',
            'Client Email',
            'Client Phone',
            'Date',
            'Start Time',
            'End Time',
            'Status',
            'Property Address',
            'Client Notes',
            'Admin Notes',
            'Created At',
        ));

        // Data rows
        foreach ($appointments as $apt) {
            fputcsv($output, array(
                $apt['id'],
                $apt['type_name'],
                $apt['client_name'],
                $apt['client_email'],
                $apt['client_phone'],
                snab_format_date($apt['appointment_date']),
                snab_format_time($apt['appointment_date'], $apt['start_time'], 'g:i A'),
                snab_format_time($apt['appointment_date'], $apt['end_time'], 'g:i A'),
                ucfirst($apt['status']),
                $apt['property_address'],
                $apt['client_notes'],
                $apt['admin_notes'],
                $apt['created_at'],
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Generate CSV data from appointments.
     *
     * @param array $appointments Appointment data.
     * @return string CSV content.
     */
    private function generate_csv($appointments) {
        $output = fopen('php://temp', 'r+');

        // Headers
        fputcsv($output, array(
            'ID',
            'Type',
            'Client Name',
            'Client Email',
            'Client Phone',
            'Date',
            'Start Time',
            'End Time',
            'Status',
            'Property Address',
            'Client Notes',
            'Admin Notes',
            'Created At',
        ));

        // Data rows
        foreach ($appointments as $apt) {
            fputcsv($output, array(
                $apt['id'],
                $apt['type_name'],
                $apt['client_name'],
                $apt['client_email'],
                $apt['client_phone'],
                snab_format_date($apt['appointment_date']),
                snab_format_time($apt['appointment_date'], $apt['start_time'], 'g:i A'),
                snab_format_time($apt['appointment_date'], $apt['end_time'], 'g:i A'),
                ucfirst($apt['status']),
                $apt['property_address'],
                $apt['client_notes'],
                $apt['admin_notes'],
                $apt['created_at'],
            ));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Validate date format.
     *
     * @param string $date Date string.
     * @return bool True if valid.
     */
    private function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Render the analytics page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        // Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        // Enqueue admin analytics JS
        wp_enqueue_script(
            'snab-admin-analytics',
            SNAB_PLUGIN_URL . 'assets/js/admin-analytics.js',
            array('jquery', 'chartjs'),
            SNAB_VERSION,
            true
        );

        wp_localize_script('snab-admin-analytics', 'snabAnalytics', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snab_admin_nonce'),
            'i18n' => array(
                'loading' => __('Loading...', 'sn-appointment-booking'),
                'error' => __('Error loading data', 'sn-appointment-booking'),
                'noData' => __('No data available', 'sn-appointment-booking'),
                'appointments' => __('Appointments', 'sn-appointment-booking'),
                'completed' => __('Completed', 'sn-appointment-booking'),
                'cancelled' => __('Cancelled', 'sn-appointment-booking'),
            ),
        ));

        // Default date range: last 30 days + next 30 days (to include upcoming appointments)
        $end_date = wp_date('Y-m-d', strtotime('+30 days'));
        $start_date = wp_date('Y-m-d', strtotime('-30 days'));

        ?>
        <div class="wrap snab-admin-wrap snab-analytics-wrap">
            <h1><?php esc_html_e('Analytics', 'sn-appointment-booking'); ?></h1>

            <!-- Date Range Selector -->
            <div class="snab-analytics-controls">
                <div class="snab-date-range">
                    <label for="snab-start-date"><?php esc_html_e('From:', 'sn-appointment-booking'); ?></label>
                    <input type="date" id="snab-start-date" value="<?php echo esc_attr($start_date); ?>">

                    <label for="snab-end-date"><?php esc_html_e('To:', 'sn-appointment-booking'); ?></label>
                    <input type="date" id="snab-end-date" value="<?php echo esc_attr($end_date); ?>">

                    <button type="button" class="button" id="snab-apply-dates">
                        <?php esc_html_e('Apply', 'sn-appointment-booking'); ?>
                    </button>
                </div>

                <div class="snab-quick-ranges">
                    <button type="button" class="button" data-range="upcoming"><?php esc_html_e('Upcoming', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button" data-range="7"><?php esc_html_e('Last 7 Days', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button" data-range="30"><?php esc_html_e('Last 30 Days', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button" data-range="90"><?php esc_html_e('Last 90 Days', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button" data-range="all"><?php esc_html_e('All Time', 'sn-appointment-booking'); ?></button>
                </div>

                <div class="snab-export-controls">
                    <button type="button" class="button" id="snab-export-csv">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export CSV', 'sn-appointment-booking'); ?>
                    </button>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div class="snab-analytics-loading" id="snab-loading">
                <span class="spinner is-active"></span>
                <?php esc_html_e('Loading analytics...', 'sn-appointment-booking'); ?>
            </div>

            <!-- Key Metrics Cards -->
            <div class="snab-metrics-grid" id="snab-metrics">
                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-total">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-total">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('Total Appointments', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>

                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-completed">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-completion">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('Completion Rate', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>

                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-noshow">
                        <span class="dashicons dashicons-dismiss"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-noshow">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('No-Show Rate', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>

                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-cancelled">
                        <span class="dashicons dashicons-no"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-cancelled">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('Cancellation Rate', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>

                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-leadtime">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-leadtime">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('Avg Lead Time (days)', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>

                <div class="snab-metric-card">
                    <div class="snab-metric-icon snab-icon-clients">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="snab-metric-content">
                        <span class="snab-metric-value" id="metric-clients">-</span>
                        <span class="snab-metric-label"><?php esc_html_e('Unique Clients', 'sn-appointment-booking'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="snab-charts-row">
                <div class="snab-chart-card snab-chart-wide">
                    <h3><?php esc_html_e('Appointment Trend', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-chart-container">
                        <canvas id="chart-trend"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="snab-charts-row">
                <div class="snab-chart-card">
                    <h3><?php esc_html_e('By Day of Week', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-chart-container">
                        <canvas id="chart-day"></canvas>
                    </div>
                </div>

                <div class="snab-chart-card">
                    <h3><?php esc_html_e('By Hour', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-chart-container">
                        <canvas id="chart-hour"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 3 -->
            <div class="snab-charts-row">
                <div class="snab-chart-card">
                    <h3><?php esc_html_e('By Type', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-chart-container">
                        <canvas id="chart-type"></canvas>
                    </div>
                </div>

                <div class="snab-chart-card">
                    <h3><?php esc_html_e('By Status', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-chart-container">
                        <canvas id="chart-status"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tables Row -->
            <div class="snab-tables-row">
                <div class="snab-table-card">
                    <h3><?php esc_html_e('Top Clients', 'sn-appointment-booking'); ?></h3>
                    <table class="widefat striped" id="table-clients">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Client', 'sn-appointment-booking'); ?></th>
                                <th><?php esc_html_e('Appointments', 'sn-appointment-booking'); ?></th>
                                <th><?php esc_html_e('Completed', 'sn-appointment-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="3" class="snab-loading-cell"><?php esc_html_e('Loading...', 'sn-appointment-booking'); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="snab-table-card">
                    <h3><?php esc_html_e('Popular Time Slots', 'sn-appointment-booking'); ?></h3>
                    <table class="widefat striped" id="table-slots">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Day', 'sn-appointment-booking'); ?></th>
                                <th><?php esc_html_e('Time', 'sn-appointment-booking'); ?></th>
                                <th><?php esc_html_e('Bookings', 'sn-appointment-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="3" class="snab-loading-cell"><?php esc_html_e('Loading...', 'sn-appointment-booking'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <style>
            .snab-analytics-wrap {
                max-width: 1400px;
            }

            .snab-analytics-controls {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                align-items: center;
                margin: 20px 0;
                padding: 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
            }

            .snab-date-range {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .snab-date-range input[type="date"] {
                padding: 6px 10px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }

            .snab-quick-ranges {
                display: flex;
                gap: 8px;
            }

            .snab-quick-ranges .button.active {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }

            .snab-export-controls {
                margin-left: auto;
            }

            .snab-export-controls .dashicons {
                margin-right: 4px;
                vertical-align: middle;
            }

            .snab-analytics-loading {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 40px;
                justify-content: center;
                color: #666;
            }

            .snab-analytics-loading.hidden {
                display: none;
            }

            /* Metrics Grid */
            .snab-metrics-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .snab-metric-card {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }

            .snab-metric-icon {
                width: 50px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
            }

            .snab-metric-icon .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                color: #fff;
            }

            .snab-icon-total { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
            .snab-icon-completed { background: linear-gradient(135deg, #10b981, #059669); }
            .snab-icon-noshow { background: linear-gradient(135deg, #f59e0b, #d97706); }
            .snab-icon-cancelled { background: linear-gradient(135deg, #ef4444, #dc2626); }
            .snab-icon-leadtime { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
            .snab-icon-clients { background: linear-gradient(135deg, #06b6d4, #0891b2); }

            .snab-metric-content {
                display: flex;
                flex-direction: column;
            }

            .snab-metric-value {
                font-size: 1.75rem;
                font-weight: 700;
                color: #1f2937;
                line-height: 1.2;
            }

            .snab-metric-label {
                font-size: 0.875rem;
                color: #6b7280;
            }

            /* Charts */
            .snab-charts-row {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-bottom: 20px;
            }

            .snab-chart-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 20px;
            }

            .snab-chart-card h3 {
                margin: 0 0 16px;
                font-size: 1rem;
                color: #1f2937;
            }

            .snab-chart-card.snab-chart-wide {
                grid-column: span 2;
            }

            .snab-chart-container {
                position: relative;
                height: 280px;
            }

            .snab-chart-card.snab-chart-wide .snab-chart-container {
                height: 300px;
            }

            /* Tables */
            .snab-tables-row {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .snab-table-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 20px;
            }

            .snab-table-card h3 {
                margin: 0 0 16px;
                font-size: 1rem;
                color: #1f2937;
            }

            .snab-table-card table {
                border: none;
            }

            .snab-loading-cell {
                text-align: center;
                color: #666;
                padding: 20px !important;
            }

            @media (max-width: 1200px) {
                .snab-charts-row {
                    grid-template-columns: 1fr;
                }
                .snab-chart-card.snab-chart-wide {
                    grid-column: span 1;
                }
                .snab-tables-row {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 768px) {
                .snab-analytics-controls {
                    flex-direction: column;
                    align-items: stretch;
                }
                .snab-date-range {
                    flex-wrap: wrap;
                }
                .snab-quick-ranges {
                    flex-wrap: wrap;
                }
                .snab-export-controls {
                    margin-left: 0;
                }
            }
        </style>
        <?php
    }
}
