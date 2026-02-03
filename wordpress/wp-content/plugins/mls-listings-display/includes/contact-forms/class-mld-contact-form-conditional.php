<?php
/**
 * Contact Form Conditional Logic Handler
 *
 * Evaluates conditional logic rules for contact form fields.
 * Supports show/hide actions based on field values with AND/OR logic.
 *
 * @package MLS_Listings_Display
 * @since 6.22.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Conditional
 *
 * Handles conditional logic evaluation for contact form fields.
 * Determines field visibility based on other field values.
 */
class MLD_Contact_Form_Conditional {

    /**
     * Supported operators for conditional rules.
     *
     * @var array
     */
    private static $operators = array(
        'equals'        => 'Equals',
        'not_equals'    => 'Does not equal',
        'contains'      => 'Contains',
        'not_contains'  => 'Does not contain',
        'is_empty'      => 'Is empty',
        'is_not_empty'  => 'Is not empty',
        'greater_than'  => 'Greater than',
        'less_than'     => 'Less than',
        'starts_with'   => 'Starts with',
        'ends_with'     => 'Ends with',
    );

    /**
     * Supported actions for conditional rules.
     *
     * @var array
     */
    private static $actions = array(
        'show' => 'Show this field',
        'hide' => 'Hide this field',
    );

    /**
     * Supported logic operators for combining rules.
     *
     * @var array
     */
    private static $logic_operators = array(
        'all' => 'All conditions must match (AND)',
        'any' => 'Any condition can match (OR)',
    );

    /**
     * Get available operators.
     *
     * @return array
     */
    public static function get_operators() {
        return self::$operators;
    }

    /**
     * Get available actions.
     *
     * @return array
     */
    public static function get_actions() {
        return self::$actions;
    }

    /**
     * Get available logic operators.
     *
     * @return array
     */
    public static function get_logic_operators() {
        return self::$logic_operators;
    }

    /**
     * Evaluate all conditional rules for a form and return visibility map.
     *
     * @param array $fields     Array of form field definitions.
     * @param array $form_data  Submitted form data (field_id => value).
     * @return array            Map of field_id => boolean (true = visible, false = hidden).
     */
    public static function get_field_visibility_map(array $fields, array $form_data = array()) {
        $visibility_map = array();

        // Initialize all fields as visible by default
        foreach ($fields as $field) {
            $field_id = isset($field['id']) ? $field['id'] : '';
            if ($field_id) {
                $visibility_map[$field_id] = true;
            }
        }

        // Build dependency graph for cascading logic
        $dependency_graph = self::build_dependency_graph($fields);

        // Process fields in dependency order (fields without dependencies first)
        $processed = array();
        $max_iterations = count($fields) * 2; // Prevent infinite loops
        $iteration = 0;

        while (count($processed) < count($fields) && $iteration < $max_iterations) {
            $iteration++;

            foreach ($fields as $field) {
                $field_id = isset($field['id']) ? $field['id'] : '';

                // Skip already processed fields
                if (in_array($field_id, $processed)) {
                    continue;
                }

                // Check if this field has conditional logic
                $conditional = isset($field['conditional']) ? $field['conditional'] : null;

                if (!$conditional || empty($conditional['enabled'])) {
                    // No conditional logic, field is always visible
                    $processed[] = $field_id;
                    continue;
                }

                // Get dependencies for this field
                $dependencies = isset($dependency_graph[$field_id]) ? $dependency_graph[$field_id] : array();

                // Check if all dependencies have been processed
                $all_dependencies_processed = true;
                foreach ($dependencies as $dep_field_id) {
                    if (!in_array($dep_field_id, $processed)) {
                        $all_dependencies_processed = false;
                        break;
                    }
                }

                if (!$all_dependencies_processed) {
                    // Wait until dependencies are processed
                    continue;
                }

                // Evaluate visibility for this field
                $visibility_map[$field_id] = self::evaluate_field_visibility(
                    $field,
                    $form_data,
                    $visibility_map
                );

                $processed[] = $field_id;
            }
        }

        return $visibility_map;
    }

