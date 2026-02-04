<?php
/**
 * Shortcodes Class
 *
 * Registers and handles all plugin shortcodes.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcodes class.
 *
 * @since 1.0.0
 */
class SNAB_Shortcodes {

    /**
     * Availability service instance.
     *
     * @var SNAB_Availability_Service
     */
    private $availability_service;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->availability_service = new SNAB_Availability_Service();

        // Register shortcodes
        add_shortcode('snab_booking_form', array($this, 'booking_form_shortcode'));
        add_shortcode('snab_my_appointments', array($this, 'my_appointments_shortcode'));
    }

    /**
     * Render the booking form shortcode.
     *
     * Supports loading settings from a preset or using inline attributes.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     *
     * @since 1.0.0
     * @since 1.2.0 Added preset support and new attributes.
     */
    public function booking_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'preset' => '',         // Load preset by slug
            'type' => '',           // Filter to specific type slug(s), comma-separated
            'types' => '',          // Alias for type - filter by type IDs, comma-separated
            'weeks' => '',          // Number of weeks to show (empty means use default or preset)
            'days' => '',           // Allowed days: mon,tue,wed,thu,fri,sat,sun
            'start_hour' => '',     // Earliest hour (0-23)
            'end_hour' => '',       // Latest hour (0-23)
            'location' => '',       // Pre-fill location field
            'title' => '',          // Custom widget title
            'class' => '',          // Custom CSS class
            'show_timezone' => 'true',
            'staff_selection' => '',  // Staff selection mode: disabled, optional, required (empty = use global setting)
            'staff' => '',          // Pre-select specific staff member by ID
        ), $atts, 'snab_booking_form');

        // Check for staff parameter in URL (e.g., /book/?staff=2)
        // This allows linking directly to a booking form with a specific staff pre-selected
        if (empty($atts['staff']) && isset($_GET['staff'])) {
            $atts['staff'] = absint($_GET['staff']);
        }

        // Load preset if specified
        $preset = null;
        if (!empty($atts['preset'])) {
            $preset = $this->load_preset($atts['preset']);
        }

        // Merge preset settings with shortcode attributes (shortcode attributes take precedence)
        $settings = $this->merge_settings_with_preset($atts, $preset);

        // Parse type filters
        $type_filter = array();
        $type_id_filter = array();

        // First check for type IDs from preset or 'types' attribute
        if (!empty($settings['appointment_types'])) {
            $type_id_filter = array_map('intval', array_filter(explode(',', $settings['appointment_types'])));
        }

        // Then check for type slugs from 'type' attribute (overrides type IDs if specified)
        if (!empty($settings['type'])) {
            $type_filter = array_map('trim', explode(',', $settings['type']));
        }

        // Parse other settings
        $weeks = !empty($settings['weeks']) ? max(1, min(8, (int) $settings['weeks'])) : 2;
        $allowed_days = !empty($settings['allowed_days']) ? $this->parse_allowed_days($settings['allowed_days']) : array();
        $start_hour = $settings['start_hour'] !== '' ? max(0, min(23, (int) $settings['start_hour'])) : null;
        $end_hour = $settings['end_hour'] !== '' ? max(0, min(23, (int) $settings['end_hour'])) : null;
        $default_location = $settings['default_location'];
        $custom_title = $settings['custom_title'];
        $css_class = $settings['css_class'];
        $show_timezone = filter_var($atts['show_timezone'], FILTER_VALIDATE_BOOLEAN);

        // Get appointment types
        $all_types = $this->availability_service->get_active_appointment_types();

        // Filter types if specified
        $types = array();
        if (!empty($type_filter)) {
            // Filter by type slugs
            foreach ($all_types as $type) {
                if (in_array($type->slug, $type_filter, true)) {
                    $types[] = $type;
                }
            }
        } elseif (!empty($type_id_filter)) {
            // Filter by type IDs
            foreach ($all_types as $type) {
                if (in_array((int) $type->id, $type_id_filter, true)) {
                    $types[] = $type;
                }
            }
        } else {
            $types = $all_types;
        }

        if (empty($types)) {
            return '<p class="snab-no-types">' . esc_html__('No appointment types available.', 'sn-appointment-booking') . '</p>';
        }

        // Calculate date range
        $start_date = wp_date('Y-m-d');
        $end_date = wp_date('Y-m-d', strtotime("+{$weeks} weeks"));

        // Build availability filter options
        $availability_options = array(
            'allowed_days' => $allowed_days,
            'start_hour' => $start_hour,
            'end_hour' => $end_hour,
        );

        // Determine staff selection mode
        $staff_selection_mode = !empty($atts['staff_selection']) ? $atts['staff_selection'] : get_option('snab_staff_selection_mode', 'disabled');
        if (!in_array($staff_selection_mode, array('disabled', 'optional', 'required'), true)) {
            $staff_selection_mode = 'disabled';
        }

        // Get active staff for staff selection (only if mode is not disabled)
        $active_staff = array();
        if ($staff_selection_mode !== 'disabled') {
            $active_staff = $this->get_active_staff();
            // If only one staff, disable staff selection
            if (count($active_staff) <= 1) {
                $staff_selection_mode = 'disabled';
            }
        }

        // Staff display options
        $show_staff_avatar = get_option('snab_show_staff_avatar', true);
        $show_staff_bio = get_option('snab_show_staff_bio', false);

        // Enqueue frontend assets
        $this->enqueue_frontend_assets();

        // Generate unique ID for this widget instance
        $widget_id = 'snab-booking-' . wp_rand(1000, 9999);

        // Build widget CSS classes
        $widget_classes = array('snab-booking-widget');
        if (!empty($css_class)) {
            $widget_classes[] = sanitize_html_class($css_class);
        }

        // Start output buffering
        ob_start();
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>" class="<?php echo esc_attr(implode(' ', $widget_classes)); ?>"
             data-start-date="<?php echo esc_attr($start_date); ?>"
             data-end-date="<?php echo esc_attr($end_date); ?>"
             data-weeks="<?php echo esc_attr($weeks); ?>"
             data-allowed-days="<?php echo esc_attr(!empty($allowed_days) ? implode(',', $allowed_days) : ''); ?>"
             data-start-hour="<?php echo esc_attr($start_hour !== null ? $start_hour : ''); ?>"
             data-end-hour="<?php echo esc_attr($end_hour !== null ? $end_hour : ''); ?>"
             data-default-location="<?php echo esc_attr($default_location); ?>"
             data-staff-selection="<?php echo esc_attr($staff_selection_mode); ?>"
             data-show-staff-avatar="<?php echo esc_attr($show_staff_avatar ? 'true' : 'false'); ?>"
             data-show-staff-bio="<?php echo esc_attr($show_staff_bio ? 'true' : 'false'); ?>"
             data-preselected-staff="<?php echo esc_attr($atts['staff']); ?>">

            <?php if (!empty($custom_title)): ?>
                <h2 class="snab-widget-title"><?php echo esc_html($custom_title); ?></h2>
            <?php endif; ?>

            <!-- Step 1: Select Appointment Type -->
            <div class="snab-step snab-step-type active" data-step="1">
                <h3 class="snab-step-title"><?php esc_html_e('Select Appointment Type', 'sn-appointment-booking'); ?></h3>
                <div class="snab-type-list">
                    <?php foreach ($types as $type): ?>
                        <button type="button" class="snab-type-option"
                                data-type-id="<?php echo esc_attr($type->id); ?>"
                                data-type-slug="<?php echo esc_attr($type->slug); ?>"
                                data-duration="<?php echo esc_attr($type->duration_minutes); ?>">
                            <span class="snab-type-color" style="background-color: <?php echo esc_attr($type->color); ?>"></span>
                            <span class="snab-type-info">
                                <span class="snab-type-name"><?php echo esc_html($type->name); ?></span>
                                <span class="snab-type-duration">
                                    <?php echo esc_html(sprintf(
                                        _n('%d minute', '%d minutes', $type->duration_minutes, 'sn-appointment-booking'),
                                        $type->duration_minutes
                                    )); ?>
                                </span>
                                <?php if (!empty($type->description)): ?>
                                    <span class="snab-type-description"><?php echo esc_html($type->description); ?></span>
                                <?php endif; ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 1.5: Select Staff (conditional) -->
            <?php if ($staff_selection_mode !== 'disabled' && !empty($active_staff)): ?>
            <div class="snab-step snab-step-staff" data-step="1.5">
                <h3 class="snab-step-title">
                    <button type="button" class="snab-back-btn" data-back="1">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <?php esc_html_e('Select Staff Member', 'sn-appointment-booking'); ?>
                </h3>
                <div class="snab-selected-type"></div>
                <div class="snab-staff-list">
                    <?php if ($staff_selection_mode === 'optional'): ?>
                        <button type="button" class="snab-staff-option snab-any-staff"
                                data-staff-id="0"
                                data-staff-name="<?php esc_attr_e('Any Available', 'sn-appointment-booking'); ?>">
                            <span class="snab-staff-avatar snab-staff-any-icon">
                                <span class="dashicons dashicons-groups"></span>
                            </span>
                            <span class="snab-staff-info">
                                <span class="snab-staff-name"><?php esc_html_e('Any Available', 'sn-appointment-booking'); ?></span>
                                <span class="snab-staff-description"><?php esc_html_e('Book with the first available staff member', 'sn-appointment-booking'); ?></span>
                            </span>
                        </button>
                    <?php endif; ?>

                    <?php foreach ($active_staff as $staff): ?>
                        <button type="button" class="snab-staff-option"
                                data-staff-id="<?php echo esc_attr($staff->id); ?>"
                                data-staff-name="<?php echo esc_attr($staff->name); ?>"
                                data-staff-services="<?php echo esc_attr(implode(',', $staff->service_ids ?? array())); ?>">
                            <?php if ($show_staff_avatar && !empty($staff->avatar_url)): ?>
                                <span class="snab-staff-avatar">
                                    <img src="<?php echo esc_url($staff->avatar_url); ?>" alt="<?php echo esc_attr($staff->name); ?>">
                                </span>
                            <?php elseif ($show_staff_avatar): ?>
                                <span class="snab-staff-avatar snab-staff-initials">
                                    <?php echo esc_html($this->get_initials($staff->name)); ?>
                                </span>
                            <?php endif; ?>
                            <span class="snab-staff-info">
                                <span class="snab-staff-name"><?php echo esc_html($staff->name); ?></span>
                                <?php if (!empty($staff->title)): ?>
                                    <span class="snab-staff-title"><?php echo esc_html($staff->title); ?></span>
                                <?php endif; ?>
                                <?php if ($show_staff_bio && !empty($staff->bio)): ?>
                                    <span class="snab-staff-bio"><?php echo esc_html(wp_trim_words($staff->bio, 20)); ?></span>
                                <?php endif; ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Step 2: Select Date -->
            <div class="snab-step snab-step-date" data-step="2">
                <h3 class="snab-step-title">
                    <button type="button" class="snab-back-btn" data-back="<?php echo $staff_selection_mode !== 'disabled' ? '1.5' : '1'; ?>">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <?php esc_html_e('Select Date', 'sn-appointment-booking'); ?>
                </h3>
                <div class="snab-selected-type"></div>
                <div class="snab-calendar-container">
                    <div class="snab-calendar-header">
                        <button type="button" class="snab-calendar-nav snab-prev-week" disabled>
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                        </button>
                        <span class="snab-calendar-title"></span>
                        <button type="button" class="snab-calendar-nav snab-next-week">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                    <div class="snab-calendar-grid">
                        <div class="snab-calendar-loading">
                            <span class="spinner is-active"></span>
                            <?php esc_html_e('Loading availability...', 'sn-appointment-booking'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Select Time -->
            <div class="snab-step snab-step-time" data-step="3">
                <h3 class="snab-step-title">
                    <button type="button" class="snab-back-btn" data-back="2">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <?php esc_html_e('Select Time', 'sn-appointment-booking'); ?>
                </h3>
                <div class="snab-selected-date"></div>
                <div class="snab-time-slots">
                    <div class="snab-slots-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e('Loading time slots...', 'sn-appointment-booking'); ?>
                    </div>
                </div>
            </div>

            <!-- Step 4: Your Information -->
            <div class="snab-step snab-step-info" data-step="4">
                <h3 class="snab-step-title">
                    <button type="button" class="snab-back-btn" data-back="3">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <?php esc_html_e('Your Information', 'sn-appointment-booking'); ?>
                </h3>
                <div class="snab-booking-summary"></div>
                <form class="snab-booking-form" id="<?php echo esc_attr($widget_id); ?>-form">
                    <?php wp_nonce_field('snab_book_appointment', 'snab_booking_nonce'); ?>
                    <input type="hidden" name="appointment_type_id" value="">
                    <input type="hidden" name="staff_id" value="">
                    <input type="hidden" name="appointment_date" value="">
                    <input type="hidden" name="appointment_time" value="">

                    <!-- Client Selection for Agents (populated via JS if user is agent) -->
                    <div class="snab-client-selection" style="display: none;">
                        <div class="snab-client-selection-header">
                            <span class="snab-client-selection-label"><?php esc_html_e('Book for a Client', 'sn-appointment-booking'); ?></span>
                            <button type="button" class="snab-client-clear-btn" style="display: none;">
                                <?php esc_html_e('Clear', 'sn-appointment-booking'); ?>
                            </button>
                        </div>
                        <div class="snab-client-search-wrapper">
                            <input type="text" class="snab-client-search"
                                   placeholder="<?php esc_attr_e('Search clients by name...', 'sn-appointment-booking'); ?>">
                            <span class="dashicons dashicons-search snab-client-search-icon"></span>
                        </div>
                        <div class="snab-client-bubbles"></div>
                        <div class="snab-client-no-results" style="display: none;">
                            <?php esc_html_e('No clients match your search', 'sn-appointment-booking'); ?>
                        </div>
                    </div>

                    <div class="snab-form-row">
                        <label for="<?php echo esc_attr($widget_id); ?>-name">
                            <?php esc_html_e('Full Name', 'sn-appointment-booking'); ?> <span class="required">*</span>
                        </label>
                        <input type="text" id="<?php echo esc_attr($widget_id); ?>-name"
                               name="client_name" required
                               placeholder="<?php esc_attr_e('John Smith', 'sn-appointment-booking'); ?>">
                    </div>

                    <div class="snab-form-row">
                        <label for="<?php echo esc_attr($widget_id); ?>-email">
                            <?php esc_html_e('Email Address', 'sn-appointment-booking'); ?> <span class="required">*</span>
                        </label>
                        <input type="email" id="<?php echo esc_attr($widget_id); ?>-email"
                               name="client_email" required
                               placeholder="<?php esc_attr_e('john@example.com', 'sn-appointment-booking'); ?>">
                    </div>

                    <div class="snab-form-row">
                        <label for="<?php echo esc_attr($widget_id); ?>-phone">
                            <?php esc_html_e('Phone Number', 'sn-appointment-booking'); ?>
                        </label>
                        <input type="tel" id="<?php echo esc_attr($widget_id); ?>-phone"
                               name="client_phone"
                               placeholder="<?php esc_attr_e('(555) 123-4567', 'sn-appointment-booking'); ?>">
                    </div>

                    <div class="snab-form-row snab-property-field" style="display: none;">
                        <label for="<?php echo esc_attr($widget_id); ?>-address">
                            <?php esc_html_e('Property Address', 'sn-appointment-booking'); ?>
                        </label>
                        <input type="text" id="<?php echo esc_attr($widget_id); ?>-address"
                               name="property_address"
                               placeholder="<?php esc_attr_e('123 Main St, City, State', 'sn-appointment-booking'); ?>">
                    </div>

                    <div class="snab-form-row">
                        <label for="<?php echo esc_attr($widget_id); ?>-notes">
                            <?php esc_html_e('Additional Notes', 'sn-appointment-booking'); ?>
                        </label>
                        <textarea id="<?php echo esc_attr($widget_id); ?>-notes"
                                  name="client_notes" rows="3"
                                  placeholder="<?php esc_attr_e('Any additional information...', 'sn-appointment-booking'); ?>"></textarea>
                    </div>

                    <div class="snab-form-actions">
                        <button type="submit" class="snab-submit-btn">
                            <?php esc_html_e('Confirm Booking', 'sn-appointment-booking'); ?>
                        </button>
                    </div>

                    <div class="snab-form-error" style="display: none;"></div>
                </form>
            </div>

            <!-- Step 5: Confirmation -->
            <div class="snab-step snab-step-confirmation" data-step="5">
                <div class="snab-confirmation-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h3 class="snab-confirmation-title"><?php esc_html_e('Booking Confirmed!', 'sn-appointment-booking'); ?></h3>
                <p class="snab-confirmation-message">
                    <?php esc_html_e('Your appointment has been booked successfully. A confirmation email has been sent to your email address.', 'sn-appointment-booking'); ?>
                </p>
                <div class="snab-confirmation-details"></div>
                <div class="snab-confirmation-actions">
                    <button type="button" class="snab-book-another">
                        <?php esc_html_e('Book Another Appointment', 'sn-appointment-booking'); ?>
                    </button>
                </div>
            </div>

            <?php if ($show_timezone): ?>
                <div class="snab-timezone-notice">
                    <?php echo esc_html(sprintf(
                        __('All times are shown in %s timezone.', 'sn-appointment-booking'),
                        wp_timezone_string()
                    )); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the client portal shortcode.
     *
     * Displays the logged-in user's appointments with options to
     * cancel or reschedule.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     *
     * @since 1.5.0
     */
    public function my_appointments_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_past' => 'true',         // Show past appointments
            'days_past' => '90',           // Days of history to show
            'allow_cancel' => 'true',      // Enable cancel button
            'allow_reschedule' => 'true',  // Enable reschedule button
            'class' => '',                 // Custom CSS class
        ), $atts, 'snab_my_appointments');

        // Check if client portal is enabled
        $portal = snab_client_portal();
        if (!$portal->is_enabled()) {
            return '<p class="snab-portal-disabled">' . esc_html__('Client portal is currently disabled.', 'sn-appointment-booking') . '</p>';
        }

        // Parse attributes
        $show_past = filter_var($atts['show_past'], FILTER_VALIDATE_BOOLEAN);
        $days_past = max(1, min(365, absint($atts['days_past'])));
        $allow_cancel = filter_var($atts['allow_cancel'], FILTER_VALIDATE_BOOLEAN);
        $allow_reschedule = filter_var($atts['allow_reschedule'], FILTER_VALIDATE_BOOLEAN);
        $css_class = sanitize_html_class($atts['class']);

        // Enqueue client portal assets
        $this->enqueue_client_portal_assets();

        // Generate unique ID for this widget instance
        $widget_id = 'snab-portal-' . wp_rand(1000, 9999);

        // Build widget CSS classes
        $widget_classes = array('snab-client-portal');
        if (!empty($css_class)) {
            $widget_classes[] = $css_class;
        }

        // Check if user is logged in
        $is_logged_in = is_user_logged_in();

        // Start output buffering
        ob_start();
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>"
             class="<?php echo esc_attr(implode(' ', $widget_classes)); ?>"
             data-show-past="<?php echo esc_attr($show_past ? 'true' : 'false'); ?>"
             data-days-past="<?php echo esc_attr($days_past); ?>"
             data-allow-cancel="<?php echo esc_attr($allow_cancel ? 'true' : 'false'); ?>"
             data-allow-reschedule="<?php echo esc_attr($allow_reschedule ? 'true' : 'false'); ?>">

            <?php if (!$is_logged_in): ?>
                <!-- Login Required Message -->
                <div class="snab-portal-login-required">
                    <div class="snab-login-icon">
                        <span class="dashicons dashicons-lock"></span>
                    </div>
                    <h3><?php esc_html_e('Login Required', 'sn-appointment-booking'); ?></h3>
                    <p><?php esc_html_e('Please log in to view and manage your appointments.', 'sn-appointment-booking'); ?></p>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="snab-login-btn">
                        <?php esc_html_e('Log In', 'sn-appointment-booking'); ?>
                    </a>
                </div>
            <?php else: ?>
                <!-- Portal Content -->
                <div class="snab-portal-content">
                    <!-- Tabs -->
                    <div class="snab-portal-tabs">
                        <button type="button" class="snab-tab-btn active" data-tab="upcoming">
                            <?php esc_html_e('Upcoming', 'sn-appointment-booking'); ?>
                            <span class="snab-tab-count" data-count="upcoming">0</span>
                        </button>
                        <?php if ($show_past): ?>
                            <button type="button" class="snab-tab-btn" data-tab="past">
                                <?php esc_html_e('Past', 'sn-appointment-booking'); ?>
                                <span class="snab-tab-count" data-count="past">0</span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Appointments List -->
                    <div class="snab-portal-appointments">
                        <div class="snab-portal-loading">
                            <span class="spinner is-active"></span>
                            <?php esc_html_e('Loading your appointments...', 'sn-appointment-booking'); ?>
                        </div>
                        <div class="snab-appointments-list" style="display: none;"></div>
                        <div class="snab-no-appointments" style="display: none;">
                            <div class="snab-empty-icon">
                                <span class="dashicons dashicons-calendar-alt"></span>
                            </div>
                            <p class="snab-empty-message"></p>
                            <a href="<?php echo esc_url(home_url()); ?>" class="snab-book-new-btn">
                                <?php esc_html_e('Book an Appointment', 'sn-appointment-booking'); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div class="snab-portal-pagination" style="display: none;">
                        <button type="button" class="snab-page-btn snab-prev-page" disabled>
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                            <?php esc_html_e('Previous', 'sn-appointment-booking'); ?>
                        </button>
                        <span class="snab-page-info"></span>
                        <button type="button" class="snab-page-btn snab-next-page">
                            <?php esc_html_e('Next', 'sn-appointment-booking'); ?>
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>

                    <!-- Policy Notice -->
                    <div class="snab-portal-policies">
                        <div class="snab-policy-item">
                            <strong><?php esc_html_e('Cancellation Policy:', 'sn-appointment-booking'); ?></strong>
                            <?php echo esc_html($portal->get_cancellation_policy()); ?>
                        </div>
                        <div class="snab-policy-item">
                            <strong><?php esc_html_e('Reschedule Policy:', 'sn-appointment-booking'); ?></strong>
                            <?php echo esc_html($portal->get_reschedule_policy()); ?>
                        </div>
                    </div>
                </div>

                <!-- Cancel Modal -->
                <div class="snab-modal snab-cancel-modal" style="display: none;">
                    <div class="snab-modal-overlay"></div>
                    <div class="snab-modal-content">
                        <button type="button" class="snab-modal-close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                        <h3 class="snab-modal-title"><?php esc_html_e('Cancel Appointment', 'sn-appointment-booking'); ?></h3>
                        <div class="snab-cancel-details"></div>
                        <form class="snab-cancel-form">
                            <input type="hidden" name="appointment_id" value="">
                            <div class="snab-form-row">
                                <label for="<?php echo esc_attr($widget_id); ?>-cancel-reason">
                                    <?php esc_html_e('Reason for cancellation', 'sn-appointment-booking'); ?>
                                    <?php if (get_option('snab_require_cancel_reason', '1') === '1'): ?>
                                        <span class="required">*</span>
                                    <?php endif; ?>
                                </label>
                                <textarea id="<?php echo esc_attr($widget_id); ?>-cancel-reason"
                                          name="reason" rows="3"
                                          placeholder="<?php esc_attr_e('Please let us know why you are cancelling...', 'sn-appointment-booking'); ?>"
                                          <?php echo get_option('snab_require_cancel_reason', '1') === '1' ? 'required' : ''; ?>></textarea>
                            </div>
                            <div class="snab-modal-actions">
                                <button type="button" class="snab-btn-secondary snab-cancel-close">
                                    <?php esc_html_e('Keep Appointment', 'sn-appointment-booking'); ?>
                                </button>
                                <button type="submit" class="snab-btn-danger snab-confirm-cancel">
                                    <?php esc_html_e('Cancel Appointment', 'sn-appointment-booking'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Reschedule Modal -->
                <div class="snab-modal snab-reschedule-modal" style="display: none;">
                    <div class="snab-modal-overlay"></div>
                    <div class="snab-modal-content snab-modal-large">
                        <button type="button" class="snab-modal-close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                        <h3 class="snab-modal-title"><?php esc_html_e('Reschedule Appointment', 'sn-appointment-booking'); ?></h3>
                        <div class="snab-reschedule-current"></div>

                        <!-- Calendar for selecting new date -->
                        <div class="snab-reschedule-calendar">
                            <div class="snab-calendar-header">
                                <button type="button" class="snab-calendar-nav snab-prev-week" disabled>
                                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                                </button>
                                <span class="snab-calendar-title"></span>
                                <button type="button" class="snab-calendar-nav snab-next-week">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </button>
                            </div>
                            <div class="snab-calendar-grid">
                                <div class="snab-calendar-loading">
                                    <span class="spinner is-active"></span>
                                    <?php esc_html_e('Loading availability...', 'sn-appointment-booking'); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Time slots -->
                        <div class="snab-reschedule-times" style="display: none;">
                            <h4><?php esc_html_e('Select New Time', 'sn-appointment-booking'); ?></h4>
                            <div class="snab-time-slots"></div>
                        </div>

                        <form class="snab-reschedule-form" style="display: none;">
                            <input type="hidden" name="appointment_id" value="">
                            <input type="hidden" name="new_date" value="">
                            <input type="hidden" name="new_time" value="">
                            <div class="snab-reschedule-summary"></div>
                            <div class="snab-modal-actions">
                                <button type="button" class="snab-btn-secondary snab-reschedule-back">
                                    <?php esc_html_e('Choose Different Time', 'sn-appointment-booking'); ?>
                                </button>
                                <button type="submit" class="snab-btn-primary snab-confirm-reschedule">
                                    <?php esc_html_e('Confirm Reschedule', 'sn-appointment-booking'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Timezone Notice -->
            <div class="snab-timezone-notice">
                <?php echo esc_html(sprintf(
                    __('All times are shown in %s timezone.', 'sn-appointment-booking'),
                    wp_timezone_string()
                )); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue client portal assets.
     *
     * @since 1.5.0
     */
    private function enqueue_client_portal_assets() {
        // Dashicons for icons
        wp_enqueue_style('dashicons');

        // CSS Variables (theme customization)
        wp_enqueue_style(
            'snab-frontend-variables',
            SNAB_PLUGIN_URL . 'assets/css/frontend-variables.css',
            array('dashicons'),
            SNAB_VERSION
        );

        // Client portal CSS
        wp_enqueue_style(
            'snab-client-portal',
            SNAB_PLUGIN_URL . 'assets/css/client-portal.css',
            array('snab-frontend-variables'),
            SNAB_VERSION
        );

        // Add dynamic theme overrides from settings
        $this->add_theme_overrides();

        // Client portal JavaScript
        wp_enqueue_script(
            'snab-client-portal',
            SNAB_PLUGIN_URL . 'assets/js/client-portal.js',
            array('jquery'),
            SNAB_VERSION,
            true
        );

        // Localize script
        wp_localize_script('snab-client-portal', 'snabPortal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snab_client_portal_nonce'),
            'timezone' => wp_timezone_string(),
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'requireCancelReason' => get_option('snab_require_cancel_reason', '1') === '1',
            'i18n' => array(
                'loading' => __('Loading...', 'sn-appointment-booking'),
                'noUpcoming' => __('You have no upcoming appointments.', 'sn-appointment-booking'),
                'noPast' => __('You have no past appointments.', 'sn-appointment-booking'),
                'cancelSuccess' => __('Your appointment has been cancelled.', 'sn-appointment-booking'),
                'rescheduleSuccess' => __('Your appointment has been rescheduled.', 'sn-appointment-booking'),
                'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'sn-appointment-booking'),
                'reasonRequired' => __('Please provide a reason for cancellation.', 'sn-appointment-booking'),
                'selectDateTime' => __('Please select a new date and time.', 'sn-appointment-booking'),
                'noSlots' => __('No available times for this date.', 'sn-appointment-booking'),
                'cancel' => __('Cancel', 'sn-appointment-booking'),
                'reschedule' => __('Reschedule', 'sn-appointment-booking'),
                'view' => __('View Details', 'sn-appointment-booking'),
                'pageOf' => __('Page %1$d of %2$d', 'sn-appointment-booking'),
                'days' => array(
                    __('Sun', 'sn-appointment-booking'),
                    __('Mon', 'sn-appointment-booking'),
                    __('Tue', 'sn-appointment-booking'),
                    __('Wed', 'sn-appointment-booking'),
                    __('Thu', 'sn-appointment-booking'),
                    __('Fri', 'sn-appointment-booking'),
                    __('Sat', 'sn-appointment-booking'),
                ),
                'months' => array(
                    __('January', 'sn-appointment-booking'),
                    __('February', 'sn-appointment-booking'),
                    __('March', 'sn-appointment-booking'),
                    __('April', 'sn-appointment-booking'),
                    __('May', 'sn-appointment-booking'),
                    __('June', 'sn-appointment-booking'),
                    __('July', 'sn-appointment-booking'),
                    __('August', 'sn-appointment-booking'),
                    __('September', 'sn-appointment-booking'),
                    __('October', 'sn-appointment-booking'),
                    __('November', 'sn-appointment-booking'),
                    __('December', 'sn-appointment-booking'),
                ),
                'statuses' => array(
                    'pending' => __('Pending', 'sn-appointment-booking'),
                    'confirmed' => __('Confirmed', 'sn-appointment-booking'),
                    'cancelled' => __('Cancelled', 'sn-appointment-booking'),
                    'completed' => __('Completed', 'sn-appointment-booking'),
                    'no_show' => __('No Show', 'sn-appointment-booking'),
                ),
            ),
        ));
    }

    /**
     * Enqueue frontend assets.
     */
    private function enqueue_frontend_assets() {
        // Dashicons for icons
        wp_enqueue_style('dashicons');

        // CSS Variables (theme customization)
        wp_enqueue_style(
            'snab-frontend-variables',
            SNAB_PLUGIN_URL . 'assets/css/frontend-variables.css',
            array('dashicons'),
            SNAB_VERSION
        );

        // Frontend CSS
        wp_enqueue_style(
            'snab-frontend',
            SNAB_PLUGIN_URL . 'assets/css/frontend.css',
            array('snab-frontend-variables'),
            SNAB_VERSION
        );

        // Add dynamic theme overrides from settings
        $this->add_theme_overrides();

        // Frontend JavaScript
        wp_enqueue_script(
            'snab-booking-widget',
            SNAB_PLUGIN_URL . 'assets/js/booking-widget.js',
            array('jquery'),
            SNAB_VERSION,
            true
        );

        // Localize script
        wp_localize_script('snab-booking-widget', 'snabBooking', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snab_frontend_nonce'),
            'timezone' => wp_timezone_string(),
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'i18n' => array(
                'selectType' => __('Select Appointment Type', 'sn-appointment-booking'),
                'selectDate' => __('Select Date', 'sn-appointment-booking'),
                'selectTime' => __('Select Time', 'sn-appointment-booking'),
                'yourInfo' => __('Your Information', 'sn-appointment-booking'),
                'loading' => __('Loading...', 'sn-appointment-booking'),
                'noSlots' => __('No available times for this date.', 'sn-appointment-booking'),
                'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                'required' => __('This field is required.', 'sn-appointment-booking'),
                'invalidEmail' => __('Please enter a valid email address.', 'sn-appointment-booking'),
                'bookingFailed' => __('Booking failed. Please try again.', 'sn-appointment-booking'),
                'slotTaken' => __('Sorry, this time slot is no longer available. Please select another time.', 'sn-appointment-booking'),
                'minute' => __('minute', 'sn-appointment-booking'),
                'minutes' => __('minutes', 'sn-appointment-booking'),
                'days' => array(
                    __('Sun', 'sn-appointment-booking'),
                    __('Mon', 'sn-appointment-booking'),
                    __('Tue', 'sn-appointment-booking'),
                    __('Wed', 'sn-appointment-booking'),
                    __('Thu', 'sn-appointment-booking'),
                    __('Fri', 'sn-appointment-booking'),
                    __('Sat', 'sn-appointment-booking'),
                ),
                'months' => array(
                    __('January', 'sn-appointment-booking'),
                    __('February', 'sn-appointment-booking'),
                    __('March', 'sn-appointment-booking'),
                    __('April', 'sn-appointment-booking'),
                    __('May', 'sn-appointment-booking'),
                    __('June', 'sn-appointment-booking'),
                    __('July', 'sn-appointment-booking'),
                    __('August', 'sn-appointment-booking'),
                    __('September', 'sn-appointment-booking'),
                    __('October', 'sn-appointment-booking'),
                    __('November', 'sn-appointment-booking'),
                    __('December', 'sn-appointment-booking'),
                ),
            ),
        ));
    }

    /**
     * Load a preset by slug.
     *
     * @since 1.2.0
     * @param string $slug Preset slug.
     * @return object|null Preset object or null if not found.
     */
    private function load_preset($slug) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'snab_shortcode_presets';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        if (!$table_exists) {
            return null;
        }

        $preset = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE slug = %s AND is_active = 1",
            $slug
        ));

        return $preset;
    }

    /**
     * Merge shortcode attributes with preset settings.
     *
     * Shortcode attributes take precedence over preset values.
     *
     * @since 1.2.0
     * @param array       $atts   Shortcode attributes.
     * @param object|null $preset Preset object.
     * @return array Merged settings.
     */
    private function merge_settings_with_preset($atts, $preset) {
        $settings = array(
            'type' => '',
            'appointment_types' => '',
            'weeks' => '',
            'allowed_days' => '',
            'start_hour' => '',
            'end_hour' => '',
            'default_location' => '',
            'custom_title' => '',
            'css_class' => '',
        );

        // Load preset values first (if preset exists)
        if ($preset) {
            $settings['appointment_types'] = $preset->appointment_types ?? '';
            $settings['weeks'] = $preset->weeks_to_show ?? '';
            $settings['allowed_days'] = $preset->allowed_days ?? '';
            $settings['start_hour'] = $preset->start_hour ?? '';
            $settings['end_hour'] = $preset->end_hour ?? '';
            $settings['default_location'] = $preset->default_location ?? '';
            $settings['custom_title'] = $preset->custom_title ?? '';
            $settings['css_class'] = $preset->css_class ?? '';
        }

        // Override with shortcode attributes if provided
        if (!empty($atts['type'])) {
            $settings['type'] = $atts['type'];
        }
        if (!empty($atts['types'])) {
            $settings['appointment_types'] = $atts['types'];
        }
        if (!empty($atts['weeks'])) {
            $settings['weeks'] = $atts['weeks'];
        }
        if (!empty($atts['days'])) {
            $settings['allowed_days'] = $atts['days'];
        }
        if ($atts['start_hour'] !== '') {
            $settings['start_hour'] = $atts['start_hour'];
        }
        if ($atts['end_hour'] !== '') {
            $settings['end_hour'] = $atts['end_hour'];
        }
        if (!empty($atts['location'])) {
            $settings['default_location'] = $atts['location'];
        }
        if (!empty($atts['title'])) {
            $settings['custom_title'] = $atts['title'];
        }
        if (!empty($atts['class'])) {
            $settings['css_class'] = $atts['class'];
        }

        return $settings;
    }

    /**
     * Parse allowed days string to array of day numbers.
     *
     * Accepts both day names (mon, tue, etc.) and day numbers (0-6).
     *
     * @since 1.2.0
     * @param string $days_string Comma-separated day values.
     * @return array Array of day numbers (0=Sun, 6=Sat).
     */
    private function parse_allowed_days($days_string) {
        if (empty($days_string)) {
            return array();
        }

        $day_map = array(
            'sun' => 0, 'sunday' => 0,
            'mon' => 1, 'monday' => 1,
            'tue' => 2, 'tuesday' => 2,
            'wed' => 3, 'wednesday' => 3,
            'thu' => 4, 'thursday' => 4,
            'fri' => 5, 'friday' => 5,
            'sat' => 6, 'saturday' => 6,
        );

        $parts = array_map('trim', explode(',', strtolower($days_string)));
        $days = array();

        foreach ($parts as $part) {
            if (isset($day_map[$part])) {
                $days[] = $day_map[$part];
            } elseif (is_numeric($part) && $part >= 0 && $part <= 6) {
                $days[] = (int) $part;
            }
        }

        return array_unique($days);
    }

    /**
     * Add dynamic theme CSS overrides from admin settings.
     *
     * @since 1.3.0
     */
    private function add_theme_overrides() {
        $appearance = get_option('snab_appearance_settings', array());

        if (empty($appearance)) {
            return;
        }

        $css_vars = array();

        // Primary color
        if (!empty($appearance['primary_color'])) {
            $primary = sanitize_hex_color($appearance['primary_color']);
            $css_vars[] = "--snab-primary-color: {$primary}";
            $css_vars[] = "--snab-primary-hover: " . $this->adjust_brightness($primary, -15);
            $css_vars[] = "--snab-primary-light: " . $this->hex_to_rgba($primary, 0.1);
        }

        // Accent color
        if (!empty($appearance['accent_color'])) {
            $accent = sanitize_hex_color($appearance['accent_color']);
            $css_vars[] = "--snab-accent-color: {$accent}";
            $css_vars[] = "--snab-accent-hover: " . $this->adjust_brightness($accent, -15);
            $css_vars[] = "--snab-accent-light: " . $this->hex_to_rgba($accent, 0.1);
        }

        // Text color
        if (!empty($appearance['text_color'])) {
            $text = sanitize_hex_color($appearance['text_color']);
            $css_vars[] = "--snab-text-color: {$text}";
        }

        // Background color
        if (!empty($appearance['bg_color'])) {
            $bg = sanitize_hex_color($appearance['bg_color']);
            $css_vars[] = "--snab-bg-color: {$bg}";
        }

        // Border radius
        if (!empty($appearance['border_radius'])) {
            $radius = absint($appearance['border_radius']);
            $css_vars[] = "--snab-radius-sm: " . max(2, $radius - 2) . "px";
            $css_vars[] = "--snab-radius-md: {$radius}px";
            $css_vars[] = "--snab-radius-lg: " . ($radius + 2) . "px";
            $css_vars[] = "--snab-radius-xl: " . ($radius + 4) . "px";
        }

        // Font family
        if (!empty($appearance['font_family']) && $appearance['font_family'] !== 'inherit') {
            $font = esc_attr($appearance['font_family']);
            $css_vars[] = "--snab-font-family: {$font}";
        }

        if (empty($css_vars)) {
            return;
        }

        $inline_css = ".snab-booking-widget {\n    " . implode(";\n    ", $css_vars) . ";\n}";

        wp_add_inline_style('snab-frontend', $inline_css);
    }

    /**
     * Adjust brightness of a hex color.
     *
     * @since 1.3.0
     * @param string $hex    Hex color.
     * @param int    $amount Amount to adjust (-100 to 100).
     * @return string Adjusted hex color.
     */
    private function adjust_brightness($hex, $amount) {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $amount));
        $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $amount));
        $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $amount));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Convert hex color to rgba.
     *
     * @since 1.3.0
     * @param string $hex   Hex color.
     * @param float  $alpha Alpha value (0-1).
     * @return string RGBA color string.
     */
    private function hex_to_rgba($hex, $alpha = 1) {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }

    /**
     * Get active staff members with their linked services.
     *
     * @since 1.6.0
     * @return array Array of staff objects with service_ids.
     */
    private function get_active_staff() {
        global $wpdb;

        $staff_table = $wpdb->prefix . 'snab_staff';
        $services_table = $wpdb->prefix . 'snab_staff_services';

        $staff = $wpdb->get_results("
            SELECT s.*, GROUP_CONCAT(ss.appointment_type_id) as service_ids
            FROM {$staff_table} s
            LEFT JOIN {$services_table} ss ON s.id = ss.staff_id AND ss.is_active = 1
            WHERE s.is_active = 1
            GROUP BY s.id
            ORDER BY s.is_primary DESC, s.name ASC
        ");

        // Parse service IDs into arrays
        foreach ($staff as &$member) {
            if (!empty($member->service_ids)) {
                $member->service_ids = array_map('intval', explode(',', $member->service_ids));
            } else {
                $member->service_ids = array();
            }
        }

        return $staff;
    }

    /**
     * Get initials from a name.
     *
     * @since 1.6.0
     * @param string $name Full name.
     * @return string Initials (max 2 characters).
     */
    private function get_initials($name) {
        $words = explode(' ', trim($name));
        $initials = '';

        if (count($words) >= 2) {
            $initials = mb_substr($words[0], 0, 1) . mb_substr(end($words), 0, 1);
        } elseif (count($words) === 1) {
            $initials = mb_substr($words[0], 0, 2);
        }

        return mb_strtoupper($initials);
    }
}
