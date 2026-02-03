<?php
/**
 * Tool Registry for AI Function Calling
 *
 * Defines available tools that AI providers can call to retrieve
 * real-time property data. Implements OpenAI function calling schema.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.10.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Tool_Registry {

    /**
     * Singleton instance
     *
     * @var MLD_Tool_Registry
     */
    private static $instance = null;

    /**
     * Registered tools
     *
     * @var array
     */
    private $tools = array();

    /**
     * Get singleton instance
     *
     * @return MLD_Tool_Registry
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - registers default tools
     */
    private function __construct() {
        $this->register_default_tools();
    }

    /**
     * Register default property search tools
     */
    private function register_default_tools() {
        // Tool 1: Search Properties
        $this->register_tool('search_properties', array(
            'description' => 'Search for property listings based on criteria like location, price, bedrooms, etc. Use this when users ask about finding homes or properties.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'city' => array(
                        'type' => 'string',
                        'description' => 'City name to search in (e.g., "Boston", "Cambridge", "Brookline"). Can also accept Boston neighborhoods like "South Boston", "Back Bay", "North End" etc.',
                    ),
                    'neighborhood' => array(
                        'type' => 'string',
                        'description' => 'Boston neighborhood name (e.g., "South Boston", "Back Bay", "North End", "Beacon Hill", "Seaport", "Jamaica Plain"). Use this when users specifically ask for a Boston neighborhood.',
                    ),
                    'min_price' => array(
                        'type' => 'integer',
                        'description' => 'Minimum listing price in dollars',
                    ),
                    'max_price' => array(
                        'type' => 'integer',
                        'description' => 'Maximum listing price in dollars',
                    ),
                    'min_bedrooms' => array(
                        'type' => 'integer',
                        'description' => 'Minimum number of bedrooms',
                    ),
                    'min_bathrooms' => array(
                        'type' => 'number',
                        'description' => 'Minimum number of bathrooms',
                    ),
                    'property_type' => array(
                        'type' => 'string',
                        'description' => 'Type of property. Use: "Condo" or "Condominium" for condos, "Single Family" or "House" for single family homes, "Apartment", "Townhouse", "Duplex", "Multi-Family". The system will map these to the correct database values.',
                    ),
                    'min_sqft' => array(
                        'type' => 'integer',
                        'description' => 'Minimum square footage',
                    ),
                    'max_sqft' => array(
                        'type' => 'integer',
                        'description' => 'Maximum square footage',
                    ),
                    'sort_by' => array(
                        'type' => 'string',
                        'enum' => array('list_price', 'bedrooms_total', 'living_area', 'original_entry_timestamp'),
                        'description' => 'Field to sort results by',
                    ),
                    'sort_order' => array(
                        'type' => 'string',
                        'enum' => array('ASC', 'DESC'),
                        'description' => 'Sort order (ascending or descending)',
                    ),
                    'limit' => array(
                        'type' => 'integer',
                        'description' => 'Maximum number of results to return (1-10, default 5)',
                    ),
                ),
                'required' => array(),
            ),
            'handler' => 'handle_search_properties',
        ));

        // Tool 2: Get Market Statistics
        $this->register_tool('get_market_stats', array(
            'description' => 'Get real estate market statistics like total listings, average prices, inventory counts. Use this when users ask about market conditions or statistics.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'stat_type' => array(
                        'type' => 'string',
                        'enum' => array(
                            'total_active',
                            'average_price',
                            'median_price',
                            'price_range',
                            'inventory_by_type',
                            'inventory_by_city',
                            'new_listings_today',
                            'price_reduced_today',
                        ),
                        'description' => 'Type of statistic to retrieve',
                    ),
                    'city' => array(
                        'type' => 'string',
                        'description' => 'Optional city to filter statistics',
                    ),
                    'property_type' => array(
                        'type' => 'string',
                        'description' => 'Optional property type filter',
                    ),
                ),
                'required' => array('stat_type'),
            ),
            'handler' => 'handle_get_market_stats',
        ));

        // Tool 3: Get Property Details
        $this->register_tool('get_property_details', array(
            'description' => 'Get detailed information about a specific property by its MLS listing ID. Use when users ask about a specific property or want more details.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'listing_id' => array(
                        'type' => 'string',
                        'description' => 'The MLS listing ID of the property',
                    ),
                    'include_photos' => array(
                        'type' => 'boolean',
                        'description' => 'Whether to include photo URLs (default false)',
                    ),
                    'include_schools' => array(
                        'type' => 'boolean',
                        'description' => 'Whether to include nearby school information (default false)',
                    ),
                ),
                'required' => array('listing_id'),
            ),
            'handler' => 'handle_get_property_details',
        ));

        // Tool 4: Get Neighborhood Info
        $this->register_tool('get_neighborhood_info', array(
            'description' => 'Get information and statistics about a neighborhood or city. Use when users ask about areas, neighborhoods, or want to compare locations.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'neighborhood' => array(
                        'type' => 'string',
                        'description' => 'Name of the neighborhood or city',
                    ),
                ),
                'required' => array('neighborhood'),
            ),
            'handler' => 'handle_get_neighborhood_info',
        ));

        // Tool 5: Get Price Trends
        $this->register_tool('get_price_trends', array(
            'description' => 'Get historical price trends for an area. Use when users ask about price history, market trends, or how prices have changed.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'city' => array(
                        'type' => 'string',
                        'description' => 'City to get price trends for',
                    ),
                    'property_type' => array(
                        'type' => 'string',
                        'description' => 'Optional property type filter',
                    ),
                    'timeframe' => array(
                        'type' => 'string',
                        'enum' => array('30d', '90d', '6m', '1y', 'all'),
                        'description' => 'Time period for trend data (default 90d)',
                    ),
                ),
                'required' => array(),
            ),
            'handler' => 'handle_get_price_trends',
        ));

        // Tool 6: Find Similar Properties (Comps)
        $this->register_tool('find_similar_properties', array(
            'description' => 'Find properties similar to a given property (comparables). Use when users want to see similar homes or compare properties.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'listing_id' => array(
                        'type' => 'string',
                        'description' => 'The MLS listing ID of the subject property',
                    ),
                    'count' => array(
                        'type' => 'integer',
                        'description' => 'Number of similar properties to find (1-6, default 3)',
                    ),
                ),
                'required' => array('listing_id'),
            ),
            'handler' => 'handle_find_similar_properties',
        ));

        // Tool 7: Text Search
        $this->register_tool('text_search', array(
            'description' => 'Search properties by free-text query. Use for general searches like "waterfront", "garage", "pool", addresses, or MLS numbers.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array(
                        'type' => 'string',
                        'description' => 'The search text (address, feature, MLS number, etc.)',
                    ),
                    'limit' => array(
                        'type' => 'integer',
                        'description' => 'Maximum results to return (1-10, default 5)',
                    ),
                ),
                'required' => array('query'),
            ),
            'handler' => 'handle_text_search',
        ));

        // Tool 8: Schedule Tour
        $this->register_tool('schedule_tour', array(
            'description' => 'Schedule a property tour/showing for a user. Use when users want to schedule a showing, view a property, or book a tour. Collect their name, email, phone, preferred date/time, and any message.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'listing_id' => array(
                        'type' => 'string',
                        'description' => 'The MLS listing ID of the property to tour',
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Full name of the person scheduling the tour',
                    ),
                    'email' => array(
                        'type' => 'string',
                        'description' => 'Email address for tour confirmation',
                    ),
                    'phone' => array(
                        'type' => 'string',
                        'description' => 'Phone number to reach the person',
                    ),
                    'preferred_date' => array(
                        'type' => 'string',
                        'description' => 'Preferred date for the tour (YYYY-MM-DD format or natural language like "next Saturday")',
                    ),
                    'preferred_time' => array(
                        'type' => 'string',
                        'description' => 'Preferred time for the tour (e.g., "morning", "afternoon", "2:00 PM")',
                    ),
                    'message' => array(
                        'type' => 'string',
                        'description' => 'Additional message or special requests from the user',
                    ),
                ),
                'required' => array('listing_id', 'name', 'email', 'phone'),
            ),
            'handler' => 'handle_schedule_tour',
        ));

        // Tool 9: Contact Agent
        $this->register_tool('contact_agent', array(
            'description' => 'Send a message to the listing agent or contact the real estate office. Use when users want to ask questions about a property, request more information, or get in touch with an agent.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'listing_id' => array(
                        'type' => 'string',
                        'description' => 'The MLS listing ID of the property (optional - can be general inquiry)',
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Full name of the person contacting',
                    ),
                    'email' => array(
                        'type' => 'string',
                        'description' => 'Email address for response',
                    ),
                    'phone' => array(
                        'type' => 'string',
                        'description' => 'Phone number (optional)',
                    ),
                    'message' => array(
                        'type' => 'string',
                        'description' => 'The message or question for the agent',
                    ),
                    'inquiry_type' => array(
                        'type' => 'string',
                        'enum' => array('general', 'property_question', 'buying', 'selling', 'rental'),
                        'description' => 'Type of inquiry (default: general)',
                    ),
                ),
                'required' => array('name', 'email', 'message'),
            ),
            'handler' => 'handle_contact_agent',
        ));

        // Tool: Resolve Property Reference (v6.14.0)
        // Resolves user references like "number 5", "first one", "70 Phillips" to listing_id
        $this->register_tool('resolve_property_reference', array(
            'description' => 'Resolve a user reference to a property from the previously shown list. Use when user says things like "show me number 5", "tell me about the first one", "the third property", or refers to an address from the previous results.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'reference' => array(
                        'type' => 'string',
                        'description' => 'The user\'s reference to resolve. Examples: "5", "first", "number 3", "70 Phillips", "the second one"',
                    ),
                ),
                'required' => array('reference'),
            ),
            'handler' => 'handle_resolve_property_reference',
        ));

        // Tool: Get Property Category Data (v6.14.0)
        // Returns specific category data from the active property in context
        $this->register_tool('get_property_category', array(
            'description' => 'Get specific category data about the currently active property. Use when user asks detailed questions about HVAC, rooms, financial info, etc. for a property already being discussed.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'category' => array(
                        'type' => 'string',
                        'enum' => array('hvac', 'rooms', 'financial', 'features', 'history', 'location', 'schools'),
                        'description' => 'The category of data to retrieve. hvac=heating/cooling, rooms=room dimensions, financial=taxes/HOA, features=appliances/flooring/parking, history=price changes, location=coordinates/area, schools=school info',
                    ),
                ),
                'required' => array('category'),
            ),
            'handler' => 'handle_get_property_category',
        ));
    }

    /**
     * Register a tool
     *
     * @param string $name Tool name
     * @param array $config Tool configuration
     */
    public function register_tool($name, $config) {
        $this->tools[$name] = array(
            'name' => $name,
            'description' => $config['description'],
            'parameters' => $config['parameters'],
            'handler' => isset($config['handler']) ? $config['handler'] : null,
        );
    }

    /**
     * Get all tools in OpenAI format
     *
     * @return array Tools array for OpenAI API
     */
    public function get_tools_for_openai() {
        $openai_tools = array();

        foreach ($this->tools as $name => $tool) {
            $openai_tools[] = array(
                'type' => 'function',
                'function' => array(
                    'name' => $name,
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ),
            );
        }

        return $openai_tools;
    }

    /**
     * Get a specific tool definition
     *
     * @param string $name Tool name
     * @return array|null Tool definition or null if not found
     */
    public function get_tool($name) {
        return isset($this->tools[$name]) ? $this->tools[$name] : null;
    }

    /**
     * Check if a tool exists
     *
     * @param string $name Tool name
     * @return bool
     */
    public function has_tool($name) {
        return isset($this->tools[$name]);
    }

    /**
     * Get handler method name for a tool
     *
     * @param string $name Tool name
     * @return string|null Handler method name
     */
    public function get_handler($name) {
        if (isset($this->tools[$name]) && isset($this->tools[$name]['handler'])) {
            return $this->tools[$name]['handler'];
        }
        return null;
    }

    /**
     * Get all registered tool names
     *
     * @return array Tool names
     */
    public function get_tool_names() {
        return array_keys($this->tools);
    }

    /**
     * Get tool count
     *
     * @return int Number of registered tools
     */
    public function get_tool_count() {
        return count($this->tools);
    }

    /**
     * Check if function calling is enabled
     *
     * @return bool
     */
    public function is_enabled() {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            'enable_function_calling'
        ));

        // Default to enabled if setting doesn't exist
        return $enabled !== '0';
    }
}

/**
 * Get the tool registry instance
 *
 * @return MLD_Tool_Registry
 */
function mld_get_tool_registry() {
    return MLD_Tool_Registry::get_instance();
}
