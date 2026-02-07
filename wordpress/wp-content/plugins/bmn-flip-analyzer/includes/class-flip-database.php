<?php
/**
 * Database layer for flip analysis results.
 *
 * Tables: wp_bmn_flip_scores, wp_bmn_flip_reports, wp_bmn_flip_monitor_seen
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Database {

    const TABLE_NAME         = 'bmn_flip_scores';
    const REPORTS_TABLE      = 'bmn_flip_reports';
    const MONITOR_SEEN_TABLE = 'bmn_flip_monitor_seen';
    const MAX_REPORTS        = 25;

    /**
     * Get the full table name with prefix.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function reports_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::REPORTS_TABLE;
    }

    public static function monitor_seen_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::MONITOR_SEEN_TABLE;
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
     * Add columns for v0.11.0 (renovation potential guard).
     */
    public static function migrate_v0110(): void {
        global $wpdb;
        $table = self::table_name();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('age_condition_multiplier', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN age_condition_multiplier DECIMAL(4,2) DEFAULT 1.00 AFTER rehab_multiplier");
        }
    }

    /**
     * Create the reports table via dbDelta.
     */
    public static function create_reports_table(): void {
        global $wpdb;
        $table           = self::reports_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'manual',
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            cities_json TEXT NOT NULL,
            filters_json TEXT NOT NULL,
            run_date DATETIME DEFAULT NULL,
            last_run_date DATETIME DEFAULT NULL,
            run_count INT UNSIGNED NOT NULL DEFAULT 0,
            property_count INT UNSIGNED NOT NULL DEFAULT 0,
            viable_count INT UNSIGNED NOT NULL DEFAULT 0,
            monitor_frequency VARCHAR(20) DEFAULT NULL,
            monitor_last_check DATETIME DEFAULT NULL,
            monitor_last_new_count INT UNSIGNED NOT NULL DEFAULT 0,
            notification_email VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            INDEX idx_type_status (type, status),
            INDEX idx_created_at (created_at DESC)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create the monitor seen table via dbDelta.
     */
    public static function create_monitor_seen_table(): void {
        global $wpdb;
        $table           = self::monitor_seen_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            listing_id INT NOT NULL,
            first_seen_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_report_listing (report_id, listing_id),
            INDEX idx_report_id (report_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add report_id column to flip_scores for v0.13.0 (saved reports).
     */
    public static function migrate_v0130(): void {
        global $wpdb;
        $table = self::table_name();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('report_id', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN report_id BIGINT UNSIGNED DEFAULT NULL AFTER run_date");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_report_id (report_id)");
        }
    }

    // ---------------------------------------------------------------
    // Report CRUD
    // ---------------------------------------------------------------

    /**
     * Create a new report. Returns the new report ID.
     */
    public static function create_report(array $data): int {
        global $wpdb;

        $wpdb->insert(self::reports_table(), [
            'name'                  => $data['name'],
            'type'                  => $data['type'] ?? 'manual',
            'status'                => 'active',
            'cities_json'           => $data['cities_json'],
            'filters_json'          => $data['filters_json'],
            'run_date'              => $data['run_date'] ?? null,
            'last_run_date'         => $data['last_run_date'] ?? null,
            'run_count'             => $data['run_count'] ?? 0,
            'property_count'        => $data['property_count'] ?? 0,
            'viable_count'          => $data['viable_count'] ?? 0,
            'monitor_frequency'     => $data['monitor_frequency'] ?? null,
            'notification_email'    => $data['notification_email'] ?? null,
            'notification_level'    => $data['notification_level'] ?? 'viable_only',
            'created_at'            => $data['created_at'] ?? current_time('mysql'),
            'updated_at'            => $data['updated_at'] ?? current_time('mysql'),
            'created_by'            => $data['created_by'] ?? get_current_user_id(),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Get a single report by ID.
     */
    public static function get_report(int $id): ?object {
        global $wpdb;
        $table = self::reports_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status != 'deleted'",
            $id
        ));
    }

    /**
     * Get reports list with optional filters.
     */
    public static function get_reports(array $args = []): array {
        global $wpdb;
        $table = self::reports_table();

        $where  = ["status != 'deleted'"];
        $params = [];

        if (!empty($args['type'])) {
            $where[]  = 'type = %s';
            $params[] = $args['type'];
        }
        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 50;
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Update report fields.
     */
    public static function update_report(int $id, array $data): bool {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return (bool) $wpdb->update(self::reports_table(), $data, ['id' => $id]);
    }

    /**
     * Soft-delete a report.
     */
    public static function delete_report(int $id): bool {
        return self::update_report($id, ['status' => 'deleted']);
    }

    /**
     * Count active reports (for enforcing MAX_REPORTS cap).
     */
    public static function count_reports(?string $type = null): int {
        global $wpdb;
        $table = self::reports_table();

        if ($type) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND type = %s",
                $type
            ));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
    }

    // ---------------------------------------------------------------
    // Report-Scoped Queries
    // ---------------------------------------------------------------

    /**
     * Get all results for a specific report.
     */
    public static function get_results_by_report(int $report_id): array {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE report_id = %d
             ORDER BY disqualified ASC, total_score DESC",
            $report_id
        ));
    }

    /**
     * Get summary stats for a specific report.
     */
    public static function get_summary_by_report(int $report_id): array {
        global $wpdb;
        $table = self::table_name();

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN disqualified = 0 AND total_score >= 60 THEN 1 ELSE 0 END) as viable,
                AVG(CASE WHEN disqualified = 0 THEN total_score END) as avg_score,
                AVG(CASE WHEN disqualified = 0 AND estimated_roi > 0 THEN estimated_roi END) as avg_roi,
                SUM(CASE WHEN disqualified = 1 THEN 1 ELSE 0 END) as disqualified,
                SUM(CASE WHEN disqualified = 1 AND near_viable = 1 THEN 1 ELSE 0 END) as near_viable,
                MAX(run_date) as last_run
            FROM {$table} WHERE report_id = %d",
            $report_id
        ));

        if (!$totals || (int) $totals->total === 0) {
            return ['total' => 0, 'last_run' => null, 'cities' => []];
        }

        $city_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city,
                COUNT(*) as total,
                SUM(CASE WHEN disqualified = 0 AND total_score >= 60 THEN 1 ELSE 0 END) as viable,
                AVG(CASE WHEN disqualified = 0 THEN total_score END) as avg_score
            FROM {$table}
            WHERE report_id = %d
            GROUP BY city
            ORDER BY viable DESC",
            $report_id
        ));

        return [
            'total'        => (int) $totals->total,
            'viable'       => (int) $totals->viable,
            'avg_score'    => round((float) $totals->avg_score, 1),
            'avg_roi'      => round((float) $totals->avg_roi, 1),
            'disqualified' => (int) $totals->disqualified,
            'near_viable'  => (int) ($totals->near_viable ?? 0),
            'last_run'     => $totals->last_run,
            'cities'       => $city_breakdown,
        ];
    }

    /**
     * Get a single result by listing_id within a specific report.
     */
    public static function get_result_by_listing_and_report(int $listing_id, int $report_id): ?object {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id = %d AND report_id = %d ORDER BY run_date DESC LIMIT 1",
            $listing_id, $report_id
        ));
    }

    /**
     * Delete scores belonging to soft-deleted reports.
     * Called periodically to prevent unbounded table growth.
     */
    public static function cleanup_deleted_report_scores(): int {
        global $wpdb;
        $scores_table  = self::table_name();
        $reports_table = self::reports_table();

        return (int) $wpdb->query(
            "DELETE s FROM {$scores_table} s
             INNER JOIN {$reports_table} r ON s.report_id = r.id
             WHERE r.status = 'deleted'"
        );
    }

    /**
     * Delete monitor_seen entries for soft-deleted reports.
     */
    public static function cleanup_deleted_monitor_seen(): int {
        global $wpdb;
        $seen_table    = self::monitor_seen_table();
        $reports_table = self::reports_table();

        return (int) $wpdb->query(
            "DELETE ms FROM {$seen_table} ms
             INNER JOIN {$reports_table} r ON ms.report_id = r.id
             WHERE r.status = 'deleted'"
        );
    }

    // ---------------------------------------------------------------
    // Monitor Tracking
    // ---------------------------------------------------------------

    /**
     * Get listing_ids already seen by a monitor.
     */
    public static function get_seen_listing_ids(int $report_id): array {
        global $wpdb;
        $table = self::monitor_seen_table();
        return $wpdb->get_col($wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE report_id = %d",
            $report_id
        ));
    }

    /**
     * Mark listing_ids as seen by a monitor (bulk insert, ignores duplicates).
     */
    public static function mark_listings_seen(int $report_id, array $listing_ids): void {
        global $wpdb;
        $table = self::monitor_seen_table();
        $now   = current_time('mysql');

        foreach (array_chunk($listing_ids, 500) as $chunk) {
            $values = [];
            $placeholders = [];
            foreach ($chunk as $lid) {
                $placeholders[] = '(%d, %d, %s)';
                $values[]       = $report_id;
                $values[]       = (int) $lid;
                $values[]       = $now;
            }
            $sql = "INSERT IGNORE INTO {$table} (report_id, listing_id, first_seen_at) VALUES "
                 . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values));
        }
    }

    /**
     * Get listing_ids from $all_ids that have NOT been seen by a monitor.
     */
    public static function get_unseen_listing_ids(int $report_id, array $all_ids): array {
        if (empty($all_ids)) {
            return [];
        }
        $seen = self::get_seen_listing_ids($report_id);
        $seen_map = array_flip($seen);

        return array_values(array_filter($all_ids, function ($id) use ($seen_map) {
            return !isset($seen_map[$id]);
        }));
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

        // Delete any existing result for this listing from the same run date + report
        if (!empty($data['report_id'])) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE listing_id = %d AND run_date = %s AND report_id = %d",
                $data['listing_id'], $data['run_date'], $data['report_id']
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE listing_id = %d AND run_date = %s AND report_id IS NULL",
                $data['listing_id'], $data['run_date']
            ));
        }

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Get the most recent active manual report.
     */
    public static function get_latest_manual_report(): ?object {
        global $wpdb;
        $table = self::reports_table();
        return $wpdb->get_row(
            "SELECT * FROM {$table}
             WHERE type = 'manual' AND status = 'active'
             ORDER BY COALESCE(last_run_date, run_date, created_at) DESC
             LIMIT 1"
        );
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
     * Get summary stats from the latest orphan run (scores not attached to any report).
     *
     * This is the fallback used when no saved manual reports exist.
     * Report-scoped scores are excluded to prevent monitor runs from
     * contaminating the global "latest" view.
     */
    public static function get_summary(): array {
        global $wpdb;
        $table = self::table_name();

        $latest_run = $wpdb->get_var(
            "SELECT MAX(run_date) FROM {$table} WHERE report_id IS NULL"
        );
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
            FROM {$table} WHERE run_date = %s AND report_id IS NULL",
            $latest_run
        ));

        $city_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city,
                COUNT(*) as total,
                SUM(CASE WHEN disqualified = 0 AND total_score >= 60 THEN 1 ELSE 0 END) as viable,
                AVG(CASE WHEN disqualified = 0 THEN total_score END) as avg_score
            FROM {$table}
            WHERE run_date = %s AND report_id IS NULL
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
     * Delete results older than N days (only orphan scores, not saved reports).
     */
    public static function clear_old_results(int $days = 30): int {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE run_date < DATE_SUB(NOW(), INTERVAL %d DAY) AND report_id IS NULL",
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

    // ---------------------------------------------------------------
    // v0.15.0: Scoring Weights
    // ---------------------------------------------------------------

    /**
     * Default scoring weights — used as fallback when no custom weights saved.
     */
    public static function get_default_scoring_weights(): array {
        return [
            'main' => [
                'financial' => 0.40,
                'property'  => 0.25,
                'location'  => 0.25,
                'market'    => 0.10,
            ],
            'financial_sub' => [
                'price_arv'  => 0.375,
                'ppsf'       => 0.25,
                'reduction'  => 0.25,
                'dom'        => 0.125,
            ],
            'property_sub' => [
                'lot_size'   => 0.35,
                'expansion'  => 0.30,
                'sqft'       => 0.20,
                'renovation' => 0.15,
            ],
            'location_sub' => [
                'road_type'    => 0.25,
                'ceiling'      => 0.25,
                'trend'        => 0.25,
                'comp_density' => 0.15,
                'schools'      => 0.10,
            ],
            'market_sub' => [
                'dom'       => 0.40,
                'reduction' => 0.30,
                'season'    => 0.30,
            ],
            'thresholds' => [
                'min_profit' => 25000,
                'min_roi'    => 15,
            ],
            'market_remarks_cap' => 25,
        ];
    }

    /**
     * Get scoring weights (custom overrides merged with defaults).
     */
    public static function get_scoring_weights(): array {
        $defaults = self::get_default_scoring_weights();
        $saved = get_option('bmn_flip_scoring_weights', '{}');
        $saved = json_decode($saved, true) ?: [];

        return array_replace_recursive($defaults, $saved);
    }

    /**
     * Save custom scoring weights.
     */
    public static function set_scoring_weights(array $weights): void {
        // Sanitize numeric values in each group
        $groups = ['main', 'financial_sub', 'property_sub', 'location_sub', 'market_sub'];
        foreach ($groups as $group) {
            if (isset($weights[$group]) && is_array($weights[$group])) {
                $weights[$group] = array_map('floatval', $weights[$group]);
            }
        }

        if (isset($weights['thresholds'])) {
            $weights['thresholds']['min_profit'] = (int) ($weights['thresholds']['min_profit'] ?? 25000);
            $weights['thresholds']['min_roi']    = (float) ($weights['thresholds']['min_roi'] ?? 15);
        }

        if (isset($weights['market_remarks_cap'])) {
            $weights['market_remarks_cap'] = (int) $weights['market_remarks_cap'];
        }

        update_option('bmn_flip_scoring_weights', wp_json_encode($weights));
    }

    // ---------------------------------------------------------------
    // v0.15.0: Digest Settings
    // ---------------------------------------------------------------

    /**
     * Get email digest settings.
     */
    public static function get_digest_settings(): array {
        $defaults = [
            'enabled'   => false,
            'email'     => get_option('admin_email'),
            'frequency' => 'daily',
            'last_sent' => null,
        ];

        $saved = get_option('bmn_flip_digest_settings', '{}');
        $saved = json_decode($saved, true) ?: [];

        return array_merge($defaults, $saved);
    }

    /**
     * Save email digest settings.
     */
    public static function set_digest_settings(array $settings): void {
        $settings['enabled']   = !empty($settings['enabled']);
        $settings['email']     = sanitize_email($settings['email'] ?? '');
        $allowed_freq          = ['daily', 'weekly'];
        $settings['frequency'] = in_array($settings['frequency'] ?? '', $allowed_freq, true)
            ? $settings['frequency']
            : 'daily';

        update_option('bmn_flip_digest_settings', wp_json_encode($settings));
    }

    // ---------------------------------------------------------------
    // v0.15.0: Monitor Activity Query
    // ---------------------------------------------------------------

    /**
     * Get monitor activity summary since a given datetime.
     * Used by the digest email to show per-monitor stats.
     */
    public static function get_monitor_activity_since(string $since_datetime): array {
        global $wpdb;
        $reports_table = self::reports_table();
        $scores_table  = self::table_name();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.name, r.monitor_frequency, r.monitor_last_new_count,
                    r.property_count, r.viable_count, r.monitor_last_check,
                    (SELECT COUNT(*) FROM {$scores_table} s
                     WHERE s.report_id = r.id AND s.run_date >= %s) as new_analyzed,
                    (SELECT COUNT(*) FROM {$scores_table} s
                     WHERE s.report_id = r.id AND s.run_date >= %s
                     AND s.disqualified = 0 AND s.total_score >= 60) as new_viable,
                    (SELECT COUNT(*) FROM {$scores_table} s
                     WHERE s.report_id = r.id AND s.run_date >= %s
                     AND s.near_viable = 1) as new_near_viable,
                    (SELECT COUNT(*) FROM {$scores_table} s
                     WHERE s.report_id = r.id AND s.run_date >= %s
                     AND s.disqualified = 1 AND s.near_viable = 0) as new_dq
             FROM {$reports_table} r
             WHERE r.type = 'monitor' AND r.status = 'active'
             ORDER BY r.name ASC",
            $since_datetime, $since_datetime, $since_datetime, $since_datetime
        )) ?: [];
    }

    // ---------------------------------------------------------------
    // v0.15.0: Migration
    // ---------------------------------------------------------------

    /**
     * Add notification_level column to reports table.
     */
    public static function migrate_v0150(): void {
        global $wpdb;
        $reports_table = self::reports_table();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$reports_table}", 0);

        if (!in_array('notification_level', $cols, true)) {
            $wpdb->query("ALTER TABLE {$reports_table} ADD COLUMN notification_level VARCHAR(20) DEFAULT 'viable_only' AFTER notification_email");
        }
    }

    // ---------------------------------------------------------------
    // v0.16.0: Multi-Exit Strategy Analysis
    // ---------------------------------------------------------------

    /**
     * Add rental_analysis_json column to scores table.
     */
    public static function migrate_v0160(): void {
        global $wpdb;
        $table = self::table_name();

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('rental_analysis_json', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN rental_analysis_json LONGTEXT DEFAULT NULL AFTER photo_analysis_json");
        }
    }

    /**
     * Default rental calculation parameters.
     */
    public static function get_default_rental_params(): array {
        return [
            'vacancy_rate'        => 0.05,   // 5%
            'management_fee_rate' => 0.08,   // 8% of gross rent
            'maintenance_rate'    => 0.01,   // 1% of property value/year
            'capex_reserve_rate'  => 0.05,   // 5% of gross rent
            'insurance_rate'      => 0.006,  // 0.6% of property value/year
            'appreciation_rate'   => 0.03,   // 3% annual (MA historical)
            'rent_growth_rate'    => 0.02,   // 2% annual
            'marginal_tax_rate'   => 0.32,   // Assumed marginal tax bracket
            'brrrr_refi_ltv'      => 0.75,   // 75% LTV on refi
            'brrrr_refi_rate'     => 0.072,  // 7.2% conventional 30yr
            'brrrr_refi_term'     => 30,     // 30-year term
            'rental_rate_overrides' => [],   // City-level $/sqft/month overrides
        ];
    }

    /**
     * Get rental defaults (custom overrides merged with defaults).
     */
    public static function get_rental_defaults(): array {
        $defaults = self::get_default_rental_params();
        $saved = get_option('bmn_flip_rental_defaults', '{}');
        $saved = json_decode($saved, true) ?: [];

        return array_merge($defaults, $saved);
    }

    /**
     * Save rental defaults.
     */
    public static function set_rental_defaults(array $params): void {
        $float_keys = [
            'vacancy_rate', 'management_fee_rate', 'maintenance_rate',
            'capex_reserve_rate', 'insurance_rate', 'appreciation_rate',
            'rent_growth_rate', 'marginal_tax_rate', 'brrrr_refi_ltv',
            'brrrr_refi_rate',
        ];
        foreach ($float_keys as $key) {
            if (isset($params[$key])) {
                $params[$key] = (float) $params[$key];
            }
        }
        if (isset($params['brrrr_refi_term'])) {
            $params['brrrr_refi_term'] = (int) $params['brrrr_refi_term'];
        }
        if (isset($params['rental_rate_overrides']) && is_array($params['rental_rate_overrides'])) {
            $params['rental_rate_overrides'] = array_map('floatval', $params['rental_rate_overrides']);
        }

        update_option('bmn_flip_rental_defaults', wp_json_encode($params));
    }
}
