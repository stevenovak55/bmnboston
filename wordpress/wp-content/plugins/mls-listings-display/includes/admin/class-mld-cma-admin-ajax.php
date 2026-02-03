<?php
/**
 * MLD CMA Admin AJAX Handlers
 *
 * Handles AJAX requests from the CMA admin page
 *
 * @package MLS_Listings_Display
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Admin_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_mld_get_market_adjustments', array($this, 'get_market_adjustments'));
        add_action('wp_ajax_mld_test_cma_email', array($this, 'test_cma_email'));
        add_action('wp_ajax_mld_save_adjustment_overrides', array($this, 'save_adjustment_overrides'));
        add_action('wp_ajax_mld_reset_adjustment_overrides', array($this, 'reset_adjustment_overrides'));
    }

    /**
     * Get market adjustments via AJAX
     */
    public function get_market_adjustments() {
        // Verify nonce
        check_ajax_referer('mld_cma_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Get parameters
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $property_type = sanitize_text_field($_POST['property_type'] ?? 'all');
        $months = absint($_POST['months'] ?? 12);

        if (empty($city)) {
            wp_send_json_error('City is required');
            return;
        }

        try {
            // Get calculator instance
            if (!class_exists('MLD_Market_Data_Calculator')) {
                require_once plugin_dir_path(dirname(__FILE__)) . 'class-mld-market-data-calculator.php';
            }

            $calculator = new MLD_Market_Data_Calculator();

            // Get adjustments
            $adjustments = $calculator->get_all_adjustments($city, $state, $property_type, $months);

            // Return success
            wp_send_json_success(array(
                'adjustments' => $adjustments,
                'params' => array(
                    'city' => $city,
                    'state' => $state,
                    'property_type' => $property_type,
                    'months' => $months
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Test CMA email via AJAX
     */
    public function test_cma_email() {
        // Verify nonce - use wp_verify_nonce instead of check_ajax_referer
        // check_ajax_referer also checks HTTP Referer which fails with tracking prevention
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'mld_cma_nonce')) {
            wp_send_json_error('Nonce verification failed');
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Get parameters
        $email = sanitize_email($_POST['email'] ?? '');
        $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');

        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Valid email address is required');
            return;
        }

        if (empty($listing_id)) {
            wp_send_json_error('Listing ID is required');
            return;
        }

        try {
            // Get listing data
            global $wpdb;
            $listing = $wpdb->get_row($wpdb->prepare("
                SELECT l.*, loc.*, ld.*
                FROM {$wpdb->prefix}bme_listings l
                LEFT JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
                LEFT JOIN {$wpdb->prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
                WHERE l.listing_id = %s
                LIMIT 1
            ", $listing_id));

            if (!$listing) {
                wp_send_json_error('Listing not found');
                return;
            }

            // Build property address
            $address = trim(
                ($listing->street_number ?? '') . ' ' .
                ($listing->street_name ?? '') .
                ($listing->street_dir_suffix ? ' ' . $listing->street_dir_suffix : '') . ', ' .
                ($listing->city ?? '') . ', ' .
                ($listing->state_or_province ?? '')
            );

            // Get market adjustments
            if (!class_exists('MLD_Market_Data_Calculator')) {
                require_once plugin_dir_path(dirname(__FILE__)) . 'class-mld-market-data-calculator.php';
            }

            $calculator = new MLD_Market_Data_Calculator();
            $adjustments = $calculator->get_all_adjustments(
                $listing->city ?? '',
                $listing->state_or_province ?? '',
                'all',
                12
            );

            // Build email content
            $subject = 'Test CMA Report - ' . $address;

            $message = '<html><body style="font-family: Arial, sans-serif; padding: 20px;">';
            $message .= '<h1 style="color: #2271b1;">Comparative Market Analysis</h1>';
            $message .= '<h2>Subject Property</h2>';
            $message .= '<p><strong>Address:</strong> ' . esc_html($address) . '</p>';
            $message .= '<p><strong>List Price:</strong> $' . number_format($listing->list_price ?? 0) . '</p>';
            $message .= '<p><strong>Bedrooms:</strong> ' . ($listing->bedrooms_total ?? 'N/A') . '</p>';
            $message .= '<p><strong>Bathrooms:</strong> ' . ($listing->bathrooms_total ?? 'N/A') . '</p>';
            $message .= '<p><strong>Square Feet:</strong> ' . number_format($listing->building_area_total ?? 0) . '</p>';

            $message .= '<h2>Market Adjustments</h2>';
            $message .= '<table style="border-collapse: collapse; width: 100%;">';
            $message .= '<tr style="background: #f0f0f0;"><th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Feature</th><th style="padding: 10px; text-align: right; border: 1px solid #ddd;">Value</th></tr>';

            foreach ($adjustments as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                $display_value = is_numeric($value) ? '$' . number_format($value, 2) : $value;
                $message .= '<tr>';
                $message .= '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($label) . '</td>';
                $message .= '<td style="padding: 10px; text-align: right; border: 1px solid #ddd;">' . esc_html($display_value) . '</td>';
                $message .= '</tr>';
            }

            $message .= '</table>';
            $message .= '<p style="margin-top: 20px; color: #666; font-size: 12px;">This is a test email generated by the MLS Listings Display CMA system.</p>';
            $message .= '</body></html>';

            // Send email
            $headers = array('Content-Type: text/html; charset=UTF-8');

            // Capture PHPMailer errors
            $mail_error = '';
            add_action('wp_mail_failed', function($wp_error) use (&$mail_error) {
                $mail_error = $wp_error->get_error_message();
            });

            $sent = wp_mail($email, $subject, $message, $headers);

            if ($sent) {
                // Build success message - only show MailHog link in development
                $success_msg = 'Email sent successfully to ' . $email . '.';
                $hostname = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $is_dev = (strpos($hostname, 'localhost') !== false || strpos($hostname, '.local') !== false)
                          && file_exists('/.dockerenv');
                if ($is_dev) {
                    $success_msg .= ' Check MailHog at http://localhost:8027';
                }
                wp_send_json_success(array('message' => $success_msg));
            } else {
                $error_msg = $mail_error ? $mail_error : 'Failed to send email. Check WordPress email configuration.';
                wp_send_json_error($error_msg);
            }

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Save adjustment overrides
     */
    public function save_adjustment_overrides() {
        // Verify nonce
        check_ajax_referer('mld_cma_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        try {
            // Define allowed override fields
            $override_fields = array(
                'price_per_sqft' => 'float',
                'garage_first' => 'int',
                'garage_additional' => 'int',
                'pool' => 'int',
                'bedroom' => 'int',
                'bathroom' => 'int',
                'waterfront' => 'int',
                'year_built_rate' => 'int',
                'location_rate' => 'int',
                'road_type_discount' => 'int'
            );

            $saved_count = 0;

            foreach ($override_fields as $field => $type) {
                $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';

                // If empty, delete the option (use auto-calculated)
                if ($value === '' || $value === null) {
                    if ($field === 'road_type_discount') {
                        // Keep default for road_type_discount
                        update_option('mld_cma_road_type_discount', '25');
                    } else {
                        delete_option('mld_cma_override_' . $field);
                    }
                    continue;
                }

                // Sanitize based on type
                if ($type === 'float') {
                    $value = floatval($value);
                } else {
                    $value = intval($value);
                }

                // Save the override
                if ($field === 'road_type_discount') {
                    update_option('mld_cma_road_type_discount', $value);
                } else {
                    update_option('mld_cma_override_' . $field, $value);
                }

                $saved_count++;
            }

            wp_send_json_success(array(
                'message' => 'Adjustment overrides saved successfully!',
                'saved_count' => $saved_count
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Reset adjustment overrides to auto-calculated values
     */
    public function reset_adjustment_overrides() {
        // Verify nonce
        check_ajax_referer('mld_cma_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        try {
            // Delete all override options
            $override_fields = array(
                'price_per_sqft',
                'garage_first',
                'garage_additional',
                'pool',
                'bedroom',
                'bathroom',
                'waterfront',
                'year_built_rate',
                'location_rate'
            );

            foreach ($override_fields as $field) {
                delete_option('mld_cma_override_' . $field);
            }

            // Reset road type discount to default
            update_option('mld_cma_road_type_discount', '25');

            wp_send_json_success(array(
                'message' => 'All overrides reset to auto-calculated values!'
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}

// Initialize
new MLD_CMA_Admin_Ajax();
