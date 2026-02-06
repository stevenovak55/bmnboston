<?php
/**
 * Database layer for flip analysis results.
 *
 * Table: wp_bmn_flip_scores
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Database {

    const TABLE_NAME = 'bmn_flip_scores';

    /**
     * Get the full table name with prefix.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the results table via dbDelta.
     */
    public static function create_tables(): void {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            listing_id INT NOT NULL,
            listing_key VARCHAR(128) DEFAULT '',
            run_date DATETIME NOT NULL,

            total_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            financial_score DECIMAL(5,2) DEFAULT 0,
            property_score DECIMAL(5,2) DEFAULT 0,
            location_score DECIMAL(5,2) DEFAULT 0,
            market_score DECIMAL(5,2) DEFAULT 0,
            photo_score DECIMAL(5,2) DEFAULT NULL,

            estimated_arv DECIMAL(12,2) DEFAULT 0,
            arv_confidence VARCHAR(20) DEFAULT '',
            comp_count INT DEFAULT 0,
            avg_comp_ppsf DECIMAL(10,2) DEFAULT 0,
            comp_details_json LONGTEXT DEFAULT NULL,
            neighborhood_ceiling DECIMAL(12,2) DEFAULT 0,
            ceiling_pct DECIMAL(5,1) DEFAULT 0,
            ceiling_warning TINYINT(1) DEFAULT 0,
            estimated_rehab_cost DECIMAL(12,2) DEFAULT 0,
            rehab_level VARCHAR(20) DEFAULT 'unknown',
            mao DECIMAL(12,2) DEFAULT 0,
            estimated_profit DECIMAL(12,2) DEFAULT 0,
            estimated_roi DECIMAL(8,2) DEFAULT 0,
            financing_costs DECIMAL(12,2) DEFAULT 0,
            holding_costs DECIMAL(12,2) DEFAULT 0,
            rehab_contingency DECIMAL(12,2) DEFAULT 0,
            hold_months INT DEFAULT 6,
            cash_profit DECIMAL(12,2) DEFAULT 0,
            cash_roi DECIMAL(8,2) DEFAULT 0,
            cash_on_cash_roi DECIMAL(8,2) DEFAULT 0,
            market_strength VARCHAR(20) DEFAULT 'balanced',
            avg_sale_to_list DECIMAL(5,3) DEFAULT 1.000,
            rehab_multiplier DECIMAL(4,2) DEFAULT 1.00,
            road_type VARCHAR(30) DEFAULT 'unknown',
            days_on_market INT DEFAULT 0,

            list_price DECIMAL(12,2) DEFAULT 0,
            original_list_price DECIMAL(12,2) DEFAULT 0,
            price_per_sqft DECIMAL(10,2) DEFAULT 0,
            building_area_total INT DEFAULT 0,
            bedrooms_total INT DEFAULT 0,
            bathrooms_total DECIMAL(3,1) DEFAULT 0,
            year_built INT DEFAULT 0,
            lot_size_acres DECIMAL(10,4) DEFAULT 0,
            city VARCHAR(100) DEFAULT '',
            address VARCHAR(255) DEFAULT '',
            main_photo_url VARCHAR(500) DEFAULT '',

            photo_analysis_json LONGTEXT DEFAULT NULL,
            remarks_signals_json LONGTEXT DEFAULT NULL,

            disqualified TINYINT(1) DEFAULT 0,
            disqualify_reason VARCHAR(255) DEFAULT NULL,
            near_viable TINYINT(1) DEFAULT 0,
            applied_thresholds_json TEXT DEFAULT NULL,

            annualized_roi DECIMAL(10,2) DEFAULT 0,
            breakeven_arv DECIMAL(12,2) DEFAULT 0,
            deal_risk_grade VARCHAR(2) DEFAULT NULL,
            lead_paint_flag TINYINT(1) DEFAULT 0,
            transfer_tax_buy DECIMAL(10,2) DEFAULT 0,
            transfer_tax_sell DECIMAL(10,2) DEFAULT 0,

            PRIMARY KEY (id),
            INDEX idx_total_score (total_score DESC),
            INDEX idx_listing_id (listing_id),
            INDEX idx_run_date (run_date),
            INDEX idx_city (city),
            INDEX idx_disqualified (disqualified)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add columns for v0.7.0 (market-adaptive thresholds).
     */
    public static function migrate_v070(): void {
        global $wpdb;
        $table = self::table_name();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('near_viable', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN near_viable TINYINT(1) DEFAULT 0 AFTER disqualify_reason");
        }
        if (!in_array('applied_thresholds_json', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN applied_thresholds_json TEXT DEFAULT NULL AFTER near_viable");
        }
    }

    /**
     * Add columns for v0.8.0 (ARV & financial model overhaul).
     */
    public static function migrate_v080(): void {
        global $wpdb;
        $table = self::table_name();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        $new_columns = [
            'annualized_roi'    => "ALTER TABLE {$table} ADD COLUMN annualized_roi DECIMAL(10,2) DEFAULT 0 AFTER applied_thresholds_json",
            'breakeven_arv'     => "ALTER TABLE {$table} ADD COLUMN breakeven_arv DECIMAL(12,2) DEFAULT 0 AFTER annualized_roi",
            'deal_risk_grade'   => "ALTER TABLE {$table} ADD COLUMN deal_risk_grade VARCHAR(2) DEFAULT NULL AFTER breakeven_arv",
            'lead_paint_flag'   => "ALTER TABLE {$table} ADD COLUMN lead_paint_flag TINYINT(1) DEFAULT 0 AFTER deal_risk_grade",
            'transfer_tax_buy'  => "ALTER TABLE {$table} ADD COLUMN transfer_tax_buy DECIMAL(10,2) DEFAULT 0 AFTER lead_paint_flag",
            'transfer_tax_sell' => "ALTER TABLE {$table} ADD COLUMN transfer_tax_sell DECIMAL(10,2) DEFAULT 0 AFTER transfer_tax_buy",
        ];

        foreach ($new_columns as $col_name => $sql) {
            if (!in_array($col_name, $cols, true)) {
                $wpdb->query($sql);
            }
        }
    }

    /**
     * Set default target cities on activation.
     */
    public static function set_default_cities(): void {
        if (!get_option('bmn_flip_target_cities')) {
            update_option('bmn_flip_target_cities', json_encode([
                'Reading', 'Melrose', 'Stoneham', 'Burlington',
                'Andover', 'North Andover', 'Wakefield',
            ]));
        }
    }

    /**
     * Get the configured target cities.
     */
    public static function get_target_cities(): array {
        $cities = get_option('bmn_flip_target_cities', '[]');
        return json_decode($cities, true) ?: [];
    }

    /**
     * Update target cities.
     */
    public static function set_target_cities(array $cities): void {
        update_option('bmn_flip_target_cities', json_encode(array_values($cities)));
    }

    /**
     * Get saved analysis filters (merged with defaults).
     */
    public static function get_analysis_filters(): array {
        $defaults = [
            'property_sub_types' => ['Single Family Residence'],
            'statuses'           => ['Active'],
            'sewer_public_only'  => false,
            'min_dom'            => null,
            'max_dom'            => null,
            'list_date_from'     => null,
            'list_date_to'       => null,
            'year_built_min'     => null,
            'year_built_max'     => null,
            'min_price'          => null,
            'max_price'          => null,
            'min_sqft'           => null,
            'max_sqft'           => null,
            'min_lot_acres'      => null,
            'min_beds'           => null,
            'min_baths'          => null,
            'has_garage'         => false,
        ];

        $saved = get_option('bmn_flip_analysis_filters', '{}');
        $saved = json_decode($saved, true) ?: [];

        return array_merge($defaults, $saved);
    }

    /**
     * Save analysis filters.
     */
    public static function set_analysis_filters(array $filters): void {
        // Sanitize arrays
        if (isset($filters['property_sub_types']) && is_array($filters['property_sub_types'])) {
            $filters['property_sub_types'] = array_values(array_map('sanitize_text_field', $filters['property_sub_types']));
        }
        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $allowed = ['Active', 'Active Under Contract', 'Pending', 'Closed'];
            $filters['statuses'] = array_values(array_intersect(array_map('sanitize_text_field', $filters['statuses']), $allowed));
        }

        // Sanitize scalars
        $filters['sewer_public_only'] = !empty($filters['sewer_public_only']);
        $filters['has_garage']        = !empty($filters['has_garage']);

        foreach (['min_dom', 'max_dom', 'year_built_min', 'year_built_max', 'min_sqft', 'max_sqft', 'min_beds'] as $int_key) {
            $filters[$int_key] = isset($filters[$int_key]) && $filters[$int_key] !== '' ? (int) $filters[$int_key] : null;
        }
        foreach (['min_price', 'max_price', 'min_lot_acres', 'min_baths'] as $float_key) {
            $filters[$float_key] = isset($filters[$float_key]) && $filters[$float_key] !== '' ? (float) $filters[$float_key] : null;
        }
        foreach (['list_date_from', 'list_date_to'] as $date_key) {
            $filters[$date_key] = !empty($filters[$date_key]) ? sanitize_text_field($filters[$date_key]) : null;
        }

        update_option('bmn_flip_analysis_filters', json_encode($filters));
    }

    /**
     * Get distinct residential property sub types from the database.
     */
    public static function get_available_property_sub_types(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';
        return $wpdb->get_col(
            "SELECT DISTINCT property_sub_type FROM {$table}
             WHERE property_type = 'Residential' AND property_sub_type IS NOT NULL AND property_sub_type != ''
             ORDER BY property_sub_type"
        );
    }

    /**
     * Insert or update a flip score result.
     */
    public static function upsert_result(array $data): int {
        global $wpdb;
        $table = self::table_name();

        // Delete any existing result for this listing from the same run date
        $wpdb->delete($table, [
            'listing_id' => $data['listing_id'],
            'run_date'   => $data['run_date'],
        ]);

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Get results from the latest run, optionally filtered.
     */
    public static function get_results(array $args = []): array {
        global $wpdb;
        $table = self::table_name();

        $defaults = [
            'top'        => 50,
            'min_score'  => 0,
            'city'       => null,
            'sort'       => 'total_score',
            'order'      => 'DESC',
            'has_photos' => false,
            'run_date'   => null,
        ];
        $args = wp_parse_args($args, $defaults);

        // Get the latest run_date if not specified
        if (empty($args['run_date'])) {
            $args['run_date'] = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
            if (!$args['run_date']) {
                return [];
            }
        }

        $where = ["run_date = %s", "disqualified = 0"];
        $params = [$args['run_date']];

        if ($args['min_score'] > 0) {
            $where[] = "total_score >= %f";
            $params[] = $args['min_score'];
        }

        if (!empty($args['city'])) {
            $cities = array_map('trim', explode(',', $args['city']));
            $placeholders = implode(',', array_fill(0, count($cities), '%s'));
            $where[] = "city IN ({$placeholders})";
            $params = array_merge($params, $cities);
        }

        if ($args['has_photos']) {
            $where[] = "photo_score IS NOT NULL";
        }

        $allowed_sorts = ['total_score', 'estimated_profit', 'estimated_roi', 'cash_on_cash_roi', 'annualized_roi', 'list_price', 'estimated_arv', 'deal_risk_grade'];
        $sort = in_array($args['sort'], $allowed_sorts) ? $args['sort'] : 'total_score';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $where_sql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) $args['top']));

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$sort} {$order} LIMIT %d",
            array_merge($params, [$limit])
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Get a single result by listing_id (latest run).
     */
    public static function get_result_by_listing(int $listing_id): ?object {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id = %d ORDER BY run_date DESC LIMIT 1",
            $listing_id
        ));
    }

    /**
     * Get summary stats from the latest run.
     */
    public static function get_summary(): array {
        global $wpdb;
        $table = self::table_name();

        $latest_run = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
        if (!$latest_run) {
            return ['total' => 0, 'last_run' => null, 'cities' => []];
        }

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN disqualified = 0 AND total_score >= 60 THEN 1 ELSE 0 END) as viable,
                AVG(CASE WHEN disqualified = 0 THEN total_score END) as avg_score,
                AVG(CASE WHEN disqualified = 0 AND estimated_roi > 0 THEN estimated_roi END) as avg_roi,
                SUM(CASE WHEN disqualified = 1 THEN 1 ELSE 0 END) as disqualified,
                SUM(CASE WHEN disqualified = 1 AND near_viable = 1 THEN 1 ELSE 0 END) as near_viable
            FROM {$table} WHERE run_date = %s",
            $latest_run
        ));

        $city_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city,
                COUNT(*) as total,
                SUM(CASE WHEN disqualified = 0 AND total_score >= 60 THEN 1 ELSE 0 END) as viable,
                AVG(CASE WHEN disqualified = 0 THEN total_score END) as avg_score
            FROM {$table}
            WHERE run_date = %s
            GROUP BY city
            ORDER BY viable DESC",
            $latest_run
        ));

        return [
            'total'        => (int) $totals->total,
            'viable'       => (int) $totals->viable,
            'avg_score'    => round((float) $totals->avg_score, 1),
            'avg_roi'      => round((float) $totals->avg_roi, 1),
            'disqualified' => (int) $totals->disqualified,
            'near_viable'  => (int) ($totals->near_viable ?? 0),
            'last_run'     => $latest_run,
            'cities'       => $city_breakdown,
        ];
    }

    /**
     * Delete results older than N days.
     */
    public static function clear_old_results(int $days = 30): int {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE run_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Delete all results.
     */
    public static function clear_all(): int {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
