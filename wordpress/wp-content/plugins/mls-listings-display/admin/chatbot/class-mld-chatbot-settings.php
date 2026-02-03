<?php
/**
 * Chatbot Admin Settings Page
 *
 * Handles the chatbot configuration interface in WordPress admin
 * Provides tabs for AI config, knowledge base, notifications, and FAQ management
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Settings {

    /**
     * Settings page slug
     *
     * @var string
     */
    const PAGE_SLUG = 'mld-chatbot-settings';

    /**
     * Option group name
     *
     * @var string
     */
    const OPTION_GROUP = 'mld_chatbot_settings';

    /**
     * Current active tab
     *
     * @var string
     */
    private $active_tab;

    /**
     * Available settings tabs
     *
     * @var array
     */
    private $tabs = array(
        'ai_config' => 'AI Configuration',
        'knowledge' => 'Knowledge Base',
        'notifications' => 'Notifications',
        'faq' => 'FAQ Library',
        'analytics' => 'Analytics',
    );

    /**
     * Ensure table schema has all required columns
     * Auto-repairs missing columns on first access (defensive coding)
     * Added in v6.11.44 to fix production schema mismatch issues
     *
     * @since 6.11.44
     * @return void
     */
    private static function ensure_table_schema() {
        static $schema_verified = false;
        if ($schema_verified) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if (!$table_exists) {
            $schema_verified = true;
            return;
        }

        // Get existing columns
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        // Define required columns with their ALTER TABLE statements
        $required_columns = array(
            'setting_type' => "ADD COLUMN setting_type varchar(50) DEFAULT 'string' AFTER setting_value",
            'setting_category' => "ADD COLUMN setting_category varchar(50) DEFAULT 'general' AFTER setting_type",
            'is_encrypted' => "ADD COLUMN is_encrypted tinyint(1) DEFAULT 0 AFTER setting_category",
            'description' => "ADD COLUMN description text DEFAULT NULL AFTER is_encrypted"
        );

        $columns_added = array();
        foreach ($required_columns as $column => $sql) {
            if (!in_array($column, $existing_columns)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");
                if ($result !== false) {
                    $columns_added[] = $column;
                }
            }
        }

        $schema_verified = true;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 20); // Priority 20 to run after parent menu
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_mld_test_ai_connection', array($this, 'ajax_test_ai_connection'));
        add_action('wp_ajax_mld_save_chatbot_setting', array($this, 'ajax_save_setting'));
        add_action('wp_ajax_mld_run_knowledge_scan', array($this, 'ajax_run_knowledge_scan'));
        add_action('wp_ajax_mld_save_faq', array($this, 'ajax_save_faq'));
        add_action('wp_ajax_mld_get_faq', array($this, 'ajax_get_faq'));
        add_action('wp_ajax_mld_delete_faq', array($this, 'ajax_delete_faq'));

        // A/B Testing AJAX handlers (v6.9.0)
        add_action('wp_ajax_mld_save_variant', array($this, 'ajax_save_variant'));
        add_action('wp_ajax_mld_get_variant', array($this, 'ajax_get_variant'));
        add_action('wp_ajax_mld_delete_variant', array($this, 'ajax_delete_variant'));
        add_action('wp_ajax_mld_toggle_variant', array($this, 'ajax_toggle_variant'));
        add_action('wp_ajax_mld_update_variant_weight', array($this, 'ajax_update_variant_weight'));

        // Smart Model Routing AJAX handlers (v6.11.1)
        add_action('wp_ajax_mld_save_routing_config', array($this, 'ajax_save_routing_config'));
        add_action('wp_ajax_mld_refresh_models', array($this, 'ajax_refresh_models'));
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'AI Chatbot Settings',
            'AI Chatbot',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            'mld_chatbot_settings',
            array($this, 'sanitize_settings')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'mld-chatbot-admin',
            MLD_PLUGIN_URL . 'admin/chatbot/css/chatbot-settings.css',
            array(),
            MLD_VERSION
        );

        wp_enqueue_script(
            'mld-chatbot-admin',
            MLD_PLUGIN_URL . 'admin/chatbot/js/chatbot-settings.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        // Variant manager for A/B testing (v6.9.0)
        wp_enqueue_script(
            'mld-variant-manager',
            MLD_PLUGIN_URL . 'assets/js/variant-manager.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        wp_localize_script('mld-chatbot-admin', 'mldChatbot', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_chatbot_settings'),
            'strings' => array(
                'testingConnection' => __('Testing connection...', 'mls-listings-display'),
                'connectionSuccess' => __('Connection successful!', 'mls-listings-display'),
                'connectionFailed' => __('Connection failed', 'mls-listings-display'),
                'saving' => __('Saving...', 'mls-listings-display'),
                'saved' => __('Settings saved', 'mls-listings-display'),
                'scanningWebsite' => __('Scanning website...', 'mls-listings-display'),
                'scanComplete' => __('Scan complete', 'mls-listings-display'),
            ),
        ));
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get active tab
        $this->active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'ai_config';

        // Get current settings
        $settings = $this->get_all_settings();

        ?>
        <div class="wrap mld-chatbot-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->render_status_messages(); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($this->tabs as $tab_key => $tab_label) : ?>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=<?php echo esc_attr($tab_key); ?>"
                       class="nav-tab <?php echo $this->active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="mld-chatbot-tab-content">
                <?php
                switch ($this->active_tab) {
                    case 'ai_config':
                        $this->render_ai_config_tab($settings);
                        break;
                    case 'knowledge':
                        $this->render_knowledge_tab($settings);
                        break;
                    case 'notifications':
                        $this->render_notifications_tab($settings);
                        break;
                    case 'faq':
                        $this->render_faq_tab();
                        break;
                    case 'analytics':
                        $this->render_analytics_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render status messages
     */
    private function render_status_messages() {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved successfully.', 'mls-listings-display'); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Render AI Configuration tab
     *
     * @param array $settings Current settings
     */
    private function render_ai_config_tab($settings) {
        require_once dirname(__FILE__) . '/views/tab-ai-config.php';
        mld_render_ai_config_tab($settings);
    }

    /**
     * Render Knowledge Base tab
     *
     * @param array $settings Current settings
     */
    private function render_knowledge_tab($settings) {
        require_once dirname(__FILE__) . '/views/tab-knowledge.php';
        mld_render_knowledge_tab($settings);
    }

    /**
     * Render Notifications tab
     *
     * @param array $settings Current settings
     */
    private function render_notifications_tab($settings) {
        require_once dirname(__FILE__) . '/views/tab-notifications.php';
        mld_render_notifications_tab($settings);
    }

    /**
     * Render FAQ tab
     */
    private function render_faq_tab() {
        require_once dirname(__FILE__) . '/views/tab-faq.php';
        mld_render_faq_tab();
    }

    /**
     * Render Analytics tab
     */
    private function render_analytics_tab() {
        require_once dirname(__FILE__) . '/views/tab-analytics.php';
        mld_render_analytics_tab();
    }

    /**
     * Get all chatbot settings from database
     *
     * @return array Settings grouped by category
     */
    private function get_all_settings() {
        // Ensure table schema is complete before querying
        self::ensure_table_schema();

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $results = $wpdb->get_results(
            "SELECT setting_key, setting_value, setting_category, setting_type
             FROM {$table_name}
             ORDER BY setting_category, setting_key",
            ARRAY_A
        );

        $settings = array();
        foreach ($results as $row) {
            $category = $row['setting_category'];
            if (!isset($settings[$category])) {
                $settings[$category] = array();
            }
            $settings[$category][$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    /**
     * Get single setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public static function get_setting($key, $default = null) {
        // Ensure table schema is complete before querying
        self::ensure_table_schema();

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            $key
        ));

        return $value !== null ? $value : $default;
    }

    /**
     * Update single setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @param string $category Setting category
     * @param string $type Setting type
     * @return bool Success status
     */
    public static function update_setting($key, $value, $category = 'general', $type = 'string', $is_encrypted = null) {
        // Ensure table schema is complete before updating
        self::ensure_table_schema();

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        // Check if setting exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE setting_key = %s",
            $key
        ));

        if ($exists) {
            // Update existing
            $update_data = array(
                'setting_value' => $value,
                'setting_type' => $type,
            );
            $update_format = array('%s', '%s');

            // Include is_encrypted if specified
            if ($is_encrypted !== null) {
                $update_data['is_encrypted'] = $is_encrypted;
                $update_format[] = '%d';
            }

            $result = $wpdb->update(
                $table_name,
                $update_data,
                array('setting_key' => $key),
                $update_format,
                array('%s')
            );

            return $result;
        } else {
            // Insert new
            $insert_data = array(
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_category' => $category,
                'setting_type' => $type,
            );
            $insert_format = array('%s', '%s', '%s', '%s');

            // Include is_encrypted if specified
            if ($is_encrypted !== null) {
                $insert_data['is_encrypted'] = $is_encrypted;
                $insert_format[] = '%d';
            }

            $result = $wpdb->insert(
                $table_name,
                $insert_data,
                $insert_format
            );

            return $result;
        }
    }

    /**
     * Sanitize settings before saving
     *
     * @param array $settings Raw settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($settings) {
        $sanitized = array();

        foreach ($settings as $key => $value) {
            // Sanitize based on key pattern
            if (strpos($key, 'email') !== false) {
                $sanitized[$key] = sanitize_email($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = floatval($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    /**
     * AJAX: Test AI provider connection
     */
    public function ajax_test_ai_connection() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
        $use_stored_key = isset($_POST['use_stored_key']) && $_POST['use_stored_key'] == '1';

        // Test provider doesn't need an API key
        if (empty($provider)) {
            wp_send_json_error(array('message' => 'Provider is required'));
        }

        // If no API key provided and use_stored_key is set, fetch from database
        if ($provider !== 'test' && empty($api_key) && $use_stored_key) {
            global $wpdb;
            $settings_table = $wpdb->prefix . 'mld_chat_settings';
            $stored_key = $wpdb->get_var($wpdb->prepare(
                "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
                $provider . '_api_key'
            ));

            if (!empty($stored_key)) {
                // Decrypt if encrypted
                $api_key = $this->decrypt_api_key($stored_key);
            }

            // Also get stored model if not provided
            if (empty($model)) {
                $model = $wpdb->get_var($wpdb->prepare(
                    "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
                    $provider . '_model'
                ));
            }
        }

        if ($provider !== 'test' && empty($api_key)) {
            wp_send_json_error(array('message' => 'No API key configured for this provider'));
        }

        // Load provider class
        $provider_class = $this->get_provider_class($provider);
        if (!$provider_class) {
            wp_send_json_error(array('message' => 'Invalid provider'));
        }

        // Test connection
        $provider_instance = new $provider_class($api_key, $model);
        $result = $provider_instance->test_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Save single setting
     */
    public function ajax_save_setting() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        $value = isset($_POST['value']) ? $_POST['value'] : '';
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'general';

        if (empty($key)) {
            wp_send_json_error(array('message' => 'Setting key is required'));
        }

        // Encrypt API keys
        $is_encrypted = 0;
        if (strpos($key, 'api_key') !== false && !empty($value)) {
            $value = $this->encrypt_api_key($value);
            $is_encrypted = 1;
        }

        global $wpdb;

        $success = self::update_setting($key, $value, $category, 'string', $is_encrypted);

        if ($success !== false) {
            wp_send_json_success(array('message' => 'Setting saved'));
        } else {
            $error_msg = 'Failed to save setting';
            if ($wpdb->last_error) {
                $error_msg .= ': ' . $wpdb->last_error;
            }
            wp_send_json_error(array('message' => $error_msg));
        }
    }

    /**
     * AJAX: Run knowledge base scan
     */
    public function ajax_run_knowledge_scan() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        // Run knowledge base scan
        $scanner = new MLD_Knowledge_Scanner();
        $results = $scanner->run_full_scan();

        if ($results['success']) {
            wp_send_json_success(array(
                'message' => sprintf(
                    'Scan complete! Scanned %d items, updated %d entries.',
                    $results['scanned'],
                    $results['updated']
                ),
                'results' => $results,
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Knowledge scan failed. Check error logs for details.',
                'results' => $results,
            ));
        }
    }

    /**
     * AJAX: Save FAQ entry
     */
    public function ajax_save_faq() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_faq_library';

        $faq_id = isset($_POST['faq_id']) ? intval($_POST['faq_id']) : 0;
        $question = isset($_POST['question']) ? sanitize_textarea_field($_POST['question']) : '';
        $answer = isset($_POST['answer']) ? wp_kses_post($_POST['answer']) : '';
        $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'general';
        $priority = isset($_POST['priority']) ? intval($_POST['priority']) : 5;

        if (empty($question) || empty($answer)) {
            wp_send_json_error(array('message' => 'Question and answer are required'));
        }

        $data = array(
            'question' => $question,
            'answer' => $answer,
            'keywords' => $keywords,
            'category' => $category,
            'priority' => $priority,
            'is_active' => 1,
        );

        if ($faq_id > 0) {
            // Update existing
            $result = $wpdb->update($table_name, $data, array('id' => $faq_id));
        } else {
            // Insert new
            $data['created_by'] = get_current_user_id();
            $result = $wpdb->insert($table_name, $data);
            $faq_id = $wpdb->insert_id;
        }

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'FAQ saved',
                'faq_id' => $faq_id,
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save FAQ'));
        }
    }

    /**
     * AJAX: Get single FAQ entry for editing
     */
    public function ajax_get_faq() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $faq_id = isset($_POST['faq_id']) ? intval($_POST['faq_id']) : 0;

        if ($faq_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid FAQ ID'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_faq_library';

        $faq = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $faq_id
        ), ARRAY_A);

        if ($faq) {
            wp_send_json_success($faq);
        } else {
            wp_send_json_error(array('message' => 'FAQ not found'));
        }
    }

    /**
     * AJAX: Delete FAQ entry
     */
    public function ajax_delete_faq() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $faq_id = isset($_POST['faq_id']) ? intval($_POST['faq_id']) : 0;

        if ($faq_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid FAQ ID'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_faq_library';

        $result = $wpdb->delete($table_name, array('id' => $faq_id), array('%d'));

        if ($result !== false) {
            wp_send_json_success(array('message' => 'FAQ deleted'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete FAQ'));
        }
    }

    /**
     * Get provider class name
     *
     * @param string $provider Provider name
     * @return string|null Class name or null
     */
    private function get_provider_class($provider) {
        $providers = array(
            'test' => 'MLD_Test_Provider',
            'openai' => 'MLD_OpenAI_Provider',
            'claude' => 'MLD_Claude_Provider',
            'gemini' => 'MLD_Gemini_Provider',
        );

        if (!isset($providers[$provider])) {
            return null;
        }

        $class_file = MLD_PLUGIN_PATH . 'includes/chatbot/providers/class-mld-' . $provider . '-provider.php';
        if (file_exists($class_file)) {
            require_once $class_file;
            return $providers[$provider];
        }

        return null;
    }

    /**
     * Encrypt API key
     *
     * @param string $plaintext API key
     * @return string Encrypted value
     */
    private function encrypt_api_key($plaintext) {
        $key = wp_salt('auth');
        return openssl_encrypt($plaintext, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
    }

    /**
     * Decrypt an API key
     *
     * @param string $ciphertext Encrypted value
     * @return string Decrypted value
     */
    private function decrypt_api_key($ciphertext) {
        $key = wp_salt('auth');
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
        // If decryption fails, the key might not be encrypted (legacy), return as-is
        return $decrypted !== false ? $decrypted : $ciphertext;
    }

    /**
     * AJAX: Save or update prompt variant
     *
     * @since 6.9.0
     */
    public function ajax_save_variant() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
        $variant_name = isset($_POST['variant_name']) ? sanitize_text_field($_POST['variant_name']) : '';
        $prompt_content = isset($_POST['prompt_content']) ? wp_kses_post($_POST['prompt_content']) : '';
        $weight = isset($_POST['weight']) ? intval($_POST['weight']) : 50;

        // Validate input
        if (empty($variant_name) || empty($prompt_content)) {
            wp_send_json_error(array('message' => 'Variant name and prompt content are required'));
        }

        if ($weight < 0 || $weight > 100) {
            wp_send_json_error(array('message' => 'Weight must be between 0 and 100'));
        }

        $data = array(
            'variant_name' => $variant_name,
            'prompt_content' => $prompt_content,
            'weight' => $weight,
        );

        if ($variant_id > 0) {
            // Update existing variant
            $result = $wpdb->update(
                $table_name,
                $data,
                array('id' => $variant_id),
                array('%s', '%s', '%d'),
                array('%d')
            );

            if ($result === false) {
                wp_send_json_error(array('message' => 'Failed to update variant'));
            }

            wp_send_json_success(array(
                'message' => 'Variant updated successfully',
                'variant_id' => $variant_id
            ));
        } else {
            // Create new variant
            $data['created_by'] = get_current_user_id();

            $result = $wpdb->insert(
                $table_name,
                $data,
                array('%s', '%s', '%d', '%d')
            );

            if ($result === false) {
                wp_send_json_error(array('message' => 'Failed to create variant'));
            }

            wp_send_json_success(array(
                'message' => 'Variant created successfully',
                'variant_id' => $wpdb->insert_id
            ));
        }
    }

    /**
     * AJAX: Get variant data for editing
     *
     * @since 6.9.0
     */
    public function ajax_get_variant() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;

        if ($variant_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid variant ID'));
        }

        $variant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $variant_id
        ), ARRAY_A);

        if (!$variant) {
            wp_send_json_error(array('message' => 'Variant not found'));
        }

        wp_send_json_success(array('variant' => $variant));
    }

    /**
     * AJAX: Delete prompt variant
     *
     * @since 6.9.0
     */
    public function ajax_delete_variant() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;

        if ($variant_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid variant ID'));
        }

        // Check if this is the control variant
        $variant = $wpdb->get_row($wpdb->prepare(
            "SELECT variant_name FROM {$table_name} WHERE id = %d",
            $variant_id
        ), ARRAY_A);

        if ($variant && $variant['variant_name'] === 'Control') {
            wp_send_json_error(array('message' => 'Cannot delete the Control variant'));
        }

        // Check if at least one other variant will remain active
        $active_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE is_active = 1 AND id != %d",
            $variant_id
        ));

        if ($active_count < 1) {
            wp_send_json_error(array('message' => 'Cannot delete the last active variant'));
        }

        $result = $wpdb->delete(
            $table_name,
            array('id' => $variant_id),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete variant'));
        }

        wp_send_json_success(array('message' => 'Variant deleted successfully'));
    }

    /**
     * AJAX: Toggle variant active status
     *
     * @since 6.9.0
     */
    public function ajax_toggle_variant() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
        $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

        if ($variant_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid variant ID'));
        }

        // If deactivating, check if at least one other variant will remain active
        if ($is_active == 0) {
            $active_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE is_active = 1 AND id != %d",
                $variant_id
            ));

            if ($active_count < 1) {
                wp_send_json_error(array('message' => 'Cannot deactivate the last active variant'));
            }
        }

        $result = $wpdb->update(
            $table_name,
            array('is_active' => $is_active),
            array('id' => $variant_id),
            array('%d'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to update variant status'));
        }

        wp_send_json_success(array(
            'message' => 'Variant status updated successfully',
            'is_active' => $is_active
        ));
    }

    /**
     * AJAX: Update variant weight
     *
     * @since 6.9.0
     */
    public function ajax_update_variant_weight() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
        $weight = isset($_POST['weight']) ? intval($_POST['weight']) : 50;

        if ($variant_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid variant ID'));
        }

        if ($weight < 0 || $weight > 100) {
            wp_send_json_error(array('message' => 'Weight must be between 0 and 100'));
        }

        $result = $wpdb->update(
            $table_name,
            array('weight' => $weight),
            array('id' => $variant_id),
            array('%d'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to update variant weight'));
        }

        wp_send_json_success(array(
            'message' => 'Variant weight updated successfully',
            'weight' => $weight
        ));
    }

    /**
     * AJAX: Save routing configuration
     *
     * @since 6.11.1
     */
    public function ajax_save_routing_config() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $routing_enabled = isset($_POST['routing_enabled']) ? (bool) $_POST['routing_enabled'] : true;
        $cost_optimization = isset($_POST['cost_optimization']) ? (bool) $_POST['cost_optimization'] : true;
        $fallback_enabled = isset($_POST['fallback_enabled']) ? (bool) $_POST['fallback_enabled'] : true;

        // Build routing configuration
        $routing_config = array(
            'enabled' => $routing_enabled,
            'cost_optimization' => $cost_optimization,
            'fallback_enabled' => $fallback_enabled,
            'query_routing' => array(
                'simple' => array(
                    'preferred' => array('openai:gpt-4o-mini', 'gemini:gemini-1.5-flash', 'claude:claude-3-5-haiku'),
                    'reason' => 'Fast and cheap for simple queries',
                ),
                'search' => array(
                    'preferred' => array('openai:gpt-4o', 'openai:gpt-4o-mini', 'claude:claude-3-5-sonnet'),
                    'reason' => 'Best function calling support',
                ),
                'analysis' => array(
                    'preferred' => array('claude:claude-3-5-sonnet', 'openai:gpt-4o', 'gemini:gemini-1.5-pro'),
                    'reason' => 'Strong reasoning capabilities',
                ),
                'general' => array(
                    'preferred' => array('openai:gpt-4o-mini', 'gemini:gemini-1.5-flash', 'claude:claude-3-5-haiku'),
                    'reason' => 'Balanced cost and capability',
                ),
            ),
        );

        // Save to database
        $result = self::update_setting('model_routing_config', wp_json_encode($routing_config), 'routing', 'json');

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Routing configuration saved successfully',
                'config' => $routing_config
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save routing configuration'));
        }
    }

    /**
     * AJAX: Refresh AI models from APIs
     *
     * Clears the cached model lists and fetches fresh data from provider APIs
     *
     * @since 6.11.1
     */
    public function ajax_refresh_models() {
        check_ajax_referer('mld_chatbot_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'all';

        $chatbot_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'includes/chatbot/providers/';
        $refreshed = array();

        // Clear caches based on provider
        if ($provider === 'all' || $provider === 'openai') {
            delete_transient('mld_openai_models');
            $refreshed[] = 'OpenAI';
        }

        if ($provider === 'all' || $provider === 'claude') {
            delete_transient('mld_claude_models');
            $refreshed[] = 'Claude';
        }

        if ($provider === 'all' || $provider === 'gemini') {
            delete_transient('mld_gemini_models');
            $refreshed[] = 'Gemini';
        }

        wp_send_json_success(array(
            'message' => 'Model cache cleared for: ' . implode(', ', $refreshed) . '. Refresh the page to see updated models.',
            'refreshed' => $refreshed
        ));
    }
}

// Initialize settings page
new MLD_Chatbot_Settings();
