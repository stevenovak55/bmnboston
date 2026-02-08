<?php
/**
 * Report & Monitor AJAX Handlers — extracted from Flip_Admin_Dashboard in v0.14.0.
 *
 * Handles all AJAX actions related to saved reports and monitors:
 * get, load, rename, re-run, delete reports, and create monitors.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Report_AJAX {

    /**
     * Register AJAX hooks.
     */
    public static function init(): void {
        add_action('wp_ajax_flip_get_reports', [__CLASS__, 'ajax_get_reports']);
        add_action('wp_ajax_flip_load_report', [__CLASS__, 'ajax_load_report']);
        add_action('wp_ajax_flip_rename_report', [__CLASS__, 'ajax_rename_report']);
        add_action('wp_ajax_flip_rerun_report', [__CLASS__, 'ajax_rerun_report']);
        add_action('wp_ajax_flip_rerun_init', [__CLASS__, 'ajax_rerun_init']);
        add_action('wp_ajax_flip_delete_report', [__CLASS__, 'ajax_delete_report']);
        add_action('wp_ajax_flip_create_monitor', [__CLASS__, 'ajax_create_monitor']);
    }

    /**
     * AJAX: Get all saved reports.
     */
    public static function ajax_get_reports(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        wp_send_json_success(self::get_reports_list());
    }

    /**
     * AJAX: Load a saved report's full dashboard data.
     */
    public static function ajax_load_report(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        if ($report_id <= 0) {
            wp_send_json_error('Invalid report ID.');
        }

        $report = Flip_Database::get_report($report_id);
        if (!$report || $report->status === 'deleted') {
            wp_send_json_error('Report not found.');
        }

        wp_send_json_success(Flip_Admin_Dashboard::get_dashboard_data($report_id));
    }

    /**
     * AJAX: Rename a saved report.
     */
    public static function ajax_rename_report(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        $new_name  = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($report_id <= 0 || empty($new_name)) {
            wp_send_json_error('Invalid report ID or name.');
        }

        Flip_Database::update_report($report_id, ['name' => $new_name]);

        wp_send_json_success([
            'reports' => self::get_reports_list(),
            'message' => 'Report renamed.',
        ]);
    }

    /**
     * AJAX: Re-run a report with fresh MLS data (replaces old results).
     */
    public static function ajax_rerun_report(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        set_time_limit(300);

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        if ($report_id <= 0) {
            wp_send_json_error('Invalid report ID.');
        }

        $report = Flip_Database::get_report($report_id);
        if (!$report || $report->status === 'deleted') {
            wp_send_json_error('Report not found.');
        }

        // Concurrency lock — prevent overlapping runs on the same report
        $lock_key = 'flip_report_lock_' . $report_id;
        if (get_transient($lock_key)) {
            wp_send_json_error('This report is already being processed. Please wait.');
        }
        set_transient($lock_key, true, 900);

        // Restore the report's original cities + filters
        $cities  = json_decode($report->cities_json, true) ?: [];
        $filters = json_decode($report->filters_json, true) ?: [];

        // Run new analysis first (pass cities via option, don't mutate global state)
        $messages = [];
        $result = Flip_Analyzer::run(
            ['filters' => $filters, 'report_id' => $report_id, 'city' => implode(',', $cities)],
            function ($msg) use (&$messages) {
                $messages[] = $msg;
            }
        );

        global $wpdb;
        $table = Flip_Database::table_name();

        // Delete ALL old scores for this report after new run (even if 0 results,
        // so stale data from a previous run doesn't persist with updated metadata)
        $new_run_date = $result['run_date'] ?? '';
        if ($new_run_date) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE report_id = %d AND run_date != %s",
                $report_id, $new_run_date
            ));
        }

        delete_transient($lock_key);

        // Update report metadata
        $now = current_time('mysql');
        $viable_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE report_id = %d AND disqualified = 0 AND total_score >= 60",
            $report_id
        ));
        $total_count = (int) ($result['analyzed'] ?? 0);

        Flip_Database::update_report($report_id, [
            'last_run_date'  => $now,
            'run_count'      => (int) $report->run_count + 1,
            'property_count' => $total_count,
            'viable_count'   => $viable_count,
        ]);

        $result['messages']  = $messages;
        $result['dashboard'] = Flip_Admin_Dashboard::get_dashboard_data($report_id);
        $result['report_id'] = $report_id;
        $result['reports']   = self::get_reports_list();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Batched report re-run — Phase 1: Init.
     *
     * Restores original criteria, fetches listing IDs, deletes old scores,
     * sets concurrency lock. Client then sends batches via flip_analysis_batch.
     */
    public static function ajax_rerun_init(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        if ($report_id <= 0) {
            wp_send_json_error('Invalid report ID.');
        }

        $report = Flip_Database::get_report($report_id);
        if (!$report || $report->status === 'deleted') {
            wp_send_json_error('Report not found.');
        }

        // Concurrency lock
        $lock_key = 'flip_analysis_lock_' . $report_id;
        if (get_transient($lock_key)) {
            wp_send_json_error('This report is already being processed. Please wait.');
        }
        set_transient($lock_key, true, 900);

        // Restore original criteria
        $cities  = json_decode($report->cities_json, true) ?: [];
        $filters = json_decode($report->filters_json, true) ?: [];

        // Pre-compute city metrics
        Flip_Location_Scorer::precompute_city_metrics($cities);

        // Fetch matching listing IDs
        $listing_ids = Flip_Property_Fetcher::fetch_matching_listing_ids($cities, $filters);

        // Delete old scores for this report (fresh re-run)
        global $wpdb;
        $table = Flip_Database::table_name();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE report_id = %d",
            $report_id
        ));

        $run_date = current_time('mysql');

        wp_send_json_success([
            'report_id'   => $report_id,
            'listing_ids' => array_values($listing_ids),
            'total_count' => count($listing_ids),
            'cities'      => $cities,
            'run_date'    => $run_date,
            'run_count'   => (int) $report->run_count + 1,
        ]);
    }

    /**
     * AJAX: Soft-delete a saved report.
     */
    public static function ajax_delete_report(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        if ($report_id <= 0) {
            wp_send_json_error('Invalid report ID.');
        }

        Flip_Database::delete_report($report_id);

        wp_send_json_success([
            'reports' => self::get_reports_list(),
            'message' => 'Report deleted.',
        ]);
    }

    /**
     * AJAX: Create a monitor from current criteria.
     */
    public static function ajax_create_monitor(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $name      = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $frequency = isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'daily';
        $email     = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $notification_level = isset($_POST['notification_level']) ? sanitize_text_field(wp_unslash($_POST['notification_level'])) : 'viable_only';

        $allowed_levels = ['viable_only', 'viable_and_near', 'all'];
        if (!in_array($notification_level, $allowed_levels, true)) {
            $notification_level = 'viable_only';
        }

        if (empty($name)) {
            wp_send_json_error('Monitor name is required.');
        }

        $allowed_freqs = ['daily', 'twice_daily', 'weekly'];
        if (!in_array($frequency, $allowed_freqs, true)) {
            $frequency = 'daily';
        }

        // Enforce report cap
        if (Flip_Database::count_reports() >= Flip_Database::MAX_REPORTS) {
            wp_send_json_error('Maximum of ' . Flip_Database::MAX_REPORTS . ' reports reached. Delete old reports first.');
        }

        $cities  = Flip_Database::get_target_cities();
        $filters = Flip_Database::get_analysis_filters();

        $report_id = Flip_Database::create_report([
            'name'               => $name,
            'type'               => 'monitor',
            'cities_json'        => wp_json_encode($cities),
            'filters_json'       => wp_json_encode($filters),
            'monitor_frequency'  => $frequency,
            'notification_email' => $email,
            'notification_level' => $notification_level,
            'created_by'         => get_current_user_id(),
        ]);

        if (!$report_id) {
            wp_send_json_error('Failed to create monitor. Maximum of ' . Flip_Database::MAX_REPORTS . ' reports reached.');
        }

        // Mark all currently matching listings as "seen" so only future ones trigger
        $listing_ids = Flip_Property_Fetcher::fetch_matching_listing_ids($cities, $filters);
        if (!empty($listing_ids)) {
            Flip_Database::mark_listings_seen($report_id, $listing_ids);
        }

        wp_send_json_success([
            'reports' => self::get_reports_list(),
            'message' => 'Monitor created. It will check for new listings ' . str_replace('_', ' ', $frequency) . '.',
        ]);
    }

    /* ─── Helpers ──────────────────────────────────────────── */

    /**
     * Get lightweight reports list for JS.
     */
    public static function get_reports_list(): array {
        $reports = Flip_Database::get_reports();
        $list = [];

        foreach ($reports as $r) {
            $list[] = [
                'id'               => (int) $r->id,
                'name'             => $r->name,
                'type'             => $r->type,
                'status'           => $r->status,
                'property_count'   => (int) $r->property_count,
                'viable_count'     => (int) $r->viable_count,
                'run_count'        => (int) $r->run_count,
                'run_date'         => $r->run_date,
                'last_run_date'    => $r->last_run_date,
                'monitor_frequency'      => $r->monitor_frequency,
                'monitor_last_new_count' => (int) ($r->monitor_last_new_count ?? 0),
                'notification_level'     => $r->notification_level ?? 'viable_only',
                'created_at'             => $r->created_at,
            ];
        }

        return $list;
    }
}
