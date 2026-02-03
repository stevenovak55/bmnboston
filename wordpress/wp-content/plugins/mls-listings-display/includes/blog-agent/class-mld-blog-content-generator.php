<?php
/**
 * Blog Content Generator
 *
 * AI-powered article generation with structured output, local data integration,
 * and iterative section writing.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Content_Generator
 *
 * Generates blog articles using AI with structured prompts.
 */
class MLD_Blog_Content_Generator {

    /**
     * Log to dedicated Blog Agent log file
     */
    private function blog_log($message) {
        $log_file = WP_CONTENT_DIR . '/blog-agent-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] [ContentGenerator] $message\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * AI provider instance
     *
     * @var object
     */
    private $ai_provider;

    /**
     * Prompt manager
     *
     * @var MLD_Blog_Prompt_Manager
     */
    private $prompt_manager;

    /**
     * Internal linker
     *
     * @var MLD_Blog_Internal_Linker
     */
    private $internal_linker;

    /**
     * CTA manager
     *
     * @var MLD_Blog_CTA_Manager
     */
    private $cta_manager;

    /**
     * Generation statistics
     *
     * @var array
     */
    private $generation_stats = array(
        'tokens_used' => 0,
        'cost' => 0,
        'sections_generated' => 0,
        'ai_calls' => 0,
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
        if (!class_exists('MLD_Model_Router')) {
            if (file_exists($chatbot_path . 'class-mld-model-router.php')) {
                require_once $chatbot_path . 'class-mld-model-router.php';
            }
        }
        if (!class_exists('MLD_Claude_Provider')) {
            if (file_exists($chatbot_path . 'providers/class-mld-claude-provider.php')) {
                require_once $chatbot_path . 'providers/class-mld-claude-provider.php';
            }
        }

        // Try Claude provider first
        if (class_exists('MLD_Claude_Provider')) {
            try {
                $this->ai_provider = new MLD_Claude_Provider();
            } catch (Exception $e) {
                // Fall through to Model Router
            }
        }

        // Fallback to Model Router (singleton pattern)
        if (!$this->ai_provider && class_exists('MLD_Model_Router')) {
            try {
                $router = MLD_Model_Router::get_instance();
                if ($router->is_enabled()) {
                    $this->ai_provider = $router;
                }
            } catch (Exception $e) {
                // No provider available
            }
        }
    }

    /**
     * Set dependencies
     *
     * @param MLD_Blog_Prompt_Manager $prompt_manager
     * @param MLD_Blog_Internal_Linker $internal_linker
     * @param MLD_Blog_CTA_Manager $cta_manager
     */
    public function set_dependencies($prompt_manager, $internal_linker, $cta_manager) {
        $this->prompt_manager = $prompt_manager;
        $this->internal_linker = $internal_linker;
        $this->cta_manager = $cta_manager;
    }

    /**
     * Generate a complete article from a topic
     *
     * @param array $topic Topic data
     * @param array $options Generation options
     * @return array Generation result
     */
    public function generate_article($topic, $options = array()) {
        $this->blog_log('generate_article: starting');

        $defaults = array(
            'target_length' => 2000,
            'include_market_data' => true,
            'include_school_data' => true,
            'cta_type' => 'auto',
            'tone' => 'professional',
        );
        $options = wp_parse_args($options, $defaults);

        // Reset stats
        $this->generation_stats = array(
            'tokens_used' => 0,
            'cost' => 0,
            'sections_generated' => 0,
            'ai_calls' => 0,
        );

        // Step 1: Generate article structure
        $this->blog_log('generate_article: Step 1 - generating structure');
        $structure = $this->generate_structure($topic, $options);
        if (!$structure['success']) {
            $this->blog_log('generate_article: Step 1 FAILED - ' . ($structure['error'] ?? 'unknown'));
            return $structure;
        }
        $this->blog_log('generate_article: Step 1 complete');

        // Step 2: Gather local data for context
        $this->blog_log('generate_article: Step 2 - gathering local data');
        $local_data = $this->gather_local_data($topic, $structure['data']);
        $this->blog_log('generate_article: Step 2 complete');

        // Step 3: Generate each section
        $this->blog_log('generate_article: Step 3 - generating sections');
        $sections = $this->generate_sections($structure['data'], $local_data, $options);
        if (!$sections['success']) {
            $this->blog_log('generate_article: Step 3 FAILED - ' . ($sections['error'] ?? 'unknown'));
            return $sections;
        }
        $this->blog_log('generate_article: Step 3 complete - ' . count($sections['sections']) . ' sections');

        // Step 4: Assemble full article
        $this->blog_log('generate_article: Step 4 - assembling article');
        $article = $this->assemble_article($structure['data'], $sections['sections'], $options);
        $this->blog_log('generate_article: Step 4 complete');

        // Step 5: Insert internal links
        $this->blog_log('generate_article: Step 5 - inserting internal links');
        if ($this->internal_linker) {
            $article['content'] = $this->internal_linker->process_content($article['content']);
        }
        $this->blog_log('generate_article: Step 5 complete');

        // Step 6: Add CTA
        $this->blog_log('generate_article: Step 6 - adding CTA');
        if ($this->cta_manager) {
            $cta = $this->cta_manager->get_cta_for_topic($topic, $options['cta_type']);
            $article['content'] = $this->insert_cta($article['content'], $cta);
            $article['cta_type'] = $cta['type'];
            $article['cta_position'] = $cta['position'];
        }
        $this->blog_log('generate_article: Step 6 complete');

        // Step 7: Generate meta content
        $this->blog_log('generate_article: Step 7 - generating meta content');
        $meta = $this->generate_meta_content($article, $structure['data']);
        $this->blog_log('generate_article: Step 7 complete');

        $this->blog_log('generate_article: SUCCESS - returning article');
        return array(
            'success' => true,
            'article' => array(
                'title' => $structure['data']['title'],
                'content' => $article['content'],
                'meta_description' => $meta['description'],
                'meta_keywords' => $meta['keywords'],
                'word_count' => str_word_count(strip_tags($article['content'])),
                'cta_type' => $article['cta_type'] ?? '',
                'cta_position' => $article['cta_position'] ?? '',
                'structure' => $structure['data'],
            ),
            'stats' => $this->generation_stats,
            'prompt_version' => $this->get_prompt_version(),
        );
    }

    /**
     * Generate article structure
     *
     * @param array $topic Topic data
     * @param array $options Options
     * @return array Structure result
     */
    private function generate_structure($topic, $options) {
        $this->blog_log('generate_structure: starting');

        $prompt = $this->get_prompt('article_structure');
        $this->blog_log('generate_structure: system prompt length=' . strlen($prompt));

        $user_message = $this->build_structure_prompt($topic, $options);
        $this->blog_log('generate_structure: user message length=' . strlen($user_message));

        // Add explicit JSON format request to user message
        $user_message .= "\n\nIMPORTANT: Respond with a JSON object in this exact format:\n";
        $user_message .= "```json\n";
        $user_message .= "{\n";
        $user_message .= "  \"title\": \"Article title (50-60 characters)\",\n";
        $user_message .= "  \"meta_description\": \"Meta description (140-155 characters)\",\n";
        $user_message .= "  \"primary_keyword\": \"main keyword\",\n";
        $user_message .= "  \"secondary_keywords\": [\"keyword1\", \"keyword2\"],\n";
        $user_message .= "  \"introduction\": {\"hook\": \"Opening hook\", \"preview\": \"Article preview\"},\n";
        $user_message .= "  \"sections\": [\n";
        $user_message .= "    {\"heading\": \"Section 1 Heading\", \"key_points\": [\"point 1\", \"point 2\"]}\n";
        $user_message .= "  ],\n";
        $user_message .= "  \"conclusion\": {\"summary\": \"Key takeaways\", \"cta\": \"Call to action\"}\n";
        $user_message .= "}\n";
        $user_message .= "```\n";
        $user_message .= "Respond ONLY with the JSON, no other text.";

        $result = $this->call_ai($user_message, $prompt);

        $this->blog_log('generate_structure: AI call result success=' . ($result['success'] ? 'true' : 'false'));

        if (!$result['success']) {
            $this->blog_log('generate_structure: AI call failed - ' . ($result['error'] ?? 'unknown'));
            return $result;
        }

        $this->blog_log('generate_structure: AI response (first 500 chars): ' . substr($result['text'], 0, 500));

        $structure = $this->parse_structure_response($result['text']);

        $this->blog_log('generate_structure: parsed title=' . ($structure['title'] ?? 'none') . ', sections=' . count($structure['sections'] ?? []));

        if (empty($structure['title']) || empty($structure['sections'])) {
            $this->blog_log('generate_structure: parse failed - empty title or sections');
            return array(
                'success' => false,
                'error' => 'Failed to parse article structure from AI response',
            );
        }

        $this->blog_log('generate_structure: success');
        return array(
            'success' => true,
            'data' => $structure,
        );
    }

    /**
     * Build structure generation prompt
     *
     * @param array $topic Topic data
     * @param array $options Options
     * @return string User message
     */
    private function build_structure_prompt($topic, $options) {
        $message = "Create an article structure for the following topic:\n\n";
        $message .= "**Topic:** " . ($topic['title'] ?? 'Untitled') . "\n";
        $message .= "**Description:** " . ($topic['description'] ?? '') . "\n";

        if (!empty($topic['keywords'])) {
            $keywords = is_array($topic['keywords']) ? $topic['keywords'] : json_decode($topic['keywords'], true);
            if ($keywords) {
                $message .= "**Keywords:** " . implode(', ', $keywords) . "\n";
            }
        }

        if (!empty($topic['related_cities'])) {
            $cities = is_array($topic['related_cities']) ? $topic['related_cities'] : json_decode($topic['related_cities'], true);
            if ($cities) {
                $message .= "**Related Cities:** " . implode(', ', $cities) . "\n";
            }
        }

        $message .= "\n**Target Length:** " . $options['target_length'] . " words\n";
        $message .= "**Tone:** " . $options['tone'] . "\n";
        $message .= "**Current Date:** " . current_time('F j, Y') . "\n";

        return $message;
    }

    /**
     * Parse structure from AI response
     *
     * @param string $response AI response
     * @return array Parsed structure
     */
    private function parse_structure_response($response) {
        $structure = array(
            'title' => '',
            'meta_description' => '',
            'primary_keyword' => '',
            'secondary_keywords' => array(),
            'introduction' => array(),
            'sections' => array(),
            'conclusion' => array(),
        );

        // Extract JSON from response
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $json_str = $matches[1];
        } elseif (preg_match('/\{[\s\S]*"title"[\s\S]*\}/', $response, $matches)) {
            $json_str = $matches[0];
        } else {
            return $structure;
        }

        $data = json_decode($json_str, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $structure = array_merge($structure, $data);
        }

        return $structure;
    }

