<?php
/**
 * Enhanced Email Template: Listing Updates with Images and Rich Details
 *
 * @package MLS_Listings_Display
 * @since 5.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

// Helper function to get listing details from related tables
function get_full_listing_data($listing) {
    global $wpdb;

    // Get location data
    $location = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bme_listing_location WHERE listing_id = %d",
        $listing->listing_id
    ));

    // Get property details
    $details = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %d",
        $listing->listing_id
    ));

    // Get first image
    $image = $wpdb->get_var($wpdb->prepare(
        "SELECT media_url FROM {$wpdb->prefix}bme_media
         WHERE listing_id = %d AND media_category = 'Photo'
         ORDER BY order_index LIMIT 1",
        $listing->listing_id
    ));

    // Merge all data
    if ($location) {
        foreach ($location as $key => $value) {
            if (!isset($listing->$key)) {
                $listing->$key = $value;
            }
        }
    }

    if ($details) {
        foreach ($details as $key => $value) {
            if (!isset($listing->$key)) {
                $listing->$key = $value;
            }
        }
    }

    $listing->primary_image = $image;

    return $listing;
}

// Format currency
function format_price($price) {
    return '$' . number_format($price);
}

// Format price change
function get_price_change_text($listing) {
    if ($listing->mls_status === 'Price Changed' && $listing->original_list_price) {
        $change = $listing->list_price - $listing->original_list_price;
        $percent = round(($change / $listing->original_list_price) * 100, 1);
        if ($change < 0) {
            return '<span style="color: #28a745;">↓ ' . format_price(abs($change)) . ' (' . abs($percent) . '% reduction)</span>';
        } elseif ($change > 0) {
            return '<span style="color: #dc3545;">↑ ' . format_price($change) . ' (' . $percent . '% increase)</span>';
        }
    }
    return '';
}

// Generate listing URL
function get_listing_url($listing) {
    // Use the correct property URL structure
    return home_url("/property/{$listing->listing_id}");
}

// Get status badge color
function get_status_color($status) {
    switch(strtolower($status)) {
        case 'new':
            return '#28a745';
        case 'price changed':
            return '#ffc107';
        case 'back on market':
            return '#17a2b8';
        default:
            return '#6c757d';
    }
}

// Enhance listings with full data
$enhanced_listings = array_map('get_full_listing_data', $listings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($search_name); ?> - Property Updates</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 650px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #2c5aa0 0%, #1e3d6f 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.95;
            font-size: 16px;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 25px;
            color: #333;
        }
        .summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 5px solid #2c5aa0;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .summary h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #2c5aa0;
        }
        .summary p {
            margin: 0;
            color: #555;
            font-size: 15px;
        }
        .listing {
            border: 1px solid #dee2e6;
            border-radius: 12px;
            margin: 25px 0;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background: white;
        }
        .listing:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .listing-image {
            width: 100%;
            height: 280px;
            object-fit: cover;
            display: block;
        }
        .no-image {
            width: 100%;
            height: 280px;
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 18px;
        }
        .listing-status-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: white;
            letter-spacing: 0.5px;
        }
        .listing-image-container {
            position: relative;
        }
        .listing-content {
            padding: 25px;
        }
        .listing-header {
            margin-bottom: 20px;
        }
        .listing-price-row {
            display: flex;
            align-items: baseline;
            gap: 15px;
            margin-bottom: 10px;
        }
        .listing-price {
            font-size: 28px;
            font-weight: 700;
            color: #2c5aa0;
            margin: 0;
        }
        .price-change {
            font-size: 14px;
            font-weight: 600;
        }
        .listing-address {
            font-size: 18px;
            color: #333;
            margin: 0 0 5px 0;
            font-weight: 500;
        }
        .listing-city {
            font-size: 15px;
            color: #666;
            margin: 0 0 15px 0;
        }
        .listing-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            padding: 15px 0;
            border-top: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
            margin: 15px 0;
        }
        .detail-item {
            text-align: center;
        }
        .detail-value {
            font-size: 20px;
            font-weight: 700;
            color: #2c5aa0;
            display: block;
        }
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .listing-description {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin: 20px 0;
        }
        .listing-features {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 15px 0;
        }
        .feature-tag {
            background: #e9ecef;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 13px;
            color: #495057;
        }
        .view-listing-btn {
            display: inline-block;
            background: linear-gradient(135deg, #2c5aa0 0%, #1e3d6f 100%);
            color: white;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            transition: transform 0.2s ease;
        }
        .view-listing-btn:hover {
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }
        .mls-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
            font-size: 13px;
            color: #6c757d;
        }
        .footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 35px 30px;
            text-align: center;
            border-top: 1px solid #dee2e6;
        }
        .footer p {
            margin: 0 0 12px 0;
            color: #555;
            font-size: 14px;
        }
        .footer a {
            color: #2c5aa0;
            text-decoration: none;
            font-weight: 600;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .unsubscribe {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #6c757d;
        }
        .unsubscribe a {
            color: #6c757d;
            text-decoration: underline;
        }
        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .listing-details {
                grid-template-columns: repeat(2, 1fr);
            }
            .listing-price {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo esc_html($site_name); ?></h1>
            <p>🏡 Property Update Notification</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hello <?php echo esc_html($user->display_name); ?>,
            </div>

            <div class="summary">
                <h2>
                    <?php
                    $new_count = 0;
                    $updated_count = 0;
                    foreach ($enhanced_listings as $l) {
                        if ($l->mls_status === 'New') $new_count++;
                        else $updated_count++;
                    }

                    if ($new_count > 0 && $updated_count > 0) {
                        echo $new_count . ' New & ' . $updated_count . ' Updated Listings';
                    } elseif ($new_count > 0) {
                        echo $new_count . ' New ' . ($new_count === 1 ? 'Listing' : 'Listings');
                    } else {
                        echo $updated_count . ' Updated ' . ($updated_count === 1 ? 'Listing' : 'Listings');
                    }
                    ?>
                </h2>
                <p>
                    We found <?php echo count($enhanced_listings); ?>
                    <?php echo count($enhanced_listings) === 1 ? 'property' : 'properties'; ?>
                    matching your saved search "<strong><?php echo esc_html($search_name); ?></strong>".
                </p>
            </div>

            <!-- Listings -->
            <?php foreach ($enhanced_listings as $listing): ?>
            <div class="listing">
                <!-- Property Image -->
                <div class="listing-image-container">
                    <?php if (!empty($listing->primary_image)): ?>
                        <img src="<?php echo esc_url($listing->primary_image); ?>"
                             alt="<?php echo esc_attr($listing->unparsed_address); ?>"
                             class="listing-image">
                    <?php else: ?>
                        <div class="no-image">No Image Available</div>
                    <?php endif; ?>

                    <?php if (!empty($listing->mls_status)): ?>
                        <div class="listing-status-badge" style="background-color: <?php echo get_status_color($listing->mls_status); ?>">
                            <?php echo esc_html($listing->mls_status); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="listing-content">
                    <div class="listing-header">
                        <div class="listing-price-row">
                            <div class="listing-price">
                                <?php echo format_price($listing->list_price); ?>
                            </div>
                            <?php
                            $price_change = get_price_change_text($listing);
                            if ($price_change): ?>
                                <div class="price-change"><?php echo $price_change; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="listing-address">
                            <?php echo esc_html($listing->street_number . ' ' . $listing->street_name); ?>
                        </div>
                        <div class="listing-city">
                            <?php echo esc_html($listing->city); ?>, <?php echo esc_html($listing->state_or_province); ?> <?php echo esc_html($listing->postal_code); ?>
                        </div>
                    </div>

                    <div class="listing-details">
                        <?php if (!empty($listing->bedrooms_total)): ?>
                        <div class="detail-item">
                            <span class="detail-value"><?php echo intval($listing->bedrooms_total); ?></span>
                            <span class="detail-label">Bed<?php echo intval($listing->bedrooms_total) !== 1 ? 's' : ''; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($listing->bathrooms_total_integer)): ?>
                        <div class="detail-item">
                            <span class="detail-value"><?php echo intval($listing->bathrooms_total_integer); ?></span>
                            <span class="detail-label">Bath<?php echo intval($listing->bathrooms_total_integer) !== 1 ? 's' : ''; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($listing->living_area)): ?>
                        <div class="detail-item">
                            <span class="detail-value"><?php echo number_format(intval($listing->living_area)); ?></span>
                            <span class="detail-label">Sq Ft</span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($listing->lot_size_acres)): ?>
                        <div class="detail-item">
                            <span class="detail-value"><?php echo number_format($listing->lot_size_acres, 2); ?></span>
                            <span class="detail-label">Acres</span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($listing->year_built)): ?>
                        <div class="detail-item">
                            <span class="detail-value"><?php echo intval($listing->year_built); ?></span>
                            <span class="detail-label">Built</span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($listing->property_type)): ?>
                        <div class="detail-item">
                            <span class="detail-value" style="font-size: 14px;"><?php echo esc_html($listing->property_type); ?></span>
                            <span class="detail-label">Type</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($listing->public_remarks)): ?>
                    <div class="listing-description">
                        <?php echo esc_html(wp_trim_words($listing->public_remarks, 40, '...')); ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Show key features if available
                    $features = [];
                    if (!empty($listing->attached_garage_yn) && $listing->attached_garage_yn) {
                        $features[] = 'Attached Garage';
                    }
                    if (!empty($listing->basement) && is_array(json_decode($listing->basement, true))) {
                        $features[] = 'Basement';
                    }
                    if (!empty($listing->cooling) && $listing->cooling !== '[]') {
                        $cooling = json_decode($listing->cooling, true);
                        if (!empty($cooling[0])) $features[] = $cooling[0];
                    }
                    if (!empty($listing->heating) && $listing->heating !== '[]') {
                        $heating = json_decode($listing->heating, true);
                        if (!empty($heating[0])) $features[] = $heating[0] . ' Heat';
                    }

                    if (!empty($features)): ?>
                    <div class="listing-features">
                        <?php foreach (array_slice($features, 0, 4) as $feature): ?>
                            <span class="feature-tag"><?php echo esc_html($feature); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url(get_listing_url($listing)); ?>" class="view-listing-btn">
                        View Full Details →
                    </a>

                    <div class="mls-info">
                        <span>MLS #<?php echo esc_html($listing->listing_id); ?></span>
                        <span>Listed <?php
                            // v6.75.7: Fix timezone bug - use DateTime with wp_timezone() instead of strtotime()
                            // Database stores in WP timezone, strtotime() interprets as UTC causing 5-hour offset
                            $list_date = new DateTime($listing->listing_contract_date, wp_timezone());
                            echo wp_date('M j, Y', $list_date->getTimestamp());
                        ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (count($enhanced_listings) > 5): ?>
            <div style="text-align: center; margin: 35px 0;">
                <a href="<?php echo esc_url(home_url('/properties/')); ?>"
                   style="color: #2c5aa0; text-decoration: none; font-weight: 600; font-size: 16px;">
                    View All <?php echo count($enhanced_listings); ?> Matching Properties →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                This email was sent because you have notifications enabled for your saved search
                "<strong><?php echo esc_html($search_name); ?></strong>".
            </p>
            <p>
                <a href="<?php echo esc_url(home_url('/saved-search/')); ?>">Manage Your Saved Searches</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url($site_url); ?>">Visit Our Website</a>
            </p>

            <!-- iOS App Store Badge -->
            <div style="margin: 25px 0; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center;">
                <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">
                    Get faster alerts on your iPhone
                </p>
                <a href="https://apps.apple.com/us/app/bmn-boston/id6745724401" style="display: inline-block;">
                    <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83"
                         alt="Download on the App Store"
                         style="height: 40px; width: auto;">
                </a>
            </div>

            <div class="unsubscribe">
                <p>
                    Don't want these notifications?<br>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>">Unsubscribe from this search</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>