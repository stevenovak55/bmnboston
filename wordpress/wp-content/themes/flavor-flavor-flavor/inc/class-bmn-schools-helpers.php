<?php
/**
 * BMN Schools Helper Functions
 *
 * Helper functions for school district pages and data fetching.
 * Includes transient caching for expensive queries.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get districts for browse page with filters and pagination.
 *
 * @param array $filters Filter options (grade, city, min_score, max_score, sort, page).
 * @return array Districts data with pagination info.
 */
function bmn_get_districts_browse_data($filters = array()) {
    global $wpdb;

    // Default filters
    $filters = wp_parse_args($filters, array(
        'grade'     => '',
        'city'      => '',
        'min_score' => 0,
        'max_score' => 100,
        'sort'      => 'rank',
        'page'      => 1,
    ));

    // Generate cache key based on filters
    $cache_key = 'bmn_districts_browse_' . md5(serialize($filters));
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $rankings_table = $wpdb->prefix . 'bmn_district_rankings';
    $schools_table = $wpdb->prefix . 'bmn_schools';

    // Check if tables exist
    if ($wpdb->get_var("SHOW TABLES LIKE '{$districts_table}'") !== $districts_table) {
        return array(
            'districts'   => array(),
            'total'       => 0,
            'page'        => 1,
            'per_page'    => 24,
            'total_pages' => 0,
            'filters'     => $filters,
        );
    }

    // Build WHERE clauses
    // Exclude duplicate/inactive districts and those with no schools
    $where = array(
        '1=1',
        "(d.type IS NULL OR d.type NOT IN ('duplicate', 'inactive'))",
        "EXISTS (SELECT 1 FROM {$schools_table} s WHERE s.district_id = d.id)"
    );
    $params = array();

    // Filter by letter grade (percentile ranges)
    // Must match get_letter_grade_from_percentile() in ranking calculator:
    // A+: 90+, A: 80-89, A-: 70-79, B+: 60-69, B: 50-59, B-: 40-49
    // C+: 30-39, C: 20-29, C-: 10-19, D: 5-9, F: 0-4
    if (!empty($filters['grade'])) {
        $grade_ranges = array(
            'A' => array(70, 100),  // A-, A, A+ (top 30%)
            'B' => array(40, 69),   // B-, B, B+ (40th-69th percentile)
            'C' => array(10, 39),   // C-, C, C+ (10th-39th percentile)
            'D' => array(5, 9),     // D (5th-9th percentile)
            'F' => array(0, 4),     // F (bottom 5%)
        );
        if (isset($grade_ranges[$filters['grade']])) {
            $where[] = 'r.percentile_rank BETWEEN %d AND %d';
            $params[] = $grade_ranges[$filters['grade']][0];
            $params[] = $grade_ranges[$filters['grade']][1];
        }
    }

    // Filter by city/town - search district name since city field may be empty
    if (!empty($filters['city'])) {
        $where[] = 'd.name LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['city']) . '%';
    }

    // Filter by score range
    if (!empty($filters['min_score']) && $filters['min_score'] > 0) {
        $where[] = 'r.composite_score >= %f';
        $params[] = floatval($filters['min_score']);
    }
    if (!empty($filters['max_score']) && $filters['max_score'] < 100) {
        $where[] = 'r.composite_score <= %f';
        $params[] = floatval($filters['max_score']);
    }

    // Sorting
    // For rank sorting, use state_rank ASC to show #1 first, then #2, etc.
    // NULL values should appear last (districts without rankings)
    $order_by = 'CASE WHEN r.state_rank IS NULL THEN 1 ELSE 0 END, r.state_rank ASC, d.name ASC';
    switch ($filters['sort']) {
        case 'name':
            $order_by = 'd.name ASC';
            break;
        case 'score':
            $order_by = 'CASE WHEN r.composite_score IS NULL THEN 1 ELSE 0 END, r.composite_score DESC, d.name ASC';
            break;
        case 'rank':
        default:
            $order_by = 'CASE WHEN r.state_rank IS NULL THEN 1 ELSE 0 END, r.state_rank ASC, d.name ASC';
            break;
    }

    // Pagination
    $per_page = 24;
    $page = max(1, intval($filters['page']));
    $offset = ($page - 1) * $per_page;

    // Get total count first
    $count_where = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(DISTINCT d.id)
                  FROM {$districts_table} d
                  LEFT JOIN {$rankings_table} r ON d.id = r.district_id";

    if (count($params) > 0) {
        // Clone params for count query (without LIMIT params)
        $count_params = $params;
        $count_sql .= " WHERE {$count_where}";
        $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_params));
    } else {
        $total = $wpdb->get_var($count_sql . " WHERE {$count_where}");
    }

    // Build main query
    $sql = "SELECT
                d.id,
                d.name,
                d.city,
                d.county,
                d.total_schools,
                d.total_students,
                d.website,
                r.composite_score,
                r.percentile_rank,
                r.state_rank,
                r.elementary_avg,
                r.middle_avg,
                r.high_avg,
                r.schools_count,
                r.year as ranking_year
            FROM {$districts_table} d
            LEFT JOIN {$rankings_table} r ON d.id = r.district_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$order_by}
            LIMIT %d OFFSET %d";

    $params[] = $per_page;
    $params[] = $offset;

    $results = $wpdb->get_results($wpdb->prepare($sql, $params));

    // Process results - add letter grades and URLs
    foreach ($results as &$district) {
        $district->letter_grade = bmn_get_letter_grade_from_percentile($district->percentile_rank);
        $district->slug = sanitize_title($district->name);
        $district->url = home_url('/schools/' . $district->slug . '/');

        // Count schools if not in rankings table
        if (empty($district->schools_count)) {
            $district->schools_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$schools_table} WHERE district_id = %d",
                $district->id
            ));
        }
    }

    $result = array(
        'districts'   => $results,
        'total'       => intval($total),
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => ceil($total / $per_page),
        'filters'     => $filters,
    );

    // Cache for 1 hour
    set_transient($cache_key, $result, HOUR_IN_SECONDS);

    return $result;
}

