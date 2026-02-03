<?php
/**
 * WordPress Admin interface for exclusive listings
 *
 * @package Exclusive_Listings
 * @since 1.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Admin
 *
 * Handles WordPress admin pages for managing exclusive listings.
 * Provides list view, add/edit forms, and photo management.
 */
class EL_Admin {

    /**
     * BME sync service
     * @var EL_BME_Sync
     */
    private $bme_sync;

    /**
     * Image handler
     * @var EL_Image_Handler
     */
    private $image_handler;

    /**
     * Admin page hook
     * @var string
     */
    private $page_hook;

    /**
     * Constructor
     */
    public function __construct() {
        $this->bme_sync = new EL_BME_Sync();
        $this->image_handler = new EL_Image_Handler();

        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('wp_ajax_el_upload_photo', array($this, 'ajax_upload_photo'));
        add_action('wp_ajax_el_delete_photo', array($this, 'ajax_delete_photo'));
        add_action('wp_ajax_el_reorder_photos', array($this, 'ajax_reorder_photos'));
    }

    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        $this->page_hook = add_menu_page(
            'Exclusive Listings',
            'Exclusive Listings',
            'edit_posts',
            'exclusive-listings',
            array($this, 'render_list_page'),
            'dashicons-building',
            25
        );

        add_submenu_page(
            'exclusive-listings',
            'All Listings',
            'All Listings',
            'edit_posts',
            'exclusive-listings',
            array($this, 'render_list_page')
        );

