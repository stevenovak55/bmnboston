<?php
/**
 * Disqualifier — pre/post-calculation property disqualification checks.
 *
 * Extracted from Flip_Analyzer in v0.14.0 to improve maintainability.
 * Handles new construction DQ, financial threshold DQ, distress signal
 * detection, property condition checks, and DQ result storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Disqualifier {

    /** Minimum list price to exclude lease/land anomalies */
    const MIN_LIST_PRICE = 100000;

    /**
     * Check pre-financial auto-disqualifiers.
     *
     * @param object      $property           Row from bme_listing_summary.
     * @param array       $arv_data           ARV calculation result.
     * @param string|null $remarks            Public remarks (for distress keyword check).
     * @param string|null $property_condition  Property condition from bme_listing_details.
     * @return string|null Reason for disqualification, or null if passed.
     */
    public static function check_disqualifiers(
        object $property,
        array $arv_data,
        ?string $remarks = null,
        ?string $property_condition = null
    ): ?string {
        $list_price = (float) $property->list_price;
        $arv = (float) $arv_data['estimated_arv'];
        $sqft = (int) $property->building_area_total;

        if ($list_price < self::MIN_LIST_PRICE) {
            return 'List price below minimum ($' . number_format(self::MIN_LIST_PRICE) . ') - likely lease or land';
        }

        if ($arv_data['comp_count'] === 0) {
            return 'No comparable sales found within 1 mile';
        }

        if ($sqft < 600) {
            return 'Building area too small (' . $sqft . ' sqft)';
        }

        // New construction / near-new disqualifier
        $year_built = (int) $property->year_built;
        if ($year_built > 0) {
            $current_year = (int) wp_date('Y');
            $age = $current_year - $year_built;
            $has_distress = self::has_distress_signals($remarks);
            $condition_is_poor = self::condition_indicates_distress($property_condition);

            // Age ≤ 5: auto-DQ unless distress signals or poor condition
            if ($age <= 5 && !$has_distress && !$condition_is_poor) {
                return "Recent construction ({$year_built}) - minimal renovation potential";
            }

            // Property condition "New Construction" or "Excellent" on < 15 year old property
            if (self::condition_indicates_pristine($property_condition, $age) && !$has_distress) {
                return "Property condition '{$property_condition}' on {$year_built} build - no renovation potential";
            }
        }

        $market_str = $arv_data['market_strength'] ?? 'balanced';
        $max_ratio = Flip_Analyzer::MARKET_MAX_PRICE_ARV_RATIO[$market_str] ?? 0.85;
        if ($arv > 0 && $list_price > $arv * $max_ratio) {
            $ratio = round($list_price / $arv, 2);
            $pct = round($max_ratio * 100);
            return "List price too close to ARV (ratio: {$ratio}, max: {$pct}% [{$market_str}])";
        }

        $base_rehab_ppsf  = Flip_Analyzer::get_rehab_per_sqft($year_built);
        $age_mult         = Flip_Analyzer::get_age_condition_multiplier($year_built);
        $remarks_mult     = Flip_Analyzer::get_remarks_rehab_multiplier($remarks);
        $estimated_rehab  = $sqft * max(2.0, $base_rehab_ppsf * $age_mult * $remarks_mult);
        if ($arv > 0 && $estimated_rehab > $arv * 0.35) {
            return 'Rehab estimate exceeds 35% of ARV';
        }

        $ceiling_pct = $arv_data['ceiling_pct'] ?? 0;
        if ($ceiling_pct > 120) {
            return 'ARV exceeds 120% of neighborhood ceiling ($'
                . number_format($arv_data['neighborhood_ceiling'] ?? 0)
                . ', ceiling_pct: ' . round($ceiling_pct) . '%)';
        }

        return null;
    }

    /**
     * Post-calculation disqualifier using financed numbers and adaptive thresholds.
     *
     * @return string|null Reason for disqualification, or null if passed.
     */
    public static function check_post_calc_disqualifiers(float $profit, float $roi, array $thresholds, string $arv_confidence = 'medium'): ?string {
        // Confidence safety margin — require more profit/ROI when ARV is uncertain
        $confidence_factor = match ($arv_confidence) {
            'high'   => 1.0,
            'medium' => 1.0,
            'low'    => 1.25,  // 25% stricter
            default  => 1.5,   // 50% stricter for 'none'
        };

        $min_profit = $thresholds['min_profit'] * $confidence_factor;
        $min_roi    = $thresholds['min_roi'] * $confidence_factor;
        $market     = $thresholds['market_strength'];
        $suffix     = ($market !== 'balanced')
            ? ' [' . $market . ' market]'
            : '';
        if ($confidence_factor > 1.0) {
            $suffix .= ' [low ARV confidence]';
        }

        if ($profit < $min_profit) {
            return 'Estimated profit ($' . number_format($profit) . ') below minimum ($'
                . number_format($min_profit) . ')' . $suffix;
        }

        if ($roi < $min_roi) {
            return 'Estimated ROI (' . round($roi, 1) . '%) below minimum ('
                . round($min_roi, 1) . '%)' . $suffix;
        }

        return null;
    }

    /**
     * Store a disqualified property result (pre-calculation DQ).
     */
    public static function store_disqualified(object $property, array $arv_data, string $reason, string $run_date, ?int $report_id = null): void {
        $thresholds = Flip_Analyzer::get_adaptive_thresholds(
            $arv_data['market_strength'] ?? 'balanced',
            $arv_data['avg_sale_to_list'] ?? 1.0,
            $arv_data['arv_confidence'] ?? 'medium'
        );

        $data = [
            'listing_id'          => (int) $property->listing_id,
            'listing_key'         => $property->listing_key ?? '',
            'run_date'            => $run_date,
            'total_score'         => 0,
            'financial_score'     => 0,
            'property_score'      => 0,
            'location_score'      => 0,
            'market_score'        => 0,
            'estimated_arv'       => round((float) $arv_data['estimated_arv'], 2),
            'arv_confidence'      => $arv_data['arv_confidence'],
            'comp_count'          => $arv_data['comp_count'],
            'avg_comp_ppsf'       => $arv_data['avg_comp_ppsf'],
            'neighborhood_ceiling' => round((float) ($arv_data['neighborhood_ceiling'] ?? 0), 2),
            'ceiling_pct'          => $arv_data['ceiling_pct'] ?? 0,
            'ceiling_warning'      => ($arv_data['ceiling_warning'] ?? false) ? 1 : 0,
            'estimated_rehab_cost' => 0,
            'rehab_level'         => 'unknown',
            'mao'                 => 0,
            'estimated_profit'    => 0,
            'estimated_roi'       => 0,
            'financing_costs'     => 0,
            'holding_costs'       => 0,
            'rehab_contingency'   => 0,
            'hold_months'         => 0,
            'cash_profit'         => 0,
            'cash_roi'            => 0,
            'cash_on_cash_roi'    => 0,
            'market_strength'     => $arv_data['market_strength'] ?? 'balanced',
            'avg_sale_to_list'    => $arv_data['avg_sale_to_list'] ?? 1.0,
            'rehab_multiplier'         => 1.0,
            'age_condition_multiplier' => 1.0,
            'days_on_market'           => (int) ($property->days_on_market ?? 0),
            'list_price'               => (float) $property->list_price,
            'original_list_price' => (float) ($property->original_list_price ?? 0),
            'price_per_sqft'      => (float) ($property->price_per_sqft ?? 0),
            'building_area_total' => (int) $property->building_area_total,
            'bedrooms_total'      => (int) $property->bedrooms_total,
            'bathrooms_total'     => (float) $property->bathrooms_total,
            'year_built'          => (int) $property->year_built,
            'lot_size_acres'      => (float) ($property->lot_size_acres ?? 0),
            'city'                => $property->city ?? '',
            'address'             => trim(($property->street_number ?? '') . ' ' . ($property->street_name ?? '')),
            'main_photo_url'      => $property->main_photo_url ?? '',
            'disqualified'        => 1,
            'disqualify_reason'   => $reason,
            'near_viable'         => 0,
            'applied_thresholds_json' => json_encode($thresholds),
            'annualized_roi'      => 0,
            'breakeven_arv'       => 0,
            'deal_risk_grade'     => null,
            'lead_paint_flag'     => ((int) $property->year_built > 0 && (int) $property->year_built < Flip_Analyzer::LEAD_PAINT_YEAR) ? 1 : 0,
            'transfer_tax_buy'    => 0,
            'transfer_tax_sell'   => 0,
        ];

        if ($report_id) {
            $data['report_id'] = $report_id;
        }

        Flip_Database::upsert_result($data);
    }

    /**
     * Look up property condition from MLS listing details.
     *
     * @param int $listing_id MLS listing ID.
     * @return string|null Condition value (e.g. "Excellent", "New Construction", "Poor") or null.
     */
    public static function get_property_condition(int $listing_id): ?string {
        global $wpdb;
        $details_table = $wpdb->prefix . 'bme_listing_details';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT property_condition FROM {$details_table} WHERE listing_id = %d LIMIT 1",
            $listing_id
        ));
    }

    /**
     * Check if listing remarks contain distress signals that indicate
     * a property may need renovation despite being recently built.
     */
    private static function has_distress_signals(?string $remarks): bool {
        if (empty($remarks)) return false;
        $lower = strtolower($remarks);

        // Strong signals — unambiguous distress, never used in marketing
        $strong_keywords = [
            'foreclosure', 'bank owned', 'reo', 'short sale',
            'fire damage', 'water damage', 'condemned', 'court ordered',
            'estate sale', 'probate', 'uninhabitable',
        ];
        foreach ($strong_keywords as $keyword) {
            if (str_contains($lower, $keyword)) return true;
        }

        // Weak signals — commonly used in marketing copy ("settle for a fixer upper",
        // "no need for a handyman"). Require they NOT appear in a negation context.
        // Uses word-boundary matching and expanded negation detection.
        $weak_keywords = [
            'fixer', 'handyman', 'needs work', 'as-is', 'as is',
            'tear down', 'teardown',
        ];
        $negation_phrases = [
            'no need', 'don\'t need', 'doesn\'t need', 'not a fixer', 'no fixer',
            'settle for', 'instead of', 'rather than', 'unlike',
            'forget the', 'won\'t need', 'without the',
            'why settle', 'say goodbye', 'no more',
            'never have to', 'don\'t have to', 'you won\'t',
        ];
        foreach ($weak_keywords as $keyword) {
            // Word-boundary aware search: find all occurrences
            $offset = 0;
            $found_unegated = false;
            while (($pos = strpos($lower, $keyword, $offset)) !== false) {
                // Basic word boundary: char before/after shouldn't be a letter
                if ($pos > 0 && ctype_alpha($lower[$pos - 1])) {
                    $offset = $pos + 1;
                    continue;
                }
                $end = $pos + strlen($keyword);
                if ($end < strlen($lower) && ctype_alpha($lower[$end])) {
                    $offset = $pos + 1;
                    continue;
                }

                // Check negation context: 120 chars before, 60 chars after
                $context_start = max(0, $pos - 120);
                $context_end = min(strlen($lower), $end + 60);
                $context = substr($lower, $context_start, $context_end - $context_start);

                $negated = false;
                foreach ($negation_phrases as $neg) {
                    if (str_contains($context, $neg)) {
                        $negated = true;
                        break;
                    }
                }
                if (!$negated) {
                    $found_unegated = true;
                    break;
                }
                $offset = $pos + 1;
            }
            if ($found_unegated) return true;
        }
        return false;
    }

    /**
     * Check if property condition field indicates distress/renovation need.
     */
    private static function condition_indicates_distress(?string $condition): bool {
        if (empty($condition)) return false;
        $lower = strtolower(trim($condition));
        return str_contains($lower, 'poor')
            || str_contains($lower, 'needs')
            || str_contains($lower, 'fixer');
    }

    /**
     * Check if property condition field indicates pristine/new state
     * on a relatively new property (< 15 years old).
     */
    private static function condition_indicates_pristine(?string $condition, int $age): bool {
        if (empty($condition) || $age > 15) return false;
        $lower = strtolower(trim($condition));
        return in_array($lower, ['new construction', 'excellent', 'new/never occupied'], true);
    }
}
