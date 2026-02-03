<?php
/**
 * MLD CMA Admin Page
 *
 * Admin interface for testing and using CMA features
 *
 * @package MLS_Listings_Display
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'CMA Tools',
            'CMA Tools',
            'manage_options',
            'mld-cma-tools',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        // Check if we're on the CMA Tools page
        // Hook can be 'mls-listings-display_page_mld-cma-tools' or 'admin_page_mld-cma-tools'
        if (strpos($hook, 'mld-cma-tools') === false) {
            return;
        }

        wp_enqueue_script(
            'mld-cma-admin',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/cma-admin.js',
            array('jquery'),
            '5.2.0',
            true
        );

        wp_localize_script('mld-cma-admin', 'mldCmaAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_cma_nonce')
        ));

        wp_enqueue_style(
            'mld-cma-admin',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/cma-admin.css',
            array(),
            '5.2.0'
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get a sample property
        global $wpdb;
        $sample_listing = $wpdb->get_row(
            "SELECT l.listing_id, l.list_price, loc.city, loc.state_or_province,
                    loc.street_number, loc.street_name, loc.street_dir_suffix
             FROM {$wpdb->prefix}bme_listings l
             LEFT JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
             WHERE l.standard_status = 'Active'
             LIMIT 1"
        );

        ?>
        <div class="wrap">
            <h1>🏠 CMA Intelligence System</h1>

            <div class="mld-cma-dashboard">

                <!-- Market Data Calculator Section -->
                <div class="mld-cma-card">
                    <h2>📊 Market Data Calculator</h2>
                    <p>View real-time market-driven adjustment values calculated from your MLS data.</p>

                    <form id="mld-market-calculator-form">
                        <table class="form-table">
                            <tr>
                                <th>City</th>
                                <td><input type="text" name="city" class="regular-text" value="<?php echo esc_attr($sample_listing ? $sample_listing->city : ''); ?>" required></td>
                            </tr>
                            <tr>
                                <th>State</th>
                                <td><input type="text" name="state" class="regular-text" value="<?php echo esc_attr($sample_listing ? $sample_listing->state_or_province : ''); ?>" required></td>
                            </tr>
                            <tr>
                                <th>Property Type</th>
                                <td>
                                    <select name="property_type">
                                        <option value="all">All Types</option>
                                        <option value="Residential">Residential</option>
                                        <option value="Commercial Lease">Commercial Lease</option>
                                        <option value="Residential Lease">Residential Lease</option>
                                        <option value="Residential Income">Residential Income</option>
                                        <option value="Land">Land</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Months</th>
                                <td><input type="number" name="months" value="12" min="1" max="36"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">Calculate Market Data</button>
                        </p>
                    </form>

                    <div id="market-calculator-results" class="mld-results-box" style="display:none;">
                        <h3>Market Adjustments</h3>
                        <div id="market-calculator-data"></div>
                    </div>
                </div>

                <!-- Adjustment Overrides Section -->
                <div class="mld-cma-card">
                    <h2>⚙️ Adjustment Overrides</h2>
                    <p>Customize default adjustment values. Leave blank to use auto-calculated market data.</p>
                    <p><small><strong>Note:</strong> These overrides apply site-wide until changed or reset.</small></p>

                    <?php
                    // Get current override settings
                    $overrides = array(
                        'price_per_sqft' => get_option('mld_cma_override_price_per_sqft', ''),
                        'garage_first' => get_option('mld_cma_override_garage_first', ''),
                        'garage_additional' => get_option('mld_cma_override_garage_additional', ''),
                        'pool' => get_option('mld_cma_override_pool', ''),
                        'bedroom' => get_option('mld_cma_override_bedroom', ''),
                        'bathroom' => get_option('mld_cma_override_bathroom', ''),
                        'waterfront' => get_option('mld_cma_override_waterfront', ''),
                        'year_built_rate' => get_option('mld_cma_override_year_built_rate', ''),
                        'location_rate' => get_option('mld_cma_override_location_rate', ''),
                        'road_type_discount' => get_option('mld_cma_road_type_discount', '25'),
                    );
                    ?>

                    <form id="mld-adjustment-overrides-form">
                        <table class="form-table">
                            <tr>
                                <th colspan="2"><strong>Property Features</strong></th>
                            </tr>
                            <tr>
                                <th>Price per Sqft</th>
                                <td>
                                    $<input type="number" name="price_per_sqft" step="0.01" min="0" max="2000"
                                           value="<?php echo esc_attr($overrides['price_per_sqft']); ?>"
                                           placeholder="Auto-calculated" class="small-text">
                                    <span class="description">Leave blank for market calculation</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Garage (1st space)</th>
                                <td>
                                    $<input type="number" name="garage_first" step="1000" min="0" max="500000"
                                           value="<?php echo esc_attr($overrides['garage_first']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Garage (additional)</th>
                                <td>
                                    $<input type="number" name="garage_additional" step="1000" min="0" max="250000"
                                           value="<?php echo esc_attr($overrides['garage_additional']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Pool</th>
                                <td>
                                    $<input type="number" name="pool" step="1000" min="0" max="200000"
                                           value="<?php echo esc_attr($overrides['pool']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Bedroom (per room)</th>
                                <td>
                                    $<input type="number" name="bedroom" step="1000" min="0" max="200000"
                                           value="<?php echo esc_attr($overrides['bedroom']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Bathroom (per full)</th>
                                <td>
                                    $<input type="number" name="bathroom" step="1000" min="0" max="100000"
                                           value="<?php echo esc_attr($overrides['bathroom']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Waterfront</th>
                                <td>
                                    $<input type="number" name="waterfront" step="5000" min="0" max="1000000"
                                           value="<?php echo esc_attr($overrides['waterfront']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Year Built (per year)</th>
                                <td>
                                    $<input type="number" name="year_built_rate" step="100" min="0" max="50000"
                                           value="<?php echo esc_attr($overrides['year_built_rate']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Location Adjustment</th>
                                <td>
                                    $<input type="number" name="location_rate" step="1000" min="0" max="100000"
                                           value="<?php echo esc_attr($overrides['location_rate']); ?>"
                                           placeholder="Auto-calculated" class="regular-text">
                                </td>
                            </tr>

                            <tr>
                                <th colspan="2"><strong>Road Type Adjustment</strong></th>
                            </tr>
                            <tr>
                                <th>Main Road Discount</th>
                                <td>
                                    <input type="number" name="road_type_discount" step="1" min="0" max="100"
                                           value="<?php echo esc_attr($overrides['road_type_discount']); ?>"
                                           class="small-text">%
                                    <span class="description">Discount for properties on main/busy roads (default: 25%)</span>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">Save Overrides</button>
                            <button type="button" id="reset-overrides-btn" class="button">Reset to Auto-Calculated</button>
                        </p>
                    </form>

                    <div id="overrides-save-message" class="mld-results-box" style="display:none;"></div>
                </div>

                <!-- Test Email Section -->
                <div class="mld-cma-card">
                    <h2>📧 Test CMA Email</h2>
                    <p>Send a test CMA report email to yourself.</p>

                    <form id="mld-test-email-form">
                        <table class="form-table">
                            <tr>
                                <th>Your Email</th>
                                <td><input type="email" name="email" class="regular-text" value="<?php echo esc_attr(get_option('admin_email')); ?>" required></td>
                            </tr>
                            <?php if ($sample_listing): ?>
                            <tr>
                                <th>Test Property</th>
                                <td>
                                    <code><?php echo esc_html($sample_listing->street_number . ' ' . $sample_listing->street_name . ($sample_listing->street_dir_suffix ? ' ' . $sample_listing->street_dir_suffix : '') . ', ' . $sample_listing->city . ', ' . $sample_listing->state_or_province); ?></code>
                                    <input type="hidden" name="listing_id" value="<?php echo esc_attr($sample_listing->listing_id); ?>">
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">Send Test Email</button>
                        </p>
                    </form>

                    <div id="test-email-results" class="mld-results-box" style="display:none;"></div>
                </div>

                <!-- CMA Settings Section -->
                <div class="mld-cma-card">
                    <h2>⚙️ CMA Settings</h2>
                    <p>Current configuration settings.</p>

                    <?php
                    $settings = $wpdb->get_results(
                        "SELECT setting_key, setting_value, setting_type
                         FROM {$wpdb->prefix}mld_cma_settings
                         WHERE city IS NULL
                         ORDER BY setting_key"
                    );
                    ?>

                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Setting</th>
                                <th>Value</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings as $setting): ?>
                            <tr>
                                <td><code><?php echo esc_html($setting->setting_key); ?></code></td>
                                <td><?php echo esc_html($setting->setting_value); ?></td>
                                <td><em><?php echo esc_html($setting->setting_type); ?></em></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Statistics Section -->
                <div class="mld-cma-card">
                    <h2>📈 CMA Statistics</h2>

                    <?php
                    $email_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_cma_emails");
                    $report_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_cma_reports");
                    $listing_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings WHERE standard_status = 'Active'");
                    ?>

                    <table class="widefat">
                        <tbody>
                            <tr>
                                <th>Active Listings</th>
                                <td><?php echo number_format($listing_count); ?></td>
                            </tr>
                            <tr>
                                <th>CMA Emails Sent</th>
                                <td><?php echo number_format($email_count); ?></td>
                            </tr>
                            <tr>
                                <th>Reports Generated</th>
                                <td><?php echo number_format($report_count); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <style>
        .mld-cma-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .mld-cma-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
        }
        .mld-cma-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .mld-results-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            margin-top: 15px;
            border-radius: 4px;
        }
        .mld-results-box.success {
            background: #d4edda;
            border-color: #c3e6cb;
        }
        .mld-results-box.error {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        .mld-adjustment-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        .mld-adjustment-item:last-child {
            border-bottom: none;
        }
        .mld-adjustment-label {
            font-weight: 600;
        }
        .mld-adjustment-value {
            color: #0073aa;
            font-family: monospace;
        }
        </style>
        <?php
    }
}

// Don't auto-initialize - let main plugin file handle it
