<?php
/**
 * WP-CLI Commands for Flip Analyzer.
 *
 * Commands:
 *   wp flip analyze          - Run data-only scoring on target cities
 *   wp flip analyze-photos   - Run photo analysis on top candidates
 *   wp flip results          - View scored results
 *   wp flip property <id>    - Deep-dive a single property
 *   wp flip summary          - Show per-city summary stats
 *   wp flip pdf <id>          - Generate PDF report for a property
 *   wp flip config           - Manage target cities
 *   wp flip clear            - Delete old results
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Analyze SFR flip candidates across target cities.
 */
class Flip_CLI {

    /**
     * Run data-only scoring analysis (Pass 1).
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Maximum properties to analyze.
     * ---
     * default: 500
     * ---
     *
     * [--city=<city>]
     * : Comma-separated list of cities (overrides configured cities).
     *
     * [--property-type=<types>]
     * : Comma-separated property sub types (e.g. "Single Family Residence,Condominium").
     *
     * [--status=<statuses>]
     * : Comma-separated statuses (Active, Pending, Active Under Contract, Closed).
     *
     * [--sewer-public-only]
     * : Only include properties with public sewer.
     *
     * [--min-dom=<days>]
     * : Minimum days on market.
     *
     * [--max-dom=<days>]
     * : Maximum days on market.
     *
     * [--list-date-from=<date>]
     * : List date start (YYYY-MM-DD).
     *
     * [--list-date-to=<date>]
     * : List date end (YYYY-MM-DD).
     *
     * [--year-min=<year>]
     * : Minimum year built.
     *
     * [--year-max=<year>]
     * : Maximum year built.
     *
     * [--min-price=<price>]
     * : Minimum list price.
     *
     * [--max-price=<price>]
     * : Maximum list price.
     *
     * [--min-sqft=<sqft>]
     * : Minimum building sqft.
     *
     * [--min-beds=<beds>]
     * : Minimum bedrooms.
     *
     * [--min-baths=<baths>]
     * : Minimum bathrooms.
     *
     * ## EXAMPLES
     *
     *     # Analyze all target cities (uses saved filters)
     *     wp flip analyze
     *
     *     # Override filters for this run
     *     wp flip analyze --city=Reading --status=Active,Pending --min-price=200000 --max-price=600000
     *
     * @when after_wp_load
     */
    public function analyze($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 500;
        $city = $assoc_args['city'] ?? null;

        WP_CLI::line('');
        WP_CLI::line('BMN Flip Analyzer - Pass 1: Data Scoring');
        WP_CLI::line(str_repeat('=', 50));

        $options = ['limit' => $limit];
        if ($city) {
            $options['city'] = $city;
        }

        // Build filter overrides from CLI flags (override saved filters)
        $filters = Flip_Database::get_analysis_filters();
        if (!empty($assoc_args['property-type'])) {
            $filters['property_sub_types'] = array_map('trim', explode(',', $assoc_args['property-type']));
        }
        if (!empty($assoc_args['status'])) {
            $filters['statuses'] = array_map('trim', explode(',', $assoc_args['status']));
        }
        if (isset($assoc_args['sewer-public-only'])) {
            $filters['sewer_public_only'] = true;
        }
        foreach ([
            'min-dom'        => 'min_dom',
            'max-dom'        => 'max_dom',
            'list-date-from' => 'list_date_from',
            'list-date-to'   => 'list_date_to',
            'year-min'       => 'year_built_min',
            'year-max'       => 'year_built_max',
            'min-price'      => 'min_price',
            'max-price'      => 'max_price',
            'min-sqft'       => 'min_sqft',
            'min-beds'       => 'min_beds',
            'min-baths'      => 'min_baths',
        ] as $cli_key => $filter_key) {
            if (isset($assoc_args[$cli_key])) {
                $filters[$filter_key] = $assoc_args[$cli_key];
            }
        }
        $options['filters'] = $filters;

        $progress = function ($msg) {
            WP_CLI::line("  {$msg}");
        };

        $result = Flip_Analyzer::run($options, $progress);

        if (isset($result['error'])) {
            WP_CLI::error($result['error']);
            return;
        }

        WP_CLI::line('');
        WP_CLI::success(sprintf(
            'Analysis complete: %d analyzed, %d disqualified. Run date: %s',
            $result['analyzed'],
            $result['disqualified'],
            $result['run_date']
        ));

        WP_CLI::line('');
        WP_CLI::line('View results: wp flip results --top=20');
    }