        add_submenu_page(
            'exclusive-listings',
            'Add New',
            'Add New',
            'edit_posts',
            'exclusive-listings-add',
            array($this, 'render_add_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'exclusive-listings') === false) {
            return;
        }

        // Enqueue WordPress media uploader
        wp_enqueue_media();

        // Enqueue jQuery UI for sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Admin styles
        wp_enqueue_style(
            'exclusive-listings-admin',
            EL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EL_VERSION
        );

        // Admin scripts
        wp_enqueue_script(
            'exclusive-listings-admin',
            EL_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-sortable'),
            EL_VERSION,
            true
        );

        wp_localize_script('exclusive-listings-admin', 'elAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('el_admin_nonce'),
            'maxPhotos' => EL_Image_Handler::MAX_PHOTOS,
            'maxFileSize' => EL_Image_Handler::MAX_FILE_SIZE,
        ));
    }

    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        if (!isset($_POST['el_action'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['el_nonce'], 'el_listing_action')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to perform this action');
        }

        $action = sanitize_text_field($_POST['el_action']);

        switch ($action) {
            case 'create':
                $this->handle_create();
                break;
            case 'update':
                $this->handle_update();
                break;
            case 'delete':
                $this->handle_delete();
                break;
        }
    }

    /**
     * Handle create listing
     */
    private function handle_create() {
        // Sanitize input
        $data = EL_Validator::sanitize($_POST);

        // Set default status
        if (empty($data['standard_status'])) {
            $data['standard_status'] = 'Active';
        }

        // Validate
        $validation = EL_Validator::validate_create($data);
        if (!$validation['valid']) {
            $this->redirect_with_message('exclusive-listings-add', 'error', 'Validation failed: ' . implode(', ', $validation['errors']));
            return;
        }

        // Generate new ID
        $id_generator = exclusive_listings()->get_id_generator();
        $listing_id = $id_generator->generate();

        if (is_wp_error($listing_id)) {
            $this->redirect_with_message('exclusive-listings-add', 'error', $listing_id->get_error_message());
            return;
        }

        // Generate listing key
        $listing_key = $id_generator->generate_listing_key($listing_id);

        // Add creator info
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = current_time('mysql');

        // Sync to BME tables
        $result = $this->bme_sync->sync_listing($listing_id, $data, $listing_key);

        if (is_wp_error($result)) {
            $this->redirect_with_message('exclusive-listings-add', 'error', $result->get_error_message());
            return;
        }

        // Redirect to edit page with success message
        wp_redirect(admin_url('admin.php?page=exclusive-listings-add&id=' . $listing_id . '&message=created'));
        exit;
    }

    /**
     * Handle update listing
     */
    private function handle_update() {
        $listing_id = intval($_POST['listing_id']);

        if (!$listing_id) {
            $this->redirect_with_message('exclusive-listings', 'error', 'Invalid listing ID');
            return;
        }

        // Get existing listing
        $existing = $this->get_listing_by_id($listing_id);
        if (!$existing) {
            $this->redirect_with_message('exclusive-listings', 'error', 'Listing not found');
            return;
        }

        // Sanitize input
        $data = EL_Validator::sanitize($_POST);

        // Validate
        $validation = EL_Validator::validate_update($data);
        if (!$validation['valid']) {
            $this->redirect_with_message('exclusive-listings-add', 'error', 'Validation failed: ' . implode(', ', $validation['errors']), array('id' => $listing_id));
            return;
        }

        // Check for status change
        $old_status = $existing['standard_status'];
        $new_status = isset($data['standard_status']) ? $data['standard_status'] : $old_status;

        // Merge with existing data
        $merged = array_merge($existing, $data);

        // Sync to BME tables
        $result = $this->bme_sync->sync_listing($listing_id, $merged, $existing['listing_key']);

        if (is_wp_error($result)) {
            $this->redirect_with_message('exclusive-listings-add', 'error', $result->get_error_message(), array('id' => $listing_id));
            return;
        }

        // Archive if status changed to Closed
        if ($old_status !== 'Closed' && $new_status === 'Closed') {
            $this->bme_sync->archive_listing($listing_id);
        }

        wp_redirect(admin_url('admin.php?page=exclusive-listings-add&id=' . $listing_id . '&message=updated'));
        exit;
    }

    /**
     * Handle delete listing
     */
    private function handle_delete() {
        $listing_id = intval($_POST['listing_id']);
        $archive = isset($_POST['archive']) && $_POST['archive'] === '1';

        if (!$listing_id) {
            $this->redirect_with_message('exclusive-listings', 'error', 'Invalid listing ID');
            return;
        }

        if (!$this->bme_sync->listing_exists($listing_id)) {
            $this->redirect_with_message('exclusive-listings', 'error', 'Listing not found');
            return;
        }

        if ($archive) {
            $result = $this->bme_sync->archive_listing($listing_id);
            $message = 'archived';
        } else {
            $this->image_handler->delete_all_photos($listing_id);
            $result = $this->bme_sync->delete_listing($listing_id);
            $message = 'deleted';
        }

        if (is_wp_error($result)) {
            $this->redirect_with_message('exclusive-listings', 'error', $result->get_error_message());
            return;
        }

        $this->redirect_with_message('exclusive-listings', 'success', 'Listing ' . $message . ' successfully');
    }

    /**
     * Render the list page
     */
    public function render_list_page() {
        global $wpdb;

        // Handle single item actions (from row action links)
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'el_delete_' . $_GET['id'])) {
                $listing_id = intval($_GET['id']);
                $this->bme_sync->archive_listing($listing_id);
                echo '<div class="notice notice-success"><p>Listing archived successfully.</p></div>';
            }
        }

        // Handle bulk actions
        $bulk_message = $this->handle_bulk_actions();

        // Get filter parameters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Build query
        $where = "WHERE listing_id < " . EL_EXCLUSIVE_ID_THRESHOLD;

        if ($status_filter) {
            $where .= $wpdb->prepare(" AND standard_status = %s", $status_filter);
        }

        if ($search) {
            $where .= $wpdb->prepare(
                " AND (unparsed_address LIKE %s OR city LIKE %s OR listing_id = %d)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                intval($search)
            );
        }

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table} {$where}");

        // Get listings
        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$summary_table} {$where}
             ORDER BY modification_timestamp DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        // Get status counts for filter tabs
        $status_counts = $wpdb->get_results(
            "SELECT standard_status, COUNT(*) as count
             FROM {$summary_table}
             WHERE listing_id < " . EL_EXCLUSIVE_ID_THRESHOLD . "
             GROUP BY standard_status",
            OBJECT_K
        );

        // Calculate pagination
        $total_pages = ceil($total / $per_page);

        // Display message if present
        if (isset($_GET['message'])) {
            $message_type = $_GET['message'] === 'error' ? 'error' : 'success';
            $message_text = isset($_GET['msg']) ? sanitize_text_field($_GET['msg']) : 'Operation completed successfully.';
            echo '<div class="notice notice-' . $message_type . '"><p>' . esc_html($message_text) . '</p></div>';
        }

        // Display bulk action message
        if ($bulk_message) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($bulk_message) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Exclusive Listings</h1>
            <a href="<?php echo admin_url('admin.php?page=exclusive-listings-add'); ?>" class="page-title-action">Add New</a>

            <!-- Filter tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo admin_url('admin.php?page=exclusive-listings'); ?>" <?php echo empty($status_filter) ? 'class="current"' : ''; ?>>
                        All <span class="count">(<?php echo esc_html($total); ?>)</span>
                    </a> |
                </li>
                <?php foreach (EL_Validator::get_statuses() as $status): ?>
                    <?php $count = isset($status_counts[$status]) ? $status_counts[$status]->count : 0; ?>
                    <?php if ($count > 0): ?>
                        <li>
                            <a href="<?php echo admin_url('admin.php?page=exclusive-listings&status=' . urlencode($status)); ?>"
                               <?php echo $status_filter === $status ? 'class="current"' : ''; ?>>
                                <?php echo esc_html($status); ?> <span class="count">(<?php echo esc_html($count); ?>)</span>
                            </a> |
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <!-- Search form -->
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="exclusive-listings">
                <?php if ($status_filter): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                <p class="search-box">
                    <label class="screen-reader-text" for="listing-search">Search Listings:</label>
                    <input type="search" id="listing-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by address, city, or ID...">
                    <input type="submit" id="search-submit" class="button" value="Search Listings">
                </p>
            </form>

            <!-- Bulk Actions Form -->
            <form method="post" id="el-bulk-form">
                <?php wp_nonce_field('el_bulk_action', 'el_bulk_nonce'); ?>

                <!-- Top bulk actions -->
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1">Bulk Actions</option>
                            <option value="resync">Resync to BME</option>
                            <option value="archive">Archive</option>
                            <option value="activate">Set to Active</option>
                            <option value="delete">Delete Permanently</option>
                        </select>
                        <input type="submit" id="doaction" class="button action" value="Apply">
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total); ?> items</span>
                    </div>
                    <br class="clear">
                </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column" style="width: 30px;">
                            <label class="screen-reader-text" for="cb-select-all-1">Select All</label>
                            <input id="cb-select-all-1" type="checkbox">
                        </td>
                        <th scope="col" class="column-id" style="width: 60px;">ID</th>
                        <th scope="col" class="column-photo" style="width: 80px;">Photo</th>
                        <th scope="col" class="column-address">Address</th>
                        <th scope="col" class="column-price" style="width: 120px;">Price</th>
                        <th scope="col" class="column-type" style="width: 120px;">Type</th>
                        <th scope="col" class="column-status" style="width: 100px;">Status</th>
                        <th scope="col" class="column-details" style="width: 100px;">Details</th>
                        <th scope="col" class="column-modified" style="width: 140px;">Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listings)): ?>
                        <tr>
                            <td colspan="9">No exclusive listings found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($listings as $listing): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($listing['listing_id']); ?>">
                                        Select listing <?php echo esc_html($listing['listing_id']); ?>
                                    </label>
                                    <input id="cb-select-<?php echo esc_attr($listing['listing_id']); ?>" type="checkbox" name="listing_ids[]" value="<?php echo esc_attr($listing['listing_id']); ?>">
                                </th>
                                <td class="column-id">
                                    <strong><?php echo esc_html($listing['listing_id']); ?></strong>
                                </td>
                                <td class="column-photo">
                                    <?php if (!empty($listing['main_photo_url'])): ?>
                                        <img src="<?php echo esc_url($listing['main_photo_url']); ?>" alt="" style="width: 60px; height: 45px; object-fit: cover;">
                                    <?php else: ?>
                                        <span class="dashicons dashicons-format-image" style="font-size: 40px; color: #ccc;"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-address">
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=exclusive-listings-add&id=' . $listing['listing_id']); ?>">
                                            <?php echo esc_html(!empty($listing['unparsed_address']) ? $listing['unparsed_address'] : (($listing['street_number'] ?? '') . ' ' . ($listing['street_name'] ?? ''))); ?>
                                        </a>
                                    </strong>
                                    <br>
                                    <span class="description"><?php echo esc_html($listing['city']); ?>, <?php echo esc_html($listing['state_or_province']); ?> <?php echo esc_html($listing['postal_code']); ?></span>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo admin_url('admin.php?page=exclusive-listings-add&id=' . $listing['listing_id']); ?>">Edit</a> |
                                        </span>
                                        <span class="view">
                                            <a href="<?php echo home_url('/property/' . $listing['listing_id'] . '/'); ?>" target="_blank">View</a> |
                                        </span>
                                        <span class="trash">
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=exclusive-listings&action=delete&id=' . $listing['listing_id']), 'el_delete_' . $listing['listing_id']); ?>" onclick="return confirm('Are you sure you want to archive this listing?');">Archive</a>
                                        </span>
                                    </div>
                                </td>
                                <td class="column-price">
                                    <strong>$<?php echo number_format($listing['list_price']); ?></strong>
                                </td>
                                <td class="column-type">
                                    <?php echo esc_html($listing['property_sub_type'] ?: $listing['property_type']); ?>
                                </td>
                                <td class="column-status">
                                    <span class="el-status el-status-<?php echo sanitize_html_class(strtolower($listing['standard_status'])); ?>">
                                        <?php echo esc_html($listing['standard_status']); ?>
                                    </span>
                                </td>
                                <td class="column-details">
                                    <?php if ($listing['bedrooms_total']): ?>
                                        <?php echo esc_html($listing['bedrooms_total']); ?> bd
                                    <?php endif; ?>
                                    <?php if ($listing['bathrooms_total']): ?>
                                        / <?php echo esc_html($listing['bathrooms_total']); ?> ba
                                    <?php endif; ?>
                                    <?php if ($listing['building_area_total']): ?>
                                        <br><?php echo number_format($listing['building_area_total']); ?> sqft
                                    <?php endif; ?>
                                </td>
                                <td class="column-modified">
                                    <?php
                                    $date = new DateTime($listing['modification_timestamp'], wp_timezone());
                                    echo wp_date('M j, Y', $date->getTimestamp());
                                    ?>
                                    <br>
                                    <span class="description"><?php echo wp_date('g:i A', $date->getTimestamp()); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <label class="screen-reader-text" for="cb-select-all-2">Select All</label>
                            <input id="cb-select-all-2" type="checkbox">
                        </td>
                        <th scope="col" class="column-id">ID</th>
                        <th scope="col" class="column-photo">Photo</th>
                        <th scope="col" class="column-address">Address</th>
                        <th scope="col" class="column-price">Price</th>
                        <th scope="col" class="column-type">Type</th>
                        <th scope="col" class="column-status">Status</th>
                        <th scope="col" class="column-details">Details</th>
                        <th scope="col" class="column-modified">Modified</th>
                    </tr>
                </tfoot>
            </table>

            <!-- Bottom bulk actions -->
            <div class="tablenav bottom">
                <div class="alignleft actions bulkactions">
                    <label for="bulk-action-selector-bottom" class="screen-reader-text">Select bulk action</label>
                    <select name="bulk_action_bottom" id="bulk-action-selector-bottom">
                        <option value="-1">Bulk Actions</option>
                        <option value="resync">Resync to BME</option>
                        <option value="archive">Archive</option>
                        <option value="activate">Set to Active</option>
                        <option value="delete">Delete Permanently</option>
                    </select>
                    <input type="submit" id="doaction2" class="button action" value="Apply">
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total); ?> items</span>
                        <span class="pagination-links">
                            <?php
                            $base_url = admin_url('admin.php?page=exclusive-listings');
                            if ($status_filter) {
                                $base_url .= '&status=' . urlencode($status_filter);
                            }
                            if ($search) {
                                $base_url .= '&s=' . urlencode($search);
                            }

                            if ($paged > 1): ?>
                                <a class="prev-page button" href="<?php echo esc_url($base_url . '&paged=' . ($paged - 1)); ?>">
                                    <span aria-hidden="true">&lsaquo;</span>
                                </a>
                            <?php endif; ?>

                            <span class="paging-input">
                                <span class="tablenav-paging-text">
                                    <?php echo esc_html($paged); ?> of <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                                </span>
                            </span>

                            <?php if ($paged < $total_pages): ?>
                                <a class="next-page button" href="<?php echo esc_url($base_url . '&paged=' . ($paged + 1)); ?>">
                                    <span aria-hidden="true">&rsaquo;</span>
                                </a>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <br class="clear">
            </div>
            </form><!-- End bulk actions form -->
        </div>
        <?php
    }

    /**
     * Handle bulk actions
     *
     * @return string|null Success message or null
     */
    private function handle_bulk_actions() {
        // Check if bulk action submitted
        if (!isset($_POST['el_bulk_nonce'])) {
            return null;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['el_bulk_nonce'], 'el_bulk_action')) {
            return null;
        }

        // Check permissions
        if (!current_user_can('edit_posts')) {
            return null;
        }

        // Get action (check both top and bottom dropdowns)
        $action = isset($_POST['bulk_action']) && $_POST['bulk_action'] !== '-1'
            ? $_POST['bulk_action']
            : (isset($_POST['bulk_action_bottom']) && $_POST['bulk_action_bottom'] !== '-1'
                ? $_POST['bulk_action_bottom']
                : null);

        if (!$action) {
            return null;
        }

        // Get selected listing IDs
        $listing_ids = isset($_POST['listing_ids']) ? array_map('intval', $_POST['listing_ids']) : array();

        if (empty($listing_ids)) {
            return null;
        }

        $count = 0;
        $action_label = '';

        switch ($action) {
            case 'archive':
                foreach ($listing_ids as $listing_id) {
                    if ($this->bme_sync->listing_exists($listing_id)) {
                        $result = $this->bme_sync->archive_listing($listing_id);
                        if (!is_wp_error($result)) {
                            $count++;
                        }
                    }
                }
                $action_label = 'archived';
                break;

            case 'activate':
                foreach ($listing_ids as $listing_id) {
                    $listing = $this->get_listing_by_id($listing_id);
                    if ($listing) {
                        $listing['standard_status'] = 'Active';
                        $result = $this->bme_sync->sync_listing($listing_id, $listing, $listing['listing_key']);
                        if (!is_wp_error($result)) {
                            $count++;
                        }
                    }
                }
                $action_label = 'set to Active';
                break;

            case 'resync':
                // Resync listings to all BME tables (fixes OG image previews, etc.)
                foreach ($listing_ids as $listing_id) {
                    $listing = $this->get_listing_by_id($listing_id);
                    if ($listing) {
                        // Resync to all BME tables without changing status
                        $result = $this->bme_sync->sync_listing($listing_id, $listing, $listing['listing_key']);
                        if (!is_wp_error($result)) {
                            // Also sync photos from WordPress media to bme_media
                            $this->sync_photos_to_bme($listing_id);
                            $count++;
                        }
                    }
                }
                $action_label = 'resynced to BME';
                break;

            case 'delete':
                // Only allow admins to permanently delete
                if (!current_user_can('manage_options')) {
                    return 'You do not have permission to permanently delete listings.';
                }
                foreach ($listing_ids as $listing_id) {
                    if ($this->bme_sync->listing_exists($listing_id)) {
                        $this->image_handler->delete_all_photos($listing_id);
                        $result = $this->bme_sync->delete_listing($listing_id);
                        if (!is_wp_error($result)) {
                            $count++;
                        }
                    }
                }
                $action_label = 'permanently deleted';
                break;
        }

        if ($count > 0) {
            return sprintf('%d listing(s) %s.', $count, $action_label);
        }

        return null;
    }

    /**
     * Render the add/edit page
     */
    public function render_add_page() {
        $listing_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $listing = null;
        $photos = array();
        $is_edit = false;

        if ($listing_id) {
            $listing = $this->get_listing_by_id($listing_id);
            if ($listing) {
                $is_edit = true;
                $photos = $this->image_handler->get_photos($listing_id);
            }
        }

        // Display message if present
        if (isset($_GET['message'])) {
            $message_map = array(
                'created' => 'Listing created successfully.',
                'updated' => 'Listing updated successfully.',
            );
            $message = isset($message_map[$_GET['message']]) ? $message_map[$_GET['message']] : 'Operation completed.';
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Exclusive Listing #' . esc_html($listing_id) : 'Add New Exclusive Listing'; ?></h1>

            <form method="post" action="" id="el-listing-form">
                <?php wp_nonce_field('el_listing_action', 'el_nonce'); ?>
                <input type="hidden" name="el_action" value="<?php echo $is_edit ? 'update' : 'create'; ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="listing_id" value="<?php echo esc_attr($listing_id); ?>">
                <?php endif; ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <!-- Main content -->
                        <div id="post-body-content">
                            <!-- Address Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span>Address</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="street_number">Street Number <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="street_number" name="street_number"
                                                       value="<?php echo esc_attr($listing['street_number'] ?? ''); ?>"
                                                       class="regular-text" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="street_name">Street Name <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="street_name" name="street_name"
                                                       value="<?php echo esc_attr($listing['street_name'] ?? ''); ?>"
                                                       class="regular-text" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="unit_number">Unit Number</label></th>
                                            <td>
                                                <input type="text" id="unit_number" name="unit_number"
                                                       value="<?php echo esc_attr($listing['unit_number'] ?? ''); ?>"
                                                       class="small-text">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="city">City <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="city" name="city"
                                                       value="<?php echo esc_attr($listing['city'] ?? ''); ?>"
                                                       class="regular-text" required>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="state_or_province">State <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="state_or_province" name="state_or_province"
                                                       value="<?php echo esc_attr($listing['state_or_province'] ?? 'MA'); ?>"
                                                       class="small-text" maxlength="2" required placeholder="MA">
                                                <p class="description">2-letter state code (e.g., MA)</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="postal_code">Postal Code <span class="required">*</span></label></th>
                                            <td>
                                                <input type="text" id="postal_code" name="postal_code"
                                                       value="<?php echo esc_attr($listing['postal_code'] ?? ''); ?>"
                                                       class="small-text" required placeholder="02138">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="county">County</label></th>
                                            <td>
                                                <input type="text" id="county" name="county"
                                                       value="<?php echo esc_attr($listing['county'] ?? ''); ?>"
                                                       class="regular-text">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Property Details Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span>Property Details</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="list_price">List Price <span class="required">*</span></label></th>
                                            <td>
                                                <span class="el-price-prefix">$</span>
                                                <input type="text" id="list_price" name="list_price"
                                                       value="<?php echo esc_attr($listing['list_price'] ?? ''); ?>"
                                                       class="regular-text" required placeholder="500000">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="property_type">Property Type <span class="required">*</span></label></th>
                                            <td>
                                                <select id="property_type" name="property_type" required>
                                                    <option value="">Select Type...</option>
                                                    <?php
                                                    // Normalize MLS value to form value for correct dropdown selection
                                                    $current_type = EL_Validator::normalize_property_type($listing['property_type'] ?? '');
                                                    foreach (EL_Validator::get_property_types() as $type): ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                            <?php selected($current_type, $type); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="property_sub_type">Property Sub-Type</label></th>
                                            <td>
                                                <select id="property_sub_type" name="property_sub_type">
                                                    <option value="">Select Sub-Type...</option>
                                                    <?php
                                                    // Normalize MLS value to form value for correct dropdown selection
                                                    $current_sub_type = EL_Validator::normalize_property_sub_type($listing['property_sub_type'] ?? '');
                                                    foreach (EL_Validator::get_property_sub_types() as $type): ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                            <?php selected($current_sub_type, $type); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="bedrooms_total">Bedrooms</label></th>
                                            <td>
                                                <input type="number" id="bedrooms_total" name="bedrooms_total"
                                                       value="<?php echo esc_attr($listing['bedrooms_total'] ?? ''); ?>"
                                                       class="small-text" min="0">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="bathrooms_total">Total Bathrooms</label></th>
                                            <td>
                                                <input type="number" id="bathrooms_total" name="bathrooms_total"
                                                       value="<?php echo esc_attr($listing['bathrooms_total'] ?? ''); ?>"
                                                       class="small-text" min="0" step="0.5">
                                                <span class="description">Auto-calculates from full + half</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="bathrooms_full">Full Bathrooms</label></th>
                                            <td>
                                                <input type="number" id="bathrooms_full" name="bathrooms_full"
                                                       value="<?php echo esc_attr($listing['bathrooms_full'] ?? ''); ?>"
                                                       class="small-text" min="0">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="bathrooms_half">Half Bathrooms</label></th>
                                            <td>
                                                <input type="number" id="bathrooms_half" name="bathrooms_half"
                                                       value="<?php echo esc_attr($listing['bathrooms_half'] ?? ''); ?>"
                                                       class="small-text" min="0">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="building_area_total">Square Footage</label></th>
                                            <td>
                                                <input type="number" id="building_area_total" name="building_area_total"
                                                       value="<?php echo esc_attr($listing['building_area_total'] ?? ''); ?>"
                                                       class="small-text" min="0">
                                                <span class="description">sqft</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="lot_size">Lot Size</label></th>
                                            <td>
                                                <input type="number" id="lot_size_square_feet" name="lot_size_square_feet"
                                                       value="<?php echo esc_attr($listing['lot_size_square_feet'] ?? ''); ?>"
                                                       class="small-text" min="0" step="1">
                                                <span class="description">Sq Ft</span>
                                                &nbsp;&nbsp;<strong>OR</strong>&nbsp;&nbsp;
                                                <input type="number" id="lot_size_acres" name="lot_size_acres"
                                                       value="<?php echo esc_attr($listing['lot_size_acres'] ?? ''); ?>"
                                                       class="small-text" min="0" step="0.001">
                                                <span class="description">Acres</span>
                                                <p class="description">Enter either value - the other will auto-calculate (1 acre = 43,560 sq ft)</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="year_built">Year Built</label></th>
                                            <td>
                                                <input type="number" id="year_built" name="year_built"
                                                       value="<?php echo esc_attr($listing['year_built'] ?? ''); ?>"
                                                       class="small-text" min="1600" max="<?php echo date('Y') + 5; ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="parking">Parking</label></th>
                                            <td>
                                                <table class="el-inline-table">
                                                    <tr>
                                                        <td>
                                                            <input type="number" id="garage_spaces" name="garage_spaces"
                                                                   value="<?php echo esc_attr($listing['garage_spaces'] ?? ''); ?>"
                                                                   class="small-text" min="0">
                                                            <span class="description">Garage</span>
                                                        </td>
                                                        <td style="padding-left: 20px;">
                                                            <input type="number" id="parking_total" name="parking_total"
                                                                   value="<?php echo esc_attr($listing['parking_total'] ?? ''); ?>"
                                                                   class="small-text" min="0">
                                                            <span class="description">Other (driveway, street)</span>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <p class="description">Garage = covered parking; Other = driveway, street, etc.</p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Features Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span>Features</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th>Amenities</th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="has_pool" value="1"
                                                            <?php checked(!empty($listing['has_pool'])); ?>>
                                                        Pool
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="has_fireplace" value="1"
                                                            <?php checked(!empty($listing['has_fireplace'])); ?>>
                                                        Fireplace
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="has_basement" value="1"
                                                            <?php checked(!empty($listing['has_basement'])); ?>>
                                                        Basement
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="has_hoa" value="1"
                                                            <?php checked(!empty($listing['has_hoa'])); ?>>
                                                        HOA
                                                    </label>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="public_remarks">Description</label></th>
                                            <td>
                                                <textarea id="public_remarks" name="public_remarks" rows="6" class="large-text"><?php echo esc_textarea($listing['public_remarks'] ?? ''); ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="virtual_tour_url">Virtual Tour URL</label></th>
                                            <td>
                                                <input type="url" id="virtual_tour_url" name="virtual_tour_url"
                                                       value="<?php echo esc_url($listing['virtual_tour_url'] ?? ''); ?>"
                                                       class="large-text" placeholder="https://...">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Property Description Section (Tier 1) -->
                            <div class="postbox el-collapsible">
                                <button type="button" class="handlediv" aria-expanded="true">
                                    <span class="toggle-indicator"></span>
                                </button>
                                <h2 class="hndle"><span>Property Description</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="original_list_price">Original List Price</label></th>
                                            <td>
                                                <span class="el-price-prefix">$</span>
                                                <input type="text" id="original_list_price" name="original_list_price"
                                                       value="<?php echo esc_attr($listing['original_list_price'] ?? ''); ?>"
                                                       class="regular-text" placeholder="500000">
                                                <p class="description">Shows price reductions</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="architectural_style">Architectural Style</label></th>
                                            <td>
                                                <select id="architectural_style" name="architectural_style">
                                                    <option value="">Select Style...</option>
                                                    <?php foreach (EL_Validator::get_architectural_styles() as $style): ?>
                                                        <option value="<?php echo esc_attr($style); ?>"
                                                            <?php selected(($listing['architectural_style'] ?? ''), $style); ?>>
                                                            <?php echo esc_html($style); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="stories_total">Stories</label></th>
                                            <td>
                                                <input type="number" id="stories_total" name="stories_total"
                                                       value="<?php echo esc_attr($listing['stories_total'] ?? ''); ?>"
                                                       class="small-text" min="1" max="100">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="private_remarks">Private Remarks</label></th>
                                            <td>
                                                <textarea id="private_remarks" name="private_remarks" rows="4" class="large-text"><?php echo esc_textarea($listing['private_remarks'] ?? ''); ?></textarea>
                                                <p class="description">Agent-only notes (not shown to clients)</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="showing_instructions">Showing Instructions</label></th>
                                            <td>
                                                <textarea id="showing_instructions" name="showing_instructions" rows="3" class="large-text"><?php echo esc_textarea($listing['showing_instructions'] ?? ''); ?></textarea>
                                                <p class="description">Access codes, lockbox location, etc.</p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Interior Details Section (Tier 2) -->
                            <div class="postbox el-collapsible">
                                <button type="button" class="handlediv" aria-expanded="true">
                                    <span class="toggle-indicator"></span>
                                </button>
                                <h2 class="hndle"><span>Interior Details</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label>Heating</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_heating = isset($listing['heating']) ? explode(',', $listing['heating']) : array();
                                                    foreach (EL_Validator::get_heating_types() as $type): ?>
                                                        <label>
                                                            <input type="checkbox" name="heating[]" value="<?php echo esc_attr($type); ?>"
                                                                <?php checked(in_array($type, $current_heating)); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Cooling</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_cooling = isset($listing['cooling']) ? explode(',', $listing['cooling']) : array();
                                                    foreach (EL_Validator::get_cooling_types() as $type): ?>
                                                        <label>
                                                            <input type="checkbox" name="cooling[]" value="<?php echo esc_attr($type); ?>"
                                                                <?php checked(in_array($type, $current_cooling)); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Interior Features</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_interior = isset($listing['interior_features']) ? explode(',', $listing['interior_features']) : array();
                                                    foreach (EL_Validator::get_interior_features() as $feature): ?>
                                                        <label>
                                                            <input type="checkbox" name="interior_features[]" value="<?php echo esc_attr($feature); ?>"
                                                                <?php checked(in_array($feature, $current_interior)); ?>>
                                                            <?php echo esc_html($feature); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Appliances</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_appliances = isset($listing['appliances']) ? explode(',', $listing['appliances']) : array();
                                                    foreach (EL_Validator::get_appliances() as $appliance): ?>
                                                        <label>
                                                            <input type="checkbox" name="appliances[]" value="<?php echo esc_attr($appliance); ?>"
                                                                <?php checked(in_array($appliance, $current_appliances)); ?>>
                                                            <?php echo esc_html($appliance); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Flooring</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_flooring = isset($listing['flooring']) ? explode(',', $listing['flooring']) : array();
                                                    foreach (EL_Validator::get_flooring_types() as $type): ?>
                                                        <label>
                                                            <input type="checkbox" name="flooring[]" value="<?php echo esc_attr($type); ?>"
                                                                <?php checked(in_array($type, $current_flooring)); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Laundry Features</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_laundry = isset($listing['laundry_features']) ? explode(',', $listing['laundry_features']) : array();
                                                    foreach (EL_Validator::get_laundry_features() as $feature): ?>
                                                        <label>
                                                            <input type="checkbox" name="laundry_features[]" value="<?php echo esc_attr($feature); ?>"
                                                                <?php checked(in_array($feature, $current_laundry)); ?>>
                                                            <?php echo esc_html($feature); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="basement">Basement</label></th>
                                            <td>
                                                <select id="basement" name="basement">
                                                    <option value="">Select Basement Type...</option>
                                                    <?php foreach (EL_Validator::get_basement_types() as $type): ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                            <?php selected(($listing['basement'] ?? ''), $type); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Exterior & Lot Section (Tier 3) -->
                            <div class="postbox el-collapsible">
                                <button type="button" class="handlediv" aria-expanded="true">
                                    <span class="toggle-indicator"></span>
                                </button>
                                <h2 class="hndle"><span>Exterior & Lot</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label>Construction Materials</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_construction = isset($listing['construction_materials']) ? explode(',', $listing['construction_materials']) : array();
                                                    foreach (EL_Validator::get_construction_materials() as $material): ?>
                                                        <label>
                                                            <input type="checkbox" name="construction_materials[]" value="<?php echo esc_attr($material); ?>"
                                                                <?php checked(in_array($material, $current_construction)); ?>>
                                                            <?php echo esc_html($material); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="roof">Roof Type</label></th>
                                            <td>
                                                <select id="roof" name="roof">
                                                    <option value="">Select Roof Type...</option>
                                                    <?php foreach (EL_Validator::get_roof_types() as $type): ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                            <?php selected(($listing['roof'] ?? ''), $type); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="foundation_details">Foundation</label></th>
                                            <td>
                                                <select id="foundation_details" name="foundation_details">
                                                    <option value="">Select Foundation Type...</option>
                                                    <?php foreach (EL_Validator::get_foundation_types() as $type): ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                            <?php selected(($listing['foundation_details'] ?? ''), $type); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Exterior Features</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_exterior = isset($listing['exterior_features']) ? explode(',', $listing['exterior_features']) : array();
                                                    foreach (EL_Validator::get_exterior_features() as $feature): ?>
                                                        <label>
                                                            <input type="checkbox" name="exterior_features[]" value="<?php echo esc_attr($feature); ?>"
                                                                <?php checked(in_array($feature, $current_exterior)); ?>>
                                                            <?php echo esc_html($feature); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Waterfront</label></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="waterfront_yn" value="1"
                                                        <?php checked(!empty($listing['waterfront_yn'])); ?>>
                                                    Property has waterfront access
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Waterfront Features</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_waterfront = isset($listing['waterfront_features']) ? explode(',', $listing['waterfront_features']) : array();
                                                    foreach (EL_Validator::get_waterfront_features() as $feature): ?>
                                                        <label>
                                                            <input type="checkbox" name="waterfront_features[]" value="<?php echo esc_attr($feature); ?>"
                                                                <?php checked(in_array($feature, $current_waterfront)); ?>>
                                                            <?php echo esc_html($feature); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>View</label></th>
                                            <td>
                                                <label style="display: block; margin-bottom: 10px;">
                                                    <input type="checkbox" name="view_yn" value="1"
                                                        <?php checked(!empty($listing['view_yn'])); ?>>
                                                    Property has notable view
                                                </label>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_view = isset($listing['view']) ? explode(',', $listing['view']) : array();
                                                    foreach (EL_Validator::get_view_types() as $type): ?>
                                                        <label>
                                                            <input type="checkbox" name="view[]" value="<?php echo esc_attr($type); ?>"
                                                                <?php checked(in_array($type, $current_view)); ?>>
                                                            <?php echo esc_html($type); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Parking Features</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_parking = isset($listing['parking_features']) ? explode(',', $listing['parking_features']) : array();
                                                    foreach (EL_Validator::get_parking_features() as $feature): ?>
                                                        <label>
                                                            <input type="checkbox" name="parking_features[]" value="<?php echo esc_attr($feature); ?>"
                                                                <?php checked(in_array($feature, $current_parking)); ?>>
                                                            <?php echo esc_html($feature); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Financial Section (Tier 4) -->
                            <div class="postbox el-collapsible">
                                <button type="button" class="handlediv" aria-expanded="true">
                                    <span class="toggle-indicator"></span>
                                </button>
                                <h2 class="hndle"><span>Financial & HOA</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="tax_annual_amount">Annual Property Tax</label></th>
                                            <td>
                                                <span class="el-price-prefix">$</span>
                                                <input type="text" id="tax_annual_amount" name="tax_annual_amount"
                                                       value="<?php echo esc_attr($listing['tax_annual_amount'] ?? ''); ?>"
                                                       class="regular-text" placeholder="5000">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="tax_year">Tax Year</label></th>
                                            <td>
                                                <input type="number" id="tax_year" name="tax_year"
                                                       value="<?php echo esc_attr($listing['tax_year'] ?? date('Y')); ?>"
                                                       class="small-text" min="2000" max="<?php echo date('Y') + 1; ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Association (HOA)</label></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="association_yn" value="1" id="association_yn_checkbox"
                                                        <?php checked(!empty($listing['association_yn'])); ?>>
                                                    Property has HOA/Association
                                                </label>
                                            </td>
                                        </tr>
                                        <tr class="el-hoa-field">
                                            <th><label for="association_fee">Association Fee</label></th>
                                            <td>
                                                <span class="el-price-prefix">$</span>
                                                <input type="text" id="association_fee" name="association_fee"
                                                       value="<?php echo esc_attr($listing['association_fee'] ?? ''); ?>"
                                                       class="regular-text" placeholder="350">
                                            </td>
                                        </tr>
                                        <tr class="el-hoa-field">
                                            <th><label for="association_fee_frequency">Fee Frequency</label></th>
                                            <td>
                                                <select id="association_fee_frequency" name="association_fee_frequency">
                                                    <option value="">Select Frequency...</option>
                                                    <?php foreach (EL_Validator::get_association_fee_frequencies() as $freq): ?>
                                                        <option value="<?php echo esc_attr($freq); ?>"
                                                            <?php selected(($listing['association_fee_frequency'] ?? ''), $freq); ?>>
                                                            <?php echo esc_html($freq); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr class="el-hoa-field">
                                            <th><label>Fee Includes</label></th>
                                            <td>
                                                <fieldset class="el-checkbox-grid">
                                                    <?php
                                                    $current_includes = isset($listing['association_fee_includes']) ? explode(',', $listing['association_fee_includes']) : array();
                                                    foreach (EL_Validator::get_association_fee_includes() as $item): ?>
                                                        <label>
                                                            <input type="checkbox" name="association_fee_includes[]" value="<?php echo esc_attr($item); ?>"
                                                                <?php checked(in_array($item, $current_includes)); ?>>
                                                            <?php echo esc_html($item); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Photos Section (only for edit mode) -->
                            <?php if ($is_edit): ?>
                            <div class="postbox">
                                <h2 class="hndle"><span>Photos</span></h2>
                                <div class="inside">
                                    <div id="el-photo-manager" data-listing-id="<?php echo esc_attr($listing_id); ?>">
                                        <div id="el-photo-grid" class="el-photo-grid">
                                            <?php foreach ($photos as $index => $photo): ?>
                                                <div class="el-photo-item" data-id="<?php echo esc_attr($photo['id']); ?>">
                                                    <img src="<?php echo esc_url($photo['media_url']); ?>" alt="">
                                                    <div class="el-photo-overlay">
                                                        <span class="el-photo-order"><?php echo $index + 1; ?></span>
                                                        <button type="button" class="el-photo-delete" data-id="<?php echo esc_attr($photo['id']); ?>" title="Delete">
                                                            <span class="dashicons dashicons-trash"></span>
                                                        </button>
                                                    </div>
                                                    <?php if ($index === 0): ?>
                                                        <span class="el-photo-primary">Primary</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="description">
                                            Drag photos to reorder. First photo is the primary image.
                                            Max <?php echo EL_Image_Handler::MAX_PHOTOS; ?> photos.
                                        </p>
                                        <div class="el-photo-upload">
                                            <input type="file" id="el-photo-input" accept="image/*" multiple style="display: none;">
                                            <button type="button" id="el-add-photos" class="button">
                                                <span class="dashicons dashicons-camera"></span> Add Photos
                                            </button>
                                            <span id="el-upload-status"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="postbox">
                                <h2 class="hndle"><span>Photos</span></h2>
                                <div class="inside">
                                    <p class="description">Save the listing first, then you can add photos.</p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Coordinates Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span>Location Coordinates</span></h2>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="latitude">Latitude</label></th>
                                            <td>
                                                <input type="text" id="latitude" name="latitude"
                                                       value="<?php echo esc_attr($listing['latitude'] ?? ''); ?>"
                                                       class="regular-text" placeholder="42.3601">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="longitude">Longitude</label></th>
                                            <td>
                                                <input type="text" id="longitude" name="longitude"
                                                       value="<?php echo esc_attr($listing['longitude'] ?? ''); ?>"
                                                       class="regular-text" placeholder="-71.0589">
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="description">Leave blank to auto-geocode from address.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar -->
                        <div id="postbox-container-1" class="postbox-container">
                            <!-- Publish Box -->
                            <div class="postbox">
                                <h2 class="hndle"><span>Publish</span></h2>
                                <div class="inside">
                                    <div class="submitbox">
                                        <div id="minor-publishing">
                                            <div class="misc-pub-section">
                                                <label for="standard_status"><strong>Status:</strong></label>
                                                <select id="standard_status" name="standard_status">
                                                    <?php foreach (EL_Validator::get_statuses() as $status): ?>
                                                        <option value="<?php echo esc_attr($status); ?>"
                                                            <?php selected(($listing['standard_status'] ?? 'Active'), $status); ?>>
                                                            <?php echo esc_html($status); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="misc-pub-section">
                                                <label for="exclusive_tag"><strong>Badge Text:</strong></label>
                                                <select id="exclusive_tag_select" onchange="elHandleTagSelect(this)">
                                                    <?php
                                                    $current_tag = $listing['exclusive_tag'] ?? '';
                                                    $predefined_tags = EL_Validator::get_exclusive_tags();
                                                    $is_custom = !empty($current_tag) && !in_array($current_tag, $predefined_tags);
                                                    ?>
                                                    <?php foreach ($predefined_tags as $tag): ?>
                                                        <option value="<?php echo esc_attr($tag); ?>"
                                                            <?php selected($current_tag, $tag); ?>>
                                                            <?php echo esc_html($tag); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom__" <?php echo $is_custom ? 'selected' : ''; ?>>Custom...</option>
                                                </select>
                                                <input type="text"
                                                       id="exclusive_tag_custom"
                                                       name="exclusive_tag"
                                                       value="<?php echo esc_attr($current_tag); ?>"
                                                       maxlength="50"
                                                       placeholder="Enter custom text"
                                                       style="margin-top: 5px; <?php echo $is_custom ? '' : 'display: none;'; ?>">
                                                <p class="description" style="margin-top: 5px;">
                                                    Badge shown on listing cards (e.g., "Coming Soon")
                                                </p>
                                            </div>
                                            <?php if ($is_edit): ?>
                                            <div class="misc-pub-section">
                                                <strong>Listing ID:</strong> <?php echo esc_html($listing_id); ?>
                                            </div>
                                            <div class="misc-pub-section">
                                                <strong>Photos:</strong> <?php echo count($photos); ?> / <?php echo EL_Image_Handler::MAX_PHOTOS; ?>
                                            </div>
                                            <?php if ($listing['modification_timestamp']): ?>
                                            <div class="misc-pub-section">
                                                <strong>Last Modified:</strong><br>
                                                <?php
                                                $date = new DateTime($listing['modification_timestamp'], wp_timezone());
                                                echo wp_date('M j, Y g:i A', $date->getTimestamp());
                                                ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div id="major-publishing-actions">
                                            <?php if ($is_edit): ?>
                                            <div id="delete-action">
                                                <a class="submitdelete deletion" href="<?php echo wp_nonce_url(admin_url('admin.php?page=exclusive-listings&action=delete&id=' . $listing_id), 'el_delete_' . $listing_id); ?>" onclick="return confirm('Are you sure you want to archive this listing?');">
                                                    Archive
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            <div id="publishing-action">
                                                <input type="submit" class="button button-primary button-large" value="<?php echo $is_edit ? 'Update' : 'Publish'; ?>">
                                            </div>
                                            <div class="clear"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($is_edit && !empty($listing)): ?>
                            <!-- View Listing Box -->
                            <div class="postbox">
                                <h2 class="hndle"><span>View</span></h2>
                                <div class="inside">
                                    <p>
                                        <a href="<?php echo home_url('/property/' . $listing_id . '/'); ?>" target="_blank" class="button">
                                            <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                                            View Property Page
                                        </a>
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <style>
        .required { color: #d63638; }
        .el-price-prefix { font-size: 16px; font-weight: bold; }
        .el-photo-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        .el-photo-item {
            position: relative;
            aspect-ratio: 4/3;
            cursor: move;
            border: 2px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }
        .el-photo-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .el-photo-overlay {
            position: absolute;
            top: 0;
            right: 0;
            padding: 5px;
            display: flex;
            gap: 5px;
        }
        .el-photo-order {
            background: rgba(0,0,0,0.6);
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .el-photo-delete {
            background: rgba(255,0,0,0.8);
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            padding: 2px;
            line-height: 1;
        }
        .el-photo-delete:hover {
            background: red;
        }
        .el-photo-primary {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: #2271b1;
            color: white;
            text-align: center;
            font-size: 11px;
            padding: 2px;
        }
        .el-photo-item.ui-sortable-placeholder {
            background: #f0f0f0;
            border: 2px dashed #ccc;
        }
        .el-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .el-status-active { background: #d4edda; color: #155724; }
        .el-status-pending { background: #fff3cd; color: #856404; }
        .el-status-closed { background: #f8d7da; color: #721c24; }
        .el-status-withdrawn { background: #e2e3e5; color: #383d41; }
        #postbox-container-1 { width: 280px; }

        /* v1.4.0 - Collapsible sections and checkbox grids */
        .el-collapsible .handlediv {
            position: absolute;
            top: 0;
            right: 0;
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            cursor: pointer;
        }
        .el-collapsible .toggle-indicator::before {
            content: '\f142';
            font-family: dashicons;
            display: block;
            line-height: 36px;
            text-align: center;
        }
        .el-collapsible.closed .toggle-indicator::before {
            content: '\f140';
        }
        .el-collapsible.closed .inside {
            display: none;
        }
        .el-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px 20px;
        }
        .el-checkbox-grid label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 4px 0;
        }
        .el-checkbox-grid input[type="checkbox"] {
            margin: 0;
        }
        .el-hoa-field {
            transition: opacity 0.2s;
        }
        body:not(.el-hoa-enabled) .el-hoa-field {
            opacity: 0.5;
        }
        @media (max-width: 782px) {
            .el-checkbox-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Collapsible sections
            $('.el-collapsible .handlediv').on('click', function() {
                $(this).closest('.postbox').toggleClass('closed');
            });

            // HOA fields toggle
            function toggleHOAFields() {
                if ($('#association_yn_checkbox').is(':checked')) {
                    $('body').addClass('el-hoa-enabled');
                    $('.el-hoa-field input, .el-hoa-field select').prop('disabled', false);
                } else {
                    $('body').removeClass('el-hoa-enabled');
                    $('.el-hoa-field input, .el-hoa-field select').prop('disabled', true);
                }
            }
            $('#association_yn_checkbox').on('change', toggleHOAFields);
            toggleHOAFields();

            // v1.5.0: Bathroom auto-calculation
            // When full or half changes, update total
            $('#bathrooms_full, #bathrooms_half').on('change input', function() {
                var full = parseInt($('#bathrooms_full').val()) || 0;
                var half = parseInt($('#bathrooms_half').val()) || 0;
                var total = full + (half * 0.5);
                if (total > 0) {
                    $('#bathrooms_total').val(total);
                }
            });
            // When total changes, decompose to full/half
            $('#bathrooms_total').on('change', function() {
                var total = parseFloat($(this).val()) || 0;
                if (total > 0) {
                    var half = (total % 1) * 2; // 0.5 becomes 1 half bath
                    var full = Math.floor(total);
                    $('#bathrooms_full').val(full > 0 ? full : '');
                    $('#bathrooms_half').val(half > 0 ? half : '');
                }
            });

            // v1.5.0: Lot size auto-conversion (1 acre = 43,560 sq ft)
            var SQ_FT_PER_ACRE = 43560;

            // On page load, if acres has a value but sq ft is empty, calculate sq ft
            var initialAcres = parseFloat($('#lot_size_acres').val()) || 0;
            var initialSqft = parseFloat($('#lot_size_square_feet').val()) || 0;
            if (initialAcres > 0 && initialSqft === 0) {
                $('#lot_size_square_feet').val(Math.round(initialAcres * SQ_FT_PER_ACRE));
            }

            $('#lot_size_square_feet').on('change input', function() {
                var sqft = parseFloat($(this).val()) || 0;
                if (sqft > 0) {
                    var acres = (sqft / SQ_FT_PER_ACRE).toFixed(4);
                    $('#lot_size_acres').val(acres);
                }
            });
            $('#lot_size_acres').on('change input', function() {
                var acres = parseFloat($(this).val()) || 0;
                if (acres > 0) {
                    var sqft = Math.round(acres * SQ_FT_PER_ACRE);
                    $('#lot_size_square_feet').val(sqft);
                }
            });
        });

        // Exclusive Tag selector (v1.5.0)
        function elHandleTagSelect(select) {
            var customInput = document.getElementById('exclusive_tag_custom');
            if (select.value === '__custom__') {
                customInput.style.display = 'block';
                customInput.value = '';
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.value = select.value;
            }
        }
        </script>
        <?php
    }

    /**
     * AJAX handler for photo upload
     */
    public function ajax_upload_photo() {
        check_ajax_referer('el_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $listing_id = intval($_POST['listing_id']);

        if (!$listing_id || !$this->bme_sync->listing_exists($listing_id)) {
            wp_send_json_error('Invalid listing');
        }

        if (empty($_FILES['photo'])) {
            wp_send_json_error('No file uploaded');
        }

        $result = $this->image_handler->upload_photo($listing_id, $_FILES['photo']);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'id' => $result['id'],
            'url' => $result['url'],
            'order' => $result['order'],
        ));
    }

    /**
     * AJAX handler for photo delete
     */
    public function ajax_delete_photo() {
        check_ajax_referer('el_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $listing_id = intval($_POST['listing_id']);
        $photo_id = intval($_POST['photo_id']);

        $result = $this->image_handler->delete_photo($listing_id, $photo_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler for photo reorder
     */
    public function ajax_reorder_photos() {
        check_ajax_referer('el_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $listing_id = intval($_POST['listing_id']);
        $order = isset($_POST['order']) ? array_map('intval', $_POST['order']) : array();

        if (empty($order)) {
            wp_send_json_error('Invalid order');
        }

        $result = $this->image_handler->reorder_photos($listing_id, $order);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success();
    }

    /**
     * Get listing by ID from BME summary table with extended data from other tables
     *
     * @since 1.0.0
     * @since 1.4.0 Added extended data from listings, details, features, and financial tables
     * @param int $listing_id Listing ID
     * @return array|null Listing data or null
     */
    private function get_listing_by_id($listing_id) {
        global $wpdb;

        // Get basic data from summary table
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        if (!$listing) {
            return null;
        }

        // Get extended data from bme_listings table
        $listings_table = $wpdb->prefix . 'bme_listings';
        $listings_data = $wpdb->get_row($wpdb->prepare(
            "SELECT original_list_price, public_remarks, private_remarks, showing_instructions, virtual_tour_url_unbranded as virtual_tour_url
             FROM {$listings_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
        if ($listings_data) {
            $listing = array_merge($listing, $listings_data);
        }

        // Get data from details table (includes interior_features, appliances, parking_features, parking_total)
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $details_data = $wpdb->get_row($wpdb->prepare(
            "SELECT architectural_style, stories_total, heating, cooling, heating_yn, cooling_yn,
                    flooring, laundry_features, basement, construction_materials, roof, foundation_details,
                    interior_features, appliances, parking_features, parking_total
             FROM {$details_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
        if ($details_data) {
            $listing = array_merge($listing, $details_data);
        }

        // Get data from features table (exterior/waterfront/view features only)
        $features_table = $wpdb->prefix . 'bme_listing_features';
        $features_data = $wpdb->get_row($wpdb->prepare(
            "SELECT exterior_features, waterfront_yn, waterfront_features, view_yn, view
             FROM {$features_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
        if ($features_data) {
            $listing = array_merge($listing, $features_data);
        }

        // Get data from financial table
        $financial_table = $wpdb->prefix . 'bme_listing_financial';
        $financial_data = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_annual_amount, tax_year, association_yn, association_fee,
                    association_fee_frequency, association_fee_includes
             FROM {$financial_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
        if ($financial_data) {
            $listing = array_merge($listing, $financial_data);
        }

        return $listing;
    }

    /**
     * Sync photos from WordPress media library to bme_media table
     *
     * This ensures photos uploaded via the admin are properly stored in bme_media
     * so they appear in property detail pages and OG image tags.
     *
     * @since 1.4.1
     * @param int $listing_id Listing ID
     * @return void
     */
    private function sync_photos_to_bme($listing_id) {
        global $wpdb;

        // Get all attachments linked to this exclusive listing
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_exclusive_listing_id',
                    'value' => $listing_id,
                    'compare' => '=',
                ),
            ),
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ));

        if (empty($attachments)) {
            return;
        }

        $media_table = $wpdb->prefix . 'bme_media';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get listing_key from summary table
        $listing_key = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_key FROM {$summary_table} WHERE listing_id = %d",
            $listing_id
        ));

        if (!$listing_key) {
            $listing_key = md5('exclusive_' . $listing_id);
        }

        // Clear existing photos for this listing
        $wpdb->delete($media_table, array('listing_id' => $listing_id));

        // Insert each photo
        $order = 0;
        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if (!$url) {
                continue;
            }

            $media_key = md5($listing_id . '_' . $url . '_' . time() . '_' . $order);

            $wpdb->insert($media_table, array(
                'listing_id' => $listing_id,
                'listing_key' => $listing_key,
                'media_key' => $media_key,
                'media_url' => $url,
                'media_category' => 'Photo',
                'order_index' => $order,
                'source_table' => 'active',
                'modification_timestamp' => current_time('mysql'),
            ));

            $order++;
        }

        // Update summary table with main photo and count
        if ($order > 0) {
            $first_url = wp_get_attachment_url($attachments[0]->ID);
            $wpdb->update(
                $summary_table,
                array(
                    'main_photo_url' => $first_url,
                    'photo_count' => $order,
                ),
                array('listing_id' => $listing_id)
            );
        }
    }

    /**
     * Redirect with message
     *
     * @param string $page Page slug
     * @param string $type Message type (success/error)
     * @param string $message Message text
     * @param array $extra_params Additional URL parameters
     */
    private function redirect_with_message($page, $type, $message, $extra_params = array()) {
        $url = admin_url('admin.php?page=' . $page . '&message=' . $type . '&msg=' . urlencode($message));

        foreach ($extra_params as $key => $value) {
            $url .= '&' . $key . '=' . urlencode($value);
        }

        wp_redirect($url);
        exit;
    }
}
