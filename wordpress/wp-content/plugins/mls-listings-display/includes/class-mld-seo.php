<?php
/**
 * MLS Listings Display SEO Manager
 *
 * Handles all SEO functionality for property detail pages including
 * meta tags, structured data, and featured images
 *
 * @package MLS_Listings_Display
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_SEO {
    
    /**
     * Property data
     * @var array
     */
    private $property_data;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress head for meta tags with very high priority (lower number = higher priority)
        add_action('wp_head', array($this, 'remove_other_og_tags'), 0);
        add_action('wp_head', array($this, 'output_meta_tags'), 1);
        add_action('wp_head', array($this, 'output_structured_data'), 10);
        add_action('wp_head', array($this, 'output_image_optimization_tags'), 5);
        
        // Also add a very late hook to ensure our image tags are present
        add_action('wp_head', array($this, 'ensure_og_image_tags'), 999);
        
        // Add filter to ensure featured image is set
        add_filter('post_thumbnail_html', array($this, 'filter_post_thumbnail'), 10, 5);
        add_filter('get_post_metadata', array($this, 'filter_post_thumbnail_id'), 10, 4);
        
        // Override default site icon for property pages
        add_filter('get_site_icon_url', array($this, 'filter_site_icon_url'), 10, 3);
        
        // WordPress title filter
        add_filter('document_title_parts', array($this, 'filter_document_title'), 10, 1);
        add_filter('wp_title', array($this, 'filter_wp_title'), 10, 2);
        
        // Filter for WordPress SEO plugins
        add_filter('wpseo_title', array($this, 'filter_yoast_title'), 10, 1);
        add_filter('wpseo_metadesc', array($this, 'filter_yoast_description'), 10, 1);
        add_filter('wpseo_opengraph_image', array($this, 'filter_yoast_og_image'), 10, 1);
        
        // RankMath filters
        add_filter('rank_math/frontend/title', array($this, 'filter_rankmath_title'), 10, 1);
        add_filter('rank_math/frontend/description', array($this, 'filter_rankmath_description'), 10, 1);
        
        // All in One SEO filters
        add_filter('aioseo_title', array($this, 'filter_aioseo_title'), 10, 1);
        add_filter('aioseo_description', array($this, 'filter_aioseo_description'), 10, 1);
    }
    
    /**
     * Set property data for current page
     * 
     * @param array $property_data
     */
    public function set_property_data($property_data) {
        $this->property_data = $property_data;
    }
    
    /**
     * Get SEO-optimized title
     * 
     * @return string
     */
    private function get_seo_title() {
        if (empty($this->property_data)) {
            return get_bloginfo('name');
        }
        
        $property = $this->property_data;
        $title_parts = array();
        
        // Format: [unparsed address] - [beds] - [baths] - [property sub type] - [For Sale/For Rent]
        
        // Add unparsed address
        if (!empty($property['unparsed_address'])) {
            $title_parts[] = $property['unparsed_address'];
        } elseif (!empty($property['full_street_address'])) {
            $title_parts[] = $property['full_street_address'];
        }
        
        // Add beds
        if (!empty($property['bedrooms_total'])) {
            $beds = $property['bedrooms_total'];
            $title_parts[] = $beds . ' Bed' . ($beds != 1 ? 's' : '');
        }
        
        // Add baths
        $baths_full = $property['bathrooms_full'] ?? 0;
        $baths_half = $property['bathrooms_half'] ?? 0;
        $total_baths = $baths_full + ($baths_half * 0.5);
        if ($total_baths > 0) {
            $title_parts[] = $total_baths . ' Bath' . ($total_baths != 1 ? 's' : '');
        }
        
        // Add property sub type (or property type if sub type not available)
        if (!empty($property['property_sub_type'])) {
            $title_parts[] = $property['property_sub_type'];
        } elseif (!empty($property['property_type'])) {
            $title_parts[] = mld_get_seo_property_type($property['property_type']);
        }
        
        // Add listing status (For Sale/For Rent)
        // Check multiple fields since different sources use different field names
        $listing_type = 'For Sale'; // Default
        if (!empty($property['property_type'])) {
            if (stripos($property['property_type'], 'rent') !== false ||
                stripos($property['property_type'], 'lease') !== false) {
                $listing_type = 'For Rent';
            }
        }
        if ($listing_type === 'For Sale' && !empty($property['listing_type'])) {
            if (stripos($property['listing_type'], 'rent') !== false ||
                stripos($property['listing_type'], 'lease') !== false) {
                $listing_type = 'For Rent';
            }
        }
        if ($listing_type === 'For Sale' && !empty($property['standard_status'])) {
            if (stripos($property['standard_status'], 'rent') !== false ||
                stripos($property['standard_status'], 'lease') !== false) {
                $listing_type = 'For Rent';
            }
        }
        $title_parts[] = $listing_type;
        
        return implode(' - ', $title_parts);
    }
    
    /**
     * Get SEO-optimized description
     * 
     * @return string
     */
    private function get_seo_description() {
        if (empty($this->property_data)) {
            return '';
        }
        
        $property = $this->property_data;
        $description_parts = array();
        
        // Property type and status
        if (!empty($property['property_type'])) {
            $status = !empty($property['standard_status']) ? $property['standard_status'] : 'Available';
            $description_parts[] = $status . ' ' . mld_get_seo_property_type($property['property_type']);
        }
        
        // Address
        if (!empty($property['full_street_address'])) {
            $description_parts[] = 'at ' . $property['full_street_address'];
        }
        
        // City, State with Neighborhood
        $location_parts = [];
        if (!empty($property['city']) && !empty($property['state_or_province'])) {
            $city_state = $property['city'] . ', ' . $property['state_or_province'];

            // Add neighborhood if available
            if (!empty($property['subdivision']) && strtolower($property['subdivision']) !== 'none') {
                $location_parts[] = 'in the ' . $property['subdivision'] . ' neighborhood of ' . $city_state;
            } elseif (!empty($property['area']) && strtolower($property['area']) !== 'none') {
                $location_parts[] = 'in the ' . $property['area'] . ' area of ' . $city_state;
            } else {
                $location_parts[] = 'in ' . $city_state;
            }

            $description_parts[] = implode(' ', $location_parts);
        }
        
        // Beds/Baths
        $features = array();
        if (!empty($property['bedrooms_total']) && $property['bedrooms_total'] > 0) {
            $features[] = $property['bedrooms_total'] . ' bed' . ($property['bedrooms_total'] > 1 ? 's' : '');
        }
        if (!empty($property['bathrooms_total']) && $property['bathrooms_total'] > 0) {
            $features[] = $property['bathrooms_total'] . ' bath' . ($property['bathrooms_total'] > 1 ? 's' : '');
        }
        if (!empty($features)) {
            $description_parts[] = 'featuring ' . implode(', ', $features);
        }
        
        // Living area
        if (!empty($property['living_area']) && $property['living_area'] > 0) {
            $description_parts[] = 'with ' . number_format($property['living_area']) . ' sq ft';
        }
        
        // Determine if this is a rental
        $is_rental = false;
        if (!empty($property['listing_type'])) {
            $is_rental = stripos($property['listing_type'], 'rent') !== false ||
                        stripos($property['listing_type'], 'lease') !== false;
        } elseif (!empty($property['property_type'])) {
            $is_rental = stripos($property['property_type'], 'rent') !== false ||
                        stripos($property['property_type'], 'lease') !== false;
        }

        // Price - format differently for rentals vs sales
        if (!empty($property['list_price']) && $property['list_price'] > 0) {
            if ($is_rental) {
                $description_parts[] = 'renting for $' . number_format($property['list_price']) . '/month';
            } else {
                $description_parts[] = 'listed at $' . number_format($property['list_price']);
            }
        }
        
        // Add key features (different priorities for buyers vs renters)
        $key_features = [];

        // Lot size - only relevant for sales, not rentals
        if (!$is_rental) {
            if (!empty($property['lot_size_acres']) && $property['lot_size_acres'] > 0) {
                $key_features[] = number_format($property['lot_size_acres'], 2) . ' acre lot';
            } elseif (!empty($property['lot_size_area']) && $property['lot_size_area'] > 0) {
                $key_features[] = number_format($property['lot_size_area']) . ' sq ft lot';
            }
        }

        // Year built
        if (!empty($property['year_built']) && $property['year_built'] > 0) {
            $key_features[] = 'built in ' . $property['year_built'];
        }

        // Parking
        if (!empty($property['garage_spaces']) && $property['garage_spaces'] > 0) {
            $key_features[] = $property['garage_spaces'] . ' car garage';
        } elseif ($is_rental && !empty($property['parking_total']) && $property['parking_total'] > 0) {
            // For rentals, mention total parking if no garage
            $key_features[] = $property['parking_total'] . ' parking space' . ($property['parking_total'] > 1 ? 's' : '');
        }

        // Pool
        if (!empty($property['pool_features']) && stripos($property['pool_features'], 'none') === false) {
            $key_features[] = 'pool';
        }

        // For rentals, add laundry info if available (important for renters)
        if ($is_rental && !empty($property['laundry_features']) && stripos($property['laundry_features'], 'none') === false) {
            if (stripos($property['laundry_features'], 'in unit') !== false) {
                $key_features[] = 'in-unit laundry';
            }
        }

        // Add key features if we have any
        if (!empty($key_features)) {
            $description_parts[] = 'with ' . implode(', ', array_slice($key_features, 0, 2)); // Limit to 2 features
        }

        // Add nearby schools if available
        $school_info = $this->get_nearby_schools();
        if (!empty($school_info)) {
            $description_parts[] = '. ' . $school_info;
        }

        // Add call to action - different for rentals
        if ($is_rental) {
            $description_parts[] = '. View photos, details, and schedule a tour.';
        } else {
            $description_parts[] = '. View photos, details, and schedule a showing.';
        }

        $description = implode(' ', $description_parts);

        // Ensure description is within optimal length (155-160 chars)
        if (strlen($description) > 160) {
            // Trim to 157 chars and add ellipsis
            $description = substr($description, 0, 157) . '...';
        }
        
        // Ensure description is within optimal length (155-160 characters)
        if (strlen($description) > 160) {
            $description = substr($description, 0, 157) . '...';
        }
        
        return $description;
    }
    
    /**
     * Get nearby schools information for meta description
     *
     * @return string
     */
    private function get_nearby_schools() {
        if (empty($this->property_data['listing_id'])) {
            return '';
        }

        global $wpdb;

        // Check if distance column exists
        $property_schools_table = $wpdb->prefix . 'mld_property_schools';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$property_schools_table}");

        // Build query based on available columns
        if (in_array('distance', $columns)) {
            // Query with distance if available
            $schools = $wpdb->get_results($wpdb->prepare("
                SELECT s.name, s.level, ps.distance
                FROM {$wpdb->prefix}mld_property_schools ps
                INNER JOIN {$wpdb->prefix}mld_schools s ON ps.school_id = s.id
                WHERE ps.listing_id = %s
                ORDER BY ps.distance ASC
                LIMIT 3
            ", $this->property_data['listing_id']));
        } else {
            // Query without distance
            $schools = $wpdb->get_results($wpdb->prepare("
                SELECT s.name, s.level, NULL as distance
                FROM {$wpdb->prefix}mld_property_schools ps
                INNER JOIN {$wpdb->prefix}mld_schools s ON ps.school_id = s.id
                WHERE ps.listing_id = %s
                LIMIT 3
            ", $this->property_data['listing_id']));
        }

        if (empty($schools)) {
            return '';
        }

        // Get the closest school
        $closest_school = $schools[0];
        $school_levels = array_unique(array_column($schools, 'level'));

        // Format school information
        if (count($school_levels) >= 3) {
            return 'Near top-rated schools';
        } elseif ($closest_school->distance < 1.0) {
            return 'Walking distance to ' . $closest_school->name;
        } else {
            return 'Near ' . $closest_school->name;
        }
    }

    /**
     * Get featured image URL (first gallery image)
     *
     * @return string|false
     */
    private function get_featured_image_url() {
        if (empty($this->property_data)) {
            return false;
        }
        
        // Check for media in both cases (Media and media)
        $media_array = null;
        if (!empty($this->property_data['Media'])) {
            $media_array = $this->property_data['Media'];
        } elseif (!empty($this->property_data['media'])) {
            $media_array = $this->property_data['media'];
        }
        
        if (empty($media_array)) {
            return false;
        }
        
        // Get first image from media array
        foreach ($media_array as $media) {
            if (!empty($media['MediaURL'])) {
                // Check if it's a photo/image (be lenient with category check)
                $is_photo = true;
                if (!empty($media['MediaCategory'])) {
                    $category = strtolower($media['MediaCategory']);
                    // Only exclude if explicitly NOT a photo
                    if (strpos($category, 'video') !== false || 
                        strpos($category, 'tour') !== false ||
                        strpos($category, 'floorplan') !== false) {
                        $is_photo = false;
                    }
                }
                
                if ($is_photo) {
                    // Ensure absolute URL
                    $image_url = $media['MediaURL'];
                    if (strpos($image_url, 'http') !== 0) {
                        $image_url = home_url($image_url);
                    }
                    return $image_url;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Output meta tags
     */
    public function output_meta_tags() {
        // Only output on single property pages
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number === false) {
            return;
        }
        
        // If property data hasn't been set yet, fetch it now
        if (empty($this->property_data)) {
            $listing = MLD_Query::get_listing_details($mls_number);
            if (!empty($listing)) {
                $this->property_data = $listing;
            } else {
                return;
            }
        }
        
        $title = $this->get_seo_title();
        $description = $this->get_seo_description();
        $featured_image = $this->get_featured_image_url();
        // The MLS number passed in the URL is what we need for the canonical URL
        $canonical_url = home_url('/property/' . $mls_number . '/');

        // Force remove any existing og:image tags that might be set by theme or other plugins
        remove_action('wp_head', 'jetpack_og_tags');
        remove_action('wp_head', 'wpcom_og_tags');
        
        // Also output a link tag for preview image (some platforms check this)
        if ($featured_image) {
            echo '<link rel="image_src" href="' . esc_url($featured_image) . '" />' . "\n";
        }
        
        // Basic meta tags
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . "\n";
        
        // Open Graph tags
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($canonical_url) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        
        if ($featured_image) {
            // Primary image tag - already has absolute URL from get_featured_image_url
            $absolute_url = $featured_image;
            
            // Ensure HTTPS for secure_url
            $secure_url = str_replace('http://', 'https://', $absolute_url);
            
            echo '<meta property="og:image" content="' . esc_url($absolute_url) . '">' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url($secure_url) . '">' . "\n";
            echo '<meta property="og:image:url" content="' . esc_url($absolute_url) . '">' . "\n";
            echo '<meta property="og:image:type" content="image/jpeg">' . "\n";
            echo '<meta property="og:image:width" content="1200">' . "\n";
            echo '<meta property="og:image:height" content="630">' . "\n";
            echo '<meta property="og:image:alt" content="' . esc_attr($this->property_data['unparsed_address'] ?? $this->property_data['full_street_address'] ?? 'Property Image') . '">' . "\n";
            
            // Add multiple images if available
            $media_array = $this->property_data['Media'] ?? $this->property_data['media'] ?? [];
            $image_count = 0;
            foreach ($media_array as $media) {
                if ($image_count >= 3) break; // Limit to 3 additional images
                if (!empty($media['MediaURL']) && 
                    (empty($media['MediaCategory']) || 
                     stripos($media['MediaCategory'], 'photo') !== false || 
                     stripos($media['MediaCategory'], 'image') !== false)) {
                    if ($media['MediaURL'] !== $featured_image) {
                        echo '<meta property="og:image" content="' . esc_url($media['MediaURL']) . '">' . "\n";
                        $image_count++;
                    }
                }
            }
        }
        
        // Twitter Card tags
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        
        if ($featured_image) {
            echo '<meta name="twitter:image" content="' . esc_url($featured_image) . '">' . "\n";
            echo '<meta name="twitter:image:alt" content="' . esc_attr($this->property_data['unparsed_address'] ?? $this->property_data['full_street_address'] ?? 'Property Image') . '">' . "\n";
        }
        
        // Additional meta tags for better compatibility
        echo '<meta itemprop="name" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta itemprop="description" content="' . esc_attr($description) . '">' . "\n";
        if ($featured_image) {
            echo '<meta itemprop="image" content="' . esc_url($featured_image) . '">' . "\n";
        }
        
        // Additional SEO meta tags
        echo '<meta name="robots" content="index, follow">' . "\n";
        
        if (!empty($this->property_data['listing_contract_date'])) {
            echo '<meta property="article:published_time" content="' . esc_attr($this->property_data['listing_contract_date']) . '">' . "\n";
        }
        
        if (!empty($this->property_data['modification_timestamp'])) {
            echo '<meta property="article:modified_time" content="' . esc_attr($this->property_data['modification_timestamp']) . '">' . "\n";
        }
    }
    
    /**
     * Map MLS standard_status to Schema.org ItemAvailability
     *
     * @param string $status MLS standard status
     * @return string Schema.org ItemAvailability URL
     */
    private function map_availability_status($status) {
        $status = strtolower(trim($status));

        switch ($status) {
            case 'active':
            case 'active under contract':
                return 'https://schema.org/InStock';

            case 'pending':
            case 'pending showing for backups':
            case 'coming soon':
                return 'https://schema.org/LimitedAvailability';

            case 'closed':
            case 'sold':
            case 'withdrawn':
            case 'canceled':
            case 'expired':
                return 'https://schema.org/SoldOut';

            default:
                return 'https://schema.org/InStock';
        }
    }

    /**
     * Get the appropriate Schema.org type for a property
     *
     * Maps property_sub_type and property_type to specific Schema.org types
     * for better AI/GEO discoverability (HouseForSale, ApartmentForSale, etc.)
     *
     * @param array $property Property data array
     * @return string Schema.org type
     * @since 6.15.1
     */
    private function get_schema_type_for_property($property) {
        $type = $property['property_type'] ?? '';
        $subtype = $property['property_sub_type'] ?? '';

        // Check for rental/lease - these would be different schema types
        $is_lease = stripos($type, 'Lease') !== false || stripos($type, 'Rental') !== false;

        // Single-family homes → HouseForSale (or similar for rent)
        $house_subtypes = array('Single Family Residence', 'Townhouse', 'Condex', 'Single Family', 'Detached');
        foreach ($house_subtypes as $house_type) {
            if (stripos($subtype, $house_type) !== false) {
                return $is_lease ? 'RealEstateListing' : 'HouseForSale';
            }
        }

        // Multi-family/condos → ApartmentForSale
        $apartment_subtypes = array('Condominium', 'Apartment', '2 Family', '3 Family', 'Duplex', 'Multi Family', 'Multi-Family');
        foreach ($apartment_subtypes as $apt_type) {
            if (stripos($subtype, $apt_type) !== false) {
                return $is_lease ? 'RealEstateListing' : 'ApartmentForSale';
            }
        }

        // Residential Income properties → ApartmentForSale
        if (stripos($type, 'Residential Income') !== false) {
            return $is_lease ? 'RealEstateListing' : 'ApartmentForSale';
        }

        // Default fallback
        return 'RealEstateListing';
    }

    /**
     * Output structured data
     */
    public function output_structured_data() {
        // Only output on single property pages
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number === false) {
            return;
        }

        // If property data hasn't been set yet, fetch it now
        if (empty($this->property_data)) {
            $listing = MLD_Query::get_listing_details($mls_number);
            if (!empty($listing)) {
                $this->property_data = $listing;
            } else {
                return;
            }
        }

        $property = $this->property_data;

        // Build structured data
        $structured_data = array(
            '@context' => 'https://schema.org',
            '@type' => $this->get_schema_type_for_property($property),
            'name' => $this->get_seo_title(),
            'description' => $this->get_seo_description(),
            'url' => home_url('/property/' . ($property['listing_id'] ?? '') . '/')
        );

        // Add address
        if (!empty($property['full_street_address'])) {
            $structured_data['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => $property['full_street_address'],
                'addressLocality' => !empty($property['city']) ? $property['city'] : '',
                'addressRegion' => !empty($property['state_or_province']) ? $property['state_or_province'] : '',
                'postalCode' => !empty($property['postal_code']) ? $property['postal_code'] : '',
                'addressCountry' => 'US'
            );
        }

        // Add Place schema for enhanced location context
        if (!empty($property['city']) && !empty($property['state_or_province'])) {
            $place_schema = array(
                '@type' => 'Place',
                'name' => $property['city'] . ', ' . $property['state_or_province'],
                'address' => $structured_data['address'] ?? null
            );

            // Add geo coordinates to Place if available
            if (!empty($property['latitude']) && !empty($property['longitude'])) {
                $place_schema['geo'] = array(
                    '@type' => 'GeoCoordinates',
                    'latitude' => floatval($property['latitude']),
                    'longitude' => floatval($property['longitude'])
                );
            }

            $structured_data['contentLocation'] = $place_schema;
        }

        // Add price with dynamic availability status
        if (!empty($property['list_price']) && $property['list_price'] > 0) {
            $availability_status = $this->map_availability_status($property['standard_status'] ?? 'Active');

            $structured_data['offers'] = array(
                '@type' => 'Offer',
                'price' => $property['list_price'],
                'priceCurrency' => 'USD',
                'availability' => $availability_status,
                'url' => home_url('/property/' . ($property['listing_id'] ?? '') . '/')
            );

            // Add price valid until date (typically 90 days from listing date)
            if (!empty($property['listing_contract_date'])) {
                $valid_until = wp_date('Y-m-d', strtotime($property['listing_contract_date'] . ' + 90 days'));
                $structured_data['offers']['priceValidUntil'] = $valid_until;
            }

            // Add seller information if available
            if (!empty($property['list_office_name'])) {
                $structured_data['offers']['seller'] = array(
                    '@type' => 'RealEstateAgent',
                    'name' => $property['list_office_name']
                );
            }
        }
        
        // Add images using ImageObject schema for better SEO
        $media_array = null;
        if (!empty($property['Media'])) {
            $media_array = $property['Media'];
        } elseif (!empty($property['media'])) {
            $media_array = $property['media'];
        }

        if (!empty($media_array)) {
            $images = array();
            $image_count = 0;
            $property_address = $property['unparsed_address'] ?? $property['full_street_address'] ?? 'Property';

            foreach ($media_array as $index => $media) {
                if (!empty($media['MediaURL']) &&
                    (empty($media['MediaCategory']) ||
                     strtolower($media['MediaCategory']) == 'photo' ||
                     strtolower($media['MediaCategory']) == 'image')) {

                    // Create ImageObject for each photo
                    $image_object = array(
                        '@type' => 'ImageObject',
                        'url' => $media['MediaURL'],
                        'contentUrl' => $media['MediaURL']
                    );

                    // Add caption/description if available
                    if (!empty($media['MediaDescription'])) {
                        $image_object['caption'] = $media['MediaDescription'];
                        $image_object['description'] = $media['MediaDescription'];
                    } else {
                        // Generate descriptive caption
                        $photo_number = $index + 1;
                        $caption = "Photo {$photo_number} of {$property_address}";
                        $image_object['caption'] = $caption;
                        $image_object['description'] = $caption;
                    }

                    // Add standard dimensions for real estate photos
                    $image_object['width'] = '1200';
                    $image_object['height'] = '800';

                    $images[] = $image_object;
                    $image_count++;

                    // Limit to 10 images for performance
                    if ($image_count >= 10) {
                        break;
                    }
                }
            }

            if (!empty($images)) {
                // Use single image if only one, array if multiple
                $structured_data['image'] = count($images) === 1 ? $images[0] : $images;
            }
        }
        
        // Add property details
        if (!empty($property['property_type'])) {
            $structured_data['propertyType'] = mld_get_seo_property_type($property['property_type']);
        }

        if (!empty($property['bedrooms_total']) && $property['bedrooms_total'] > 0) {
            $structured_data['numberOfRooms'] = $property['bedrooms_total'];
        }

        // Handle bathroom count - prefer decimal, fallback to calculating from full+half
        if (!empty($property['bathrooms_total_decimal']) && $property['bathrooms_total_decimal'] > 0) {
            $structured_data['numberOfBathroomsTotal'] = floatval($property['bathrooms_total_decimal']);
        } elseif (!empty($property['bathrooms_full']) || !empty($property['bathrooms_half'])) {
            $full = intval($property['bathrooms_full'] ?? 0);
            $half = intval($property['bathrooms_half'] ?? 0) * 0.5;
            if (($full + $half) > 0) {
                $structured_data['numberOfBathroomsTotal'] = $full + $half;
            }
        }
        
        if (!empty($property['living_area']) && $property['living_area'] > 0) {
            $structured_data['floorSize'] = array(
                '@type' => 'QuantitativeValue',
                'value' => $property['living_area'],
                'unitCode' => 'SQFT'
            );
        }

        // Add lot size
        if (!empty($property['lot_size_acres']) && $property['lot_size_acres'] > 0) {
            $structured_data['lotSize'] = array(
                '@type' => 'QuantitativeValue',
                'value' => $property['lot_size_acres'],
                'unitText' => 'acres'
            );
        } elseif (!empty($property['lot_size_area']) && $property['lot_size_area'] > 0) {
            $structured_data['lotSize'] = array(
                '@type' => 'QuantitativeValue',
                'value' => $property['lot_size_area'],
                'unitCode' => 'SQFT'
            );
        }

        // Add year built
        if (!empty($property['year_built']) && $property['year_built'] > 0) {
            $structured_data['yearBuilt'] = intval($property['year_built']);
        }

        // Add parking/garage information
        if (!empty($property['garage_spaces']) && $property['garage_spaces'] > 0) {
            $structured_data['numberOfParkingSpaces'] = intval($property['garage_spaces']);
        }

        // Add additional amenities in additionalProperty array
        $additional_properties = array();

        // Pool
        if (!empty($property['pool_features']) && stripos($property['pool_features'], 'none') === false) {
            $additional_properties[] = array(
                '@type' => 'PropertyValue',
                'name' => 'Pool',
                'value' => $property['pool_features']
            );
        }

        // Heating
        if (!empty($property['heating'])) {
            $additional_properties[] = array(
                '@type' => 'PropertyValue',
                'name' => 'Heating',
                'value' => $property['heating']
            );
        }

        // Cooling
        if (!empty($property['cooling'])) {
            $additional_properties[] = array(
                '@type' => 'PropertyValue',
                'name' => 'Cooling',
                'value' => $property['cooling']
            );
        }

        // Stories/Levels
        if (!empty($property['stories_total']) && $property['stories_total'] > 0) {
            $additional_properties[] = array(
                '@type' => 'PropertyValue',
                'name' => 'Stories',
                'value' => $property['stories_total']
            );
        }

        if (!empty($additional_properties)) {
            $structured_data['additionalProperty'] = $additional_properties;
        }

        // Add geo coordinates
        if (!empty($property['latitude']) && !empty($property['longitude'])) {
            $structured_data['geo'] = array(
                '@type' => 'GeoCoordinates',
                'latitude' => floatval($property['latitude']),
                'longitude' => floatval($property['longitude'])
            );
        }

        // Add dates for freshness signals (using wp_date for WordPress timezone)
        if (!empty($property['listing_contract_date'])) {
            $structured_data['datePosted'] = wp_date('c', strtotime($property['listing_contract_date']));
        } elseif (!empty($property['created_at'])) {
            $structured_data['datePosted'] = wp_date('c', strtotime($property['created_at']));
        }

        if (!empty($property['modification_timestamp'])) {
            $structured_data['dateModified'] = wp_date('c', strtotime($property['modification_timestamp']));
        } elseif (!empty($property['updated_at'])) {
            $structured_data['dateModified'] = wp_date('c', strtotime($property['updated_at']));
        }
        
        // Add listing agent
        if (!empty($property['list_agent_full_name'])) {
            $structured_data['employee'] = array(
                '@type' => 'Person',
                'name' => $property['list_agent_full_name']
            );
            
            if (!empty($property['list_agent_email'])) {
                $structured_data['employee']['email'] = $property['list_agent_email'];
            }
            
            if (!empty($property['list_agent_direct_phone'])) {
                $structured_data['employee']['telephone'] = $property['list_agent_direct_phone'];
            }
        }
        
        
        // Add listing office with LocalBusiness schema
        
        // Add listing office with LocalBusiness schema
        if (!empty($property['list_office_name']) || !empty($property['ListOfficeName'])) {
            $office_name = $property['list_office_name'] ?? $property['ListOfficeName'] ?? '';
            
            $structured_data['provider'] = array(
                '@type' => 'LocalBusiness',
                '@id' => '#listing-office',
                'name' => $office_name
            );
            
            // Add office phone
            $office_phone = $property['list_office_phone'] ?? $property['ListOfficePhone'] ?? '';
            if (!empty($office_phone)) {
                $structured_data['provider']['telephone'] = $office_phone;
            }
            
            // Add office address
            $office_address = $property['ListOfficeAddress'] ?? $property['list_office_address'] ?? '';
            $office_city = $property['ListOfficeCity'] ?? $property['list_office_city'] ?? '';
            $office_state = $property['ListOfficeState'] ?? $property['list_office_state'] ?? '';
            $office_postal = $property['ListOfficePostalCode'] ?? $property['list_office_postal_code'] ?? '';
            
            if (!empty($office_address) || !empty($office_city)) {
                $address_parts = array('@type' => 'PostalAddress');
                
                if (!empty($office_address)) {
                    $address_parts['streetAddress'] = $office_address;
                }
                
                if (!empty($office_city)) {
                    $address_parts['addressLocality'] = $office_city;
                }
                
                if (!empty($office_state)) {
                    $address_parts['addressRegion'] = $office_state;
                }
                
                if (!empty($office_postal)) {
                    $address_parts['postalCode'] = $office_postal;
                }
                
                $address_parts['addressCountry'] = 'US';
                
                $structured_data['provider']['address'] = $address_parts;
            }
        }
        if (!empty($property['listing_contract_date'])) {
            $structured_data['datePosted'] = $property['listing_contract_date'];
        }
        
        // Output structured data
        echo '<script type="application/ld+json">' . "\n";
        echo json_encode($structured_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo "\n" . '</script>' . "\n";
        
        // Add breadcrumb structured data
        $this->output_breadcrumb_structured_data();
    }
    
    /**
     * Output breadcrumb structured data
     */
    /**
     * Output breadcrumb structured data
     * 
     * Breadcrumb hierarchy: Home > MA > City > Property
     */
    private function output_breadcrumb_structured_data() {
        if (empty($this->property_data)) {
            return;
        }
        
        $property = $this->property_data;
        $position = 1;
        
        $breadcrumb_data = array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array()
        );
        
        // 1. Home
        $breadcrumb_data['itemListElement'][] = array(
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => 'Home',
            'item' => home_url('/')
        );
        
        // 2. State (MA) - use abbreviation as requested
        $state_code = !empty($property['state_or_province']) ? $property['state_or_province'] : 'MA';
        $state_slug = strtolower($state_code) === 'ma' ? 'massachusetts' : strtolower($state_code);
        
        $breadcrumb_data['itemListElement'][] = array(
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => $state_code,  // Use abbreviation (MA) as per user choice
            'item' => home_url('/homes-for-sale-in-' . $state_slug . '/')
        );
        
        // 3. City (Reading)
        if (!empty($property['city'])) {
            $city = $property['city'];
            $city_slug = sanitize_title($city);
            $state_lower = strtolower($state_code);
            
            $breadcrumb_data['itemListElement'][] = array(
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $city,
                'item' => home_url('/homes-for-sale-in-' . $city_slug . '-' . $state_lower . '/')
            );
        }
        
        // 4. Property (Street address only, as per user choice)
        $street_address = '';
        
        // Try different address field combinations
        if (!empty($property['street_number']) && !empty($property['street_name'])) {
            $street_address = trim($property['street_number'] . ' ' . $property['street_name']);
        } elseif (!empty($property['full_street_address'])) {
            $street_address = $property['full_street_address'];
        } elseif (!empty($property['unparsed_address'])) {
            // Extract just the street part from unparsed_address (before first comma)
            $parts = explode(',', $property['unparsed_address']);
            $street_address = trim($parts[0]);
        }
        
        // Fallback to listing ID if no address
        if (empty($street_address)) {
            $street_address = 'Property #' . ($property['listing_id'] ?? 'Unknown');
        }
        
        $breadcrumb_data['itemListElement'][] = array(
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => $street_address,
            'item' => home_url('/property/' . ($property['listing_id'] ?? '') . '/')
        );
        
        echo '<script type="application/ld+json">' . "\n";
        echo json_encode($breadcrumb_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo "\n" . '</script>' . "\n";
    }
    
    /**
     * Output image optimization tags
     */
    public function output_image_optimization_tags() {
        // Only output on single property pages
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number === false) {
            return;
        }
        
        // If property data hasn't been set yet, fetch it now
        if (empty($this->property_data)) {
            $listing = MLD_Query::get_listing_details($mls_number);
            if (!empty($listing)) {
                $this->property_data = $listing;
            } else {
                return;
            }
        }
        
        // Preload the featured image for better performance
        $featured_image = $this->get_featured_image_url();
        if ($featured_image) {
            echo '<link rel="preload" as="image" href="' . esc_url($featured_image) . '">' . "\n";
        }
        
        // Add preconnect hints for external image CDNs if needed
        $media_array = null;
        if (!empty($this->property_data['Media'])) {
            $media_array = $this->property_data['Media'];
        } elseif (!empty($this->property_data['media'])) {
            $media_array = $this->property_data['media'];
        }
        
        if (!empty($media_array)) {
            $domains = array();
            foreach ($media_array as $media) {
                if (!empty($media['MediaURL'])) {
                    $parsed = parse_url($media['MediaURL']);
                    if (!empty($parsed['host']) && $parsed['host'] !== $_SERVER['HTTP_HOST']) {
                        $domains[$parsed['scheme'] . '://' . $parsed['host']] = true;
                    }
                }
            }
            
            foreach (array_keys($domains) as $domain) {
                echo '<link rel="preconnect" href="' . esc_url($domain) . '">' . "\n";
                echo '<link rel="dns-prefetch" href="' . esc_url($domain) . '">' . "\n";
            }
        }
    }
    
    /**
     * SEO plugin filter methods
     */
    public function filter_yoast_title($title) {
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number !== false) {
            // Ensure we have property data
            if (empty($this->property_data)) {
                $listing = MLD_Query::get_listing_details($mls_number);
                if (!empty($listing)) {
                    $this->property_data = $listing;
                }
            }
            if (!empty($this->property_data)) {
                return $this->get_seo_title();
            }
        }
        return $title;
    }
    
    public function filter_yoast_description($description) {
        if (get_query_var('mls_number', false) !== false && !empty($this->property_data)) {
            return $this->get_seo_description();
        }
        return $description;
    }
    
    public function filter_yoast_og_image($image) {
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number !== false) {
            // Ensure we have property data
            if (empty($this->property_data)) {
                $listing = MLD_Query::get_listing_details($mls_number);
                if (!empty($listing)) {
                    $this->property_data = $listing;
                }
            }
            if (!empty($this->property_data)) {
                $featured_image = $this->get_featured_image_url();
                if ($featured_image) {
                    return $featured_image;
                }
            }
        }
        return $image;
    }
    
    public function filter_rankmath_title($title) {
        return $this->filter_yoast_title($title);
    }
    
    public function filter_rankmath_description($description) {
        return $this->filter_yoast_description($description);
    }
    
    public function filter_aioseo_title($title) {
        return $this->filter_yoast_title($title);
    }
    
    public function filter_aioseo_description($description) {
        return $this->filter_yoast_description($description);
    }
    
    /**
     * Filter document title parts
     */
    public function filter_document_title($title_parts) {
        if (get_query_var('mls_number', false) !== false && !empty($this->property_data)) {
            $title_parts['title'] = $this->get_seo_title();
        }
        return $title_parts;
    }
    
    /**
     * Filter wp_title
     */
    public function filter_wp_title($title, $sep) {
        if (get_query_var('mls_number', false) !== false && !empty($this->property_data)) {
            return $this->get_seo_title();
        }
        return $title;
    }
    
    /**
     * Filter post thumbnail ID to return virtual featured image
     */
    public function filter_post_thumbnail_id($value, $object_id, $meta_key, $single) {
        if ($meta_key === '_thumbnail_id' && get_query_var('mls_number', false) !== false) {
            // Return a fake ID to trigger featured image display
            return 999999999;
        }
        return $value;
    }
    
    /**
     * Filter post thumbnail HTML
     */
    public function filter_post_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (get_query_var('mls_number', false) !== false && !empty($this->property_data)) {
            $featured_image = $this->get_featured_image_url();
            if ($featured_image) {
                $alt = esc_attr($this->property_data['full_street_address'] ?? 'Property Image');
                return '<img src="' . esc_url($featured_image) . '" alt="' . $alt . '" class="mld-featured-image">';
            }
        }
        return $html;
    }
    
    /**
     * Remove other plugins' OG tags
     */
    public function remove_other_og_tags() {
        if (get_query_var('mls_number', false) === false) {
            return;
        }
        
        // Remove Yoast
        if (defined('WPSEO_VERSION')) {
            add_filter('wpseo_opengraph_image', '__return_false', 99);
            add_filter('wpseo_opengraph_url', '__return_false', 99);
            add_filter('wpseo_opengraph_title', '__return_false', 99);
            add_filter('wpseo_opengraph_desc', '__return_false', 99);
            add_filter('wpseo_opengraph_type', '__return_false', 99);
            add_filter('wpseo_opengraph_site_name', '__return_false', 99);
            // Disable entire frontend presentation for this page
            add_filter('wpseo_frontend_presenter_classes', '__return_empty_array', 99);
        }
        
        // Remove All in One SEO
        if (defined('AIOSEO_VERSION')) {
            add_filter('aioseo_facebook_tags', '__return_empty_array', 99);
            add_filter('aioseo_twitter_tags', '__return_empty_array', 99);
            add_filter('aioseo_disable', '__return_true', 99);
        }
        
        // Remove Jetpack
        add_filter('jetpack_enable_open_graph', '__return_false', 99);
        
        // Remove WordPress default
        remove_action('wp_head', 'wp_site_icon', 99);
        
        // Remove theme OG tags (common action names)
        remove_action('wp_head', 'add_opengraph', 99);
        remove_action('wp_head', 'opengraph', 99);
        
        // RankMath
        if (class_exists('RankMath')) {
            add_filter('rank_math/opengraph/facebook/add_images', '__return_false', 99);
            add_filter('rank_math/opengraph/enable', '__return_false', 99);
        }
    }
    
    /**
     * Filter site icon URL for property pages
     */
    public function filter_site_icon_url($url, $size, $blog_id) {
        if (get_query_var('mls_number', false) !== false && !empty($this->property_data)) {
            $featured_image = $this->get_featured_image_url();
            if ($featured_image) {
                return $featured_image;
            }
        }
        return $url;
    }
    
    /**
     * Ensure OG image tags are present (last resort)
     */
    public function ensure_og_image_tags() {
        // Only on property pages
        $mls_number = get_query_var('mls_number', false);
        if ($mls_number === false) {
            return;
        }
        
        // Check if we already output an image tag by looking at the output buffer
        $output = ob_get_contents();
        if ($output && strpos($output, 'property="og:image"') !== false) {
            // Already has OG image, don't duplicate
            return;
        }
        
        // If no OG image found, try to output one now
        if (empty($this->property_data)) {
            $listing = MLD_Query::get_listing_details($mls_number);
            if (!empty($listing)) {
                $this->property_data = $listing;
            } else {
                return;
            }
        }
        
        $featured_image = $this->get_featured_image_url();
        if ($featured_image) {
            echo "\n<!-- MLD SEO Fallback Image -->\n";
            echo '<meta property="og:image" content="' . esc_url($featured_image) . '" />' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url(str_replace('http://', 'https://', $featured_image)) . '" />' . "\n";
        }
    }
}

/**
 * Output visual breadcrumbs HTML
 */
function mld_output_visual_breadcrumbs($property_data) {
    if (empty($property_data)) {
        return;
    }

    $state_code = !empty($property_data['state_or_province']) ? $property_data['state_or_province'] : 'MA';
    $state_slug = strtolower($state_code) === 'ma' ? 'massachusetts' : strtolower($state_code);
    $city = !empty($property_data['city']) ? $property_data['city'] : '';
    $city_slug = sanitize_title($city);
    $state_lower = strtolower($state_code);

    // Get search page URL from settings
    $mld_settings = get_option('mld_settings', array());
    $search_page_url = !empty($mld_settings['search_page_url']) ? $mld_settings['search_page_url'] : '/search/';

    // Get street address
    $street_address = '';
    if (!empty($property_data['street_number']) && !empty($property_data['street_name'])) {
        $street_address = trim($property_data['street_number'] . ' ' . $property_data['street_name']);
    } elseif (!empty($property_data['full_street_address'])) {
        $street_address = $property_data['full_street_address'];
    } elseif (!empty($property_data['unparsed_address'])) {
        $parts = explode(',', $property_data['unparsed_address']);
        $street_address = trim($parts[0]);
    }

    ?>
    <nav class="mld-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
        <span class="separator"> &gt; </span>
        <a href="<?php echo esc_url(home_url('/homes-for-sale-in-' . $state_slug . '/')); ?>"><?php echo esc_html($state_code); ?></a>
        <?php if ($city): ?>
            <span class="separator"> &gt; </span>
            <a href="<?php echo esc_url(home_url($search_page_url . '#City=' . urlencode($city) . '&PropertyType=Residential&status=Active')); ?>"><?php echo esc_html($city); ?></a>
        <?php endif; ?>
        <span class="separator"> &gt; </span>
        <span class="current"><?php echo esc_html($street_address); ?></span>
    </nav>
    <style>
    .mld-breadcrumbs {
        background: #f8f9fa;
        padding: 12px 20px;
        border-bottom: 1px solid #e0e0e0;
        font-size: 14px;
        margin-bottom: 20px;
    }
    .mld-breadcrumbs a {
        color: #667eea;
        text-decoration: none;
    }
    .mld-breadcrumbs a:hover {
        text-decoration: underline;
    }
    .mld-breadcrumbs .separator {
        color: #999;
        margin: 0 8px;
    }
    .mld-breadcrumbs .current {
        color: #333;
        font-weight: 500;
    }
    </style>
    <?php
}

/**
 * Convert MLS property types to SEO-friendly labels
 *
 * @param string $property_type The MLS property type
 * @param string $property_sub_type Optional property sub-type for more specific labeling
 * @return string SEO-friendly property type label
 */
function mld_get_seo_property_type($property_type, $property_sub_type = '') {
    if (empty($property_type)) {
        return 'Property';
    }

    // Normalize property type for comparison
    $type_lower = strtolower(trim($property_type));

    // Map MLS property types to SEO-friendly labels
    $type_map = array(
        'residential' => 'Home for Sale',
        'residential lease' => 'Property for Rent',
        'land' => 'Land for Sale',
        'commercial sale' => 'Commercial Property for Sale',
        'commercial lease' => 'Commercial Property for Lease',
        'business opportunity' => 'Business for Sale',
        'residential income' => 'Multi-Family Property for Sale',
    );

    // Check if we have a mapping for this type
    if (isset($type_map[$type_lower])) {
        return $type_map[$type_lower];
    }

    // Fallback: clean up the original type
    // If it contains "Lease", it's for rent
    if (stripos($type_lower, 'lease') !== false) {
        return str_replace('Lease', 'for Lease', $property_type);
    }

    // If it contains "Sale", keep it
    if (stripos($type_lower, 'sale') !== false) {
        return $property_type;
    }

    // Default fallback
    return $property_type . ' for Sale';
}
