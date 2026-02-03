<?php
/**
 * City Boundaries Diagnostic Tool
 * Helps diagnose why boundaries aren't being saved
 *
 * @package MLS_Listings_Display
 * @since 4.4.1
 */

class MLD_Boundary_Diagnostics {

    /**
     * Run comprehensive diagnostics
     */
    public static function run_diagnostics() {
        global $wpdb;

        $results = [];
        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        // 1. Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $results['table_exists'] = $table_exists;

        if (!$table_exists) {
            $results['error'] = 'Table does not exist';
            return $results;
        }

        // 2. Get table structure
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $results['columns'] = [];
        foreach ($columns as $column) {
            $results['columns'][$column->Field] = [
                'type' => $column->Type,
                'null' => $column->Null,
                'key' => $column->Key,
                'default' => $column->Default
            ];
        }

        // 3. Check for required columns
        $required_columns = [
            'city', 'state', 'country', 'boundary_type',
            'boundary_data', 'bbox_north', 'bbox_south',
            'bbox_east', 'bbox_west', 'fetched_at', 'last_used'
        ];

        $missing_columns = [];
        foreach ($required_columns as $col) {
            if (!isset($results['columns'][$col])) {
                $missing_columns[] = $col;
            }
        }
        $results['missing_columns'] = $missing_columns;

        // 4. Check if display_name column exists (optional but important)
        $results['has_display_name'] = isset($results['columns']['display_name']);

        // 5. Get record count
        $results['record_count'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        // 6. Check indexes
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
        $results['indexes'] = [];
        foreach ($indexes as $index) {
            $results['indexes'][] = $index->Key_name . ' (' . $index->Column_name . ')';
        }

        // 7. Test insert capability
        $test_data = [
            'city' => 'Test_City_' . time(),
            'state' => 'Test_State',
            'country' => 'USA',
            'boundary_type' => 'test',
            'boundary_data' => '{"test": true}',
            'bbox_north' => 42.0,
            'bbox_south' => 41.0,
            'bbox_east' => -70.0,
            'bbox_west' => -71.0
        ];

        // Only add display_name if column exists
        if ($results['has_display_name']) {
            $test_data['display_name'] = 'Test Display Name';
        }

        $insert_result = $wpdb->insert($table_name, $test_data);
        $results['can_insert'] = ($insert_result !== false);

        if (!$results['can_insert']) {
            $results['insert_error'] = $wpdb->last_error;
        } else {
            // Clean up test record
            $wpdb->delete($table_name, ['city' => $test_data['city']]);
        }

        // 8. Check WordPress database error
        $results['last_db_error'] = $wpdb->last_error;

        return $results;
    }

    /**
     * Output diagnostics as HTML
     */
    public static function display_diagnostics() {
        $diagnostics = self::run_diagnostics();

        echo '<div class="mld-diagnostics">';
        echo '<h2>City Boundaries Database Diagnostics</h2>';

        // Table existence
        echo '<h3>Table Status</h3>';
        if ($diagnostics['table_exists']) {
            echo '<p>✅ Table exists</p>';
        } else {
            echo '<p>❌ Table does not exist!</p>';
            echo '</div>';
            return;
        }

        // Record count
        echo '<p>Records in table: <strong>' . $diagnostics['record_count'] . '</strong></p>';

        // Missing columns
        if (!empty($diagnostics['missing_columns'])) {
            echo '<h3>❌ Missing Required Columns</h3>';
            echo '<ul>';
            foreach ($diagnostics['missing_columns'] as $col) {
                echo '<li>' . esc_html($col) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<h3>✅ All Required Columns Present</h3>';
        }

        // Display name column
        if ($diagnostics['has_display_name']) {
            echo '<p>✅ display_name column exists</p>';
        } else {
            echo '<p>⚠️ display_name column missing (optional but recommended)</p>';
        }

        // Insert capability
        echo '<h3>Write Capability</h3>';
        if ($diagnostics['can_insert']) {
            echo '<p>✅ Can insert records</p>';
        } else {
            echo '<p>❌ Cannot insert records!</p>';
            echo '<p>Error: ' . esc_html($diagnostics['insert_error']) . '</p>';
        }

        // Table structure
        echo '<h3>Table Structure</h3>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th></tr></thead>';
        echo '<tbody>';
        foreach ($diagnostics['columns'] as $name => $info) {
            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($info['type']) . '</td>';
            echo '<td>' . esc_html($info['null']) . '</td>';
            echo '<td>' . esc_html($info['key']) . '</td>';
            echo '<td>' . esc_html($info['default'] ?? 'NULL') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Indexes
        echo '<h3>Indexes</h3>';
        echo '<ul>';
        foreach ($diagnostics['indexes'] as $index) {
            echo '<li>' . esc_html($index) . '</li>';
        }
        echo '</ul>';

        echo '</div>';
    }

    /**
     * Fix table structure issues
     */
    public static function fix_table_structure() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';
        $messages = [];

        // Check if display_name column exists
        $has_display_name = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'display_name'");

        if (!$has_display_name) {
            // Add display_name column
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN display_name VARCHAR(255) AFTER boundary_type");

            if ($result !== false) {
                $messages[] = '✅ Added display_name column';
            } else {
                $messages[] = '❌ Failed to add display_name column: ' . $wpdb->last_error;
            }
        }

        // Check if boundary_type column has correct length
        $boundary_type_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name WHERE Field = 'boundary_type'");

        if ($boundary_type_info && strpos($boundary_type_info->Type, 'varchar(20)') !== false) {
            // Update to varchar(50) to match our schema
            $result = $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN boundary_type VARCHAR(50) DEFAULT 'city'");

            if ($result !== false) {
                $messages[] = '✅ Updated boundary_type column length';
            } else {
                $messages[] = '❌ Failed to update boundary_type column: ' . $wpdb->last_error;
            }
        }

        return $messages;
    }
}