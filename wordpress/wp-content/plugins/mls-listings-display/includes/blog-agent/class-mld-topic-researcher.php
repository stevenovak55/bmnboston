<?php
/**
 * Topic Researcher
 *
 * Handles trending topic discovery and scoring for real estate content.
 * Queries multiple sources, filters for Boston/MA relevance, and ranks topics.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Topic_Researcher
 *
 * Discovers and scores trending real estate topics.
 */
class MLD_Topic_Researcher {

    /**
     * Log to dedicated Blog Agent log file
     */
    private function blog_log($message) {
        $log_file = WP_CONTENT_DIR . '/blog-agent-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] [TopicResearcher] $message\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * AI provider instance
     *
     * @var object
     */
    private $ai_provider;

    /**
     * Prompt manager instance
     *
     * @var MLD_Blog_Prompt_Manager
     */
    private $prompt_manager;

    /**
     * Last error message
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Topic sources with their weights
     *
     * @var array
     */
    private $sources = array(
        'web_search' => array(
            'weight' => 0.4,
            'enabled' => true,
        ),
        'rss_feeds' => array(
            'weight' => 0.3,
            'enabled' => false,  // Temporarily disabled - needs JSON format fix
        ),
        'market_data' => array(
            'weight' => 0.3,
            'enabled' => false,  // Temporarily disabled - slow/timeout
        ),
    );

    /**
     * Boston area cities for relevance filtering
     *
     * @var array
     */
    private $boston_area_cities = array(
        'Boston', 'Cambridge', 'Somerville', 'Brookline', 'Newton',
        'Quincy', 'Medford', 'Malden', 'Everett', 'Chelsea',
        'Revere', 'Waltham', 'Watertown', 'Arlington', 'Belmont',
        'Winchester', 'Lexington', 'Concord', 'Wellesley', 'Needham',
        'Milton', 'Dedham', 'Norwood', 'Braintree', 'Weymouth',
    );

    /**
     * RSS feeds for real estate news
     *
     * @var array
     */
    private $rss_feeds = array(
        'https://www.inman.com/feed/',
        'https://www.housingwire.com/feed/',
        'https://www.realtor.com/news/feed/',
        'https://www.nar.realtor/newsroom/rss',
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_ai_provider();
    }

    /**
     * Initialize AI provider
     */
    private function init_ai_provider() {
        // Load chatbot dependencies if not already loaded
        $chatbot_path = MLD_PLUGIN_PATH . 'includes/chatbot/';

        // Load AI provider interface and base class
        if (!interface_exists('MLD_AI_Provider_Interface')) {
            if (file_exists($chatbot_path . 'interface-mld-ai-provider.php')) {
                require_once $chatbot_path . 'interface-mld-ai-provider.php';
            }
        }
        if (!class_exists('MLD_AI_Provider')) {
            if (file_exists($chatbot_path . 'abstract-mld-ai-provider.php')) {
                require_once $chatbot_path . 'abstract-mld-ai-provider.php';
            }
        }

        // Load Model Router
        if (!class_exists('MLD_Model_Router')) {
            if (file_exists($chatbot_path . 'class-mld-model-router.php')) {
                require_once $chatbot_path . 'class-mld-model-router.php';
            }
        }

        // Load Claude Provider
        if (!class_exists('MLD_Claude_Provider')) {
            if (file_exists($chatbot_path . 'providers/class-mld-claude-provider.php')) {
                require_once $chatbot_path . 'providers/class-mld-claude-provider.php';
            }
        }

        // Try Claude provider first (most reliable for content generation)
        if (class_exists('MLD_Claude_Provider')) {
            try {
                $claude = new MLD_Claude_Provider();
                // Check if Claude has a valid API key configured
                if (method_exists($claude, 'is_configured') && $claude->is_configured()) {
                    $this->ai_provider = $claude;
                } elseif (!method_exists($claude, 'is_configured')) {
                    // Older version without is_configured method - try to use it anyway
                    $this->ai_provider = $claude;
                }
            } catch (Exception $e) {
                if (class_exists('MLD_Logger')) {
                    MLD_Logger::warning('Blog Agent: Failed to initialize Claude provider: ' . $e->getMessage());
                }
            }
        }

        // Fallback to Model Router if Claude isn't available
        if (!$this->ai_provider && class_exists('MLD_Model_Router')) {
            try {
                // Model Router uses singleton pattern
                $router = MLD_Model_Router::get_instance();
                if (method_exists($router, 'is_enabled') && $router->is_enabled()) {
                    $this->ai_provider = $router;
                }
            } catch (Exception $e) {
                if (class_exists('MLD_Logger')) {
                    MLD_Logger::warning('Blog Agent: Failed to initialize Model Router: ' . $e->getMessage());
                }
            }
        }

        // Log status
        if ($this->ai_provider) {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::info('Blog Agent: AI provider initialized: ' . get_class($this->ai_provider));
            }
        } else {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::warning('Blog Agent: No AI provider available. Check chatbot AI configuration.');
            }
        }
    }

    /**
     * Set prompt manager
     *
     * @param MLD_Blog_Prompt_Manager $manager
     */
    public function set_prompt_manager($manager) {
        $this->prompt_manager = $manager;
    }

    /**
     * Research trending topics
     *
     * @param array $options Research options
     * @return array Research results
     */
    public function research_topics($options = array()) {
        $this->blog_log('research_topics called');

        $defaults = array(
            'count' => 5,
            'focus_areas' => array(),
            'exclude_recent' => true,
            'min_score' => 50,
        );
        $options = wp_parse_args($options, $defaults);

        // Check if AI provider is available
        if (!$this->ai_provider) {
            $this->blog_log('No AI provider available');
            return array(
                'success' => false,
                'error' => 'AI provider not configured. Please check the Chatbot AI settings in the WordPress admin under Chatbot → Settings → AI Configuration.',
                'topics' => array(),
                'count' => 0,
            );
        }

        $this->blog_log('AI provider: ' . get_class($this->ai_provider));

        $all_topics = array();

        // Gather topics from all sources
        try {
            if ($this->sources['web_search']['enabled']) {
                $this->blog_log('Researching web sources...');
                $web_topics = $this->research_web_sources();
                $this->blog_log('Web topics found: ' . count($web_topics));
                $all_topics = array_merge($all_topics, $web_topics);
            }

            if ($this->sources['rss_feeds']['enabled']) {
                $this->blog_log('Researching RSS feeds...');
                $rss_topics = $this->research_rss_feeds();
                $this->blog_log('RSS topics found: ' . count($rss_topics));
                $all_topics = array_merge($all_topics, $rss_topics);
            }

            if ($this->sources['market_data']['enabled']) {
                $this->blog_log('Researching market data...');
                $market_topics = $this->research_market_data();
                $this->blog_log('Market topics found: ' . count($market_topics));
                $all_topics = array_merge($all_topics, $market_topics);
            }
        } catch (Exception $e) {
            $this->blog_log('Exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Error researching topics: ' . $e->getMessage(),
                'topics' => array(),
                'count' => 0,
            );
        }

        $this->blog_log('Total topics collected: ' . count($all_topics));

        // Check if we got any topics
        if (empty($all_topics)) {
            // Log detailed debug info
            $debug_info = array(
                'ai_provider' => $this->ai_provider ? get_class($this->ai_provider) : 'none',
                'web_search_enabled' => $this->sources['web_search']['enabled'],
                'rss_enabled' => $this->sources['rss_feeds']['enabled'],
                'market_data_enabled' => $this->sources['market_data']['enabled'],
                'last_error' => $this->last_error,
            );

            $this->blog_log('No topics found. Debug: ' . json_encode($debug_info));

            $error_msg = 'No topics found.';
            if (!empty($this->last_error)) {
                $error_msg .= ' AI Error: ' . $this->last_error;
            } else {
                $error_msg .= ' The AI returned an empty or invalid response.';
            }
            $error_msg .= ' (Provider: ' . ($this->ai_provider ? get_class($this->ai_provider) : 'none') . ')';

            return array(
                'success' => false,
                'error' => $error_msg,
                'topics' => array(),
                'count' => 0,
            );
        }

        // Use AI to analyze and score topics
        $this->blog_log('Scoring topics...');
        $scored_topics = $this->analyze_and_score_topics($all_topics, $options);
        $this->blog_log('After scoring: ' . count($scored_topics) . ' topics');

        // Filter and exclude recent topics if requested
        if ($options['exclude_recent']) {
            $this->blog_log('Excluding recent topics...');
            $scored_topics = $this->exclude_recent_topics($scored_topics);
            $this->blog_log('After excluding recent: ' . count($scored_topics) . ' topics');
        }

        // Sort by total score and limit
        usort($scored_topics, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });

        $scored_topics = array_slice($scored_topics, 0, $options['count']);
        $this->blog_log('Final topics count: ' . count($scored_topics));

        // Save topics to database
        $this->blog_log('Saving topics to database...');
        $this->save_topics($scored_topics);
        $this->blog_log('Topics saved. Returning success.');

        return array(
            'success' => true,
            'topics' => $scored_topics,
            'count' => count($scored_topics),
            'researched_at' => current_time('mysql'),
        );
    }

    /**
     * Research web sources for trending topics
     *
     * @return array Topics from web search
     */
    private function research_web_sources() {
        $topics = array();

        $search_queries = array(
            'Boston real estate market trends ' . date('Y'),
            'Massachusetts housing market news',
            'Greater Boston home buying tips',
            'Boston neighborhood real estate',
            'MA mortgage rates trends',
            'Boston first time home buyer',
        );

        // Use AI to identify trending topics from search context
        if ($this->ai_provider) {
            $research_instructions = $this->get_research_prompt();

            // Include full instructions in the user message since Claude provider
            // uses its own system prompt from database
            $messages = array(
                array(
                    'role' => 'user',
                    'content' => $research_instructions . "\n\n" .
                                 "---\n\n" .
                                 "Research trending real estate topics for the Boston area. " .
                                 "Consider these search contexts:\n\n" .
                                 implode("\n", $search_queries) . "\n\n" .
                                 "Current date: " . current_time('F j, Y') . "\n\n" .
                                 "Return exactly 5 topics in this JSON format:\n" .
                                 "```json\n" .
                                 "{\n" .
                                 "  \"topics\": [\n" .
                                 "    {\n" .
                                 "      \"title\": \"Topic title (50-60 characters)\",\n" .
                                 "      \"description\": \"Brief description (100-150 words)\",\n" .
                                 "      \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"],\n" .
                                 "      \"related_cities\": [\"Boston\", \"Cambridge\"]\n" .
                                 "    }\n" .
                                 "  ]\n" .
                                 "}\n" .
                                 "```\n\n" .
                                 "Respond ONLY with the JSON, no other text.",
                ),
            );

            $result = $this->call_ai($messages, '');

            // Log result for debugging
            if (class_exists('MLD_Logger')) {
                MLD_Logger::debug('Blog Agent web_search result: success=' . ($result['success'] ? 'true' : 'false') .
                    ', topics_count=' . (isset($result['topics']) ? count($result['topics']) : 0) .
                    ', error=' . ($result['error'] ?? 'none'));
            }

            if ($result['success'] && !empty($result['topics'])) {
                foreach ($result['topics'] as $topic) {
                    $topic['source'] = 'web_search';
                    $topics[] = $topic;
                }
            } elseif (!$result['success']) {
                // Store the error for later
                $this->last_error = $result['error'] ?? 'Unknown error';
            }
        }

        return $topics;
    }

    /**
     * Research RSS feeds for topic ideas
     *
     * @return array Topics from RSS feeds
     */
    private function research_rss_feeds() {
        $topics = array();
        $feed_items = array();

        foreach ($this->rss_feeds as $feed_url) {
            $feed = fetch_feed($feed_url);

            if (!is_wp_error($feed)) {
                $items = $feed->get_items(0, 10);

                foreach ($items as $item) {
                    $feed_items[] = array(
                        'title' => $item->get_title(),
                        'description' => wp_strip_all_tags($item->get_description()),
                        'date' => $item->get_date('Y-m-d'),
                        'link' => $item->get_link(),
                    );
                }
            }
        }

        // Use AI to extract Boston-relevant topics from feed items
        if (!empty($feed_items) && $this->ai_provider) {
            $feed_summary = array_slice($feed_items, 0, 20);

            $messages = array(
                array(
                    'role' => 'user',
                    'content' => "Analyze these recent real estate news items and identify 3 topics that could be localized for the Boston/Massachusetts market:\n\n" .
                                 json_encode($feed_summary, JSON_PRETTY_PRINT) . "\n\n" .
                                 "For each topic, explain how it applies to Boston and suggest a local angle.",
                ),
            );

            $result = $this->call_ai($messages, $this->get_research_prompt());

            if ($result['success'] && !empty($result['topics'])) {
                foreach ($result['topics'] as $topic) {
                    $topic['source'] = 'rss_feeds';
                    $topics[] = $topic;
                }
            }
        }

        return $topics;
    }

    /**
     * Research market data for topic ideas
     *
     * @return array Topics from market data
     */
    private function research_market_data() {
        $topics = array();

        // Get current market statistics from BME data
        $market_stats = $this->get_market_statistics();

        if (!empty($market_stats) && $this->ai_provider) {
            $messages = array(
                array(
                    'role' => 'user',
                    'content' => "Based on this Boston area real estate market data, suggest 2 compelling blog topics:\n\n" .
                                 json_encode($market_stats, JSON_PRETTY_PRINT) . "\n\n" .
                                 "Focus on notable trends, changes, or insights that would interest buyers and sellers.",
                ),
            );

            $result = $this->call_ai($messages, $this->get_research_prompt());

            if ($result['success'] && !empty($result['topics'])) {
                foreach ($result['topics'] as $topic) {
                    $topic['source'] = 'market_data';
                    $topic['market_stats'] = $market_stats;
                    $topics[] = $topic;
                }
            }
        }

        return $topics;
    }

    /**
     * Get current market statistics
     *
     * @return array Market statistics
     */
    private function get_market_statistics() {
        global $wpdb;

        $stats = array();

        // Use existing market data calculator if available
        if (class_exists('MLD_Market_Data_Calculator')) {
            $calculator = new MLD_Market_Data_Calculator();

            // Get aggregate stats for Boston area
            $stats['median_price'] = $calculator->get_median_price(array('city' => 'Boston'));
            $stats['avg_dom'] = $calculator->get_average_days_on_market(array('city' => 'Boston'));
            $stats['inventory'] = $calculator->get_active_inventory(array('city' => 'Boston'));
        }

        // Get basic stats from bme_listing_summary
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Active listings count by city
        $city_counts = $wpdb->get_results(
            "SELECT city, COUNT(*) as count
             FROM $summary_table
             WHERE standard_status = 'Active'
             AND city IN ('" . implode("','", array_map('esc_sql', $this->boston_area_cities)) . "')
             GROUP BY city
             ORDER BY count DESC
             LIMIT 10",
            ARRAY_A
        );

        if ($city_counts) {
            $stats['city_inventory'] = $city_counts;
        }

        // Price range distribution
        $price_distribution = $wpdb->get_results(
            "SELECT
                CASE
                    WHEN list_price < 500000 THEN 'Under $500K'
                    WHEN list_price < 750000 THEN '$500K-$750K'
                    WHEN list_price < 1000000 THEN '$750K-$1M'
                    WHEN list_price < 1500000 THEN '$1M-$1.5M'
                    ELSE 'Over $1.5M'
                END as price_range,
                COUNT(*) as count
             FROM $summary_table
             WHERE standard_status = 'Active'
             AND state_or_province = 'MA'
             GROUP BY price_range
             ORDER BY MIN(list_price)",
            ARRAY_A
        );

        if ($price_distribution) {
            $stats['price_distribution'] = $price_distribution;
        }

        // Property type distribution
        $type_distribution = $wpdb->get_results(
            "SELECT property_sub_type, COUNT(*) as count
             FROM $summary_table
             WHERE standard_status = 'Active'
             AND state_or_province = 'MA'
             GROUP BY property_sub_type
             ORDER BY count DESC
             LIMIT 5",
            ARRAY_A
        );

        if ($type_distribution) {
            $stats['property_types'] = $type_distribution;
        }

        $stats['data_date'] = current_time('Y-m-d');

        return $stats;
    }

    /**
     * Analyze and score topics using AI
     *
     * @param array $topics Raw topics to analyze
     * @param array $options Analysis options
     * @return array Scored topics
     */
    private function analyze_and_score_topics($topics, $options) {
        if (empty($topics)) {
            return array();
        }

        // Deduplicate similar topics
        $topics = $this->deduplicate_topics($topics);

        // Score each topic
        foreach ($topics as &$topic) {
            $topic['relevance_score'] = $this->calculate_relevance_score($topic);
            $topic['recency_score'] = $this->calculate_recency_score($topic);
            $topic['authority_score'] = $this->calculate_authority_score($topic);
            $topic['uniqueness_score'] = $this->calculate_uniqueness_score($topic);

            // Calculate total score (weighted average)
            $topic['total_score'] = (
                $topic['relevance_score'] * 0.35 +
                $topic['recency_score'] * 0.25 +
                $topic['authority_score'] * 0.20 +
                $topic['uniqueness_score'] * 0.20
            );
        }

        // Filter by minimum score
        $topics = array_filter($topics, function($topic) use ($options) {
            return $topic['total_score'] >= $options['min_score'];
        });

        return array_values($topics);
    }

    /**
     * Calculate relevance score for a topic
     *
     * @param array $topic Topic data
     * @return float Score 0-100
     */
    private function calculate_relevance_score($topic) {
        $score = 50; // Base score

        $title = strtolower($topic['title'] ?? '');
        $description = strtolower($topic['description'] ?? '');
        $text = $title . ' ' . $description;

        // Boston/MA mentions
        if (preg_match('/\b(boston|massachusetts|ma)\b/i', $text)) {
            $score += 20;
        }

        // Specific city mentions
        foreach ($this->boston_area_cities as $city) {
            if (stripos($text, $city) !== false) {
                $score += 10;
                break;
            }
        }

        // Real estate keywords
        $re_keywords = array('home', 'house', 'property', 'real estate', 'mortgage', 'buyer', 'seller', 'market');
        foreach ($re_keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $score += 5;
            }
        }

        // Related cities in topic data
        if (!empty($topic['related_cities'])) {
            $score += min(count($topic['related_cities']) * 5, 15);
        }

        return min($score, 100);
    }

    /**
     * Calculate recency score for a topic
     *
     * @param array $topic Topic data
     * @return float Score 0-100
     */
    private function calculate_recency_score($topic) {
        $score = 50;

        $title = strtolower($topic['title'] ?? '');
        $description = strtolower($topic['description'] ?? '');
        $text = $title . ' ' . $description;

        // Current year mentions
        $current_year = date('Y');
        if (strpos($text, $current_year) !== false) {
            $score += 25;
        }

        // Seasonal relevance
        $month = date('n');
        $seasonal_keywords = array();

        if ($month >= 3 && $month <= 5) {
            $seasonal_keywords = array('spring', 'selling season', 'move', 'garden');
        } elseif ($month >= 6 && $month <= 8) {
            $seasonal_keywords = array('summer', 'back to school', 'fall market');
        } elseif ($month >= 9 && $month <= 11) {
            $seasonal_keywords = array('fall', 'autumn', 'holiday', 'year end');
        } else {
            $seasonal_keywords = array('winter', 'new year', 'forecast', 'prediction');
        }

        foreach ($seasonal_keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $score += 10;
                break;
            }
        }

        // Trending indicators
        $trending_words = array('new', 'latest', 'update', 'change', 'trend', 'rising', 'falling');
        foreach ($trending_words as $word) {
            if (stripos($text, $word) !== false) {
                $score += 5;
            }
        }

        return min($score, 100);
    }

    /**
     * Calculate authority score for a topic
     *
     * @param array $topic Topic data
     * @return float Score 0-100
     */
    private function calculate_authority_score($topic) {
        $score = 50;

        $title = strtolower($topic['title'] ?? '');
        $description = strtolower($topic['description'] ?? '');
        $text = $title . ' ' . $description;

        // Data-driven indicators
        $data_words = array('statistics', 'data', 'report', 'analysis', 'study', 'research', 'survey');
        foreach ($data_words as $word) {
            if (stripos($text, $word) !== false) {
                $score += 10;
            }
        }

        // Expert positioning
        $expert_words = array('guide', 'tips', 'how to', 'expert', 'professional', 'advice');
        foreach ($expert_words as $word) {
            if (stripos($text, $word) !== false) {
                $score += 5;
            }
        }

        // Has market stats
        if (!empty($topic['market_stats'])) {
            $score += 15;
        }

        // Source authority
        if (!empty($topic['source'])) {
            if ($topic['source'] === 'market_data') {
                $score += 10;
            } elseif ($topic['source'] === 'rss_feeds') {
                $score += 5;
            }
        }

        return min($score, 100);
    }

    /**
     * Calculate uniqueness score for a topic
     *
     * @param array $topic Topic data
     * @return float Score 0-100
     */
    private function calculate_uniqueness_score($topic) {
        global $wpdb;

        $score = 70; // Start higher, deduct for similarities

        $title = $topic['title'] ?? '';

        // Check against recent blog posts
        $recent_posts = $wpdb->get_col(
            "SELECT post_title FROM {$wpdb->posts}
             WHERE post_type = 'post'
             AND post_status IN ('publish', 'draft')
             AND post_date > DATE_SUB(NOW(), INTERVAL 6 MONTH)
             LIMIT 50"
        );

        foreach ($recent_posts as $post_title) {
            $similarity = similar_text(strtolower($title), strtolower($post_title), $percent);
            if ($percent > 50) {
                $score -= 20;
            } elseif ($percent > 30) {
                $score -= 10;
            }
        }

        // Check against recent topics in our table
        $topics_table = $wpdb->prefix . 'mld_blog_topics';
        $recent_topics = $wpdb->get_col($wpdb->prepare(
            "SELECT title FROM $topics_table
             WHERE created_at > DATE_SUB(%s, INTERVAL 30 DAY)
             AND status != 'archived'
             LIMIT 50",
            current_time('mysql')
        ));

        foreach ($recent_topics as $recent_title) {
            $similarity = similar_text(strtolower($title), strtolower($recent_title), $percent);
            if ($percent > 60) {
                $score -= 30;
            } elseif ($percent > 40) {
                $score -= 15;
            }
        }

        // Generic topic penalty
        $generic_phrases = array(
            'tips for buying',
            'things to know',
            'guide to',
            'everything you need',
            'complete guide',
        );

        foreach ($generic_phrases as $phrase) {
            if (stripos($title, $phrase) !== false) {
                $score -= 10;
            }
        }

        return max($score, 0);
    }

    /**
     * Deduplicate similar topics
     *
     * @param array $topics Topics to deduplicate
     * @return array Deduplicated topics
     */
    private function deduplicate_topics($topics) {
        $unique_topics = array();

        foreach ($topics as $topic) {
            $is_duplicate = false;

            foreach ($unique_topics as $unique) {
                similar_text(
                    strtolower($topic['title'] ?? ''),
                    strtolower($unique['title'] ?? ''),
                    $percent
                );

                if ($percent > 70) {
                    $is_duplicate = true;
                    break;
                }
            }

            if (!$is_duplicate) {
                $unique_topics[] = $topic;
            }
        }

        return $unique_topics;
    }

    /**
     * Exclude recently used topics
     *
     * @param array $topics Topics to filter
     * @return array Filtered topics
     */
    private function exclude_recent_topics($topics) {
        global $wpdb;

        $topics_table = $wpdb->prefix . 'mld_blog_topics';

        $recent_slugs = $wpdb->get_col($wpdb->prepare(
            "SELECT slug FROM $topics_table
             WHERE status IN ('selected', 'generated', 'published')
             AND created_at > DATE_SUB(%s, INTERVAL 60 DAY)",
            current_time('mysql')
        ));

        if (empty($recent_slugs)) {
            return $topics;
        }

        return array_filter($topics, function($topic) use ($recent_slugs) {
            $slug = sanitize_title($topic['title'] ?? '');
            return !in_array($slug, $recent_slugs);
        });
    }

    /**
     * Save topics to database
     *
     * @param array $topics Topics to save
     */
    private function save_topics($topics) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        foreach ($topics as $topic) {
            $slug = sanitize_title($topic['title'] ?? '');

            // Check if topic already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s",
                $slug
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($table, array(
                'title' => $topic['title'] ?? '',
                'slug' => $slug,
                'description' => $topic['description'] ?? '',
                'relevance_score' => $topic['relevance_score'] ?? 0,
                'recency_score' => $topic['recency_score'] ?? 0,
                'authority_score' => $topic['authority_score'] ?? 0,
                'uniqueness_score' => $topic['uniqueness_score'] ?? 0,
                'total_score' => $topic['total_score'] ?? 0,
                'source' => $topic['source'] ?? 'manual',
                'source_url' => $topic['source_url'] ?? '',
                'keywords' => json_encode($topic['keywords'] ?? array()),
                'related_cities' => json_encode($topic['related_cities'] ?? array()),
                'status' => 'pending',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            ));
        }
    }

    /**
     * Get research prompt
     *
     * @return string
     */
    private function get_research_prompt() {
        if ($this->prompt_manager) {
            $prompt_data = $this->prompt_manager->get_prompt('topic_research');
            if ($prompt_data && !empty($prompt_data['prompt_content'])) {
                return $prompt_data['prompt_content'];
            }
        }

        // Fallback to default
        return "You are a real estate content strategist. Identify trending, relevant topics for a Boston area real estate blog.";
    }

    /**
     * Call AI provider
     *
     * @param array $messages Messages to send
     * @param string $system_prompt System prompt
     * @return array AI response
     */
    private function call_ai($messages, $system_prompt) {
        $this->blog_log('call_ai: Starting AI call');

        if (!$this->ai_provider) {
            $this->blog_log('call_ai: No AI provider');
            return array(
                'success' => false,
                'error' => 'AI provider not available',
            );
        }

        $context = array(
            'system_prompt' => $system_prompt,
        );

        $options = array(
            'max_tokens' => 2000,
            'temperature' => 0.7,
        );

        try {
            $this->blog_log('call_ai: Provider class: ' . get_class($this->ai_provider));

            if (method_exists($this->ai_provider, 'route')) {
                // Model router
                $this->blog_log('call_ai: Using route method');
                $result = $this->ai_provider->route($messages[0]['content'], $context);
            } else {
                // Direct provider
                $this->blog_log('call_ai: Using chat method');
                $result = $this->ai_provider->chat($messages, $context, $options);
            }

            // Log the raw result for debugging
            $this->blog_log('call_ai: Result: success=' . ($result['success'] ? 'true' : 'false') .
                ', has_text=' . (!empty($result['text']) ? 'yes(' . strlen($result['text']) . ' chars)' : 'no') .
                ', error=' . ($result['error'] ?? 'none'));

            if (!$result['success']) {
                $this->blog_log('call_ai: AI call failed: ' . ($result['error'] ?? 'Unknown error'));
                return array(
                    'success' => false,
                    'error' => 'AI call failed: ' . ($result['error'] ?? 'Unknown error'),
                    'topics' => array(),
                );
            }

            // Parse JSON from response
            $text = $result['text'] ?? '';

            if (empty($text)) {
                $this->blog_log('call_ai: AI returned empty text');
                return array(
                    'success' => false,
                    'error' => 'AI returned empty response',
                    'topics' => array(),
                );
            }

            $this->blog_log('call_ai: AI response (first 500 chars): ' . substr($text, 0, 500));

            $topics = $this->parse_topics_from_response($text);

            $this->blog_log('call_ai: Parsed ' . count($topics) . ' topics from response');

            return array(
                'success' => true,
                'topics' => $topics,
                'raw_response' => $text,
            );

        } catch (Exception $e) {
            $this->blog_log('call_ai: Exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Parse topics from AI response
     *
     * @param string $response AI response text
     * @return array Parsed topics
     */
    private function parse_topics_from_response($response) {
        $topics = array();

        // Try to extract JSON
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $json_str = $matches[1];
        } elseif (preg_match('/\{[\s\S]*"topics"[\s\S]*\}/', $response, $matches)) {
            $json_str = $matches[0];
        } else {
            $json_str = $response;
        }

        $data = json_decode($json_str, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($data['topics']) && is_array($data['topics'])) {
                $topics = $data['topics'];
            } elseif (is_array($data) && isset($data[0]['title'])) {
                $topics = $data;
            }
        }

        return $topics;
    }

    /**
     * Get a specific topic by ID
     *
     * @param int $topic_id Topic ID
     * @return array|null Topic data
     */
    public function get_topic($topic_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $topic_id
        ), ARRAY_A);

        if ($topic) {
            $topic['keywords'] = json_decode($topic['keywords'], true) ?: array();
            $topic['related_cities'] = json_decode($topic['related_cities'], true) ?: array();
        }

        return $topic;
    }

    /**
     * Get pending topics
     *
     * @param int $limit Number of topics to return
     * @return array Topics
     */
    public function get_pending_topics($limit = 10) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        $topics = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE status = 'pending'
             AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY total_score DESC
             LIMIT %d",
            current_time('mysql'),
            $limit
        ), ARRAY_A);

        foreach ($topics as &$topic) {
            $topic['keywords'] = json_decode($topic['keywords'], true) ?: array();
            $topic['related_cities'] = json_decode($topic['related_cities'], true) ?: array();
        }

        return $topics;
    }

    /**
     * Mark a topic as selected
     *
     * @param int $topic_id Topic ID
     * @return bool Success
     */
    public function select_topic($topic_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        return $wpdb->update(
            $table,
            array('status' => 'selected'),
            array('id' => $topic_id)
        ) !== false;
    }

    /**
     * Create a custom topic
     *
     * @param array $topic_data Topic data
     * @return int|false Topic ID or false on failure
     */
    public function create_custom_topic($topic_data) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        $slug = sanitize_title($topic_data['title'] ?? '');

        $result = $wpdb->insert($table, array(
            'title' => $topic_data['title'] ?? '',
            'slug' => $slug,
            'description' => $topic_data['description'] ?? '',
            'relevance_score' => 100,
            'recency_score' => 100,
            'authority_score' => 100,
            'uniqueness_score' => 100,
            'total_score' => 100,
            'source' => 'manual',
            'keywords' => json_encode($topic_data['keywords'] ?? array()),
            'related_cities' => json_encode($topic_data['related_cities'] ?? array()),
            'status' => 'selected',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ));

        return $result ? $wpdb->insert_id : false;
    }
}