    /**
     * Gather local market and school data
     *
     * @param array $topic Topic data
     * @param array $structure Article structure
     * @return array Local data
     */
    private function gather_local_data($topic, $structure) {
        $this->blog_log('gather_local_data: starting');

        $data = array(
            'market' => array(),
            'schools' => array(),
            'properties' => array(),
        );

        // Get related cities (limit to 2 for performance)
        $cities = array();
        if (!empty($topic['related_cities'])) {
            $cities = is_array($topic['related_cities']) ? $topic['related_cities'] : json_decode($topic['related_cities'], true);
        }
        if (empty($cities)) {
            $cities = array('Boston');
        }
        $cities = array_slice($cities, 0, 2);

        $this->blog_log('gather_local_data: cities=' . implode(', ', $cities));

        // Gather market data with caching
        foreach ($cities as $city) {
            $cache_key = 'blog_market_' . sanitize_key($city);
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                $data['market'][$city] = $cached;
                $this->blog_log("gather_local_data: using cached market data for $city");
                continue;
            }

            if (class_exists('MLD_Market_Data_Calculator')) {
                $this->blog_log("gather_local_data: fetching market data for $city");
                try {
                    $calculator = new MLD_Market_Data_Calculator();
                    $market_data = array(
                        'median_price' => $calculator->get_median_price(array('city' => $city)),
                        'avg_dom' => $calculator->get_average_days_on_market(array('city' => $city)),
                        'inventory' => $calculator->get_active_inventory(array('city' => $city)),
                    );
                    $data['market'][$city] = $market_data;
                    set_transient($cache_key, $market_data, HOUR_IN_SECONDS);
                } catch (Exception $e) {
                    $this->blog_log("gather_local_data: market data error for $city - " . $e->getMessage());
                }
            }
        }

