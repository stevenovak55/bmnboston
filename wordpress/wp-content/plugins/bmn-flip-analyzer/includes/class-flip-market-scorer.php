<?php
/**
 * Market Timing Scorer (10% of total score).
 *
 * Evaluates: listing DOM, price reductions, listing season.
 * Also scans listing remarks for flip-signal keywords.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Market_Scorer {

    /** Keywords indicating flip opportunity in listing remarks */
    const POSITIVE_KEYWORDS = [
        'as-is', 'as is', 'estate sale', 'estate', 'investor',
        'handyman', 'handyman special', 'tlc', 'potential',
        'needs work', 'needs updating', 'priced to sell',
        'motivated', 'bring your vision', 'diamond in the rough',
        'fixer', 'below market', 'must sell', 'foreclosure',
        'bank owned', 'reo', 'short sale', 'probate',
        'opportunity', 'value add', 'sweat equity',
    ];

    /** Keywords indicating already-renovated (less flip margin) */
    const NEGATIVE_KEYWORDS = [
        'new roof', 'new kitchen', 'renovated', 'remodeled',
        'updated kitchen', 'updated bath', 'move-in ready',
        'move in ready', 'turnkey', 'turn key', 'fully updated',
        'completely renovated', 'gut renovation', 'brand new',
        'new construction', 'custom built',
    ];

    /**
     * Score market timing factors.
     *
     * @param object $property Row from bme_listing_summary.
     * @param string|null $remarks Public remarks from bme_listing_details.
     * @return array { score: float (0-100), factors: array, remarks_signals: array }
     */
    public static function score(object $property, ?string $remarks = null): array {
        $factors = [];

        // Factor 1: Listing DOM (4% → 40%)
        $factors['listing_dom'] = self::score_listing_dom((int) $property->days_on_market);

        // Factor 2: Price reduction magnitude (3% → 30%)
        $factors['price_reduction'] = self::score_reduction_magnitude(
            (float) $property->original_list_price,
            (float) $property->list_price
        );

        // Factor 3: Listing season (3% → 30%)
        $factors['season'] = self::score_season();

        // Weighted composite
        $base_score = ($factors['listing_dom'] * 0.40)
                    + ($factors['price_reduction'] * 0.30)
                    + ($factors['season'] * 0.30);

        // Remarks analysis (bonus/penalty, max ±15 points)
        $remarks_signals = self::analyze_remarks($remarks);
        $remarks_adjustment = $remarks_signals['adjustment'];
        $score = max(0, min(100, $base_score + $remarks_adjustment));

        return [
            'score'            => round($score, 2),
            'factors'          => $factors,
            'remarks_signals'  => $remarks_signals,
        ];
    }

    private static function score_listing_dom(int $dom): float {
        return match (true) {
            $dom > 120 => 100,
            $dom > 90  => 85,
            $dom > 60  => 70,
            $dom > 30  => 50,
            default    => 30,
        };
    }

    private static function score_reduction_magnitude(float $original, float $current): float {
        if ($original <= 0 || $current <= 0 || $original <= $current) return 20;
        $pct = (($original - $current) / $original) * 100;

        return match (true) {
            $pct > 20 => 100,
            $pct > 15 => 80,
            $pct > 10 => 60,
            $pct > 5  => 40,
            default   => 20,
        };
    }

    private static function score_season(): float {
        $month = (int) current_time('n');
        return match (true) {
            $month >= 12 || $month <= 2 => 100,  // Winter — fewer buyers, more negotiation
            $month >= 9 && $month <= 11 => 70,   // Fall
            $month >= 3 && $month <= 5  => 40,   // Spring — competitive
            default                     => 30,   // Summer — competitive
        };
    }

    /**
     * Scan listing remarks for flip-signal keywords.
     *
     * @return array { positive: array, negative: array, adjustment: float }
     */
    public static function analyze_remarks(?string $remarks): array {
        $result = [
            'positive'   => [],
            'negative'   => [],
            'adjustment' => 0,
        ];

        if (empty($remarks)) return $result;

        $lower = strtolower($remarks);

        foreach (self::POSITIVE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                $result['positive'][] = $keyword;
            }
        }

        foreach (self::NEGATIVE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                $result['negative'][] = $keyword;
            }
        }

        // Each positive keyword adds 3 points, each negative subtracts 3, capped at ±15
        $raw = (count($result['positive']) * 3) - (count($result['negative']) * 3);
        $result['adjustment'] = max(-15, min(15, $raw));

        return $result;
    }

    /**
     * Get listing remarks from listings table.
     */
    public static function get_remarks(int $listing_id): ?string {
        global $wpdb;
        $listings_table = $wpdb->prefix . 'bme_listings';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT public_remarks FROM {$listings_table} WHERE listing_id = %s",
            $listing_id
        ));
    }
}
