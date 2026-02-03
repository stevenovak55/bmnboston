<?php
/**
 * BME Machine Learning Price Predictor
 * 
 * Provides ML-based price predictions and market analysis
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_ML_Predictor {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Database manager
     */
    private $db_manager;
    
    /**
     * Model weights (would be loaded from trained model)
     */
    private $model_weights = [];
    
    /**
     * Feature importance scores
     */
    private $feature_importance = [
        'square_feet' => 0.25,
        'bedrooms' => 0.10,
        'bathrooms' => 0.12,
        'lot_size' => 0.08,
        'year_built' => 0.05,
        'location_score' => 0.20,
        'school_rating' => 0.10,
        'market_trend' => 0.10
    ];
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->db_manager = BME_Database_Manager::get_instance();
        $this->load_model();
    }
    
    /**
     * Load ML model
     */
    private function load_model() {
        // In production, this would load a trained model
        // For now, we'll use a simplified linear regression approach
        $this->model_weights = get_option('bme_ml_model_weights', [
            'base_price_per_sqft' => 150,
            'bedroom_value' => 5000,
            'bathroom_value' => 7500,
            'age_depreciation' => -500,
            'location_multiplier' => 1.0
        ]);
    }
    
    /**
     * Predict property price
     */
    public function predict_price($property_data) {
        // Extract features
        $features = $this->extract_features($property_data);
        
        // Get comparable properties
        $comparables = $this->get_comparable_properties($property_data);
        
        // Calculate base prediction
        $base_prediction = $this->calculate_base_prediction($features);
        
        // Adjust based on comparables
        $adjusted_prediction = $this->adjust_for_comparables($base_prediction, $comparables);
        
        // Apply market trends
        $final_prediction = $this->apply_market_trends($adjusted_prediction, $property_data);
        
        // Calculate confidence interval
        $confidence = $this->calculate_confidence($features, $comparables);
        
        return [
            'predicted_price' => round($final_prediction),
            'confidence' => $confidence,
            'price_range' => [
                'min' => round($final_prediction * (1 - (1 - $confidence) * 0.1)),
                'max' => round($final_prediction * (1 + (1 - $confidence) * 0.1))
            ],
            'comparables_used' => count($comparables),
            'factors' => $this->get_price_factors($features, $comparables),
            'market_trend' => $this->get_market_trend($property_data)
        ];
    }
    
    /**
     * Extract features from property data
     */
    private function extract_features($property_data) {
        $features = [
            'square_feet' => intval($property_data['square_feet'] ?? 0),
            'bedrooms' => intval($property_data['bedrooms'] ?? 0),
            'bathrooms' => floatval($property_data['bathrooms'] ?? 0),
            'lot_size' => floatval($property_data['lot_size'] ?? 0),
            'year_built' => intval($property_data['year_built'] ?? 0),
            'property_type' => $property_data['property_type'] ?? 'Single Family',
            'garage_spaces' => intval($property_data['garage_spaces'] ?? 0),
            'pool' => isset($property_data['pool']) ? 1 : 0,
            'city' => $property_data['city'] ?? '',
            'zip_code' => $property_data['postal_code'] ?? '',
            'latitude' => floatval($property_data['latitude'] ?? 0),
            'longitude' => floatval($property_data['longitude'] ?? 0)
        ];
        
        // Calculate derived features
        $features['age'] = date('Y') - $features['year_built'];
        $features['price_per_sqft_area'] = $this->get_area_price_per_sqft($features['zip_code']);
        $features['location_score'] = $this->calculate_location_score($features);
        $features['school_rating'] = $this->get_school_rating($features['latitude'], $features['longitude']);
        
        return $features;
    }
    
    /**
     * Get comparable properties
     */
    private function get_comparable_properties($property_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listings';
        
        // Define search criteria for comparables
        $square_feet = intval($property_data['square_feet'] ?? 0);
        $bedrooms = intval($property_data['bedrooms'] ?? 0);
        $bathrooms = floatval($property_data['bathrooms'] ?? 0);
        $zip_code = $property_data['postal_code'] ?? '';
        
        // Query for similar properties sold in last 6 months
        $comparables = $wpdb->get_results($wpdb->prepare("
            SELECT 
                listing_id,
                list_price,
                close_price,
                square_feet,
                bedrooms,
                bathrooms,
                year_built,
                days_on_market,
                latitude,
                longitude
            FROM $table
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND postal_code = %s
            AND square_feet BETWEEN %d AND %d
            AND bedrooms BETWEEN %d AND %d
            AND bathrooms BETWEEN %f AND %f
            ORDER BY close_date DESC
            LIMIT 20
        ", 
            $zip_code,
            $square_feet * 0.8, $square_feet * 1.2,
            max(0, $bedrooms - 1), $bedrooms + 1,
            max(0, $bathrooms - 1), $bathrooms + 1
        ));
        
        // If not enough comparables in same zip, expand search
        if (count($comparables) < 5) {
            $comparables = $this->expand_comparable_search($property_data);
        }
        
        // Calculate similarity scores
        foreach ($comparables as &$comp) {
            $comp->similarity = $this->calculate_similarity($property_data, $comp);
        }
        
        // Sort by similarity
        usort($comparables, function($a, $b) {
            return $b->similarity <=> $a->similarity;
        });
        
        // Return top 10 most similar
        return array_slice($comparables, 0, 10);
    }
    
    /**
     * Calculate base prediction
     */
    private function calculate_base_prediction($features) {
        $weights = $this->model_weights;
        
        // Simple linear regression model
        $prediction = 0;
        
        // Square footage is primary driver
        $prediction += $features['square_feet'] * $weights['base_price_per_sqft'];
        
        // Add value for bedrooms and bathrooms
        $prediction += $features['bedrooms'] * $weights['bedroom_value'];
        $prediction += $features['bathrooms'] * $weights['bathroom_value'];
        
        // Adjust for age
        $prediction += $features['age'] * $weights['age_depreciation'];
        
        // Location adjustment
        $prediction *= $features['location_score'];
        
        // Property type adjustment
        $type_multipliers = [
            'Single Family' => 1.0,
            'Condo' => 0.85,
            'Townhouse' => 0.9,
            'Multi Family' => 1.2
        ];
        $prediction *= $type_multipliers[$features['property_type']] ?? 1.0;
        
        return $prediction;
    }
    
    /**
     * Adjust prediction based on comparables
     */
    private function adjust_for_comparables($base_prediction, $comparables) {
        if (empty($comparables)) {
            return $base_prediction;
        }
        
        // Calculate weighted average of comparable prices
        $total_weight = 0;
        $weighted_sum = 0;
        
        foreach ($comparables as $comp) {
            $price = $comp->close_price ?: $comp->list_price;
            $weight = $comp->similarity;
            
            $weighted_sum += $price * $weight;
            $total_weight += $weight;
        }
        
        if ($total_weight > 0) {
            $comparable_avg = $weighted_sum / $total_weight;
            
            // Blend base prediction with comparable average
            // More comparables = more weight to comparable average
            $comparable_weight = min(0.7, count($comparables) * 0.1);
            $adjusted = ($base_prediction * (1 - $comparable_weight)) + ($comparable_avg * $comparable_weight);
            
            return $adjusted;
        }
        
        return $base_prediction;
    }
    
    /**
     * Apply market trends
     */
    private function apply_market_trends($price, $property_data) {
        $trend = $this->get_market_trend($property_data);
        
        // Adjust price based on market trend
        $trend_adjustment = 1 + ($trend['monthly_change'] / 100);
        
        return $price * $trend_adjustment;
    }
    
    /**
     * Calculate confidence score
     */
    private function calculate_confidence($features, $comparables) {
        $confidence = 0;
        
        // More comparables = higher confidence
        $comparables_score = min(1, count($comparables) / 10) * 0.3;
        
        // Complete features = higher confidence
        $features_score = 0;
        $required_features = ['square_feet', 'bedrooms', 'bathrooms', 'year_built'];
        foreach ($required_features as $feature) {
            if (!empty($features[$feature])) {
                $features_score += 0.1;
            }
        }
        
        // High similarity of comparables = higher confidence
        $similarity_score = 0;
        if (!empty($comparables)) {
            $avg_similarity = array_sum(array_column($comparables, 'similarity')) / count($comparables);
            $similarity_score = $avg_similarity * 0.3;
        }
        
        $confidence = $comparables_score + $features_score + $similarity_score;
        
        return min(1, max(0, $confidence));
    }
    
    /**
     * Calculate similarity between properties
     */
    private function calculate_similarity($property1, $property2) {
        $similarity = 0;
        
        // Square footage similarity (most important)
        $sqft1 = $property1['square_feet'] ?? 0;
        $sqft2 = $property2->square_feet ?? 0;
        if ($sqft1 && $sqft2) {
            $sqft_diff = abs($sqft1 - $sqft2) / max($sqft1, $sqft2);
            $similarity += (1 - $sqft_diff) * 0.3;
        }
        
        // Bedroom similarity
        $bed1 = $property1['bedrooms'] ?? 0;
        $bed2 = $property2->bedrooms ?? 0;
        if ($bed1 == $bed2) {
            $similarity += 0.2;
        } elseif (abs($bed1 - $bed2) == 1) {
            $similarity += 0.1;
        }
        
        // Bathroom similarity
        $bath1 = $property1['bathrooms'] ?? 0;
        $bath2 = $property2->bathrooms ?? 0;
        if (abs($bath1 - $bath2) < 0.5) {
            $similarity += 0.15;
        } elseif (abs($bath1 - $bath2) < 1) {
            $similarity += 0.075;
        }
        
        // Age similarity
        $year1 = $property1['year_built'] ?? 0;
        $year2 = $property2->year_built ?? 0;
        if ($year1 && $year2) {
            $age_diff = abs($year1 - $year2);
            if ($age_diff < 5) {
                $similarity += 0.15;
            } elseif ($age_diff < 10) {
                $similarity += 0.075;
            }
        }
        
        // Location proximity
        $lat1 = $property1['latitude'] ?? 0;
        $lon1 = $property1['longitude'] ?? 0;
        $lat2 = $property2->latitude ?? 0;
        $lon2 = $property2->longitude ?? 0;
        
        if ($lat1 && $lon1 && $lat2 && $lon2) {
            $distance = $this->calculate_distance($lat1, $lon1, $lat2, $lon2);
            if ($distance < 0.5) { // Within 0.5 miles
                $similarity += 0.2;
            } elseif ($distance < 1) { // Within 1 mile
                $similarity += 0.1;
            }
        }
        
        return $similarity;
    }
    
    /**
     * Calculate distance between two points
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 3959; // Miles
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
    }
    
    /**
     * Get area price per square foot
     */
    private function get_area_price_per_sqft($zip_code) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listings';
        
        $avg_price_per_sqft = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(close_price / square_feet) 
            FROM $table
            WHERE postal_code = %s
            AND standard_status = 'Closed'
            AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND square_feet > 0
            AND close_price > 0
        ", $zip_code));
        
        return $avg_price_per_sqft ?: 150; // Default fallback
    }
    
    /**
     * Calculate location score
     */
    private function calculate_location_score($features) {
        // In production, this would use more sophisticated location data
        // For now, use zip code-based scoring
        
        $premium_zips = ['02116', '02108', '02109', '02110']; // Example premium Boston zip codes
        $good_zips = ['02114', '02115', '02118', '02119'];
        
        if (in_array($features['zip_code'], $premium_zips)) {
            return 1.3;
        } elseif (in_array($features['zip_code'], $good_zips)) {
            return 1.15;
        }
        
        return 1.0;
    }
    
    /**
     * Get school rating for location
     */
    private function get_school_rating($lat, $lon) {
        // In production, this would call a school rating API
        // For now, return a random score
        return rand(60, 100) / 100;
    }
    
    /**
     * Get market trend
     */
    public function get_market_trend($property_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listings';
        
        $zip_code = $property_data['postal_code'] ?? '';
        
        // Get average prices for last 6 months
        $monthly_averages = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(close_date, '%%Y-%%m') as month,
                AVG(close_price) as avg_price,
                COUNT(*) as sales_count
            FROM $table
            WHERE postal_code = %s
            AND standard_status = 'Closed'
            AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(close_date, '%%Y-%%m')
            ORDER BY month
        ", $zip_code));
        
        if (count($monthly_averages) < 2) {
            return [
                'trend' => 'stable',
                'monthly_change' => 0,
                'confidence' => 'low'
            ];
        }
        
        // Calculate trend
        $first_month = reset($monthly_averages);
        $last_month = end($monthly_averages);
        
        $price_change = (($last_month->avg_price - $first_month->avg_price) / $first_month->avg_price) * 100;
        $monthly_change = $price_change / count($monthly_averages);
        
        $trend = 'stable';
        if ($monthly_change > 1) {
            $trend = 'rising';
        } elseif ($monthly_change < -1) {
            $trend = 'falling';
        }
        
        return [
            'trend' => $trend,
            'monthly_change' => round($monthly_change, 2),
            'total_change' => round($price_change, 2),
            'months_analyzed' => count($monthly_averages),
            'confidence' => count($monthly_averages) >= 4 ? 'high' : 'medium'
        ];
    }
    
    /**
     * Get price factors
     */
    private function get_price_factors($features, $comparables) {
        $factors = [];
        
        // Positive factors
        if ($features['location_score'] > 1.1) {
            $factors[] = [
                'factor' => 'Premium Location',
                'impact' => '+' . round(($features['location_score'] - 1) * 100) . '%',
                'type' => 'positive'
            ];
        }
        
        if ($features['school_rating'] > 0.8) {
            $factors[] = [
                'factor' => 'Excellent Schools',
                'impact' => '+' . round($features['school_rating'] * 10) . '%',
                'type' => 'positive'
            ];
        }
        
        if ($features['age'] < 5) {
            $factors[] = [
                'factor' => 'New Construction',
                'impact' => '+5%',
                'type' => 'positive'
            ];
        }
        
        // Negative factors
        if ($features['age'] > 50) {
            $factors[] = [
                'factor' => 'Older Property',
                'impact' => '-' . min(10, round($features['age'] / 10)) . '%',
                'type' => 'negative'
            ];
        }
        
        if (count($comparables) < 3) {
            $factors[] = [
                'factor' => 'Limited Comparables',
                'impact' => 'Lower Confidence',
                'type' => 'neutral'
            ];
        }
        
        return $factors;
    }
    
    /**
     * Expand comparable search if needed
     */
    private function expand_comparable_search($property_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listings';
        
        $lat = floatval($property_data['latitude'] ?? 0);
        $lon = floatval($property_data['longitude'] ?? 0);
        
        if (!$lat || !$lon) {
            return [];
        }
        
        // Search within 2 mile radius
        $radius = 2;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                listing_id,
                list_price,
                close_price,
                square_feet,
                bedrooms,
                bathrooms,
                year_built,
                days_on_market,
                latitude,
                longitude,
                (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) * cos(radians(longitude) - radians(%f)) + sin(radians(%f)) * sin(radians(latitude)))) AS distance
            FROM $table
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            HAVING distance < %f
            ORDER BY distance, close_date DESC
            LIMIT 20
        ", $lat, $lon, $lat, $radius));
    }
    
    /**
     * Train model with new data
     */
    public function train_model() {
        // This would implement model training
        // For production, consider using a Python service for ML
        
        // Update model weights based on recent sales
        $this->update_model_weights();
        
        // Save updated model
        update_option('bme_ml_model_weights', $this->model_weights);
        update_option('bme_ml_model_last_trained', current_time('mysql'));
    }
    
    /**
     * Update model weights
     */
    private function update_model_weights() {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listings';
        
        // Get recent sales data
        $recent_sales = $wpdb->get_results("
            SELECT 
                close_price,
                square_feet,
                bedrooms,
                bathrooms,
                year_built
            FROM $table
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
            AND square_feet > 0
            AND close_price > 0
            LIMIT 1000
        ");
        
        if (empty($recent_sales)) {
            return;
        }
        
        // Simple linear regression to update weights
        // In production, use proper ML algorithms
        
        $total_sqft_price = 0;
        $count = 0;
        
        foreach ($recent_sales as $sale) {
            // Validate square_feet to prevent division by zero
            if (!empty($sale->square_feet) && $sale->square_feet > 0 && !empty($sale->close_price) && $sale->close_price > 0) {
                $price_per_sqft = $sale->close_price / $sale->square_feet;
                $total_sqft_price += $price_per_sqft;
                $count++;
            }
        }
        
        if ($count > 0) {
            $this->model_weights['base_price_per_sqft'] = $total_sqft_price / $count;
        }
    }
    
    /**
     * Get prediction history for property
     */
    public function get_prediction_history($listing_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_price_predictions';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT
                id,
                listing_id,
                predicted_price,
                confidence_score,
                factors,
                created_at
            FROM $table
            WHERE listing_id = %d
            ORDER BY created_at DESC
            LIMIT 30
        ", $listing_id));
    }
    
    /**
     * Save prediction
     */
    public function save_prediction($listing_id, $prediction) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_price_predictions';
        
        return $wpdb->insert($table, [
            'listing_id' => $listing_id,
            'predicted_price' => $prediction['predicted_price'],
            'confidence' => $prediction['confidence'],
            'min_price' => $prediction['price_range']['min'],
            'max_price' => $prediction['price_range']['max'],
            'factors' => json_encode($prediction['factors']),
            'created_at' => current_time('mysql')
        ]);
    }
}

// Initialize
BME_ML_Predictor::get_instance();