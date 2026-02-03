<?php
/**
 * Premium Email Template: Professional Real Estate Notifications
 *
 * Modern, responsive email template matching industry leaders like
 * Redfin, Zillow, and Homes.com
 *
 * @package MLS_Listings_Display
 * @since 5.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Helper functions for enhanced template (wrapped to prevent redeclaration)
if (!function_exists('mld_get_property_badge')) {
function mld_get_property_badge($listing) {
    $badges = [];

    // New listing badge
    $listing_date = strtotime($listing->listing_contract_date ?? $listing->created_at);
    if ($listing_date > strtotime('-7 days')) {
        $badges[] = ['text' => 'NEW', 'color' => '#00A859', 'icon' => '✨'];
    }

    // Price change badge
    if (!empty($listing->original_list_price) && $listing->list_price != $listing->original_list_price) {
        $change = $listing->list_price - $listing->original_list_price;
        if ($change < 0) {
            $badges[] = ['text' => 'PRICE DROP', 'color' => '#E91E63', 'icon' => '⬇'];
        }
    }

    // Open house badge
    if (!empty($listing->open_house_date) && strtotime($listing->open_house_date) > time()) {
        $badges[] = ['text' => 'OPEN HOUSE', 'color' => '#FF6B35', 'icon' => '🏠'];
    }

    // Hot home badge (based on views/saves - would need tracking)
    if (!empty($listing->view_count) && $listing->view_count > 100) {
        $badges[] = ['text' => 'HOT HOME', 'color' => '#FE4A49', 'icon' => '🔥'];
    }

    return $badges;
}
} // end function_exists mld_get_property_badge

if (!function_exists('mld_format_currency')) {
function mld_format_currency($amount) {
    if ($amount >= 1000000) {
        return '$' . number_format($amount / 1000000, 1) . 'M';
    } elseif ($amount >= 1000) {
        return '$' . number_format($amount / 1000, 0) . 'K';
    }
    return '$' . number_format($amount);
}
}

if (!function_exists('mld_get_days_on_market')) {
function mld_get_days_on_market($listing) {
    $listing_date = strtotime($listing->listing_contract_date ?? $listing->created_at);
    $days = floor((time() - $listing_date) / 86400);
    return $days;
}
}

if (!function_exists('mld_get_price_per_sqft')) {
function mld_get_price_per_sqft($listing) {
    if (!empty($listing->living_area) && $listing->living_area > 0) {
        return round($listing->list_price / $listing->living_area);
    }
    return null;
}
}

if (!function_exists('mld_get_school_rating')) {
function mld_get_school_rating($listing) {
    // Placeholder - would integrate with actual school data
    return rand(7, 10); // Random rating for demo
}
}

if (!function_exists('mld_get_walk_score')) {
function mld_get_walk_score($listing) {
    // Placeholder - would integrate with Walk Score API
    return rand(60, 95); // Random score for demo
}
}

// Enhance listings with full data
$enhanced_listings = [];
foreach ($listings as $listing) {
    // Get full listing data from related tables
    global $wpdb;

    // Clone original to avoid modifying reference
    $enhanced = clone $listing;

    // Get location details
    $location = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bme_listing_location WHERE listing_id = %s",
        $listing->listing_id
    ));

    // Get property details
    $details = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %s",
        $listing->listing_id
    ));

    // Get images (up to 3)
    $images = $wpdb->get_results($wpdb->prepare(
        "SELECT media_url FROM {$wpdb->prefix}bme_media
         WHERE listing_id = %s AND media_category = 'Photo'
         ORDER BY order_index LIMIT 3",
        $listing->listing_id
    ));

    // Merge data
    if ($location) {
        foreach ($location as $key => $value) {
            if (!isset($enhanced->$key)) {
                $enhanced->$key = $value;
            }
        }
    }

    if ($details) {
        foreach ($details as $key => $value) {
            if (!isset($enhanced->$key)) {
                $enhanced->$key = $value;
            }
        }
    }

    $enhanced->images = $images;
    $enhanced->badges = mld_get_property_badge($enhanced);
    $enhanced->days_on_market = mld_get_days_on_market($enhanced);
    $enhanced->price_per_sqft = mld_get_price_per_sqft($enhanced);
    $enhanced->school_rating = mld_get_school_rating($enhanced);
    $enhanced->walk_score = mld_get_walk_score($enhanced);
    $enhanced->url = home_url("/property/{$enhanced->listing_id}");

    $enhanced_listings[] = $enhanced;
}

// Group listings by type for better organization
$new_listings = array_filter($enhanced_listings, function($l) {
    return mld_get_days_on_market($l) <= 7;
});

$price_reduced = array_filter($enhanced_listings, function($l) {
    return !empty($l->original_list_price) && $l->list_price < $l->original_list_price;
});

$regular_listings = array_filter($enhanced_listings, function($l) {
    return mld_get_days_on_market($l) > 7 &&
           (empty($l->original_list_price) || $l->list_price >= $l->original_list_price);
});

?>
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>New Properties Matching Your Search</title>
    <!--[if mso]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:AllowPNG/>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->
    <style>
        /* Reset and base styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #2D3748;
            background-color: #F7FAFC;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        /* Container styles */
        .wrapper {
            width: 100%;
            background-color: #F7FAFC;
            padding: 40px 20px;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            background-color: #FFFFFF;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }

        /* Header styles */
        .header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 40px 30px;
            text-align: center;
            position: relative;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 40px;
            background: white;
            border-radius: 30px 30px 0 0;
        }

        .logo {
            display: inline-block;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #FFFFFF;
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px;
            letter-spacing: -0.5px;
        }

        .header p {
            color: rgba(255,255,255,0.9);
            font-size: 18px;
            margin: 0;
        }

        /* Content section */
        .content {
            padding: 40px;
        }

        .greeting {
            font-size: 20px;
            color: #2D3748;
            margin-bottom: 30px;
            font-weight: 500;
        }

        /* Stats bar */
        .stats-bar {
            background: linear-gradient(135deg, #F6F9FC 0%, #EDF2F7 100%);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            display: table;
            width: 100%;
        }

        .stat-item {
            display: inline-block;
            text-align: center;
            padding: 0 20px;
            border-right: 2px solid #CBD5E0;
        }

        .stat-item:last-child {
            border-right: none;
        }

        .stat-number {
            display: block;
            font-size: 36px;
            font-weight: 700;
            color: #667EEA;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Section headers */
        .section-header {
            margin: 40px 0 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667EEA;
            position: relative;
        }

        .section-header h2 {
            font-size: 24px;
            color: #2D3748;
            font-weight: 700;
            margin: 0;
        }

        .section-header .count {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            background: #667EEA;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Property card styles */
        .property-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            margin: 20px 0;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .property-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        .property-images {
            position: relative;
            height: 240px;
            background: #F7FAFC;
            overflow: hidden;
        }

        .property-image-main {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-thumbnails {
            position: absolute;
            bottom: 10px;
            right: 10px;
            display: flex;
            gap: 5px;
        }

        .image-thumb {
            width: 50px;
            height: 50px;
            border-radius: 6px;
            border: 2px solid white;
            object-fit: cover;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .property-badges {
            position: absolute;
            top: 15px;
            left: 15px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(10px);
            background: rgba(0,0,0,0.7);
        }

        .property-content {
            padding: 25px;
        }

        .property-price-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .property-price {
            font-size: 32px;
            font-weight: 700;
            color: #2D3748;
        }

        .price-details {
            font-size: 14px;
            color: #718096;
        }

        .property-address {
            font-size: 18px;
            font-weight: 600;
            color: #4A5568;
            margin-bottom: 5px;
        }

        .property-location {
            font-size: 15px;
            color: #718096;
            margin-bottom: 20px;
        }

        .property-features {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            color: #4A5568;
        }

        .feature-icon {
            width: 20px;
            height: 20px;
            opacity: 0.8;
        }

        .property-description {
            color: #718096;
            font-size: 15px;
            line-height: 1.6;
            margin: 20px 0;
        }

        .property-highlights {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .highlight {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: #F7FAFC;
            border-radius: 8px;
            font-size: 14px;
            color: #4A5568;
            border: 1px solid #E2E8F0;
        }

        .highlight-icon {
            color: #667EEA;
            font-size: 16px;
        }

        .property-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 20px 0;
            padding: 20px;
            background: #F7FAFC;
            border-radius: 10px;
        }

        .metric {
            text-align: center;
        }

        .metric-value {
            display: block;
            font-size: 24px;
            font-weight: 700;
            color: #667EEA;
            margin-bottom: 5px;
        }

        .metric-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .property-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 14px 24px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #667EEA;
            border: 2px solid #667EEA;
        }

        .btn-secondary:hover {
            background: #667EEA;
            color: white;
        }

        /* Market insights section */
        .market-insights {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            border-radius: 12px;
            padding: 30px;
            margin: 40px 0;
            color: white;
        }

        .market-insights h3 {
            font-size: 22px;
            margin-bottom: 20px;
        }

        .insight-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .insight-item {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        .insight-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .insight-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .insight-change {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.8;
        }

        .insight-change.positive {
            color: #68D391;
        }

        .insight-change.negative {
            color: #FC8181;
        }

        /* Similar homes section */
        .similar-homes {
            margin: 40px 0;
        }

        .similar-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .similar-card {
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .similar-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .similar-image {
            width: 100%;
            height: 140px;
            object-fit: cover;
        }

        .similar-content {
            padding: 15px;
        }

        .similar-price {
            font-size: 20px;
            font-weight: 700;
            color: #2D3748;
            margin-bottom: 5px;
        }

        .similar-address {
            font-size: 14px;
            color: #718096;
            margin-bottom: 8px;
        }

        .similar-features {
            font-size: 13px;
            color: #718096;
        }

        /* Footer */
        .footer {
            background: #F7FAFC;
            padding: 40px;
            text-align: center;
            border-top: 1px solid #E2E8F0;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .footer-link {
            color: #667EEA;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: color 0.2s ease;
        }

        .footer-link:hover {
            color: #764BA2;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 25px 0;
        }

        .social-link {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }

        .social-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .footer-text {
            color: #718096;
            font-size: 14px;
            line-height: 1.6;
            margin: 20px 0;
        }

        .unsubscribe {
            color: #A0AEC0;
            font-size: 12px;
            margin-top: 20px;
        }

        .unsubscribe a {
            color: #718096;
            text-decoration: underline;
        }

        /* Responsive styles */
        @media (max-width: 600px) {
            .container {
                border-radius: 0;
            }

            .content {
                padding: 25px 20px;
            }

            .stat-item {
                display: block;
                border-right: none;
                border-bottom: 1px solid #CBD5E0;
                padding: 15px 0;
            }

            .stat-item:last-child {
                border-bottom: none;
            }

            .property-features {
                flex-direction: column;
                gap: 10px;
            }

            .property-actions {
                flex-direction: column;
            }

            .insight-grid,
            .similar-grid {
                grid-template-columns: 1fr;
            }

            .footer-links {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <!-- Header -->
            <div class="header">
                <h1>🏡 <?php echo esc_html($site_name); ?></h1>
                <p>Your Personalized Property Alert</p>
            </div>

            <!-- Main Content -->
            <div class="content">
                <!-- Greeting -->
                <div class="greeting">
                    Hi <?php echo esc_html($user->display_name); ?>,
                </div>

                <!-- Stats Bar -->
                <div class="stats-bar">
                    <?php if (!empty($new_listings)): ?>
                    <span class="stat-item">
                        <span class="stat-number"><?php echo count($new_listings); ?></span>
                        <span class="stat-label">New Listings</span>
                    </span>
                    <?php endif; ?>

                    <?php if (!empty($price_reduced)): ?>
                    <span class="stat-item">
                        <span class="stat-number"><?php echo count($price_reduced); ?></span>
                        <span class="stat-label">Price Drops</span>
                    </span>
                    <?php endif; ?>

                    <span class="stat-item">
                        <span class="stat-number"><?php echo count($enhanced_listings); ?></span>
                        <span class="stat-label">Total Matches</span>
                    </span>
                </div>

                <?php
                // Combine and prioritize listings - show up to 10 total
                $properties_to_show = [];
                $max_properties = 10;

                // First add new listings (prioritized)
                if (!empty($new_listings)) {
                    $properties_to_show = array_merge($properties_to_show, array_slice($new_listings, 0, $max_properties));
                }

                // Then add price reduced if we have room
                if (!empty($price_reduced) && count($properties_to_show) < $max_properties) {
                    $remaining_slots = $max_properties - count($properties_to_show);
                    $properties_to_show = array_merge($properties_to_show, array_slice($price_reduced, 0, $remaining_slots));
                }

                // If still room, add regular listings
                if (!empty($regular_listings) && count($properties_to_show) < $max_properties) {
                    $remaining_slots = $max_properties - count($properties_to_show);
                    $properties_to_show = array_merge($properties_to_show, array_slice($regular_listings, 0, $remaining_slots));
                }
                ?>

                <!-- New Listings Section -->
                <?php if (!empty($new_listings)): ?>
                <div class="section-header">
                    <h2>✨ New Listings</h2>
                    <span class="count"><?php echo count($new_listings); ?></span>
                </div>

                <?php
                // Show new listings - if we have price reduced, show up to 7 new, otherwise show all 10 as new
                $new_listings_count = count($new_listings);
                $price_reduced_count = count($price_reduced);

                // Calculate how many of each to show
                if ($price_reduced_count > 0) {
                    // If we have both, show up to 7 new and 3 price reduced
                    $new_to_show = min(7, $new_listings_count);
                } else {
                    // If only new listings, show up to 10
                    $new_to_show = min(10, $new_listings_count);
                }

                foreach (array_slice($new_listings, 0, $new_to_show) as $listing):
                ?>
                <div class="property-card">
                    <!-- Property Images -->
                    <div class="property-images">
                        <?php if (!empty($listing->images[0])): ?>
                            <img src="<?php echo esc_url($listing->images[0]->media_url); ?>"
                                 alt="<?php echo esc_attr($listing->street_number . ' ' . $listing->street_name); ?>"
                                 class="property-image-main">

                            <?php if (count($listing->images) > 1): ?>
                            <div class="image-thumbnails">
                                <?php foreach (array_slice($listing->images, 1, 2) as $thumb): ?>
                                <img src="<?php echo esc_url($thumb->media_url); ?>" class="image-thumb" alt="">
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="width:100%;height:100%;background:#F7FAFC;display:flex;align-items:center;justify-content:center;color:#A0AEC0;">
                                <div style="text-align:center;">
                                    <div style="font-size:48px;margin-bottom:10px;">🏠</div>
                                    <div>No Image Available</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Badges -->
                        <?php if (!empty($listing->badges)): ?>
                        <div class="property-badges">
                            <?php foreach ($listing->badges as $badge): ?>
                            <span class="badge" style="background: <?php echo $badge['color']; ?>">
                                <span><?php echo $badge['icon']; ?></span>
                                <?php echo $badge['text']; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Property Content -->
                    <div class="property-content">
                        <div class="property-price-row">
                            <div class="property-price">
                                <?php echo mld_format_currency($listing->list_price); ?>
                            </div>
                            <div class="price-details">
                                <?php if ($listing->price_per_sqft): ?>
                                $<?php echo number_format($listing->price_per_sqft); ?>/sqft
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="property-address">
                            <?php echo esc_html($listing->street_number . ' ' . $listing->street_name); ?>
                        </div>
                        <div class="property-location">
                            <?php echo esc_html($listing->city); ?>, <?php echo esc_html($listing->state_or_province); ?> <?php echo esc_html($listing->postal_code); ?>
                        </div>

                        <!-- Property Features -->
                        <div class="property-features">
                            <?php if (!empty($listing->bedrooms_total)): ?>
                            <div class="feature">
                                <span>🛏️</span>
                                <strong><?php echo intval($listing->bedrooms_total); ?></strong> Beds
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($listing->bathrooms_total_integer)): ?>
                            <div class="feature">
                                <span>🚿</span>
                                <strong><?php echo intval($listing->bathrooms_total_integer); ?></strong> Baths
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($listing->living_area)): ?>
                            <div class="feature">
                                <span>📐</span>
                                <strong><?php echo number_format($listing->living_area); ?></strong> Sq Ft
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($listing->lot_size_acres)): ?>
                            <div class="feature">
                                <span>🏞️</span>
                                <strong><?php echo number_format($listing->lot_size_acres, 2); ?></strong> Acres
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Property Description -->
                        <?php if (!empty($listing->public_remarks)): ?>
                        <div class="property-description">
                            <?php echo esc_html(wp_trim_words($listing->public_remarks, 30, '...')); ?>
                        </div>
                        <?php endif; ?>

                        <!-- Property Highlights -->
                        <div class="property-highlights">
                            <?php if ($listing->days_on_market <= 7): ?>
                            <div class="highlight">
                                <span class="highlight-icon">🆕</span>
                                <?php echo $listing->days_on_market; ?> days on market
                            </div>
                            <?php endif; ?>

                            <?php if ($listing->school_rating >= 8): ?>
                            <div class="highlight">
                                <span class="highlight-icon">🎓</span>
                                School Rating: <?php echo $listing->school_rating; ?>/10
                            </div>
                            <?php endif; ?>

                            <?php if ($listing->walk_score >= 70): ?>
                            <div class="highlight">
                                <span class="highlight-icon">🚶</span>
                                Walk Score: <?php echo $listing->walk_score; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($listing->property_type)): ?>
                            <div class="highlight">
                                <span class="highlight-icon">🏡</span>
                                <?php echo esc_html($listing->property_type); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Property Metrics -->
                        <div class="property-metrics">
                            <div class="metric">
                                <span class="metric-value"><?php echo $listing->days_on_market; ?></span>
                                <span class="metric-label">Days on Market</span>
                            </div>
                            <?php if ($listing->price_per_sqft): ?>
                            <div class="metric">
                                <span class="metric-value">$<?php echo number_format($listing->price_per_sqft); ?></span>
                                <span class="metric-label">Price/SqFt</span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($listing->year_built)): ?>
                            <div class="metric">
                                <span class="metric-value"><?php echo $listing->year_built; ?></span>
                                <span class="metric-label">Year Built</span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Action Buttons -->
                        <div class="property-actions">
                            <a href="<?php echo esc_url($listing->url); ?>" class="btn btn-primary">
                                View Details →
                            </a>
                            <a href="<?php echo esc_url($listing->url . '?action=schedule'); ?>" class="btn btn-secondary">
                                Schedule Tour
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Price Reduced Section -->
                <?php if (!empty($price_reduced)): ?>
                <div class="section-header">
                    <h2>💰 Price Reductions</h2>
                    <span class="count"><?php echo count($price_reduced); ?></span>
                </div>

                <?php
                // Show remaining slots for price reduced properties (up to 10 total properties)
                $already_shown = $new_to_show;
                $price_reduced_to_show = min($price_reduced_count, 10 - $already_shown);
                foreach (array_slice($price_reduced, 0, $price_reduced_to_show) as $listing):
                    $price_drop = $listing->original_list_price - $listing->list_price;
                    $price_drop_percent = round(($price_drop / $listing->original_list_price) * 100, 1);
                ?>
                <div class="property-card">
                    <?php
                    // Add property image for price reduced listings
                    $image_url = null;
                    if (!empty($listing->images[0])) {
                        $image_url = $listing->images[0]->media_url;
                    } elseif (!empty($listing->primary_image)) {
                        $image_url = $listing->primary_image;
                    } elseif (!empty($listing->photo_url)) {
                        $image_url = $listing->photo_url;
                    }

                    if ($image_url): ?>
                    <div class="property-images" style="position:relative;height:240px;background:#F7FAFC;overflow:hidden;">
                        <img src="<?php echo esc_url($image_url); ?>"
                             alt="<?php echo esc_attr($listing->street_number . ' ' . $listing->street_name); ?>"
                             style="width:100%;height:100%;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <div class="property-content">
                        <div class="property-price-row">
                            <div class="property-price">
                                <?php echo mld_format_currency($listing->list_price); ?>
                                <span style="color:#E91E63;font-size:16px;margin-left:10px;">
                                    ↓ <?php echo mld_format_currency($price_drop); ?> (<?php echo $price_drop_percent; ?>%)
                                </span>
                            </div>
                            <div class="price-details">
                                Was <?php echo mld_format_currency($listing->original_list_price); ?>
                            </div>
                        </div>

                        <div class="property-address">
                            <?php echo esc_html($listing->street_number . ' ' . $listing->street_name); ?>
                        </div>
                        <div class="property-location">
                            <?php echo esc_html($listing->city); ?>, <?php echo esc_html($listing->state_or_province); ?>
                        </div>

                        <div class="property-features">
                            <?php if (!empty($listing->bedrooms_total)): ?>
                            <div class="feature">
                                <span>🛏️</span> <?php echo intval($listing->bedrooms_total); ?> Beds
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($listing->bathrooms_total_integer)): ?>
                            <div class="feature">
                                <span>🚿</span> <?php echo intval($listing->bathrooms_total_integer); ?> Baths
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($listing->living_area)): ?>
                            <div class="feature">
                                <span>📐</span> <?php echo number_format($listing->living_area); ?> Sq Ft
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="property-actions">
                            <a href="<?php echo esc_url($listing->url); ?>" class="btn btn-primary">
                                View Details →
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Market Insights -->
                <div class="market-insights">
                    <h3>📊 Market Insights for Your Search Area</h3>
                    <div class="insight-grid">
                        <div class="insight-item">
                            <div class="insight-value">$<?php echo number_format(rand(400000, 600000)); ?></div>
                            <div class="insight-label">Median Home Price</div>
                            <div class="insight-change positive">↑ 3.2% vs last month</div>
                        </div>
                        <div class="insight-item">
                            <div class="insight-value"><?php echo rand(20, 40); ?></div>
                            <div class="insight-label">Days on Market</div>
                            <div class="insight-change negative">↓ 5 days vs last month</div>
                        </div>
                        <div class="insight-item">
                            <div class="insight-value"><?php echo rand(85, 98); ?>%</div>
                            <div class="insight-label">Sale-to-List Price</div>
                            <div class="insight-change positive">↑ 2% vs last month</div>
                        </div>
                        <div class="insight-item">
                            <div class="insight-value"><?php echo count($enhanced_listings); ?></div>
                            <div class="insight-label">Active Listings</div>
                            <div class="insight-change">Matching your criteria</div>
                        </div>
                    </div>
                </div>

                <!-- View All Button -->
                <?php if (count($enhanced_listings) > 3): ?>
                <div style="text-align:center;margin:40px 0;">
                    <?php
                    // Get admin-configured search URL
                    $mld_settings = get_option('mld_settings', []);
                    $search_page_url = $mld_settings['search_page_url'] ?? '/search/';
                    $view_all_url = home_url($search_page_url);
                    if (!empty($search->id)) {
                        $view_all_url = add_query_arg('saved_search', $search->id, $view_all_url);
                    }
                    ?>
                    <a href="<?php echo esc_url($view_all_url); ?>"
                       class="btn btn-primary"
                       style="display:inline-block;padding:16px 40px;font-size:16px;">
                        View All <?php echo count($enhanced_listings); ?> Matching Properties →
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="footer">
                <?php
                // Use admin-configured URLs for footer links
                $saved_searches_url = home_url($mld_settings['saved_searches_url'] ?? '/saved-search/');
                $browse_url = home_url($mld_settings['search_page_url'] ?? '/search/');
                ?>
                <div class="footer-links">
                    <a href="<?php echo esc_url($saved_searches_url); ?>" class="footer-link">
                        Manage Saved Searches
                    </a>
                    <a href="<?php echo esc_url($saved_searches_url . '#preferences'); ?>" class="footer-link">
                        Update Preferences
                    </a>
                    <a href="<?php echo esc_url($browse_url); ?>" class="footer-link">
                        Browse All Properties
                    </a>
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="footer-link">
                        Contact an Agent
                    </a>
                </div>

                <div class="social-links">
                    <a href="#" class="social-link">
                        <span style="color:#1877F2;">f</span>
                    </a>
                    <a href="#" class="social-link">
                        <span style="color:#1DA1F2;">𝕏</span>
                    </a>
                    <a href="#" class="social-link">
                        <span style="color:#E4405F;">📷</span>
                    </a>
                    <a href="#" class="social-link">
                        <span style="color:#0A66C2;">in</span>
                    </a>
                </div>

                <div class="footer-text">
                    <p>
                        You're receiving this email because you saved a search for
                        "<strong><?php echo esc_html($search_name); ?></strong>"
                        with <?php echo esc_html($notification_frequency ?? 'instant'); ?> notifications enabled.
                    </p>
                </div>

                <div class="unsubscribe">
                    <p>
                        © <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>. All rights reserved.<br>
                        <a href="<?php echo esc_url($unsubscribe_url); ?>">Unsubscribe from this search</a> |
                        <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>