    /**
     * Build a dependency graph showing which fields depend on which other fields.
     *
     * @param array $fields Array of form field definitions.
     * @return array        Map of field_id => array of dependent field_ids.
     */
    private static function build_dependency_graph(array $fields) {
        $graph = array();

        foreach ($fields as $field) {
            $field_id = isset($field['id']) ? $field['id'] : '';
            $conditional = isset($field['conditional']) ? $field['conditional'] : null;

            if (!$conditional || empty($conditional['enabled']) || empty($conditional['rules'])) {
                continue;
            }

            $dependencies = array();
            foreach ($conditional['rules'] as $rule) {
                if (!empty($rule['field_id'])) {
                    $dependencies[] = $rule['field_id'];
                }
            }

            if (!empty($dependencies)) {
                $graph[$field_id] = array_unique($dependencies);
            }
        }

        return $graph;
    }

    /**
     * Evaluate visibility for a single field based on its conditional rules.
     *
     * @param array $field          Field definition with conditional config.
     * @param array $form_data      Submitted form data.
     * @param array $visibility_map Current visibility map for dependency checking.
     * @return bool                 True if field should be visible, false otherwise.
     */
    private static function evaluate_field_visibility(array $field, array $form_data, array $visibility_map) {
        $conditional = isset($field['conditional']) ? $field['conditional'] : null;

        if (!$conditional || empty($conditional['enabled'])) {
            return true; // No conditional logic, always visible
        }

        $rules = isset($conditional['rules']) ? $conditional['rules'] : array();
        $logic = isset($conditional['logic']) ? $conditional['logic'] : 'all';
        $action = isset($conditional['action']) ? $conditional['action'] : 'show';

        if (empty($rules)) {
            return true; // No rules defined, always visible
        }

        // Evaluate all rules
        $rules_result = self::evaluate_rules($rules, $logic, $form_data, $visibility_map);

        // Apply action based on rule result
        if ($action === 'show') {
            return $rules_result; // Show when conditions match
        } else {
            return !$rules_result; // Hide when conditions match (inverse)
        }
    }

    /**
     * Evaluate a set of rules with the specified logic operator.
     *
     * @param array  $rules          Array of rule definitions.
     * @param string $logic          Logic operator ('all' or 'any').
     * @param array  $form_data      Submitted form data.
     * @param array  $visibility_map Current visibility map for dependency checking.
     * @return bool                  True if conditions are met, false otherwise.
     */
    public static function evaluate_rules(array $rules, string $logic, array $form_data, array $visibility_map = array()) {
        if (empty($rules)) {
            return true;
        }

        $results = array();

        foreach ($rules as $rule) {
            $field_id = isset($rule['field_id']) ? $rule['field_id'] : '';
            $operator = isset($rule['operator']) ? $rule['operator'] : 'equals';
            $compare_value = isset($rule['value']) ? $rule['value'] : '';

            // If the source field is hidden, treat its value as empty
            $source_visible = !isset($visibility_map[$field_id]) || $visibility_map[$field_id];
            $field_value = $source_visible && isset($form_data[$field_id]) ? $form_data[$field_id] : '';

            // Evaluate the single rule
            $results[] = self::evaluate_single_rule($field_value, $operator, $compare_value);
        }

        // Apply logic operator
        if ($logic === 'any') {
            return in_array(true, $results, true); // OR logic
        } else {
            return !in_array(false, $results, true); // AND logic (default)
        }
    }

