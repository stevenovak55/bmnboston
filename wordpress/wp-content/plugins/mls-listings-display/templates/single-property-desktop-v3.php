<?php
/**
 * Desktop Property Details Template V3
 * Modern homes.com-inspired layout with full-width gallery and streamlined design
 * 
 * @version 3.1.1 - Removed Contact Agent sidebar
 */

// Get MLS number
$mls_number = get_query_var('mls_number');
if (!$mls_number) {
    wp_die('Property not found', 404);
}

// Initial MLS number from URL

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

// Get comprehensive listing data
$listing = MLD_Query::get_listing_details($mls_number);
if (!$listing) {
    wp_die('Property not found', 404);
}

// Track this property view (v6.57.0)
do_action('mld_property_viewed', $mls_number, $listing);

// Check if current user is an agent (for Share with Client button)
$is_agent = false;
$agent_clients = [];
if (is_user_logged_in() && class_exists('MLD_User_Type_Manager')) {
    $current_user_id = get_current_user_id();
    $is_agent = MLD_User_Type_Manager::is_agent($current_user_id);

    // If agent, get their clients for the share modal
    if ($is_agent && class_exists('MLD_Agent_Client_Manager')) {
        $agent_clients = MLD_Agent_Client_Manager::get_agent_clients($current_user_id);
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

// MLS number validated after query

// Prepare all data (same as V2)
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
$virtual_tours = MLD_Virtual_Tour_Utils::process_listing_tours($listing);
$virtual_tours = MLD_Virtual_Tour_Utils::sort_tours($virtual_tours);

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
$status = $listing['standard_status'] ?? '';
// Use the new calculation method that checks status and uses appropriate date fields
$days_on_market = MLD_Utils::calculate_days_on_market($listing);
// Debug: log days on market
// Days on market calculated

// Agent/Office info
$agent_name = $listing['ListAgentFullName'] ?? '';
$agent_phone = $listing['ListAgentDirectPhone'] ?? $listing['ListAgentPhone'] ?? '';
$agent_email = $listing['ListAgentEmail'] ?? '';
$office_name = $listing['ListOfficeName'] ?? '';

// Financial
$tax_amount = $listing['tax_annual_amount'] ?? $listing['TaxAnnualAmount'] ?? null;
$tax_year = $listing['tax_year'] ?? $listing['TaxYear'] ?? null;
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

// Calculate estimated monthly payment (rough estimate)
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
// Only show price drop for active listings
if (strtolower($status) !== 'closed' && !empty($listing['original_list_price']) && $listing['original_list_price'] > $listing['list_price']) {
    $price_drop = true;
    $price_drop_amount = $listing['original_list_price'] - $listing['list_price'];
}

// Check if new listing (within 7 days)
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
    // Original list price vs sale price
    $original_price = $listing['original_list_price'] ?? $listing['list_price'];
    if ($original_price) {
        $sold_stats['original_price'] = $original_price;
        $sold_stats['sale_price'] = $listing['close_price'];
        $sold_stats['price_difference'] = $listing['close_price'] - $original_price;
        $sold_stats['price_percentage'] = (($listing['close_price'] - $original_price) / $original_price) * 100;
        
        // Days on market - use the new calculation method
        $calculated_dom = MLD_Utils::calculate_days_on_market($listing);
        if ($calculated_dom !== null && !is_string($calculated_dom)) {
            $sold_stats['days_on_market'] = $calculated_dom;
        }
        
        // Price per sq ft comparison
        if ($sqft && is_numeric(str_replace(',', '', $sqft))) {
            $sqft_numeric = (int)str_replace(',', '', $sqft);
            $sold_stats['price_per_sqft'] = round($listing['close_price'] / $sqft_numeric);
            $sold_stats['original_price_per_sqft'] = round($original_price / $sqft_numeric);
        }
        
        // List to sale ratio
        $sold_stats['list_to_sale_ratio'] = ($listing['close_price'] / $original_price) * 100;
    }
}

// SEO handled by MLD_SEO class
get_header();
?>

<div class="mld-v3-property">
    <!-- Hero Gallery Section -->
    <section class="mld-v3-hero-gallery">
        <?php if (!empty($photos)): ?>
            <div class="mld-v3-gallery-container">
                <!-- Main Image Display -->
                <div class="mld-v3-gallery-main" id="v3GalleryMain">
                    <?php foreach ($photos as $index => $photo):
                        // Generate optimized image with WebP support and progressive loading
                        $image_options = [
                            'width' => 1200,
                            'height' => 800,
                            'loading' => $index === 0 ? 'eager' : 'lazy',
                            'class' => 'mld-v3-gallery-image ' . ($index === 0 ? 'active' : ''),
                            'quality' => 90,
                            'responsive' => true,
                            'webp' => true
                        ];

                        // Add progressive loading container for lazy images
                        if ($index > 0): ?>
                            <div class="mld-progressive-image" data-index="<?php echo $index; ?>">
                                <?php echo MLD_Image_Optimizer::get_optimized_image_tag(
                                    $photo['MediaURL'],
                                    $address . ' - Photo ' . ($index + 1),
                                    $image_options
                                ); ?>
                            </div>
                        <?php else:
                            // First image loads immediately, no progressive container needed
                            echo MLD_Image_Optimizer::get_optimized_image_tag(
                                $photo['MediaURL'],
                                $address . ' - Photo ' . ($index + 1),
                                $image_options
                            );
                        endif;
                    endforeach; ?>
                    
                    <!-- Map View Container -->
                    <div class="mld-v3-gallery-map" id="v3GalleryMap"></div>
                    
                    <!-- Street View Container -->
                    <div class="mld-v3-gallery-streetview" id="v3GalleryStreetView"></div>
                    
                    <!-- Virtual Tour Containers -->
                    <?php if (!empty($virtual_tours)): ?>
                        <?php foreach ($virtual_tours as $index => $tour): ?>
                            <?php if ($index < 3 && MLD_Virtual_Tour_Utils::validate_tour_url($tour['url'])): ?>
                                <div class="mld-v3-gallery-tour" 
                                     id="v3GalleryTour<?php echo $index; ?>"
                                     data-tour-type="<?php echo esc_attr($tour['type']); ?>"
                                     data-embed-url="<?php echo esc_url($tour['embed_url']); ?>">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                </div>

                <!-- Preview Images - Right Side -->
                <div class="mld-v3-gallery-preview">
                    <div class="mld-v3-preview-slot" id="v3PreviewSlot1" data-preview="1">
                        <?php if (count($photos) > 1): ?>
                            <?php foreach ($photos as $index => $photo): ?>
                                <img src="<?php echo esc_url($photo['MediaURL']); ?>"
                                     alt="Preview <?php echo ($index + 1); ?>"
                                     class="preview-<?php echo $index; ?>"
                                     loading="lazy">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="mld-v3-preview-slot" id="v3PreviewSlot2" data-preview="2">
                        <?php if (count($photos) > 2): ?>
                            <?php foreach ($photos as $index => $photo): ?>
                                <img src="<?php echo esc_url($photo['MediaURL']); ?>"
                                     alt="Preview <?php echo ($index + 1); ?>"
                                     class="preview-<?php echo $index; ?>"
                                     loading="lazy">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Gallery Controls Overlay -->
            <div class="mld-v3-gallery-overlay">
                        <button class="mld-v3-gallery-nav prev" id="v3GalleryPrev">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                        </button>
                        <button class="mld-v3-gallery-nav next" id="v3GalleryNext">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </button>
                        
                        <div class="mld-v3-gallery-actions">
                            <button class="mld-v3-gallery-action" id="v3ViewPhotos">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                    <circle cx="8.5" cy="8.5" r="1.5"/>
                                    <polyline points="21 15 16 10 5 21"/>
                                </svg>
                                <span><?php echo count($photos); ?> Photos</span>
                            </button>
                            
                            <?php 
                            // Generate buttons for virtual tours (up to 3)
                            if (!empty($virtual_tours)):
                                $tour_count = 0;
                                foreach ($virtual_tours as $tour):
                                    if ($tour_count >= 3) break;
                                    if (MLD_Virtual_Tour_Utils::validate_tour_url($tour['url'])):
                            ?>
                                <button class="mld-v3-gallery-action mld-v3-virtual-tour-btn" 
                                        data-tour-type="<?php echo esc_attr($tour['type']); ?>"
                                        data-tour-url="<?php echo esc_url($tour['url']); ?>"
                                        data-embed-url="<?php echo esc_url($tour['embed_url']); ?>"
                                        data-tour-index="<?php echo esc_attr($tour_count); ?>">
                                    <?php echo MLD_Virtual_Tour_Detector::get_tour_icon($tour['icon'] ?? 'tour'); ?>
                                    <span><?php echo esc_html($tour['label'] ?? 'Virtual Tour'); ?></span>
                                </button>
                            <?php 
                                        $tour_count++;
                                    endif;
                                endforeach;
                            endif;
                            ?>
                            
                            <button class="mld-v3-gallery-action" id="v3StreetView">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                                <span>Street View</span>
                            </button>
                            
                            <button class="mld-v3-gallery-action" id="v3MapView">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                                    <line x1="8" y1="2" x2="8" y2="18"/>
                                    <line x1="16" y1="6" x2="16" y2="22"/>
                                </svg>
                                <span>Map</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="mld-v3-no-photos">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <p>No photos available</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Sticky Navigation Bar -->
    <nav class="mld-v3-nav-bar" id="v3NavBar">
        <div class="mld-v3-nav-container">
            <!-- Hamburger Menu in Sticky Nav (v6.25.3) -->
            <button class="mld-v3-nav-toggle-sticky" id="mld-nav-toggle-sticky" aria-controls="mld-nav-drawer" aria-expanded="false" aria-label="Open navigation menu">
                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                </svg>
            </button>
            <div class="mld-v3-nav-links">
                <a href="#overview" class="mld-v3-nav-link active">Overview</a>
                <a href="#facts" class="mld-v3-nav-link">Facts & Features</a>
                <a href="#location" class="mld-v3-nav-link">Location</a>
                <a href="#schools" class="mld-v3-nav-link">Schools</a>
                <a href="#payment" class="mld-v3-nav-link">Payment Calculator</a>
                <a href="#history" class="mld-v3-nav-link">History</a>
                <a href="#similar-homes" class="mld-v3-nav-link">Similar Homes</a>
            </div>
            <div class="mld-v3-nav-actions">
                <button class="mld-v3-nav-action mld-v3-save-btn" data-mls="<?php echo esc_attr($mls_number); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                    <span>Save</span>
                </button>
                <button class="mld-v3-nav-action mld-v3-share-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                        <path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/>
                    </svg>
                    <span>Share</span>
                </button>
                <?php if ($is_agent && !empty($agent_clients)): ?>
                <button class="mld-v3-nav-action mld-v3-share-client-btn" id="mld-share-with-client-btn" data-listing-key="<?php echo esc_attr($listing['listing_key'] ?? ''); ?>" data-listing-id="<?php echo esc_attr($mls_number); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <span>Share with Client</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <?php
    // Display breadcrumbs
    if (function_exists('mld_output_visual_breadcrumbs')) {
        mld_output_visual_breadcrumbs($listing);
    }
    ?>

    <!-- Main Content -->
    <div class="mld-v3-content">
        <div class="mld-v3-main">
            <!-- Overview Section -->
            <section id="overview" class="mld-v3-section">
                <!-- Property Header -->
                <div class="mld-v3-property-header" data-url="<?php echo esc_attr(home_url('/property/' . $mls_number . '/')); ?>">
                    <div class="mld-v3-header-main">
                        <h1 class="mld-v3-address"><?php echo esc_html($address); ?></h1>
                        <div class="mld-v3-location">
                            <?php echo esc_html($city); ?>, <?php echo esc_html($state); ?> <?php echo esc_html($postal_code); ?>
                            <?php if ($neighborhood): ?>
                                <span class="mld-v3-neighborhood">• <?php echo esc_html($neighborhood); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mld-v3-header-price">
                        <div class="mld-v3-price"><?php echo esc_html($price); ?></div>
                        <?php if ($monthly_payment > 0): ?>
                            <div class="mld-v3-monthly">
                                Est. <?php echo '$' . number_format($monthly_payment); ?>/month
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Key Stats Bar -->
                <div class="mld-v3-stats-bar">
                    <?php if ($beds): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value"><?php echo $beds; ?></div>
                            <div class="mld-v3-stat-label">Beds</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($baths): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value"><?php echo $baths; ?></div>
                            <div class="mld-v3-stat-label">Baths</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($sqft): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value"><?php echo $sqft; ?></div>
                            <div class="mld-v3-stat-label">Sq Ft</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($display_price && $sqft): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value">$<?php echo number_format($display_price / $listing['living_area'], 0); ?></div>
                            <div class="mld-v3-stat-label">Price/Sq Ft</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($lot_size): ?>
                        <div class="mld-v3-stat">
                            <?php if ($listing['lot_size_acres']): ?>
                                <div class="mld-v3-stat-value"><?php echo number_format($listing['lot_size_acres'], 2); ?></div>
                                <div class="mld-v3-stat-label">Acres</div>
                            <?php else: ?>
                                <div class="mld-v3-stat-value"><?php echo number_format($listing['lot_size_square_feet']); ?></div>
                                <div class="mld-v3-stat-label">Lot Sq Ft</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($year_built): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value"><?php echo $year_built; ?></div>
                            <div class="mld-v3-stat-label">Year Built</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($property_sub_type): ?>
                    <div class="mld-v3-stat">
                        <div class="mld-v3-stat-value">
                            <?php
                            // Get property type URL
                            $listing_type = (strpos(strtolower($property_type ?? ''), 'lease') !== false || strpos(strtolower($property_type ?? ''), 'rental') !== false) ? 'rent' : 'sale';
                            $type_url = MLD_Property_Type_Pages::get_property_type_url($property_type, $property_sub_type, $listing_type);

                            if ($type_url):
                            ?>
                                <a href="<?php echo esc_url($type_url); ?>" class="mld-property-type-link" style="color: inherit; text-decoration: none;">
                                    <?php echo esc_html($property_sub_type); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($property_sub_type); ?>
                            <?php endif; ?>
                        </div>
                        <div class="mld-v3-stat-label">Type</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($days_on_market !== null): ?>
                        <div class="mld-v3-stat">
                            <div class="mld-v3-stat-value"><?php echo is_numeric($days_on_market) ? $days_on_market : esc_html($days_on_market); ?></div>
                            <div class="mld-v3-stat-label"><?php echo is_numeric($days_on_market) ? 'Days on Market' : 'Time on Market'; ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Status Bar -->
                <div class="mld-v3-status-bar">
                    <span class="mld-v3-status <?php echo sanitize_html_class(strtolower(str_replace(' ', '-', $status))); ?>">
                        <?php echo esc_html($status); ?>
                    </span>
                    
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
                    
                    <span class="mld-v3-listing-info">
                        Listed <?php echo date('M j, Y', strtotime($listing['original_entry_timestamp'] ?? $listing['listing_contract_date'] ?? 'now')); ?>
                        • <span class="mld-v3-mls-number" data-mls="<?php echo esc_attr($mls_number); ?>" title="Click to copy MLS#">
                            MLS# <?php echo esc_html($mls_number); ?>
                            <svg class="mld-v3-copy-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <span class="mld-v3-copy-feedback">Copied!</span>
                        </span>
                    </span>
                </div>

                <!-- Primary CTA Actions -->
                <div class="mld-v3-cta-actions">
                    <button class="mld-v3-cta-btn mld-v3-cta-primary mld-schedule-tour">
                        <svg class="mld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                            <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
                        </svg>
                        Schedule Tour
                    </button>
                    <button class="mld-v3-cta-btn mld-v3-cta-secondary mld-contact-agent">
                        <svg class="mld-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                        Contact Agent
                    </button>
                </div>

                <!-- Description -->
                <?php if (!empty($listing['public_remarks'])): ?>
                    <div class="mld-v3-description">
                        <h2>About this home</h2>
                        <p><?php echo nl2br(esc_html($listing['public_remarks'])); ?></p>
                    </div>
                <?php endif; ?>

                <?php
                // Property-specific iOS app download prompt
                do_action('mld_after_property_description', $mls_number, $listing);
                ?>

                <!-- Property Overview (Moved from Facts) -->
                <div class="mld-v3-property-overview">
                    <h3>Property Overview</h3>
                    <div class="mld-v3-overview-grid">
                        <?php
                        $overview_fields = [
                            'property_sub_type' => ['label' => 'Property Type'],
                            'property_type' => ['label' => 'Listing Type', 'translate' => true],
                            'year_built' => ['label' => 'Year Built'],
                            'bedrooms_total' => ['label' => 'Bedrooms'],
                            'bathrooms_full' => ['label' => 'Full Bathrooms'],
                            'bathrooms_half' => ['label' => 'Half Bathrooms'],
                            'bathrooms_total' => ['label' => 'Total Bathrooms'],
                            'living_area' => ['label' => 'Living Area', 'suffix' => ' sq ft'],
                            'rooms_total' => ['label' => 'Total Rooms'],
                            'stories_total' => ['label' => 'Stories'],
                            'levels' => ['label' => 'Levels'],
                            'entry_level' => ['label' => 'Entry Level'],
                            'main_level_bedrooms' => ['label' => 'Main Level Bedrooms'],
                            'beds_possible' => ['label' => 'Bedrooms Possible'],
                        ];

                        foreach ($overview_fields as $field => $config):
                            $value = $listing[$field] ?? null;
                            $formatted_value = format_array_field($value);
                            if (!empty($formatted_value)):
                        ?>
                        <div class="mld-v3-overview-item">
                            <div class="mld-v3-overview-label"><?php echo esc_html($config['label']); ?></div>
                            <div class="mld-v3-overview-value">
                                <?php
                                if ($field === 'living_area' && $value) {
                                    echo number_format($value) . ($config['suffix'] ?? '');
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
                                } else {
                                    echo esc_html($formatted_value);
                                }
                                ?>
                            </div>
                        </div>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Admin Only Section (All Info Combined at Top) -->
                <?php if ($is_admin): ?>
                <div class="mld-v3-facts-group mld-v3-admin-only mld-v3-admin-section" id="adminSection">
                    <div class="mld-v3-admin-header">
                        <h3>Admin Information (Private)</h3>
                        <button class="mld-v3-admin-toggle" onclick="toggleAdminSection()" aria-label="Toggle admin section">
                            <svg class="mld-v3-admin-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>
                    <div class="mld-v3-admin-notice">This section is only visible to administrators and is not shown to public visitors.</div>
                    <div class="mld-v3-admin-content">
                        <div class="mld-v3-facts-grid">
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
                            <div class="mld-v3-fact-item <?php echo isset($config['type']) && $config['type'] === 'text' ? 'mld-v3-fact-item-full' : ''; ?>">
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
                            <?php 
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>
                </div>
                
                <script>
                function toggleAdminSection() {
                    const section = document.getElementById('adminSection');
                    const isMinimized = section.classList.contains('minimized');
                    
                    if (isMinimized) {
                        section.classList.remove('minimized');
                        localStorage.setItem('mld_admin_section_minimized', 'false');
                    } else {
                        section.classList.add('minimized');
                        localStorage.setItem('mld_admin_section_minimized', 'true');
                    }
                }
                
                // Check saved state on page load
                document.addEventListener('DOMContentLoaded', function() {
                    const isMinimized = localStorage.getItem('mld_admin_section_minimized') === 'true';
                    if (isMinimized) {
                        document.getElementById('adminSection').classList.add('minimized');
                    }
                });
                </script>
                <?php endif; ?>

                <!-- Open Houses -->
                <?php 
                $open_houses = $listing['OpenHouseData'] ?? [];
                
                // Debug: Check what we're getting
                // Open house data retrieved
                
                // Handle case where OpenHouseData might be a JSON string
                if (is_string($open_houses)) {
                    $open_houses = json_decode($open_houses, true) ?? [];
                }
                
                if (!empty($open_houses)): 
                ?>
                <div class="mld-v3-open-houses">
                    <h2>Open House Schedule</h2>
                    <div class="mld-v3-open-house-list">
                        <?php foreach ($open_houses as $oh): 
                            // Create DateTime objects assuming the times are in UTC
                            $start = new DateTime($oh['OpenHouseStartTime'], new DateTimeZone('UTC'));
                            $end = new DateTime($oh['OpenHouseEndTime'], new DateTimeZone('UTC'));
                            
                            // Convert to Eastern Time
                            $eastern = new DateTimeZone('America/New_York');
                            $start->setTimezone($eastern);
                            $end->setTimezone($eastern);
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

            <!-- Sold Property Statistics (Only for Closed Properties) -->
            <?php if (!empty($sold_stats)): ?>
            <section id="sold-stats" class="mld-v3-section mld-v3-sold-stats">
                <h2>Sale Statistics</h2>
                
                <div class="mld-v3-stats-grid">
                    <!-- Primary Stats -->
                    <div class="mld-v3-stat-card primary">
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
                    <div class="mld-v3-stat-card">
                        <div class="mld-v3-stat-header">Performance Metrics</div>
                        <div class="mld-v3-stat-metric">
                            <div class="mld-v3-metric-value"><?php echo number_format($sold_stats['list_to_sale_ratio'], 1); ?>%</div>
                            <div class="mld-v3-metric-label">List to Sale Ratio</div>
                            <div class="mld-v3-metric-description">
                                <?php if ($sold_stats['list_to_sale_ratio'] > 100): ?>
                                    Sold above asking price
                                <?php elseif ($sold_stats['list_to_sale_ratio'] == 100): ?>
                                    Sold at asking price
                                <?php else: ?>
                                    Sold below asking price
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($sold_stats['days_on_market'])): ?>
                        <div class="mld-v3-stat-metric">
                            <div class="mld-v3-metric-value"><?php echo esc_html($sold_stats['days_on_market']); ?></div>
                            <div class="mld-v3-metric-label">Days on Market</div>
                            <div class="mld-v3-metric-description">
                                <?php 
                                if ($sold_stats['days_on_market'] < 7) echo 'Very fast sale';
                                elseif ($sold_stats['days_on_market'] < 30) echo 'Quick sale';
                                elseif ($sold_stats['days_on_market'] < 60) echo 'Average time';
                                else echo 'Extended listing';
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Price Analysis -->
                    <?php if (!empty($sold_stats['price_per_sqft'])): ?>
                    <div class="mld-v3-stat-card">
                        <div class="mld-v3-stat-header">Price per Square Foot Analysis</div>
                        <div class="mld-v3-stat-row">
                            <span class="mld-v3-stat-label">Original $/sq ft:</span>
                            <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['original_price_per_sqft']); ?></span>
                        </div>
                        <div class="mld-v3-stat-row highlight">
                            <span class="mld-v3-stat-label">Sale $/sq ft:</span>
                            <span class="mld-v3-stat-value">$<?php echo number_format($sold_stats['price_per_sqft']); ?></span>
                        </div>
                        <div class="mld-v3-stat-note">
                            This property sold for <strong>$<?php echo number_format($sold_stats['price_per_sqft']); ?></strong> per square foot
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Sale Summary -->
                    <div class="mld-v3-stat-card summary">
                        <div class="mld-v3-stat-header">Sale Summary</div>
                        <div class="mld-v3-stat-summary">
                            <?php if ($sold_stats['price_percentage'] > 0): ?>
                                <p class="positive">This property sold for <strong><?php echo number_format(abs($sold_stats['price_percentage']), 1); ?>%</strong> above the original asking price, indicating strong buyer demand.</p>
                            <?php elseif ($sold_stats['price_percentage'] < 0): ?>
                                <p class="negative">This property sold for <strong><?php echo number_format(abs($sold_stats['price_percentage']), 1); ?>%</strong> below the original asking price.</p>
                            <?php else: ?>
                                <p class="neutral">This property sold exactly at the asking price.</p>
                            <?php endif; ?>
                            
                            <?php if (!empty($sold_stats['days_on_market'])): ?>
                                <?php if ($sold_stats['days_on_market'] < 30): ?>
                                    <p>With only <strong><?php echo esc_html($sold_stats['days_on_market']); ?> days</strong> on market, this was a quick sale compared to typical market conditions.</p>
                                <?php elseif ($sold_stats['days_on_market'] > 90): ?>
                                    <p>At <strong><?php echo esc_html($sold_stats['days_on_market']); ?> days</strong> on market, this property took longer than average to sell.</p>
                                <?php else: ?>
                                    <p>The property spent <strong><?php echo esc_html($sold_stats['days_on_market']); ?> days</strong> on market before selling.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Nearby Sales Comparison -->
                <?php
                // Get nearby sold properties for comparison
                $nearby_sales = MLD_Query::get_nearby_sold_properties(
                    $lat, 
                    $lng, 
                    0.5, // 0.5 mile radius
                    $listing['property_sub_type'] ?? $listing['property_type'],
                    90 // Last 90 days
                );
                
                if (!empty($nearby_sales) && count($nearby_sales) > 1): // More than just this property
                    $price_per_sqft_values = array();
                    $sale_prices = array();
                    
                    foreach ($nearby_sales as $sale) {
                        if ($sale['listing_id'] != $mls_number && !empty($sale['living_area']) && $sale['living_area'] > 0) {
                            $price_per_sqft_values[] = $sale['close_price'] / $sale['living_area'];
                            $sale_prices[] = $sale['close_price'];
                        }
                    }
                    
                    if (!empty($price_per_sqft_values)) {
                        $avg_price_per_sqft = array_sum($price_per_sqft_values) / count($price_per_sqft_values);
                        $avg_sale_price = array_sum($sale_prices) / count($sale_prices);
                        $comparison_percentage = (($sold_stats['price_per_sqft'] - $avg_price_per_sqft) / $avg_price_per_sqft) * 100;
                ?>
                <div class="mld-v3-nearby-comparison">
                    <h3>Nearby Sales Comparison</h3>
                    <p class="mld-v3-comparison-subtitle">Based on <?php echo count($price_per_sqft_values); ?> similar properties sold within 0.5 miles in the last 90 days</p>
                    
                    <div class="mld-v3-comparison-grid">
                        <div class="mld-v3-comparison-item">
                            <div class="mld-v3-comparison-label">This Property</div>
                            <div class="mld-v3-comparison-value">$<?php echo number_format($sold_stats['price_per_sqft']); ?>/sq ft</div>
                        </div>
                        <div class="mld-v3-comparison-item">
                            <div class="mld-v3-comparison-label">Area Average</div>
                            <div class="mld-v3-comparison-value">$<?php echo number_format($avg_price_per_sqft); ?>/sq ft</div>
                        </div>
                        <div class="mld-v3-comparison-item <?php echo $comparison_percentage >= 0 ? 'positive' : 'negative'; ?>">
                            <div class="mld-v3-comparison-label">Difference</div>
                            <div class="mld-v3-comparison-value">
                                <?php echo $comparison_percentage >= 0 ? '+' : ''; ?><?php echo number_format(abs($comparison_percentage), 1); ?>%
                            </div>
                        </div>
                    </div>
                    
                    <div class="mld-v3-comparison-summary">
                        <?php if ($comparison_percentage > 10): ?>
                            <p>This property sold for significantly more per square foot than similar nearby properties, suggesting premium features or condition.</p>
                        <?php elseif ($comparison_percentage > 0): ?>
                            <p>This property sold slightly above the area average for similar homes.</p>
                        <?php elseif ($comparison_percentage < -10): ?>
                            <p>This property sold below the area average, potentially representing good value for the buyer.</p>
                        <?php else: ?>
                            <p>This property sold near the area average for similar homes.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php } ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- Facts & Features Section -->
            <section id="facts" class="mld-v3-section mld-facts-v2">
                <h2>Facts & Features</h2>

                <?php
                // Include the new template component
                require_once MLD_PLUGIN_PATH . 'includes/template-facts-features-v2.php';

                // Render property highlights (tags)
                mld_render_property_highlights($listing);

                // Render the main facts grid
                mld_render_facts_grid($listing, false);
                ?>

                <!-- Old structure removed - using new Facts & Features v2 -->
                <?php if (false): // Completely disable old structure ?>
                <!-- Interior Features -->
                <div class="mld-v3-facts-category">
                    <div class="mld-v3-facts-category-header">
                        <div class="mld-v3-facts-icon">
                            <?php echo mld_get_feature_icon('interior'); ?>
                        </div>
                        <h3>Interior Features</h3>
                    </div>
                    <div class="mld-v3-facts-card-grid">
                        <?php 
                        $interior_fields = [
                            'flooring' => ['label' => 'Flooring'],
                            'heating' => ['label' => 'Heating'],
                            'heating_yn' => ['label' => 'Has Heating', 'format' => 'yn'],
                            'cooling' => ['label' => 'Cooling'],
                            'cooling_yn' => ['label' => 'Has Cooling', 'format' => 'yn'],
                            'fireplace_features' => ['label' => 'Fireplace Features'],
                            'fireplace_yn' => ['label' => 'Has Fireplace', 'format' => 'yn'],
                            'fireplaces_total' => ['label' => 'Number of Fireplaces'],
                            'appliances' => ['label' => 'Appliances'],
                            'interior_features' => ['label' => 'Interior Features'],
                            'kitchen_features' => ['label' => 'Kitchen Features'],
                            'laundry_features' => ['label' => 'Laundry Features'],
                            'basement_yn' => ['label' => 'Has Basement', 'format' => 'yn'],
                            'basement' => ['label' => 'Basement Details'],
                            'attic_yn' => ['label' => 'Has Attic', 'format' => 'yn'],
                            'window_features' => ['label' => 'Window Features'],
                            'door_features' => ['label' => 'Door Features'],
                            'insulation' => ['label' => 'Insulation'],
                            'accessibility_features' => ['label' => 'Accessibility Features'],
                        ];
                        
                        foreach ($interior_fields as $field => $config):
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
                        <div class="mld-v3-fact-card">
                            <div class="mld-v3-fact-card-icon">
                                <?php echo mld_get_icon_for_field($field); ?>
                            </div>
                            <div class="mld-v3-fact-card-content">
                                <div class="mld-v3-fact-card-value"><?php echo esc_html($formatted_value); ?></div>
                                <div class="mld-v3-fact-card-label"><?php echo esc_html($config['label']); ?></div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Exterior & Structure -->
                <div class="mld-v3-facts-group">
                    <h3>Exterior & Structure</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-item">
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
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Lot & Land -->
                <div class="mld-v3-facts-group">
                    <h3>Lot & Land</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-item">
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
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Parking & Garage -->
                <div class="mld-v3-facts-group">
                    <h3>Parking & Garage</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-card">
                            <div class="mld-v3-fact-card-icon">
                                <?php echo mld_get_icon_for_field($field); ?>
                            </div>
                            <div class="mld-v3-fact-card-content">
                                <div class="mld-v3-fact-card-value"><?php echo esc_html($formatted_value); ?></div>
                                <div class="mld-v3-fact-card-label"><?php echo esc_html($config['label']); ?></div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Utilities & Systems -->
                <div class="mld-v3-facts-group">
                    <h3>Utilities & Systems</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-card">
                            <div class="mld-v3-fact-card-icon">
                                <?php echo mld_get_icon_for_field($field); ?>
                            </div>
                            <div class="mld-v3-fact-card-content">
                                <div class="mld-v3-fact-card-value"><?php echo esc_html($formatted_value); ?></div>
                                <div class="mld-v3-fact-card-label"><?php echo esc_html($config['label']); ?></div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Community & HOA -->
                <div class="mld-v3-facts-group">
                    <h3>Community & HOA</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-item">
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
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Financial & Tax -->
                <div class="mld-v3-facts-group">
                    <h3>Financial & Tax Information</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-item">
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
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Additional Details -->
                <div class="mld-v3-facts-group">
                    <h3>Additional Details</h3>
                    <div class="mld-v3-facts-grid">
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
                        <div class="mld-v3-fact-card">
                            <div class="mld-v3-fact-card-icon">
                                <?php echo mld_get_icon_for_field($field); ?>
                            </div>
                            <div class="mld-v3-fact-card-content">
                                <div class="mld-v3-fact-card-value"><?php echo esc_html($formatted_value); ?></div>
                                <div class="mld-v3-fact-card-label"><?php echo esc_html($config['label']); ?></div>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>

                <!-- Room Details -->
                <?php if (!empty($rooms)): ?>
                <div class="mld-v3-facts-group">
                    <h3>Room Details</h3>
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
                                    <span>Features: <?php echo esc_html(format_array_field($room['room_features'])); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($room['room_description'])): ?>
                                    <span><?php echo esc_html($room['room_description']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; // End disabled old structure ?>

            </section>

            <!-- Room-by-Room Breakdown Section -->
            <?php if (!empty($rooms) && is_array($rooms)): ?>
            <section id="rooms" class="mld-v3-section">
                <h2>Room Details</h2>

                <?php
                // Group rooms by level
                $rooms_by_level = [];
                foreach ($rooms as $room) {
                    $level = $room['room_level'] ?? 'Other';
                    if (empty($level) || $level === '0') {
                        $level = 'Other';
                    }
                    $rooms_by_level[$level][] = $room;
                }

                // Sort levels in logical order
                $level_order = ['First', 'Second', 'Third', 'Fourth', 'Main', 'Upper', 'Lower', 'Basement', 'Other'];
                uksort($rooms_by_level, function($a, $b) use ($level_order) {
                    $pos_a = array_search($a, $level_order);
                    $pos_b = array_search($b, $level_order);
                    if ($pos_a === false) $pos_a = 999;
                    if ($pos_b === false) $pos_b = 999;
                    return $pos_a - $pos_b;
                });

                foreach ($rooms_by_level as $level => $level_rooms):
                ?>
                    <div class="mld-v3-room-level">
                        <h3><?php echo esc_html($level); ?> Floor</h3>
                        <div class="mld-v3-room-grid">
                            <?php foreach ($level_rooms as $room): ?>
                                <div class="mld-v3-room-card">
                                    <div class="mld-v3-room-type">
                                        <?php echo esc_html($room['room_type'] ?? 'Room'); ?>
                                    </div>
                                    <?php if (!empty($room['room_dimensions'])): ?>
                                        <div class="mld-v3-room-size">
                                            <?php echo esc_html($room['room_dimensions']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($room['room_features'])): ?>
                                        <div class="mld-v3-room-features">
                                            <?php echo esc_html(format_array_field($room['room_features'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <!-- Interior Details Section -->
            <?php
            $has_interior_data = !empty($listing['flooring']) ||
                                !empty($listing['appliances']) ||
                                !empty($listing['interior_features']) ||
                                !empty($listing['window_features']) ||
                                !empty($listing['basement']) ||
                                !empty($listing['laundry_features']);

            if ($has_interior_data):
            ?>
            <section id="interior" class="mld-v3-section">
                <h2>Interior Features</h2>
                <div class="mld-v3-features-grid">
                    <?php if (!empty($listing['flooring'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Flooring:</strong>
                            <span><?php echo esc_html(format_array_field($listing['flooring'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['appliances'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Appliances:</strong>
                            <span><?php echo esc_html(format_array_field($listing['appliances'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['interior_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Interior Features:</strong>
                            <span><?php echo esc_html(format_array_field($listing['interior_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['window_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Windows:</strong>
                            <span><?php echo esc_html(format_array_field($listing['window_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['basement'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Basement:</strong>
                            <span><?php echo esc_html(format_array_field($listing['basement'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['laundry_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Laundry:</strong>
                            <span><?php echo esc_html(format_array_field($listing['laundry_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['security_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Security:</strong>
                            <span><?php echo esc_html(format_array_field($listing['security_features'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Construction & Materials Section -->
            <?php
            $has_construction_data = !empty($listing['architectural_style']) ||
                                    !empty($listing['structure_type']) ||
                                    !empty($listing['construction_materials']) ||
                                    !empty($listing['roof']) ||
                                    !empty($listing['foundation_details']) ||
                                    !empty($listing['property_condition']) ||
                                    !empty($listing['year_built_effective']);

            if ($has_construction_data):
            ?>
            <section id="construction" class="mld-v3-section">
                <h2>Construction & Materials</h2>
                <div class="mld-v3-features-grid">
                    <?php
                    $architectural_style = format_array_field($listing['architectural_style']);
                    if (!empty($architectural_style)):
                    ?>
                        <div class="mld-v3-feature-item">
                            <strong>Style:</strong>
                            <span><?php echo esc_html($architectural_style); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($year_built)): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Year Built:</strong>
                            <span><?php echo esc_html($year_built); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['year_built_effective'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Effective Year (Renovated):</strong>
                            <span><?php echo esc_html($listing['year_built_effective']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['structure_type'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Structure Type:</strong>
                            <span><?php echo esc_html(format_array_field($listing['structure_type'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['construction_materials'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Exterior Materials:</strong>
                            <span><?php echo esc_html(format_array_field($listing['construction_materials'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['roof'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Roof:</strong>
                            <span><?php echo esc_html(format_array_field($listing['roof'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['foundation_details'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Foundation:</strong>
                            <span><?php echo esc_html(format_array_field($listing['foundation_details'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php
                    $property_condition = format_array_field($listing['property_condition']);
                    if (!empty($property_condition)):
                    ?>
                        <div class="mld-v3-feature-item">
                            <strong>Condition:</strong>
                            <span><?php echo esc_html($property_condition); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['stories_total'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Stories:</strong>
                            <span><?php echo esc_html($listing['stories_total']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Heating, Cooling & Utilities Section -->
            <?php
            $has_systems_data = !empty($listing['heating']) ||
                               !empty($listing['cooling']) ||
                               !empty($listing['mlspin_hot_water']) ||
                               !empty($listing['water_source']) ||
                               !empty($listing['sewer']);

            if ($has_systems_data):
            ?>
            <section id="systems" class="mld-v3-section">
                <h2>Heating, Cooling & Utilities</h2>
                <div class="mld-v3-features-grid">
                    <?php if (!empty($listing['heating'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Heating:</strong>
                            <span><?php echo esc_html(format_array_field($listing['heating'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['mlspin_heat_zones'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Heating Zones:</strong>
                            <span><?php echo esc_html($listing['mlspin_heat_zones']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['cooling'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Cooling:</strong>
                            <span><?php echo esc_html(format_array_field($listing['cooling'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['mlspin_cooling_zones'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Cooling Zones:</strong>
                            <span><?php echo esc_html($listing['mlspin_cooling_zones']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['mlspin_hot_water'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Hot Water:</strong>
                            <span><?php echo esc_html(format_array_field($listing['mlspin_hot_water'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['water_source'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Water Source:</strong>
                            <span><?php echo esc_html(format_array_field($listing['water_source'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['sewer'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Sewer:</strong>
                            <span><?php echo esc_html(format_array_field($listing['sewer'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['green_energy_efficient'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Energy Features:</strong>
                            <span><?php echo esc_html(format_array_field($listing['green_energy_efficient'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Exterior & Lot Features Section -->
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
            <section id="exterior" class="mld-v3-section">
                <h2>Exterior & Lot</h2>
                <div class="mld-v3-features-grid">
                    <?php if (!empty($listing['exterior_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Exterior Features:</strong>
                            <span><?php echo esc_html(format_array_field($listing['exterior_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['patio_and_porch_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Patio & Porch:</strong>
                            <span><?php echo esc_html(format_array_field($listing['patio_and_porch_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['fencing'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Fencing:</strong>
                            <span><?php echo esc_html(format_array_field($listing['fencing'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['other_structures'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Other Structures:</strong>
                            <span><?php echo esc_html(format_array_field($listing['other_structures'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['lot_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Lot Features:</strong>
                            <span><?php echo esc_html(format_array_field($listing['lot_features'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['frontage_length'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Road Frontage:</strong>
                            <span><?php echo esc_html($listing['frontage_length']); ?> ft</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['road_surface_type'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Road Surface:</strong>
                            <span><?php echo esc_html(format_array_field($listing['road_surface_type'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Parking & Garage Section -->
            <?php
            $has_parking_data = !empty($listing['garage_yn']) ||
                               !empty($listing['garage_spaces']) ||
                               !empty($listing['parking_total']) ||
                               !empty($listing['parking_features']);

            if ($has_parking_data):
            ?>
            <section id="parking" class="mld-v3-section">
                <h2>Parking & Garage</h2>
                <div class="mld-v3-features-grid">
                    <?php if (!empty($listing['garage_spaces'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Garage Spaces:</strong>
                            <span><?php echo esc_html($listing['garage_spaces']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['attached_garage_yn'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Garage Type:</strong>
                            <span><?php echo ($listing['attached_garage_yn'] === 'Y' || $listing['attached_garage_yn'] === '1') ? 'Attached' : 'Detached'; ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['parking_total'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Total Parking Spaces:</strong>
                            <span><?php echo esc_html($listing['parking_total']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($listing['parking_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Parking Features:</strong>
                            <span><?php echo esc_html(format_array_field($listing['parking_features'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Special Features Section (Fireplace, Pool, Waterfront, Views) -->
            <?php
            $has_fireplace = !empty($listing['fireplace_yn']) && ($listing['fireplace_yn'] === 'Y' || $listing['fireplace_yn'] === '1');
            $has_pool = !empty($listing['pool_private_yn']) && ($listing['pool_private_yn'] === 'Y' || $listing['pool_private_yn'] === '1');
            $has_waterfront = !empty($listing['waterfront_yn']) && ($listing['waterfront_yn'] === 'Y' || $listing['waterfront_yn'] === '1');
            $has_view = !empty($listing['view_yn']) && ($listing['view_yn'] === 'Y' || $listing['view_yn'] === '1');
            $has_special_features = $has_fireplace || $has_pool || $has_waterfront || $has_view;

            if ($has_special_features):
            ?>
            <section id="special-features" class="mld-v3-section">
                <h2>Special Features</h2>
                <div class="mld-v3-features-grid">
                    <?php if ($has_fireplace): ?>
                        <?php if (!empty($listing['fireplaces_total'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>Fireplaces:</strong>
                                <span><?php echo esc_html($listing['fireplaces_total']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($listing['fireplace_features'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>Fireplace Type:</strong>
                                <span><?php echo esc_html(format_array_field($listing['fireplace_features'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($has_pool): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Pool:</strong>
                            <span>Private Pool</span>
                        </div>

                        <?php if (!empty($listing['pool_features'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>Pool Features:</strong>
                                <span><?php echo esc_html(format_array_field($listing['pool_features'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($listing['spa_yn']) && ($listing['spa_yn'] === 'Y' || $listing['spa_yn'] === '1')): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Spa/Hot Tub:</strong>
                            <span>Yes</span>
                        </div>

                        <?php if (!empty($listing['spa_features'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>Spa Features:</strong>
                                <span><?php echo esc_html(format_array_field($listing['spa_features'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($has_waterfront): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Waterfront:</strong>
                            <span>Yes</span>
                        </div>

                        <?php if (!empty($listing['waterfront_features'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>Waterfront Type:</strong>
                                <span><?php echo esc_html(format_array_field($listing['waterfront_features'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($has_view): ?>
                        <div class="mld-v3-feature-item">
                            <strong>View:</strong>
                            <span>Yes</span>
                        </div>

                        <?php if (!empty($listing['view'])): ?>
                            <div class="mld-v3-feature-item">
                                <strong>View Type:</strong>
                                <span><?php echo esc_html(format_array_field($listing['view'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($listing['community_features'])): ?>
                        <div class="mld-v3-feature-item">
                            <strong>Community Amenities:</strong>
                            <span><?php echo esc_html(format_array_field($listing['community_features'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Location Section -->
            <section id="location" class="mld-v3-section">
                <h2>Location</h2>

                <!-- Map -->
                <?php if ($lat && $lng): ?>
                <div class="mld-v3-map-container" id="v3PropertyMap" 
                     data-lat="<?php echo esc_attr($lat); ?>" 
                     data-lng="<?php echo esc_attr($lng); ?>">
                    <div class="mld-v3-map-loading">Loading map...</div>
                </div>
                <?php endif; ?>

                <!-- Neighborhood Info -->
                <div class="mld-v3-neighborhood-info">
                    <h3>Neighborhood</h3>
                    <div class="mld-v3-neighborhood-details">
                        <?php if ($neighborhood): ?>
                            <p><strong><?php echo esc_html($neighborhood); ?></strong></p>
                        <?php endif; ?>
                        <p><?php echo esc_html($address); ?></p>
                        <p><?php echo esc_html($city); ?>, <?php echo esc_html($state); ?> <?php echo esc_html($postal_code); ?></p>
                    </div>
                </div>

                <!-- Walk Score -->
                <?php 
                $walk_score_enabled = class_exists('MLD_Settings') && MLD_Settings::is_walk_score_enabled();
                if ($walk_score_enabled): 
                ?>
                <div class="mld-v3-walk-score">
                    <h3>Walkability</h3>
                    <div id="v3-walk-score-container">
                        <!-- Walk Score will be loaded here via JavaScript -->
                        <div style="color: #666; font-size: 14px;">Loading Walk Score...</div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Walk Score is disabled - API key not configured -->
                <?php endif; ?>

                <!-- Nearby Places is now integrated into Walk Score section above -->
            </section>

            <!-- Enhanced Schools Section (v6.30.4 - District-based) -->
            <section id="schools" class="mld-v3-section">
                <?php
                if ($lat && $lng && class_exists('MLD_BMN_Schools_Integration')) {
                    $schools_integration = MLD_BMN_Schools_Integration::get_instance();
                    // Use district-based fetching to show ALL schools in the school district
                    $schools_data = $schools_integration->get_schools_for_district($lat, $lng);
                    if ($schools_data && (!empty($schools_data['schools']) || !empty($schools_data['district']))) {
                        echo $schools_integration->render_enhanced_schools_section($schools_data);
                    } else {
                        ?>
                        <h2>Nearby Schools</h2>
                        <p class="mld-schools-unavailable">School information not available for this location.</p>
                        <?php
                    }
                } else {
                    ?>
                    <h2>Nearby Schools</h2>
                    <p class="mld-schools-unavailable">School information not available.</p>
                    <?php
                }
                ?>
            </section>

            <!-- Payment Calculator Section -->
            <section id="payment" class="mld-v3-section">
                <h2>Monthly Payment Calculator</h2>
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
                                <input type="number" id="v3CalcPrice" value="<?php echo esc_attr($display_price ?? 0); ?>">
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

            <!-- History Section -->
            <section id="history" class="mld-v3-section">
                <h2>Property History</h2>
                
                <?php 
                // Include enhanced property history display
                include MLD_PLUGIN_PATH . 'templates/partials/property-history-enhanced.php';
                ?>
                
                <!-- Original history display (hidden by default, can be removed later) -->
                <div style="display: none;"><?php // Original code below for reference ?>
                
                <!-- Price & Status History -->
                <div class="mld-v3-history-group">
                    <h3>Price & Status History</h3>
                    <div class="mld-v3-history-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Event</th>
                                    <th>Price</th>
                                    <th>Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Build history array
                                $history_events = array();
                                $has_tracked_history = false;
                                
                                // Get tracked property history first (most accurate dates)
                                $tracked_history = MLD_Query::get_tracked_property_history($mls_number);
                                if (!empty($tracked_history)) {
                                    $has_tracked_history = true;
                                    foreach ($tracked_history as $event) {
                                        if ($event['event_type'] === 'price_change') {
                                            $history_events[] = array(
                                                'date' => strtotime($event['event_date']),
                                                'event' => 'Price Changed',
                                                'price' => $event['new_price'],
                                                'change' => $event['new_price'] - $event['old_price']
                                            );
                                        } elseif ($event['event_type'] === 'status_change') {
                                            $history_events[] = array(
                                                'date' => strtotime($event['event_date']),
                                                'event' => 'Status: ' . ucfirst(strtolower($event['new_status'])),
                                                'price' => $event['new_price'] ?: null,
                                                'change' => null
                                            );
                                        } elseif ($event['event_type'] === 'new_listing') {
                                            $history_events[] = array(
                                                'date' => strtotime($event['event_date']),
                                                'event' => 'New Listing Added',
                                                'price' => $event['new_price'],
                                                'change' => null
                                            );
                                        } elseif ($event['event_type'] === 'field_change' && in_array($event['field_name'], ['bedrooms_total', 'bathrooms_full', 'living_area'])) {
                                            $field_labels = [
                                                'bedrooms_total' => 'Bedrooms',
                                                'bathrooms_full' => 'Bathrooms',
                                                'living_area' => 'Square Footage'
                                            ];
                                            $history_events[] = array(
                                                'date' => strtotime($event['event_date']),
                                                'event' => $field_labels[$event['field_name']] . ' Updated: ' . $event['old_value'] . ' → ' . $event['new_value'],
                                                'price' => null,
                                                'change' => null
                                            );
                                        }
                                    }
                                }
                                
                                // If no tracked history, fall back to basic listing data
                                if (!$has_tracked_history) {
                                    // Current listing status - skip for closed properties as they have their own sold entry
                                    if (strtolower($status) !== 'closed') {
                                        $history_events[] = array(
                                            'date' => strtotime($listing['modification_timestamp'] ?? $listing['creation_timestamp'] ?? 'now'),
                                            'event' => 'Current Status: ' . ucfirst(strtolower($status)),
                                            'price' => $listing['list_price'],
                                            'change' => null
                                        );
                                    }
                                    
                                    // Price change if exists (but date might not be accurate)
                                    if (!empty($listing['original_list_price']) && $listing['original_list_price'] != $listing['list_price']) {
                                        $price_change = $listing['original_list_price'] - $listing['list_price'];
                                        $history_events[] = array(
                                            'date' => strtotime($listing['modification_timestamp'] ?? 'now'),
                                            'event' => 'Price Reduced (date approximate)',
                                            'price' => $listing['list_price'],
                                            'change' => -$price_change
                                        );
                                    }
                                    
                                    // Original listing
                                    $history_events[] = array(
                                        'date' => strtotime($listing['creation_timestamp'] ?? 'now'),
                                        'event' => 'Listed for Sale',
                                        'price' => $listing['original_list_price'] ?? $listing['list_price'],
                                        'change' => null
                                    );
                                    
                                    // For closed properties, add the sold event
                                    if (strtolower($status) === 'closed' && !empty($listing['close_date'])) {
                                        $history_events[] = array(
                                            'date' => strtotime($listing['close_date']),
                                            'event' => 'Sold',
                                            'price' => $listing['close_price'] ?? $listing['list_price'],
                                            'change' => null
                                        );
                                    }
                                }
                                
                                // Check for previous sales at same address
                                $previous_sales = MLD_Query::get_property_sales_history($address, $mls_number);
                                if (!empty($previous_sales)) {
                                    foreach ($previous_sales as $sale) {
                                        $history_events[] = array(
                                            'date' => strtotime($sale['close_date'] ?? $sale['mlspin_ant_sold_date'] ?? $sale['modification_timestamp']),
                                            'event' => 'Sold',
                                            'price' => $sale['close_price'] ?? $sale['list_price'] ?? 0,
                                            'change' => null
                                        );
                                        
                                        // If this sale had a listing history, add it
                                        if (!empty($sale['creation_timestamp'])) {
                                            $history_events[] = array(
                                                'date' => strtotime($sale['creation_timestamp']),
                                                'event' => 'Previously Listed',
                                                'price' => $sale['original_list_price'] ?? $sale['list_price'] ?? 0,
                                                'change' => null
                                            );
                                        }
                                    }
                                }
                                
                                // Sort by date descending
                                usort($history_events, function($a, $b) {
                                    return $b['date'] - $a['date'];
                                });
                                
                                // Display events
                                foreach ($history_events as $event):
                                ?>
                                <tr>
                                    <td><?php echo date('M j, Y', $event['date']); ?></td>
                                    <td><?php echo esc_html($event['event']); ?></td>
                                    <td>$<?php echo number_format($event['price']); ?></td>
                                    <td class="<?php echo $event['change'] < 0 ? 'mld-v3-price-change negative' : ''; ?>">
                                        <?php echo $event['change'] ? '-$' . number_format(abs($event['change'])) : '—'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Market Statistics -->
                <div class="mld-v3-history-group">
                    <h3>Market Statistics</h3>
                    <div class="mld-v3-market-stats">
                        <div class="mld-v3-stat-item">
                            <div class="mld-v3-stat-label"><?php echo is_numeric($days_on_market) ? 'Days on Market' : 'Time on Market'; ?></div>
                            <div class="mld-v3-stat-value"><?php echo is_numeric($days_on_market) ? $days_on_market . ' days' : esc_html($days_on_market); ?></div>
                        </div>
                        <?php if (!empty($listing['cumulative_days_on_market'])): ?>
                        <div class="mld-v3-stat-item">
                            <div class="mld-v3-stat-label">Total Days on Market</div>
                            <div class="mld-v3-stat-value"><?php echo $listing['cumulative_days_on_market']; ?> days</div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($listing['list_price']) && !empty($listing['original_list_price'])): ?>
                        <div class="mld-v3-stat-item">
                            <div class="mld-v3-stat-label">Price per Sq Ft</div>
                            <div class="mld-v3-stat-value">$<?php echo $sqft ? number_format($display_price / str_replace(',', '', $sqft)) : '—'; ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($price_drop): ?>
                        <div class="mld-v3-stat-item">
                            <div class="mld-v3-stat-label">Total Price Drop</div>
                            <div class="mld-v3-stat-value">-$<?php echo number_format($price_drop_amount); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tax History -->
                <div class="mld-v3-history-group">
                    <h3>Tax History</h3>
                    <div class="mld-v3-history-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Taxes</th>
                                    <th>Assessment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($tax_amount && $tax_year): ?>
                                <tr>
                                    <td><?php echo esc_html($tax_year); ?></td>
                                    <td>$<?php echo number_format($tax_amount); ?></td>
                                    <td><?php echo $listing['tax_assessed_value'] ? '$' . number_format($listing['tax_assessed_value']) : '—'; ?></td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td colspan="3" class="mld-v3-no-data">Tax history not available</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Financial Details -->
            <?php if ($tax_amount || $hoa_fee): ?>
            <section class="mld-v3-section">
                <h2>Financial Details</h2>
                <div class="mld-v3-financial-grid">
                    <?php if ($tax_amount): ?>
                    <div class="mld-v3-financial-item">
                        <div class="mld-v3-financial-label">Annual Property Tax</div>
                        <div class="mld-v3-financial-value">$<?php echo number_format($tax_amount); ?></div>
                        <?php if ($tax_year): ?>
                            <div class="mld-v3-financial-note">Tax Year <?php echo $tax_year; ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($hoa_fee): ?>
                    <div class="mld-v3-financial-item">
                        <div class="mld-v3-financial-label">HOA Fee</div>
                        <div class="mld-v3-financial-value">$<?php echo number_format($hoa_fee); ?></div>
                        <div class="mld-v3-financial-note"><?php echo esc_html($hoa_frequency ?: 'Per month'); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['tax_assessed_value']): ?>
                    <div class="mld-v3-financial-item">
                        <div class="mld-v3-financial-label">Assessed Value</div>
                        <div class="mld-v3-financial-value">$<?php echo number_format($listing['tax_assessed_value']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Listing Agent Section -->
            <section class="mld-v3-listing-agent">
                <h2>Listing Information</h2>
                <div class="mld-v3-agent-details">
                    <?php if ($agent_name): ?>
                    <div class="mld-v3-agent-item">
                        <span class="mld-v3-agent-label">Listing Agent</span>
                        <span class="mld-v3-agent-value"><?php echo esc_html($agent_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($office_name): ?>
                    <div class="mld-v3-agent-item">
                        <span class="mld-v3-agent-label">Listing Office</span>
                        <span class="mld-v3-agent-value"><?php echo esc_html($office_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['list_agent_mls_id']): ?>
                    <div class="mld-v3-agent-item">
                        <span class="mld-v3-agent-label">Agent MLS ID</span>
                        <span class="mld-v3-agent-value"><?php echo esc_html($listing['list_agent_mls_id']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($listing['list_office_mls_id']): ?>
                    <div class="mld-v3-agent-item">
                        <span class="mld-v3-agent-label">Office MLS ID</span>
                        <span class="mld-v3-agent-value"><?php echo esc_html($listing['list_office_mls_id']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($is_admin && ($agent_phone || $agent_email)): ?>
                <div class="mld-v3-agent-contact">
                    <strong>Contact Information (Admin Only):</strong>
                    <?php if ($agent_phone): ?> Phone: <?php echo esc_html($agent_phone); ?><?php endif; ?>
                    <?php if ($agent_email): ?> | Email: <?php echo esc_html($agent_email); ?><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                // MLS Disclosure Section
                $disclosure_settings = get_option('mld_disclosure_settings', []);
                $disclosure_enabled = !empty($disclosure_settings['enabled']);
                $disclosure_logo = isset($disclosure_settings['logo_url']) ? $disclosure_settings['logo_url'] : '';
                $disclosure_text = isset($disclosure_settings['disclosure_text']) ? $disclosure_settings['disclosure_text'] : '';

                if ($disclosure_enabled && ($disclosure_logo || $disclosure_text)):
                ?>
                <div class="mld-v3-disclosure">
                    <?php if ($disclosure_logo): ?>
                    <img src="<?php echo esc_url($disclosure_logo); ?>"
                         alt="MLS Logo"
                         class="mld-v3-disclosure-logo">
                    <?php endif; ?>
                    <?php if ($disclosure_text): ?>
                    <div class="mld-v3-disclosure-text">
                        <?php echo wp_kses_post($disclosure_text); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Enhanced Comparable Sales Section (Full Width) -->
    <div class="mld-v3-full-width-section">
        <section id="similar-homes" class="mld-v3-section">
            <div class="mld-v3-section-container">
                <h2>Comparable Properties</h2>
                <?php
                // Prepare property data for comparable sales display
                // Fetch user property data (road type and condition)
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
                mld_render_comparable_sales($subject_property);
                ?>
            </div>
        </section>
    </div><!-- .mld-v3-main -->

    <?php
    // Market Analytics Section (lazy-loaded)
    if (!empty($city)) {
        if (!class_exists('MLD_Analytics_Tabs')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-tabs.php';
        }
        $property_type_filter = !empty($listing['property_type']) ? $listing['property_type'] : 'all';
        echo MLD_Analytics_Tabs::render_property_section($city, $state ?? 'MA', $property_type_filter);
    }
    ?>

</div><!-- .mld-v3-content -->

    <!-- Photo Grid Modal -->
    <div class="mld-v3-modal" id="v3PhotoModal">
        <div class="mld-v3-modal-content">
            <button class="mld-v3-modal-close" id="v3ModalClose">&times;</button>
            <div class="mld-v3-photo-grid" id="v3PhotoGrid">
                <?php foreach ($photos as $index => $photo): ?>
                    <img src="<?php echo esc_url($photo['MediaURL']); ?>" 
                         alt="<?php echo esc_attr($address . ' - Photo ' . ($index + 1)); ?>"
                         class="mld-v3-grid-photo"
                         data-index="<?php echo $index; ?>"
                         loading="lazy">
                <?php endforeach; ?>
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
                            <label for="desktop_contact_first_name">First Name *</label>
                            <input type="text" id="desktop_contact_first_name" name="first_name" required>
                        </div>
                        <div class="mld-form-group">
                            <label for="desktop_contact_last_name">Last Name *</label>
                            <input type="text" id="desktop_contact_last_name" name="last_name" required>
                        </div>
                    </div>

                    <div class="mld-form-group">
                        <label for="desktop_contact_email">Email *</label>
                        <input type="email" id="desktop_contact_email" name="email" required>
                    </div>
                    <div class="mld-form-group">
                        <label for="desktop_contact_phone">Phone</label>
                        <input type="tel" id="desktop_contact_phone" name="phone">
                    </div>
                    <div class="mld-form-group">
                        <label for="desktop_contact_message">Message</label>
                        <textarea id="desktop_contact_message" name="message" rows="4" placeholder="I'm interested in this property..."></textarea>
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
                            <label for="desktop_tour_first_name">First Name *</label>
                            <input type="text" id="desktop_tour_first_name" name="first_name" required>
                        </div>
                        <div class="mld-form-group">
                            <label for="desktop_tour_last_name">Last Name *</label>
                            <input type="text" id="desktop_tour_last_name" name="last_name" required>
                        </div>
                    </div>

                    <div class="mld-form-group">
                        <label for="desktop_tour_email">Email *</label>
                        <input type="email" id="desktop_tour_email" name="email" required>
                    </div>
                    <div class="mld-form-group">
                        <label for="desktop_tour_phone">Phone *</label>
                        <input type="tel" id="desktop_tour_phone" name="phone" required>
                    </div>

                    <div class="mld-form-group">
                        <label for="desktop_tour_type">Tour Type</label>
                        <select id="desktop_tour_type" name="tour_type">
                            <option value="in_person">In-Person Tour</option>
                            <option value="virtual">Virtual Tour</option>
                        </select>
                    </div>

                    <div class="mld-form-row">
                        <div class="mld-form-group">
                            <label for="desktop_tour_date">Preferred Date</label>
                            <input type="date" id="desktop_tour_date" name="preferred_date">
                        </div>
                        <div class="mld-form-group">
                            <label for="desktop_tour_time">Preferred Time</label>
                            <select id="desktop_tour_time" name="preferred_time">
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
// Property data for JavaScript
window.mldPropertyDataV3 = {
    mlsNumber: <?php echo json_encode($mls_number); ?>,
    address: <?php echo json_encode($address); ?>,
    city: <?php echo json_encode($city); ?>,
    state: <?php echo json_encode($state); ?>,
    zipCode: <?php echo json_encode($postal_code); ?>,
    price: <?php echo json_encode($display_price ?? 0); ?>,
    lat: <?php echo json_encode($lat); ?>,
    lng: <?php echo json_encode($lng); ?>,
    propertyTax: <?php echo json_encode((int)($tax_amount ?? 0)); ?>,  // Annual tax
    hoaFees: <?php echo json_encode((int)($hoa_monthly ?? 0)); ?>,  // Monthly HOA (already converted)
    photos: <?php echo json_encode(array_map(function($photo) { return $photo['MediaURL']; }, $photos)); ?>,
    // Additional data for similar homes
    beds: <?php echo json_encode($beds ?? 0); ?>,
    baths: <?php echo json_encode($baths ?? 0); ?>,
    sqft: <?php echo json_encode($sqft ? str_replace(',', '', $sqft) : 0); ?>,
    propertyType: <?php echo json_encode($listing['property_type'] ?? ''); ?>,
    propertySubType: <?php echo json_encode($listing['property_sub_type'] ?? $property_type ?? ''); ?>,
    status: <?php echo json_encode($status ?? 'Active'); ?>,
    closeDate: <?php echo json_encode($listing['close_date'] ?? ''); ?>,
    daysOnMarket: <?php echo json_encode(is_numeric($days_on_market) ? (int)$days_on_market : 0); ?>,
    originalEntryTimestamp: <?php echo json_encode($listing['original_entry_timestamp'] ?? ''); ?>,
    offMarketDate: <?php echo json_encode($listing['off_market_date'] ?? ''); ?>,
    yearBuilt: <?php echo json_encode($year_built ?? null); ?>,
    lotSizeAcres: <?php echo json_encode($listing['lot_size_acres'] ?? null); ?>,
    lotSizeSquareFeet: <?php echo json_encode($listing['lot_size_square_feet'] ?? null); ?>,
    garageSpaces: <?php echo json_encode($listing['garage_spaces'] ?? 0); ?>,
    parkingTotal: <?php echo json_encode($listing['parking_total'] ?? 0); ?>,
    isWaterfront: <?php echo json_encode(isset($listing['waterfront_yn']) && ($listing['waterfront_yn'] === 'Y' || $listing['waterfront_yn'] === '1')); ?>,
    entryLevel: <?php echo json_encode($listing['entry_level'] ?? null); ?>
};

// Settings (includes Walk Score settings)
window.mldSettings = <?php 
    $settings = MLD_Settings::get_js_settings();
    // Add AJAX settings needed for Walk Score
    $settings['ajax_url'] = admin_url('admin-ajax.php');
    $settings['ajax_nonce'] = wp_create_nonce('mld_ajax_nonce');
    echo json_encode($settings);
?>;

// Map data - Always Google Maps now (Mapbox removed for performance)
window.bmeMapDataV3 = {
    mapProvider: 'google',
    google_key: <?php echo json_encode(MLD_Settings::get_google_maps_api_key()); ?>
};
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

    <!-- Drawer User Menu (Collapsible v6.45.0) -->
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
            <!-- Collapsible User Toggle -->
            <button type="button" class="mld-nav-drawer__user-toggle" aria-expanded="false" aria-controls="mld-drawer-user-menu">
                <img src="<?php echo esc_url($drawer_avatar_url); ?>"
                     alt="<?php echo esc_attr($drawer_display_name); ?>"
                     class="mld-nav-drawer__user-avatar">
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php echo esc_html($drawer_display_name); ?></span>
                </div>
                <svg class="mld-nav-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                </svg>
            </button>
            <!-- Collapsible User Menu Items -->
            <nav id="mld-drawer-user-menu" class="mld-nav-drawer__user-nav mld-nav-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'mls-listings-display'); ?>">
                <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span><?php esc_html_e('My Dashboard', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-dashboard/#favorites')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                    </svg>
                    <span><?php esc_html_e('Favorites', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/my-dashboard/#searches')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                    </svg>
                    <span><?php esc_html_e('Saved Searches', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(get_edit_profile_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <span><?php esc_html_e('Edit Profile', 'mls-listings-display'); ?></span>
                </a>
                <?php if (current_user_can('manage_options')) : ?>
                <a href="<?php echo esc_url(admin_url()); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                    </svg>
                    <span><?php esc_html_e('Admin', 'mls-listings-display'); ?></span>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--logout">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                    </svg>
                    <span><?php esc_html_e('Log Out', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php else : ?>
            <!-- Guest User - Collapsible Login/Register -->
            <button type="button" class="mld-nav-drawer__user-toggle mld-nav-drawer__user-toggle--guest" aria-expanded="false" aria-controls="mld-drawer-user-menu">
                <span class="mld-nav-drawer__user-avatar mld-nav-drawer__user-avatar--guest">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </span>
                <div class="mld-nav-drawer__user-info">
                    <span class="mld-nav-drawer__user-name"><?php esc_html_e('Login / Register', 'mls-listings-display'); ?></span>
                </div>
                <svg class="mld-nav-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                </svg>
            </button>
            <nav id="mld-drawer-user-menu" class="mld-nav-drawer__user-nav mld-nav-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'mls-listings-display'); ?>">
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="mld-nav-drawer__user-item mld-nav-drawer__user-item--primary">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/>
                    </svg>
                    <span><?php esc_html_e('Log In', 'mls-listings-display'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="mld-nav-drawer__user-item">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <span><?php esc_html_e('Create Account', 'mls-listings-display'); ?></span>
                </a>
            </nav>
        <?php endif; ?>
    </div>
</aside>

<?php if ($is_agent && !empty($agent_clients)): ?>
<!-- Share with Client Modal - Agent Only -->
<div id="mld-share-client-modal" class="mld-share-client-modal" style="display: none;">
    <div class="mld-share-client-modal__overlay" id="mld-share-modal-overlay"></div>
    <div class="mld-share-client-modal__content">
        <div class="mld-share-client-modal__header">
            <h3>Share Property with Client</h3>
            <button type="button" class="mld-share-client-modal__close" id="mld-share-modal-close">&times;</button>
        </div>
        <div class="mld-share-client-modal__body">
            <p class="mld-share-client-modal__desc">Select a client to share this property with:</p>
            <div class="mld-share-client-modal__clients">
                <?php foreach ($agent_clients as $client): ?>
                <label class="mld-share-client-modal__client">
                    <input type="radio" name="share_client" value="<?php echo esc_attr($client['client_id']); ?>" class="mld-share-client-radio">
                    <div class="mld-share-client-modal__client-info">
                        <span class="mld-share-client-modal__client-name"><?php echo esc_html($client['display_name'] ?? $client['user_email']); ?></span>
                        <span class="mld-share-client-modal__client-email"><?php echo esc_html($client['user_email']); ?></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="mld-share-client-modal__note-wrapper">
                <label for="mld-share-note">Add a note (optional):</label>
                <textarea id="mld-share-note" class="mld-share-client-modal__note" placeholder="E.g., This property has the backyard you were looking for!" rows="3"></textarea>
            </div>
        </div>
        <div class="mld-share-client-modal__footer">
            <button type="button" class="mld-share-client-modal__btn mld-share-client-modal__btn--cancel" id="mld-share-cancel-btn">Cancel</button>
            <button type="button" class="mld-share-client-modal__btn mld-share-client-modal__btn--share" id="mld-share-confirm-btn" disabled>Share Property</button>
        </div>
        <div class="mld-share-client-modal__status" id="mld-share-status" style="display: none;"></div>
    </div>
</div>

<style>
/* Share with Client Modal Styles */
.mld-share-client-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
}
.mld-share-client-modal__overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}
.mld-share-client-modal__content {
    position: relative;
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 480px;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}
.mld-share-client-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e0e0e0;
}
.mld-share-client-modal__header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #333;
}
.mld-share-client-modal__close {
    background: none;
    border: none;
    font-size: 28px;
    color: #666;
    cursor: pointer;
    padding: 0;
    line-height: 1;
}
.mld-share-client-modal__close:hover {
    color: #333;
}
.mld-share-client-modal__body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.mld-share-client-modal__desc {
    margin: 0 0 16px 0;
    color: #666;
    font-size: 14px;
}
.mld-share-client-modal__clients {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
    max-height: 200px;
    overflow-y: auto;
}
.mld-share-client-modal__client {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.mld-share-client-modal__client:hover {
    border-color: #2563eb;
    background: #f0f7ff;
}
.mld-share-client-modal__client:has(input:checked) {
    border-color: #2563eb;
    background: #eff6ff;
}
.mld-share-client-radio {
    accent-color: #2563eb;
    width: 18px;
    height: 18px;
}
.mld-share-client-modal__client-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.mld-share-client-modal__client-name {
    font-weight: 500;
    color: #333;
}
.mld-share-client-modal__client-email {
    font-size: 13px;
    color: #666;
}
.mld-share-client-modal__note-wrapper {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.mld-share-client-modal__note-wrapper label {
    font-size: 14px;
    font-weight: 500;
    color: #333;
}
.mld-share-client-modal__note {
    width: 100%;
    padding: 12px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    resize: vertical;
    font-family: inherit;
}
.mld-share-client-modal__note:focus {
    outline: none;
    border-color: #2563eb;
}
.mld-share-client-modal__footer {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid #e0e0e0;
    justify-content: flex-end;
}
.mld-share-client-modal__btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.mld-share-client-modal__btn--cancel {
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    color: #666;
}
.mld-share-client-modal__btn--cancel:hover {
    background: #eee;
}
.mld-share-client-modal__btn--share {
    background: #2563eb;
    border: none;
    color: #fff;
}
.mld-share-client-modal__btn--share:hover:not(:disabled) {
    background: #1d4ed8;
}
.mld-share-client-modal__btn--share:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}
.mld-share-client-modal__status {
    padding: 12px 20px;
    text-align: center;
    font-size: 14px;
}
.mld-share-client-modal__status.success {
    background: #dcfce7;
    color: #166534;
}
.mld-share-client-modal__status.error {
    background: #fee2e2;
    color: #991b1b;
}
/* Button in nav bar styling */
.mld-v3-share-client-btn {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff !important;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
}
.mld-v3-share-client-btn:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
}
.mld-v3-share-client-btn svg {
    stroke: #fff;
}
</style>

<script>
(function() {
    'use strict';

    const modal = document.getElementById('mld-share-client-modal');
    const openBtn = document.getElementById('mld-share-with-client-btn');
    const closeBtn = document.getElementById('mld-share-modal-close');
    const cancelBtn = document.getElementById('mld-share-cancel-btn');
    const confirmBtn = document.getElementById('mld-share-confirm-btn');
    const overlay = document.getElementById('mld-share-modal-overlay');
    const radios = document.querySelectorAll('.mld-share-client-radio');
    const noteField = document.getElementById('mld-share-note');
    const statusEl = document.getElementById('mld-share-status');

    if (!modal || !openBtn) return;

    // Get listing data from button
    const listingKey = openBtn.dataset.listingKey;
    const listingId = openBtn.dataset.listingId;

    // Open modal
    openBtn.addEventListener('click', function(e) {
        e.preventDefault();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    });

    // Close modal
    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        // Reset form
        radios.forEach(r => r.checked = false);
        noteField.value = '';
        confirmBtn.disabled = true;
        statusEl.style.display = 'none';
        statusEl.className = 'mld-share-client-modal__status';
    }

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', closeModal);

    // Enable share button when client is selected
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            confirmBtn.disabled = false;
        });
    });

    // Handle share
    confirmBtn.addEventListener('click', async function() {
        const selectedClient = document.querySelector('.mld-share-client-radio:checked');
        if (!selectedClient) return;

        const clientId = selectedClient.value;
        const note = noteField.value.trim();

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Sharing...';

        try {
            const response = await fetch('<?php echo esc_url(rest_url('mld-mobile/v1/shared-properties')); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({
                    client_ids: [parseInt(clientId)],
                    listing_keys: [listingKey],
                    note: note
                })
            });

            const result = await response.json();
            console.log('Share API response:', result);

            if (result.success) {
                statusEl.textContent = 'Property shared successfully!';
                statusEl.className = 'mld-share-client-modal__status success';
                statusEl.style.display = 'block';
                setTimeout(closeModal, 1500);
            } else {
                // Show more detailed error
                const errorMsg = result.message || result.data?.message || 'Failed to share property';
                console.error('Share failed:', result);
                throw new Error(errorMsg);
            }
        } catch (error) {
            console.error('Share error:', error);
            statusEl.textContent = error.message || 'Failed to share property. Please try again.';
            statusEl.className = 'mld-share-client-modal__status error';
            statusEl.style.display = 'block';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Share Property';
        }
    });

    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>