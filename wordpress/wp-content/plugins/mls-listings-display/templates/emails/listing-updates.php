<?php
/**
 * Email Template: Listing Updates
 *
 * Simple, clean email template for listing update notifications
 *
 * Available variables:
 * - $user: WP_User object
 * - $search: Saved search object
 * - $listings: Array of listing objects
 * - $search_name: Name of the saved search
 * - $listing_count: Number of listings
 * - $site_name: Site name
 * - $site_url: Site URL
 * - $unsubscribe_url: Unsubscribe URL
 *
 * @package MLS_Listings_Display
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Format currency
function format_price($price) {
    return '$' . number_format($price);
}

// Generate listing URL
function get_listing_url($listing) {
    // Use listing_id instead of mls_number
    $id = isset($listing->listing_id) ? $listing->listing_id :
          (isset($listing->mls_number) ? $listing->mls_number : '');
    return home_url("/property/{$id}");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($search_name); ?> - New Listings</title>
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
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #2c5aa0;
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 30px 20px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
        }
        .summary {
            background-color: #f8f9fa;
            border-left: 4px solid #2c5aa0;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .summary h2 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #2c5aa0;
        }
        .summary p {
            margin: 0;
            color: #666;
        }
        .listing {
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            margin: 20px 0;
            overflow: hidden;
            transition: box-shadow 0.2s ease;
        }
        .listing:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .listing-header {
            background-color: #fff;
            padding: 20px;
            border-bottom: 1px solid #e1e5e9;
        }
        .listing-price {
            font-size: 24px;
            font-weight: 700;
            color: #2c5aa0;
            margin: 0 0 5px 0;
        }
        .listing-address {
            font-size: 16px;
            color: #333;
            margin: 0 0 10px 0;
        }
        .listing-details {
            display: flex;
            gap: 20px;
            font-size: 14px;
            color: #666;
        }
        .listing-details span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .listing-body {
            padding: 20px;
        }
        .listing-description {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
            margin: 0 0 15px 0;
        }
        .view-listing-btn {
            display: inline-block;
            background-color: #2c5aa0;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: background-color 0.2s ease;
        }
        .view-listing-btn:hover {
            background-color: #1e3d6f;
            color: white;
            text-decoration: none;
        }
        .mls-number {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px 20px;
            text-align: center;
            border-top: 1px solid #e1e5e9;
        }
        .footer p {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
        }
        .footer a {
            color: #2c5aa0;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .unsubscribe {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e1e5e9;
            font-size: 12px;
            color: #999;
        }
        .unsubscribe a {
            color: #999;
        }
        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .listing-details {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo esc_html($site_name); ?></h1>
            <p>New Property Listings</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hello <?php echo esc_html($user->display_name); ?>,
            </div>

            <div class="summary">
                <h2><?php echo $listing_count === 1 ? 'New Listing Match' : 'New Listing Matches'; ?></h2>
                <p>
                    We found <?php echo $listing_count; ?> new
                    <?php echo $listing_count === 1 ? 'property' : 'properties'; ?>
                    matching your saved search "<strong><?php echo esc_html($search_name); ?></strong>".
                </p>
            </div>

            <!-- Listings -->
            <?php foreach ($listings as $listing): ?>
            <div class="listing">
                <div class="listing-header">
                    <div class="listing-price">
                        <?php echo format_price($listing->list_price); ?>
                    </div>
                    <div class="listing-address">
                        <?php echo esc_html($listing->address); ?><br>
                        <?php echo esc_html($listing->city); ?>, <?php echo esc_html($listing->state_or_province); ?> <?php echo esc_html($listing->postal_code); ?>
                    </div>
                    <div class="listing-details">
                        <?php if (!empty($listing->bedrooms_total)): ?>
                        <span>
                            <strong><?php echo intval($listing->bedrooms_total); ?></strong> Bed<?php echo intval($listing->bedrooms_total) !== 1 ? 's' : ''; ?>
                        </span>
                        <?php endif; ?>

                        <?php if (!empty($listing->bathrooms_total)): ?>
                        <span>
                            <strong><?php echo number_format(floatval($listing->bathrooms_total), 1); ?></strong> Bath<?php echo floatval($listing->bathrooms_total) !== 1.0 ? 's' : ''; ?>
                        </span>
                        <?php endif; ?>

                        <?php if (!empty($listing->living_area)): ?>
                        <span>
                            <strong><?php echo number_format(intval($listing->living_area)); ?></strong> Sq Ft
                        </span>
                        <?php endif; ?>

                        <?php if (!empty($listing->property_type)): ?>
                        <span>
                            <strong><?php echo esc_html($listing->property_type); ?></strong>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="listing-body">
                    <?php if (!empty($listing->remarks)): ?>
                    <div class="listing-description">
                        <?php echo esc_html(wp_trim_words($listing->remarks, 30, '...')); ?>
                    </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url(get_listing_url($listing)); ?>" class="view-listing-btn">
                        View Full Details
                    </a>

                    <div class="mls-number">
                        MLS #<?php echo esc_html($listing->mls_number); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($listing_count > 3): ?>
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo esc_url(home_url('/properties/?search=' . urlencode($search_name))); ?>"
                   style="color: #2c5aa0; text-decoration: none; font-weight: 600;">
                    View All <?php echo $listing_count; ?> Matching Properties →
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
                <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>">Manage Your Saved Searches</a>
            </p>

            <div class="unsubscribe">
                <p>
                    Don't want to receive these notifications anymore?<br>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>">Unsubscribe from this search</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>