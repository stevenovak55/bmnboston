<?php
/**
 * MLD Data Reference Mapper
 *
 * Maps natural language questions to database sources without duplicating data.
 * Teaches the AI where to find information in the existing database structure.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Data_Reference_Mapper {

    /**
     * Database table metadata cache
     *
     * @var array
     */
    private $schema_cache = array();

    /**
     * Query templates for common questions
     *
     * @var array
     */
    private $query_templates = array();

    /**
     * Data source mappings
     *
     * @var array
     */
    private $data_mappings = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->initialize_mappings();
        $this->initialize_query_templates();
    }

    /**
     * Initialize data source mappings
     * Maps question patterns to database locations
     */
    private function initialize_mappings() {
        global $wpdb;

        $this->data_mappings = array(
            // Property-related questions
            'property_details' => array(
                'patterns' => array(
                    '/price of|listing price|how much|cost/i',
                    '/bedroom|bathroom|bed|bath|rooms/i',
                    '/square feet|sq ft|size|area/i',
                    '/address|location|where is/i',
                    '/property type|home type|style/i'
                ),
                'primary_table' => $wpdb->prefix . 'bme_listings',
                'join_tables' => array(
                    $wpdb->prefix . 'bme_property_details',
                    $wpdb->prefix . 'bme_media'
                ),
                'key_fields' => array(
                    'listing_id', 'list_price', 'bedrooms_total',
                    'bathrooms_total', 'living_area', 'street_address',
                    'city', 'state', 'postal_code', 'property_type'
                ),
                'description' => 'Main property listing information including price, size, and location'
            ),

            // Market analytics questions
            'market_stats' => array(
                'patterns' => array(
                    '/average price|avg price|median price/i',
                    '/market trend|price trend|market analysis/i',
                    '/days on market|dom|how long to sell/i',
                    '/inventory|homes for sale|available properties/i',
                    '/price per square foot|price\/sqft/i'
                ),
                'primary_table' => $wpdb->prefix . 'mld_market_analytics',
                'join_tables' => array(
                    $wpdb->prefix . 'bme_listing_summary'
                ),
                'key_fields' => array(
                    'area', 'period', 'avg_price', 'median_price',
                    'total_listings', 'avg_dom', 'price_per_sqft'
                ),
                'description' => 'Market statistics and trend data for areas'
            ),

            // Neighborhood information
            'neighborhood_data' => array(
                'patterns' => array(
                    '/neighborhood|area|community|district/i',
                    '/school|education|school district/i',
                    '/amenities|nearby|close to|walkable/i',
                    '/demographics|population|residents/i',
                    '/crime|safety|safe area/i'
                ),
                'primary_table' => $wpdb->prefix . 'mld_neighborhood_analytics',
                'join_tables' => array(
                    $wpdb->prefix . 'bme_schools'
                ),
                'key_fields' => array(
                    'neighborhood', 'school_rating', 'walkability_score',
                    'crime_index', 'median_income', 'population'
                ),
                'description' => 'Neighborhood demographics, schools, and area information'
            ),

            // Agent information
            'agent_data' => array(
                'patterns' => array(
                    '/agent|realtor|broker|representative/i',
                    '/contact|call|email|reach/i',
                    '/listing agent|seller agent/i',
                    '/who is selling|who to contact/i'
                ),
                'primary_table' => $wpdb->prefix . 'bme_agents',
                'join_tables' => array(
                    $wpdb->prefix . 'mld_agents',
                    $wpdb->prefix . 'bme_offices'
                ),
                'key_fields' => array(
                    'agent_id', 'agent_name', 'agent_email',
                    'agent_phone', 'office_name', 'specialties'
                ),
                'description' => 'Real estate agent contact and profile information'
            ),

            // Comparable properties (CMA)
            'comparables' => array(
                'patterns' => array(
                    '/similar|comparable|comps|like this/i',
                    '/sold for|recent sales|sold recently/i',
                    '/compare|comparison|versus/i',
                    '/homes like|properties like/i'
                ),
                'primary_table' => $wpdb->prefix . 'mld_cma_comparables',
                'join_tables' => array(
                    $wpdb->prefix . 'bme_listings',
                    $wpdb->prefix . 'bme_listings_archive'
                ),
                'key_fields' => array(
                    'subject_property', 'comp_property', 'similarity_score',
                    'sold_price', 'sold_date', 'distance'
                ),
                'description' => 'Comparable property sales for valuation'
            ),

            // User preferences and saved searches
            'user_preferences' => array(
                'patterns' => array(
                    '/saved search|my searches|favorite/i',
                    '/preferences|criteria|looking for/i',
                    '/notify|alert|email me/i',
                    '/wishlist|interested in/i'
                ),
                'primary_table' => $wpdb->prefix . 'mld_saved_searches',
                'join_tables' => array(
                    $wpdb->prefix . 'mld_search_alerts'
                ),
                'key_fields' => array(
                    'user_id', 'search_criteria', 'notification_frequency',
                    'last_notified', 'is_active'
                ),
                'description' => 'User saved searches and notification preferences'
            ),

            // Property history and changes
            'property_history' => array(
                'patterns' => array(
                    '/price change|reduced|increased/i',
                    '/history|previous|was listed/i',
                    '/how long listed|when listed/i',
                    '/status change|pending|sold/i'
                ),
                'primary_table' => $wpdb->prefix . 'bme_listing_history',
                'join_tables' => array(
                    $wpdb->prefix . 'bme_price_history'
                ),
                'key_fields' => array(
                    'listing_id', 'change_date', 'old_price',
                    'new_price', 'old_status', 'new_status'
                ),
                'description' => 'Property listing history and price changes'
            )
        );
    }

    /**
     * Initialize query templates for common questions
     */
    private function initialize_query_templates() {
        global $wpdb;

        $this->query_templates = array(
            // Property search queries
            'find_properties_by_criteria' => array(
                'template' => "SELECT l.*, ld.* FROM {$wpdb->prefix}bme_listings l
                              LEFT JOIN {$wpdb->prefix}bme_property_details ld ON l.listing_id = ld.listing_id
                              WHERE l.standard_status = 'Active'
                              AND l.city = %s
                              AND l.bedrooms_total >= %d
                              AND l.list_price BETWEEN %d AND %d
                              LIMIT 10",
                'parameters' => array('city', 'min_bedrooms', 'min_price', 'max_price'),
                'description' => 'Find active properties by city, bedrooms, and price range'
            ),

            // Market statistics queries
            'get_area_market_stats' => array(
                'template' => "SELECT
                              COUNT(*) as total_listings,
                              AVG(list_price) as avg_price,
                              MIN(list_price) as min_price,
                              MAX(list_price) as max_price,
                              AVG(DATEDIFF(CURDATE(), original_entry_timestamp)) as avg_dom
                              FROM {$wpdb->prefix}bme_listings
                              WHERE city = %s
                              AND standard_status = 'Active'",
                'parameters' => array('city'),
                'description' => 'Get market statistics for a specific area'
            ),

            // Recent sales query
            'get_recent_sales' => array(
                'template' => "SELECT * FROM {$wpdb->prefix}bme_listings_archive
                              WHERE city = %s
                              AND standard_status = 'Closed'
                              AND close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
                              ORDER BY close_date DESC
                              LIMIT 20",
                'parameters' => array('city', 'months_back'),
                'description' => 'Get recently sold properties in an area'
            ),

            // Agent listings query
            'get_agent_listings' => array(
                'template' => "SELECT l.*, a.agent_name, a.agent_email, a.agent_phone
                              FROM {$wpdb->prefix}bme_listings l
                              LEFT JOIN {$wpdb->prefix}bme_agents a ON l.listing_agent_id = a.agent_id
                              WHERE a.agent_id = %s
                              AND l.standard_status = 'Active'",
                'parameters' => array('agent_id'),
                'description' => 'Get all active listings for a specific agent'
            ),

            // School information query
            'get_property_schools' => array(
                'template' => "SELECT s.* FROM {$wpdb->prefix}bme_schools s
                              WHERE s.listing_id = %s
                              ORDER BY s.school_rating DESC",
                'parameters' => array('listing_id'),
                'description' => 'Get school information for a property'
            ),

            // Comparable properties query
            'find_comparable_properties' => array(
                'template' => "SELECT l.*,
                              ABS(l.living_area - %d) as size_diff,
                              ABS(l.bedrooms_total - %d) as bed_diff,
                              ABS(l.list_price - %d) as price_diff,
                              ST_Distance_Sphere(
                                  POINT(l.longitude, l.latitude),
                                  POINT(%f, %f)
                              ) / 1609.34 as distance_miles
                              FROM {$wpdb->prefix}bme_listings l
                              WHERE l.standard_status = 'Active'
                              AND l.property_type = %s
                              AND l.bedrooms_total BETWEEN %d AND %d
                              AND l.list_price BETWEEN %d AND %d
                              AND l.listing_id != %s
                              HAVING distance_miles <= 3
                              ORDER BY (size_diff + bed_diff * 10000 + price_diff / 1000 + distance_miles * 5000) ASC
                              LIMIT 6",
                'parameters' => array(
                    'subject_sqft', 'subject_beds', 'subject_price',
                    'subject_lng', 'subject_lat', 'property_type',
                    'min_beds', 'max_beds', 'min_price', 'max_price',
                    'exclude_listing_id'
                ),
                'description' => 'Find comparable properties based on size, beds, price, and location'
            )
        );
    }

    /**
     * Discover database schema and relationships
     *
     * @return array Schema information
     */
    public function discover_schema() {
        global $wpdb;

        if (!empty($this->schema_cache)) {
            return $this->schema_cache;
        }

        $schema = array();

        // Get all relevant tables
        $table_patterns = array(
            'bme_' => 'Bridge MLS Extractor tables',
            'mld_' => 'MLS Listings Display tables'
        );

        foreach ($table_patterns as $pattern => $description) {
            $tables = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT table_name
                     FROM information_schema.tables
                     WHERE table_schema = %s
                     AND table_name LIKE %s",
                    DB_NAME,
                    $wpdb->prefix . $pattern . '%'
                )
            );

            foreach ($tables as $table) {
                // Get table columns
                $columns = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT column_name, data_type, column_key, column_comment
                         FROM information_schema.columns
                         WHERE table_schema = %s
                         AND table_name = %s",
                        DB_NAME,
                        $table
                    ),
                    ARRAY_A
                );

                // Get row count for context
                $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");

                $schema[$table] = array(
                    'description' => $description,
                    'columns' => $columns,
                    'row_count' => $count,
                    'relationships' => $this->discover_relationships($table)
                );
            }
        }

        $this->schema_cache = $schema;
        return $schema;
    }

    /**
     * Discover table relationships based on foreign keys and naming patterns
     *
     * @param string $table Table name
     * @return array Relationships
     */
    private function discover_relationships($table) {
        global $wpdb;

        $relationships = array();

        // Check for listing_id relationships (most common)
        if (strpos($table, 'bme_') !== false || strpos($table, 'mld_') !== false) {
            $has_listing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema = %s
                     AND table_name = %s
                     AND column_name = 'listing_id'",
                    DB_NAME,
                    $table
                )
            );

            if ($has_listing_id) {
                $relationships[] = array(
                    'type' => 'foreign_key',
                    'column' => 'listing_id',
                    'references' => $wpdb->prefix . 'bme_listings',
                    'on_column' => 'listing_id'
                );
            }
        }

        // Check for agent_id relationships
        $has_agent_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = %s
                 AND table_name = %s
                 AND column_name LIKE '%agent_id%'",
                DB_NAME,
                $table
            )
        );

        if ($has_agent_id) {
            $relationships[] = array(
                'type' => 'foreign_key',
                'column' => 'agent_id',
                'references' => $wpdb->prefix . 'bme_agents',
                'on_column' => 'agent_id'
            );
        }

        return $relationships;
    }

    /**
     * Map a natural language question to data sources
     *
     * @param string $question The user's question
     * @return array Mapped data sources and suggested queries
     */
    public function map_question_to_data($question) {
        $matched_sources = array();

        // Check each mapping pattern
        foreach ($this->data_mappings as $source_key => $mapping) {
            foreach ($mapping['patterns'] as $pattern) {
                if (preg_match($pattern, $question)) {
                    $matched_sources[$source_key] = array(
                        'confidence' => $this->calculate_confidence($question, $pattern),
                        'primary_table' => $mapping['primary_table'],
                        'join_tables' => $mapping['join_tables'],
                        'key_fields' => $mapping['key_fields'],
                        'description' => $mapping['description']
                    );
                    break; // Found a match for this source
                }
            }
        }

        // Find matching query templates
        $suggested_queries = $this->find_matching_queries($question);

        return array(
            'matched_sources' => $matched_sources,
            'suggested_queries' => $suggested_queries,
            'extraction_hints' => $this->extract_query_parameters($question)
        );
    }

    /**
     * Calculate confidence score for pattern match
     *
     * @param string $question User question
     * @param string $pattern Regex pattern
     * @return float Confidence score 0-1
     */
    private function calculate_confidence($question, $pattern) {
        $matches = array();
        preg_match_all($pattern, $question, $matches);

        if (empty($matches[0])) {
            return 0.0;
        }

        // Calculate based on match coverage and specificity
        $match_length = strlen(implode(' ', $matches[0]));
        $question_length = strlen($question);
        $coverage = $match_length / $question_length;

        // Boost confidence for more specific patterns
        $specificity_boost = substr_count($pattern, '|') > 3 ? 0.1 : 0.2;

        return min(1.0, $coverage + $specificity_boost);
    }

    /**
     * Find query templates that might help answer the question
     *
     * @param string $question User question
     * @return array Matching query templates
     */
    private function find_matching_queries($question) {
        $matches = array();
        $question_lower = strtolower($question);

        // Keywords that suggest certain query types
        $query_keywords = array(
            'find_properties_by_criteria' => array('find', 'search', 'looking for', 'show me'),
            'get_area_market_stats' => array('average', 'market', 'statistics', 'trend'),
            'get_recent_sales' => array('sold', 'recent sales', 'closed'),
            'get_agent_listings' => array('agent', 'realtor', 'broker'),
            'get_property_schools' => array('school', 'education', 'district'),
            'find_comparable_properties' => array('similar', 'comparable', 'comps', 'like')
        );

        foreach ($query_keywords as $template_key => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($question_lower, $keyword) !== false) {
                    $matches[] = array(
                        'template_key' => $template_key,
                        'template' => $this->query_templates[$template_key]['template'],
                        'parameters' => $this->query_templates[$template_key]['parameters'],
                        'description' => $this->query_templates[$template_key]['description']
                    );
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Extract potential query parameters from question
     *
     * @param string $question User question
     * @return array Extracted parameters
     */
    private function extract_query_parameters($question) {
        $parameters = array();

        // Extract numbers (could be bedrooms, price, etc.)
        preg_match_all('/\b\d+\b/', $question, $numbers);
        if (!empty($numbers[0])) {
            $parameters['numbers'] = $numbers[0];
        }

        // Extract price patterns
        preg_match_all('/\$[\d,]+k?m?|\d+k|\d+m/i', $question, $prices);
        if (!empty($prices[0])) {
            $parameters['prices'] = array_map(array($this, 'parse_price'), $prices[0]);
        }

        // Extract location mentions (basic city extraction)
        // This would be enhanced with a proper location database
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $question, $locations);
        if (!empty($locations[0])) {
            $parameters['possible_locations'] = $locations[0];
        }

        // Extract property types
        $property_types = array('house', 'condo', 'townhouse', 'apartment', 'land', 'single family', 'multi family');
        foreach ($property_types as $type) {
            if (stripos($question, $type) !== false) {
                $parameters['property_type'] = $type;
                break;
            }
        }

        return $parameters;
    }

    /**
     * Parse price string to numeric value
     *
     * @param string $price_str Price string
     * @return int Price in dollars
     */
    private function parse_price($price_str) {
        $price_str = strtolower(str_replace(array('$', ','), '', $price_str));

        if (strpos($price_str, 'm') !== false) {
            return intval($price_str) * 1000000;
        } elseif (strpos($price_str, 'k') !== false) {
            return intval($price_str) * 1000;
        } else {
            return intval($price_str);
        }
    }

    /**
     * Generate context for AI based on mapped data
     *
     * @param array $mapping_result Result from map_question_to_data
     * @return string Context string for AI
     */
    public function generate_ai_context($mapping_result) {
        $context_parts = array();

        // Add data source information
        if (!empty($mapping_result['matched_sources'])) {
            $context_parts[] = "Available data sources for this query:";
            foreach ($mapping_result['matched_sources'] as $source_key => $source) {
                $context_parts[] = sprintf(
                    "- %s (confidence: %.1f%%): %s",
                    $source_key,
                    $source['confidence'] * 100,
                    $source['description']
                );
                $context_parts[] = "  Primary table: " . $source['primary_table'];
                $context_parts[] = "  Key fields: " . implode(', ', array_slice($source['key_fields'], 0, 5));
            }
        }

        // Add suggested queries
        if (!empty($mapping_result['suggested_queries'])) {
            $context_parts[] = "\nSuggested query approaches:";
            foreach ($mapping_result['suggested_queries'] as $query) {
                $context_parts[] = "- " . $query['description'];
                $context_parts[] = "  Required parameters: " . implode(', ', $query['parameters']);
            }
        }

        // Add extracted parameters
        if (!empty($mapping_result['extraction_hints'])) {
            $context_parts[] = "\nExtracted information from question:";
            foreach ($mapping_result['extraction_hints'] as $hint_type => $values) {
                if (is_array($values)) {
                    $context_parts[] = "- " . $hint_type . ": " . implode(', ', $values);
                } else {
                    $context_parts[] = "- " . $hint_type . ": " . $values;
                }
            }
        }

        return implode("\n", $context_parts);
    }

    /**
     * Get all available data references for knowledge base
     *
     * @return array All data references
     */
    public function get_all_references() {
        $references = array();

        // Combine mappings and query templates
        foreach ($this->data_mappings as $key => $mapping) {
            $references[] = array(
                'type' => 'data_mapping',
                'key' => $key,
                'patterns' => $mapping['patterns'],
                'tables' => array_merge(array($mapping['primary_table']), $mapping['join_tables']),
                'description' => $mapping['description']
            );
        }

        foreach ($this->query_templates as $key => $template) {
            $references[] = array(
                'type' => 'query_template',
                'key' => $key,
                'parameters' => $template['parameters'],
                'description' => $template['description']
            );
        }

        return $references;
    }

    /**
     * Save data references to knowledge base
     *
     * @return bool Success status
     */
    public function save_to_knowledge_base() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_knowledge_base';
        $saved_count = 0;

        // Save schema information
        $schema = $this->discover_schema();
        foreach ($schema as $table_name => $table_info) {
            $content = sprintf(
                "Database Table: %s\nRows: %d\nColumns: %s\nRelationships: %s",
                $table_name,
                $table_info['row_count'],
                json_encode($table_info['columns']),
                json_encode($table_info['relationships'])
            );

            $result = $wpdb->replace(
                $table,
                array(
                    'entry_type' => 'schema',
                    'title' => 'Schema: ' . $table_name,
                    'content' => $content,
                    'keywords' => $table_name . ' database schema structure',
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if ($result) {
                $saved_count++;
            }
        }

        // Save data references
        $references = $this->get_all_references();
        foreach ($references as $reference) {
            $content = sprintf(
                "Reference Type: %s\nKey: %s\nDescription: %s\nDetails: %s",
                $reference['type'],
                $reference['key'],
                $reference['description'],
                json_encode($reference)
            );

            $result = $wpdb->replace(
                $table,
                array(
                    'entry_type' => 'data_reference',
                    'title' => 'Data Reference: ' . $reference['key'],
                    'content' => $content,
                    'keywords' => $reference['key'] . ' ' . $reference['description'],
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if ($result) {
                $saved_count++;
            }
        }

        return $saved_count > 0;
    }
}