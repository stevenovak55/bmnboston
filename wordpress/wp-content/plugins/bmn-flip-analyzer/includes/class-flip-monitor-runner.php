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
        global $wpdb;
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
        $all_listing_ids = Flip_Property_Fetcher::fetch_matching_listing_ids($cities, $filters);

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

        // Step 4b: Mark only successfully analyzed listings as seen
        // (failed listings will be retried on the next run)
        $table = Flip_Database::table_name();
        $id_list = implode(',', array_map('intval', $new_listing_ids));
        $analyzed_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE report_id = %d AND listing_id IN ({$id_list})",
            $report_id
        ));
        if (!empty($analyzed_ids)) {
            Flip_Database::mark_listings_seen($report_id, $analyzed_ids);
        }

        $new_total = (int) ($result['analyzed'] ?? 0);

        // Step 5: Identify viable properties from this batch
        $viable_results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE report_id = %d AND disqualified = 0 AND total_score >= 60
             AND listing_id IN ({$id_list})",
            $report_id
        ));

        // Step 6: Tiered notifications based on notification_level
        $notification_level = $monitor->notification_level ?? 'viable_only';

        if (!empty($viable_results)) {
            // Viable found: photo analysis + PDF + email
            self::process_viable($monitor, $viable_results);
        }

        // Near-viable and DQ notifications (if notification_level allows)
        if (!empty($monitor->notification_email) && $notification_level !== 'viable_only') {
            // Near-viable notifications
            $near_viable_results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE report_id = %d AND near_viable = 1
                 AND listing_id IN ({$id_list})",
                $report_id
            ));
            if (!empty($near_viable_results)) {
                self::send_near_viable_notification($monitor, $near_viable_results);
            }

            // DQ notifications (only for 'all' level)
            if ($notification_level === 'all') {
                $dq_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE report_id = %d AND disqualified = 1
                     AND listing_id IN ({$id_list})
                     ORDER BY total_score DESC LIMIT 20",
                    $report_id
                ));
                if (!empty($dq_results)) {
                    self::send_dq_notification($monitor, $dq_results);
                }
            }
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
                        error_log("[Flip Monitor] Photo analysis failed for listing {$lid}: " . ($result['error'] ?? 'unknown'));
                    }
                } catch (\Exception $e) {
                    error_log("[Flip Monitor] Photo analysis failed for listing {$lid}: " . $e->getMessage());
                }
            }
        }

        // Generate PDFs
        $pdf_urls     = [];
        $pdf_failures = [];
        require_once FLIP_PLUGIN_PATH . 'includes/class-flip-pdf-generator.php';
        $upload_dir = wp_upload_dir();

        foreach ($listing_ids as $lid) {
            try {
                $generator = new Flip_PDF_Generator();
                $pdf_path  = $generator->generate($lid, (int) $monitor->id);
                if ($pdf_path) {
                    $pdf_urls[$lid] = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
                } else {
                    $pdf_failures[] = $lid;
                }
            } catch (\Exception $e) {
                error_log("[Flip Monitor] PDF generation failed for listing {$lid}: " . $e->getMessage());
                $pdf_failures[] = $lid;
            }
        }

        // Send email notification
        if (!empty($monitor->notification_email)) {
            self::send_viable_notification($monitor, $viable_results, $pdf_urls, $pdf_failures);
        }
    }

    /**
     * Send email notification for viable properties found by a monitor.
     */
    private static function send_viable_notification(object $monitor, array $viable_results, array $pdf_urls, array $pdf_failures = []): void {
        $to      = $monitor->notification_email;
        $count   = count($viable_results);
        $subject = "Flip Monitor: \"{$monitor->name}\" found {$count} viable " . ($count === 1 ? 'property' : 'properties');

        $content = "<h2 style='margin:0 0 12px;font-size:20px;color:#1d2327;'>Monitor: " . esc_html($monitor->name) . "</h2>\n";
        $content .= "<p style='margin:0 0 16px;color:#50575e;'>{$count} new viable flip " . ($count === 1 ? 'candidate' : 'candidates') . " found:</p>\n";
        $content .= self::build_property_table($viable_results, $pdf_urls, $pdf_failures);

        if (!empty($pdf_failures)) {
            $fail_count = count($pdf_failures);
            $content .= "<p style='margin-top:12px;color:#cc1818;font-size:12px;'>"
                . "Note: PDF reports could not be generated for {$fail_count} "
                . ($fail_count === 1 ? 'property' : 'properties')
                . ". View full details on the Flip Analyzer dashboard.</p>\n";
        }

        $body = self::build_email_wrapper($content, '#2271b1', "Viable Properties Found");
        $headers = self::get_email_headers($to);

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Send email notification for near-viable properties.
     */
    private static function send_near_viable_notification(object $monitor, array $results): void {
        $to      = $monitor->notification_email;
        $count   = count($results);
        $subject = "Flip Monitor: \"{$monitor->name}\" — {$count} near-viable " . ($count === 1 ? 'property' : 'properties');

        $content = "<h2 style='margin:0 0 12px;font-size:20px;color:#1d2327;'>Monitor: " . esc_html($monitor->name) . "</h2>\n";
        $content .= "<p style='margin:0 0 16px;color:#50575e;'>{$count} near-viable " . ($count === 1 ? 'property' : 'properties')
            . " found (close to viability thresholds):</p>\n";
        $content .= self::build_score_table($results);

        $body = self::build_email_wrapper($content, '#f0ad4e', "Near-Viable Properties");
        $headers = self::get_email_headers($to);

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Send email notification for disqualified properties.
     */
    private static function send_dq_notification(object $monitor, array $results): void {
        $to      = $monitor->notification_email;
        $count   = count($results);
        $subject = "Flip Monitor: \"{$monitor->name}\" — {$count} disqualified " . ($count === 1 ? 'property' : 'properties');

        $content = "<h2 style='margin:0 0 12px;font-size:20px;color:#1d2327;'>Monitor: " . esc_html($monitor->name) . "</h2>\n";
        $content .= "<p style='margin:0 0 16px;color:#50575e;'>{$count} new " . ($count === 1 ? 'property was' : 'properties were')
            . " analyzed and disqualified:</p>\n";

        $content .= "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:14px;'>\n";
        $content .= "<tr style='background:#999;color:#fff;'>";
        $content .= "<th style='padding:8px;text-align:left;'>Property</th>";
        $content .= "<th style='padding:8px;text-align:right;'>List Price</th>";
        $content .= "<th style='padding:8px;text-align:left;'>DQ Reason</th>";
        $content .= "</tr>\n";

        $site_url = home_url();
        foreach ($results as $r) {
            $lid = (int) $r->listing_id;
            $content .= "<tr style='border-bottom:1px solid #ddd;'>";
            $content .= "<td style='padding:8px;'><a href='" . esc_url("{$site_url}/property/{$lid}/") . "' style='color:#2271b1;'>"
                . esc_html($r->address) . "</a><br><small style='color:#888;'>" . esc_html($r->city) . " | MLS# {$lid}</small></td>";
            $content .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->list_price) . "</td>";
            $content .= "<td style='padding:8px;color:#666;font-size:12px;'>" . esc_html($r->disqualify_reason ?: 'N/A') . "</td>";
            $content .= "</tr>\n";
        }
        $content .= "</table>\n";

        $body = self::build_email_wrapper($content, '#999999', "Disqualified Properties");
        $headers = self::get_email_headers($to);

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Build a property table with scores, prices, and optional PDF links.
     * Used by viable notification emails.
     */
    private static function build_property_table(array $results, array $pdf_urls = [], array $pdf_failures = []): string {
        $html = "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:14px;'>\n";
        $html .= "<tr style='background:#2271b1;color:#fff;'>";
        $html .= "<th style='padding:8px;text-align:left;'>Property</th>";
        $html .= "<th style='padding:8px;text-align:right;'>Score</th>";
        $html .= "<th style='padding:8px;text-align:right;'>List Price</th>";
        $html .= "<th style='padding:8px;text-align:right;'>ARV</th>";
        $html .= "<th style='padding:8px;text-align:right;'>Profit</th>";
        if (!empty($pdf_urls) || !empty($pdf_failures)) {
            $html .= "<th style='padding:8px;text-align:center;'>PDF</th>";
        }
        $html .= "</tr>\n";

        $site_url = home_url();
        foreach ($results as $r) {
            $lid = (int) $r->listing_id;
            $pdf_link = '';
            if (!empty($pdf_urls[$lid])) {
                $pdf_link = "<a href='" . esc_url($pdf_urls[$lid]) . "' style='color:#2271b1;'>Download</a>";
            } elseif (in_array($lid, $pdf_failures, true)) {
                $pdf_link = "<span style='color:#999;font-size:12px;'>N/A</span>";
            }

            $html .= "<tr style='border-bottom:1px solid #ddd;'>";
            $html .= "<td style='padding:8px;'><a href='" . esc_url("{$site_url}/property/{$lid}/") . "' style='color:#2271b1;'>"
                . esc_html($r->address) . "</a><br><small style='color:#888;'>" . esc_html($r->city) . " | MLS# {$lid}</small></td>";
            $html .= "<td style='padding:8px;text-align:right;font-weight:bold;'>" . number_format($r->total_score, 1) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->list_price) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->estimated_arv) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;color:" . ($r->estimated_profit > 0 ? '#00a32a' : '#cc1818') . ";'>$"
                . number_format($r->estimated_profit) . "</td>";
            if (!empty($pdf_urls) || !empty($pdf_failures)) {
                $html .= "<td style='padding:8px;text-align:center;'>{$pdf_link}</td>";
            }
            $html .= "</tr>\n";
        }
        $html .= "</table>\n";

        return $html;
    }

    /**
     * Build a score table for near-viable properties.
     */
    private static function build_score_table(array $results): string {
        $html = "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:14px;'>\n";
        $html .= "<tr style='background:#f0ad4e;color:#fff;'>";
        $html .= "<th style='padding:8px;text-align:left;'>Property</th>";
        $html .= "<th style='padding:8px;text-align:right;'>Score</th>";
        $html .= "<th style='padding:8px;text-align:right;'>List Price</th>";
        $html .= "<th style='padding:8px;text-align:right;'>ARV</th>";
        $html .= "<th style='padding:8px;text-align:right;'>Profit</th>";
        $html .= "</tr>\n";

        $site_url = home_url();
        foreach ($results as $r) {
            $lid = (int) $r->listing_id;
            $html .= "<tr style='border-bottom:1px solid #ddd;'>";
            $html .= "<td style='padding:8px;'><a href='" . esc_url("{$site_url}/property/{$lid}/") . "' style='color:#2271b1;'>"
                . esc_html($r->address) . "</a><br><small style='color:#888;'>" . esc_html($r->city) . " | MLS# {$lid}</small></td>";
            $html .= "<td style='padding:8px;text-align:right;font-weight:bold;'>" . number_format($r->total_score, 1) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->list_price) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;'>$" . number_format($r->estimated_arv) . "</td>";
            $html .= "<td style='padding:8px;text-align:right;color:" . ($r->estimated_profit > 0 ? '#00a32a' : '#cc1818') . ";'>$"
                . number_format($r->estimated_profit) . "</td>";
            $html .= "</tr>\n";
        }
        $html .= "</table>\n";

        return $html;
    }

    /**
     * Build branded HTML email wrapper with responsive container.
     *
     * @param string $content     Inner HTML content.
     * @param string $header_color Header band color (hex).
     * @param string $header_text  Header band text.
     * @return string Complete HTML email body.
     */
    private static function build_email_wrapper(string $content, string $header_color, string $header_text): string {
        $footer = '';
        if (class_exists('MLD_Email_Utilities')) {
            $footer = MLD_Email_Utilities::get_unified_footer([
                'context'   => 'general',
                'compact'   => true,
                'show_social' => false,
            ]);
        }

        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>"
            . "<meta name='viewport' content='width=device-width,initial-scale=1.0'></head><body style='margin:0;padding:0;background:#f0f0f1;'>\n";

        // Outer container
        $html .= "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f1;'><tr><td align='center' style='padding:24px 16px;'>\n";

        // Inner container (600px max-width)
        $html .= "<table role='presentation' width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.1);'>\n";

        // Branded header band
        $html .= "<tr><td style='background:{$header_color};padding:16px 24px;'>"
            . "<h1 style='margin:0;font-family:Arial,sans-serif;font-size:18px;color:#fff;font-weight:600;'>"
            . esc_html($header_text) . "</h1></td></tr>\n";

        // Content area
        $html .= "<tr><td style='padding:24px;font-family:Arial,sans-serif;color:#1d2327;line-height:1.5;'>\n"
            . $content . "\n</td></tr>\n";

        // Flip Analyzer attribution
        $html .= "<tr><td style='padding:0 24px 16px;'>"
            . "<p style='margin:0;color:#888;font-size:12px;font-family:Arial,sans-serif;'>"
            . "Sent by BMN Flip Analyzer</p></td></tr>\n";

        // MLD unified footer (if available)
        if (!empty($footer)) {
            $html .= "<tr><td style='border-top:1px solid #e2e4e7;'>{$footer}</td></tr>\n";
        }

        $html .= "</table>\n"; // End inner container
        $html .= "</td></tr></table>\n"; // End outer container
        $html .= "</body></html>";

        return $html;
    }

    /**
     * Get email headers, using MLD utilities when available.
     */
    private static function get_email_headers(string $to): array {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (class_exists('MLD_Email_Utilities')) {
            $user_id = MLD_Email_Utilities::get_user_id_from_email($to);
            if ($user_id) {
                $headers = MLD_Email_Utilities::get_email_headers($user_id);
            }
        }

        return $headers;
    }

    // ─── Digest Emails ──────────────────────────────────────────

    /**
     * Send periodic digest email summarizing monitor activity.
     * Called by wp_cron at priority 20 (after run_all_due at default 10).
     */
    public static function maybe_send_digest(): void {
        $settings = Flip_Database::get_digest_settings();

        if (empty($settings['enabled'])) {
            return;
        }

        $frequency = $settings['frequency'] ?? 'daily';
        $last_sent = $settings['last_sent'] ?? null;
        $now       = current_time('timestamp');

        // Check if digest is due
        $interval = ($frequency === 'weekly') ? 604800 : 86400;
        if (!empty($last_sent)) {
            $last_ts = strtotime($last_sent);
            if (($now - $last_ts) < $interval) {
                return; // Not due yet
            }
        }

        $since = $last_sent ?: wp_date('Y-m-d H:i:s', $now - $interval);
        $activity = Flip_Database::get_monitor_activity_since($since);

        if (empty($activity)) {
            // Update last_sent even with no activity to avoid re-checking
            Flip_Database::set_digest_settings(array_merge($settings, [
                'last_sent' => current_time('mysql'),
            ]));
            return;
        }

        $to = $settings['email'] ?? get_option('admin_email');
        self::send_digest_email($to, $activity, $since);

        Flip_Database::set_digest_settings(array_merge($settings, [
            'last_sent' => current_time('mysql'),
        ]));
    }

    /**
     * Compose and send the digest summary email.
     */
    private static function send_digest_email(string $to, array $activity, string $since): void {
        $total_monitors = count($activity);
        $total_analyzed = array_sum(array_column($activity, 'new_analyzed'));
        $total_viable   = array_sum(array_column($activity, 'new_viable'));
        $subject = "Flip Analyzer Digest: {$total_viable} viable from {$total_monitors} " . ($total_monitors === 1 ? 'monitor' : 'monitors');

        $since_display = wp_date('M j, Y g:i A', (new DateTime($since, wp_timezone()))->getTimestamp());
        $content = "<h2 style='margin:0 0 12px;font-size:20px;color:#1d2327;'>Monitor Activity Digest</h2>\n";
        $content .= "<p style='margin:0 0 16px;color:#50575e;'>Activity since {$since_display}:</p>\n";

        // Summary stats
        $content .= "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:14px;margin-bottom:20px;'>\n";
        $content .= "<tr>";
        $content .= "<td style='padding:12px;background:#f0f6fc;border-radius:4px;text-align:center;width:33%;'>"
            . "<div style='font-size:24px;font-weight:bold;color:#2271b1;'>{$total_analyzed}</div>"
            . "<div style='font-size:12px;color:#888;'>Analyzed</div></td>";
        $content .= "<td style='padding:12px;background:#edf7ee;border-radius:4px;text-align:center;width:33%;'>"
            . "<div style='font-size:24px;font-weight:bold;color:#00a32a;'>{$total_viable}</div>"
            . "<div style='font-size:12px;color:#888;'>Viable</div></td>";
        $total_dq = array_sum(array_column($activity, 'new_dq'));
        $content .= "<td style='padding:12px;background:#fcf0f0;border-radius:4px;text-align:center;width:33%;'>"
            . "<div style='font-size:24px;font-weight:bold;color:#cc1818;'>{$total_dq}</div>"
            . "<div style='font-size:12px;color:#888;'>Disqualified</div></td>";
        $content .= "</tr></table>\n";

        // Per-monitor table
        $content .= "<table style='border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;'>\n";
        $content .= "<tr style='background:#f0f0f1;'>";
        $content .= "<th style='padding:8px;text-align:left;'>Monitor</th>";
        $content .= "<th style='padding:8px;text-align:right;'>New</th>";
        $content .= "<th style='padding:8px;text-align:right;'>Viable</th>";
        $content .= "<th style='padding:8px;text-align:right;'>Near</th>";
        $content .= "<th style='padding:8px;text-align:right;'>DQ'd</th>";
        $content .= "<th style='padding:8px;text-align:right;'>Last Check</th>";
        $content .= "</tr>\n";

        foreach ($activity as $row) {
            $last_check = !empty($row['last_check']) ? wp_date('M j g:i A', (new DateTime($row['last_check'], wp_timezone()))->getTimestamp()) : 'N/A';
            $content .= "<tr style='border-bottom:1px solid #ddd;'>";
            $content .= "<td style='padding:8px;font-weight:500;'>" . esc_html($row['name']) . "</td>";
            $content .= "<td style='padding:8px;text-align:right;'>" . (int) $row['new_analyzed'] . "</td>";
            $content .= "<td style='padding:8px;text-align:right;color:#00a32a;font-weight:bold;'>" . (int) $row['new_viable'] . "</td>";
            $content .= "<td style='padding:8px;text-align:right;color:#f0ad4e;'>" . (int) ($row['new_near_viable'] ?? 0) . "</td>";
            $content .= "<td style='padding:8px;text-align:right;color:#cc1818;'>" . (int) $row['new_dq'] . "</td>";
            $content .= "<td style='padding:8px;text-align:right;color:#888;font-size:12px;'>{$last_check}</td>";
            $content .= "</tr>\n";
        }
        $content .= "</table>\n";

        $body = self::build_email_wrapper($content, '#2271b1', "Flip Analyzer Digest");
        $headers = self::get_email_headers($to);

        wp_mail($to, $subject, $body, $headers);
    }
}
