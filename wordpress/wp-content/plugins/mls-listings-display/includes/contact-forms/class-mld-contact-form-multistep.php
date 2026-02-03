<?php
/**
 * Contact Form Multi-Step Handler
 *
 * Handles multi-step form functionality including step organization,
 * progress tracking, and per-step validation.
 *
 * @package MLS_Listings_Display
 * @since 6.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Multistep
 *
 * Manages multi-step form wizard functionality.
 */
class MLD_Contact_Form_Multistep {

    /**
     * Check if a form has multi-step enabled.
     *
     * @param object $form Form object with settings.
     * @return bool True if multi-step is enabled.
     */
    public static function is_multistep_enabled($form) {
        if (!is_object($form) || !isset($form->settings)) {
            return false;
        }

        $settings = is_array($form->settings) ? $form->settings : json_decode($form->settings, true);

        return !empty($settings['multistep_enabled']);
    }

    /**
     * Get step definitions from form settings.
     *
     * @param object $form Form object.
     * @return array Array of step definitions.
     */
    public static function get_steps($form) {
        if (!is_object($form) || !isset($form->settings)) {
            return [['title' => 'Step 1', 'description' => '']];
        }

        $settings = is_array($form->settings) ? $form->settings : json_decode($form->settings, true);

        if (empty($settings['steps']) || !is_array($settings['steps'])) {
            return [['title' => 'Step 1', 'description' => '']];
        }

        return $settings['steps'];
    }

    /**
     * Get multi-step settings from form.
     *
     * @param object $form Form object.
     * @return array Multi-step settings with defaults.
     */
    public static function get_multistep_settings($form) {
        $defaults = [
            'multistep_enabled' => false,
            'multistep_progress_type' => 'steps',
            'multistep_show_step_titles' => true,
            'multistep_prev_button_text' => 'Previous',
            'multistep_next_button_text' => 'Next',
            'steps' => [['title' => 'Step 1', 'description' => '']]
        ];

        if (!is_object($form) || !isset($form->settings)) {
            return $defaults;
        }

        $settings = is_array($form->settings) ? $form->settings : json_decode($form->settings, true);

        return wp_parse_args(array_intersect_key($settings, $defaults), $defaults);
    }

