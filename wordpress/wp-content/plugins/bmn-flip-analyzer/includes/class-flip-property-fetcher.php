<?php
/**
 * Property Fetcher — retrieves listings matching analysis filters.
 *
 * Extracted from Flip_Analyzer in v0.14.0 to eliminate duplicated filter
 * logic between fetch_properties() and fetch_matching_listing_ids().
 * The shared build_filter_conditions() method is the single source of truth
 * for all 17 analysis filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Property_Fetcher {

    /**
     * Check if any selected sub-types belong to the "Residential Income" property type.
     * Uses name-based heuristic: any sub-type containing "Family" (except SFR) or "Duplex".
     */
    private static function includes_multifamily(array $sub_types): bool {
        foreach ($sub_types as $st) {
            if ($st === 'Single Family Residence') continue;
            if (stripos($st, 'Family') !== false || stripos($st, 'Duplex') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if any selected sub-types belong to the standard "Residential" property type.
     */
    private static function includes_residential(array $sub_types): bool {
        $residential_types = ['Single Family Residence', 'Condominium', 'Townhouse', 'Stock Cooperative', 'Condex', 'Mobile Home'];
        return !empty(array_intersect($sub_types, $residential_types));
    }

    /**
     * Build WHERE conditions and params from analysis filters.
     *
     * Single source of truth for all 17 filter blocks. Used by both
     * fetch_properties() and fetch_matching_listing_ids().
     *
     * @param array $cities  Target city names.
     * @param array $filters Analysis filter settings.
     * @return array { where: string[], params: mixed[], join: string, statuses: string[] }
     */
    private static function build_filter_conditions(array $cities, array $filters): array {
        global $wpdb;

        // Determine property_type filter based on selected sub-types
        $sub_types = !empty($filters['property_sub_types']) ? $filters['property_sub_types'] : ['Single Family Residence'];
        $has_multifamily = self::includes_multifamily($sub_types);
        $has_residential = self::includes_residential($sub_types);

        if ($has_multifamily && $has_residential) {
            $property_type_sql = "s.property_type IN ('Residential', 'Residential Income')";
        } elseif ($has_multifamily) {
            $property_type_sql = "s.property_type = 'Residential Income'";
        } else {
            $property_type_sql = "s.property_type = 'Residential'";
        }

        $where  = [$property_type_sql, "s.list_price > 0", "s.building_area_total > 0"];
        $params = [];
        $join   = '';

        // Property sub types
        $ph = implode(',', array_fill(0, count($sub_types), '%s'));
        $where[] = "s.property_sub_type IN ({$ph})";
        $params  = array_merge($params, $sub_types);

        // Statuses
        $statuses = !empty($filters['statuses']) ? $filters['statuses'] : ['Active'];
        $ph = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = "s.standard_status IN ({$ph})";
        $params  = array_merge($params, $statuses);

        // Cities
        $ph = implode(',', array_fill(0, count($cities), '%s'));
        $where[] = "s.city IN ({$ph})";
        $params  = array_merge($params, $cities);

        // Sewer (requires JOIN to bme_listing_details)
        if (!empty($filters['sewer_public_only'])) {
            $join = "INNER JOIN {$wpdb->prefix}bme_listing_details d ON s.listing_id = d.listing_id";
            $where[] = "d.sewer LIKE '%%Public Sewer%%'";
        }

        // Days on market range
        if (!empty($filters['min_dom'])) {
            $where[]  = "s.days_on_market >= %d";
            $params[] = (int) $filters['min_dom'];
        }
        if (!empty($filters['max_dom'])) {
            $where[]  = "s.days_on_market <= %d";
            $params[] = (int) $filters['max_dom'];
        }

        // List date range
        if (!empty($filters['list_date_from'])) {
            $where[]  = "s.listing_contract_date >= %s";
            $params[] = $filters['list_date_from'];
        }
        if (!empty($filters['list_date_to'])) {
            $where[]  = "s.listing_contract_date <= %s";
            $params[] = $filters['list_date_to'];
        }

        // Year built range
        if (!empty($filters['year_built_min'])) {
            $where[]  = "s.year_built >= %d";
            $params[] = (int) $filters['year_built_min'];
        }
        if (!empty($filters['year_built_max'])) {
            $where[]  = "s.year_built <= %d";
            $params[] = (int) $filters['year_built_max'];
        }

        // Price range
        if (!empty($filters['min_price'])) {
            $where[]  = "s.list_price >= %f";
            $params[] = (float) $filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[]  = "s.list_price <= %f";
            $params[] = (float) $filters['max_price'];
        }

        // Sqft range
        if (!empty($filters['min_sqft'])) {
            $where[]  = "s.building_area_total >= %d";
            $params[] = (int) $filters['min_sqft'];
        }
        if (!empty($filters['max_sqft'])) {
            $where[]  = "s.building_area_total <= %d";
            $params[] = (int) $filters['max_sqft'];
        }

        // Lot size
        if (!empty($filters['min_lot_acres'])) {
            $where[]  = "s.lot_size_acres >= %f";
            $params[] = (float) $filters['min_lot_acres'];
        }

        // Beds / baths
        if (!empty($filters['min_beds'])) {
            $where[]  = "s.bedrooms_total >= %d";
            $params[] = (int) $filters['min_beds'];
        }
        if (!empty($filters['min_baths'])) {
            $where[]  = "s.bathrooms_total >= %f";
            $params[] = (float) $filters['min_baths'];
        }

        // Garage
        if (!empty($filters['has_garage'])) {
            $where[] = "s.garage_spaces > 0";
        }

        return [
            'where'    => $where,
            'params'   => $params,
            'join'     => $join,
            'statuses' => $statuses,
        ];
    }

    /**
     * Fetch listings matching analysis filters from target cities.
     *
     * @param array $cities  Target city names.
     * @param int   $limit   Max properties to return.
     * @param array $filters Analysis filter settings.
     * @return array Array of listing summary objects.
     */
    public static function fetch_properties(array $cities, int $limit, array $filters = []): array {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $fc = self::build_filter_conditions($cities, $filters);
        $where    = $fc['where'];
        $params   = $fc['params'];
        $join     = $fc['join'];
        $statuses = $fc['statuses'];

        $params[] = $limit;
        $where_sql = implode(' AND ', $where);

        // Query active listings
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$summary_table} s {$join}
             WHERE {$where_sql}
             ORDER BY s.list_price ASC
             LIMIT %d",
            $params
        ));

        // Also query archive table if Closed status is included
        if (in_array('Closed', $statuses, true)) {
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $archive_join  = str_replace('bme_listing_details', 'bme_listing_details_archive', $join);
            $archive_results = $wpdb->get_results($wpdb->prepare(
                "SELECT s.* FROM {$archive_table} s {$archive_join}
                 WHERE {$where_sql}
                 ORDER BY s.list_price ASC
                 LIMIT %d",
                $params
            ));
            if ($archive_results) {
                $results = array_merge($results, $archive_results);
                // Re-sort by list_price and apply limit
                usort($results, fn($a, $b) => (float) $a->list_price <=> (float) $b->list_price);
                $results = array_slice($results, 0, $limit);
            }
        }

        return $results;
    }

    /**
     * Fetch specific properties by listing_id (for monitor incremental runs).
     * Checks both active and archive summary tables.
     *
     * @param array $listing_ids Array of MLS listing IDs.
     * @return array Array of listing summary objects.
     */
    public static function fetch_properties_by_ids(array $listing_ids): array {
        global $wpdb;
        if (empty($listing_ids)) {
            return [];
        }

        $table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
        $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
        $ids = array_map('intval', $listing_ids);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id IN ({$placeholders})",
            $ids
        ));

        // Check archive for any IDs not found in the active table
        $found_ids = array_map(function ($r) { return (int) $r->listing_id; }, $results);
        $missing_ids = array_values(array_diff($ids, $found_ids));

        if (!empty($missing_ids)) {
            $ph = implode(',', array_fill(0, count($missing_ids), '%d'));
            $archive_results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_id IN ({$ph})",
                $missing_ids
            ));
            if ($archive_results) {
                $results = array_merge($results, $archive_results);
            }
        }

        return $results;
    }

    /**
     * Fetch just listing_ids matching criteria (for monitor new-listing detection).
     *
     * @param array $cities  Target city names.
     * @param array $filters Analysis filter settings.
     * @return array Array of listing_id strings.
     */
    public static function fetch_matching_listing_ids(array $cities, array $filters = []): array {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $fc = self::build_filter_conditions($cities, $filters);
        $where    = $fc['where'];
        $params   = $fc['params'];
        $join     = $fc['join'];
        $statuses = $fc['statuses'];

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT s.listing_id FROM {$summary_table} s {$join} WHERE {$where_sql}",
            $params
        ));

        // Also query archive table if Closed status is included
        if (in_array('Closed', $statuses, true)) {
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $archive_join  = str_replace('bme_listing_details', 'bme_listing_details_archive', $join);
            $archive_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT s.listing_id FROM {$archive_table} s {$archive_join} WHERE {$where_sql}",
                $params
            ));
            if ($archive_ids) {
                $results = array_unique(array_merge($results, $archive_ids));
            }
        }

        return $results;
    }
}
