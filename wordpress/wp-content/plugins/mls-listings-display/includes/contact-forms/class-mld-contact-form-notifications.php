<?php
/**
 * Contact Form Notifications
 *
 * Handles email notifications for form submissions.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Notifications
 *
 * Sends email notifications for contact form submissions.
 */
class MLD_Contact_Form_Notifications {

    /**
     * Form data
     *
     * @var object
     */
    private $form;

    /**
     * Submission data
     *
     * @var array
     */
    private $data;

    /**
     * Notification settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     *
     * @param object $form Form object
     * @param array  $data Submission data
     */
    public function __construct($form, array $data) {
        $this->form = $form;
        $this->data = $data;
        $this->settings = isset($form->notification_settings) ?
            (is_array($form->notification_settings) ? $form->notification_settings : json_decode($form->notification_settings, true)) :
            [];
    }

    /**
     * Send all notifications
     *
     * @return array Results of notification attempts
     */
    public function send_all_notifications() {
        $results = [
            'admin' => false,
            'user' => false,
            'additional' => false,
        ];

        // Admin notification
        if ($this->is_admin_notification_enabled()) {
            $results['admin'] = $this->send_admin_notification();
        }

        // User confirmation
        if ($this->is_user_confirmation_enabled()) {
            $results['user'] = $this->send_user_confirmation();
        }

        // Additional recipients
        if ($this->has_additional_recipients()) {
            $results['additional'] = $this->send_additional_recipients();
        }

        return $results;
    }

    /**
     * Check if admin notification is enabled
     *
     * @return bool
     */
    private function is_admin_notification_enabled() {
        return !isset($this->settings['admin_email_enabled']) || $this->settings['admin_email_enabled'];
    }

    /**
     * Check if user confirmation is enabled
     *
     * @return bool
     */
    private function is_user_confirmation_enabled() {
        return !empty($this->settings['user_confirmation_enabled']) && $this->get_user_email();
    }

    /**
     * Check if there are additional recipients
     *
     * @return bool
     */
    private function has_additional_recipients() {
        return !empty($this->settings['additional_recipients']);
    }

    /**
     * Send admin notification
     *
     * @return bool
     */
    public function send_admin_notification() {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        // Subject
        $subject = !empty($this->settings['admin_email_subject'])
            ? $this->replace_placeholders($this->settings['admin_email_subject'])
            : sprintf(__('New Contact Form Submission: %s', 'mls-listings-display'), $this->form->form_name);

        // Build email content
        $content = $this->build_admin_email_content();

        // Send email
        $headers = $this->get_email_headers();

        // Add reply-to if we have user email
        $user_email = $this->get_user_email();
        if ($user_email) {
            $user_name = $this->get_user_name();
            $headers[] = 'Reply-To: ' . ($user_name ? "$user_name <$user_email>" : $user_email);
        }

        return wp_mail($admin_email, $subject, $content, $headers);
    }

    /**
     * Send user confirmation email
     *
     * @return bool
     */
    public function send_user_confirmation() {
        $user_email = $this->get_user_email();
        if (!$user_email) {
            return false;
        }

        // Subject
        $subject = !empty($this->settings['user_confirmation_subject'])
            ? $this->replace_placeholders($this->settings['user_confirmation_subject'])
            : sprintf(__('Thank you for contacting %s', 'mls-listings-display'), get_bloginfo('name'));

        // Message
        $message = !empty($this->settings['user_confirmation_message'])
            ? $this->replace_placeholders($this->settings['user_confirmation_message'])
            : $this->get_default_user_confirmation_message();

        // Build HTML email
        $content = $this->build_html_email($message, true);

        // Look up user ID for dynamic from address
        $recipient_user_id = null;
        if (class_exists('MLD_Email_Utilities')) {
            $recipient_user_id = MLD_Email_Utilities::get_user_id_from_email($user_email);
        }

        // Headers with dynamic from address
        $headers = $this->get_email_headers($recipient_user_id);

        return wp_mail($user_email, $subject, $content, $headers);
    }

