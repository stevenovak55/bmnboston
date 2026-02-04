<?php
// Enable error reporting temporarily for debugging
if (current_user_can('manage_options')) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

/**
 * Mobile Property Details Template V3
 * Combines V2 mobile bottom sheet UI with complete V3 desktop content
 *
 * @version 4.3.0
 *
 * v4.2.0 Changes:
 * - Added YouTube video preview as second item in gallery scroll
 * - Implemented fullscreen video modal with close button
 * - Updated photo counter to include video in total count
 * - Fixed gallery scroll padding for better first image visibility
 */

// Include icon functions (may be needed for display)
if (!function_exists('mld_get_icon_for_field')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/facts-features-icons.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/facts-features-icons.php';
    }
}
if (!function_exists('mld_get_feature_icon')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/facts-features-icons.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/facts-features-icons.php';
    }
}

// Get MLS number
$mls_number = get_query_var('mls_number');
if (!$mls_number) {
    wp_die('Property not found', 404);
}

// Extract the listing ID if it's a descriptive URL
if (!is_numeric($mls_number)) {
    // Load URL helper if needed
    if (!class_exists('MLD_URL_Helper')) {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-url-helper.php';
    }
    $extracted_id = MLD_URL_Helper::extract_listing_id_from_slug($mls_number);
    if ($extracted_id) {
        $mls_number = $extracted_id;
    }
}

// Ensure required classes are loaded
if (!class_exists('MLD_Query')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-query.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-query.php';
    } else {
        wp_die('MLD_Query class not found', 500);
    }
}

if (!class_exists('MLD_Utils')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-utils.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-utils.php';
    }
}

if (!class_exists('MLD_Settings')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-settings.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-settings.php';
    }
}

// Load Agent Client Manager for assigned agent lookup
if (!class_exists('MLD_Agent_Client_Manager')) {
    if (file_exists(MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-agent-client-manager.php')) {
        require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-agent-client-manager.php';
    }
}

// Get contact agent info (assigned agent or site default)
$contact_agent_name = '';
$contact_agent_email = '';
$contact_agent_phone = '';
$contact_agent_photo = '';
$contact_agent_brokerage = '';
$contact_agent_label = ''; // "Your Agent" or brokerage name

if (is_user_logged_in() && class_exists('MLD_Agent_Client_Manager')) {
    $current_user_id = get_current_user_id();
    $assigned_agent = MLD_Agent_Client_Manager::get_client_agent($current_user_id);

    if ($assigned_agent) {
        // User has an assigned agent - use their info
        $agent_api_data = MLD_Agent_Client_Manager::get_agent_for_api($assigned_agent['user_id']);
        if ($agent_api_data) {
            $contact_agent_name = $agent_api_data['name'] ?? '';
            $contact_agent_email = $agent_api_data['email'] ?? '';
            $contact_agent_phone = $agent_api_data['phone'] ?? '';
            $contact_agent_photo = $agent_api_data['photo_url'] ?? '';
            $contact_agent_brokerage = $agent_api_data['office_name'] ?? '';
            $contact_agent_label = 'Your Agent';
        }
    }
}

// Fall back to site contact settings if no assigned agent
if (empty($contact_agent_email)) {
    $theme_mods = get_theme_mods();
    $contact_agent_name = !empty($theme_mods['bne_agent_name']) ? $theme_mods['bne_agent_name'] : get_bloginfo('name');
    $contact_agent_email = !empty($theme_mods['bne_agent_email']) ? $theme_mods['bne_agent_email'] : get_option('admin_email');
    $contact_agent_phone = !empty($theme_mods['bne_phone_number']) ? $theme_mods['bne_phone_number'] : '';
    $contact_agent_photo = !empty($theme_mods['bne_agent_photo']) ? $theme_mods['bne_agent_photo'] : '';
    $contact_agent_brokerage = !empty($theme_mods['bne_group_name']) ? $theme_mods['bne_group_name'] : '';
    $contact_agent_label = $contact_agent_brokerage ?: '';
}

// Get comprehensive listing data
$listing = MLD_Query::get_listing_details($mls_number);
if (!$listing) {
    wp_die('Property not found', 404);
}

// Track this property view (v6.57.0)
do_action('mld_property_viewed', $mls_number, $listing);

// Prepare all data (from V3 desktop)
$media = $listing['Media'] ?? [];
$photos = array_filter($media, function($m) {
    return empty($m['MediaCategory']) ||
           stripos($m['MediaCategory'], 'photo') !== false ||
           stripos($m['MediaCategory'], 'image') !== false;
});
$videos = array_filter($media, function($m) {
    return !empty($m['MediaCategory']) && stripos($m['MediaCategory'], 'video') !== false;
});

// Virtual tours - process with new detection system
$virtual_tours = [];
if (class_exists('MLD_Virtual_Tour_Utils')) {
    $virtual_tours = MLD_Virtual_Tour_Utils::process_listing_tours($listing);
    $virtual_tours = MLD_Virtual_Tour_Utils::sort_tours($virtual_tours);
}

// Helper function to format array fields
function format_array_field($value) {
    if (empty($value)) {
        return null;
    }
    
    // If it's already an array, use it
    if (is_array($value)) {
        return empty($value) ? null : implode(', ', array_filter($value));
    }
    
    // If it's a JSON string, decode it
    if (is_string($value) && (substr($value, 0, 1) === '[' || substr($value, 0, 1) === '{')) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return empty($decoded) ? null : implode(', ', array_filter($decoded));
        }
    }
    
    // Otherwise return as is
    return $value;
}

// Basic info
// Use close_price for Closed status, list_price for others
$status = $listing['standard_status'] ?? '';
$display_price = (strtolower($status) === 'closed' && !empty($listing['close_price'])) ? $listing['close_price'] : $listing['list_price'];
$price = '$' . number_format($display_price ?? 0);
$address = $listing['unparsed_address'] ?? '';
$beds = $listing['bedrooms_total'] ?? 0;
$baths_full = $listing['bathrooms_full'] ?? 0;
$baths_half = $listing['bathrooms_half'] ?? 0;
$baths = $baths_full + ($baths_half * 0.5);
$sqft = $listing['living_area'] ? number_format($listing['living_area']) : null;
$lot_size = $listing['lot_size_acres'] ?? $listing['lot_size_square_feet'] ?? null;
$year_built = $listing['year_built'] ?? null;

// Property type
$property_type = $listing['property_type'] ?? '';
$property_sub_type = $listing['property_sub_type'] ?? '';

// City, State, ZIP parsing
$city = $listing['city'] ?? '';
$state = $listing['state_or_province'] ?? 'MA';
$postal_code = $listing['postal_code'] ?? '';

// Neighborhood
$neighborhoods = array_filter([
    $listing['mls_area_major'] ?? null,
    $listing['mls_area_minor'] ?? null,
    $listing['subdivision_name'] ?? null
]);
$neighborhood = implode(' - ', array_unique($neighborhoods));

// Status and market time
$days_on_market = null;
if (class_exists('MLD_Utils') && method_exists('MLD_Utils', 'calculate_days_on_market')) {
    $days_on_market = MLD_Utils::calculate_days_on_market($listing);
} else {
    // Fallback calculation
    if (!empty($listing['original_entry_timestamp'])) {
        $days_on_market = floor((time() - strtotime($listing['original_entry_timestamp'])) / 86400);
    }
}

// Agent/Office info
$agent_name = $listing['ListAgentFullName'] ?? '';
$agent_phone = $listing['ListAgentDirectPhone'] ?? $listing['ListAgentPhone'] ?? '';
$agent_email = $listing['ListAgentEmail'] ?? '';
$office_name = $listing['ListOfficeName'] ?? '';

// Financial
$tax_amount = $listing['tax_annual_amount'] ?? null;
$tax_year = $listing['tax_year'] ?? null;
$hoa_fee = $listing['association_fee'] ?? null;
$hoa_frequency = $listing['association_fee_frequency'] ?? '';

// Rooms
$rooms = $listing['RoomData'] ?? [];

// Schools
$schools = [
    'elementary' => $listing['elementary_school'] ?? null,
    'middle' => $listing['middle_or_junior_school'] ?? null,
    'high' => $listing['high_school'] ?? null,
];

// Map coordinates
$lat = $listing['latitude'] ?? $listing['Latitude'] ?? null;
$lng = $listing['longitude'] ?? $listing['Longitude'] ?? null;

// Calculate estimated monthly payment
$monthly_payment = 0;
if ($display_price) {
    $loan_amount = $display_price * 0.8; // 20% down
    $monthly_rate = 0.065 / 12; // 6.5% annual rate
    $num_payments = 30 * 12; // 30-year loan
    $principal_interest = ($loan_amount * $monthly_rate * pow(1 + $monthly_rate, $num_payments)) / (pow(1 + $monthly_rate, $num_payments) - 1);

    // Add property tax and insurance
    $property_tax_monthly = ($tax_amount ?? 0) / 12;
    $insurance_monthly = 200; // Default insurance estimate

    // Add HOA fee (convert to monthly if needed)
    $hoa_monthly = 0;
    if ($hoa_fee) {
        $hoa_fee_num = (float)$hoa_fee;
        if (stripos($hoa_frequency, 'year') !== false || stripos($hoa_frequency, 'annual') !== false) {
            $hoa_monthly = $hoa_fee_num / 12;
        } elseif (stripos($hoa_frequency, 'quarter') !== false) {
            $hoa_monthly = $hoa_fee_num / 3;
        } elseif (stripos($hoa_frequency, 'month') !== false) {
            $hoa_monthly = $hoa_fee_num;
        } else {
            // Default to monthly if frequency is unclear
            $hoa_monthly = $hoa_fee_num;
        }
    }

    $monthly_payment = round($principal_interest + $property_tax_monthly + $insurance_monthly + $hoa_monthly);
}

// Get brokerage/display settings
$mld_settings = get_option('mld_settings', []);
$logo_url = $mld_settings['mld_logo_url'] ?? '';

// Get admin contact settings
$contact_settings = get_option('mld_contact_settings', []);

// Check if user is admin for showing contact info
$is_admin = current_user_can('manage_options');

// Calculate price change if applicable
$price_drop = false;
$price_drop_amount = 0;
if (strtolower($status) !== 'closed' && !empty($listing['original_list_price']) && $listing['original_list_price'] > $listing['list_price']) {
    $price_drop = true;
    $price_drop_amount = $listing['original_list_price'] - $listing['list_price'];
}

// Check if new listing
$is_new_listing = false;
if (!empty($listing['creation_timestamp'])) {
    $days_since_listed = (time() - strtotime($listing['creation_timestamp'])) / 86400;
    $is_new_listing = $days_since_listed <= 7;
}

// Check for active open houses
$has_open_house = !empty($listing['OpenHouseData']);

// Calculate sold property statistics
$sold_stats = array();
if (strtolower($status) === 'closed' && !empty($listing['close_price'])) {
    $original_price = $listing['original_list_price'] ?? $listing['list_price'];
    if ($original_price) {
        $sold_stats['original_price'] = $original_price;
        $sold_stats['sale_price'] = $listing['close_price'];
        $sold_stats['price_difference'] = $listing['close_price'] - $original_price;
        $sold_stats['price_percentage'] = (($listing['close_price'] - $original_price) / $original_price) * 100;
        
        $calculated_dom = null;
        if (class_exists('MLD_Utils') && method_exists('MLD_Utils', 'calculate_days_on_market')) {
            $calculated_dom = MLD_Utils::calculate_days_on_market($listing);
        } else if (!empty($listing['original_entry_timestamp'])) {
            $calculated_dom = floor((time() - strtotime($listing['original_entry_timestamp'])) / 86400);
        }
        if ($calculated_dom !== null && !is_string($calculated_dom)) {
            $sold_stats['days_on_market'] = $calculated_dom;
        }
        
        if ($sqft && is_numeric(str_replace(',', '', $sqft))) {
            $sqft_numeric = (int)str_replace(',', '', $sqft);
            $sold_stats['price_per_sqft'] = round($listing['close_price'] / $sqft_numeric);
            $sold_stats['original_price_per_sqft'] = round($original_price / $sqft_numeric);
        }
        
        $sold_stats['list_to_sale_ratio'] = round(($listing['close_price'] / $original_price) * 100, 1);
    }
}

// SEO handled by MLD_SEO class
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <!-- Permissions Policy for Street View (v6.0.1) -->
    <meta http-equiv="Permissions-Policy" content="accelerometer=*, gyroscope=*, magnetometer=*, camera=*">
    <?php wp_head(); ?>
    <!-- v6.13.14: Inline styles moved to property-mobile-v3.css for cleaner architecture -->
</head>
<body <?php body_class('mld-property-mobile-v3'); ?>>

<!-- Navigation Menu Button (Fixed - v6.25.4) -->
<button class="mld-nav-toggle-property" id="mld-nav-toggle" aria-controls="mld-nav-drawer" aria-expanded="false" aria-label="Open navigation menu">
    <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22" aria-hidden="true">
        <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
    </svg>
</button>

