<?php
/**
 * Smart Property Matcher for MLS Notifications
 *
 * Intelligent property matching engine that uses advanced algorithms
 * to match properties with saved searches, similar to Redfin and Zillow
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 5.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Smart_Property_Matcher {

    /**
     * Match scoring weights
     */
    private $weights = [
        'price' => 0.25,
        'location' => 0.20,
        'size' => 0.15,
        'bedrooms' => 0.10,
        'bathrooms' => 0.10,
        'property_type' => 0.10,
        'features' => 0.05,
        'school_rating' => 0.05
    ];

    /**
     * Cache for performance
     */
    private $cache = [];

    /**
     * Find all saved searches that match a property
     */
    public function find_matching_searches($listing_data) {
        global $wpdb;

        $matches = [];

        // Get all active saved searches
        $saved_searches = $wpdb->get_results("
            SELECT ss.*, u.user_email, u.display_name
            FROM {$wpdb->prefix}mld_saved_searches ss
            JOIN {$wpdb->prefix}users u ON ss.user_id = u.ID
            WHERE ss.is_active = 1
            AND ss.notification_frequency IS NOT NULL
        ");

        foreach ($saved_searches as $search) {
            $match_result = $this->evaluate_match($listing_data, $search);

            if ($match_result['matches']) {
                $matches[] = [
                    'user_id' => $search->user_id,
                    'search_id' => $search->id,
                    'search_name' => $search->name,
                    'score' => $match_result['score'],
                    'reasons' => $match_result['reasons'],
                    'user_email' => $search->user_email,
                    'notification_frequency' => $search->notification_frequency
                ];
            }
        }

        // Sort by match score
        usort($matches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $matches;
    }

    /**
     * Evaluate if a listing matches a saved search
     */
    private function evaluate_match($listing, $search) {
        $filters = json_decode($search->filters, true);
        if (!is_array($filters)) {
            return ['matches' => false, 'score' => 0, 'reasons' => []];
        }

        $score = 0;
        $reasons = [];
        $must_match = []; // Required criteria
        $should_match = []; // Optional criteria

        // Price matching (must match)
        if (!$this->matches_price($listing, $filters)) {
            return ['matches' => false, 'score' => 0, 'reasons' => ['Price out of range']];
        }
        $score += $this->weights['price'];
        $reasons[] = 'Price within budget';

        // Location matching (must match if specified)
        if (!empty($filters['city']) || !empty($filters['zip_code']) || !empty($filters['polygon_shapes'])) {
            if (!$this->matches_location($listing, $filters, $search)) {
                return ['matches' => false, 'score' => 0, 'reasons' => ['Location mismatch']];
            }
            $score += $this->weights['location'];
            $reasons[] = 'Location matches';
        }

        // Property type (must match if specified)
        if (!empty($filters['property_type'])) {
            if (!$this->matches_property_type($listing, $filters)) {
                return ['matches' => false, 'score' => 0, 'reasons' => ['Property type mismatch']];
            }
            $score += $this->weights['property_type'];
            $reasons[] = 'Property type matches';
        }

        // Size matching (flexible)
        $size_match = $this->calculate_size_match($listing, $filters);
        if ($size_match > 0) {
            $score += $this->weights['size'] * $size_match;
            $reasons[] = 'Size requirements met';
        }

        // Bedrooms (flexible)
        $bedroom_match = $this->calculate_bedroom_match($listing, $filters);
        if ($bedroom_match > 0) {
            $score += $this->weights['bedrooms'] * $bedroom_match;
            $reasons[] = 'Bedroom count matches';
        }

        // Bathrooms (flexible)
        $bathroom_match = $this->calculate_bathroom_match($listing, $filters);
        if ($bathroom_match > 0) {
            $score += $this->weights['bathrooms'] * $bathroom_match;
            $reasons[] = 'Bathroom count matches';
        }

        // Features matching
        $feature_match = $this->calculate_feature_match($listing, $filters);
        if ($feature_match > 0) {
            $score += $this->weights['features'] * $feature_match;
            $reasons[] = 'Desired features present';
        }

        // School rating
        if (!empty($filters['min_school_rating'])) {
            $school_match = $this->calculate_school_match($listing, $filters);
            if ($school_match > 0) {
                $score += $this->weights['school_rating'] * $school_match;
                $reasons[] = 'Good school ratings';
            }
        }

        // Bonus scoring for exceptional matches
        $score += $this->calculate_bonus_score($listing, $filters);

        // Normalize score to 0-1 range
        $score = min(1.0, $score);

        return [
            'matches' => $score > 0.3, // Minimum 30% match to qualify
            'score' => round($score, 2),
            'reasons' => $reasons
        ];
    }

    /**
     * Check price matching
     */
    private function matches_price($listing, $filters) {
        $price = $listing->list_price ?? 0;

        if (!empty($filters['min_price']) && $price < $filters['min_price']) {
            return false;
        }

        if (!empty($filters['max_price']) && $price > $filters['max_price']) {
            return false;
        }

        return true;
    }

    /**
     * Check location matching
     */
    private function matches_location($listing, $filters, $search) {
        // City match
        if (!empty($filters['city'])) {
            $cities = is_array($filters['city']) ? $filters['city'] : [$filters['city']];
            $listing_city = strtolower($listing->city ?? '');

            $city_match = false;
            foreach ($cities as $city) {
                if (stripos($listing_city, strtolower($city)) !== false) {
                    $city_match = true;
                    break;
                }
            }

            if (!$city_match) {
                return false;
            }
        }

        // Zip code match
        if (!empty($filters['zip_code'])) {
            $zip_codes = is_array($filters['zip_code']) ? $filters['zip_code'] : [$filters['zip_code']];
            $listing_zip = $listing->postal_code ?? '';

            $zip_match = false;
            foreach ($zip_codes as $zip) {
                if (strpos($listing_zip, $zip) !== false) {
                    $zip_match = true;
                    break;
                }
            }

            if (!$zip_match) {
                return false;
            }
        }

        // Polygon/boundary match
        if (!empty($search->polygon_shapes)) {
            $shapes = json_decode($search->polygon_shapes, true);
            if (!empty($shapes) && !$this->is_in_polygon($listing, $shapes)) {
                return false;
            }
        }

        // Radius search
        if (!empty($filters['center_lat']) && !empty($filters['center_lng']) && !empty($filters['radius'])) {
            $distance = $this->calculate_distance(
                $listing->latitude,
                $listing->longitude,
                $filters['center_lat'],
                $filters['center_lng']
            );

            if ($distance > $filters['radius']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if property is within polygon boundaries
     */
    private function is_in_polygon($listing, $shapes) {
        if (empty($listing->latitude) || empty($listing->longitude)) {
            return false;
        }

        foreach ($shapes as $shape) {
            if ($shape['type'] === 'polygon' && !empty($shape['coordinates'])) {
                if ($this->point_in_polygon($listing->latitude, $listing->longitude, $shape['coordinates'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Point in polygon algorithm
     */
    private function point_in_polygon($lat, $lng, $polygon) {
        $inside = false;
        $p1x = $polygon[0]['lat'];
        $p1y = $polygon[0]['lng'];

        $n = count($polygon);
        for ($i = 1; $i <= $n; $i++) {
            $p2x = $polygon[$i % $n]['lat'];
            $p2y = $polygon[$i % $n]['lng'];

            if ($lng > min($p1y, $p2y)) {
                if ($lng <= max($p1y, $p2y)) {
                    if ($lat <= max($p1x, $p2x)) {
                        if ($p1y != $p2y) {
                            $xinters = ($lng - $p1y) * ($p2x - $p1x) / ($p2y - $p1y) + $p1x;
                        }
                        if ($p1x == $p2x || $lat <= $xinters) {
                            $inside = !$inside;
                        }
                    }
                }
            }

            $p1x = $p2x;
            $p1y = $p2y;
        }

        return $inside;
    }

    /**
     * Calculate distance between two points
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 3959; // miles

        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earth_radius * $c;
    }

    /**
     * Check property type matching
     */
    private function matches_property_type($listing, $filters) {
        if (empty($filters['property_type'])) {
            return true;
        }

        $types = is_array($filters['property_type']) ? $filters['property_type'] : [$filters['property_type']];
        $listing_type = strtolower($listing->property_type ?? '');

        foreach ($types as $type) {
            if (stripos($listing_type, strtolower($type)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate size match score
     */
    private function calculate_size_match($listing, $filters) {
        $size = $listing->living_area ?? 0;

        if (empty($filters['min_sqft']) && empty($filters['max_sqft'])) {
            return 0; // No size preference
        }

        $score = 1.0;

        if (!empty($filters['min_sqft']) && $size < $filters['min_sqft']) {
            // Allow 10% flexibility
            $diff_percent = ($filters['min_sqft'] - $size) / $filters['min_sqft'];
            $score = max(0, 1 - ($diff_percent * 2));
        }

        if (!empty($filters['max_sqft']) && $size > $filters['max_sqft']) {
            // Allow 10% flexibility
            $diff_percent = ($size - $filters['max_sqft']) / $filters['max_sqft'];
            $score = min($score, max(0, 1 - ($diff_percent * 2)));
        }

        return $score;
    }

    /**
     * Calculate bedroom match score
     */
    private function calculate_bedroom_match($listing, $filters) {
        $bedrooms = $listing->bedrooms_total ?? 0;

        if (empty($filters['min_bedrooms'])) {
            return 0; // No bedroom preference
        }

        if ($bedrooms >= $filters['min_bedrooms']) {
            return 1.0;
        }

        // Allow one bedroom less with reduced score
        if ($bedrooms == $filters['min_bedrooms'] - 1) {
            return 0.5;
        }

        return 0;
    }

    /**
     * Calculate bathroom match score
     */
    private function calculate_bathroom_match($listing, $filters) {
        $bathrooms = $listing->bathrooms_total ?? $listing->bathrooms_total_integer ?? 0;

        if (empty($filters['min_bathrooms'])) {
            return 0; // No bathroom preference
        }

        if ($bathrooms >= $filters['min_bathrooms']) {
            return 1.0;
        }

        // Allow 0.5 bathroom less with reduced score
        if ($bathrooms >= $filters['min_bathrooms'] - 0.5) {
            return 0.7;
        }

        return 0;
    }

    /**
     * Calculate feature match score
     */
    private function calculate_feature_match($listing, $filters) {
        if (empty($filters['features'])) {
            return 0;
        }

        $desired_features = is_array($filters['features']) ? $filters['features'] : [$filters['features']];
        $matched_features = 0;

        foreach ($desired_features as $feature) {
            if ($this->has_feature($listing, $feature)) {
                $matched_features++;
            }
        }

        return $matched_features / count($desired_features);
    }

    /**
     * Check if listing has a specific feature
     */
    private function has_feature($listing, $feature) {
        $feature = strtolower($feature);

        // Check various fields for the feature
        $fields_to_check = [
            'features',
            'interior_features',
            'exterior_features',
            'appliances',
            'cooling',
            'heating',
            'parking_features',
            'pool_features',
            'public_remarks'
        ];

        foreach ($fields_to_check as $field) {
            if (!empty($listing->$field)) {
                $value = is_array($listing->$field) ? json_encode($listing->$field) : $listing->$field;
                if (stripos($value, $feature) !== false) {
                    return true;
                }
            }
        }

        // Special cases
        switch ($feature) {
            case 'garage':
                return !empty($listing->attached_garage_yn) || !empty($listing->garage_spaces);
            case 'pool':
                return !empty($listing->pool_features) || stripos($listing->public_remarks ?? '', 'pool') !== false;
            case 'basement':
                return !empty($listing->basement);
            case 'waterfront':
                return !empty($listing->waterfront_yn) || !empty($listing->water_view_yn);
            case 'view':
                return !empty($listing->view) || !empty($listing->water_view_yn);
        }

        return false;
    }

    /**
     * Calculate school rating match
     */
    private function calculate_school_match($listing, $filters) {
        // Get school ratings for the property
        $school_rating = $this->get_school_rating($listing);

        if ($school_rating === null) {
            return 0.5; // No data, neutral score
        }

        if ($school_rating >= $filters['min_school_rating']) {
            return 1.0;
        }

        // Allow 1 point lower with reduced score
        if ($school_rating >= $filters['min_school_rating'] - 1) {
            return 0.7;
        }

        return 0;
    }

    /**
     * Get school rating for a property
     */
    private function get_school_rating($listing) {
        global $wpdb;

        // Check cache
        $cache_key = 'school_' . $listing->listing_id;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        // Query school data
        $school_data = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(rating) FROM {$wpdb->prefix}mld_property_schools
            WHERE listing_id = %s
        ", $listing->listing_id));

        $this->cache[$cache_key] = $school_data ? floatval($school_data) : null;
        return $this->cache[$cache_key];
    }

    /**
     * Calculate bonus scoring for exceptional properties
     */
    private function calculate_bonus_score($listing, $filters) {
        $bonus = 0;

        // New listing bonus
        if ($this->is_new_listing($listing)) {
            $bonus += 0.05;
        }

        // Price reduction bonus
        if ($this->has_price_reduction($listing)) {
            $bonus += 0.05;
        }

        // Premium features bonus
        if ($this->has_premium_features($listing)) {
            $bonus += 0.03;
        }

        // Under market value bonus
        if ($this->is_under_market_value($listing)) {
            $bonus += 0.07;
        }

        return $bonus;
    }

    /**
     * Check if listing is new
     */
    private function is_new_listing($listing) {
        $listing_date = strtotime($listing->listing_contract_date ?? $listing->created_at ?? '');
        return $listing_date > strtotime('-7 days');
    }

    /**
     * Check if listing has price reduction
     */
    private function has_price_reduction($listing) {
        return !empty($listing->original_list_price) &&
               $listing->list_price < $listing->original_list_price;
    }

    /**
     * Check for premium features
     */
    private function has_premium_features($listing) {
        $premium_keywords = [
            'renovated', 'updated', 'remodeled', 'granite', 'stainless',
            'hardwood', 'pool', 'spa', 'view', 'waterfront', 'gated',
            'smart home', 'solar', 'wine cellar', 'theater'
        ];

        $text = strtolower($listing->public_remarks ?? '');
        foreach ($premium_keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if property is under market value
     */
    private function is_under_market_value($listing) {
        // Get average price for similar properties
        $avg_price = $this->get_area_average_price($listing);

        if ($avg_price && $listing->list_price < $avg_price * 0.9) {
            return true;
        }

        // Check price per square foot
        if (!empty($listing->living_area) && $listing->living_area > 0) {
            $price_per_sqft = $listing->list_price / $listing->living_area;
            $avg_price_per_sqft = $this->get_area_average_price_per_sqft($listing);

            if ($avg_price_per_sqft && $price_per_sqft < $avg_price_per_sqft * 0.85) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get area average price
     */
    private function get_area_average_price($listing) {
        global $wpdb;

        $cache_key = 'avg_price_' . $listing->city . '_' . $listing->property_type;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $avg = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(list_price) FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
            AND property_type = %s
            AND standard_status = 'Active'
            AND bedrooms_total BETWEEN %d AND %d
        ",
            $listing->city,
            $listing->property_type,
            max(1, $listing->bedrooms_total - 1),
            $listing->bedrooms_total + 1
        ));

        $this->cache[$cache_key] = $avg ? floatval($avg) : null;
        return $this->cache[$cache_key];
    }

    /**
     * Get area average price per square foot
     */
    private function get_area_average_price_per_sqft($listing) {
        global $wpdb;

        $cache_key = 'avg_ppsf_' . $listing->city;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $avg = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(list_price / living_area) FROM {$wpdb->prefix}bme_listings
            WHERE city = %s
            AND standard_status = 'Active'
            AND living_area > 0
        ", $listing->city));

        $this->cache[$cache_key] = $avg ? floatval($avg) : null;
        return $this->cache[$cache_key];
    }

    /**
     * Find similar properties for recommendations
     */
    public function find_similar_properties($listing, $limit = 5) {
        global $wpdb;

        if (!$listing) {
            return [];
        }

        // Build similarity query
        $query = $wpdb->prepare("
            SELECT *,
                (
                    CASE WHEN property_type = %s THEN 2 ELSE 0 END +
                    CASE WHEN city = %s THEN 2 ELSE 0 END +
                    CASE WHEN ABS(list_price - %d) < %d THEN 2 ELSE 0 END +
                    CASE WHEN ABS(bedrooms_total - %d) <= 1 THEN 1 ELSE 0 END +
                    CASE WHEN ABS(bathrooms_total - %d) <= 1 THEN 1 ELSE 0 END +
                    CASE WHEN ABS(living_area - %d) < %d THEN 1 ELSE 0 END
                ) as similarity_score
            FROM {$wpdb->prefix}bme_listings
            WHERE listing_id != %s
            AND standard_status = 'Active'
            AND list_price BETWEEN %d AND %d
            ORDER BY similarity_score DESC, list_price ASC
            LIMIT %d
        ",
            $listing->property_type,
            $listing->city,
            $listing->list_price,
            $listing->list_price * 0.1, // 10% price range
            $listing->ld.bedrooms_total ?? 0,
            $listing->bathrooms_total ?? 0,
            $listing->living_area ?? 0,
            ($listing->living_area ?? 0) * 0.2, // 20% size range
            $listing->listing_id,
            $listing->list_price * 0.8,
            $listing->list_price * 1.2,
            $limit
        );

        return $wpdb->get_results($query);
    }

    /**
     * Get match statistics for analytics
     */
    public function get_match_statistics($user_id = null) {
        global $wpdb;

        $where = $user_id ? $wpdb->prepare("WHERE user_id = %d", $user_id) : "";

        return $wpdb->get_row("
            SELECT
                COUNT(DISTINCT search_id) as total_searches,
                COUNT(DISTINCT listing_id) as total_matches,
                AVG(match_score) as avg_match_score,
                MAX(match_score) as best_match_score
            FROM {$wpdb->prefix}mld_notification_queue
            $where
        ");
    }
}