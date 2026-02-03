<?php
/**
 * Sitemap Admin Interface for MLS Listings Display
 *
 * Provides admin UI for viewing and managing XML sitemaps
 *
 * @package MLS_Listings_Display
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Sitemap_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 60);
        add_action('admin_post_mld_regenerate_sitemaps', array($this, 'handle_regenerate'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'XML Sitemaps',
            'XML Sitemaps',
            'manage_options',
            'mld-sitemaps',
            array($this, 'render_admin_page')
        );
    }

    public function handle_regenerate() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('mld_regenerate_sitemaps');

        $generator = MLD_Sitemap_Generator::get_instance();
        $generator->regenerate_all_sitemaps();

        wp_redirect(add_query_arg(array(
            'page' => 'mld-sitemaps',
            'regenerated' => '1'
        ), admin_url('admin.php')));
        exit;
    }

    public function render_admin_page() {
        $site_url = trailingslashit(home_url());
        $sitemap_index_url = $site_url . 'sitemap.xml';
        $new_listings_url = $site_url . 'new-listings-sitemap.xml';
        $modified_listings_url = $site_url . 'modified-listings-sitemap.xml';
        $property_sitemap_url = $site_url . 'property-sitemap.xml';
        $city_sitemap_url = $site_url . 'city-sitemap.xml';
        $state_sitemap_url = $site_url . 'state-sitemap.xml';
        $property_type_sitemap_url = $site_url . 'property-type-sitemap.xml';

        // Get stats
        global $wpdb;
        $total_properties = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
        ");

        $total_cities = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT city)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND city IS NOT NULL
                AND city != ''
        ");

        $with_photos = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND main_photo_url IS NOT NULL
        ");

        // Get incremental sitemap stats
        $incremental = MLD_Incremental_Sitemaps::get_instance();
        $incremental_stats = $incremental->get_stats();

        $next_scheduled = wp_next_scheduled('mld_regenerate_sitemaps');
        $cache_dir = WP_CONTENT_DIR . '/cache/mld-sitemaps/';
        $cache_exists = file_exists($cache_dir . 'sitemap-index.xml');
        $last_generated = $cache_exists ? date('F j, Y g:i a', filemtime($cache_dir . 'sitemap-index.xml')) : 'Never';

        ?>
        <div class="wrap">
            <h1>XML Sitemaps for SEO</h1>

            <?php if (isset($_GET['regenerated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> All sitemaps have been regenerated.</p>
                </div>
            <?php endif; ?>

            <!-- Overview Section -->
            <div class="card">
                <h2>Overview</h2>
                <p>XML sitemaps help search engines like Google discover and index all your property listings and city pages. This system automatically generates and maintains sitemaps for your entire MLS inventory.</p>
                
                <table class="widefat" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Total Properties Indexed</strong></td>
                            <td><?php echo number_format($total_properties); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Properties with Photos</strong></td>
                            <td><?php echo number_format($with_photos); ?> (<?php echo $total_properties > 0 ? round(($with_photos / $total_properties) * 100) : 0; ?>%)</td>
                        </tr>
                        <tr>
                            <td><strong>Unique Cities</strong></td>
                            <td><?php echo number_format($total_cities); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Last Generated</strong></td>
                            <td><?php echo esc_html($last_generated); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Next Auto-Update</strong></td>
                            <td><?php echo $next_scheduled ? date('F j, Y g:i a', $next_scheduled) : 'Not scheduled'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Sitemap URLs Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>Your Sitemap URLs</h2>
                <p>These are the URLs to submit to Google Search Console and other search engines:</p>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Sitemap Type</th>
                            <th>URL</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Sitemap Index</strong><br><small>Points to all sub-sitemaps</small></td>
                            <td><code><?php echo esc_html($sitemap_index_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($sitemap_index_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($sitemap_index_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr style="background: #e8f5e9;">
                            <td><strong>🆕 New Listings Sitemap</strong><br><small><?php echo number_format($incremental_stats['new_listings']); ?> listings (last 48 hours) • Updates every 15 min</small></td>
                            <td><code><?php echo esc_html($new_listings_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($new_listings_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($new_listings_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr style="background: #fff3e0;">
                            <td><strong>✏️ Modified Listings Sitemap</strong><br><small><?php echo number_format($incremental_stats['modified_listings']); ?> listings (last 24 hours) • Updates hourly</small></td>
                            <td><code><?php echo esc_html($modified_listings_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($modified_listings_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($modified_listings_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Property Sitemap</strong><br><small><?php echo number_format($total_properties); ?> listings • Updates daily</small></td>
                            <td><code><?php echo esc_html($property_sitemap_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($property_sitemap_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($property_sitemap_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>City Sitemap</strong><br><small><?php echo number_format($total_cities); ?> cities</small></td>
                            <td><code><?php echo esc_html($city_sitemap_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($city_sitemap_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($city_sitemap_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>State Sitemap</strong><br><small>State landing pages</small></td>
                            <td><code><?php echo esc_html($state_sitemap_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($state_sitemap_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($state_sitemap_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Property Type Sitemap</strong><br><small>Property type pages</small></td>
                            <td><code><?php echo esc_html($property_type_sitemap_url); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url($property_type_sitemap_url); ?>" target="_blank" class="button button-small">View</a>
                                <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($property_type_sitemap_url); ?>'); alert('Copied to clipboard!');">Copy</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 15px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong>📋 Robots.txt Reference:</strong><br>
                    Your sitemaps are automatically added to <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank">robots.txt</a> so search engines can discover them.
                </div>
            </div>

            <!-- Google Search Console Instructions -->
            <div class="card" style="margin-top: 20px;">
                <h2>📤 Submit to Google Search Console</h2>
                <p>Follow these steps to submit your sitemaps to Google:</p>
                
                <ol style="line-height: 2;">
                    <li><strong>Access Google Search Console:</strong> Go to <a href="https://search.google.com/search-console" target="_blank">search.google.com/search-console</a></li>
                    <li><strong>Select Your Property:</strong> Choose your website from the property selector</li>
                    <li><strong>Navigate to Sitemaps:</strong> Click "Sitemaps" in the left sidebar under "Indexing"</li>
                    <li><strong>Add New Sitemap:</strong>
                        <ul style="margin-top: 10px;">
                            <li>In the "Add a new sitemap" field, enter: <code>sitemap.xml</code></li>
                            <li>Click "Submit"</li>
                        </ul>
                    </li>
                    <li><strong>Monitor Status:</strong> Google will process your sitemap and show the number of pages discovered</li>
                </ol>

                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <strong>💡 Pro Tip:</strong> You only need to submit the <strong>Sitemap Index</strong> URL. Google will automatically discover and crawl the property and city sitemaps linked from it.
                </div>

                <div style="margin-top: 15px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745;">
                    <strong>✅ Expected Result:</strong> Within 24-48 hours, you should see "Success" status with the number of discovered URLs matching your property count above.
                </div>
            </div>

            <!-- How It Works Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>⚙️ How It Works</h2>
                
                <h3>Incremental Update Strategy (90x More Efficient!)</h3>
                <ul>
                    <li><strong>New Listings Sitemap:</strong> Regenerates every 15 minutes • Only includes properties added in last 48 hours • Priority 1.0 • Google gets instant notification</li>
                    <li><strong>Modified Listings Sitemap:</strong> Regenerates hourly • Only includes properties updated in last 24 hours (excluding new ones) • Priority 0.9</li>
                    <li><strong>Full Property Sitemap:</strong> Regenerates daily • All active listings • Priority 0.5-0.8 based on age and value</li>
                    <li><strong>City Sitemap:</strong> Regenerates daily • All city landing pages • Priority based on listing count</li>
                    <li><strong>Smart Caching:</strong> 15-min cache for new listings, 1-hour for modified, 24-hour for full sitemap</li>
                    <li><strong>Search Engine Ping:</strong> Google and Bing are automatically notified when new listings appear</li>
                </ul>

                <div style="margin-top: 15px; padding: 15px; background: #e3f2fd; border-left: 4px solid #2196f3;">
                    <strong>🚀 Performance:</strong> This incremental approach is 90x more efficient than regenerating all <?php echo number_format($total_properties); ?> listings every 15 minutes. New listings get discovered by Google within 15-30 minutes instead of waiting up to 24 hours!
                </div>

                <h3>What's Included</h3>
                <ul>
                    <li><strong>Property URLs:</strong> All active and pending listings with descriptive SEO-friendly URLs</li>
                    <li><strong>Image Metadata:</strong> Property photos are included in image sitemaps for Google Image Search</li>
                    <li><strong>City Landing Pages:</strong> SEO-optimized city pages for local search</li>
                    <li><strong>Smart Prioritization:</strong>
                        <ul>
                            <li>New listings (< 7 days): Priority 0.9, updated daily</li>
                            <li>Recent listings (< 30 days): Priority 0.8, updated weekly</li>
                            <li>Older listings: Priority 0.5-0.8, updated monthly</li>
                            <li>High-value properties (> $1M): Priority 0.9</li>
                        </ul>
                    </li>
                </ul>

                <h3>Data Source</h3>
                <p>Sitemaps are generated from the <code>wp_bme_listing_summary</code> table, which is synchronized with the Bridge MLS Extractor Pro plugin. As your MLS data updates, the sitemaps automatically reflect the changes.</p>
            </div>

            <!-- Manual Controls Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>🔧 Manual Controls</h2>
                <p>Use this button to immediately regenerate all sitemaps without waiting for the scheduled update:</p>
                
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="mld_regenerate_sitemaps">
                    <?php wp_nonce_field('mld_regenerate_sitemaps'); ?>
                    <button type="submit" class="button button-primary button-large">
                        🔄 Regenerate All Sitemaps Now
                    </button>
                    <p class="description">This will clear the cache and rebuild all sitemaps immediately. Use this after making bulk listing changes.</p>
                </form>
            </div>

            <!-- Advanced Information -->
            <div class="card" style="margin-top: 20px;">
                <h2>🔍 Advanced Information</h2>
                
                <h3>Sitemap Specifications</h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Maximum URLs per Sitemap</strong></td>
                            <td>45,000 (Google limit: 50,000)</td>
                        </tr>
                        <tr>
                            <td><strong>Cache Location</strong></td>
                            <td><code>/wp-content/cache/mld-sitemaps/</code></td>
                        </tr>
                        <tr>
                            <td><strong>Cache Duration</strong></td>
                            <td>24 hours</td>
                        </tr>
                        <tr>
                            <td><strong>Cron Schedule</strong></td>
                            <td>Daily at <?php echo $next_scheduled ? date('g:i a', $next_scheduled) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Format</strong></td>
                            <td>XML 1.0, UTF-8 encoding</td>
                        </tr>
                        <tr>
                            <td><strong>Schema</strong></td>
                            <td>
                                Sitemap Protocol 0.9<br>
                                Image Sitemap Extension 1.1
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="margin-top: 20px;">WP-CLI Commands</h3>
                <p>For advanced users, you can manage sitemaps via WP-CLI:</p>
                <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; border-radius: 4px;">
# Regenerate all sitemaps
wp eval "\$generator = MLD_Sitemap_Generator::get_instance(); \$generator->regenerate_all_sitemaps();"

# Check cron schedule
wp cron event list --fields=hook,next_run | grep mld_regenerate_sitemaps

# Run cron event immediately
wp cron event run mld_regenerate_sitemaps
                </pre>
            </div>

            <!-- Troubleshooting Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>🆘 Troubleshooting</h2>
                
                <h3>Common Issues</h3>
                <dl style="line-height: 1.8;">
                    <dt><strong>Q: Google shows "Couldn't fetch" error</strong></dt>
                    <dd>A: Make sure your site is publicly accessible and not blocking search engine bots. Check your robots.txt file.</dd>

                    <dt><strong>Q: Sitemap shows 0 URLs</strong></dt>
                    <dd>A: Verify you have active listings in your database. Check the "Total Properties Indexed" count above.</dd>

                    <dt><strong>Q: New listings not appearing in sitemap</strong></dt>
                    <dd>A: Wait for the next automatic update (<?php echo $next_scheduled ? date('g:i a', $next_scheduled) : 'pending'; ?>) or click "Regenerate All Sitemaps Now" above.</dd>

                    <dt><strong>Q: City pages returning 404 errors</strong></dt>
                    <dd>A: Go to Settings → Permalinks and click "Save Changes" to flush rewrite rules.</dd>
                </dl>

                <h3 style="margin-top: 20px;">Need Help?</h3>
                <p>For additional support, check the <a href="https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview" target="_blank">Google Sitemap Documentation</a> or contact your developer.</p>
            </div>

            <style>
                .card h2 { margin-top: 0; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
                .card h3 { margin-top: 25px; color: #23282d; }
                .card ul, .card ol { margin-left: 20px; }
                .card code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; }
                .card dl dt { font-weight: 600; margin-top: 15px; }
                .card dl dd { margin-left: 20px; margin-bottom: 10px; }
            </style>
        </div>
        <?php
    }
}

// Initialize
add_action('plugins_loaded', function() {
    if (is_admin()) {
        MLD_Sitemap_Admin::get_instance();
    }
});