    /**
     * Send to additional recipients
     *
     * @return bool
     */
    public function send_additional_recipients() {
        $recipients = $this->settings['additional_recipients'];

        // Parse recipients (comma-separated)
        $emails = array_map('trim', explode(',', $recipients));
        $emails = array_filter($emails, function($email) {
            return is_email($email);
        });

        if (empty($emails)) {
            return false;
        }

        $site_name = get_bloginfo('name');

        // Subject (same as admin)
        $subject = !empty($this->settings['admin_email_subject'])
            ? $this->replace_placeholders($this->settings['admin_email_subject'])
            : sprintf(__('New Contact Form Submission: %s', 'mls-listings-display'), $this->form->form_name);

        // Content (same as admin)
        $content = $this->build_admin_email_content();

        // Headers
        $headers = $this->get_email_headers();

        // Send to each recipient
        $success = true;
        foreach ($emails as $email) {
            if (!wp_mail($email, $subject, $content, $headers)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Build admin email content
     *
     * @return string HTML content
     */
    private function build_admin_email_content() {
        $fields = isset($this->form->fields['fields']) ? $this->form->fields['fields'] : [];
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        // Build submission details table
        $table_rows = '';
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $field_label = $field['label'];
            $value = isset($this->data[$field_id]) ? $this->data[$field_id] : '';

            // Format array values (checkboxes)
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            // Escape and format
            $value = esc_html($value);
            if (empty($value)) {
                $value = '<em style="color: #9ca3af;">' . __('Not provided', 'mls-listings-display') . '</em>';
            }

            $table_rows .= sprintf(
                '<tr>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151; width: 30%%; vertical-align: top;">%s</td>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; color: #1f2937;">%s</td>
                </tr>',
                esc_html($field_label),
                nl2br($value)
            );
        }

        // Submission meta
        $submission_time = wp_date(get_option('date_format') . ' ' . get_option('time_format'));
        $page_url = isset($this->data['_page_url']) ? $this->data['_page_url'] : '';

        $meta_info = sprintf(
            '<p style="margin: 0 0 8px; color: #6b7280; font-size: 13px;"><strong>%s:</strong> %s</p>',
            __('Submitted', 'mls-listings-display'),
            $submission_time
        );

        if ($page_url) {
            $meta_info .= sprintf(
                '<p style="margin: 0; color: #6b7280; font-size: 13px;"><strong>%s:</strong> <a href="%s" style="color: #0891B2;">%s</a></p>',
                __('Page', 'mls-listings-display'),
                esc_url($page_url),
                esc_html($page_url)
            );
        }

        $content = sprintf('
            <div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
                <div style="background: #0891B2; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                    <h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 600;">%s</h1>
                    <p style="margin: 8px 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">%s</p>
                </div>
                <div style="background: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
                    <table style="width: 100%%; border-collapse: collapse; margin-bottom: 24px;">
                        %s
                    </table>
                    <div style="padding-top: 16px; border-top: 1px solid #e5e7eb;">
                        %s
                    </div>
                </div>
                <div style="background: #f9fafb; padding: 16px 32px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; text-align: center;">
                    <p style="margin: 0; color: #9ca3af; font-size: 12px;">
                        %s &copy; %s <a href="%s" style="color: #0891B2; text-decoration: none;">%s</a>
                    </p>
                </div>
            </div>
        ',
            __('New Contact Form Submission', 'mls-listings-display'),
            sprintf(__('From form: %s', 'mls-listings-display'), esc_html($this->form->form_name)),
            $table_rows,
            $meta_info,
            __('Sent from', 'mls-listings-display'),
            wp_date('Y'),
            esc_url($site_url),
            esc_html($site_name)
        );

        return $content;
    }

    /**
     * Build HTML email wrapper
     *
     * @param string $message Message content
     * @param bool   $is_user_email Whether this is for user
     * @return string HTML content
     */
    private function build_html_email($message, $is_user_email = false) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        // Process message (convert line breaks, allow basic HTML)
        $message = wp_kses_post($message);
        $message = nl2br($message);

        // Get unified footer
        $footer_html = '';
        if (class_exists('MLD_Email_Utilities')) {
            $footer_html = MLD_Email_Utilities::get_unified_footer([
                'context' => 'general',
                'show_social' => true,
                'show_app_download' => true,
                'compact' => true,
            ]);
        }

        return sprintf('
            <div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
                <div style="background: #0891B2; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                    <h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 600;">%s</h1>
                </div>
                <div style="background: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
                    <div style="color: #1f2937; font-size: 15px; line-height: 1.6;">
                        %s
                    </div>
                </div>
                <div style="background: #f9fafb; padding: 24px 32px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">
                    %s
                </div>
            </div>
        ',
            esc_html($site_name),
            $message,
            $footer_html
        );
    }

    /**
     * Get default user confirmation message
     *
     * @return string
     */
    private function get_default_user_confirmation_message() {
        $user_name = $this->get_user_name();
        $greeting = $user_name ? sprintf(__('Hi %s,', 'mls-listings-display'), $user_name) : __('Hi,', 'mls-listings-display');

        return $greeting . "\n\n" .
            __('Thank you for contacting us. We have received your message and will get back to you as soon as possible.', 'mls-listings-display') . "\n\n" .
            __('Best regards,', 'mls-listings-display') . "\n" .
            get_bloginfo('name');
    }

    /**
     * Replace placeholders in text
     *
     * @param string $text Text with placeholders
     * @return string Processed text
     */
    private function replace_placeholders($text) {
        $replacements = [
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
            '{form_name}' => $this->form->form_name,
            '{submission_date}' => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
        ];

        // Add field placeholders
        $fields = isset($this->form->fields['fields']) ? $this->form->fields['fields'] : [];
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $value = isset($this->data[$field_id]) ? $this->data[$field_id] : '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $replacements['{field_' . $field_id . '}'] = $value;

            // Also add by field type for common fields
            if ($field['type'] === 'text' && stripos($field['label'], 'name') !== false) {
                if (stripos($field['label'], 'first') !== false) {
                    $replacements['{first_name}'] = $value;
                    $replacements['{field_first_name}'] = $value; // User-friendly alias
                } elseif (stripos($field['label'], 'last') !== false) {
                    $replacements['{last_name}'] = $value;
                    $replacements['{field_last_name}'] = $value; // User-friendly alias
                } else {
                    $replacements['{name}'] = $value;
                }
            }

            if ($field['type'] === 'email') {
                $replacements['{email}'] = $value;
                $replacements['{field_email}'] = $value; // User-friendly alias
            }

            if ($field['type'] === 'phone') {
                $replacements['{phone}'] = $value;
                $replacements['{field_phone}'] = $value; // User-friendly alias
            }

            if ($field['type'] === 'textarea') {
                $replacements['{message}'] = $value;
                $replacements['{field_message}'] = $value; // User-friendly alias
            }
        }

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Get user email from submission
     *
     * @return string|null
     */
    private function get_user_email() {
        // Look for email field in data
        $fields = isset($this->form->fields['fields']) ? $this->form->fields['fields'] : [];

        foreach ($fields as $field) {
            if ($field['type'] === 'email') {
                $email = isset($this->data[$field['id']]) ? $this->data[$field['id']] : '';
                if (is_email($email)) {
                    return $email;
                }
            }
        }

        return null;
    }

    /**
     * Get user name from submission
     *
     * @return string|null
     */
    private function get_user_name() {
        $fields = isset($this->form->fields['fields']) ? $this->form->fields['fields'] : [];
        $first_name = '';
        $last_name = '';
        $name = '';

        foreach ($fields as $field) {
            if ($field['type'] !== 'text') {
                continue;
            }

            $label_lower = strtolower($field['label']);
            $value = isset($this->data[$field['id']]) ? trim($this->data[$field['id']]) : '';

            if (empty($value)) {
                continue;
            }

            if (strpos($label_lower, 'first') !== false && strpos($label_lower, 'name') !== false) {
                $first_name = $value;
            } elseif (strpos($label_lower, 'last') !== false && strpos($label_lower, 'name') !== false) {
                $last_name = $value;
            } elseif (strpos($label_lower, 'name') !== false && strpos($label_lower, 'full') !== false) {
                $name = $value;
            } elseif ($label_lower === 'name') {
                $name = $value;
            }
        }

        if ($first_name || $last_name) {
            return trim($first_name . ' ' . $last_name);
        }

        return $name ?: null;
    }

    /**
     * Get email headers
     *
     * @param int|null $recipient_user_id User ID of recipient (for dynamic from address)
     * @return array
     */
    private function get_email_headers($recipient_user_id = null) {
        // Use centralized email utilities if available
        if (class_exists('MLD_Email_Utilities')) {
            return MLD_Email_Utilities::get_email_headers($recipient_user_id);
        }

        // Fallback to static headers
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
        ];
    }
}
