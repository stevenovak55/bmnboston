<?php
/**
 * Admin Appearance Settings Class
 *
 * Handles the appearance/styling settings for the booking widget.
 *
 * @package SN_Appointment_Booking
 * @since 1.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Appearance class.
 *
 * @since 1.3.0
 */
class SNAB_Admin_Appearance {

    /**
     * Option name for appearance settings.
     */
    const OPTION_NAME = 'snab_appearance_settings';

    /**
     * Default appearance settings.
     *
     * @var array
     */
    private $defaults = array(
        'primary_color' => '#1e73be',
        'accent_color' => '#0891b2',
        'text_color' => '#222222',
        'bg_color' => '#ffffff',
        'border_radius' => '6',
        'font_family' => 'inherit',
    );

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_snab_save_appearance', array($this, 'ajax_save_appearance'));
        add_action('wp_ajax_snab_reset_appearance', array($this, 'ajax_reset_appearance'));
    }

    /**
     * Get current appearance settings.
     *
     * @return array Settings with defaults applied.
     */
    public function get_settings() {
        $settings = get_option(self::OPTION_NAME, array());
        return wp_parse_args($settings, $this->defaults);
    }

    /**
     * AJAX handler to save appearance settings.
     */
    public function ajax_save_appearance() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $settings = array(
            'primary_color' => isset($_POST['primary_color']) ? sanitize_hex_color($_POST['primary_color']) : $this->defaults['primary_color'],
            'accent_color' => isset($_POST['accent_color']) ? sanitize_hex_color($_POST['accent_color']) : $this->defaults['accent_color'],
            'text_color' => isset($_POST['text_color']) ? sanitize_hex_color($_POST['text_color']) : $this->defaults['text_color'],
            'bg_color' => isset($_POST['bg_color']) ? sanitize_hex_color($_POST['bg_color']) : $this->defaults['bg_color'],
            'border_radius' => isset($_POST['border_radius']) ? absint($_POST['border_radius']) : $this->defaults['border_radius'],
            'font_family' => isset($_POST['font_family']) ? sanitize_text_field($_POST['font_family']) : $this->defaults['font_family'],
        );

        update_option(self::OPTION_NAME, $settings);

        wp_send_json_success(array(
            'message' => __('Appearance settings saved successfully.', 'sn-appointment-booking'),
            'settings' => $settings,
        ));
    }

    /**
     * AJAX handler to reset appearance settings.
     */
    public function ajax_reset_appearance() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        delete_option(self::OPTION_NAME);

        wp_send_json_success(array(
            'message' => __('Appearance settings reset to defaults.', 'sn-appointment-booking'),
            'settings' => $this->defaults,
        ));
    }

    /**
     * Render the appearance settings tab content.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        // Enqueue frontend CSS for preview
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'snab-frontend-variables',
            SNAB_PLUGIN_URL . 'assets/css/frontend-variables.css',
            array('dashicons'),
            SNAB_VERSION
        );
        wp_enqueue_style(
            'snab-frontend',
            SNAB_PLUGIN_URL . 'assets/css/frontend.css',
            array('snab-frontend-variables'),
            SNAB_VERSION
        );

        $settings = $this->get_settings();

        // Font family options
        $font_options = array(
            'inherit' => __('Inherit from theme', 'sn-appointment-booking'),
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' => 'System Default',
            '"Inter", sans-serif' => 'Inter',
            '"Open Sans", sans-serif' => 'Open Sans',
            '"Roboto", sans-serif' => 'Roboto',
            '"Lato", sans-serif' => 'Lato',
            '"Poppins", sans-serif' => 'Poppins',
            '"Montserrat", sans-serif' => 'Montserrat',
            'Georgia, serif' => 'Georgia',
        );
        ?>
        <div class="snab-appearance-settings">
            <h2><?php esc_html_e('Widget Appearance', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Customize the look and feel of your booking widget to match your website design.', 'sn-appointment-booking'); ?>
            </p>

            <div class="snab-appearance-container">
                <!-- Settings Form -->
                <div class="snab-appearance-form">
                    <form id="snab-appearance-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="snab-primary-color"><?php esc_html_e('Primary Color', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="snab-primary-color" name="primary_color"
                                           value="<?php echo esc_attr($settings['primary_color']); ?>"
                                           class="snab-color-picker">
                                    <input type="text" id="snab-primary-color-text"
                                           value="<?php echo esc_attr($settings['primary_color']); ?>"
                                           class="snab-color-text" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                                    <p class="description"><?php esc_html_e('Used for headings, selected items, and highlights.', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="snab-accent-color"><?php esc_html_e('Accent Color', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="snab-accent-color" name="accent_color"
                                           value="<?php echo esc_attr($settings['accent_color']); ?>"
                                           class="snab-color-picker">
                                    <input type="text" id="snab-accent-color-text"
                                           value="<?php echo esc_attr($settings['accent_color']); ?>"
                                           class="snab-color-text" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                                    <p class="description"><?php esc_html_e('Used for buttons and call-to-action elements.', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="snab-text-color"><?php esc_html_e('Text Color', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="snab-text-color" name="text_color"
                                           value="<?php echo esc_attr($settings['text_color']); ?>"
                                           class="snab-color-picker">
                                    <input type="text" id="snab-text-color-text"
                                           value="<?php echo esc_attr($settings['text_color']); ?>"
                                           class="snab-color-text" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                                    <p class="description"><?php esc_html_e('Main text color throughout the widget.', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="snab-bg-color"><?php esc_html_e('Background Color', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="snab-bg-color" name="bg_color"
                                           value="<?php echo esc_attr($settings['bg_color']); ?>"
                                           class="snab-color-picker">
                                    <input type="text" id="snab-bg-color-text"
                                           value="<?php echo esc_attr($settings['bg_color']); ?>"
                                           class="snab-color-text" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                                    <p class="description"><?php esc_html_e('Widget background color.', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="snab-border-radius"><?php esc_html_e('Border Radius', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <input type="range" id="snab-border-radius" name="border_radius"
                                           value="<?php echo esc_attr($settings['border_radius']); ?>"
                                           min="0" max="16" step="1" class="snab-range-slider">
                                    <span id="snab-border-radius-value"><?php echo esc_html($settings['border_radius']); ?>px</span>
                                    <p class="description"><?php esc_html_e('Roundness of corners (0 = square, 16 = very rounded).', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="snab-font-family"><?php esc_html_e('Font Family', 'sn-appointment-booking'); ?></label>
                                </th>
                                <td>
                                    <select id="snab-font-family" name="font_family" class="regular-text">
                                        <?php foreach ($font_options as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['font_family'], $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e('Font for the booking widget. "Inherit" uses your theme\'s font.', 'sn-appointment-booking'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="snab-save-appearance">
                                <?php esc_html_e('Save Appearance', 'sn-appointment-booking'); ?>
                            </button>
                            <button type="button" class="button" id="snab-reset-appearance">
                                <?php esc_html_e('Reset to Defaults', 'sn-appointment-booking'); ?>
                            </button>
                            <span class="spinner"></span>
                        </p>
                    </form>
                </div>

                <!-- Live Preview -->
                <div class="snab-appearance-preview">
                    <h3><?php esc_html_e('Live Preview', 'sn-appointment-booking'); ?></h3>
                    <div class="snab-preview-container" id="snab-preview">
                        <div class="snab-booking-widget snab-preview-widget">
                            <div class="snab-step active">
                                <h3 class="snab-step-title">
                                    <?php esc_html_e('Select Appointment Type', 'sn-appointment-booking'); ?>
                                </h3>
                                <div class="snab-type-list">
                                    <button type="button" class="snab-type-option selected">
                                        <span class="snab-type-color" style="background: #4CAF50;"></span>
                                        <div class="snab-type-info">
                                            <span class="snab-type-name"><?php esc_html_e('Property Showing', 'sn-appointment-booking'); ?></span>
                                            <span class="snab-type-duration">30 <?php esc_html_e('minutes', 'sn-appointment-booking'); ?></span>
                                        </div>
                                    </button>
                                    <button type="button" class="snab-type-option">
                                        <span class="snab-type-color" style="background: #2196F3;"></span>
                                        <div class="snab-type-info">
                                            <span class="snab-type-name"><?php esc_html_e('Buyer Consultation', 'sn-appointment-booking'); ?></span>
                                            <span class="snab-type-duration">60 <?php esc_html_e('minutes', 'sn-appointment-booking'); ?></span>
                                        </div>
                                    </button>
                                </div>
                                <div class="snab-preview-time-slots" style="margin-top: 20px;">
                                    <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: var(--snab-text-color);"><?php esc_html_e('Available Times', 'sn-appointment-booking'); ?></h4>
                                    <div class="snab-slots-grid">
                                        <button type="button" class="snab-time-slot">9:00 AM</button>
                                        <button type="button" class="snab-time-slot selected">10:00 AM</button>
                                        <button type="button" class="snab-time-slot">11:00 AM</button>
                                    </div>
                                </div>
                                <div class="snab-preview-button" style="margin-top: 20px;">
                                    <button type="button" class="snab-submit-btn"><?php esc_html_e('Book Appointment', 'sn-appointment-booking'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .snab-appearance-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-top: 20px;
            }

            @media (max-width: 1200px) {
                .snab-appearance-container {
                    grid-template-columns: 1fr;
                }
            }

            .snab-appearance-form {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .snab-appearance-preview {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .snab-appearance-preview h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }

            .snab-preview-container {
                background: #f7f8f9;
                padding: 20px;
                border-radius: 8px;
                min-height: 300px;
            }

            .snab-color-picker {
                width: 50px;
                height: 38px;
                padding: 2px;
                border: 1px solid #ddd;
                border-radius: 4px;
                cursor: pointer;
                vertical-align: middle;
            }

            .snab-color-text {
                width: 80px;
                margin-left: 10px;
                vertical-align: middle;
                font-family: monospace;
            }

            .snab-range-slider {
                width: 200px;
                vertical-align: middle;
            }

            #snab-border-radius-value {
                display: inline-block;
                width: 50px;
                margin-left: 10px;
                font-family: monospace;
            }

            .snab-appearance-form .spinner {
                float: none;
                margin-left: 10px;
                visibility: hidden;
            }

            .snab-appearance-form.loading .spinner {
                visibility: visible;
            }

            /* Preview widget styling (inherits from frontend CSS) */
            .snab-preview-widget {
                max-width: 100% !important;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';

            // Sync color picker with text input
            $('.snab-color-picker').on('input change', function() {
                var id = $(this).attr('id');
                $('#' + id + '-text').val($(this).val());
                updatePreview();
            });

            $('.snab-color-text').on('input change', function() {
                var id = $(this).attr('id').replace('-text', '');
                var val = $(this).val();
                if (/^#[0-9A-Fa-f]{6}$/i.test(val)) {
                    $('#' + id).val(val);
                    updatePreview();
                }
            });

            // Border radius slider
            $('#snab-border-radius').on('input change', function() {
                $('#snab-border-radius-value').text($(this).val() + 'px');
                updatePreview();
            });

            // Font family change
            $('#snab-font-family').on('change', function() {
                updatePreview();
            });

            // Update preview
            function updatePreview() {
                var $preview = $('#snab-preview .snab-booking-widget');

                var primaryColor = $('#snab-primary-color').val();
                var accentColor = $('#snab-accent-color').val();
                var textColor = $('#snab-text-color').val();
                var bgColor = $('#snab-bg-color').val();
                var borderRadius = $('#snab-border-radius').val();
                var fontFamily = $('#snab-font-family').val();

                // Calculate derived colors
                var primaryLight = hexToRgba(primaryColor, 0.1);

                $preview.css({
                    '--snab-primary-color': primaryColor,
                    '--snab-primary-hover': adjustBrightness(primaryColor, -15),
                    '--snab-primary-light': primaryLight,
                    '--snab-accent-color': accentColor,
                    '--snab-accent-hover': adjustBrightness(accentColor, -15),
                    '--snab-text-color': textColor,
                    '--snab-bg-color': bgColor,
                    '--snab-radius-sm': Math.max(2, borderRadius - 2) + 'px',
                    '--snab-radius-md': borderRadius + 'px',
                    '--snab-radius-lg': (parseInt(borderRadius) + 2) + 'px',
                    '--snab-radius-xl': (parseInt(borderRadius) + 4) + 'px',
                    '--snab-font-family': fontFamily === 'inherit' ? 'inherit' : fontFamily
                });
            }

            function hexToRgba(hex, alpha) {
                hex = hex.replace('#', '');
                var r = parseInt(hex.substring(0, 2), 16);
                var g = parseInt(hex.substring(2, 4), 16);
                var b = parseInt(hex.substring(4, 6), 16);
                return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
            }

            function adjustBrightness(hex, amount) {
                hex = hex.replace('#', '');
                var r = Math.max(0, Math.min(255, parseInt(hex.substring(0, 2), 16) + amount));
                var g = Math.max(0, Math.min(255, parseInt(hex.substring(2, 4), 16) + amount));
                var b = Math.max(0, Math.min(255, parseInt(hex.substring(4, 6), 16) + amount));
                return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
            }

            // Initialize preview
            updatePreview();

            // Save appearance
            $('#snab-appearance-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $('#snab-save-appearance');

                $form.addClass('loading');
                $button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_appearance',
                        nonce: nonce,
                        primary_color: $('#snab-primary-color').val(),
                        accent_color: $('#snab-accent-color').val(),
                        text_color: $('#snab-text-color').val(),
                        bg_color: $('#snab-bg-color').val(),
                        border_radius: $('#snab-border-radius').val(),
                        font_family: $('#snab-font-family').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                    }
                });
            });

            // Reset to defaults
            $('#snab-reset-appearance').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to reset appearance settings to defaults?', 'sn-appointment-booking')); ?>')) {
                    return;
                }

                var $form = $('#snab-appearance-form');
                var $button = $(this);

                $form.addClass('loading');
                $button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_reset_appearance',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update form fields with defaults
                            var s = response.data.settings;
                            $('#snab-primary-color').val(s.primary_color);
                            $('#snab-primary-color-text').val(s.primary_color);
                            $('#snab-accent-color').val(s.accent_color);
                            $('#snab-accent-color-text').val(s.accent_color);
                            $('#snab-text-color').val(s.text_color);
                            $('#snab-text-color-text').val(s.text_color);
                            $('#snab-bg-color').val(s.bg_color);
                            $('#snab-bg-color-text').val(s.bg_color);
                            $('#snab-border-radius').val(s.border_radius);
                            $('#snab-border-radius-value').text(s.border_radius + 'px');
                            $('#snab-font-family').val(s.font_family);

                            updatePreview();
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
}