    /**
     * Run photo analysis on top candidates (Pass 2).
     *
     * ## OPTIONS
     *
     * [--top=<top>]
     * : Number of top candidates to analyze.
     * ---
     * default: 50
     * ---
     *
     * [--min-score=<min_score>]
     * : Minimum total score threshold.
     * ---
     * default: 40
     * ---
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp flip analyze_photos --top=10
     *     wp flip analyze_photos --top=3 --yes
     *
     * @when after_wp_load
     */
    public function analyze_photos($args, $assoc_args) {
        $top = isset($assoc_args['top']) ? (int) $assoc_args['top'] : 50;
        $min_score = isset($assoc_args['min-score']) ? (float) $assoc_args['min-score'] : 40;

        // Check for API key in MLD chatbot settings
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';
        $api_key = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            'claude_api_key'
        ));
        if (empty($api_key)) {
            WP_CLI::error('Claude API key not configured. Configure it in MLD Chatbot settings.');
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('BMN Flip Analyzer - Pass 2: Photo Analysis');
        WP_CLI::line(str_repeat('=', 50));
        WP_CLI::line("Analyzing top {$top} candidates with score >= {$min_score}");
        WP_CLI::line('');

        if (!isset($assoc_args['yes'])) {
            WP_CLI::warning('This will make API calls to Claude Vision (~$0.05-0.10 per property with 10 photos).');
            WP_CLI::confirm('Continue with photo analysis?');
        }
        WP_CLI::line('');

        $progress = function ($msg) {
            WP_CLI::line($msg);
        };

        $result = Flip_Photo_Analyzer::analyze_top_candidates($top, $min_score, $progress);

        WP_CLI::line('');
        WP_CLI::success(sprintf(
            'Photo analysis complete: %d analyzed, %d updated, %d errors.',
            $result['analyzed'],
            $result['updated'],
            $result['errors']
        ));
        WP_CLI::line('');
        WP_CLI::line('View updated results: wp flip results --top=20');
    }

    /**
     * View scored results from the latest run.
     *
     * ## OPTIONS
     *
     * [--top=<top>]
     * : Number of results to show.
     * ---
     * default: 20
     * ---
     *
     * [--min-score=<min_score>]
     * : Minimum total score to include.
     * ---
     * default: 0
     * ---
     *
     * [--city=<city>]
     * : Filter by city (comma-separated).
     *
     * [--sort=<sort>]
     * : Sort field.
     * ---
     * default: total_score
     * options:
     *   - total_score
     *   - estimated_profit
     *   - estimated_roi
     *   - list_price
     *   - estimated_arv
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp flip results --top=10 --min-score=60
     *     wp flip results --city=Reading --format=json
     *
     * @when after_wp_load
     */
    public function results($args, $assoc_args) {
        $query_args = [
            'top'       => isset($assoc_args['top']) ? (int) $assoc_args['top'] : 20,
            'min_score' => isset($assoc_args['min-score']) ? (float) $assoc_args['min-score'] : 0,
            'city'      => $assoc_args['city'] ?? null,
            'sort'      => $assoc_args['sort'] ?? 'total_score',
        ];

        $results = Flip_Database::get_results($query_args);

        if (empty($results)) {
            WP_CLI::warning('No results found. Run "wp flip analyze" first.');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($results, JSON_PRETTY_PRINT));
            return;
        }

        $table_data = [];
        foreach ($results as $r) {
            $table_data[] = [
                'MLS#'    => $r->listing_id,
                'Address' => self::truncate($r->address, 22),
                'City'    => self::truncate($r->city, 12),
                'Score'   => $r->total_score,
                'ARV'     => '$' . number_format($r->estimated_arv / 1000) . 'K',
                'Price'   => '$' . number_format($r->list_price / 1000) . 'K',
                'Rehab'   => '$' . number_format($r->estimated_rehab_cost / 1000) . 'K',
                'Profit'  => '$' . number_format($r->estimated_profit / 1000) . 'K',
                'ROI%'    => $r->estimated_roi . '%',
                'Level'   => ucfirst($r->rehab_level),
            ];
        }

        WP_CLI::line('');
        WP_CLI::line('Top Flip Candidates (Latest Run)');
        WP_CLI::line(str_repeat('-', 80));
        WP_CLI\Utils\format_items($format, $table_data, array_keys($table_data[0]));
        WP_CLI::line('');
        WP_CLI::line(count($results) . ' results shown.');
    }

    /**
     * Deep-dive a single property with full score breakdown.
     *
     * ## OPTIONS
     *
     * <listing_id>
     * : The MLS listing ID to inspect.
     *
     * [--verbose]
     * : Show comp details and remarks signals.
     *
     * ## EXAMPLES
     *
     *     wp flip property 734648
     *     wp flip property 734648 --verbose
     *
     * @when after_wp_load
     */
    public function property($args, $assoc_args) {
        $listing_id = (int) $args[0];
        if ($listing_id <= 0) {
            WP_CLI::error('Please provide a valid listing ID.');
            return;
        }

        $result = Flip_Database::get_result_by_listing($listing_id);
        if (!$result) {
            WP_CLI::error("No analysis found for MLS# {$listing_id}. Run 'wp flip analyze' first.");
            return;
        }

        $verbose = isset($assoc_args['verbose']);

        WP_CLI::line('');
        WP_CLI::line("Flip Analysis: MLS# {$result->listing_id}");
        WP_CLI::line(str_repeat('=', 60));
        WP_CLI::line("Address:     {$result->address}, {$result->city}");
        WP_CLI::line("Run Date:    {$result->run_date}");

        if ($result->disqualified) {
            WP_CLI::line('');
            WP_CLI::warning("DISQUALIFIED: {$result->disqualify_reason}");
            WP_CLI::line('');
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('Score Breakdown');
        WP_CLI::line(str_repeat('-', 40));
        WP_CLI::line(sprintf("  Total Score:     %s / 100", $result->total_score));
        WP_CLI::line(sprintf("  Financial (40%%): %s / 100", $result->financial_score));
        WP_CLI::line(sprintf("  Property  (25%%): %s / 100", $result->property_score));
        WP_CLI::line(sprintf("  Location  (25%%): %s / 100", $result->location_score));
        WP_CLI::line(sprintf("  Market    (10%%): %s / 100", $result->market_score));
        if ($result->photo_score !== null) {
            WP_CLI::line(sprintf("  Photo (bonus):   %s / 100", $result->photo_score));
        }

        WP_CLI::line('');
        WP_CLI::line('Financial Summary');
        WP_CLI::line(str_repeat('-', 40));
        WP_CLI::line(sprintf("  List Price:      $%s", number_format($result->list_price)));
        WP_CLI::line(sprintf("  Estimated ARV:   $%s", number_format($result->estimated_arv)));
        WP_CLI::line(sprintf("  Rehab Estimate:  $%s (%s)", number_format($result->estimated_rehab_cost), $result->rehab_level));
        WP_CLI::line(sprintf("  MAO (70%% Rule):  $%s", number_format($result->mao)));
        WP_CLI::line(sprintf("  Est. Profit:     $%s", number_format($result->estimated_profit)));
        WP_CLI::line(sprintf("  Est. ROI:        %s%%", $result->estimated_roi));
        WP_CLI::line(sprintf("  ARV Confidence:  %s (%d comps)", strtoupper($result->arv_confidence), $result->comp_count));
        WP_CLI::line(sprintf("  Avg Comp $/sqft: $%s", number_format($result->avg_comp_ppsf, 2)));

        WP_CLI::line('');
        WP_CLI::line('Property Details');
        WP_CLI::line(str_repeat('-', 40));
        WP_CLI::line(sprintf("  Beds/Baths:      %d / %s", $result->bedrooms_total, $result->bathrooms_total));
        WP_CLI::line(sprintf("  Sqft:            %s", number_format($result->building_area_total)));
        WP_CLI::line(sprintf("  Year Built:      %d", $result->year_built));
        $age_mult = (float) ($result->age_condition_multiplier ?? 1.0);
        if ($age_mult < 1.0) {
            $age = $result->year_built > 0 ? ((int) wp_date('Y') - (int) $result->year_built) : 0;
            WP_CLI::line(sprintf("  Age Condition:   %sx rehab (age %d, near-new discount)", $age_mult, $age));
        }
        WP_CLI::line(sprintf("  Lot Size:        %s acres", $result->lot_size_acres));
        WP_CLI::line(sprintf("  $/sqft:          $%s", number_format($result->price_per_sqft, 2)));

        // Expansion potential
        $lot_sqft = (float) $result->lot_size_acres * 43560;
        $building_sqft = (int) $result->building_area_total;
        $expansion_ratio = $building_sqft > 0 ? round($lot_sqft / $building_sqft, 1) : 0;
        $expansion_label = Flip_Property_Scorer::get_expansion_category((float) $result->lot_size_acres, $building_sqft);
        WP_CLI::line(sprintf("  Expansion:       %s (ratio: %sx)", ucfirst($expansion_label), $expansion_ratio));

        // Road type (from photo analysis)
        $road_type = $result->road_type ?? 'unknown';
        if ($road_type !== 'unknown') {
            $road_label = Flip_Location_Scorer::get_road_type_label($road_type);
            WP_CLI::line(sprintf("  Road Type:       %s", $road_label));
        }

        // Neighborhood ceiling check
        if (!empty($result->neighborhood_ceiling) && $result->neighborhood_ceiling > 0) {
            WP_CLI::line('');
            WP_CLI::line('Neighborhood Analysis');
            WP_CLI::line(str_repeat('-', 40));
            WP_CLI::line(sprintf("  Ceiling (Max Sale): $%s", number_format($result->neighborhood_ceiling)));
            WP_CLI::line(sprintf("  ARV vs Ceiling:     %s%%", $result->ceiling_pct));
            if ($result->ceiling_warning) {
                WP_CLI::warning("  ⚠ ARV is near/above neighborhood ceiling - verify pricing");
            }
        }

        if ($verbose) {
            // Show comps
            $comps = json_decode($result->comp_details_json ?? '[]', true);
            if (!empty($comps)) {
                WP_CLI::line('');
                WP_CLI::line('Comparable Sales');
                WP_CLI::line(str_repeat('-', 40));
                $comp_table = [];
                foreach ($comps as $comp) {
                    $comp_table[] = [
                        'Address'  => self::truncate($comp['address'] ?? 'N/A', 20),
                        'Price'    => '$' . number_format($comp['close_price']),
                        '$/sqft'   => '$' . number_format($comp['ppsf'], 2),
                        'Sqft'     => number_format($comp['sqft']),
                        'Dist'     => $comp['distance_miles'] . 'mi',
                        'Closed'   => $comp['close_date'],
                    ];
                }
                WP_CLI\Utils\format_items('table', $comp_table, array_keys($comp_table[0]));
            }

            // Show remarks signals
            $signals = json_decode($result->remarks_signals_json ?? '{}', true);
            if (!empty($signals['positive']) || !empty($signals['negative'])) {
                WP_CLI::line('');
                WP_CLI::line('Remarks Signals');
                WP_CLI::line(str_repeat('-', 40));
                if (!empty($signals['positive'])) {
                    WP_CLI::line('  + Positive: ' . implode(', ', $signals['positive']));
                }
                if (!empty($signals['negative'])) {
                    WP_CLI::line('  - Negative: ' . implode(', ', $signals['negative']));
                }
                WP_CLI::line(sprintf('  Adjustment: %+d points', $signals['adjustment'] ?? 0));
            }
        }

        WP_CLI::line('');
    }

    /**
     * Show analysis summary with per-city breakdown.
     *
     * ## EXAMPLES
     *
     *     wp flip summary
     *
     * @when after_wp_load
     */
    public function summary($args, $assoc_args) {
        $summary = Flip_Database::get_summary();

        if ($summary['total'] === 0) {
            WP_CLI::warning('No analysis data. Run "wp flip analyze" first.');
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('BMN Flip Analyzer - Summary');
        WP_CLI::line(str_repeat('=', 50));
        WP_CLI::line(sprintf("  Last Run:       %s", $summary['last_run']));
        WP_CLI::line(sprintf("  Total Analyzed: %d", $summary['total']));
        WP_CLI::line(sprintf("  Viable (60+):   %d", $summary['viable']));
        WP_CLI::line(sprintf("  Disqualified:   %d", $summary['disqualified']));
        WP_CLI::line(sprintf("  Avg Score:      %s", $summary['avg_score']));
        WP_CLI::line(sprintf("  Avg ROI:        %s%%", $summary['avg_roi']));

        if (!empty($summary['cities'])) {
            WP_CLI::line('');
            WP_CLI::line('City Breakdown');
            WP_CLI::line(str_repeat('-', 50));

            $city_data = [];
            foreach ($summary['cities'] as $city) {
                $city_data[] = [
                    'City'    => $city->city,
                    'Total'   => $city->total,
                    'Viable'  => $city->viable,
                    'AvgScore' => round((float) $city->avg_score, 1),
                ];
            }
            WP_CLI\Utils\format_items('table', $city_data, ['City', 'Total', 'Viable', 'AvgScore']);
        }

        WP_CLI::line('');
    }

    /**
     * Manage target cities configuration.
     *
     * ## OPTIONS
     *
     * [--list-cities]
     * : List current target cities.
     *
     * [--add-city=<city>]
     * : Add a city to the target list.
     *
     * [--remove-city=<city>]
     * : Remove a city from the target list.
     *
     * ## EXAMPLES
     *
     *     wp flip config --list-cities
     *     wp flip config --add-city=Woburn
     *     wp flip config --remove-city=Burlington
     *
     * @when after_wp_load
     */
    public function config($args, $assoc_args) {
        $cities = Flip_Database::get_target_cities();

        // List cities
        if (isset($assoc_args['list-cities'])) {
            WP_CLI::line('');
            WP_CLI::line('Target Cities:');
            foreach ($cities as $i => $city) {
                WP_CLI::line(sprintf("  %d. %s", $i + 1, $city));
            }
            WP_CLI::line('');
            return;
        }

        // Add city
        if (isset($assoc_args['add-city'])) {
            $new_city = trim($assoc_args['add-city']);
            if (in_array($new_city, $cities)) {
                WP_CLI::warning("{$new_city} is already in the target list.");
                return;
            }
            $cities[] = $new_city;
            Flip_Database::set_target_cities($cities);
            WP_CLI::success("Added '{$new_city}' to target cities.");
            return;
        }

        // Remove city
        if (isset($assoc_args['remove-city'])) {
            $remove = trim($assoc_args['remove-city']);
            $filtered = array_filter($cities, fn($c) => $c !== $remove);
            if (count($filtered) === count($cities)) {
                WP_CLI::warning("'{$remove}' is not in the target list.");
                return;
            }
            Flip_Database::set_target_cities($filtered);
            WP_CLI::success("Removed '{$remove}' from target cities.");
            return;
        }

        // No flag specified
        WP_CLI::line('Usage: wp flip config --list-cities | --add-city=<name> | --remove-city=<name>');
    }

    /**
     * Generate a PDF report for a property.
     *
     * ## OPTIONS
     *
     * <listing_id>
     * : The MLS listing ID to generate a report for.
     *
     * [--output=<path>]
     * : Save the PDF to a specific path instead of the default uploads directory.
     *
     * ## EXAMPLES
     *
     *     wp flip pdf 73457035
     *     wp flip pdf 73457035 --output=/tmp/report.pdf
     *
     * @when after_wp_load
     */
    public function pdf($args, $assoc_args) {
        $listing_id = (int) $args[0];
        if ($listing_id <= 0) {
            WP_CLI::error('Please provide a valid listing ID.');
            return;
        }

        // Check that the property exists
        $result = Flip_Database::get_result_by_listing($listing_id);
        if (!$result) {
            WP_CLI::error("No analysis found for MLS# {$listing_id}. Run 'wp flip analyze' first.");
            return;
        }

        WP_CLI::line('');
        WP_CLI::line("Generating PDF report for MLS# {$listing_id}...");

        // Lazy-load PDF generator
        require_once FLIP_PLUGIN_PATH . 'includes/class-flip-pdf-generator.php';

        $generator = new Flip_PDF_Generator();
        $pdf_path = $generator->generate($listing_id);

        if (!$pdf_path) {
            WP_CLI::error('Failed to generate PDF. Check that TCPDF is available.');
            return;
        }

        // If custom output path, move the file
        if (!empty($assoc_args['output'])) {
            $dest = $assoc_args['output'];
            if (copy($pdf_path, $dest)) {
                unlink($pdf_path);
                $pdf_path = $dest;
            } else {
                WP_CLI::warning("Could not copy to {$dest}. File saved at: {$pdf_path}");
                return;
            }
        }

        WP_CLI::success("PDF saved: {$pdf_path}");
        WP_CLI::line('');
    }

    /**
     * Clear old analysis results.
     *
     * ## OPTIONS
     *
     * [--older-than=<days>]
     * : Delete results older than this many days.
     * ---
     * default: 30
     * ---
     *
     * [--all]
     * : Delete all results.
     *
     * [--yes]
     * : Skip confirmation.
     *
     * ## EXAMPLES
     *
     *     wp flip clear --older-than=7
     *     wp flip clear --all --yes
     *
     * @when after_wp_load
     */
    public function clear($args, $assoc_args) {
        if (isset($assoc_args['all'])) {
            if (!isset($assoc_args['yes'])) {
                WP_CLI::confirm('Delete ALL flip analysis results?');
            }
            $deleted = Flip_Database::clear_all();
            WP_CLI::success('All results deleted.');
            return;
        }

        $days = isset($assoc_args['older-than']) ? (int) $assoc_args['older-than'] : 30;

        if (!isset($assoc_args['yes'])) {
            WP_CLI::confirm("Delete results older than {$days} days?");
        }

        $deleted = Flip_Database::clear_old_results($days);
        WP_CLI::success("Deleted {$deleted} old results.");
    }

    /**
     * Truncate a string for table display.
     */
    private static function truncate(string $str, int $max): string {
        return mb_strlen($str) > $max ? mb_substr($str, 0, $max - 1) . '~' : $str;
    }
}

// Register WP-CLI commands
WP_CLI::add_command('flip', 'Flip_CLI');
