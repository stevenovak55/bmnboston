<?php
/**
 * Enhanced Admin view for the main settings page with shortcode reference
 *
 * @package MLS_Listings_Display
 * @since 4.5.0
 */

// Determine the active tab
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api_settings';
$allowed_tabs = ['api_settings', 'display_settings', 'notifications', 'shortcodes', 'pages_setup', 'card_generator'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'api_settings';
}
?>
<div class="wrap mld-settings-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- Tab navigation -->
    <h2 class="nav-tab-wrapper">
        <a href="?page=mls_listings_display&tab=api_settings" class="nav-tab <?php echo esc_attr($active_tab == 'api_settings' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-admin-settings"></span> API & Map Settings
        </a>
        <a href="?page=mls_listings_display&tab=display_settings" class="nav-tab <?php echo esc_attr($active_tab == 'display_settings' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-welcome-view-site"></span> Display Settings
        </a>
        <a href="?page=mls_listings_display&tab=notifications" class="nav-tab <?php echo esc_attr($active_tab == 'notifications' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-email-alt"></span> Email Notifications
        </a>
        <a href="?page=mls_listings_display&tab=shortcodes" class="nav-tab <?php echo esc_attr($active_tab == 'shortcodes' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-shortcode"></span> Shortcodes Reference
        </a>
        <a href="?page=mls_listings_display&tab=pages_setup" class="nav-tab <?php echo esc_attr($active_tab == 'pages_setup' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-admin-page"></span> Quick Page Setup
        </a>
        <a href="?page=mls_listings_display&tab=card_generator" class="nav-tab <?php echo esc_attr($active_tab == 'card_generator' ? 'nav-tab-active' : ''); ?>">
            <span class="dashicons dashicons-layout"></span> Card Generator
        </a>
    </h2>

    <?php if ($active_tab == 'shortcodes'): ?>
        <!-- Shortcodes Reference Tab -->
        <div class="mld-shortcodes-reference">
            <style>
                .mld-shortcodes-reference {
                    margin: 20px 0;
                    max-width: 1200px;
                }
                .shortcode-section {
                    background: #fff;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    padding: 20px;
                    margin-bottom: 20px;
                }
                .shortcode-section h3 {
                    margin-top: 0;
                    color: #23282d;
                    border-bottom: 2px solid #0073aa;
                    padding-bottom: 10px;
                }
                .shortcode-item {
                    margin-bottom: 25px;
                    padding: 15px;
                    background: #f9f9f9;
                    border-left: 4px solid #0073aa;
                }
                .shortcode-code {
                    background: #2c3338;
                    color: #50fa7b;
                    padding: 10px 15px;
                    border-radius: 3px;
                    font-family: 'Courier New', monospace;
                    margin: 10px 0;
                    display: inline-block;
                    user-select: all;
                    cursor: pointer;
                }
                .shortcode-code:hover {
                    background: #1d2327;
                }
                .shortcode-description {
                    color: #555;
                    margin: 10px 0;
                    line-height: 1.6;
                }
                .shortcode-params {
                    margin-top: 10px;
                }
                .shortcode-params h5 {
                    margin: 10px 0 5px 0;
                    color: #23282d;
                    cursor: pointer;
                    background: #f5f5f5;
                    padding: 8px 12px;
                    border-radius: 4px;
                    position: relative;
                    transition: background 0.2s;
                }
                .shortcode-params h5:hover {
                    background: #e8e8e8;
                }
                .shortcode-params h5:before {
                    content: '▼';
                    position: absolute;
                    right: 12px;
                    font-size: 10px;
                    transition: transform 0.2s;
                }
                .shortcode-params h5.collapsed:before {
                    transform: rotate(-90deg);
                }
                .shortcode-params h5.collapsed + .param-list {
                    display: none;
                }
                .param-list {
                    list-style: none;
                    padding-left: 0;
                    max-height: 300px;
                    overflow-y: auto;
                    border: 1px solid #e0e0e0;
                    border-radius: 4px;
                    background: #f9f9f9;
                    margin: 10px 0;
                }
                .param-list li {
                    margin: 0;
                    padding: 8px 10px;
                    background: #fff;
                    border-bottom: 1px solid #e0e0e0;
                }
                .param-list li:last-child {
                    border-bottom: none;
                }
                .param-list li:hover {
                    background: #f5f5f5;
                }
                .param-name {
                    font-weight: bold;
                    color: #0073aa;
                }
                .param-desc {
                    color: #666;
                    margin-left: 10px;
                }
                .shortcode-example {
                    background: #fff;
                    border: 1px solid #ddd;
                    padding: 10px;
                    margin-top: 10px;
                    border-radius: 3px;
                }
                .shortcode-example h5 {
                    margin: 0 0 10px 0;
                    color: #555;
                }
                .copy-notice {
                    display: inline-block;
                    margin-left: 10px;
                    color: #46b450;
                    font-size: 12px;
                    opacity: 0;
                    transition: opacity 0.3s;
                }
                .copy-notice.show {
                    opacity: 1;
                }
                .shortcode-category {
                    display: inline-block;
                    background: #0073aa;
                    color: white;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    margin-left: 10px;
                    text-transform: uppercase;
                }
                .new-badge {
                    background: #46b450;
                }
                .popular-badge {
                    background: #ff6b6b;
                }
            </style>

            <!-- Map Display Shortcodes -->
            <div class="shortcode-section">
                <h3>🗺️ Map Display Shortcodes</h3>

                <div class="shortcode-item">
                    <h4>Full Map View <span class="shortcode-category popular-badge">Popular</span></h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[bme_listings_map_view]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Displays a full-screen interactive map with property listings. Includes filters, search functionality, and responsive design with extensive customization options.
                    </p>
                    <div class="shortcode-params">
                        <h5>Location Filter Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">city</span><span class="param-desc">Filter by city (comma-separated for multiple, e.g., "Boston,Cambridge")</span></li>
                            <li><span class="param-name">neighborhood</span><span class="param-desc">Filter by neighborhood (comma-separated)</span></li>
                            <li><span class="param-name">postal_code</span><span class="param-desc">Filter by ZIP/postal code (comma-separated)</span></li>
                            <li><span class="param-name">street_name</span><span class="param-desc">Filter by street name</span></li>
                            <li><span class="param-name">building</span><span class="param-desc">Filter by building name</span></li>
                            <li><span class="param-name">address</span><span class="param-desc">Filter by specific address</span></li>
                            <li><span class="param-name">mls_number</span><span class="param-desc">Filter by MLS number</span></li>
                        </ul>

                        <h5>Property Type Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">property_type</span><span class="param-desc">Property type (Residential, Commercial, etc.)</span></li>
                            <li><span class="param-name">home_type</span><span class="param-desc">Home subtypes (comma-separated, e.g., "Single Family,Condo")</span></li>
                            <li><span class="param-name">structure_type</span><span class="param-desc">Structure types (comma-separated)</span></li>
                            <li><span class="param-name">architectural_style</span><span class="param-desc">Architectural styles (comma-separated)</span></li>
                        </ul>

                        <h5>Price & Size Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">price_min</span><span class="param-desc">Minimum price (e.g., "500000")</span></li>
                            <li><span class="param-name">price_max</span><span class="param-desc">Maximum price</span></li>
                            <li><span class="param-name">beds</span><span class="param-desc">Number of bedrooms ("2,3,4" or "3+" for 3 or more)</span></li>
                            <li><span class="param-name">baths_min</span><span class="param-desc">Minimum bathrooms</span></li>
                            <li><span class="param-name">sqft_min</span><span class="param-desc">Minimum square footage</span></li>
                            <li><span class="param-name">sqft_max</span><span class="param-desc">Maximum square footage</span></li>
                            <li><span class="param-name">lot_size_min</span><span class="param-desc">Minimum lot size</span></li>
                            <li><span class="param-name">lot_size_max</span><span class="param-desc">Maximum lot size</span></li>
                        </ul>

                        <h5>Additional Numeric Filters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">year_built_min</span><span class="param-desc">Minimum year built</span></li>
                            <li><span class="param-name">year_built_max</span><span class="param-desc">Maximum year built</span></li>
                            <li><span class="param-name">entry_level_min</span><span class="param-desc">Minimum entry level/floor</span></li>
                            <li><span class="param-name">entry_level_max</span><span class="param-desc">Maximum entry level/floor</span></li>
                            <li><span class="param-name">garage_spaces_min</span><span class="param-desc">Minimum garage spaces</span></li>
                            <li><span class="param-name">parking_total_min</span><span class="param-desc">Minimum total parking spaces</span></li>
                        </ul>

                        <h5>Status & Date Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">status</span><span class="param-desc">Listing status (comma-separated, default: "Active", options: Active, Under Agreement, Sold, etc.)</span></li>
                            <li><span class="param-name">available_by</span><span class="param-desc">Available by date (YYYY-MM-DD format)</span></li>
                            <li><span class="param-name">open_house_only</span><span class="param-desc">Show only open houses (yes/no)</span></li>
                        </ul>

                        <h5>Boolean Feature Parameters (yes/no):</h5>
                        <ul class="param-list">
                            <li><span class="param-name">spa</span><span class="param-desc">Has spa/hot tub</span></li>
                            <li><span class="param-name">waterfront</span><span class="param-desc">Waterfront property</span></li>
                            <li><span class="param-name">view</span><span class="param-desc">Has view</span></li>
                            <li><span class="param-name">waterview</span><span class="param-desc">Has water view</span></li>
                            <li><span class="param-name">attached</span><span class="param-desc">Attached property</span></li>
                            <li><span class="param-name">lender_owned</span><span class="param-desc">Lender/bank owned</span></li>
                            <li><span class="param-name">available_now</span><span class="param-desc">Available immediately</span></li>
                            <li><span class="param-name">senior_community</span><span class="param-desc">Senior community</span></li>
                            <li><span class="param-name">outdoor_space</span><span class="param-desc">Has outdoor space</span></li>
                            <li><span class="param-name">dpr</span><span class="param-desc">DPR property</span></li>
                            <li><span class="param-name">laundry_in_unit</span><span class="param-desc">In-unit laundry</span></li>
                            <li><span class="param-name">pets_allowed</span><span class="param-desc">Pets allowed</span></li>
                            <li><span class="param-name">cooling</span><span class="param-desc">Has cooling/AC</span></li>
                            <li><span class="param-name">heating</span><span class="param-desc">Has heating</span></li>
                            <li><span class="param-name">basement</span><span class="param-desc">Has basement</span></li>
                            <li><span class="param-name">fireplace</span><span class="param-desc">Has fireplace</span></li>
                            <li><span class="param-name">garage</span><span class="param-desc">Has garage</span></li>
                            <li><span class="param-name">pool</span><span class="param-desc">Has pool</span></li>
                            <li><span class="param-name">horses</span><span class="param-desc">Allows horses</span></li>
                        </ul>

                        <h5>Map Configuration Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">center_lat</span><span class="param-desc">Map center latitude (e.g., "42.3601")</span></li>
                            <li><span class="param-name">center_lng</span><span class="param-desc">Map center longitude (e.g., "-71.0589")</span></li>
                            <li><span class="param-name">zoom</span><span class="param-desc">Initial zoom level (8-18, default: 13)</span></li>
                            <li><span class="param-name">polygon_shapes</span><span class="param-desc">JSON-encoded polygon coordinates for area selection</span></li>
                        </ul>

                        <h5>Display Settings Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">show_filters</span><span class="param-desc">Show filter panel (yes/no, default: yes)</span></li>
                            <li><span class="param-name">show_search</span><span class="param-desc">Show search bar (yes/no, default: yes)</span></li>
                            <li><span class="param-name">show_sidebar</span><span class="param-desc">Show property list sidebar (yes/no, default: yes)</span></li>
                            <li><span class="param-name">default_view</span><span class="param-desc">Default view mode (map/list, default: map)</span></li>
                            <li><span class="param-name">max_results</span><span class="param-desc">Maximum number of results to display</span></li>
                        </ul>
                    </div>
                    <div class="shortcode-example">
                        <h5>Simple Example:</h5>
                        <code>[bme_listings_map_view city="Boston" price_max="1000000" beds="3+"]</code>

                        <h5>Advanced Example:</h5>
                        <code>[bme_listings_map_view city="Boston,Cambridge" price_min="500000" price_max="1500000" beds="3,4,5" waterfront="yes" status="Active,Under Agreement" center_lat="42.3601" center_lng="-71.0589" zoom="12"]</code>
                    </div>
                </div>

                <div class="shortcode-item">
                    <h4>Half Map View</h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[bme_listings_half_map_view]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Split-screen layout with map on one side and property list on the other. Perfect for desktop viewing. Supports all the same filter parameters as the full map view.
                    </p>
                    <div class="shortcode-params">
                        <h5>All Parameters from Full Map View:</h5>
                        <p style="margin: 10px 0; color: #666;">
                            The half map view supports ALL the same parameters as the full map view (location filters, property filters, price & size, features, etc.).
                            The only difference is the default_view parameter defaults to "split" instead of "map".
                        </p>

                        <h5>Unique Display Setting:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">default_view</span><span class="param-desc">Default view mode (split/map/list, default: split)</span></li>
                        </ul>
                    </div>
                    <div class="shortcode-example">
                        <h5>Example:</h5>
                        <code>[bme_listings_half_map_view city="Cambridge" home_type="Condo" price_max="800000" sqft_min="1000"]</code>
                    </div>
                </div>

                <div class="shortcode-item">
                    <h4>Alternative Map Shortcodes</h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[mld_map_full]</div>
                    <span class="copy-notice">Copied!</span>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[mld_map_half]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Aliases for the map view shortcodes with MLD prefix for consistency.
                    </p>
                </div>
            </div>

            <!-- User Dashboard Shortcodes -->
            <div class="shortcode-section">
                <h3>👤 User Dashboard & Saved Searches</h3>

                <div class="shortcode-item">
                    <h4>Complete User Dashboard <span class="shortcode-category new-badge">New</span></h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[mld_user_dashboard]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Comprehensive user dashboard with tabs for saved searches, liked properties, hidden properties, and account settings.
                        Automatically handles login state and shows appropriate content.
                    </p>
                    <div class="shortcode-params">
                        <h5>Features Included:</h5>
                        <ul class="param-list">
                            <li>✅ Saved searches management with instant/daily/weekly notifications</li>
                            <li>✅ Liked properties collection</li>
                            <li>✅ Hidden properties management</li>
                            <li>✅ Property comparison tool</li>
                            <li>✅ Email notification preferences</li>
                            <li>✅ Agent assignment display (if applicable)</li>
                        </ul>
                    </div>
                </div>

                <div class="shortcode-item">
                    <h4>Saved Searches List</h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[mld_saved_searches]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Display user's saved searches with management options. Shows search criteria, notification settings, and quick actions.
                    </p>
                    <div class="shortcode-params">
                        <h5>Optional Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">show_inactive</span><span class="param-desc">Include paused searches (true/false, default: false)</span></li>
                            <li><span class="param-name">layout</span><span class="param-desc">Display layout (grid/list, default: grid)</span></li>
                        </ul>
                    </div>
                </div>

                <div class="shortcode-item">
                    <h4>Saved Properties Gallery</h4>
                    <div class="shortcode-code" onclick="copyShortcode(this)">[mld_saved_properties]</div>
                    <span class="copy-notice">Copied!</span>
                    <p class="shortcode-description">
                        Gallery view of user's liked/saved properties. Interactive cards with quick actions and comparison tools.
                    </p>
                    <div class="shortcode-params">
                        <h5>Optional Parameters:</h5>
                        <ul class="param-list">
                            <li><span class="param-name">columns</span><span class="param-desc">Number of columns (2-4, default: 3)</span></li>
                            <li><span class="param-name">show_hidden</span><span class="param-desc">Include hidden properties tab (true/false, default: true)</span></li>
                            <li><span class="param-name">enable_compare</span><span class="param-desc">Show comparison checkboxes (true/false, default: true)</span></li>
                        </ul>
                    </div>
                </div>
            </div>



            <script>
            function copyShortcode(element) {
                // Select the text
                const range = document.createRange();
                range.selectNode(element);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);

                // Copy to clipboard
                document.execCommand('copy');

                // Clear selection
                window.getSelection().removeAllRanges();

                // Show notice
                const notice = element.nextElementSibling;
                if (notice && notice.classList.contains('copy-notice')) {
                    notice.classList.add('show');
                    setTimeout(() => {
                        notice.classList.remove('show');
                    }, 2000);
                }
            }

            // Make parameter sections collapsible
            document.addEventListener('DOMContentLoaded', function() {
                const paramHeaders = document.querySelectorAll('.shortcode-params h5');

                // Start with all sections collapsed
                paramHeaders.forEach((header) => {
                    header.classList.add('collapsed');

                    header.addEventListener('click', function() {
                        this.classList.toggle('collapsed');
                    });
                });
            });
            </script>
        </div>

    <?php elseif ($active_tab == 'pages_setup'): ?>
        <!-- Quick Page Setup Tab -->
        <div class="mld-pages-setup">
            <style>
                .mld-pages-setup {
                    margin: 20px 0;
                    max-width: 900px;
                }
                .page-setup-section {
                    background: #fff;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    padding: 20px;
                    margin-bottom: 20px;
                }
                .page-setup-item {
                    margin-bottom: 20px;
                    padding: 15px;
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                }
                .page-setup-item h4 {
                    margin-top: 0;
                    color: #23282d;
                }
                .create-page-btn {
                    background: #0073aa;
                    color: white;
                    padding: 8px 15px;
                    border: none;
                    border-radius: 3px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    margin-top: 10px;
                }
                .create-page-btn:hover {
                    background: #005177;
                    color: white;
                }
                .page-exists {
                    color: #46b450;
                    font-weight: bold;
                }
                .page-not-exists {
                    color: #dc3232;
                }
                .recommended-badge {
                    background: #46b450;
                    color: white;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 11px;
                    margin-left: 10px;
                    text-transform: uppercase;
                }
            </style>

            <div class="page-setup-section">
                <h3>Quick Page Setup</h3>
                <p>Create essential pages for your MLS listings website with one click. These pages will be created with the appropriate shortcodes.</p>

                <?php
                // Check which pages already exist - ONLY WORKING SHORTCODES
                $pages_to_create = [
                    [
                        'title' => 'Property Search',
                        'slug' => 'search',
                        'alt_slugs' => ['property-search', 'search'],
                        'shortcode' => '[bme_listings_map_view]',
                        'description' => 'Main property search page with interactive map and filters.',
                        'recommended' => true
                    ],
                    [
                        'title' => 'Property Search (Split View)',
                        'slug' => 'property-search-split',
                        'shortcode' => '[bme_listings_half_map_view]',
                        'description' => 'Property search with map and list side-by-side.',
                        'recommended' => false
                    ],
                    [
                        'title' => 'My Dashboard',
                        'slug' => 'my-dashboard',
                        'shortcode' => '[mld_user_dashboard]',
                        'description' => 'User dashboard for saved searches and liked properties.',
                        'recommended' => true
                    ],
                    [
                        'title' => 'My Saved Searches',
                        'slug' => 'my-saved-searches',
                        'alt_slugs' => ['saved-searches', 'my-saved-searches'],
                        'shortcode' => '[mld_saved_searches]',
                        'description' => 'Manage saved searches and email notifications.'
                    ],
                    [
                        'title' => 'My Saved Properties',
                        'slug' => 'my-saved-properties',
                        'alt_slugs' => ['my-properties', 'my-saved-properties'],
                        'shortcode' => '[mld_saved_properties]',
                        'description' => 'View saved and hidden properties.'
                    ]
                ];

                foreach ($pages_to_create as $page_info):
                    // Check primary slug first
                    $existing_page = get_page_by_path($page_info['slug']);

                    // If not found, check alternate slugs
                    if (!$existing_page && !empty($page_info['alt_slugs'])) {
                        foreach ($page_info['alt_slugs'] as $alt_slug) {
                            $existing_page = get_page_by_path($alt_slug);
                            if ($existing_page) break;
                        }
                    }
                    ?>
                    <div class="page-setup-item">
                        <h4>
                            <?php echo esc_html($page_info['title']); ?>
                            <?php if (isset($page_info['recommended']) && $page_info['recommended']): ?>
                                <span class="recommended-badge">Recommended</span>
                            <?php endif; ?>
                        </h4>
                        <p><?php echo esc_html($page_info['description']); ?></p>
                        <p>Shortcode: <code><?php echo esc_html($page_info['shortcode']); ?></code></p>

                        <?php if ($existing_page): ?>
                            <p class="page-exists">✓ Page exists</p>
                            <a href="<?php echo get_edit_post_link($existing_page->ID); ?>" class="create-page-btn">
                                Edit Page
                            </a>
                            <a href="<?php echo get_permalink($existing_page->ID); ?>" class="create-page-btn" target="_blank">
                                View Page
                            </a>
                        <?php else: ?>
                            <p class="page-not-exists">✗ Page does not exist</p>
                            <button class="create-page-btn" onclick="createPage('<?php echo esc_js($page_info['title']); ?>', '<?php echo esc_js($page_info['slug']); ?>', '<?php echo esc_js($page_info['shortcode']); ?>')">
                                Create Page
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="page-setup-section">
                <h3>Recommended Menu Structure</h3>
                <p>Add these pages to your main menu for easy navigation:</p>
                <ol>
                    <li><strong>Property Search</strong> - Main search page</li>
                    <li><strong>My Dashboard</strong> - User account area (logged-in users only)</li>
                    <li><strong>Market Analytics</strong> - Market insights and trends</li>
                    <li><strong>Compare</strong> - Property comparison tool</li>
                </ol>
                <p>
                    <a href="<?php echo admin_url('nav-menus.php'); ?>" class="create-page-btn">
                        Manage Menus
                    </a>
                </p>
            </div>

            <script>
            function createPage(title, slug, shortcode) {
                if (!confirm('Create page "' + title + '"?')) {
                    return;
                }

                jQuery.post(ajaxurl, {
                    action: 'mld_create_page',
                    title: title,
                    slug: slug,
                    shortcode: shortcode,
                    nonce: '<?php echo wp_create_nonce('mld_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Page created successfully!');
                        location.reload();
                    } else {
                        alert('Error creating page: ' + (response.data.message || response.data));
                    }
                });
            }
            </script>
        </div>

    <?php elseif ($active_tab == 'notifications'): ?>
        <!-- Notifications Settings Tab -->
        <div class="mld-notifications-settings">
            <p>Configure email notification settings for property alerts and system emails.</p>

            <form action="options.php" method="post">
                <?php
                settings_fields('mld_simple_notification_group');
                do_settings_sections('mld_simple_notification_group');
                submit_button('Save Notification Settings');
                ?>
            </form>

            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 5px;">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=mld_notification_status'); ?>">View Notification Status Dashboard</a> - Monitor system health and activity</li>
                    <li><a href="<?php echo admin_url('admin.php?page=mld_form_submissions'); ?>">View Form Submissions</a> - Manage contact form submissions</li>
                </ul>
            </div>
        </div>

    <?php elseif ($active_tab == 'card_generator'): ?>
        <!-- Card Generator Tab -->
        <?php include MLD_PLUGIN_PATH . 'admin/views/shortcode-generator-tab.php'; ?>

    <?php else: ?>
        <!-- Original form for other tabs -->
        <form action="options.php" method="post">
            <?php
            // Display the correct settings section based on the active tab
            if ($active_tab == 'display_settings') {
                settings_fields('mld_display_options_group');
                do_settings_sections('mld_display_options_group');
            } else {
                settings_fields('mld_options_group');
                do_settings_sections('mld_options_group');
            }

            submit_button('Save Settings');
            ?>
        </form>
    <?php endif; ?>
</div>