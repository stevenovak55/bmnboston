<?php
/**
 * MLS Listings Display - Business Settings Admin Page
 *
 * Admin interface for configuring LocalBusiness schema settings
 *
 * @package MLS_Listings_Display
 * @since 6.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Business_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',  // Fixed: Use correct parent menu slug
            'Business Information',
            'Business Info',
            'manage_options',
            'mld-business-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register all business settings
        register_setting('mld_business_settings', 'mld_business_schema_enabled');
        register_setting('mld_business_settings', 'mld_business_type');
        register_setting('mld_business_settings', 'mld_business_name');
        register_setting('mld_business_settings', 'mld_agent_name');
        register_setting('mld_business_settings', 'mld_business_phone');
        register_setting('mld_business_settings', 'mld_business_email');
        register_setting('mld_business_settings', 'mld_business_street_address');
        register_setting('mld_business_settings', 'mld_business_city');
        register_setting('mld_business_settings', 'mld_business_state');
        register_setting('mld_business_settings', 'mld_business_zip');
        register_setting('mld_business_settings', 'mld_business_hours');
        register_setting('mld_business_settings', 'mld_business_logo');
        register_setting('mld_business_settings', 'mld_business_image');
        register_setting('mld_business_settings', 'mld_business_description');
        register_setting('mld_business_settings', 'mld_business_service_area');
        register_setting('mld_business_settings', 'mld_business_facebook');
        register_setting('mld_business_settings', 'mld_business_twitter');
        register_setting('mld_business_settings', 'mld_business_instagram');
        register_setting('mld_business_settings', 'mld_business_linkedin');
        register_setting('mld_business_settings', 'mld_business_youtube');
        register_setting('mld_business_settings', 'mld_business_price_range');
        register_setting('mld_business_settings', 'mld_business_established');
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Business Information Settings</h1>
            <p>Configure your business information for enhanced local SEO with LocalBusiness schema markup.</p>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('mld_business_settings'); ?>

                <!-- Enable/Disable Section -->
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_schema_enabled">Enable Business Schema</label>
                        </th>
                        <td>
                            <input type="checkbox"
                                   id="mld_business_schema_enabled"
                                   name="mld_business_schema_enabled"
                                   value="1"
                                   <?php checked(get_option('mld_business_schema_enabled'), 1); ?>>
                            <p class="description">Enable LocalBusiness structured data on your website for better local SEO.</p>
                        </td>
                    </tr>
                </table>

                <h2>Basic Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_type">Business Type</label>
                        </th>
                        <td>
                            <select id="mld_business_type" name="mld_business_type">
                                <option value="RealEstateAgent" <?php selected(get_option('mld_business_type', 'RealEstateAgent'), 'RealEstateAgent'); ?>>Real Estate Agent</option>
                                <option value="RealEstateAgency" <?php selected(get_option('mld_business_type'), 'RealEstateAgency'); ?>>Real Estate Agency</option>
                            </select>
                            <p class="description">Select whether you are an individual agent or an agency.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_name">Business Name</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_name"
                                   name="mld_business_name"
                                   value="<?php echo esc_attr(get_option('mld_business_name', get_bloginfo('name'))); ?>"
                                   class="regular-text">
                            <p class="description">Your business or agency name (e.g., "Jane Smith Realty")</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_agent_name">Agent Name</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_agent_name"
                                   name="mld_agent_name"
                                   value="<?php echo esc_attr(get_option('mld_agent_name')); ?>"
                                   class="regular-text">
                            <p class="description">Your name as the primary agent (e.g., "Jane Smith")</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_description">Business Description</label>
                        </th>
                        <td>
                            <textarea id="mld_business_description"
                                      name="mld_business_description"
                                      rows="3"
                                      class="large-text"><?php echo esc_textarea(get_option('mld_business_description')); ?></textarea>
                            <p class="description">Brief description of your real estate services (1-2 sentences)</p>
                        </td>
                    </tr>
                </table>

                <h2>Contact Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_phone">Phone Number</label>
                        </th>
                        <td>
                            <input type="tel"
                                   id="mld_business_phone"
                                   name="mld_business_phone"
                                   value="<?php echo esc_attr(get_option('mld_business_phone')); ?>"
                                   class="regular-text">
                            <p class="description">Primary business phone number (e.g., "+1-781-555-0100")</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_email">Email Address</label>
                        </th>
                        <td>
                            <input type="email"
                                   id="mld_business_email"
                                   name="mld_business_email"
                                   value="<?php echo esc_attr(get_option('mld_business_email', get_option('admin_email'))); ?>"
                                   class="regular-text">
                            <p class="description">Business email address</p>
                        </td>
                    </tr>
                </table>

                <h2>Business Address</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_street_address">Street Address</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_street_address"
                                   name="mld_business_street_address"
                                   value="<?php echo esc_attr(get_option('mld_business_street_address')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_city">City</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_city"
                                   name="mld_business_city"
                                   value="<?php echo esc_attr(get_option('mld_business_city')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_state">State</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_state"
                                   name="mld_business_state"
                                   value="<?php echo esc_attr(get_option('mld_business_state')); ?>"
                                   class="regular-text"
                                   placeholder="MA">
                            <p class="description">Two-letter state code (e.g., MA, CA, NY)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_zip">ZIP Code</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_zip"
                                   name="mld_business_zip"
                                   value="<?php echo esc_attr(get_option('mld_business_zip')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                </table>

                <h2>Service Area & Hours</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_service_area">Service Area</label>
                        </th>
                        <td>
                            <textarea id="mld_business_service_area"
                                      name="mld_business_service_area"
                                      rows="2"
                                      class="large-text"><?php echo esc_textarea(get_option('mld_business_service_area')); ?></textarea>
                            <p class="description">Cities you serve, separated by commas (e.g., "Reading, Wakefield, Stoneham, Melrose")</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_hours">Business Hours</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mld_business_hours"
                                   name="mld_business_hours"
                                   value="<?php echo esc_attr(get_option('mld_business_hours')); ?>"
                                   class="large-text"
                                   placeholder="Mo-Fr 09:00-17:00">
                            <p class="description">Format: Mo-Fr 09:00-17:00 or Mo,Tu,We,Th,Fr 09:00-17:00</p>
                        </td>
                    </tr>
                </table>

                <h2>Images</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_logo">Logo URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_logo"
                                   name="mld_business_logo"
                                   value="<?php echo esc_attr(get_option('mld_business_logo')); ?>"
                                   class="large-text">
                            <p class="description">Full URL to your business logo (recommended: 400x400px)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_image">Business Image URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_image"
                                   name="mld_business_image"
                                   value="<?php echo esc_attr(get_option('mld_business_image')); ?>"
                                   class="large-text">
                            <p class="description">Photo of your office or yourself (recommended: 1200x800px)</p>
                        </td>
                    </tr>
                </table>

                <h2>Social Media</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_facebook">Facebook URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_facebook"
                                   name="mld_business_facebook"
                                   value="<?php echo esc_attr(get_option('mld_business_facebook')); ?>"
                                   class="large-text"
                                   placeholder="https://facebook.com/yourpage">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_twitter">Twitter URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_twitter"
                                   name="mld_business_twitter"
                                   value="<?php echo esc_attr(get_option('mld_business_twitter')); ?>"
                                   class="large-text"
                                   placeholder="https://twitter.com/yourhandle">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_instagram">Instagram URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_instagram"
                                   name="mld_business_instagram"
                                   value="<?php echo esc_attr(get_option('mld_business_instagram')); ?>"
                                   class="large-text"
                                   placeholder="https://instagram.com/yourhandle">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_linkedin">LinkedIn URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_linkedin"
                                   name="mld_business_linkedin"
                                   value="<?php echo esc_attr(get_option('mld_business_linkedin')); ?>"
                                   class="large-text"
                                   placeholder="https://linkedin.com/in/yourprofile">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_youtube">YouTube URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="mld_business_youtube"
                                   name="mld_business_youtube"
                                   value="<?php echo esc_attr(get_option('mld_business_youtube')); ?>"
                                   class="large-text"
                                   placeholder="https://youtube.com/c/yourchannel">
                        </td>
                    </tr>
                </table>

                <h2>Additional Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mld_business_price_range">Price Range</label>
                        </th>
                        <td>
                            <select id="mld_business_price_range" name="mld_business_price_range">
                                <option value="">Select...</option>
                                <option value="$" <?php selected(get_option('mld_business_price_range'), '$'); ?>>$ (Budget-friendly)</option>
                                <option value="$$" <?php selected(get_option('mld_business_price_range'), '$$'); ?>>$$ (Moderate)</option>
                                <option value="$$$" <?php selected(get_option('mld_business_price_range'), '$$$'); ?>>$$$ (Expensive)</option>
                                <option value="$$$$" <?php selected(get_option('mld_business_price_range'), '$$$$'); ?>>$$$$ (Very Expensive)</option>
                            </select>
                            <p class="description">Relative price range for your services</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mld_business_established">Year Established</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="mld_business_established"
                                   name="mld_business_established"
                                   value="<?php echo esc_attr(get_option('mld_business_established')); ?>"
                                   min="1900"
                                   max="<?php echo date('Y'); ?>"
                                   placeholder="<?php echo date('Y'); ?>">
                            <p class="description">Year your business was founded</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Business Information'); ?>
            </form>

            <div style="margin-top: 30px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                <h3>Why Business Schema Matters for SEO</h3>
                <p><strong>LocalBusiness schema helps Google understand:</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>Your business name, location, and contact information</li>
                    <li>The areas you serve (cities/regions)</li>
                    <li>Your operating hours and services</li>
                    <li>Your social media presence</li>
                </ul>
                <p><strong>Benefits:</strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>Improved local search rankings</li>
                    <li>Enhanced Google My Business integration</li>
                    <li>Rich snippets in search results (phone, address, hours)</li>
                    <li>Better visibility in "near me" searches</li>
                </ul>
                <p><strong>Test your schema:</strong> <a href="https://search.google.com/test/rich-results" target="_blank">Google Rich Results Test</a></p>
            </div>
        </div>
        <?php
    }
}
