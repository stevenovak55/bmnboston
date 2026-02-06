<?php
/**
 * Property Fundamentals Scorer (25% of total score).
 *
 * REVISED: Focuses on expansion potential and lot value, NOT beds/baths.
 * Beds and baths can always be added - lot size and location cannot.
 *
 * Evaluates: lot size, expansion potential, existing sqft, renovation need (age-based).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Property_Scorer {

    /**
     * Score property fundamentals for flip potential.
     *
     * @param object $property Row from bme_listing_summary.
     * @return array { score: float (0-100), factors: array }
     */
    public static function score(object $property): array {
        $factors = [];

        $sqft = (int) $property->building_area_total;
        $lot_acres = (float) $property->lot_size_acres;
        $lot_sqft = $lot_acres * 43560;

        // Factor 1: Lot Size (35% of property score)
        // Larger lots = more expansion potential and land value
        $factors['lot_size'] = self::score_lot_size($lot_acres);

        // Factor 2: Expansion Potential (30% of property score)
        // Ratio of unused lot to existing building - room to add sqft
        // Condos/townhouses can't practically expand on their lot
        $sub_type = $property->property_sub_type ?? 'Single Family Residence';
        $expansion_score = self::score_expansion_potential($lot_sqft, $sqft);
        if (in_array($sub_type, ['Condominium', 'Townhouse'], true)) {
            $expansion_score = min($expansion_score, 40);
        }
        $factors['expansion_potential'] = $expansion_score;

        // Factor 3: Existing Square Footage (20% of property score)
        // More sqft = more to work with, but also more rehab cost
        $factors['existing_sqft'] = self::score_existing_sqft($sqft);

        // Factor 4: Renovation Need (15% of property score)
        // Older properties have more value-add potential through renovation
        $year = (int) $property->year_built;
        $factors['renovation_need'] = self::score_renovation_need($year);

        // Weighted composite
        $score = ($factors['lot_size'] * 0.35)
               + ($factors['expansion_potential'] * 0.30)
               + ($factors['existing_sqft'] * 0.20)
               + ($factors['renovation_need'] * 0.15);

        return [
            'score'   => round($score, 2),
            'factors' => $factors,
        ];
    }

    /**
     * Score lot size - larger lots have more value and potential.
     */
    private static function score_lot_size(float $lot_acres): float {
        if ($lot_acres <= 0) return 30;

        return match (true) {
            $lot_acres >= 2.0  => 100,  // 2+ acres - excellent
            $lot_acres >= 1.0  => 90,   // 1-2 acres - great
            $lot_acres >= 0.5  => 80,   // 0.5-1 acre - good
            $lot_acres >= 0.33 => 70,   // 1/3 acre - decent
            $lot_acres >= 0.25 => 60,   // 1/4 acre - standard
            $lot_acres >= 0.15 => 45,   // Small lot
            default            => 30,   // Very small lot
        };
    }

    /**
     * Score expansion potential based on lot-to-house ratio.
     * Higher ratio = more room to expand.
     */
    private static function score_expansion_potential(float $lot_sqft, int $building_sqft): float {
        if ($lot_sqft <= 0 || $building_sqft <= 0) return 50;

        // Calculate how much of the lot is unused
        $ratio = $lot_sqft / $building_sqft;

        // Also consider raw expansion room
        $expansion_room = $lot_sqft - $building_sqft;

        return match (true) {
            // Massive expansion potential
            $ratio >= 20 && $expansion_room >= 40000 => 100,
            $ratio >= 15 && $expansion_room >= 30000 => 95,
            $ratio >= 10 && $expansion_room >= 20000 => 90,
            // Great expansion potential
            $ratio >= 8 && $expansion_room >= 15000 => 85,
            $ratio >= 6 && $expansion_room >= 10000 => 80,
            // Good expansion potential
            $ratio >= 5 => 70,
            $ratio >= 4 => 60,
            // Moderate expansion potential
            $ratio >= 3 => 50,
            $ratio >= 2.5 => 40,
            // Limited expansion potential
            $ratio >= 2 => 30,
            default => 20, // Very limited
        };
    }

    /**
     * Score existing square footage.
     * More sqft is generally better (more to work with).
     */
    private static function score_existing_sqft(int $sqft): float {
        if ($sqft <= 0) return 30;

        return match (true) {
            $sqft >= 4000 => 100,  // Large home - lots of potential
            $sqft >= 3000 => 90,
            $sqft >= 2500 => 85,
            $sqft >= 2000 => 80,
            $sqft >= 1500 => 70,
            $sqft >= 1200 => 60,
            $sqft >= 1000 => 50,
            $sqft >= 800  => 40,
            default       => 30,   // Very small
        };
    }

    /**
     * Score renovation need — older properties have more value-add potential.
     *
     * Sweet spot is 41-70 years: full systems + finishes need updating,
     * but structure is still sound. Very new = nothing to renovate.
     * Very old = diminishing returns (lead, asbestos, irregular framing).
     */
    private static function score_renovation_need(int $year): float {
        if ($year <= 0) return 50; // Unknown

        $age = (int) wp_date('Y') - $year;

        return match (true) {
            $age <= 5   => 5,   // Brand new — nothing to renovate
            $age <= 10  => 15,  // Very little to do
            $age <= 20  => 35,  // Light cosmetic at best
            $age <= 40  => 70,  // Kitchens/baths likely dated — good candidate
            $age <= 70  => 95,  // Strong candidate — full systems + finishes
            $age <= 100 => 85,  // Excellent but some structural complexity
            default     => 70,  // Diminishing returns — lead, asbestos, irregular framing
        };
    }

    /**
     * Get expansion potential category for display.
     */
    public static function get_expansion_category(float $lot_acres, int $building_sqft): string {
        if ($lot_acres <= 0 || $building_sqft <= 0) return 'unknown';

        $lot_sqft = $lot_acres * 43560;
        $ratio = $lot_sqft / $building_sqft;

        return match (true) {
            $ratio >= 10 => 'excellent',
            $ratio >= 6  => 'great',
            $ratio >= 4  => 'good',
            $ratio >= 2.5 => 'moderate',
            default      => 'limited',
        };
    }
}