/**
 * Get detailed data for a single district.
 *
 * @param string $slug The district URL slug.
 * @return array|null District data or null if not found.
 */
function bmn_get_district_detail_data($slug) {
    global $wpdb;

    // Check cache first
    $cache_key = 'bmn_district_detail_' . sanitize_key($slug);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $rankings_table = $wpdb->prefix . 'bmn_district_rankings';
    $schools_table = $wpdb->prefix . 'bmn_schools';
    $school_rankings_table = $wpdb->prefix . 'bmn_school_rankings';

    // Check if tables exist
    if ($wpdb->get_var("SHOW TABLES LIKE '{$districts_table}'") !== $districts_table) {
        return null;
    }

    // Find district by slug
    $district = bmn_find_district_by_slug($slug);

    if (!$district) {
        return null;
    }

    // Get district ranking
    $ranking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$rankings_table} WHERE district_id = %d ORDER BY year DESC LIMIT 1",
        $district->id
    ));

    // Get the most recent ranking year
    $latest_year = $wpdb->get_var("SELECT MAX(year) FROM {$school_rankings_table}");

    // Get all schools in district with their latest rankings only
    $schools = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*,
                r.composite_score,
                r.percentile_rank,
                r.state_rank,
                r.year as ranking_year
         FROM {$schools_table} s
         LEFT JOIN {$school_rankings_table} r ON s.id = r.school_id AND r.year = %d
         WHERE s.district_id = %d
         ORDER BY s.level, s.name",
        $latest_year,
        $district->id
    ));

    // Group schools by level
    $schools_by_level = array(
        'elementary' => array(),
        'middle'     => array(),
        'high'       => array(),
    );

    foreach ($schools as $school) {
        // Add letter grade
        $school->letter_grade = bmn_get_letter_grade_from_percentile($school->percentile_rank);
        $school->slug = sanitize_title($school->name);
        $school->url = home_url('/schools/' . sanitize_title($district->name) . '/' . $school->slug . '/');

        // Determine level
        $level = strtolower($school->level ?? 'elementary');
        if (strpos($level, 'elem') !== false || strpos($level, 'primary') !== false) {
            $schools_by_level['elementary'][] = $school;
        } elseif (strpos($level, 'middle') !== false || strpos($level, 'junior') !== false) {
            $schools_by_level['middle'][] = $school;
        } elseif (strpos($level, 'high') !== false || strpos($level, 'senior') !== false) {
            $schools_by_level['high'][] = $school;
        } else {
            // Default based on grade range
            $grades_high = intval($school->grades_high ?? 0);
            if ($grades_high <= 5) {
                $schools_by_level['elementary'][] = $school;
            } elseif ($grades_high <= 8) {
                $schools_by_level['middle'][] = $school;
            } else {
                $schools_by_level['high'][] = $school;
            }
        }
    }

    // Parse extra_data for college outcomes and discipline
    $extra_data = json_decode($district->extra_data ?? '{}', true);

    // Get property listings in district (if MLD plugin is active)
    $listings = array();
    $median_price = 0;
    if (function_exists('bmn_get_listings_in_district')) {
        $listings = bmn_get_listings_in_district($district->id);
        $median_price = bmn_calculate_median_price($listings);
    }

    // Get nearby districts
    $nearby = bmn_get_nearby_districts($district->id, $district->city);

    // Get cities served by this district
    $cities_served = bmn_get_district_cities($district->id);

    $result = array(
        'id'                  => $district->id,
        'name'                => $district->name,
        'slug'                => $slug,
        'url'                 => home_url('/schools/' . $slug . '/'),
        'city'                => $district->city,
        'county'              => $district->county,
        'state'               => 'MA',
        'total_schools'       => $district->total_schools,
        'total_students'      => $district->total_students,
        'website'             => $district->website,
        'phone'               => $district->phone,

        // Ranking data
        'composite_score'     => $ranking->composite_score ?? null,
        'percentile_rank'     => $ranking->percentile_rank ?? null,
        'letter_grade'        => bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? null),
        'state_rank'          => $ranking->state_rank ?? null,
        'elementary_avg'      => $ranking->elementary_avg ?? null,
        'middle_avg'          => $ranking->middle_avg ?? null,
        'high_avg'            => $ranking->high_avg ?? null,
        'ranking_year'        => $ranking->year ?? null,

        // Schools grouped by level
        'schools_by_level'    => $schools_by_level,
        'schools'             => $schools,
        'schools_count'       => count($schools),

        // Extra data
        'college_outcomes'    => $extra_data['college_outcomes'] ?? null,
        'discipline'          => $extra_data['discipline'] ?? null,
        'expenditure_per_pupil' => $extra_data['expenditure_per_pupil_total'] ?? null,

        // Map boundary
        'boundary_geojson'    => $district->boundary_geojson,

        // Property listings
        'listings'            => $listings,
        'listing_count'       => count($listings),
        'median_price'        => $median_price,

        // Nearby districts
        'nearby'              => $nearby,

        // Cities served
        'cities_served'       => $cities_served,

        // For SEO
        'data_freshness'      => $ranking->calculated_at ?? date('Y-m-d'),
    );

    // Cache for 30 minutes (listings change more frequently than school data)
    set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

    return $result;
}

/**
 * Find a district by its URL slug.
 *
 * @param string $slug The URL slug.
 * @return object|null District object or null if not found.
 */
function bmn_find_district_by_slug($slug) {
    global $wpdb;

    $table = $wpdb->prefix . 'bmn_school_districts';

    // Try cache first
    $cache_key = 'bmn_district_slug_' . $slug;
    $cached = wp_cache_get($cache_key, 'bmn_schools');
    if ($cached !== false) {
        return $cached;
    }

    // Get all districts and check slugs
    $districts = $wpdb->get_results("SELECT id, name FROM {$table}");

    foreach ($districts as $district) {
        if (sanitize_title($district->name) === $slug) {
            // Get full district data
            $full_district = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $district->id
            ));
            wp_cache_set($cache_key, $full_district, 'bmn_schools', 3600);
            return $full_district;
        }
    }

    return null;
}

