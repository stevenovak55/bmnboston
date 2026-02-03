<?php
/**
 * MLS Listings Display - BME Hook Fallback System
 *
 * Detects property changes when BME hooks are missing or not fired
 *
 * @package MLS_Listings_Display
 * @since 4.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_BME_Hook_Fallback {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * List of hooks we're monitoring
     */
    private $monitored_hooks = [
        'bme_listing_price_increased',
        'bme_property_updated',
        'bme_open_house_scheduled'
    ];

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
        // Schedule fallback detection cron job
        add_action('init', [$this, 'schedule_fallback_detection']);
        add_action('mld_fallback_detection', [$this, 'run_fallback_detection']);

        // Hook into existing BME events to track which ones are working
        $this->monitor_existing_hooks();
    }

    /**
     * Schedule the fallback detection cron job
     */
    public function schedule_fallback_detection() {
        if (!wp_next_scheduled('mld_fallback_detection')) {
            wp_schedule_event(time(), 'hourly', 'mld_fallback_detection');
        }
    }

    /**
     * Monitor existing BME hooks to see which ones are actually firing
     */
    private function monitor_existing_hooks() {
        // Track which hooks have fired recently
        add_action('bme_listing_updated', [$this, 'mark_hook_active'], 5, 4);
        add_action('bme_listing_price_reduced', [$this, 'mark_hook_active'], 5, 4);
        add_action('bme_listing_status_changed', [$this, 'mark_hook_active'], 5, 4);

        // Monitor for missing hooks
        foreach ($this->monitored_hooks as $hook) {
            add_action($hook, [$this, 'mark_hook_active'], 5);
        }
    }

    /**
     * Mark a hook as active (recently fired)
     */
    public function mark_hook_active($hook_name) {
        $active_hooks = get_option('mld_active_bme_hooks', []);
        $active_hooks[$hook_name] = time();

        // Clean up old entries (older than 24 hours)
        $cutoff = time() - DAY_IN_SECONDS;
        $active_hooks = array_filter($active_hooks, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });

        update_option('mld_active_bme_hooks', $active_hooks);
    }

    /**
     * Run fallback detection for missing events
     */
    public function run_fallback_detection() {
        $this->detect_price_increases();
        $this->detect_property_updates();
        $this->detect_open_houses();
        $this->detect_status_changes();
        $this->detect_sold_properties();
        $this->detect_coming_soon_properties();
    }

    /**
     * Detect price increases that BME might have missed
     */
    private function detect_price_increases() {
        global $wpdb;

        // Check if bme_listing_price_increased hook is working
        $active_hooks = get_option('mld_active_bme_hooks', []);
        if (isset($active_hooks['bme_listing_price_increased']) &&
            $active_hooks['bme_listing_price_increased'] > (time() - HOUR_IN_SECONDS)) {
            return; // Hook is working, no need for fallback
        }

        // Look for price increases in the last hour using WordPress timezone
        $wp_now = current_time('mysql');
        $price_increases = $wpdb->get_results($wpdb->prepare("
            SELECT
                l.listing_id,
                l.list_price as current_price,
                ph.old_value as old_price,
                l.*
            FROM {$wpdb->prefix}bme_listings l
            JOIN {$wpdb->prefix}bme_price_history ph ON l.listing_id = ph.listing_id
            WHERE ph.change_type = %s
            AND ph.change_date >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (l.fallback_price_increase_sent IS NULL OR l.fallback_price_increase_sent = 0)
            ORDER BY ph.change_date DESC
        ", 'increase', $wp_now));

        foreach ($price_increases as $increase) {
            // Trigger our custom price increase event
            do_action('mld_fallback_price_increased',
                $increase->listing_id,
                $increase->old_price,
                $increase->current_price,
                (array) $increase
            );

            // Mark as processed
            $wpdb->update(
                $wpdb->prefix . 'bme_listings',
                ['fallback_price_increase_sent' => 1],
                ['listing_id' => $increase->listing_id],
                ['%d'],
                ['%s']
            );
        }

        if (count($price_increases) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($price_increases) . ' price increases via fallback system');
        }
    }

    /**
     * Detect property updates that BME might have missed
     */
    private function detect_property_updates() {
        global $wpdb;

        // Check if bme_property_updated hook is working
        $active_hooks = get_option('mld_active_bme_hooks', []);
        if (isset($active_hooks['bme_property_updated']) &&
            $active_hooks['bme_property_updated'] > (time() - HOUR_IN_SECONDS)) {
            return; // Hook is working
        }

        // Look for significant property changes in the last hour using WordPress timezone
        $wp_now = current_time('mysql');
        $updates = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT
                l.listing_id,
                l.*
            FROM {$wpdb->prefix}bme_listings l
            WHERE l.modification_timestamp >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (l.fallback_update_sent IS NULL OR l.fallback_update_sent = 0)
            AND l.creation_timestamp < DATE_SUB(%s, INTERVAL 1 HOUR)
        ", $wp_now, $wp_now));

        foreach ($updates as $update) {
            // Get the previous version of the listing to see what changed
            $changes = $this->detect_significant_changes($update);

            if (!empty($changes)) {
                // Trigger our custom property update event
                do_action('mld_fallback_property_updated',
                    $update->listing_id,
                    null, // old data - we don't have it in this fallback
                    (array) $update,
                    $changes
                );

                // Mark as processed
                $wpdb->update(
                    $wpdb->prefix . 'bme_listings',
                    ['fallback_update_sent' => 1],
                    ['listing_id' => $update->listing_id],
                    ['%d'],
                    ['%s']
                );
            }
        }

        if (count($updates) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($updates) . ' property updates via fallback system');
        }
    }

    /**
     * Detect open houses that BME might have missed
     */
    private function detect_open_houses() {
        global $wpdb;

        // Check if bme_open_house_scheduled hook is working
        $active_hooks = get_option('mld_active_bme_hooks', []);
        if (isset($active_hooks['bme_open_house_scheduled']) &&
            $active_hooks['bme_open_house_scheduled'] > (time() - HOUR_IN_SECONDS)) {
            return; // Hook is working
        }

        // Look for new open houses in the last hour using WordPress timezone
        $wp_now = current_time('mysql');
        $wp_today = wp_date('Y-m-d');
        $open_houses = $wpdb->get_results($wpdb->prepare("
            SELECT
                oh.*,
                l.*
            FROM {$wpdb->prefix}bme_open_houses oh
            JOIN {$wpdb->prefix}bme_listings l ON oh.listing_id = l.listing_id
            WHERE oh.created_at >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (oh.fallback_notification_sent IS NULL OR oh.fallback_notification_sent = 0)
            AND oh.open_house_date >= %s
        ", $wp_now, $wp_today));

        foreach ($open_houses as $open_house) {
            // Trigger our custom open house event
            do_action('mld_fallback_open_house_scheduled',
                $open_house->listing_id,
                (array) $open_house,
                (array) $open_house
            );

            // Mark as processed
            $wpdb->update(
                $wpdb->prefix . 'bme_open_houses',
                ['fallback_notification_sent' => 1],
                ['id' => $open_house->id],
                ['%d'],
                ['%d']
            );
        }

        if (count($open_houses) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($open_houses) . ' open houses via fallback system');
        }
    }

    /**
     * Detect significant changes that warrant notifications
     */
    private function detect_significant_changes($listing) {
        $significant_fields = [
            'bedrooms_total' => 'Bedrooms',
            'bathrooms_total' => 'Bathrooms',
            'living_area' => 'Square Footage',
            'property_type' => 'Property Type',
            'property_sub_type' => 'Property Subtype'
        ];

        $changes = [];

        // For fallback detection, we assume any modification is significant
        // since we don't have the old data to compare against
        foreach ($significant_fields as $field => $label) {
            if (isset($listing->$field)) {
                $changes[$field] = [
                    'field' => $label,
                    'old_value' => 'unknown',
                    'new_value' => $listing->$field
                ];
            }
        }

        return $changes;
    }

    /**
     * Get fallback detection status
     */
    public function get_status() {
        $active_hooks = get_option('mld_active_bme_hooks', []);
        $status = [];

        $all_hooks = [
            'bme_listing_updated',
            'bme_listing_price_reduced',
            'bme_listing_status_changed',
            'bme_listing_price_increased',
            'bme_property_updated',
            'bme_open_house_scheduled'
        ];

        foreach ($all_hooks as $hook) {
            $last_fired = $active_hooks[$hook] ?? 0;
            $status[$hook] = [
                'active' => $last_fired > (current_time('timestamp') - DAY_IN_SECONDS),
                'last_fired' => $last_fired ? wp_date('Y-m-d H:i:s', $last_fired) : 'Never',
                'using_fallback' => in_array($hook, $this->monitored_hooks) &&
                                   $last_fired < (current_time('timestamp') - HOUR_IN_SECONDS)
            ];
        }

        return $status;
    }

    /**
     * Detect status changes that BME might have missed
     */
    private function detect_status_changes() {
        global $wpdb;

        // Look for status changes in the last hour using WordPress timezone
        $wp_now = current_time('mysql');
        $status_changes = $wpdb->get_results($wpdb->prepare("
            SELECT
                l.listing_id,
                l.standard_status as current_status,
                l.*
            FROM {$wpdb->prefix}bme_listings l
            WHERE l.modification_timestamp >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (l.fallback_status_change_sent IS NULL OR l.fallback_status_change_sent = 0)
            AND l.creation_timestamp < DATE_SUB(%s, INTERVAL 1 HOUR)
        ", $wp_now, $wp_now));

        foreach ($status_changes as $change) {
            // Trigger status change event
            do_action('mld_fallback_status_changed',
                $change->listing_id,
                'Unknown', // old status - we don't have it in fallback
                $change->current_status,
                (array) $change
            );

            // Mark as processed
            $wpdb->update(
                $wpdb->prefix . 'bme_listings',
                ['fallback_status_change_sent' => 1],
                ['listing_id' => $change->listing_id],
                ['%d'],
                ['%s']
            );
        }

        if (count($status_changes) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($status_changes) . ' status changes via fallback system');
        }
    }

    /**
     * Detect sold properties that BME might have missed
     */
    private function detect_sold_properties() {
        global $wpdb;

        // Look for properties that changed to sold status using WordPress timezone
        $wp_now = current_time('mysql');
        $sold_properties = $wpdb->get_results($wpdb->prepare("
            SELECT
                l.listing_id,
                l.*
            FROM {$wpdb->prefix}bme_listings l
            WHERE l.standard_status IN (%s, %s)
            AND l.modification_timestamp >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (l.fallback_sold_sent IS NULL OR l.fallback_sold_sent = 0)
        ", 'Sold', 'Closed', $wp_now));

        foreach ($sold_properties as $sold) {
            // Trigger sold event
            do_action('mld_fallback_property_sold',
                $sold->listing_id,
                (array) $sold
            );

            // Mark as processed
            $wpdb->update(
                $wpdb->prefix . 'bme_listings',
                ['fallback_sold_sent' => 1],
                ['listing_id' => $sold->listing_id],
                ['%d'],
                ['%s']
            );
        }

        if (count($sold_properties) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($sold_properties) . ' sold properties via fallback system');
        }
    }

    /**
     * Detect coming soon properties that BME might have missed
     */
    private function detect_coming_soon_properties() {
        global $wpdb;

        // Look for properties that changed to coming soon status using WordPress timezone
        $wp_now = current_time('mysql');
        $coming_soon = $wpdb->get_results($wpdb->prepare("
            SELECT
                l.listing_id,
                l.*
            FROM {$wpdb->prefix}bme_listings l
            WHERE l.standard_status = %s
            AND l.modification_timestamp >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (l.fallback_coming_soon_sent IS NULL OR l.fallback_coming_soon_sent = 0)
        ", 'Coming Soon', $wp_now));

        foreach ($coming_soon as $property) {
            // Trigger coming soon event
            do_action('mld_fallback_coming_soon',
                $property->listing_id,
                (array) $property
            );

            // Mark as processed
            $wpdb->update(
                $wpdb->prefix . 'bme_listings',
                ['fallback_coming_soon_sent' => 1],
                ['listing_id' => $property->listing_id],
                ['%d'],
                ['%s']
            );
        }

        if (count($coming_soon) > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Fallback] Detected ' . count($coming_soon) . ' coming soon properties via fallback system');
        }
    }
}

// Initialize the fallback system
MLD_BME_Hook_Fallback::get_instance();