    /**
     * Evaluate a single conditional rule.
     *
     * @param mixed  $field_value   The actual field value.
     * @param string $operator      The comparison operator.
     * @param mixed  $compare_value The value to compare against.
     * @return bool                 True if condition is met, false otherwise.
     */
    public static function evaluate_single_rule($field_value, string $operator, $compare_value) {
        // Normalize values for comparison
        $field_value = is_array($field_value) ? $field_value : strval($field_value);
        $compare_value = strval($compare_value);

        // Handle array values (checkboxes)
        if (is_array($field_value)) {
            return self::evaluate_array_rule($field_value, $operator, $compare_value);
        }

        switch ($operator) {
            case 'equals':
                return strcasecmp($field_value, $compare_value) === 0;

            case 'not_equals':
                return strcasecmp($field_value, $compare_value) !== 0;

            case 'contains':
                return stripos($field_value, $compare_value) !== false;

            case 'not_contains':
                return stripos($field_value, $compare_value) === false;

            case 'is_empty':
                return $field_value === '' || $field_value === null;

            case 'is_not_empty':
                return $field_value !== '' && $field_value !== null;

            case 'greater_than':
                return is_numeric($field_value) && is_numeric($compare_value)
                    && floatval($field_value) > floatval($compare_value);

            case 'less_than':
                return is_numeric($field_value) && is_numeric($compare_value)
                    && floatval($field_value) < floatval($compare_value);

            case 'starts_with':
                return stripos($field_value, $compare_value) === 0;

            case 'ends_with':
                $len = strlen($compare_value);
                if ($len === 0) {
                    return true;
                }
                return strcasecmp(substr($field_value, -$len), $compare_value) === 0;

            default:
                return false;
        }
    }

    /**
     * Evaluate rule for array field values (checkboxes).
     *
     * @param array  $field_values  Array of selected values.
     * @param string $operator      The comparison operator.
     * @param string $compare_value The value to compare against.
     * @return bool                 True if condition is met, false otherwise.
     */
    private static function evaluate_array_rule(array $field_values, string $operator, string $compare_value) {
        switch ($operator) {
            case 'equals':
            case 'contains':
                // Check if the compare value is in the selected values
                foreach ($field_values as $val) {
                    if (strcasecmp(strval($val), $compare_value) === 0) {
                        return true;
                    }
                }
                return false;

            case 'not_equals':
            case 'not_contains':
                // Check if the compare value is NOT in the selected values
                foreach ($field_values as $val) {
                    if (strcasecmp(strval($val), $compare_value) === 0) {
                        return false;
                    }
                }
                return true;

            case 'is_empty':
                return empty($field_values);

            case 'is_not_empty':
                return !empty($field_values);

            default:
                return false;
        }
    }

    /**
     * Get conditional config as JSON data attributes for a field.
     *
     * @param array $field Field definition with conditional config.
     * @return string      HTML data attributes string.
     */
    public static function get_conditional_data_attributes(array $field) {
        $conditional = isset($field['conditional']) ? $field['conditional'] : null;

        if (!$conditional || empty($conditional['enabled'])) {
            return '';
        }

        $data = array(
            'enabled' => true,
            'action'  => isset($conditional['action']) ? $conditional['action'] : 'show',
            'logic'   => isset($conditional['logic']) ? $conditional['logic'] : 'all',
            'rules'   => isset($conditional['rules']) ? $conditional['rules'] : array(),
        );

        return sprintf(
            'data-conditional="%s"',
            esc_attr(wp_json_encode($data))
        );
    }

    /**
     * Get fields that can be used as conditional sources (exclude display-only types).
     *
     * @param array $fields All form fields.
     * @return array        Fields that can be used in conditional rules.
     */
    public static function get_conditional_source_fields(array $fields) {
        $excluded_types = array('section', 'paragraph', 'hidden');
        $source_fields = array();

        foreach ($fields as $field) {
            $type = isset($field['type']) ? $field['type'] : '';
            if (!in_array($type, $excluded_types)) {
                $source_fields[] = $field;
            }
        }

        return $source_fields;
    }

