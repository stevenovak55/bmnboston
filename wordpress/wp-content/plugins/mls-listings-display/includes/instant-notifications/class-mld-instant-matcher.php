<?php
/**
 * MLD Instant Matcher - Real-time property matching system
 *
 * Listens to Bridge Extractor hooks and matches listings against saved searches
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Instant_Matcher {

    /**
     * Throttle manager instance
     */
    private $throttle_manager = null;

    /**
     * Router instance
     */
    private $notification_router = null;

    /**
     * Match score for current matching operation
     */
    private $match_score = 0;

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into Bridge Extractor events
        add_action('bme_listing_imported', [$this, 'handle_new_listing'], 10, 3);
        add_action('bme_listing_updated', [$this, 'handle_updated_listing'], 10, 4);
        add_action('bme_listing_price_reduced', [$this, 'handle_price_reduction'], 10, 4);
        add_action('bme_listing_status_changed', [$this, 'handle_status_change'], 10, 4);

        // Hook into additional Bridge Extractor events if they exist
        add_action('bme_listing_price_increased', [$this, 'handle_price_increase'], 10, 4);
        add_action('bme_property_updated', [$this, 'handle_property_update'], 10, 4);
        add_action('bme_open_house_scheduled', [$this, 'handle_open_house'], 10, 3);

        // Hook into MLD Alert Types system for missing events
        add_action('mld_check_open_houses', [$this, 'check_open_houses_for_instant'], 10);
        add_action('mld_check_coming_soon', [$this, 'check_coming_soon_for_instant'], 10);

        // Hook for delayed media validation
        add_action('mld_delayed_media_check', [$this, 'handle_delayed_media_check'], 10, 4);

        // Custom hooks for digest notifications (triggered by cron)
        add_action('mld_process_digest_notifications', [$this, 'handle_digest_notifications'], 10, 2);

        $this->log('Instant Matcher initialized and listening for Bridge Extractor hooks + enhanced alert detection', 'info');
    }

    /**
     * Set throttle manager
     */
    public function set_throttle_manager($throttle_manager) {
        $this->throttle_manager = $throttle_manager;
    }

    /**
     * Set notification router
     */
    public function set_notification_router($router) {
        $this->notification_router = $router;
    }

    /**
     * Handle new listing import - CHECK ALL SAVED SEARCHES
     */
    public function handle_new_listing($listing_id, $listing_data, $metadata) {
        $start_time = microtime(true);

        $this->log("Processing new listing: $listing_id", 'info');

        // Skip archived listings
        if (isset($metadata['table']) && $metadata['table'] === 'archive') {
            $this->log("Skipping archived listing: $listing_id", 'debug');
            return;
        }

        // Skip non-active statuses - check metadata status from Bridge Extractor first
        // This prevents false alerts for Closed/Under Agreement listings after database cleanup
        $metadata_status = $metadata['status'] ?? null;
        if ($metadata_status && !in_array($metadata_status, ['Active', 'Coming Soon'])) {
            $this->log("Skipping non-active listing $listing_id with status from metadata: $metadata_status", 'debug');
            return;
        }

        // Log incoming data for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log("Incoming data keys: " . implode(', ', array_keys((array)$listing_data)), 'debug');
            $this->log("Status: " . ($listing_data['StandardStatus'] ?? $listing_data['standard_status'] ?? 'unknown'), 'debug');
            $this->log("City: " . ($listing_data['City'] ?? $listing_data['city'] ?? 'unknown'), 'debug');

            // Log media availability information
            $media_count = $metadata['media_count'] ?? 'unknown';
            $this->log("Media count available: " . $media_count, 'debug');
            $this->log("All data processed: " . ($metadata['all_data_processed'] ?? 'false'), 'debug');
        }

        // If listing data is incomplete, fetch from database
        if (empty($listing_data) || !isset($listing_data['PropertyType']) || !isset($listing_data['City'])) {
            global $wpdb;
            $db_listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bme_listings WHERE listing_id = %s",
                $listing_id
            ), ARRAY_A);

            if ($db_listing) {
                // Merge database data with provided data
                $listing_data = array_merge($db_listing, (array)$listing_data);

                // Ensure proper field mapping for both formats (CamelCase and snake_case)
                $listing_data['PropertyType'] = $listing_data['PropertyType'] ??
                                                $listing_data['property_type'] ??
                                                'Residential';
                $listing_data['property_type'] = strtolower($listing_data['PropertyType']);

                $listing_data['StandardStatus'] = $listing_data['StandardStatus'] ??
                                                  $listing_data['standard_status'] ??
                                                  $metadata['status'] ??  // Use metadata as fallback
                                                  null;  // Don't assume Active - prevents false alerts
                $listing_data['standard_status'] = $listing_data['StandardStatus'];

                $listing_data['ListPrice'] = $listing_data['ListPrice'] ??
                                            $listing_data['list_price'] ??
                                            0;
                $listing_data['list_price'] = $listing_data['ListPrice'];

                // Map additional fields for matching algorithm
                $listing_data['BedroomsTotal'] = $listing_data['BedroomsTotal'] ??
                                                 $listing_data['bedrooms_total'] ??
                                                 0;
                $listing_data['bedrooms_total'] = $listing_data['BedroomsTotal'];

                $listing_data['BathroomsTotalInteger'] = $listing_data['BathroomsTotalInteger'] ??
                                                         $listing_data['bathrooms_total'] ??
                                                         0;
                $listing_data['bathrooms_total'] = $listing_data['BathroomsTotalInteger'];

                $listing_data['LivingArea'] = $listing_data['LivingArea'] ??
                                              $listing_data['living_area'] ??
                                              0;
                $listing_data['living_area'] = $listing_data['LivingArea'];

                // For address components, provide defaults if missing
                $listing_data['StreetNumber'] = $listing_data['StreetNumber'] ??
                                                $listing_data['street_number'] ??
                                                '';
                $listing_data['StreetName'] = $listing_data['StreetName'] ??
                                              $listing_data['street_name'] ??
                                              '';
                $listing_data['City'] = $listing_data['City'] ??
                                       $listing_data['city'] ??
                                       'Reading'; // Default city if not specified

                $this->log("Enhanced listing data for $listing_id - Type: {$listing_data['PropertyType']}, City: {$listing_data['City']}", 'debug');
            }
        }

        // Get all active saved searches with instant notifications
        $searches = $this->get_instant_searches();

        if (empty($searches)) {
            $this->log('No instant searches found', 'debug');
            return;
        }

        $matches_found = 0;
        // v6.68.15: Get listing import timestamp for search creation date filtering
        $listing_timestamp = $listing_data['modification_timestamp'] ?? $listing_data['ModificationTimestamp'] ?? null;

        foreach ($searches as $search) {
            // v6.68.15: Skip if search was created AFTER this listing was imported
            // This prevents new searches from receiving alerts for properties that existed before the search
            // v6.75.4: Use DateTime with wp_timezone() - database stores in WP timezone, not UTC
            if (!empty($search->created_at) && !empty($listing_timestamp)) {
                $search_created = (new \DateTime($search->created_at, wp_timezone()))->getTimestamp();
                $listing_modified = (new \DateTime($listing_timestamp, wp_timezone()))->getTimestamp();
                if ($listing_modified > 0 && $search_created > $listing_modified) {
                    $this->log("Skipping search {$search->id} for listing {$listing_id} - search created at {$search->created_at}, listing modified at {$listing_timestamp}", 'debug');
                    continue;
                }
            }

            if ($this->listing_matches_search($listing_data, $search, $metadata)) {
                $this->queue_instant_notification($search, $listing_id, 'new_listing', $listing_data);
                $matches_found++;
                $this->log("Match found for search {$search->id}: {$search->name}", 'info');
            }
        }

        // Log performance metrics
        $execution_time = microtime(true) - $start_time;
        $this->log_performance($listing_id, count($searches), $matches_found, $execution_time);
    }

    /**
     * Handle updated listing
     */
    public function handle_updated_listing($listing_id, $old_data, $listing_data, $metadata) {
        $start_time = microtime(true);

        $this->log("Processing updated listing: $listing_id", 'info');

        // Skip archived listings
        if (isset($metadata['table']) && $metadata['table'] === 'archive') {
            return;
        }

        // Get all active saved searches
        $searches = $this->get_instant_searches();

        $matches_found = 0;
        // v6.68.15: Get listing timestamp for search creation date filtering
        $listing_timestamp = $listing_data['modification_timestamp'] ?? $listing_data['ModificationTimestamp'] ?? null;

        foreach ($searches as $search) {
            // v6.68.15: Skip if search was created AFTER this listing was last modified
            // v6.75.4: Use DateTime with wp_timezone() - database stores in WP timezone, not UTC
            if (!empty($search->created_at) && !empty($listing_timestamp)) {
                $search_created = (new \DateTime($search->created_at, wp_timezone()))->getTimestamp();
                $listing_modified = (new \DateTime($listing_timestamp, wp_timezone()))->getTimestamp();
                if ($listing_modified > 0 && $search_created > $listing_modified) {
                    continue;
                }
            }

            // Check if this listing now matches (might not have matched before)
            $matches_now = $this->listing_matches_search($listing_data, $search, $metadata);

            if ($matches_now) {
                // Check if significant changes warrant a notification
                if ($this->has_significant_changes($metadata['changes'] ?? [])) {
                    $this->queue_instant_notification($search, $listing_id, 'updated', $listing_data);
                    $matches_found++;
                }
            }
        }

        $execution_time = microtime(true) - $start_time;
        $this->log_performance($listing_id, count($searches), $matches_found, $execution_time);
    }

    /**
     * Handle price reduction
     */
    public function handle_price_reduction($listing_id, $old_price, $new_price, $listing_data) {
        $this->log("Processing price reduction for listing: $listing_id ($old_price -> $new_price)", 'info');

        // Get searches that match this listing
        $searches = $this->get_instant_searches();

        // v6.68.15: Get listing timestamp for search creation date filtering
        $listing_timestamp = $listing_data['modification_timestamp'] ?? $listing_data['ModificationTimestamp'] ?? null;

        foreach ($searches as $search) {
            // v6.68.15: Skip if search was created AFTER this listing was last modified
            // v6.75.4: Use DateTime with wp_timezone() - database stores in WP timezone, not UTC
            if (!empty($search->created_at) && !empty($listing_timestamp)) {
                $search_created = (new \DateTime($search->created_at, wp_timezone()))->getTimestamp();
                $listing_modified = (new \DateTime($listing_timestamp, wp_timezone()))->getTimestamp();
                if ($listing_modified > 0 && $search_created > $listing_modified) {
                    continue;
                }
            }

            if ($this->listing_matches_search($listing_data, $search)) {
                $reduction_data = array_merge($listing_data, [
                    'old_price' => $old_price,
                    'new_price' => $new_price,
                    'reduction_amount' => $old_price - $new_price,
                    'reduction_percent' => round((($old_price - $new_price) / $old_price) * 100, 1)
                ]);

                $this->queue_instant_notification($search, $listing_id, 'price_drop', $reduction_data);
            }
        }
    }

    /**
     * Handle status change
     */
    public function handle_status_change($listing_id, $old_status, $new_status, $listing_data) {
        $this->log("Processing status change for listing: $listing_id ($old_status -> $new_status)", 'info');

        // Back on market is special case
        if ($old_status === 'Active Under Contract' && $new_status === 'Active') {
            $notification_type = 'back_on_market';
        } else {
            $notification_type = 'status_change';
        }

        $searches = $this->get_instant_searches();

        // v6.68.15: Get listing timestamp for search creation date filtering
        $listing_timestamp = $listing_data['modification_timestamp'] ?? $listing_data['ModificationTimestamp'] ?? null;

        foreach ($searches as $search) {
            // v6.68.15: Skip if search was created AFTER this listing was last modified
            // v6.75.4: Use DateTime with wp_timezone() - database stores in WP timezone, not UTC
            if (!empty($search->created_at) && !empty($listing_timestamp)) {
                $search_created = (new \DateTime($search->created_at, wp_timezone()))->getTimestamp();
                $listing_modified = (new \DateTime($listing_timestamp, wp_timezone()))->getTimestamp();
                if ($listing_modified > 0 && $search_created > $listing_modified) {
                    continue;
                }
            }

            if ($this->listing_matches_search($listing_data, $search)) {
                $status_data = array_merge($listing_data, [
                    'old_status' => $old_status,
                    'new_status' => $new_status
                ]);

                $this->queue_instant_notification($search, $listing_id, $notification_type, $status_data);
            }
        }
    }

    /**
     * Core matching algorithm
     */
    private function listing_matches_search($listing, $search, $metadata = []) {
        $filters = json_decode($search->filters, true);

        // Empty filters means match all properties
        if (empty($filters)) return true;

        $this->match_score = 100;

        // Price Range Check (High Priority)
        if (!$this->matches_price_range($listing, $filters)) {
            return false;
        }

        // Location Check (Critical)
        if (!$this->matches_location($listing, $filters, $metadata)) {
            return false;
        }

        // Property Type Check
        if (!$this->matches_property_type($listing, $filters)) {
            return false;
        }

        // Beds/Baths Check
        if (!$this->matches_beds_baths($listing, $filters)) {
            return false;
        }

        // Square Footage Check
        if (!$this->matches_square_footage($listing, $filters)) {
            return false;
        }

        // Year Built Check
        if (!$this->matches_year_built($listing, $filters)) {
            return false;
        }

        // Polygon Boundary Check (if any)
        if (!$this->matches_polygon_boundaries($listing, $search)) {
            return false;
        }

        // Status Check
        if (!$this->matches_status($listing, $filters)) {
            return false;
        }

        // School Criteria Check (v6.68.13)
        if (!$this->matches_school_criteria($listing, $filters)) {
            return false;
        }

        // Lot Size Check (v6.72.1)
        if (!$this->matches_lot_size($listing, $filters)) {
            return false;
        }

        // Parking/Garage Check (v6.72.1)
        if (!$this->matches_parking($listing, $filters)) {
            return false;
        }

        // Amenities Check (v6.72.1)
        if (!$this->matches_amenities($listing, $filters)) {
            return false;
        }

        // Rental-specific Filters Check (v6.72.1)
        if (!$this->matches_rental_filters($listing, $filters)) {
            return false;
        }

        // Special Filters Check (v6.72.1)
        if (!$this->matches_special_filters($listing, $filters)) {
            return false;
        }

        // All checks passed!
        return true;
    }

    /**
     * Price range matching
     */
    private function matches_price_range($listing, $filters) {
        $price = $listing['ListPrice'] ?? $listing['list_price'] ?? 0;

        if (isset($filters['price_min']) && $price < $filters['price_min']) {
            return false;
        }

        if (isset($filters['price_max']) && $price > $filters['price_max']) {
            return false;
        }

        return true;
    }

    /**
     * Location matching - handles multiple location types
     */
    private function matches_location($listing, $filters, $metadata) {
        // City matching - check multiple possible field names
        $city_filters = [];

        // Collect all city-related filters
        if (!empty($filters['selected_cities'])) {
            $city_filters = array_merge($city_filters, (array)$filters['selected_cities']);
        }
        if (!empty($filters['keyword_City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['keyword_City']);
        }
        if (!empty($filters['City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['City']);
        }

        // If city filters exist, check them
        if (!empty($city_filters)) {
            $listing_city = $listing['City'] ?? $metadata['city'] ?? '';
            if (!in_array($listing_city, $city_filters)) {
                return false;
            }
        }

        // Zip code matching
        if (!empty($filters['keyword_PostalCode'])) {
            $listing_zip = $listing['PostalCode'] ?? '';
            if (!in_array($listing_zip, $filters['keyword_PostalCode'])) {
                return false;
            }
        }

        // Neighborhood matching
        if (!empty($filters['selected_neighborhoods']) || !empty($filters['keyword_Neighborhood'])) {
            $neighborhoods = array_merge(
                $filters['selected_neighborhoods'] ?? [],
                $filters['keyword_Neighborhood'] ?? []
            );

            $listing_neighborhood = $listing['Subdivision'] ?? '';
            if (!empty($neighborhoods) && !in_array($listing_neighborhood, $neighborhoods)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Property type matching
     */
    private function matches_property_type($listing, $filters) {
        // Check both singular and plural forms of property type filters
        $has_type_filter = !empty($filters['property_types']) || !empty($filters['PropertyType']);
        $has_subtype_filter = !empty($filters['property_sub_types']) || !empty($filters['PropertySubType']);

        if (!$has_type_filter && !$has_subtype_filter) {
            return true;
        }

        $property_type = $listing['PropertyType'] ?? $listing['property_type'] ?? '';
        $property_sub_type = $listing['PropertySubType'] ?? $listing['property_sub_type'] ?? '';

        // Check property_types array or PropertyType string
        if (!empty($filters['property_types'])) {
            if (!in_array($property_type, $filters['property_types'])) {
                return false;
            }
        } elseif (!empty($filters['PropertyType'])) {
            // Handle singular PropertyType field
            if (is_array($filters['PropertyType'])) {
                if (!in_array($property_type, $filters['PropertyType'])) {
                    return false;
                }
            } else {
                if ($property_type !== $filters['PropertyType']) {
                    return false;
                }
            }
        }

        // Check property sub types
        if (!empty($filters['property_sub_types'])) {
            if (!in_array($property_sub_type, $filters['property_sub_types'])) {
                return false;
            }
        } elseif (!empty($filters['PropertySubType'])) {
            if (is_array($filters['PropertySubType'])) {
                if (!in_array($property_sub_type, $filters['PropertySubType'])) {
                    return false;
                }
            } else {
                if ($property_sub_type !== $filters['PropertySubType']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Beds/Baths matching
     */
    private function matches_beds_baths($listing, $filters) {
        $beds = $listing['BedroomsTotal'] ?? $listing['bedrooms_total'] ?? 0;
        $baths = $listing['BathroomsTotalInteger'] ?? $listing['bathrooms_total'] ?? 0;

        // Check beds - could be 'beds_min', 'beds', or other variations
        if (isset($filters['beds_min']) && $beds < $filters['beds_min']) {
            return false;
        }

        // Handle 'beds' as an array (specific bed counts)
        if (!empty($filters['beds']) && is_array($filters['beds'])) {
            if (!in_array($beds, $filters['beds'])) {
                return false;
            }
        }

        // Check baths
        if (isset($filters['baths_min']) && $baths < $filters['baths_min']) {
            return false;
        }

        return true;
    }

    /**
     * Square footage matching
     */
    private function matches_square_footage($listing, $filters) {
        $sqft = $listing['LivingArea'] ?? $listing['living_area'] ?? 0;

        if (isset($filters['sqft_min']) && $sqft < $filters['sqft_min']) {
            return false;
        }

        if (isset($filters['sqft_max']) && $sqft > $filters['sqft_max']) {
            return false;
        }

        return true;
    }

    /**
     * Year built matching (v6.72.1 - added year_built_max support)
     */
    private function matches_year_built($listing, $filters) {
        // If no year filters, pass
        if (!isset($filters['year_built_min']) && !isset($filters['year_built_max'])) {
            return true;
        }

        $year_built = $listing['YearBuilt'] ?? $listing['year_built'] ?? 0;

        // Check minimum year
        if (isset($filters['year_built_min']) && $year_built < $filters['year_built_min']) {
            return false;
        }

        // Check maximum year
        if (isset($filters['year_built_max']) && $year_built > $filters['year_built_max']) {
            return false;
        }

        return true;
    }

    /**
     * Status matching
     */
    private function matches_status($listing, $filters) {
        // Check both 'statuses' and 'status' fields
        $status_filter = $filters['statuses'] ?? $filters['status'] ?? null;

        if (empty($status_filter)) {
            // If no status filter specified, accept Active and Coming Soon listings
            $status = $listing['StandardStatus'] ?? $listing['standard_status'] ?? '';
            // Empty/unknown status should NOT match - prevents false alerts for
            // listings with missing status data after database cleanup
            if (empty($status)) {
                $this->log("Rejecting listing with empty/unknown status in matches_status()", 'debug');
                return false;
            }
            return in_array($status, ['Active', 'Coming Soon', 'active', 'coming soon']);
        }

        $status = $listing['StandardStatus'] ?? $listing['standard_status'] ?? '';

        if (is_array($status_filter)) {
            return in_array($status, $status_filter);
        } else {
            return $status === $status_filter;
        }
    }

    /**
     * Check if listing matches school criteria (v6.68.13)
     *
     * @param array $listing Listing data
     * @param array $filters Search filters
     * @return bool True if listing matches school criteria
     */
    private function matches_school_criteria($listing, $filters) {
        // School filter keys to check
        $school_filter_keys = [
            'school_grade', 'school_district_id',
            'near_a_elementary', 'near_ab_elementary',
            'near_a_middle', 'near_ab_middle',
            'near_a_high', 'near_ab_high',
            // Legacy filters
            'near_top_elementary', 'near_top_high',
        ];

        // Check if any school filters are set
        $has_school_filters = false;
        foreach ($school_filter_keys as $key) {
            if (!empty($filters[$key])) {
                $has_school_filters = true;
                break;
            }
        }

        // No school filters = passes
        if (!$has_school_filters) {
            return true;
        }

        // Check if BMN Schools Integration is available
        if (!class_exists('MLD_BMN_Schools_Integration')) {
            $this->log('BMN_Schools_Integration not available - school filters bypassed for instant notifications', 'warning');
            return true; // Graceful degradation
        }

        $schools_integration = MLD_BMN_Schools_Integration::get_instance();

        // Get city from listing (handle both CamelCase and snake_case)
        $city = $listing['City'] ?? $listing['city'] ?? null;

        // School Grade filter (district average)
        if (!empty($filters['school_grade'])) {
            if (empty($city)) {
                $this->log("School filter: No city for listing, rejecting", 'debug');
                return false;
            }

            // v6.68.14: Use get_district_grade_for_city() for consistency with API display
            // This uses district_rankings table (same as property detail page shows)
            $district_info = $schools_integration->get_district_grade_for_city($city);
            if (!$district_info || empty($district_info['grade'])) {
                $this->log("School filter: No district grade for city '{$city}', rejecting", 'debug');
                return false;
            }

            $district_grade = $district_info['grade'];
            if (!$this->grade_meets_minimum($district_grade, $filters['school_grade'])) {
                $this->log("School filter: District grade '{$district_grade}' doesn't meet minimum '{$filters['school_grade']}' for city '{$city}'", 'debug');
                return false;
            }

            $this->log("School filter: City '{$city}' passes with district grade '{$district_grade}'", 'debug');
        }

        // Get coordinates for proximity-based filters
        $lat = $listing['Latitude'] ?? $listing['latitude'] ?? null;
        $lng = $listing['Longitude'] ?? $listing['longitude'] ?? null;

        // School District ID filter
        if (!empty($filters['school_district_id'])) {
            if (!$lat || !$lng) {
                return false;
            }
            $district = $schools_integration->get_district_for_point($lat, $lng);
            if (!$district || $district['id'] != $filters['school_district_id']) {
                return false;
            }
        }

        // Level-specific proximity filters (1 mile radius)
        // Near A-rated elementary
        if (!empty($filters['near_a_elementary'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'elementary', 1.0, 'A-')) {
                return false;
            }
        }

        // Near A/B-rated elementary
        if (!empty($filters['near_ab_elementary'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'elementary', 1.0, 'B-')) {
                return false;
            }
        }

        // Near A-rated middle
        if (!empty($filters['near_a_middle'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'middle', 1.0, 'A-')) {
                return false;
            }
        }

        // Near A/B-rated middle
        if (!empty($filters['near_ab_middle'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'middle', 1.0, 'B-')) {
                return false;
            }
        }

        // Near A-rated high school
        if (!empty($filters['near_a_high'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'high', 1.0, 'A-')) {
                return false;
            }
        }

        // Near A/B-rated high school
        if (!empty($filters['near_ab_high'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'high', 1.0, 'B-')) {
                return false;
            }
        }

        // Legacy filters (2-3 mile radius)
        if (!empty($filters['near_top_elementary'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'elementary', 2.0, 'A')) {
                return false;
            }
        }

        if (!empty($filters['near_top_high'])) {
            if (!$lat || !$lng || !$schools_integration->property_near_top_school($lat, $lng, 'high', 3.0, 'A')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if grade meets minimum requirement (v6.68.13)
     *
     * @param string $grade The grade to check (e.g., 'A', 'B+')
     * @param string $min_grade Minimum required grade
     * @return bool True if grade meets or exceeds minimum
     */
    private function grade_meets_minimum($grade, $min_grade) {
        $grade_order = [
            'A+' => 12, 'A' => 11, 'A-' => 10,
            'B+' => 9,  'B' => 8,  'B-' => 7,
            'C+' => 6,  'C' => 5,  'C-' => 4,
            'D+' => 3,  'D' => 2,  'D-' => 1,
            'F' => 0
        ];

        // When min_grade is a single letter (A, B, C, D), include all variants
        // So "A" means A+, A, A- all pass; treat single letter as the "-" variant
        if (strlen($min_grade) === 1 && $min_grade !== 'F') {
            $min_grade = $min_grade . '-';
        }

        $grade_value = $grade_order[$grade] ?? 0;
        $min_value = $grade_order[$min_grade] ?? 0;

        return $grade_value >= $min_value;
    }

    /**
     * Check if listing matches lot size criteria (v6.72.1)
     */
    private function matches_lot_size($listing, $filters) {
        // Get lot size - handle both CamelCase and snake_case
        $lot_size = $listing['LotSizeAcres'] ?? $listing['lot_size_acres'] ?? null;

        // If no lot size data, don't filter out (graceful degradation)
        if ($lot_size === null) {
            return true;
        }

        // Check minimum lot size
        if (!empty($filters['lot_size_min']) && $lot_size < $filters['lot_size_min']) {
            return false;
        }

        // Check maximum lot size
        if (!empty($filters['lot_size_max']) && $lot_size > $filters['lot_size_max']) {
            return false;
        }

        return true;
    }

    /**
     * Check if listing matches parking/garage criteria (v6.72.1)
     */
    private function matches_parking($listing, $filters) {
        // Check garage spaces
        if (!empty($filters['garage_spaces_min'])) {
            $garage_spaces = $listing['GarageSpaces'] ?? $listing['garage_spaces'] ?? 0;
            if ($garage_spaces < $filters['garage_spaces_min']) {
                return false;
            }
        }

        // Check total parking
        if (!empty($filters['parking_total_min'])) {
            $parking_total = $listing['ParkingTotal'] ?? $listing['parking_total'] ?? 0;
            if ($parking_total < $filters['parking_total_min']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if listing matches amenity filters (v6.72.1)
     *
     * Handles boolean YN flags for: pool, fireplace, waterfront, view, cooling,
     * spa, outdoor space, senior community, virtual tour
     */
    private function matches_amenities($listing, $filters) {
        // Pool filter
        if (!empty($filters['PoolPrivateYN']) || !empty($filters['has_pool'])) {
            $has_pool = $listing['PoolPrivateYN'] ?? $listing['pool_private_yn'] ?? 'N';
            if (strtoupper($has_pool) !== 'Y') {
                return false;
            }
        }

        // Fireplace filter
        if (!empty($filters['FireplaceYN']) || !empty($filters['has_fireplace'])) {
            $has_fireplace = $listing['FireplaceYN'] ?? $listing['fireplace_yn'] ?? 'N';
            if (strtoupper($has_fireplace) !== 'Y') {
                return false;
            }
        }

        // Waterfront filter
        if (!empty($filters['WaterfrontYN']) || !empty($filters['has_waterfront'])) {
            $has_waterfront = $listing['WaterfrontYN'] ?? $listing['waterfront_yn'] ?? 'N';
            if (strtoupper($has_waterfront) !== 'Y') {
                return false;
            }
        }

        // View filter
        if (!empty($filters['ViewYN']) || !empty($filters['has_view'])) {
            $has_view = $listing['ViewYN'] ?? $listing['view_yn'] ?? 'N';
            if (strtoupper($has_view) !== 'Y') {
                return false;
            }
        }

        // Cooling/AC filter
        if (!empty($filters['CoolingYN']) || !empty($filters['has_cooling'])) {
            $has_cooling = $listing['CoolingYN'] ?? $listing['cooling_yn'] ?? 'N';
            if (strtoupper($has_cooling) !== 'Y') {
                return false;
            }
        }

        // Spa filter
        if (!empty($filters['SpaYN']) || !empty($filters['has_spa'])) {
            $has_spa = $listing['SpaYN'] ?? $listing['spa_yn'] ?? 'N';
            if (strtoupper($has_spa) !== 'Y') {
                return false;
            }
        }

        // Virtual tour filter
        if (!empty($filters['has_virtual_tour'])) {
            $virtual_tour = $listing['VirtualTourURLUnbranded'] ??
                           $listing['virtual_tour_url_unbranded'] ??
                           $listing['VirtualTourURLBranded'] ??
                           $listing['virtual_tour_url_branded'] ?? '';
            if (empty($virtual_tour)) {
                return false;
            }
        }

        // Senior community filter
        if (!empty($filters['SeniorCommunityYN']) || !empty($filters['senior_community'])) {
            $is_senior = $listing['SeniorCommunityYN'] ?? $listing['senior_community_yn'] ?? 'N';
            if (strtoupper($is_senior) !== 'Y') {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if listing matches rental-specific filters (v6.72.1)
     *
     * Handles pet policies and laundry features for rental properties
     */
    private function matches_rental_filters($listing, $filters) {
        $property_type = $listing['PropertyType'] ?? $listing['property_type'] ?? '';

        // Only apply rental filters to rental properties
        if (strtolower($property_type) !== 'residential lease') {
            // If not a rental, don't apply rental filters but don't exclude
            return true;
        }

        // Pet policy filters - check if listing allows specified pets
        if (!empty($filters['pets_dogs'])) {
            $pets_allowed = $listing['PetsAllowed'] ?? $listing['pets_allowed'] ?? '';
            $pets_str = is_array($pets_allowed) ? implode(' ', $pets_allowed) : (string)$pets_allowed;
            if (stripos($pets_str, 'dog') === false && stripos($pets_str, 'yes') === false) {
                return false;
            }
        }

        if (!empty($filters['pets_cats'])) {
            $pets_allowed = $listing['PetsAllowed'] ?? $listing['pets_allowed'] ?? '';
            $pets_str = is_array($pets_allowed) ? implode(' ', $pets_allowed) : (string)$pets_allowed;
            if (stripos($pets_str, 'cat') === false && stripos($pets_str, 'yes') === false) {
                return false;
            }
        }

        if (!empty($filters['pets_none'])) {
            $pets_allowed = $listing['PetsAllowed'] ?? $listing['pets_allowed'] ?? '';
            $pets_str = is_array($pets_allowed) ? implode(' ', $pets_allowed) : (string)$pets_allowed;
            // "No pets" filter - property should NOT allow pets
            if (!empty($pets_str) && stripos($pets_str, 'no') === false) {
                return false;
            }
        }

        if (!empty($filters['pets_negotiable'])) {
            $pets_allowed = $listing['PetsAllowed'] ?? $listing['pets_allowed'] ?? '';
            $pets_str = is_array($pets_allowed) ? implode(' ', $pets_allowed) : (string)$pets_allowed;
            if (stripos($pets_str, 'negotiable') === false && stripos($pets_str, 'conditional') === false) {
                return false;
            }
        }

        // Laundry features filter
        if (!empty($filters['laundry_features'])) {
            $laundry = $listing['LaundryFeatures'] ?? $listing['laundry_features'] ?? '';
            $laundry_str = is_array($laundry) ? implode(' ', $laundry) : (string)$laundry;

            $required_features = is_array($filters['laundry_features'])
                ? $filters['laundry_features']
                : [$filters['laundry_features']];

            foreach ($required_features as $feature) {
                if (stripos($laundry_str, $feature) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if listing matches special filters (v6.72.1)
     *
     * Handles: exclusive_only, price_reduced, max_dom, min_dom, open_house_only
     */
    private function matches_special_filters($listing, $filters) {
        // Exclusive listing filter (listing_id < 1,000,000)
        if (!empty($filters['exclusive_only'])) {
            $listing_id = $listing['listing_id'] ?? $listing['ListingId'] ?? '';
            if (!is_numeric($listing_id) || (int)$listing_id >= 1000000) {
                return false;
            }
        }

        // Price reduced filter - check if listing has had price reduction
        if (!empty($filters['price_reduced'])) {
            // Check for price change indicators
            $original_price = $listing['OriginalListPrice'] ?? $listing['original_list_price'] ?? 0;
            $current_price = $listing['ListPrice'] ?? $listing['list_price'] ?? 0;

            // If we have both prices and current is less than original = price reduced
            if ($original_price > 0 && $current_price > 0 && $current_price >= $original_price) {
                return false; // Not price reduced
            }

            // Also check for explicit price change flag if available
            $price_change = $listing['PriceChangeTimestamp'] ?? $listing['price_change_timestamp'] ?? null;
            if (!$price_change && $original_price <= 0) {
                // No price change data available, can't determine
                return false;
            }
        }

        // Days on market filters
        $dom = $listing['DaysOnMarket'] ?? $listing['days_on_market'] ?? null;

        if (!empty($filters['max_dom']) && $dom !== null) {
            if ($dom > $filters['max_dom']) {
                return false;
            }
        }

        if (!empty($filters['min_dom']) && $dom !== null) {
            if ($dom < $filters['min_dom']) {
                return false;
            }
        }

        // Open house filter - check if listing has upcoming open house
        if (!empty($filters['open_house_only'])) {
            global $wpdb;

            $listing_id = $listing['listing_id'] ?? $listing['ListingId'] ?? '';
            if (empty($listing_id)) {
                return false;
            }

            // Check for upcoming open houses
            $wp_today = wp_date('Y-m-d');
            $has_open_house = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bme_open_houses
                 WHERE listing_id = %s AND open_house_date >= %s",
                $listing_id,
                $wp_today
            ));

            if (!$has_open_house) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if point is within polygon boundaries
     */
    private function matches_polygon_boundaries($listing, $search) {
        if (empty($search->polygon_shapes)) {
            return true;
        }

        $lat = $listing['Latitude'] ?? $listing['latitude'] ?? null;
        $lng = $listing['Longitude'] ?? $listing['longitude'] ?? null;

        if (!$lat || !$lng) {
            return false; // Can't match without coordinates
        }

        $polygons = json_decode($search->polygon_shapes, true);
        if (empty($polygons)) {
            return true;
        }

        foreach ($polygons as $polygon) {
            if ($this->point_in_polygon($lat, $lng, $polygon['coordinates'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ray-casting algorithm for point-in-polygon test
     */
    private function point_in_polygon($lat, $lng, $polygon) {
        if (empty($polygon)) {
            return false;
        }

        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $lat_i = $polygon[$i]['lat'] ?? $polygon[$i][0] ?? 0;
            $lng_i = $polygon[$i]['lng'] ?? $polygon[$i][1] ?? 0;
            $lat_j = $polygon[$j]['lat'] ?? $polygon[$j][0] ?? 0;
            $lng_j = $polygon[$j]['lng'] ?? $polygon[$j][1] ?? 0;

            if ((($lng_i > $lng) != ($lng_j > $lng)) &&
                ($lat < ($lat_j - $lat_i) * ($lng - $lng_i) / ($lng_j - $lng_i) + $lat_i)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Check if changes are significant enough for notification
     */
    private function has_significant_changes($changes) {
        if (empty($changes)) {
            return false;
        }

        // These changes are considered significant
        $significant_fields = ['list_price', 'standard_status', 'bedrooms_total', 'bathrooms_total'];

        foreach ($significant_fields as $field) {
            if (isset($changes[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all instant searches
     */
    private function get_instant_searches() {
        global $wpdb;

        $searches = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mld_saved_searches
             WHERE notification_frequency = 'instant'
             AND is_active = 1"
        );

        return $searches;
    }

    /**
     * Queue notification for sending
     */
    private function queue_instant_notification($search, $listing_id, $type, $listing_data) {
        global $wpdb;

        // Enhanced media validation for new listings to ensure images are imported before sending emails
        if ($type === 'new_listing') {
            $media_validation = $this->validate_listing_media($listing_id);

            if (!$media_validation['has_media']) {
                // If no media found, delay notification to allow for media processing
                $this->log("Media validation failed for listing $listing_id: {$media_validation['message']}. Delaying notification.", 'warning');

                // Schedule a delayed check in 30 seconds to allow media processing
                wp_schedule_single_event(time() + 30, 'mld_delayed_media_check', [$search, $listing_id, $type, $listing_data]);
                return;
            } else {
                $this->log("Media validation passed: {$media_validation['count']} photos found for listing $listing_id", 'debug');
            }
        }

        // Check throttling
        if ($this->throttle_manager) {
            $throttle_result = $this->throttle_manager->should_send_instant($search->user_id, $search->id, $type, $listing_data);

            // Handle blocked notifications
            if (is_array($throttle_result) && isset($throttle_result['blocked']) && $throttle_result['blocked']) {
                $this->log("Notification blocked for user {$search->user_id}, search {$search->id}, reason: {$throttle_result['reason']}", 'debug');

                // Add to queue for later processing
                $this->add_to_notification_queue($search, $listing_id, $type, $listing_data, $throttle_result);
                return;
            } elseif ($throttle_result === false) {
                // Legacy format - just log and return
                $this->log("Notification throttled for user {$search->user_id}, search {$search->id}", 'debug');
                return;
            }
        }

        // Get activity log ID if available
        $activity_log_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bme_activity_logs
             WHERE mls_id = %s
             ORDER BY created_at DESC
             LIMIT 1",
            $listing_id
        ));

        // Record the match
        $match_data = [
            'activity_log_id' => $activity_log_id ?: 0,
            'saved_search_id' => $search->id,
            'listing_id' => $listing_id,
            'match_type' => $type,
            'match_score' => $this->match_score,
            'notification_status' => 'pending',
            'created_at' => current_time('mysql')
        ];

        $result = $wpdb->insert($wpdb->prefix . 'mld_search_activity_matches', $match_data);
        $match_id = $wpdb->insert_id;

        if (!$result) {
            $this->log("Failed to insert match record: " . $wpdb->last_error, 'error');
        }

        // Send through unified dispatcher
        if (!class_exists('MLD_Notification_Dispatcher')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-notification-dispatcher.php';
        }

        $dispatcher = MLD_Notification_Dispatcher::get_instance();
        $context = [
            'match_id' => $match_id,
            'match_score' => $this->match_score,
            'listing_id' => $listing_id
        ];

        $result = $dispatcher->dispatch_notification($search->user_id, $listing_data, $type, $context, $search);

        // Update match record with result
        $wpdb->update(
            $wpdb->prefix . 'mld_search_activity_matches',
            [
                'notification_status' => $result['success'] ? 'sent' : 'failed',
                'notified_at' => current_time('mysql'),
                'notification_channels' => json_encode(array_keys($result['channels'] ?? [])),
                'error_message' => implode('; ', $result['errors'] ?? [])
            ],
            ['id' => $match_id]
        );
    }

    /**
     * Add blocked notification to queue
     */
    private function add_to_notification_queue($search, $listing_id, $type, $listing_data, $throttle_result) {
        global $wpdb;

        // Prepare queue data
        $queue_data = [
            'user_id' => $search->user_id,
            'saved_search_id' => $search->id,
            'listing_id' => $listing_id,
            'match_type' => $type,
            'listing_data' => json_encode($listing_data),
            'reason_blocked' => $throttle_result['reason'],
            'retry_after' => $throttle_result['retry_after'],
            'created_at' => current_time('mysql')
        ];

        $result = $wpdb->insert($wpdb->prefix . 'mld_notification_queue', $queue_data);

        if ($result) {
            $this->log("Notification queued for user {$search->user_id}, listing {$listing_id}, retry after: {$throttle_result['retry_after']}", 'info');
        } else {
            $this->log("Failed to queue notification: " . $wpdb->last_error, 'error');
        }
    }

    /**
     * Log performance metrics
     */
    private function log_performance($listing_id, $searches_checked, $matches_found, $execution_time) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log(sprintf(
                'Performance: Listing %s - Checked %d searches, found %d matches in %.3f seconds',
                $listing_id,
                $searches_checked,
                $matches_found,
                $execution_time
            ), 'debug');
        }
    }

    /**
     * Handle price increase event (BME hook)
     */
    public function handle_price_increase($listing_id, $old_price, $new_price, $listing_data) {
        $this->log("Processing price increase for listing: $listing_id ($old_price -> $new_price)", 'info');

        $searches = $this->get_instant_searches();

        foreach ($searches as $search) {
            if ($this->listing_matches_search($listing_data, $search)) {
                $increase_data = array_merge($listing_data, [
                    'old_price' => $old_price,
                    'new_price' => $new_price,
                    'increase_amount' => $new_price - $old_price,
                    'increase_percent' => round((($new_price - $old_price) / $old_price) * 100, 1)
                ]);

                $this->queue_instant_notification($search, $listing_id, 'price_increased', $increase_data);
            }
        }
    }

    /**
     * Handle property update event (BME hook)
     */
    public function handle_property_update($listing_id, $old_data, $new_data, $changes) {
        $this->log("Processing property update for listing: $listing_id", 'info');

        // Only notify for significant changes
        $significant_changes = ['BedroomsTotal', 'BathroomsTotalInteger', 'LivingArea', 'PropertyType', 'PropertySubType'];
        $has_significant_change = false;

        foreach ($significant_changes as $field) {
            if (isset($changes[$field])) {
                $has_significant_change = true;
                break;
            }
        }

        if (!$has_significant_change) {
            return;
        }

        $searches = $this->get_instant_searches();

        foreach ($searches as $search) {
            if ($this->listing_matches_search($new_data, $search)) {
                $update_data = array_merge($new_data, [
                    'changes' => $changes,
                    'significant_changes' => array_intersect_key($changes, array_flip($significant_changes))
                ]);

                $this->queue_instant_notification($search, $listing_id, 'property_updated', $update_data);
            }
        }
    }

    /**
     * Handle open house event (BME hook)
     */
    public function handle_open_house($listing_id, $open_house_data, $listing_data) {
        $this->log("Processing open house for listing: $listing_id", 'info');

        $searches = $this->get_instant_searches();

        foreach ($searches as $search) {
            if ($this->listing_matches_search($listing_data, $search)) {
                $oh_data = array_merge($listing_data, [
                    'open_house' => $open_house_data
                ]);

                $this->queue_instant_notification($search, $listing_id, 'open_house', $oh_data);
            }
        }
    }

    /**
     * Check open houses for instant notifications (MLD Alert Types integration)
     */
    public function check_open_houses_for_instant() {
        global $wpdb;

        // Get upcoming open houses in the next 24 hours that haven't been instant notified
        // Use WordPress timezone-aware date instead of MySQL CURDATE()
        $wp_today = wp_date('Y-m-d');
        $wp_tomorrow = wp_date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);
        $upcoming = $wpdb->get_results($wpdb->prepare("
            SELECT oh.*, l.*
            FROM {$wpdb->prefix}bme_open_houses oh
            JOIN {$wpdb->prefix}bme_listings l ON oh.listing_id = l.listing_id
            WHERE oh.open_house_date >= %s
            AND oh.open_house_date <= %s
            AND (oh.instant_notification_sent IS NULL OR oh.instant_notification_sent = 0)
        ", $wp_today, $wp_tomorrow));

        foreach ($upcoming as $open_house) {
            $listing_data = (array) $open_house;
            $this->handle_open_house($open_house->listing_id, (array) $open_house, $listing_data);

            // Mark as instant notified
            $wpdb->update(
                $wpdb->prefix . 'bme_open_houses',
                ['instant_notification_sent' => 1],
                ['id' => $open_house->id]
            );
        }
    }

    /**
     * Check coming soon properties for instant notifications
     */
    public function check_coming_soon_for_instant() {
        global $wpdb;

        // Get properties that changed to Coming Soon in the last hour
        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        $coming_soon = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}bme_listings
            WHERE standard_status = 'Coming Soon'
            AND modification_timestamp >= DATE_SUB(%s, INTERVAL 1 HOUR)
            AND (instant_coming_soon_sent IS NULL OR instant_coming_soon_sent = 0)
        ", $wp_now));

        foreach ($coming_soon as $listing) {
            $listing_data = (array) $listing;
            $searches = $this->get_instant_searches();

            foreach ($searches as $search) {
                if ($this->listing_matches_search($listing_data, $search)) {
                    $this->queue_instant_notification($search, $listing->listing_id, 'coming_soon', $listing_data);
                }
            }

            // Mark as instant notified
            $wpdb->update(
                $wpdb->prefix . 'bme_listings',
                ['instant_coming_soon_sent' => 1],
                ['listing_id' => $listing->listing_id]
            );
        }
    }

    /**
     * Handle digest notifications (triggered by cron)
     */
    public function handle_digest_notifications($frequency, $search_results) {
        $this->log("Processing digest notifications for frequency: $frequency", 'info');

        foreach ($search_results as $search_id => $properties) {
            if (empty($properties)) {
                continue;
            }

            global $wpdb;
            $search = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mld_saved_searches WHERE id = %d",
                $search_id
            ));

            if (!$search || $search->notification_frequency !== $frequency) {
                continue;
            }

            // Create digest data
            $digest_data = [
                'digest_type' => $frequency,
                'digest_count' => count($properties),
                'properties' => $properties,
                'search_name' => $search->name
            ];

            // Send as single digest notification
            $this->queue_instant_notification($search, 'digest_' . $search_id, $frequency . '_digest', $digest_data);
        }
    }

    /**
     * Enhanced media validation for new listings
     */
    private function validate_listing_media($listing_id) {
        global $wpdb;

        // Check for photos in the media table
        $photo_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bme_media
             WHERE listing_id = %s AND media_category = 'Photo'",
            $listing_id
        ));

        // Check if listing was created very recently (within last 2 minutes)
        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        $listing_age = $wpdb->get_var($wpdb->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, creation_timestamp, %s) as age_seconds
             FROM {$wpdb->prefix}bme_listings
             WHERE listing_id = %s",
            $wp_now, $listing_id
        ));

        $validation_result = [
            'has_media' => false,
            'count' => $photo_count,
            'listing_age_seconds' => $listing_age,
            'message' => ''
        ];

        if ($photo_count > 0) {
            $validation_result['has_media'] = true;
            $validation_result['message'] = "Found {$photo_count} photos";
        } elseif ($listing_age < 120) {
            // Listing is very new, media might still be processing
            $validation_result['message'] = "New listing ({$listing_age}s old), media may still be processing";
        } else {
            // Listing is older but no media - could be listing without photos
            $validation_result['has_media'] = true; // Send anyway, may have no photos
            $validation_result['message'] = "No photos found but listing is {$listing_age}s old - proceeding";
        }

        return $validation_result;
    }

    /**
     * Handle delayed media validation check
     */
    public function handle_delayed_media_check($search, $listing_id, $type, $listing_data) {
        $this->log("Processing delayed media check for listing $listing_id", 'info');

        $media_validation = $this->validate_listing_media($listing_id);

        if ($media_validation['has_media'] || $media_validation['listing_age_seconds'] > 300) {
            // Media is now available OR listing is old enough that we should send anyway
            $this->log("Delayed media validation passed for listing $listing_id: {$media_validation['message']}", 'info');
            $this->queue_instant_notification($search, $listing_id, $type, $listing_data);
        } else {
            // Still no media after delay - send anyway but log
            $this->log("Delayed media validation still failed for listing $listing_id, sending notification anyway", 'warning');
            $this->queue_instant_notification($search, $listing_id, $type, $listing_data);
        }
    }

    /**
     * Log activity
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Skip logging during plugin activation to prevent unexpected output
            if (defined('WP_ADMIN') && WP_ADMIN &&
                isset($_GET['action']) && $_GET['action'] === 'activate') {
                return;
            }

            // Only log to file, never to browser during web requests
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf('[MLD Instant Matcher] [%s] %s', $level, $message), 3, WP_CONTENT_DIR . '/debug.log');
            } elseif (php_sapi_name() === 'cli') {
                // Only output to error_log if we're in CLI mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('[MLD Instant Matcher] [%s] %s', $level, $message));
                }
            }
        }
    }
}