<?php
/**
 * MLD Admin Menu
 * Handles admin dashboard menu for manual data imports
 *
 * @package MLS_Listings_Display
 * @since 4.3.0
 */

class MLD_Admin_Menu {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX handlers for import actions
        add_action('wp_ajax_mld_import_schools', array($this, 'ajax_import_schools'));
        add_action('wp_ajax_mld_import_boundaries', array($this, 'ajax_import_boundaries'));
        add_action('wp_ajax_mld_get_statistics', array($this, 'ajax_get_statistics'));
        add_action('wp_ajax_mld_clear_data', array($this, 'ajax_clear_data'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'MLS Data Import',
            'MLS Data Import',
            'manage_options',
            'mld-data-import',
            array($this, 'render_import_page'),
            'dashicons-download',
            30
        );

        // Submenu for schools
        add_submenu_page(
            'mld-data-import',
            'Import Schools',
            'Schools',
            'manage_options',
            'mld-data-import',
            array($this, 'render_import_page')
        );


        // Submenu for city boundaries
        add_submenu_page(
            'mld-data-import',
            'Import City Boundaries',
            'City Boundaries',
            'manage_options',
            'mld-import-boundaries',
            array($this, 'render_boundaries_page')
        );

        // Submenu for data statistics
        add_submenu_page(
            'mld-data-import',
            'Data Statistics',
            'Statistics',
            'manage_options',
            'mld-data-stats',
            array($this, 'render_stats_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'mld-') === false) {
            return;
        }

        wp_enqueue_style('mld-admin-style', MLD_PLUGIN_URL . 'assets/css/admin.css', array(), MLD_VERSION);
        wp_enqueue_script('mld-admin-script', MLD_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), MLD_VERSION, true);

        wp_localize_script('mld-admin-script', 'mldAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_admin_nonce')
        ));
    }

    /**
     * Render the main import page (Schools)
     */
    public function render_import_page() {
        global $wpdb;

        // Get current school count
        $schools_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_schools");

        ?>
        <div class="wrap">
            <h1>Import Schools Data</h1>

            <div class="mld-admin-container">
                <div class="mld-stats-box">
                    <h3>Current Data</h3>
                    <p>Total Schools: <strong><?php echo number_format($schools_count); ?></strong></p>
                </div>

                <div class="mld-import-section">
                    <h2>Import Schools by State</h2>
                    <p>Select a state to import school data from OpenStreetMap.</p>

                    <form id="mld-import-schools" method="post" action="#">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Select State</th>
                                <td>
                                    <?php echo $this->get_state_dropdown('schools-state'); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Import Options</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="clear_existing" value="1">
                                        Clear existing schools before import
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="include_private" value="1" checked>
                                        Include private schools
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="include_preschools" value="1" checked>
                                        Include preschools
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="import-schools-btn">
                                Import Schools
                            </button>
                            <span class="spinner" style="float: none;"></span>
                        </p>
                    </form>

                    <div id="schools-progress" class="mld-import-progress">
                        <h3>Import Progress</h3>
                        <div class="progress-bar">
                            <div class="progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <p class="progress-text"></p>
                    </div>

                    <div id="import-results" style="display: none;">
                        <h3>Import Results</h3>
                        <div class="results-content"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Render the city boundaries import page
     */
    public function render_boundaries_page() {
        global $wpdb;

        // Get current boundaries count
        $boundaries_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_city_boundaries");

        ?>
        <div class="wrap">
            <h1>Import City Boundaries</h1>

            <div class="mld-admin-container">
                <div class="mld-stats-box">
                    <h3>Current Data</h3>
                    <p>Total City Boundaries: <strong><?php echo number_format($boundaries_count); ?></strong></p>
                </div>

                <div class="mld-import-section">
                    <h2>Import City Boundaries by State</h2>
                    <p>Select a state to import city boundary data from OpenStreetMap.</p>

                    <form id="mld-import-boundaries" method="post" action="#">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Select State</th>
                                <td>
                                    <?php echo $this->get_state_dropdown('boundaries-state'); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Import Options</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="clear_existing" value="1">
                                        Clear existing boundaries before import
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="major_cities_only" value="1">
                                        Major cities only (population > 10,000)
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="import-boundaries-btn">
                                Import City Boundaries
                            </button>
                            <span class="spinner" style="float: none;"></span>
                        </p>
                    </form>

                    <div id="boundaries-progress" class="mld-import-progress">
                        <h3>Import Progress</h3>
                        <div class="progress-bar">
                            <div class="progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <p class="progress-text"></p>
                    </div>

                    <div id="import-results" style="display: none;">
                        <h3>Import Results</h3>
                        <div class="results-content"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the statistics page
     */
    public function render_stats_page() {
        global $wpdb;

        // Get statistics
        $schools_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_schools");
        $boundaries_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_city_boundaries");

        // Get breakdown by type
        $schools_by_type = $wpdb->get_results("
            SELECT school_level, COUNT(*) as count
            FROM {$wpdb->prefix}mld_schools
            GROUP BY school_level
            ORDER BY count DESC
        ");

        ?>
        <div class="wrap">
            <h1>MLS Data Statistics</h1>

            <div class="mld-admin-container">
                <div class="mld-stats-grid">
                    <div class="mld-stats-box">
                        <h3>Schools</h3>
                        <p class="stat-number"><?php echo number_format($schools_count); ?></p>
                        <?php if ($schools_by_type): ?>
                            <ul class="stat-breakdown">
                                <?php foreach ($schools_by_type as $type): ?>
                                    <li><?php echo ucfirst($type->school_level); ?>: <?php echo number_format($type->count); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>


                    <div class="mld-stats-box">
                        <h3>City Boundaries</h3>
                        <p class="stat-number"><?php echo number_format($boundaries_count); ?></p>
                    </div>
                </div>

                <div class="mld-actions-box">
                    <h3>Quick Actions</h3>
                    <p>
                        <button class="button" onclick="if(confirm('This will clear all schools data. Are you sure?')) { mldAdmin.clearData('schools'); }">
                            Clear All Schools
                        </button>
                        <button class="button" onclick="if(confirm('This will clear all city boundaries. Are you sure?')) { mldAdmin.clearData('boundaries'); }">
                            Clear All Boundaries
                        </button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get state dropdown HTML
     */
    private function get_state_dropdown($name = 'state') {
        $states = array(
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming'
        );

        $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="regular-text">';
        $html .= '<option value="">Select a State</option>';
        foreach ($states as $code => $state) {
            $selected = ($code === 'MA') ? ' selected' : '';
            $html .= '<option value="' . esc_attr($code) . '"' . $selected . '>' . esc_html($state) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * AJAX handler for importing schools
     */
    public function ajax_import_schools() {
        // Check nonce
        if (!check_ajax_referer('mld_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';
        $clear_existing = isset($_POST['clear_existing']) && $_POST['clear_existing'] === '1';

        if (empty($state)) {
            wp_send_json_error('Please select a state');
        }

        // Start the import process
        $result = $this->import_schools_for_state($state, $clear_existing);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Import schools for a specific state
     */
    private function import_schools_for_state($state_code, $clear_existing = false) {
        // Load the data fetcher
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-data-fetcher.php';

        $fetcher = new MLD_Data_Fetcher();

        // Fetch schools with options
        $options = [
            'clear_existing' => $clear_existing,
            'include_private' => true,
            'include_preschools' => true
        ];

        $result = $fetcher->fetch_schools($state_code, $options);

        if ($result && $result['success']) {
            // Build detailed results HTML
            $html = '<div class="import-summary">';
            $html .= '<h4>Import Summary</h4>';
            $html .= '<ul>';
            $html .= '<li><strong>Total Imported:</strong> ' . $result['imported'] . ' schools</li>';
            $html .= '<li><strong>Duplicates Skipped:</strong> ' . $result['skipped'] . '</li>';
            if (!empty($result['errors'])) {
                $html .= '<li><strong>Errors:</strong> ' . count($result['errors']) . '</li>';
            }
            $html .= '</ul>';

            // Add error summary if present
            if (!empty($result['error_summary'])) {
                $html .= $result['error_summary'];
            }

            if (!empty($result['items'])) {
                $html .= '<h4>Sample of Imported Schools:</h4>';
                $html .= '<table class="widefat">';
                $html .= '<thead><tr><th>Name</th><th>Type</th><th>City</th><th>Address</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($result['items'] as $school) {
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($school['name']) . '</td>';
                    $html .= '<td>' . esc_html(ucfirst($school['type'])) . '</td>';
                    $html .= '<td>' . esc_html($school['city']) . '</td>';
                    $html .= '<td>' . esc_html($school['address']) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                if ($result['imported'] > 10) {
                    $html .= '<p><em>Showing first 10 of ' . $result['imported'] . ' imported schools</em></p>';
                }
            }
            $html .= '</div>';

            return array(
                'success' => true,
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'message' => "Successfully imported {$result['imported']} schools for {$state_code}",
                'details_html' => $html
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to import schools for ' . $state_code
            );
        }
    }


    /**
     * AJAX handler for importing city boundaries
     */
    public function ajax_import_boundaries() {
        // Check nonce
        if (!check_ajax_referer('mld_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';

        if (empty($state)) {
            wp_send_json_error('Please select a state');
        }

        // This would need implementation for city boundaries import
        wp_send_json_success(array(
            'imported' => 0,
            'message' => "City boundaries import for {$state} - Coming soon!"
        ));
    }

    /**
     * AJAX handler for getting statistics
     */
    public function ajax_get_statistics() {
        // Check nonce
        if (!check_ajax_referer('mld_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        global $wpdb;

        $schools_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_schools");
        $boundaries_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_city_boundaries");

        wp_send_json_success(array(
            'schools' => $schools_count ?: 0,
            'boundaries' => $boundaries_count ?: 0,
            'last_import' => array(
                'schools' => get_option('mld_last_schools_import', ''),
                'boundaries' => get_option('mld_last_boundaries_import', '')
            )
        ));
    }

    /**
     * AJAX handler for clearing data
     */
    public function ajax_clear_data() {
        // Check nonce
        if (!check_ajax_referer('mld_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $data_type = isset($_POST['data_type']) ? sanitize_text_field($_POST['data_type']) : '';

        if (empty($data_type)) {
            wp_send_json_error('Invalid data type');
        }

        global $wpdb;

        switch ($data_type) {
            case 'schools':
                $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mld_schools");
                $message = 'All schools data has been cleared.';
                break;


            case 'boundaries':
                $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mld_city_boundaries");
                $message = 'All city boundaries data has been cleared.';
                break;

            default:
                wp_send_json_error('Invalid data type');
                return;
        }

        wp_send_json_success(array(
            'message' => $message
        ));
    }
}