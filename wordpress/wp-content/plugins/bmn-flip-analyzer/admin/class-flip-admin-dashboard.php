<?php
/**
 * Admin Dashboard for Flip Analyzer.
 *
 * Registers the admin menu page, enqueues assets, handles AJAX
 * for running analysis and refreshing data.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Admin_Dashboard {

    /** @var string Admin page hook suffix */
    private static string $page_hook = '';

    /**
     * Initialize admin hooks.
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_flip_run_analysis', [__CLASS__, 'ajax_run_analysis']);
        add_action('wp_ajax_flip_run_photo_analysis', [__CLASS__, 'ajax_run_photo_analysis']);
        add_action('wp_ajax_flip_refresh_data', [__CLASS__, 'ajax_refresh_data']);
        add_action('wp_ajax_flip_update_cities', [__CLASS__, 'ajax_update_cities']);
        add_action('wp_ajax_flip_generate_pdf', [__CLASS__, 'ajax_generate_pdf']);
        add_action('wp_ajax_flip_force_analyze', [__CLASS__, 'ajax_force_analyze']);
        add_action('wp_ajax_flip_save_filters', [__CLASS__, 'ajax_save_filters']);
    }

    /**
     * Register the admin menu page.
     */
    public static function register_menu(): void {
        self::$page_hook = add_menu_page(
            'Flip Analyzer',
            'Flip Analyzer',
            'manage_options',
            'flip-analyzer',
            [__CLASS__, 'render_dashboard'],
            'dashicons-chart-bar',
            80
        );
    }

    /**
     * Enqueue dashboard CSS/JS only on our admin page.
     *
     * v0.12.0: Split monolithic flip-dashboard.js into 10 focused modules.
     * Load order enforced via wp_enqueue_script dependency arrays.
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook !== self::$page_hook) {
            return;
        }

        $url = FLIP_PLUGIN_URL . 'assets/js/';
        $ver = FLIP_VERSION;

        // Chart.js from CDN
        wp_enqueue_script('chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [], '4.4.1', true);

        // Core namespace (no deps)
        wp_enqueue_script('flip-core',
            $url . 'flip-core.js', [], $ver, true);

        // Helpers (pure functions)
        wp_enqueue_script('flip-helpers',
            $url . 'flip-helpers.js', ['flip-core'], $ver, true);

        // Stats & Chart
        wp_enqueue_script('flip-stats-chart',
            $url . 'flip-stats-chart.js', ['flip-core', 'flip-helpers', 'chartjs'], $ver, true);

        // Filters & Table
        wp_enqueue_script('flip-filters-table',
            $url . 'flip-filters-table.js', ['flip-core', 'flip-helpers', 'jquery'], $ver, true);

        // Detail Row
        wp_enqueue_script('flip-detail-row',
            $url . 'flip-detail-row.js', ['flip-core', 'flip-helpers'], $ver, true);

        // Projections
        wp_enqueue_script('flip-projections',
            $url . 'flip-projections.js', ['flip-core', 'flip-helpers', 'jquery'], $ver, true);

        // AJAX Actions
        wp_enqueue_script('flip-ajax',
            $url . 'flip-ajax.js', ['flip-core', 'flip-helpers', 'flip-filters-table', 'jquery'], $ver, true);

        // Analysis Filters Panel
        wp_enqueue_script('flip-analysis-filters',
            $url . 'flip-analysis-filters.js', ['flip-core', 'flip-helpers', 'jquery'], $ver, true);

        // City Management
        wp_enqueue_script('flip-cities',
            $url . 'flip-cities.js', ['flip-core', 'flip-helpers', 'jquery'], $ver, true);

        // Reports Module
        wp_enqueue_script('flip-reports',
            $url . 'flip-reports.js',
            ['flip-core', 'flip-helpers', 'flip-stats-chart', 'flip-filters-table', 'flip-ajax', 'jquery'],
            $ver, true);

        // Init (runs last, binds everything)
        wp_enqueue_script('flip-init',
            $url . 'flip-init.js',
            ['flip-core', 'flip-helpers', 'flip-stats-chart', 'flip-filters-table',
             'flip-detail-row', 'flip-projections', 'flip-ajax',
             'flip-analysis-filters', 'flip-cities', 'flip-reports', 'jquery'],
            $ver, true);

        // Dashboard CSS
        wp_enqueue_style(
            'flip-dashboard',
            FLIP_PLUGIN_URL . 'assets/css/flip-dashboard.css',
            [],
            $ver
        );

        // Pass initial data to JS (attached to last script)
        wp_localize_script('flip-init', 'flipData', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('flip_dashboard'),
            'siteUrl'          => home_url(),
            'data'             => self::get_dashboard_data(),
            'filters'          => Flip_Database::get_analysis_filters(),
            'propertySubTypes' => Flip_Database::get_available_property_sub_types(),
            'reports'          => Flip_Report_AJAX::get_reports_list(),
            'activeReportId'   => null,
        ]);
    }

    /**
     * Render the dashboard page.
     */
    public static function render_dashboard(): void {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        include FLIP_PLUGIN_PATH . 'admin/views/dashboard.php';
    }

    /**
     * Get all data needed for the dashboard.
     *
     * @param int|null $report_id  When set, load data for a specific saved report.
     *                             When null, prefers the latest manual report to avoid
     *                             monitor runs contaminating the default view.
     */
    public static function get_dashboard_data(?int $report_id = null): array {
        global $wpdb;
        $table = Flip_Database::table_name();

        // When no specific report requested, prefer the latest manual report
        if (!$report_id) {
            $latest_manual = Flip_Database::get_latest_manual_report();
            if ($latest_manual) {
                $report_id = (int) $latest_manual->id;
            }
        }

        if ($report_id) {
            $report = Flip_Database::get_report($report_id);
            if (!$report) {
                // Report was deleted or doesn't exist — fall through to orphan scores
                $report_id = null;
            } else {
                $summary     = Flip_Database::get_summary_by_report($report_id);
                $all_results = Flip_Database::get_results_by_report($report_id);

                return [
                    'summary' => $summary,
                    'results' => array_map([__CLASS__, 'format_result'], $all_results),
                    'cities'  => Flip_Database::get_target_cities(),
                    'report'  => (array) $report,
                ];
            }
        }

        // Fallback: orphan scores only (pre-v0.13.0 runs without report_id)
        $summary = Flip_Database::get_summary();

        $latest_run = $wpdb->get_var(
            "SELECT MAX(run_date) FROM {$table} WHERE report_id IS NULL"
        );
        $all_results = [];

        if ($latest_run) {
            $all_results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE run_date = %s AND report_id IS NULL
                 ORDER BY disqualified ASC, total_score DESC",
                $latest_run
            ));
        }

        return [
            'summary' => $summary,
            'results' => array_map([__CLASS__, 'format_result'], $all_results),
            'cities'  => Flip_Database::get_target_cities(),
        ];
    }

    /**
     * Format a database row for JSON output.
     */
    public static function format_result(object $row): array {
        return [
            'listing_id'          => (int) $row->listing_id,
            'address'             => $row->address,
            'city'                => $row->city,
            'list_price'          => (float) $row->list_price,
            'total_score'         => (float) $row->total_score,
            'financial_score'     => (float) $row->financial_score,
            'property_score'      => (float) $row->property_score,
            'location_score'      => (float) $row->location_score,
            'market_score'        => (float) $row->market_score,
            'photo_score'         => $row->photo_score !== null ? (float) $row->photo_score : null,
            'estimated_arv'       => (float) $row->estimated_arv,
            'arv_confidence'      => $row->arv_confidence,
            'comp_count'          => (int) $row->comp_count,
            'estimated_rehab_cost' => (float) $row->estimated_rehab_cost,
            'rehab_level'         => $row->rehab_level,
            'rehab_contingency'   => (float) ($row->rehab_contingency ?? 0),
            'rehab_multiplier'         => (float) ($row->rehab_multiplier ?? 1.0),
            'age_condition_multiplier' => (float) ($row->age_condition_multiplier ?? 1.0),
            'mao'                 => (float) $row->mao,
            'estimated_profit'    => (float) $row->estimated_profit,
            'estimated_roi'       => (float) $row->estimated_roi,
            'financing_costs'     => (float) ($row->financing_costs ?? 0),
            'holding_costs'       => (float) ($row->holding_costs ?? 0),
            'hold_months'         => (int) ($row->hold_months ?? 6),
            'cash_profit'         => (float) ($row->cash_profit ?? 0),
            'cash_roi'            => (float) ($row->cash_roi ?? 0),
            'cash_on_cash_roi'    => (float) ($row->cash_on_cash_roi ?? 0),
            'market_strength'     => $row->market_strength ?? 'balanced',
            'avg_sale_to_list'    => (float) ($row->avg_sale_to_list ?? 1.0),
            'road_type'           => $row->road_type ?? 'unknown',
            'road_arv_discount'   => Flip_Analyzer::ROAD_ARV_DISCOUNT[$row->road_type ?? ''] ?? 0,
            'days_on_market'      => (int) ($row->days_on_market ?? 0),
            'neighborhood_ceiling' => (float) ($row->neighborhood_ceiling ?? 0),
            'ceiling_pct'         => (float) ($row->ceiling_pct ?? 0),
            'ceiling_warning'     => (bool) ($row->ceiling_warning ?? 0),
            'building_area_total' => (int) $row->building_area_total,
            'bedrooms_total'      => (int) $row->bedrooms_total,
            'bathrooms_total'     => (float) $row->bathrooms_total,
            'year_built'          => (int) $row->year_built,
            'lot_size_acres'      => (float) ($row->lot_size_acres ?? 0),
            'main_photo_url'      => $row->main_photo_url ?? '',
            'disqualified'        => (bool) $row->disqualified,
            'disqualify_reason'   => $row->disqualify_reason,
            'near_viable'         => (bool) ($row->near_viable ?? 0),
            'applied_thresholds'  => !empty($row->applied_thresholds_json)
                ? json_decode($row->applied_thresholds_json, true) : null,
            'annualized_roi'      => (float) ($row->annualized_roi ?? 0),
            'breakeven_arv'       => (float) ($row->breakeven_arv ?? 0),
            'deal_risk_grade'     => $row->deal_risk_grade ?? null,
            'lead_paint_flag'     => (bool) ($row->lead_paint_flag ?? 0),
            'transfer_tax_buy'    => (float) ($row->transfer_tax_buy ?? 0),
            'transfer_tax_sell'   => (float) ($row->transfer_tax_sell ?? 0),
            'comps'               => !empty($row->comp_details_json) ? json_decode($row->comp_details_json, true) : [],
            'photo_analysis'      => !empty($row->photo_analysis_json) ? json_decode($row->photo_analysis_json, true) : null,
            'remarks_signals'     => !empty($row->remarks_signals_json) ? json_decode($row->remarks_signals_json, true) : [],
            'run_date'            => $row->run_date,
        ];
    }

    /**
     * AJAX: Run the analysis pipeline (Pass 1).
     *
     * Now auto-saves every run as a named report.
     */
    public static function ajax_run_analysis(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        set_time_limit(300);

        // Get report name from POST (JS prompts for this before calling)
        $report_name = isset($_POST['report_name'])
            ? sanitize_text_field(wp_unslash($_POST['report_name']))
            : '';

        // Enforce report cap
        if (Flip_Database::count_reports() >= Flip_Database::MAX_REPORTS) {
            wp_send_json_error('Maximum of ' . Flip_Database::MAX_REPORTS . ' reports reached. Delete old reports first.');
        }

        // Create a report record before running analysis
        $filters = Flip_Database::get_analysis_filters();
        $cities  = Flip_Database::get_target_cities();
        $now     = current_time('mysql');

        $report_id = Flip_Database::create_report([
            'name'         => $report_name ?: (implode(', ', $cities) . ' - ' . wp_date('M j, Y')),
            'type'         => 'manual',
            'cities_json'  => wp_json_encode($cities),
            'filters_json' => wp_json_encode($filters),
            'run_date'     => $now,
            'created_by'   => get_current_user_id(),
        ]);

        $messages = [];
        $result = Flip_Analyzer::run(
            ['filters' => $filters, 'report_id' => $report_id ?: null],
            function ($msg) use (&$messages) {
                $messages[] = $msg;
            }
        );

        // Update report metadata after run
        if ($report_id) {
            $viable_count = 0;
            $total_count  = (int) ($result['analyzed'] ?? 0);
            if (!empty($result['analyzed'])) {
                global $wpdb;
                $table = Flip_Database::table_name();
                $viable_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE report_id = %d AND disqualified = 0 AND total_score >= 60",
                    $report_id
                ));
            }

            Flip_Database::update_report($report_id, [
                'last_run_date'  => $now,
                'run_count'      => 1,
                'property_count' => $total_count,
                'viable_count'   => $viable_count,
            ]);

            // Clean up empty reports (0 properties analyzed)
            if ($total_count === 0) {
                Flip_Database::delete_report($report_id);
                $report_id = null;
            }
        }

        $result['messages']    = $messages;
        $result['dashboard']   = self::get_dashboard_data($report_id);
        $result['report_id']   = $report_id;
        $result['reports']     = self::get_reports_list();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Run photo analysis on viable candidates (Pass 2).
     */
    public static function ajax_run_photo_analysis(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        set_time_limit(600); // 10 minutes for photo analysis

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;

        $messages = [];
        $result = Flip_Photo_Analyzer::analyze_top_candidates(50, 40, $report_id ?: null, function ($msg) use (&$messages) {
            $messages[] = $msg;
        });

        $result['messages']  = $messages;
        $result['dashboard'] = self::get_dashboard_data($report_id ?: null);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Refresh dashboard data without running analysis.
     */
    public static function ajax_refresh_data(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        wp_send_json_success(self::get_dashboard_data($report_id ?: null));
    }

    /**
     * AJAX: Generate PDF report for a property.
     */
    public static function ajax_generate_pdf(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $listing_id = isset($_POST['listing_id']) ? (int) $_POST['listing_id'] : 0;
        if ($listing_id <= 0) {
            wp_send_json_error('Invalid listing ID.');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;

        // TCPDF needs extra memory in web context
        @ini_set('memory_limit', '512M');
        set_time_limit(60);

        // Lazy-load PDF generator
        require_once FLIP_PLUGIN_PATH . 'includes/class-flip-pdf-generator.php';

        $generator = new Flip_PDF_Generator();
        $pdf_path = $generator->generate($listing_id, $report_id ?: null);

        if (!$pdf_path) {
            wp_send_json_error('Failed to generate PDF. Check that the property exists and TCPDF is available.');
        }

        // Convert file path to URL
        $upload_dir = wp_upload_dir();
        $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);

        wp_send_json_success([
            'url'     => $pdf_url,
            'message' => 'PDF report generated.',
        ]);
    }

    /**
     * AJAX: Force full analysis on a single DQ'd property.
     */
    public static function ajax_force_analyze(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $listing_id = isset($_POST['listing_id']) ? (int) $_POST['listing_id'] : 0;
        if ($listing_id <= 0) {
            wp_send_json_error('Invalid listing ID.');
        }

        $report_id = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;

        set_time_limit(120);

        // Use latest run_date so the result groups with the current batch
        global $wpdb;
        $table = Flip_Database::table_name();

        if ($report_id) {
            $run_date = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(run_date) FROM {$table} WHERE report_id = %d",
                $report_id
            ));
        } else {
            $run_date = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
        }

        $result = Flip_Analyzer::force_analyze_single($listing_id, $run_date, $report_id ?: null);

        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }

        // Fetch the fresh row and format for JS
        $where_sql = "listing_id = %d AND run_date = %s";
        $params    = [$listing_id, $run_date];

        if ($report_id) {
            $where_sql .= " AND report_id = %d";
            $params[]   = $report_id;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql}",
            ...$params
        ));

        if (!$row) {
            wp_send_json_error('Analysis completed but result not found in database.');
        }

        wp_send_json_success([
            'result'  => self::format_result($row),
            'message' => 'Full analysis complete (DQ bypassed). Score: ' . $result['total_score'],
        ]);
    }

    /**
     * AJAX: Save analysis filters.
     */
    public static function ajax_save_filters(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $raw = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : '{}';
        $filters = json_decode($raw, true);

        if (!is_array($filters)) {
            wp_send_json_error('Invalid filter data.');
        }

        Flip_Database::set_analysis_filters($filters);

        wp_send_json_success([
            'filters' => Flip_Database::get_analysis_filters(),
            'message' => 'Analysis filters saved.',
        ]);
    }

    /**
     * AJAX: Update target cities list.
     */
    public static function ajax_update_cities(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $cities_raw = isset($_POST['cities']) ? sanitize_text_field(wp_unslash($_POST['cities'])) : '';
        $cities = array_values(array_unique(array_filter(array_map('trim', explode(',', $cities_raw)))));

        if (empty($cities)) {
            wp_send_json_error('At least one city is required.');
        }

        Flip_Database::set_target_cities($cities);

        wp_send_json_success([
            'cities'  => $cities,
            'message' => count($cities) . ' target cities saved.',
        ]);
    }

}
