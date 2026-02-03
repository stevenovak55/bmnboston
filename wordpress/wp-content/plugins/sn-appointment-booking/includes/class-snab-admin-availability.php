<?php
/**
 * Admin Availability Class
 *
 * Handles availability management in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Availability class.
 *
 * @since 1.0.0
 */
class SNAB_Admin_Availability {

    /**
     * Table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Staff table name.
     *
     * @var string
     */
    private $staff_table;

    /**
     * Types table name.
     *
     * @var string
     */
    private $types_table;

    /**
     * Days of the week.
     *
     * @var array
     */
    private $days_of_week = array(
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    );

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'snab_availability_rules';
        $this->staff_table = $wpdb->prefix . 'snab_staff';
        $this->types_table = $wpdb->prefix . 'snab_appointment_types';

        // Register AJAX handlers
        add_action('wp_ajax_snab_save_recurring_schedule', array($this, 'ajax_save_recurring_schedule'));
        add_action('wp_ajax_snab_save_date_override', array($this, 'ajax_save_date_override'));
        add_action('wp_ajax_snab_delete_date_override', array($this, 'ajax_delete_date_override'));
        add_action('wp_ajax_snab_save_blocked_time', array($this, 'ajax_save_blocked_time'));
        add_action('wp_ajax_snab_delete_blocked_time', array($this, 'ajax_delete_blocked_time'));
        add_action('wp_ajax_snab_get_availability_preview', array($this, 'ajax_get_availability_preview'));
    }

    /**
     * Render the availability management page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        // Get all staff members
        $all_staff = $this->get_all_staff();
        if (empty($all_staff)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' .
                 esc_html__('No staff members found. Please add staff members first.', 'sn-appointment-booking') .
                 '</p></div></div>';
            return;
        }

        // Get selected staff from query param, default to primary staff
        $selected_staff_id = isset($_GET['staff_id']) ? absint($_GET['staff_id']) : 0;
        if (!$selected_staff_id) {
            // Find primary staff as default
            foreach ($all_staff as $s) {
                if ($s->is_primary) {
                    $selected_staff_id = $s->id;
                    break;
                }
            }
            // Fallback to first staff if no primary
            if (!$selected_staff_id && !empty($all_staff)) {
                $selected_staff_id = $all_staff[0]->id;
            }
        }

        // Get selected staff object
        $staff = null;
        foreach ($all_staff as $s) {
            if ($s->id == $selected_staff_id) {
                $staff = $s;
                break;
            }
        }

        if (!$staff) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' .
                 esc_html__('Selected staff member not found.', 'sn-appointment-booking') .
                 '</p></div></div>';
            return;
        }

        // Get appointment types for filter
        $appointment_types = $this->get_appointment_types();
        $selected_type_id = isset($_GET['type_id']) ? absint($_GET['type_id']) : 0;

        // Get current data filtered by staff and appointment type
        $recurring_rules = $this->get_recurring_rules($staff->id, $selected_type_id);
        $date_overrides = $this->get_date_overrides($staff->id, $selected_type_id);
        $blocked_times = $this->get_blocked_times($staff->id, $selected_type_id);

        ?>
        <div class="wrap snab-admin-wrap">
            <h1><?php esc_html_e('Availability', 'sn-appointment-booking'); ?></h1>

            <div id="snab-availability-notice" class="notice" style="display: none;"></div>

            <!-- Staff and Appointment Type Filters -->
            <div class="snab-availability-filters" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
                    <!-- Staff Selector -->
                    <div class="snab-filter-group">
                        <label for="snab-staff-filter" style="font-weight: 600; margin-right: 10px; display: block; margin-bottom: 5px;">
                            <?php esc_html_e('Staff Member:', 'sn-appointment-booking'); ?>
                        </label>
                        <select id="snab-staff-filter" style="min-width: 200px;">
                            <?php foreach ($all_staff as $s): ?>
                                <option value="<?php echo esc_attr($s->id); ?>" <?php selected($selected_staff_id, $s->id); ?>>
                                    <?php echo esc_html($s->name); ?>
                                    <?php if ($s->is_primary): ?>
                                        (<?php esc_html_e('Primary', 'sn-appointment-booking'); ?>)
                                    <?php endif; ?>
                                    <?php if (!$s->is_active): ?>
                                        (<?php esc_html_e('Inactive', 'sn-appointment-booking'); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Appointment Type Filter -->
                    <div class="snab-filter-group">
                        <label for="snab-type-filter" style="font-weight: 600; margin-right: 10px; display: block; margin-bottom: 5px;">
                            <?php esc_html_e('Appointment Type:', 'sn-appointment-booking'); ?>
                        </label>
                        <select id="snab-type-filter" style="min-width: 250px;">
                            <option value="0" <?php selected($selected_type_id, 0); ?>>
                                <?php esc_html_e('All Types (Default Rules)', 'sn-appointment-booking'); ?>
                            </option>
                            <?php foreach ($appointment_types as $type): ?>
                                <option value="<?php echo esc_attr($type->id); ?>" <?php selected($selected_type_id, $type->id); ?>>
                                    <?php echo esc_html($type->name); ?>
                                    <?php if (!$type->is_active): ?>
                                        (<?php esc_html_e('Inactive', 'sn-appointment-booking'); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="snab-filter-description" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                    <span style="color: #666;">
                        <?php
                        printf(
                            esc_html__('Managing availability for: %s', 'sn-appointment-booking'),
                            '<strong>' . esc_html($staff->name) . '</strong>'
                        );
                        ?>
                        <?php if ($selected_type_id > 0): ?>
                            &mdash; <?php esc_html_e('Type-specific rules override default rules.', 'sn-appointment-booking'); ?>
                        <?php else: ?>
                            &mdash; <?php esc_html_e('Default rules apply to all appointment types.', 'sn-appointment-booking'); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <input type="hidden" id="snab-selected-type-id" value="<?php echo esc_attr($selected_type_id); ?>">

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper snab-availability-tabs">
                <a href="#snab-tab-recurring" class="nav-tab nav-tab-active" data-tab="recurring">
                    <?php esc_html_e('Weekly Schedule', 'sn-appointment-booking'); ?>
                </a>
                <a href="#snab-tab-overrides" class="nav-tab" data-tab="overrides">
                    <?php esc_html_e('Date Overrides', 'sn-appointment-booking'); ?>
                    <?php if (!empty($date_overrides)): ?>
                        <span class="snab-badge"><?php echo count($date_overrides); ?></span>
                    <?php endif; ?>
                </a>
                <a href="#snab-tab-blocked" class="nav-tab" data-tab="blocked">
                    <?php esc_html_e('Blocked Times', 'sn-appointment-booking'); ?>
                    <?php if (!empty($blocked_times)): ?>
                        <span class="snab-badge"><?php echo count($blocked_times); ?></span>
                    <?php endif; ?>
                </a>
            </nav>

            <input type="hidden" id="snab-staff-id" value="<?php echo esc_attr($staff->id); ?>">
            <?php wp_nonce_field('snab_admin_nonce', 'snab_availability_nonce'); ?>

            <!-- Weekly Schedule Tab -->
            <div id="snab-tab-recurring" class="snab-tab-content snab-tab-active">
                <div class="snab-section">
                    <h2><?php esc_html_e('Regular Weekly Hours', 'sn-appointment-booking'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Set your regular weekly availability. These hours repeat every week.', 'sn-appointment-booking'); ?>
                    </p>

                    <form id="snab-recurring-form">
                        <div class="snab-weekly-grid">
                            <?php foreach ($this->days_of_week as $day_num => $day_name): ?>
                                <?php
                                $day_rules = array_filter($recurring_rules, function($r) use ($day_num) {
                                    return $r->day_of_week == $day_num;
                                });
                                $day_rule = !empty($day_rules) ? reset($day_rules) : null;
                                $is_available = $day_rule && $day_rule->is_active;
                                ?>
                                <div class="snab-day-row" data-day="<?php echo esc_attr($day_num); ?>">
                                    <div class="snab-day-toggle">
                                        <label class="snab-toggle-switch">
                                            <input type="checkbox"
                                                   name="days[<?php echo esc_attr($day_num); ?>][enabled]"
                                                   value="1"
                                                   <?php checked($is_available); ?>>
                                            <span class="snab-toggle-slider"></span>
                                        </label>
                                        <span class="snab-day-name"><?php echo esc_html($day_name); ?></span>
                                    </div>
                                    <div class="snab-day-times <?php echo !$is_available ? 'snab-disabled' : ''; ?>">
                                        <input type="time"
                                               name="days[<?php echo esc_attr($day_num); ?>][start]"
                                               value="<?php echo $day_rule ? esc_attr(substr($day_rule->start_time, 0, 5)) : '09:00'; ?>"
                                               <?php echo !$is_available ? 'disabled' : ''; ?>>
                                        <span class="snab-time-separator"><?php esc_html_e('to', 'sn-appointment-booking'); ?></span>
                                        <input type="time"
                                               name="days[<?php echo esc_attr($day_num); ?>][end]"
                                               value="<?php echo $day_rule ? esc_attr(substr($day_rule->end_time, 0, 5)) : '17:00'; ?>"
                                               <?php echo !$is_available ? 'disabled' : ''; ?>>
                                    </div>
                                    <div class="snab-day-status">
                                        <?php if ($is_available): ?>
                                            <span class="snab-available"><?php esc_html_e('Available', 'sn-appointment-booking'); ?></span>
                                        <?php else: ?>
                                            <span class="snab-unavailable"><?php esc_html_e('Unavailable', 'sn-appointment-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="snab-form-actions">
                            <button type="submit" class="button button-primary" id="snab-save-recurring-btn">
                                <span class="snab-btn-text"><?php esc_html_e('Save Weekly Schedule', 'sn-appointment-booking'); ?></span>
                                <span class="snab-spinner" style="display: none;"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Date Overrides Tab -->
            <div id="snab-tab-overrides" class="snab-tab-content">
                <div class="snab-section">
                    <h2>
                        <?php esc_html_e('Date Overrides', 'sn-appointment-booking'); ?>
                        <button type="button" class="button button-secondary snab-add-override-btn">
                            <?php esc_html_e('Add Override', 'sn-appointment-booking'); ?>
                        </button>
                    </h2>
                    <p class="description">
                        <?php esc_html_e('Set special hours for specific dates (holidays, events, etc.). These override the weekly schedule.', 'sn-appointment-booking'); ?>
                    </p>

                    <div id="snab-overrides-list">
                        <?php if (empty($date_overrides)): ?>
                            <p class="snab-no-data" id="snab-no-overrides">
                                <?php esc_html_e('No date overrides set. Click "Add Override" to add special hours for a specific date.', 'sn-appointment-booking'); ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($date_overrides as $override): ?>
                                <?php $this->render_override_row($override); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Blocked Times Tab -->
            <div id="snab-tab-blocked" class="snab-tab-content">
                <div class="snab-section">
                    <h2>
                        <?php esc_html_e('Blocked Times', 'sn-appointment-booking'); ?>
                        <button type="button" class="button button-secondary snab-add-blocked-btn">
                            <?php esc_html_e('Block Time', 'sn-appointment-booking'); ?>
                        </button>
                    </h2>
                    <p class="description">
                        <?php esc_html_e('Block specific time slots when you\'re unavailable (meetings, personal time, etc.).', 'sn-appointment-booking'); ?>
                    </p>

                    <div id="snab-blocked-list">
                        <?php if (empty($blocked_times)): ?>
                            <p class="snab-no-data" id="snab-no-blocked">
                                <?php esc_html_e('No blocked times. Click "Block Time" to mark a time slot as unavailable.', 'sn-appointment-booking'); ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($blocked_times as $blocked): ?>
                                <?php $this->render_blocked_row($blocked); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Add Override Modal -->
            <div id="snab-override-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content">
                    <div class="snab-modal-header">
                        <h2 id="snab-override-modal-title"><?php esc_html_e('Add Date Override', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <form id="snab-override-form">
                        <input type="hidden" id="snab-override-id" name="override_id" value="">
                        <div class="snab-modal-body">
                            <div class="snab-form-row">
                                <label for="snab-override-date"><?php esc_html_e('Date', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                <input type="date" id="snab-override-date" name="specific_date" required
                                       min="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                            </div>

                            <div class="snab-form-row">
                                <label>
                                    <input type="checkbox" id="snab-override-closed" name="is_closed" value="1">
                                    <?php esc_html_e('Closed all day (no appointments)', 'sn-appointment-booking'); ?>
                                </label>
                            </div>

                            <div id="snab-override-times-wrap">
                                <div class="snab-form-row snab-form-row-inline">
                                    <div>
                                        <label for="snab-override-start"><?php esc_html_e('Start Time', 'sn-appointment-booking'); ?></label>
                                        <input type="time" id="snab-override-start" name="start_time" value="09:00">
                                    </div>
                                    <div>
                                        <label for="snab-override-end"><?php esc_html_e('End Time', 'sn-appointment-booking'); ?></label>
                                        <input type="time" id="snab-override-end" name="end_time" value="17:00">
                                    </div>
                                </div>
                            </div>

                            <div class="snab-form-row">
                                <label for="snab-override-note"><?php esc_html_e('Note (optional)', 'sn-appointment-booking'); ?></label>
                                <input type="text" id="snab-override-note" name="note" placeholder="<?php esc_attr_e('e.g., Holiday, Training day', 'sn-appointment-booking'); ?>">
                            </div>
                        </div>
                        <div class="snab-modal-footer">
                            <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                            <button type="submit" class="button button-primary" id="snab-save-override-btn">
                                <?php esc_html_e('Save Override', 'sn-appointment-booking'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Add Blocked Time Modal -->
            <div id="snab-blocked-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content">
                    <div class="snab-modal-header">
                        <h2><?php esc_html_e('Block Time Slot', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <form id="snab-blocked-form">
                        <input type="hidden" id="snab-blocked-id" name="blocked_id" value="">
                        <div class="snab-modal-body">
                            <div class="snab-form-row">
                                <label for="snab-blocked-date"><?php esc_html_e('Date', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                <input type="date" id="snab-blocked-date" name="specific_date" required
                                       min="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                            </div>

                            <div class="snab-form-row snab-form-row-inline">
                                <div>
                                    <label for="snab-blocked-start"><?php esc_html_e('Start Time', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="time" id="snab-blocked-start" name="start_time" required value="12:00">
                                </div>
                                <div>
                                    <label for="snab-blocked-end"><?php esc_html_e('End Time', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="time" id="snab-blocked-end" name="end_time" required value="13:00">
                                </div>
                            </div>

                            <div class="snab-form-row">
                                <label for="snab-blocked-reason"><?php esc_html_e('Reason (optional)', 'sn-appointment-booking'); ?></label>
                                <input type="text" id="snab-blocked-reason" name="reason" placeholder="<?php esc_attr_e('e.g., Lunch meeting, Personal appointment', 'sn-appointment-booking'); ?>">
                            </div>
                        </div>
                        <div class="snab-modal-footer">
                            <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                            <button type="submit" class="button button-primary" id="snab-save-blocked-btn">
                                <?php esc_html_e('Block Time', 'sn-appointment-booking'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div id="snab-availability-delete-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content snab-modal-small">
                    <div class="snab-modal-header">
                        <h2><?php esc_html_e('Confirm Delete', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <div class="snab-modal-body">
                        <p id="snab-delete-availability-message"><?php esc_html_e('Are you sure you want to delete this?', 'sn-appointment-booking'); ?></p>
                        <input type="hidden" id="snab-delete-availability-id" value="">
                        <input type="hidden" id="snab-delete-availability-type" value="">
                    </div>
                    <div class="snab-modal-footer">
                        <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                        <button type="button" class="button button-link-delete" id="snab-confirm-availability-delete-btn">
                            <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render an override row.
     *
     * @param object $override The override object.
     */
    public function render_override_row($override) {
        $is_closed = $override->start_time === '00:00:00' && $override->end_time === '00:00:00';
        $formatted_date = snab_format_date($override->specific_date);
        ?>
        <div class="snab-override-row" data-id="<?php echo esc_attr($override->id); ?>">
            <div class="snab-override-date">
                <strong><?php echo esc_html($formatted_date); ?></strong>
                <span class="snab-override-day"><?php echo esc_html(snab_format_date($override->specific_date, 'l')); ?></span>
            </div>
            <div class="snab-override-hours">
                <?php if ($is_closed): ?>
                    <span class="snab-closed-badge"><?php esc_html_e('Closed', 'sn-appointment-booking'); ?></span>
                <?php else: ?>
                    <?php echo esc_html(snab_format_time($override->specific_date, $override->start_time)); ?>
                    -
                    <?php echo esc_html(snab_format_time($override->specific_date, $override->end_time)); ?>
                <?php endif; ?>
            </div>
            <div class="snab-override-actions">
                <button type="button" class="button button-small snab-edit-override"
                        data-id="<?php echo esc_attr($override->id); ?>"
                        data-date="<?php echo esc_attr($override->specific_date); ?>"
                        data-start="<?php echo esc_attr(substr($override->start_time, 0, 5)); ?>"
                        data-end="<?php echo esc_attr(substr($override->end_time, 0, 5)); ?>"
                        data-closed="<?php echo $is_closed ? '1' : '0'; ?>">
                    <?php esc_html_e('Edit', 'sn-appointment-booking'); ?>
                </button>
                <button type="button" class="button button-small button-link-delete snab-delete-override"
                        data-id="<?php echo esc_attr($override->id); ?>">
                    <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render a blocked time row.
     *
     * @param object $blocked The blocked time object.
     */
    public function render_blocked_row($blocked) {
        $formatted_date = snab_format_date($blocked->specific_date);
        ?>
        <div class="snab-blocked-row" data-id="<?php echo esc_attr($blocked->id); ?>">
            <div class="snab-blocked-date">
                <strong><?php echo esc_html($formatted_date); ?></strong>
                <span class="snab-blocked-day"><?php echo esc_html(snab_format_date($blocked->specific_date, 'l')); ?></span>
            </div>
            <div class="snab-blocked-hours">
                <?php echo esc_html(snab_format_time($blocked->specific_date, $blocked->start_time)); ?>
                -
                <?php echo esc_html(snab_format_time($blocked->specific_date, $blocked->end_time)); ?>
            </div>
            <div class="snab-blocked-actions">
                <button type="button" class="button button-small button-link-delete snab-delete-blocked"
                        data-id="<?php echo esc_attr($blocked->id); ?>">
                    <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Get all staff members.
     *
     * @return array Array of staff objects.
     */
    private function get_all_staff() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->staff_table} ORDER BY is_primary DESC, name ASC");
    }

    /**
     * Get primary staff member.
     *
     * @return object|null Staff object or null.
     */
    private function get_primary_staff() {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$this->staff_table} WHERE is_primary = 1 LIMIT 1");
    }

    /**
     * Get all appointment types.
     *
     * @return array Array of type objects.
     */
    private function get_appointment_types() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->types_table} ORDER BY sort_order, name");
    }

    /**
     * Get recurring rules for a staff member.
     *
     * @param int $staff_id Staff ID.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return array Array of rule objects.
     */
    public function get_recurring_rules($staff_id, $type_id = 0) {
        global $wpdb;

        if ($type_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE staff_id = %d AND rule_type = 'recurring' AND appointment_type_id = %d
                 ORDER BY day_of_week",
                $staff_id,
                $type_id
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE staff_id = %d AND rule_type = 'recurring' AND appointment_type_id IS NULL
             ORDER BY day_of_week",
            $staff_id
        ));
    }

    /**
     * Get date overrides for a staff member.
     *
     * @param int $staff_id Staff ID.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return array Array of override objects.
     */
    public function get_date_overrides($staff_id, $type_id = 0) {
        global $wpdb;

        if ($type_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE staff_id = %d AND rule_type = 'specific_date' AND specific_date >= %s AND appointment_type_id = %d
                 ORDER BY specific_date",
                $staff_id,
                wp_date('Y-m-d'),
                $type_id
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE staff_id = %d AND rule_type = 'specific_date' AND specific_date >= %s AND appointment_type_id IS NULL
             ORDER BY specific_date",
            $staff_id,
            wp_date('Y-m-d')
        ));
    }

    /**
     * Get blocked times for a staff member.
     *
     * @param int $staff_id Staff ID.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return array Array of blocked time objects.
     */
    public function get_blocked_times($staff_id, $type_id = 0) {
        global $wpdb;

        if ($type_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE staff_id = %d AND rule_type = 'blocked' AND specific_date >= %s AND appointment_type_id = %d
                 ORDER BY specific_date, start_time",
                $staff_id,
                wp_date('Y-m-d'),
                $type_id
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE staff_id = %d AND rule_type = 'blocked' AND specific_date >= %s AND appointment_type_id IS NULL
             ORDER BY specific_date, start_time",
            $staff_id,
            wp_date('Y-m-d')
        ));
    }

    /**
     * Save recurring schedule.
     *
     * @param int $staff_id Staff ID.
     * @param array $days Days data.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function save_recurring_schedule($staff_id, $days, $type_id = 0) {
        global $wpdb;

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Delete existing recurring rules for this type
            if ($type_id > 0) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->table_name} WHERE staff_id = %d AND rule_type = 'recurring' AND appointment_type_id = %d",
                    $staff_id,
                    $type_id
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->table_name} WHERE staff_id = %d AND rule_type = 'recurring' AND appointment_type_id IS NULL",
                    $staff_id
                ));
            }

            // Insert new rules
            foreach ($days as $day_num => $day_data) {
                $day_num = absint($day_num);
                if ($day_num > 6) continue;

                $is_enabled = !empty($day_data['enabled']);
                $start_time = isset($day_data['start']) ? sanitize_text_field($day_data['start']) : '09:00';
                $end_time = isset($day_data['end']) ? sanitize_text_field($day_data['end']) : '17:00';

                // Validate times
                if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time = '09:00';
                if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) $end_time = '17:00';

                $insert_data = array(
                    'staff_id' => $staff_id,
                    'rule_type' => 'recurring',
                    'day_of_week' => $day_num,
                    'start_time' => $start_time . ':00',
                    'end_time' => $end_time . ':00',
                    'is_active' => $is_enabled ? 1 : 0,
                    'created_at' => current_time('mysql'),
                );
                $format = array('%d', '%s', '%d', '%s', '%s', '%d', '%s');

                if ($type_id > 0) {
                    $insert_data['appointment_type_id'] = $type_id;
                    $format[] = '%d';
                }

                $result = $wpdb->insert($this->table_name, $insert_data, $format);

                if ($result === false) {
                    throw new Exception(__('Failed to save schedule.', 'sn-appointment-booking'));
                }
            }

            $wpdb->query('COMMIT');
            SNAB_Logger::info('Recurring schedule saved', array('staff_id' => $staff_id, 'type_id' => $type_id));
            return true;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            SNAB_Logger::error('Failed to save recurring schedule', array('error' => $e->getMessage()));
            return new WP_Error('save_failed', $e->getMessage());
        }
    }

    /**
     * Save date override.
     *
     * @param int $staff_id Staff ID.
     * @param array $data Override data.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return int|WP_Error Override ID on success, WP_Error on failure.
     */
    public function save_date_override($staff_id, $data, $type_id = 0) {
        global $wpdb;

        $id = isset($data['id']) ? absint($data['id']) : 0;
        $specific_date = isset($data['specific_date']) ? sanitize_text_field($data['specific_date']) : '';
        $is_closed = !empty($data['is_closed']);
        $start_time = $is_closed ? '00:00' : (isset($data['start_time']) ? sanitize_text_field($data['start_time']) : '09:00');
        $end_time = $is_closed ? '00:00' : (isset($data['end_time']) ? sanitize_text_field($data['end_time']) : '17:00');

        // Validate date
        if (empty($specific_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $specific_date)) {
            return new WP_Error('invalid_date', __('Invalid date.', 'sn-appointment-booking'));
        }

        // Check for existing override on same date for same type (excluding current if editing)
        if ($type_id > 0) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name}
                 WHERE staff_id = %d AND rule_type = 'specific_date' AND specific_date = %s AND appointment_type_id = %d AND id != %d",
                $staff_id, $specific_date, $type_id, $id
            ));
        } else {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name}
                 WHERE staff_id = %d AND rule_type = 'specific_date' AND specific_date = %s AND appointment_type_id IS NULL AND id != %d",
                $staff_id, $specific_date, $id
            ));
        }

        if ($existing) {
            return new WP_Error('duplicate', __('An override already exists for this date.', 'sn-appointment-booking'));
        }

        $override_data = array(
            'staff_id' => $staff_id,
            'rule_type' => 'specific_date',
            'specific_date' => $specific_date,
            'start_time' => $start_time . ':00',
            'end_time' => $end_time . ':00',
            'is_active' => 1,
        );

        $format = array('%d', '%s', '%s', '%s', '%s', '%d');

        if ($type_id > 0) {
            $override_data['appointment_type_id'] = $type_id;
            $format[] = '%d';
        }

        if ($id > 0) {
            // Update
            $result = $wpdb->update(
                $this->table_name,
                $override_data,
                array('id' => $id),
                $format,
                array('%d')
            );
        } else {
            // Insert
            $override_data['created_at'] = current_time('mysql');
            $format[] = '%s';
            $result = $wpdb->insert($this->table_name, $override_data, $format);
            $id = $wpdb->insert_id;
        }

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to save override.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Date override saved', array('id' => $id, 'date' => $specific_date, 'type_id' => $type_id));
        return $id;
    }

    /**
     * Delete date override.
     *
     * @param int $id Override ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_date_override($id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id, 'rule_type' => 'specific_date'),
            array('%d', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to delete override.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Date override deleted', array('id' => $id));
        return true;
    }

    /**
     * Save blocked time.
     *
     * @param int $staff_id Staff ID.
     * @param array $data Blocked time data.
     * @param int $type_id Optional appointment type ID (0 = all types/default rules).
     * @return int|WP_Error Blocked time ID on success, WP_Error on failure.
     */
    public function save_blocked_time($staff_id, $data, $type_id = 0) {
        global $wpdb;

        $specific_date = isset($data['specific_date']) ? sanitize_text_field($data['specific_date']) : '';
        $start_time = isset($data['start_time']) ? sanitize_text_field($data['start_time']) : '';
        $end_time = isset($data['end_time']) ? sanitize_text_field($data['end_time']) : '';

        // Validate
        if (empty($specific_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $specific_date)) {
            return new WP_Error('invalid_date', __('Invalid date.', 'sn-appointment-booking'));
        }

        if (empty($start_time) || empty($end_time)) {
            return new WP_Error('invalid_time', __('Start and end time are required.', 'sn-appointment-booking'));
        }

        if ($start_time >= $end_time) {
            return new WP_Error('invalid_range', __('End time must be after start time.', 'sn-appointment-booking'));
        }

        $blocked_data = array(
            'staff_id' => $staff_id,
            'rule_type' => 'blocked',
            'specific_date' => $specific_date,
            'start_time' => $start_time . ':00',
            'end_time' => $end_time . ':00',
            'is_active' => 1,
            'created_at' => current_time('mysql'),
        );

        $format = array('%d', '%s', '%s', '%s', '%s', '%d', '%s');

        if ($type_id > 0) {
            $blocked_data['appointment_type_id'] = $type_id;
            $format[] = '%d';
        }

        $result = $wpdb->insert($this->table_name, $blocked_data, $format);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to save blocked time.', 'sn-appointment-booking'));
        }

        $id = $wpdb->insert_id;
        SNAB_Logger::info('Blocked time saved', array('id' => $id, 'date' => $specific_date, 'type_id' => $type_id));
        return $id;
    }

    /**
     * Delete blocked time.
     *
     * @param int $id Blocked time ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_blocked_time($id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id, 'rule_type' => 'blocked'),
            array('%d', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to delete blocked time.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Blocked time deleted', array('id' => $id));
        return true;
    }

    /**
     * AJAX: Save recurring schedule.
     */
    public function ajax_save_recurring_schedule() {
        // Log the request for debugging
        SNAB_Logger::debug('AJAX save_recurring_schedule called', array(
            'POST' => $_POST,
            'nonce_field' => isset($_POST['snab_availability_nonce']) ? 'present' : 'missing',
        ));

        // Verify nonce
        if (!isset($_POST['snab_availability_nonce']) || !wp_verify_nonce($_POST['snab_availability_nonce'], 'snab_admin_nonce')) {
            SNAB_Logger::error('Nonce verification failed');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'sn-appointment-booking')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
        $days = isset($_POST['days']) ? $_POST['days'] : array();

        SNAB_Logger::debug('Processing schedule', array('staff_id' => $staff_id, 'type_id' => $type_id, 'days' => $days));

        if (!$staff_id) {
            wp_send_json_error(array('message' => __('Invalid staff ID.', 'sn-appointment-booking')));
        }

        $result = $this->save_recurring_schedule($staff_id, $days, $type_id);

        if (is_wp_error($result)) {
            SNAB_Logger::error('Save failed', array('error' => $result->get_error_message()));
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        SNAB_Logger::info('Schedule saved successfully');
        wp_send_json_success(array('message' => __('Weekly schedule saved successfully.', 'sn-appointment-booking')));
    }

    /**
     * AJAX: Save date override.
     */
    public function ajax_save_date_override() {
        check_ajax_referer('snab_admin_nonce', 'snab_availability_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
        $data = array(
            'id' => isset($_POST['override_id']) ? absint($_POST['override_id']) : 0,
            'specific_date' => isset($_POST['specific_date']) ? sanitize_text_field($_POST['specific_date']) : '',
            'is_closed' => !empty($_POST['is_closed']),
            'start_time' => isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '09:00',
            'end_time' => isset($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : '17:00',
        );

        if (!$staff_id) {
            wp_send_json_error(array('message' => __('Invalid staff ID.', 'sn-appointment-booking')));
        }

        $result = $this->save_date_override($staff_id, $data, $type_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Get the saved override to return HTML
        global $wpdb;
        $override = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $result
        ));

        ob_start();
        $this->render_override_row($override);
        $row_html = ob_get_clean();

        wp_send_json_success(array(
            'message' => __('Date override saved successfully.', 'sn-appointment-booking'),
            'id' => $result,
            'row_html' => $row_html,
            'is_new' => $data['id'] == 0,
        ));
    }

    /**
     * AJAX: Delete date override.
     */
    public function ajax_delete_date_override() {
        check_ajax_referer('snab_admin_nonce', 'snab_availability_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid ID.', 'sn-appointment-booking')));
        }

        $result = $this->delete_date_override($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Date override deleted.', 'sn-appointment-booking')));
    }

    /**
     * AJAX: Save blocked time.
     */
    public function ajax_save_blocked_time() {
        check_ajax_referer('snab_admin_nonce', 'snab_availability_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
        $data = array(
            'specific_date' => isset($_POST['specific_date']) ? sanitize_text_field($_POST['specific_date']) : '',
            'start_time' => isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '',
            'end_time' => isset($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : '',
        );

        if (!$staff_id) {
            wp_send_json_error(array('message' => __('Invalid staff ID.', 'sn-appointment-booking')));
        }

        $result = $this->save_blocked_time($staff_id, $data, $type_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Get the saved blocked time to return HTML
        global $wpdb;
        $blocked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $result
        ));

        ob_start();
        $this->render_blocked_row($blocked);
        $row_html = ob_get_clean();

        wp_send_json_success(array(
            'message' => __('Blocked time saved successfully.', 'sn-appointment-booking'),
            'id' => $result,
            'row_html' => $row_html,
        ));
    }

    /**
     * AJAX: Delete blocked time.
     */
    public function ajax_delete_blocked_time() {
        check_ajax_referer('snab_admin_nonce', 'snab_availability_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid ID.', 'sn-appointment-booking')));
        }

        $result = $this->delete_blocked_time($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Blocked time deleted.', 'sn-appointment-booking')));
    }

    /**
     * AJAX: Get availability preview for a date range.
     */
    public function ajax_get_availability_preview() {
        check_ajax_referer('snab_admin_nonce', 'snab_availability_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        // This will be implemented in Phase 5 when we build the availability service
        wp_send_json_success(array('message' => __('Preview coming in Phase 5.', 'sn-appointment-booking')));
    }
}