<div class="mld-mobile-container">
    <!-- Photo Gallery with Map/Street View (V3 Enhanced) -->
    <div class="mld-gallery-container" id="gallery">
        <div class="mld-gallery-scroll">
            <!-- Photos -->
            <?php if (!empty($photos)): ?>
                <?php

                // Check if we have a YouTube video to insert
                $youtube_video = null;
                foreach ($virtual_tours as $tour) {
                    if ($tour['type'] === 'youtube') {
                        $youtube_video = $tour;
                        break;
                    }
                }
                ?>
                <?php foreach ($photos as $index => $photo):
                    // Generate optimized mobile image
                    $mobile_image_options = [
                        'width' => 800,
                        'height' => 600,
                        'loading' => $index < 3 ? 'eager' : 'lazy',
                        'class' => 'mld-photo',
                        'quality' => 85,
                        'responsive' => true,
                        'webp' => true
                    ];
                ?>
                    <div class="mld-photo-item<?php echo $index >= 3 ? ' mld-progressive-image' : ''; ?>" data-index="<?php echo $index; ?>">
                        <?php if ($index >= 3): ?>
                            <!-- Lazy loaded images with placeholder -->
                            <div class="mld-image-placeholder mld-placeholder-pattern">
                                <img data-src="<?php echo esc_url($photo['MediaURL']); ?>"
                                     src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='600' viewBox='0 0 800 600'%3E%3Crect width='100%25' height='100%25' fill='%23f5f5f5'/%3E%3C/svg%3E"
                                     alt="<?php echo esc_attr($address . ' - Photo ' . ($index + 1)); ?>"
                                     loading="lazy"
                                     class="mld-photo lazy-image"
                                     onerror="this.parentElement.classList.add('image-error'); this.style.display='none';">
                            </div>
                        <?php else: ?>
                            <!-- Eager loaded images for above-fold content -->
                            <img src="<?php echo esc_url($photo['MediaURL']); ?>"
                                 alt="<?php echo esc_attr($address . ' - Photo ' . ($index + 1)); ?>"
                                 loading="eager"
                                 class="mld-photo"
                                 onerror="this.parentElement.classList.add('image-error'); this.style.display='none';">
                        <?php endif; ?>
                        <!-- Loading spinner for lazy images -->
                        <?php if ($index >= 3): ?>
                            <div class="mld-image-spinner">
                                <div class="mld-spinner-circle"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php 
                    // Insert YouTube video preview after first image
                    if ($index === 0 && $youtube_video): 
                        $video_id = $youtube_video['video_id'] ?? '';
                        if ($video_id):
                            // Get YouTube thumbnail
                            $thumbnail_url = "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg";
                            // Fallback to high quality if maxres doesn't exist
                            $fallback_thumbnail = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
                    ?>
                        <div class="mld-photo-item mld-youtube-preview" data-video-id="<?php echo esc_attr($video_id); ?>" data-embed-url="<?php echo esc_url($youtube_video['embed_url']); ?>">
                            <div class="mld-video-thumbnail-wrapper">
                                <img src="<?php echo esc_url($thumbnail_url); ?>" 
                                     onerror="this.src='<?php echo esc_url($fallback_thumbnail); ?>'"
                                     alt="Video Tour"
                                     loading="eager"
                                     class="mld-video-thumbnail">
                                <div class="mld-video-play-overlay">
                                    <svg class="mld-play-button" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="12" cy="12" r="12" fill="rgba(0,0,0,0.7)"/>
                                        <polygon points="9.5 7.5 16.5 12 9.5 16.5" fill="white"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    <?php 
                        endif;
                    endif; 
                    ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="mld-photo-item">
                    <div class="mld-no-photo">
                        <svg class="mld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5"/>
                            <polyline points="21 15 16 10 5 21"/>
                        </svg>
                        <p>No photos available</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Videos -->
            <?php if (!empty($videos)): ?>
                <?php foreach ($videos as $video): ?>
                    <div class="mld-photo-item mld-video-item">
                        <video controls playsinline poster="<?php echo esc_url($photos[0]['MediaURL'] ?? ''); ?>">
                            <source src="<?php echo esc_url($video['MediaURL']); ?>" type="video/mp4">
                        </video>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- v6.25.23: Gallery Controls moved inside bottom sheet -->
        
        <!-- Photo Counter -->
        <?php if (count($photos) > 1): ?>
            <?php 
            // Check if we have a YouTube video to account for in the count
            $has_youtube = false;
            foreach ($virtual_tours as $tour) {
                if ($tour['type'] === 'youtube') {
                    $has_youtube = true;
                    break;
                }
            }
            $total_items = $has_youtube ? count($photos) + 1 : count($photos);
            ?>
            <div class="mld-photo-counter" data-has-video="<?php echo $has_youtube ? 'true' : 'false'; ?>">
                <span id="current-photo">1</span> of <?php echo $total_items; ?>
            </div>
        <?php endif; ?>
        
        <!-- Status Badge (Right Side) -->
        <?php if ($status): ?>
            <div class="mld-status-badge <?php echo sanitize_html_class(strtolower($status)); ?>">
                <?php echo esc_html($status); ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bottom Sheet with V3 Content -->
    <div class="mld-bottom-sheet" id="bottomSheet">
        <!-- Drag Handle -->
        <div class="mld-sheet-handle" id="sheetHandle">
            <div class="mld-handle-bar"></div>
        </div>
        
        <!-- Sheet Content -->
        <div class="mld-sheet-content" id="sheetContent">
            <?php
            // Display breadcrumbs
            if (function_exists('mld_output_visual_breadcrumbs')) {
                mld_output_visual_breadcrumbs($listing);
            }
            ?>

            <!-- Main Content Container (Unified Scroll) -->
            <div class="mld-v3-main-mobile">
                <!-- Overview Section -->
                <section id="overview" class="mld-v3-section-mobile">
                    <!-- Header Section -->
                    <div class="mld-sheet-header">
                        <div class="mld-price-status">
                            <h1 class="mld-price"><?php echo esc_html($price); ?></h1>
                            <?php if ($monthly_payment > 0): ?>
                                <div class="mld-v3-monthly">
                                    Est. <?php echo '$' . number_format($monthly_payment); ?>/month
                                </div>
                            <?php endif; ?>
                            <?php if ($days_on_market !== null): ?>
                                <span class="mld-dom"><?php echo is_numeric($days_on_market) ? $days_on_market . ' days' : esc_html($days_on_market); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mld-address-stats">
                            <h2 class="mld-address"><?php echo esc_html($address); ?></h2>
                            <div class="mld-location-info">
                                <?php echo esc_html($city); ?>, <?php echo esc_html($state); ?> <?php echo esc_html($postal_code); ?>
                                <?php if ($neighborhood): ?>
                                    <span class="mld-neighborhood">• <?php echo esc_html($neighborhood); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Status and Listing Info -->
                            <div class="mld-status-listing-info">
                                <span class="mld-status-badge-inline <?php echo sanitize_html_class(strtolower(str_replace(' ', '-', $status))); ?>">
                                    <?php echo esc_html($status); ?>
                                </span>
                                <span class="mld-listing-details">
                                    Listed <?php echo date('M j, Y', strtotime($listing['original_entry_timestamp'] ?? $listing['listing_contract_date'] ?? 'now')); ?>
                                    • <span class="mld-v3-mls-number-mobile" data-mls="<?php echo esc_attr($mls_number); ?>" title="Tap to copy MLS#">
                                        MLS# <?php echo esc_html($mls_number); ?>
                                        <svg class="mld-v3-copy-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                        </svg>
                                        <span class="mld-v3-copy-feedback">Copied!</span>
                                    </span>
                                </span>
                            </div>

                            <div class="mld-key-stats">
                                <?php if ($beds): ?>
                                    <span class="mld-stat">
                                        <strong><?php echo $beds; ?></strong> bed<?php echo $beds != 1 ? 's' : ''; ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($baths): ?>
                                    <span class="mld-stat">
                                        <strong><?php echo $baths; ?></strong> bath<?php echo $baths != 1 ? 's' : ''; ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($sqft): ?>
                                    <span class="mld-stat">
                                        <strong><?php echo $sqft; ?></strong> sqft
                                    </span>
                                <?php endif; ?>

                                <?php if ($sqft && $display_price): ?>
                                    <span class="mld-stat">
                                        <strong>$<?php echo number_format($display_price / str_replace(',', '', $sqft), 0); ?></strong>/sqft
                                    </span>
                                <?php endif; ?>

                                <?php if ($lot_size): ?>
                                    <span class="mld-stat">
                                        <?php if ($listing['lot_size_acres']): ?>
                                            <strong><?php echo number_format($listing['lot_size_acres'], 2); ?></strong> acres
                                        <?php else: ?>
                                            <strong><?php echo number_format($listing['lot_size_square_feet']); ?></strong> sqft lot
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Property Highlights Chips (v6.57.0 - iOS alignment)
                            $highlights = [];

                            // Check for highlights from listing data
                            $has_pool = !empty($listing['pool_private_yn']) && ($listing['pool_private_yn'] === 'Y' || $listing['pool_private_yn'] === '1');
                            $has_waterfront = !empty($listing['waterfront_yn']) && ($listing['waterfront_yn'] === 'Y' || $listing['waterfront_yn'] === '1');
                            $has_view = !empty($listing['view_yn']) && ($listing['view_yn'] === 'Y' || $listing['view_yn'] === '1');
                            $has_garage = !empty($listing['garage_spaces']) && (int)$listing['garage_spaces'] > 0;
                            $has_fireplace = !empty($listing['fireplace_yn']) && ($listing['fireplace_yn'] === 'Y' || $listing['fireplace_yn'] === '1');

                            if ($has_pool) {
                                $highlights[] = ['icon' => 'pool', 'label' => 'Pool', 'color' => '#06b6d4'];
                            }
                            if ($has_waterfront) {
                                $highlights[] = ['icon' => 'waterfront', 'label' => 'Waterfront', 'color' => '#3b82f6'];
                            }
                            if ($has_view) {
                                $highlights[] = ['icon' => 'view', 'label' => 'View', 'color' => '#22c55e'];
                            }
                            if ($has_garage) {
                                $garage_count = (int)$listing['garage_spaces'];
                                $highlights[] = ['icon' => 'garage', 'label' => $garage_count . '-Car Garage', 'color' => '#6366f1'];
                            }
                            if ($has_fireplace) {
                                $highlights[] = ['icon' => 'fireplace', 'label' => 'Fireplace', 'color' => '#f97316'];
                            }

                            if (!empty($highlights)):
                            ?>
                            <div class="mld-detail-highlights">
                                <?php foreach ($highlights as $h): ?>
                                <span class="mld-detail-highlight-chip" style="--highlight-color: <?php echo esc_attr($h['color']); ?>;">
                                    <span class="mld-highlight-icon mld-icon-<?php echo esc_attr($h['icon']); ?>"></span>
                                    <?php echo esc_html($h['label']); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Status Tags -->
                        <div class="mld-v3-tags">
                            <?php if ($price_drop): ?>
                                <span class="mld-v3-tag price-drop">
                                    Price Drop -$<?php echo number_format($price_drop_amount); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($is_new_listing): ?>
                                <span class="mld-v3-tag new-listing">
                                    New Listing
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($has_open_house): ?>
                                <span class="mld-v3-tag open-house">
                                    Open House
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- v6.25.23: Gallery Controls moved here from above sheet -->
                    <!-- v6.25.25: Removed default active - Photos opens lightbox -->
                    <div class="mld-gallery-controls-inline" id="galleryControls">
                        <button class="mld-gallery-control" data-view="photos">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <polyline points="21 15 16 10 5 21"/>
                            </svg>
                            <span>Photos</span>
                        </button>
                        <?php if ($lat && $lng): ?>
                        <button class="mld-gallery-control" data-view="map">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            <span>Map</span>
                        </button>
                        <button class="mld-gallery-control" data-view="street">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                            <span>Street</span>
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($virtual_tours) && MLD_Virtual_Tour_Utils::validate_tour_url($virtual_tours[0]['url'])): ?>
                        <button class="mld-gallery-control" data-view="tour"
                                data-tour-type="<?php echo esc_attr($virtual_tours[0]['type']); ?>"
                                data-embed-url="<?php echo esc_url($virtual_tours[0]['embed_url']); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polygon points="10 8 16 12 10 16 10 8"/>
                            </svg>
                            <span>3D Tour</span>
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Save & Share Actions (v5.6.0) -->
                    <div class="mld-v3-quick-actions-mobile">
                        <button class="mld-v3-action-btn-mobile mld-v3-save-btn-mobile" data-mls="<?php echo esc_attr($mls_number); ?>" aria-label="Save property">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                            <span>Save</span>
                        </button>
                        <button class="mld-v3-action-btn-mobile mld-v3-share-btn-mobile" aria-label="Share property">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                                <path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/>
                            </svg>
                            <span>Share</span>
                        </button>
                    </div>

                    <!-- Primary CTAs -->
                    <div class="mld-primary-actions">
                        <button class="mld-cta-primary mld-schedule-tour">
                            <svg class="mld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                                <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
                            </svg>
                            Schedule Tour
                        </button>
                        
                        <button class="mld-cta-secondary mld-contact-agent">
                            <svg class="mld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                            </svg>
                            Contact Agent
                        </button>
                    </div>

                    <!-- Admin Only Section (Complete from V3 Desktop) -->
                    <?php if ($is_admin): ?>
                    <div class="mld-v3-facts-group mld-v3-admin-only mld-v3-admin-section" id="adminSection">
                        <div class="mld-v3-admin-header">
                            <h3>Admin Information (Private)</h3>
                            <button class="mld-v3-admin-toggle" id="adminToggleBtn" aria-label="Toggle admin section">
                                <svg class="mld-v3-admin-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                        </div>
                        <div class="mld-v3-admin-notice">This section is only visible to administrators and is not shown to public visitors.</div>
                        <div class="mld-v3-admin-content">
                            <div class="mld-v3-facts-card-grid-mobile">
                                <?php 
                                $admin_fields = [
                                    // Private Remarks
                                    'private_remarks' => ['label' => 'Private Remarks', 'type' => 'text'],
                                    'private_office_remarks' => ['label' => 'Private Office Remarks', 'type' => 'text'],
                                    'directions' => ['label' => 'Directions', 'type' => 'text'],
                                    
                                    // Showing Information
                                    'showing_instructions' => ['label' => 'Showing Instructions', 'type' => 'text'],
                                    'showing_requirements' => ['label' => 'Showing Requirements'],
                                    'appointment_required_yn' => ['label' => 'Appointment Required', 'format' => 'yn'],
                                    'lock_box_location' => ['label' => 'Lock Box Location'],
                                    'lock_box_type' => ['label' => 'Lock Box Type'],
                                    'key_number' => ['label' => 'Key Number'],
                                    'access_code' => ['label' => 'Access Code'],
                                    
                                    // Listing Agent Full Details
                                    'list_agent_mls_id' => ['label' => 'Listing Agent MLS ID'],
                                    'list_agent_full_name' => ['label' => 'Listing Agent Name'],
                                    'list_agent_direct_phone' => ['label' => 'Agent Direct Phone'],
                                    'list_agent_cell_phone' => ['label' => 'Agent Cell Phone'],
                                    'list_agent_email' => ['label' => 'Agent Email'],
                                    'list_agent_license_number' => ['label' => 'Agent License #'],
                                    
                                    // Listing Office Details
                                    'list_office_mls_id' => ['label' => 'Listing Office MLS ID'],
                                    'list_office_name' => ['label' => 'Listing Office Name'],
                                    'list_office_phone' => ['label' => 'Office Phone'],
                                    'list_office_email' => ['label' => 'Office Email'],
                                    
                                    // Co-List Agent Details
                                    'co_list_agent_mls_id' => ['label' => 'Co-List Agent MLS ID'],
                                    'co_list_agent_full_name' => ['label' => 'Co-List Agent Name'],
                                    'co_list_agent_direct_phone' => ['label' => 'Co-List Agent Phone'],
                                    
                                    // Compensation Details
                                    'compensation_based_on' => ['label' => 'Compensation Based On'],
                                    'buyer_agency_compensation' => ['label' => 'Buyer Agency Compensation'],
                                    'sub_agency_compensation' => ['label' => 'Sub-Agency Compensation'],
                                    'dual_variable_compensation_yn' => ['label' => 'Dual Variable Compensation', 'format' => 'yn'],
                                    'commission_remarks' => ['label' => 'Commission Remarks', 'type' => 'text'],
                                    
                                    // MLS & System Information
                                    'system_id' => ['label' => 'System ID'],
                                    'listing_key_numeric' => ['label' => 'Listing Key Numeric'],
                                    'originating_system_id' => ['label' => 'Originating System ID'],
                                    'originating_system_name' => ['label' => 'Originating System Name'],
                                    
                                    // Important Dates
                                    'original_entry_timestamp' => ['label' => 'Original Entry Date', 'format' => 'date'],
                                    'creation_timestamp' => ['label' => 'Creation Date', 'format' => 'date'],
                                    'modification_timestamp' => ['label' => 'Last Modified', 'format' => 'date'],
                                    'off_market_date' => ['label' => 'Off Market Date', 'format' => 'date'],
                                    'cancellation_date' => ['label' => 'Cancellation Date', 'format' => 'date'],
                                    'contract_status_change_date' => ['label' => 'Contract Status Change', 'format' => 'date'],
                                    'close_date' => ['label' => 'Close Date', 'format' => 'date'],
                                    
                                    // Price History
                                    'original_list_price' => ['label' => 'Original List Price', 'prefix' => '$'],
                                    'previous_list_price' => ['label' => 'Previous List Price', 'prefix' => '$'],
                                    'close_price' => ['label' => 'Close Price', 'prefix' => '$'],
                                    
                                    // Marketing & Days on Market
                                    'cumulative_days_on_market' => ['label' => 'Cumulative Days on Market'],
                                    'days_on_market' => ['label' => 'Days on Market'],
                                    'days_on_market_original' => ['label' => 'Original Days on Market'],
                                    'syndication_remarks' => ['label' => 'Syndication Remarks', 'type' => 'text'],
                                    'internet_entire_listing_display_yn' => ['label' => 'Internet Display', 'format' => 'yn'],
                                    'internet_address_display_yn' => ['label' => 'Internet Address Display', 'format' => 'yn'],
                                    'virtual_tour_url_branded' => ['label' => 'Branded Virtual Tour URL'],
                                    'virtual_tour_url_unbranded' => ['label' => 'Unbranded Virtual Tour URL'],
                                ];
                                
                                foreach ($admin_fields as $field => $config):
                                    $value = $listing[$field] ?? null;
                                    $formatted_value = format_array_field($value);
                                    
                                    // Handle Y/N fields
                                    if (isset($config['format']) && $config['format'] === 'yn') {
                                        if ($value === 'Y' || $value === '1') {
                                            $formatted_value = 'Yes';
                                        } elseif ($value === 'N' || $value === '0') {
                                            $formatted_value = 'No';
                                        }
                                    }
                                    
                                    // Handle date fields
                                    if (isset($config['format']) && $config['format'] === 'date' && !empty($value)) {
                                        $formatted_value = date('M j, Y g:i A', strtotime($value));
                                    }
                                    
                                    if (!empty($formatted_value)):
                                ?>
                                <div class="mld-v3-facts-card-mobile <?php echo isset($config['type']) && $config['type'] === 'text' ? 'mld-v3-fact-item-full' : ''; ?>">
                                    <div class="mld-v3-facts-card-icon-mobile">
                                        <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                    </div>
                                    <div class="mld-v3-facts-card-content-mobile">
                                        <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                        <div class="mld-v3-fact-value">
                                        <?php 
                                        if (isset($config['prefix']) && is_numeric($value)) {
                                            echo $config['prefix'] . number_format($value);
                                        } elseif (isset($config['type']) && $config['type'] === 'text') {
                                            // Special handling for showing instructions field
                                            if ($field === 'showing_instructions' && stripos($formatted_value, 'Schedule with ShowingTime') !== false) {
                                                // Get the current user's agent ID from BuddyPress xprofile data
                                                global $wpdb;
                                                $current_user_id = get_current_user_id();
                                                $agent_id = 'CT004645'; // Default fallback
                                                
                                                if ($current_user_id) {
                                                    $agent_id_result = $wpdb->get_var($wpdb->prepare(
                                                        "SELECT value FROM {$wpdb->prefix}bp_xprofile_data 
                                                         WHERE user_id = %d AND field_id = 5",
                                                        $current_user_id
                                                    ));
                                                    
                                                    if ($agent_id_result) {
                                                        $agent_id = $agent_id_result;
                                                    }
                                                }
                                                
                                                // Replace "Schedule with ShowingTime" with a link
                                                $showingtime_url = 'http://schedulingsso.showingtime.com/icons?siteid=PROP.MLSPIN.I&MLSID=MLSPIN&raid=' . urlencode($agent_id) . '&listingid=' . urlencode($mls_number);
                                                $formatted_value_with_link = preg_replace(
                                                    '/Schedule with ShowingTime/i',
                                                    '<a href="' . esc_url($showingtime_url) . '" target="_blank" style="color: #0066cc; text-decoration: underline;">Schedule with ShowingTime</a>',
                                                    $formatted_value
                                                );
                                                echo '<div class="mld-v3-text-block">' . nl2br($formatted_value_with_link) . '</div>';
                                            } else {
                                                echo '<div class="mld-v3-text-block">' . nl2br(esc_html($formatted_value)) . '</div>';
                                            }
                                        } else {
                                            echo esc_html($formatted_value);
                                        }
                                        ?>
                                        </div>
                                    </div>
                                </div>
                                <?php 
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- About This Home -->
                    <?php if (!empty($listing['public_remarks'])): ?>
                    <div class="mld-v3-description">
                        <h3>About This Home</h3>
                        <p><?php echo nl2br(esc_html($listing['public_remarks'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Property-specific iOS app download prompt
                    do_action('mld_after_property_description', $mls_number, $listing);
                    ?>

                    <!-- Property Overview (Moved from Facts) -->
                    <div class="mld-v3-property-overview-mobile">
                        <h3>Property Overview</h3>
                        <div class="mld-v3-overview-grid-mobile">
                            <?php
                            $overview_fields = [
                                'property_type' => ['label' => 'Listing Type', 'translate' => true],
                                'property_sub_type' => ['label' => 'Property Type'],
                                'year_built' => ['label' => 'Year Built'],
                                'bedrooms_total' => ['label' => 'Beds'],
                                'bathrooms_full' => ['label' => 'Full Baths'],
                                'bathrooms_half' => ['label' => 'Half Baths'],
                                'living_area' => ['label' => 'Sq Ft', 'suffix' => ''],
                                'above_grade_finished_area' => ['label' => 'Above Grade Sq Ft', 'suffix' => ''],
                                'below_grade_finished_area' => ['label' => 'Below Grade Sq Ft', 'suffix' => ''],
                                'total_area' => ['label' => 'Total Area', 'suffix' => ''],
                                'rooms_total' => ['label' => 'Rooms'],
                                'stories_total' => ['label' => 'Stories'],
                                'levels' => ['label' => 'Levels'],
                                'main_level_bedrooms' => ['label' => 'Main Beds'],
                                'beds_possible' => ['label' => 'Beds Possible'],
                            ];

                            foreach ($overview_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-overview-item-mobile">
                                <div class="mld-v3-overview-value-mobile">
                                    <?php
                                    if (in_array($field, ['living_area', 'above_grade_finished_area', 'below_grade_finished_area', 'total_area']) && $value) {
                                        echo number_format($value);
                                    } elseif ($field === 'property_type' && isset($config['translate']) && $config['translate']) {
                                        // Translate property type values
                                        $translated_value = $formatted_value;
                                        if ($formatted_value === 'Residential') {
                                            $translated_value = 'For Sale';
                                        } elseif ($formatted_value === 'Residential Lease') {
                                            $translated_value = 'For Rent';
                                        } elseif ($formatted_value === 'Residential Income') {
                                            $translated_value = 'For Sale';
                                        }
                                        echo esc_html($translated_value);
                                    } elseif ($field === 'property_sub_type') {
                                        // Generate property type URL link
                                        $listing_type = (strpos(strtolower($listing['property_type'] ?? ''), 'lease') !== false || strpos(strtolower($listing['property_type'] ?? ''), 'rental') !== false) ? 'rent' : 'sale';
                                        $type_url = MLD_Property_Type_Pages::get_property_type_url($listing['property_type'], $formatted_value, $listing_type);

                                        if ($type_url):
                                        ?>
                                            <a href="<?php echo esc_url($type_url); ?>" class="mld-property-type-link">
                                                <?php echo esc_html($formatted_value); ?>
                                            </a>
                                        <?php else:
                                            echo esc_html($formatted_value);
                                        endif;
                                    } else {
                                        echo esc_html($formatted_value);
                                    }
                                    ?>
                                </div>
                                <div class="mld-v3-overview-label-mobile"><?php echo esc_html($config['label']); ?></div>
                            </div>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Open Houses -->
                    <?php 
                    $open_houses = $listing['OpenHouseData'] ?? [];

                    // Handle case where OpenHouseData might be a JSON string
                    if (is_string($open_houses)) {
                        $open_houses = json_decode($open_houses, true) ?? [];
                    }
                    
                    if (!empty($open_houses)): 
                    ?>
                    <div class="mld-v3-open-houses">
                        <h3>Open House Schedule</h3>
                        <div class="mld-v3-open-house-list">
                            <?php foreach ($open_houses as $oh):
                                // v6.75.4: Database stores in WordPress timezone (America/New_York), not UTC
                                // No conversion needed - use wp_timezone() directly
                                $start = new DateTime($oh['OpenHouseStartTime'], wp_timezone());
                                $end = new DateTime($oh['OpenHouseEndTime'], wp_timezone());
                            ?>
                            <div class="mld-v3-open-house-item">
                                <div class="mld-v3-oh-date">
                                    <div class="mld-v3-oh-day"><?php echo $start->format('d'); ?></div>
                                    <div class="mld-v3-oh-month"><?php echo $start->format('M'); ?></div>
                                </div>
                                <div class="mld-v3-oh-details">
                                    <div class="mld-v3-oh-dayname"><?php echo $start->format('l, F j'); ?></div>
                                    <div class="mld-v3-oh-time"><?php echo $start->format('g:i A'); ?> - <?php echo $end->format('g:i A'); ?> <?php echo $start->format('T'); ?></div>
                                </div>
                                <div class="mld-v3-oh-calendar">
                                    <button class="mld-v3-add-calendar" 
                                            data-title="Open House: <?php echo esc_attr($address); ?>"
                                            data-start="<?php echo esc_attr($start->format('Y-m-d\TH:i:s')); ?>"
                                            data-end="<?php echo esc_attr($end->format('Y-m-d\TH:i:s')); ?>"
                                            data-location="<?php echo esc_attr($address); ?>"
                                            data-timezone="America/New_York">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                            <line x1="12" y1="14" x2="12" y2="18"/>
                                            <line x1="10" y1="16" x2="14" y2="16"/>
                                        </svg>
                                        Add to Calendar
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- Facts & Features Section -->
                <section id="facts" class="mld-v3-section-mobile mld-facts-v2">
                    <div class="mld-v3-section-header">
                        <h2>Facts & Features</h2>
                        <button class="mld-v3-section-toggle" aria-label="Toggle section">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>
                    <div class="mld-v3-section-content">

                    <?php
                    // Include the new template component
                    require_once MLD_PLUGIN_PATH . 'includes/template-facts-features-v2.php';

                    // Render property highlights (tags)
                    mld_render_property_highlights($listing);

                    // Render the main facts grid for mobile
                    mld_render_facts_grid($listing, true);
                    ?>

                    <!-- Old structure removed - using new Facts & Features v2 -->
                    <?php if (false): // Completely disable old structure ?>
                    <?php
                    // Include icon helper
                    require_once MLD_PLUGIN_PATH . 'includes/facts-features-icons.php';
                    ?>

                    <!-- Interior Features -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile">
                            <div class="mld-v3-facts-icon-mobile">
                                <?php echo mld_get_feature_icon('interior'); ?>
                            </div>
                            <h3>Interior Features</h3>
                        </div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $interior_fields = [
                                'interior_features' => ['label' => 'Interior Features'],
                                'flooring' => ['label' => 'Flooring'],
                                'heating' => ['label' => 'Heating'],
                                'heating_yn' => ['label' => 'Has Heating', 'format' => 'yn'],
                                'cooling' => ['label' => 'Cooling'],
                                'cooling_yn' => ['label' => 'Has Cooling', 'format' => 'yn'],
                                'fireplace_features' => ['label' => 'Fireplace Features'],
                                'fireplace_yn' => ['label' => 'Has Fireplace', 'format' => 'yn'],
                                'fireplaces_total' => ['label' => 'Total Fireplaces'],
                                'basement' => ['label' => 'Basement'],
                                'basement_yn' => ['label' => 'Has Basement', 'format' => 'yn'],
                                'appliances' => ['label' => 'Appliances'],
                                'laundry_features' => ['label' => 'Laundry Features'],
                                'number_of_units_in_floor' => ['label' => 'Units on Floor'],
                                'entry_level' => ['label' => 'Entry Level'],
                                'entry_location' => ['label' => 'Entry Location'],
                                'common_walls' => ['label' => 'Common Walls'],
                                'rooms_total' => ['label' => 'Total Rooms'],
                                'levels' => ['label' => 'Levels'],
                                'master_bedroom_level' => ['label' => 'Master Bedroom Level'],
                                'main_level_bedrooms' => ['label' => 'Main Level Bedrooms'],
                                'main_level_bathrooms' => ['label' => 'Main Level Bathrooms'],
                                'other_rooms' => ['label' => 'Other Rooms'],
                                'security_features' => ['label' => 'Security Features'],
                                'attic' => ['label' => 'Attic'],
                                'attic_yn' => ['label' => 'Has Attic', 'format' => 'yn'],
                                'window_features' => ['label' => 'Window Features'],
                                'door_features' => ['label' => 'Door Features'],
                                'insulation' => ['label' => 'Insulation'],
                                'accessibility_features' => ['label' => 'Accessibility Features'],
                            ];
                            
                            foreach ($interior_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value"><?php echo esc_html($formatted_value); ?></div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Exterior & Structure -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile">
                            <div class="mld-v3-facts-icon-mobile">
                                <?php echo mld_get_feature_icon('exterior'); ?>
                            </div>
                            <h3>Exterior & Structure</h3>
                        </div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $exterior_fields = [
                                'construction_materials' => ['label' => 'Construction Materials'],
                                'architectural_style' => ['label' => 'Architectural Style'],
                                'roof' => ['label' => 'Roof'],
                                'foundation_details' => ['label' => 'Foundation'],
                                'foundation_area' => ['label' => 'Foundation Area', 'suffix' => ' sq ft'],
                                'exterior_features' => ['label' => 'Exterior Features'],
                                'patio_and_porch_features' => ['label' => 'Patio/Porch Features'],
                                'fencing' => ['label' => 'Fencing'],
                                'fence_yn' => ['label' => 'Has Fence', 'format' => 'yn'],
                                'spa_features' => ['label' => 'Spa Features'],
                                'spa_yn' => ['label' => 'Has Spa', 'format' => 'yn'],
                                'pool_features' => ['label' => 'Pool Features'],
                                'pool_private_yn' => ['label' => 'Private Pool', 'format' => 'yn'],
                                'view' => ['label' => 'View'],
                                'view_yn' => ['label' => 'Has View', 'format' => 'yn'],
                                'waterfront_features' => ['label' => 'Waterfront Features'],
                                'waterfront_yn' => ['label' => 'Waterfront Property', 'format' => 'yn'],
                                'water_body_name' => ['label' => 'Water Body Name'],
                            ];
                            
                            foreach ($exterior_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value">
                                        <?php
                                        if ($field === 'foundation_area' && $value) {
                                            echo number_format($value) . ($config['suffix'] ?? '');
                                        } else {
                                            echo esc_html($formatted_value);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Lot & Land -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile">
                            <div class="mld-v3-facts-icon-mobile">
                                <?php echo mld_get_feature_icon('lot'); ?>
                            </div>
                            <h3>Lot & Land</h3>
                        </div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $lot_fields = [
                                'lot_size_acres' => ['label' => 'Lot Size', 'suffix' => ' acres'],
                                'lot_size_square_feet' => ['label' => 'Lot Size', 'suffix' => ' sq ft'],
                                'lot_size_area' => ['label' => 'Lot Size Area'],
                                'lot_size_dimensions' => ['label' => 'Lot Dimensions'],
                                'lot_features' => ['label' => 'Lot Features'],
                                'land_lease_yn' => ['label' => 'Land Lease', 'format' => 'yn'],
                                'land_lease_amount' => ['label' => 'Land Lease Amount', 'prefix' => '$'],
                                'land_lease_expiration_date' => ['label' => 'Land Lease Expiration'],
                                'horse_yn' => ['label' => 'Horse Property', 'format' => 'yn'],
                                'horse_amenities' => ['label' => 'Horse Amenities'],
                                'vegetation' => ['label' => 'Vegetation'],
                                'topography' => ['label' => 'Topography'],
                                'frontage_type' => ['label' => 'Frontage Type'],
                                'frontage_length' => ['label' => 'Frontage Length', 'suffix' => ' ft'],
                                'road_surface_type' => ['label' => 'Road Surface Type'],
                                'road_frontage_type' => ['label' => 'Road Frontage Type'],
                            ];
                            
                            foreach ($lot_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value">
                                        <?php
                                        if (isset($config['prefix'])) {
                                            echo $config['prefix'] . number_format($value);
                                        } elseif (in_array($field, ['lot_size_acres', 'lot_size_square_feet', 'frontage_length']) && $value) {
                                            $decimals = $field === 'lot_size_acres' ? 2 : 0;
                                            echo number_format($value, $decimals) . ($config['suffix'] ?? '');
                                        } else {
                                            echo esc_html($formatted_value);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Parking & Garage -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('parking'); ?></div><h3>Parking & Garage</h3></div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $parking_fields = [
                                'garage_spaces' => ['label' => 'Garage Spaces'],
                                'garage_yn' => ['label' => 'Has Garage', 'format' => 'yn'],
                                'attached_garage_yn' => ['label' => 'Attached Garage', 'format' => 'yn'],
                                'carport_spaces' => ['label' => 'Carport Spaces'],
                                'carport_yn' => ['label' => 'Has Carport', 'format' => 'yn'],
                                'parking_total' => ['label' => 'Total Non-Garage Parking Spaces'],
                                'parking_features' => ['label' => 'Parking Features'],
                                'covered_spaces' => ['label' => 'Covered Parking Spaces'],
                                'open_parking_spaces' => ['label' => 'Open Parking Spaces'],
                                'driveway_surface' => ['label' => 'Driveway Surface'],
                            ];
                            
                            foreach ($parking_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value"><?php echo esc_html($formatted_value); ?></div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Utilities & Systems -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('utilities'); ?></div><h3>Utilities & Systems</h3></div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $utility_fields = [
                                'utilities' => ['label' => 'Utilities Available'],
                                'water_source' => ['label' => 'Water Source'],
                                'sewer' => ['label' => 'Sewer'],
                                'electric' => ['label' => 'Electric'],
                                'electric_on_property_yn' => ['label' => 'Electric on Property', 'format' => 'yn'],
                                'gas' => ['label' => 'Gas'],
                                'internet_type' => ['label' => 'Internet Type'],
                                'cable_available_yn' => ['label' => 'Cable Available', 'format' => 'yn'],
                                'security_features' => ['label' => 'Security Features'],
                                'smart_home_features' => ['label' => 'Smart Home Features'],
                                'energy_features' => ['label' => 'Energy Features'],
                                'green_building_certification' => ['label' => 'Green Building Certification'],
                                'green_certification_rating' => ['label' => 'Green Certification Rating'],
                                'green_energy_efficient' => ['label' => 'Energy Efficient Features'],
                                'green_sustainability' => ['label' => 'Sustainability Features'],
                            ];
                            
                            foreach ($utility_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value"><?php echo esc_html($formatted_value); ?></div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Community & HOA -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('association'); ?></div><h3>Community & HOA</h3></div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $community_fields = [
                                'subdivision_name' => ['label' => 'Subdivision'],
                                'community_features' => ['label' => 'Community Features'],
                                'association_yn' => ['label' => 'HOA', 'format' => 'yn'],
                                'association_fee' => ['label' => 'HOA Fee', 'prefix' => '$'],
                                'association_fee_frequency' => ['label' => 'HOA Fee Frequency'],
                                'association_fee2' => ['label' => 'Additional HOA Fee', 'prefix' => '$'],
                                'association_fee2_frequency' => ['label' => 'Additional HOA Frequency'],
                                'association_amenities' => ['label' => 'Association Amenities'],
                                'association_name' => ['label' => 'Association Name'],
                                'association_phone' => ['label' => 'Association Phone'],
                                'master_association_fee' => ['label' => 'Master Association Fee', 'prefix' => '$'],
                                'condo_association_fee' => ['label' => 'Condo Association Fee', 'prefix' => '$'],
                                'senior_community_yn' => ['label' => 'Senior Community', 'format' => 'yn'],
                                'pets_allowed' => ['label' => 'Pets Allowed'],
                                'pets_allowed_yn' => ['label' => 'Pets Allowed', 'format' => 'yn'],
                                'pet_restrictions' => ['label' => 'Pet Restrictions'],
                            ];
                            
                            foreach ($community_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value">
                                        <?php
                                        if (isset($config['prefix']) && is_numeric($value)) {
                                            echo $config['prefix'] . number_format($value);
                                        } else {
                                            echo esc_html($formatted_value);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Financial & Tax -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('financial'); ?></div><h3>Financial & Tax Information</h3></div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $financial_fields = [
                                'tax_annual_amount' => ['label' => 'Annual Tax Amount', 'prefix' => '$'],
                                'tax_year' => ['label' => 'Tax Year'],
                                'tax_assessed_value' => ['label' => 'Tax Assessed Value', 'prefix' => '$'],
                                'tax_legal_description' => ['label' => 'Legal Description'],
                                'tax_lot' => ['label' => 'Tax Lot'],
                                'tax_block' => ['label' => 'Tax Block'],
                                'tax_map_number' => ['label' => 'Tax Map Number'],
                                'parcel_number' => ['label' => 'Parcel Number'],
                                'additional_parcels_yn' => ['label' => 'Additional Parcels', 'format' => 'yn'],
                                'additional_parcels_description' => ['label' => 'Additional Parcels Info'],
                                'zoning' => ['label' => 'Zoning'],
                                'zoning_description' => ['label' => 'Zoning Description'],
                                'crop' => ['label' => 'Crop/Agricultural Use'],
                                'farm_credit_service_inc_yn' => ['label' => 'Farm Credit Service', 'format' => 'yn'],
                                'financial_data_source' => ['label' => 'Financial Data Source'],
                            ];
                            
                            foreach ($financial_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value">
                                        <?php
                                        if (isset($config['prefix']) && is_numeric($value)) {
                                            echo $config['prefix'] . number_format($value);
                                        } else {
                                            echo esc_html($formatted_value);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Schools & Education -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile">
                            <div class="mld-v3-facts-icon-mobile">
                                <?php echo mld_get_feature_icon('school'); ?>
                            </div>
                            <h3>Schools & Education</h3>
                        </div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php
                            $school_fields = [
                                'school_district' => ['label' => 'School District'],
                                'elementary_school' => ['label' => 'Elementary School'],
                                'middle_or_junior_school' => ['label' => 'Middle School'],
                                'high_school' => ['label' => 'High School'],
                            ];

                            foreach ($school_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);

                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : '🏫'; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value"><?php echo esc_html($formatted_value); ?></div>
                                </div>
                            </div>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Additional Details -->
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('default'); ?></div><h3>Additional Details</h3></div>
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php 
                            $additional_fields = [
                                'year_built_source' => ['label' => 'Year Built Source'],
                                'year_built_details' => ['label' => 'Year Built Details'],
                                'building_name' => ['label' => 'Building Name'],
                                'building_features' => ['label' => 'Building Features'],
                                'common_walls' => ['label' => 'Common Walls'],
                                'property_attached_yn' => ['label' => 'Attached Property', 'format' => 'yn'],
                                'property_condition' => ['label' => 'Property Condition'],
                                'disclosures' => ['label' => 'Disclosures'],
                                'exclusions' => ['label' => 'Exclusions'],
                                'inclusions' => ['label' => 'Inclusions'],
                                'ownership' => ['label' => 'Ownership Type'],
                                'occupant_type' => ['label' => 'Occupant Type'],
                                'possession' => ['label' => 'Possession'],
                                'listing_terms' => ['label' => 'Listing Terms'],
                                'listing_service' => ['label' => 'Listing Service'],
                                'special_listing_conditions' => ['label' => 'Special Listing Conditions'],
                                'buyer_agency_compensation' => ['label' => 'Buyer Agency Compensation'],
                                'sub_agency_compensation' => ['label' => 'Sub-Agency Compensation'],
                                'dual_variable_compensation_yn' => ['label' => 'Dual Variable Compensation', 'format' => 'yn'],
                            ];
                            
                            foreach ($additional_fields as $field => $config):
                                $value = $listing[$field] ?? null;
                                $formatted_value = format_array_field($value);
                                
                                // Handle Y/N fields
                                if (isset($config['format']) && $config['format'] === 'yn') {
                                    if ($value === 'Y' || $value === '1') {
                                        $formatted_value = 'Yes';
                                    } elseif ($value === 'N' || $value === '0') {
                                        $formatted_value = 'No';
                                    }
                                }
                                
                                if (!empty($formatted_value)):
                            ?>
                            <div class="mld-v3-facts-card-mobile">
                                <div class="mld-v3-facts-card-icon-mobile">
                                    <?php echo function_exists('mld_get_icon_for_field') ? mld_get_icon_for_field($field) : ''; ?>
                                </div>
                                <div class="mld-v3-facts-card-content-mobile">
                                    <div class="mld-v3-fact-label"><?php echo esc_html($config['label']); ?></div>
                                    <div class="mld-v3-fact-value"><?php echo esc_html($formatted_value); ?></div>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <!-- Room Details -->
                    <?php 
                    $rooms = $listing['Rooms'] ?? [];
                    if (!empty($rooms)): 
                    ?>
                    <div class="mld-v3-facts-category-mobile">
                        <div class="mld-v3-facts-category-header-mobile"><div class="mld-v3-facts-icon-mobile"><?php echo mld_get_feature_icon('rooms'); ?></div><h3>Room Details</h3></div>
                        <div class="mld-v3-rooms-list">
                            <?php foreach ($rooms as $room): ?>
                            <div class="mld-v3-room-item">
                                <div class="mld-v3-room-name"><?php echo esc_html($room['room_type'] ?? 'Room'); ?></div>
                                <div class="mld-v3-room-details">
                                    <?php if (!empty($room['room_level'])): ?>
                                        <span>Level: <?php echo esc_html($room['room_level']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($room['room_dimensions'])): ?>
                                        <span><?php echo esc_html($room['room_dimensions']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($room['room_features'])): ?>
                                        <span><?php echo esc_html(is_array($room['room_features']) ? implode(', ', $room['room_features']) : $room['room_features']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($room['room_description'])): ?>
                                        <div class="mld-v3-room-desc"><?php echo esc_html($room['room_description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; // End disabled old structure ?>
                    </div>
                </section>

                <!-- Construction & Materials Section (v5.7.0) -->
                <?php
                $has_construction_data = !empty($listing['architectural_style']) ||
                                        !empty($listing['structure_type']) ||
                                        !empty($listing['construction_materials']) ||
                                        !empty($listing['roof']) ||
                                        !empty($listing['foundation_details']) ||
                                        !empty($listing['property_condition']) ||
                                        !empty($listing['year_built_effective']) ||
                                        !empty($listing['stories_total']);

                if ($has_construction_data):
                ?>
                <section id="construction" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Construction & Materials</h2>
                    </div>
                    <div class="mld-v3-section-content">
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php if (!empty($listing['architectural_style'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Style</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['architectural_style'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($year_built)): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Year Built</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($year_built); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['year_built_effective'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Effective Year</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['year_built_effective']); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['structure_type'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Structure Type</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['structure_type'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['construction_materials'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Exterior Materials</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['construction_materials'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['roof'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Roof</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['roof'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['foundation_details'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Foundation</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['foundation_details'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['property_condition'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Condition</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['property_condition'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['stories_total'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Stories</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['stories_total']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Systems & Utilities Section (v5.7.0) -->
                <?php
                $has_systems_data = !empty($listing['heating']) ||
                                   !empty($listing['cooling']) ||
                                   !empty($listing['mlspin_hot_water']) ||
                                   !empty($listing['water_source']) ||
                                   !empty($listing['sewer']) ||
                                   !empty($listing['green_energy_efficient']);

                if ($has_systems_data):
                ?>
                <section id="systems" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Systems & Utilities</h2>
                    </div>
                    <div class="mld-v3-section-content">
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php if (!empty($listing['heating'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Heating</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['heating'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['mlspin_heat_zones'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Heating Zones</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['mlspin_heat_zones']); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['cooling'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Cooling</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['cooling'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['mlspin_cooling_zones'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Cooling Zones</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['mlspin_cooling_zones']); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['mlspin_hot_water'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Hot Water</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['mlspin_hot_water'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['water_source'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Water Source</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['water_source'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['sewer'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Sewer</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['sewer'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['green_energy_efficient'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Energy Features</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['green_energy_efficient'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Exterior & Lot Section (v5.7.0) -->
                <?php
                $has_exterior_data = !empty($listing['exterior_features']) ||
                                    !empty($listing['patio_and_porch_features']) ||
                                    !empty($listing['fencing']) ||
                                    !empty($listing['other_structures']) ||
                                    !empty($listing['lot_features']) ||
                                    !empty($listing['frontage_length']) ||
                                    !empty($listing['road_surface_type']);

                if ($has_exterior_data):
                ?>
                <section id="exterior" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Exterior & Lot</h2>
                    </div>
                    <div class="mld-v3-section-content">
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php if (!empty($listing['exterior_features'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Exterior Features</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['exterior_features'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['patio_and_porch_features'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Patio & Porch</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['patio_and_porch_features'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['fencing'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Fencing</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['fencing'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['other_structures'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Other Structures</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['other_structures'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['lot_features'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Lot Features</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['lot_features'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['frontage_length'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Frontage Length</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['frontage_length']); ?> ft</div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($listing['road_surface_type'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Road Surface</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['road_surface_type'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Special Features Section (v5.7.0) -->
                <?php
                $has_fireplace = !empty($listing['fireplace_yn']) && ($listing['fireplace_yn'] === 'Y' || $listing['fireplace_yn'] === '1');
                $has_pool = !empty($listing['pool_private_yn']) && ($listing['pool_private_yn'] === 'Y' || $listing['pool_private_yn'] === '1');
                $has_spa = !empty($listing['spa_yn']) && ($listing['spa_yn'] === 'Y' || $listing['spa_yn'] === '1');
                $has_waterfront = !empty($listing['waterfront_yn']) && ($listing['waterfront_yn'] === 'Y' || $listing['waterfront_yn'] === '1');
                $has_view = !empty($listing['view_yn']) && ($listing['view_yn'] === 'Y' || $listing['view_yn'] === '1');
                $has_special_features = $has_fireplace || $has_pool || $has_spa || $has_waterfront || $has_view || !empty($listing['community_features']);

                if ($has_special_features):
                ?>
                <section id="special-features" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Special Features</h2>
                    </div>
                    <div class="mld-v3-section-content">
                        <div class="mld-v3-facts-card-grid-mobile">
                            <?php if ($has_fireplace): ?>
                                <?php if (!empty($listing['fireplaces_total'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">Fireplaces</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html($listing['fireplaces_total']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($listing['fireplace_features'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">Fireplace Type</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['fireplace_features'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($has_pool): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Pool</div>
                                    <div class="mld-v3-fact-value-mobile">Private Pool</div>
                                </div>

                                <?php if (!empty($listing['pool_features'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">Pool Features</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['pool_features'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($has_spa): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Spa/Hot Tub</div>
                                    <div class="mld-v3-fact-value-mobile">Yes</div>
                                </div>

                                <?php if (!empty($listing['spa_features'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">Spa Features</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['spa_features'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($has_waterfront): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Waterfront</div>
                                    <div class="mld-v3-fact-value-mobile">Yes</div>
                                </div>

                                <?php if (!empty($listing['waterfront_features'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">Waterfront Type</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['waterfront_features'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($has_view): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">View</div>
                                    <div class="mld-v3-fact-value-mobile">Yes</div>
                                </div>

                                <?php if (!empty($listing['view'])): ?>
                                    <div class="mld-v3-fact-card-mobile">
                                        <div class="mld-v3-fact-label-mobile">View Type</div>
                                        <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['view'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($listing['community_features'])): ?>
                                <div class="mld-v3-fact-card-mobile">
                                    <div class="mld-v3-fact-label-mobile">Community Amenities</div>
                                    <div class="mld-v3-fact-value-mobile"><?php echo esc_html(format_array_field($listing['community_features'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Location Section -->
                <section id="location" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Location</h2>
                    </div>
                    
                    <!-- Map v6.26.0: Enhanced with skeleton loader, expand button, and FABs -->
                    <?php if ($lat && $lng): ?>
                    <?php $first_photo_url = !empty($photos) && !empty($photos[0]['MediaURL']) ? $photos[0]['MediaURL'] : ''; ?>
                    <div class="mld-v3-map-container" id="inlineMapContainer">
                        <!-- Skeleton Loader -->
                        <div class="mld-map-skeleton" id="mapSkeleton"></div>

                        <!-- Interactive Map -->
                        <div id="propertyMapTab" class="mld-v3-map"
                             data-lat="<?php echo esc_attr($lat); ?>"
                             data-lng="<?php echo esc_attr($lng); ?>"
                             data-address="<?php echo esc_attr($address); ?>"
                             data-price="<?php echo esc_attr($display_price); ?>"
                             data-photo="<?php echo esc_attr($first_photo_url); ?>">
                        </div>

                        <!-- Expand Button Overlay -->
                        <div class="mld-map-expand-overlay">
                            <button class="mld-map-expand-btn" id="expandMapBtn" aria-label="Expand map">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
                                </svg>
                                <span>Expand Map</span>
                            </button>
                        </div>

                        <!-- Floating Action Button (Directions only) -->
                        <div class="mld-map-fab-container" id="inlineMapFabs">
                            <button class="mld-map-fab mld-map-fab-directions"
                                    data-lat="<?php echo esc_attr($lat); ?>"
                                    data-lng="<?php echo esc_attr($lng); ?>"
                                    aria-label="Get directions">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M21.71 11.29l-9-9a.996.996 0 00-1.41 0l-9 9a.996.996 0 000 1.41l9 9c.39.39 1.02.39 1.41 0l9-9a.996.996 0 000-1.41zM14 14.5V12h-4v3H8v-4c0-.55.45-1 1-1h5V7.5l3.5 3.5-3.5 3.5z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Neighborhood Info -->
                    <?php if ($neighborhood): ?>
                    <div class="mld-v3-neighborhood">
                        <h3>Neighborhood</h3>
                        <p><?php echo esc_html($neighborhood); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Enhanced Schools Section (v6.30.4 - District-based) -->
                    <div class="mld-v3-schools-enhanced">
                        <?php
                        if ($lat && $lng && class_exists('MLD_BMN_Schools_Integration')) {
                            $schools_integration = MLD_BMN_Schools_Integration::get_instance();
                            // Use district-based fetching to show ALL schools in the school district
                            $schools_data = $schools_integration->get_schools_for_district($lat, $lng);
                            if ($schools_data && (!empty($schools_data['schools']) || !empty($schools_data['district']))) {
                                echo $schools_integration->render_enhanced_schools_section($schools_data);
                            } else {
                                ?>
                                <h3>Nearby Schools</h3>
                                <p class="mld-schools-unavailable">School information not available for this location.</p>
                                <?php
                            }
                        } else {
                            ?>
                            <h3>Nearby Schools</h3>
                            <p class="mld-schools-unavailable">School information not available.</p>
                            <?php
                        }
                        ?>
                    </div>
                    
                    <!-- Walk Score -->
                    <?php if (class_exists('MLD_Settings') && MLD_Settings::is_walk_score_enabled()): ?>
                    <div class="mld-v3-scores">
                        <div id="walk-score-container"></div>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- Payment Calculator Section (Desktop Enhanced v6.0.2) -->
                <section id="payment" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Monthly Payment Calculator</h2>
                    </div>
                    <div class="mld-v3-calculator">

                        <!-- Summary Cards at Top -->
                        <div class="mld-v3-calc-summary-grid">
                            <div class="mld-v3-calc-summary-card">
                                <div class="mld-v3-summary-label">Monthly Payment</div>
                                <div class="mld-v3-summary-value" id="v3CalcPaymentSummary">$0</div>
                                <div class="mld-v3-summary-note">Principal, interest, taxes & insurance</div>
                            </div>
                            <div class="mld-v3-calc-summary-card">
                                <div class="mld-v3-summary-label">Loan Amount</div>
                                <div class="mld-v3-summary-value" id="v3CalcLoanAmount">$0</div>
                                <div class="mld-v3-summary-note">After down payment</div>
                            </div>
                            <div class="mld-v3-calc-summary-card">
                                <div class="mld-v3-summary-label">Total Interest</div>
                                <div class="mld-v3-summary-value" id="v3CalcTotalInterest">$0</div>
                                <div class="mld-v3-summary-note">Over life of loan</div>
                            </div>
                            <div class="mld-v3-calc-summary-card">
                                <div class="mld-v3-summary-label">Total Cost</div>
                                <div class="mld-v3-summary-value" id="v3CalcTotalCost">$0</div>
                                <div class="mld-v3-summary-note">Including down payment</div>
                            </div>
                        </div>

                        <!-- Input Fields -->
                        <div class="mld-v3-calc-inputs">
                            <div class="mld-v3-calc-row">
                                <div class="mld-v3-calc-field">
                                    <label>Home Price</label>
                                    <input type="number" id="v3CalcPrice" value="<?php echo esc_attr((int)($display_price ?? 0)); ?>">
                                </div>
                                <div class="mld-v3-calc-field">
                                    <label>Down Payment</label>
                                    <div class="mld-v3-calc-input-group">
                                        <input type="number" id="v3CalcDownPercent" value="20" min="0" max="100">
                                        <span class="mld-v3-calc-suffix">%</span>
                                    </div>
                                    <div class="mld-v3-calc-helper">Down: $<span id="v3CalcDownDisplay">0</span></div>
                                </div>
                            </div>
                            <div class="mld-v3-calc-row">
                                <div class="mld-v3-calc-field">
                                    <label>Interest Rate</label>
                                    <div class="mld-v3-calc-input-group">
                                        <input type="number" id="v3CalcRate" value="6.5" step="0.1" min="0">
                                        <span class="mld-v3-calc-suffix">%</span>
                                    </div>
                                </div>
                                <div class="mld-v3-calc-field">
                                    <label>Loan Term</label>
                                    <select id="v3CalcTerm">
                                        <option value="30" selected>30 years</option>
                                        <option value="15">15 years</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Detailed Breakdown -->
                        <div class="mld-v3-calc-results">
                            <div class="mld-v3-calc-breakdown">
                                <h4>Monthly Payment Breakdown</h4>
                                <div class="mld-v3-calc-item">
                                    <span>Principal & Interest</span>
                                    <span id="v3CalcPI">$0</span>
                                </div>
                                <div class="mld-v3-calc-item">
                                    <span>Property Tax</span>
                                    <span id="v3CalcTax">$<?php echo $tax_amount ? number_format($tax_amount / 12) : '0'; ?></span>
                                </div>
                                <div class="mld-v3-calc-item">
                                    <span>Homeowners Insurance</span>
                                    <span id="v3CalcInsurance">$200</span>
                                </div>
                                <?php if ($hoa_monthly): ?>
                                <div class="mld-v3-calc-item">
                                    <span>HOA Fee</span>
                                    <span>$<?php echo number_format($hoa_monthly); ?>/mo</span>
                                </div>
                                <?php endif; ?>
                                <div class="mld-v3-calc-item">
                                    <span>PMI (if &lt;20% down)</span>
                                    <span id="v3CalcPMI">$0</span>
                                </div>
                            </div>

                            <!-- Loan Summary -->
                            <div class="mld-v3-calc-loan-summary">
                                <h4>Loan Summary</h4>
                                <div class="mld-v3-calc-item">
                                    <span>Total of 360 payments</span>
                                    <span id="v3CalcTotalPayments">$0</span>
                                </div>
                                <div class="mld-v3-calc-item">
                                    <span>Total principal paid</span>
                                    <span id="v3CalcTotalPrincipal">$0</span>
                                </div>
                                <div class="mld-v3-calc-item mld-v3-calc-highlight">
                                    <span>Total interest paid</span>
                                    <span id="v3CalcTotalInterestDetail">$0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Rate Impact Analysis -->
                        <div class="mld-v3-rate-impact">
                            <h4>Rate Impact</h4>
                            <div class="mld-v3-rate-comparison">
                                <div class="mld-v3-rate-scenario mld-v3-rate-lower">
                                    <div class="mld-v3-rate-label">-0.5% Rate</div>
                                    <div class="mld-v3-rate-value" id="v3CalcRateLower">$0/mo</div>
                                    <div class="mld-v3-rate-save" id="v3CalcRateLowerSave">Save $0/mo</div>
                                </div>
                                <div class="mld-v3-rate-scenario mld-v3-rate-current">
                                    <div class="mld-v3-rate-label">Current Rate</div>
                                    <div class="mld-v3-rate-value" id="v3CalcRateCurrent">$0/mo</div>
                                </div>
                                <div class="mld-v3-rate-scenario mld-v3-rate-higher">
                                    <div class="mld-v3-rate-label">+0.5% Rate</div>
                                    <div class="mld-v3-rate-value" id="v3CalcRateHigher">$0/mo</div>
                                    <div class="mld-v3-rate-cost" id="v3CalcRateHigherCost">Cost $0/mo</div>
                                </div>
                            </div>
                        </div>

                        <!-- Amortization Breakdown -->
                        <div class="mld-v3-amortization">
                            <h4>Payment Over Time</h4>
                            <div class="mld-v3-amort-visual">
                                <div class="mld-v3-amort-bar">
                                    <div class="mld-v3-amort-interest" id="v3AmortInterest" style="width: 80%">
                                        <span class="mld-v3-amort-label">Interest</span>
                                    </div>
                                    <div class="mld-v3-amort-principal" id="v3AmortPrincipal" style="width: 20%">
                                        <span class="mld-v3-amort-label">Principal</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mld-v3-amort-milestones">
                                <div class="mld-v3-milestone">
                                    <div class="mld-v3-milestone-year">Year 1</div>
                                    <div class="mld-v3-milestone-split">
                                        <span class="mld-v3-interest-pct" id="v3Year1Interest">95%</span> Interest
                                    </div>
                                </div>
                                <div class="mld-v3-milestone">
                                    <div class="mld-v3-milestone-year">Year 15</div>
                                    <div class="mld-v3-milestone-split">
                                        <span id="v3Year15Split">50/50</span>
                                    </div>
                                </div>
                                <div class="mld-v3-milestone">
                                    <div class="mld-v3-milestone-year">Year 30</div>
                                    <div class="mld-v3-milestone-split">
                                        <span class="mld-v3-principal-pct" id="v3Year30Principal">95%</span> Principal
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mld-v3-calc-disclaimer">
                            * Estimates only. Actual payments may vary. Consult with a lender for accurate figures.
                        </div>
                    </div>
                </section>

                <!-- Sold Statistics Section (for closed properties) -->
                <?php if (!empty($sold_stats)): ?>
                <section id="sold-stats" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Sale Statistics</h2>
                    </div>
                    <div class="mld-v3-stats-grid">
                        <div class="mld-v3-stat-card">
                            <div class="mld-v3-stat-header">Sale Overview</div>
                            <div class="mld-v3-stat-row">
                                <span class="mld-v3-stat-label">Original List Price:</span>
                                <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['original_price']); ?></span>
                            </div>
                            <div class="mld-v3-stat-row highlight">
                                <span class="mld-v3-stat-label">Final Sale Price:</span>
                                <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['sale_price']); ?></span>
                            </div>
                            <div class="mld-v3-stat-row <?php echo $sold_stats['price_difference'] >= 0 ? 'positive' : 'negative'; ?>">
                                <span class="mld-v3-stat-label">Difference:</span>
                                <span class="mld-v3-stat-value">
                                    <?php echo $sold_stats['price_difference'] >= 0 ? '+' : ''; ?>$<?php echo esc_html(number_format(abs($sold_stats['price_difference']))); ?>
                                    (<?php echo $sold_stats['price_percentage'] >= 0 ? '+' : ''; ?><?php echo esc_html(number_format($sold_stats['price_percentage'], 1)); ?>%)
                                </span>
                            </div>
                        </div>

                        <!-- Performance Metrics -->
                        <?php if (!empty($sold_stats['list_to_sale_ratio'])): ?>
                        <div class="mld-v3-stat-card">
                            <div class="mld-v3-stat-header">Performance Metrics</div>
                            <div class="mld-v3-stat-metric">
                                <div class="mld-v3-metric-value"><?php echo number_format($sold_stats['list_to_sale_ratio'], 1); ?>%</div>
                                <div class="mld-v3-metric-label">List to Sale Ratio</div>
                            </div>
                            <?php if (!empty($sold_stats['days_on_market'])): ?>
                            <div class="mld-v3-stat-metric">
                                <div class="mld-v3-metric-value"><?php echo esc_html($sold_stats['days_on_market']); ?></div>
                                <div class="mld-v3-metric-label">Days on Market</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Price Analysis -->
                        <?php if (!empty($sold_stats['price_per_sqft'])): ?>
                        <div class="mld-v3-stat-card">
                            <div class="mld-v3-stat-header">Price per Square Foot</div>
                            <div class="mld-v3-stat-row">
                                <span class="mld-v3-stat-label">Original $/sq ft:</span>
                                <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['original_price_per_sqft']); ?></span>
                            </div>
                            <div class="mld-v3-stat-row highlight">
                                <span class="mld-v3-stat-label">Sale $/sq ft:</span>
                                <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['price_per_sqft']); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Property History Section -->
                <section id="history" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Property History</h2>
                    </div>
                    <?php include MLD_PLUGIN_PATH . 'templates/partials/property-history-enhanced.php'; ?>
                </section>

                <!-- Comparable Properties Section (matches desktop v6.13.15) -->
                <section id="similar-homes" class="mld-v3-section-mobile">
                    <div class="mld-v3-section-header">
                        <h2>Comparable Properties</h2>
                    </div>
                    <?php
                    // Prepare property data for comparable sales display (same as desktop)
                    global $wpdb;
                    $user_prop_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT road_type, property_condition FROM {$wpdb->prefix}mld_user_property_data WHERE listing_id = %s",
                        $mls_number
                    ), ARRAY_A);

                    $subject_property = array(
                        'mlsNumber' => $mls_number,
                        'lat' => $lat,
                        'lng' => $lng,
                        'price' => $display_price ?? 0,
                        'beds' => $beds ?? 0,
                        'baths' => $baths ?? 0,
                        'sqft' => $sqft ? str_replace(',', '', $sqft) : 0,
                        'propertyType' => $listing['property_type'] ?? '',
                        'yearBuilt' => $year_built ?? 0,
                        'garageSpaces' => $listing['garage_spaces'] ?? 0,
                        'pool' => !empty($listing['pool_yn']),
                        'waterfront' => !empty($listing['waterfront_yn']),
                        'roadType' => $user_prop_data['road_type'] ?? '',
                        'propertyCondition' => $user_prop_data['property_condition'] ?? '',
                        'city' => $city,
                        'state' => $state ?? 'MA'
                    );

                    // Render enhanced comparable sales display
                    if (function_exists('mld_render_comparable_sales')) {
                        mld_render_comparable_sales($subject_property);
                    }
                    ?>
                </section>

                <?php
                // MLS Disclosure Section
                $disclosure_settings = get_option('mld_disclosure_settings', []);
                $disclosure_enabled = !empty($disclosure_settings['enabled']);
                $disclosure_logo = isset($disclosure_settings['logo_url']) ? $disclosure_settings['logo_url'] : '';
                $disclosure_text = isset($disclosure_settings['disclosure_text']) ? $disclosure_settings['disclosure_text'] : '';

                if ($disclosure_enabled && ($disclosure_logo || $disclosure_text)):
                ?>
                <!-- MLS Disclosure Section -->
                <section class="mld-v3-section-mobile mld-v3-disclosure-section">
                    <div class="mld-v3-section-header">
                        <h2>Listing Information</h2>
                    </div>
                    <div class="mld-v3-disclosure-mobile">
                        <?php if ($disclosure_logo): ?>
                        <img src="<?php echo esc_url($disclosure_logo); ?>"
                             alt="MLS Logo"
                             class="mld-v3-disclosure-logo-mobile">
                        <?php endif; ?>
                        <?php if ($disclosure_text): ?>
                        <div class="mld-v3-disclosure-text-mobile">
                            <?php echo wp_kses_post($disclosure_text); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php
                // Market Analytics Section (lazy-loaded, lite mode for mobile)
                if (!empty($city)) {
                    if (!class_exists('MLD_Analytics_Tabs')) {
                        require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-tabs.php';
                    }
                    $property_type_filter = !empty($listing['property_type']) ? $listing['property_type'] : 'all';
                    echo MLD_Analytics_Tabs::render_property_section($city, $state ?? 'MA', $property_type_filter);
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Virtual Tour Modal -->
    <div class="mld-modal" id="virtualTourModal">
        <div class="mld-modal-backdrop"></div>
        <div class="mld-modal-content mld-tour-modal-content">
            <div class="mld-modal-header">
                <h3 id="tourModalTitle">Virtual Tour</h3>
                <button class="mld-modal-close">&times;</button>
            </div>
            <div class="mld-tour-viewer" id="tourViewer">
                <!-- Tour iframe will be inserted here -->
            </div>
        </div>
    </div>
    
    <!-- Combined Map/Street View Modal (v6.26.1 - Redesigned Header) -->
    <div class="mld-modal mld-map-modal" id="mapModal">
        <div class="mld-modal-backdrop"></div>
        <div class="mld-modal-content mld-fullscreen-content">
            <div class="mld-modal-header mld-map-modal-header">
                <button class="mld-modal-back" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                    </svg>
                </button>
                <div class="mld-modal-address"><?php echo esc_html($address); ?></div>
                <div class="mld-map-view-toggle">
                    <button class="mld-view-tab active" data-view="map">Map</button>
                    <button class="mld-view-tab" data-view="streetview">Street View</button>
                </div>
            </div>
            <div class="mld-modal-body mld-map-body">
                <div id="modalMap" class="mld-modal-map mld-view-panel active"
                     data-lat="<?php echo esc_attr($lat); ?>"
                     data-lng="<?php echo esc_attr($lng); ?>"
                     data-address="<?php echo esc_attr($address); ?>"
                     data-price="<?php echo esc_attr($display_price); ?>">
                </div>
                <div id="modalStreetView" class="mld-modal-streetview mld-view-panel"
                     data-lat="<?php echo esc_attr($lat); ?>"
                     data-lng="<?php echo esc_attr($lng); ?>">
                </div>
                <!-- v6.26.2: Modal Map FAB (Directions only) -->
                <div class="mld-map-fab-container" id="modalMapFabs">
                    <button class="mld-map-fab mld-map-fab-directions"
                            data-lat="<?php echo esc_attr($lat); ?>"
                            data-lng="<?php echo esc_attr($lng); ?>"
                            aria-label="Get directions">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M21.71 11.29l-9-9a.996.996 0 00-1.41 0l-9 9a.996.996 0 000 1.41l9 9c.39.39 1.02.39 1.41 0l9-9a.996.996 0 000-1.41zM14 14.5V12h-4v3H8v-4c0-.55.45-1 1-1h5V7.5l3.5 3.5-3.5 3.5z"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Agent Modal -->
    <div class="mld-modal mld-contact-modal" id="contactModal">
        <div class="mld-modal-backdrop"></div>
        <div class="mld-modal-content">
            <div class="mld-modal-header">
                <div class="mld-contact-agent-info">
                    <?php if (!empty($contact_agent_photo)): ?>
                        <img src="<?php echo esc_url($contact_agent_photo); ?>" alt="<?php echo esc_attr($contact_agent_name); ?>" class="mld-contact-agent-photo">
                    <?php endif; ?>
                    <div class="mld-contact-agent-details">
                        <h3><?php echo esc_html($contact_agent_name); ?></h3>
                        <?php if (!empty($contact_agent_label)): ?>
                            <span class="mld-contact-agent-label"><?php echo esc_html($contact_agent_label); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="mld-modal-close">&times;</button>
            </div>
            <div class="mld-modal-body">
                <form id="contactForm" class="mld-contact-form">
                    <input type="hidden" name="mls_number" value="<?php echo esc_attr($mls_number); ?>">
                    <input type="hidden" name="property_address" value="<?php echo esc_attr($address); ?>">
                    <input type="hidden" name="agent_email" value="<?php echo esc_attr($contact_agent_email); ?>">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('mld_ajax_nonce'); ?>">

                    <div class="mld-form-row">
                        <div class="mld-form-group">
                            <label for="contact_first_name">First Name *</label>
                            <input type="text" id="contact_first_name" name="first_name" required>
                        </div>
                        <div class="mld-form-group">
                            <label for="contact_last_name">Last Name *</label>
                            <input type="text" id="contact_last_name" name="last_name" required>
                        </div>
                    </div>

                    <div class="mld-form-group">
                        <label for="contact_email">Email *</label>
                        <input type="email" id="contact_email" name="email" required>
                    </div>
                    <div class="mld-form-group">
                        <label for="contact_phone">Phone</label>
                        <input type="tel" id="contact_phone" name="phone">
                    </div>
                    <div class="mld-form-group">
                        <label for="contact_message">Message</label>
                        <textarea id="contact_message" name="message" rows="4" placeholder="I'm interested in this property..."></textarea>
                    </div>

                    <button type="submit" class="mld-form-submit">Send Message</button>
                    <div class="mld-form-status"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Schedule Tour Modal -->
    <div class="mld-modal mld-tour-modal" id="tourModal">
        <div class="mld-modal-backdrop"></div>
        <div class="mld-modal-content">
            <div class="mld-modal-header">
                <h3>Schedule a Tour</h3>
                <button class="mld-modal-close">&times;</button>
            </div>
            <div class="mld-modal-body">
                <form id="tourForm" class="mld-tour-form">
                    <input type="hidden" name="mls_number" value="<?php echo esc_attr($mls_number); ?>">
                    <input type="hidden" name="property_address" value="<?php echo esc_attr($address); ?>">
                    <input type="hidden" name="agent_email" value="<?php echo esc_attr($contact_agent_email); ?>">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('mld_ajax_nonce'); ?>">

                    <div class="mld-form-row">
                        <div class="mld-form-group">
                            <label for="tour_first_name">First Name *</label>
                            <input type="text" id="tour_first_name" name="first_name" required>
                        </div>
                        <div class="mld-form-group">
                            <label for="tour_last_name">Last Name *</label>
                            <input type="text" id="tour_last_name" name="last_name" required>
                        </div>
                    </div>

                    <div class="mld-form-group">
                        <label for="tour_email">Email *</label>
                        <input type="email" id="tour_email" name="email" required>
                    </div>
                    <div class="mld-form-group">
                        <label for="tour_phone">Phone *</label>
                        <input type="tel" id="tour_phone" name="phone" required>
                    </div>

                    <div class="mld-form-group">
                        <label for="tour_type">Tour Type</label>
                        <select id="tour_type" name="tour_type">
                            <option value="in_person">In-Person Tour</option>
                            <option value="virtual">Virtual Tour</option>
                        </select>
                    </div>

                    <div class="mld-form-row">
                        <div class="mld-form-group">
                            <label for="tour_date">Preferred Date</label>
                            <input type="date" id="tour_date" name="preferred_date">
                        </div>
                        <div class="mld-form-group">
                            <label for="tour_time">Preferred Time</label>
                            <select id="tour_time" name="preferred_time">
                                <option value="">Select time...</option>
                                <option value="morning">Morning (9am - 12pm)</option>
                                <option value="afternoon">Afternoon (12pm - 5pm)</option>
                                <option value="evening">Evening (5pm - 8pm)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="mld-form-submit">Request Tour</button>
                    <div class="mld-form-status"></div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Property data for JavaScript modules (backwards compatibility)
window.mldPropertyData = {
    propertyId: <?php echo json_encode($mls_number); ?>,
    address: <?php echo json_encode($address); ?>,
    coordinates: <?php echo json_encode($lat && $lng ? array('lat' => (float)$lat, 'lng' => (float)$lng) : null); ?>,
    price: <?php echo (int)($display_price ?? 0); ?>,
    priceFormatted: <?php echo json_encode($price); ?>,
    propertyTax: <?php echo (int)($tax_amount ?? 0); ?>,
    hoaFees: <?php echo (int)($hoa_fee ?? 0); ?>,
    photos: <?php echo json_encode(array_values($photos)); ?>,
    details: <?php echo json_encode($beds . ' beds, ' . $baths . ' baths' . ($sqft ? ', ' . $sqft . ' sqft' : '')); ?>,
    mainPhoto: <?php echo json_encode(!empty($photos) ? $photos[0]['MediaURL'] : null); ?>,
    city: <?php echo json_encode($city); ?>,
    daysOnMarket: <?php echo json_encode($days_on_market); ?>,
    ajaxUrl: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
    nonce: <?php echo json_encode(wp_create_nonce('mld_ajax_nonce')); ?>
};

// V3 Property data (matching desktop format)
window.mldPropertyDataV3 = {
    mlsNumber: <?php echo json_encode($mls_number); ?>,
    price: <?php echo (int)($display_price ?? 0); ?>,
    beds: <?php echo (int)$beds; ?>,
    baths: <?php echo (float)$baths; ?>,
    sqft: <?php echo (int)str_replace(',', '', $sqft ?? '0'); ?>,
    propertyType: <?php echo json_encode($property_type); ?>,
    propertySubType: <?php echo json_encode($property_sub_type); ?>,
    status: <?php echo json_encode($status); ?>,
    closeDate: <?php echo json_encode($listing['close_date'] ?? ''); ?>,
    originalEntryTimestamp: <?php echo json_encode($listing['original_entry_timestamp'] ?? ''); ?>,
    offMarketDate: <?php echo json_encode($listing['off_market_date'] ?? ''); ?>,
    daysOnMarket: <?php echo json_encode($days_on_market); ?>,
    lat: <?php echo json_encode($lat ? (float)$lat : null); ?>,
    lng: <?php echo json_encode($lng ? (float)$lng : null); ?>,
    city: <?php echo json_encode($city); ?>,
    yearBuilt: <?php echo json_encode($year_built); ?>,
    lotSizeAcres: <?php echo json_encode($listing['lot_size_acres'] ?? null); ?>,
    lotSizeSquareFeet: <?php echo json_encode($listing['lot_size_square_feet'] ?? null); ?>,
    garageSpaces: <?php echo json_encode($listing['garage_spaces'] ?? 0); ?>,
    parkingTotal: <?php echo json_encode($listing['parking_total'] ?? 0); ?>,
    isWaterfront: <?php echo json_encode(isset($listing['waterfront_yn']) && ($listing['waterfront_yn'] === 'Y' || $listing['waterfront_yn'] === '1')); ?>,
    entryLevel: <?php echo json_encode($listing['entry_level'] ?? null); ?>,
    propertyTax: <?php echo json_encode((int)($tax_amount ?? 0)); ?>,
    hoaFees: <?php echo json_encode((int)($hoa_fee ?? 0)); ?>
};

// Settings (includes Walk Score settings)
window.mldSettings = <?php
    $settings = [];
    if (class_exists('MLD_Settings')) {
        $settings = MLD_Settings::get_js_settings();
    }
    // Add AJAX settings needed for Walk Score
    $settings['ajax_url'] = admin_url('admin-ajax.php');
    $settings['ajax_nonce'] = wp_create_nonce('mld_ajax_nonce');
    echo json_encode($settings);
?>;

// Map data
window.bmeMapDataV3 = {
    mapProvider: 'google', // Always Google Maps now (Mapbox removed for performance)
    google_key: <?php
        $google_key = '';
        if (class_exists('MLD_Settings')) {
            $google_key = MLD_Settings::get_google_maps_api_key();
        }
        echo json_encode($google_key);
    ?>
};

// Backwards compatibility for map data
window.bmeMapData = window.bmeMapDataV3;
</script>

<!-- YouTube Video Modal -->
<div class="mld-youtube-modal" id="youtubeVideoModal" style="display: none;">
    <div class="mld-youtube-modal-overlay"></div>
    <div class="mld-youtube-modal-content">
        <button class="mld-youtube-modal-close" id="youtubeModalClose">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <div class="mld-youtube-iframe-container" id="youtubeIframeContainer">
            <!-- YouTube iframe will be inserted here dynamically -->
        </div>
    </div>
</div>


<!-- Initialize Mobile Property Page -->
<script>
(function() {
    'use strict';

    // Enhanced initialization with debugging

    // Initialize bottom sheet position on DOM ready
    document.addEventListener('DOMContentLoaded', function() {

        // Set initial bottom sheet position with force styles
        var bottomSheet = document.getElementById('bottomSheet');
        if (bottomSheet) {
            bottomSheet.style.transform = 'translateY(50%)';
            bottomSheet.style.display = 'flex';
            bottomSheet.style.visibility = 'visible';
            bottomSheet.style.position = 'fixed';
            bottomSheet.style.bottom = '0';
            bottomSheet.style.left = '0';
            bottomSheet.style.right = '0';
            bottomSheet.style.height = '100vh';
            bottomSheet.style.zIndex = '1000';
        } else {
        }

        // Implement intersection observer for lazy loading
        if ('IntersectionObserver' in window) {
            const lazyImages = document.querySelectorAll('.lazy-image[data-src]');
            const imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const lazyImage = entry.target;
                        const placeholder = lazyImage.parentElement;
                        const spinner = placeholder.parentElement.querySelector('.mld-image-spinner');

                        // Show spinner
                        if (spinner) spinner.style.display = 'block';

                        lazyImage.src = lazyImage.dataset.src;
                        lazyImage.classList.remove('lazy-image');

                        lazyImage.addEventListener('load', function() {
                            // Hide spinner and placeholder
                            if (spinner) spinner.style.display = 'none';
                            placeholder.style.background = 'none';
                        });

                        lazyImage.addEventListener('error', function() {
                            // Hide spinner and show error state
                            if (spinner) spinner.style.display = 'none';
                            placeholder.classList.add('image-error');
                            console.error('Lazy image failed to load:', lazyImage.dataset.src);
                        });

                        observer.unobserve(lazyImage);
                    }
                });
            }, {
                // Start loading when image is 50px away from viewport
                rootMargin: '50px'
            });

            lazyImages.forEach(function(lazyImage) {
                imageObserver.observe(lazyImage);
            });
        } else {
            // Fallback for browsers without Intersection Observer
            const lazyImages = document.querySelectorAll('.lazy-image[data-src]');
            lazyImages.forEach(function(lazyImage) {
                lazyImage.src = lazyImage.dataset.src;
                lazyImage.classList.remove('lazy-image');
            });
        }

        // Monitor all image loading (for debugging if needed)
        const images = document.querySelectorAll('.mld-photo-item img');
        let loadedCount = 0;
        let errorCount = 0;


        images.forEach(function(img, index) {
            if (img.complete) {
                loadedCount++;
            } else {
                img.addEventListener('load', function() {
                    loadedCount++;
                });
                img.addEventListener('error', function() {
                    errorCount++;
                    console.error('[MLD Mobile] Image ' + (index + 1) + ' failed:', img.src || img.dataset.src);
                });
            }
        });


        // Force trigger mobile initialization if available
        if (window.MLDMobile && window.MLDMobile.reinit) {
            setTimeout(function() {
                window.MLDMobile.reinit();
            }, 500);
        }
    });
})();
</script>

<!-- Sticky Contact Bar (appears after scrolling past contact section) -->
<div class="mld-sticky-contact-bar" id="stickyContactBar" style="display: none;">
    <div class="mld-sticky-contact-inner">
        <button class="mld-sticky-btn mld-sticky-schedule">
            <svg class="mld-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
                <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
            </svg>
            <span>Schedule Tour</span>
        </button>
        <button class="mld-sticky-btn mld-sticky-contact">
            <svg class="mld-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
            <span>Contact</span>
        </button>
    </div>
</div>

<!-- Photo Lightbox Modal -->
<div class="mld-photo-lightbox" id="photoLightbox" style="display: none;">
    <div class="mld-lightbox-overlay"></div>
    <div class="mld-lightbox-content">
        <button class="mld-lightbox-close" id="lightboxClose" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <div class="mld-lightbox-counter" id="lightboxCounter">1 / 1</div>
        <div class="mld-lightbox-rotate-hint" id="lightboxRotateHint">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="6" width="14" height="10" rx="2"/>
                <path d="M17 10h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2"/>
                <path d="M13 3l3 3-3 3"/>
            </svg>
            <span>Rotate for better view</span>
        </div>
        <div class="mld-lightbox-image-container" id="lightboxImageContainer">
            <img id="lightboxImage" class="mld-lightbox-image" src="" alt="">
        </div>
        <button class="mld-lightbox-nav mld-lightbox-prev" id="lightboxPrev" aria-label="Previous">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
        </button>
        <button class="mld-lightbox-nav mld-lightbox-next" id="lightboxNext" aria-label="Next">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
        </button>
    </div>
</div>

<!-- Quick Nav removed in v6.13.15 for cleaner UI -->

<script>
// Photo Lightbox with Zoom, Pan, and Rotation Hint
(function() {
    'use strict';

    const lightbox = document.getElementById('photoLightbox');
    const lightboxImage = document.getElementById('lightboxImage');
    const lightboxCounter = document.getElementById('lightboxCounter');
    const lightboxClose = document.getElementById('lightboxClose');
    const lightboxPrev = document.getElementById('lightboxPrev');
    const lightboxNext = document.getElementById('lightboxNext');
    const imageContainer = document.getElementById('lightboxImageContainer');
    const rotateHint = document.getElementById('lightboxRotateHint');

    if (!lightbox || !lightboxImage || !imageContainer) {
        console.error('[MLD Lightbox] Required elements not found');
        return;
    }

    let currentPhotoIndex = 0;
    let photos = window.mldPropertyData.photos || [];
    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let startDistance = 0;
    let startScale = 1;
    let touchStartX = 0;
    let touchStartY = 0;
    let touchStartTranslateX = 0;
    let touchStartTranslateY = 0;
    let isPanning = false;
    let isSwiping = false;
    let lastTap = 0;

    // Control visibility (v5.8.0)
    let controlsVisible = true;
    let controlHideTimer = null;
    let tapStartTime = 0;
    let tapStartPos = { x: 0, y: 0 };

    function hideControls() {
        if (!lightbox || lightbox.style.display === 'none') return;
        lightbox.classList.add('mld-controls-hidden');
        controlsVisible = false;
        console.log('[MLD Lightbox] Controls hidden');
    }

    function showControls() {
        if (!lightbox || lightbox.style.display === 'none') return;
        lightbox.classList.remove('mld-controls-hidden');
        controlsVisible = true;
        console.log('[MLD Lightbox] Controls shown');

        // Auto-hide after 3 seconds
        clearTimeout(controlHideTimer);
        controlHideTimer = setTimeout(hideControls, 3000);
    }

    function toggleControls() {
        if (controlsVisible) {
            clearTimeout(controlHideTimer);
            hideControls();
        } else {
            showControls();
        }
    }

    function updateTransform() {
        lightboxImage.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
    }

    function updateLightboxImage() {
        if (photos.length === 0) return;

        const photo = photos[currentPhotoIndex];
        lightboxImage.src = photo.MediaURL || '';
        lightboxImage.alt = `Photo ${currentPhotoIndex + 1}`;
        lightboxCounter.textContent = `${currentPhotoIndex + 1} / ${photos.length}`;

        // Force counter styles
        if (lightboxCounter) {
            lightboxCounter.style.cssText = 'position: fixed !important; bottom: 20px !important; left: 50% !important; transform: translateX(-50%) !important; background: rgba(0, 0, 0, 0.7) !important; color: white !important; padding: 8px 16px !important; border-radius: 20px !important; font-size: 14px !important; font-weight: 500 !important; z-index: 1000001 !important; display: block !important;';
        }

        // Reset transform
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();

        // Update navigation buttons
        if (lightboxPrev) lightboxPrev.style.display = currentPhotoIndex > 0 ? 'flex' : 'none';
        if (lightboxNext) lightboxNext.style.display = currentPhotoIndex < photos.length - 1 ? 'flex' : 'none';
    }

    function openLightbox(index) {
        currentPhotoIndex = index;
        updateLightboxImage();

        // Force display styles
        lightbox.style.cssText = 'display: block !important; position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; z-index: 999999 !important;';

        const overlay = lightbox.querySelector('.mld-lightbox-overlay');
        if (overlay) {
            overlay.style.cssText = 'position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; background: rgba(0,0,0,0.95) !important; z-index: 999998 !important;';
        }

        imageContainer.style.cssText = 'position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; display: flex !important; align-items: center !important; justify-content: center !important; z-index: 999998 !important;';

        lightboxImage.style.cssText = 'max-width: 100vw !important; max-height: 100vh !important; width: 100% !important; height: 100% !important; object-fit: contain !important; display: block !important; opacity: 1 !important; z-index: 999999 !important;';
        lightboxImage.className = 'mld-lightbox-image';

        const closeBtn = lightbox.querySelector('.mld-lightbox-close');
        if (closeBtn) {
            closeBtn.style.cssText = 'position: fixed !important; top: 20px !important; right: 20px !important; width: 50px !important; height: 50px !important; background: rgba(255,255,255,0.9) !important; border-radius: 50% !important; border: none !important; color: #000 !important; font-size: 30px !important; display: flex !important; align-items: center !important; justify-content: center !important; z-index: 1000000 !important; cursor: pointer !important; box-shadow: 0 2px 10px rgba(0,0,0,0.3) !important;';
        }

        document.body.style.overflow = 'hidden';

        // Show rotation hint in portrait mode
        updateRotateHint();

        // Show controls initially, auto-hide after 2 seconds (v5.8.0)
        lightbox.classList.remove('mld-controls-hidden');
        controlsVisible = true;
        clearTimeout(controlHideTimer);
        controlHideTimer = setTimeout(hideControls, 2000);
    }

    function closeLightbox() {
        lightbox.style.display = 'none';
        document.body.style.overflow = '';
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();

        // Clear control timers (v5.8.0)
        clearTimeout(controlHideTimer);
        lightbox.classList.remove('mld-controls-hidden');
        controlsVisible = true;
    }

    function nextPhoto() {
        if (currentPhotoIndex < photos.length - 1) {
            currentPhotoIndex++;
            updateLightboxImage();

            // Show controls and reset timer (v5.8.0)
            if (!controlsVisible) {
                showControls();
            } else {
                clearTimeout(controlHideTimer);
                controlHideTimer = setTimeout(hideControls, 3000);
            }
        }
    }

    function prevPhoto() {
        if (currentPhotoIndex > 0) {
            currentPhotoIndex--;
            updateLightboxImage();

            // Show controls and reset timer (v5.8.0)
            if (!controlsVisible) {
                showControls();
            } else {
                clearTimeout(controlHideTimer);
                controlHideTimer = setTimeout(hideControls, 3000);
            }
        }
    }

    function updateRotateHint() {
        if (!rotateHint) return;

        // Show hint only in portrait mode
        const isPortrait = window.innerHeight > window.innerWidth;
        if (isPortrait) {
            rotateHint.style.cssText = 'position: fixed !important; bottom: 80px !important; left: 50% !important; transform: translateX(-50%) !important; background: rgba(0, 0, 0, 0.85) !important; color: white !important; padding: 12px 20px !important; border-radius: 25px !important; font-size: 14px !important; font-weight: 500 !important; z-index: 1000001 !important; display: flex !important; align-items: center !important; gap: 10px !important; animation: fadeIn 0.3s ease, pulse 2s ease-in-out infinite !important;';
        } else {
            rotateHint.style.display = 'none';
        }
    }

    // Event listeners
    if (lightboxClose) {
        lightboxClose.addEventListener('click', closeLightbox);
    }

    if (lightboxPrev) {
        lightboxPrev.addEventListener('click', prevPhoto);
    }

    if (lightboxNext) {
        lightboxNext.addEventListener('click', nextPhoto);
    }

    // Close on overlay click
    const overlay = lightbox.querySelector('.mld-lightbox-overlay');
    if (overlay) {
        overlay.addEventListener('click', closeLightbox);
    }

    // Touch events for zoom and pan
    imageContainer.addEventListener('touchstart', function(e) {
        if (e.touches.length === 2) {
            // Pinch zoom
            const touch1 = e.touches[0];
            const touch2 = e.touches[1];
            startDistance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
            );
            startScale = scale;
            isPanning = false;
        } else if (e.touches.length === 1) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchStartTranslateX = translateX;
            touchStartTranslateY = translateY;
            isPanning = scale > 1;
            isSwiping = false;

            // Track tap for control toggle (v5.8.0)
            tapStartTime = Date.now();
            tapStartPos = { x: touchStartX, y: touchStartY };
        }
    }, { passive: true });

    imageContainer.addEventListener('touchmove', function(e) {
        if (e.touches.length === 2) {
            // Pinch zoom
            e.preventDefault();
            const touch1 = e.touches[0];
            const touch2 = e.touches[1];
            const distance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
            );
            scale = Math.max(1, Math.min(4, startScale * (distance / startDistance)));
            updateTransform();
        } else if (e.touches.length === 1) {
            if (scale > 1 && isPanning) {
                // Pan when zoomed
                e.preventDefault();
                const touchMoveX = e.touches[0].clientX;
                const touchMoveY = e.touches[0].clientY;
                const deltaX = touchMoveX - touchStartX;
                const deltaY = touchMoveY - touchStartY;
                translateX = touchStartTranslateX + deltaX;
                translateY = touchStartTranslateY + deltaY;
                updateTransform();
            } else if (scale === 1) {
                // Navigation swipe
                const touchMoveX = e.touches[0].clientX;
                const diffX = touchMoveX - touchStartX;
                if (Math.abs(diffX) > 10) {
                    isSwiping = true;
                }
            }
        }
    }, { passive: false });

    imageContainer.addEventListener('touchend', function(e) {
        if (e.touches.length === 0 && scale === 1 && isSwiping) {
            const touchEndX = e.changedTouches[0].clientX;
            const diffX = touchEndX - touchStartX;

            if (Math.abs(diffX) > 50) {
                if (diffX > 0) {
                    // Swipe right - previous
                    prevPhoto();
                } else {
                    // Swipe left - next
                    nextPhoto();
                }
            }
        }
        isPanning = false;
        isSwiping = false;
    }, { passive: true });

    // Double tap to zoom
    imageContainer.addEventListener('touchend', function(e) {
        const currentTime = new Date().getTime();
        const tapLength = currentTime - lastTap;

        if (tapLength < 300 && tapLength > 0) {
            // Double tap
            if (scale > 1) {
                scale = 1;
                translateX = 0;
                translateY = 0;
            } else {
                scale = 2;
            }
            updateTransform();
        }
        lastTap = currentTime;
    }, { passive: true });

    // Single tap to toggle controls (v5.8.0)
    imageContainer.addEventListener('touchend', function(e) {
        if (e.touches.length > 0 || isPanning || isSwiping) return;

        const touchEndX = e.changedTouches[0].clientX;
        const touchEndY = e.changedTouches[0].clientY;
        const tapDuration = Date.now() - tapStartTime;
        const tapDistance = Math.hypot(
            touchEndX - tapStartPos.x,
            touchEndY - tapStartPos.y
        );

        // Single tap: quick (< 200ms) and minimal movement (< 10px)
        if (tapDuration < 200 && tapDistance < 10) {
            // Wait 300ms to ensure it's not a double-tap
            setTimeout(function() {
                const timeSinceLastTap = Date.now() - lastTap;
                // Only toggle if more than 300ms since last tap (not a double-tap)
                if (timeSinceLastTap > 300) {
                    toggleControls();
                }
            }, 310);
        }
    }, { passive: true });

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (lightbox.style.display !== 'none') {
            if (e.key === 'Escape') {
                closeLightbox();
            } else if (e.key === 'ArrowLeft') {
                prevPhoto();
            } else if (e.key === 'ArrowRight') {
                nextPhoto();
            }
        }
    });

    // Orientation change - update rotation hint
    window.addEventListener('orientationchange', function() {
        setTimeout(updateRotateHint, 100);
    });

    window.addEventListener('resize', updateRotateHint);

    // Attach click handlers to photo items
    setTimeout(function() {
        const photoItems = document.querySelectorAll('.mld-photo-item');
        console.log('[MLD Lightbox] Found', photoItems.length, 'photo items');

        photoItems.forEach(function(item) {
            // Skip YouTube previews
            if (item.classList.contains('mld-youtube-preview')) return;

            item.addEventListener('click', function(e) {
                e.preventDefault();
                const photoIndex = parseInt(item.getAttribute('data-index')) || 0;
                console.log('[MLD Lightbox] Opening lightbox for photo', photoIndex);
                openLightbox(photoIndex);
            });

            item.style.cursor = 'pointer';
        });
    }, 100);

    // Expose openLightbox globally for external access
    window.mldOpenLightbox = openLightbox;

    console.log('[MLD Lightbox] Initialized with', photos.length, 'photos');
})();

// Quick Nav System (v5.9.0)
(function() {
    'use strict';

    const quickNavBtn = document.getElementById('quickNavBtn');
    const quickNavSheet = document.getElementById('quickNavSheet');
    const navSheetClose = document.getElementById('navSheetClose');
    const navOverlay = quickNavSheet ? quickNavSheet.querySelector('.mld-nav-sheet-overlay') : null;
    const navLinks = document.querySelectorAll('.mld-nav-link');

    if (!quickNavBtn || !quickNavSheet) {
        console.error('[MLD Quick Nav] Required elements not found');
        return;
    }

    // Hide navigation links for sections that don't exist
    function initializeNavLinks() {
        navLinks.forEach(link => {
            const sectionId = link.getAttribute('data-section');
            const section = document.getElementById(sectionId);

            if (!section) {
                link.classList.add('hidden');
                console.log('[MLD Quick Nav] Hiding link for missing section:', sectionId);
            }
        });
    }

    // Open bottom sheet
    function openSheet() {
        quickNavSheet.classList.add('active');
        document.body.style.overflow = 'hidden';
        console.log('[MLD Quick Nav] Sheet opened');
    }

    // Close bottom sheet
    function closeSheet() {
        quickNavSheet.classList.remove('active');
        document.body.style.overflow = '';
        console.log('[MLD Quick Nav] Sheet closed');
    }

    // Smooth scroll to section
    function scrollToSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            // Close sheet first
            closeSheet();

            // Wait for sheet animation to complete, then scroll
            setTimeout(() => {
                const yOffset = -20; // 20px padding from top
                const y = section.getBoundingClientRect().top + window.pageYOffset + yOffset;

                window.scrollTo({
                    top: y,
                    behavior: 'smooth'
                });

                console.log('[MLD Quick Nav] Scrolled to section:', sectionId);
            }, 300);
        }
    }

    // Event listeners
    quickNavBtn.addEventListener('click', openSheet);

    if (navSheetClose) {
        navSheetClose.addEventListener('click', closeSheet);
    }

    if (navOverlay) {
        navOverlay.addEventListener('click', closeSheet);
    }

    // Handle nav link clicks
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const sectionId = this.getAttribute('data-section');
            scrollToSection(sectionId);
        });
    });

    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && quickNavSheet.classList.contains('active')) {
            closeSheet();
        }
    });

    // Initialize
    initializeNavLinks();
    console.log('[MLD Quick Nav] Initialized with', navLinks.length - document.querySelectorAll('.mld-nav-link.hidden').length, 'visible sections');
})();
</script>

<!-- Navigation Drawer Overlay - v6.25.2 -->
<div id="mld-nav-overlay" class="mld-nav-overlay" aria-hidden="true"></div>

<!-- Navigation Drawer - v6.25.2 -->
<aside id="mld-nav-drawer" class="mld-nav-drawer" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php esc_attr_e('Navigation menu', 'mls-listings-display'); ?>">
    <div class="mld-nav-drawer__header">
        <div class="mld-nav-drawer__logo">
            <?php
            // Try to get custom logo, fallback to site title
            if (function_exists('the_custom_logo') && has_custom_logo()) {
                the_custom_logo();
            } else {
                echo '<span class="mld-nav-drawer__site-title">' . esc_html(get_bloginfo('name')) . '</span>';
            }
            ?>
        </div>
        <button type="button" class="mld-nav-drawer__close" aria-label="<?php esc_attr_e('Close navigation menu', 'mls-listings-display'); ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>
    </div>

    <nav class="mld-nav-drawer__nav">
        <?php
        // Output primary menu if it exists
        if (has_nav_menu('primary')) {
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'container'      => false,
                'menu_class'     => 'mld-nav-drawer__menu',
                'fallback_cb'    => 'mld_nav_drawer_fallback_menu',
                'depth'          => 2,
            ));
        } elseif (has_nav_menu('main-menu')) {
            // Try alternate menu location
            wp_nav_menu(array(
                'theme_location' => 'main-menu',
                'container'      => false,
                'menu_class'     => 'mld-nav-drawer__menu',
                'fallback_cb'    => 'mld_nav_drawer_fallback_menu',
                'depth'          => 2,
            ));
        } else {
            // Fallback menu
            mld_nav_drawer_fallback_menu();
        }
        ?>
    </nav>

    <!-- User Menu Section - v6.36.10 -->
    <div class="mld-nav-drawer__user">
        <?php if (is_user_logged_in()) :
            $drawer_user = wp_get_current_user();
            // Get avatar URL - try theme function first, fallback to WordPress
            if (function_exists('bne_get_user_avatar_url')) {
                $drawer_avatar_url = bne_get_user_avatar_url($drawer_user->ID, 48);
            } else {
                $drawer_avatar_url = get_avatar_url($drawer_user->ID, array('size' => 48));
            }
            $drawer_display_name = $drawer_user->display_name ?: $drawer_user->user_login;
        ?>
            <div class="mld-nav-drawer__user-header">
                <img src="<?php echo esc_url($drawer_avatar_url); ?>"
                     alt="<?php echo esc_attr($drawer_display_name); ?>"
                     class="mld-nav-drawer__user-avatar">
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php echo esc_html($drawer_display_name); ?></span>
                    <span class="mld-nav-drawer__user-email"><?php echo esc_html($drawer_user->user_email); ?></span>
                </div>
            </div>
            <nav class="mld-nav-drawer__user-nav">
                <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    <span><?php esc_html_e('My Dashboard', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-favorites/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                    <span><?php esc_html_e('Favorites', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-saved-searches/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    <span><?php esc_html_e('Saved Searches', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/edit-profile/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span><?php esc_html_e('Edit Profile', 'mls-listings-display'); ?></span>
                </a>
                <?php if (current_user_can('manage_options')) : ?>
                <a href="<?php echo esc_url(admin_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span><?php esc_html_e('Admin', 'mls-listings-display'); ?></span>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span><?php esc_html_e('Log Out', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php else : ?>
            <div class="mld-nav-drawer__user-header mld-nav-drawer__user-header--guest">
                <div class="mld-nav-drawer__user-avatar mld-nav-drawer__user-avatar--guest">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php esc_html_e('Welcome, Guest', 'mls-listings-display'); ?></span>
                </div>
            </div>
            <nav class="mld-nav-drawer__user-nav">
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                        <polyline points="10 17 15 12 10 7"></polyline>
                        <line x1="15" y1="12" x2="3" y2="12"></line>
                    </svg>
                    <span><?php esc_html_e('Log In', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="20" y1="8" x2="20" y2="14"></line>
                        <line x1="23" y1="11" x2="17" y2="11"></line>
                    </svg>
                    <span><?php esc_html_e('Create Account', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php endif; ?>
    </div>

    <?php
    // Get phone number from MLD settings or theme customizer
    $phone_number = '';
    if (class_exists('MLD_Settings')) {
        $phone_number = MLD_Settings::get('agent_phone', '');
    }
    if (empty($phone_number)) {
        $phone_number = get_theme_mod('phone_number', '');
    }

    if (!empty($phone_number)):
    ?>
    <div class="mld-nav-drawer__footer">
        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone_number)); ?>" class="mld-nav-drawer__phone">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
            </svg>
            <span><?php echo esc_html($phone_number); ?></span>
        </a>
    </div>
    <?php endif; ?>
</aside>

<?php wp_footer(); ?>
</body>
</html>