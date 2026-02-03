<?php
/**
 * Contact Form Validator
 *
 * Handles server-side validation of contact form submissions.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Validator
 *
 * Validates form submissions against field definitions.
 */
class MLD_Contact_Form_Validator {

    /**
     * Validation errors
     *
     * @var array
     */
    private $errors = [];

    /**
     * Field labels for error messages
     *
     * @var array
     */
    private $field_labels = [];

    /**
     * Validate form submission
     *
     * @param array $form_fields Array of field definitions from the form
     * @param array $submitted_data Submitted form data
     * @return bool True if valid, false if errors
     */
    public function validate(array $form_fields, array $submitted_data) {
        $this->errors = [];
        $this->field_labels = [];

        // Build field labels lookup
        foreach ($form_fields as $field) {
            $this->field_labels[$field['id']] = $field['label'];
        }

        // Get conditional visibility map (v6.22.0)
        $visibility_map = array();
        if (class_exists('MLD_Contact_Form_Conditional')) {
            $visibility_map = MLD_Contact_Form_Conditional::get_field_visibility_map($form_fields, $submitted_data);
        }

        // Validate each field
        foreach ($form_fields as $field) {
            $field_id = $field['id'];
            $value = isset($submitted_data[$field_id]) ? $submitted_data[$field_id] : '';

            // Skip validation for conditionally hidden fields (v6.22.0)
            if (isset($visibility_map[$field_id]) && $visibility_map[$field_id] === false) {
                continue;
            }

            // Skip if field has no validation rules and is not required
            if (empty($field['required']) && empty($value)) {
                continue;
            }

            // Validate based on field type
            switch ($field['type']) {
                case 'text':
                    $this->validate_text($field, $value);
                    break;

                case 'email':
                    $this->validate_email($field, $value);
                    break;

                case 'phone':
                    $this->validate_phone($field, $value);
                    break;

                case 'textarea':
                    $this->validate_textarea($field, $value);
                    break;

                case 'dropdown':
                    $this->validate_dropdown($field, $value);
                    break;

                case 'checkbox':
                    $this->validate_checkbox($field, $value);
                    break;

                case 'radio':
                    $this->validate_radio($field, $value);
                    break;

                case 'date':
                    $this->validate_date($field, $value);
                    break;

                // New field types added in v6.22.0
                case 'number':
                    $this->validate_number($field, $value);
                    break;

                case 'currency':
                    $this->validate_currency($field, $value);
                    break;

                case 'url':
                    $this->validate_url($field, $value);
                    break;

                case 'hidden':
                    // Hidden fields don't need validation (they're set by the form)
                    break;

                case 'section':
                case 'paragraph':
                    // Display-only fields, no validation needed
                    break;

                // New field type added in v6.24.0
                case 'file':
                    $this->validate_file($field, $value, $submitted_data);
                    break;

                default:
                    // Unknown field type, just check required
                    if (!empty($field['required']) && empty($value)) {
                        $this->add_error($field_id, sprintf(
                            __('%s is required.', 'mls-listings-display'),
                            $field['label']
                        ));
                    }
            }
        }

        return empty($this->errors);
    }

    /**
     * Get validation errors
     *
     * @return array Associative array of field_id => error_message
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Get first error message (for simple error display)
     *
     * @return string|null First error message or null
     */
    public function get_first_error() {
        if (empty($this->errors)) {
            return null;
        }
        return reset($this->errors);
    }

