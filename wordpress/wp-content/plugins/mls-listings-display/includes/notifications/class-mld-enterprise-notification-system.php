<?php
/**
 * Enterprise Notification System for MLS Listings Display
 *
 * A comprehensive, scalable notification system matching industry leaders
 * like Redfin, Zillow, and Homes.com. Features queue-based processing,
 * smart matching, analytics, and multi-channel delivery.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 5.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Enterprise_Notification_System {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * System components
     */
    private $queue_manager;
    private $matcher;
    private $template_engine;
    private $analytics;
    private $throttler;
    private $preference_manager;

    /**
     * Configuration
     */
    private $config = [
        'batch_size' => 50,
        'max_retries' => 3,
        'retry_delay' => 300, // 5 minutes
        'rate_limit' => 100, // emails per minute
        'queue_processing_interval' => 60, // 1 minute
        'analytics_enabled' => true,
        'test_mode' => false
    ];

    /**
     * Notification types
     */
    const TYPE_INSTANT = 'instant';
    const TYPE_HOURLY = 'hourly';
    const TYPE_DAILY = 'daily';
    const TYPE_WEEKLY = 'weekly';
    const TYPE_PRICE_DROP = 'price_drop';
    const TYPE_NEW_LISTING = 'new_listing';
    const TYPE_OPEN_HOUSE = 'open_house';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_SIMILAR_HOMES = 'similar_homes';
    const TYPE_MARKET_UPDATE = 'market_update';

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize the notification system
     */
    private function init() {
        // Load configuration
        $this->load_config();

        // Initialize components
        $this->init_components();

        // Register hooks
        $this->register_hooks();

        // Schedule cron jobs
        $this->schedule_cron_jobs();

        // Initialize database tables
        $this->init_database();

        $this->log('Enterprise Notification System initialized', 'info');
    }

    /**
     * Load configuration
     */
    private function load_config() {
        $saved_config = get_option('mld_enterprise_notification_config', []);
        $this->config = wp_parse_args($saved_config, $this->config);

        // Check if in test mode
        $this->config['test_mode'] = defined('MLD_NOTIFICATION_TEST_MODE') && MLD_NOTIFICATION_TEST_MODE;
    }

    /**
     * Initialize system components
     */
    private function init_components() {
        // Queue Manager for handling notification queue
        require_once plugin_dir_path(__FILE__) . 'class-mld-notification-queue-manager.php';
        $this->queue_manager = new MLD_Notification_Queue_Manager();

        // Property Matcher for intelligent matching
        require_once plugin_dir_path(__FILE__) . 'class-mld-smart-property-matcher.php';
        $this->matcher = new MLD_Smart_Property_Matcher();

        // Template Engine for email generation
        require_once plugin_dir_path(__FILE__) . 'class-mld-template-engine.php';
        $this->template_engine = new MLD_Template_Engine();

        // Analytics for tracking and insights
        require_once plugin_dir_path(__FILE__) . 'class-mld-notification-analytics.php';
        $this->analytics = new MLD_Notification_Analytics();

        // Throttler for rate limiting
        require_once plugin_dir_path(__FILE__) . 'class-mld-notification-throttler.php';
        $this->throttler = new MLD_Notification_Throttler($this->config['rate_limit']);

        // Preference Manager for user settings
        require_once plugin_dir_path(__FILE__) . 'class-mld-preference-manager.php';
        $this->preference_manager = new MLD_Preference_Manager();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Property change detection
        add_action('bme_property_imported', [$this, 'on_property_imported'], 10, 2);
        add_action('bme_property_updated', [$this, 'on_property_updated'], 10, 2);
        add_action('bme_property_deleted', [$this, 'on_property_deleted'], 10, 1);

        // Queue processing
        add_action('mld_process_notification_queue', [$this, 'process_queue']);
        add_action('mld_send_hourly_digest', [$this, 'send_hourly_digest']);
        add_action('mld_send_daily_digest', [$this, 'send_daily_digest']);
        add_action('mld_send_weekly_digest', [$this, 'send_weekly_digest']);

        // Cleanup and maintenance
        add_action('mld_cleanup_notifications', [$this, 'cleanup_old_data']);
        add_action('mld_analyze_notifications', [$this, 'run_analytics']);

        // Admin interface
        if (is_admin()) {
            add_action('wp_ajax_mld_test_notification', [$this, 'handle_test_notification']);
            add_action('wp_ajax_mld_preview_email', [$this, 'handle_email_preview']);
            add_action('wp_ajax_mld_notification_stats', [$this, 'get_notification_stats']);
        }

        // User preference management
        add_action('wp_ajax_mld_update_notification_preferences', [$this, 'handle_preference_update']);
        add_action('init', [$this, 'handle_unsubscribe']);

        // Email tracking
        add_action('init', [$this, 'track_email_engagement']);
    }

    /**
     * Schedule cron jobs
     */
    private function schedule_cron_jobs() {
        // Queue processing (every minute)
        if (!wp_next_scheduled('mld_process_notification_queue')) {
            wp_schedule_event(time(), 'mld_every_minute', 'mld_process_notification_queue');
        }

        // Hourly digest
        if (!wp_next_scheduled('mld_send_hourly_digest')) {
            wp_schedule_event(time(), 'hourly', 'mld_send_hourly_digest');
        }

        // Daily digest (9 AM local time)
        if (!wp_next_scheduled('mld_send_daily_digest')) {
            $next_9am = $this->get_next_time(9, 0);
            wp_schedule_event($next_9am, 'daily', 'mld_send_daily_digest');
        }

        // Weekly digest (Monday 9 AM)
        if (!wp_next_scheduled('mld_send_weekly_digest')) {
            $next_monday = $this->get_next_weekday(1, 9, 0);
            wp_schedule_event($next_monday, 'weekly', 'mld_send_weekly_digest');
        }

        // Cleanup (daily at 2 AM)
        if (!wp_next_scheduled('mld_cleanup_notifications')) {
            $next_2am = $this->get_next_time(2, 0);
            wp_schedule_event($next_2am, 'daily', 'mld_cleanup_notifications');
        }

        // Analytics (every 6 hours)
        if (!wp_next_scheduled('mld_analyze_notifications')) {
            wp_schedule_event(time(), 'mld_six_hours', 'mld_analyze_notifications');
        }

        // Add custom schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['mld_every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'mld')
        ];

        $schedules['mld_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'mld')
        ];

        return $schedules;
    }

    /**
     * Initialize database tables
     */
    private function init_database() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Notification queue table
        $queue_table = $wpdb->prefix . 'mld_notification_queue';
        $queue_sql = "CREATE TABLE IF NOT EXISTS $queue_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            search_id bigint(20) UNSIGNED NOT NULL,
            listing_id varchar(100) NOT NULL,
            notification_type varchar(50) NOT NULL,
            priority int(11) DEFAULT 5,
            data longtext,
            status enum('pending','processing','sent','failed') DEFAULT 'pending',
            retry_count int(11) DEFAULT 0,
            scheduled_at datetime DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_status (user_id, status),
            KEY idx_scheduled (scheduled_at, status),
            KEY idx_search_listing (search_id, listing_id)
        ) $charset_collate;";

        // Analytics table
        $analytics_table = $wpdb->prefix . 'mld_notification_analytics';
        $analytics_sql = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            ip_address varchar(45),
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notification (notification_id),
            KEY idx_user_event (user_id, event_type),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // User preferences table
        $preferences_table = $wpdb->prefix . 'mld_notification_preferences';
        $preferences_sql = "CREATE TABLE IF NOT EXISTS $preferences_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            preference_key varchar(100) NOT NULL,
            preference_value text,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_key (user_id, preference_key)
        ) $charset_collate;";

        // Sent notifications history
        $history_table = $wpdb->prefix . 'mld_notification_history';
        $history_sql = "CREATE TABLE IF NOT EXISTS $history_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            search_id bigint(20) UNSIGNED NOT NULL,
            notification_type varchar(50) NOT NULL,
            listing_ids text,
            subject varchar(255),
            template_used varchar(100),
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            opened_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            unsubscribed_at datetime DEFAULT NULL,
            bounce_type varchar(50) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user_sent (user_id, sent_at),
            KEY idx_search (search_id),
            KEY idx_tracking (opened_at, clicked_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($queue_sql);
        dbDelta($analytics_sql);
        dbDelta($preferences_sql);
        dbDelta($history_sql);
    }

    /**
     * Handle property imported event
     */
    public function on_property_imported($listing_id, $listing_data) {
        $this->log("Property imported: $listing_id", 'debug');

        // Find matching saved searches
        $matches = $this->matcher->find_matching_searches($listing_data);

        if (empty($matches)) {
            return;
        }

        // Queue notifications for each match
        foreach ($matches as $match) {
            $this->queue_notification([
                'user_id' => $match['user_id'],
                'search_id' => $match['search_id'],
                'listing_id' => $listing_id,
                'notification_type' => self::TYPE_NEW_LISTING,
                'priority' => $this->calculate_priority($match, $listing_data),
                'data' => json_encode([
                    'listing' => $listing_data,
                    'match_score' => $match['score'],
                    'match_reasons' => $match['reasons']
                ])
            ]);
        }

        // Check for similar homes for active users
        $this->check_similar_homes($listing_data);
    }

    /**
     * Handle property updated event
     */
    public function on_property_updated($listing_id, $changes) {
        $this->log("Property updated: $listing_id", 'debug');

        // Check for price changes
        if (isset($changes['list_price'])) {
            $this->handle_price_change($listing_id, $changes);
        }

        // Check for status changes
        if (isset($changes['mls_status'])) {
            $this->handle_status_change($listing_id, $changes);
        }

        // Check for open house updates
        if (isset($changes['open_house_date'])) {
            $this->handle_open_house($listing_id, $changes);
        }
    }

    /**
     * Queue a notification
     */
    private function queue_notification($data) {
        global $wpdb;

        // Check user preferences
        $preferences = $this->preference_manager->get_user_preferences($data['user_id']);

        if (!$this->should_send_notification($data, $preferences)) {
            return false;
        }

        // Check for duplicates
        if ($this->is_duplicate_notification($data)) {
            return false;
        }

        // Calculate scheduled time based on user preferences
        $scheduled_at = $this->calculate_scheduled_time($data['user_id'], $data['notification_type']);

        // Insert into queue
        $result = $wpdb->insert(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'user_id' => $data['user_id'],
                'search_id' => $data['search_id'],
                'listing_id' => $data['listing_id'],
                'notification_type' => $data['notification_type'],
                'priority' => $data['priority'] ?? 5,
                'data' => $data['data'] ?? null,
                'status' => 'pending',
                'scheduled_at' => $scheduled_at
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($result) {
            $this->log("Notification queued for user {$data['user_id']}", 'debug');
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Process notification queue
     */
    public function process_queue() {
        global $wpdb;

        // Skip if already processing
        if (get_transient('mld_queue_processing')) {
            return;
        }

        // Set processing flag
        set_transient('mld_queue_processing', true, 300); // 5 minutes

        try {
            // Get pending notifications
            // Use WordPress timezone-aware time instead of MySQL NOW()
            $wp_now = current_time('mysql');
            $notifications = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}mld_notification_queue
                WHERE status = 'pending'
                AND (scheduled_at IS NULL OR scheduled_at <= %s)
                AND retry_count < %d
                ORDER BY priority DESC, created_at ASC
                LIMIT %d
            ", $wp_now, $this->config['max_retries'], $this->config['batch_size']));

            if (empty($notifications)) {
                delete_transient('mld_queue_processing');
                return;
            }

            // Group notifications by user and type for batching
            $grouped = $this->group_notifications($notifications);

            foreach ($grouped as $group) {
                // Check throttling
                if (!$this->throttler->can_send($group['user_id'])) {
                    $this->reschedule_notifications($group['notifications'], 300); // 5 minutes
                    continue;
                }

                // Send notification
                $sent = $this->send_notification($group);

                if ($sent) {
                    $this->mark_notifications_sent($group['notifications']);
                    $this->throttler->record_sent($group['user_id']);

                    // Track analytics
                    if ($this->config['analytics_enabled']) {
                        $this->analytics->track_sent($group);
                    }
                } else {
                    $this->handle_send_failure($group['notifications']);
                }
            }

        } catch (Exception $e) {
            $this->log('Queue processing error: ' . $e->getMessage(), 'error');
        }

        delete_transient('mld_queue_processing');
    }

    /**
     * Send notification
     */
    private function send_notification($group) {
        $user = get_user_by('id', $group['user_id']);
        if (!$user) {
            return false;
        }

        // Get template based on notification type
        $template = $this->get_template($group['type']);

        // Prepare template data
        $template_data = $this->prepare_template_data($group);

        // Generate email content
        $email_content = $this->template_engine->render($template, $template_data);

        // Add tracking pixels and links
        if ($this->config['analytics_enabled']) {
            $email_content = $this->add_tracking($email_content, $group);
        }

        // Prepare headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email')
        ];

        // Add custom headers for tracking
        if ($this->config['analytics_enabled']) {
            $headers[] = 'X-MLD-Notification-ID: ' . $group['notification_id'];
            $headers[] = 'X-MLD-User-ID: ' . $group['user_id'];
            $headers[] = 'List-Unsubscribe: <' . $this->get_unsubscribe_url($group) . '>';
        }

        // Send email
        $subject = $this->generate_subject($group);
        $sent = wp_mail($user->user_email, $subject, $email_content, $headers);

        // Send to additional channels if configured
        $this->send_to_additional_channels($group, $template_data);

        // Log result
        if ($sent) {
            $this->log("Email sent to {$user->user_email} for {$group['type']}", 'info');
            $this->record_sent_notification($group);
        } else {
            $this->log("Failed to send email to {$user->user_email}", 'error');
        }

        return $sent;
    }

    /**
     * Get template for notification type
     */
    private function get_template($type) {
        $template_map = [
            self::TYPE_NEW_LISTING => 'listing-updates-premium.php',
            self::TYPE_PRICE_DROP => 'price-drop-premium.php',
            self::TYPE_OPEN_HOUSE => 'open-house-premium.php',
            self::TYPE_DAILY => 'daily-digest-premium.php',
            self::TYPE_WEEKLY => 'weekly-digest-premium.php',
            self::TYPE_MARKET_UPDATE => 'market-update-premium.php'
        ];

        $template = $template_map[$type] ?? 'listing-updates-premium.php';

        // Check for A/B test templates
        if ($this->should_use_ab_test($type)) {
            $template = $this->get_ab_test_template($template);
        }

        return $template;
    }

    /**
     * Prepare template data
     */
    private function prepare_template_data($group) {
        global $wpdb;

        $user = get_user_by('id', $group['user_id']);
        $search = $this->get_saved_search($group['search_id']);

        // Get listings data
        $listings = [];
        foreach ($group['listing_ids'] as $listing_id) {
            $listing = $this->get_listing_data($listing_id);
            if ($listing) {
                $listings[] = $listing;
            }
        }

        // Get market insights
        $market_insights = $this->get_market_insights($search);

        // Get similar homes
        $similar_homes = $this->get_similar_homes($listings[0] ?? null);

        // Prepare data array
        return [
            'user' => $user,
            'search' => $search,
            'search_name' => $search->name,
            'listings' => $listings,
            'listing_count' => count($listings),
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'unsubscribe_url' => $this->get_unsubscribe_url($group),
            'preference_url' => $this->get_preference_url($group['user_id']),
            'market_insights' => $market_insights,
            'similar_homes' => $similar_homes,
            'notification_frequency' => $search->notification_frequency,
            'tracking_params' => $this->get_tracking_params($group)
        ];
    }

    /**
     * Send hourly digest
     */
    public function send_hourly_digest() {
        $this->send_digest(self::TYPE_HOURLY);
    }

    /**
     * Send daily digest
     */
    public function send_daily_digest() {
        $this->send_digest(self::TYPE_DAILY);
    }

    /**
     * Send weekly digest
     */
    public function send_weekly_digest() {
        $this->send_digest(self::TYPE_WEEKLY);
    }

    /**
     * Send digest notifications
     */
    private function send_digest($frequency) {
        global $wpdb;

        $this->log("Processing $frequency digest", 'info');

        // Get users with this frequency preference
        $users = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT user_id
            FROM {$wpdb->prefix}mld_saved_searches
            WHERE notification_frequency = %s
            AND is_active = 1
        ", $frequency));

        foreach ($users as $user_data) {
            $this->process_user_digest($user_data->user_id, $frequency);
        }
    }

    /**
     * Process digest for a single user
     */
    private function process_user_digest($user_id, $frequency) {
        global $wpdb;

        // Get time range for digest
        $time_range = $this->get_digest_time_range($frequency);

        // Get all matching listings for user's saved searches
        $listings = $this->get_user_digest_listings($user_id, $time_range);

        if (empty($listings)) {
            return;
        }

        // Group by saved search
        $grouped_listings = $this->group_listings_by_search($listings);

        // Prepare and send digest
        $this->send_digest_email($user_id, $grouped_listings, $frequency);
    }

    /**
     * Track email engagement
     */
    public function track_email_engagement() {
        if (!isset($_GET['mld_track'])) {
            return;
        }

        $tracking_data = base64_decode($_GET['mld_track']);
        $data = json_decode($tracking_data, true);

        if (!$data || !isset($data['notification_id'])) {
            return;
        }

        $event_type = $_GET['event'] ?? 'open';

        // Record engagement
        $this->analytics->track_engagement([
            'notification_id' => $data['notification_id'],
            'user_id' => $data['user_id'] ?? 0,
            'event_type' => $event_type,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Return tracking pixel for email opens
        if ($event_type === 'open') {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        // Redirect for click tracking
        if ($event_type === 'click' && isset($_GET['url'])) {
            wp_redirect(urldecode($_GET['url']));
            exit;
        }
    }

    /**
     * Handle unsubscribe requests
     */
    public function handle_unsubscribe() {
        if (!isset($_GET['mld_unsubscribe'])) {
            return;
        }

        $token = $_GET['mld_unsubscribe'];
        $data = $this->verify_unsubscribe_token($token);

        if (!$data) {
            wp_die('Invalid unsubscribe link');
        }

        // Process unsubscribe
        $this->preference_manager->unsubscribe_user($data['user_id'], $data['search_id']);

        // Show confirmation page
        $this->show_unsubscribe_confirmation($data);
        exit;
    }

    /**
     * Run analytics
     */
    public function run_analytics() {
        $this->analytics->generate_reports();
        $this->analytics->calculate_metrics();
        $this->analytics->identify_trends();
    }

    /**
     * Cleanup old data
     */
    public function cleanup_old_data() {
        global $wpdb;

        // Use WordPress timezone-aware time for cleanup operations
        $wp_now = current_time('mysql');

        // Remove old queue items
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->prefix}mld_notification_queue
            WHERE status IN ('sent', 'failed')
            AND created_at < DATE_SUB(%s, INTERVAL %d DAY)
        ", $wp_now, 30));

        // Archive old analytics
        $this->analytics->archive_old_data(90);

        // Clean up history
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->prefix}mld_notification_history
            WHERE sent_at < DATE_SUB(%s, INTERVAL %d DAY)
        ", $wp_now, 180));

        $this->log('Cleanup completed', 'info');
    }

    /**
     * Test notification handler for admin
     */
    public function handle_test_notification() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('mld_admin_nonce', 'nonce');

        $user_id = intval($_POST['user_id'] ?? get_current_user_id());
        $type = sanitize_text_field($_POST['type'] ?? self::TYPE_NEW_LISTING);

        // Create test data
        $test_listings = $this->generate_test_listings();

        // Queue test notification
        $notification_id = $this->queue_notification([
            'user_id' => $user_id,
            'search_id' => 0, // Test search
            'listing_id' => 'TEST_' . time(),
            'notification_type' => $type,
            'priority' => 10, // High priority for test
            'data' => json_encode(['test' => true, 'listings' => $test_listings])
        ]);

        // Process immediately
        $this->process_queue();

        wp_send_json_success([
            'message' => 'Test notification sent',
            'notification_id' => $notification_id
        ]);
    }

    /**
     * Get notification statistics
     */
    public function get_notification_stats() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = $this->analytics->get_dashboard_stats();
        wp_send_json_success($stats);
    }

    /**
     * Helper: Calculate priority
     */
    private function calculate_priority($match, $listing) {
        $priority = 5; // Default

        // Higher priority for better matches
        if ($match['score'] > 0.9) {
            $priority += 2;
        }

        // Higher priority for new listings
        if (strtotime($listing->listing_contract_date) > strtotime('-24 hours')) {
            $priority += 1;
        }

        // Higher priority for price drops
        if (!empty($listing->original_list_price) && $listing->list_price < $listing->original_list_price) {
            $priority += 1;
        }

        return min($priority, 10); // Max priority is 10
    }

    /**
     * Helper: Get next occurrence of specific time
     */
    private function get_next_time($hour, $minute) {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        $scheduled = new DateTime('today', $timezone);
        $scheduled->setTime($hour, $minute);

        if ($scheduled <= $now) {
            $scheduled->modify('+1 day');
        }

        return $scheduled->getTimestamp();
    }

    /**
     * Helper: Get next occurrence of weekday at specific time
     */
    private function get_next_weekday($day, $hour, $minute) {
        $timezone = wp_timezone();
        $scheduled = new DateTime('now', $timezone);

        // Get next occurrence of the weekday
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $scheduled->modify('next ' . $days[$day]);
        $scheduled->setTime($hour, $minute);

        return $scheduled->getTimestamp();
    }

    /**
     * Log message
     */
    private function log($message, $level = 'info') {
        if ($this->config['test_mode'] || WP_DEBUG) {
            error_log("[MLD Enterprise Notification][$level] $message");
        }

        // Also log to database for analytics
        if ($level === 'error') {
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'mld_notification_logs',
                [
                    'level' => $level,
                    'message' => $message,
                    'created_at' => current_time('mysql')
                ]
            );
        }
    }

    /**
     * Deactivation cleanup
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('mld_process_notification_queue');
        wp_clear_scheduled_hook('mld_send_hourly_digest');
        wp_clear_scheduled_hook('mld_send_daily_digest');
        wp_clear_scheduled_hook('mld_send_weekly_digest');
        wp_clear_scheduled_hook('mld_cleanup_notifications');
        wp_clear_scheduled_hook('mld_analyze_notifications');
    }
}

// Initialize the system
add_action('init', function() {
    MLD_Enterprise_Notification_System::get_instance();
});

// Handle deactivation
register_deactivation_hook(__FILE__, ['MLD_Enterprise_Notification_System', 'deactivate']);