/**
 * Get nearby districts for comparison.
 *
 * @param int    $district_id Current district ID.
 * @param string $city        Current district city.
 * @return array Array of nearby districts.
 */
function bmn_get_nearby_districts($district_id, $city) {
    global $wpdb;

    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $rankings_table = $wpdb->prefix . 'bmn_district_rankings';

    // Get districts in the same county or nearby cities
    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT county FROM {$districts_table} WHERE id = %d",
        $district_id
    ));

    if (!$current) {
        return array();
    }

    // Get other districts in the same county, ranked by composite score
    $nearby = $wpdb->get_results($wpdb->prepare(
        "SELECT d.id, d.name, d.city, d.total_schools,
                r.composite_score, r.percentile_rank
         FROM {$districts_table} d
         LEFT JOIN {$rankings_table} r ON d.id = r.district_id
         WHERE d.county = %s AND d.id != %d
         ORDER BY r.composite_score DESC
         LIMIT 6",
        $current->county,
        $district_id
    ));

    // Add letter grades and URLs
    foreach ($nearby as &$district) {
        $district->letter_grade = bmn_get_letter_grade_from_percentile($district->percentile_rank);
        $district->slug = sanitize_title($district->name);
        $district->url = home_url('/schools/' . $district->slug . '/');
    }

    return $nearby;
}

/**
 * Get cities served by a school district.
 *
 * @param int $district_id District ID.
 * @return array Array of city names.
 */
function bmn_get_district_cities($district_id) {
    global $wpdb;

    $schools_table = $wpdb->prefix . 'bmn_schools';

    // Get unique cities from schools in this district
    $cities = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city FROM {$schools_table} WHERE district_id = %d ORDER BY city",
        $district_id
    ));

    return $cities;
}

/**
 * Get property listings in a school district.
 *
 * @param int $district_id District ID.
 * @return array Array of listing objects.
 */
function bmn_get_listings_in_district($district_id) {
    global $wpdb;

    // Check if MLD plugin tables exist
    $summary_table = $wpdb->prefix . 'bme_listing_summary';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") !== $summary_table) {
        return array();
    }

    // Get district boundary or cities
    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $schools_table = $wpdb->prefix . 'bmn_schools';

    // Get cities in this district
    $cities = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city FROM {$schools_table} WHERE district_id = %d",
        $district_id
    ));

    if (empty($cities)) {
        return array();
    }

    // Build city placeholders
    $placeholders = implode(',', array_fill(0, count($cities), '%s'));

    // Get active listings in these cities
    $listings = $wpdb->get_results($wpdb->prepare(
        "SELECT listing_key, listing_id, street_number, street_name, city,
                list_price, bedrooms_total, bathrooms_total, building_area_total,
                main_photo_url, latitude, longitude
         FROM {$summary_table}
         WHERE standard_status = 'Active'
           AND property_type = 'Residential'
           AND city IN ({$placeholders})
         ORDER BY list_date DESC
         LIMIT 12",
        $cities
    ));

    return $listings;
}

/**
 * Calculate median price from listings array.
 *
 * @param array $listings Array of listing objects.
 * @return int Median price or 0.
 */
function bmn_calculate_median_price($listings) {
    if (empty($listings)) {
        return 0;
    }

    $prices = array();
    foreach ($listings as $listing) {
        if (!empty($listing->list_price) && $listing->list_price > 0) {
            $prices[] = intval($listing->list_price);
        }
    }

    if (empty($prices)) {
        return 0;
    }

    sort($prices);
    $count = count($prices);
    $middle = floor($count / 2);

    if ($count % 2 === 0) {
        return ($prices[$middle - 1] + $prices[$middle]) / 2;
    }

    return $prices[$middle];
}

/**
 * Convert percentile rank to letter grade.
 *
 * @param int|float $percentile Percentile rank (0-100).
 * @return string Letter grade (A+ through F).
 */
function bmn_get_letter_grade_from_percentile($percentile) {
    if ($percentile === null) {
        return 'N/A';
    }

    $percentile = floatval($percentile);

    // Must match BMN_Schools_Ranking_Calculator::get_letter_grade_from_percentile()
    if ($percentile >= 90) return 'A+';
    if ($percentile >= 80) return 'A';
    if ($percentile >= 70) return 'A-';
    if ($percentile >= 60) return 'B+';
    if ($percentile >= 50) return 'B';
    if ($percentile >= 40) return 'B-';
    if ($percentile >= 30) return 'C+';
    if ($percentile >= 20) return 'C';
    if ($percentile >= 10) return 'C-';
    if ($percentile >= 5) return 'D';
    return 'F';
}

/**
 * Get letter grade from a composite SCORE (not percentile) for a given school level.
 *
 * This calculates what percentile the score represents among all schools at that level,
 * then returns the appropriate letter grade.
 *
 * Use this for district average scores, NOT for individual school grades
 * (which already have percentile_rank stored).
 *
 * @param float|null $score Composite score (0-100).
 * @param string $level School level ('elementary', 'middle', 'high').
 * @return string Letter grade (A+ through F, or N/A).
 */
function bmn_get_letter_grade_from_score($score, $level = 'all') {
    if ($score === null) {
        return 'N/A';
    }

    global $wpdb;
    $schools_table = $wpdb->prefix . 'bmn_schools';
    $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

    // Get the latest year with data
    $year = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");
    if (!$year) {
        return 'N/A';
    }

    // Build level filter
    $level_filter = '';
    if ($level && $level !== 'all') {
        $level_filter = $wpdb->prepare(" AND s.level = %s", $level);
    }

    // Count total schools and schools below this score
    $total = $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$rankings_table} r
        JOIN {$schools_table} s ON r.school_id = s.id
        WHERE r.year = {$year} {$level_filter}
    ");

    if (!$total) {
        return 'N/A';
    }

    $below = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$rankings_table} r
        JOIN {$schools_table} s ON r.school_id = s.id
        WHERE r.year = {$year} {$level_filter} AND r.composite_score < %f
    ", $score));

    // Calculate percentile (what % of schools have lower scores)
    $percentile = ($below / $total) * 100;

    // Use the standard percentile-to-grade function
    return bmn_get_letter_grade_from_percentile($percentile);
}