    /**
     * Validate conditional configuration for a field.
     *
     * @param array $conditional The conditional configuration to validate.
     * @param array $all_fields  All form fields for reference validation.
     * @return array|true        True if valid, array of errors if invalid.
     */
    public static function validate_conditional_config(array $conditional, array $all_fields = array()) {
        $errors = array();

        // Check if enabled but no rules
        if (!empty($conditional['enabled']) && empty($conditional['rules'])) {
            $errors[] = __('Conditional logic is enabled but no rules are defined.', 'mls-listings-display');
        }

        // Validate each rule
        if (!empty($conditional['rules'])) {
            $field_ids = wp_list_pluck($all_fields, 'id');

            foreach ($conditional['rules'] as $index => $rule) {
                // Check if field exists
                if (empty($rule['field_id'])) {
                    $errors[] = sprintf(
                        __('Rule %d: No source field selected.', 'mls-listings-display'),
                        $index + 1
                    );
                } elseif (!empty($all_fields) && !in_array($rule['field_id'], $field_ids)) {
                    $errors[] = sprintf(
                        __('Rule %d: Source field does not exist.', 'mls-listings-display'),
                        $index + 1
                    );
                }

                // Check operator
                if (empty($rule['operator']) || !isset(self::$operators[$rule['operator']])) {
                    $errors[] = sprintf(
                        __('Rule %d: Invalid operator.', 'mls-listings-display'),
                        $index + 1
                    );
                }

                // Check value requirement for certain operators
                $value_required_operators = array(
                    'equals', 'not_equals', 'contains', 'not_contains',
                    'greater_than', 'less_than', 'starts_with', 'ends_with'
                );
                if (isset($rule['operator']) &&
                    in_array($rule['operator'], $value_required_operators) &&
                    (!isset($rule['value']) || $rule['value'] === '')) {
                    $errors[] = sprintf(
                        __('Rule %d: Value is required for this operator.', 'mls-listings-display'),
                        $index + 1
                    );
                }
            }
        }

        // Validate action
        if (!empty($conditional['action']) && !isset(self::$actions[$conditional['action']])) {
            $errors[] = __('Invalid conditional action.', 'mls-listings-display');
        }

        // Validate logic
        if (!empty($conditional['logic']) && !isset(self::$logic_operators[$conditional['logic']])) {
            $errors[] = __('Invalid logic operator.', 'mls-listings-display');
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Detect circular dependencies in conditional rules.
     *
     * @param array $fields All form fields.
     * @return array        Array of circular dependency paths found.
     */
    public static function detect_circular_dependencies(array $fields) {
        $graph = self::build_dependency_graph($fields);
        $circular_paths = array();

        foreach (array_keys($graph) as $start_field) {
            $visited = array();
            $path = array();
            self::dfs_detect_cycle($graph, $start_field, $visited, $path, $circular_paths);
        }

        return array_unique($circular_paths);
    }

    /**
     * Depth-first search to detect cycles in dependency graph.
     *
     * @param array  $graph          The dependency graph.
     * @param string $node           Current node being visited.
     * @param array  $visited        Set of visited nodes.
     * @param array  $path           Current path being explored.
     * @param array  $circular_paths Array to collect circular dependency descriptions.
     */
    private static function dfs_detect_cycle(array $graph, string $node, array &$visited, array &$path, array &$circular_paths) {
        if (in_array($node, $path)) {
            // Found a cycle
            $cycle_start = array_search($node, $path);
            $cycle = array_slice($path, $cycle_start);
            $cycle[] = $node;
            $circular_paths[] = implode(' -> ', $cycle);
            return;
        }

        if (in_array($node, $visited)) {
            return;
        }

        $visited[] = $node;
        $path[] = $node;

        if (isset($graph[$node])) {
            foreach ($graph[$node] as $dependency) {
                self::dfs_detect_cycle($graph, $dependency, $visited, $path, $circular_paths);
            }
        }

        array_pop($path);
    }

    /**
     * Get conditional logic configuration for JavaScript.
     *
     * @param array $fields Form fields with conditional config.
     * @return array        Simplified config for frontend use.
     */
    public static function get_frontend_config(array $fields) {
        $config = array();

        foreach ($fields as $field) {
            $field_id = isset($field['id']) ? $field['id'] : '';
            $conditional = isset($field['conditional']) ? $field['conditional'] : null;

            if (!$field_id || !$conditional || empty($conditional['enabled'])) {
                continue;
            }

            $config[$field_id] = array(
                'action' => isset($conditional['action']) ? $conditional['action'] : 'show',
                'logic'  => isset($conditional['logic']) ? $conditional['logic'] : 'all',
                'rules'  => isset($conditional['rules']) ? $conditional['rules'] : array(),
            );
        }

        return $config;
    }
}
