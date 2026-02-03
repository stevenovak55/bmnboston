<?php
/**
 * Chatbot Training Admin Interface
 *
 * Handles the training examples management interface
 * Allows admins to rate conversations and save them as training examples
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot
 * @since 6.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Training {

    /**
     * Settings page slug
     *
     * @var string
     */
    const PAGE_SLUG = 'mld-chatbot-training';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 21);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_mld_save_training_example', array($this, 'ajax_save_training_example'));
        add_action('wp_ajax_mld_get_training_examples', array($this, 'ajax_get_training_examples'));
        add_action('wp_ajax_mld_delete_training_example', array($this, 'ajax_delete_training_example'));
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'AI Training',
            'AI Training',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_training_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our page
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'mld-chatbot-training-admin',
            plugins_url('../../assets/css/chatbot-admin.css', __FILE__),
            array(),
            MLD_VERSION
        );

        wp_enqueue_script(
            'mld-chatbot-training-admin',
            plugins_url('../../assets/js/chatbot-training-admin.js', __FILE__),
            array('jquery'),
            MLD_VERSION,
            true
        );

        wp_localize_script('mld-chatbot-training-admin', 'mldTrainingAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_training_nonce'),
        ));
    }

    /**
     * Render training page
     */
    public function render_training_page() {
        ?>
        <div class="wrap mld-chatbot-training-wrap">
            <h1>AI Chatbot Training</h1>
            <p class="description">
                Save conversations as training examples to improve your chatbot. Rate conversations as good, bad, or needs improvement.
            </p>

            <div class="mld-training-container">
                <!-- Add New Training Example Form -->
                <div class="mld-training-form-section">
                    <h2>Add Training Example</h2>
                    <form id="mld-training-form" method="post">
                        <?php wp_nonce_field('mld_save_training', 'mld_training_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="example_type">Rating *</label>
                                </th>
                                <td>
                                    <select name="example_type" id="example_type" required>
                                        <option value="">Select rating...</option>
                                        <option value="good">✓ Good - Excellent response</option>
                                        <option value="needs_improvement">⚠ Needs Improvement - Could be better</option>
                                        <option value="bad">✗ Bad - Poor response</option>
                                    </select>
                                    <p class="description">How would you rate this conversation?</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="user_message">User Message *</label>
                                </th>
                                <td>
                                    <textarea name="user_message" id="user_message" rows="4" class="large-text" required
                                        placeholder="Paste the user's question or message here..."></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="ai_response">AI Response *</label>
                                </th>
                                <td>
                                    <textarea name="ai_response" id="ai_response" rows="6" class="large-text" required
                                        placeholder="Paste the AI's response here..."></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="feedback_notes">Your Feedback</label>
                                </th>
                                <td>
                                    <textarea name="feedback_notes" id="feedback_notes" rows="3" class="large-text"
                                        placeholder="What was good or bad about this response? What could be improved?"></textarea>
                                    <p class="description">Explain why you gave this rating (optional)</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="conversation_context">Full Conversation Context</label>
                                </th>
                                <td>
                                    <textarea name="conversation_context" id="conversation_context" rows="4" class="large-text"
                                        placeholder="If this was part of a longer conversation, paste the full context here..."></textarea>
                                    <p class="description">Previous messages in the conversation (optional)</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="rating">Numeric Rating</label>
                                </th>
                                <td>
                                    <select name="rating" id="rating">
                                        <option value="">No rating</option>
                                        <option value="5">5 - Excellent</option>
                                        <option value="4">4 - Good</option>
                                        <option value="3">3 - Average</option>
                                        <option value="2">2 - Poor</option>
                                        <option value="1">1 - Very Poor</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="tags">Tags</label>
                                </th>
                                <td>
                                    <input type="text" name="tags" id="tags" class="regular-text"
                                        placeholder="property-search, pricing, neighborhood, etc.">
                                    <p class="description">Comma-separated tags for categorization (optional)</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="save-training-btn">
                                Save Training Example
                            </button>
                            <span class="spinner"></span>
                        </p>

                        <div id="training-save-message" class="notice" style="display:none;"></div>
                    </form>
                </div>

                <!-- Training Examples List -->
                <div class="mld-training-list-section">
                    <h2>Training Examples</h2>

                    <!-- Search and Filter Controls (v6.9.0) -->
                    <div class="mld-training-filters">
                        <div class="mld-filter-row">
                            <!-- Search Box -->
                            <div class="mld-filter-group">
                                <label for="training-search">Search:</label>
                                <input type="text" id="training-search" placeholder="Search messages, responses, feedback..."
                                       class="regular-text">
                            </div>

                            <!-- Type Filter -->
                            <div class="mld-filter-group">
                                <label for="filter-type">Type:</label>
                                <select id="filter-type">
                                    <option value="">All Types</option>
                                    <option value="good">✓ Good</option>
                                    <option value="needs_improvement">⚠ Needs Improvement</option>
                                    <option value="bad">✗ Bad</option>
                                </select>
                            </div>

                            <!-- Rating Filter -->
                            <div class="mld-filter-group">
                                <label for="filter-rating">Rating:</label>
                                <select id="filter-rating">
                                    <option value="">All Ratings</option>
                                    <option value="5">5 Stars</option>
                                    <option value="4">4 Stars</option>
                                    <option value="3">3 Stars</option>
                                    <option value="2">2 Stars</option>
                                    <option value="1">1 Star</option>
                                </select>
                            </div>

                            <!-- Tag Filter -->
                            <div class="mld-filter-group">
                                <label for="filter-tag">Tag:</label>
                                <input type="text" id="filter-tag" placeholder="Filter by tag..."
                                       class="regular-text">
                            </div>
                        </div>

                        <div class="mld-filter-row">
                            <!-- Date Range Filter -->
                            <div class="mld-filter-group">
                                <label for="filter-date-from">From:</label>
                                <input type="date" id="filter-date-from">
                            </div>

                            <div class="mld-filter-group">
                                <label for="filter-date-to">To:</label>
                                <input type="date" id="filter-date-to">
                            </div>

                            <!-- Filter Actions -->
                            <div class="mld-filter-group">
                                <button type="button" id="apply-filters" class="button button-primary">
                                    Apply Filters
                                </button>
                                <button type="button" id="clear-filters" class="button">
                                    Clear
                                </button>
                            </div>
                        </div>

                        <!-- Active Filters Display -->
                        <div id="active-filters" class="mld-active-filters" style="display:none;">
                            <strong>Active Filters:</strong>
                            <span id="active-filters-list"></span>
                            <button type="button" id="remove-all-filters" class="button-link">Remove All</button>
                        </div>
                    </div>

                    <div id="training-examples-list">
                        <p>Loading training examples...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save training example
     */
    public function ajax_save_training_example() {
        check_ajax_referer('mld_training_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_training';

        // Validate required fields
        $required = array('example_type', 'user_message', 'ai_response');
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(array('message' => "Field '{$field}' is required"));
            }
        }

        // Sanitize and prepare data
        $data = array(
            'example_type' => sanitize_text_field($_POST['example_type']),
            'user_message' => wp_kses_post($_POST['user_message']),
            'ai_response' => wp_kses_post($_POST['ai_response']),
            'feedback_notes' => !empty($_POST['feedback_notes']) ? wp_kses_post($_POST['feedback_notes']) : null,
            'conversation_context' => !empty($_POST['conversation_context']) ? wp_kses_post($_POST['conversation_context']) : null,
            'rating' => !empty($_POST['rating']) ? intval($_POST['rating']) : null,
            'tags' => !empty($_POST['tags']) ? sanitize_text_field($_POST['tags']) : null,
            'created_by' => get_current_user_id(),
        );

        $format = array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d');

        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            wp_send_json_error(array(
                'message' => 'Failed to save training example',
                'error' => $wpdb->last_error
            ));
        }

        wp_send_json_success(array(
            'message' => 'Training example saved successfully',
            'id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX: Get training examples (v6.9.0 - enhanced with search and filters)
     */
    public function ajax_get_training_examples() {
        check_ajax_referer('mld_training_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_training';

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Get filter parameters (v6.9.0)
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        $filter_rating = isset($_GET['filter_rating']) ? intval($_GET['filter_rating']) : 0;
        $filter_tag = isset($_GET['filter_tag']) ? sanitize_text_field($_GET['filter_tag']) : '';
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

        // Build WHERE clause
        $where_conditions = array();
        $where_values = array();

        // Filter by type
        if (!empty($filter_type) && in_array($filter_type, array('good', 'bad', 'needs_improvement'))) {
            $where_conditions[] = "example_type = %s";
            $where_values[] = $filter_type;
        }

        // Filter by rating
        if ($filter_rating > 0 && $filter_rating <= 5) {
            $where_conditions[] = "rating = %d";
            $where_values[] = $filter_rating;
        }

        // Filter by tag
        if (!empty($filter_tag)) {
            $where_conditions[] = "tags LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($filter_tag) . '%';
        }

        // Search in messages and feedback
        if (!empty($search_query)) {
            $where_conditions[] = "(user_message LIKE %s OR ai_response LIKE %s OR feedback_notes LIKE %s)";
            $search_like = '%' . $wpdb->esc_like($search_query) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }

        // Filter by date range
        if (!empty($date_from)) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $where_conditions[] = "created_at <= %s";
            $where_values[] = $date_to . ' 23:59:59';
        }

        // Combine WHERE conditions
        $where = '';
        if (!empty($where_conditions)) {
            $where = " WHERE " . implode(" AND ", $where_conditions);
            if (!empty($where_values)) {
                $where = $wpdb->prepare($where, $where_values);
            }
        }

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}{$where}");

        // Get examples
        $examples = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        wp_send_json_success(array(
            'examples' => $examples,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
            'filters' => array(
                'type' => $filter_type,
                'rating' => $filter_rating,
                'tag' => $filter_tag,
                'search' => $search_query,
                'date_from' => $date_from,
                'date_to' => $date_to,
            )
        ));
    }

    /**
     * AJAX: Delete training example
     */
    public function ajax_delete_training_example() {
        check_ajax_referer('mld_training_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (empty($id)) {
            wp_send_json_error(array('message' => 'Invalid ID'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_training';

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete training example'));
        }

        wp_send_json_success(array('message' => 'Training example deleted'));
    }
}

// Initialize the training admin page
new MLD_Chatbot_Training();
