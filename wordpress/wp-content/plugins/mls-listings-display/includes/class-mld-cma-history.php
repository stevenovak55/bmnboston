<?php
/**
 * MLD CMA History Class
 *
 * Handles tracking and retrieval of CMA value history for properties.
 * Enables trend visualization and historical analysis.
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.20.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_History {

    /**
     * Table name
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mld_cma_value_history';
    }

    /**
     * Check if table exists
     * @return bool
     */
    public function table_exists() {
        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        return $result === $this->table_name;
    }

    /**
     * Record a CMA valuation in history
     *
     * @param array $data CMA data to record
     * @return int|false Insert ID on success, false on failure
     */
    public function record_valuation($data) {
        global $wpdb;

        // Validate required fields
        if (empty($data['property_address'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD CMA History] Cannot record valuation - missing property_address');
            }
            return false;
        }

        // Prepare record data
        $record = array(
            'property_address'     => sanitize_text_field($data['property_address']),
            'property_city'        => sanitize_text_field($data['property_city'] ?? ''),
            'property_state'       => sanitize_text_field($data['property_state'] ?? ''),
            'property_zip'         => sanitize_text_field($data['property_zip'] ?? ''),
            'listing_id'           => sanitize_text_field($data['listing_id'] ?? ''),
            'session_id'           => absint($data['session_id'] ?? 0) ?: null,
            'user_id'              => absint($data['user_id'] ?? get_current_user_id()) ?: null,
            'estimated_value_low'  => floatval($data['estimated_value_low'] ?? 0),
            'estimated_value_mid'  => floatval($data['estimated_value_mid'] ?? 0),
            'estimated_value_high' => floatval($data['estimated_value_high'] ?? 0),
            'weighted_value_mid'   => floatval($data['weighted_value_mid'] ?? 0),
            'comparables_count'    => absint($data['comparables_count'] ?? 0),
            'top_comps_count'      => absint($data['top_comps_count'] ?? 0),
            'confidence_score'     => floatval($data['confidence_score'] ?? 0),
            'confidence_level'     => sanitize_text_field($data['confidence_level'] ?? ''),
            'avg_price_per_sqft'   => floatval($data['avg_price_per_sqft'] ?? 0),
            'filters_used'         => wp_json_encode($data['filters_used'] ?? array()),
            'is_arv_mode'          => !empty($data['is_arv_mode']) ? 1 : 0,
            'arv_overrides'        => wp_json_encode($data['arv_overrides'] ?? null),
            'notes'                => sanitize_textarea_field($data['notes'] ?? ''),
            'created_at'           => current_time('mysql')
        );

        // Format specifications for wpdb->insert
        $format = array(
            '%s', '%s', '%s', '%s', '%s', '%d', '%d',
            '%f', '%f', '%f', '%f',
            '%d', '%d', '%f', '%s', '%f',
            '%s', '%d', '%s', '%s', '%s'
        );

        $result = $wpdb->insert($this->table_name, $record, $format);

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD CMA History] Failed to insert: ' . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get value trend for a property
     *
     * @param string $listing_id Listing ID
     * @param int $months Number of months to look back
     * @return array Trend data with data_points and summary
     */
    public function get_value_trend($listing_id, $months = 12) {
        global $wpdb;

        $since_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($months * 30 * DAY_IN_SECONDS));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, estimated_value_mid, weighted_value_mid, confidence_score,
                    comparables_count, is_arv_mode, created_at
             FROM {$this->table_name}
             WHERE listing_id = %s
               AND created_at >= %s
             ORDER BY created_at ASC",
            $listing_id,
            $since_date
        ), ARRAY_A);

        if (empty($results)) {
            return array(
                'has_history' => false,
                'data_points' => array(),
                'summary' => null
            );
        }

        $data_points = array();
        foreach ($results as $row) {
            $data_points[] = array(
                'id' => $row['id'],
                'date' => $row['created_at'],
                'date_formatted' => wp_date('M j, Y', strtotime($row['created_at'])),
                'value' => floatval($row['estimated_value_mid']),
                'weighted_value' => floatval($row['weighted_value_mid']),
                'confidence' => floatval($row['confidence_score']),
                'comps_used' => intval($row['comparables_count']),
                'is_arv' => $row['is_arv_mode'] == 1
            );
        }

        $first = $data_points[0];
        $last = $data_points[count($data_points) - 1];
        $value_change = $last['value'] - $first['value'];
        $value_change_pct = $first['value'] > 0 ? round(($value_change / $first['value']) * 100, 2) : 0;

        return array(
            'has_history' => true,
            'data_points' => $data_points,
            'summary' => array(
                'first_date' => $first['date'],
                'last_date' => $last['date'],
                'first_value' => $first['value'],
                'last_value' => $last['value'],
                'value_change' => $value_change,
                'value_change_pct' => $value_change_pct,
                'trend_direction' => $value_change > 0 ? 'up' : ($value_change < 0 ? 'down' : 'flat'),
                'total_assessments' => count($data_points),
                'avg_confidence' => round(array_sum(array_column($data_points, 'confidence')) / count($data_points), 1)
            )
        );
    }

    /**
     * Get value statistics for a property
     *
     * @param string $listing_id Listing ID
     * @return array Statistics data
     */
    public function get_value_statistics($listing_id) {
        global $wpdb;

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                MIN(estimated_value_mid) as min_value,
                MAX(estimated_value_mid) as max_value,
                AVG(estimated_value_mid) as avg_value,
                AVG(confidence_score) as avg_confidence,
                COUNT(*) as total_count
             FROM {$this->table_name}
             WHERE listing_id = %s",
            $listing_id
        ), ARRAY_A);

        if (!$stats || $stats['total_count'] == 0) {
            return array('has_data' => false);
        }

        return array(
            'has_data' => true,
            'min_value' => floatval($stats['min_value']),
            'max_value' => floatval($stats['max_value']),
            'avg_value' => floatval($stats['avg_value']),
            'avg_confidence' => round(floatval($stats['avg_confidence']), 1),
            'total_count' => intval($stats['total_count'])
        );
    }
}
