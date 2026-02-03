<?php
/**
 * MLD Admin Settings Page
 * Manages plugin settings and API keys
 *
 * @package MLS_Listings_Display
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Admin_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'mls_listings_display',
            'API Settings',
            'API Settings',
            'manage_options',
            'mld-api-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('mld_settings_group', MLD_Settings::OPTION_NAME);
        
        // API Keys Section
        add_settings_section(
            'mld_api_keys',
            'API Keys',
            array($this, 'render_api_section'),
            'mld-api-settings'
        );
        
        // Walk Score API Key
        add_settings_field(
            'walk_score_api_key',
            'Walk Score API Key',
            array($this, 'render_text_field'),
            'mld-api-settings',
            'mld_api_keys',
            array(
                'name' => 'walk_score_api_key',
                'description' => 'Get your API key from <a href="https://www.walkscore.com/professional/api.php" target="_blank">walkscore.com</a>'
            )
        );
        
        // Google Maps API Key
        add_settings_field(
            'google_maps_api_key',
            'Google Maps API Key',
            array($this, 'render_text_field'),
            'mld-api-settings',
            'mld_api_keys',
            array(
                'name' => 'google_maps_api_key',
                'description' => 'Enable Maps JavaScript API, Places API, and Geocoding API'
            )
        );
        
        // Mapbox API Key removed - Google Maps only for performance optimization
        
        // Great Schools API Key
        add_settings_field(
            'great_schools_api_key',
            'GreatSchools API Key',
            array($this, 'render_text_field'),
            'mld-api-settings',
            'mld_api_keys',
            array(
                'name' => 'great_schools_api_key',
                'description' => 'For school ratings. Get your key from <a href="https://www.greatschools.org/api/" target="_blank">greatschools.org</a>'
            )
        );
        
        // Features Section
        add_settings_section(
            'mld_features',
            'Features',
            array($this, 'render_features_section'),
            'mld-api-settings'
        );
        
        // Enable Walk Score
        add_settings_field(
            'enable_walk_score',
            'Enable Walk Score',
            array($this, 'render_checkbox_field'),
            'mld-api-settings',
            'mld_features',
            array(
                'name' => 'enable_walk_score',
                'description' => 'Show walkability, transit, and bike scores on property pages'
            )
        );
        
        // Enable School Ratings
        add_settings_field(
            'enable_school_ratings',
            'Enable School Ratings',
            array($this, 'render_checkbox_field'),
            'mld-api-settings',
            'mld_features',
            array(
                'name' => 'enable_school_ratings',
                'description' => 'Show school ratings and information on property pages'
            )
        );
        
        // Enable Climate Risk
        add_settings_field(
            'enable_climate_risk',
            'Enable Climate Risk Scores',
            array($this, 'render_checkbox_field'),
            'mld-api-settings',
            'mld_features',
            array(
                'name' => 'enable_climate_risk',
                'description' => 'Show flood, fire, and other climate risk information'
            )
        );
        
        // Enable V2 Templates
        add_settings_field(
            'use_v2_templates',
            'Use V2 Templates',
            array($this, 'render_checkbox_field'),
            'mld-api-settings',
            'mld_features',
            array(
                'name' => 'use_v2_templates',
                'description' => 'Enable the modern V2 property display templates'
            )
        );
        
        // Map Provider setting removed - Google Maps only for performance optimization

        // Page URLs Section
        add_settings_section(
            'mld_page_urls',
            'Page URLs',
            array($this, 'render_page_urls_section'),
            'mld-api-settings'
        );

        // Search Page URL
        add_settings_field(
            'search_page_url',
            'Property Search Page URL',
            array($this, 'render_text_field'),
            'mld-api-settings',
            'mld_page_urls',
            array(
                'name' => 'search_page_url',
                'description' => 'The URL of your property search page (e.g., /search/ or /property-search/)',
                'placeholder' => '/search/'
            )
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>MLS Listings Display - API Settings</h1>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('mld_settings_group');
                do_settings_sections('mld-api-settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2>API Status</h2>
            <div id="mld-api-status">
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Walk Score API</td>
                            <td class="mld-status" data-api="walkscore">
                                <span class="dashicons dashicons-clock"></span> Not tested
                            </td>
                            <td>
                                <button class="button mld-test-api" data-api="walkscore">Test Connection</button>
                            </td>
                        </tr>
                        <tr>
                            <td>Google Maps API</td>
                            <td class="mld-status" data-api="googlemaps">
                                <span class="dashicons dashicons-clock"></span> Not tested
                            </td>
                            <td>
                                <button class="button mld-test-api" data-api="googlemaps">Test Connection</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <hr>
            
            <h2>Useful Links</h2>
            <ul>
                <li><a href="https://www.walkscore.com/professional/api.php" target="_blank">Get Walk Score API Key</a></li>
                <li><a href="https://console.cloud.google.com/apis" target="_blank">Google Cloud Console</a></li>
                <li><a href="https://www.greatschools.org/api/" target="_blank">GreatSchools API</a></li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Render API section description
     */
    public function render_api_section() {
        echo '<p>Enter your API keys to enable advanced features like walkability scores, school ratings, and maps.</p>';
    }
    
    /**
     * Render features section description
     */
    public function render_features_section() {
        echo '<p>Enable or disable specific features for your property listings.</p>';
    }

    /**
     * Render page URLs section description
     */
    public function render_page_urls_section() {
        echo '<p>Configure the URLs for various pages used by the plugin.</p>';
    }
    
    /**
     * Render text field
     */
    public function render_text_field($args) {
        $settings = MLD_Settings::get_all();
        $value = isset($settings[$args['name']]) ? $settings[$args['name']] : '';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        ?>
        <input type="text"
               name="<?php echo MLD_Settings::OPTION_NAME; ?>[<?php echo esc_attr($args['name']); ?>]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="<?php echo esc_attr($placeholder); ?>" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
        <?php endif;
    }
    
    /**
     * Render checkbox field
     */
    public function render_checkbox_field($args) {
        $settings = MLD_Settings::get_all();
        $checked = isset($settings[$args['name']]) ? $settings[$args['name']] : false;
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo MLD_Settings::OPTION_NAME; ?>[<?php echo esc_attr($args['name']); ?>]" 
                   value="1" 
                   <?php checked($checked, 1); ?> />
            <?php if (!empty($args['description'])): ?>
                <span class="description"><?php echo esc_html($args['description']); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }
    
    /**
     * Render select field
     */
    public function render_select_field($args) {
        $settings = MLD_Settings::get_all();
        $value = isset($settings[$args['name']]) ? $settings[$args['name']] : '';
        ?>
        <select name="<?php echo MLD_Settings::OPTION_NAME; ?>[<?php echo esc_attr($args['name']); ?>]">
            <?php foreach ($args['options'] as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'mls-display_page_mld-api-settings') {
            return;
        }
        
        wp_enqueue_script(
            'mld-admin-settings',
            MLD_PLUGIN_URL . 'admin/js/admin-settings.js',
            array('jquery'),
            MLD_VERSION,
            true
        );
        
        wp_localize_script('mld-admin-settings', 'mldAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_admin_nonce')
        ));
        
        wp_enqueue_style(
            'mld-admin-settings',
            MLD_PLUGIN_URL . 'admin/css/admin-settings.css',
            array(),
            MLD_VERSION
        );
    }
}