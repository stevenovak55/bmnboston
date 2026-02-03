<?php
/**
 * MLS Listings Display - Alert Email Builder
 *
 * Builds professional HTML emails for saved search alerts with change-type sections.
 * Supports new listings, price changes, and status changes.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Alert_Email_Builder {

    /**
     * Send alert email for saved search matches
     *
     * @param array $search Saved search data
     * @param array $grouped_changes Changes grouped by type
     * @param int $total_matches Total matches before limit
     * @return bool Success
     */
    public static function send($search, $grouped_changes, $total_matches) {
        $to = $search['user_email'];
        $user_id = isset($search['user_id']) ? intval($search['user_id']) : null;
        $subject = self::build_subject($search, $grouped_changes, $total_matches);
        $body = self::build_html_body($search, $grouped_changes, $total_matches);
        $headers = self::get_headers($user_id);

        return wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Build email subject line
     *
     * @param array $search Saved search data
     * @param array $grouped Changes grouped by type
     * @param int $total_matches Total matches
     * @return string Email subject
     */
    private static function build_subject($search, $grouped, $total_matches) {
        $new_count = count($grouped['new_listing']);
        $price_count = count($grouped['price_change']);
        $status_count = count($grouped['status_change']);

        $parts = array();
        if ($new_count > 0) {
            $parts[] = $new_count . ' new listing' . ($new_count > 1 ? 's' : '');
        }
        if ($price_count > 0) {
            $parts[] = $price_count . ' price change' . ($price_count > 1 ? 's' : '');
        }
        if ($status_count > 0) {
            $parts[] = $status_count . ' status update' . ($status_count > 1 ? 's' : '');
        }

        $summary = implode(', ', $parts);
        $search_name = $search['name'];

        if ($total_matches > 25) {
            return sprintf('Property Alert: %s (showing 25 of %d) - "%s"', $summary, $total_matches, $search_name);
        }

        return sprintf('Property Alert: %s - "%s"', $summary, $search_name);
    }

    /**
     * Get email headers with dynamic from address
     *
     * Uses MLD_Email_Utilities to determine the correct "from" address:
     * - Clients with assigned agent: email from agent
     * - Others: email from MLD settings
     *
     * @param int|null $recipient_user_id User ID of email recipient
     * @return array Email headers
     * @since 6.63.0 Updated to use dynamic from address
     */
    private static function get_headers($recipient_user_id = null) {
        if (class_exists('MLD_Email_Utilities')) {
            return MLD_Email_Utilities::get_email_headers($recipient_user_id);
        }

        // Fallback if utilities class not loaded
        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
    }

    /**
     * Build HTML email body
     *
     * @param array $search Saved search data
     * @param array $grouped Changes grouped by type
     * @param int $total_matches Total matches
     * @return string HTML email body
     */
    private static function build_html_body($search, $grouped, $total_matches) {
        $html = self::get_email_header($search['name']);

        // Summary section
        $shown_count = count($grouped['new_listing']) + count($grouped['price_change']) + count($grouped['status_change']);
        $html .= self::build_summary_section($grouped, $total_matches, $shown_count);

        // New Listings section
        if (!empty($grouped['new_listing'])) {
            $html .= self::build_section('New Listings', $grouped['new_listing'], '#28a745', 'NEW');
        }

        // Price Changes section
        if (!empty($grouped['price_change'])) {
            $html .= self::build_price_change_section($grouped['price_change']);
        }

        // Status Changes section
        if (!empty($grouped['status_change'])) {
            $html .= self::build_status_change_section($grouped['status_change']);
        }

        // Market Insights section - Added v6.13.14
        $html .= self::build_market_insights_section($search, $grouped);

        // View All button
        if (!empty($search['search_url'])) {
            $html .= self::build_cta_button($search['search_url'], $total_matches);
        }

        // Footer
        $html .= self::get_email_footer();

        return $html;
    }

    /**
     * Get email header HTML
     *
     * @param string $search_name Saved search name
     * @return string HTML
     */
    private static function get_email_header($search_name) {
        $site_name = get_bloginfo('name');
        $logo_url = '';

        // Try to get custom logo
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Alert</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f4f4f4;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f4;">
        <tr>
            <td align="center" style="padding:20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);padding:30px 40px;text-align:center;">
';

        if ($logo_url) {
            $html .= '                            <img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" style="max-width:180px;height:auto;margin-bottom:15px;">' . "\n";
        }

        $html .= '                            <h1 style="color:#ffffff;margin:0;font-size:28px;font-weight:600;">Property Alert</h1>
                            <p style="color:#b8d4e8;margin:12px 0 0 0;font-size:18px;">for "' . esc_html($search_name) . '"</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
';

        return $html;
    }

    /**
     * Build summary section
     *
     * @param array $grouped Grouped changes
     * @param int $total_matches Total matches
     * @param int $shown_count Shown count
     * @return string HTML
     */
    private static function build_summary_section($grouped, $total_matches, $shown_count) {
        $new_count = count($grouped['new_listing']);
        $price_count = count($grouped['price_change']);
        $status_count = count($grouped['status_change']);

        $html = '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:30px;">
                                <tr>
';

        // New Listings stat
        if ($new_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#e8f5e9;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#16a34a;">' . $new_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">New</div>
                                        </div>
                                    </td>
';
        }

        // Price Changes stat
        if ($price_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#fff3cd;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#b45309;">' . $price_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">Price Changes</div>
                                        </div>
                                    </td>
';
        }

        // Status Changes stat
        if ($status_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#e0f2fe;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#0284c7;">' . $status_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">Status Updates</div>
                                        </div>
                                    </td>
';
        }

        $html .= '                                </tr>
                            </table>
';

        // Show "Showing X of Y" notice if truncated
        if ($total_matches > $shown_count) {
            $html .= '                            <p style="text-align:center;color:#4a4a4a;font-size:16px;margin-bottom:25px;background:#f8f9fa;padding:12px;border-radius:6px;">
                                Showing <strong>' . $shown_count . '</strong> of <strong>' . $total_matches . '</strong> total matches
                            </p>
';
        }

        return $html;
    }

    /**
     * Build a listings section
     *
     * @param string $title Section title
     * @param array $listings Listings data
     * @param string $badge_color Badge background color
     * @param string $badge_text Badge text
     * @return string HTML
     */
    private static function build_section($title, $listings, $badge_color, $badge_text) {
        $html = '                            <h2 style="color:#1a1a1a;font-size:22px;border-bottom:2px solid #e5e7eb;padding-bottom:12px;margin:30px 0 20px 0;font-weight:600;">' . esc_html($title) . ' (' . count($listings) . ')</h2>
';

        foreach ($listings as $listing_id => $change_data) {
            $html .= self::build_listing_card($listing_id, $change_data, $badge_color, $badge_text);
        }

        return $html;
    }

    /**
     * Build price change section with price arrows
     *
     * @param array $listings Listings with price changes
     * @return string HTML
     */
    private static function build_price_change_section($listings) {
        $html = '                            <h2 style="color:#1a1a1a;font-size:22px;border-bottom:2px solid #e5e7eb;padding-bottom:12px;margin:30px 0 20px 0;font-weight:600;">Price Changes (' . count($listings) . ')</h2>
';

        foreach ($listings as $listing_id => $change_data) {
            $old_price = isset($change_data['old_price']) ? $change_data['old_price'] : 0;
            $new_price = isset($change_data['new_price']) ? $change_data['new_price'] : 0;
            $is_reduction = $new_price < $old_price;

            $badge_color = $is_reduction ? '#dc3545' : '#ffc107';
            $badge_text = $is_reduction ? 'PRICE REDUCED' : 'PRICE INCREASED';

            $html .= self::build_listing_card($listing_id, $change_data, $badge_color, $badge_text, 'price');
        }

        return $html;
    }

    /**
     * Build status change section
     *
     * @param array $listings Listings with status changes
     * @return string HTML
     */
    private static function build_status_change_section($listings) {
        $html = '                            <h2 style="color:#1a1a1a;font-size:22px;border-bottom:2px solid #e5e7eb;padding-bottom:12px;margin:30px 0 20px 0;font-weight:600;">Status Updates (' . count($listings) . ')</h2>
';

        foreach ($listings as $listing_id => $change_data) {
            $html .= self::build_listing_card($listing_id, $change_data, '#17a2b8', 'STATUS CHANGE', 'status');
        }

        return $html;
    }

    /**
     * Build a single listing card
     *
     * Mobile-first vertical stacked layout with full-width images.
     * Updated v6.13.22 for better mobile readability.
     *
     * @param string $listing_id Listing ID
     * @param array $change_data Change data with listing_data
     * @param string $badge_color Badge color
     * @param string $badge_text Badge text
     * @param string $change_type Type: null, 'price', or 'status'
     * @return string HTML
     */
    private static function build_listing_card($listing_id, $change_data, $badge_color, $badge_text, $change_type = null) {
        $listing = $change_data['listing_data'];

        // Get listing details
        $address = isset($listing['full_address']) ? $listing['full_address'] : (isset($listing['street_address']) ? $listing['street_address'] : 'Address unavailable');
        $city = isset($listing['city']) ? $listing['city'] : '';
        $state = isset($listing['state_or_province']) ? $listing['state_or_province'] : '';
        $zip = isset($listing['postal_code']) ? $listing['postal_code'] : '';
        $location = trim(implode(', ', array_filter(array($city, $state))) . ' ' . $zip);

        $price = isset($listing['list_price']) ? '$' . number_format($listing['list_price']) : '';
        $beds = isset($listing['bedrooms_total']) ? $listing['bedrooms_total'] : '?';
        $baths = isset($listing['bathrooms_total']) ? $listing['bathrooms_total'] : '?';
        $sqft = isset($listing['building_area_total']) ? number_format($listing['building_area_total']) : '';

        // Get photo URL
        $photo_url = isset($listing['primary_photo']) ? $listing['primary_photo'] : '';
        if (empty($photo_url) && isset($listing['photo_url'])) {
            $photo_url = $listing['photo_url'];
        }

        $listing_url = home_url('/property/' . $listing_id . '/');

        // Vertical stacked layout for mobile-first design
        $html = '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:20px;border:1px solid #d1d5db;border-radius:10px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.08);">
';

        // Full-width photo row (if available)
        if ($photo_url) {
            $html .= '                                <tr>
                                    <td style="padding:0;">
                                        <a href="' . esc_url($listing_url) . '" style="display:block;">
                                            <img src="' . esc_url($photo_url) . '" alt="' . esc_attr($address) . '" width="100%" style="display:block;width:100%;height:auto;min-height:200px;max-height:280px;object-fit:cover;">
                                        </a>
                                    </td>
                                </tr>
';
        }

        // Content row
        $html .= '                                <tr>
                                    <td style="padding:20px;">
                                        <!-- Badge -->
                                        <span style="background:' . $badge_color . ';color:white;padding:5px 12px;border-radius:4px;font-size:13px;font-weight:bold;display:inline-block;margin-bottom:12px;letter-spacing:0.5px;">' . $badge_text . '</span>
                                        <!-- Address -->
                                        <h3 style="margin:0 0 8px 0;font-size:20px;line-height:1.3;">
                                            <a href="' . esc_url($listing_url) . '" style="color:#1a1a1a;text-decoration:none;">' . esc_html($address) . '</a>
                                        </h3>
                                        <p style="margin:0 0 12px 0;color:#4a4a4a;font-size:16px;">' . esc_html($location) . '</p>
                                        <!-- Price and Details -->
                                        <p style="margin:0 0 10px 0;font-size:18px;line-height:1.5;">
                                            <strong style="color:#2d2d2d;font-size:22px;">' . $price . '</strong>';

        if ($beds !== '?' || $baths !== '?') {
            $html .= ' <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:#1a1a1a;">' . $beds . ' bed' . ($beds != 1 ? 's' : '') . '</span> <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:#1a1a1a;">' . $baths . ' bath' . ($baths != 1 ? 's' : '') . '</span>';
        }

        if ($sqft) {
            $html .= ' <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:#1a1a1a;">' . $sqft . ' sqft</span>';
        }

        $html .= '</p>
';

        // Property metrics row (Price/sqft, Days on Market, Year Built, Lot Size) - Added v6.13.14
        $price_per_sqft = self::get_price_per_sqft($listing);
        $days_on_market = self::get_days_on_market($listing);
        $year_built = $listing['year_built'] ?? null;
        $lot_acres = $listing['lot_size_acres'] ?? null;

        $metrics = [];
        if ($price_per_sqft) {
            $metrics[] = '$' . number_format($price_per_sqft) . '/sqft';
        }
        if ($days_on_market !== null) {
            $dom_label = $days_on_market <= 7 ? '<span style="color:#16a34a;font-weight:bold;">NEW</span> ' : '';
            $metrics[] = $dom_label . $days_on_market . ' days';
        }
        if ($year_built) {
            $metrics[] = 'Built ' . $year_built;
        }
        if ($lot_acres && $lot_acres > 0) {
            $metrics[] = number_format($lot_acres, 2) . ' acres';
        }

        if (!empty($metrics)) {
            $html .= '                                        <p style="margin:0 0 10px 0;font-size:15px;color:#525252;line-height:1.6;">' . implode(' <span style="color:#a3a3a3;margin:0 4px;">|</span> ', $metrics) . '</p>
';
        }

        // Property description excerpt - Added v6.13.14
        $description = self::get_description_excerpt($listing);
        if ($description) {
            $html .= '                                        <p style="margin:10px 0 0 0;font-size:15px;color:#4a4a4a;line-height:1.6;font-style:italic;">' . esc_html($description) . '</p>
';
        }

        // Price change details
        if ($change_type === 'price' && isset($change_data['old_price']) && isset($change_data['new_price'])) {
            $old = '$' . number_format($change_data['old_price']);
            $new = '$' . number_format($change_data['new_price']);
            $diff = $change_data['new_price'] - $change_data['old_price'];
            $diff_abs = abs($diff);
            $diff_formatted = '$' . number_format($diff_abs);
            $arrow = $diff < 0 ? '&darr;' : '&uarr;';
            $color = $diff < 0 ? '#16a34a' : '#dc2626';

            $html .= '                                        <p style="margin:12px 0 0 0;font-size:16px;color:#4a4a4a;background:#f9fafb;padding:10px 12px;border-radius:6px;">
                                            <span style="text-decoration:line-through;color:#6b7280;">' . $old . '</span>
                                            <span style="margin:0 8px;">&rarr;</span> <strong style="color:#1a1a1a;">' . $new . '</strong>
                                            <span style="color:' . $color . ';font-weight:bold;margin-left:8px;">(' . $arrow . ' ' . $diff_formatted . ')</span>
                                        </p>
';
        }

        // Status change details
        if ($change_type === 'status' && isset($change_data['old_status']) && isset($change_data['new_status'])) {
            $html .= '                                        <p style="margin:12px 0 0 0;font-size:16px;color:#4a4a4a;background:#f9fafb;padding:10px 12px;border-radius:6px;">
                                            Status: <span style="text-decoration:line-through;color:#6b7280;">' . esc_html($change_data['old_status']) . '</span>
                                            <span style="margin:0 8px;">&rarr;</span> <strong style="color:#0891b2;">' . esc_html($change_data['new_status']) . '</strong>
                                        </p>
';
        }

        $html .= '                                    </td>
                                </tr>
                            </table>
';

        return $html;
    }

    /**
     * Build CTA button
     *
     * @param string $url Search URL
     * @param int $total_matches Total matches
     * @return string HTML
     */
    private static function build_cta_button($url, $total_matches) {
        $button_text = 'View All Results';
        if ($total_matches > 25) {
            $button_text = 'View All ' . $total_matches . ' Results';
        }

        return '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:35px;">
                                <tr>
                                    <td align="center">
                                        <a href="' . esc_url($url) . '" style="display:inline-block;background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);color:#ffffff;padding:18px 45px;text-decoration:none;border-radius:8px;font-weight:600;font-size:18px;box-shadow:0 2px 4px rgba(0,0,0,0.15);">' . esc_html($button_text) . '</a>
                                    </td>
                                </tr>
                            </table>
';
    }

    /**
     * Get email footer HTML
     *
     * Uses unified footer from MLD_Email_Utilities with:
     * - Social media links from theme settings
     * - Prominent App Store download section with QR code
     *
     * @return string HTML
     * @since 6.13.14 Added social media links support
     * @since 6.63.0 Updated to use unified footer from MLD_Email_Utilities
     */
    private static function get_email_footer() {
        // Get unified footer content
        $footer_content = '';
        if (class_exists('MLD_Email_Utilities')) {
            $footer_content = MLD_Email_Utilities::get_unified_footer([
                'context' => 'property_alert',
                'show_social' => true,
                'show_app_download' => true,
                'show_qr_code' => true,
                'unsubscribe_url' => home_url('/my-account/saved-searches/'),
            ]);
        } else {
            // Fallback to basic footer
            $site_name = get_bloginfo('name');
            $site_url = home_url();
            $footer_content = '<p style="margin:0;color:#4a4a4a;font-size:14px;"><a href="' . esc_url($site_url) . '">' . esc_html($site_name) . '</a></p>';
        }

        $html = '                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:25px 40px;text-align:center;border-top:1px solid #e9ecef;">
                            ' . $footer_content . '
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Calculate price per square foot
     *
     * @param array $listing Listing data
     * @return int|null Price per sqft or null if not calculable
     * @since 6.13.14
     */
    private static function get_price_per_sqft($listing) {
        $sqft = $listing['building_area_total'] ?? $listing['living_area'] ?? 0;
        $price = $listing['list_price'] ?? 0;
        if ($sqft > 0 && $price > 0) {
            return (int) round($price / $sqft);
        }
        return null;
    }

    /**
     * Calculate days on market
     *
     * @param array $listing Listing data
     * @return int|null Days on market or null if not available
     * @since 6.13.14
     */
    private static function get_days_on_market($listing) {
        $list_date = $listing['listing_contract_date'] ?? $listing['created_at'] ?? null;
        if ($list_date) {
            $days = floor((time() - strtotime($list_date)) / 86400);
            return max(0, $days);
        }
        return null;
    }

    /**
     * Get property description excerpt (30 words)
     *
     * @param array $listing Listing data
     * @return string Truncated description or empty string
     * @since 6.13.14
     */
    private static function get_description_excerpt($listing) {
        $remarks = $listing['public_remarks'] ?? '';
        if (!empty($remarks)) {
            return wp_trim_words($remarks, 30, '...');
        }
        return '';
    }

    /**
     * Build market insights section
     *
     * Shows average price, average days on market, and active listing count
     * for the user's search area based on their saved search filters.
     *
     * @param array $search Saved search data
     * @param array $grouped Grouped changes
     * @return string HTML for market insights section
     * @since 6.13.14
     */
    private static function build_market_insights_section($search, $grouped) {
        global $wpdb;

        // Get filters to determine search area
        $filters = json_decode($search['filters'], true);
        $cities = $filters['selected_cities'] ?? [];

        // Build city filter clause
        $city_clause = '';
        $query_args = [];
        if (!empty($cities)) {
            $placeholders = implode(',', array_fill(0, count($cities), '%s'));
            $city_clause = "AND city IN ($placeholders)";
            $query_args = $cities;
        }

        // Get market stats from active listings
        // Use WordPress timezone-aware date for comparison
        $wp_now = current_time('mysql');
        $sql = "
            SELECT
                AVG(list_price) as avg_price,
                AVG(DATEDIFF(%s, listing_contract_date)) as avg_dom,
                COUNT(*) as total_active
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
            {$city_clause}
        ";

        // Prepare query with all arguments
        array_unshift($query_args, $wp_now);
        $stats = $wpdb->get_row($wpdb->prepare($sql, ...$query_args));

        if (!$stats || !$stats->avg_price) {
            return '';
        }

        $html = '
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:30px 0;background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);border-radius:10px;overflow:hidden;">
                                <tr>
                                    <td style="padding:25px;">
                                        <h3 style="color:#fff;margin:0 0 20px 0;font-size:20px;font-weight:600;">Market Insights for Your Search Area</h3>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">$' . number_format($stats->avg_price) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Avg. List Price</div>
                                                    </div>
                                                </td>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">' . round($stats->avg_dom) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Avg. Days on Market</div>
                                                    </div>
                                                </td>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">' . number_format($stats->total_active) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Active Listings</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
';

        return $html;
    }
}
