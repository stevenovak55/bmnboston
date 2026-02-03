<?php
/**
 * Neighborhood Analytics Shortcodes
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Analytics_Shortcodes {

    /**
     * Analytics instance
     */
    private $analytics;

    /**
     * Constructor
     */
    public function __construct() {
        require_once plugin_dir_path(__FILE__) . 'class-mld-neighborhood-analytics.php';
        $this->analytics = new MLD_Neighborhood_Analytics();

        // Register shortcodes
        add_shortcode('bme_neighborhood_analytics', array($this, 'neighborhood_analytics_shortcode'));
        add_shortcode('mld_neighborhood_analytics', array($this, 'neighborhood_analytics_shortcode'));
        add_shortcode('mld_analytics', array($this, 'neighborhood_analytics_shortcode'));
    }

    /**
     * Neighborhood Analytics Shortcode
     *
     * Usage:
     * [bme_neighborhood_analytics city="Seattle" state="WA"]
     * [bme_neighborhood_analytics city="Boston" state="MA" property_type="Single Family"]
     * [bme_neighborhood_analytics city="Miami" period="12_months"]
     */
    public function neighborhood_analytics_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'property_type' => 'all',
            'period' => '12_months',
            'show' => 'all', // all, price, velocity, inventory, market_heat
        ), $atts, 'bme_neighborhood_analytics');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        // Get analytics
        $analytics_data = $this->analytics->get_city_analytics(
            $atts['city'],
            $atts['state'],
            $atts['property_type']
        );

        if (empty($analytics_data) || !isset($analytics_data[$atts['period']])) {
            return '<div class="mld-analytics-notice">No analytics data available for ' . esc_html($atts['city']) . '. Please contact the administrator.</div>';
        }

        $data = $analytics_data[$atts['period']];

        // Build output
        ob_start();
        ?>
        <div class="mld-neighborhood-analytics" data-city="<?php echo esc_attr($atts['city']); ?>">
            <div class="mld-analytics-header">
                <h2 class="mld-analytics-title">
                    <?php echo esc_html($atts['city']); ?>
                    <?php if (!empty($atts['state'])): ?>
                        <span class="mld-analytics-state">, <?php echo esc_html($atts['state']); ?></span>
                    <?php endif; ?>
                    Market Analytics
                </h2>
                <div class="mld-analytics-period">
                    <?php echo esc_html(str_replace('_', ' ', ucwords($atts['period'], '_'))); ?> Data
                </div>
            </div>

            <?php if ($atts['show'] === 'all' || $atts['show'] === 'market_heat'): ?>
                <div class="mld-analytics-section mld-analytics-market-heat">
                    <div class="mld-heat-badge mld-heat-<?php echo esc_attr($data['market_classification']); ?>">
                        <span class="mld-heat-label"><?php echo esc_html($data['market_description']); ?></span>
                        <span class="mld-heat-score"><?php echo number_format($data['market_heat_index'], 0); ?>/100</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($atts['show'] === 'all' || $atts['show'] === 'price'): ?>
                <div class="mld-analytics-section mld-analytics-price">
                    <h3>Price Metrics</h3>
                    <div class="mld-analytics-grid">
                        <?php if (isset($data['median_price'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Median Price</div>
                                <div class="mld-stat-value">$<?php echo number_format($data['median_price'], 0); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['average_price'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Average Price</div>
                                <div class="mld-stat-value">$<?php echo number_format($data['average_price'], 0); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['price_per_sqft_average'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Price per Sq Ft</div>
                                <div class="mld-stat-value">$<?php echo number_format($data['price_per_sqft_average'], 2); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['data_points'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Listings Analyzed</div>
                                <div class="mld-stat-value"><?php echo number_format($data['data_points']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($atts['show'] === 'all' || $atts['show'] === 'velocity'): ?>
                <div class="mld-analytics-section mld-analytics-velocity">
                    <h3>Market Velocity</h3>
                    <div class="mld-analytics-grid">
                        <?php if (isset($data['avg_days_on_market'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Avg Days on Market</div>
                                <div class="mld-stat-value"><?php echo number_format($data['avg_days_on_market'], 0); ?> days</div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['listing_turnover_rate'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Turnover Rate</div>
                                <div class="mld-stat-value"><?php echo number_format($data['listing_turnover_rate'], 1); ?>%</div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['avg_days_to_close'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Avg Days to Close</div>
                                <div class="mld-stat-value"><?php echo number_format($data['avg_days_to_close'], 0); ?> days</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($atts['show'] === 'all' || $atts['show'] === 'inventory'): ?>
                <div class="mld-analytics-section mld-analytics-inventory">
                    <h3>Inventory Analysis</h3>
                    <div class="mld-analytics-grid">
                        <?php if (isset($data['active_listings_count'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Active Listings</div>
                                <div class="mld-stat-value"><?php echo number_format($data['active_listings_count']); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['months_of_supply'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Months of Supply</div>
                                <div class="mld-stat-value"><?php echo number_format($data['months_of_supply'], 1); ?> mos</div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['sold_listings_count'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Sold Listings</div>
                                <div class="mld-stat-value"><?php echo number_format($data['sold_listings_count']); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($data['absorption_rate'])): ?>
                            <div class="mld-analytics-stat">
                                <div class="mld-stat-label">Absorption Rate</div>
                                <div class="mld-stat-value"><?php echo number_format($data['absorption_rate'], 1); ?>%</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mld-analytics-footer">
                <small>Data calculated: <?php echo date('M j, Y', strtotime($data['calculation_date'])); ?></small>
            </div>
        </div>

        <style>
            .mld-neighborhood-analytics {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 24px;
                margin: 20px 0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }

            .mld-analytics-header {
                margin-bottom: 24px;
                border-bottom: 2px solid #2c5aa0;
                padding-bottom: 16px;
            }

            .mld-analytics-title {
                margin: 0 0 8px 0;
                color: #1e3d6f;
                font-size: 28px;
            }

            .mld-analytics-period {
                color: #666;
                font-size: 14px;
            }

            .mld-analytics-section {
                margin: 24px 0;
            }

            .mld-analytics-section h3 {
                margin: 0 0 16px 0;
                color: #333;
                font-size: 18px;
                font-weight: 600;
            }

            .mld-analytics-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
            }

            .mld-analytics-stat {
                background: #f8f9fa;
                padding: 16px;
                border-radius: 6px;
                border-left: 4px solid #2c5aa0;
            }

            .mld-stat-label {
                font-size: 13px;
                color: #666;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .mld-stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #1e3d6f;
            }

            .mld-analytics-market-heat {
                text-align: center;
                margin: 32px 0;
            }

            .mld-heat-badge {
                display: inline-block;
                padding: 20px 40px;
                border-radius: 50px;
                color: white;
                font-size: 18px;
                font-weight: bold;
            }

            .mld-heat-badge.mld-heat-hot {
                background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            }

            .mld-heat-badge.mld-heat-balanced {
                background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            }

            .mld-heat-badge.mld-heat-cold {
                background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            }

            .mld-heat-score {
                display: inline-block;
                margin-left: 12px;
                padding: 4px 12px;
                background: rgba(255,255,255,0.3);
                border-radius: 20px;
                font-size: 16px;
            }

            .mld-analytics-footer {
                margin-top: 24px;
                padding-top: 16px;
                border-top: 1px solid #e0e0e0;
                text-align: center;
                color: #999;
            }

            .mld-analytics-error,
            .mld-analytics-notice {
                padding: 16px;
                margin: 20px 0;
                border-radius: 4px;
                border-left: 4px solid #2c5aa0;
                background: #f0f6ff;
            }

            .mld-analytics-error {
                border-left-color: #dc3545;
                background: #fff0f0;
                color: #721c24;
            }

            /* Mobile Responsive */
            @media (max-width: 768px) {
                .mld-neighborhood-analytics {
                    padding: 16px;
                }

                .mld-analytics-title {
                    font-size: 22px;
                }

                .mld-analytics-grid {
                    grid-template-columns: 1fr;
                }

                .mld-heat-badge {
                    padding: 16px 24px;
                    font-size: 16px;
                }

                .mld-stat-value {
                    font-size: 20px;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Market Summary Card Shortcode
     *
     * Usage: [mld_market_summary city="Reading" state="MA" months="12"]
     */
    public function market_summary_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'months' => 12,
            'property_type' => 'all',
        ), $atts, 'mld_market_summary');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        $trends = new MLD_Market_Trends();
        $summary = $trends->get_market_summary($atts['city'], $atts['state'], $atts['property_type'], intval($atts['months']));
        $heat = MLD_Extended_Analytics::get_market_heat_index($atts['city'], $atts['state']);

        if (isset($summary['error'])) {
            return '<div class="mld-analytics-notice">No market data available for ' . esc_html($atts['city']) . '.</div>';
        }

        ob_start();
        ?>
        <div class="mld-market-summary-card">
            <div class="mld-summary-header">
                <h3><?php echo esc_html($atts['city']); ?><?php echo $atts['state'] ? ', ' . esc_html($atts['state']) : ''; ?></h3>
                <?php if (!empty($heat) && isset($heat['classification'])): ?>
                    <span class="mld-heat-tag mld-heat-<?php echo esc_attr(strtolower($heat['classification'])); ?>">
                        <?php echo esc_html($heat['classification']); ?> Market
                    </span>
                <?php endif; ?>
            </div>
            <div class="mld-summary-stats">
                <div class="mld-summary-stat">
                    <span class="mld-stat-value">$<?php echo number_format($summary['avg_close_price']); ?></span>
                    <span class="mld-stat-label">Avg Price</span>
                </div>
                <div class="mld-summary-stat">
                    <span class="mld-stat-value"><?php echo number_format($summary['total_sales']); ?></span>
                    <span class="mld-stat-label">Sales (<?php echo $atts['months']; ?>mo)</span>
                </div>
                <div class="mld-summary-stat">
                    <span class="mld-stat-value"><?php echo number_format($summary['avg_dom']); ?></span>
                    <span class="mld-stat-label">Avg DOM</span>
                </div>
                <div class="mld-summary-stat">
                    <span class="mld-stat-value"><?php echo number_format($summary['avg_sp_lp_ratio'], 1); ?>%</span>
                    <span class="mld-stat-label">SP/LP Ratio</span>
                </div>
            </div>
        </div>
        <style>
            .mld-market-summary-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 15px 0; }
            .mld-summary-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #2c5aa0; padding-bottom: 12px; }
            .mld-summary-header h3 { margin: 0; color: #1e3d6f; font-size: 20px; }
            .mld-heat-tag { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; color: #fff; }
            .mld-heat-tag.mld-heat-hot { background: #dc3545; }
            .mld-heat-tag.mld-heat-warm { background: #fd7e14; }
            .mld-heat-tag.mld-heat-balanced { background: #28a745; }
            .mld-heat-tag.mld-heat-cool { background: #17a2b8; }
            .mld-heat-tag.mld-heat-cold { background: #6c757d; }
            .mld-summary-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
            .mld-summary-stat { text-align: center; padding: 10px; background: #f8f9fa; border-radius: 8px; }
            .mld-summary-stat .mld-stat-value { display: block; font-size: 22px; font-weight: bold; color: #2c5aa0; }
            .mld-summary-stat .mld-stat-label { display: block; font-size: 11px; color: #666; text-transform: uppercase; margin-top: 4px; }
            @media (max-width: 600px) { .mld-summary-stats { grid-template-columns: repeat(2, 1fr); } }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Market Heat Badge Shortcode
     *
     * Usage: [mld_market_heat city="Reading" state="MA"]
     */
    public function market_heat_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'style' => 'badge', // badge, minimal, detailed
        ), $atts, 'mld_market_heat');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        $heat = MLD_Extended_Analytics::get_market_heat_index($atts['city'], $atts['state']);

        if (empty($heat) || !isset($heat['heat_index'])) {
            return '<div class="mld-analytics-notice">Market heat data not available.</div>';
        }

        $class = strtolower($heat['classification']);

        ob_start();
        if ($atts['style'] === 'minimal') {
            ?>
            <span class="mld-heat-inline mld-heat-<?php echo esc_attr($class); ?>">
                <?php echo esc_html($heat['classification']); ?> (<?php echo number_format($heat['heat_index']); ?>)
            </span>
            <?php
        } elseif ($atts['style'] === 'detailed') {
            ?>
            <div class="mld-heat-detailed mld-heat-<?php echo esc_attr($class); ?>">
                <div class="mld-heat-score-large"><?php echo number_format($heat['heat_index']); ?></div>
                <div class="mld-heat-class"><?php echo esc_html($heat['classification']); ?> Market</div>
                <div class="mld-heat-interpretation"><?php echo esc_html($heat['interpretation']); ?></div>
            </div>
            <?php
        } else {
            ?>
            <div class="mld-heat-badge-lg mld-heat-<?php echo esc_attr($class); ?>">
                <span class="mld-heat-label"><?php echo esc_html($heat['classification']); ?> Market</span>
                <span class="mld-heat-score"><?php echo number_format($heat['heat_index']); ?>/100</span>
            </div>
            <?php
        }
        ?>
        <style>
            .mld-heat-inline { padding: 2px 8px; border-radius: 4px; font-weight: bold; color: #fff; font-size: 12px; }
            .mld-heat-badge-lg { display: inline-block; padding: 12px 24px; border-radius: 30px; color: #fff; font-weight: bold; }
            .mld-heat-badge-lg .mld-heat-score { margin-left: 10px; padding: 4px 10px; background: rgba(255,255,255,0.3); border-radius: 15px; }
            .mld-heat-detailed { padding: 20px; border-radius: 12px; color: #fff; text-align: center; }
            .mld-heat-score-large { font-size: 48px; font-weight: bold; }
            .mld-heat-class { font-size: 18px; margin: 8px 0; }
            .mld-heat-interpretation { font-size: 14px; opacity: 0.9; max-width: 400px; margin: 0 auto; }
            .mld-heat-hot { background: linear-gradient(135deg, #dc3545, #c82333); }
            .mld-heat-warm { background: linear-gradient(135deg, #fd7e14, #e06600); }
            .mld-heat-balanced { background: linear-gradient(135deg, #28a745, #1e7e34); }
            .mld-heat-cool { background: linear-gradient(135deg, #17a2b8, #117a8b); }
            .mld-heat-cold { background: linear-gradient(135deg, #6c757d, #545b62); }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Feature Premiums Shortcode
     *
     * Usage: [mld_feature_premiums city="Reading" state="MA"]
     */
    public function feature_premiums_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'property_type' => 'Residential',
            'months' => 24,
        ), $atts, 'mld_feature_premiums');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        $premiums = MLD_Extended_Analytics::get_all_feature_premiums(
            $atts['city'], $atts['state'], $atts['property_type'], intval($atts['months'])
        );

        if (empty($premiums)) {
            return '<div class="mld-analytics-notice">No feature premium data available.</div>';
        }

        ob_start();
        ?>
        <div class="mld-feature-premiums">
            <h3>Feature Value Premiums in <?php echo esc_html($atts['city']); ?></h3>
            <p class="mld-premiums-desc">Properties with these features sell for more than comparable properties without them:</p>
            <div class="mld-premiums-grid">
                <?php foreach ($premiums as $key => $premium): ?>
                    <?php if (!isset($premium['error'])): ?>
                        <div class="mld-premium-card">
                            <div class="mld-premium-value <?php echo $premium['premium_pct'] > 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $premium['premium_pct'] > 0 ? '+' : ''; ?><?php echo number_format($premium['premium_pct'], 1); ?>%
                            </div>
                            <div class="mld-premium-label"><?php echo esc_html($premium['label']); ?></div>
                            <div class="mld-premium-confidence"><?php echo esc_html($premium['confidence']); ?> confidence</div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .mld-feature-premiums { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 15px 0; }
            .mld-feature-premiums h3 { margin: 0 0 10px 0; color: #1e3d6f; }
            .mld-premiums-desc { color: #666; font-size: 14px; margin-bottom: 20px; }
            .mld-premiums-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
            .mld-premium-card { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; }
            .mld-premium-value { font-size: 28px; font-weight: bold; }
            .mld-premium-value.positive { color: #28a745; }
            .mld-premium-value.negative { color: #dc3545; }
            .mld-premium-label { font-size: 14px; font-weight: 600; color: #333; margin: 8px 0 4px; }
            .mld-premium-confidence { font-size: 11px; color: #888; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Top Agents Shortcode
     *
     * Usage: [mld_top_agents city="Reading" state="MA" limit="5"]
     */
    public function top_agents_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'limit' => 5,
            'months' => 12,
        ), $atts, 'mld_top_agents');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        $agents = MLD_Extended_Analytics::get_top_agents(
            $atts['city'], $atts['state'], intval($atts['limit']), intval($atts['months'])
        );

        if (empty($agents)) {
            return '<div class="mld-analytics-notice">No agent performance data available.</div>';
        }

        ob_start();
        ?>
        <div class="mld-top-agents">
            <h3>Top Agents in <?php echo esc_html($atts['city']); ?></h3>
            <div class="mld-agents-list">
                <?php foreach ($agents as $index => $agent): ?>
                    <div class="mld-agent-row">
                        <span class="mld-agent-rank"><?php echo $index + 1; ?></span>
                        <div class="mld-agent-info">
                            <span class="mld-agent-name"><?php echo esc_html($agent['agent_name'] ?? 'Unknown'); ?></span>
                            <span class="mld-agent-stats">
                                <?php echo number_format($agent['transaction_count'] ?? 0); ?> transactions •
                                $<?php echo number_format($agent['total_volume'] ?? 0); ?> volume
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .mld-top-agents { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 15px 0; }
            .mld-top-agents h3 { margin: 0 0 15px 0; color: #1e3d6f; }
            .mld-agents-list { display: flex; flex-direction: column; gap: 10px; }
            .mld-agent-row { display: flex; align-items: center; padding: 12px; background: #f8f9fa; border-radius: 8px; }
            .mld-agent-rank { width: 30px; height: 30px; background: #2c5aa0; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 15px; }
            .mld-agent-info { flex: 1; }
            .mld-agent-name { display: block; font-weight: 600; color: #333; }
            .mld-agent-stats { display: block; font-size: 12px; color: #666; margin-top: 2px; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Price by Bedrooms Shortcode
     *
     * Usage: [mld_price_by_bedrooms city="Reading" state="MA"]
     */
    public function price_by_bedrooms_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => '',
            'state' => '',
            'months' => 12,
        ), $atts, 'mld_price_by_bedrooms');

        if (empty($atts['city'])) {
            return '<div class="mld-analytics-error">Error: City name is required</div>';
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        $beds = MLD_Extended_Analytics::get_price_by_bedrooms($atts['city'], $atts['state'], intval($atts['months']));

        if (empty($beds)) {
            return '<div class="mld-analytics-notice">No bedroom pricing data available.</div>';
        }

        ob_start();
        ?>
        <div class="mld-price-bedrooms">
            <h3>Average Prices by Bedroom Count</h3>
            <div class="mld-beds-grid">
                <?php foreach ($beds as $bed): ?>
                    <div class="mld-bed-card">
                        <div class="mld-bed-count"><?php echo esc_html($bed['bedrooms']); ?> BR</div>
                        <div class="mld-bed-price">$<?php echo number_format($bed['avg_price']); ?></div>
                        <div class="mld-bed-stats">
                            <?php echo number_format($bed['sales_count']); ?> sales •
                            <?php echo number_format($bed['avg_dom']); ?> days avg
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .mld-price-bedrooms { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 15px 0; }
            .mld-price-bedrooms h3 { margin: 0 0 15px 0; color: #1e3d6f; }
            .mld-beds-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; }
            .mld-bed-card { text-align: center; padding: 15px; background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 8px; }
            .mld-bed-count { font-size: 18px; font-weight: bold; color: #2c5aa0; }
            .mld-bed-price { font-size: 20px; font-weight: bold; color: #1e3d6f; margin: 8px 0; }
            .mld-bed-stats { font-size: 11px; color: #666; }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Initialize shortcodes
function mld_init_analytics_shortcodes() {
    $shortcodes = new MLD_Analytics_Shortcodes();

    // Register additional shortcodes
    add_shortcode('mld_market_summary', array($shortcodes, 'market_summary_shortcode'));
    add_shortcode('mld_market_heat', array($shortcodes, 'market_heat_shortcode'));
    add_shortcode('mld_feature_premiums', array($shortcodes, 'feature_premiums_shortcode'));
    add_shortcode('mld_top_agents', array($shortcodes, 'top_agents_shortcode'));
    add_shortcode('mld_price_by_bedrooms', array($shortcodes, 'price_by_bedrooms_shortcode'));
}
add_action('init', 'mld_init_analytics_shortcodes');
