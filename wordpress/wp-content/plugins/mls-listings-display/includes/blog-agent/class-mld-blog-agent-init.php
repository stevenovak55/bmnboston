<?php
/**
 * Blog Agent Initialization
 *
 * Bootstrap file for the Real Estate Blog Writing Agent module.
 * Handles dependency loading, database table creation, and class instantiation.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Agent_Init
 *
 * Main initialization class for the Blog Agent module.
 */
class MLD_Blog_Agent_Init {

    /**
     * Module version
     */
    const VERSION = '1.0.0';

    /**
     * Database version for migrations
     */
    const DB_VERSION = '1.0.0';

    /**
     * Singleton instance
     *
     * @var MLD_Blog_Agent_Init|null
     */
    private static $instance = null;

    /**
     * Module components
     *
     * @var array
     */
    private $components = array();

    /**
     * Get singleton instance
     *
     * @return MLD_Blog_Agent_Init
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->load_dependencies();
        $this->check_database();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Define module constants
     */
    private function define_constants() {
        if (!defined('MLD_BLOG_AGENT_PATH')) {
            define('MLD_BLOG_AGENT_PATH', plugin_dir_path(__FILE__));
        }
        if (!defined('MLD_BLOG_AGENT_URL')) {
            define('MLD_BLOG_AGENT_URL', plugin_dir_url(__FILE__));
        }
        if (!defined('MLD_BLOG_AGENT_VERSION')) {
            define('MLD_BLOG_AGENT_VERSION', self::VERSION);
        }
    }

    /**
     * Load all module dependencies
     */
    private function load_dependencies() {
        $base_path = MLD_BLOG_AGENT_PATH;

        // Core components
        require_once $base_path . 'class-mld-topic-researcher.php';
        require_once $base_path . 'class-mld-blog-content-generator.php';
        require_once $base_path . 'class-mld-blog-seo-optimizer.php';
        require_once $base_path . 'class-mld-blog-internal-linker.php';
        require_once $base_path . 'class-mld-blog-image-handler.php';
        require_once $base_path . 'class-mld-blog-publisher.php';
        require_once $base_path . 'class-mld-blog-feedback-learner.php';
        require_once $base_path . 'class-mld-blog-cta-manager.php';
        require_once $base_path . 'class-mld-blog-prompt-manager.php';

        // Admin components (only when needed)
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            require_once $base_path . 'class-mld-blog-agent-admin.php';
            require_once $base_path . 'class-mld-blog-agent-ajax.php';
        }

