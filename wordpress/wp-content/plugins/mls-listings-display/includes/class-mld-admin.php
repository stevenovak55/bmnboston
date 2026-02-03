<?php
/**
 * Handles all admin-facing functionality.
 *
 * @package MLS_Listings_Display
 */
class MLD_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_mld_update_submission_status', [ $this, 'ajax_update_submission_status' ] );
        add_action( 'wp_ajax_mld_send_test_email', [ $this, 'ajax_send_test_email' ] );
        add_action( 'wp_ajax_mld_create_page', [ $this, 'ajax_create_page' ] );
        add_action( 'wp_ajax_mld_toggle_notifications', [ $this, 'ajax_toggle_notifications' ] );
        add_action( 'wp_ajax_mld_send_test_notification', [ $this, 'ajax_send_test_notification' ] );

        // Load notification test script
        if (file_exists(MLD_PLUGIN_PATH . 'includes/notifications/test-simple-notifications.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/notifications/test-simple-notifications.php';
        }
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu() {
        add_menu_page('MLS Display Settings', 'MLS Display', 'manage_options', 'mls_listings_display', [ $this, 'render_settings_page' ], 'dashicons-admin-home', 25);
        add_submenu_page('mls_listings_display', 'General Settings', 'General Settings', 'manage_options', 'mls_listings_display', [ $this, 'render_settings_page' ]);
        add_submenu_page('mls_listings_display', 'MLS Disclosures', 'MLS Disclosures', 'manage_options', 'mld_disclosures', [ $this, 'render_disclosures_page' ]);
        add_submenu_page('mls_listings_display', 'Icon & Label Manager', 'Icon & Label Manager', 'manage_options', 'mld_icon_manager', [ $this, 'render_icon_manager_page' ]);
        add_submenu_page('mls_listings_display', 'Agent Contacts', 'Agent Contacts', 'manage_options', 'mld_agent_contacts', [ $this, 'render_agent_contacts_page' ]);
        add_submenu_page('mls_listings_display', 'Notification Status', 'Notification Status', 'manage_options', 'mld_notification_status', [ $this, 'render_notification_status_page' ]);
        add_submenu_page('mls_listings_display', 'Form Submissions', 'Form Submissions', 'manage_options', 'mld_form_submissions', [ $this, 'render_form_submissions_page' ]);
        add_submenu_page('mls_listings_display', 'Contact Forms', 'Contact Forms', 'manage_options', 'mld_contact_forms', [ $this, 'render_contact_forms_page' ]);
        add_submenu_page('mls_listings_display', 'Cleanup Tool', 'Cleanup Tool', 'manage_options', 'mld_cleanup_tool', [ $this, 'render_cleanup_tool_page' ]);
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_assets( $hook_suffix ) {
        // Load assets on all plugin pages
        if ( strpos($hook_suffix, 'mls_listings_display') === false &&
            strpos($hook_suffix, 'mld_disclosures') === false &&
            strpos($hook_suffix, 'mld_icon_manager') === false &&
            strpos($hook_suffix, 'mld_agent_contacts') === false &&
            strpos($hook_suffix, 'mld_notification_status') === false &&
            strpos($hook_suffix, 'mld_form_submissions') === false &&
            strpos($hook_suffix, 'mld_contact_forms') === false &&
            strpos($hook_suffix, 'mld_cleanup_tool') === false) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'mld-admin-js', MLD_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], MLD_VERSION, true );

        // Create nonce for admin AJAX operations
        $admin_nonce = wp_create_nonce('mld_admin_nonce');

        wp_localize_script( 'mld-admin-js', 'mld_admin', [
            'nonce' => $admin_nonce,
            'ajax_url' => admin_url('admin-ajax.php'),
            'is_admin' => current_user_can('manage_options') ? 'yes' : 'no'
        ]);
        wp_enqueue_style( 'mld-admin-css', MLD_PLUGIN_URL . 'assets/css/admin.css', [], MLD_VERSION );

        // Load enhanced settings JS on main settings page
        if ( strpos($hook_suffix, 'mls_listings_display') !== false && !isset($_GET['page']) ||
            (isset($_GET['page']) && $_GET['page'] === 'mls_listings_display') ) {
            wp_enqueue_script( 'mld-settings-enhanced-js', MLD_PLUGIN_URL . 'assets/js/mld-settings-enhanced.js', [ 'jquery' ], MLD_VERSION, true );
            // The settings enhanced script will use the already localized mld_admin object from admin.js
        }

        // Load Contact Forms builder JS and CSS on contact forms page
        if (isset($_GET['page']) && $_GET['page'] === 'mld_contact_forms') {
            wp_enqueue_style('mld-contact-form-admin-css', MLD_PLUGIN_URL . 'assets/css/mld-contact-form-admin.css', [], MLD_VERSION);
            wp_enqueue_script('mld-contact-form-builder-js', MLD_PLUGIN_URL . 'assets/js/mld-contact-form-builder.js', ['jquery', 'jquery-ui-sortable'], MLD_VERSION, true);
            wp_localize_script('mld-contact-form-builder-js', 'mldContactFormBuilder', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mld_contact_form_admin'),
                'strings' => [
                    'confirmDelete' => __('Are you sure you want to delete this form?', 'mls-listings-display'),
                    'confirmDeleteField' => __('Are you sure you want to remove this field?', 'mls-listings-display'),
                    'saving' => __('Saving...', 'mls-listings-display'),
                    'saved' => __('Saved!', 'mls-listings-display'),
                    'error' => __('Error saving form. Please try again.', 'mls-listings-display'),
                    'copiedShortcode' => __('Shortcode copied to clipboard!', 'mls-listings-display'),
                ]
            ]);
        }
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        // Main settings (API & Map)
        register_setting( 'mld_options_group', 'mld_settings' );
        add_settings_section('mld_api_keys_section', 'API Keys & Settings', null, 'mld_options_group');
        add_settings_field( 'mld_logo_url', 'Display Logo', [ $this, 'render_logo_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        // Map provider and Mapbox settings removed - Google Maps only for performance optimization
        add_settings_field( 'mld_google_maps_api_key', 'Google Maps API Key', [ $this, 'render_google_maps_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_walk_score_api_key', 'Walk Score API Key', [ $this, 'render_walk_score_api_key_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_search_page_url', 'Property Search Page URL', [ $this, 'render_search_page_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_saved_searches_url', 'Saved Searches Dashboard URL', [ $this, 'render_saved_searches_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_login_url', 'Login Page URL', [ $this, 'render_login_url_field' ], 'mld_options_group', 'mld_api_keys_section' );
        add_settings_field( 'mld_register_url', 'Register/Sign Up Page URL', [ $this, 'render_register_url_field' ], 'mld_options_group', 'mld_api_keys_section' );

        // Icon Manager settings
        register_setting( 'mld_icon_manager_group', 'mld_subtype_customizations' );
        
        // Agent Contact settings
        register_setting( 'mld_agent_contacts_group', 'mld_contact_settings', [$this, 'sanitize_contact_settings']);
        add_settings_section('mld_agents_section', 'Site Agent Contact Information', [$this, 'render_agents_section_text'], 'mld_agent_contacts_group');
        for ($i = 1; $i <= 5; $i++) {
            add_settings_field( "mld_agent_{$i}", "Agent {$i}", [$this, 'render_agent_fields'], 'mld_agent_contacts_group', 'mld_agents_section', ['agent_index' => $i - 1] );
        }
        
        // Display Settings
        register_setting( 'mld_display_options_group', 'mld_display_settings' );
        add_settings_section( 'mld_display_section', 'Single Property Page Display', null, 'mld_display_options_group' );
        add_settings_field( 'mld_show_similar_listings', 'Show "Similar Listings"', [ $this, 'render_show_similar_listings_field' ], 'mld_display_options_group', 'mld_display_section' );
        add_settings_field( 'mld_similar_listings_count', 'Number of Similar Listings', [ $this, 'render_similar_listings_count_field' ], 'mld_display_options_group', 'mld_display_section' );
        add_settings_field( 'mld_show_recently_viewed', 'Show "Recently Viewed"', [ $this, 'render_show_recently_viewed_field' ], 'mld_display_options_group', 'mld_display_section' );
        add_settings_field( 'mld_recently_viewed_count', 'Number of Recently Viewed Listings', [ $this, 'render_recently_viewed_count_field' ], 'mld_display_options_group', 'mld_display_section' );
        
        // Simple Notification Settings
        register_setting( 'mld_simple_notification_group', 'mld_simple_notification_settings' );
        add_settings_section( 'mld_simple_notification_section', 'Property Alert Email Settings', null, 'mld_simple_notification_group' );
        add_settings_field( 'mld_notifications_enabled', 'Enable Property Alerts', [ $this, 'render_notifications_enabled_field' ], 'mld_simple_notification_group', 'mld_simple_notification_section' );
        add_settings_field( 'mld_notification_from_email', 'From Email Address', [ $this, 'render_notification_from_email_field' ], 'mld_simple_notification_group', 'mld_simple_notification_section' );
        add_settings_field( 'mld_notification_from_name', 'From Name', [ $this, 'render_notification_from_name_field' ], 'mld_simple_notification_group', 'mld_simple_notification_section' );
        add_settings_field( 'mld_notification_footer', 'Email Footer Text', [ $this, 'render_notification_footer_field' ], 'mld_simple_notification_group', 'mld_simple_notification_section' );

        // Social Media Links for Email Footer - Added v6.13.14
        add_settings_section( 'mld_social_media_section', 'Social Media Links (for Email Footer)', null, 'mld_simple_notification_group' );
        register_setting( 'mld_simple_notification_group', 'mld_social_facebook', 'esc_url_raw' );
        register_setting( 'mld_simple_notification_group', 'mld_social_twitter', 'esc_url_raw' );
        register_setting( 'mld_simple_notification_group', 'mld_social_instagram', 'esc_url_raw' );
        register_setting( 'mld_simple_notification_group', 'mld_social_linkedin', 'esc_url_raw' );
        add_settings_field( 'mld_social_facebook', 'Facebook URL', [ $this, 'render_social_facebook_field' ], 'mld_simple_notification_group', 'mld_social_media_section' );
        add_settings_field( 'mld_social_twitter', 'Twitter/X URL', [ $this, 'render_social_twitter_field' ], 'mld_simple_notification_group', 'mld_social_media_section' );
        add_settings_field( 'mld_social_instagram', 'Instagram URL', [ $this, 'render_social_instagram_field' ], 'mld_simple_notification_group', 'mld_social_media_section' );
        add_settings_field( 'mld_social_linkedin', 'LinkedIn URL', [ $this, 'render_social_linkedin_field' ], 'mld_simple_notification_group', 'mld_social_media_section' );
        
        // Contact Form Confirmation Settings
        register_setting( 'mld_contact_confirmation_group', 'mld_contact_confirmation_settings' );
        add_settings_section( 'mld_contact_confirmation_section', 'Contact Form Confirmation Email', null, 'mld_contact_confirmation_group' );
        add_settings_field( 'mld_contact_confirmation_enable', 'Enable Confirmation Emails', [ $this, 'render_contact_confirmation_enable_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_sender_email', 'Sender Email Address', [ $this, 'render_contact_confirmation_sender_email_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_sender_name', 'Sender Name', [ $this, 'render_contact_confirmation_sender_name_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_subject', 'Email Subject', [ $this, 'render_contact_confirmation_subject_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_enable_html', 'Enable HTML Email', [ $this, 'render_contact_confirmation_enable_html_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_header_image', 'Header Image', [ $this, 'render_contact_confirmation_header_image_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        add_settings_field( 'mld_contact_confirmation_message', 'Email Message', [ $this, 'render_contact_confirmation_message_field' ], 'mld_contact_confirmation_group', 'mld_contact_confirmation_section' );
        
        // Tour Request Confirmation Settings
        register_setting( 'mld_tour_confirmation_group', 'mld_tour_confirmation_settings' );
        add_settings_section( 'mld_tour_confirmation_section', 'Tour Request Confirmation Email', null, 'mld_tour_confirmation_group' );
        add_settings_field( 'mld_tour_confirmation_enable', 'Enable Confirmation Emails', [ $this, 'render_tour_confirmation_enable_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_sender_email', 'Sender Email Address', [ $this, 'render_tour_confirmation_sender_email_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_sender_name', 'Sender Name', [ $this, 'render_tour_confirmation_sender_name_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_subject', 'Email Subject', [ $this, 'render_tour_confirmation_subject_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_enable_html', 'Enable HTML Email', [ $this, 'render_tour_confirmation_enable_html_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_header_image', 'Header Image', [ $this, 'render_tour_confirmation_header_image_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        add_settings_field( 'mld_tour_confirmation_message', 'Email Message', [ $this, 'render_tour_confirmation_message_field' ], 'mld_tour_confirmation_group', 'mld_tour_confirmation_section' );
        
        // Calendar Notification Settings
        register_setting( 'mld_calendar_notification_group', 'mld_calendar_notification_settings' );
        add_settings_section( 'mld_calendar_notification_section', 'Open House Calendar Notifications', [ $this, 'render_calendar_notification_section' ], 'mld_calendar_notification_group' );
        add_settings_field( 'mld_calendar_enable_notifications', 'Enable Calendar Notifications', [ $this, 'render_calendar_enable_notifications_field' ], 'mld_calendar_notification_group', 'mld_calendar_notification_section' );
        add_settings_field( 'mld_calendar_notification_email', 'Notification Email Address', [ $this, 'render_calendar_notification_email_field' ], 'mld_calendar_notification_group', 'mld_calendar_notification_section' );

        // MLS Disclosure Settings
        register_setting( 'mld_disclosure_group', 'mld_disclosure_settings', [ $this, 'sanitize_disclosure_settings' ] );
    }

    /**
     * Render the main settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        include MLD_PLUGIN_PATH . 'admin/views/settings-page-enhanced.php';
    }

    /**
     * Render the icon manager page.
     */
    public function render_icon_manager_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        include MLD_PLUGIN_PATH . 'admin/views/icon-manager-page.php';
    }

    /**
     * Render the new agent contacts page.
     */
    public function render_agent_contacts_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Agent Contact Information</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'mld_agent_contacts_group' );
                do_settings_sections( 'mld_agent_contacts_group' );
                submit_button( 'Save Agent Contacts' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the notification status page.
     */
    public function render_notification_status_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        include MLD_PLUGIN_PATH . 'admin/views/notification-status.php';
    }
    
    /**
     * Render the form submissions page.
     */
    public function render_form_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_submission_' . $id)) {
                MLD_Form_Submissions::delete_submission($id);
                echo '<div class="notice notice-success"><p>Submission deleted successfully.</p></div>';
            }
        }
        
        // Handle bulk delete
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && !empty($_POST['submission_ids'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'bulk_delete_submissions')) {
                MLD_Form_Submissions::delete_submissions($_POST['submission_ids']);
                echo '<div class="notice notice-success"><p>Selected submissions deleted successfully.</p></div>';
            }
        }
        
        // Get filter parameters
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $form_type = isset($_GET['form_type']) ? sanitize_text_field($_GET['form_type']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // Get submissions
        $results = MLD_Form_Submissions::get_submissions([
            'page' => $page,
            'form_type' => $form_type,
            'status' => $status,
            'search' => $search,
            'date_from' => $date_from,
            'date_to' => $date_to
        ]);
        
        // Get statistics
        $stats = MLD_Form_Submissions::get_statistics();

        include MLD_PLUGIN_PATH . 'admin/views/form-submissions-page.php';
    }

    /**
     * Render the Contact Forms admin page.
     *
     * @since 6.21.0
     */
    public function render_contact_forms_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Determine which view to show
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;

        // Handle actions
        if ($action === 'delete' && $form_id > 0 && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_contact_form_' . $form_id)) {
                $manager = MLD_Contact_Form_Manager::get_instance();
                $manager->delete_form($form_id);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Contact form deleted successfully.', 'mls-listings-display') . '</p></div>';
                $action = 'list'; // Return to list after delete
            }
        }

        if ($action === 'duplicate' && $form_id > 0 && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'duplicate_contact_form_' . $form_id)) {
                $manager = MLD_Contact_Form_Manager::get_instance();
                $new_id = $manager->duplicate_form($form_id);
                if ($new_id) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Contact form duplicated successfully.', 'mls-listings-display') . ' <a href="' . esc_url(admin_url('admin.php?page=mld_contact_forms&action=edit&form_id=' . $new_id)) . '">' . esc_html__('Edit the new form', 'mls-listings-display') . '</a></p></div>';
                }
                $action = 'list';
            }
        }

        // Load appropriate view
        switch ($action) {
            case 'new':
            case 'edit':
                include MLD_PLUGIN_PATH . 'admin/views/contact-form-editor.php';
                break;

            case 'list':
            default:
                include MLD_PLUGIN_PATH . 'admin/views/contact-forms-list.php';
                break;
        }
    }

    public function render_agents_section_text() {
        echo '<p>Enter the contact information for the agents you want to display on the property details page sidebar. Leave fields blank for unused agent slots.</p>';
    }

    /**
     * Render the fields for a single agent.
     */
    public function render_agent_fields($args) {
        $options = get_option('mld_contact_settings');
        $index = $args['agent_index'];

        $name = isset($options[$index]['name']) ? $options[$index]['name'] : '';
        $email = isset($options[$index]['email']) ? $options[$index]['email'] : '';
        $phone = isset($options[$index]['phone']) ? $options[$index]['phone'] : '';
        $photo = isset($options[$index]['photo']) ? $options[$index]['photo'] : '';
        ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
            <p>
                <label for="agent_name_<?php echo $index; ?>">Name:</label><br>
                <input type="text" id="agent_name_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][name]" value="<?php echo esc_attr($name); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_email_<?php echo $index; ?>">Email:</label><br>
                <input type="email" id="agent_email_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][email]" value="<?php echo esc_attr($email); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_phone_<?php echo $index; ?>">Phone:</label><br>
                <input type="text" id="agent_phone_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][phone]" value="<?php echo esc_attr($phone); ?>" class="regular-text">
            </p>
            <p>
                <label for="agent_photo_<?php echo $index; ?>">Photo URL:</label><br>
                <input type="text" id="agent_photo_<?php echo $index; ?>" name="mld_contact_settings[<?php echo $index; ?>][photo]" value="<?php echo esc_url($photo); ?>" class="regular-text mld-icon-url-input">
                <button type="button" class="button mld-upload-button" data-target-input="#agent_photo_<?php echo $index; ?>" data-target-preview="#agent-photo-preview-<?php echo $index; ?>">Upload Photo</button>
                <div id="agent-photo-preview-<?php echo $index; ?>" class="mld-image-preview">
                    <?php if ($photo) echo '<img src="' . esc_url($photo) . '" />'; ?>
                </div>
            </p>
        </div>
        <?php
    }

    /**
     * Sanitize the agent contact settings.
     */
    public function sanitize_contact_settings($input) {
        $sanitized_input = [];
        if (is_array($input)) {
            foreach ($input as $index => $agent_data) {
                if (!empty($agent_data['name']) || !empty($agent_data['email'])) {
                    $sanitized_input[$index]['name'] = sanitize_text_field($agent_data['name']);
                    $sanitized_input[$index]['email'] = sanitize_email($agent_data['email']);
                    $sanitized_input[$index]['phone'] = sanitize_text_field($agent_data['phone']);
                    // Force HTTPS for photo URLs if on HTTPS site
                    $photo_url = esc_url_raw($agent_data['photo']);
                    if (!empty($photo_url) && is_ssl()) {
                        $photo_url = str_replace('http://', 'https://', $photo_url);
                    }
                    $sanitized_input[$index]['photo'] = $photo_url;
                }
            }
        }
        return $sanitized_input;
    }

    /**
     * Sanitize disclosure settings.
     */
    public function sanitize_disclosure_settings($input) {
        $sanitized = [];

        // Sanitize enabled checkbox
        $sanitized['enabled'] = !empty($input['enabled']) ? 1 : 0;

        // Sanitize logo URL
        $logo_url = isset($input['logo_url']) ? esc_url_raw($input['logo_url']) : '';
        if (!empty($logo_url) && is_ssl()) {
            $logo_url = str_replace('http://', 'https://', $logo_url);
        }
        $sanitized['logo_url'] = $logo_url;

        // Sanitize disclosure text (allow safe HTML)
        $sanitized['disclosure_text'] = isset($input['disclosure_text']) ? wp_kses_post($input['disclosure_text']) : '';

        return $sanitized;
    }

    /**
     * Render the MLS Disclosures settings page.
     */
    public function render_disclosures_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        include MLD_PLUGIN_PATH . 'admin/views/disclosure-settings-page.php';
    }

    // --- Field Render Callbacks for Main Settings (API & Map) ---
    public function render_logo_url_field() {
        $options = get_option( 'mld_settings' );
        $logo_url = isset( $options['mld_logo_url'] ) ? esc_url( $options['mld_logo_url'] ) : '';
        echo '<input type="text" name="mld_settings[mld_logo_url]" id="mld_logo_url" value="' . $logo_url . '" class="regular-text" />';
        echo '<button type="button" class="button mld-upload-button" data-target-input="#mld_logo_url" data-target-preview="#mld-logo-preview">Upload Logo</button>';
        echo '<p class="description">Upload or choose a logo to display next to the search bar.</p>';
        echo '<div id="mld-logo-preview" class="mld-image-preview">';
        if ( $logo_url ) echo '<img src="' . $logo_url . '" />';
        echo '</div>';
    }

    // Map provider and Mapbox render methods removed - Google Maps only for performance optimization

    public function render_google_maps_api_key_field() {
        $options = get_option( 'mld_settings' );
        $key = $options['mld_google_maps_api_key'] ?? '';
        echo "<input type='text' name='mld_settings[mld_google_maps_api_key]' value='" . esc_attr( $key ) . "' class='regular-text' />";
        echo "<p class='description'>Required if Map Provider is set to Google Maps.</p>";
    }
    
    public function render_walk_score_api_key_field() {
        $options = get_option( 'mld_settings' );
        $key = $options['mld_walk_score_api_key'] ?? '';
        echo "<input type='text' name='mld_settings[mld_walk_score_api_key]' value='" . esc_attr( $key ) . "' class='regular-text' placeholder='Enter your Walk Score API key' />";
        echo "<p class='description'>Get your API key from <a href='https://www.walkscore.com/professional/api.php' target='_blank'>walkscore.com</a>. This enables walkability, transit, and bike scores on property pages.</p>";
    }

    public function render_search_page_url_field() {
        $options = get_option( 'mld_settings' );
        $url = $options['search_page_url'] ?? '/search/';
        echo "<input type='text' name='mld_settings[search_page_url]' value='" . esc_attr( $url ) . "' class='regular-text' placeholder='/search/' />";
        echo "<p class='description'>Enter the URL of your property search page (e.g., /search/ or /property-search/). This will be used in links from the saved searches dashboard.</p>";
    }

    public function render_saved_searches_url_field() {
        $options = get_option( 'mld_settings' );
        $url = $options['saved_searches_url'] ?? '/saved-search/';
        echo "<input type='text' name='mld_settings[saved_searches_url]' value='" . esc_attr( $url ) . "' class='regular-text' placeholder='/saved-search/' />";
        echo "<p class='description'>Enter the URL of your saved searches dashboard page (e.g., /saved-search/ or /my-saved-searches/). This will be used in modal links and redirects.</p>";
    }

    public function render_login_url_field() {
        $options = get_option( 'mld_settings' );
        $url = $options['login_url'] ?? '/wp-login.php';
        echo "<input type='text' name='mld_settings[login_url]' value='" . esc_attr( $url ) . "' class='regular-text' placeholder='/wp-login.php' />";
        echo "<p class='description'>Enter the URL of your login page (e.g., /wp-login.php or /login/). This will be used when prompting users to log in.</p>";
    }

    public function render_register_url_field() {
        $options = get_option( 'mld_settings' );
        $url = $options['register_url'] ?? '/register/';
        echo "<input type='text' name='mld_settings[register_url]' value='" . esc_attr( $url ) . "' class='regular-text' placeholder='/register/' />";
        echo "<p class='description'>Enter the URL of your registration/sign up page (e.g., /register/ or /sign-up/). This will be used when prompting users to create an account.</p>";
    }

    // --- Field Render Callbacks for Display Settings ---
    public function render_show_similar_listings_field() {
        $options = get_option( 'mld_display_settings' );
        $value = isset( $options['show_similar_listings'] ) ? $options['show_similar_listings'] : 0;
        echo '<input type="checkbox" name="mld_display_settings[show_similar_listings]" value="1"' . checked( 1, $value, false ) . ' />';
        echo '<p class="description">Enable to display a "Similar Listings" section at the bottom of the single property page.</p>';
    }

    public function render_similar_listings_count_field() {
        $options = get_option( 'mld_display_settings' );
        $value = isset( $options['similar_listings_count'] ) ? $options['similar_listings_count'] : 4;
        echo '<input type="number" name="mld_display_settings[similar_listings_count]" value="' . esc_attr( $value ) . '" class="small-text" min="1" max="10" />';
        echo '<p class="description">How many similar listings to show (e.g., 3 or 4).</p>';
    }

    public function render_show_recently_viewed_field() {
        $options = get_option( 'mld_display_settings' );
        $value = isset( $options['show_recently_viewed'] ) ? $options['show_recently_viewed'] : 0;
        echo '<input type="checkbox" name="mld_display_settings[show_recently_viewed]" value="1"' . checked( 1, $value, false ) . ' />';
        echo '<p class="description">Enable to display a "Recently Viewed" section for users.</p>';
    }

    public function render_recently_viewed_count_field() {
        $options = get_option( 'mld_display_settings' );
        $value = isset( $options['recently_viewed_count'] ) ? $options['recently_viewed_count'] : 4;
        echo '<input type="number" name="mld_display_settings[recently_viewed_count]" value="' . esc_attr( $value ) . '" class="small-text" min="1" max="10" />';
        echo '<p class="description">How many recently viewed listings to show.</p>';
    }

    // --- Field Render Callbacks for Simple Notification Settings ---
    public function render_notifications_enabled_field() {
        $enabled = get_option( 'mld_notifications_enabled', true );
        echo '<input type="checkbox" name="mld_notifications_enabled" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Enable or disable property alert emails globally. When disabled, no notification emails will be sent.</p>';
    }

    public function render_notification_from_email_field() {
        $options = get_option( 'mld_simple_notification_settings' );
        $email = isset( $options['from_email'] ) ? $options['from_email'] : get_option('admin_email');
        echo '<input type="email" name="mld_simple_notification_settings[from_email]" value="' . esc_attr( $email ) . '" class="regular-text" />';
        echo '<p class="description">Email address that notification emails will be sent from.</p>';
    }

    public function render_notification_from_name_field() {
        $options = get_option( 'mld_simple_notification_settings' );
        $name = isset( $options['from_name'] ) ? $options['from_name'] : get_bloginfo('name');
        echo '<input type="text" name="mld_simple_notification_settings[from_name]" value="' . esc_attr( $name ) . '" class="regular-text" />';
        echo '<p class="description">Name that will appear as the sender of notification emails.</p>';
    }

    public function render_notification_footer_field() {
        $options = get_option( 'mld_simple_notification_settings' );
        $default_footer = "You are receiving this email because you have saved searches on " . get_bloginfo('name') . ". To manage your saved searches and email preferences, please visit your dashboard.";
        $footer = isset( $options['footer_text'] ) ? $options['footer_text'] : $default_footer;
        echo '<textarea name="mld_simple_notification_settings[footer_text]" rows="3" class="large-text">' . esc_textarea( $footer ) . '</textarea>';
        echo '<p class="description">Text that will appear at the bottom of notification emails.</p>';
    }

    // --- Social Media Links for Email Footer - Added v6.13.14 ---

    /**
     * Render Facebook URL field
     * @since 6.13.14
     */
    public function render_social_facebook_field() {
        $value = get_option('mld_social_facebook', '');
        echo '<input type="url" name="mld_social_facebook" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://facebook.com/yourpage" />';
        echo '<p class="description">Your Facebook page URL. Leave blank to hide from emails.</p>';
    }

    /**
     * Render Twitter/X URL field
     * @since 6.13.14
     */
    public function render_social_twitter_field() {
        $value = get_option('mld_social_twitter', '');
        echo '<input type="url" name="mld_social_twitter" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://x.com/yourhandle" />';
        echo '<p class="description">Your Twitter/X profile URL. Leave blank to hide from emails.</p>';
    }

    /**
     * Render Instagram URL field
     * @since 6.13.14
     */
    public function render_social_instagram_field() {
        $value = get_option('mld_social_instagram', '');
        echo '<input type="url" name="mld_social_instagram" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://instagram.com/yourprofile" />';
        echo '<p class="description">Your Instagram profile URL. Leave blank to hide from emails.</p>';
    }

    /**
     * Render LinkedIn URL field
     * @since 6.13.14
     */
    public function render_social_linkedin_field() {
        $value = get_option('mld_social_linkedin', '');
        echo '<input type="url" name="mld_social_linkedin" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://linkedin.com/in/yourprofile" />';
        echo '<p class="description">Your LinkedIn profile URL. Leave blank to hide from emails.</p>';
    }

    // --- Field Render Callbacks for Admin Notification Settings ---
    public function render_admin_notification_email_field() {
        $options = get_option( 'mld_admin_notification_settings' );
        $email = isset( $options['notification_email'] ) ? $options['notification_email'] : get_option('admin_email');
        echo '<input type="email" name="mld_admin_notification_settings[notification_email]" value="' . esc_attr( $email ) . '" class="regular-text" />';
        echo '<p class="description">Email address to receive form submission notifications. Leave blank to use the site admin email.</p>';
    }
    
    public function render_admin_email_subject_field() {
        $options = get_option( 'mld_admin_notification_settings' );
        $subject = isset( $options['email_subject'] ) ? $options['email_subject'] : 'New Property Inquiry - {property_address}';
        echo '<input type="text" name="mld_admin_notification_settings[email_subject]" value="' . esc_attr( $subject ) . '" class="regular-text" />';
        echo '<p class="description">Email subject template. Available placeholders: {property_address}, {property_mls}, {form_type}</p>';
    }
    
    public function render_admin_enable_notifications_field() {
        $options = get_option( 'mld_admin_notification_settings' );
        $enabled = isset( $options['enable_notifications'] ) ? $options['enable_notifications'] : 1;
        echo '<input type="checkbox" name="mld_admin_notification_settings[enable_notifications]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Enable email notifications to admin when forms are submitted.</p>';
    }
    
    /**
     * AJAX handler to update submission status
     */
    public function ajax_update_submission_status() {
        check_ajax_referer('update_submission_status');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        
        if (!$id || !in_array($status, ['new', 'read', 'contacted', 'converted'])) {
            wp_send_json_error('Invalid parameters');
        }
        
        $result = MLD_Form_Submissions::update_status($id, $status);
        
        if ($result) {
            wp_send_json_success('Status updated');
        } else {
            wp_send_json_error('Failed to update status');
        }
    }
    
    /**
     * AJAX handler to send test email
     */
    public function ajax_send_test_email() {
        check_ajax_referer('mld_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $to = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if (!$to) {
            wp_send_json_error('Please provide a valid email address');
        }
        
        // Prepare test email
        $subject = 'Test Email from ' . get_bloginfo('name');
        $message = "This is a test email from your MLS Listings Display plugin.\n\n";
        $message .= "If you're receiving this email, it means WordPress can successfully send emails from your site.\n\n";
        $message .= "Site: " . get_bloginfo('name') . "\n";
        $message .= "URL: " . home_url() . "\n";
        $message .= "Time: " . current_time('mysql') . "\n\n";
        $message .= "Your contact form submissions should be arriving at the configured email address.\n\n";
        $message .= "If you're not receiving form submissions, please check:\n";
        $message .= "1. Email notifications are enabled in settings\n";
        $message .= "2. The notification email address is correct\n";
        $message .= "3. Your spam folder\n";
        
        $headers = [
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        // Send test email
        $sent = wp_mail($to, $subject, $message, $headers);
        
        if ($sent) {
            wp_send_json_success('Test email sent successfully! Please check your inbox (and spam folder).');
        } else {
            wp_send_json_error('Failed to send test email. Your WordPress installation may not be configured to send emails. Consider installing an SMTP plugin.');
        }
    }
    
    // --- Field Render Callbacks for Contact Confirmation Email Settings ---
    public function render_contact_confirmation_enable_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $enabled = isset( $options['enable'] ) ? $options['enable'] : 1;
        echo '<input type="checkbox" name="mld_contact_confirmation_settings[enable]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Send automatic confirmation email to users when they submit a contact form.</p>';
    }
    
    public function render_contact_confirmation_sender_email_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $email = isset( $options['sender_email'] ) ? $options['sender_email'] : get_option('admin_email');
        echo '<input type="email" name="mld_contact_confirmation_settings[sender_email]" value="' . esc_attr( $email ) . '" class="regular-text" />';
        echo '<p class="description">The email address that confirmation emails will be sent from.</p>';
    }
    
    public function render_contact_confirmation_sender_name_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $name = isset( $options['sender_name'] ) ? $options['sender_name'] : get_bloginfo('name');
        echo '<input type="text" name="mld_contact_confirmation_settings[sender_name]" value="' . esc_attr( $name ) . '" class="regular-text" />';
        echo '<p class="description">The name that will appear as the sender of confirmation emails.</p>';
    }
    
    public function render_contact_confirmation_subject_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $subject = isset( $options['subject'] ) ? $options['subject'] : 'Thank you for your inquiry';
        echo '<input type="text" name="mld_contact_confirmation_settings[subject]" value="' . esc_attr( $subject ) . '" class="regular-text" />';
        echo '<p class="description">Subject line for confirmation emails. Available placeholders: {property_address}, {first_name}</p>';
    }
    
    public function render_contact_confirmation_enable_html_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $enabled = isset( $options['enable_html'] ) ? $options['enable_html'] : 1;
        echo '<input type="checkbox" name="mld_contact_confirmation_settings[enable_html]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Enable HTML formatting for confirmation emails (recommended for better appearance).</p>';
    }
    
    public function render_contact_confirmation_header_image_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $image_url = isset( $options['header_image'] ) ? $options['header_image'] : '';
        echo '<input type="text" id="mld_contact_confirmation_header_image" name="mld_contact_confirmation_settings[header_image]" value="' . esc_url( $image_url ) . '" class="regular-text mld-icon-url-input" />';
        echo '<button type="button" class="button mld-upload-button" data-target-input="#mld_contact_confirmation_header_image" data-target-preview="#mld-contact-header-image-preview">Upload Image</button>';
        echo '<p class="description">Header image for HTML emails (recommended size: 600px width).</p>';
        echo '<div id="mld-contact-header-image-preview" class="mld-image-preview">';
        if ( $image_url ) echo '<img src="' . esc_url( $image_url ) . '" style="max-width: 300px;" />';
        echo '</div>';
    }
    
    public function render_contact_confirmation_message_field() {
        $options = get_option( 'mld_contact_confirmation_settings' );
        $default_message = $this->get_default_contact_confirmation_message();
        $message = isset( $options['message'] ) ? $options['message'] : $default_message;
        
        echo '<textarea name="mld_contact_confirmation_settings[message]" rows="10" class="large-text code">' . esc_textarea( $message ) . '</textarea>';
        echo '<p class="description">The message body for confirmation emails. Available placeholders:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><code>{first_name}</code> - User\'s first name</li>';
        echo '<li><code>{last_name}</code> - User\'s last name</li>';
        echo '<li><code>{property_address}</code> - Property address (plain text)</li>';
        echo '<li><code>{property_address_linked}</code> - Property address as clickable link (HTML emails only)</li>';
        echo '<li><code>{property_mls}</code> - Property MLS number</li>';
        echo '<li><code>{property_url}</code> - Direct URL to property page</li>';
        echo '<li><code>{message}</code> - User\'s message (if provided)</li>';
        echo '<li><code>{site_name}</code> - Your website name</li>';
        echo '<li><code>{site_url}</code> - Your website URL</li>';
        echo '</ul>';
        echo '<p><button type="button" class="button" onclick="document.querySelector(\'[name=\\\'mld_contact_confirmation_settings[message]\\\']\').value = \'' . esc_js($default_message) . '\'">Reset to Default</button></p>';
    }
    
    // --- Field Render Callbacks for Tour Confirmation Email Settings ---
    public function render_tour_confirmation_enable_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $enabled = isset( $options['enable'] ) ? $options['enable'] : 1;
        echo '<input type="checkbox" name="mld_tour_confirmation_settings[enable]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Send automatic confirmation email to users when they submit a tour request.</p>';
    }
    
    public function render_tour_confirmation_sender_email_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $email = isset( $options['sender_email'] ) ? $options['sender_email'] : get_option('admin_email');
        echo '<input type="email" name="mld_tour_confirmation_settings[sender_email]" value="' . esc_attr( $email ) . '" class="regular-text" />';
        echo '<p class="description">The email address that confirmation emails will be sent from.</p>';
    }
    
    public function render_tour_confirmation_sender_name_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $name = isset( $options['sender_name'] ) ? $options['sender_name'] : get_bloginfo('name');
        echo '<input type="text" name="mld_tour_confirmation_settings[sender_name]" value="' . esc_attr( $name ) . '" class="regular-text" />';
        echo '<p class="description">The name that will appear as the sender of confirmation emails.</p>';
    }
    
    public function render_tour_confirmation_subject_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $subject = isset( $options['subject'] ) ? $options['subject'] : 'Tour Request Confirmation - {property_address}';
        echo '<input type="text" name="mld_tour_confirmation_settings[subject]" value="' . esc_attr( $subject ) . '" class="regular-text" />';
        echo '<p class="description">Subject line for tour confirmation emails. Available placeholders: {property_address}, {first_name}</p>';
    }
    
    public function render_tour_confirmation_enable_html_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $enabled = isset( $options['enable_html'] ) ? $options['enable_html'] : 1;
        echo '<input type="checkbox" name="mld_tour_confirmation_settings[enable_html]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Enable HTML formatting for confirmation emails (recommended for better appearance).</p>';
    }
    
    public function render_tour_confirmation_header_image_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $image_url = isset( $options['header_image'] ) ? $options['header_image'] : '';
        echo '<input type="text" id="mld_tour_confirmation_header_image" name="mld_tour_confirmation_settings[header_image]" value="' . esc_url( $image_url ) . '" class="regular-text mld-icon-url-input" />';
        echo '<button type="button" class="button mld-upload-button" data-target-input="#mld_tour_confirmation_header_image" data-target-preview="#mld-tour-header-image-preview">Upload Image</button>';
        echo '<p class="description">Header image for HTML emails (recommended size: 600px width).</p>';
        echo '<div id="mld-tour-header-image-preview" class="mld-image-preview">';
        if ( $image_url ) echo '<img src="' . esc_url( $image_url ) . '" style="max-width: 300px;" />';
        echo '</div>';
    }
    
    public function render_tour_confirmation_message_field() {
        $options = get_option( 'mld_tour_confirmation_settings' );
        $default_message = $this->get_default_tour_confirmation_message();
        $message = isset( $options['message'] ) ? $options['message'] : $default_message;
        
        echo '<textarea name="mld_tour_confirmation_settings[message]" rows="10" class="large-text code">' . esc_textarea( $message ) . '</textarea>';
        echo '<p class="description">The message body for tour confirmation emails. Available placeholders:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><code>{first_name}</code> - User\'s first name</li>';
        echo '<li><code>{last_name}</code> - User\'s last name</li>';
        echo '<li><code>{property_address}</code> - Property address (plain text)</li>';
        echo '<li><code>{property_address_linked}</code> - Property address as clickable link (HTML emails only)</li>';
        echo '<li><code>{property_mls}</code> - Property MLS number</li>';
        echo '<li><code>{property_url}</code> - Direct URL to property page</li>';
        echo '<li><code>{message}</code> - User\'s message (if provided)</li>';
        echo '<li><code>{site_name}</code> - Your website name</li>';
        echo '<li><code>{site_url}</code> - Your website URL</li>';
        echo '</ul>';
        echo '<p><strong>Tour Request Specific Placeholders:</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><code>{tour_type}</code> - Tour type (in_person, virtual, video_chat)</li>';
        echo '<li><code>{tour_type_formatted}</code> - Tour type formatted (In person, Virtual, Video chat)</li>';
        echo '<li><code>{preferred_date}</code> - Preferred date (YYYY-MM-DD format)</li>';
        echo '<li><code>{preferred_date_formatted}</code> - Preferred date formatted (e.g., January 15, 2025)</li>';
        echo '<li><code>{preferred_time}</code> - Preferred time</li>';
        echo '</ul>';
        echo '<p><button type="button" class="button" onclick="document.querySelector(\'[name=\\\'mld_tour_confirmation_settings[message]\\\']\').value = \'' . esc_js($default_message) . '\'">Reset to Default</button></p>';
    }
    
    private function get_default_contact_confirmation_message() {
        return 'Dear {first_name},

Thank you for your inquiry about {property_address_linked}. We have received your message and appreciate your interest.

One of our real estate professionals will review your inquiry and get back to you within 24 hours.

In the meantime, feel free to:
- Continue browsing our listings at {site_url}
- Save your favorite properties for easy access
- Contact us directly if you have urgent questions

Your inquiry details:
Property: {property_address_linked}
MLS #: {property_mls}

Best regards,
The {site_name} Team

---
This is an automated confirmation email. Please do not reply directly to this message.';
    }
    
    private function get_default_tour_confirmation_message() {
        return 'Dear {first_name},

Thank you for requesting a tour of {property_address_linked}. We have received your tour request and appreciate your interest.

Your tour preferences:
Tour Type: {tour_type_formatted}
Preferred Date: {preferred_date_formatted}
Preferred Time: {preferred_time}

One of our real estate professionals will contact you within 24 hours to confirm your tour appointment.

In the meantime, feel free to:
- View more details about this property: {property_address_linked}
- Browse similar properties at {site_url}
- Contact us directly if you have urgent questions

Best regards,
The {site_name} Team

---
This is an automated confirmation email. Please do not reply directly to this message.';
    }
    
    /**
     * Render calendar notification section description
     */
    public function render_calendar_notification_section() {
        echo '<p>Configure email notifications sent to administrators when users add open house events to their calendars.</p>';
    }
    
    /**
     * Render calendar enable notifications field
     */
    public function render_calendar_enable_notifications_field() {
        $options = get_option( 'mld_calendar_notification_settings' );
        $enabled = isset( $options['enable_notifications'] ) ? $options['enable_notifications'] : 0;
        echo '<input type="checkbox" name="mld_calendar_notification_settings[enable_notifications]" value="1"' . checked( 1, $enabled, false ) . ' />';
        echo '<p class="description">Enable email notifications when users add open house events to their calendars.</p>';
    }
    
    /**
     * Render calendar notification email field
     */
    public function render_calendar_notification_email_field() {
        $options = get_option( 'mld_calendar_notification_settings' );
        $email = isset( $options['notification_email'] ) ? $options['notification_email'] : get_option( 'admin_email' );
        echo '<input type="email" name="mld_calendar_notification_settings[notification_email]" value="' . esc_attr( $email ) . '" class="regular-text" />';
        echo '<p class="description">Email address to receive calendar event notifications. Leave blank to use the site admin email.</p>';
    }

    /**
     * AJAX handler to create a page with shortcode
     */
    public function ajax_create_page() {
        // Verify nonce for security
        check_ajax_referer('mld_admin_nonce', 'nonce');

        // Debug authentication
        $user = wp_get_current_user();
        $debug_info = [
            'user_id' => $user->ID,
            'username' => $user->user_login ?? 'not_logged_in',
            'is_logged_in' => is_user_logged_in(),
            'can_manage' => current_user_can('manage_options'),
            'roles' => $user->roles ?? [],
            'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX,
            'auth_cookie_set' => isset($_COOKIE[LOGGED_IN_COOKIE])
        ];

        // Check if user can manage options first
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'You must be logged in as an administrator to create pages.',
                'debug' => $debug_info
            ]);
            return;
        }

        // Simplified nonce check - check both possible nonce names
        $nonce = $_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';

        // Try multiple nonce actions in case of mismatch
        $valid = false;
        if ($nonce) {
            $valid = wp_verify_nonce($nonce, 'mld_admin_nonce') ||
                     wp_verify_nonce($nonce, 'wp_rest') ||
                     check_ajax_referer('mld_admin_nonce', 'nonce', false);
        }

        // For admin users, be more lenient with nonce checking if they're properly authenticated
        if (!$valid && current_user_can('manage_options')) {
            // Double-check they're really an admin
            $user = wp_get_current_user();
            if ($user && $user->ID > 0 && in_array('administrator', $user->roles)) {
                // Admin is authenticated, allow action with warning
                $valid = true;
            }
        }

        if (!$valid) {
            wp_send_json_error('Security verification failed. Please refresh the page and try again.');
            return;
        }

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
        $shortcode = isset($_POST['shortcode']) ? wp_unslash($_POST['shortcode']) : '';

        if (!$title || !$shortcode) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }

        // Check if page already exists by slug first (more reliable)
        if ($slug) {
            $existing_by_slug = get_page_by_path($slug);
            if ($existing_by_slug) {
                wp_send_json_error(['message' => 'A page with this URL slug already exists: /' . $slug . '/']);
            }
        }

        // Also check by title
        $query = new WP_Query([
            'post_type' => 'page',
            'title' => $title,
            'post_status' => 'all',
            'posts_per_page' => 1
        ]);

        if ($query->have_posts()) {
            wp_send_json_error(['message' => 'A page with this title already exists']);
        }

        // Create the page with the specified slug
        $page_id = wp_insert_post([
            'post_title'    => $title,
            'post_name'     => $slug, // Set the URL slug
            'post_content'  => $shortcode,
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => get_current_user_id(),
            'comment_status'=> 'closed'
        ]);

        if (is_wp_error($page_id) || !$page_id) {
            $error_message = is_wp_error($page_id) ? $page_id->get_error_message() : 'Unknown error occurred';
            wp_send_json_error('Failed to create page: ' . $error_message);
        }

        // Get the page URL
        $page_url = get_permalink($page_id);

        wp_send_json_success([
            'message' => 'Page created successfully!',
            'page_id' => $page_id,
            'page_url' => $page_url,
            'edit_url' => admin_url('post.php?post=' . $page_id . '&action=edit')
        ]);
    }

    /**
     * Render Cleanup Tool page
     */
    public function render_cleanup_tool_page() {
        include_once MLD_PLUGIN_PATH . 'admin/cleanup-tool-simplified.php';
    }

    /**
     * Render Cron Status page
     */
    public function render_cron_status_page() {
        // Handle manual cron execution
        if (isset($_POST['run_cron']) && isset($_POST['cron_hook'])) {
            // Verify nonce for security
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'run_cron_action')) {
                wp_die('Security check failed');
            }

            $hook = sanitize_text_field($_POST['cron_hook']);
            wp_schedule_single_event(time(), $hook);
            spawn_cron();
            echo '<div class="notice notice-success"><p>Cron job "' . esc_html($hook) . '" has been executed.</p></div>';
        }

        // Get cron status
        $cron_status = [];
        if (class_exists('MLD_Saved_Search_Cron')) {
            $cron_status = MLD_Saved_Search_Cron::get_cron_status();
        }

        // Get system status
        $system_status = [];
        if (class_exists('MLD_Notification_System_Init')) {
            $system_status = MLD_Notification_System_Init::get_system_status();
        }

        // Get notification analytics
        $analytics = [];
        if (class_exists('MLD_Notification_Analytics')) {
            $analytics_instance = MLD_Notification_Analytics::get_instance();
            $analytics = $analytics_instance->get_analytics_data('7 days');
        }

        ?>
        <div class="wrap">
            <h1>Notification System & Cron Status</h1>

            <!-- System Health -->
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">System Health</h2>
                <div class="inside">
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_status as $component => $status): ?>
                            <tr>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $component))); ?></td>
                                <td>
                                    <?php if (is_bool($status)): ?>
                                        <span class="<?php echo $status ? 'text-success' : 'text-error'; ?>">
                                            <?php echo $status ? '✅ Active' : '❌ Inactive'; ?>
                                        </span>
                                    <?php else: ?>
                                        <?php echo esc_html($status); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($component === 'cron_status'): ?>
                                        <?php echo $status ? 'Cron jobs scheduled' : 'No cron jobs found'; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cron Jobs Status -->
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">Cron Jobs Status</h2>
                <div class="inside">
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Frequency</th>
                                <th>Scheduled</th>
                                <th>Next Run</th>
                                <th>Last Run</th>
                                <th>Last Results</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cron_status as $frequency => $status): ?>
                            <tr>
                                <td><?php echo esc_html(ucfirst($frequency)); ?></td>
                                <td>
                                    <span class="<?php echo $status['scheduled'] ? 'text-success' : 'text-error'; ?>">
                                        <?php echo $status['scheduled'] ? '✅ Yes' : '❌ No'; ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($status['next_run'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($status['last_run'] ?? 'Never'); ?></td>
                                <td>
                                    <?php if (isset($status['last_sent'])): ?>
                                        Sent: <?php echo intval($status['last_sent']); ?> |
                                        Failed: <?php echo intval($status['last_failed'] ?? 0); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('run_cron_action'); ?>
                                        <input type="hidden" name="cron_hook" value="mld_saved_search_<?php echo esc_attr($frequency); ?>" />
                                        <input type="submit" name="run_cron" class="button-secondary" value="Run Now" />
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Analytics -->
            <?php if (!empty($analytics['stats'])): ?>
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">Notification Performance (Last 7 Days)</h2>
                <div class="inside">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
                        <div style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                            <h3>Total Sent</h3>
                            <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                                <?php echo number_format($analytics['stats']['total_sent']); ?>
                            </div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                            <h3>Open Rate</h3>
                            <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                                <?php echo esc_html($analytics['stats']['open_rate']); ?>%
                            </div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                            <h3>Click Rate</h3>
                            <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                                <?php echo esc_html($analytics['stats']['click_rate']); ?>%
                            </div>
                        </div>
                        <div style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                            <h3>Failure Rate</h3>
                            <div style="font-size: 24px; font-weight: bold; color: #d63638;">
                                <?php echo esc_html($analytics['stats']['failure_rate']); ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <style>
            .text-success { color: #00a32a; font-weight: bold; }
            .text-error { color: #d63638; font-weight: bold; }
            .postbox h2.hndle { padding: 12px; background: #f1f1f1; margin: 0; }
            .postbox .inside { padding: 15px; }
            </style>
        </div>
        <?php
    }

    /**
     * AJAX handler to toggle notification system on/off
     */
    public function ajax_toggle_notifications() {
        check_ajax_referer('mld_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

        // Update notification system setting
        update_option('mld_notifications_enabled', $enabled);

        wp_send_json_success([
            'enabled' => $enabled,
            'message' => $enabled ? 'Notifications enabled' : 'Notifications disabled'
        ]);
    }

    /**
     * AJAX handler to send test notification
     */
    public function ajax_send_test_notification() {
        check_ajax_referer('mld_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check if notifications are enabled
        if (!get_option('mld_notifications_enabled', true)) {
            wp_send_json_error('Notifications are currently disabled');
        }

        // Send a test notification - this would integrate with the simple notification system
        $result = $this->send_test_notification();

        if ($result) {
            wp_send_json_success('Test notification sent successfully');
        } else {
            wp_send_json_error('Failed to send test notification');
        }
    }

    /**
     * Send a test notification to verify the system is working
     */
    private function send_test_notification() {
        $current_user = wp_get_current_user();
        $to = $current_user->user_email;
        $subject = '[Test] MLS Listings Display - Notification System Test';

        $message = "This is a test notification from MLS Listings Display.\n\n";
        $message .= "If you received this email, your notification system is working correctly.\n\n";
        $message .= "System Information:\n";
        $message .= "- WordPress Version: " . get_bloginfo('version') . "\n";
        $message .= "- Plugin Version: " . MLD_VERSION . "\n";
        $message .= "- Site URL: " . get_site_url() . "\n";
        $message .= "- Current Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        $message .= "This test was sent to: " . $to;

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $from_email = get_option('mld_notifications_from_email', get_option('admin_email'));
        $from_name = get_option('mld_notifications_from_name', get_bloginfo('name'));

        if ($from_email && $from_name) {
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
        }

        $result = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Test Notification: Sent successfully to ' . $to);
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Test Notification: Failed to send to ' . $to);
                }
            }
        }

        return $result;
    }
}
