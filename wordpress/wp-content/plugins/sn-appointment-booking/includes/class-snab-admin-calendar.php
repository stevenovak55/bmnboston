<?php
/**
 * Admin Calendar Class
 *
 * Visual calendar preview for appointments using FullCalendar.js
 *
 * @package SN_Appointment_Booking
 * @since 1.6.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Calendar class.
 *
 * @since 1.6.0
 */
class SNAB_Admin_Calendar {

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_snab_calendar_get_events', array($this, 'ajax_get_events'));
    }

    /**
     * Render the calendar page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        $staff = $this->get_staff_members();
        $types = $this->get_appointment_types();
        ?>
        <div class="wrap snab-admin-wrap">
            <h1><?php esc_html_e('Calendar', 'sn-appointment-booking'); ?></h1>

            <div class="snab-calendar-controls">
                <div class="snab-filter-group">
                    <label for="snab-calendar-staff"><?php esc_html_e('Staff:', 'sn-appointment-booking'); ?></label>
                    <select id="snab-calendar-staff">
                        <option value=""><?php esc_html_e('All Staff', 'sn-appointment-booking'); ?></option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?php echo esc_attr($member->id); ?>">
                                <?php echo esc_html($member->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="snab-filter-group">
                    <label for="snab-calendar-type"><?php esc_html_e('Type:', 'sn-appointment-booking'); ?></label>
                    <select id="snab-calendar-type">
                        <option value=""><?php esc_html_e('All Types', 'sn-appointment-booking'); ?></option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>" data-color="<?php echo esc_attr($type->color); ?>">
                                <?php echo esc_html($type->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="snab-filter-group">
                    <label for="snab-calendar-status"><?php esc_html_e('Status:', 'sn-appointment-booking'); ?></label>
                    <select id="snab-calendar-status">
                        <option value=""><?php esc_html_e('All', 'sn-appointment-booking'); ?></option>
                        <option value="pending"><?php esc_html_e('Pending', 'sn-appointment-booking'); ?></option>
                        <option value="confirmed"><?php esc_html_e('Confirmed', 'sn-appointment-booking'); ?></option>
                        <option value="completed"><?php esc_html_e('Completed', 'sn-appointment-booking'); ?></option>
                        <option value="cancelled"><?php esc_html_e('Cancelled', 'sn-appointment-booking'); ?></option>
                        <option value="no_show"><?php esc_html_e('No Show', 'sn-appointment-booking'); ?></option>
                    </select>
                </div>
            </div>

            <div class="snab-calendar-legend">
                <?php foreach ($types as $type): ?>
                    <span class="snab-legend-item" style="--type-color: <?php echo esc_attr($type->color); ?>">
                        <span class="snab-legend-color"></span>
                        <?php echo esc_html($type->name); ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div id="snab-calendar"></div>
        </div>

        <!-- Event Detail Modal -->
        <div id="snab-event-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-overlay"></div>
            <div class="snab-modal-content">
                <div class="snab-modal-header">
                    <h2 id="snab-event-modal-title"><?php esc_html_e('Appointment Details', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body" id="snab-event-details">
                    <!-- Populated by JavaScript -->
                </div>
                <div class="snab-modal-footer">
                    <a href="#" id="snab-event-edit-link" class="button button-primary">
                        <?php esc_html_e('Edit Appointment', 'sn-appointment-booking'); ?>
                    </a>
                    <button type="button" class="button snab-modal-close">
                        <?php esc_html_e('Close', 'sn-appointment-booking'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .snab-calendar-controls {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccd0d4;
            }

            .snab-filter-group {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .snab-filter-group label {
                font-weight: 500;
            }

            .snab-filter-group select {
                min-width: 150px;
            }

            .snab-calendar-legend {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-bottom: 15px;
                padding: 10px 15px;
                background: #f8f9fa;
                border: 1px solid #e2e4e7;
                border-radius: 4px;
            }

            .snab-legend-item {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
            }

            .snab-legend-color {
                width: 14px;
                height: 14px;
                border-radius: 3px;
                background: var(--type-color, #3788d8);
            }

            #snab-calendar {
                background: #fff;
                padding: 15px;
                border: 1px solid #ccd0d4;
            }

            .fc .fc-toolbar-title {
                font-size: 1.4em;
            }

            .fc-event {
                cursor: pointer;
                border: none;
                padding: 2px 4px;
            }

            .fc-event-title {
                font-weight: 500;
            }

            .fc-daygrid-event {
                border-radius: 3px;
            }

            .fc-timegrid-event {
                border-radius: 3px;
            }

            .snab-event-cancelled {
                opacity: 0.5;
                text-decoration: line-through;
            }

            .snab-event-pending {
                border-left: 3px solid #ffc107 !important;
            }

            .snab-event-confirmed {
                border-left: 3px solid #28a745 !important;
            }

            .snab-event-completed {
                border-left: 3px solid #6c757d !important;
            }

            .snab-event-no_show {
                border-left: 3px solid #dc3545 !important;
            }

            .snab-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .snab-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
            }

            .snab-modal-content {
                position: relative;
                background: #fff;
                border-radius: 4px;
                max-width: 500px;
                width: 90%;
                max-height: 90vh;
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            .snab-modal-header {
                padding: 15px 20px;
                border-bottom: 1px solid #dcdcde;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .snab-modal-header h2 {
                margin: 0;
            }

            .snab-modal-close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #666;
            }

            .snab-modal-body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }

            .snab-modal-footer {
                padding: 15px 20px;
                border-top: 1px solid #dcdcde;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }

            .snab-event-detail {
                margin-bottom: 12px;
            }

            .snab-event-detail-label {
                font-weight: 600;
                color: #1d2327;
                margin-bottom: 3px;
            }

            .snab-event-detail-value {
                color: #50575e;
            }

            .snab-event-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
            }

            .snab-event-status-pending {
                background: #fff3cd;
                color: #856404;
            }

            .snab-event-status-confirmed {
                background: #d4edda;
                color: #155724;
            }

            .snab-event-status-completed {
                background: #e2e3e5;
                color: #383d41;
            }

            .snab-event-status-cancelled {
                background: #f8d7da;
                color: #721c24;
            }

            .snab-event-status-no_show {
                background: #f8d7da;
                color: #721c24;
            }
        </style>

        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('snab-calendar');
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';
            var appointmentsUrl = '<?php echo admin_url('admin.php?page=snab-appointments&appointment_id='); ?>';

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                navLinks: true,
                editable: false,
                dayMaxEvents: true,
                events: function(info, successCallback, failureCallback) {
                    var filters = {
                        action: 'snab_calendar_get_events',
                        nonce: nonce,
                        start: info.startStr,
                        end: info.endStr,
                        staff_id: document.getElementById('snab-calendar-staff').value,
                        type_id: document.getElementById('snab-calendar-type').value,
                        status: document.getElementById('snab-calendar-status').value
                    };

                    jQuery.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: filters,
                        success: function(response) {
                            if (response.success) {
                                successCallback(response.data);
                            } else {
                                failureCallback();
                            }
                        },
                        error: function() {
                            failureCallback();
                        }
                    });
                },
                eventClick: function(info) {
                    showEventDetails(info.event);
                },
                eventClassNames: function(arg) {
                    var classes = ['snab-event-' + arg.event.extendedProps.status];
                    if (arg.event.extendedProps.status === 'cancelled') {
                        classes.push('snab-event-cancelled');
                    }
                    return classes;
                }
            });

            calendar.render();

            // Filter change handlers
            document.getElementById('snab-calendar-staff').addEventListener('change', function() {
                calendar.refetchEvents();
            });
            document.getElementById('snab-calendar-type').addEventListener('change', function() {
                calendar.refetchEvents();
            });
            document.getElementById('snab-calendar-status').addEventListener('change', function() {
                calendar.refetchEvents();
            });

            // Show event details modal
            function showEventDetails(event) {
                var props = event.extendedProps;
                var statusClass = 'snab-event-status-' + props.status;

                var html = '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Client', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value">' + escapeHtml(props.client_name) + '</div>' +
                '</div>' +
                '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Email', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value"><a href="mailto:' + escapeHtml(props.client_email) + '">' + escapeHtml(props.client_email) + '</a></div>' +
                '</div>' +
                '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Type', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value">' + escapeHtml(props.type_name) + '</div>' +
                '</div>' +
                '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Date & Time', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value">' + escapeHtml(props.formatted_datetime) + '</div>' +
                '</div>' +
                '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Staff', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value">' + escapeHtml(props.staff_name) + '</div>' +
                '</div>' +
                '<div class="snab-event-detail">' +
                    '<div class="snab-event-detail-label"><?php echo esc_js(__('Status', 'sn-appointment-booking')); ?></div>' +
                    '<div class="snab-event-detail-value"><span class="snab-event-status-badge ' + statusClass + '">' + escapeHtml(props.status) + '</span></div>' +
                '</div>';

                if (props.notes) {
                    html += '<div class="snab-event-detail">' +
                        '<div class="snab-event-detail-label"><?php echo esc_js(__('Notes', 'sn-appointment-booking')); ?></div>' +
                        '<div class="snab-event-detail-value">' + escapeHtml(props.notes) + '</div>' +
                    '</div>';
                }

                document.getElementById('snab-event-details').innerHTML = html;
                document.getElementById('snab-event-edit-link').href = appointmentsUrl + event.id;
                document.getElementById('snab-event-modal').style.display = 'flex';
            }

            // Close modal
            document.querySelectorAll('.snab-modal-close, .snab-modal-overlay').forEach(function(el) {
                el.addEventListener('click', function() {
                    document.getElementById('snab-event-modal').style.display = 'none';
                });
            });

            // Escape HTML helper
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
        </script>
        <?php
    }

    /**
     * Get staff members.
     *
     * @return array
     */
    private function get_staff_members() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}snab_staff WHERE is_active = 1 ORDER BY name ASC"
        );
    }

    /**
     * Get appointment types.
     *
     * @return array
     */
    private function get_appointment_types() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, color FROM {$wpdb->prefix}snab_appointment_types WHERE is_active = 1 ORDER BY sort_order ASC"
        );
    }

    /**
     * AJAX: Get calendar events.
     */
    public function ajax_get_events() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
        $end = isset($_POST['end']) ? sanitize_text_field($_POST['end']) : '';
        $staff_id = isset($_POST['staff_id']) && $_POST['staff_id'] ? absint($_POST['staff_id']) : 0;
        $type_id = isset($_POST['type_id']) && $_POST['type_id'] ? absint($_POST['type_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

        // Build query
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $staff_table = $wpdb->prefix . 'snab_staff';

        $where = array("a.appointment_date >= %s", "a.appointment_date <= %s");
        $params = array(substr($start, 0, 10), substr($end, 0, 10));

        if ($staff_id) {
            $where[] = "a.staff_id = %d";
            $params[] = $staff_id;
        }

        if ($type_id) {
            $where[] = "a.appointment_type_id = %d";
            $params[] = $type_id;
        }

        if ($status) {
            $where[] = "a.status = %s";
            $params[] = $status;
        }

        $where_sql = implode(' AND ', $where);

        $query = $wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color, s.name as staff_name
             FROM {$appointments_table} a
             LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
             LEFT JOIN {$staff_table} s ON a.staff_id = s.id
             WHERE {$where_sql}
             ORDER BY a.appointment_date ASC, a.start_time ASC",
            ...$params
        );

        $appointments = $wpdb->get_results($query);

        // Format for FullCalendar
        $events = array();
        foreach ($appointments as $apt) {
            $start_datetime = $apt->appointment_date . 'T' . $apt->start_time;
            $end_datetime = $apt->appointment_date . 'T' . $apt->end_time;

            $events[] = array(
                'id' => $apt->id,
                'title' => $apt->client_name . ' - ' . $apt->type_name,
                'start' => $start_datetime,
                'end' => $end_datetime,
                'backgroundColor' => $apt->type_color ?: '#3788d8',
                'borderColor' => $apt->type_color ?: '#3788d8',
                'extendedProps' => array(
                    'client_name' => $apt->client_name,
                    'client_email' => $apt->client_email,
                    'client_phone' => $apt->client_phone,
                    'type_name' => $apt->type_name,
                    'staff_name' => $apt->staff_name ?: __('Not assigned', 'sn-appointment-booking'),
                    'status' => $apt->status,
                    'notes' => $apt->notes,
                    'formatted_datetime' => snab_format_date($apt->appointment_date) . ' ' . snab_format_time($apt->appointment_date, $apt->start_time) . ' - ' . snab_format_time($apt->appointment_date, $apt->end_time),
                ),
            );
        }

        wp_send_json_success($events);
    }
}
