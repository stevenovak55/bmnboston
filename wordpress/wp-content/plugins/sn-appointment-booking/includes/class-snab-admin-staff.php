<?php
/**
 * Admin Staff Class
 *
 * Handles staff member CRUD operations in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.6.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Staff class.
 *
 * @since 1.6.0
 */
class SNAB_Admin_Staff {

    /**
     * Table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Staff services table name.
     *
     * @var string
     */
    private $services_table;

    /**
     * Google Calendar instance.
     *
     * @var SNAB_Google_Calendar
     */
    private $google_calendar;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'snab_staff';
        $this->services_table = $wpdb->prefix . 'snab_staff_services';
        $this->google_calendar = snab_google_calendar();

        // Register AJAX handlers
        add_action('wp_ajax_snab_save_staff', array($this, 'ajax_save_staff'));
        add_action('wp_ajax_snab_delete_staff', array($this, 'ajax_delete_staff'));
        add_action('wp_ajax_snab_toggle_staff_status', array($this, 'ajax_toggle_status'));
        add_action('wp_ajax_snab_get_staff', array($this, 'ajax_get_staff'));
        add_action('wp_ajax_snab_set_primary_staff', array($this, 'ajax_set_primary'));
        add_action('wp_ajax_snab_save_staff_services', array($this, 'ajax_save_services'));
    }

    /**
     * Render the staff management page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        $staff = $this->get_all_staff();
        $appointment_types = $this->get_appointment_types();
        $google_configured = $this->google_calendar->is_configured();

        // Show success notice for calendar connection
        if (isset($_GET['calendar_connected'])) {
            $connected_staff_id = absint($_GET['calendar_connected']);
            global $wpdb;
            $staff_name = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$this->table_name} WHERE id = %d",
                $connected_staff_id
            ));
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 sprintf(__('Google Calendar connected successfully for %s. Please select which calendar to use below.', 'sn-appointment-booking'), esc_html($staff_name)) .
                 '</p></div>';
        }
        ?>
        <div class="wrap snab-admin-wrap">
            <h1>
                <?php esc_html_e('Staff Members', 'sn-appointment-booking'); ?>
                <button type="button" class="page-title-action snab-add-staff-btn">
                    <?php esc_html_e('Add New', 'sn-appointment-booking'); ?>
                </button>
            </h1>

            <p class="description">
                <?php esc_html_e('Manage staff members who can receive appointments. Each staff member can have their own availability schedule and Google Calendar connection.', 'sn-appointment-booking'); ?>
            </p>

            <?php if (!$google_configured): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        printf(
                            __('Google Calendar is not configured. <a href="%s">Configure Google Calendar</a> first to enable staff calendar connections.', 'sn-appointment-booking'),
                            admin_url('admin.php?page=snab-settings&tab=google')
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div id="snab-staff-notice" class="notice" style="display: none;"></div>

            <!-- Staff List -->
            <div class="snab-section">
                <table class="wp-list-table widefat fixed striped snab-staff-table" id="snab-staff-table">
                    <thead>
                        <tr>
                            <th class="column-avatar" style="width: 50px;"></th>
                            <th class="column-name"><?php esc_html_e('Name', 'sn-appointment-booking'); ?></th>
                            <th class="column-email"><?php esc_html_e('Email', 'sn-appointment-booking'); ?></th>
                            <th class="column-services" style="width: 200px;"><?php esc_html_e('Services', 'sn-appointment-booking'); ?></th>
                            <th class="column-calendar" style="width: 120px;"><?php esc_html_e('Calendar', 'sn-appointment-booking'); ?></th>
                            <th class="column-primary" style="width: 80px;"><?php esc_html_e('Primary', 'sn-appointment-booking'); ?></th>
                            <th class="column-status" style="width: 80px;"><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                            <th class="column-actions" style="width: 150px;"><?php esc_html_e('Actions', 'sn-appointment-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="snab-staff-list">
                        <?php if (empty($staff)): ?>
                            <tr class="snab-no-staff">
                                <td colspan="8"><?php esc_html_e('No staff members found. Click "Add New" to create one.', 'sn-appointment-booking'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff as $member): ?>
                                <?php $this->render_staff_row($member); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add/Edit Modal -->
            <div id="snab-staff-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content">
                    <div class="snab-modal-header">
                        <h2 id="snab-staff-modal-title"><?php esc_html_e('Add Staff Member', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <div class="snab-modal-body">
                        <form id="snab-staff-form">
                            <input type="hidden" name="staff_id" id="snab-staff-id" value="">

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="snab-staff-name"><?php esc_html_e('Name', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" id="snab-staff-name" name="name" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="snab-staff-email"><?php esc_html_e('Email', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="email" id="snab-staff-email" name="email" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="snab-staff-phone"><?php esc_html_e('Phone', 'sn-appointment-booking'); ?></label>
                                    </th>
                                    <td>
                                        <input type="tel" id="snab-staff-phone" name="phone" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="snab-staff-bio"><?php esc_html_e('Bio', 'sn-appointment-booking'); ?></label>
                                    </th>
                                    <td>
                                        <textarea id="snab-staff-bio" name="bio" rows="3" class="large-text"></textarea>
                                        <p class="description"><?php esc_html_e('Brief description shown to clients when selecting staff.', 'sn-appointment-booking'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="snab-staff-user"><?php esc_html_e('WordPress User', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <?php
                                        wp_dropdown_users(array(
                                            'name' => 'user_id',
                                            'id' => 'snab-staff-user',
                                            'show_option_none' => __('— None —', 'sn-appointment-booking'),
                                            'option_none_value' => '',
                                        ));
                                        ?>
                                        <p class="description"><?php esc_html_e('Required. Staff must be linked to a WordPress user to view their appointments.', 'sn-appointment-booking'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <?php esc_html_e('Appointment Types', 'sn-appointment-booking'); ?>
                                    </th>
                                    <td>
                                        <fieldset id="snab-staff-services">
                                            <?php foreach ($appointment_types as $type): ?>
                                                <label style="display: block; margin-bottom: 5px;">
                                                    <input type="checkbox" name="services[]" value="<?php echo esc_attr($type->id); ?>">
                                                    <?php echo esc_html($type->name); ?>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if (empty($appointment_types)): ?>
                                                <em><?php esc_html_e('No appointment types available.', 'sn-appointment-booking'); ?></em>
                                            <?php endif; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('Select which appointment types this staff member can handle.', 'sn-appointment-booking'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </form>
                    </div>
                    <div class="snab-modal-footer">
                        <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                        <button type="button" class="button button-primary" id="snab-save-staff"><?php esc_html_e('Save Staff Member', 'sn-appointment-booking'); ?></button>
                        <span class="spinner"></span>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .snab-staff-table .column-avatar img {
                width: 32px;
                height: 32px;
                border-radius: 50%;
            }
            .snab-staff-table .snab-primary-badge {
                background: #2271b1;
                color: #fff;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
            }
            .snab-staff-table .snab-calendar-status {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .snab-staff-table .snab-calendar-status.connected {
                color: #00a32a;
            }
            .snab-staff-table .snab-calendar-status.not-connected {
                color: #d63638;
            }
            .snab-services-list {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
            }
            .snab-service-badge {
                background: #f0f0f1;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
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
                max-width: 600px;
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
                text-align: right;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                align-items: center;
            }
            .snab-modal-footer .spinner {
                float: none;
                margin: 0;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';

            // Open add modal
            $('.snab-add-staff-btn').on('click', function() {
                $('#snab-staff-modal-title').text('<?php echo esc_js(__('Add Staff Member', 'sn-appointment-booking')); ?>');
                $('#snab-staff-form')[0].reset();
                $('#snab-staff-id').val('');
                $('#snab-staff-services input[type="checkbox"]').prop('checked', true); // Default: all services
                $('#snab-staff-modal').show();
            });

            // Close modal
            $('.snab-modal-close, .snab-modal-cancel, .snab-modal-overlay').on('click', function() {
                $('#snab-staff-modal').hide();
            });

            // Edit staff
            $(document).on('click', '.snab-edit-staff', function() {
                var staffId = $(this).data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_get_staff',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        if (response.success) {
                            var staff = response.data;
                            $('#snab-staff-modal-title').text('<?php echo esc_js(__('Edit Staff Member', 'sn-appointment-booking')); ?>');
                            $('#snab-staff-id').val(staff.id);
                            $('#snab-staff-name').val(staff.name);
                            $('#snab-staff-email').val(staff.email);
                            $('#snab-staff-phone').val(staff.phone || '');
                            $('#snab-staff-bio').val(staff.bio || '');
                            $('#snab-staff-user').val(staff.user_id || '');

                            // Set services checkboxes
                            $('#snab-staff-services input[type="checkbox"]').prop('checked', false);
                            if (staff.services) {
                                staff.services.forEach(function(serviceId) {
                                    $('#snab-staff-services input[value="' + serviceId + '"]').prop('checked', true);
                                });
                            }

                            $('#snab-staff-modal').show();
                        } else {
                            alert(response.data || 'Error loading staff data');
                        }
                    }
                });
            });

            // Save staff
            $('#snab-save-staff').on('click', function() {
                var $btn = $(this);
                var $spinner = $btn.next('.spinner');
                var formData = {
                    action: 'snab_save_staff',
                    nonce: nonce,
                    staff_id: $('#snab-staff-id').val(),
                    name: $('#snab-staff-name').val(),
                    email: $('#snab-staff-email').val(),
                    phone: $('#snab-staff-phone').val(),
                    bio: $('#snab-staff-bio').val(),
                    user_id: $('#snab-staff-user').val(),
                    services: []
                };

                $('#snab-staff-services input[type="checkbox"]:checked').each(function() {
                    formData.services.push($(this).val());
                });

                if (!formData.name || !formData.email) {
                    alert('<?php echo esc_js(__('Name and email are required.', 'sn-appointment-booking')); ?>');
                    return;
                }

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error saving staff');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });

            // Delete staff
            $(document).on('click', '.snab-delete-staff', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this staff member?', 'sn-appointment-booking')); ?>')) {
                    return;
                }

                var staffId = $(this).data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_delete_staff',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error deleting staff');
                        }
                    }
                });
            });

            // Toggle status
            $(document).on('click', '.snab-toggle-staff', function() {
                var staffId = $(this).data('id');
                var $row = $(this).closest('tr');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_toggle_staff_status',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error toggling status');
                        }
                    }
                });
            });

            // Set as primary
            $(document).on('click', '.snab-set-primary', function() {
                var staffId = $(this).data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_set_primary_staff',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error setting primary staff');
                        }
                    }
                });
            });

            // Disconnect calendar
            $(document).on('click', '.snab-disconnect-calendar', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to disconnect this staff member\'s Google Calendar?', 'sn-appointment-booking')); ?>')) {
                    return;
                }

                var staffId = $(this).data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_staff_google_disconnect',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error disconnecting calendar');
                        }
                    }
                });
            });

            // Select calendar modal
            $(document).on('click', '.snab-select-calendar', function() {
                var staffId = $(this).data('id');
                var $btn = $(this);

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Loading...', 'sn-appointment-booking')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_staff_get_calendars',
                        nonce: nonce,
                        staff_id: staffId
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Select Calendar', 'sn-appointment-booking')); ?>');

                        if (response.success) {
                            showCalendarModal(staffId, response.data);
                        } else {
                            alert(response.data || 'Error loading calendars');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Select Calendar', 'sn-appointment-booking')); ?>');
                        alert('<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                    }
                });
            });

            function showCalendarModal(staffId, calendars) {
                var html = '<div id="snab-calendar-modal" class="snab-modal">' +
                    '<div class="snab-modal-overlay"></div>' +
                    '<div class="snab-modal-content" style="max-width: 500px;">' +
                    '<div class="snab-modal-header">' +
                    '<h2><?php echo esc_js(__('Select Calendar', 'sn-appointment-booking')); ?></h2>' +
                    '<button type="button" class="snab-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="snab-modal-body">' +
                    '<p><?php echo esc_js(__('Select which calendar to use for this staff member\'s appointments:', 'sn-appointment-booking')); ?></p>' +
                    '<select id="snab-calendar-select" class="regular-text" style="width: 100%;">';

                calendars.forEach(function(cal) {
                    var label = cal.summary + (cal.primary ? ' (Primary)' : '');
                    html += '<option value="' + cal.id + '">' + label + '</option>';
                });

                html += '</select>' +
                    '</div>' +
                    '<div class="snab-modal-footer">' +
                    '<button type="button" class="button snab-modal-cancel"><?php echo esc_js(__('Cancel', 'sn-appointment-booking')); ?></button>' +
                    '<button type="button" class="button button-primary" id="snab-save-calendar"><?php echo esc_js(__('Save', 'sn-appointment-booking')); ?></button>' +
                    '<span class="spinner"></span>' +
                    '</div>' +
                    '</div>' +
                    '</div>';

                $('body').append(html);

                $('#snab-calendar-modal .snab-modal-close, #snab-calendar-modal .snab-modal-cancel, #snab-calendar-modal .snab-modal-overlay').on('click', function() {
                    $('#snab-calendar-modal').remove();
                });

                $('#snab-save-calendar').on('click', function() {
                    var calendarId = $('#snab-calendar-select').val();
                    var $saveBtn = $(this);
                    var $spinner = $saveBtn.next('.spinner');

                    $saveBtn.prop('disabled', true);
                    $spinner.addClass('is-active');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'snab_staff_set_calendar',
                            nonce: nonce,
                            staff_id: staffId,
                            calendar_id: calendarId
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#snab-calendar-modal').remove();
                                location.reload();
                            } else {
                                alert(response.data || 'Error saving calendar');
                                $saveBtn.prop('disabled', false);
                                $spinner.removeClass('is-active');
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                            $saveBtn.prop('disabled', false);
                            $spinner.removeClass('is-active');
                        }
                    });
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Render a single staff row.
     *
     * @param object $member Staff member object.
     */
    private function render_staff_row($member) {
        $services = $this->get_staff_services($member->id);
        $avatar = $member->avatar_url ? $member->avatar_url : get_avatar_url($member->email, array('size' => 32));
        $is_connected = !empty($member->google_refresh_token);
        $google_configured = $this->google_calendar->is_configured();
        ?>
        <tr data-id="<?php echo esc_attr($member->id); ?>">
            <td class="column-avatar">
                <img src="<?php echo esc_url($avatar); ?>" alt="">
            </td>
            <td class="column-name">
                <strong><?php echo esc_html($member->name); ?></strong>
                <?php if ($member->phone): ?>
                    <br><small><?php echo esc_html($member->phone); ?></small>
                <?php endif; ?>
            </td>
            <td class="column-email">
                <a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a>
            </td>
            <td class="column-services">
                <div class="snab-services-list">
                    <?php if (empty($services)): ?>
                        <em><?php esc_html_e('None', 'sn-appointment-booking'); ?></em>
                    <?php else: ?>
                        <?php foreach ($services as $service): ?>
                            <span class="snab-service-badge"><?php echo esc_html($service->name); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </td>
            <td class="column-calendar">
                <?php if ($is_connected): ?>
                    <span class="snab-calendar-status connected">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Connected', 'sn-appointment-booking'); ?>
                    </span>
                    <?php if ($member->google_calendar_id): ?>
                        <br><small class="snab-calendar-name" title="<?php echo esc_attr($member->google_calendar_id); ?>">
                            <?php echo esc_html(strlen($member->google_calendar_id) > 20 ? substr($member->google_calendar_id, 0, 20) . '...' : $member->google_calendar_id); ?>
                        </small>
                    <?php endif; ?>
                    <div class="snab-calendar-actions" style="margin-top: 5px;">
                        <button type="button" class="button button-small snab-select-calendar" data-id="<?php echo esc_attr($member->id); ?>">
                            <?php esc_html_e('Select Calendar', 'sn-appointment-booking'); ?>
                        </button>
                        <button type="button" class="button button-small snab-disconnect-calendar" data-id="<?php echo esc_attr($member->id); ?>">
                            <?php esc_html_e('Disconnect', 'sn-appointment-booking'); ?>
                        </button>
                    </div>
                <?php else: ?>
                    <span class="snab-calendar-status not-connected">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Not connected', 'sn-appointment-booking'); ?>
                    </span>
                    <?php if ($google_configured): ?>
                        <?php $auth_url = $this->google_calendar->get_staff_auth_url($member->id); ?>
                        <?php if ($auth_url): ?>
                            <div style="margin-top: 5px;">
                                <a href="<?php echo esc_url($auth_url); ?>" class="button button-small button-primary">
                                    <?php esc_html_e('Connect Calendar', 'sn-appointment-booking'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="column-primary">
                <?php if ($member->is_primary): ?>
                    <span class="snab-primary-badge"><?php esc_html_e('Primary', 'sn-appointment-booking'); ?></span>
                <?php else: ?>
                    <button type="button" class="button button-small snab-set-primary" data-id="<?php echo esc_attr($member->id); ?>">
                        <?php esc_html_e('Set', 'sn-appointment-booking'); ?>
                    </button>
                <?php endif; ?>
            </td>
            <td class="column-status">
                <?php if ($member->is_active): ?>
                    <span class="snab-status-active"><?php esc_html_e('Active', 'sn-appointment-booking'); ?></span>
                <?php else: ?>
                    <span class="snab-status-inactive"><?php esc_html_e('Inactive', 'sn-appointment-booking'); ?></span>
                <?php endif; ?>
            </td>
            <td class="column-actions">
                <button type="button" class="button button-small snab-edit-staff" data-id="<?php echo esc_attr($member->id); ?>">
                    <?php esc_html_e('Edit', 'sn-appointment-booking'); ?>
                </button>
                <button type="button" class="button button-small snab-toggle-staff" data-id="<?php echo esc_attr($member->id); ?>">
                    <?php echo $member->is_active ? esc_html__('Disable', 'sn-appointment-booking') : esc_html__('Enable', 'sn-appointment-booking'); ?>
                </button>
                <?php if (!$member->is_primary): ?>
                    <button type="button" class="button button-small snab-delete-staff" data-id="<?php echo esc_attr($member->id); ?>">
                        <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Get all staff members.
     *
     * @return array
     */
    private function get_all_staff() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY is_primary DESC, name ASC");
    }

    /**
     * Get all appointment types.
     *
     * @return array
     */
    private function get_appointment_types() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}snab_appointment_types WHERE is_active = 1 ORDER BY sort_order ASC"
        );
    }

    /**
     * Get services for a staff member.
     *
     * @param int $staff_id Staff ID.
     * @return array
     */
    private function get_staff_services($staff_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.name
             FROM {$this->services_table} ss
             JOIN {$wpdb->prefix}snab_appointment_types t ON ss.appointment_type_id = t.id
             WHERE ss.staff_id = %d AND ss.is_active = 1 AND t.is_active = 1
             ORDER BY t.sort_order ASC",
            $staff_id
        ));
    }

    /**
     * AJAX: Save staff member.
     */
    public function ajax_save_staff() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $bio = isset($_POST['bio']) ? sanitize_textarea_field($_POST['bio']) : '';
        $user_id = isset($_POST['user_id']) && $_POST['user_id'] ? absint($_POST['user_id']) : null;
        $services = isset($_POST['services']) ? array_map('absint', (array) $_POST['services']) : array();

        if (empty($name) || empty($email)) {
            wp_send_json_error(__('Name and email are required.', 'sn-appointment-booking'));
        }

        if (empty($user_id)) {
            wp_send_json_error(__('WordPress User is required. Staff members must be linked to a user account to view their appointments.', 'sn-appointment-booking'));
        }

        $data = array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'bio' => $bio,
            'user_id' => $user_id,
            'updated_at' => current_time('mysql'),
        );

        $format = array('%s', '%s', '%s', '%s', '%d', '%s');

        if ($staff_id) {
            // Update
            $result = $wpdb->update($this->table_name, $data, array('id' => $staff_id), $format, array('%d'));
        } else {
            // Insert
            $data['created_at'] = current_time('mysql');
            $data['is_active'] = 1;
            $format[] = '%s';
            $format[] = '%d';
            $result = $wpdb->insert($this->table_name, $data, $format);
            $staff_id = $wpdb->insert_id;
        }

        if ($result === false) {
            wp_send_json_error(__('Database error.', 'sn-appointment-booking'));
        }

        // Update services
        $wpdb->delete($this->services_table, array('staff_id' => $staff_id), array('%d'));
        if (!empty($services)) {
            $now = current_time('mysql');
            foreach ($services as $type_id) {
                $wpdb->insert(
                    $this->services_table,
                    array(
                        'staff_id' => $staff_id,
                        'appointment_type_id' => $type_id,
                        'is_active' => 1,
                        'created_at' => $now,
                    ),
                    array('%d', '%d', '%d', '%s')
                );
            }
        }

        wp_send_json_success(array('id' => $staff_id));
    }

    /**
     * AJAX: Get staff member.
     */
    public function ajax_get_staff() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;

        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $staff_id
        ));

        if (!$staff) {
            wp_send_json_error(__('Staff member not found.', 'sn-appointment-booking'));
        }

        // Get services
        $services = $wpdb->get_col($wpdb->prepare(
            "SELECT appointment_type_id FROM {$this->services_table} WHERE staff_id = %d AND is_active = 1",
            $staff_id
        ));

        $staff->services = $services;

        wp_send_json_success($staff);
    }

    /**
     * AJAX: Delete staff member.
     */
    public function ajax_delete_staff() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;

        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        // Check if primary
        $is_primary = $wpdb->get_var($wpdb->prepare(
            "SELECT is_primary FROM {$this->table_name} WHERE id = %d",
            $staff_id
        ));

        if ($is_primary) {
            wp_send_json_error(__('Cannot delete the primary staff member.', 'sn-appointment-booking'));
        }

        // Delete services first
        $wpdb->delete($this->services_table, array('staff_id' => $staff_id), array('%d'));

        // Delete staff
        $wpdb->delete($this->table_name, array('id' => $staff_id), array('%d'));

        wp_send_json_success();
    }

    /**
     * AJAX: Toggle staff status.
     */
    public function ajax_toggle_status() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;

        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$this->table_name} WHERE id = %d",
            $staff_id
        ));

        $wpdb->update(
            $this->table_name,
            array('is_active' => $current ? 0 : 1, 'updated_at' => current_time('mysql')),
            array('id' => $staff_id),
            array('%d', '%s'),
            array('%d')
        );

        wp_send_json_success();
    }

    /**
     * AJAX: Set primary staff.
     */
    public function ajax_set_primary() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;

        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        // Remove primary from all
        $wpdb->update($this->table_name, array('is_primary' => 0), array('is_primary' => 1), array('%d'), array('%d'));

        // Set new primary
        $wpdb->update($this->table_name, array('is_primary' => 1), array('id' => $staff_id), array('%d'), array('%d'));

        wp_send_json_success();
    }
}