/**
 * Get CSS class for grade badge color.
 *
 * @param string $grade Letter grade (A+, A, B+, etc.).
 * @return string CSS class name.
 */
function bmn_get_grade_class($grade) {
    $grade_letter = substr($grade, 0, 1);

    switch ($grade_letter) {
        case 'A':
            return 'bne-grade--a';
        case 'B':
            return 'bne-grade--b';
        case 'C':
            return 'bne-grade--c';
        case 'D':
            return 'bne-grade--d';
        case 'F':
            return 'bne-grade--f';
        default:
            return 'bne-grade--na';
    }
}

/**
 * Format a number with abbreviation (K, M).
 *
 * @param int $number The number to format.
 * @return string Formatted number.
 */
function bmn_format_number_short($number) {
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    }
    if ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

/**
 * Format currency for display.
 *
 * @param int $amount The amount in dollars.
 * @return string Formatted currency.
 */
function bmn_format_currency($amount) {
    if ($amount >= 1000000) {
        return '$' . round($amount / 1000000, 2) . 'M';
    }
    if ($amount >= 1000) {
        return '$' . round($amount / 1000) . 'K';
    }
    return '$' . number_format($amount);
}

/**
 * Get detailed data for a single school.
 *
 * @param string $district_slug The district URL slug.
 * @param string $school_slug The school URL slug.
 * @return array|null School data or null if not found.
 */
function bmn_get_school_detail_data($district_slug, $school_slug) {
    global $wpdb;

    // Check cache first
    $cache_key = 'bmn_school_detail_' . sanitize_key($district_slug . '_' . $school_slug);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $schools_table = $wpdb->prefix . 'bmn_schools';
    $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
    $scores_table = $wpdb->prefix . 'bmn_school_test_scores';
    $demographics_table = $wpdb->prefix . 'bmn_school_demographics';
    $features_table = $wpdb->prefix . 'bmn_school_features';
    $sports_table = $wpdb->prefix . 'bmn_school_sports';
    $district_rankings_table = $wpdb->prefix . 'bmn_district_rankings';

    // Find district by slug
    $district = bmn_find_district_by_slug($district_slug);
    if (!$district) {
        return null;
    }

    // Find school in district by slug
    $schools = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$schools_table} WHERE district_id = %d",
        $district->id
    ));

    $school = null;
    foreach ($schools as $s) {
        if (sanitize_title($s->name) === $school_slug) {
            $school = $s;
            break;
        }
    }

    if (!$school) {
        return null;
    }

    // Get school ranking
    $ranking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$rankings_table} WHERE school_id = %d ORDER BY year DESC LIMIT 1",
        $school->id
    ));

    // Get previous year ranking for trend
    $prev_ranking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$rankings_table} WHERE school_id = %d ORDER BY year DESC LIMIT 1 OFFSET 1",
        $school->id
    ));

    // Get district ranking
    $district_ranking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$district_rankings_table} WHERE district_id = %d ORDER BY year DESC LIMIT 1",
        $district->id
    ));

    // Get MCAS test scores (last 3 years)
    $test_scores = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$scores_table} WHERE school_id = %d ORDER BY year DESC, subject, grade",
        $school->id
    ));

    // Group scores by year and subject
    $scores_by_year = array();
    foreach ($test_scores as $score) {
        $year = $score->year;
        if (!isset($scores_by_year[$year])) {
            $scores_by_year[$year] = array();
        }
        $scores_by_year[$year][] = $score;
    }

    // Get MCAS averages by subject (most recent year)
    $mcas_averages = $wpdb->get_results($wpdb->prepare(
        "SELECT subject,
                AVG(proficient_or_above_pct) as avg_proficient,
                AVG(advanced_pct) as avg_advanced
         FROM {$scores_table}
         WHERE school_id = %d AND year = (SELECT MAX(year) FROM {$scores_table} WHERE school_id = %d)
         GROUP BY subject",
        $school->id,
        $school->id
    ));

    // Get demographics
    $demographics = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$demographics_table} WHERE school_id = %d ORDER BY year DESC LIMIT 1",
        $school->id
    ));

    // Get features (attendance, graduation, AP, etc.)
    $features = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$features_table} WHERE school_id = %d ORDER BY created_at DESC",
        $school->id
    ));

    // Index features by type
    $features_by_type = array();
    foreach ($features as $feature) {
        $type = $feature->feature_type;
        if (!isset($features_by_type[$type])) {
            $features_by_type[$type] = $feature;
        }
    }

    // Get sports (for high schools)
    $sports = array();
    $school_level = strtolower($school->level ?? '');
    if (strpos($school_level, 'high') !== false) {
        $sports = $wpdb->get_results($wpdb->prepare(
            "SELECT sport, gender, participants FROM {$sports_table}
             WHERE school_id = %d
             ORDER BY participants DESC",
            $school->id
        ));
    }

    // Calculate trend
    $trend = null;
    if ($ranking && $prev_ranking) {
        $rank_change = $prev_ranking->state_rank - $ranking->state_rank;
        $trend = array(
            'direction' => $rank_change > 0 ? 'up' : ($rank_change < 0 ? 'down' : 'stable'),
            'rank_change' => abs($rank_change),
            'text' => $rank_change > 0 ? "Improved {$rank_change} spots from last year" :
                     ($rank_change < 0 ? "Dropped " . abs($rank_change) . " spots from last year" : "No change from last year"),
        );
    }

    // Build result
    $result = array(
        // Basic info
        'id'              => $school->id,
        'name'            => $school->name,
        'slug'            => $school_slug,
        'url'             => home_url('/schools/' . $district_slug . '/' . $school_slug . '/'),
        'address'         => $school->address,
        'city'            => $school->city,
        'state'           => $school->state ?? 'MA',
        'zip'             => $school->zip,
        'phone'           => $school->phone,
        'website'         => $school->website,
        'level'           => $school->level,
        'grades_low'      => $school->grades_low,
        'grades_high'     => $school->grades_high,
        'type'            => $school->type ?? 'Public',
        'latitude'        => $school->latitude,
        'longitude'       => $school->longitude,

        // District info
        'district'        => array(
            'id'          => $district->id,
            'name'        => $district->name,
            'slug'        => $district_slug,
            'url'         => home_url('/schools/' . $district_slug . '/'),
            'letter_grade'=> bmn_get_letter_grade_from_percentile($district_ranking->percentile_rank ?? null),
            'state_rank'  => $district_ranking->state_rank ?? null,
        ),

        // Ranking data
        'composite_score' => $ranking->composite_score ?? null,
        'percentile_rank' => $ranking->percentile_rank ?? null,
        'letter_grade'    => bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? null),
        'state_rank'      => $ranking->state_rank ?? null,
        'category_rank'   => $ranking->category_rank ?? null,
        'category_total'  => $ranking->category_total ?? null,
        'ranking_year'    => $ranking->year ?? null,
        'trend'           => $trend,

        // MCAS scores
        'mcas_averages'   => $mcas_averages,
        'scores_by_year'  => $scores_by_year,

        // Demographics
        'demographics'    => $demographics ? array(
            'total_students'           => $demographics->total_students ?? null,
            'pct_white'                => $demographics->pct_white ?? null,
            'pct_black'                => $demographics->pct_black ?? null,
            'pct_hispanic'             => $demographics->pct_hispanic ?? null,
            'pct_asian'                => $demographics->pct_asian ?? null,
            'pct_multirace'            => $demographics->pct_multiracial ?? null,
            'pct_male'                 => $demographics->pct_male ?? null,
            'pct_female'               => $demographics->pct_female ?? null,
            'pct_english_learner'      => $demographics->pct_english_learner ?? null,
            'pct_special_education'    => $demographics->pct_special_ed ?? null,
            'pct_free_reduced_lunch'   => $demographics->pct_free_reduced_lunch ?? null,
        ) : null,

        // Features
        'features'        => array(
            'attendance'      => isset($features_by_type['attendance']) ? json_decode($features_by_type['attendance']->feature_value, true) : null,
            'graduation'      => isset($features_by_type['graduation']) ? json_decode($features_by_type['graduation']->feature_value, true) : null,
            'ap_summary'      => isset($features_by_type['ap_summary']) ? json_decode($features_by_type['ap_summary']->feature_value, true) : null,
            'staffing'        => isset($features_by_type['staffing']) ? json_decode($features_by_type['staffing']->feature_value, true) : null,
            'masscore'        => isset($features_by_type['masscore']) ? json_decode($features_by_type['masscore']->feature_value, true) : null,
            'expenditure'     => isset($features_by_type['expenditure']) ? json_decode($features_by_type['expenditure']->feature_value, true) : null,
        ),

        // Sports (high schools only)
        'sports'          => !empty($sports) ? array(
            'count'        => count($sports),
            'total_participants' => array_sum(array_column($sports, 'participants')),
            'list'         => $sports,
        ) : null,

        // SEO data
        'data_freshness'  => $ranking->calculated_at ?? date('Y-m-d'),
    );

    // Cache for 30 minutes
    set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

    return $result;
}

