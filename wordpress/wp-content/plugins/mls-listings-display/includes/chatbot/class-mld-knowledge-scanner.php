<?php
/**
 * Enhanced Knowledge Base Scanner
 *
 * Scans database structure and creates references to data locations
 * instead of copying actual data. Teaches the AI where to find information.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Knowledge_Scanner {

    /**
     * Content types to scan
     *
     * @var array
     */
    private $content_types = array(
        'data_references' => 'Database Structure & Query Templates',
        'listings_metadata' => 'Property Listings Metadata',
        'pages' => 'Website Pages (Full Content)',
        'posts' => 'Blog Posts (Full Content)',
        'analytics_metadata' => 'Market Analytics Metadata',
        'business_info' => 'Business Information',
        'agent_metadata' => 'Agent Information Metadata',
        'query_patterns' => 'Common Query Patterns'
    );

    /**
     * Data reference mapper instance
     *
     * @var MLD_Data_Reference_Mapper
     */
    private $data_mapper;

    /**
     * Constructor
     */
    public function __construct() {
        // Register manual scan AJAX handler
        add_action('wp_ajax_mld_trigger_knowledge_scan', array($this, 'ajax_trigger_scan'));

        // Initialize data reference mapper
        require_once dirname(__FILE__) . '/class-mld-data-reference-mapper.php';
        $this->data_mapper = new MLD_Data_Reference_Mapper();
    }

    /**
     * Run full knowledge base scan
     *
     * Creates references to data locations rather than copying data
     *
     * @return array Scan results
     */
    public function run_full_scan() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Knowledge Scanner Enhanced] Starting reference-based knowledge scan');
        }

        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'scanned' => 0,
            'updated' => 0,
            'errors' => 0,
            'content_types' => array(),
        );

        // Get enabled content types from settings
        $enabled_types = $this->get_enabled_content_types();

        foreach ($enabled_types as $type) {
            if (isset($this->content_types[$type])) {
                $method = 'scan_' . $type;
                if (method_exists($this, $method)) {
                    try {
                        $type_result = $this->$method();
                        $results['scanned'] += $type_result['scanned'];
                        $results['updated'] += $type_result['updated'];
                        $results['content_types'][$type] = $type_result;
                    } catch (Exception $e) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[MLD Knowledge Scanner Enhanced] Error scanning ' . $type . ': ' . $e->getMessage());
                        }
                        $results['errors']++;
                        $results['content_types'][$type] = array(
                            'success' => false,
                            'error' => $e->getMessage(),
                        );
                    }
                }
            }
        }

        // Update last scan timestamp
        $this->update_last_scan_time();

        $duration = round((microtime(true) - $start_time) * 1000);
        $results['duration_ms'] = $duration;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Knowledge Scanner Enhanced] Scan complete: {$results['scanned']} references created, {$results['updated']} updated, {$results['errors']} errors ({$duration}ms)");
        }

        return $results;
    }

    /**
     * Scan and create data references
     * Uses the data mapper to document database structure
     *
     * @return array Scan results
     */
    private function scan_data_references() {
        $result = array(
            'scanned' => 0,
            'updated' => 0,
        );

        // Use data mapper to discover schema
        $schema = $this->data_mapper->discover_schema();

        // Save schema references to knowledge base
        foreach ($schema as $table_name => $table_info) {
            $reference_content = $this->create_table_reference($table_name, $table_info);

            $this->save_knowledge_entry(
                'data_reference',
                "Table Reference: {$table_name}",
                $reference_content,
                array(
                    'table_name' => $table_name,
                    'row_count' => $table_info['row_count'],
                    'columns_count' => count($table_info['columns'])
                )
            );

            $result['scanned']++;
            $result['updated']++;
        }

        // Save query templates
        $references = $this->data_mapper->get_all_references();
        foreach ($references as $reference) {
            $this->save_knowledge_entry(
                'query_template',
                "Query Reference: {$reference['key']}",
                json_encode($reference),
                array(
                    'reference_type' => $reference['type'],
                    'key' => $reference['key']
                )
            );

            $result['scanned']++;
            $result['updated']++;
        }

        return $result;
    }

    /**
     * Create table reference documentation
     *
     * @param string $table_name Table name
     * @param array $table_info Table information
     * @return string Reference documentation
     */
    private function create_table_reference($table_name, $table_info) {
        $content = "Database Table Reference:\n";
        $content .= "Table: {$table_name}\n";
        $content .= "Purpose: {$table_info['description']}\n";
        $content .= "Total Records: {$table_info['row_count']}\n\n";

        $content .= "How to query this table:\n";
        $content .= "- Use global \$wpdb to access\n";
        $content .= "- Table name: \$wpdb->prefix . '" . str_replace('wp_', '', $table_name) . "'\n\n";

        $content .= "Key columns available:\n";
        foreach ($table_info['columns'] as $column) {
            $content .= "- {$column['column_name']} ({$column['data_type']})";
            if ($column['column_key'] === 'PRI') {
                $content .= " [PRIMARY KEY]";
            }
            $content .= "\n";
        }

        if (!empty($table_info['relationships'])) {
            $content .= "\nRelationships:\n";
            foreach ($table_info['relationships'] as $relationship) {
                $content .= "- {$relationship['column']} -> {$relationship['references']}.{$relationship['on_column']}\n";
            }
        }

        return $content;
    }

    /**
     * Scan property listings metadata (not actual data)
     *
     * @return array Scan results
     */
    private function scan_listings_metadata() {
        global $wpdb;

        $result = array(
            'scanned' => 0,
            'updated' => 0,
        );

        // Check if BME plugin is available
        if (!function_exists('bme_pro')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Knowledge Scanner Enhanced] BME plugin not available for listing scan');
            }
            return $result;
        }

        // Create references to listing data sources
        $listing_references = array(
            'active_listings' => array(
                'query' => "SELECT * FROM {$wpdb->prefix}bme_listings WHERE standard_status = 'Active'",
                'description' => 'All active property listings',
                'fields' => array('listing_id', 'list_price', 'bedrooms_total', 'city', 'property_type')
            ),
            'listing_summary' => array(
                'query' => "SELECT * FROM {$wpdb->prefix}bme_listing_summary WHERE standard_status = 'Active'",
                'description' => 'Optimized summary table for fast queries',
                'fields' => array('listing_id', 'list_price', 'city', 'latitude', 'longitude')
            ),
            'listing_media' => array(
                'query' => "SELECT * FROM {$wpdb->prefix}bme_media WHERE listing_id = ?",
                'description' => 'Property photos and media',
                'fields' => array('media_url', 'media_caption', 'media_order')
            ),
            'listing_details' => array(
                'query' => "SELECT * FROM {$wpdb->prefix}bme_property_details WHERE listing_id = ?",
                'description' => 'Detailed property information',
                'fields' => array('lot_size', 'year_built', 'garage_spaces', 'pool')
            )
        );

        // Document how to access listing data
        $content = "Property Listing Data Access:\n\n";
        $content .= "To retrieve property information, query these tables:\n\n";

        foreach ($listing_references as $key => $ref) {
            $content .= "For {$ref['description']}:\n";
            $content .= "Query Template: {$ref['query']}\n";
            $content .= "Key Fields: " . implode(', ', $ref['fields']) . "\n\n";
        }

        // Add statistics metadata (not actual numbers)
        $content .= "Available Statistics Queries:\n";
        $content .= "- COUNT(*) for total listings\n";
        $content .= "- AVG(list_price) for average price\n";
        $content .= "- GROUP BY city for city breakdown\n";
        $content .= "- GROUP BY property_type for property type analysis\n";
        $content .= "- WHERE bedrooms_total >= X for bedroom filters\n";

        $this->save_knowledge_entry(
            'listings_metadata',
            'Property Listings Data Reference',
            $content,
            array(
                'data_sources' => count($listing_references),
                'last_updated' => current_time('mysql')
            )
        );

        $result['scanned'] = 1;
        $result['updated'] = 1;

        return $result;
    }

    /**
     * Scan market analytics metadata
     *
     * @return array Scan results
     */
    private function scan_analytics_metadata() {
        global $wpdb;

        $result = array(
            'scanned' => 0,
            'updated' => 0,
        );

        // Check if analytics table exists
        $analytics_table = $wpdb->prefix . 'mld_neighborhood_analytics';
        $market_table = $wpdb->prefix . 'mld_market_analytics';

        $content = "Market Analytics Data Access:\n\n";

        // Document neighborhood analytics access
        $content .= "Neighborhood Analytics Table: {$analytics_table}\n";
        $content .= "Query for neighborhood data:\n";
        $content .= "- SELECT * FROM {$analytics_table} WHERE neighborhood = ?\n";
        $content .= "- Available fields: neighborhood, total_listings, avg_price, price_trend, school_rating\n\n";

        // Document market analytics access
        $content .= "Market Statistics Table: {$market_table}\n";
        $content .= "Query for market stats:\n";
        $content .= "- SELECT * FROM {$market_table} WHERE area = ? AND period = ?\n";
        $content .= "- Available periods: daily, weekly, monthly, yearly\n";
        $content .= "- Available metrics: avg_price, median_price, total_sold, avg_dom, inventory\n\n";

        // Document aggregation patterns
        $content .= "Common Analytics Queries:\n";
        $content .= "- Price trends: SELECT period, avg_price FROM {$market_table} WHERE area = ? ORDER BY period\n";
        $content .= "- Area comparison: SELECT area, avg_price FROM {$market_table} WHERE period = CURRENT_DATE()\n";
        $content .= "- School ratings: SELECT neighborhood, school_rating FROM {$analytics_table} ORDER BY school_rating DESC\n";

        $this->save_knowledge_entry(
            'analytics_metadata',
            'Market Analytics Data Reference',
            $content,
            array('analytics_types' => 2)
        );

        $result['scanned'] = 1;
        $result['updated'] = 1;

        return $result;
    }

    /**
     * Scan agent metadata
     *
     * @return array Scan results
     */
    private function scan_agent_metadata() {
        global $wpdb;

        $result = array(
            'scanned' => 0,
            'updated' => 0,
        );

        $agents_table = $wpdb->prefix . 'bme_agents';
        $offices_table = $wpdb->prefix . 'bme_offices';

        $content = "Agent Data Access:\n\n";

        $content .= "Agent Information Table: {$agents_table}\n";
        $content .= "Query for agent data:\n";
        $content .= "- SELECT * FROM {$agents_table} WHERE agent_id = ?\n";
        $content .= "- SELECT * FROM {$agents_table} WHERE agent_name LIKE ?\n";
        $content .= "- Available fields: agent_id, agent_name, agent_email, agent_phone, office_id\n\n";

        $content .= "Office Information Table: {$offices_table}\n";
        $content .= "Query for office data:\n";
        $content .= "- SELECT * FROM {$offices_table} WHERE office_id = ?\n";
        $content .= "- Available fields: office_id, office_name, office_phone, office_address\n\n";

        $content .= "Common Agent Queries:\n";
        $content .= "- Agent listings: SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings WHERE listing_agent_id = ?\n";
        $content .= "- Top agents: SELECT agent_id, COUNT(*) as listings FROM {$wpdb->prefix}bme_listings GROUP BY agent_id ORDER BY listings DESC\n";
        $content .= "- Agent with office: SELECT a.*, o.* FROM {$agents_table} a LEFT JOIN {$offices_table} o ON a.office_id = o.office_id\n";

        $this->save_knowledge_entry(
            'agent_metadata',
            'Agent Data Reference',
            $content,
            array('data_types' => 2)
        );

        $result['scanned'] = 1;
        $result['updated'] = 1;

        return $result;
    }

    /**
     * Scan and document common query patterns
     *
     * @return array Scan results
     */
    private function scan_query_patterns() {
        global $wpdb;

        $patterns = array(
            'property_search' => array(
                'pattern' => 'Find [X] bedroom homes in [City] under $[Price]',
                'query' => "SELECT * FROM {$wpdb->prefix}bme_listings WHERE bedrooms_total >= ? AND city = ? AND list_price <= ? AND standard_status = 'Active'",
                'parameters' => array('bedrooms', 'city', 'max_price')
            ),
            'price_range' => array(
                'pattern' => 'Properties between $[Min] and $[Max]',
                'query' => "SELECT * FROM {$wpdb->prefix}bme_listings WHERE list_price BETWEEN ? AND ? AND standard_status = 'Active'",
                'parameters' => array('min_price', 'max_price')
            ),
            'area_stats' => array(
                'pattern' => 'Average price in [Area]',
                'query' => "SELECT AVG(list_price) as avg_price, COUNT(*) as total FROM {$wpdb->prefix}bme_listings WHERE city = ? AND standard_status = 'Active'",
                'parameters' => array('city')
            ),
            'recent_listings' => array(
                'pattern' => 'Newest listings in [Area]',
                'query' => "SELECT * FROM {$wpdb->prefix}bme_listings WHERE city = ? AND standard_status = 'Active' ORDER BY original_entry_timestamp DESC LIMIT 10",
                'parameters' => array('city')
            ),
            'school_search' => array(
                'pattern' => 'Homes near [School] school',
                'query' => "SELECT l.* FROM {$wpdb->prefix}bme_listings l JOIN {$wpdb->prefix}bme_schools s ON l.listing_id = s.listing_id WHERE s.school_name LIKE ? AND l.standard_status = 'Active'",
                'parameters' => array('school_name')
            )
        );

        $content = "Common Query Patterns:\n\n";
        $content .= "These patterns can be used to answer user questions:\n\n";

        foreach ($patterns as $key => $pattern) {
            $content .= "Pattern: {$pattern['pattern']}\n";
            $content .= "SQL Template: {$pattern['query']}\n";
            $content .= "Required Parameters: " . implode(', ', $pattern['parameters']) . "\n\n";
        }

        $this->save_knowledge_entry(
            'query_patterns',
            'Common Query Patterns Reference',
            $content,
            array('patterns_count' => count($patterns))
        );

        return array(
            'scanned' => 1,
            'updated' => 1
        );
    }

    /**
     * Scan WordPress pages - extracts ACTUAL content for AI knowledge
     *
     * @since 6.27.2 - Enhanced to save actual page content, not just references
     * @return array Scan results
     */
    private function scan_pages() {
        $pages = get_pages(array(
            'post_status' => 'publish',
            'number' => 100, // Increased limit
        ));

        $scanned = 0;
        $updated = 0;

        foreach ($pages as $page) {
            // Extract and clean the actual page content
            $raw_content = $page->post_content;
            $clean_content = $this->extract_clean_content($raw_content);

            // Skip pages with no meaningful content
            if (strlen($clean_content) < 50) {
                continue;
            }

            // Build knowledge entry with actual content
            $knowledge_content = "=== PAGE: {$page->post_title} ===\n";
            $knowledge_content .= "URL: " . get_permalink($page->ID) . "\n";
            $knowledge_content .= "Last Updated: " . get_the_modified_date('Y-m-d', $page->ID) . "\n\n";
            $knowledge_content .= "CONTENT:\n";
            $knowledge_content .= $clean_content . "\n";

            // Check if content is long enough to chunk
            if (strlen($clean_content) > 3000) {
                // Save chunked content for better retrieval
                $chunks = $this->chunk_content($clean_content, $page->post_title);
                foreach ($chunks as $index => $chunk) {
                    $chunk_title = $page->post_title . " (Part " . ($index + 1) . ")";
                    $chunk_content = "=== PAGE: {$page->post_title} (Section " . ($index + 1) . ") ===\n";
                    $chunk_content .= "URL: " . get_permalink($page->ID) . "\n\n";
                    $chunk_content .= "CONTENT:\n" . $chunk['content'] . "\n";

                    if (!empty($chunk['heading'])) {
                        $chunk_content = "=== PAGE: {$page->post_title} - {$chunk['heading']} ===\n";
                        $chunk_content .= "URL: " . get_permalink($page->ID) . "\n\n";
                        $chunk_content .= "CONTENT:\n" . $chunk['content'] . "\n";
                        $chunk_title = $page->post_title . " - " . $chunk['heading'];
                    }

                    $this->save_knowledge_entry(
                        'page_content',
                        $chunk_title,
                        $chunk_content,
                        array(
                            'post_id' => $page->ID,
                            'url' => get_permalink($page->ID),
                            'chunk_index' => $index,
                            'total_chunks' => count($chunks),
                            'parent_title' => $page->post_title,
                        )
                    );
                    $scanned++;
                    $updated++;
                }
            } else {
                // Save as single entry
                $this->save_knowledge_entry(
                    'page_content',
                    $page->post_title,
                    $knowledge_content,
                    array(
                        'post_id' => $page->ID,
                        'url' => get_permalink($page->ID),
                        'content_length' => strlen($clean_content),
                    )
                );
                $scanned++;
                $updated++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Knowledge Scanner] Scanned {$scanned} pages with actual content");
        }

        return array(
            'scanned' => $scanned,
            'updated' => $updated,
        );
    }

    /**
     * Scan WordPress blog posts - extracts ACTUAL content for AI knowledge
     *
     * @since 6.27.2 - Enhanced to save actual post content, not just references
     * @return array Scan results
     */
    private function scan_posts() {
        $posts = get_posts(array(
            'post_status' => 'publish',
            'numberposts' => 50, // Increased limit
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $scanned = 0;
        $updated = 0;

        foreach ($posts as $post) {
            // Extract and clean the actual post content
            $raw_content = $post->post_content;
            $clean_content = $this->extract_clean_content($raw_content);

            // Skip posts with no meaningful content
            if (strlen($clean_content) < 50) {
                continue;
            }

            // Get categories and tags for context
            $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
            $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));

            // Build knowledge entry with actual content
            $knowledge_content = "=== BLOG POST: {$post->post_title} ===\n";
            $knowledge_content .= "URL: " . get_permalink($post->ID) . "\n";
            $knowledge_content .= "Published: " . get_the_date('F j, Y', $post->ID) . "\n";

            if (!empty($categories)) {
                $knowledge_content .= "Categories: " . implode(', ', $categories) . "\n";
            }
            if (!empty($tags)) {
                $knowledge_content .= "Tags: " . implode(', ', $tags) . "\n";
            }

            $knowledge_content .= "\nCONTENT:\n";
            $knowledge_content .= $clean_content . "\n";

            // Get excerpt for summary
            $excerpt = !empty($post->post_excerpt)
                ? $post->post_excerpt
                : wp_trim_words($clean_content, 50, '...');

            $this->save_knowledge_entry(
                'blog_content',
                $post->post_title,
                $knowledge_content,
                array(
                    'post_id' => $post->ID,
                    'url' => get_permalink($post->ID),
                    'published_date' => get_the_date('Y-m-d', $post->ID),
                    'categories' => $categories,
                    'tags' => $tags,
                    'excerpt' => $excerpt,
                    'content_length' => strlen($clean_content),
                )
            );

            $scanned++;
            $updated++;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Knowledge Scanner] Scanned {$scanned} blog posts with actual content");
        }

        return array(
            'scanned' => $scanned,
            'updated' => $updated,
        );
    }

    /**
     * Extract and clean content from HTML/shortcode content
     *
     * @since 6.27.2
     * @param string $content Raw content with HTML/shortcodes
     * @return string Clean text content
     */
    private function extract_clean_content($content) {
        // First, try to render shortcodes to get their output
        $content = do_shortcode($content);

        // Remove script and style tags completely
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Convert common HTML elements to readable format
        $content = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', "\n\n## $1\n\n", $content);
        $content = preg_replace('/<li[^>]*>(.*?)<\/li>/i', "• $1\n", $content);
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = preg_replace('/<\/p>/i', "\n\n", $content);
        $content = preg_replace('/<\/div>/i', "\n", $content);

        // Remove remaining HTML tags
        $content = wp_strip_all_tags($content);

        // Clean up whitespace
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = trim($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

        return $content;
    }

    /**
     * Chunk long content into logical sections
     *
     * @since 6.27.2
     * @param string $content Clean content to chunk
     * @param string $title Original title for context
     * @return array Array of content chunks with headings
     */
    private function chunk_content($content, $title) {
        $chunks = array();

        // Try to split by headings first (## heading format from our conversion)
        if (preg_match_all('/##\s+([^\n]+)\n+([^#]+)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $heading = trim($match[1]);
                $section_content = trim($match[2]);

                if (strlen($section_content) > 100) {
                    $chunks[] = array(
                        'heading' => $heading,
                        'content' => $section_content,
                    );
                }
            }
        }

        // If no headings found or chunks are empty, split by character count
        if (empty($chunks)) {
            $max_chunk_size = 2500;
            $paragraphs = preg_split('/\n\n+/', $content);
            $current_chunk = '';
            $chunk_index = 0;

            foreach ($paragraphs as $paragraph) {
                if (strlen($current_chunk) + strlen($paragraph) > $max_chunk_size && !empty($current_chunk)) {
                    $chunks[] = array(
                        'heading' => '',
                        'content' => trim($current_chunk),
                    );
                    $current_chunk = $paragraph;
                    $chunk_index++;
                } else {
                    $current_chunk .= "\n\n" . $paragraph;
                }
            }

            // Add remaining content
            if (!empty(trim($current_chunk))) {
                $chunks[] = array(
                    'heading' => '',
                    'content' => trim($current_chunk),
                );
            }
        }

        return $chunks;
    }

    /**
     * Scan business information (kept mostly the same as it's not duplicating data)
     *
     * @return array Scan results
     */
    private function scan_business_info() {
        $content = "Business Information:\n\n";

        // Site details
        $content .= "Website: " . get_bloginfo('name') . "\n";
        $content .= "Description: " . get_bloginfo('description') . "\n";
        $content .= "URL: " . home_url() . "\n";
        $content .= "Contact Email: " . get_option('admin_email') . "\n\n";

        // Additional business info from settings
        $phone = get_option('mld_business_phone', '');
        $address = get_option('mld_business_address', '');
        $hours = get_option('mld_business_hours', '');

        if ($phone) {
            $content .= "Phone: {$phone}\n";
        }
        if ($address) {
            $content .= "Address: {$address}\n";
        }
        if ($hours) {
            $content .= "Hours: {$hours}\n";
        }

        $this->save_knowledge_entry(
            'business_info',
            'Company Information',
            $content,
            array('site_name' => get_bloginfo('name'))
        );

        return array(
            'scanned' => 1,
            'updated' => 1,
        );
    }

    /**
     * Save knowledge entry to database
     *
     * @param string $content_type Content type
     * @param string $title Entry title
     * @param string $content Entry content
     * @param array $metadata Additional metadata
     * @return int|false Entry ID or false
     */
    private function save_knowledge_entry($content_type, $title, $content, $metadata = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_knowledge_base';

        // Check if entry exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE content_type = %s AND content_title = %s",
            $content_type,
            $title
        ));

        // Generate a summary from content (first 500 chars)
        $summary = wp_trim_words(strip_tags($content), 50, '...');

        // Build data array with correct column names matching database schema
        $data = array(
            'content_type' => $content_type,
            'content_title' => $title,
            'content_text' => $content,           // Fixed: was 'content'
            'content_summary' => $summary,
            'content_metadata' => json_encode($metadata),  // Fixed: was 'metadata'
            'entry_type' => 'reference',
            'is_active' => 1,
            'scan_date' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        if ($existing) {
            // Update existing entry
            $result = $wpdb->update(
                $table,
                $data,
                array('id' => $existing),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'),
                array('%d')
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Knowledge Scanner] Updated entry ID {$existing}: {$title}");
            }
        } else {
            // Insert new entry
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $table,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($result) {
                    error_log("[MLD Knowledge Scanner] Inserted new entry ID {$wpdb->insert_id}: {$title}");
                } else {
                    error_log("[MLD Knowledge Scanner] Failed to insert: {$title} - " . $wpdb->last_error);
                }
            }
        }

        return $result !== false ? ($existing ?: $wpdb->insert_id) : false;
    }

    /**
     * Extract keywords from content
     *
     * @param string $content Content to analyze
     * @return string Keywords
     */
    private function extract_keywords($content) {
        // Simple keyword extraction - can be enhanced with NLP
        $words = str_word_count(strtolower($content), 1);
        $stop_words = array('the', 'is', 'at', 'which', 'on', 'a', 'an', 'as', 'are', 'was', 'were', 'to', 'for', 'from', 'with');
        $keywords = array_diff($words, $stop_words);
        $unique_keywords = array_unique($keywords);

        return implode(' ', array_slice($unique_keywords, 0, 20));
    }

    /**
     * Get enabled content types from settings
     *
     * @return array Enabled content types
     */
    private function get_enabled_content_types() {
        $enabled = get_option('mld_knowledge_scan_types', array());

        // Default to all types if none configured
        if (empty($enabled)) {
            return array_keys($this->content_types);
        }

        return $enabled;
    }

    /**
     * Update last scan timestamp
     */
    private function update_last_scan_time() {
        update_option('mld_knowledge_last_scan', current_time('mysql'));
    }

    /**
     * AJAX handler for manual scan trigger
     */
    public function ajax_trigger_scan() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mld_chatbot_admin')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Run scan
        $results = $this->run_full_scan();

        wp_send_json_success($results);
    }

    /**
     * Cleanup old knowledge entries
     *
     * Removes inactive or outdated knowledge entries
     *
     * @since 6.27.2
     * @param int $days Number of days to retain entries
     * @return int Number of entries deleted
     */
    public function cleanup_old_entries($days = 180) {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_knowledge_base';

        // Delete entries that haven't been updated in X days and are inactive
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE is_active = 0
             AND updated_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'),
            $days
        ));

        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("[MLD Knowledge Scanner] Cleaned up {$deleted} old knowledge entries");
        }

        return $deleted ? $deleted : 0;
    }
}

// Global scanner instance
global $mld_knowledge_scanner;
$mld_knowledge_scanner = null;

/**
 * Get global knowledge scanner instance
 *
 * @since 6.27.2
 * @return MLD_Knowledge_Scanner
 */
function mld_get_knowledge_scanner() {
    global $mld_knowledge_scanner;

    if ($mld_knowledge_scanner === null) {
        $mld_knowledge_scanner = new MLD_Knowledge_Scanner();
    }

    return $mld_knowledge_scanner;
}