        // REST API (for Claude Code skill integration)
        require_once $base_path . 'class-mld-blog-agent-rest-api.php';
    }

    /**
     * Check and create database tables
     */
    private function check_database() {
        $current_version = get_option('mld_blog_agent_db_version', '0.0.0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            $this->create_tables();
            update_option('mld_blog_agent_db_version', self::DB_VERSION);
        }
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Topics table - stores researched and cached topics
        $table_topics = $wpdb->prefix . 'mld_blog_topics';
        $sql_topics = "CREATE TABLE IF NOT EXISTS $table_topics (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            slug VARCHAR(200) NOT NULL,
            description TEXT,
            relevance_score DECIMAL(5,2) DEFAULT 0,
            recency_score DECIMAL(5,2) DEFAULT 0,
            authority_score DECIMAL(5,2) DEFAULT 0,
            uniqueness_score DECIMAL(5,2) DEFAULT 0,
            total_score DECIMAL(5,2) DEFAULT 0,
            source VARCHAR(100),
            source_url VARCHAR(500),
            keywords JSON,
            related_cities JSON,
            status ENUM('pending', 'selected', 'generated', 'published', 'archived') DEFAULT 'pending',
            researched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_status (status),
            KEY idx_total_score (total_score),
            KEY idx_expires (expires_at),
            KEY idx_created (created_at),
            UNIQUE KEY unique_slug (slug)
        ) $charset_collate";
        dbDelta($sql_topics);

        // Articles table - stores generated articles and their metadata
        $table_articles = $wpdb->prefix . 'mld_blog_articles';
        $sql_articles = "CREATE TABLE IF NOT EXISTS $table_articles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            topic_id BIGINT UNSIGNED,
            wp_post_id BIGINT UNSIGNED,
            title VARCHAR(500) NOT NULL,
            slug VARCHAR(200) NOT NULL,
            content LONGTEXT,
            meta_description VARCHAR(200),
            meta_keywords VARCHAR(500),
            seo_score DECIMAL(5,2) DEFAULT 0,
            geo_score DECIMAL(5,2) DEFAULT 0,
            word_count INT UNSIGNED DEFAULT 0,
            internal_links_count INT UNSIGNED DEFAULT 0,
            external_links_count INT UNSIGNED DEFAULT 0,
            images_count INT UNSIGNED DEFAULT 0,
            cta_type VARCHAR(50),
            cta_position VARCHAR(50),
            schema_markup JSON,
            ai_provider VARCHAR(50),
            ai_model VARCHAR(100),
            prompt_version VARCHAR(50),
            generation_tokens INT UNSIGNED DEFAULT 0,
            generation_cost DECIMAL(10,6) DEFAULT 0,
            status ENUM('draft', 'pending_review', 'published', 'archived') DEFAULT 'draft',
            original_content_hash VARCHAR(64),
            published_content_hash VARCHAR(64),
            edit_distance INT UNSIGNED DEFAULT 0,
            user_rating TINYINT UNSIGNED,
            generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            published_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_topic (topic_id),
            KEY idx_wp_post (wp_post_id),
            KEY idx_status (status),
            KEY idx_seo_score (seo_score),
            KEY idx_published (published_at),
            UNIQUE KEY unique_slug (slug)
        ) $charset_collate";
        dbDelta($sql_articles);

        // Feedback table - stores learning data
        $table_feedback = $wpdb->prefix . 'mld_blog_feedback';
        $sql_feedback = "CREATE TABLE IF NOT EXISTS $table_feedback (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            article_id BIGINT UNSIGNED NOT NULL,
            feedback_type ENUM('edit_distance', 'user_rating', 'engagement', 'seo_performance') NOT NULL,
            metric_name VARCHAR(100) NOT NULL,
            metric_value DECIMAL(20,6) NOT NULL,
            metadata JSON,
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            KEY idx_article (article_id),
            KEY idx_type (feedback_type),
            KEY idx_recorded (recorded_at)
        ) $charset_collate";
        dbDelta($sql_feedback);

        // Prompts table - stores prompt versions for A/B testing
        $table_prompts = $wpdb->prefix . 'mld_blog_prompts';
        $sql_prompts = "CREATE TABLE IF NOT EXISTS $table_prompts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prompt_key VARCHAR(100) NOT NULL,
            prompt_name VARCHAR(200) NOT NULL,
            prompt_content LONGTEXT NOT NULL,
            version VARCHAR(20) NOT NULL,
            weight INT UNSIGNED DEFAULT 100,
            is_active TINYINT(1) DEFAULT 1,
            total_uses INT UNSIGNED DEFAULT 0,
            success_rate DECIMAL(5,2) DEFAULT 0,
            avg_seo_score DECIMAL(5,2) DEFAULT 0,
            avg_edit_distance DECIMAL(10,2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_prompt_key (prompt_key),
            KEY idx_active (is_active),
            KEY idx_weight (weight),
            UNIQUE KEY unique_key_version (prompt_key, version)
        ) $charset_collate";
        dbDelta($sql_prompts);

        // Insert default prompts
        $this->insert_default_prompts();
    }

    /**
     * Insert default prompt templates
     */
    private function insert_default_prompts() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        // Check if prompts already exist
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > 0) {
            return;
        }

        $default_prompts = array(
            array(
                'prompt_key' => 'topic_research',
                'prompt_name' => 'Topic Research System Prompt',
                'version' => '1.0.0',
                'prompt_content' => $this->get_default_topic_research_prompt(),
            ),
            array(
                'prompt_key' => 'article_structure',
                'prompt_name' => 'Article Structure Generation',
                'version' => '1.0.0',
                'prompt_content' => $this->get_default_article_structure_prompt(),
            ),
            array(
                'prompt_key' => 'section_writing',
                'prompt_name' => 'Section Content Writing',
                'version' => '1.0.0',
                'prompt_content' => $this->get_default_section_writing_prompt(),
            ),
            array(
                'prompt_key' => 'seo_optimization',
                'prompt_name' => 'SEO Optimization Review',
                'version' => '1.0.0',
                'prompt_content' => $this->get_default_seo_prompt(),
            ),
        );

        foreach ($default_prompts as $prompt) {
            $wpdb->insert($table, array(
                'prompt_key' => $prompt['prompt_key'],
                'prompt_name' => $prompt['prompt_name'],
                'prompt_content' => $prompt['prompt_content'],
                'version' => $prompt['version'],
                'weight' => 100,
                'is_active' => 1,
            ));
        }
    }

    /**
     * Get default topic research prompt
     *
     * @return string
     */
    private function get_default_topic_research_prompt() {
        return <<<'PROMPT'
You are a real estate content strategist specializing in the Greater Boston area market. Your task is to identify trending, relevant topics for a real estate blog targeting home buyers, sellers, and investors in Massachusetts.

## Requirements

1. **Relevance**: Topics must be directly relevant to Boston/Massachusetts real estate
2. **Timeliness**: Prioritize current trends, recent market changes, seasonal topics
3. **Authority**: Topics should position the blog as an authoritative source
4. **Uniqueness**: Avoid generic topics covered everywhere; find unique angles

## Topic Categories to Consider

- Market trends and analysis (prices, inventory, interest rates)
- Neighborhood spotlights and comparisons
- First-time homebuyer guides specific to MA
- Investment property insights
- School district analysis (top schools, ratings)
- Seasonal buying/selling tips
- Local regulations and policy changes
- Mortgage and financing updates
- New development projects
- Community amenities and lifestyle

## Output Format

Return exactly 5 topics in JSON format:
```json
{
  "topics": [
    {
      "title": "Topic title (50-60 characters)",
      "description": "Brief description of the topic angle (100-150 words)",
      "keywords": ["keyword1", "keyword2", "keyword3"],
      "relevance_reason": "Why this is relevant now",
      "target_audience": "Who this article serves",
      "related_cities": ["Boston", "Cambridge"],
      "estimated_search_volume": "high|medium|low"
    }
  ]
}
```
PROMPT;
    }

    /**
     * Get default article structure prompt
     *
     * @return string
     */
    private function get_default_article_structure_prompt() {
        return <<<'PROMPT'
You are a professional real estate content writer creating an article outline for BMNBoston, a real estate platform serving the Greater Boston area.

## Article Requirements

- **Target length**: 1,500-2,500 words
- **SEO optimized**: Include primary and secondary keywords naturally
- **Engaging**: Use attention-grabbing headlines and compelling hooks
- **Actionable**: Provide practical value to readers
- **Local focus**: Include Boston/MA specific information

## Structure Guidelines

1. **Title**: 50-60 characters, include primary keyword, compelling
2. **Introduction**: Hook the reader, establish relevance, preview value
3. **Main Sections (4-6)**: Each with H2 heading, 200-400 words
4. **Subsections**: H3 headings where needed for clarity
5. **Conclusion**: Summarize key points, call to action

## Output Format

Return the structure in JSON:
```json
{
  "title": "Article title",
  "meta_description": "150-155 character meta description",
  "primary_keyword": "main keyword",
  "secondary_keywords": ["keyword2", "keyword3"],
  "introduction": {
    "hook": "Opening hook sentence",
    "context": "Why this matters",
    "preview": "What readers will learn"
  },
  "sections": [
    {
      "heading": "H2 Section Title",
      "key_points": ["point 1", "point 2"],
      "subsections": [
        {
          "heading": "H3 Subsection",
          "key_points": ["point 1"]
        }
      ],
      "internal_link_opportunity": "search|schools|calculator|book",
      "data_to_include": "market_stats|school_ratings|price_trends"
    }
  ],
  "conclusion": {
    "summary_points": ["point 1", "point 2"],
    "cta_type": "contact|search|book|download"
  }
}
```
PROMPT;
    }

    /**
     * Get default section writing prompt
     *
     * @return string
     */
    private function get_default_section_writing_prompt() {
        return <<<'PROMPT'
You are writing a section of a real estate blog article for BMNBoston. Write engaging, informative content that provides genuine value to readers.

## Writing Guidelines

1. **Voice**: Professional yet approachable, authoritative but not stuffy
2. **Style**: Clear, concise sentences. Avoid jargon unless explained.
3. **Local focus**: Mention Boston/MA specifics, neighborhoods, local data
4. **Evidence-based**: Include statistics, data points, expert insights
5. **Scannable**: Use bullet points, short paragraphs, bold key terms

## SEO Requirements

- Include the primary keyword 2-3 times naturally
- Use secondary keywords where they fit
- Write for humans first, search engines second
- Include relevant internal link anchors

## Formatting

- Use Markdown formatting
- H2 for main section heading
- H3 for subsections
- Bullet points for lists
- Bold for emphasis
- Link placeholders: [link text](INTERNAL:search) or [link text](EXTERNAL:url)

## Output

Return the section content in Markdown format, ready for WordPress.
PROMPT;
    }

    /**
     * Get default SEO optimization prompt
     *
     * @return string
     */
    private function get_default_seo_prompt() {
        return <<<'PROMPT'
You are an SEO specialist reviewing a real estate blog article. Analyze the content and provide optimization recommendations.

## SEO Checklist

1. **Title Tag**: 50-60 characters, includes primary keyword
2. **Meta Description**: 140-155 characters, compelling, includes keyword
3. **H1**: One H1 tag, matches title intent
4. **Headings**: 3-8 H2 headings, logical hierarchy
5. **Word Count**: 1,200-2,500 words ideal
6. **Keyword Density**: 0.5-2.5% for primary keyword
7. **Internal Links**: 3-7 links to platform pages
8. **External Links**: 2-5 authoritative sources
9. **Images**: 2-6 images with alt text
10. **Schema**: Article schema markup present

## GEO Optimization

1. **Boston mentions**: 3+ references
2. **Neighborhood mentions**: 2+ specific areas
3. **MA state mentions**: 1+ references
4. **Local school references**: When relevant
5. **Local market data**: Prices, trends, statistics
6. **NAP consistency**: Business name, address, phone

## Output Format

Return analysis in JSON:
```json
{
  "seo_score": 85,
  "geo_score": 78,
  "issues": [
    {
      "severity": "high|medium|low",
      "category": "title|meta|content|links|images",
      "issue": "Description of the issue",
      "recommendation": "How to fix it"
    }
  ],
  "improvements": [
    {
      "type": "keyword|link|content",
      "current": "Current text",
      "suggested": "Improved text",
      "reason": "Why this improves SEO"
    }
  ]
}
```
PROMPT;
    }

    /**
     * Initialize module components
     */
    private function init_components() {
        // Initialize core components
        $this->components['topic_researcher'] = new MLD_Topic_Researcher();
        $this->components['content_generator'] = new MLD_Blog_Content_Generator();
        $this->components['seo_optimizer'] = new MLD_Blog_SEO_Optimizer();
        $this->components['internal_linker'] = new MLD_Blog_Internal_Linker();
        $this->components['image_handler'] = new MLD_Blog_Image_Handler();
        $this->components['publisher'] = new MLD_Blog_Publisher();
        $this->components['feedback_learner'] = new MLD_Blog_Feedback_Learner();
        $this->components['cta_manager'] = new MLD_Blog_CTA_Manager();
        $this->components['prompt_manager'] = new MLD_Blog_Prompt_Manager();

        // Initialize admin components
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $this->components['admin'] = new MLD_Blog_Agent_Admin();
            $this->components['ajax'] = new MLD_Blog_Agent_Ajax();
        }

        // Initialize REST API
        $this->components['rest_api'] = new MLD_Blog_Agent_REST_API();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Weekly cron for collecting engagement metrics
        add_action('mld_blog_collect_metrics', array($this, 'collect_engagement_metrics'));

        // Register cron schedule if not exists
        if (!wp_next_scheduled('mld_blog_collect_metrics')) {
            wp_schedule_event(time(), 'weekly', 'mld_blog_collect_metrics');
        }

        // Clean up expired topics daily
        add_action('mld_blog_cleanup_topics', array($this, 'cleanup_expired_topics'));
        if (!wp_next_scheduled('mld_blog_cleanup_topics')) {
            wp_schedule_event(time(), 'daily', 'mld_blog_cleanup_topics');
        }
    }

    /**
     * Collect engagement metrics from GA4 (called by cron)
     */
    public function collect_engagement_metrics() {
        if (isset($this->components['feedback_learner'])) {
            $this->components['feedback_learner']->collect_weekly_metrics();
        }
    }

    /**
     * Clean up expired topics (called by cron)
     */
    public function cleanup_expired_topics() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';

        // Archive topics older than 30 days that weren't selected
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = 'archived'
             WHERE status = 'pending'
             AND expires_at < %s",
            current_time('mysql')
        ));

        // Delete archived topics older than 90 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table
             WHERE status = 'archived'
             AND updated_at < DATE_SUB(%s, INTERVAL 90 DAY)",
            current_time('mysql')
        ));
    }

    /**
     * Get a component instance
     *
     * @param string $name Component name
     * @return object|null
     */
    public function get_component($name) {
        return isset($this->components[$name]) ? $this->components[$name] : null;
    }

    /**
     * Get all components
     *
     * @return array
     */
    public function get_components() {
        return $this->components;
    }

    /**
     * Plugin activation hook
     */
    public static function activate() {
        $instance = self::get_instance();
        $instance->create_tables();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('mld_blog_collect_metrics');
        wp_clear_scheduled_hook('mld_blog_cleanup_topics');
    }
}
