<?php
/**
 * Financial Viability Scorer (40% of total score).
 *
 * Evaluates: price vs ARV ratio, $/sqft vs neighborhood, price reductions, DOM.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Financial_Scorer {

    /**
     * Score financial viability of a flip candidate.
     *
     * @param object $property Row from bme_listing_summary.
     * @param array  $arv_data From Flip_ARV_Calculator::calculate().
     * @param float  $neighborhood_avg_ppsf City average $/sqft.
     * @return array { score: float (0-100), factors: array }
     */
    public static function score(object $property, array $arv_data, float $neighborhood_avg_ppsf): array {
        $factors = [];

        // Factor 1: Price vs ARV ratio (15% weight → 37.5% of financial score)
        $price_arv_score = self::score_price_arv_ratio(
            (float) $property->list_price,
            (float) $arv_data['estimated_arv']
        );
        $factors['price_vs_arv'] = $price_arv_score;

        // Factor 2: $/sqft vs neighborhood average (10% weight → 25%)
        $ppsf_score = self::score_ppsf_vs_neighborhood(
            (float) $property->price_per_sqft,
            $neighborhood_avg_ppsf
        );
        $factors['ppsf_vs_neighborhood'] = $ppsf_score;

        // Factor 3: Price reduction (10% weight → 25%)
        $reduction_score = self::score_price_reduction(
            (float) $property->original_list_price,
            (float) $property->list_price
        );
        $factors['price_reduction'] = $reduction_score;

        // Factor 4: Motivated seller signals via DOM (5% weight → 12.5%)
        $dom_score = self::score_dom_motivation((int) $property->days_on_market);
        $factors['dom_motivation'] = $dom_score;

        // Weighted composite (weights relative within financial category)
        $w = Flip_Database::get_scoring_weights()['financial_sub'];
        $score = ($price_arv_score * $w['price_arv'])
               + ($ppsf_score * $w['ppsf'])
               + ($reduction_score * $w['reduction'])
               + ($dom_score * $w['dom']);

        return [
            'score'   => round($score, 2),
            'factors' => $factors,
        ];
    }

    private static function score_price_arv_ratio(float $list_price, float $arv): float {
        if ($arv <= 0 || $list_price <= 0) return 0;
        $ratio = $list_price / $arv;

        if ($ratio < 0.65) return 100;
        if ($ratio < 0.70) return 80;
        if ($ratio < 0.75) return 60;
        if ($ratio < 0.80) return 40;
        return 20;
    }

    private static function score_ppsf_vs_neighborhood(float $ppsf, float $avg_ppsf): float {
        if ($avg_ppsf <= 0) return 50;  // No neighborhood data — neutral
        if ($ppsf <= 0) return 30;      // Missing property data — penalty, not neutral
        $pct_below = (($avg_ppsf - $ppsf) / $avg_ppsf) * 100;

        if ($pct_below > 25) return 100;
        if ($pct_below > 20) return 80;
        if ($pct_below > 15) return 60;
        if ($pct_below > 10) return 40;
        return 20;
    }

    private static function score_price_reduction(float $original, float $current): float {
        if ($original <= 0 || $current <= 0 || $original <= $current) return 20;
        $pct = (($original - $current) / $original) * 100;

        if ($pct > 15) return 100;
        if ($pct > 10) return 80;
        if ($pct > 5)  return 60;
        if ($pct > 1)  return 40;
        return 20;
    }

    private static function score_dom_motivation(int $dom): float {
        if ($dom > 90)  return 100;
        if ($dom > 60)  return 70;
        if ($dom > 30)  return 40;
        return 20;
    }
}
