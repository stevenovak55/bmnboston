<?php
/**
 * MLD CMA Email Delivery
 *
 * Handles email delivery of CMA reports
 * Supports PDF attachments and HTML email templates
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Email {

    /**
     * Send CMA report via email
     *
     * @param array $params Email parameters
     * @return array Result array with success status
     */
    public function send_report($params) {
        // Validate parameters
        $validation = $this->validate_params($params);
        if (!$validation['valid']) {
            return array(
                'success' => false,
                'message' => $validation['message']
            );
        }

        // Prepare email components
        $to = $params['recipient_email'];
        $subject = $params['subject'] ?? $this->get_default_subject($params);
        $message = $this->build_email_body($params);
        $headers = $this->build_headers($params);
        $attachments = $this->prepare_attachments($params);

        // Send email
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));

        $sent = wp_mail($to, $subject, $message, $headers, $attachments);

        remove_filter('wp_mail_content_type', array($this, 'set_html_content_type'));

        if ($sent) {
            // Log successful send
            $this->log_email_sent($params);

            return array(
                'success' => true,
                'message' => 'CMA report sent successfully to ' . $to
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to send email. Please check server email configuration.'
            );
        }
    }

    /**
     * Validate email parameters
     *
     * @param array $params Parameters to validate
     * @return array Validation result
     */
    private function validate_params($params) {
        // Required fields
        if (empty($params['recipient_email'])) {
            return array('valid' => false, 'message' => 'Recipient email is required');
        }

        if (!is_email($params['recipient_email'])) {
            return array('valid' => false, 'message' => 'Invalid recipient email address');
        }

        // Optional CC validation
        if (!empty($params['cc_email']) && !is_email($params['cc_email'])) {
            return array('valid' => false, 'message' => 'Invalid CC email address');
        }

        // Optional sender validation
        if (!empty($params['from_email']) && !is_email($params['from_email'])) {
            return array('valid' => false, 'message' => 'Invalid sender email address');
        }

        return array('valid' => true, 'message' => 'Valid');
    }

    /**
     * Get default email subject
     *
     * @param array $params Email parameters
     * @return string Default subject
     */
    private function get_default_subject($params) {
        $property_address = $params['property_address'] ?? 'Property';
        return 'Comparative Market Analysis - ' . $property_address;
    }

    /**
     * Build email body (HTML)
     *
     * @param array $params Email parameters
     * @return string HTML email body
     */
    private function build_email_body($params) {
        $template = $params['template'] ?? 'default';

        if ($template === 'custom' && !empty($params['custom_message'])) {
            return $this->build_custom_email($params);
        }

        return $this->build_default_email($params);
    }

    /**
     * Build default email template
     *
     * @param array $params Email parameters
     * @return string HTML email
     */
    private function build_default_email($params) {
        $property_address = $params['property_address'] ?? 'the property';
        $agent_name = $params['agent_name'] ?? 'Your Real Estate Professional';
        $agent_phone = $params['agent_phone'] ?? '';
        $agent_email = $params['agent_email'] ?? '';
        $brokerage = $params['brokerage_name'] ?? '';
        $recipient_name = $params['recipient_name'] ?? 'Valued Client';

        // Estimate values from CMA data
        $est_low = $params['estimated_value_low'] ?? 0;
        $est_high = $params['estimated_value_high'] ?? 0;
        $value_range = '';
        if ($est_low > 0 && $est_high > 0) {
            $value_range = '$' . number_format($est_low) . ' - $' . number_format($est_high);
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    line-height: 1.6;
                    color: #333333;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .email-container {
                    max-width: 600px;
                    margin: 20px auto;
                    background-color: #ffffff;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                }
                .email-header {
                    background: linear-gradient(135deg, #2c5aa0 0%, #1e4278 100%);
                    color: #ffffff;
                    padding: 30px 20px;
                    text-align: center;
                }
                .email-header h1 {
                    margin: 0;
                    font-size: 24px;
                    font-weight: 600;
                }
                .email-body {
                    padding: 30px 20px;
                }
                .email-body h2 {
                    color: #2c5aa0;
                    font-size: 20px;
                    margin-top: 0;
                }
                .property-info {
                    background-color: #f8f9fa;
                    border-left: 4px solid #2c5aa0;
                    padding: 15px;
                    margin: 20px 0;
                }
                .property-address {
                    font-size: 18px;
                    font-weight: 600;
                    color: #2c5aa0;
                    margin-bottom: 10px;
                }
                .value-estimate {
                    background-color: #28a745;
                    color: #ffffff;
                    padding: 20px;
                    border-radius: 6px;
                    text-align: center;
                    margin: 20px 0;
                }
                .value-estimate .label {
                    font-size: 14px;
                    opacity: 0.9;
                    margin-bottom: 5px;
                }
                .value-estimate .value {
                    font-size: 28px;
                    font-weight: bold;
                }
                .cta-button {
                    display: inline-block;
                    background-color: #2c5aa0;
                    color: #ffffff;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 4px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .agent-info {
                    background-color: #f8f9fa;
                    padding: 20px;
                    border-radius: 6px;
                    margin-top: 30px;
                }
                .agent-info h3 {
                    margin-top: 0;
                    color: #333333;
                    font-size: 16px;
                }
                .contact-item {
                    margin: 8px 0;
                    color: #555555;
                }
                .email-footer {
                    background-color: #f1f1f1;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #666666;
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1>Comparative Market Analysis</h1>
                </div>

                <div class="email-body">
                    <h2>Hello <?php echo esc_html($recipient_name); ?>,</h2>

                    <p>I'm pleased to provide you with a comprehensive Comparative Market Analysis (CMA) for:</p>

                    <div class="property-info">
                        <div class="property-address"><?php echo esc_html($property_address); ?></div>
                        <p style="margin: 5px 0; color: #666;">This detailed analysis includes comparable properties, market trends, and price forecasting.</p>
                    </div>

                    <?php if (!empty($value_range)): ?>
                    <div class="value-estimate">
                        <div class="label">Estimated Market Value Range</div>
                        <div class="value"><?php echo esc_html($value_range); ?></div>
                    </div>
                    <?php endif; ?>

                    <p>The attached CMA report includes:</p>
                    <ul>
                        <li><strong>Comparable Properties</strong> - Analysis of similar properties recently sold in the area</li>
                        <li><strong>Market Trends</strong> - Current market conditions and price appreciation data</li>
                        <li><strong>Price Forecast</strong> - Projected values for the next 3, 6, and 12 months</li>
                        <li><strong>Investment Analysis</strong> - Long-term appreciation projections and risk assessment</li>
                    </ul>

                    <p>Please review the attached PDF report for complete details and analysis.</p>

                    <p>I'm here to answer any questions you may have about this analysis or discuss your real estate goals. Please don't hesitate to reach out.</p>

                    <div class="agent-info">
                        <h3>Your Real Estate Professional</h3>
                        <div class="contact-item"><strong><?php echo esc_html($agent_name); ?></strong></div>
                        <?php if (!empty($brokerage)): ?>
                        <div class="contact-item"><?php echo esc_html($brokerage); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($agent_phone)): ?>
                        <div class="contact-item">📞 <?php echo esc_html($agent_phone); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($agent_email)): ?>
                        <div class="contact-item">✉️ <?php echo esc_html($agent_email); ?></div>
                        <?php endif; ?>
                    </div>

                    <p style="margin-top: 30px; font-size: 14px; color: #666;">
                        <strong>Note:</strong> This CMA is for informational purposes only and is not an appraisal. Actual market values may vary. Please consult with a licensed professional before making real estate decisions.
                    </p>
                </div>

<?php
                // Use unified footer with social links and App Store promotion
                if (class_exists('MLD_Email_Utilities')) {
                    echo MLD_Email_Utilities::get_unified_footer();
                } else {
                ?>
                <div class="email-footer">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html($brokerage ?: 'Real Estate Services'); ?>. All rights reserved.</p>
                    <p>This email and any attachments are confidential and intended solely for the addressee.</p>
                </div>
                <?php } ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Build custom email template
     *
     * @param array $params Email parameters
     * @return string HTML email
     */
    private function build_custom_email($params) {
        $custom_message = $params['custom_message'];
        $agent_name = $params['agent_name'] ?? 'Your Real Estate Professional';
        $agent_signature = $params['agent_signature'] ?? '';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    line-height: 1.6;
                    color: #333333;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .email-container {
                    max-width: 600px;
                    margin: 20px auto;
                    background-color: #ffffff;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                }
                .custom-message {
                    margin: 20px 0;
                    white-space: pre-wrap;
                }
                .signature {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #e0e0e0;
                }
                .email-footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #e0e0e0;
                    text-align: center;
                    font-size: 12px;
                    color: #666666;
                }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="custom-message">
                    <?php echo nl2br(esc_html($custom_message)); ?>
                </div>

                <div class="signature">
                    <?php if (!empty($agent_signature)): ?>
                        <?php echo wpautop($agent_signature); ?>
                    <?php else: ?>
                        <p>Best regards,<br><?php echo esc_html($agent_name); ?></p>
                    <?php endif; ?>
                </div>

<?php
                // Use unified footer with social links and App Store promotion
                if (class_exists('MLD_Email_Utilities')) {
                    echo MLD_Email_Utilities::get_unified_footer();
                } else {
                ?>
                <div class="email-footer">
                    <p>&copy; <?php echo date('Y'); ?>. All rights reserved.</p>
                </div>
                <?php } ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Build email headers
     *
     * Uses dynamic from address based on recipient's assigned agent (v6.63.0)
     *
     * @param array $params Email parameters
     * @return array Email headers
     */
    private function build_headers($params) {
        $headers = array();

        // From header - use explicit params if provided, otherwise use dynamic from (v6.63.0)
        if (!empty($params['from_email']) && !empty($params['from_name'])) {
            $headers[] = 'From: ' . $params['from_name'] . ' <' . $params['from_email'] . '>';
        } elseif (!empty($params['from_email'])) {
            $headers[] = 'From: ' . $params['from_email'];
        } else {
            // Use dynamic from based on recipient (v6.63.0)
            $recipient_user_id = null;
            if (!empty($params['recipient_email'])) {
                if (class_exists('MLD_Email_Utilities')) {
                    $recipient_user_id = MLD_Email_Utilities::get_user_id_from_email($params['recipient_email']);
                }
            }

            if (class_exists('MLD_Email_Utilities')) {
                $headers[] = 'From: ' . MLD_Email_Utilities::get_from_header($recipient_user_id);
            } else {
                $headers[] = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
            }
        }

        // Reply-To header
        if (!empty($params['reply_to_email'])) {
            $headers[] = 'Reply-To: ' . $params['reply_to_email'];
        } elseif (!empty($params['agent_email'])) {
            $headers[] = 'Reply-To: ' . $params['agent_email'];
        }

        // CC header
        if (!empty($params['cc_email'])) {
            $headers[] = 'Cc: ' . $params['cc_email'];
        }

        return $headers;
    }

    /**
     * Prepare attachments
     *
     * @param array $params Email parameters
     * @return array Attachment file paths
     */
    private function prepare_attachments($params) {
        $attachments = array();

        // PDF report attachment
        if (!empty($params['pdf_path']) && file_exists($params['pdf_path'])) {
            $attachments[] = $params['pdf_path'];
        }

        // Additional attachments
        if (!empty($params['additional_attachments']) && is_array($params['additional_attachments'])) {
            foreach ($params['additional_attachments'] as $file_path) {
                if (file_exists($file_path)) {
                    $attachments[] = $file_path;
                }
            }
        }

        return $attachments;
    }

    /**
     * Set HTML content type for wp_mail
     *
     * @return string Content type
     */
    public function set_html_content_type() {
        return 'text/html';
    }

    /**
     * Log email sent
     *
     * @param array $params Email parameters
     */
    private function log_email_sent($params) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_cma_emails';

        // Check if table exists (will be created in update hook)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if ($table_exists) {
            $wpdb->insert(
                $table_name,
                array(
                    'recipient_email' => $params['recipient_email'],
                    'property_address' => $params['property_address'] ?? '',
                    'agent_name' => $params['agent_name'] ?? '',
                    'sent_at' => current_time('mysql'),
                    'pdf_attached' => !empty($params['pdf_path']) ? 1 : 0
                ),
                array('%s', '%s', '%s', '%s', '%d')
            );
        }
    }

    /**
     * Send test email
     *
     * @param string $to Recipient email
     * @param array $options Test email options
     * @return array Result
     */
    public function send_test_email($to, $options = array()) {
        $params = array_merge(array(
            'recipient_email' => $to,
            'recipient_name' => 'Test Recipient',
            'subject' => 'Test Email - CMA Report System',
            'property_address' => '123 Example Street, Test City, ST 12345',
            'agent_name' => 'Test Agent',
            'agent_email' => 'agent@example.com',
            'agent_phone' => '(555) 123-4567',
            'brokerage_name' => 'Test Realty',
            'estimated_value_low' => 500000,
            'estimated_value_high' => 550000,
        ), $options);

        return $this->send_report($params);
    }

    /**
     * Get email sending statistics
     *
     * @param array $filters Optional filters
     * @return array Statistics
     */
    public function get_statistics($filters = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_cma_emails';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            return array(
                'total_sent' => 0,
                'sent_today' => 0,
                'sent_this_week' => 0,
                'sent_this_month' => 0
            );
        }

        $total_sent = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $sent_today = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE DATE(sent_at) = CURDATE()");
        $sent_this_week = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE YEARWEEK(sent_at) = YEARWEEK(NOW())");
        $sent_this_month = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE YEAR(sent_at) = YEAR(NOW()) AND MONTH(sent_at) = MONTH(NOW())");

        return array(
            'total_sent' => intval($total_sent),
            'sent_today' => intval($sent_today),
            'sent_this_week' => intval($sent_this_week),
            'sent_this_month' => intval($sent_this_month)
        );
    }
}
