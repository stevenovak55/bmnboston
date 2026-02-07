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

    /** Keywords indicating flip opportunity in listing remarks (keyword => weight) */
    const POSITIVE_KEYWORDS = [
        'as-is'                => 5,  'as is'                => 5,
        'estate sale'          => 4,  'estate'               => 3,
        'investor'             => 3,  'handyman'             => 5,
        'handyman special'     => 5,  'tlc'                  => 4,
        'potential'            => 2,  'needs work'           => 5,
        'needs updating'       => 4,  'priced to sell'       => 3,
        'motivated'            => 3,  'bring your vision'    => 3,
        'diamond in the rough' => 4,  'fixer'                => 5,
        'below market'         => 3,  'must sell'            => 4,
        'foreclosure'          => 5,  'bank owned'           => 5,
        'reo'                  => 5,  'short sale'           => 5,
        'probate'              => 4,  'opportunity'          => 2,
        'value add'            => 3,  'sweat equity'         => 4,
        'original condition'   => 4,  'untouched'            => 4,
        'dated kitchen'        => 4,  'dated bath'           => 4,
        'contractor special'   => 5,  'tear down'            => 5,
        'teardown'             => 5,
    ];

    /** Keywords indicating already-renovated (less flip margin) (keyword => weight) */
    const NEGATIVE_KEYWORDS = [
        'new roof'              => 3,  'new kitchen'           => 4,
        'renovated'             => 4,  'remodeled'             => 4,
        'updated kitchen'       => 3,  'updated bath'          => 3,
        'move-in ready'         => 4,  'move in ready'         => 4,
        'turnkey'               => 5,  'turn key'              => 5,
        'fully updated'         => 5,  'completely renovated'  => 5,
        'gut renovation'        => 5,  'brand new'             => 5,
        'new construction'      => 5,  'custom built'          => 4,
        'just built'            => 5,  'newly built'           => 5,
        'recently completed'    => 5,  'like new'              => 4,
        'new build'             => 5,
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
        $w = Flip_Database::get_scoring_weights();
        $mw = $w['market_sub'];
        $base_score = ($factors['listing_dom'] * $mw['dom'])
                    + ($factors['price_reduction'] * $mw['reduction'])
                    + ($factors['season'] * $mw['season']);

        // Remarks analysis (bonus/penalty, max ±cap points)
        $remarks_signals = self::analyze_remarks($remarks, (int) $w['market_remarks_cap']);
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
    public static function analyze_remarks(?string $remarks, int $cap = 25): array {
        $result = [
            'positive'   => [],
            'negative'   => [],
            'adjustment' => 0,
        ];

        if (empty($remarks)) return $result;

        $lower = strtolower($remarks);

        $raw = 0;
        foreach (self::POSITIVE_KEYWORDS as $keyword => $weight) {
            if (str_contains($lower, $keyword)) {
                $result['positive'][] = $keyword;
                $raw += $weight;
            }
        }

        foreach (self::NEGATIVE_KEYWORDS as $keyword => $weight) {
            if (str_contains($lower, $keyword)) {
                $result['negative'][] = $keyword;
                $raw -= $weight;
            }
        }

        // Weighted adjustment capped at ±cap (v0.11.0: was ±15 with flat 3 per keyword)
        $result['adjustment'] = max(-$cap, min($cap, $raw));

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