/**
 * Get district name suggestions for autocomplete.
 *
 * @param string $term Search term.
 * @param int    $limit Maximum number of results.
 * @return array Array of matching district names.
 */
function bmn_get_district_suggestions($term, $limit = 10) {
    global $wpdb;

    if (strlen($term) < 2) {
        return array();
    }

    // Check cache first
    $cache_key = 'bmn_district_suggest_' . md5($term);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $districts_table = $wpdb->prefix . 'bmn_school_districts';
    $rankings_table = $wpdb->prefix . 'bmn_district_rankings';

    // Search district names (exclude duplicates and districts with no schools)
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT d.name, r.letter_grade, r.percentile_rank
         FROM {$districts_table} d
         LEFT JOIN {$rankings_table} r ON d.id = r.district_id AND r.year = 2025
         WHERE d.name LIKE %s
         AND (d.type IS NULL OR d.type NOT IN ('duplicate', 'inactive'))
         AND EXISTS (SELECT 1 FROM {$wpdb->prefix}bmn_schools s WHERE s.district_id = d.id)
         ORDER BY r.percentile_rank DESC, d.name ASC
         LIMIT %d",
        '%' . $wpdb->esc_like($term) . '%',
        $limit
    ));

    $suggestions = array();
    foreach ($results as $row) {
        // Extract city/town name from district name
        $display_name = $row->name;
        $city_name = preg_replace('/ (School District|Public Schools|Regional School District|Schools)$/i', '', $row->name);

        $suggestions[] = array(
            'value'       => $city_name,
            'label'       => $display_name,
            'letter_grade'=> $row->letter_grade ?? bmn_get_letter_grade_from_percentile($row->percentile_rank),
        );
    }

    // Cache for 1 hour
    set_transient($cache_key, $suggestions, HOUR_IN_SECONDS);

    return $suggestions;
}

/**
 * AJAX handler for district autocomplete.
 */
function bmn_ajax_district_autocomplete() {
    // Check both GET and POST for the term parameter
    $term = '';
    if (isset($_GET['term'])) {
        $term = sanitize_text_field($_GET['term']);
    } elseif (isset($_POST['term'])) {
        $term = sanitize_text_field($_POST['term']);
    } elseif (isset($_REQUEST['term'])) {
        $term = sanitize_text_field($_REQUEST['term']);
    }

    if (strlen($term) < 2) {
        wp_send_json_success(array());
        return;
    }

    $suggestions = bmn_get_district_suggestions($term, 8);
    wp_send_json_success($suggestions);
}
add_action('wp_ajax_bmn_district_autocomplete', 'bmn_ajax_district_autocomplete');
add_action('wp_ajax_nopriv_bmn_district_autocomplete', 'bmn_ajax_district_autocomplete');

/**
 * Clear all school page caches.
 *
 * Call this when school or district data is updated.
 *
 * @return int Number of transients deleted.
 */
