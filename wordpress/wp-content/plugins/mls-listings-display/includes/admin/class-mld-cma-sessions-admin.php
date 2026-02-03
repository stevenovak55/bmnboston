<?php
/**
 * MLD CMA Sessions Admin Page
 *
 * Admin page for viewing and managing all CMA sessions (property-based and standalone).
 *
 * @package MLS_Listings_Display
 * @subpackage Admin
 * @since 6.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Sessions_Admin {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Items per page
     */
    const ITEMS_PER_PAGE = 20;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_mld_admin_delete_cma_session', array($this, 'ajax_delete_session'));
        add_action('wp_ajax_mld_admin_assign_cma_session', array($this, 'ajax_assign_session'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'CMA Sessions',
            'CMA Sessions',
            'manage_options',
            'mld-cma-sessions',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'mld-cma-sessions') === false) {
            return;
        }

        wp_enqueue_style(
            'mld-cma-sessions-admin',
            MLD_PLUGIN_URL . 'assets/css/admin-cma-sessions.css',
            array(),
            MLD_VERSION
        );

        wp_enqueue_script(
            'mld-cma-sessions-admin',
            MLD_PLUGIN_URL . 'assets/js/admin-cma-sessions.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        wp_localize_script('mld-cma-sessions-admin', 'mldCMASessionsAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_cma_sessions_admin_nonce'),
            'confirmDelete' => 'Are you sure you want to delete this CMA session?',
        ));
    }

    /**
     * Render the admin page
     */
    public function render_page() {
        // Include sessions class
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-sessions.php';

        // Get filter parameters
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';
        $user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        // Validate order
        $order = in_array($order, array('ASC', 'DESC')) ? $order : 'DESC';
        $orderby = in_array($orderby, array('created_at', 'updated_at', 'session_name', 'estimated_value_mid')) ? $orderby : 'created_at';

        // Build query args
        $args = array(
            'type' => $type_filter,
            'search' => $search,
            'order_by' => $orderby,
            'order' => $order,
            'limit' => self::ITEMS_PER_PAGE,
            'offset' => ($paged - 1) * self::ITEMS_PER_PAGE,
        );

        if ($user_filter > 0) {
            $args['user_id'] = $user_filter;
        }

        // Get sessions
        $sessions = MLD_CMA_Sessions::get_all_sessions($args);
        $total_items = MLD_CMA_Sessions::get_all_sessions_count($args);
        $total_pages = ceil($total_items / self::ITEMS_PER_PAGE);

        // Get counts by type
        $count_all = MLD_CMA_Sessions::get_all_sessions_count(array('type' => 'all'));
        $count_property = MLD_CMA_Sessions::get_all_sessions_count(array('type' => 'property'));
        $count_standalone = MLD_CMA_Sessions::get_all_sessions_count(array('type' => 'standalone'));

        // Get users with sessions for filter dropdown
        $users_with_sessions = $this->get_users_with_sessions();

        ?>
        <div class="wrap mld-cma-sessions-admin">
            <h1>CMA Sessions</h1>

            <p class="description">View and manage all saved CMA sessions. Property CMAs are linked to MLS listings, while Standalone CMAs are for manual property entries.</p>

            <!-- Filter Tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url(add_query_arg('type', 'all', remove_query_arg(array('paged')))); ?>"
                       class="<?php echo $type_filter === 'all' ? 'current' : ''; ?>">
                        All <span class="count">(<?php echo esc_html($count_all); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('type', 'property', remove_query_arg(array('paged')))); ?>"
                       class="<?php echo $type_filter === 'property' ? 'current' : ''; ?>">
                        Property CMAs <span class="count">(<?php echo esc_html($count_property); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('type', 'standalone', remove_query_arg(array('paged')))); ?>"
                       class="<?php echo $type_filter === 'standalone' ? 'current' : ''; ?>">
                        Standalone CMAs <span class="count">(<?php echo esc_html($count_standalone); ?>)</span>
                    </a>
                </li>
            </ul>

            <!-- Search and Filter Form -->
            <form method="get" class="mld-cma-filter-form">
                <input type="hidden" name="page" value="mld-cma-sessions">
                <input type="hidden" name="type" value="<?php echo esc_attr($type_filter); ?>">

                <div class="mld-cma-filters">
                    <label>
                        <span class="screen-reader-text">Filter by User</span>
                        <select name="user_id">
                            <option value="0">All Users</option>
                            <option value="-1" <?php selected($user_filter, -1); ?>>Anonymous</option>
                            <?php foreach ($users_with_sessions as $user) : ?>
                                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($user_filter, $user->ID); ?>>
                                    <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span class="screen-reader-text">Search</span>
                        <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="Search by name or address...">
                    </label>

                    <input type="submit" class="button" value="Filter">

                    <?php if ($search || $user_filter) : ?>
                        <a href="<?php echo esc_url(add_query_arg('type', $type_filter, admin_url('admin.php?page=mld-cma-sessions'))); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Sessions Table -->
            <table class="wp-list-table widefat fixed striped mld-cma-sessions-table">
                <thead>
                    <tr>
                        <th scope="col" class="column-id" style="width: 50px;">ID</th>
                        <th scope="col" class="column-type" style="width: 100px;">Type</th>
                        <th scope="col" class="column-name">
                            <?php echo $this->get_sortable_column_header('session_name', 'Name', $orderby, $order); ?>
                        </th>
                        <th scope="col" class="column-property">Subject Property</th>
                        <th scope="col" class="column-user">Created By</th>
                        <th scope="col" class="column-created">
                            <?php echo $this->get_sortable_column_header('created_at', 'Created', $orderby, $order); ?>
                        </th>
                        <th scope="col" class="column-value" style="width: 100px;">
                            <?php echo $this->get_sortable_column_header('estimated_value_mid', 'Est. Value', $orderby, $order); ?>
                        </th>
                        <th scope="col" class="column-actions" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)) : ?>
                        <tr>
                            <td colspan="8" class="mld-cma-no-items">No CMA sessions found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($sessions as $session) : ?>
                            <?php
                            $is_standalone = !empty($session['is_standalone']);
                            $is_anonymous = empty($session['user_id']) || $session['user_id'] == 0;
                            $user_info = $is_anonymous ? 'Anonymous' : $this->get_user_display($session['user_id']);

                            // Subject property display
                            if ($is_standalone) {
                                $subject_data = $session['subject_property_data'] ?? array();
                                $property_display = esc_html($subject_data['address'] ?? 'N/A');
                                if (!empty($subject_data['city'])) {
                                    $property_display .= ', ' . esc_html($subject_data['city']);
                                }
                                $property_display .= '<br><small>Slug: ' . esc_html($session['standalone_slug'] ?? 'N/A') . '</small>';
                            } else {
                                $property_display = esc_html($session['subject_listing_id'] ?? 'N/A');
                            }

                            // Format value
                            $value = !empty($session['estimated_value_mid']) ? '$' . number_format($session['estimated_value_mid']) : '--';

                            // View URL
                            if ($is_standalone && !empty($session['standalone_slug'])) {
                                $view_url = home_url('/cma/' . $session['standalone_slug'] . '/');
                            } else {
                                $view_url = home_url('/property/' . $session['subject_listing_id'] . '/?load_cma=' . $session['id']);
                            }
                            ?>
                            <tr data-session-id="<?php echo esc_attr($session['id']); ?>">
                                <td class="column-id"><?php echo esc_html($session['id']); ?></td>
                                <td class="column-type">
                                    <?php if ($is_standalone) : ?>
                                        <span class="mld-cma-badge mld-cma-badge-standalone">Standalone</span>
                                    <?php else : ?>
                                        <span class="mld-cma-badge mld-cma-badge-property">Property</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-name">
                                    <strong><?php echo esc_html($session['session_name']); ?></strong>
                                    <?php if (!empty($session['description'])) : ?>
                                        <br><small class="description"><?php echo esc_html(wp_trim_words($session['description'], 10)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="column-property"><?php echo $property_display; ?></td>
                                <td class="column-user">
                                    <?php echo $user_info; ?>
                                    <?php if ($is_anonymous && $is_standalone) : ?>
                                        <br><button type="button" class="button-link mld-assign-session-btn" data-session-id="<?php echo esc_attr($session['id']); ?>">Assign to user</button>
                                    <?php endif; ?>
                                </td>
                                <td class="column-created">
                                    <?php echo esc_html(wp_date('M j, Y', strtotime($session['created_at']))); ?>
                                    <br><small><?php echo esc_html(wp_date('g:i a', strtotime($session['created_at']))); ?></small>
                                </td>
                                <td class="column-value"><?php echo esc_html($value); ?></td>
                                <td class="column-actions">
                                    <a href="<?php echo esc_url($view_url); ?>" target="_blank" class="button button-small">View</a>
                                    <button type="button" class="button button-small mld-delete-session-btn" data-session-id="<?php echo esc_attr($session['id']); ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
                        <?php
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                        );
                        echo paginate_links($pagination_args);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assign User Modal -->
        <div id="mld-assign-user-modal" class="mld-admin-modal" style="display: none;">
            <div class="mld-admin-modal-content">
                <h3>Assign CMA to User</h3>
                <p>Select a user to assign this anonymous CMA to:</p>
                <input type="hidden" id="assign-session-id" value="">
                <select id="assign-user-select">
                    <option value="">Select a user...</option>
                    <?php
                    $all_users = get_users(array('orderby' => 'display_name'));
                    foreach ($all_users as $user) :
                    ?>
                        <option value="<?php echo esc_attr($user->ID); ?>">
                            <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mld-admin-modal-actions">
                    <button type="button" class="button button-primary" id="confirm-assign-btn">Assign</button>
                    <button type="button" class="button" id="cancel-assign-btn">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get sortable column header HTML
     */
    private function get_sortable_column_header($column, $label, $current_orderby, $current_order) {
        $is_current = ($current_orderby === $column);
        $new_order = ($is_current && $current_order === 'ASC') ? 'DESC' : 'ASC';
        $url = add_query_arg(array('orderby' => $column, 'order' => $new_order));

        $class = 'sortable';
        if ($is_current) {
            $class .= ' sorted ' . strtolower($current_order);
        }

        $indicator = '';
        if ($is_current) {
            $indicator = $current_order === 'ASC' ? ' &#9650;' : ' &#9660;';
        }

        return '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . $indicator . '</a>';
    }

    /**
     * Get users who have CMA sessions
     */
    private function get_users_with_sessions() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_cma_saved_sessions';

        $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$table_name} WHERE user_id > 0");

        if (empty($user_ids)) {
            return array();
        }

        return get_users(array(
            'include' => $user_ids,
            'orderby' => 'display_name',
        ));
    }

    /**
     * Get user display string
     */
    private function get_user_display($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return 'Unknown';
        }
        return esc_html($user->display_name) . '<br><small>' . esc_html($user->user_email) . '</small>';
    }

    /**
     * AJAX handler for deleting a session
     */
    public function ajax_delete_session() {
        check_ajax_referer('mld_cma_sessions_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $session_id = intval($_POST['session_id'] ?? 0);
        if (!$session_id) {
            wp_send_json_error(array('message' => 'Invalid session ID'));
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_cma_saved_sessions';

        $result = $wpdb->delete($table_name, array('id' => $session_id), array('%d'));

        if ($result) {
            wp_send_json_success(array('message' => 'Session deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete session'));
        }
    }

    /**
     * AJAX handler for assigning a session to a user
     */
    public function ajax_assign_session() {
        check_ajax_referer('mld_cma_sessions_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $session_id = intval($_POST['session_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);

        if (!$session_id || !$user_id) {
            wp_send_json_error(array('message' => 'Invalid session or user ID'));
            return;
        }

        require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-sessions.php';

        $result = MLD_CMA_Sessions::assign_session_to_user($session_id, $user_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        wp_send_json_success(array('message' => 'Session assigned successfully'));
    }
}

// Initialize
add_action('plugins_loaded', function() {
    MLD_CMA_Sessions_Admin::get_instance();
}, 25);
