<?php
/**
 * AJAX Handler: Get adjustments for single comparable
 *
 * @package MLS_Listings_Display
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get fresh adjustment calculations for a single comparable property
 * Returns: adjustments, adjusted_price, score, grade
 */
add_action('wp_ajax_get_single_comparable_adjustments', 'mld_ajax_get_single_comparable_adjustments');
add_action('wp_ajax_nopriv_get_single_comparable_adjustments', 'mld_ajax_get_single_comparable_adjustments');

function mld_ajax_get_single_comparable_adjustments() {
    check_ajax_referer('mld_ajax_nonce', 'nonce');

    $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
    $subject_json = isset($_POST['subject_property']) ? $_POST['subject_property'] : '';

    if (empty($listing_id) || empty($subject_json)) {
        wp_send_json_error('Missing required parameters');
        return;
    }

    $subject = json_decode(stripslashes($subject_json), true);

    if (!$subject) {
        wp_send_json_error('Invalid subject property data');
        return;
    }

    try {
        global $wpdb;

        // Get comparable property data
        $comp_data = $wpdb->get_row($wpdb->prepare("
            SELECT
                s.listing_id,
                s.list_price,
                s.close_price,
                s.standard_status,
                s.property_type,
                s.close_date,
                s.bedrooms_total,
                s.bathrooms_total as bathrooms_total_decimal,
                s.building_area_total,
                s.year_built,
                s.garage_spaces,
                s.latitude,
                s.longitude,
                COALESCE(lf.waterfront_yn, lfa.waterfront_yn) as waterfront_yn,
                upd.road_type,
                upd.property_condition,
                (3959 * acos(cos(radians(%f)) * cos(radians(s.latitude))
                * cos(radians(s.longitude) - radians(%f))
                + sin(radians(%f)) * sin(radians(s.latitude)))) AS distance_miles
            FROM {$wpdb->prefix}bme_listing_summary s
            LEFT JOIN {$wpdb->prefix}bme_listing_features lf ON s.listing_id = lf.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_features_archive lfa ON s.listing_id = lfa.listing_id
            LEFT JOIN {$wpdb->prefix}mld_user_property_data upd ON s.listing_id = upd.listing_id
            WHERE s.listing_id = %s
        ", $subject['lat'], $subject['lng'], $subject['lat'], $listing_id), ARRAY_A);

        if (!$comp_data) {
            wp_send_json_error('Comparable property not found');
            return;
        }

        // Use CMA calculator
        $cma = new MLD_Comparable_Sales();
        $reflection = new ReflectionClass($cma);

        // Calculate adjustments
        $calc_adjustments = $reflection->getMethod('calculate_adjustments');
        $calc_adjustments->setAccessible(true);
        $adjustments = $calc_adjustments->invoke($cma, $comp_data, $subject);

        // Calculate adjusted price
        $base_price = !empty($comp_data['close_price']) ? $comp_data['close_price'] : $comp_data['list_price'];
        $adjusted_price = $base_price + $adjustments['total_adjustment'];

        // Calculate score
        $calc_score = $reflection->getMethod('calculate_comparability_score');
        $calc_score->setAccessible(true);
        $score = $calc_score->invoke($cma, $comp_data, $subject, $adjustments);

        // Get grade
        $get_grade = $reflection->getMethod('get_comparability_grade');
        $get_grade->setAccessible(true);
        $grade = $get_grade->invoke($cma, $score);

        wp_send_json_success(array(
            'adjustments' => $adjustments,
            'adjusted_price' => $adjusted_price,
            'comparability_score' => $score,
            'comparability_grade' => $grade
        ));

    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error in get_single_comparable_adjustments: ' . $e->getMessage());
        }
        wp_send_json_error($e->getMessage());
    }
}