function bmn_clear_schools_cache() {
    global $wpdb;

    // Delete all school page transients
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_bmn_district%'
            OR option_name LIKE '_transient_bmn_districts_browse%'
            OR option_name LIKE '_transient_timeout_bmn_district%'
            OR option_name LIKE '_transient_timeout_bmn_districts_browse%'"
    );

    // Also clear object cache
    wp_cache_flush();

    return $deleted;
}

/**
 * Generate dynamic FAQs for a school page based on available data.
 *
 * @param array $school_data School data from bmn_get_school_detail_data().
 * @return array Array of FAQ items with 'question' and 'answer' keys.
 */
function bmn_generate_school_faqs($school_data) {
    $faqs = array();
    $name = $school_data['name'] ?? 'This school';
    $letter_grade = $school_data['letter_grade'] ?? 'N/A';
    $percentile_rank = $school_data['percentile_rank'] ?? null;
    $state_rank = $school_data['state_rank'] ?? null;
    $level = ucfirst(strtolower($school_data['level'] ?? 'school'));
    $district_name = $school_data['district']['name'] ?? 'the district';
    $city = $school_data['city'] ?? '';
    $ranking_year = $school_data['ranking_year'] ?? date('Y');

    // 1. Grade rating FAQ (always include)
    if ($letter_grade !== 'N/A' && $percentile_rank !== null) {
        $top_pct = 100 - intval($percentile_rank);
        $rank_text = $state_rank ? ", ranked #{$state_rank} in Massachusetts" : "";
        $faqs[] = array(
            'question' => "What is {$name}'s grade rating?",
            'answer'   => "{$name} has earned a grade of {$letter_grade}{$rank_text}, placing it in the top {$top_pct}% of Massachusetts {$level} schools. This rating is based on {$ranking_year} MCAS test scores, attendance rates, and other performance metrics from the Massachusetts Department of Elementary and Secondary Education.",
        );
    } else {
        $faqs[] = array(
            'question' => "What is {$name}'s grade rating?",
            'answer'   => "{$name} currently has limited data available for rating. This may be because the school is new, has a small student population, or MCAS testing data is not yet available. We update school ratings as new data becomes available from DESE.",
        );
    }

    // 2. Student enrollment FAQ
    if (!empty($school_data['demographics']['total_students'])) {
        $students = number_format($school_data['demographics']['total_students']);
        $faqs[] = array(
            'question' => "How many students attend {$name}?",
            'answer'   => "Approximately {$students} students are enrolled at {$name}.",
        );
    }

    // 3. MCAS scores FAQ
    if (!empty($school_data['mcas_averages'])) {
        $subjects = array();
        foreach ($school_data['mcas_averages'] as $avg) {
            if (!empty($avg->avg_proficient)) {
                $subjects[] = sprintf('%s (%.0f%% proficient)', $avg->subject, $avg->avg_proficient);
            }
        }
        if (!empty($subjects)) {
            $subjects_text = implode(', ', array_slice($subjects, 0, 3));
            $faqs[] = array(
                'question' => "How does {$name} perform on MCAS tests?",
                'answer'   => "Based on the most recent MCAS results, {$name}'s proficiency rates are: {$subjects_text}. MCAS (Massachusetts Comprehensive Assessment System) tests measure student achievement in English Language Arts, Mathematics, and Science.",
            );
        }
    }

    // 4. Student-teacher ratio FAQ
    if (!empty($school_data['features']['staffing']['student_teacher_ratio'])) {
        $ratio = $school_data['features']['staffing']['student_teacher_ratio'];
        $comparison = $ratio <= 12 ? 'below the state average, indicating smaller class sizes' :
                     ($ratio >= 18 ? 'above the state average' : 'near the state average');
        $faqs[] = array(
            'question' => "What is the student-teacher ratio at {$name}?",
            'answer'   => "{$name} has a student-teacher ratio of {$ratio}:1, which is {$comparison}. Lower ratios typically mean more individualized attention for students.",
        );
    }

    // 5. Sports FAQ (high schools)
    if (!empty($school_data['sports']) && $school_data['sports']['count'] > 0) {
        $sports_count = $school_data['sports']['count'];
        $participants = number_format($school_data['sports']['total_participants']);

        // Count by gender
        $boys = 0;
        $girls = 0;
        $coed = 0;
        foreach ($school_data['sports']['list'] as $sport) {
            if ($sport->gender === 'Boys') $boys++;
            elseif ($sport->gender === 'Girls') $girls++;
            else $coed++;
        }

        $gender_breakdown = array();
        if ($boys > 0) $gender_breakdown[] = "{$boys} boys' sports";
        if ($girls > 0) $gender_breakdown[] = "{$girls} girls' sports";
        if ($coed > 0) $gender_breakdown[] = "{$coed} coed sports";
        $gender_text = implode(', ', $gender_breakdown);

        $faqs[] = array(
            'question' => "What sports does {$name} offer?",
            'answer'   => "{$name} offers {$sports_count} athletic programs ({$gender_text}) with approximately {$participants} student athletes participating. Sports programs are sanctioned by the MIAA (Massachusetts Interscholastic Athletic Association).",
        );
    }

    // 6. AP courses FAQ (high schools)
    if (!empty($school_data['features']['ap_summary']['ap_courses_offered'])) {
        $ap_count = $school_data['features']['ap_summary']['ap_courses_offered'];
        $ap_text = $ap_count >= 15 ? 'a comprehensive selection of' : ($ap_count >= 8 ? 'a strong selection of' : '');
        $faqs[] = array(
            'question' => "Does {$name} offer AP courses?",
            'answer'   => "Yes, {$name} offers {$ap_text} {$ap_count} Advanced Placement (AP) courses. AP courses allow students to take college-level classes and potentially earn college credit through AP exams.",
        );
    }

    // 7. Graduation rate FAQ (high schools)
    if (!empty($school_data['features']['graduation']['graduation_rate'])) {
        $grad_rate = $school_data['features']['graduation']['graduation_rate'];
        $comparison = $grad_rate >= 95 ? 'excellent' : ($grad_rate >= 90 ? 'strong' : ($grad_rate >= 85 ? 'solid' : ''));
        $faqs[] = array(
            'question' => "What is the graduation rate at {$name}?",
            'answer'   => "{$name} has a {$comparison} {$grad_rate}% graduation rate. The state average graduation rate in Massachusetts is approximately 89%.",
        );
    }

    // 8. Attendance FAQ
    if (!empty($school_data['features']['attendance']['attendance_rate'])) {
        $attend_rate = $school_data['features']['attendance']['attendance_rate'];
        $faqs[] = array(
            'question' => "What is the attendance rate at {$name}?",
            'answer'   => "{$name} has an attendance rate of {$attend_rate}%. Strong attendance is correlated with better academic outcomes.",
        );
    }

    // 9. District and enrollment FAQ (always include)
    $faqs[] = array(
        'question' => "What school district is {$name} part of?",
        'answer'   => "{$name} is part of {$district_name}. For enrollment information, contact the district office or visit the school's website. Enrollment typically requires proof of residency within the district boundaries.",
    );

    // 10. How ratings are calculated FAQ
    $faqs[] = array(
        'question' => "How are Massachusetts school ratings calculated?",
        'answer'   => "School ratings are calculated using multiple data points from the Massachusetts Department of Elementary and Secondary Education (DESE), including MCAS test scores (proficiency and growth), attendance rates, graduation rates (for high schools), and student-teacher ratios. Schools are ranked by percentile against similar schools statewide.",
    );

    // Return up to 8 FAQs
    return array_slice($faqs, 0, 8);
}

