<?php
/**
 * Tool Executor for AI Function Calling
 *
 * Executes tool calls from AI providers and returns formatted results.
 * Connects AI function calls to MLD_Unified_Data_Provider methods.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.10.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Tool_Executor {

    /**
     * Data provider instance
     *
     * @var MLD_Unified_Data_Provider
     */
    private $data_provider;

    /**
     * Tool registry instance
     *
     * @var MLD_Tool_Registry
     */
    private $registry;

    /**
     * Conversation context manager
     *
     * @var MLD_Conversation_Context|null
     * @since 6.14.0
     */
    private $conversation_context = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Load dependencies
        if (!class_exists('MLD_Unified_Data_Provider')) {
            require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-unified-data-provider.php';
        }
        if (!class_exists('MLD_Tool_Registry')) {
            require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-tool-registry.php';
        }

        $this->data_provider = new MLD_Unified_Data_Provider();
        $this->registry = mld_get_tool_registry();
    }

    /**
     * Set conversation context manager
     *
     * @param MLD_Conversation_Context|null $context
     * @since 6.14.0
     */
    public function set_context($context) {
        $this->conversation_context = $context;
    }

    /**
     * Get conversation context manager
     *
     * @return MLD_Conversation_Context|null
     * @since 6.14.0
     */
    public function get_context() {
        return $this->conversation_context;
    }

    /**
     * Execute a tool call
     *
     * @param string $tool_name Name of the tool to execute
     * @param array $arguments Arguments passed to the tool
     * @return array Result with 'success', 'data', and optional 'error'
     */
    public function execute($tool_name, $arguments) {
        // Validate tool exists
        if (!$this->registry->has_tool($tool_name)) {
            return $this->format_error("Unknown tool: {$tool_name}");
        }

        // Get handler method
        $handler = $this->registry->get_handler($tool_name);
        if (!$handler || !method_exists($this, $handler)) {
            return $this->format_error("No handler for tool: {$tool_name}");
        }

        try {
            // Execute the handler
            $result = $this->$handler($arguments);
            return $result;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Tool Executor] Error executing {$tool_name}: " . $e->getMessage());
            }
            return $this->format_error("Error executing tool: " . $e->getMessage());
        }
    }

    /**
     * Execute multiple tool calls
     *
     * @param array $tool_calls Array of tool calls from AI
     * @return array Results keyed by tool_call_id
     */
    public function execute_multiple($tool_calls) {
        $results = array();

        foreach ($tool_calls as $call) {
            $tool_name = $call['function']['name'];
            $arguments = json_decode($call['function']['arguments'], true);
            $call_id = $call['id'];

            $results[$call_id] = array(
                'tool_call_id' => $call_id,
                'name' => $tool_name,
                'result' => $this->execute($tool_name, $arguments ?: array()),
            );
        }

        return $results;
    }

    /**
     * Handler: Search Properties
     *
     * Enhanced in v6.14.0 to:
     * - Merge new args with stored search criteria (context persistence)
     * - Record shown properties for reference resolution
     * - Save context after each search
     *
     * @param array $args Search arguments
     * @return array Formatted result
     */
    protected function handle_search_properties($args) {
        // Get existing search criteria from context (v6.14.0)
        $existing_criteria = array();
        if ($this->conversation_context) {
            $existing_criteria = $this->conversation_context->get_search_criteria();
        }

        // Build criteria from arguments, merging with existing context
        $criteria = array();

        // Location: new value replaces, but city from context is kept if not specified
        if (!empty($args['city'])) {
            $criteria['city'] = sanitize_text_field($args['city']);
        } elseif (!empty($existing_criteria['city'])) {
            $criteria['city'] = $existing_criteria['city'];
        }

        // Neighborhood support (v6.14.0)
        if (!empty($args['neighborhood'])) {
            $criteria['neighborhood'] = sanitize_text_field($args['neighborhood']);
        } elseif (!empty($existing_criteria['neighborhood'])) {
            $criteria['neighborhood'] = $existing_criteria['neighborhood'];
        }

        // Price range: new values replace, but keep context if not specified
        if (!empty($args['min_price'])) {
            $criteria['min_price'] = absint($args['min_price']);
        } elseif (!empty($existing_criteria['min_price'])) {
            $criteria['min_price'] = $existing_criteria['min_price'];
        }

        if (!empty($args['max_price'])) {
            $criteria['max_price'] = absint($args['max_price']);
        } elseif (!empty($existing_criteria['max_price'])) {
            $criteria['max_price'] = $existing_criteria['max_price'];
        }

        // Bedrooms: new value replaces, keep context if not specified
        if (!empty($args['min_bedrooms'])) {
            $criteria['min_bedrooms'] = absint($args['min_bedrooms']);
        } elseif (!empty($existing_criteria['min_bedrooms'])) {
            $criteria['min_bedrooms'] = $existing_criteria['min_bedrooms'];
        }

        if (!empty($args['min_bathrooms'])) {
            $criteria['min_bathrooms'] = floatval($args['min_bathrooms']);
        } elseif (!empty($existing_criteria['min_bathrooms'])) {
            $criteria['min_bathrooms'] = $existing_criteria['min_bathrooms'];
        }

        if (!empty($args['property_type'])) {
            $criteria['property_type'] = sanitize_text_field($args['property_type']);
        } elseif (!empty($existing_criteria['property_type'])) {
            $criteria['property_type'] = $existing_criteria['property_type'];
        }

        if (!empty($args['min_sqft'])) {
            $criteria['min_sqft'] = absint($args['min_sqft']);
        }
        if (!empty($args['max_sqft'])) {
            $criteria['max_sqft'] = absint($args['max_sqft']);
        }
        if (!empty($args['sort_by'])) {
            $criteria['sort_by'] = sanitize_text_field($args['sort_by']);
        }
        if (!empty($args['sort_order'])) {
            $criteria['sort_order'] = sanitize_text_field($args['sort_order']);
        }

        // Limit results for chatbot (max 10)
        $criteria['limit'] = min(absint($args['limit'] ?? 5), 10);

        // Execute search
        $properties = $this->data_provider->getPropertyData($criteria);

        if (empty($properties)) {
            // Still save criteria even if no results (user might refine)
            if ($this->conversation_context) {
                $this->conversation_context->update_search_criteria($criteria);
                $this->conversation_context->save();
            }

            return $this->format_success(array(
                'count' => 0,
                'properties' => array(),
                'message' => 'No properties found matching your criteria.',
                'criteria_used' => $criteria,
            ));
        }

        // Format properties for AI consumption with numbered references (v6.14.0)
        $formatted = array();
        $shown_for_context = array();
        $index = 1;

        foreach ($properties as $property) {
            $summary = $this->format_property_summary($property);
            $summary['reference_number'] = $index; // Add reference number for "show me #5"
            $formatted[] = $summary;

            // Store for context with essential data for follow-up questions (v6.14.0)
            // v6.27.6: Added property_url for clickable links
            $shown_for_context[] = array(
                'index' => $index,
                'listing_id' => $property['listing_id'],
                'address' => $summary['address'],
                'price' => $summary['price'],
                'bedrooms' => $summary['bedrooms'] ?? $property['bedrooms_total'] ?? null,
                'bathrooms' => $summary['bathrooms'] ?? $property['bathrooms_total'] ?? null,
                'sqft' => $summary['sqft'] ?? $property['living_area'] ?? null,
                'property_type' => $property['property_sub_type'] ?? $property['property_type'] ?? null,
                'property_url' => $summary['property_url'] ?? $this->get_property_url($property['listing_id']),
            );

            $index++;
        }

        // Update context with search criteria and shown properties (v6.14.0)
        if ($this->conversation_context) {
            $this->conversation_context->update_search_criteria($criteria);
            $this->conversation_context->record_shown_properties($shown_for_context);
            $this->conversation_context->save();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Tool Executor 6.14.0] Updated search context: ' . json_encode($criteria));
                error_log('[MLD Tool Executor 6.14.0] Recorded ' . count($shown_for_context) . ' shown properties');
            }
        }

        return $this->format_success(array(
            'count' => count($formatted),
            'properties' => $formatted,
            'criteria_used' => $criteria,
        ));
    }

    /**
     * Handler: Get Market Stats
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_get_market_stats($args) {
        $stat_type = sanitize_text_field($args['stat_type'] ?? 'total_active');

        $filters = array();
        if (!empty($args['city'])) {
            $filters['city'] = sanitize_text_field($args['city']);
        }
        if (!empty($args['property_type'])) {
            $filters['property_type'] = sanitize_text_field($args['property_type']);
        }

        $result = $this->data_provider->getQuickStat($stat_type, $filters);

        if ($result === null) {
            return $this->format_error("Unable to retrieve statistic: {$stat_type}");
        }

        // Format based on stat type
        $formatted = array(
            'stat_type' => $stat_type,
            'filters' => $filters,
        );

        switch ($stat_type) {
            case 'total_active':
                $formatted['total_listings'] = intval($result);
                $formatted['description'] = "There are {$result} active listings";
                break;

            case 'average_price':
                $formatted['average_price'] = floatval($result);
                $formatted['formatted_price'] = '$' . number_format($result);
                $formatted['description'] = "The average listing price is $" . number_format($result);
                break;

            case 'median_price':
                $formatted['median_price'] = floatval($result);
                $formatted['formatted_price'] = '$' . number_format($result);
                $formatted['description'] = "The median listing price is $" . number_format($result);
                break;

            case 'price_range':
                $formatted['min_price'] = floatval($result['min_price']);
                $formatted['max_price'] = floatval($result['max_price']);
                $formatted['description'] = "Prices range from $" . number_format($result['min_price']) .
                    " to $" . number_format($result['max_price']);
                break;

            case 'inventory_by_type':
            case 'inventory_by_city':
                $formatted['breakdown'] = $result;
                break;

            case 'new_listings_today':
                $formatted['new_today'] = intval($result);
                $formatted['description'] = "{$result} new listings were added today";
                break;

            case 'price_reduced_today':
                $formatted['reduced_today'] = intval($result);
                $formatted['description'] = "{$result} listings had price reductions today";
                break;

            default:
                $formatted['value'] = $result;
        }

        return $this->format_success($formatted);
    }

    /**
     * Handler: Get Property Details
     *
     * Enhanced in v6.14.0 to:
     * - Load comprehensive property data (450+ columns)
     * - Store full data in context for follow-up questions
     * - Return formatted summary while retaining full data
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_get_property_details($args) {
        if (empty($args['listing_id'])) {
            return $this->format_error('listing_id is required');
        }

        $listing_id = sanitize_text_field($args['listing_id']);

        // Try to get comprehensive data first (v6.14.0)
        $comprehensive_data = null;
        if (method_exists($this->data_provider, 'getComprehensivePropertyData')) {
            $comprehensive_data = $this->data_provider->getComprehensivePropertyData($listing_id);
        }

        if ($comprehensive_data) {
            // Store comprehensive data in context for follow-up questions
            if ($this->conversation_context) {
                $this->conversation_context->set_active_property($listing_id, $comprehensive_data);
                $this->conversation_context->save();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Tool Executor 6.14.0] Stored comprehensive property data for ' . $listing_id);
                }
            }

            // Format comprehensive response
            return $this->format_success(array(
                'property' => $this->format_comprehensive_property($comprehensive_data),
                'has_full_data' => true,
                'data_categories_available' => array(
                    'hvac' => 'Heating, cooling, utilities',
                    'rooms' => 'Room dimensions and features',
                    'financial' => 'Taxes, HOA, assessments',
                    'features' => 'Interior/exterior features',
                    'history' => 'Price changes, status changes',
                ),
            ));
        }

        // Fallback to standard property retrieval
        $criteria = array(
            'listing_id' => $listing_id,
            'include_details' => true,
            'limit' => 1,
        );

        $properties = $this->data_provider->getPropertyData($criteria);

        if (empty($properties)) {
            return $this->format_error("Property not found: {$listing_id}");
        }

        $property = $properties[0];

        // Optionally include photos
        if (!empty($args['include_photos'])) {
            $property['photos'] = $this->data_provider->getPropertyMedia($listing_id);
        }

        // Optionally include schools
        if (!empty($args['include_schools'])) {
            $property['schools'] = $this->data_provider->getPropertySchools($listing_id);
        }

        return $this->format_success(array(
            'property' => $this->format_property_detail($property),
        ));
    }

    /**
     * Handler: Get Neighborhood Info
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_get_neighborhood_info($args) {
        if (empty($args['neighborhood'])) {
            return $this->format_error('neighborhood is required');
        }

        $neighborhood = sanitize_text_field($args['neighborhood']);
        $stats = $this->data_provider->getNeighborhoodStats($neighborhood);

        if (empty($stats) || empty($stats['total_listings'])) {
            return $this->format_success(array(
                'neighborhood' => $neighborhood,
                'message' => "No data available for {$neighborhood}. Try a different neighborhood or city name.",
            ));
        }

        return $this->format_success(array(
            'neighborhood' => $neighborhood,
            'total_listings' => intval($stats['total_listings']),
            'average_price' => '$' . number_format($stats['avg_price']),
            'price_range' => '$' . number_format($stats['min_price']) . ' - $' . number_format($stats['max_price']),
            'average_bedrooms' => round(floatval($stats['avg_bedrooms']), 1),
            'average_bathrooms' => round(floatval($stats['avg_bathrooms']), 1),
            'average_sqft' => number_format(round($stats['avg_sqft'])),
        ));
    }

    /**
     * Handler: Get Price Trends
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_get_price_trends($args) {
        $criteria = array();

        if (!empty($args['city'])) {
            $criteria['city'] = sanitize_text_field($args['city']);
        }
        if (!empty($args['property_type'])) {
            $criteria['property_type'] = sanitize_text_field($args['property_type']);
        }

        $timeframe = sanitize_text_field($args['timeframe'] ?? '90d');

        $trends = $this->data_provider->getPriceTrends($criteria, $timeframe);

        if (empty($trends)) {
            return $this->format_success(array(
                'timeframe' => $timeframe,
                'criteria' => $criteria,
                'message' => 'No price trend data available for this criteria.',
                'trends' => array(),
            ));
        }

        // Format trends for readability
        $formatted_trends = array();
        foreach ($trends as $trend) {
            $formatted_trends[] = array(
                'month' => $trend['month'],
                'average_price' => '$' . number_format($trend['avg_price']),
                'change_percent' => round(floatval($trend['avg_change_percent']), 1) . '%',
                'price_changes' => intval($trend['change_count']),
            );
        }

        return $this->format_success(array(
            'timeframe' => $timeframe,
            'criteria' => $criteria,
            'trends' => $formatted_trends,
        ));
    }

    /**
     * Handler: Find Similar Properties
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_find_similar_properties($args) {
        if (empty($args['listing_id'])) {
            return $this->format_error('listing_id is required');
        }

        $listing_id = sanitize_text_field($args['listing_id']);
        $count = min(absint($args['count'] ?? 3), 6);

        // First get the subject property
        $criteria = array('listing_id' => $listing_id, 'limit' => 1);
        $subject_properties = $this->data_provider->getPropertyData($criteria);

        if (empty($subject_properties)) {
            return $this->format_error("Subject property not found: {$listing_id}");
        }

        $subject = $subject_properties[0];

        // Find comparables
        $comparables = $this->data_provider->getCMAComparables($subject, $count);

        if (empty($comparables)) {
            return $this->format_success(array(
                'subject_property' => $this->format_property_summary($subject),
                'similar_count' => 0,
                'similar_properties' => array(),
                'message' => 'No similar properties found in the area.',
            ));
        }

        // Format comparables
        $formatted = array();
        foreach ($comparables as $comp) {
            $summary = $this->format_property_summary($comp);
            $summary['similarity_score'] = round(floatval($comp['similarity_score'])) . '%';
            $summary['distance_miles'] = round(floatval($comp['distance_miles']), 1);
            $formatted[] = $summary;
        }

        return $this->format_success(array(
            'subject_property' => $this->format_property_summary($subject),
            'similar_count' => count($formatted),
            'similar_properties' => $formatted,
        ));
    }

    /**
     * Handler: Text Search
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_text_search($args) {
        if (empty($args['query'])) {
            return $this->format_error('query is required');
        }

        $query = sanitize_text_field($args['query']);
        $limit = min(absint($args['limit'] ?? 5), 10);

        $results = $this->data_provider->searchProperties($query, $limit);

        if (empty($results)) {
            return $this->format_success(array(
                'query' => $query,
                'count' => 0,
                'properties' => array(),
                'message' => "No properties found matching '{$query}'.",
            ));
        }

        // Format results
        $formatted = array();
        foreach ($results as $property) {
            $formatted[] = $this->format_property_summary($property);
        }

        return $this->format_success(array(
            'query' => $query,
            'count' => count($formatted),
            'properties' => $formatted,
        ));
    }

    /**
     * Handler: Schedule Tour
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_schedule_tour($args) {
        // Validate required fields
        $required = array('listing_id', 'name', 'email', 'phone');
        foreach ($required as $field) {
            if (empty($args[$field])) {
                return $this->format_error("{$field} is required to schedule a tour");
            }
        }

        // Sanitize inputs
        $listing_id = sanitize_text_field($args['listing_id']);
        $name = sanitize_text_field($args['name']);
        $email = sanitize_email($args['email']);
        $phone = sanitize_text_field($args['phone']);
        $preferred_date = sanitize_text_field($args['preferred_date'] ?? '');
        $preferred_time = sanitize_text_field($args['preferred_time'] ?? '');
        $message = sanitize_textarea_field($args['message'] ?? '');

        // Validate email
        if (!is_email($email)) {
            return $this->format_error('Please provide a valid email address');
        }

        // Get property details for the email
        $property_info = $this->get_property_for_submission($listing_id);
        if (!$property_info) {
            return $this->format_error("Property not found: {$listing_id}");
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_form_submissions';

        // Split name into first and last
        $name_parts = explode(' ', $name, 2);
        $first_name = $name_parts[0];
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

        // Build message with tour details
        $full_message = '';
        if (!empty($preferred_date)) {
            $full_message .= "Preferred Date: {$preferred_date}\n";
        }
        if (!empty($preferred_time)) {
            $full_message .= "Preferred Time: {$preferred_time}\n";
        }
        if (!empty($message)) {
            $full_message .= "\n{$message}";
        }

        // Insert into database using existing table schema
        $result = $wpdb->insert(
            $table_name,
            array(
                'form_type' => 'tour',
                'property_mls' => $listing_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'message' => trim($full_message),
                'status' => 'new',
                'source' => 'ai_chatbot',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Tool Executor] Failed to save tour request: ' . $wpdb->last_error);
            }
            return $this->format_error('Failed to save tour request. Please try again.');
        }

        // Send notification email with original form data
        $email_data = array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'preferred_date' => $preferred_date,
            'preferred_time' => $preferred_time,
            'message' => $message,
        );
        $this->send_tour_notification_email($email_data, $listing_id, $property_info);

        return $this->format_success(array(
            'submission_id' => $wpdb->insert_id,
            'listing_id' => $listing_id,
            'property_address' => $property_info['address'],
            'message' => "Tour request submitted successfully! We'll contact {$name} at {$email} or {$phone} to confirm the showing" .
                ($preferred_date ? " for {$preferred_date}" : "") .
                ($preferred_time ? " ({$preferred_time})" : "") . ".",
            'next_steps' => 'The listing agent will review your request and contact you to confirm the tour time.',
        ));
    }

    /**
     * Handler: Contact Agent
     *
     * @param array $args Arguments
     * @return array Formatted result
     */
    protected function handle_contact_agent($args) {
        // Validate required fields
        $required = array('name', 'email', 'message');
        foreach ($required as $field) {
            if (empty($args[$field])) {
                return $this->format_error("{$field} is required to contact an agent");
            }
        }

        // Sanitize inputs
        $listing_id = sanitize_text_field($args['listing_id'] ?? '');
        $name = sanitize_text_field($args['name']);
        $email = sanitize_email($args['email']);
        $phone = sanitize_text_field($args['phone'] ?? '');
        $message = sanitize_textarea_field($args['message']);
        $inquiry_type = sanitize_text_field($args['inquiry_type'] ?? 'general');

        // Validate email
        if (!is_email($email)) {
            return $this->format_error('Please provide a valid email address');
        }

        // Get property details if listing_id provided
        $property_info = null;
        if (!empty($listing_id)) {
            $property_info = $this->get_property_for_submission($listing_id);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_form_submissions';

        // Split name into first and last
        $name_parts = explode(' ', $name, 2);
        $first_name = $name_parts[0];
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

        // Build message with inquiry type prefix
        $full_message = $message;
        if ($inquiry_type !== 'general') {
            $inquiry_labels = array(
                'property_question' => 'Property Question',
                'buying' => 'Buying Inquiry',
                'selling' => 'Selling Inquiry',
                'rental' => 'Rental Inquiry',
            );
            $label = isset($inquiry_labels[$inquiry_type]) ? $inquiry_labels[$inquiry_type] : ucfirst($inquiry_type);
            $full_message = "[{$label}]\n\n{$message}";
        }

        // Insert into database using existing table schema
        $result = $wpdb->insert(
            $table_name,
            array(
                'form_type' => 'contact',
                'property_mls' => $listing_id ?: null,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone ?: null,
                'message' => $full_message,
                'status' => 'new',
                'source' => 'ai_chatbot',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Tool Executor] Failed to save contact request: ' . $wpdb->last_error);
            }
            return $this->format_error('Failed to send message. Please try again.');
        }

        // Send notification email with original form data
        $email_data = array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'inquiry_type' => $inquiry_type,
        );
        $this->send_contact_notification_email($email_data, $listing_id, $property_info);

        $response_message = "Message sent successfully! An agent will respond to {$name} at {$email}";
        if ($phone) {
            $response_message .= " or {$phone}";
        }
        $response_message .= " soon.";

        return $this->format_success(array(
            'submission_id' => $wpdb->insert_id,
            'listing_id' => $listing_id,
            'inquiry_type' => $inquiry_type,
            'message' => $response_message,
            'next_steps' => 'An agent will review your message and respond within 1-2 business days.',
        ));
    }

    /**
     * Get property info for form submissions
     *
     * @param string $listing_id MLS listing ID
     * @return array|null Property info or null if not found
     */
    protected function get_property_for_submission($listing_id) {
        $criteria = array('listing_id' => $listing_id, 'limit' => 1);
        $properties = $this->data_provider->getPropertyData($criteria);

        if (empty($properties)) {
            return null;
        }

        $property = $properties[0];
        $summary = $this->format_property_summary($property);

        return array(
            'address' => $summary['address'],
            'price' => $summary['price'],
            'bedrooms' => $summary['bedrooms'],
            'bathrooms' => $summary['bathrooms'],
            'sqft' => $summary['sqft'],
            'listing_id' => $listing_id,
        );
    }

    /**
     * Send tour notification email
     *
     * @param array $form_data Form data
     * @param string $listing_id Listing ID
     * @param array $property_info Property information
     */
    protected function send_tour_notification_email($form_data, $listing_id, $property_info) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = "[{$site_name}] New Tour Request - {$property_info['address']}";

        $message = "A new tour request has been submitted:\n\n";
        $message .= "=== CONTACT INFORMATION ===\n";
        $message .= "Name: {$form_data['name']}\n";
        $message .= "Email: {$form_data['email']}\n";
        $message .= "Phone: {$form_data['phone']}\n\n";

        $message .= "=== TOUR DETAILS ===\n";
        if (!empty($form_data['preferred_date'])) {
            $message .= "Preferred Date: {$form_data['preferred_date']}\n";
        }
        if (!empty($form_data['preferred_time'])) {
            $message .= "Preferred Time: {$form_data['preferred_time']}\n";
        }
        if (!empty($form_data['message'])) {
            $message .= "Message: {$form_data['message']}\n";
        }
        $message .= "\n";

        $message .= "=== PROPERTY DETAILS ===\n";
        $message .= "MLS #: {$listing_id}\n";
        $message .= "Address: {$property_info['address']}\n";
        $message .= "Price: {$property_info['price']}\n\n";

        $message .= "Please respond to this request promptly.\n";
        $message .= "Source: AI Chatbot";

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Send contact notification email
     *
     * @param array $form_data Form data
     * @param string $listing_id Listing ID (optional)
     * @param array|null $property_info Property information (optional)
     */
    protected function send_contact_notification_email($form_data, $listing_id, $property_info) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $inquiry_labels = array(
            'general' => 'General Inquiry',
            'property_question' => 'Property Question',
            'buying' => 'Buying Inquiry',
            'selling' => 'Selling Inquiry',
            'rental' => 'Rental Inquiry',
        );
        $inquiry_label = $inquiry_labels[$form_data['inquiry_type']] ?? 'General Inquiry';

        $subject = "[{$site_name}] {$inquiry_label}";
        if ($property_info) {
            $subject .= " - {$property_info['address']}";
        }

        $message = "A new contact request has been submitted:\n\n";
        $message .= "=== CONTACT INFORMATION ===\n";
        $message .= "Name: {$form_data['name']}\n";
        $message .= "Email: {$form_data['email']}\n";
        if (!empty($form_data['phone'])) {
            $message .= "Phone: {$form_data['phone']}\n";
        }
        $message .= "Inquiry Type: {$inquiry_label}\n\n";

        $message .= "=== MESSAGE ===\n";
        $message .= "{$form_data['message']}\n\n";

        if ($property_info) {
            $message .= "=== PROPERTY DETAILS ===\n";
            $message .= "MLS #: {$listing_id}\n";
            $message .= "Address: {$property_info['address']}\n";
            $message .= "Price: {$property_info['price']}\n\n";
        }

        $message .= "Please respond to this inquiry promptly.\n";
        $message .= "Source: AI Chatbot";

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Format property for summary display
     *
     * Enhanced in v6.27.6 to include property_url for clickable links
     *
     * @param array $property Raw property data
     * @return array Formatted summary
     */
    protected function format_property_summary($property) {
        // Handle column name differences between main listings and summary tables
        $sqft = intval($property['living_area'] ?? $property['building_area_total'] ?? 0);
        $street = $property['street_address'] ?? '';
        if (empty($street) && !empty($property['street_number']) && !empty($property['street_name'])) {
            $street = trim($property['street_number'] . ' ' . $property['street_name']);
        }

        $listing_id = $property['listing_id'];

        return array(
            'listing_id' => $listing_id,
            'address' => trim(sprintf(
                '%s, %s, %s %s',
                $street,
                $property['city'] ?? '',
                $property['state_or_province'] ?? $property['state'] ?? '',
                $property['postal_code'] ?? ''
            ), ', '),
            'price' => '$' . number_format(floatval($property['list_price'])),
            'bedrooms' => intval($property['bedrooms_total']),
            'bathrooms' => floatval($property['bathrooms_total']),
            'sqft' => number_format($sqft),
            'property_type' => $property['property_type'] ?? 'Unknown',
            'status' => $property['standard_status'] ?? 'Active',
            'property_url' => $this->get_property_url($listing_id), // v6.27.6: Added for clickable links
        );
    }

    /**
     * Get property detail page URL
     *
     * @param string $listing_id Listing ID
     * @return string Property URL
     * @since 6.27.6
     */
    protected function get_property_url($listing_id) {
        return home_url('/property/' . $listing_id . '/');
    }

    /**
     * Format property for detailed display
     *
     * Enhanced in v6.27.6 to include property_url for clickable links
     *
     * @param array $property Raw property data
     * @return array Formatted detail
     */
    protected function format_property_detail($property) {
        $detail = $this->format_property_summary($property);

        // Add additional details
        $detail['description'] = isset($property['property_description']) ?
            wp_trim_words($property['property_description'], 100) : '';
        $detail['year_built'] = intval($property['year_built'] ?? 0);
        $detail['lot_size'] = $property['lot_size_area'] ?? '';
        $detail['garage'] = $property['garage_spaces'] ?? 0;
        $detail['days_on_market'] = isset($property['original_entry_timestamp']) ?
            floor((time() - strtotime($property['original_entry_timestamp'])) / 86400) : null;

        // Include photos if present
        if (!empty($property['photos'])) {
            $detail['photo_count'] = count($property['photos']);
            $detail['primary_photo'] = $property['photos'][0]['media_url'] ?? null;
        }

        // Include schools if present
        if (!empty($property['schools'])) {
            $detail['nearby_schools'] = array_slice($property['schools'], 0, 3);
        }

        // Ensure property_url is included (v6.27.6)
        if (empty($detail['property_url']) && !empty($property['listing_id'])) {
            $detail['property_url'] = $this->get_property_url($property['listing_id']);
        }

        return $detail;
    }

    /**
     * Handler: Resolve Property Reference
     *
     * Resolves user references like "number 5", "first one", "70 Phillips" to listing_id
     *
     * @param array $args Arguments with 'reference' key
     * @return array Formatted result with resolved property
     * @since 6.14.0
     */
    protected function handle_resolve_property_reference($args) {
        if (empty($args['reference'])) {
            return $this->format_error('reference is required');
        }

        $reference = sanitize_text_field($args['reference']);

        if (!$this->conversation_context) {
            return $this->format_error('No conversation context available');
        }

        $resolved = $this->conversation_context->resolve_reference($reference);

        if (!$resolved) {
            return $this->format_error("Could not resolve reference: {$reference}. Try asking to see the property list again.");
        }

        // Get details for the resolved property
        return $this->handle_get_property_details(array(
            'listing_id' => $resolved['listing_id'],
        ));
    }

    /**
     * Handler: Get Property Category Data
     *
     * Returns specific category data from stored active property
     *
     * @param array $args Arguments with 'category' key
     * @return array Formatted result with category data
     * @since 6.14.0
     */
    protected function handle_get_property_category($args) {
        if (empty($args['category'])) {
            return $this->format_error('category is required');
        }

        $category = sanitize_text_field($args['category']);

        if (!$this->conversation_context) {
            return $this->format_error('No conversation context available');
        }

        $active_property = $this->conversation_context->get_active_property();

        if (!$active_property) {
            return $this->format_error('No active property in context. Please ask about a specific property first.');
        }

        $category_data = $this->extract_property_category($active_property, $category);

        if (empty($category_data)) {
            return $this->format_success(array(
                'category' => $category,
                'message' => "No {$category} data available for this property.",
            ));
        }

        return $this->format_success(array(
            'category' => $category,
            'listing_id' => $this->conversation_context->get_active_property_id(),
            'data' => $category_data,
        ));
    }

    /**
     * Extract specific category data from comprehensive property
     *
     * @param array $property Full property data
     * @param string $category Category name
     * @return array Category-specific data
     * @since 6.14.0
     */
    protected function extract_property_category($property, $category) {
        $data = array();

        switch ($category) {
            case 'hvac':
                $data = array(
                    'heating' => $property['heating'] ?? $property['heating_yn'] ?? null,
                    'cooling' => $property['cooling'] ?? $property['cooling_yn'] ?? null,
                    'fuel_type' => $property['heating_fuel'] ?? null,
                    'water_heater' => $property['water_heater'] ?? null,
                    'utilities' => $property['utilities'] ?? null,
                    'electric' => $property['electric'] ?? null,
                    'sewer' => $property['sewer'] ?? null,
                    'water_source' => $property['water_source'] ?? null,
                );
                break;

            case 'rooms':
                $data = $property['rooms'] ?? array();
                break;

            case 'financial':
                $data = array(
                    'tax_annual_amount' => $property['tax_annual_amount'] ?? null,
                    'tax_year' => $property['tax_year'] ?? null,
                    'association_fee' => $property['association_fee'] ?? null,
                    'association_fee_frequency' => $property['association_fee_frequency'] ?? null,
                    'association_fee_includes' => $property['association_fee_includes'] ?? null,
                    'assessed_value' => $property['assessed_value'] ?? null,
                    'special_assessment' => $property['special_assessment'] ?? null,
                );
                break;

            case 'features':
                $data = array(
                    'interior_features' => $property['interior_features'] ?? null,
                    'exterior_features' => $property['exterior_features'] ?? null,
                    'appliances' => $property['appliances'] ?? null,
                    'flooring' => $property['flooring'] ?? null,
                    'basement' => $property['basement'] ?? $property['basement_yn'] ?? null,
                    'fireplace' => $property['fireplace_yn'] ?? null,
                    'fireplace_features' => $property['fireplace_features'] ?? null,
                    'pool' => $property['pool_private_yn'] ?? null,
                    'garage' => $property['garage_yn'] ?? null,
                    'garage_spaces' => $property['garage_spaces'] ?? null,
                    'parking_features' => $property['parking_features'] ?? null,
                );
                break;

            case 'history':
                $data = array(
                    'price_history' => $property['price_history'] ?? array(),
                    'original_list_price' => $property['original_list_price'] ?? null,
                    'list_price' => $property['list_price'] ?? null,
                    'original_entry_timestamp' => $property['original_entry_timestamp'] ?? null,
                    'modification_timestamp' => $property['modification_timestamp'] ?? null,
                    'days_on_market' => $property['days_on_market'] ?? null,
                );
                break;

            case 'location':
                $data = array(
                    'latitude' => $property['latitude'] ?? null,
                    'longitude' => $property['longitude'] ?? null,
                    'subdivision' => $property['subdivision_name'] ?? null,
                    'mls_area' => $property['mls_area_major'] ?? null,
                    'directions' => $property['directions'] ?? null,
                    'county' => $property['county_or_parish'] ?? null,
                );
                break;

            case 'schools':
                $data = array(
                    'elementary_school' => $property['elementary_school'] ?? null,
                    'middle_school' => $property['middle_school'] ?? null,
                    'high_school' => $property['high_school'] ?? null,
                    'school_district' => $property['school_district'] ?? null,
                );
                break;

            default:
                // Unknown category - return empty
                break;
        }

        // Filter out null values
        return array_filter($data, function($v) { return $v !== null; });
    }

    /**
     * Format comprehensive property data for AI consumption
     *
     * @param array $property Full property data
     * @return array Formatted property
     * @since 6.14.0
     */
    protected function format_comprehensive_property($property) {
        // Start with standard summary
        $formatted = $this->format_property_detail($property);

        // Add comprehensive details
        $formatted['description'] = $property['public_remarks'] ?? $property['property_description'] ?? '';

        // HVAC summary
        $hvac = array();
        if (!empty($property['heating'])) $hvac[] = 'Heating: ' . $property['heating'];
        if (!empty($property['cooling'])) $hvac[] = 'Cooling: ' . $property['cooling'];
        if (!empty($hvac)) $formatted['hvac'] = implode(', ', $hvac);

        // Financial summary
        if (!empty($property['tax_annual_amount'])) {
            $formatted['annual_taxes'] = '$' . number_format($property['tax_annual_amount']);
        }
        if (!empty($property['association_fee'])) {
            $freq = $property['association_fee_frequency'] ?? 'monthly';
            $formatted['hoa_fee'] = '$' . number_format($property['association_fee']) . '/' . $freq;
        }

        // Features summary
        if (!empty($property['appliances'])) {
            $formatted['appliances'] = $property['appliances'];
        }
        if (!empty($property['flooring'])) {
            $formatted['flooring'] = $property['flooring'];
        }
        if (!empty($property['parking_features'])) {
            $formatted['parking'] = $property['parking_features'];
        }

        // Room summary
        if (!empty($property['rooms']) && is_array($property['rooms'])) {
            $room_list = array();
            foreach ($property['rooms'] as $room) {
                $room_str = $room['room_type'] ?? 'Room';
                if (!empty($room['room_dimensions'])) {
                    $room_str .= ' (' . $room['room_dimensions'] . ')';
                }
                if (!empty($room['room_level'])) {
                    $room_str .= ' - Level ' . $room['room_level'];
                }
                $room_list[] = $room_str;
            }
            $formatted['rooms_list'] = $room_list;
        }

        // Price history summary
        if (!empty($property['price_history']) && is_array($property['price_history'])) {
            $history = array();
            foreach (array_slice($property['price_history'], 0, 3) as $change) {
                $history[] = array(
                    'date' => $change['event_date'] ?? '',
                    'from' => !empty($change['old_price']) ? '$' . number_format($change['old_price']) : null,
                    'to' => !empty($change['new_price']) ? '$' . number_format($change['new_price']) : null,
                );
            }
            $formatted['price_changes'] = $history;
        }

        // Schools
        $schools = array();
        if (!empty($property['elementary_school'])) $schools['elementary'] = $property['elementary_school'];
        if (!empty($property['middle_school'])) $schools['middle'] = $property['middle_school'];
        if (!empty($property['high_school'])) $schools['high'] = $property['high_school'];
        if (!empty($schools)) $formatted['schools'] = $schools;

        // Agent info
        if (!empty($property['agent'])) {
            $formatted['listing_agent'] = array(
                'name' => $property['agent']['agent_full_name'] ?? '',
                'phone' => $property['agent']['agent_phone'] ?? '',
                'email' => $property['agent']['agent_email'] ?? '',
            );
        }

        // Open houses
        if (!empty($property['open_houses'])) {
            $formatted['open_houses'] = $property['open_houses'];
        }

        return $formatted;
    }

    /**
     * Format success response
     *
     * @param mixed $data Result data
     * @return array Formatted response
     */
    protected function format_success($data) {
        return array(
            'success' => true,
            'data' => $data,
        );
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @return array Formatted response
     */
    protected function format_error($message) {
        return array(
            'success' => false,
            'error' => $message,
        );
    }
}

/**
 * Get a tool executor instance
 *
 * @return MLD_Tool_Executor
 */
function mld_get_tool_executor() {
    return new MLD_Tool_Executor();
}
