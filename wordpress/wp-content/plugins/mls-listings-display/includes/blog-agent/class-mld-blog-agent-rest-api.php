<?php
/**
 * Blog Agent REST API
 *
 * REST API endpoints for the Blog Agent, primarily for Claude Code skill integration.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Agent_REST_API
 *
 * REST API for Blog Agent.
 */
class MLD_Blog_Agent_REST_API {

    /**
     * Namespace
     */
    const NAMESPACE = 'mld-blog-agent/v1';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Research topics
        register_rest_route(self::NAMESPACE, '/topics/research', array(
            'methods' => 'POST',
            'callback' => array($this, 'research_topics'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'count' => array(
                    'type' => 'integer',
                    'default' => 5,
                    'minimum' => 1,
                    'maximum' => 10,
                ),
            ),
        ));

        // Get pending topics
        register_rest_route(self::NAMESPACE, '/topics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_topics'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'type' => 'integer',
                    'default' => 10,
                ),
            ),
        ));

        // Get single topic
        register_rest_route(self::NAMESPACE, '/topics/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_topic'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Create custom topic
        register_rest_route(self::NAMESPACE, '/topics', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_topic'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'title' => array(
                    'type' => 'string',
                    'required' => true,
                ),
                'description' => array(
                    'type' => 'string',
                    'default' => '',
                ),
                'keywords' => array(
                    'type' => 'array',
                    'default' => array(),
                ),
                'related_cities' => array(
                    'type' => 'array',
                    'default' => array(),
                ),
            ),
        ));

        // Generate article
        register_rest_route(self::NAMESPACE, '/articles/generate', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_article'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'topic_id' => array(
                    'type' => 'integer',
                ),
                'topic' => array(
                    'type' => 'object',
                ),
                'target_length' => array(
                    'type' => 'integer',
                    'default' => 2000,
                ),
                'cta_type' => array(
                    'type' => 'string',
                    'default' => 'auto',
                ),
            ),
        ));

        // Save as draft
        register_rest_route(self::NAMESPACE, '/articles/draft', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_draft'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'article' => array(
                    'type' => 'object',
                    'required' => true,
                ),
                'category' => array(
                    'type' => 'string',
                    'default' => 'real-estate',
                ),
            ),
        ));

        // Publish article
        register_rest_route(self::NAMESPACE, '/articles/(?P<id>\d+)/publish', array(
            'methods' => 'POST',
            'callback' => array($this, 'publish_article'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Get articles
        register_rest_route(self::NAMESPACE, '/articles', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_articles'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'type' => 'integer',
                    'default' => 10,
                ),
                'status' => array(
                    'type' => 'string',
                    'default' => '',
                ),
            ),
        ));

        // SEO analysis
        register_rest_route(self::NAMESPACE, '/analyze', array(
            'methods' => 'POST',
            'callback' => array($this, 'analyze_content'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'title' => array(
                    'type' => 'string',
                    'required' => true,
                ),
                'content' => array(
                    'type' => 'string',
                    'required' => true,
                ),
                'meta_description' => array(
                    'type' => 'string',
                    'default' => '',
                ),
            ),
        ));

        // Full workflow endpoint (for Claude Code skill)
        register_rest_route(self::NAMESPACE, '/workflow', array(
            'methods' => 'POST',
            'callback' => array($this, 'run_workflow'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'action' => array(
                    'type' => 'string',
                    'required' => true,
                    'enum' => array('research', 'generate', 'save', 'publish', 'full'),
                ),
                'topic' => array(
                    'type' => 'string',
                ),
                'topic_id' => array(
                    'type' => 'integer',
                ),
                'options' => array(
                    'type' => 'object',
                    'default' => array(),
                ),
            ),
        ));
    }

    /**
     * Check permission for API access
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_permission($request) {
        // Allow if user can manage options
        if (current_user_can('manage_options')) {
            return true;
        }

        // Check for application password or other auth methods
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to access this endpoint.',
            array('status' => 403)
        );
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
     * Research trending topics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function research_topics($request) {
        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Topic researcher not available.',
            ), 500);
        }

        $prompt_manager = $init->get_component('prompt_manager');
        if ($prompt_manager) {
            $researcher->set_prompt_manager($prompt_manager);
        }

        $result = $researcher->research_topics(array(
            'count' => $request->get_param('count'),
            'exclude_recent' => true,
        ));

        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'topics' => $result['topics'],
                'count' => $result['count'],
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $result['error'] ?? 'Research failed.',
        ), 500);
    }

    /**
     * Get pending topics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_topics($request) {
        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Topic researcher not available.',
            ), 500);
        }

        $topics = $researcher->get_pending_topics($request->get_param('limit'));

        return new WP_REST_Response(array(
            'success' => true,
            'topics' => $topics,
        ), 200);
    }

    /**
     * Get single topic
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_topic($request) {
        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Topic researcher not available.',
            ), 500);
        }

        $topic = $researcher->get_topic($request->get_param('id'));

        if ($topic) {
            return new WP_REST_Response(array(
                'success' => true,
                'topic' => $topic,
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Topic not found.',
        ), 404);
    }

    /**
     * Create custom topic
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function create_topic($request) {
        $init = $this->get_init();
        $researcher = $init->get_component('topic_researcher');

        if (!$researcher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Topic researcher not available.',
            ), 500);
        }

        $topic_data = array(
            'title' => $request->get_param('title'),
            'description' => $request->get_param('description'),
            'keywords' => $request->get_param('keywords'),
            'related_cities' => $request->get_param('related_cities'),
        );

        $topic_id = $researcher->create_custom_topic($topic_data);

        if ($topic_id) {
            $topic = $researcher->get_topic($topic_id);
            return new WP_REST_Response(array(
                'success' => true,
                'topic_id' => $topic_id,
                'topic' => $topic,
            ), 201);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Failed to create topic.',
        ), 500);
    }

    /**
     * Generate article from topic
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function generate_article($request) {
        $init = $this->get_init();

        $topic_id = $request->get_param('topic_id');
        $topic_data = $request->get_param('topic');

        // Get topic data
        if ($topic_id && !$topic_data) {
            $researcher = $init->get_component('topic_researcher');
            $topic_data = $researcher ? $researcher->get_topic($topic_id) : null;
        }

        if (!$topic_data) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Topic is required.',
            ), 400);
        }

        // Set up generator
        $generator = $init->get_component('content_generator');
        $prompt_manager = $init->get_component('prompt_manager');
        $internal_linker = $init->get_component('internal_linker');
        $cta_manager = $init->get_component('cta_manager');
        $seo_optimizer = $init->get_component('seo_optimizer');
        $image_handler = $init->get_component('image_handler');

        if (!$generator) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Content generator not available.',
            ), 500);
        }

        $generator->set_dependencies($prompt_manager, $internal_linker, $cta_manager);

        $options = array(
            'target_length' => $request->get_param('target_length'),
            'cta_type' => $request->get_param('cta_type'),
            'include_market_data' => true,
            'include_school_data' => true,
        );

        $result = $generator->generate_article($topic_data, $options);

        if (!$result['success']) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $result['error'] ?? 'Generation failed.',
            ), 500);
        }

        $article = $result['article'];
        $article['topic_id'] = $topic_data['id'] ?? 0;

        // Run SEO analysis
        if ($seo_optimizer) {
            $analysis = $seo_optimizer->analyze($article);
            $article['seo_score'] = $analysis['seo_score'];
            $article['geo_score'] = $analysis['geo_score'];
            $article['schema'] = $analysis['schema'];
            $article = $seo_optimizer->optimize($article, $analysis);
        }

        // Get images
        if ($image_handler) {
            $images = $image_handler->get_images($article, array(
                'related_cities' => $topic_data['related_cities'] ?? array(),
            ));
            $article['images'] = $images['images'];
        }

        $article['stats'] = $result['stats'];
        $article['prompt_version'] = $result['prompt_version'];

        return new WP_REST_Response(array(
            'success' => true,
            'article' => $article,
        ), 200);
    }

    /**
     * Save article as draft
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function save_draft($request) {
        $init = $this->get_init();
        $publisher = $init->get_component('publisher');
        $image_handler = $init->get_component('image_handler');
        $seo_optimizer = $init->get_component('seo_optimizer');

        if (!$publisher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Publisher not available.',
            ), 500);
        }

        $publisher->set_dependencies($image_handler, $seo_optimizer);

        $article = $request->get_param('article');
        $options = array(
            'category' => $request->get_param('category'),
        );

        $result = $publisher->create_draft($article, $options);

        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'post_id' => $result['post_id'],
                'edit_url' => $result['edit_url'],
                'preview_url' => $result['preview_url'],
            ), 201);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $result['error'] ?? 'Failed to save draft.',
        ), 500);
    }

    /**
     * Publish article
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function publish_article($request) {
        $init = $this->get_init();
        $publisher = $init->get_component('publisher');

        if (!$publisher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Publisher not available.',
            ), 500);
        }

        $post_id = $request->get_param('id');
        $result = $publisher->publish($post_id);

        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'post_id' => $result['post_id'],
                'url' => $result['url'],
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $result['error'] ?? 'Failed to publish.',
        ), 500);
    }

    /**
     * Get articles
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_articles($request) {
        $init = $this->get_init();
        $publisher = $init->get_component('publisher');

        if (!$publisher) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Publisher not available.',
            ), 500);
        }

        $articles = $publisher->get_recent_articles($request->get_param('limit'));

        return new WP_REST_Response(array(
            'success' => true,
            'articles' => $articles,
        ), 200);
    }

    /**
     * Analyze content for SEO
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analyze_content($request) {
        $init = $this->get_init();
        $seo_optimizer = $init->get_component('seo_optimizer');

        if (!$seo_optimizer) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'SEO optimizer not available.',
            ), 500);
        }

        $article = array(
            'title' => $request->get_param('title'),
            'content' => $request->get_param('content'),
            'meta_description' => $request->get_param('meta_description'),
            'primary_keyword' => $request->get_param('primary_keyword'),
        );

        $analysis = $seo_optimizer->analyze($article);

        return new WP_REST_Response(array(
            'success' => true,
            'seo_score' => $analysis['seo_score'],
            'geo_score' => $analysis['geo_score'],
            'recommendations' => $analysis['recommendations'],
        ), 200);
    }

    /**
     * Run full workflow (for Claude Code skill)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function run_workflow($request) {
        $action = $request->get_param('action');
        $topic = $request->get_param('topic');
        $topic_id = $request->get_param('topic_id');
        $options = $request->get_param('options');

        switch ($action) {
            case 'research':
                return $this->research_topics($request);

            case 'generate':
                if (!$topic && !$topic_id) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'error' => 'Topic or topic_id is required for generation.',
                    ), 400);
                }

                // If topic is a string, create a custom topic first
                if (is_string($topic) && !empty($topic)) {
                    $request->set_param('title', $topic);
                    $request->set_param('description', '');
                    $request->set_param('keywords', array());
                    $request->set_param('related_cities', array('Boston'));
                    $topic_response = $this->create_topic($request);
                    $topic_data = $topic_response->get_data();

                    if (!$topic_data['success']) {
                        return $topic_response;
                    }

                    $request->set_param('topic_id', $topic_data['topic_id']);
                    $request->set_param('topic', $topic_data['topic']);
                }

                return $this->generate_article($request);

            case 'save':
                return $this->save_draft($request);

            case 'publish':
                return $this->publish_article($request);

            case 'full':
                // Full workflow: research -> select first topic -> generate -> save as draft
                $init = $this->get_init();

                // Step 1: Research or use provided topic
                if ($topic) {
                    // Create custom topic
                    $researcher = $init->get_component('topic_researcher');
                    $topic_id = $researcher->create_custom_topic(array(
                        'title' => is_string($topic) ? $topic : ($topic['title'] ?? ''),
                        'description' => is_array($topic) ? ($topic['description'] ?? '') : '',
                        'keywords' => is_array($topic) ? ($topic['keywords'] ?? array()) : array(),
                        'related_cities' => is_array($topic) ? ($topic['related_cities'] ?? array('Boston')) : array('Boston'),
                    ));
                    $topic_data = $researcher->get_topic($topic_id);
                } elseif ($topic_id) {
                    $researcher = $init->get_component('topic_researcher');
                    $topic_data = $researcher->get_topic($topic_id);
                } else {
                    // Research and use top topic
                    $request->set_param('count', 5);
                    $research_response = $this->research_topics($request);
                    $research_data = $research_response->get_data();

                    if (!$research_data['success'] || empty($research_data['topics'])) {
                        return $research_response;
                    }

                    $topic_data = $research_data['topics'][0];
                }

                // Step 2: Generate article
                $request->set_param('topic', $topic_data);
                $request->set_param('topic_id', $topic_data['id'] ?? 0);
                $request->set_param('target_length', $options['target_length'] ?? 2000);
                $request->set_param('cta_type', $options['cta_type'] ?? 'auto');

                $generate_response = $this->generate_article($request);
                $generate_data = $generate_response->get_data();

                if (!$generate_data['success']) {
                    return $generate_response;
                }

                // Step 3: Save as draft
                $request->set_param('article', $generate_data['article']);
                $request->set_param('category', $options['category'] ?? 'real-estate');

                $draft_response = $this->save_draft($request);
                $draft_data = $draft_response->get_data();

                return new WP_REST_Response(array(
                    'success' => $draft_data['success'],
                    'topic' => $topic_data,
                    'article' => $generate_data['article'],
                    'post_id' => $draft_data['post_id'] ?? null,
                    'edit_url' => $draft_data['edit_url'] ?? null,
                    'preview_url' => $draft_data['preview_url'] ?? null,
                ), $draft_data['success'] ? 201 : 500);

            default:
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Invalid action.',
                ), 400);
        }
    }
}
