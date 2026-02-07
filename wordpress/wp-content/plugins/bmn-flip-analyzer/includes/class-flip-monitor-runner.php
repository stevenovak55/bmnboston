<?php
/**
 * Monitor Runner — Cron-based incremental analysis for saved monitors.
 *
 * Checks active monitors on schedule, analyzes only NEW listings,
 * and sends tiered notifications based on results.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Monitor_Runner {

    /**
     * Frequency → seconds between checks.
     */
    private const FREQUENCY_INTERVALS = [
        'twice_daily' => 43200,  // 12 hours
        'daily'       => 86400,  // 24 hours
        'weekly'      => 604800, // 7 days
    ];

    /**
     * Run all monitors that are due for a check.
     * Called by wp_cron via the 'bmn_flip_monitor_check' hook.
     */
    public static function run_all_due(): void {
        $monitors = Flip_Database::get_reports(['type' => 'monitor', 'status' => 'active']);

        if (empty($monitors)) {
            return;
        }

        foreach ($monitors as $monitor) {
            if (!self::is_due($monitor)) {
                continue;
            }

            self::run_incremental($monitor);
        }
    }

    /**
     * Check whether a monitor is due for its next check.
     */
    private static function is_due(object $monitor): bool {
        $frequency = $monitor->monitor_frequency ?? 'daily';
        $interval  = self::FREQUENCY_INTERVALS[$frequency] ?? self::FREQUENCY_INTERVALS['daily'];

        if (empty($monitor->monitor_last_check)) {
            return true;
        }

        $last_check = strtotime($monitor->monitor_last_check);
        $now        = current_time('timestamp');

        return ($now - $last_check) >= $interval;
    }

    /**
     * Run incremental analysis for a single monitor.
     *
     * 1. Fetch all listing IDs matching the monitor's criteria
     * 2. Subtract already-seen listing IDs
     * 3. If new listings found, run analysis on them
     * 4. Send tiered notifications based on results
     */
    private static function run_incremental(object $monitor): void {
        $report_id = (int) $monitor->id;

        // Concurrency lock — prevent overlapping runs on the same report
        $lock_key = 'flip_report_lock_' . $report_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, true, 900);

        $cities    = json_decode($monitor->cities_json, true) ?: [];
        $filters   = json_decode($monitor->filters_json, true) ?: [];
        $now       = current_time('mysql');

        // Step 1: Get all matching listing IDs
        $all_listing_ids = Flip_Analyzer::fetch_matching_listing_ids($cities, $filters);

        if (empty($all_listing_ids)) {
            Flip_Database::update_report($report_id, [
                'monitor_last_check'     => $now,
                'monitor_last_new_count' => 0,
            ]);
            delete_transient($lock_key);
            return;
        }

        // Step 2: Find new listings not yet seen
        $new_listing_ids = Flip_Database::get_unseen_listing_ids($report_id, $all_listing_ids);

        // Step 3: Mark existing (non-new) listings as seen — safe to do now
        $existing_ids = array_values(array_diff($all_listing_ids, $new_listing_ids));
        if (!empty($existing_ids)) {
            Flip_Database::mark_listings_seen($report_id, $existing_ids);
        }

        if (empty($new_listing_ids)) {
            Flip_Database::update_report($report_id, [
                'monitor_last_check'     => $now,
                'monitor_last_new_count' => 0,
            ]);
            delete_transient($lock_key);
            return;
        }

        // Step 4: Run analysis on new listings only
        $result = Flip_Analyzer::run([
            'filters'     => $filters,
            'report_id'   => $report_id,
            'listing_ids' => $new_listing_ids,
            'city'        => implode(',', $cities),
        ]);

        // Step 4b: Mark new listings as seen AFTER analysis completes
        // (if analysis failed, these listings will be retried on the next run)
        Flip_Database::mark_listings_seen($report_id, $new_listing_ids);

        $new_total = (int) ($result['analyzed'] ?? 0);

        // Step 5: Identify viable properties from this batch
        global $wpdb;
        $table = Flip_Database::table_name();
        $viable_results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE report_id = %d AND disqualified = 0 AND total_score >= 60
             AND listing_id IN (" . implode(',', array_map('intval', $new_listing_ids)) . ")",
            $report_id
        ));

        // Step 6: Tiered notifications
        if (!empty($viable_results)) {
            // Viable found: photo analysis + PDF + email
            self::process_viable($monitor, $viable_results);
        }

        // Step 7: Update report metadata
        $total_viable = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE report_id = %d AND disqualified = 0",
            $report_id
        ));
        $total_properties = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE report_id = %d",
            $report_id
        ));

        Flip_Database::update_report($report_id, [
            'monitor_last_check'     => $now,
            'monitor_last_new_count' => $new_total,
            'last_run_date'          => $now,
            'run_count'              => (int) $monitor->run_count + 1,
            'property_count'         => $total_properties,
            'viable_count'           => $total_viable,
        ]);

        delete_transient($lock_key);
    }

    /**
     * Process viable properties: run photo analysis, generate PDFs, send email.
     */
    private static function process_viable(object $monitor, array $viable_results): void {
        $report_id   = (int) $monitor->id;
        $listing_ids = array_map(function ($r) {
            return (int) $r->listing_id;
        }, $viable_results);

        // Run photo analysis on viable properties (report-scoped)
        if (class_exists('Flip_Photo_Analyzer')) {
            foreach ($listing_ids as $lid) {
                try {
                    $result = Flip_Photo_Analyzer::analyze_and_update($lid, $report_id);
                    if (!$result['success']) {
                        error_log("Flip Monitor: Photo analysis failed for listing {$lid}: " . ($result['error'] ?? 'unknown'));
                    }
                } catch (\Exception $e) {
                    error_log("Flip Monitor: Photo analysis failed for listing {$lid}: " . $e->getMessage());
                }
            }
        }

        // Generate PDFs
        $pdf_urls = [];
        require_once FLIP_PLUGIN_PATH . 'includes/class-flip-pdf-generator.php';
        $upload_dir = wp_upload_dir();

        foreach ($listing_ids as $lid) {
            try {
                $generator = new Flip_PDF_Generator();
                $pdf_path  = $generator->generate($lid, (int) $monitor->id);
                if ($pdf_path) {
                    $pdf_urls[$lid] = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
                }
            } catch (\Exception $e) {
                error_log("Flip Monitor: PDF generation failed for listing {$lid}: " . $e->getMessage());
            }
        }

        // Send email notification
        if (!empty($monitor->notification_email)) {
            self::send_viable_notification($monitor, $viable_results, $pdf_urls);
        }
    }

    /**
     * Send email notification for viable properties found by a monitor.
     */
    private static function send_viable_notification(object $monitor, array $viable_results, array $pdf_urls): void {
        $to      = $monitor->notification_email;
        $count   = count($viable_results);
        $subject = "Flip Monitor: \"{$monitor->name}\" found {$count} viable " . ($count === 1 ? 'property' : 'properties');

        $body = "<h2>Monitor: {$monitor->name}</h2>\n";
        $body .= "<p>{$count} new viable flip " . ($count === 1 ? 'candidate' : 'candidates') . " found:</p>\n";
        $body .= "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;'>\n";
        $body .= "<tr style='background:#2271b1;color:#fff;'>";
        $body .= "<th style='padding:8px;text-align:left;'>Property</th>";
        $body .= "<th style='padding:8px;text-align:right;'>Score</th>";
        $body .= "<th style='padding:8px;text-align:right;'>List Price</th>";
        $body .= "<th style='padding:8px;text-align:right;'>ARV</th>";
        $body .= "<th style='padding:8px;text-align:right;'>Profit</th>";
        $body .= "<th style='padding:8px;text-align:center;'>PDF</th>";
        $body .= "</tr>\n";

        $site_url = home_url();
        foreach ($viable_results as $r) {
            $lid     = (int) $r->listing_id;
            $pdf_link = '';
            if (!empty($pdf_urls[$lid])) {
                $pdf_link = "<a href='" . esc_url($pdf_urls[$lid]) . "'>Download</a>";
            }

            $body .= "<tr style='border-bottom:1px solid #ddd;'>";
            $body .= "<td style='padding:8px;'><a href='" . esc_url("{$site_url}/property/{$lid}/") . "'>"
                . esc_html($r->address) . "</a><br><small style='color:#888;'>" . esc_html($r->city) . " | MLS# {$lid}</small></td>";
            $body .= "<td style='padding:8px;text-align:right;font-weight:bold;'>" . number_format($r->total_score, 1) . "</td>";
            $body .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->list_price) . "</td>";
            $body .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->estimated_arv) . "</td>";
            $body .= "<td style='padding:8px;text-align:right;color:" . ($r->estimated_profit > 0 ? '#00a32a' : '#cc1818') . ";'>$"
                . number_format($r->estimated_profit) . "</td>";
            $body .= "<td style='padding:8px;text-align:center;'>{$pdf_link}</td>";
            $body .= "</tr>\n";
        }

        $body .= "</table>\n";
        $body .= "<p style='margin-top:16px;color:#666;font-size:12px;'>This is an automated notification from the BMN Flip Analyzer monitor: \""
            . esc_html($monitor->name) . "\"</p>\n";

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($to, $subject, $body, $headers);
    }
}