        // Gather school data with caching
        foreach ($cities as $city) {
            $cache_key = 'blog_schools_' . sanitize_key($city);
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                $data['schools'][$city] = $cached;
                $this->blog_log("gather_local_data: using cached school data for $city");
                continue;
            }

            if (class_exists('MLD_BMN_Schools_Integration')) {
                $this->blog_log("gather_local_data: fetching school data for $city");
                try {
                    $schools = new MLD_BMN_Schools_Integration();
                    $city_schools = $schools->get_schools_by_city($city, array('limit' => 3));
                    if ($city_schools) {
                        $data['schools'][$city] = $city_schools;
                        set_transient($cache_key, $city_schools, HOUR_IN_SECONDS);
                    }
                } catch (Exception $e) {
                    $this->blog_log("gather_local_data: school data error for $city - " . $e->getMessage());
                }
            }
        }

        // Get sample properties (fast query with index)
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        foreach ($cities as $city) {
            $cache_key = 'blog_props_' . sanitize_key($city);
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                $data['properties'][$city] = $cached;
                continue;
            }

            $properties = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, full_street_address, list_price, bedrooms_total, bathrooms_full, living_area
                 FROM $summary_table
                 WHERE city = %s
                 AND standard_status = 'Active'
                 ORDER BY original_entry_timestamp DESC
                 LIMIT 3",
                $city
            ), ARRAY_A);

            if ($properties) {
                $data['properties'][$city] = $properties;
                set_transient($cache_key, $properties, HOUR_IN_SECONDS);
            }
        }

        $this->blog_log('gather_local_data: complete - market=' . count($data['market']) .
            ', schools=' . count($data['schools']) . ', properties=' . count($data['properties']));
        return $data;
    }

    /**
     * Generate article sections
     *
     * @param array $structure Article structure
     * @param array $local_data Local data context
     * @param array $options Options
     * @return array Sections result
     */
    private function generate_sections($structure, $local_data, $options) {
        $sections = array();

        $section_prompt = $this->get_prompt('section_writing');

        // Generate introduction
        $intro_content = $this->generate_section(
            'introduction',
            $structure['introduction'] ?? array(),
            $structure,
            $local_data,
            $section_prompt,
            $options
        );
        $sections['introduction'] = $intro_content;

        // Generate main sections
        foreach ($structure['sections'] as $index => $section) {
            $section_content = $this->generate_section(
                'section_' . ($index + 1),
                $section,
                $structure,
                $local_data,
                $section_prompt,
                $options
            );
            $sections['section_' . ($index + 1)] = $section_content;
            $this->generation_stats['sections_generated']++;
        }

        // Generate conclusion
        $conclusion_content = $this->generate_section(
            'conclusion',
            $structure['conclusion'] ?? array(),
            $structure,
            $local_data,
            $section_prompt,
            $options
        );
        $sections['conclusion'] = $conclusion_content;

        return array(
            'success' => true,
            'sections' => $sections,
        );
    }

    /**
     * Generate a single section
     *
     * @param string $section_type Section type identifier
     * @param array $section_data Section structure data
     * @param array $full_structure Full article structure
     * @param array $local_data Local data context
     * @param string $prompt System prompt
     * @param array $options Options
     * @return string Generated content
     */
    private function generate_section($section_type, $section_data, $full_structure, $local_data, $prompt, $options) {
        $user_message = $this->build_section_prompt($section_type, $section_data, $full_structure, $local_data, $options);

        $result = $this->call_ai($user_message, $prompt, array('max_tokens' => 1500));

        if ($result['success']) {
            return $this->clean_section_content($result['text']);
        }

        // Fallback: return a placeholder
        return "<!-- Section generation failed: " . esc_html($section_type) . " -->\n\n";
    }

    /**
     * Build section generation prompt
     *
     * @param string $section_type Section type
     * @param array $section_data Section data
     * @param array $full_structure Full structure
     * @param array $local_data Local data
     * @param array $options Options
     * @return string User message
     */
    private function build_section_prompt($section_type, $section_data, $full_structure, $local_data, $options) {
        $message = "Write the **" . str_replace('_', ' ', $section_type) . "** section of an article.\n\n";

        $message .= "**Article Title:** " . ($full_structure['title'] ?? 'Untitled') . "\n";
        $message .= "**Primary Keyword:** " . ($full_structure['primary_keyword'] ?? '') . "\n\n";

        if ($section_type === 'introduction') {
            $message .= "**Introduction Requirements:**\n";
            $message .= "- Hook: " . ($section_data['hook'] ?? 'Engage the reader immediately') . "\n";
            $message .= "- Context: " . ($section_data['context'] ?? 'Establish relevance') . "\n";
            $message .= "- Preview: " . ($section_data['preview'] ?? 'What readers will learn') . "\n";
            $message .= "- Length: 150-200 words\n";
        } elseif ($section_type === 'conclusion') {
            $message .= "**Conclusion Requirements:**\n";
            $message .= "- Summarize key points: " . implode(', ', $section_data['summary_points'] ?? array()) . "\n";
            $message .= "- End with a forward-looking statement\n";
            $message .= "- Length: 100-150 words\n";
        } else {
            $message .= "**Section Requirements:**\n";
            $message .= "- Heading: " . ($section_data['heading'] ?? '') . "\n";
            $message .= "- Key Points: " . implode(', ', $section_data['key_points'] ?? array()) . "\n";

            if (!empty($section_data['subsections'])) {
                $message .= "- Subsections:\n";
                foreach ($section_data['subsections'] as $sub) {
                    $message .= "  - " . ($sub['heading'] ?? '') . "\n";
                }
            }

            if (!empty($section_data['internal_link_opportunity'])) {
                $message .= "- Include natural link opportunity for: " . $section_data['internal_link_opportunity'] . "\n";
            }

            if (!empty($section_data['data_to_include'])) {
                $message .= "- Data to incorporate: " . $section_data['data_to_include'] . "\n";
            }

            $message .= "- Length: 250-400 words\n";
        }

        // Add local data context
        if (!empty($local_data['market'])) {
            $message .= "\n**Local Market Data (use if relevant):**\n";
            foreach ($local_data['market'] as $city => $stats) {
                $message .= "- $city: ";
                if (!empty($stats['median_price'])) {
                    $message .= "Median price $" . number_format($stats['median_price']) . ", ";
                }
                if (!empty($stats['avg_dom'])) {
                    $message .= "Avg " . $stats['avg_dom'] . " days on market, ";
                }
                if (!empty($stats['inventory'])) {
                    $message .= $stats['inventory'] . " active listings";
                }
                $message .= "\n";
            }
        }

        if (!empty($local_data['schools'])) {
            $message .= "\n**Local School Data (use if relevant):**\n";
            foreach ($local_data['schools'] as $city => $schools) {
                $message .= "- $city top schools: ";
                $school_names = array_column(array_slice($schools, 0, 3), 'school_name');
                $message .= implode(', ', $school_names) . "\n";
            }
        }

        $message .= "\n**Writing Guidelines:**\n";
        $message .= "- Tone: " . $options['tone'] . "\n";
        $message .= "- Use Markdown formatting (## for H2, ### for H3, **bold**, etc.)\n";
        $message .= "- Include specific Boston/MA references\n";
        $message .= "- Use [link text](INTERNAL:type) for internal link placeholders\n";

        return $message;
    }

    /**
     * Clean section content
     *
     * @param string $content Raw content
     * @return string Cleaned content
     */
    private function clean_section_content($content) {
        // Remove markdown code blocks if AI wrapped the response
        $content = preg_replace('/^```(?:markdown|md)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);

        // Trim whitespace
        $content = trim($content);

        return $content;
    }

    /**
     * Assemble full article from sections
     *
     * @param array $structure Article structure
     * @param array $sections Generated sections
     * @param array $options Options
     * @return array Assembled article
     */
    private function assemble_article($structure, $sections, $options) {
        $content = '';

        // Introduction
        if (!empty($sections['introduction'])) {
            $content .= $sections['introduction'] . "\n\n";
        }

        // Main sections
        $section_index = 1;
        while (isset($sections['section_' . $section_index])) {
            $content .= $sections['section_' . $section_index] . "\n\n";
            $section_index++;
        }

        // Conclusion
        if (!empty($sections['conclusion'])) {
            $content .= $sections['conclusion'] . "\n\n";
        }

        // Convert Markdown to HTML
        $content = $this->markdown_to_html($content);

        return array(
            'content' => $content,
        );
    }

    /**
     * Convert Markdown to HTML
     *
     * @param string $markdown Markdown content
     * @return string HTML content
     */
    private function markdown_to_html($markdown) {
        // Simple Markdown to HTML conversion
        // For production, consider using a library like Parsedown

        $html = $markdown;

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Links (preserve INTERNAL: and EXTERNAL: markers for linker)
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);

        // Bullet lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.+<\/li>\n?)+/', '<ul>$0</ul>', $html);

        // Numbered lists
        $html = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $html);

        // Paragraphs
        $html = preg_replace('/\n\n+/', '</p><p>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        $html = preg_replace('/<p>\s*(<h[23]>)/', '$1', $html);
        $html = preg_replace('/(<\/h[23]>)\s*<\/p>/', '$1', $html);
        $html = preg_replace('/<p>\s*(<ul>)/', '$1', $html);
        $html = preg_replace('/(<\/ul>)\s*<\/p>/', '$1', $html);

        return $html;
    }

    /**
     * Insert CTA into content
     *
     * @param string $content Article content
     * @param array $cta CTA data
     * @return string Content with CTA
     */
    private function insert_cta($content, $cta) {
        if (empty($cta['html'])) {
            return $content;
        }

        $position = $cta['position'] ?? 'end';

        if ($position === 'end') {
            $content .= "\n\n" . $cta['html'];
        } elseif ($position === 'middle') {
            // Insert after ~60% of content
            $paragraphs = preg_split('/(<\/p>|<\/h[23]>|<\/ul>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $total = count($paragraphs);
            $insert_point = (int) ($total * 0.6);

            $before = implode('', array_slice($paragraphs, 0, $insert_point));
            $after = implode('', array_slice($paragraphs, $insert_point));

            $content = $before . "\n\n" . $cta['html'] . "\n\n" . $after;
        }

        return $content;
    }

    /**
     * Generate meta description and keywords
     *
     * @param array $article Article data
     * @param array $structure Structure data
     * @return array Meta content
     */
    private function generate_meta_content($article, $structure) {
        $meta = array(
            'description' => '',
            'keywords' => '',
        );

        // Use structure meta if available
        if (!empty($structure['meta_description'])) {
            $meta['description'] = substr($structure['meta_description'], 0, 155);
        } else {
            // Generate from content
            $plain_text = strip_tags($article['content']);
            $sentences = preg_split('/[.!?]/', $plain_text, 3);
            $meta['description'] = trim($sentences[0] ?? '') . '.';
            if (strlen($meta['description']) < 100 && !empty($sentences[1])) {
                $meta['description'] .= ' ' . trim($sentences[1]) . '.';
            }
            $meta['description'] = substr($meta['description'], 0, 155);
        }

        // Keywords
        $keywords = array();
        if (!empty($structure['primary_keyword'])) {
            $keywords[] = $structure['primary_keyword'];
        }
        if (!empty($structure['secondary_keywords'])) {
            $keywords = array_merge($keywords, $structure['secondary_keywords']);
        }
        $meta['keywords'] = implode(', ', array_slice($keywords, 0, 10));

        return $meta;
    }

    /**
     * Get prompt by key
     *
     * @param string $key Prompt key
     * @return string Prompt content
     */
    private function get_prompt($key) {
        if ($this->prompt_manager) {
            $prompt_data = $this->prompt_manager->get_prompt($key);
            if ($prompt_data && !empty($prompt_data['prompt_content'])) {
                return $prompt_data['prompt_content'];
            }
        }

        // Fallback defaults
        $defaults = array(
            'article_structure' => 'Create a structured blog article outline for a Boston real estate topic.',
            'section_writing' => 'Write engaging, informative content for a real estate blog section.',
        );

        return $defaults[$key] ?? '';
    }

    /**
     * Get current prompt version
     *
     * @return string Version string
     */
    private function get_prompt_version() {
        if ($this->prompt_manager) {
            return $this->prompt_manager->get_current_version();
        }
        return '1.0.0';
    }

    /**
     * Call AI provider
     *
     * @param string $user_message User message
     * @param string $system_prompt System prompt
     * @param array $options AI options
     * @return array AI response
     */
    private function call_ai($user_message, $system_prompt, $options = array()) {
        $this->blog_log('call_ai: starting');

        if (!$this->ai_provider) {
            $this->blog_log('call_ai: no AI provider');
            return array(
                'success' => false,
                'error' => 'AI provider not available',
            );
        }

        $defaults = array(
            'max_tokens' => 2000,
            'temperature' => 0.7,
        );
        $options = wp_parse_args($options, $defaults);

        $context = array(
            'system_prompt' => $system_prompt,
        );

        $messages = array(
            array('role' => 'user', 'content' => $user_message),
        );

        $this->generation_stats['ai_calls']++;

        try {
            $this->blog_log('call_ai: provider=' . get_class($this->ai_provider));

            if (method_exists($this->ai_provider, 'route')) {
                $this->blog_log('call_ai: using route method');
                $result = $this->ai_provider->route($user_message, $context);
            } else {
                $this->blog_log('call_ai: using chat method');
                $result = $this->ai_provider->chat($messages, $context, $options);
            }

            $this->blog_log('call_ai: result success=' . ($result['success'] ? 'true' : 'false') .
                ', has_text=' . (!empty($result['text']) ? 'yes(' . strlen($result['text']) . ')' : 'no') .
                ', error=' . ($result['error'] ?? 'none'));

            if ($result['success']) {
                // Track tokens
                if (!empty($result['tokens']['total_tokens'])) {
                    $this->generation_stats['tokens_used'] += $result['tokens']['total_tokens'];
                }

                // Estimate cost
                if (!empty($result['tokens'])) {
                    $input = $result['tokens']['prompt_tokens'] ?? 0;
                    $output = $result['tokens']['completion_tokens'] ?? 0;
                    // Rough estimate based on Claude pricing
                    $this->generation_stats['cost'] += ($input * 0.000003) + ($output * 0.000015);
                }
            }

            return $result;

        } catch (Exception $e) {
            $this->blog_log('call_ai: exception - ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Regenerate a specific section
     *
     * @param array $article_data Full article data
     * @param string $section_key Section to regenerate
     * @param string $feedback User feedback for improvement
     * @return array Regeneration result
     */
    public function regenerate_section($article_data, $section_key, $feedback = '') {
        $structure = $article_data['structure'] ?? array();
        $local_data = $this->gather_local_data(array(), $structure);

        $section_prompt = $this->get_prompt('section_writing');

        // Find the section data
        $section_data = array();
        if ($section_key === 'introduction') {
            $section_data = $structure['introduction'] ?? array();
        } elseif ($section_key === 'conclusion') {
            $section_data = $structure['conclusion'] ?? array();
        } else {
            $index = (int) str_replace('section_', '', $section_key) - 1;
            if (isset($structure['sections'][$index])) {
                $section_data = $structure['sections'][$index];
            }
        }

        // Add feedback to prompt
        $enhanced_prompt = $section_prompt;
        if (!empty($feedback)) {
            $enhanced_prompt .= "\n\n**User Feedback for Improvement:**\n" . $feedback;
        }

        $content = $this->generate_section(
            $section_key,
            $section_data,
            $structure,
            $local_data,
            $enhanced_prompt,
            array('tone' => 'professional')
        );

        return array(
            'success' => true,
            'section_key' => $section_key,
            'content' => $content,
        );
    }
}
