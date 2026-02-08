<?php
/**
 * Force full analysis on a specific listing, bypassing all DQ checks.
 * Usage: wp eval-file wp-content/plugins/bmn-flip-analyzer/force-analyze.php 73473965
 */

if (!defined('ABSPATH')) {
    // Running via wp eval-file
}

$listing_id = $args[0] ?? null;
if (!$listing_id) {
    WP_CLI::error('Usage: wp eval-file force-analyze.php <listing_id>');
}

global $wpdb;

// Fetch the property from summary table
$property = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bme_listing_summary WHERE listing_id = %s",
    $listing_id
));

if (!$property) {
    WP_CLI::error("Property {$listing_id} not found in bme_listing_summary.");
}

WP_CLI::log("Force-analyzing MLS# {$listing_id}: {$property->street_number} {$property->street_name}, {$property->city}");

// Ensure all classes are loaded
$plugin_path = WP_PLUGIN_DIR . '/bmn-flip-analyzer/';
$includes = ['class-flip-analyzer.php', 'class-flip-arv-calculator.php', 'class-flip-financial-scorer.php',
    'class-flip-property-scorer.php', 'class-flip-location-scorer.php', 'class-flip-road-analyzer.php',
    'class-flip-market-scorer.php', 'class-flip-database.php'];
foreach ($includes as $file) {
    require_once $plugin_path . 'includes/' . $file;
}

$run_date = current_time('mysql');

// Step 1: Pre-compute city metrics
Flip_Location_Scorer::precompute_city_metrics([$property->city]);
$city_metrics = Flip_Location_Scorer::get_city_metrics($property->city);
WP_CLI::log("  City metrics loaded for {$property->city}");

// Step 2: Calculate ARV
$arv_data = Flip_ARV_Calculator::calculate($property);
WP_CLI::log("  ARV: \$" . number_format($arv_data['estimated_arv']) . " ({$arv_data['arv_confidence']} confidence, {$arv_data['comp_count']} comps)");

// Step 3: Run all scorers (BYPASSING DQ CHECKS)
$financial = Flip_Financial_Scorer::score($property, $arv_data, $city_metrics['avg_ppsf']);
$prop_score = Flip_Property_Scorer::score($property);

$comp_density = Flip_ARV_Calculator::count_nearby_comps(
    (float) $property->latitude,
    (float) $property->longitude
);

$street_name = trim(($property->street_name ?? '') . ' ' . ($property->street_suffix ?? ''));
$road_analysis = Flip_Road_Analyzer::analyze(
    (float) $property->latitude,
    (float) $property->longitude,
    $street_name
);
WP_CLI::log("  Road type: {$road_analysis['road_type']}");

$location = Flip_Location_Scorer::score(
    $property,
    $city_metrics['price_trend'],
    $city_metrics['avg_dom'],
    $comp_density,
    $arv_data,
    ['road_type' => $road_analysis['road_type']]
);

$remarks = Flip_Market_Scorer::get_remarks((int) $listing_id);
$market = Flip_Market_Scorer::score($property, $remarks);

// Compute weighted total
$total_score = ($financial['score'] * Flip_Analyzer::WEIGHT_FINANCIAL)
    + ($prop_score['score'] * Flip_Analyzer::WEIGHT_PROPERTY)
    + ($location['score'] * Flip_Analyzer::WEIGHT_LOCATION)
    + ($market['score'] * Flip_Analyzer::WEIGHT_MARKET);

WP_CLI::log("  Total score: " . number_format($total_score, 1) . " (Fin: {$financial['score']}, Prop: {$prop_score['score']}, Loc: {$location['score']}, Mkt: {$market['score']})");

// Step 4: Financial calculations
$sqft = (int) $property->building_area_total;
$year_built = (int) $property->year_built;
$list_price = (float) $property->list_price;
$raw_arv = (float) $arv_data['estimated_arv'];

$road_discount = Flip_Analyzer::ROAD_ARV_DISCOUNT[$road_analysis['road_type']] ?? 0;
$arv = $road_discount > 0 ? round($raw_arv * (1 - $road_discount), 2) : $raw_arv;

// Look up actual property tax rate
$fin_row = $wpdb->get_row($wpdb->prepare(
    "SELECT tax_annual_amount FROM {$wpdb->prefix}bme_listing_financial WHERE listing_id = %s",
    $listing_id
));
$actual_tax_rate = null;
if ($fin_row && $fin_row->tax_annual_amount > 0 && $list_price > 0) {
    $actual_tax_rate = (float) $fin_row->tax_annual_amount / $list_price;
}

$fin = Flip_Analyzer::calculate_financials(
    $arv, $list_price, $sqft, $year_built,
    $remarks, $city_metrics['avg_dom'] ?? 30,
    $actual_tax_rate
);

WP_CLI::log("  Profit: \$" . number_format($fin['estimated_profit']) . " | ROI: " . number_format($fin['cash_on_cash_roi'], 1) . "%");

// Step 5: Thresholds and risk grade
$thresholds = Flip_Analyzer::get_adaptive_thresholds(
    $arv_data['market_strength'] ?? 'balanced',
    $arv_data['avg_sale_to_list'] ?? 1.0,
    $arv_data['arv_confidence'] ?? 'medium'
);

