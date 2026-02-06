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
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook !== self::$page_hook) {
            return;
        }

        // Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Dashboard JS
        wp_enqueue_script(
            'flip-dashboard',
            FLIP_PLUGIN_URL . 'assets/js/flip-dashboard.js',
            ['jquery', 'chartjs'],
            FLIP_VERSION,
            true
        );

        // Dashboard CSS
        wp_enqueue_style(
            'flip-dashboard',
            FLIP_PLUGIN_URL . 'assets/css/flip-dashboard.css',
            [],
            FLIP_VERSION
        );

        // Pass initial data to JS
        wp_localize_script('flip-dashboard', 'flipData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('flip_dashboard'),
            'siteUrl' => home_url(),
            'data'    => self::get_dashboard_data(),
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
     */
    public static function get_dashboard_data(): array {
        global $wpdb;
        $table = Flip_Database::table_name();

        $summary = Flip_Database::get_summary();

        // Get all results from latest run (viable + disqualified)
        $latest_run = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
        $all_results = [];

        if ($latest_run) {
            $all_results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE run_date = %s
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
    private static function format_result(object $row): array {
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
            'rehab_multiplier'    => (float) ($row->rehab_multiplier ?? 1.0),
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
            'comps'               => !empty($row->comp_details_json) ? json_decode($row->comp_details_json, true) : [],
            'photo_analysis'      => !empty($row->photo_analysis_json) ? json_decode($row->photo_analysis_json, true) : null,
            'remarks_signals'     => !empty($row->remarks_signals_json) ? json_decode($row->remarks_signals_json, true) : [],
            'run_date'            => $row->run_date,
        ];
    }

    /**
     * AJAX: Run the analysis pipeline (Pass 1).
     */
    public static function ajax_run_analysis(): void {
        check_ajax_referer('flip_dashboard', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        set_time_limit(300);

        $messages = [];
        $result = Flip_Analyzer::run([], function ($msg) use (&$messages) {
            $messages[] = $msg;
        });

        $result['messages']  = $messages;
        $result['dashboard'] = self::get_dashboard_data();

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

        $messages = [];
        $result = Flip_Photo_Analyzer::analyze_top_candidates(50, 40, function ($msg) use (&$messages) {
            $messages[] = $msg;
        });

        $result['messages']  = $messages;
        $result['dashboard'] = self::get_dashboard_data();

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

        wp_send_json_success(self::get_dashboard_data());
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
