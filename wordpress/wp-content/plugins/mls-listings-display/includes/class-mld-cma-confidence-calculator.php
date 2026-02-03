<?php
/**
 * CMA Confidence Calculator
 *
 * Calculates statistical confidence scores for CMA valuations based on:
 * - Sample size and quality
 * - Data completeness
 * - Market stability (price variance)
 * - Time relevance
 * - Geographic concentration
 * - Comparability distribution
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Confidence_Calculator {

    /**
     * Calculate comprehensive confidence score for a CMA valuation
     *
     * @param array $comparables Array of comparable properties
     * @param array $subject Subject property data
     * @param array $summary Summary statistics from CMA
     * @return array Confidence data with score, level, breakdown, and recommendations
     */
    public function calculate_confidence($comparables, $subject, $summary) {
        if (empty($comparables)) {
            return array(
                'score' => 0,
                'level' => 'None',
                'breakdown' => array(),
                'recommendations' => array('No comparable properties found')
            );
        }

        $scores = array();
        $recommendations = array();

        // 1. Sample Size Score (0-25 points)
        $sample_result = $this->score_sample_size($comparables);
        $scores['sample_size'] = $sample_result['score'];
        if (!empty($sample_result['recommendation'])) {
            $recommendations[] = $sample_result['recommendation'];
        }

        // 2. Data Completeness Score (0-20 points)
        $completeness_result = $this->score_data_completeness($comparables);
        $scores['data_completeness'] = $completeness_result['score'];
        if (!empty($completeness_result['recommendation'])) {
            $recommendations[] = $completeness_result['recommendation'];
        }

        // 3. Market Stability Score (0-20 points)
        $stability_result = $this->score_market_stability($comparables, $summary);
        $scores['market_stability'] = $stability_result['score'];
        if (!empty($stability_result['recommendation'])) {
            $recommendations[] = $stability_result['recommendation'];
        }

        // 4. Time Relevance Score (0-15 points)
        $time_result = $this->score_time_relevance($comparables);
        $scores['time_relevance'] = $time_result['score'];
        if (!empty($time_result['recommendation'])) {
            $recommendations[] = $time_result['recommendation'];
        }

        // 5. Geographic Concentration Score (0-10 points)
        $geo_result = $this->score_geographic_concentration($comparables);
        $scores['geographic_concentration'] = $geo_result['score'];
        if (!empty($geo_result['recommendation'])) {
            $recommendations[] = $geo_result['recommendation'];
        }

        // 6. Comparability Quality Score (0-10 points)
        $quality_result = $this->score_comparability_quality($comparables);
        $scores['comparability_quality'] = $quality_result['score'];
        if (!empty($quality_result['recommendation'])) {
            $recommendations[] = $quality_result['recommendation'];
        }

        // Calculate total score (0-100)
        $total_score = array_sum($scores);

        // Determine confidence level
        $level = $this->get_confidence_level($total_score);

        // Add overall recommendation
        $overall_recommendation = $this->get_overall_recommendation($total_score, $level);
        if ($overall_recommendation) {
            array_unshift($recommendations, $overall_recommendation);
        }

        return array(
            'score' => round($total_score, 1),
            'level' => $level,
            'breakdown' => $scores,
            'recommendations' => $recommendations,
            'reliability_percentage' => $this->calculate_reliability_percentage($total_score)
        );
    }

    /**
     * Score based on sample size
     *
     * FHA/Fannie Mae guidelines require minimum 3 comparable sales for reliable appraisals.
     *
     * @since 6.68.23 - Updated to return 0 for < 3 comparables per FHA requirements
     * @param array $comparables
     * @return array Score and recommendation
     */
    private function score_sample_size($comparables) {
        $count = count($comparables);
        $score = 0;
        $recommendation = '';

        if ($count >= 10) {
            $score = 25; // Excellent sample size
        } else if ($count >= 7) {
            $score = 20; // Very good
        } else if ($count >= 5) {
            $score = 15; // Good
            $recommendation = 'Consider expanding search radius to find more comparables';
        } else if ($count >= 3) {
            $score = 10; // Acceptable (FHA minimum)
            $recommendation = 'Limited comparables found. Expand search criteria for better accuracy';
        } else {
            // v6.68.23: FHA requires minimum 3 comparables - return 0 score
            $score = 0; // Insufficient - below FHA minimum
            $recommendation = 'CRITICAL: FHA requires minimum 3 comparable sales. Only ' . $count . ' found. Expand search area or date range.';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Score based on data completeness
     *
     * @param array $comparables
     * @return array Score and recommendation
     */
    private function score_data_completeness($comparables) {
        $total_fields = 0;
        $complete_fields = 0;

        $critical_fields = array(
            'building_area_total', 'bedrooms_total', 'bathrooms_total',
            'year_built', 'list_price', 'latitude', 'longitude'
        );

        foreach ($comparables as $comp) {
            foreach ($critical_fields as $field) {
                $total_fields++;
                if (isset($comp[$field]) && !empty($comp[$field]) && $comp[$field] !== 0) {
                    $complete_fields++;
                }
            }
        }

        $completeness_pct = $total_fields > 0 ? ($complete_fields / $total_fields) * 100 : 0;
        
        $score = 0;
        $recommendation = '';

        if ($completeness_pct >= 95) {
            $score = 20; // Excellent data
        } else if ($completeness_pct >= 85) {
            $score = 16; // Very good
        } else if ($completeness_pct >= 75) {
            $score = 12; // Good
        } else if ($completeness_pct >= 60) {
            $score = 8; // Fair
            $recommendation = 'Some property data is missing. Results may be less accurate';
        } else {
            $score = 4; // Poor
            $recommendation = 'Significant missing data. Consider data verification';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Score based on market stability (price variance)
     *
     * @param array $comparables
     * @param array $summary
     * @return array Score and recommendation
     */
    private function score_market_stability($comparables, $summary) {
        $adjusted_prices = array_column($comparables, 'adjusted_price');
        
        if (count($adjusted_prices) < 2) {
            return array('score' => 10, 'recommendation' => '');
        }

        $avg = array_sum($adjusted_prices) / count($adjusted_prices);
        
        // Calculate coefficient of variation (CV)
        $variance = array_sum(array_map(function($p) use ($avg) {
            return pow($p - $avg, 2);
        }, $adjusted_prices)) / count($adjusted_prices);
        
        $std_dev = sqrt($variance);
        $cv = ($std_dev / $avg) * 100;

        $score = 0;
        $recommendation = '';

        if ($cv < 5) {
            $score = 20; // Very stable market
        } else if ($cv < 10) {
            $score = 16; // Stable market
        } else if ($cv < 15) {
            $score = 12; // Moderate variance
        } else if ($cv < 25) {
            $score = 8; // High variance
            $recommendation = 'Significant price variance detected. Market may be volatile';
        } else {
            $score = 4; // Very high variance
            $recommendation = 'CAUTION: Extreme price variance. Consider market conditions carefully';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Score based on time relevance of comparables
     *
     * @param array $comparables
     * @return array Score and recommendation
     */
    private function score_time_relevance($comparables) {
        $recent_count = 0;
        $total_count = 0;
        $avg_days_old = 0;
        $days_sum = 0;

        foreach ($comparables as $comp) {
            if ($comp['standard_status'] === 'Closed' && !empty($comp['close_date'])) {
                $days_ago = (time() - strtotime($comp['close_date'])) / (60 * 60 * 24);
                $days_sum += $days_ago;
                $total_count++;

                if ($days_ago < 90) { // Within 3 months
                    $recent_count++;
                }
            }
        }

        $avg_days_old = $total_count > 0 ? $days_sum / $total_count : 0;
        $recent_pct = $total_count > 0 ? ($recent_count / $total_count) * 100 : 0;

        $score = 0;
        $recommendation = '';

        if ($recent_pct >= 80) {
            $score = 15; // Very recent data
        } else if ($recent_pct >= 60) {
            $score = 12; // Mostly recent
        } else if ($recent_pct >= 40) {
            $score = 9; // Some recent
        } else if ($recent_pct >= 20) {
            $score = 6; // Limited recent data
            $recommendation = 'Consider focusing on more recent sales for better accuracy';
        } else {
            $score = 3; // Old data
            $recommendation = 'CAUTION: Comparables are dated. Market conditions may have changed';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Score based on geographic concentration
     *
     * @param array $comparables
     * @return array Score and recommendation
     */
    private function score_geographic_concentration($comparables) {
        $distances = array_column($comparables, 'distance_miles');
        
        if (empty($distances)) {
            return array('score' => 5, 'recommendation' => '');
        }

        $avg_distance = array_sum($distances) / count($distances);

        $score = 0;
        $recommendation = '';

        if ($avg_distance < 1) {
            $score = 10; // Excellent concentration
        } else if ($avg_distance < 2) {
            $score = 8; // Very good
        } else if ($avg_distance < 3) {
            $score = 6; // Good
        } else if ($avg_distance < 5) {
            $score = 4; // Fair
        } else {
            $score = 2; // Poor
            $recommendation = 'Comparables are geographically dispersed. Location factors may vary';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Score based on comparability quality (grades)
     *
     * @param array $comparables
     * @return array Score and recommendation
     */
    private function score_comparability_quality($comparables) {
        $grade_counts = array('A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0);
        
        foreach ($comparables as $comp) {
            $grade = $comp['comparability_grade'] ?? 'F';
            if (isset($grade_counts[$grade])) {
                $grade_counts[$grade]++;
            }
        }

        $total = count($comparables);
        $high_quality = $grade_counts['A'] + $grade_counts['B'];
        $quality_pct = $total > 0 ? ($high_quality / $total) * 100 : 0;

        $score = 0;
        $recommendation = '';

        if ($quality_pct >= 70) {
            $score = 10; // Excellent comparability
        } else if ($quality_pct >= 50) {
            $score = 8; // Very good
        } else if ($quality_pct >= 30) {
            $score = 6; // Good
        } else if ($quality_pct >= 15) {
            $score = 4; // Fair
            $recommendation = 'Consider adjusting filters to find more similar properties';
        } else {
            $score = 2; // Poor
            $recommendation = 'Low comparability scores. Widen search criteria';
        }

        return array('score' => $score, 'recommendation' => $recommendation);
    }

    /**
     * Get confidence level from score
     *
     * @param float $score Total confidence score (0-100)
     * @return string Confidence level
     */
    private function get_confidence_level($score) {
        if ($score >= 85) {
            return 'Very High';
        } else if ($score >= 70) {
            return 'High';
        } else if ($score >= 55) {
            return 'Medium';
        } else if ($score >= 40) {
            return 'Low';
        } else {
            return 'Very Low';
        }
    }

    /**
     * Calculate reliability percentage
     *
     * @param float $score Confidence score
     * @return string Reliability percentage with description
     */
    private function calculate_reliability_percentage($score) {
        // Convert 0-100 score to reliability percentage
        $reliability = round($score, 0);
        
        if ($reliability >= 85) {
            $description = 'Highly reliable estimate with strong supporting data';
        } else if ($reliability >= 70) {
            $description = 'Reliable estimate with good supporting data';
        } else if ($reliability >= 55) {
            $description = 'Moderately reliable - use as general guidance';
        } else if ($reliability >= 40) {
            $description = 'Limited reliability - additional research recommended';
        } else {
            $description = 'Low reliability - significant caution advised';
        }

        return array(
            'percentage' => $reliability,
            'description' => $description
        );
    }

    /**
     * Get overall recommendation based on score
     *
     * @param float $score Confidence score
     * @param string $level Confidence level
     * @return string Recommendation or empty
     */
    private function get_overall_recommendation($score, $level) {
        if ($score >= 70) {
            return '✓ This CMA has strong supporting data and can be used with confidence';
        } else if ($score >= 55) {
            return '⚠ This CMA provides reasonable guidance but additional research is recommended';
        } else if ($score >= 40) {
            return '⚠ CAUTION: Limited data quality. Use as preliminary estimate only';
        } else {
            return '✗ WARNING: Insufficient data for reliable valuation. Manual appraisal recommended';
        }
    }
}