$deal_risk_grade = Flip_Analyzer::calculate_deal_risk_grade(
    $arv_data['arv_confidence'] ?? 'none',
    $fin['breakeven_arv'],
    $arv,
    $arv_data['comps'],
    (int) ($property->days_on_market ?? 0),
    $arv_data['comp_count']
);

WP_CLI::log("  Risk grade: {$deal_risk_grade}");

// Step 6: Build comp details
$comp_details = array_map(function ($comp) {
    return [
        'listing_id'       => $comp->listing_id,
        'address'          => $comp->address ?? '',
        'city'             => $comp->city ?? '',
        'close_price'      => (float) $comp->close_price,
        'adjusted_price'   => (float) ($comp->adjusted_price ?? $comp->close_price),
        'sqft'             => (int) $comp->sqft,
        'ppsf'             => round((float) $comp->ppsf, 2),
        'adjusted_ppsf'    => round((float) ($comp->adjusted_ppsf ?? $comp->ppsf), 2),
        'close_date'       => $comp->close_date,
        'distance_miles'   => round((float) $comp->distance_miles, 2),
        'bedrooms'         => (int) $comp->bedrooms_total,
        'bathrooms'        => (float) $comp->bathrooms_total,
        'adjustments'      => $comp->adjustments ?? [],
        'total_adjustment' => round((float) ($comp->total_adjustment ?? 0), 2),
    ];
}, $arv_data['comps']);

// Step 7: Build data array and store — FORCE disqualified = 0
$data = [
    'listing_id'            => (int) $listing_id,
    'run_date'              => $run_date,
    'total_score'           => round($total_score, 2),
    'financial_score'       => (float) $financial['score'],
    'property_score'        => (float) $prop_score['score'],
    'location_score'        => (float) $location['score'],
    'market_score'          => (float) $market['score'],
    'photo_score'           => null,
    'list_price'            => $list_price,
    'address'               => trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '') . ' ' . ($property->street_suffix ?? '')),
    'city'                  => $property->city,
    'bedrooms_total'        => (int) $property->bedrooms_total,
    'bathrooms_total'       => (float) $property->bathrooms_total,
    'building_area_total'   => $sqft,
    'year_built'            => $year_built,
    'lot_size_acres'        => (float) ($property->lot_size_acres ?? 0),
    'days_on_market'        => (int) ($property->days_on_market ?? 0),
    'estimated_arv'         => $arv,
    'arv_confidence'        => $arv_data['arv_confidence'],
    'comp_count'            => $arv_data['comp_count'],
    'estimated_rehab_cost'  => $fin['rehab_cost'],
    'mao'                   => $fin['mao'],
    'estimated_profit'      => $fin['estimated_profit'],
    'estimated_roi'         => $fin['estimated_roi'],
    'cash_profit'           => $fin['cash_profit'],
    'cash_roi'              => $fin['cash_roi'],
    'cash_on_cash_roi'      => $fin['cash_on_cash_roi'],
    'annualized_roi'        => $fin['annualized_roi'],
    'breakeven_arv'         => $fin['breakeven_arv'],
    'financing_costs'       => $fin['financing_costs'],
    'holding_costs'         => $fin['holding_costs'],
    'rehab_contingency'     => $fin['rehab_contingency'],
    'rehab_multiplier'      => $fin['rehab_multiplier'],
    'hold_months'           => $fin['hold_months'],
    'transfer_tax_buy'      => $fin['transfer_tax_buy'],
    'transfer_tax_sell'     => $fin['transfer_tax_sell'],
    'lead_paint_flag'       => ($year_built > 0 && $year_built < 1978) ? 1 : 0,
    'neighborhood_ceiling'  => $arv_data['neighborhood_ceiling'] ?? 0,
    'ceiling_pct'           => ($arv_data['neighborhood_ceiling'] ?? 0) > 0 ? round(($arv / $arv_data['neighborhood_ceiling']) * 100, 1) : 0,
    'ceiling_warning'       => ($arv_data['neighborhood_ceiling'] ?? 0) > 0 && $arv > ($arv_data['neighborhood_ceiling'] * 1.2) ? 1 : 0,
    'road_type'             => $road_analysis['road_type'],
    'market_strength'       => $arv_data['market_strength'] ?? 'balanced',
    'avg_sale_to_list'      => $arv_data['avg_sale_to_list'] ?? 0,
    'comp_details_json'     => json_encode($comp_details, JSON_INVALID_UTF8_SUBSTITUTE),
    'remarks_signals_json'  => json_encode($market['remarks_signals'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE),
    'photo_analysis_json'   => null,
    'disqualified'          => 0,  // FORCE NOT DISQUALIFIED
    'disqualify_reason'     => '',
    'near_viable'           => 0,
    'applied_thresholds_json' => json_encode($thresholds, JSON_INVALID_UTF8_SUBSTITUTE),
    'deal_risk_grade'       => $deal_risk_grade,
];

Flip_Database::upsert_result($data);
WP_CLI::success("Full analysis stored for MLS# {$listing_id} (forced non-DQ). Score: " . number_format($total_score, 1));
