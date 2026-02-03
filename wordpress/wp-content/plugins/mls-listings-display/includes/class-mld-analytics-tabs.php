<?php
/**
 * Shared Market Analytics Tab Rendering
 *
 * Renders the market analytics section for property detail pages.
 * Supports both desktop (full tabs) and mobile (lite) versions.
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      6.12.8
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Analytics_Tabs {

    /**
     * Tab configuration
     */
    private static $tabs = array(
        'overview'   => array('label' => 'Overview', 'icon' => 'dashicons-chart-area', 'admin_only' => false),
        'trends'     => array('label' => 'Price Trends', 'icon' => 'dashicons-chart-line', 'admin_only' => false),
        'supply'     => array('label' => 'Supply & Demand', 'icon' => 'dashicons-chart-bar', 'admin_only' => false),
        'velocity'   => array('label' => 'Market Velocity', 'icon' => 'dashicons-performance', 'admin_only' => false),
        'comparison' => array('label' => 'City Comparison', 'icon' => 'dashicons-location', 'admin_only' => false),
        'agents'     => array('label' => 'Agent Performance', 'icon' => 'dashicons-groups', 'admin_only' => true),
        'yoy'        => array('label' => 'Year-over-Year', 'icon' => 'dashicons-calendar-alt', 'admin_only' => false),
        'property'   => array('label' => 'Property Analysis', 'icon' => 'dashicons-admin-home', 'admin_only' => false),
        'features'   => array('label' => 'Feature Premiums', 'icon' => 'dashicons-star-filled', 'admin_only' => false),
    );

    /**
     * Get available tabs based on user capabilities
     *
     * @return array Available tabs
     */
    public static function get_available_tabs() {
        $available = array();
        foreach (self::$tabs as $key => $config) {
            // Skip admin-only tabs for non-admins
            if (!empty($config['admin_only']) && !current_user_can('manage_options')) {
                continue;
            }
            $available[$key] = $config;
        }
        return $available;
    }

    /**
     * Render the analytics section for property page
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return string HTML output
     */
    public static function render_property_section($city, $state, $property_type = 'all') {
        if (empty($city)) {
            return '';
        }

        $tabs = self::get_available_tabs();
        $is_mobile = wp_is_mobile();

        ob_start();
        ?>
        <div class="mld-v3-full-width-section">
            <section id="market-analytics" class="mld-v3-section mld-market-analytics-section">
                <div class="mld-v3-section-container">

                    <!-- City Context Banner -->
                    <div class="mld-market-city-banner">
                        <span class="dashicons dashicons-location-alt mld-market-city-banner__icon"></span>
                        <div class="mld-market-city-banner__text">
                            <h2 class="mld-market-city-banner__title">Market Analytics for <?php echo esc_html($city); ?>, <?php echo esc_html($state); ?></h2>
                            <p class="mld-market-city-banner__subtitle">Real-time market trends and pricing data for this area</p>
                        </div>
                    </div>

                    <!-- Analytics Container (lazy-loaded via IntersectionObserver) -->
                    <div id="mld-property-analytics"
                         class="mld-property-analytics-wrapper <?php echo $is_mobile ? 'mld-analytics-lite' : 'mld-analytics-full'; ?>"
                         data-city="<?php echo esc_attr($city); ?>"
                         data-state="<?php echo esc_attr($state); ?>"
                         data-property-type="<?php echo esc_attr($property_type); ?>"
                         data-lite="<?php echo $is_mobile ? 'true' : 'false'; ?>"
                         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">

                        <!-- Skeleton Loader (shown before data loads) -->
                        <div class="mld-analytics-skeleton">
                            <?php if (!$is_mobile): ?>
                            <div class="mld-skeleton-tabs">
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                <div class="mld-skeleton-tab"></div>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                            <div class="mld-skeleton-metrics">
                                <?php for ($i = 0; $i < 4; $i++): ?>
                                <div class="mld-skeleton-metric-card">
                                    <div class="mld-skeleton-line mld-skeleton-icon"></div>
                                    <div class="mld-skeleton-line mld-skeleton-value"></div>
                                    <div class="mld-skeleton-line mld-skeleton-label"></div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Actual content (populated via JavaScript) -->
                        <div class="mld-analytics-content" style="display: none;">
                            <?php if (!$is_mobile): ?>
                            <!-- Desktop: Tab Navigation -->
                            <div class="mld-market-tabs" role="tablist" aria-label="Market analytics categories">
                                <?php
                                $first_tab = true;
                                foreach ($tabs as $key => $config):
                                ?>
                                <button class="mld-market-tab <?php echo $first_tab ? 'active' : ''; ?>"
                                        role="tab"
                                        aria-selected="<?php echo $first_tab ? 'true' : 'false'; ?>"
                                        aria-controls="panel-<?php echo esc_attr($key); ?>"
                                        id="tab-<?php echo esc_attr($key); ?>"
                                        data-tab="<?php echo esc_attr($key); ?>"
                                        tabindex="<?php echo $first_tab ? '0' : '-1'; ?>">
                                    <span class="dashicons <?php echo esc_attr($config['icon']); ?>"></span>
                                    <span class="mld-tab-label"><?php echo esc_html($config['label']); ?></span>
                                </button>
                                <?php
                                $first_tab = false;
                                endforeach;
                                ?>
                            </div>
                            <?php endif; ?>

                            <!-- Summary Metrics Grid (visible on all views) -->
                            <div class="mld-market-metrics-grid" aria-label="Key market metrics"></div>

                            <!-- Market Heat Gauge (desktop only) -->
                            <?php if (!$is_mobile): ?>
                            <div class="mld-market-heat-container"></div>
                            <?php endif; ?>

                            <!-- Tab Content Panels (desktop only) -->
                            <?php if (!$is_mobile): ?>
                            <div class="mld-market-tab-panels">
                                <?php
                                $first_panel = true;
                                foreach ($tabs as $key => $config):
                                ?>
                                <div class="mld-market-tab-panel <?php echo $first_panel ? 'active' : ''; ?>"
                                     id="panel-<?php echo esc_attr($key); ?>"
                                     role="tabpanel"
                                     aria-labelledby="tab-<?php echo esc_attr($key); ?>"
                                     data-tab="<?php echo esc_attr($key); ?>"
                                     tabindex="0"
                                     <?php echo !$first_panel ? 'hidden' : ''; ?>>
                                    <div class="mld-panel-loading">
                                        <span class="dashicons dashicons-update mld-spinner"></span>
                                        Loading <?php echo esc_html($config['label']); ?>...
                                    </div>
                                </div>
                                <?php
                                $first_panel = false;
                                endforeach;
                                ?>
                            </div>
                            <?php else: ?>
                            <!-- Mobile: Expandable Full Report Link -->
                            <div class="mld-mobile-expand">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mld-neighborhood-analytics&city=' . urlencode($city) . '&state=' . urlencode($state))); ?>"
                                   class="mld-mobile-expand-btn"
                                   target="_blank"
                                   rel="noopener">
                                    View Full Market Report
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get tab configuration
     *
     * @return array Tab configuration
     */
    public static function get_tabs() {
        return self::$tabs;
    }

    /**
     * Check if a specific tab is available
     *
     * @param string $tab_key Tab key
     * @return bool Whether tab is available
     */
    public static function is_tab_available($tab_key) {
        if (!isset(self::$tabs[$tab_key])) {
            return false;
        }

        $config = self::$tabs[$tab_key];
        if (!empty($config['admin_only']) && !current_user_can('manage_options')) {
            return false;
        }

        return true;
    }
}
