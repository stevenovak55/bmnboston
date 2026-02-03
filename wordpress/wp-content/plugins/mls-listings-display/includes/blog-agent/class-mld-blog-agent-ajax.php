<?php
/**
 * Blog Agent AJAX Handlers
 *
 * Handles all AJAX requests for the Blog Agent admin interface.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Agent_Ajax
 *
 * AJAX handlers for Blog Agent.
 */
class MLD_Blog_Agent_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        // Research topics
        add_action('wp_ajax_mld_blog_research_topics', array($this, 'research_topics'));

        // Create custom topic
        add_action('wp_ajax_mld_blog_create_topic', array($this, 'create_topic'));

        // Get topic
        add_action('wp_ajax_mld_blog_get_topic', array($this, 'get_topic'));

        // Generate article
        add_action('wp_ajax_mld_blog_generate_article', array($this, 'generate_article'));

        // Regenerate section
        add_action('wp_ajax_mld_blog_regenerate_section', array($this, 'regenerate_section'));

        // Save draft
        add_action('wp_ajax_mld_blog_save_draft', array($this, 'save_draft'));

        // Publish article
        add_action('wp_ajax_mld_blog_publish', array($this, 'publish_article'));

        // Get SEO analysis
        add_action('wp_ajax_mld_blog_seo_analysis', array($this, 'get_seo_analysis'));

        // Record rating
        add_action('wp_ajax_mld_blog_record_rating', array($this, 'record_rating'));
    }

    /**
     * Verify AJAX request
     *
     * @return bool
     */
    private function verify_request() {
        if (!check_ajax_referer('mld_blog_agent_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid security token.'));
            return false;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
            return false;
        }

        return true;
    }

    /**
     * Get Blog Agent init instance
     *
     * @return MLD_Blog_Agent_Init
     */
    private function get_init() {
        return MLD_Blog_Agent_Init::get_instance();
    }

    /**
     * Log to dedicated Blog Agent log file
     */
    private function blog_log($message) {
        $log_file = WP_CONTENT_DIR . '/blog-agent-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Research trending topics
     */
    public function research_topics() {
        $this->blog_log('research_topics called');

        if (!$this->verify_request()) {
            $this->blog_log('verify_request failed');
            return;
        }

        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            $this->blog_log('topic_researcher not available');
            wp_send_json_error(array('message' => 'Topic researcher not available.'));
            return;
        }

        $this->blog_log('topic_researcher found: ' . get_class($researcher));

        // Set prompt manager
        $prompt_manager = $init->get_component('prompt_manager');
        if ($prompt_manager) {
            $researcher->set_prompt_manager($prompt_manager);
        }

        $options = array(
            'count' => 5,
            'exclude_recent' => true,
        );

        $this->blog_log('calling research_topics...');
        $result = $researcher->research_topics($options);
        $this->blog_log('research_topics returned: success=' . ($result['success'] ? 'true' : 'false') . ', error=' . ($result['error'] ?? 'none') . ', topics=' . count($result['topics'] ?? []));

        if ($result['success']) {
            $this->blog_log('sending success with ' . count($result['topics']) . ' topics');
            wp_send_json_success(array(
                'topics' => $result['topics'],
                'count' => $result['count'],
            ));
        } else {
            $this->blog_log('sending error: ' . ($result['error'] ?? 'Research failed.'));
            wp_send_json_error(array('message' => $result['error'] ?? 'Research failed.'));
        }
    }

    /**
     * Create a custom topic
     */
    public function create_topic() {
        if (!$this->verify_request()) {
            return;
        }

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
        $cities = isset($_POST['cities']) ? sanitize_text_field($_POST['cities']) : '';

        if (empty($title)) {
            wp_send_json_error(array('message' => 'Topic title is required.'));
            return;
        }

        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            wp_send_json_error(array('message' => 'Topic researcher not available.'));
            return;
        }

        $topic_data = array(
            'title' => $title,
            'description' => $description,
            'keywords' => array_map('trim', explode(',', $keywords)),
            'related_cities' => array_map('trim', explode(',', $cities)),
        );

        $topic_id = $researcher->create_custom_topic($topic_data);

        if ($topic_id) {
            $topic = $researcher->get_topic($topic_id);
            wp_send_json_success(array(
                'topic_id' => $topic_id,
                'topic' => $topic,
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to create topic.'));
        }
    }

    /**
     * Get a specific topic
     */
    public function get_topic() {
        if (!$this->verify_request()) {
            return;
        }

        $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : 0;

        if (!$topic_id) {
            wp_send_json_error(array('message' => 'Topic ID is required.'));
            return;
        }

        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            wp_send_json_error(array('message' => 'Topic researcher not available.'));
            return;
        }

        $topic = $researcher->get_topic($topic_id);

        if ($topic) {
            wp_send_json_success(array('topic' => $topic));
        } else {
            wp_send_json_error(array('message' => 'Topic not found.'));
        }
    }

    /**
     * Generate an article from a topic
     */
    public function generate_article() {
        $this->blog_log('generate_article called');

        if (!$this->verify_request()) {
            $this->blog_log('generate_article: verify_request failed');
            return;
        }

        $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : 0;
        $topic_data = isset($_POST['topic']) ? $_POST['topic'] : null;

        $this->blog_log('generate_article: topic_id=' . $topic_id . ', has_topic_data=' . ($topic_data ? 'yes' : 'no'));

        if (!$topic_id && !$topic_data) {
            $this->blog_log('generate_article: no topic provided');
            wp_send_json_error(array('message' => 'Topic is required.'));
            return;
        }

        $init = $this->get_init();

        // Get topic data
        if ($topic_id && !$topic_data) {
            $researcher = $init->get_component('topic_researcher');
            $topic_data = $researcher ? $researcher->get_topic($topic_id) : null;
        }

        if (!$topic_data) {
            wp_send_json_error(array('message' => 'Topic not found.'));
            return;
        }

        // Sanitize topic data if passed from frontend
        if (is_array($topic_data)) {
            $topic = array(
                'id' => $topic_id ?: ($topic_data['id'] ?? 0),
                'title' => sanitize_text_field($topic_data['title'] ?? ''),
                'description' => sanitize_textarea_field($topic_data['description'] ?? ''),
                'keywords' => is_array($topic_data['keywords']) ? array_map('sanitize_text_field', $topic_data['keywords']) : array(),
                'related_cities' => is_array($topic_data['related_cities']) ? array_map('sanitize_text_field', $topic_data['related_cities']) : array(),
            );
        } else {
            $topic = $topic_data;
        }

        // Get generation options
        $options = array(
            'target_length' => isset($_POST['target_length']) ? intval($_POST['target_length']) : 2000,
            'cta_type' => isset($_POST['cta_type']) ? sanitize_text_field($_POST['cta_type']) : 'auto',
            'include_market_data' => isset($_POST['include_market_data']) ? (bool) $_POST['include_market_data'] : true,
            'include_school_data' => isset($_POST['include_school_data']) ? (bool) $_POST['include_school_data'] : true,
        );

        // Set up components
        $generator = $init->get_component('content_generator');
        $prompt_manager = $init->get_component('prompt_manager');
        $internal_linker = $init->get_component('internal_linker');
        $cta_manager = $init->get_component('cta_manager');
        $seo_optimizer = $init->get_component('seo_optimizer');
        $image_handler = $init->get_component('image_handler');

        if (!$generator) {
            $this->blog_log('generate_article: content generator not available');
            wp_send_json_error(array('message' => 'Content generator not available.'));
            return;
        }

        $this->blog_log('generate_article: generator found, setting dependencies');
        $generator->set_dependencies($prompt_manager, $internal_linker, $cta_manager);

        // Generate article
        $this->blog_log('generate_article: calling generator->generate_article()');
        $result = $generator->generate_article($topic, $options);
        $this->blog_log('generate_article: result success=' . ($result['success'] ? 'true' : 'false') . ', error=' . ($result['error'] ?? 'none'));

        if (!$result['success']) {
            $this->blog_log('generate_article: sending error response');
            wp_send_json_error(array('message' => $result['error'] ?? 'Generation failed.'));
            return;
        }

        $article = $result['article'];
        $article['topic_id'] = $topic['id'] ?? 0;

        // Run SEO analysis
        $this->blog_log('generate_article: Running SEO analysis, optimizer=' . ($seo_optimizer ? 'yes' : 'no'));
        if ($seo_optimizer) {
            $analysis = $seo_optimizer->analyze($article);
            $this->blog_log('generate_article: SEO score=' . ($analysis['seo_score'] ?? 'null') . ', GEO score=' . ($analysis['geo_score'] ?? 'null'));
            $article['seo_score'] = $analysis['seo_score'];
            $article['geo_score'] = $analysis['geo_score'];
            $article['seo_analysis'] = $analysis;
            $article['schema'] = $analysis['schema'];

            // Optimize content
            $article = $seo_optimizer->optimize($article, $analysis);
        } else {
            $this->blog_log('generate_article: No SEO optimizer available');
        }

        // Get images
        $this->blog_log('generate_article: Getting images, handler=' . ($image_handler ? 'yes' : 'no'));
        if ($image_handler) {
            $images = $image_handler->get_images($article, array(
                'related_cities' => $topic['related_cities'] ?? array(),
                'keywords' => $topic['keywords'] ?? array(),
            ));
            $this->blog_log('generate_article: Got ' . count($images['images'] ?? []) . ' images');
            $article['images'] = $images['images'];
        } else {
            $this->blog_log('generate_article: No image handler available');
        }

        $article['stats'] = $result['stats'];
        $article['prompt_version'] = $result['prompt_version'];

        $this->blog_log('generate_article: Sending success response');
        wp_send_json_success(array(
            'article' => $article,
        ));
    }

    /**
     * Regenerate a specific section
     */
    public function regenerate_section() {
        if (!$this->verify_request()) {
            return;
        }

        $section_key = isset($_POST['section_key']) ? sanitize_text_field($_POST['section_key']) : '';
        $article_data = isset($_POST['article']) ? $_POST['article'] : null;
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';

        if (!$section_key || !$article_data) {
            wp_send_json_error(array('message' => 'Section key and article data are required.'));
            return;
        }

        $init = $this->get_init();
        $generator = $init->get_component('content_generator');

        if (!$generator) {
            wp_send_json_error(array('message' => 'Content generator not available.'));
            return;
        }

        $result = $generator->regenerate_section($article_data, $section_key, $feedback);

        if ($result['success']) {
            wp_send_json_success(array(
                'section_key' => $result['section_key'],
                'content' => $result['content'],
            ));
        } else {
            wp_send_json_error(array('message' => $result['error'] ?? 'Regeneration failed.'));
        }
    }

    /**
     * Save article as draft
     */
    public function save_draft() {
        if (!$this->verify_request()) {
            return;
        }

        $article_data = isset($_POST['article']) ? $_POST['article'] : null;
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

        if (!$article_data) {
            wp_send_json_error(array('message' => 'Article data is required.'));
            return;
        }

        // Sanitize article data
        $article = array(
            'title' => sanitize_text_field($article_data['title'] ?? ''),
            'content' => wp_kses_post($article_data['content'] ?? ''),
            'meta_description' => sanitize_text_field($article_data['meta_description'] ?? ''),
            'meta_keywords' => sanitize_text_field($article_data['meta_keywords'] ?? ''),
            'seo_score' => floatval($article_data['seo_score'] ?? 0),
            'geo_score' => floatval($article_data['geo_score'] ?? 0),
            'word_count' => intval($article_data['word_count'] ?? 0),
            'cta_type' => sanitize_text_field($article_data['cta_type'] ?? ''),
            'cta_position' => sanitize_text_field($article_data['cta_position'] ?? ''),
            'topic_id' => intval($article_data['topic_id'] ?? 0),
            'structure' => $article_data['structure'] ?? array(),
            'schema' => $article_data['schema'] ?? array(),
            'images' => $article_data['images'] ?? array(),
            'stats' => $article_data['stats'] ?? array(),
            'prompt_version' => sanitize_text_field($article_data['prompt_version'] ?? ''),
            'primary_keyword' => sanitize_text_field($article_data['structure']['primary_keyword'] ?? ''),
        );

        $init = $this->get_init();
        $publisher = $init->get_component('publisher');
        $image_handler = $init->get_component('image_handler');
        $seo_optimizer = $init->get_component('seo_optimizer');

        if (!$publisher) {
            wp_send_json_error(array('message' => 'Publisher not available.'));
            return;
        }

        $publisher->set_dependencies($image_handler, $seo_optimizer);

        $options = array(
            'category' => $category_id ? get_category($category_id)->slug : 'real-estate',
        );

        $result = $publisher->create_draft($article, $options);

        if ($result['success']) {
            wp_send_json_success(array(
                'post_id' => $result['post_id'],
                'edit_url' => $result['edit_url'],
                'preview_url' => $result['preview_url'],
            ));
        } else {
            wp_send_json_error(array('message' => $result['error'] ?? 'Failed to save draft.'));
        }
    }

    /**
     * Publish article
     */
    public function publish_article() {
        if (!$this->verify_request()) {
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $article_data = isset($_POST['article']) ? $_POST['article'] : null;

        // If no post_id, save as draft first
        if (!$post_id && $article_data) {
            $_POST['article'] = $article_data;
            $this->save_draft();
            return; // save_draft will send its own response
        }

        if (!$post_id) {
            wp_send_json_error(array('message' => 'Post ID is required.'));
            return;
        }

        $init = $this->get_init();
        $publisher = $init->get_component('publisher');

        if (!$publisher) {
            wp_send_json_error(array('message' => 'Publisher not available.'));
            return;
        }

        $result = $publisher->publish($post_id);

        if ($result['success']) {
            wp_send_json_success(array(
                'post_id' => $result['post_id'],
                'url' => $result['url'],
                'status' => $result['status'],
            ));
        } else {
            wp_send_json_error(array('message' => $result['error'] ?? 'Failed to publish.'));
        }
    }

    /**
     * Get SEO analysis for content
     */
    public function get_seo_analysis() {
        if (!$this->verify_request()) {
            return;
        }

        $article_data = isset($_POST['article']) ? $_POST['article'] : null;

        if (!$article_data) {
            wp_send_json_error(array('message' => 'Article data is required.'));
            return;
        }

        $init = $this->get_init();
        $seo_optimizer = $init->get_component('seo_optimizer');

        if (!$seo_optimizer) {
            wp_send_json_error(array('message' => 'SEO optimizer not available.'));
            return;
        }

        $article = array(
            'title' => sanitize_text_field($article_data['title'] ?? ''),
            'content' => wp_kses_post($article_data['content'] ?? ''),
            'meta_description' => sanitize_text_field($article_data['meta_description'] ?? ''),
            'primary_keyword' => sanitize_text_field($article_data['primary_keyword'] ?? ''),
        );

        $analysis = $seo_optimizer->analyze($article);

        wp_send_json_success(array(
            'seo_score' => $analysis['seo_score'],
            'geo_score' => $analysis['geo_score'],
            'seo_analysis' => $analysis['seo_analysis'],
            'geo_analysis' => $analysis['geo_analysis'],
            'recommendations' => $analysis['recommendations'],
        ));
    }

    /**
     * Record user rating for an article
     */
    public function record_rating() {
        if (!$this->verify_request()) {
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;

        if (!$post_id || !$rating) {
            wp_send_json_error(array('message' => 'Post ID and rating are required.'));
            return;
        }

        $init = $this->get_init();
        $publisher = $init->get_component('publisher');

        if (!$publisher) {
            wp_send_json_error(array('message' => 'Publisher not available.'));
            return;
        }

        $result = $publisher->record_rating($post_id, $rating);

        if ($result) {
            wp_send_json_success(array('message' => 'Rating recorded.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to record rating.'));
        }
    }
}