    /**
     * Organize fields into steps.
     *
     * @param array $fields    Array of field definitions.
     * @param array $steps     Array of step definitions.
     * @return array           Fields organized by step number.
     */
    public static function organize_fields_by_step(array $fields, array $steps) {
        $organized = [];
        $step_count = count($steps);

        // Initialize empty arrays for each step
        for ($i = 1; $i <= $step_count; $i++) {
            $organized[$i] = [];
        }

        // Sort fields by order first
        usort($fields, function($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        // Assign fields to steps
        foreach ($fields as $field) {
            $step = isset($field['step']) ? intval($field['step']) : 1;

            // Ensure step is within valid range
            if ($step < 1) {
                $step = 1;
            } elseif ($step > $step_count) {
                $step = $step_count;
            }

            $organized[$step][] = $field;
        }

        return $organized;
    }

    /**
     * Get fields for a specific step.
     *
     * @param array $fields All form fields.
     * @param int   $step   Step number (1-based).
     * @return array        Fields belonging to the specified step.
     */
    public static function get_fields_for_step(array $fields, int $step) {
        $step_fields = [];

        foreach ($fields as $field) {
            $field_step = isset($field['step']) ? intval($field['step']) : 1;
            if ($field_step === $step) {
                $step_fields[] = $field;
            }
        }

        // Sort by order
        usort($step_fields, function($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        return $step_fields;
    }

    /**
     * Validate a specific step's fields.
     *
     * @param array $fields         All form fields.
     * @param int   $step           Step number to validate.
     * @param array $submitted_data Submitted form data.
     * @param array $visibility_map Optional visibility map from conditional logic.
     * @return array                Array of errors (empty if valid).
     */
    public static function validate_step(array $fields, int $step, array $submitted_data, array $visibility_map = []) {
        $step_fields = self::get_fields_for_step($fields, $step);
        $errors = [];

        foreach ($step_fields as $field) {
            $field_id = $field['id'] ?? '';

            // Skip if field is conditionally hidden
            if (isset($visibility_map[$field_id]) && $visibility_map[$field_id] === false) {
                continue;
            }

            // Skip display-only fields
            if (in_array($field['type'], ['section', 'paragraph', 'hidden'])) {
                continue;
            }

            $value = isset($submitted_data[$field_id]) ? $submitted_data[$field_id] : '';
            $required = !empty($field['required']);

            // Check required fields
            if ($required && self::is_value_empty($value)) {
                $errors[$field_id] = sprintf(
                    __('%s is required.', 'mls-listings-display'),
                    $field['label'] ?? 'This field'
                );
            }
        }

        return $errors;
    }

    /**
     * Check if a value is considered empty.
     *
     * @param mixed $value Value to check.
     * @return bool        True if empty.
     */
    private static function is_value_empty($value) {
        if (is_array($value)) {
            return empty($value);
        }
        return $value === '' || $value === null;
    }

    /**
     * Render progress indicator HTML.
     *
     * @param array  $steps        Array of step definitions.
     * @param int    $current_step Current step number (1-based).
     * @param string $type         Progress type ('steps' or 'bar').
     * @param bool   $show_titles  Whether to show step titles.
     * @return string              HTML for progress indicator.
     */
    public static function render_progress_indicator(array $steps, int $current_step = 1, string $type = 'steps', bool $show_titles = true) {
        $total_steps = count($steps);

        if ($total_steps < 2) {
            return ''; // No progress indicator for single-step forms
        }

        $html = '<div class="mld-cf-progress" data-type="' . esc_attr($type) . '">';

        if ($type === 'bar') {
            // Progress bar style
            $progress_percent = (($current_step - 1) / ($total_steps - 1)) * 100;
            $html .= '<div class="mld-cf-progress-bar-wrapper">';
            $html .= '<div class="mld-cf-progress-bar" style="width: ' . esc_attr($progress_percent) . '%;"></div>';
            $html .= '</div>';
            $html .= '<div class="mld-cf-progress-text">';
            $html .= sprintf(
                esc_html__('Step %1$d of %2$d', 'mls-listings-display'),
                $current_step,
                $total_steps
            );
            if ($show_titles && !empty($steps[$current_step - 1]['title'])) {
                $html .= ': <strong>' . esc_html($steps[$current_step - 1]['title']) . '</strong>';
            }
            $html .= '</div>';
        } else {
            // Numbered steps style
            $html .= '<div class="mld-cf-progress-steps">';
            foreach ($steps as $index => $step) {
                $step_num = $index + 1;
                $status = '';
                if ($step_num < $current_step) {
                    $status = 'completed';
                } elseif ($step_num === $current_step) {
                    $status = 'current';
                } else {
                    $status = 'upcoming';
                }

                $html .= '<div class="mld-cf-step-indicator ' . esc_attr($status) . '" data-step="' . esc_attr($step_num) . '">';
                $html .= '<span class="mld-cf-step-number">';
                if ($status === 'completed') {
                    $html .= '<span class="mld-cf-step-check">&#10003;</span>';
                } else {
                    $html .= esc_html($step_num);
                }
                $html .= '</span>';
                if ($show_titles && !empty($step['title'])) {
                    $html .= '<span class="mld-cf-step-title">' . esc_html($step['title']) . '</span>';
                }
                $html .= '</div>';

                // Connector line between steps (not after last step)
                if ($step_num < $total_steps) {
                    $connector_status = $step_num < $current_step ? 'completed' : '';
                    $html .= '<div class="mld-cf-step-connector ' . esc_attr($connector_status) . '"></div>';
                }
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render step navigation buttons HTML.
     *
     * @param int    $current_step  Current step number.
     * @param int    $total_steps   Total number of steps.
     * @param string $prev_text     Previous button text.
     * @param string $next_text     Next button text.
     * @param string $submit_text   Submit button text.
     * @return string               HTML for navigation buttons.
     */
    public static function render_step_navigation(
        int $current_step,
        int $total_steps,
        string $prev_text = 'Previous',
        string $next_text = 'Next',
        string $submit_text = 'Submit'
    ) {
        $html = '<div class="mld-cf-step-navigation">';

        // Previous button (not on first step)
        if ($current_step > 1) {
            $html .= '<button type="button" class="mld-cf-prev-step" data-step="' . esc_attr($current_step - 1) . '">';
            $html .= '<span class="mld-cf-nav-arrow">&larr;</span> ';
            $html .= esc_html($prev_text);
            $html .= '</button>';
        } else {
            $html .= '<span class="mld-cf-nav-placeholder"></span>';
        }

        // Next button or Submit button
        if ($current_step < $total_steps) {
            $html .= '<button type="button" class="mld-cf-next-step" data-step="' . esc_attr($current_step + 1) . '">';
            $html .= esc_html($next_text);
            $html .= ' <span class="mld-cf-nav-arrow">&rarr;</span>';
            $html .= '</button>';
        } else {
            $html .= '<button type="submit" class="mld-cf-submit">';
            $html .= esc_html($submit_text);
            $html .= '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get configuration data for JavaScript.
     *
     * @param object $form   Form object.
     * @param array  $fields Form fields.
     * @return array         Configuration for frontend JS.
     */
    public static function get_frontend_config($form, array $fields) {
        $settings = self::get_multistep_settings($form);
        $steps = $settings['steps'];
        $fields_by_step = self::organize_fields_by_step($fields, $steps);

        // Get field IDs per step
        $field_ids_by_step = [];
        foreach ($fields_by_step as $step_num => $step_fields) {
            $field_ids_by_step[$step_num] = array_map(function($f) {
                return $f['id'] ?? '';
            }, $step_fields);
        }

        return [
            'enabled' => !empty($settings['multistep_enabled']),
            'totalSteps' => count($steps),
            'currentStep' => 1,
            'progressType' => $settings['multistep_progress_type'],
            'showStepTitles' => $settings['multistep_show_step_titles'],
            'prevButtonText' => $settings['multistep_prev_button_text'],
            'nextButtonText' => $settings['multistep_next_button_text'],
            'steps' => $steps,
            'fieldsByStep' => $field_ids_by_step
        ];
    }

    /**
     * Ensure all fields have a valid step assignment.
     *
     * @param array $fields    Form fields.
     * @param int   $max_steps Maximum number of steps.
     * @return array           Fields with validated step assignments.
     */
    public static function normalize_field_steps(array $fields, int $max_steps) {
        foreach ($fields as &$field) {
            $step = isset($field['step']) ? intval($field['step']) : 1;

            if ($step < 1) {
                $field['step'] = 1;
            } elseif ($step > $max_steps) {
                $field['step'] = $max_steps;
            } else {
                $field['step'] = $step;
            }
        }

        return $fields;
    }

    /**
     * Check if form has any fields assigned to a specific step.
     *
     * @param array $fields Form fields.
     * @param int   $step   Step number.
     * @return bool         True if step has fields.
     */
    public static function step_has_fields(array $fields, int $step) {
        foreach ($fields as $field) {
            if ((isset($field['step']) ? intval($field['step']) : 1) === $step) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get step validation status for all steps.
     *
     * @param array $fields         All form fields.
     * @param array $submitted_data Submitted form data.
     * @param array $steps          Step definitions.
     * @param array $visibility_map Optional visibility map.
     * @return array                Array of step => is_valid.
     */
    public static function get_all_steps_validation_status(
        array $fields,
        array $submitted_data,
        array $steps,
        array $visibility_map = []
    ) {
        $status = [];

        for ($i = 1; $i <= count($steps); $i++) {
            $errors = self::validate_step($fields, $i, $submitted_data, $visibility_map);
            $status[$i] = empty($errors);
        }

        return $status;
    }
}
