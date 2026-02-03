<?php
/**
 * Blog Agent Admin
 *
 * WordPress admin dashboard interface for the Blog Agent.
 * Provides UI for topic research, article generation, and management.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Agent_Admin
 *
 * Admin dashboard for Blog Agent.
 */
class MLD_Blog_Agent_Admin {

    /**
     * Menu slug
     */
    const MENU_SLUG = 'mld-blog-agent';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php', // Parent: Posts menu
            'Blog Writing Agent',
            'Blog Agent',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Blog Agent Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG . '-settings',
            array($this, 'render_settings')
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_scripts($hook) {
        // Only load on our pages
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'mld-blog-agent-admin',
            MLD_BLOG_AGENT_URL . 'assets/css/blog-agent-admin.css',
            array(),
            MLD_BLOG_AGENT_VERSION
        );

        // Scripts
        wp_enqueue_script(
            'mld-blog-agent-admin',
            MLD_BLOG_AGENT_URL . 'assets/js/blog-agent-admin.js',
            array('jquery', 'wp-util'),
            MLD_BLOG_AGENT_VERSION,
            true
        );

        // Localize script
        wp_localize_script('mld-blog-agent-admin', 'mldBlogAgent', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_blog_agent_nonce'),
            'rest_url' => rest_url('mld-blog-agent/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'strings' => array(
                'researching' => 'Researching topics...',
                'generating' => 'Generating article...',
                'publishing' => 'Publishing...',
                'error' => 'An error occurred. Please try again.',
                'confirm_publish' => 'Are you sure you want to publish this article?',
            ),
        ));
    }

    /**
     * Render main dashboard
     */
    public function render_dashboard() {
        // Get current tab
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'create';

        ?>
        <div class="wrap mld-blog-agent-wrap">
            <h1>
                <span class="dashicons dashicons-edit-page"></span>
                Blog Writing Agent
            </h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo self::MENU_SLUG; ?>&tab=create"
                   class="nav-tab <?php echo $tab === 'create' ? 'nav-tab-active' : ''; ?>">
                    Create Article
                </a>
                <a href="?page=<?php echo self::MENU_SLUG; ?>&tab=articles"
                   class="nav-tab <?php echo $tab === 'articles' ? 'nav-tab-active' : ''; ?>">
                    Articles
                </a>
                <a href="?page=<?php echo self::MENU_SLUG; ?>&tab=topics"
                   class="nav-tab <?php echo $tab === 'topics' ? 'nav-tab-active' : ''; ?>">
                    Topics
                </a>
                <a href="?page=<?php echo self::MENU_SLUG; ?>&tab=performance"
                   class="nav-tab <?php echo $tab === 'performance' ? 'nav-tab-active' : ''; ?>">
                    Performance
                </a>
                <a href="?page=<?php echo self::MENU_SLUG; ?>&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
            </nav>

            <div class="mld-blog-agent-content">
                <?php
                switch ($tab) {
                    case 'articles':
                        $this->render_articles_tab();
                        break;
                    case 'topics':
                        $this->render_topics_tab();
                        break;
                    case 'performance':
                        $this->render_performance_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    default:
                        $this->render_create_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render create article tab
     */
    private function render_create_tab() {
        ?>
        <div class="mld-blog-agent-create">
            <!-- Step 1: Topic Selection -->
            <div class="mld-step mld-step-1" data-step="1">
                <h2>Step 1: Choose a Topic</h2>
                <p>Research trending real estate topics or enter your own.</p>

                <div class="mld-topic-actions">
                    <button type="button" class="button button-primary" id="mld-research-topics">
                        <span class="dashicons dashicons-search"></span>
                        Research Trending Topics
                    </button>

                    <span class="mld-or">or</span>

                    <button type="button" class="button" id="mld-custom-topic">
                        <span class="dashicons dashicons-edit"></span>
                        Enter Custom Topic
                    </button>
                </div>

                <!-- Topic Cards Container -->
                <div id="mld-topics-container" class="mld-topics-container" style="display: none;">
                    <div class="mld-topics-loading">
                        <span class="spinner is-active"></span>
                        <p>Researching trending topics...</p>
                    </div>
                    <div class="mld-topics-grid"></div>
                </div>

                <!-- Custom Topic Form -->
                <div id="mld-custom-topic-form" class="mld-custom-topic-form" style="display: none;">
                    <div class="mld-form-field">
                        <label for="mld-topic-title">Topic Title</label>
                        <input type="text" id="mld-topic-title" placeholder="e.g., Best Neighborhoods for Families in Boston">
                    </div>
                    <div class="mld-form-field">
                        <label for="mld-topic-description">Description</label>
                        <textarea id="mld-topic-description" rows="3"
                                  placeholder="Brief description of what the article should cover..."></textarea>
                    </div>
                    <div class="mld-form-field">
                        <label for="mld-topic-keywords">Keywords (comma-separated)</label>
                        <input type="text" id="mld-topic-keywords" placeholder="family homes, schools, parks">
                    </div>
                    <div class="mld-form-field">
                        <label for="mld-topic-cities">Related Cities (comma-separated)</label>
                        <input type="text" id="mld-topic-cities" placeholder="Boston, Cambridge, Newton">
                    </div>
                    <button type="button" class="button button-primary" id="mld-use-custom-topic">
                        Use This Topic
                    </button>
                </div>
            </div>

            <!-- Step 2: Configure Generation -->
            <div class="mld-step mld-step-2" data-step="2" style="display: none;">
                <h2>Step 2: Configure Article</h2>

                <div class="mld-selected-topic">
                    <h3>Selected Topic:</h3>
                    <div id="mld-selected-topic-display"></div>
                    <button type="button" class="button button-link" id="mld-change-topic">
                        ← Change Topic
                    </button>
                </div>

                <div class="mld-generation-options">
                    <div class="mld-form-row">
                        <div class="mld-form-field">
                            <label for="mld-target-length">Target Length</label>
                            <select id="mld-target-length">
                                <option value="1500">Short (~1,500 words)</option>
                                <option value="2000" selected>Medium (~2,000 words)</option>
                                <option value="2500">Long (~2,500 words)</option>
                            </select>
                        </div>

                        <div class="mld-form-field">
                            <label for="mld-cta-type">Call to Action</label>
                            <select id="mld-cta-type">
                                <option value="auto">Auto-detect</option>
                                <option value="contact">Contact Agent</option>
                                <option value="search">Search Properties</option>
                                <option value="book">Book Showing</option>
                                <option value="schools">School Search</option>
                                <option value="download">Download App</option>
                            </select>
                        </div>
                    </div>

                    <div class="mld-form-row">
                        <div class="mld-form-field">
                            <label>
                                <input type="checkbox" id="mld-include-market-data" checked>
                                Include local market data
                            </label>
                        </div>
                        <div class="mld-form-field">
                            <label>
                                <input type="checkbox" id="mld-include-school-data" checked>
                                Include school information
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="button button-primary button-hero" id="mld-generate-article">
                    <span class="dashicons dashicons-welcome-write-blog"></span>
                    Generate Article
                </button>

                <div id="mld-generation-progress" class="mld-progress" style="display: none;">
                    <div class="mld-progress-bar">
                        <div class="mld-progress-fill"></div>
                    </div>
                    <p class="mld-progress-text">Generating article structure...</p>
                </div>
            </div>

            <!-- Step 3: Preview & Edit -->
            <div class="mld-step mld-step-3" data-step="3" style="display: none;">
                <h2>Step 3: Review & Publish</h2>

                <div class="mld-article-preview">
                    <div class="mld-preview-header">
                        <div class="mld-scores">
                            <div class="mld-score mld-seo-score">
                                <span class="mld-score-label">SEO Score</span>
                                <span class="mld-score-value" id="mld-seo-score">--</span>
                            </div>
                            <div class="mld-score mld-geo-score">
                                <span class="mld-score-label">GEO Score</span>
                                <span class="mld-score-value" id="mld-geo-score">--</span>
                            </div>
                            <div class="mld-score mld-word-count">
                                <span class="mld-score-label">Words</span>
                                <span class="mld-score-value" id="mld-word-count">--</span>
                            </div>
                        </div>

                        <div class="mld-preview-actions">
                            <button type="button" class="button" id="mld-regenerate-article">
                                <span class="dashicons dashicons-update"></span>
                                Regenerate
                            </button>
                            <button type="button" class="button" id="mld-view-seo-issues">
                                <span class="dashicons dashicons-visibility"></span>
                                SEO Issues
                            </button>
                        </div>
                    </div>

                    <div class="mld-preview-content">
                        <div class="mld-title-preview">
                            <label>Title</label>
                            <input type="text" id="mld-preview-title">
                            <span class="mld-char-count"><span id="mld-title-chars">0</span>/60</span>
                        </div>

                        <div class="mld-meta-preview">
                            <label>Meta Description</label>
                            <textarea id="mld-preview-meta" rows="2"></textarea>
                            <span class="mld-char-count"><span id="mld-meta-chars">0</span>/155</span>
                        </div>

                        <div class="mld-content-preview">
                            <label>Content Preview</label>
                            <div id="mld-preview-content-area"></div>
                        </div>
                    </div>

                    <div class="mld-preview-footer">
                        <div class="mld-preview-meta">
                            <span>Category:</span>
                            <select id="mld-post-category">
                                <?php
                                $categories = get_categories(array('hide_empty' => false));
                                foreach ($categories as $cat) {
                                    $selected = ($cat->slug === 'real-estate') ? 'selected' : '';
                                    echo '<option value="' . esc_attr($cat->term_id) . '" ' . $selected . '>';
                                    echo esc_html($cat->name);
                                    echo '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mld-publish-actions">
                            <button type="button" class="button button-secondary" id="mld-save-draft">
                                Save as Draft
                            </button>
                            <button type="button" class="button button-primary" id="mld-publish-now">
                                Publish Now
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SEO Issues Modal -->
                <div id="mld-seo-modal" class="mld-modal" style="display: none;">
                    <div class="mld-modal-content">
                        <div class="mld-modal-header">
                            <h3>SEO Analysis</h3>
                            <button type="button" class="mld-modal-close">&times;</button>
                        </div>
                        <div class="mld-modal-body" id="mld-seo-issues-list"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render articles list tab
     */
    private function render_articles_tab() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_articles';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.post_title, p.post_status
             FROM $table a
             LEFT JOIN {$wpdb->posts} p ON a.wp_post_id = p.ID
             ORDER BY a.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        ?>
        <div class="mld-articles-list">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>SEO</th>
                        <th>GEO</th>
                        <th>Words</th>
                        <th>Status</th>
                        <th>Rating</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($articles)) : ?>
                        <tr>
                            <td colspan="8">No articles generated yet.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($articles as $article) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($article['title']); ?></strong>
                                </td>
                                <td>
                                    <span class="mld-score-badge mld-score-<?php echo $this->get_score_class($article['seo_score']); ?>">
                                        <?php echo intval($article['seo_score']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="mld-score-badge mld-score-<?php echo $this->get_score_class($article['geo_score']); ?>">
                                        <?php echo intval($article['geo_score']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($article['word_count']); ?></td>
                                <td>
                                    <span class="mld-status-badge mld-status-<?php echo esc_attr($article['status']); ?>">
                                        <?php echo esc_html(ucfirst($article['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($article['user_rating']) : ?>
                                        <?php echo str_repeat('★', $article['user_rating']); ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('M j, Y', strtotime($article['created_at']))); ?></td>
                                <td>
                                    <?php if ($article['wp_post_id']) : ?>
                                        <a href="<?php echo get_edit_post_link($article['wp_post_id']); ?>" class="button button-small">
                                            Edit
                                        </a>
                                        <a href="<?php echo get_preview_post_link($article['wp_post_id']); ?>" class="button button-small" target="_blank">
                                            Preview
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $total_pages,
                ));
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render topics tab
     */
    private function render_topics_tab() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_topics';
        $topics = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY total_score DESC LIMIT 50",
            ARRAY_A
        );

        ?>
        <div class="mld-topics-list">
            <div class="mld-topics-header">
                <h3>Researched Topics</h3>
                <button type="button" class="button" id="mld-refresh-topics">
                    <span class="dashicons dashicons-update"></span>
                    Research New Topics
                </button>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Topic</th>
                        <th>Score</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Researched</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topics)) : ?>
                        <tr>
                            <td colspan="6">No topics researched yet. Click "Research New Topics" to start.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($topics as $topic) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($topic['title']); ?></strong>
                                    <?php if ($topic['description']) : ?>
                                        <p class="description"><?php echo esc_html(wp_trim_words($topic['description'], 20)); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mld-score-badge mld-score-<?php echo $this->get_score_class($topic['total_score']); ?>">
                                        <?php echo intval($topic['total_score']); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($topic['source']); ?></td>
                                <td>
                                    <span class="mld-status-badge mld-status-<?php echo esc_attr($topic['status']); ?>">
                                        <?php echo esc_html(ucfirst($topic['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(human_time_diff(strtotime($topic['created_at']))); ?> ago</td>
                                <td>
                                    <?php if ($topic['status'] === 'pending') : ?>
                                        <button type="button" class="button button-small mld-use-topic"
                                                data-topic-id="<?php echo esc_attr($topic['id']); ?>">
                                            Use Topic
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render performance tab
     */
    private function render_performance_tab() {
        $init = MLD_Blog_Agent_Init::get_instance();
        $learner = $init->get_component('feedback_learner');

        $stats = $learner ? $learner->get_performance_stats(array()) : array();
        $recommendations = $learner ? $learner->get_prompt_recommendations() : array();

        ?>
        <div class="mld-performance">
            <div class="mld-stats-grid">
                <div class="mld-stat-card">
                    <span class="mld-stat-value"><?php echo intval($stats['total_articles'] ?? 0); ?></span>
                    <span class="mld-stat-label">Total Articles</span>
                </div>
                <div class="mld-stat-card">
                    <span class="mld-stat-value"><?php echo intval($stats['published_count'] ?? 0); ?></span>
                    <span class="mld-stat-label">Published</span>
                </div>
                <div class="mld-stat-card">
                    <span class="mld-stat-value"><?php echo round($stats['avg_seo_score'] ?? 0); ?></span>
                    <span class="mld-stat-label">Avg SEO Score</span>
                </div>
                <div class="mld-stat-card">
                    <span class="mld-stat-value"><?php echo round($stats['avg_edit_distance'] ?? 0); ?>%</span>
                    <span class="mld-stat-label">Avg Edit Distance</span>
                </div>
            </div>

            <div class="mld-recommendations">
                <h3>Prompt Performance</h3>

                <?php if (empty($recommendations)) : ?>
                    <p>Not enough data yet. Generate more articles to see performance insights.</p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Prompt</th>
                                <th>Version</th>
                                <th>Uses</th>
                                <th>Success Rate</th>
                                <th>Avg SEO</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recommendations as $rec) : ?>
                                <tr>
                                    <td><?php echo esc_html($rec['prompt_key']); ?></td>
                                    <td><?php echo esc_html($rec['version']); ?></td>
                                    <td><?php echo intval($rec['total_uses']); ?></td>
                                    <td><?php echo round($rec['success_rate']); ?>%</td>
                                    <td><?php echo round($rec['avg_seo_score']); ?></td>
                                    <td>
                                        <span class="mld-status-badge mld-status-<?php echo esc_attr($rec['status']); ?>">
                                            <?php echo esc_html(ucfirst(str_replace('_', ' ', $rec['status']))); ?>
                                        </span>
                                        <?php if ($rec['message']) : ?>
                                            <span class="mld-recommendation-tip"><?php echo esc_html($rec['message']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab (within dashboard)
     */
    private function render_settings_tab() {
        // Handle form submission
        if (isset($_POST['mld_blog_agent_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'mld_blog_agent_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        $settings = $this->get_settings();

        ?>
        <div class="mld-settings-tab">
            <form method="post" action="">
                <?php wp_nonce_field('mld_blog_agent_settings'); ?>
                <input type="hidden" name="mld_blog_agent_settings" value="1">

                <h3>Image API Keys</h3>
                <p class="description">Configure API keys to enable stock image fetching for articles.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="unsplash_api_key">Unsplash API Key</label>
                        </th>
                        <td>
                            <input type="text" id="unsplash_api_key" name="unsplash_api_key"
                                   value="<?php echo esc_attr($settings['unsplash_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                Get your API key from <a href="https://unsplash.com/developers" target="_blank">Unsplash Developers</a> (Free tier: 50 requests/hour)
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="pexels_api_key">Pexels API Key</label>
                        </th>
                        <td>
                            <input type="text" id="pexels_api_key" name="pexels_api_key"
                                   value="<?php echo esc_attr($settings['pexels_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                Get your API key from <a href="https://www.pexels.com/api/" target="_blank">Pexels API</a> (Free tier: 200 requests/hour)
                            </p>
                        </td>
                    </tr>
                </table>

                <h3>Article Defaults</h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="default_category">Default Category</label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_categories(array(
                                'name' => 'default_category',
                                'id' => 'default_category',
                                'selected' => $settings['default_category'],
                                'hide_empty' => false,
                                'show_option_none' => '— Select —',
                            ));
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_author">Default Author</label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_users(array(
                                'name' => 'default_author',
                                'id' => 'default_author',
                                'selected' => $settings['default_author'],
                                'who' => 'authors',
                            ));
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Enable A/B Testing</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ab_testing_enabled" value="1"
                                    <?php checked($settings['ab_testing_enabled']); ?>>
                                Enable prompt A/B testing
                            </label>
                            <p class="description">
                                When enabled, the system will test different prompt versions and automatically optimize based on performance.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render settings page (standalone - legacy)
     */
    public function render_settings() {
        // Handle form submission
        if (isset($_POST['mld_blog_agent_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'mld_blog_agent_settings')) {
            $this->save_settings();
        }

        $settings = $this->get_settings();

        ?>
        <div class="wrap">
            <h1>Blog Agent Settings</h1>

            <form method="post" action="">
                <?php wp_nonce_field('mld_blog_agent_settings'); ?>
                <input type="hidden" name="mld_blog_agent_settings" value="1">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="unsplash_api_key">Unsplash API Key</label>
                        </th>
                        <td>
                            <input type="text" id="unsplash_api_key" name="unsplash_api_key"
                                   value="<?php echo esc_attr($settings['unsplash_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                Get your API key from <a href="https://unsplash.com/developers" target="_blank">Unsplash Developers</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="pexels_api_key">Pexels API Key</label>
                        </th>
                        <td>
                            <input type="text" id="pexels_api_key" name="pexels_api_key"
                                   value="<?php echo esc_attr($settings['pexels_api_key']); ?>"
                                   class="regular-text">
                            <p class="description">
                                Get your API key from <a href="https://www.pexels.com/api/" target="_blank">Pexels API</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_category">Default Category</label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_categories(array(
                                'name' => 'default_category',
                                'id' => 'default_category',
                                'selected' => $settings['default_category'],
                                'hide_empty' => false,
                                'show_option_none' => '— Select —',
                            ));
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_author">Default Author</label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_users(array(
                                'name' => 'default_author',
                                'id' => 'default_author',
                                'selected' => $settings['default_author'],
                                'who' => 'authors',
                            ));
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Enable A/B Testing</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ab_testing_enabled" value="1"
                                    <?php checked($settings['ab_testing_enabled']); ?>>
                                Enable prompt A/B testing
                            </label>
                            <p class="description">
                                When enabled, the system will test different prompt versions and automatically optimize based on performance.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get settings
     *
     * @return array Settings
     */
    private function get_settings() {
        return array(
            'unsplash_api_key' => get_option('mld_blog_unsplash_api_key', ''),
            'pexels_api_key' => get_option('mld_blog_pexels_api_key', ''),
            'default_category' => get_option('mld_blog_default_category', 0),
            'default_author' => get_option('mld_blog_default_author', get_current_user_id()),
            'ab_testing_enabled' => get_option('mld_blog_ab_testing_enabled', true),
        );
    }

    /**
     * Save settings
     */
    private function save_settings() {
        update_option('mld_blog_unsplash_api_key', sanitize_text_field($_POST['unsplash_api_key'] ?? ''));
        update_option('mld_blog_pexels_api_key', sanitize_text_field($_POST['pexels_api_key'] ?? ''));
        update_option('mld_blog_default_category', intval($_POST['default_category'] ?? 0));
        update_option('mld_blog_default_author', intval($_POST['default_author'] ?? get_current_user_id()));
        update_option('mld_blog_ab_testing_enabled', isset($_POST['ab_testing_enabled']));

        add_settings_error('mld_blog_agent', 'settings_saved', 'Settings saved.', 'success');
    }

    /**
     * Get score CSS class
     *
     * @param float $score Score value
     * @return string CSS class
     */
    private function get_score_class($score) {
        if ($score >= 80) {
            return 'good';
        } elseif ($score >= 60) {
            return 'ok';
        }
        return 'poor';
    }
}
