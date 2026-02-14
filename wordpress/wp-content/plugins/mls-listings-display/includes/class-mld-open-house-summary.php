<?php
/**
 * Open House Summary Report Generator
 *
 * Generates post-event summary reports with metrics, hot leads,
 * and marketing breakdowns. Sends email to agent and returns
 * structured JSON for iOS summary screen.
 *
 * @package MLS_Listings_Display
 * @since 6.77.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Open_House_Summary {

    /**
     * Generate summary report for a completed open house
     *
     * @param int $open_house_id Open house ID
     * @return array Summary data for API response
     */
    public static function generate($open_house_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Get open house details
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        if (!$open_house) {
            return null;
        }

        // Get all attendees
        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE open_house_id = %d ORDER BY signed_in_at ASC",
            $open_house_id
        ));

        // Compute metrics
        $summary = self::compute_metrics($attendees);

        // Add property info
        $summary['property_address'] = trim(($open_house->property_address ?? '') . ', ' . ($open_house->property_city ?? ''), ', ');
        $summary['open_house_date'] = $open_house->event_date ?? '';
        $summary['open_house_id'] = (int) $open_house_id;

        // Send email to agent
        self::send_summary_email($open_house, $summary);

        return $summary;
    }

    /**
     * Get summary for a previously completed open house (for later viewing)
     *
     * @param int $open_house_id Open house ID
     * @return array|null Summary data
     */
    public static function get_summary($open_house_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        if (!$open_house) {
            return null;
        }

        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE open_house_id = %d ORDER BY signed_in_at ASC",
            $open_house_id
        ));

        $summary = self::compute_metrics($attendees);
        $summary['property_address'] = trim(($open_house->property_address ?? '') . ', ' . ($open_house->property_city ?? ''), ', ');
        $summary['open_house_date'] = $open_house->event_date ?? '';
        $summary['open_house_id'] = (int) $open_house_id;

        return $summary;
    }

    /**
     * Compute summary metrics from attendees
     *
     * @param array $attendees Array of attendee objects
     * @return array Metrics data
     */
    private static function compute_metrics($attendees) {
        $total = count($attendees);
        $buyer_count = 0;
        $agent_count = 0;
        $unrepresented_count = 0;
        $pre_approved_count = 0;
        $consent_count = 0;
        $interest_breakdown = array();
        $timeline_breakdown = array();
        $source_breakdown = array();
        $hot_leads = array();

        foreach ($attendees as $a) {
            $is_agent = !empty($a->is_agent);

            if ($is_agent) {
                $agent_count++;
            } else {
                $buyer_count++;
            }

            // Unrepresented buyers
            if (!$is_agent && ($a->working_with_agent ?? '') === 'no') {
                $unrepresented_count++;
            }

            // Pre-approved
            if (!$is_agent && ($a->pre_approved ?? '') === 'yes') {
                $pre_approved_count++;
            }

            // Consent
            if (!empty($a->consent_to_follow_up)) {
                $consent_count++;
            }

            // Interest breakdown
            $interest = $a->interest_level ?? 'not_set';
            if (!isset($interest_breakdown[$interest])) {
                $interest_breakdown[$interest] = 0;
            }
            $interest_breakdown[$interest]++;

            // Timeline breakdown (buyers only)
            if (!$is_agent) {
                $timeline = $a->buying_timeline ?? 'not_specified';
                if (!isset($timeline_breakdown[$timeline])) {
                    $timeline_breakdown[$timeline] = 0;
                }
                $timeline_breakdown[$timeline]++;
            }

            // Marketing source
            $source = $a->how_heard_about ?? 'not_specified';
            if (!empty($source) && $source !== 'not_specified') {
                if (!isset($source_breakdown[$source])) {
                    $source_breakdown[$source] = 0;
                }
                $source_breakdown[$source]++;
            }

            // Hot leads: unrepresented buyers with near-term timeline
            if (!$is_agent
                && ($a->working_with_agent ?? '') === 'no'
                && in_array($a->buying_timeline ?? '', array('0_to_3_months', '3_to_6_months'))
            ) {
                $hot_leads[] = self::format_attendee_summary($a);
            }
        }

        // Sort hot leads: 0-3 months first, then pre-approved first
        usort($hot_leads, function($a, $b) {
            // Timeline urgency
            $timeline_order = array('0_to_3_months' => 0, '3_to_6_months' => 1);
            $a_order = $timeline_order[$a['buying_timeline']] ?? 2;
            $b_order = $timeline_order[$b['buying_timeline']] ?? 2;
            if ($a_order !== $b_order) {
                return $a_order - $b_order;
            }
            // Pre-approved first
            $a_pre = ($a['pre_approved'] === 'yes') ? 0 : 1;
            $b_pre = ($b['pre_approved'] === 'yes') ? 0 : 1;
            return $a_pre - $b_pre;
        });

        // Format all attendees for response
        $all_attendees = array_map(array(__CLASS__, 'format_attendee_summary'), $attendees);

        return array(
            'total_attendees' => $total,
            'buyer_count' => $buyer_count,
            'agent_count' => $agent_count,
            'unrepresented_buyer_count' => $unrepresented_count,
            'pre_approved_count' => $pre_approved_count,
            'consent_to_follow_up_count' => $consent_count,
            'interest_breakdown' => $interest_breakdown,
            'timeline_breakdown' => $timeline_breakdown,
            'source_breakdown' => $source_breakdown,
            'hot_leads' => $hot_leads,
            'all_attendees' => $all_attendees,
        );
    }

    /**
     * Format a single attendee for summary output
     *
     * @param object $attendee Attendee row
     * @return array Formatted attendee
     */
    private static function format_attendee_summary($attendee) {
        return array(
            'id' => (int) $attendee->id,
            'first_name' => $attendee->first_name ?? '',
            'last_name' => $attendee->last_name ?? '',
            'email' => $attendee->email ?? '',
            'phone' => $attendee->phone ?? '',
            'is_agent' => !empty($attendee->is_agent),
            'working_with_agent' => $attendee->working_with_agent ?? '',
            'buying_timeline' => $attendee->buying_timeline ?? '',
            'pre_approved' => $attendee->pre_approved ?? '',
            'interest_level' => $attendee->interest_level ?? '',
            'how_heard_about' => $attendee->how_heard_about ?? '',
            'consent_to_follow_up' => !empty($attendee->consent_to_follow_up),
            'signed_in_at' => $attendee->signed_in_at ?? '',
            'auto_crm_processed' => !empty($attendee->auto_crm_processed),
            'auto_search_created' => !empty($attendee->auto_search_created),
        );
    }

    /**
     * Send summary email to the listing agent
     *
     * @param object $open_house Open house row
     * @param array $summary Summary data
     */
    private static function send_summary_email($open_house, $summary) {
        $agent_user_id = (int) $open_house->agent_user_id;
        $agent = get_userdata($agent_user_id);
        if (!$agent) {
            return;
        }

        $to = $agent->user_email;
        $property_address = $summary['property_address'] ?: 'Open House';
        $subject = "Open House Summary: {$property_address}";

        // Use MLD_Email_Utilities for dynamic From address if available
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if (class_exists('MLD_Email_Utilities')) {
            $headers = MLD_Email_Utilities::get_email_headers($agent_user_id);
            // Ensure HTML content type
            $has_content_type = false;
            foreach ($headers as $h) {
                if (stripos($h, 'content-type') !== false) {
                    $has_content_type = true;
                    break;
                }
            }
            if (!$has_content_type) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            }
        }

        $html = self::build_summary_email_html($open_house, $summary);
        wp_mail($to, $subject, $html, $headers);
    }

    /**
     * Build HTML email content for summary report
     *
     * @param object $open_house Open house row
     * @param array $summary Summary data
     * @return string HTML email content
     */
    private static function build_summary_email_html($open_house, $summary) {
        $property_address = $summary['property_address'] ?: 'Open House';
        $event_date = '';
        if (!empty($summary['open_house_date'])) {
            $dt = new DateTime($summary['open_house_date'], wp_timezone());
            $event_date = wp_date('l, F j, Y', $dt->getTimestamp());
        }

        $total = $summary['total_attendees'];
        $buyers = $summary['buyer_count'];
        $agents = $summary['agent_count'];
        $unrepresented = $summary['unrepresented_buyer_count'];
        $pre_approved = $summary['pre_approved_count'];
        $consent = $summary['consent_to_follow_up_count'];
        $hot_leads = $summary['hot_leads'];

        // Build email
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open House Summary</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f4f4f4;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f4;">
<tr><td align="center" style="padding:20px 10px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);max-width:600px;width:100%;">';

        // Header
        $html .= '<tr><td style="background-color:#1e3a5f;padding:24px 30px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:bold;">Open House Summary</h1>
            <p style="margin:8px 0 0;color:#a3bfdb;font-size:14px;">' . esc_html($property_address) . '</p>';
        if ($event_date) {
            $html .= '<p style="margin:4px 0 0;color:#a3bfdb;font-size:13px;">' . esc_html($event_date) . '</p>';
        }
        $html .= '</td></tr>';

        // Key stats row
        $html .= '<tr><td style="padding:24px 30px 16px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>';

        $stats = array(
            array('label' => 'Total Visitors', 'value' => $total, 'color' => '#1e3a5f'),
            array('label' => 'Buyers', 'value' => $buyers, 'color' => '#16a34a'),
            array('label' => 'Agents', 'value' => $agents, 'color' => '#7c3aed'),
            array('label' => 'Unrepresented', 'value' => $unrepresented, 'color' => '#ea580c'),
        );

        foreach ($stats as $stat) {
            $html .= '<td align="center" style="padding:8px;">
                <div style="font-size:28px;font-weight:bold;color:' . $stat['color'] . ';">' . $stat['value'] . '</div>
                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">' . $stat['label'] . '</div>
            </td>';
        }

        $html .= '</tr></table></td></tr>';

        // Additional stats
        $html .= '<tr><td style="padding:0 30px 16px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f9fafb;border-radius:6px;padding:12px;">
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#374151;">Pre-Approved Buyers</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:bold;color:#374151;">' . $pre_approved . '</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#374151;">Consented to Follow-Up</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:bold;color:#374151;">' . $consent . '</td>
            </tr>
            </table>
        </td></tr>';

        // Hot leads section
        if (!empty($hot_leads)) {
            $html .= '<tr><td style="padding:16px 30px 8px;">
                <h2 style="margin:0;font-size:16px;color:#ea580c;">&#128293; Hot Leads (' . count($hot_leads) . ')</h2>
                <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Unrepresented buyers with near-term timeline</p>
            </td></tr>';

            $html .= '<tr><td style="padding:0 30px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #fed7aa;border-radius:6px;overflow:hidden;">';

            // Header row
            $html .= '<tr style="background-color:#fff7ed;">
                <td style="padding:8px 12px;font-size:11px;font-weight:bold;color:#9a3412;text-transform:uppercase;">Name</td>
                <td style="padding:8px 12px;font-size:11px;font-weight:bold;color:#9a3412;text-transform:uppercase;">Contact</td>
                <td style="padding:8px 12px;font-size:11px;font-weight:bold;color:#9a3412;text-transform:uppercase;">Timeline</td>
                <td style="padding:8px 12px;font-size:11px;font-weight:bold;color:#9a3412;text-transform:uppercase;">Pre-Approved</td>
            </tr>';

            foreach ($hot_leads as $lead) {
                $name = esc_html(trim($lead['first_name'] . ' ' . $lead['last_name']));
                $contact = esc_html($lead['email']);
                if (!empty($lead['phone'])) {
                    $contact .= '<br>' . esc_html($lead['phone']);
                }
                $timeline_label = self::format_timeline_label($lead['buying_timeline']);
                $pre_label = $lead['pre_approved'] === 'yes' ? '&#9989; Yes' : 'No';

                $html .= '<tr style="border-top:1px solid #fed7aa;">
                    <td style="padding:10px 12px;font-size:13px;color:#1f2937;">' . $name . '</td>
                    <td style="padding:10px 12px;font-size:12px;color:#4b5563;">' . $contact . '</td>
                    <td style="padding:10px 12px;font-size:12px;color:#4b5563;">' . esc_html($timeline_label) . '</td>
                    <td style="padding:10px 12px;font-size:12px;color:#4b5563;">' . $pre_label . '</td>
                </tr>';
            }

            $html .= '</table></td></tr>';
        }

        // Marketing breakdown
        $source_breakdown = $summary['source_breakdown'];
        if (!empty($source_breakdown)) {
            arsort($source_breakdown);
            $html .= '<tr><td style="padding:16px 30px 8px;">
                <h2 style="margin:0;font-size:16px;color:#1e3a5f;">Marketing Sources</h2>
            </td></tr>';

            $html .= '<tr><td style="padding:0 30px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f9fafb;border-radius:6px;">';

            foreach ($source_breakdown as $source => $count) {
                $pct = $total > 0 ? round(($count / $total) * 100) : 0;
                $html .= '<tr>
                    <td style="padding:8px 12px;font-size:13px;color:#374151;">' . esc_html(self::format_source_label($source)) . '</td>
                    <td align="right" style="padding:8px 12px;font-size:13px;color:#374151;">' . $count . ' (' . $pct . '%)</td>
                </tr>';
            }

            $html .= '</table></td></tr>';
        }

        // Timeline breakdown
        $timeline_breakdown = $summary['timeline_breakdown'];
        if (!empty($timeline_breakdown)) {
            $html .= '<tr><td style="padding:16px 30px 8px;">
                <h2 style="margin:0;font-size:16px;color:#1e3a5f;">Buying Timeline (Buyers Only)</h2>
            </td></tr>';

            $html .= '<tr><td style="padding:0 30px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f9fafb;border-radius:6px;">';

            foreach ($timeline_breakdown as $timeline => $count) {
                $html .= '<tr>
                    <td style="padding:8px 12px;font-size:13px;color:#374151;">' . esc_html(self::format_timeline_label($timeline)) . '</td>
                    <td align="right" style="padding:8px 12px;font-size:13px;font-weight:bold;color:#374151;">' . $count . '</td>
                </tr>';
            }

            $html .= '</table></td></tr>';
        }

        // Footer
        $footer_html = '';
        if (class_exists('MLD_Email_Utilities')) {
            $footer_html = MLD_Email_Utilities::get_unified_footer(array(
                'context' => 'general',
                'show_social' => true,
                'show_app_download' => true,
                'compact' => true,
            ));
        }

        if (!empty($footer_html)) {
            $html .= '<tr><td>' . $footer_html . '</td></tr>';
        } else {
            $html .= '<tr><td style="padding:20px 30px;background-color:#f9fafb;text-align:center;font-size:12px;color:#9ca3af;">
                BMN Boston Real Estate
            </td></tr>';
        }

        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }

    /**
     * Format buying timeline value for display
     *
     * @param string $timeline Raw timeline value
     * @return string Human-readable label
     */
    private static function format_timeline_label($timeline) {
        $labels = array(
            '0_to_3_months' => '0-3 Months',
            '3_to_6_months' => '3-6 Months',
            '6_plus' => '6+ Months',
            'just_browsing' => 'Just Browsing',
            'not_specified' => 'Not Specified',
        );
        return $labels[$timeline] ?? ucwords(str_replace('_', ' ', $timeline));
    }

    /**
     * Format marketing source value for display
     *
     * @param string $source Raw source value
     * @return string Human-readable label
     */
    private static function format_source_label($source) {
        $labels = array(
            'zillow' => 'Zillow',
            'realtor_com' => 'Realtor.com',
            'signage' => 'Signage / Yard Sign',
            'social_media' => 'Social Media',
            'friend_family' => 'Friend / Family',
            'agent_referral' => 'Agent Referral',
            'website' => 'Website',
            'mailer' => 'Mailer / Flyer',
            'drive_by' => 'Drive By',
            'other' => 'Other',
            'not_specified' => 'Not Specified',
        );
        return $labels[$source] ?? ucwords(str_replace('_', ' ', $source));
    }
}
