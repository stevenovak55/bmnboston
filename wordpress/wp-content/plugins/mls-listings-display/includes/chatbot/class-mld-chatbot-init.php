<?php
/**
 * Enhanced Chatbot System Initialization
 *
 * Bootstraps and initializes all components of the enhanced chatbot system
 * including data references, conversation management, and agent handoff.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Init {

    /**
     * Singleton instance
     *
     * @var MLD_Chatbot_Init
     */
    private static $instance = null;

    /**
     * Component instances
     *
     * @var array
     */
    private $components = array();

    /**
     * Initialization status
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Get singleton instance
     *
     * @return MLD_Chatbot_Init
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
        // Initialize on plugins_loaded to ensure all dependencies are available
        add_action('plugins_loaded', array($this, 'initialize'), 20);
    }

    /**
     * Initialize the chatbot system
     */
    public function initialize() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Init] initialize() called, initialized=' . ($this->initialized ? 'TRUE' : 'FALSE'));
        }

        if ($this->initialized) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Init] Already initialized, returning early');
            }
            return;
        }

        // Load all component files
        $this->load_components();

        // Initialize core components
        $this->init_core_components();

        // Register hooks
        $this->register_hooks();

        // Schedule cron jobs
        $this->schedule_cron_jobs();

        $this->initialized = true;

        // Log initialization
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot v6.7.0] Enhanced chatbot system initialized successfully');
        }
    }

    /**
     * Load component files
     */
    private function load_components() {
        $base_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'includes/chatbot/';

        $components = array(
            // Core components (order matters)
            'class-mld-data-reference-mapper.php',
            'class-mld-unified-data-provider.php',
            'class-mld-conversation-state.php',
            'class-mld-conversation-context.php', // v6.14.0 - Context persistence manager
            'class-mld-response-engine.php',
            'class-mld-agent-handoff.php',
            'class-mld-knowledge-scanner.php',
            'class-mld-chatbot-ajax-enhanced.php',
            'class-mld-chatbot-ajax.php',

            // Existing components that need to be loaded
            'class-mld-chatbot-engine.php',
            'class-mld-faq-manager.php',
            'interface-mld-ai-provider.php',
            'abstract-mld-ai-provider.php'
        );

        // Load AI provider implementations if they exist
        $ai_providers = array(
            'class-mld-openai-provider.php',
            'class-mld-claude-provider.php',
            'class-mld-cohere-provider.php'
        );

        // Load each component
        foreach ($components as $component) {
            $file = $base_path . $component;
            if (file_exists($file)) {
                require_once $file;
            } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Chatbot] Component not found: {$component}");
            }
        }

        // Load AI providers
        foreach ($ai_providers as $provider) {
            $file = $base_path . 'providers/' . $provider;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Initialize core components
     */
    private function init_core_components() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Init] Starting core component initialization');
        }

        // Initialize AJAX handlers (original - handles mld_chat_* actions used by frontend)
        if (class_exists('MLD_Chatbot_AJAX')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Init] MLD_Chatbot_AJAX class found, instantiating...');
            }
            $this->components['ajax_handler'] = new MLD_Chatbot_AJAX();
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Init] MLD_Chatbot_AJAX class NOT found!');
        }

        // Initialize data reference mapper
        if (class_exists('MLD_Data_Reference_Mapper')) {
            $this->components['data_mapper'] = new MLD_Data_Reference_Mapper();
        }

        // Initialize unified data provider
        if (class_exists('MLD_Unified_Data_Provider')) {
            $this->components['data_provider'] = new MLD_Unified_Data_Provider();
        }

        // Initialize agent handoff system
        if (class_exists('MLD_Agent_Handoff')) {
            $this->components['agent_handoff'] = new MLD_Agent_Handoff();
        }

        // Initialize enhanced AJAX handlers (handles mld_chatbot_* actions for Phase 3 AI integration)
        // Note: Stored separately to not overwrite original ajax_handler needed by frontend
        if (class_exists('MLD_Chatbot_Ajax_Enhanced')) {
            $this->components['ajax_handler_enhanced'] = new MLD_Chatbot_Ajax_Enhanced();
        }

        // Initialize knowledge scanner
        if (class_exists('MLD_Knowledge_Scanner')) {
            $this->components['knowledge_scanner'] = new MLD_Knowledge_Scanner();
        }

        // Initialize FAQ manager
        if (class_exists('MLD_FAQ_Manager')) {
            $this->components['faq_manager'] = new MLD_FAQ_Manager();
        }
    }

    /**
     * Register hooks
     */
    private function register_hooks() {
        // Frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Shortcode for chatbot widget
        add_shortcode('mld_chatbot', array($this, 'render_chatbot_shortcode'));

        // Widget area registration
        add_action('widgets_init', array($this, 'register_chatbot_widget'));

        // REST API endpoints (alternative to AJAX)
        add_action('rest_api_init', array($this, 'register_rest_endpoints'));

        // Database upgrade hook
        add_action('plugins_loaded', array($this, 'check_database_upgrade'), 5);

        // Activation/deactivation hooks
        register_activation_hook(MLD_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(MLD_PLUGIN_FILE, array($this, 'deactivate'));
    }

    /**
     * Schedule cron jobs
     */
    private function schedule_cron_jobs() {
        // Knowledge base scan (daily)
        if (!wp_next_scheduled('mld_chatbot_knowledge_scan')) {
            wp_schedule_event(time(), 'daily', 'mld_chatbot_knowledge_scan');
        }
        add_action('mld_chatbot_knowledge_scan', array($this, 'run_knowledge_scan'));

        // Response cache cleanup (hourly)
        if (!wp_next_scheduled('mld_chatbot_cache_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'mld_chatbot_cache_cleanup');
        }
        add_action('mld_chatbot_cache_cleanup', array($this, 'cleanup_response_cache'));

        // Agent performance tracking (weekly)
        if (!wp_next_scheduled('mld_chatbot_agent_performance')) {
            wp_schedule_event(time(), 'weekly', 'mld_chatbot_agent_performance');
        }
        add_action('mld_chatbot_agent_performance', array($this, 'track_agent_performance'));
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only load on pages where chatbot is needed
        if (!$this->should_load_chatbot()) {
            return;
        }

        $version = MLD_VERSION;
        $base_url = plugin_dir_url(dirname(dirname(__FILE__)));

        // Enqueue styles
        wp_enqueue_style(
            'mld-chatbot',
            $base_url . 'assets/css/chatbot.css',
            array(),
            $version
        );

        // Enqueue enhanced chatbot script
        wp_enqueue_script(
            'mld-chatbot',
            $base_url . 'dist/js/chatbot-widget.js',
            array(),
            $version,
            true
        );

        // Localize script with necessary data
        $this->localize_frontend_script();
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on chatbot admin pages
        if (strpos($hook, 'mld-chatbot') === false) {
            return;
        }

        $version = MLD_VERSION;
        $base_url = plugin_dir_url(dirname(dirname(__FILE__)));

        // Admin styles
        wp_enqueue_style(
            'mld-chatbot-admin',
            $base_url . 'admin/css/chatbot-admin.css',
            array(),
            $version
        );

        // Admin scripts
        wp_enqueue_script(
            'mld-chatbot-admin',
            $base_url . 'admin/js/chatbot-admin.js',
            array('jquery', 'wp-color-picker'),
            $version,
            true
        );

        // Chart.js for analytics
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array(),
            '3.9.1',
            true
        );
    }

    /**
     * Localize frontend script
     */
    private function localize_frontend_script() {
        $current_user = wp_get_current_user();

        // Try to get phone from user meta (common meta keys)
        $user_phone = '';
        if ($current_user->ID > 0) {
            $user_phone = get_user_meta($current_user->ID, 'phone', true);
            if (empty($user_phone)) {
                $user_phone = get_user_meta($current_user->ID, 'billing_phone', true); // WooCommerce
            }
            if (empty($user_phone)) {
                $user_phone = get_user_meta($current_user->ID, 'user_phone', true);
            }
        }

        wp_localize_script('mld-chatbot', 'mldChatbot', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('mld-chatbot/v1/'),
            'nonce' => wp_create_nonce('mld_chatbot_nonce'),
            'session_id' => $this->get_session_id(),
            'user' => array(
                'id' => $current_user->ID,
                'name' => $current_user->display_name,
                'email' => $current_user->user_email,
                'phone' => $user_phone
            ),
            'greeting' => $this->get_greeting(),
            'settings' => $this->get_frontend_settings(),
            'translations' => $this->get_translations(),
            'lead_gate' => array(
                'enabled' => $this->is_lead_gate_enabled()
            )
        ));
    }

    /**
     * Check if lead capture gate is enabled
     *
     * @return bool True if lead gate is enabled
     * @since 6.27.0
     */
    private function is_lead_gate_enabled() {
        global $wpdb;

        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
            'lead_gate_enabled'
        ));

        // Default to enabled if not set
        return $enabled !== '0';
    }

    /**
     * Render chatbot shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_chatbot_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'position' => 'bottom-right',
            'theme' => 'default',
            'welcome_message' => '',
            'placeholder' => 'Type your message...',
            'auto_open' => 'false'
        ), $atts);

        ob_start();
        ?>
        <div id="mld-chatbot-widget"
             class="mld-chatbot-widget"
             data-position="<?php echo esc_attr($atts['position']); ?>"
             data-theme="<?php echo esc_attr($atts['theme']); ?>"
             data-auto-open="<?php echo esc_attr($atts['auto_open']); ?>">

            <div class="mld-chatbot-trigger">
                <button type="button" class="mld-chatbot-trigger-button" aria-label="Open chat">
                    <span class="mld-chatbot-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12c0 1.54.36 3 .97 4.29L1 23l6.71-1.97C9 21.64 10.46 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.41 0-2.73-.36-3.88-.99l-.28-.15-2.92.77.77-2.92-.15-.28C4.91 14.73 4.55 13.41 4.55 12c0-4.42 3.58-8 8-8s8 3.58 8 8-3.58 8-8 8z"/>
                        </svg>
                    </span>
                    <span class="mld-chatbot-badge" style="display: none;">1</span>
                </button>
            </div>

            <div class="mld-chatbot-container" style="display: none;">
                <div class="mld-chatbot-header">
                    <div class="mld-chatbot-header-content">
                        <h3>Chat with us</h3>
                        <p>We're here to help you find your dream home</p>
                    </div>
                    <button type="button" class="mld-chatbot-close" aria-label="Close chat">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>

                <div class="mld-chatbot-messages" id="mld-chatbot-messages">
                    <?php if (!empty($atts['welcome_message'])): ?>
                        <div class="mld-chatbot-message mld-chatbot-message-bot">
                            <div class="mld-chatbot-message-content">
                                <?php echo esc_html($atts['welcome_message']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mld-chatbot-suggestions" id="mld-chatbot-suggestions" style="display: none;">
                    <!-- Suggestions will be added dynamically -->
                </div>

                <div class="mld-chatbot-typing" id="mld-chatbot-typing" style="display: none;">
                    <div class="mld-chatbot-typing-indicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>

                <div class="mld-chatbot-input">
                    <form id="mld-chatbot-form">
                        <input type="text"
                               id="mld-chatbot-input"
                               placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                               autocomplete="off">
                        <button type="submit" aria-label="Send message">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Register chatbot widget
     */
    public function register_chatbot_widget() {
        // Widget registration would go here if needed
    }

    /**
     * Register REST API endpoints
     */
    public function register_rest_endpoints() {
        register_rest_route('mld-chatbot/v1', '/message', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_handle_message'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('mld-chatbot/v1', '/data/(?P<type>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_handle_data_query'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * REST API message handler
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function rest_handle_message($request) {
        $message = $request->get_param('message');
        $session_id = $request->get_param('session_id');
        $conversation_id = $request->get_param('conversation_id');

        // Process message using the enhanced engine
        $response_engine = new MLD_Response_Engine($conversation_id);
        $response = $response_engine->processQuestion($message);

        return new WP_REST_Response($response, 200);
    }

    /**
     * REST API data query handler
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function rest_handle_data_query($request) {
        $type = $request->get_param('type');
        $params = $request->get_params();

        $data_provider = new MLD_Unified_Data_Provider();
        $result = array();

        switch ($type) {
            case 'properties':
                $result = $data_provider->getPropertyData($params);
                break;
            case 'market':
                $result = $data_provider->getMarketAnalytics($params['area'] ?? null);
                break;
            case 'agents':
                $result = $data_provider->getAgentInfo();
                break;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Check if chatbot should load
     *
     * @return bool
     */
    private function should_load_chatbot() {
        // Load on all pages by default
        $load_on_all = get_option('mld_chatbot_load_on_all_pages', true);

        if ($load_on_all) {
            return true;
        }

        // Check specific page settings
        $enabled_pages = get_option('mld_chatbot_enabled_pages', array());
        $current_page_id = get_the_ID();

        return in_array($current_page_id, $enabled_pages);
    }

    /**
     * Get greeting message
     *
     * @return string
     */
    private function get_greeting() {
        global $wpdb;

        // Get greeting from chat_settings table
        $greeting = $wpdb->get_var(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = 'chatbot_greeting' LIMIT 1"
        );

        // Default greeting if not found
        if (empty($greeting)) {
            $greeting = 'Hello! 👋 I\'m your AI property assistant. How can I help you today?';
        }

        return $greeting;
    }

    /**
     * Get frontend settings
     *
     * @return array
     */
    private function get_frontend_settings() {
        return array(
            'position' => get_option('mld_chatbot_position', 'bottom-right'),
            'theme' => get_option('mld_chatbot_theme', 'default'),
            'auto_open_delay' => get_option('mld_chatbot_auto_open_delay', 0),
            'sound_enabled' => get_option('mld_chatbot_sound_enabled', true),
            'show_typing' => get_option('mld_chatbot_show_typing', true),
            'response_delay' => get_option('mld_chatbot_response_delay', 1000),
            'max_message_length' => 500
        );
    }

    /**
     * Get translations
     *
     * @return array
     */
    private function get_translations() {
        return array(
            'typing' => __('Typing...', 'mld'),
            'send' => __('Send', 'mld'),
            'placeholder' => __('Type your message...', 'mld'),
            'error' => __('Sorry, something went wrong. Please try again.', 'mld'),
            'offline' => __('Connection lost. Please check your internet.', 'mld'),
            'agent_connecting' => __('Connecting you with an agent...', 'mld'),
            'agent_connected' => __('You are now connected with an agent.', 'mld')
        );
    }

    /**
     * Get or generate session ID
     *
     * @return string
     */
    private function get_session_id() {
        if (!isset($_COOKIE['mld_chatbot_session'])) {
            $session_id = wp_generate_uuid4();
            setcookie('mld_chatbot_session', $session_id, time() + (86400 * 30), '/');
            return $session_id;
        }
        return $_COOKIE['mld_chatbot_session'];
    }

    /**
     * Run knowledge base scan
     */
    public function run_knowledge_scan() {
        if (isset($this->components['knowledge_scanner'])) {
            $results = $this->components['knowledge_scanner']->run_full_scan();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot] Knowledge scan completed: ' . json_encode($results));
            }
        }
    }

    /**
     * Cleanup response cache
     */
    public function cleanup_response_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_response_cache';

        // Delete expired cache entries
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE expires_at IS NOT NULL
             AND expires_at < %s",
            $wp_now
        ));

        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Chatbot] Cleaned up {$deleted} expired cache entries");
        }
    }

    /**
     * Track agent performance
     */
    public function track_agent_performance() {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        // Calculate performance metrics
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT
                agent_id,
                agent_name,
                COUNT(*) as total_leads,
                AVG(response_time_seconds) as avg_response_time,
                SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END) as conversions,
                SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END) as lost
             FROM {$table}
             WHERE created_at >= DATE_SUB(%s, INTERVAL 7 DAY)
             GROUP BY agent_id, agent_name",
            $wp_now
        ), ARRAY_A);

        // Store metrics for reporting
        update_option('mld_agent_performance_metrics', $metrics);
        update_option('mld_agent_performance_updated', current_time('mysql'));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot] Agent performance tracked for ' . count($metrics) . ' agents');
        }
    }

    /**
     * Check and run database upgrades
     */
    public function check_database_upgrade() {
        $current_version = get_option('mld_chatbot_db_version', '0');

        if (version_compare($current_version, '6.7.0', '<')) {
            $this->run_database_upgrade();
        }
    }

    /**
     * Run database upgrade
     */
    private function run_database_upgrade() {
        $update_file = plugin_dir_path(dirname(dirname(__FILE__))) . 'updates/update-6.7.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_7_0')) {
                $result = mld_update_to_6_7_0();
                if ($result) {
                    update_option('mld_chatbot_db_version', '6.7.0');
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Chatbot] Database upgraded to version 6.7.0');
                    }
                }
            }
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Run database upgrade
        $this->run_database_upgrade();

        // Schedule cron jobs
        $this->schedule_cron_jobs();

        // Create default options
        $this->create_default_options();

        // Flush rewrite rules for REST endpoints
        flush_rewrite_rules();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot] Plugin activated - version 6.7.0');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('mld_chatbot_knowledge_scan');
        wp_clear_scheduled_hook('mld_chatbot_cache_cleanup');
        wp_clear_scheduled_hook('mld_chatbot_agent_performance');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot] Plugin deactivated');
        }
    }

    /**
     * Create default options
     */
    private function create_default_options() {
        $defaults = array(
            'mld_chatbot_enabled' => true,
            'mld_chatbot_load_on_all_pages' => true,
            'mld_chatbot_position' => 'bottom-right',
            'mld_chatbot_theme' => 'default',
            'mld_chatbot_welcome_message' => 'Hi! I\'m here to help you find your perfect home. How can I assist you today?',
            'mld_agent_assignment_method' => 'round_robin',
            'mld_agent_notification_methods' => array('email'),
            'mld_chatbot_sound_enabled' => true,
            'mld_chatbot_show_typing' => true
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Get component instance
     *
     * @param string $component Component key
     * @return mixed Component instance or null
     */
    public function get_component($component) {
        return isset($this->components[$component]) ? $this->components[$component] : null;
    }
}

// Initialize the chatbot system
MLD_Chatbot_Init::get_instance();