<?php
/**
 * Smart Response Decision Engine
 *
 * Intelligently routes questions to minimize API token usage by checking
 * FAQs, cached responses, data queries, and templates before using AI.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Response_Engine {

    /**
     * Response confidence thresholds
     */
    const CONFIDENCE_HIGH = 0.85;
    const CONFIDENCE_MEDIUM = 0.65;
    const CONFIDENCE_LOW = 0.40;

    /**
     * Cache duration for responses (seconds)
     */
    const CACHE_SHORT = 3600;    // 1 hour
    const CACHE_MEDIUM = 86400;  // 24 hours
    const CACHE_LONG = 604800;   // 7 days

    /**
     * Data provider instance
     *
     * @var MLD_Unified_Data_Provider
     */
    private $data_provider;

    /**
     * Data reference mapper
     *
     * @var MLD_Data_Reference_Mapper
     */
    private $data_mapper;

    /**
     * Conversation state manager
     *
     * @var MLD_Conversation_State
     */
    private $state_manager;

    /**
     * Current conversation ID
     *
     * @var int
     */
    private $conversation_id;

    /**
     * Constructor
     *
     * @param int $conversation_id Conversation ID
     */
    public function __construct($conversation_id = null) {
        $this->conversation_id = $conversation_id;

        // Initialize components
        if (class_exists('MLD_Unified_Data_Provider')) {
            $this->data_provider = new MLD_Unified_Data_Provider();
        }

        if (class_exists('MLD_Data_Reference_Mapper')) {
            $this->data_mapper = new MLD_Data_Reference_Mapper();
        }

        if ($conversation_id && class_exists('MLD_Conversation_State')) {
            $this->state_manager = new MLD_Conversation_State($conversation_id);
        }
    }

    /**
     * Process question and generate response
     *
     * @param string $question User question
     * @param array $context Additional context
     * @return array Response data
     */
    public function processQuestion($question, $context = array()) {
        $start_time = microtime(true);

        // Initialize response
        $response = array(
            'answer' => '',
            'source' => 'unknown',
            'confidence' => 0.0,
            'tokens_used' => 0,
            'data' => array(),
            'suggestions' => array(),
            'processing_time' => 0,
            'requires_agent' => false
        );

        // Track processing steps for debugging
        $processing_log = array();

        // Step 1: Check FAQ database (0 tokens)
        $faq_result = $this->checkFAQ($question);
        if ($faq_result && $faq_result['confidence'] >= self::CONFIDENCE_HIGH) {
            $response['answer'] = $faq_result['answer'];
            $response['source'] = 'faq';
            $response['confidence'] = $faq_result['confidence'];
            $processing_log[] = 'Found in FAQ with high confidence';
            $this->logResponse($response, $processing_log, $start_time);
            return $response;
        }

        // Step 2: Check response cache (0 tokens)
        $cached = $this->checkResponseCache($question, $context);
        if ($cached) {
            $response = array_merge($response, $cached);
            $response['source'] = 'cache';
            $processing_log[] = 'Found in response cache';
            $this->logResponse($response, $processing_log, $start_time);
            return $response;
        }

        // Step 3: Analyze question intent and map to data
        $intent = $this->analyzeIntent($question);
        $data_mapping = $this->data_mapper->map_question_to_data($question);

        // Step 4: Try to answer with database query (0 tokens)
        if (!empty($data_mapping['matched_sources']) &&
            $this->can_answer_with_data($intent, $data_mapping)) {

            $data_response = $this->generateDataResponse($question, $intent, $data_mapping);
            if ($data_response && $data_response['confidence'] >= self::CONFIDENCE_MEDIUM) {
                $response = array_merge($response, $data_response);
                $response['source'] = 'database';
                $processing_log[] = 'Answered using database query';

                // Cache successful data response
                $this->cacheResponse($question, $context, $response);
                $this->logResponse($response, $processing_log, $start_time);
                return $response;
            }
        }

        // Step 5: Check template responses (0 tokens)
        $template = $this->checkTemplateResponses($question, $intent);
        if ($template && $template['confidence'] >= self::CONFIDENCE_MEDIUM) {
            $response['answer'] = $this->fillTemplate($template['template'], $context);
            $response['source'] = 'template';
            $response['confidence'] = $template['confidence'];
            $processing_log[] = 'Used template response';
            $this->logResponse($response, $processing_log, $start_time);
            return $response;
        }

        // Step 6: If all else fails, prepare for AI response (uses tokens)
        $response = $this->prepareAIResponse($question, $intent, $data_mapping, $context);
        $response['source'] = 'ai';
        $processing_log[] = 'Requires AI response';

        // Cache AI response if successful
        if ($response['confidence'] >= self::CONFIDENCE_MEDIUM) {
            $this->cacheResponse($question, $context, $response, self::CACHE_MEDIUM);
        }

        $this->logResponse($response, $processing_log, $start_time);
        return $response;
    }

    /**
     * Check FAQ database
     *
     * @param string $question User question
     * @return array|null FAQ match
     */
    private function checkFAQ($question) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_faq';

        // Direct match
        $direct = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE is_active = 1
             AND LOWER(question) = LOWER(%s)",
            $question
        ), ARRAY_A);

        if ($direct) {
            $this->incrementFAQUsage($direct['id']);
            return array(
                'answer' => $direct['answer'],
                'confidence' => 1.0,
                'faq_id' => $direct['id']
            );
        }

        // Keyword match
        $keywords = $this->extractKeywords($question);
        if (empty($keywords)) {
            return null;
        }

        $keyword_string = implode(' ', $keywords);
        $faqs = $wpdb->get_results($wpdb->prepare(
            "SELECT *, MATCH(keywords) AGAINST(%s) as relevance
             FROM {$table}
             WHERE is_active = 1
             AND MATCH(keywords) AGAINST(%s)
             ORDER BY relevance DESC
             LIMIT 5",
            $keyword_string,
            $keyword_string
        ), ARRAY_A);

        if (!empty($faqs)) {
            $best_match = $faqs[0];
            $confidence = $this->calculateFAQConfidence($question, $best_match);

            if ($confidence >= self::CONFIDENCE_MEDIUM) {
                $this->incrementFAQUsage($best_match['id']);
                return array(
                    'answer' => $best_match['answer'],
                    'confidence' => $confidence,
                    'faq_id' => $best_match['id']
                );
            }
        }

        return null;
    }

    /**
     * Check response cache
     *
     * @param string $question Question
     * @param array $context Context
     * @return array|null Cached response
     */
    private function checkResponseCache($question, $context) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_response_cache';
        $question_hash = md5(strtolower(trim($question)));
        $context_hash = md5(json_encode($context));

        // Check cache with and without context
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE question_hash = %s
             AND (context_hash = %s OR context_hash IS NULL)
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY context_hash = %s DESC
             LIMIT 1",
            $question_hash,
            $context_hash,
            $context_hash
        ), ARRAY_A);

        if ($cached) {
            // Update hit count and last accessed
            $wpdb->update(
                $table,
                array(
                    'hit_count' => $cached['hit_count'] + 1,
                    'last_accessed' => current_time('mysql')
                ),
                array('id' => $cached['id']),
                array('%d', '%s'),
                array('%d')
            );

            return array(
                'answer' => $cached['response'],
                'confidence' => floatval($cached['confidence_score']),
                'tokens_used' => intval($cached['tokens_used']),
                'cache_id' => $cached['id']
            );
        }

        return null;
    }

    /**
     * Analyze question intent
     *
     * @param string $question Question text
     * @return array Intent analysis
     */
    private function analyzeIntent($question) {
        $intent = array(
            'type' => 'unknown',
            'entities' => array(),
            'action' => null,
            'urgency' => 'normal'
        );

        $question_lower = strtolower($question);

        // Determine intent type
        if (preg_match('/\b(price|cost|how much|expensive|cheap|afford)\b/i', $question)) {
            $intent['type'] = 'price_inquiry';
        } elseif (preg_match('/\b(bedroom|bathroom|bed|bath|rooms?)\b/i', $question)) {
            $intent['type'] = 'property_features';
        } elseif (preg_match('/\b(where|location|address|directions?|near|close to)\b/i', $question)) {
            $intent['type'] = 'location_inquiry';
        } elseif (preg_match('/\b(available|for sale|on market|listing)\b/i', $question)) {
            $intent['type'] = 'availability_check';
        } elseif (preg_match('/\b(school|education|district|rating)\b/i', $question)) {
            $intent['type'] = 'school_info';
        } elseif (preg_match('/\b(agent|realtor|contact|call|email|reach)\b/i', $question)) {
            $intent['type'] = 'agent_contact';
            $intent['urgency'] = 'high';
        } elseif (preg_match('/\b(schedule|tour|visit|see|showing|appointment)\b/i', $question)) {
            $intent['type'] = 'scheduling';
            $intent['urgency'] = 'high';
        } elseif (preg_match('/\b(compare|versus|vs|similar|like|comps?)\b/i', $question)) {
            $intent['type'] = 'comparison';
        } elseif (preg_match('/\b(market|trend|statistics?|average|median)\b/i', $question)) {
            $intent['type'] = 'market_analysis';
        } elseif (preg_match('/\b(neighborhood|area|community|amenities)\b/i', $question)) {
            $intent['type'] = 'area_info';
        }

        // Extract entities
        $intent['entities'] = $this->extractEntities($question);

        // Determine action
        if (preg_match('/\b(show|find|search|look for|get|list)\b/i', $question)) {
            $intent['action'] = 'search';
        } elseif (preg_match('/\b(tell|explain|describe|what|how|why)\b/i', $question)) {
            $intent['action'] = 'explain';
        } elseif (preg_match('/\b(schedule|book|arrange|set up)\b/i', $question)) {
            $intent['action'] = 'schedule';
        } elseif (preg_match('/\b(contact|call|email|reach out)\b/i', $question)) {
            $intent['action'] = 'contact';
        }

        return $intent;
    }

    /**
     * Extract entities from question
     *
     * @param string $question Question text
     * @return array Extracted entities
     */
    private function extractEntities($question) {
        $entities = array();

        // Extract property ID/MLS number
        if (preg_match('/\b(?:property|listing|mls)?\s*#?\s*(\w+)\b/i', $question, $matches)) {
            $entities['listing_id'] = $matches[1];
        }

        // Extract price
        if (preg_match('/\$?([\d,]+)(?:k|K|thousand)?/i', $question, $matches)) {
            $price = str_replace(',', '', $matches[1]);
            if (stripos($question, 'k') !== false || stripos($question, 'thousand') !== false) {
                $price *= 1000;
            }
            $entities['price'] = $price;
        }

        // Extract bedrooms
        if (preg_match('/(\d+)\s*(?:bed|bedroom|br)/i', $question, $matches)) {
            $entities['bedrooms'] = intval($matches[1]);
        }

        // Extract city/location
        $cities = $this->getKnownCities();
        foreach ($cities as $city) {
            if (stripos($question, $city) !== false) {
                $entities['city'] = $city;
                break;
            }
        }

        // Extract property type
        $property_types = array('house', 'condo', 'townhouse', 'apartment', 'land');
        foreach ($property_types as $type) {
            if (stripos($question, $type) !== false) {
                $entities['property_type'] = $type;
                break;
            }
        }

        return $entities;
    }

    /**
     * Check if question can be answered with data
     *
     * @param array $intent Intent analysis
     * @param array $data_mapping Data mapping result
     * @return bool Can answer with data
     */
    private function can_answer_with_data($intent, $data_mapping) {
        // These intents can typically be answered with database queries
        $data_answerable = array(
            'price_inquiry',
            'property_features',
            'location_inquiry',
            'availability_check',
            'school_info',
            'market_analysis',
            'comparison'
        );

        if (!in_array($intent['type'], $data_answerable)) {
            return false;
        }

        // Check if we have confident data sources
        if (empty($data_mapping['matched_sources'])) {
            return false;
        }

        // Check if confidence is high enough
        $max_confidence = 0;
        foreach ($data_mapping['matched_sources'] as $source) {
            $max_confidence = max($max_confidence, $source['confidence']);
        }

        return $max_confidence >= self::CONFIDENCE_MEDIUM;
    }

    /**
     * Generate response using database data
     *
     * @param string $question Original question
     * @param array $intent Intent analysis
     * @param array $data_mapping Data mapping
     * @return array Response
     */
    private function generateDataResponse($question, $intent, $data_mapping) {
        $response = array(
            'answer' => '',
            'confidence' => 0.0,
            'data' => array()
        );

        // Handle different intent types
        switch ($intent['type']) {
            case 'price_inquiry':
                $response = $this->generatePriceResponse($intent['entities']);
                break;

            case 'property_features':
                $response = $this->generatePropertyResponse($intent['entities']);
                break;

            case 'availability_check':
                $response = $this->generateAvailabilityResponse($intent['entities']);
                break;

            case 'market_analysis':
                $response = $this->generateMarketResponse($intent['entities']);
                break;

            case 'school_info':
                $response = $this->generateSchoolResponse($intent['entities']);
                break;

            case 'comparison':
                $response = $this->generateComparisonResponse($intent['entities']);
                break;

            default:
                // Try generic data query
                $response = $this->generateGenericDataResponse($question, $data_mapping);
        }

        return $response;
    }

    /**
     * Generate price-related response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generatePriceResponse($entities) {
        if (!empty($entities['listing_id'])) {
            // Get specific property price
            $property = $this->data_provider->getPropertyData(array(
                'listing_id' => $entities['listing_id']
            ));

            if (!empty($property)) {
                $prop = $property[0];
                return array(
                    'answer' => sprintf(
                        "The property at %s is listed at $%s. It features %d bedrooms and %.1f bathrooms with %s square feet of living space.",
                        $prop['street_address'],
                        number_format($prop['list_price']),
                        $prop['bedrooms_total'],
                        $prop['bathrooms_total'],
                        number_format($prop['living_area'])
                    ),
                    'confidence' => 0.95,
                    'data' => $prop
                );
            }
        } elseif (!empty($entities['city'])) {
            // Get area price statistics
            $stats = $this->data_provider->getQuickStat('average_price', array('city' => $entities['city']));
            $range = $this->data_provider->getQuickStat('price_range', array('city' => $entities['city']));

            if ($stats && $range) {
                return array(
                    'answer' => sprintf(
                        "In %s, the average home price is $%s, with prices ranging from $%s to $%s.",
                        $entities['city'],
                        number_format($stats),
                        number_format($range['min_price']),
                        number_format($range['max_price'])
                    ),
                    'confidence' => 0.85,
                    'data' => array('average' => $stats, 'range' => $range)
                );
            }
        }

        return array('answer' => '', 'confidence' => 0);
    }

    /**
     * Generate property features response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generatePropertyResponse($entities) {
        $criteria = array();

        if (!empty($entities['bedrooms'])) {
            $criteria['min_bedrooms'] = $entities['bedrooms'];
        }
        if (!empty($entities['city'])) {
            $criteria['city'] = $entities['city'];
        }
        if (!empty($entities['price'])) {
            $criteria['max_price'] = $entities['price'];
        }

        $properties = $this->data_provider->getPropertyData($criteria);

        if (!empty($properties)) {
            $count = count($properties);
            $answer = sprintf("I found %d properties matching your criteria:\n\n", $count);

            // Show first 3 properties
            foreach (array_slice($properties, 0, 3) as $prop) {
                $answer .= sprintf(
                    "• %s - $%s (%d bed, %.1f bath, %s sqft)\n",
                    $prop['street_address'],
                    number_format($prop['list_price']),
                    $prop['bedrooms_total'],
                    $prop['bathrooms_total'],
                    number_format($prop['living_area'])
                );
            }

            if ($count > 3) {
                $answer .= sprintf("\n...and %d more properties. Would you like to see more details?", $count - 3);
            }

            return array(
                'answer' => $answer,
                'confidence' => 0.90,
                'data' => $properties
            );
        }

        return array('answer' => 'No properties found matching your criteria.', 'confidence' => 0.80);
    }

    /**
     * Generate availability response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generateAvailabilityResponse($entities) {
        $total = $this->data_provider->getQuickStat('total_active', $entities);

        if ($total !== null) {
            $answer = sprintf("There are currently %d active listings", $total);

            if (!empty($entities['city'])) {
                $answer .= sprintf(" in %s", $entities['city']);
            }
            if (!empty($entities['property_type'])) {
                $answer .= sprintf(" for %s properties", $entities['property_type']);
            }

            $answer .= ". Would you like to narrow your search with specific criteria?";

            return array(
                'answer' => $answer,
                'confidence' => 0.85,
                'data' => array('total' => $total)
            );
        }

        return array('answer' => '', 'confidence' => 0);
    }

    /**
     * Generate market analysis response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generateMarketResponse($entities) {
        $area = !empty($entities['city']) ? $entities['city'] : null;
        $analytics = $this->data_provider->getMarketAnalytics($area);

        if (!empty($analytics)) {
            $latest = $analytics[0];
            $answer = "Market Analysis:\n\n";

            if ($area) {
                $answer .= sprintf("For %s:\n", $area);
            }

            $answer .= sprintf(
                "• Average Price: $%s\n• Median Price: $%s\n• Total Listings: %d\n• Avg Days on Market: %d",
                number_format($latest['avg_price']),
                number_format($latest['median_price']),
                $latest['total_listings'],
                $latest['avg_dom']
            );

            return array(
                'answer' => $answer,
                'confidence' => 0.88,
                'data' => $analytics
            );
        }

        return array('answer' => '', 'confidence' => 0);
    }

    /**
     * Generate school information response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generateSchoolResponse($entities) {
        if (!empty($entities['listing_id'])) {
            $schools = $this->data_provider->getPropertySchools($entities['listing_id']);

            if (!empty($schools)) {
                $answer = "Schools for this property:\n\n";
                foreach ($schools as $school) {
                    $answer .= sprintf(
                        "• %s (%s) - Rating: %s, Grades: %s, Distance: %.1f miles\n",
                        $school['school_name'],
                        $school['school_type'],
                        $school['school_rating'],
                        $school['school_grades'],
                        $school['distance_miles']
                    );
                }

                return array(
                    'answer' => $answer,
                    'confidence' => 0.92,
                    'data' => $schools
                );
            }
        }

        return array('answer' => 'School information not available for this property.', 'confidence' => 0.70);
    }

    /**
     * Generate comparison response
     *
     * @param array $entities Extracted entities
     * @return array Response
     */
    private function generateComparisonResponse($entities) {
        if (!empty($entities['listing_id'])) {
            // Get subject property
            $subject = $this->data_provider->getPropertyData(array(
                'listing_id' => $entities['listing_id']
            ));

            if (!empty($subject)) {
                $comparables = $this->data_provider->getCMAComparables($subject[0], 3);

                if (!empty($comparables)) {
                    $answer = sprintf(
                        "Comparable properties to %s:\n\n",
                        $subject[0]['street_address']
                    );

                    foreach ($comparables as $comp) {
                        $answer .= sprintf(
                            "• %s - $%s (%.1f miles away, %d%% similarity)\n",
                            $comp['street_address'],
                            number_format($comp['list_price']),
                            $comp['distance_miles'],
                            $comp['similarity_score']
                        );
                    }

                    return array(
                        'answer' => $answer,
                        'confidence' => 0.86,
                        'data' => array('subject' => $subject[0], 'comparables' => $comparables)
                    );
                }
            }
        }

        return array('answer' => '', 'confidence' => 0);
    }

    /**
     * Generate generic data response
     *
     * @param string $question Question
     * @param array $data_mapping Data mapping
     * @return array Response
     */
    private function generateGenericDataResponse($question, $data_mapping) {
        // Try to execute a query template if available
        if (!empty($data_mapping['suggested_queries'])) {
            $template = $data_mapping['suggested_queries'][0];
            $params = $data_mapping['extraction_hints'];

            // Map extracted parameters to template requirements
            $query_params = array();
            foreach ($template['parameters'] as $param) {
                if (isset($params[$param])) {
                    $query_params[$param] = $params[$param];
                }
            }

            if (count($query_params) === count($template['parameters'])) {
                $results = $this->data_provider->executeQueryTemplate(
                    $template['template_key'],
                    $query_params
                );

                if (!empty($results) && !isset($results['error'])) {
                    $answer = sprintf(
                        "Based on your query, I found %d results. Here are the highlights:\n",
                        count($results)
                    );

                    // Format results based on type
                    $answer .= $this->formatQueryResults($results);

                    return array(
                        'answer' => $answer,
                        'confidence' => 0.75,
                        'data' => $results
                    );
                }
            }
        }

        return array('answer' => '', 'confidence' => 0);
    }

    /**
     * Check template responses
     *
     * @param string $question Question
     * @param array $intent Intent analysis
     * @return array|null Template match
     */
    private function checkTemplateResponses($question, $intent) {
        $templates = array(
            'greeting' => array(
                'patterns' => array('/^(hi|hello|hey|good\s+(morning|afternoon|evening))/i'),
                'template' => "Hello! I'm here to help you find your perfect home. You can ask me about available properties, prices, neighborhoods, or schedule a viewing. How can I assist you today?",
                'confidence' => 0.95
            ),
            'thanks' => array(
                'patterns' => array('/\b(thank|thanks|appreciate|grateful)\b/i'),
                'template' => "You're welcome! Is there anything else you'd like to know about our properties or the home buying process?",
                'confidence' => 0.90
            ),
            'goodbye' => array(
                'patterns' => array('/\b(bye|goodbye|see you|take care|have a good)\b/i'),
                'template' => "Thank you for visiting! Feel free to return anytime if you have more questions. Have a great day!",
                'confidence' => 0.92
            ),
            'help' => array(
                'patterns' => array('/\b(help|what can you|capabilities|how do I)\b/i'),
                'template' => "I can help you with:\n• Searching for properties by location, price, or features\n• Providing market statistics and trends\n• Information about neighborhoods and schools\n• Scheduling property viewings\n• Connecting you with an agent\n\nWhat would you like to know?",
                'confidence' => 0.88
            )
        );

        foreach ($templates as $key => $template) {
            foreach ($template['patterns'] as $pattern) {
                if (preg_match($pattern, $question)) {
                    return $template;
                }
            }
        }

        return null;
    }

    /**
     * Fill template with context data
     *
     * @param string $template Template string
     * @param array $context Context data
     * @return string Filled template
     */
    private function fillTemplate($template, $context) {
        $replacements = array(
            '{site_name}' => get_bloginfo('name'),
            '{current_date}' => date('F j, Y'),
            '{current_time}' => date('g:i A'),
            '{user_name}' => !empty($context['user_name']) ? $context['user_name'] : 'there'
        );

        foreach ($replacements as $placeholder => $value) {
            $template = str_replace($placeholder, $value, $template);
        }

        return $template;
    }

    /**
     * Prepare response for AI generation
     *
     * @param string $question Question
     * @param array $intent Intent
     * @param array $data_mapping Data mapping
     * @param array $context Context
     * @return array Response structure
     */
    private function prepareAIResponse($question, $intent, $data_mapping, $context) {
        // Build context for AI
        $ai_context = array(
            'question' => $question,
            'intent' => $intent,
            'available_data' => $this->data_mapper->generate_ai_context($data_mapping),
            'user_context' => $context
        );

        // Add relevant data if available
        if (!empty($intent['entities'])) {
            $ai_context['relevant_data'] = $this->gatherRelevantData($intent['entities']);
        }

        return array(
            'answer' => '', // Will be filled by AI
            'confidence' => 0.0,
            'ai_context' => $ai_context,
            'requires_ai' => true
        );
    }

    /**
     * Cache response
     *
     * @param string $question Question
     * @param array $context Context
     * @param array $response Response
     * @param int $ttl Time to live
     * @return bool Success
     */
    private function cacheResponse($question, $context, $response, $ttl = self::CACHE_SHORT) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_response_cache';
        $question_hash = md5(strtolower(trim($question)));
        $context_hash = !empty($context) ? md5(json_encode($context)) : null;

        $data = array(
            'question_hash' => $question_hash,
            'question' => $question,
            'response' => $response['answer'],
            'response_type' => $response['source'],
            'context_hash' => $context_hash,
            'confidence_score' => $response['confidence'],
            'tokens_used' => $response['tokens_used'],
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'created_at' => current_time('mysql')
        );

        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * Extract keywords from text
     *
     * @param string $text Text
     * @return array Keywords
     */
    private function extractKeywords($text) {
        $words = str_word_count(strtolower($text), 1);
        $stop_words = array('the', 'is', 'at', 'which', 'on', 'a', 'an', 'as', 'are', 'was', 'were', 'to', 'for');
        return array_diff($words, $stop_words);
    }

    /**
     * Calculate FAQ confidence
     *
     * @param string $question User question
     * @param array $faq FAQ entry
     * @return float Confidence
     */
    private function calculateFAQConfidence($question, $faq) {
        // Simple similarity calculation
        $question_words = $this->extractKeywords($question);
        $faq_words = $this->extractKeywords($faq['keywords']);

        if (empty($question_words) || empty($faq_words)) {
            return 0.0;
        }

        $common = array_intersect($question_words, $faq_words);
        $confidence = count($common) / max(count($question_words), count($faq_words));

        // Boost if question types match
        if (stripos($faq['question'], '?') !== false && stripos($question, '?') !== false) {
            $confidence += 0.1;
        }

        return min(1.0, $confidence);
    }

    /**
     * Increment FAQ usage count
     *
     * @param int $faq_id FAQ ID
     */
    private function incrementFAQUsage($faq_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_faq';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET usage_count = usage_count + 1 WHERE id = %d",
            $faq_id
        ));
    }

    /**
     * Get known cities from database
     *
     * @return array City names
     */
    private function getKnownCities() {
        global $wpdb;
        static $cities = null;

        if ($cities === null) {
            $table = $wpdb->prefix . 'bme_listings';
            $cities = $wpdb->get_col(
                "SELECT DISTINCT city FROM {$table}
                 WHERE city IS NOT NULL AND city != ''
                 ORDER BY city"
            );
        }

        return $cities ?: array();
    }

    /**
     * Gather relevant data based on entities
     *
     * @param array $entities Entities
     * @return array Relevant data
     */
    private function gatherRelevantData($entities) {
        $data = array();

        if (!empty($entities['listing_id'])) {
            $property = $this->data_provider->getPropertyData(array(
                'listing_id' => $entities['listing_id'],
                'include_details' => true
            ));
            if ($property) {
                $data['property'] = $property[0];
            }
        }

        if (!empty($entities['city'])) {
            $data['market_stats'] = $this->data_provider->getMarketAnalytics($entities['city']);
            $data['neighborhood'] = $this->data_provider->getNeighborhoodStats($entities['city']);
        }

        return $data;
    }

    /**
     * Format query results for display
     *
     * @param array $results Query results
     * @return string Formatted text
     */
    private function formatQueryResults($results) {
        if (empty($results)) {
            return "No results found.";
        }

        $output = "";
        $first = $results[0];

        // Detect result type and format accordingly
        if (isset($first['listing_id']) && isset($first['list_price'])) {
            // Property results
            foreach (array_slice($results, 0, 3) as $prop) {
                $output .= sprintf(
                    "• %s - $%s\n",
                    $prop['street_address'] ?? 'Property ' . $prop['listing_id'],
                    number_format($prop['list_price'])
                );
            }
        } elseif (isset($first['avg_price'])) {
            // Statistics results
            $output .= sprintf(
                "Average Price: $%s\n",
                number_format($first['avg_price'])
            );
            if (isset($first['total_listings'])) {
                $output .= sprintf("Total Listings: %d\n", $first['total_listings']);
            }
        } else {
            // Generic results
            $output = json_encode($first, JSON_PRETTY_PRINT);
        }

        return $output;
    }

    /**
     * Log response for analytics
     *
     * @param array $response Response data
     * @param array $processing_log Processing steps
     * @param float $start_time Start timestamp
     */
    private function logResponse($response, $processing_log, $start_time) {
        $response['processing_time'] = round((microtime(true) - $start_time) * 1000, 2);
        $response['processing_log'] = $processing_log;

        // Log to error_log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Response Engine] Source: %s, Confidence: %.2f, Time: %.2fms, Tokens: %d',
                $response['source'],
                $response['confidence'],
                $response['processing_time'],
                $response['tokens_used']
            ));
        }

        // Could also log to database for analytics
        if ($this->conversation_id) {
            // Update conversation with response metrics
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'mld_chatbot_conversations',
                array(
                    'last_response_source' => $response['source'],
                    'total_tokens_used' => $response['tokens_used']
                ),
                array('id' => $this->conversation_id),
                array('%s', '%d'),
                array('%d')
            );
        }
    }
}