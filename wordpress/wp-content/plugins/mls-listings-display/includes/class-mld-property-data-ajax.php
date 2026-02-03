<?php
/**
 * MLD Property Data AJAX Handler
 *
 * Handles saving and retrieving user-defined property characteristics
 * (road type and condition) for CMA analysis.
 *
 * Used by Property Details CMA tool to persist user adjustments.
 *
 * @package MLS_Listings_Display
 * @since 5.2.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Data_AJAX {

    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers for saving/retrieving property characteristics
        add_action('wp_ajax_mld_save_user_property_data', array($this, 'save_user_property_data'));
        add_action('wp_ajax_nopriv_mld_save_user_property_data', array($this, 'save_user_property_data'));

        add_action('wp_ajax_mld_get_user_property_data', array($this, 'get_user_property_data'));
        add_action('wp_ajax_nopriv_mld_get_user_property_data', array($this, 'get_user_property_data'));
    }

    /**
     * Save user-defined property data (road type and condition)
     */
    public function save_user_property_data() {
        // Use mld_ajax_nonce which is created in class-mld-rewrites.php
        $nonce_check = check_ajax_referer('mld_ajax_nonce', 'nonce', false);
        if (!$nonce_check) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Property Data AJAX: Nonce verification failed for save_user_property_data');
            }
            wp_send_json_error('Security check failed');
            return;
        }

        global $wpdb;

        $listing_id = intval($_POST['listing_id'] ?? 0);
        $road_type = sanitize_text_field($_POST['road_type'] ?? '');
        $condition = sanitize_text_field($_POST['property_condition'] ?? '');
        $user_id = get_current_user_id();

        if (!$listing_id) {
            wp_send_json_error('Invalid listing ID');
            return;
        }

        // Validate road type
        $valid_road_types = array('unknown', 'main_road', 'neighborhood_road');
        if ($road_type && !in_array($road_type, $valid_road_types)) {
            wp_send_json_error('Invalid road type');
            return;
        }

        // Validate condition
        $valid_conditions = array('unknown', 'new', 'fully_renovated', 'some_updates', 'needs_updating', 'distressed');
        if ($condition && !in_array($condition, $valid_conditions)) {
            wp_send_json_error('Invalid condition');
            return;
        }

        // Build update data
        $data = array(
            'listing_id' => $listing_id,
            'updated_at' => current_time('mysql')
        );

        if ($road_type) {
            $data['road_type'] = $road_type;
            $data['road_type_updated_by'] = $user_id;
            $data['road_type_updated_at'] = current_time('mysql');
        }

        if ($condition) {
            $data['property_condition'] = $condition;
            $data['condition_updated_by'] = $user_id;
            $data['condition_updated_at'] = current_time('mysql');
            $data['is_new_construction'] = ($condition === 'new') ? 1 : 0;
        }

        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_user_property_data WHERE listing_id = %d",
            $listing_id
        ));

        if ($exists) {
            // Update existing
            $result = $wpdb->update(
                $wpdb->prefix . 'mld_user_property_data',
                $data,
                array('listing_id' => $listing_id)
            );
        } else {
            // Insert new
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $wpdb->prefix . 'mld_user_property_data',
                $data
            );
        }

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Property data saved successfully',
                'data' => $data
            ));
        } else {
            wp_send_json_error('Failed to save property data');
        }
    }

    /**
     * Get user-defined property data
     */
    public function get_user_property_data() {
        check_ajax_referer('mld_ajax_nonce', 'nonce', false);

        global $wpdb;

        $listing_id = intval($_POST['listing_id'] ?? 0);

        if (!$listing_id) {
            wp_send_json_error('Invalid listing ID');
            return;
        }

        $data = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}mld_user_property_data
            WHERE listing_id = %d
        ", $listing_id), ARRAY_A);

        if ($data) {
            wp_send_json_success($data);
        } else {
            wp_send_json_success(array(
                'road_type' => 'unknown',
                'property_condition' => 'unknown',
                'is_new_construction' => 0
            ));
        }
    }
}

// Initialize the class
new MLD_Property_Data_AJAX();