/**
 * Generate dynamic FAQs for a district page based on available data.
 *
 * @param array $district_data District data from bmn_get_district_detail_data().
 * @return array Array of FAQ items with 'question' and 'answer' keys.
 */
function bmn_generate_district_faqs($district_data) {
    $faqs = array();
    $name = $district_data['name'] ?? 'This district';
    $letter_grade = $district_data['letter_grade'] ?? 'N/A';
    $state_rank = $district_data['state_rank'] ?? null;
    $schools_count = $district_data['schools_count'] ?? 0;
    $total_students = $district_data['total_students'] ?? null;

    // Count schools by level
    $elementary_count = count($district_data['schools_by_level']['elementary'] ?? array());
    $middle_count = count($district_data['schools_by_level']['middle'] ?? array());
    $high_count = count($district_data['schools_by_level']['high'] ?? array());

    // 1. District grade FAQ
    if ($letter_grade !== 'N/A') {
        $rank_text = $state_rank ? " and is ranked #{$state_rank} among Massachusetts school districts" : "";
        $faqs[] = array(
            'question' => "What is {$name}'s overall grade?",
            'answer'   => "{$name} has earned an overall grade of {$letter_grade}{$rank_text}. This composite rating reflects the average performance of all schools within the district based on MCAS scores, attendance, and other metrics.",
        );
    }

    // 2. Number of schools FAQ
    if ($schools_count > 0) {
        $breakdown = array();
        if ($elementary_count > 0) $breakdown[] = "{$elementary_count} elementary";
        if ($middle_count > 0) $breakdown[] = "{$middle_count} middle";
        if ($high_count > 0) $breakdown[] = "{$high_count} high";
        $breakdown_text = !empty($breakdown) ? ' (' . implode(', ', $breakdown) . ')' : '';

        $students_text = $total_students ? " serving approximately " . number_format($total_students) . " students" : "";

        $faqs[] = array(
            'question' => "How many schools are in {$name}?",
            'answer'   => "{$name} includes {$schools_count} schools{$breakdown_text}{$students_text}.",
        );
    }

    // 3. Per-pupil spending FAQ
    if (!empty($district_data['expenditure_per_pupil'])) {
        $spending = number_format($district_data['expenditure_per_pupil']);
        $state_avg = 18500; // Approximate MA average
        $comparison = $district_data['expenditure_per_pupil'] > $state_avg ? 'above' : 'below';
        $faqs[] = array(
            'question' => "What is the per-pupil spending in {$name}?",
            'answer'   => "{$name} spends approximately \${$spending} per student annually, which is {$comparison} the Massachusetts state average of approximately \$" . number_format($state_avg) . ". Per-pupil spending reflects total educational expenditures divided by student enrollment.",
        );
    }

    // 4. College outcomes FAQ
    if (!empty($district_data['college_outcomes'])) {
        $outcomes = $district_data['college_outcomes'];
        $four_year = $outcomes['four_year_pct'] ?? 0;
        $two_year = $outcomes['two_year_pct'] ?? 0;
        $total_college = $four_year + $two_year;

        if ($total_college > 0) {
            $faqs[] = array(
                'question' => "What percentage of {$name} graduates go to college?",
                'answer'   => "Approximately {$total_college}% of {$name} graduates pursue higher education, with {$four_year}% attending 4-year colleges and {$two_year}% attending 2-year colleges. This data is based on the most recent graduating class tracked by DESE.",
            );
        }
    }

    // 5. Top schools in district FAQ
    $top_schools = array();
    foreach ($district_data['schools'] ?? array() as $school) {
        if (!empty($school->letter_grade) && substr($school->letter_grade, 0, 1) === 'A') {
            $top_schools[] = $school->name . ' (' . $school->letter_grade . ')';
        }
    }
    if (!empty($top_schools)) {
        $top_text = implode(', ', array_slice($top_schools, 0, 5));
        $faqs[] = array(
            'question' => "What are the highest-rated schools in {$name}?",
            'answer'   => "The top-rated schools in {$name} include: {$top_text}. Click on any school name to view detailed performance data, MCAS scores, and demographics.",
        );
    }

    // 6. Cities served FAQ
    if (!empty($district_data['cities_served']) && count($district_data['cities_served']) > 1) {
        $cities_text = implode(', ', $district_data['cities_served']);
        $faqs[] = array(
            'question' => "What cities does {$name} serve?",
            'answer'   => "{$name} serves students from: {$cities_text}. Regional school districts may serve multiple municipalities under shared administration.",
        );
    }

    // 7. How to enroll FAQ
    $faqs[] = array(
        'question' => "How can I enroll my child in {$name}?",
        'answer'   => "To enroll in {$name}, contact the district's central office for enrollment procedures and required documentation. You'll typically need proof of residency, immunization records, and previous school records. Some districts offer school choice programs for out-of-district students.",
    );

    // 8. Rating methodology FAQ
    $faqs[] = array(
        'question' => "How are district ratings calculated?",
        'answer'   => "District ratings are based on the composite performance of all schools within the district. We calculate average MCAS proficiency rates, attendance, graduation rates, and other metrics weighted by school enrollment. Districts are then ranked against all Massachusetts districts to determine percentile rankings and letter grades.",
    );

    // Return up to 6 FAQs
    return array_slice($faqs, 0, 6);
}

