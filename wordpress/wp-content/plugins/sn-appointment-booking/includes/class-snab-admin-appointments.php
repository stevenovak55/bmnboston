<?php
/**
 * Admin Appointments Class
 *
 * Handles the appointments management page in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Appointments class.
 *
 * @since 1.0.0
 */
class SNAB_Admin_Appointments {

    /**
     * Items per page.
     */
    const PER_PAGE = 20;

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_snab_get_appointment', array($this, 'ajax_get_appointment'));
        add_action('wp_ajax_snab_update_appointment_status', array($this, 'ajax_update_status'));
        add_action('wp_ajax_snab_cancel_appointment', array($this, 'ajax_cancel_appointment'));
        add_action('wp_ajax_snab_save_admin_notes', array($this, 'ajax_save_admin_notes'));
        add_action('wp_ajax_snab_export_appointments', array($this, 'ajax_export_csv'));
        add_action('wp_ajax_snab_create_appointment', array($this, 'ajax_create_appointment'));
        add_action('wp_ajax_snab_reschedule_appointment', array($this, 'ajax_reschedule_appointment'));
        add_action('wp_ajax_snab_get_available_slots', array($this, 'ajax_get_available_slots'));
    }

    /**
     * Render the appointments page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        // Get filter values
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $type_filter = isset($_GET['type']) ? absint($_GET['type']) : 0;
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        // Get appointments
        $result = $this->get_appointments(array(
            'status' => $status_filter,
            'type_id' => $type_filter,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'search' => $search,
            'paged' => $paged,
            'per_page' => self::PER_PAGE,
        ));

        $appointments = $result['items'];
        $total = $result['total'];
        $total_pages = ceil($total / self::PER_PAGE);

        // Get appointment types for filter dropdown
        $types = $this->get_appointment_types();

        // Get status counts
        $status_counts = $this->get_status_counts();

        ?>
        <div class="wrap snab-admin-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'sn-appointment-booking'); ?></h1>

            <a href="#" class="page-title-action snab-create-btn" id="snab-create-appointment">
                <?php esc_html_e('Create Appointment', 'sn-appointment-booking'); ?>
            </a>
            <a href="#" class="page-title-action snab-export-btn" id="snab-export-csv">
                <?php esc_html_e('Export CSV', 'sn-appointment-booking'); ?>
            </a>

            <hr class="wp-header-end">

            <!-- Status Tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url(remove_query_arg('status')); ?>"
                       class="<?php echo empty($status_filter) ? 'current' : ''; ?>">
                        <?php esc_html_e('All', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['all']); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', 'confirmed')); ?>"
                       class="<?php echo $status_filter === 'confirmed' ? 'current' : ''; ?>">
                        <?php esc_html_e('Confirmed', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['confirmed']); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', 'pending')); ?>"
                       class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">
                        <?php esc_html_e('Pending', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['pending']); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', 'completed')); ?>"
                       class="<?php echo $status_filter === 'completed' ? 'current' : ''; ?>">
                        <?php esc_html_e('Completed', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['completed']); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', 'cancelled')); ?>"
                       class="<?php echo $status_filter === 'cancelled' ? 'current' : ''; ?>">
                        <?php esc_html_e('Cancelled', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['cancelled']); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', 'no_show')); ?>"
                       class="<?php echo $status_filter === 'no_show' ? 'current' : ''; ?>">
                        <?php esc_html_e('No Show', 'sn-appointment-booking'); ?>
                        <span class="count">(<?php echo esc_html($status_counts['no_show']); ?>)</span>
                    </a>
                </li>
            </ul>

            <!-- Filters -->
            <form method="get" class="snab-filters-form">
                <input type="hidden" name="page" value="snab-appointments">
                <?php if ($status_filter): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>

                <div class="snab-filters">
                    <select name="type" class="snab-filter-type">
                        <option value=""><?php esc_html_e('All Types', 'sn-appointment-booking'); ?></option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>" <?php selected($type_filter, $type->id); ?>>
                                <?php echo esc_html($type->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"
                           placeholder="<?php esc_attr_e('From Date', 'sn-appointment-booking'); ?>">
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>"
                           placeholder="<?php esc_attr_e('To Date', 'sn-appointment-booking'); ?>">

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="<?php esc_attr_e('Search client...', 'sn-appointment-booking'); ?>">

                    <button type="submit" class="button"><?php esc_html_e('Filter', 'sn-appointment-booking'); ?></button>

                    <?php if ($type_filter || $date_from || $date_to || $search): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=snab-appointments' . ($status_filter ? '&status=' . $status_filter : ''))); ?>"
                           class="button">
                            <?php esc_html_e('Clear', 'sn-appointment-booking'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Appointments Table -->
            <table class="wp-list-table widefat fixed striped snab-appointments-table">
                <thead>
                    <tr>
                        <th class="column-date"><?php esc_html_e('Date & Time', 'sn-appointment-booking'); ?></th>
                        <th class="column-type"><?php esc_html_e('Type', 'sn-appointment-booking'); ?></th>
                        <th class="column-client"><?php esc_html_e('Client', 'sn-appointment-booking'); ?></th>
                        <th class="column-status"><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                        <th class="column-gcal"><?php esc_html_e('Calendar', 'sn-appointment-booking'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Actions', 'sn-appointment-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="6" class="snab-no-items">
                                <?php esc_html_e('No appointments found.', 'sn-appointment-booking'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $apt): ?>
                            <?php
                            $is_past = snab_datetime_to_timestamp($apt->appointment_date, $apt->end_time) < current_time('timestamp');
                            $row_class = $is_past ? 'snab-past-appointment' : '';
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>" data-id="<?php echo esc_attr($apt->id); ?>">
                                <td class="column-date">
                                    <strong><?php echo esc_html(snab_format_date($apt->appointment_date)); ?></strong>
                                    <br>
                                    <span class="snab-time">
                                        <?php echo esc_html(snab_format_time($apt->appointment_date, $apt->start_time)); ?>
                                        -
                                        <?php echo esc_html(snab_format_time($apt->appointment_date, $apt->end_time)); ?>
                                    </span>
                                </td>
                                <td class="column-type">
                                    <span class="snab-type-badge" style="background-color: <?php echo esc_attr($apt->type_color); ?>">
                                        <?php echo esc_html($apt->type_name); ?>
                                    </span>
                                </td>
                                <td class="column-client">
                                    <strong><?php echo esc_html($apt->client_name); ?></strong>
                                    <br>
                                    <a href="mailto:<?php echo esc_attr($apt->client_email); ?>">
                                        <?php echo esc_html($apt->client_email); ?>
                                    </a>
                                    <?php if ($apt->client_phone): ?>
                                        <br>
                                        <a href="tel:<?php echo esc_attr($apt->client_phone); ?>">
                                            <?php echo esc_html($apt->client_phone); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="column-status">
                                    <span class="snab-status snab-status-<?php echo esc_attr($apt->status); ?>">
                                        <?php echo esc_html($this->get_status_label($apt->status)); ?>
                                    </span>
                                </td>
                                <td class="column-gcal">
                                    <?php if ($apt->google_calendar_synced): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"
                                              title="<?php esc_attr_e('Synced to Google Calendar', 'sn-appointment-booking'); ?>"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-minus" style="color: #999;"
                                              title="<?php esc_attr_e('Not synced', 'sn-appointment-booking'); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions">
                                    <button type="button" class="button button-small snab-view-btn"
                                            data-id="<?php echo esc_attr($apt->id); ?>">
                                        <?php esc_html_e('View', 'sn-appointment-booking'); ?>
                                    </button>
                                    <?php if (in_array($apt->status, array('pending', 'confirmed')) && !$is_past): ?>
                                        <button type="button" class="button button-small snab-reschedule-btn"
                                                data-id="<?php echo esc_attr($apt->id); ?>">
                                            <?php esc_html_e('Reschedule', 'sn-appointment-booking'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($apt->status === 'confirmed' && !$is_past): ?>
                                        <button type="button" class="button button-small snab-complete-btn"
                                                data-id="<?php echo esc_attr($apt->id); ?>">
                                            <?php esc_html_e('Complete', 'sn-appointment-booking'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($apt->status, array('pending', 'confirmed')) && !$is_past): ?>
                                        <button type="button" class="button button-small snab-cancel-btn"
                                                data-id="<?php echo esc_attr($apt->id); ?>">
                                            <?php esc_html_e('Cancel', 'sn-appointment-booking'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(
                                _n('%s item', '%s items', $total, 'sn-appointment-booking'),
                                number_format_i18n($total)
                            )); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'snab-appointments',
                                'status' => $status_filter,
                                'type' => $type_filter,
                                'date_from' => $date_from,
                                'date_to' => $date_to,
                                's' => $search,
                            ), admin_url('admin.php'));

                            // First page
                            if ($paged > 1) {
                                echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">&laquo;</a>';
                                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '">&lsaquo;</a>';
                            } else {
                                echo '<span class="first-page button disabled">&laquo;</span>';
                                echo '<span class="prev-page button disabled">&lsaquo;</span>';
                            }

                            echo '<span class="paging-input">' . $paged . ' / ' . $total_pages . '</span>';

                            // Last page
                            if ($paged < $total_pages) {
                                echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">&rsaquo;</a>';
                                echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">&raquo;</a>';
                            } else {
                                echo '<span class="next-page button disabled">&rsaquo;</span>';
                                echo '<span class="last-page button disabled">&raquo;</span>';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- View/Edit Modal -->
        <div id="snab-appointment-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-content snab-modal-large">
                <div class="snab-modal-header">
                    <h2><?php esc_html_e('Appointment Details', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body">
                    <div class="snab-modal-loading">
                        <span class="spinner is-active"></span>
                    </div>
                    <div class="snab-appointment-details" style="display: none;"></div>
                </div>
            </div>
        </div>

        <!-- Cancel Modal -->
        <div id="snab-cancel-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-content">
                <div class="snab-modal-header">
                    <h2><?php esc_html_e('Cancel Appointment', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body">
                    <p><?php esc_html_e('Are you sure you want to cancel this appointment?', 'sn-appointment-booking'); ?></p>
                    <div class="snab-form-row">
                        <label for="snab-cancel-reason"><?php esc_html_e('Reason (optional)', 'sn-appointment-booking'); ?></label>
                        <textarea id="snab-cancel-reason" rows="3" placeholder="<?php esc_attr_e('Enter cancellation reason...', 'sn-appointment-booking'); ?>"></textarea>
                    </div>
                    <div class="snab-form-row">
                        <label>
                            <input type="checkbox" id="snab-send-cancel-email" checked>
                            <?php esc_html_e('Send cancellation email to client', 'sn-appointment-booking'); ?>
                        </label>
                    </div>
                </div>
                <div class="snab-modal-footer">
                    <button type="button" class="button snab-modal-close"><?php esc_html_e('Keep Appointment', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button button-primary snab-confirm-cancel"><?php esc_html_e('Cancel Appointment', 'sn-appointment-booking'); ?></button>
                </div>
            </div>
        </div>

        <!-- Create Appointment Modal -->
        <div id="snab-create-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-content snab-modal-large">
                <div class="snab-modal-header">
                    <h2><?php esc_html_e('Create Appointment', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body">
                    <form id="snab-create-form">
                        <div class="snab-form-grid">
                            <div class="snab-form-section">
                                <h3><?php esc_html_e('Client Information', 'sn-appointment-booking'); ?></h3>
                                <div class="snab-form-row">
                                    <label for="snab-create-name"><?php esc_html_e('Client Name', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="text" id="snab-create-name" name="client_name" required>
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-email"><?php esc_html_e('Email', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="email" id="snab-create-email" name="client_email" required>
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-phone"><?php esc_html_e('Phone', 'sn-appointment-booking'); ?></label>
                                    <input type="tel" id="snab-create-phone" name="client_phone">
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-address"><?php esc_html_e('Property Address', 'sn-appointment-booking'); ?></label>
                                    <textarea id="snab-create-address" name="property_address" rows="2"></textarea>
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-notes"><?php esc_html_e('Notes', 'sn-appointment-booking'); ?></label>
                                    <textarea id="snab-create-notes" name="client_notes" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="snab-form-section">
                                <h3><?php esc_html_e('Appointment Details', 'sn-appointment-booking'); ?></h3>
                                <div class="snab-form-row">
                                    <label for="snab-create-type"><?php esc_html_e('Appointment Type', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <select id="snab-create-type" name="appointment_type_id" required>
                                        <option value=""><?php esc_html_e('Select type...', 'sn-appointment-booking'); ?></option>
                                        <?php foreach ($types as $type): ?>
                                            <option value="<?php echo esc_attr($type->id); ?>">
                                                <?php echo esc_html($type->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-date"><?php esc_html_e('Date', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="date" id="snab-create-date" name="appointment_date" required min="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                                </div>
                                <div class="snab-form-row">
                                    <label for="snab-create-time"><?php esc_html_e('Time', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <select id="snab-create-time" name="start_time" required disabled>
                                        <option value=""><?php esc_html_e('Select date first...', 'sn-appointment-booking'); ?></option>
                                    </select>
                                    <p class="description snab-time-hint" style="display: none;"><?php esc_html_e('Available slots based on schedule', 'sn-appointment-booking'); ?></p>
                                </div>
                                <div class="snab-form-row snab-checkbox-row">
                                    <label>
                                        <input type="checkbox" id="snab-create-send-email" name="send_confirmation" value="1" checked>
                                        <?php esc_html_e('Send confirmation email to client', 'sn-appointment-booking'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="snab-modal-footer">
                    <button type="button" class="button snab-modal-close"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button button-primary snab-submit-create"><?php esc_html_e('Create Appointment', 'sn-appointment-booking'); ?></button>
                </div>
            </div>
        </div>

        <!-- Reschedule Modal -->
        <div id="snab-reschedule-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-content snab-modal-medium">
                <div class="snab-modal-header">
                    <h2><?php esc_html_e('Reschedule Appointment', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body">
                    <div class="snab-reschedule-current">
                        <h4><?php esc_html_e('Current Appointment', 'sn-appointment-booking'); ?></h4>
                        <div class="snab-current-details"></div>
                    </div>
                    <hr>
                    <form id="snab-reschedule-form">
                        <input type="hidden" id="snab-reschedule-id" name="appointment_id">
                        <h4><?php esc_html_e('New Date & Time', 'sn-appointment-booking'); ?></h4>
                        <div class="snab-form-row">
                            <label for="snab-reschedule-date"><?php esc_html_e('New Date', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                            <input type="date" id="snab-reschedule-date" name="new_date" required min="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                        </div>
                        <div class="snab-form-row">
                            <label for="snab-reschedule-time"><?php esc_html_e('New Time', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                            <select id="snab-reschedule-time" name="new_time" required disabled>
                                <option value=""><?php esc_html_e('Select date first...', 'sn-appointment-booking'); ?></option>
                            </select>
                        </div>
                        <div class="snab-form-row">
                            <label for="snab-reschedule-reason"><?php esc_html_e('Reason for Reschedule (optional)', 'sn-appointment-booking'); ?></label>
                            <textarea id="snab-reschedule-reason" name="reason" rows="2" placeholder="<?php esc_attr_e('Enter reason...', 'sn-appointment-booking'); ?>"></textarea>
                        </div>
                        <div class="snab-form-row snab-checkbox-row">
                            <label>
                                <input type="checkbox" id="snab-reschedule-send-email" name="send_notification" value="1" checked>
                                <?php esc_html_e('Send reschedule notification to client', 'sn-appointment-booking'); ?>
                            </label>
                        </div>
                    </form>
                </div>
                <div class="snab-modal-footer">
                    <button type="button" class="button snab-modal-close"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button button-primary snab-submit-reschedule"><?php esc_html_e('Reschedule', 'sn-appointment-booking'); ?></button>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * Get appointments with filters.
     *
     * @param array $args Query arguments.
     * @return array
     */
    private function get_appointments($args) {
        global $wpdb;

        $defaults = array(
            'status' => '',
            'type_id' => 0,
            'date_from' => '',
            'date_to' => '',
            'search' => '',
            'paged' => 1,
            'per_page' => self::PER_PAGE,
        );

        $args = wp_parse_args($args, $defaults);

        $table_appointments = $wpdb->prefix . 'snab_appointments';
        $table_types = $wpdb->prefix . 'snab_appointment_types';

        $where = array('1=1');
        $values = array();

        // Status filter
        if (!empty($args['status'])) {
            $where[] = 'a.status = %s';
            $values[] = $args['status'];
        }

        // Type filter
        if (!empty($args['type_id'])) {
            $where[] = 'a.appointment_type_id = %d';
            $values[] = $args['type_id'];
        }

        // Date range filter
        if (!empty($args['date_from'])) {
            $where[] = 'a.appointment_date >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'a.appointment_date <= %s';
            $values[] = $args['date_to'];
        }

        // Search
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(a.client_name LIKE %s OR a.client_email LIKE %s OR a.client_phone LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $where_sql = implode(' AND ', $where);

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$table_appointments} a WHERE {$where_sql}";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, $values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get items
        $offset = ($args['paged'] - 1) * $args['per_page'];

        $sql = "SELECT a.*, t.name as type_name, t.color as type_color
                FROM {$table_appointments} a
                JOIN {$table_types} t ON a.appointment_type_id = t.id
                WHERE {$where_sql}
                ORDER BY a.appointment_date DESC, a.start_time DESC
                LIMIT %d OFFSET %d";

        $values[] = $args['per_page'];
        $values[] = $offset;

        $items = $wpdb->get_results($wpdb->prepare($sql, $values));

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    /**
     * Get appointment types.
     *
     * @return array
     */
    private function get_appointment_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_types';
        return $wpdb->get_results("SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY sort_order, name");
    }

    /**
     * Get status counts.
     *
     * @return array
     */
    private function get_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status"
        );

        $counts = array(
            'all' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'no_show' => 0,
        );

        foreach ($results as $row) {
            $counts[$row->status] = (int) $row->count;
            $counts['all'] += (int) $row->count;
        }

        return $counts;
    }

    /**
     * Get status label.
     *
     * @param string $status Status key.
     * @return string
     */
    private function get_status_label($status) {
        $labels = array(
            'pending' => __('Pending', 'sn-appointment-booking'),
            'confirmed' => __('Confirmed', 'sn-appointment-booking'),
            'completed' => __('Completed', 'sn-appointment-booking'),
            'cancelled' => __('Cancelled', 'sn-appointment-booking'),
            'no_show' => __('No Show', 'sn-appointment-booking'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * AJAX: Get appointment details.
     */
    public function ajax_get_appointment() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color, t.duration_minutes,
                    s.name as staff_name, s.email as staff_email
             FROM {$wpdb->prefix}snab_appointments a
             JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d",
            $id
        ));

        if (!$appointment) {
            wp_send_json_error(__('Appointment not found.', 'sn-appointment-booking'));
        }

        // Get notification log
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT notification_type, recipient_type, status, sent_at
             FROM {$wpdb->prefix}snab_notifications_log
             WHERE appointment_id = %d
             ORDER BY sent_at DESC",
            $id
        ));

        // Format dates - use helper functions for proper timezone handling
        $appointment->formatted_date = snab_format_date($appointment->appointment_date);
        $appointment->formatted_time = snab_format_time($appointment->appointment_date, $appointment->start_time) .
                                       ' - ' .
                                       snab_format_time($appointment->appointment_date, $appointment->end_time);
        // created_at is a full datetime, so we can use it directly with wp_date
        $appointment->formatted_created = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($appointment->created_at));
        $appointment->status_label = $this->get_status_label($appointment->status);
        $appointment->notifications = $notifications;

        // Build HTML
        ob_start();
        ?>
        <div class="snab-detail-grid">
            <div class="snab-detail-section">
                <h3><?php esc_html_e('Appointment Info', 'sn-appointment-booking'); ?></h3>
                <table class="snab-detail-table">
                    <tr>
                        <th><?php esc_html_e('Type', 'sn-appointment-booking'); ?></th>
                        <td>
                            <span class="snab-type-badge" style="background-color: <?php echo esc_attr($appointment->type_color); ?>">
                                <?php echo esc_html($appointment->type_name); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Date', 'sn-appointment-booking'); ?></th>
                        <td><?php echo esc_html($appointment->formatted_date); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Time', 'sn-appointment-booking'); ?></th>
                        <td><?php echo esc_html($appointment->formatted_time); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Duration', 'sn-appointment-booking'); ?></th>
                        <td><?php echo esc_html($appointment->duration_minutes); ?> <?php esc_html_e('minutes', 'sn-appointment-booking'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                        <td>
                            <span class="snab-status snab-status-<?php echo esc_attr($appointment->status); ?>">
                                <?php echo esc_html($appointment->status_label); ?>
                            </span>
                            <?php if (in_array($appointment->status, array('pending', 'confirmed'))): ?>
                                <select class="snab-status-select" data-id="<?php echo esc_attr($appointment->id); ?>">
                                    <option value=""><?php esc_html_e('Change status...', 'sn-appointment-booking'); ?></option>
                                    <option value="confirmed"><?php esc_html_e('Confirmed', 'sn-appointment-booking'); ?></option>
                                    <option value="completed"><?php esc_html_e('Completed', 'sn-appointment-booking'); ?></option>
                                    <option value="no_show"><?php esc_html_e('No Show', 'sn-appointment-booking'); ?></option>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Google Calendar', 'sn-appointment-booking'); ?></th>
                        <td>
                            <?php if ($appointment->google_calendar_synced): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                <?php esc_html_e('Synced', 'sn-appointment-booking'); ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-minus" style="color: #999;"></span>
                                <?php esc_html_e('Not synced', 'sn-appointment-booking'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Booked On', 'sn-appointment-booking'); ?></th>
                        <td><?php echo esc_html($appointment->formatted_created); ?></td>
                    </tr>
                </table>
            </div>

            <div class="snab-detail-section">
                <h3><?php esc_html_e('Client Info', 'sn-appointment-booking'); ?></h3>
                <table class="snab-detail-table">
                    <tr>
                        <th><?php esc_html_e('Name', 'sn-appointment-booking'); ?></th>
                        <td><?php echo esc_html($appointment->client_name); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email', 'sn-appointment-booking'); ?></th>
                        <td><a href="mailto:<?php echo esc_attr($appointment->client_email); ?>"><?php echo esc_html($appointment->client_email); ?></a></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Phone', 'sn-appointment-booking'); ?></th>
                        <td>
                            <?php if ($appointment->client_phone): ?>
                                <a href="tel:<?php echo esc_attr($appointment->client_phone); ?>"><?php echo esc_html($appointment->client_phone); ?></a>
                            <?php else: ?>
                                <em><?php esc_html_e('Not provided', 'sn-appointment-booking'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($appointment->property_address): ?>
                        <tr>
                            <th><?php esc_html_e('Property', 'sn-appointment-booking'); ?></th>
                            <td><?php echo esc_html($appointment->property_address); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($appointment->client_notes): ?>
                        <tr>
                            <th><?php esc_html_e('Client Notes', 'sn-appointment-booking'); ?></th>
                            <td><?php echo nl2br(esc_html($appointment->client_notes)); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="snab-detail-section snab-admin-notes-section">
            <h3><?php esc_html_e('Admin Notes', 'sn-appointment-booking'); ?></h3>
            <textarea id="snab-admin-notes" rows="3" data-id="<?php echo esc_attr($appointment->id); ?>"
                      placeholder="<?php esc_attr_e('Add private notes about this appointment...', 'sn-appointment-booking'); ?>"><?php echo esc_textarea($appointment->admin_notes); ?></textarea>
            <button type="button" class="button snab-save-notes-btn"><?php esc_html_e('Save Notes', 'sn-appointment-booking'); ?></button>
            <span class="snab-notes-saved" style="display: none;"><?php esc_html_e('Saved!', 'sn-appointment-booking'); ?></span>
        </div>

        <?php if (!empty($notifications)): ?>
            <div class="snab-detail-section">
                <h3><?php esc_html_e('Notification History', 'sn-appointment-booking'); ?></h3>
                <table class="snab-detail-table snab-notifications-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Type', 'sn-appointment-booking'); ?></th>
                            <th><?php esc_html_e('Recipient', 'sn-appointment-booking'); ?></th>
                            <th><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                            <th><?php esc_html_e('Sent', 'sn-appointment-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notif): ?>
                            <tr>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $notif->notification_type))); ?></td>
                                <td><?php echo esc_html(ucfirst($notif->recipient_type)); ?></td>
                                <td>
                                    <?php if ($notif->status === 'sent'): ?>
                                        <span style="color: #46b450;"><?php esc_html_e('Sent', 'sn-appointment-booking'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;"><?php esc_html_e('Failed', 'sn-appointment-booking'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($notif->sent_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php

        $html = ob_get_clean();

        wp_send_json_success(array(
            'html' => $html,
            'appointment' => $appointment,
        ));
    }

    /**
     * AJAX: Update appointment status.
     */
    public function ajax_update_status() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$id || !$status) {
            wp_send_json_error(__('Invalid parameters.', 'sn-appointment-booking'));
        }

        $valid_statuses = array('pending', 'confirmed', 'completed', 'no_show');
        if (!in_array($status, $valid_statuses)) {
            wp_send_json_error(__('Invalid status.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $result = $wpdb->update(
            $table,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to update status.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Appointment status updated', array(
            'appointment_id' => $id,
            'new_status' => $status,
        ));

        wp_send_json_success(array(
            'message' => __('Status updated successfully.', 'sn-appointment-booking'),
            'status' => $status,
            'status_label' => $this->get_status_label($status),
        ));
    }

    /**
     * AJAX: Cancel appointment.
     */
    public function ajax_cancel_appointment() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        $send_email = isset($_POST['send_email']) && $_POST['send_email'] === 'true';

        if (!$id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        // Get appointment for Google Calendar deletion
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$appointment) {
            wp_send_json_error(__('Appointment not found.', 'sn-appointment-booking'));
        }

        // Update status
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to cancel appointment.', 'sn-appointment-booking'));
        }

        // Delete from Google Calendar
        if ($appointment->google_event_id) {
            $gcal = snab_google_calendar();
            if ($gcal->is_connected()) {
                $gcal->delete_event($appointment->google_event_id);
            }
        }

        // Send cancellation email
        if ($send_email) {
            $notifications = snab_notifications();
            $notifications->send_cancellation($id, $reason);
        }

        SNAB_Logger::info('Appointment cancelled', array(
            'appointment_id' => $id,
            'reason' => $reason,
            'email_sent' => $send_email,
        ));

        wp_send_json_success(array(
            'message' => __('Appointment cancelled successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX: Save admin notes.
     */
    public function ajax_save_admin_notes() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (!$id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $result = $wpdb->update(
            $table,
            array(
                'admin_notes' => $notes,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to save notes.', 'sn-appointment-booking'));
        }

        wp_send_json_success(array(
            'message' => __('Notes saved successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX: Export appointments to CSV.
     */
    public function ajax_export_csv() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $appointments = $wpdb->get_results(
            "SELECT a.*, t.name as type_name
             FROM {$wpdb->prefix}snab_appointments a
             JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             ORDER BY a.appointment_date DESC, a.start_time DESC"
        );

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=appointments-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // Header row
        fputcsv($output, array(
            'ID',
            'Date',
            'Start Time',
            'End Time',
            'Type',
            'Status',
            'Client Name',
            'Client Email',
            'Client Phone',
            'Property Address',
            'Client Notes',
            'Admin Notes',
            'Google Synced',
            'Created At',
        ));

        // Data rows
        foreach ($appointments as $apt) {
            fputcsv($output, array(
                $apt->id,
                $apt->appointment_date,
                $apt->start_time,
                $apt->end_time,
                $apt->type_name,
                $apt->status,
                $apt->client_name,
                $apt->client_email,
                $apt->client_phone,
                $apt->property_address,
                $apt->client_notes,
                $apt->admin_notes,
                $apt->google_calendar_synced ? 'Yes' : 'No',
                $apt->created_at,
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * AJAX: Create a new appointment manually.
     *
     * @since 1.1.0
     */
    public function ajax_create_appointment() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        // Validate required fields
        $client_name = isset($_POST['client_name']) ? sanitize_text_field($_POST['client_name']) : '';
        $client_email = isset($_POST['client_email']) ? sanitize_email($_POST['client_email']) : '';
        $appointment_type_id = isset($_POST['appointment_type_id']) ? absint($_POST['appointment_type_id']) : 0;
        $appointment_date = isset($_POST['appointment_date']) ? sanitize_text_field($_POST['appointment_date']) : '';
        $start_time = isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '';

        if (empty($client_name) || empty($client_email) || !$appointment_type_id || empty($appointment_date) || empty($start_time)) {
            wp_send_json_error(__('Please fill in all required fields.', 'sn-appointment-booking'));
        }

        if (!is_email($client_email)) {
            wp_send_json_error(__('Please enter a valid email address.', 'sn-appointment-booking'));
        }

        // Optional fields
        $client_phone = isset($_POST['client_phone']) ? sanitize_text_field($_POST['client_phone']) : '';
        $property_address = isset($_POST['property_address']) ? sanitize_textarea_field($_POST['property_address']) : '';
        $client_notes = isset($_POST['client_notes']) ? sanitize_textarea_field($_POST['client_notes']) : '';
        $send_confirmation = isset($_POST['send_confirmation']) && $_POST['send_confirmation'] === '1';

        global $wpdb;

        // Get appointment type details
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}snab_appointment_types WHERE id = %d",
            $appointment_type_id
        ));

        if (!$type) {
            wp_send_json_error(__('Invalid appointment type.', 'sn-appointment-booking'));
        }

        // Get primary staff member
        $staff = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}snab_staff WHERE is_primary = 1 LIMIT 1"
        );

        if (!$staff) {
            wp_send_json_error(__('No staff member configured.', 'sn-appointment-booking'));
        }

        // Calculate end time
        $start_datetime = strtotime($start_time);
        $end_datetime = $start_datetime + ($type->duration_minutes * 60);
        $end_time = date('H:i:s', $end_datetime);
        $start_time_formatted = date('H:i:s', $start_datetime);

        // Create the appointment
        $result = $wpdb->insert(
            $wpdb->prefix . 'snab_appointments',
            array(
                'staff_id' => $staff->id,
                'appointment_type_id' => $appointment_type_id,
                'status' => 'confirmed',
                'appointment_date' => $appointment_date,
                'start_time' => $start_time_formatted,
                'end_time' => $end_time,
                'client_name' => $client_name,
                'client_email' => $client_email,
                'client_phone' => $client_phone,
                'property_address' => $property_address,
                'client_notes' => $client_notes,
                'created_by' => 'admin',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            wp_send_json_error(__('Failed to create appointment.', 'sn-appointment-booking'));
        }

        $appointment_id = $wpdb->insert_id;

        // Create Google Calendar event
        $gcal = snab_google_calendar();
        if ($gcal->is_connected()) {
            $event_id = $gcal->create_event(array(
                'summary' => $type->name . ' - ' . $client_name,
                'description' => $property_address ? "Property: {$property_address}\n\n" : '' . ($client_notes ?: ''),
                'start_datetime' => $appointment_date . 'T' . $start_time_formatted,
                'end_datetime' => $appointment_date . 'T' . $end_time,
                'attendee_email' => $client_email,
            ));

            if ($event_id) {
                $wpdb->update(
                    $wpdb->prefix . 'snab_appointments',
                    array(
                        'google_event_id' => $event_id,
                        'google_calendar_synced' => 1,
                    ),
                    array('id' => $appointment_id),
                    array('%s', '%d'),
                    array('%d')
                );
            }
        }

        // Send confirmation email
        if ($send_confirmation) {
            $notifications = snab_notifications();
            $notifications->send_client_confirmation($appointment_id);
            $notifications->send_admin_confirmation($appointment_id);
        }

        SNAB_Logger::info('Appointment created manually', array(
            'appointment_id' => $appointment_id,
            'created_by' => 'admin',
        ));

        wp_send_json_success(array(
            'message' => __('Appointment created successfully.', 'sn-appointment-booking'),
            'appointment_id' => $appointment_id,
        ));
    }

    /**
     * AJAX: Reschedule an appointment.
     *
     * @since 1.1.0
     */
    public function ajax_reschedule_appointment() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $new_date = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';
        $new_time = isset($_POST['new_time']) ? sanitize_text_field($_POST['new_time']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        $send_notification = isset($_POST['send_notification']) && $_POST['send_notification'] === '1';

        if (!$appointment_id || empty($new_date) || empty($new_time)) {
            wp_send_json_error(__('Please fill in all required fields.', 'sn-appointment-booking'));
        }

        global $wpdb;

        // Get current appointment
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.duration_minutes, t.name as type_name
             FROM {$wpdb->prefix}snab_appointments a
             JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             WHERE a.id = %d",
            $appointment_id
        ));

        if (!$appointment) {
            wp_send_json_error(__('Appointment not found.', 'sn-appointment-booking'));
        }

        if (!in_array($appointment->status, array('pending', 'confirmed'))) {
            wp_send_json_error(__('This appointment cannot be rescheduled.', 'sn-appointment-booking'));
        }

        // Store original datetime (only on first reschedule)
        $original_datetime = $appointment->original_datetime ?: ($appointment->appointment_date . ' ' . $appointment->start_time);

        // Calculate new end time
        $start_datetime = strtotime($new_time);
        $end_datetime = $start_datetime + ($appointment->duration_minutes * 60);
        $new_end_time = date('H:i:s', $end_datetime);
        $new_start_time = date('H:i:s', $start_datetime);

        // Get old date/time for notification
        $old_date = $appointment->appointment_date;
        $old_time = $appointment->start_time;

        // Update the appointment
        $result = $wpdb->update(
            $wpdb->prefix . 'snab_appointments',
            array(
                'appointment_date' => $new_date,
                'start_time' => $new_start_time,
                'end_time' => $new_end_time,
                'reschedule_count' => $appointment->reschedule_count + 1,
                'original_datetime' => $original_datetime,
                'rescheduled_by' => 'admin',
                'reschedule_reason' => $reason,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $appointment_id),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to reschedule appointment.', 'sn-appointment-booking'));
        }

        // Update Google Calendar event (v1.10.4: use per-staff method with attendees)
        if ($appointment->google_event_id && $appointment->staff_id) {
            try {
                $gcal = snab_google_calendar();
                if ($gcal->is_staff_connected($appointment->staff_id)) {
                    $timezone = wp_timezone_string();
                    $time_with_seconds = (strlen($new_start_time) === 5) ? $new_start_time . ':00' : $new_start_time;
                    $end_with_seconds = (strlen($new_end_time) === 5) ? $new_end_time . ':00' : $new_end_time;

                    $gcal_update_data = array(
                        'start' => array(
                            'dateTime' => $new_date . 'T' . $time_with_seconds,
                            'timeZone' => $timezone,
                        ),
                        'end' => array(
                            'dateTime' => $new_date . 'T' . $end_with_seconds,
                            'timeZone' => $timezone,
                        ),
                    );

                    // Include all attendees in the update
                    $attendees_array = $gcal->build_attendees_array($appointment_id);
                    if (!empty($attendees_array)) {
                        $gcal_update_data['attendees'] = $attendees_array;
                    }

                    $gcal->update_staff_event($appointment->staff_id, $appointment->google_event_id, $gcal_update_data);
                }
            } catch (Exception $e) {
                SNAB_Logger::error('Failed to update Google Calendar event', array(
                    'appointment_id' => $appointment_id,
                    'error' => $e->getMessage(),
                ));
            }
        }

        // Send reschedule notification
        if ($send_notification) {
            $notifications = snab_notifications();
            $notifications->send_reschedule($appointment_id, $old_date, $old_time, $reason);
        }

        SNAB_Logger::info('Appointment rescheduled', array(
            'appointment_id' => $appointment_id,
            'old_datetime' => $old_date . ' ' . $old_time,
            'new_datetime' => $new_date . ' ' . $new_start_time,
            'reason' => $reason,
        ));

        wp_send_json_success(array(
            'message' => __('Appointment rescheduled successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX: Get available slots for a date.
     *
     * @since 1.1.0
     */
    public function ajax_get_available_slots() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $appointment_type_id = isset($_POST['appointment_type_id']) ? absint($_POST['appointment_type_id']) : 0;
        $exclude_appointment_id = isset($_POST['exclude_appointment_id']) ? absint($_POST['exclude_appointment_id']) : 0;

        if (empty($date)) {
            wp_send_json_error(__('Please select a date.', 'sn-appointment-booking'));
        }

        // Get available slots using the availability service
        $availability_service = new SNAB_Availability_Service();
        $slots = $availability_service->get_available_slots($date, $date, $appointment_type_id);

        // If we're rescheduling, exclude the current appointment's slot
        $filtered_slots = array();

        if (!empty($slots[$date])) {
            foreach ($slots[$date] as $slot) {
                // For rescheduling, we want to include the current appointment's slot
                // since it will be freed up when rescheduled
                // Note: $slot is a time string like "09:00", not an array
                $filtered_slots[] = array(
                    'time' => $slot,
                    'label' => snab_format_time($date, $slot),
                );
            }
        }

        wp_send_json_success(array(
            'slots' => $filtered_slots,
        ));
    }
}
