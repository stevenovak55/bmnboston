<?php
/**
 * Mobile REST API endpoints for exclusive listings
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Mobile_REST_API
 *
 * Handles REST API endpoints for iOS app integration.
 * Registers under the mld-mobile/v1 namespace for compatibility.
 */
class EL_Mobile_REST_API {

    /**
     * REST API namespace (shared with MLD mobile)
     * @var string
     */
    const NAMESPACE = 'mld-mobile/v1';

    /**
     * BME sync service
     * @var EL_BME_Sync
     */
    private $bme_sync;

    /**
     * Image handler
     * @var EL_Image_Handler
     */
    private $image_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->bme_sync = new EL_BME_Sync();
        $this->image_handler = new EL_Image_Handler();
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_routes() {
        // List agent's exclusive listings
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'handle_list'),
                    'permission_callback' => array($this, 'check_agent_permission'),
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'handle_create'),
                    'permission_callback' => array($this, 'check_agent_permission'),
                ),
            )
        );

        // Get/Update/Delete single listing
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings/(?P<id>\d+)',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'handle_get'),
                    'permission_callback' => array($this, 'check_agent_permission'),
                    'args' => array(
                        'id' => array(
                            'required' => true,
                            'validate_callback' => function($param) {
                                return is_numeric($param) && $param > 0;
                            },
                        ),
                    ),
                ),
                array(
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => array($this, 'handle_update'),
                    'permission_callback' => array($this, 'check_listing_ownership'),
                    'args' => array(
                        'id' => array(
                            'required' => true,
                            'validate_callback' => function($param) {
                                return is_numeric($param) && $param > 0;
                            },
                        ),
                    ),
                ),
                array(
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => array($this, 'handle_delete'),
                    'permission_callback' => array($this, 'check_listing_ownership'),
                    'args' => array(
                        'id' => array(
                            'required' => true,
                            'validate_callback' => function($param) {
                                return is_numeric($param) && $param > 0;
                            },
                        ),
                    ),
                ),
            )
        );

        // Photo upload
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings/(?P<id>\d+)/photos',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array($this, 'handle_get_photos'),
                    'permission_callback' => array($this, 'check_agent_permission'),
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'handle_upload_photos'),
                    'permission_callback' => array($this, 'check_listing_ownership'),
                ),
            )
        );

        // Delete single photo
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings/(?P<id>\d+)/photos/(?P<photo_id>\d+)',
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'handle_delete_photo'),
                'permission_callback' => array($this, 'check_listing_ownership'),
            )
        );

        // Reorder photos
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings/(?P<id>\d+)/photos/order',
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'handle_reorder_photos'),
                'permission_callback' => array($this, 'check_listing_ownership'),
            )
        );

        // Get valid options (property types, statuses, etc.)
        register_rest_route(
            self::NAMESPACE,
            '/exclusive-listings/options',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_get_options'),
                'permission_callback' => array($this, 'check_agent_permission'),
            )
        );
    }

    /**
     * Handle list exclusive listings
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_list($request) {
        global $wpdb;
        $user_id = get_current_user_id();

        // Get agent ID from user
        $agent_id = $this->get_agent_id($user_id);

        $page = $request->get_param('page') ?: 1;
        $per_page = min($request->get_param('per_page') ?: 20, 100);
        $status = $request->get_param('status');
        $offset = ($page - 1) * $per_page;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $sequence_table = $wpdb->prefix . 'exclusive_listing_sequence';

        // Build query - get exclusive listings (ID < 1,000,000)
        // In the future, filter by agent_id when we store creator info
        $where = "WHERE s.listing_id < " . EL_EXCLUSIVE_ID_THRESHOLD;

        if ($status) {
            $where .= $wpdb->prepare(" AND s.standard_status = %s", $status);
        }

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table} s {$where}");

        // Get listings
        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$summary_table} s
             {$where}
             ORDER BY s.modification_timestamp DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        // Format response
        $items = array();
        foreach ($listings as $listing) {
            $items[] = $this->format_listing_response($listing);
        }

        return $this->success_response(array(
            'items' => $items,
            'total' => intval($total),
            'page' => intval($page),
            'per_page' => intval($per_page),
            'total_pages' => intval(ceil($total / $per_page)),
        ));
    }

    /**
     * Handle create exclusive listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_create($request) {
        $data = $request->get_json_params();

        if (empty($data)) {
            $data = $request->get_params();
        }

        // Sanitize input
        $sanitized = EL_Validator::sanitize($data);

        // Set default status if not provided
        if (empty($sanitized['standard_status'])) {
            $sanitized['standard_status'] = 'Active';
        }

        // Validate
        $validation = EL_Validator::validate_create($sanitized);
        if (!$validation['valid']) {
            return $this->error_response(
                'validation_failed',
                'Validation failed',
                $validation['errors'],
                400
            );
        }

        // Generate new ID
        $id_generator = exclusive_listings()->get_id_generator();
        $listing_id = $id_generator->generate();

        if (is_wp_error($listing_id)) {
            return $this->error_response(
                $listing_id->get_error_code(),
                $listing_id->get_error_message(),
                null,
                500
            );
        }

        // Generate listing key
        $listing_key = $id_generator->generate_listing_key($listing_id);

        // Add creator info
        $sanitized['created_by'] = get_current_user_id();
        $sanitized['created_at'] = current_time('mysql');

        // Sync to BME tables
        $result = $this->bme_sync->sync_listing($listing_id, $sanitized, $listing_key);

        if (is_wp_error($result)) {
            return $this->error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                null,
                500
            );
        }

        // Get the created listing
        $listing = $this->get_listing_by_id($listing_id);

        // Defensive null check - if listing retrieval fails after sync, return error
        if (!$listing) {
            error_log("Exclusive Listings: Listing {$listing_id} created but not found in summary table");
            return $this->error_response(
                'listing_retrieval_failed',
                'Listing was created but could not be retrieved immediately. Please refresh your listings.',
                array('listing_id' => $listing_id),
                500
            );
        }

        // Trigger notification to agent's clients (v1.3.0)
        do_action('exclusive_listing_created', $listing_id, $sanitized, get_current_user_id());

        return $this->success_response(array(
            'listing' => $this->format_listing_response($listing),
            'message' => 'Listing created successfully',
        ), 201);
    }

    /**
     * Handle get single exclusive listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get($request) {
        $listing_id = intval($request->get_param('id'));

        $listing = $this->get_listing_by_id($listing_id);

        if (!$listing) {
            return $this->error_response(
                'listing_not_found',
                'Listing not found',
                null,
                404
            );
        }

        // Get photos and format for iOS
        $raw_photos = $this->image_handler->get_photos($listing_id);
        $photos = $this->format_photos_array_for_ios($raw_photos);

        $response = $this->format_listing_response($listing);
        $response['photos'] = $photos;

        return $this->success_response(array(
            'listing' => $response,
        ));
    }

    /**
     * Handle update exclusive listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_update($request) {
        $listing_id = intval($request->get_param('id'));
        $data = $request->get_json_params();

        if (empty($data)) {
            $data = $request->get_params();
        }

        // Remove id from data
        unset($data['id']);

        // Sanitize input
        $sanitized = EL_Validator::sanitize($data);

        // Validate (partial validation for update)
        $validation = EL_Validator::validate_update($sanitized);
        if (!$validation['valid']) {
            return $this->error_response(
                'validation_failed',
                'Validation failed',
                $validation['errors'],
                400
            );
        }

        // Get existing listing
        $existing = $this->get_listing_by_id($listing_id);
        if (!$existing) {
            return $this->error_response('listing_not_found', 'Listing not found', null, 404);
        }

        // Get listing key
        $listing_key = $existing['listing_key'];

        // Check for status change to Closed (triggers archive)
        $old_status = $existing['standard_status'];
        $new_status = isset($sanitized['standard_status']) ? $sanitized['standard_status'] : $old_status;

        // Merge with existing data
        $merged = array_merge($existing, $sanitized);

        // Sync to BME tables
        $result = $this->bme_sync->sync_listing($listing_id, $merged, $listing_key);

        if (is_wp_error($result)) {
            return $this->error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                null,
                500
            );
        }

        // Archive if status changed to Closed
        if ($old_status !== 'Closed' && $new_status === 'Closed') {
            $this->bme_sync->archive_listing($listing_id);
        }

        // Get updated listing
        $listing = $this->get_listing_by_id($listing_id);

        return $this->success_response(array(
            'listing' => $this->format_listing_response($listing),
            'message' => 'Listing updated successfully',
        ));
    }

    /**
     * Handle delete exclusive listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_delete($request) {
        $listing_id = intval($request->get_param('id'));
        $archive = $request->get_param('archive') !== 'false'; // Default to archive

        // Verify listing exists
        if (!$this->bme_sync->listing_exists($listing_id)) {
            return $this->error_response('listing_not_found', 'Listing not found', null, 404);
        }

        if ($archive) {
            // Archive the listing
            $result = $this->bme_sync->archive_listing($listing_id);
        } else {
            // Permanently delete
            $this->image_handler->delete_all_photos($listing_id);
            $result = $this->bme_sync->delete_listing($listing_id);
        }

        if (is_wp_error($result)) {
            return $this->error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                null,
                500
            );
        }

        return $this->success_response(array(
            'message' => $archive ? 'Listing archived successfully' : 'Listing deleted successfully',
        ));
    }

    /**
     * Handle get photos for a listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_photos($request) {
        $listing_id = intval($request->get_param('id'));

        $raw_photos = $this->image_handler->get_photos($listing_id);
        $photos = $this->format_photos_array_for_ios($raw_photos);

        return $this->success_response(array(
            'photos' => $photos,
            'count' => count($photos),
        ));
    }

    /**
     * Handle upload photos
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_upload_photos($request) {
        $listing_id = intval($request->get_param('id'));

        // Verify listing exists
        if (!$this->bme_sync->listing_exists($listing_id)) {
            return $this->error_response('listing_not_found', 'Listing not found', null, 404);
        }

        // Get uploaded files
        $files = $request->get_file_params();

        if (empty($files)) {
            return $this->error_response('no_files', 'No files uploaded', null, 400);
        }

        // Handle multiple files
        $results = array();

        // Check if it's a single file or array of files
        if (isset($files['photos'])) {
            // Multiple files with same input name
            $photos = $files['photos'];

            if (is_array($photos['name'])) {
                // Multiple files
                for ($i = 0; $i < count($photos['name']); $i++) {
                    $file = array(
                        'name' => $photos['name'][$i],
                        'type' => $photos['type'][$i],
                        'tmp_name' => $photos['tmp_name'][$i],
                        'error' => $photos['error'][$i],
                        'size' => $photos['size'][$i],
                    );

                    $result = $this->image_handler->upload_photo($listing_id, $file);
                    $results[] = array(
                        'filename' => $file['name'],
                        'success' => !is_wp_error($result),
                        'data' => is_wp_error($result) ? null : $this->format_photo_for_ios($result, $i),
                        'error' => is_wp_error($result) ? $result->get_error_message() : null,
                    );
                }
            } else {
                // Single file
                $result = $this->image_handler->upload_photo($listing_id, $photos);
                $results[] = array(
                    'filename' => $photos['name'],
                    'success' => !is_wp_error($result),
                    'data' => is_wp_error($result) ? null : $this->format_photo_for_ios($result, 0),
                    'error' => is_wp_error($result) ? $result->get_error_message() : null,
                );
            }
        } elseif (isset($files['photo'])) {
            // Single file with 'photo' name
            $result = $this->image_handler->upload_photo($listing_id, $files['photo']);
            $results[] = array(
                'filename' => $files['photo']['name'],
                'success' => !is_wp_error($result),
                'data' => is_wp_error($result) ? null : $this->format_photo_for_ios($result, 0),
                'error' => is_wp_error($result) ? $result->get_error_message() : null,
            );
        }

        $successful = array_filter($results, function($r) { return $r['success']; });

        return $this->success_response(array(
            'results' => $results,
            'uploaded' => count($successful),
            'failed' => count($results) - count($successful),
        ));
    }

    /**
     * Handle delete single photo
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_delete_photo($request) {
        $listing_id = intval($request->get_param('id'));
        $photo_id = intval($request->get_param('photo_id'));

        $result = $this->image_handler->delete_photo($listing_id, $photo_id);

        if (is_wp_error($result)) {
            return $this->error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                null,
                404
            );
        }

        return $this->success_response(array(
            'message' => 'Photo deleted successfully',
        ));
    }

    /**
     * Handle reorder photos
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_reorder_photos($request) {
        $listing_id = intval($request->get_param('id'));
        $data = $request->get_json_params();

        if (empty($data['order']) || !is_array($data['order'])) {
            return $this->error_response(
                'invalid_order',
                'Order must be an array of photo IDs',
                null,
                400
            );
        }

        $result = $this->image_handler->reorder_photos($listing_id, $data['order']);

        if (is_wp_error($result)) {
            return $this->error_response(
                $result->get_error_code(),
                $result->get_error_message(),
                null,
                500
            );
        }

        // Get updated photos and format for iOS
        $photos = $this->image_handler->get_photos($listing_id);
        $formatted_photos = $this->format_photos_array_for_ios($photos);

        return $this->success_response(array(
            'photos' => $formatted_photos,
            'count' => count($formatted_photos),
            'message' => 'Photos reordered successfully',
        ));
    }

    /**
     * Handle get options (property types, statuses, etc.)
     *
     * @since 1.0.0
     * @since 1.4.0 Added all new field options for 32 new fields
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_options($request) {
        // Map sub-types to property types for iOS picker UI
        $property_sub_types_by_type = array(
            'Residential' => array('Single Family', 'Condo', 'Townhouse', 'Apartment', 'Mobile Home', 'Other'),
            'Commercial' => array('Commercial', 'Other'),
            'Land' => array('Land', 'Farm', 'Ranch', 'Other'),
            'Multi-Family' => array('Multi-Family', 'Other'),
            'Rental' => array('Single Family', 'Condo', 'Townhouse', 'Apartment', 'Other'),
        );

        return $this->success_response(array(
            // Basic options
            'property_types' => EL_Validator::get_property_types(),
            'property_sub_types' => $property_sub_types_by_type,
            'statuses' => EL_Validator::get_statuses(),
            'required_fields' => EL_Validator::get_required_fields(),

            // v1.5.0 - Custom badge/tag options
            'exclusive_tags' => EL_Validator::get_exclusive_tags(),

            // Upload limits
            'max_photos' => EL_Image_Handler::MAX_PHOTOS,
            'max_file_size_mb' => EL_Image_Handler::MAX_FILE_SIZE / 1048576,

            // v1.4.0 - Tier 1 Property Description
            'architectural_styles' => EL_Validator::get_architectural_styles(),

            // v1.4.0 - Tier 2 Interior Details
            'heating_types' => EL_Validator::get_heating_types(),
            'cooling_types' => EL_Validator::get_cooling_types(),
            'interior_features' => EL_Validator::get_interior_features(),
            'appliances' => EL_Validator::get_appliances(),
            'flooring_types' => EL_Validator::get_flooring_types(),
            'laundry_features' => EL_Validator::get_laundry_features(),
            'basement_types' => EL_Validator::get_basement_types(),

            // v1.4.0 - Tier 3 Exterior & Lot
            'construction_materials' => EL_Validator::get_construction_materials(),
            'roof_types' => EL_Validator::get_roof_types(),
            'foundation_types' => EL_Validator::get_foundation_types(),
            'exterior_features' => EL_Validator::get_exterior_features(),
            'waterfront_features' => EL_Validator::get_waterfront_features(),
            'view_types' => EL_Validator::get_view_types(),
            'parking_features' => EL_Validator::get_parking_features(),

            // v1.4.0 - Tier 4 Financial
            'association_fee_frequencies' => EL_Validator::get_association_fee_frequencies(),
            'association_fee_includes' => EL_Validator::get_association_fee_includes(),
        ));
    }

    /**
     * Check if user is an agent
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_agent_permission($request) {
        // Use MLD's authentication if available
        if (class_exists('MLD_Mobile_REST_API')) {
            $auth_result = MLD_Mobile_REST_API::check_auth($request);
            if (is_wp_error($auth_result)) {
                return $auth_result;
            }
        }

        if (!is_user_logged_in()) {
            return new WP_Error(
                'not_authenticated',
                'Authentication required',
                array('status' => 401)
            );
        }

        // Check if user can edit posts (basic capability)
        // In the future, we'll check for specific agent role
        if (!current_user_can('edit_posts')) {
            return new WP_Error(
                'not_authorized',
                'You do not have permission to manage exclusive listings',
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Check if user owns the listing
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_listing_ownership($request) {
        // First check basic agent permission
        $auth = $this->check_agent_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        // Admins can edit any listing
        if (current_user_can('manage_options')) {
            return true;
        }

        $listing_id = intval($request->get_param('id'));

        // For now, any agent can edit exclusive listings
        // In the future, we'll check created_by field
        if (!$this->bme_sync->listing_exists($listing_id)) {
            return new WP_Error(
                'listing_not_found',
                'Listing not found',
                array('status' => 404)
            );
        }

        return true;
    }

    /**
     * Get agent ID for a user
     *
     * @since 1.0.0
     * @param int $user_id WordPress user ID
     * @return int|null Agent ID or null
     */
    private function get_agent_id($user_id) {
        global $wpdb;

        // Check MLD agent profiles table
        $agent_table = $wpdb->prefix . 'mld_agent_profiles';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $agent_table
        ));

        if ($table_exists) {
            $agent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$agent_table} WHERE user_id = %d",
                $user_id
            ));

            return $agent_id ? intval($agent_id) : null;
        }

        return null;
    }

    /**
     * Get listing by ID from BME summary table
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return array|null Listing data or null
     */
    private function get_listing_by_id($listing_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
    }

    /**
     * Get listing details from BME details table
     *
     * @since 1.4.0
     * @param int $listing_id Listing ID
     * @return array|null Details data or empty array
     */
    private function get_listing_details($listing_id) {
        global $wpdb;

        // First check bme_listings for remarks and virtual tour
        $listings_table = $wpdb->prefix . 'bme_listings';
        $listings_data = $wpdb->get_row($wpdb->prepare(
            "SELECT original_list_price, public_remarks, private_remarks, showing_instructions, virtual_tour_url_unbranded
             FROM {$listings_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        // Then get details table data
        // Note: interior_features, appliances, parking_features, parking_total are in listing_details, NOT listing_features
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $details_data = $wpdb->get_row($wpdb->prepare(
            "SELECT architectural_style, stories_total, heating, cooling, heating_yn, cooling_yn,
                    flooring, laundry_features, basement, construction_materials, roof, foundation_details,
                    interior_features, appliances, parking_features, parking_total
             FROM {$details_table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        // Merge both arrays
        return array_merge($listings_data ?: array(), $details_data ?: array());
    }

    /**
     * Get listing features from BME features table
     *
     * @since 1.4.0
     * @param int $listing_id Listing ID
     * @return array|null Features data or empty array
     */
    private function get_listing_features($listing_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_features';

        // Note: interior_features, appliances, parking_features, parking_total are in listing_details, NOT here
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT exterior_features, waterfront_yn, waterfront_features, view_yn, view
             FROM {$table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        return $result ?: array();
    }

    /**
     * Get listing financial data from BME financial table
     *
     * @since 1.4.0
     * @param int $listing_id Listing ID
     * @return array|null Financial data or empty array
     */
    private function get_listing_financial($listing_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_financial';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_annual_amount, tax_year, association_yn, association_fee,
                    association_fee_frequency, association_fee_includes
             FROM {$table} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        return $result ?: array();
    }

    /**
     * Format listing data for API response
     *
     * @since 1.0.0
     * @since 1.4.0 Added 32 new property detail fields
     * @param array $listing Raw listing data
     * @return array Formatted listing data
     */
    private function format_listing_response($listing) {
        // Get extended data from other BME tables for full details
        $details = $this->get_listing_details($listing['listing_id']);
        $features = $this->get_listing_features($listing['listing_id']);
        $financial = $this->get_listing_financial($listing['listing_id']);

        return array(
            'id' => intval($listing['listing_id']),
            'listing_id' => intval($listing['listing_id']),
            'listing_key' => $listing['listing_key'],
            'mls_id' => $listing['mls_id'],
            'is_exclusive' => true,
            // v1.5.0 - Custom badge text (Coming Soon, Off-Market, etc.)
            'exclusive_tag' => !empty($listing['exclusive_tag']) ? $listing['exclusive_tag'] : 'Exclusive',

            // Status
            'status' => $listing['standard_status'],
            'standard_status' => $listing['standard_status'],

            // Price
            'list_price' => floatval($listing['list_price']),
            'price_per_sqft' => isset($listing['price_per_sqft']) ? floatval($listing['price_per_sqft']) : null,
            // Tier 1 - Original price
            'original_list_price' => isset($details['original_list_price']) ? floatval($details['original_list_price']) : null,

            // Property type
            'property_type' => $listing['property_type'] ?? null,
            'property_sub_type' => $listing['property_sub_type'] ?? null,

            // Address
            'street_number' => $listing['street_number'] ?? null,
            'street_name' => $listing['street_name'] ?? null,
            'unit_number' => $listing['unit_number'] ?? null,
            'city' => $listing['city'] ?? null,
            'state' => $listing['state_or_province'] ?? null,
            'state_or_province' => $listing['state_or_province'] ?? null,
            'postal_code' => $listing['postal_code'] ?? null,
            'county' => $listing['county'] ?? null,
            'subdivision_name' => $listing['subdivision_name'] ?? null,
            'unparsed_address' => $listing['unparsed_address'] ?? null,

            // Coordinates
            'latitude' => $listing['latitude'] ? floatval($listing['latitude']) : null,
            'longitude' => $listing['longitude'] ? floatval($listing['longitude']) : null,

            // Property details
            'bedrooms_total' => $listing['bedrooms_total'] !== null ? intval($listing['bedrooms_total']) : null,
            'bathrooms_total' => $listing['bathrooms_total'] !== null ? floatval($listing['bathrooms_total']) : null,
            'bathrooms_full' => isset($listing['bathrooms_full']) ? intval($listing['bathrooms_full']) : null,
            'bathrooms_half' => isset($listing['bathrooms_half']) ? intval($listing['bathrooms_half']) : null,
            'building_area_total' => $listing['building_area_total'] !== null ? intval($listing['building_area_total']) : null,
            // v1.5.0: lot_size in sq ft for MLS API parity, plus lot_size_acres
            // v1.5.2: Also return lot_size_square_feet for iOS compatibility
            'lot_size' => $listing['lot_size_acres'] !== null ? round(floatval($listing['lot_size_acres']) * 43560) : null,
            'lot_size_square_feet' => $listing['lot_size_acres'] !== null ? intval(round(floatval($listing['lot_size_acres']) * 43560)) : null,
            'lot_size_acres' => $listing['lot_size_acres'] !== null ? floatval($listing['lot_size_acres']) : null,
            'year_built' => $listing['year_built'] !== null ? intval($listing['year_built']) : null,
            'garage_spaces' => isset($listing['garage_spaces']) ? intval($listing['garage_spaces']) : 0,

            // Tier 1 - Property Description
            'architectural_style' => isset($details['architectural_style']) ? $details['architectural_style'] : null,
            'stories_total' => isset($details['stories_total']) ? intval($details['stories_total']) : null,
            'virtual_tour_url' => isset($details['virtual_tour_url_unbranded']) ? $details['virtual_tour_url_unbranded'] : null,
            'public_remarks' => isset($details['public_remarks']) ? $details['public_remarks'] : null,
            'private_remarks' => isset($details['private_remarks']) ? $details['private_remarks'] : null,
            'showing_instructions' => isset($details['showing_instructions']) ? $details['showing_instructions'] : null,

            // Tier 2 - Interior Details
            'heating' => isset($details['heating']) ? $details['heating'] : null,
            'cooling' => isset($details['cooling']) ? $details['cooling'] : null,
            'heating_yn' => isset($details['heating_yn']) ? (bool) $details['heating_yn'] : false,
            'cooling_yn' => isset($details['cooling_yn']) ? (bool) $details['cooling_yn'] : false,
            'interior_features' => isset($details['interior_features']) ? $details['interior_features'] : null,
            'appliances' => isset($details['appliances']) ? $details['appliances'] : null,
            'flooring' => isset($details['flooring']) ? $details['flooring'] : null,
            'laundry_features' => isset($details['laundry_features']) ? $details['laundry_features'] : null,
            'basement' => isset($details['basement']) ? $details['basement'] : null,

            // Tier 3 - Exterior & Lot
            'construction_materials' => isset($details['construction_materials']) ? $details['construction_materials'] : null,
            'roof' => isset($details['roof']) ? $details['roof'] : null,
            'foundation_details' => isset($details['foundation_details']) ? $details['foundation_details'] : null,
            'exterior_features' => isset($features['exterior_features']) ? $features['exterior_features'] : null,
            'waterfront_yn' => isset($features['waterfront_yn']) ? (bool) $features['waterfront_yn'] : false,
            'waterfront_features' => isset($features['waterfront_features']) ? $features['waterfront_features'] : null,
            'view_yn' => isset($features['view_yn']) ? (bool) $features['view_yn'] : false,
            'view' => isset($features['view']) ? $features['view'] : null,
            'parking_features' => isset($details['parking_features']) ? $details['parking_features'] : null,
            'parking_total' => isset($details['parking_total']) ? intval($details['parking_total']) : null,

            // Tier 4 - Financial
            'tax_annual_amount' => isset($financial['tax_annual_amount']) ? floatval($financial['tax_annual_amount']) : null,
            'tax_year' => isset($financial['tax_year']) ? intval($financial['tax_year']) : null,
            'association_yn' => isset($financial['association_yn']) ? (bool) $financial['association_yn'] : false,
            'association_fee' => isset($financial['association_fee']) ? floatval($financial['association_fee']) : null,
            'association_fee_frequency' => isset($financial['association_fee_frequency']) ? $financial['association_fee_frequency'] : null,
            'association_fee_includes' => isset($financial['association_fee_includes']) ? $financial['association_fee_includes'] : null,

            // Features (legacy boolean flags)
            'has_pool' => isset($listing['has_pool']) ? (bool) $listing['has_pool'] : false,
            'has_fireplace' => isset($listing['has_fireplace']) ? (bool) $listing['has_fireplace'] : false,
            'has_basement' => isset($listing['has_basement']) ? (bool) $listing['has_basement'] : false,
            'has_hoa' => isset($listing['has_hoa']) ? (bool) $listing['has_hoa'] : false,

            // Media
            'main_photo_url' => $listing['main_photo_url'],
            'photo_count' => intval($listing['photo_count']),

            // Dates
            'listing_contract_date' => $listing['listing_contract_date'],
            'days_on_market' => intval($listing['days_on_market']),
            'modification_timestamp' => $listing['modification_timestamp'],

            // URL for property page
            'url' => home_url('/property/' . $listing['listing_id'] . '/'),
        );
    }

    /**
     * Return success response with cache bypass headers
     *
     * @since 1.0.0
     * @param array $data Response data
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    private function success_response($data, $status = 200) {
        $response = new WP_REST_Response(array(
            'success' => true,
            'data' => $data,
        ), $status);

        // Prevent CDN caching of authenticated responses
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * Return error response
     *
     * @since 1.0.0
     * @param string $code Error code
     * @param string $message Error message
     * @param mixed $details Additional error details
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    private function error_response($code, $message, $details = null, $status = 400) {
        $data = array(
            'success' => false,
            'error' => array(
                'code' => $code,
                'message' => $message,
            ),
        );

        if ($details !== null) {
            $data['error']['details'] = $details;
        }

        $response = new WP_REST_Response($data, $status);

        // Prevent CDN caching of error responses
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * Format photo data for iOS response
     *
     * Transforms upload_photo result to match iOS ExclusiveListingPhoto model.
     *
     * @since 1.0.1
     * @param array $result Result from upload_photo
     * @param int $index Photo index (0-based)
     * @return array Formatted photo data
     */
    private function format_photo_for_ios($result, $index = 0) {
        return array(
            'id' => intval($result['id']),
            'url' => $result['url'],
            'sort_order' => intval($result['order']),
            'is_primary' => $index === 0,  // First photo is primary
        );
    }

    /**
     * Format array of photos from database for iOS response
     *
     * Transforms bme_media table rows to match iOS ExclusiveListingPhoto model.
     *
     * @since 1.0.1
     * @param array $photos Array of photo rows from database
     * @return array Formatted photos array
     */
    private function format_photos_array_for_ios($photos) {
        $formatted = array();
        foreach ($photos as $index => $photo) {
            // bme_media uses 'order_index' column, fallback to array index
            $order = $photo['order_index'] ?? ($index + 1);
            $formatted[] = array(
                'id' => intval($photo['id']),
                'url' => $photo['media_url'],
                'sort_order' => intval($order),
                'is_primary' => $index === 0,  // First photo (lowest order) is primary
            );
        }
        return $formatted;
    }
}