/**
 * Get school highlights/badges for display.
 *
 * @param int $school_id School ID.
 * @param array $school_data Optional school data if already loaded.
 * @return array Array of highlight items with 'text', 'type', and optional 'detail'.
 */
function bmn_get_school_highlights($school_id, $school_data = null) {
    $highlights = array();

    // If no data passed, we'd need to load it - for now use passed data
    if (!$school_data) {
        return $highlights;
    }

    // Strong AP Program (10+ courses)
    if (!empty($school_data['features']['ap_summary']['ap_courses_offered'])) {
        $ap_count = intval($school_data['features']['ap_summary']['ap_courses_offered']);
        if ($ap_count >= 10) {
            $highlights[] = array(
                'text'   => 'Strong AP',
                'type'   => 'ap',
                'detail' => $ap_count . ' courses',
            );
        }
    }

    // Low Class Size (student-teacher ratio <= 12:1)
    if (!empty($school_data['features']['staffing']['student_teacher_ratio'])) {
        $ratio = floatval($school_data['features']['staffing']['student_teacher_ratio']);
        if ($ratio <= 12 && $ratio > 0) {
            $highlights[] = array(
                'text'   => 'Small Classes',
                'type'   => 'ratio',
                'detail' => $ratio . ':1 ratio',
            );
        }
    }

    // High Graduation Rate (95%+)
    if (!empty($school_data['features']['graduation']['graduation_rate'])) {
        $grad_rate = floatval($school_data['features']['graduation']['graduation_rate']);
        if ($grad_rate >= 95) {
            $highlights[] = array(
                'text'   => 'High Grad Rate',
                'type'   => 'graduation',
                'detail' => $grad_rate . '%',
            );
        }
    }

    // Active Sports (15+ programs)
    if (!empty($school_data['sports']['count'])) {
        $sports_count = intval($school_data['sports']['count']);
        if ($sports_count >= 15) {
            $highlights[] = array(
                'text'   => 'Active Athletics',
                'type'   => 'sports',
                'detail' => $sports_count . ' sports',
            );
        }
    }

    // Diverse Student Body (no single group >60%)
    if (!empty($school_data['demographics'])) {
        $demo = $school_data['demographics'];
        $max_pct = max(
            floatval($demo['pct_white'] ?? 0),
            floatval($demo['pct_black'] ?? 0),
            floatval($demo['pct_hispanic'] ?? 0),
            floatval($demo['pct_asian'] ?? 0)
        );
        if ($max_pct <= 60 && $max_pct > 0) {
            $highlights[] = array(
                'text'   => 'Diverse',
                'type'   => 'diversity',
                'detail' => null,
            );
        }
    }

    // High Attendance (96%+)
    if (!empty($school_data['features']['attendance']['attendance_rate'])) {
        $attend_rate = floatval($school_data['features']['attendance']['attendance_rate']);
        if ($attend_rate >= 96) {
            $highlights[] = array(
                'text'   => 'High Attendance',
                'type'   => 'attendance',
                'detail' => $attend_rate . '%',
            );
        }
    }

    // Return up to 4 highlights
    return array_slice($highlights, 0, 4);
}

/**
 * Get related schools in the same district at the same level.
 *
 * @param array $school_data School data from bmn_get_school_detail_data().
 * @param int   $limit       Maximum number of related schools.
 * @return array Array of related school objects.
 */
function bmn_get_related_schools($school_data, $limit = 6) {
    global $wpdb;

    if (empty($school_data['district']['id']) || empty($school_data['level'])) {
        return array();
    }

    $schools_table = $wpdb->prefix . 'bmn_schools';
    $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
    $latest_year = intval(date('Y'));

    // Get other schools in same district at same level
    $related = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id, s.name, s.level, s.city,
                r.composite_score, r.percentile_rank, r.state_rank
         FROM {$schools_table} s
         LEFT JOIN {$rankings_table} r ON s.id = r.school_id AND r.year = %d
         WHERE s.district_id = %d
           AND s.id != %d
           AND LOWER(s.level) LIKE %s
         ORDER BY r.composite_score DESC
         LIMIT %d",
        $latest_year,
        intval($school_data['district']['id']),
        intval($school_data['id']),
        '%' . $wpdb->esc_like(strtolower($school_data['level'])) . '%',
        intval($limit)
    ));

    // If not enough at same level, get other schools in district
    if (count($related) < $limit) {
        $exclude_ids = array($school_data['id']);
        foreach ($related as $r) {
            $exclude_ids[] = $r->id;
        }
        $exclude_ids_str = implode(',', array_map('intval', $exclude_ids));

        $more = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.name, s.level, s.city,
                    r.composite_score, r.percentile_rank, r.state_rank
             FROM {$schools_table} s
             LEFT JOIN {$rankings_table} r ON s.id = r.school_id AND r.year = %d
             WHERE s.district_id = %d
               AND s.id NOT IN ({$exclude_ids_str})
             ORDER BY r.composite_score DESC
             LIMIT %d",
            $latest_year,
            intval($school_data['district']['id']),
            $limit - count($related)
        ));

        $related = array_merge($related, $more);
    }

    // Add letter grades and URLs
    $district_slug = $school_data['district']['slug'] ?? sanitize_title($school_data['district']['name'] ?? '');
    foreach ($related as &$school) {
        $school->letter_grade = bmn_get_letter_grade_from_percentile($school->percentile_rank);
        $school->slug = sanitize_title($school->name);
        $school->url = home_url('/schools/' . $district_slug . '/' . $school->slug . '/');
    }

    return $related;
}
