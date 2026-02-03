<?php
/**
 * MLD Queue Processor - Process queued notifications
 *
 * Handles processing of notifications that were queued due to throttling or quiet hours
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Queue_Processor {

    /**
     * Maximum items to process per batch
     */
    const BATCH_SIZE = 20;

    /**
     * Maximum retry attempts before marking as failed
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Notification router instance
     */
    private $notification_router = null;

    /**
     * Throttle manager instance
     */
    private $throttle_manager = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Schedule cron hooks
        add_action('mld_process_notification_queue', [$this, 'process_queue']);
        add_action('mld_cleanup_notification_queue', [$this, 'cleanup_expired']);

        $this->log('Queue Processor initialized', 'debug');
    }

    /**
     * Set dependencies
     */
    public function set_dependencies($router, $throttle_manager) {
        $this->notification_router = $router;
        $this->throttle_manager = $throttle_manager;
    }

    /**
     * Process queued notifications
     *
     * @return array Results of processing
     */
    public function process_queue() {
        global $wpdb;

        $start_time = microtime(true);
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $requeued = 0;

        // Get notifications ready to be processed
        $queued_items = $this->get_processable_notifications();

        if (empty($queued_items)) {
            $this->log('No notifications ready to process', 'debug');
            return ['processed' => 0, 'sent' => 0, 'failed' => 0];
        }

        $this->log('Processing ' . count($queued_items) . ' queued notifications', 'info');

        foreach ($queued_items as $item) {
            // Mark as processing to prevent duplicate processing
            $this->mark_as_processing($item->id);

            // Check if we can send now
            if (!$this->can_send_now($item)) {
                $this->requeue_notification($item);
                $requeued++;
                continue;
            }

            // Process the notification
            $result = $this->process_notification($item);

            if ($result['success']) {
                $this->mark_as_sent($item->id);
                $sent++;
            } else {
                if ($item->retry_attempts >= self::MAX_RETRY_ATTEMPTS) {
                    $this->mark_as_failed($item->id, $result['error'] ?? 'Max retries exceeded');
                    $failed++;
                } else {
                    $this->requeue_notification($item, $result['error'] ?? null);
                    $requeued++;
                }
            }

            $processed++;
        }

        $execution_time = microtime(true) - $start_time;

        $this->log(sprintf(
            'Queue processing complete: %d processed, %d sent, %d failed, %d requeued in %.2f seconds',
            $processed,
            $sent,
            $failed,
            $requeued,
            $execution_time
        ), 'info');

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'requeued' => $requeued,
            'execution_time' => $execution_time
        ];
    }

    /**
     * Get notifications ready to be processed
     */
    private function get_processable_notifications() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_queue';
        $current_time = current_time('mysql');

        // Get queued items where retry_after has passed
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, s.user_id as search_user_id, s.name as search_name, s.filters
             FROM {$table} q
             JOIN {$wpdb->prefix}mld_saved_searches s ON q.saved_search_id = s.id
             WHERE q.status = 'queued'
             AND q.retry_after <= %s
             AND q.retry_attempts < %d
             AND s.is_active = 1
             ORDER BY q.retry_after ASC
             LIMIT %d",
            $current_time,
            self::MAX_RETRY_ATTEMPTS,
            self::BATCH_SIZE
        ));

        return $items;
    }

    /**
     * Check if notification can be sent now
     */
    private function can_send_now($item) {
        // Check if user still wants this type of notification
        if (!$this->user_wants_notification($item)) {
            $this->log("User {$item->user_id} no longer wants notifications for search {$item->saved_search_id}", 'debug');
            return false;
        }

        // Check throttling (but with force flag for queued items)
        if ($this->throttle_manager) {
            $can_send = $this->throttle_manager->should_send_instant(
                $item->user_id,
                $item->saved_search_id,
                $item->match_type,
                json_decode($item->listing_data, true)
            );

            // If still blocked, check if it's a different reason
            if (is_array($can_send) && isset($can_send['blocked'])) {
                // If it's the same reason or a new blocking reason, we should requeue
                $this->log("Notification still blocked: {$can_send['reason']}", 'debug');
                return false;
            }
        }

        return true;
    }

    /**
     * Process a single notification
     */
    private function process_notification($item) {
        if (!$this->notification_router) {
            return ['success' => false, 'error' => 'Notification router not available'];
        }

        // Prepare search object
        $search = (object)[
            'id' => $item->saved_search_id,
            'user_id' => $item->user_id,
            'name' => $item->search_name,
            'filters' => $item->filters
        ];

        // Prepare listing data
        $listing_data = json_decode($item->listing_data, true);
        if (!$listing_data) {
            // If no listing data stored, fetch from database
            $listing_data = $this->fetch_listing_data($item->listing_id);
        }

        if (!$listing_data) {
            return ['success' => false, 'error' => 'Listing data not available'];
        }

        // Send notification with force flag to skip quiet hours check
        $result = $this->notification_router->route_notification(
            $search,
            $listing_data,
            $item->match_type,
            true // Force send flag
        );

        if ($result && isset($result['success']) && $result['success']) {
            $this->log("Successfully sent queued notification for listing {$item->listing_id}", 'info');
            return ['success' => true, 'channels' => $result['channels'] ?? []];
        }

        return ['success' => false, 'error' => 'Failed to send notification'];
    }

    /**
     * Fetch listing data from database
     */
    private function fetch_listing_data($listing_id) {
        global $wpdb;

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listings WHERE listing_id = %s",
            $listing_id
        ), ARRAY_A);

        return $listing;
    }

    /**
     * Check if user still wants this notification
     */
    private function user_wants_notification($item) {
        global $wpdb;

        // Check if saved search is still active
        $search_active = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$wpdb->prefix}mld_saved_searches
             WHERE id = %d AND user_id = %d",
            $item->saved_search_id,
            $item->user_id
        ));

        return (bool)$search_active;
    }

    /**
     * Mark notification as processing
     */
    private function mark_as_processing($queue_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'status' => 'processing',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $queue_id]
        );
    }

    /**
     * Mark notification as sent
     */
    private function mark_as_sent($queue_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'status' => 'sent',
                'processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $queue_id]
        );

        // Also create a record in the activity matches table for tracking
        $this->create_activity_record($queue_id);
    }

    /**
     * Mark notification as failed
     */
    private function mark_as_failed($queue_id, $error_message = null) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'status' => 'failed',
                'error_message' => $error_message,
                'processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $queue_id]
        );
    }

    /**
     * Requeue notification for later retry
     */
    private function requeue_notification($item, $error = null) {
        global $wpdb;

        // Calculate next retry time based on attempts
        $retry_minutes = pow(2, $item->retry_attempts) * 5; // Exponential backoff: 5, 10, 20 minutes
        $retry_after = date('Y-m-d H:i:s', strtotime("+{$retry_minutes} minutes"));

        $wpdb->update(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'status' => 'queued',
                'retry_after' => $retry_after,
                'retry_attempts' => $item->retry_attempts + 1,
                'error_message' => $error,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $item->id]
        );

        $this->log("Requeued notification {$item->id} for retry at {$retry_after}", 'debug');
    }

    /**
     * Create activity record for sent notification
     */
    private function create_activity_record($queue_id) {
        global $wpdb;

        // Get queue item details
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_notification_queue WHERE id = %d",
            $queue_id
        ));

        if (!$item) {
            return;
        }

        // Insert into activity matches for record keeping
        $wpdb->insert(
            $wpdb->prefix . 'mld_search_activity_matches',
            [
                'activity_log_id' => 0,
                'saved_search_id' => $item->saved_search_id,
                'listing_id' => $item->listing_id,
                'match_type' => $item->match_type,
                'match_score' => 100,
                'notification_status' => 'sent',
                'notified_at' => current_time('mysql'),
                'notification_channels' => json_encode(['queued']),
                'created_at' => current_time('mysql')
            ]
        );
    }

    /**
     * Clean up expired queue items
     */
    public function cleanup_expired() {
        global $wpdb;

        // Mark items as expired if they've exceeded max attempts or are too old
        $expired_date = date('Y-m-d H:i:s', strtotime('-7 days'));

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}mld_notification_queue
             SET status = 'expired',
                 updated_at = %s
             WHERE (retry_attempts >= %d OR created_at < %s)
             AND status IN ('queued', 'processing')",
            current_time('mysql'),
            self::MAX_RETRY_ATTEMPTS,
            $expired_date
        ));

        if ($updated > 0) {
            $this->log("Marked {$updated} queue items as expired", 'info');
        }

        // Delete old expired/sent items (older than 30 days)
        $delete_date = date('Y-m-d H:i:s', strtotime('-30 days'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_notification_queue
             WHERE status IN ('sent', 'failed', 'expired')
             AND updated_at < %s",
            $delete_date
        ));

        if ($deleted > 0) {
            $this->log("Deleted {$deleted} old queue items", 'info');
        }
    }

    /**
     * Get queue statistics
     */
    public function get_queue_stats() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_queue';

        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(CASE WHEN status = 'queued' THEN 1 END) as queued,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired,
                COUNT(*) as total,
                MIN(retry_after) as next_retry
            FROM {$table}
            WHERE created_at >= DATE_SUB(%s, INTERVAL 7 DAY)
        ", $wp_now));

        return $stats;
    }

    /**
     * Log activity
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Skip logging during plugin activation to prevent unexpected output
            if (defined('WP_ADMIN') && WP_ADMIN &&
                isset($_GET['action']) && $_GET['action'] === 'activate') {
                return;
            }

            // Only log to file, never to browser during web requests
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf('[MLD Queue Processor] [%s] %s', $level, $message), 3, WP_CONTENT_DIR . '/debug.log');
            } elseif (php_sapi_name() === 'cli') {
                // Only output to error_log if we're in CLI mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('[MLD Queue Processor] [%s] %s', $level, $message));
                }
            }
        }
    }
}