    /**
     * Validate text field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_text(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Length validation
        if (!$this->is_length_valid($field, $value)) {
            $valid = false;
        }

        // Pattern validation
        if (!$this->is_pattern_valid($field, $value)) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate email field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_email(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Email format validation
        if (!is_email($value)) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid email address for %s.', 'mls-listings-display'),
                $field['label']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate phone field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_phone(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Strip common phone formatting characters for validation
        $digits_only = preg_replace('/[^0-9]/', '', $value);

        // Check minimum digits (at least 10 for US numbers)
        if (strlen($digits_only) < 10) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid phone number for %s.', 'mls-listings-display'),
                $field['label']
            ));
            $valid = false;
        }

        // Check maximum digits (international numbers can be up to 15)
        if (strlen($digits_only) > 15) {
            $this->add_error($field['id'], sprintf(
                __('Phone number is too long for %s.', 'mls-listings-display'),
                $field['label']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate textarea field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_textarea(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Length validation
        if (!$this->is_length_valid($field, $value)) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate dropdown field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_dropdown(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Check if value is in allowed options
        $options = isset($field['options']) ? $field['options'] : [];
        if (!empty($options)) {
            // Remove WordPress slashes and decode HTML entities
            $clean_value = wp_unslash($value);
            $clean_value = html_entity_decode($clean_value, ENT_QUOTES, 'UTF-8');
            if (!in_array($value, $options) && !in_array($clean_value, $options)) {
                $this->add_error($field['id'], sprintf(
                    __('Please select a valid option for %s.', 'mls-listings-display'),
                    $field['label']
                ));
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate checkbox field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value (can be array or single value)
     * @return bool
     */
    private function validate_checkbox(array $field, $value) {
        $valid = true;

        // For checkboxes, required means at least one must be selected
        if (!empty($field['required'])) {
            if (empty($value) || (is_array($value) && count($value) === 0)) {
                $this->add_error($field['id'], sprintf(
                    __('Please select at least one option for %s.', 'mls-listings-display'),
                    $field['label']
                ));
                $valid = false;
            }
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Ensure value is array for checking
        $values = is_array($value) ? $value : [$value];

        // Check if all values are in allowed options
        $options = isset($field['options']) ? $field['options'] : [];
        if (!empty($options)) {
            foreach ($values as $v) {
                // Remove WordPress slashes and decode HTML entities
                $clean_value = wp_unslash($v);
                $clean_value = html_entity_decode($clean_value, ENT_QUOTES, 'UTF-8');

                if (!in_array($v, $options) && !in_array($clean_value, $options)) {
                    $this->add_error($field['id'], sprintf(
                        __('Invalid selection for %s.', 'mls-listings-display'),
                        $field['label']
                    ));
                    $valid = false;
                    break;
                }
            }
        }

        return $valid;
    }

    /**
     * Validate radio field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_radio(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Check if value is in allowed options
        $options = isset($field['options']) ? $field['options'] : [];
        if (!empty($options)) {
            // Remove WordPress slashes and decode HTML entities
            $clean_value = wp_unslash($value);
            $clean_value = html_entity_decode($clean_value, ENT_QUOTES, 'UTF-8');
            if (!in_array($value, $options) && !in_array($clean_value, $options)) {
                $this->add_error($field['id'], sprintf(
                    __('Please select a valid option for %s.', 'mls-listings-display'),
                    $field['label']
                ));
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate date field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_date(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Check date format (YYYY-MM-DD)
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid date for %s.', 'mls-listings-display'),
                $field['label']
            ));
            $valid = false;
        }

        return $valid;
    }

    // =====================================================
    // New field type validation methods added in v6.22.0
    // =====================================================

    /**
     * Validate number field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_number(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if ($value === '' || $value === null) {
            return $valid;
        }

        // Check if it's a valid number
        if (!is_numeric($value)) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid number for %s.', 'mls-listings-display'),
                $field['label']
            ));
            return false;
        }

        $num_value = floatval($value);
        $validation = isset($field['validation']) ? $field['validation'] : [];

        // Min value check
        if (isset($validation['min']) && $validation['min'] !== '' && $num_value < floatval($validation['min'])) {
            $this->add_error($field['id'], sprintf(
                __('%s must be at least %s.', 'mls-listings-display'),
                $field['label'],
                $validation['min']
            ));
            $valid = false;
        }

        // Max value check
        if (isset($validation['max']) && $validation['max'] !== '' && $num_value > floatval($validation['max'])) {
            $this->add_error($field['id'], sprintf(
                __('%s cannot exceed %s.', 'mls-listings-display'),
                $field['label'],
                $validation['max']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate currency field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_currency(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if ($value === '' || $value === null) {
            return $valid;
        }

        // Remove currency symbol and commas for validation
        $clean_value = preg_replace('/[^0-9.\-]/', '', $value);

        // Check if it's a valid number
        if (!is_numeric($clean_value)) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid amount for %s.', 'mls-listings-display'),
                $field['label']
            ));
            return false;
        }

        $num_value = floatval($clean_value);
        $validation = isset($field['validation']) ? $field['validation'] : [];

        // Min value check (currency is usually non-negative)
        if (isset($validation['min']) && $validation['min'] !== '' && $num_value < floatval($validation['min'])) {
            $this->add_error($field['id'], sprintf(
                __('%s must be at least %s.', 'mls-listings-display'),
                $field['label'],
                $validation['min']
            ));
            $valid = false;
        }

        // Max value check
        if (isset($validation['max']) && $validation['max'] !== '' && $num_value > floatval($validation['max'])) {
            $this->add_error($field['id'], sprintf(
                __('%s cannot exceed %s.', 'mls-listings-display'),
                $field['label'],
                $validation['max']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate URL field
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function validate_url(array $field, $value) {
        $valid = true;

        // Required check
        if (!$this->is_required_valid($field, $value)) {
            $valid = false;
        }

        // Skip further validation if empty and not required
        if (empty($value)) {
            return $valid;
        }

        // Check URL format
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->add_error($field['id'], sprintf(
                __('Please enter a valid URL for %s (e.g., https://example.com).', 'mls-listings-display'),
                $field['label']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Validate file upload field (v6.24.0)
     *
     * @param array $field          Field definition
     * @param mixed $value          Submitted value (not used for files)
     * @param array $submitted_data Full submitted data
     * @return bool
     */
    private function validate_file(array $field, $value, array $submitted_data) {
        $field_id = $field['id'];
        $tokens_key = $field_id . '_tokens';
        $valid = true;

        // Get upload tokens from submitted data
        $tokens_value = isset($submitted_data[$tokens_key]) ? $submitted_data[$tokens_key] : '';
        $tokens = !empty($tokens_value) ? explode(',', $tokens_value) : [];

        // Required check - must have at least one upload token
        if (!empty($field['required']) && empty($tokens)) {
            $this->add_error($field_id, sprintf(
                __('Please upload at least one file for %s.', 'mls-listings-display'),
                $field['label']
            ));
            return false;
        }

        // Skip further validation if no files uploaded and not required
        if (empty($tokens)) {
            return $valid;
        }

        // Get file configuration
        $file_config = isset($field['file_config']) ? $field['file_config'] : [];
        $max_files = isset($file_config['max_files']) ? intval($file_config['max_files']) : 3;

        // Check max files limit
        if (count($tokens) > $max_files) {
            $this->add_error($field_id, sprintf(
                __('Maximum of %d files allowed for %s.', 'mls-listings-display'),
                $max_files,
                $field['label']
            ));
            $valid = false;
        }

        return $valid;
    }

    /**
     * Check if required field is valid
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function is_required_valid(array $field, $value) {
        if (!empty($field['required'])) {
            $is_empty = is_array($value) ? empty($value) : trim($value) === '';

            if ($is_empty) {
                $this->add_error($field['id'], sprintf(
                    __('%s is required.', 'mls-listings-display'),
                    $field['label']
                ));
                return false;
            }
        }
        return true;
    }

    /**
     * Check if field length is valid
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function is_length_valid(array $field, $value) {
        if (!isset($field['validation']) || !is_array($field['validation'])) {
            return true;
        }

        $length = mb_strlen($value);
        $validation = $field['validation'];

        // Min length check
        if (isset($validation['min_length']) && $validation['min_length'] > 0) {
            if ($length < $validation['min_length']) {
                $this->add_error($field['id'], sprintf(
                    __('%s must be at least %d characters.', 'mls-listings-display'),
                    $field['label'],
                    $validation['min_length']
                ));
                return false;
            }
        }

        // Max length check
        if (isset($validation['max_length']) && $validation['max_length'] > 0) {
            if ($length > $validation['max_length']) {
                $this->add_error($field['id'], sprintf(
                    __('%s cannot exceed %d characters.', 'mls-listings-display'),
                    $field['label'],
                    $validation['max_length']
                ));
                return false;
            }
        }

        return true;
    }

    /**
     * Check if field matches pattern
     *
     * @param array $field Field definition
     * @param mixed $value Submitted value
     * @return bool
     */
    private function is_pattern_valid(array $field, $value) {
        if (!isset($field['validation']['pattern']) || empty($field['validation']['pattern'])) {
            return true;
        }

        $pattern = $field['validation']['pattern'];

        // Ensure pattern has delimiters
        if (substr($pattern, 0, 1) !== '/') {
            $pattern = '/' . $pattern . '/';
        }

        if (!preg_match($pattern, $value)) {
            $error_message = isset($field['validation']['pattern_message'])
                ? $field['validation']['pattern_message']
                : sprintf(__('%s has an invalid format.', 'mls-listings-display'), $field['label']);

            $this->add_error($field['id'], $error_message);
            return false;
        }

        return true;
    }

    /**
     * Add an error message
     *
     * @param string $field_id Field ID
     * @param string $message Error message
     * @return void
     */
    private function add_error($field_id, $message) {
        // Only add first error per field
        if (!isset($this->errors[$field_id])) {
            $this->errors[$field_id] = $message;
        }
    }

    /**
     * Sanitize submitted data
     *
     * @param array $form_fields Array of field definitions
     * @param array $submitted_data Raw submitted data
     * @return array Sanitized data
     */
    public function sanitize_data(array $form_fields, array $submitted_data) {
        $sanitized = [];

        foreach ($form_fields as $field) {
            $field_id = $field['id'];

            if (!isset($submitted_data[$field_id])) {
                continue;
            }

            // Remove WordPress slashes before sanitizing
            $value = wp_unslash($submitted_data[$field_id]);

            switch ($field['type']) {
                case 'email':
                    $sanitized[$field_id] = sanitize_email($value);
                    break;

                case 'textarea':
                    $sanitized[$field_id] = sanitize_textarea_field($value);
                    break;

                case 'checkbox':
                    if (is_array($value)) {
                        $sanitized[$field_id] = array_map('sanitize_text_field', $value);
                    } else {
                        $sanitized[$field_id] = sanitize_text_field($value);
                    }
                    break;

                case 'phone':
                    // Keep common phone characters but sanitize
                    $sanitized[$field_id] = preg_replace('/[^0-9\-\+\(\)\s\.]/', '', $value);
                    break;

                case 'date':
                    // Ensure date format
                    $date = \DateTime::createFromFormat('Y-m-d', $value);
                    $sanitized[$field_id] = $date ? $date->format('Y-m-d') : '';
                    break;

                // New field types added in v6.22.0
                case 'number':
                    // Remove anything that's not a number, decimal, or negative sign
                    $sanitized[$field_id] = preg_replace('/[^0-9.\-]/', '', $value);
                    break;

                case 'currency':
                    // Remove currency symbols and commas, keep numbers and decimal
                    $sanitized[$field_id] = preg_replace('/[^0-9.\-]/', '', $value);
                    break;

                case 'url':
                    $sanitized[$field_id] = esc_url_raw($value);
                    break;

                case 'hidden':
                    $sanitized[$field_id] = sanitize_text_field($value);
                    break;

                case 'section':
                case 'paragraph':
                    // Display-only fields, no data to sanitize
                    break;

                // New field type added in v6.24.0
                case 'file':
                    // File uploads are handled via tokens
                    $tokens_key = $field_id . '_tokens';
                    if (isset($submitted_data[$tokens_key])) {
                        $sanitized[$tokens_key] = sanitize_text_field(wp_unslash($submitted_data[$tokens_key]));
                    }
                    break;

                default:
                    $sanitized[$field_id] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    /**
     * Check honeypot field (spam protection)
     *
     * @param array $submitted_data Submitted data
     * @return bool True if valid (honeypot empty), false if spam detected
     */
    public function check_honeypot(array $submitted_data) {
        // The honeypot field should be empty
        if (isset($submitted_data['mld_cf_hp']) && !empty($submitted_data['mld_cf_hp'])) {
            return false; // Spam detected
        }
        return true;
    }
}
