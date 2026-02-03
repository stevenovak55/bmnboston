<?php
/**
 * Enhanced Market Analytics Admin Dashboard
 * With Chart.js visualizations, market trend analysis, and extended analytics
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/admin
 * @since      5.3.0
 * @updated    6.12.0 - Added extended analytics, dynamic cities, supply/demand, comparisons, agent performance
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue Chart.js and admin styles
 */
function mld_enqueue_analytics_assets($hook) {
    if ($hook !== 'mls-listings-display_page_mld-neighborhood-analytics') {
        return;
    }

    // Chart.js from CDN
    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        array(),
        '4.4.0',
        true
    );

    // Custom admin JavaScript
    wp_enqueue_script(
        'mld-analytics-charts',
        plugins_url('js/analytics-charts.js', __FILE__),
        array('jquery', 'chartjs'),
        '6.12.0',
        true
    );

    // Admin styles
    wp_enqueue_style(
        'mld-analytics-admin',
        plugins_url('css/analytics-admin.css', __FILE__),
        array(),
        '6.12.0'
    );
}
add_action('admin_enqueue_scripts', 'mld_enqueue_analytics_assets');

/**
 * Add admin menu for analytics
 */
function mld_analytics_admin_menu() {
    add_submenu_page(
        'mls_listings_display',
        'Market Analytics',
        'Analytics',
        'manage_options',
        'mld-neighborhood-analytics',
        'mld_analytics_admin_page'
    );
}
add_action('admin_menu', 'mld_analytics_admin_menu', 20);

/**
 * Render enhanced analytics dashboard
 */
function mld_analytics_admin_page() {
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-mld-market-trends.php';
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-mld-extended-analytics.php';

    // Get available cities dynamically
    $available_cities = MLD_Extended_Analytics::get_available_cities();

    // Get selected filters from URL
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $selected_state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
    $selected_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
    $selected_property_type = isset($_GET['property_type']) ? sanitize_text_field($_GET['property_type']) : 'all';
    $selected_date_range = isset($_GET['date_range']) ? intval($_GET['date_range']) : 12;

    // Validate date range
    $valid_ranges = array(3, 6, 12, 24, 36);
    if (!in_array($selected_date_range, $valid_ranges)) {
        $selected_date_range = 12;
    }

    // Default to first available city if not specified
    if (empty($selected_city) && !empty($available_cities)) {
        $selected_city = $available_cities[0]['city'];
        $selected_state = $available_cities[0]['state'];
    } elseif (empty($selected_city)) {
        $selected_city = 'Reading';
        $selected_state = 'MA';
    }

    // Get dynamic property types based on selected city
    $available_property_types = MLD_Extended_Analytics::get_available_property_types('all', $selected_city);

    // Get date range options
    $date_range_options = MLD_Extended_Analytics::get_date_range_options();

    // Initialize trends class
    $trends = new MLD_Market_Trends();

    // Get market data using selected date range
    $market_summary = $trends->get_market_summary($selected_city, $selected_state, $selected_property_type, $selected_date_range);
    $monthly_trends = $trends->calculate_monthly_trends($selected_city, $selected_state, $selected_property_type, $selected_date_range);
    $quarterly_trends = $trends->calculate_quarterly_trends($selected_city, $selected_state, $selected_property_type, ceil($selected_date_range / 3));
    $yoy_comparison = $trends->calculate_yoy_comparison($selected_city, $selected_state, $selected_property_type);
    $appreciation = $trends->calculate_appreciation_rate($selected_city, $selected_state, $selected_property_type, $selected_date_range);

    // Get extended analytics data
    $city_summary = MLD_Extended_Analytics::get_city_summary($selected_city, $selected_state);
    $market_heat = MLD_Extended_Analytics::get_market_heat_index($selected_city, $selected_state);

    ?>
    <div class="wrap mld-analytics-dashboard">
        <h1 class="mld-dashboard-title">
            Market Analytics Dashboard
            <span class="mld-city-badge"><?php echo esc_html($selected_city); ?>, <?php echo esc_html($selected_state); ?></span>
            <?php if (!empty($market_heat) && isset($market_heat['heat_index'])): ?>
                <span class="mld-heat-badge mld-heat-<?php echo esc_attr(strtolower($market_heat['classification'])); ?>">
                    <?php echo esc_html($market_heat['classification']); ?> Market (<?php echo number_format($market_heat['heat_index']); ?>)
                </span>
            <?php endif; ?>
        </h1>

        <?php settings_errors('mld_analytics'); ?>

        <!-- City & Property Type Selector -->
        <div class="mld-filter-bar">
            <form method="get" action="" class="mld-filter-form">
                <input type="hidden" name="page" value="mld-neighborhood-analytics">
                <input type="hidden" name="tab" value="<?php echo esc_attr($selected_tab); ?>">

                <div class="mld-filter-group">
                    <label for="city-select">City:</label>
                    <select name="city" id="city-select">
                        <?php if (empty($available_cities)): ?>
                            <option value="Reading">Reading, MA</option>
                            <option value="North Reading">North Reading, MA</option>
                        <?php else: ?>
                            <?php foreach ($available_cities as $city_data): ?>
                                <option value="<?php echo esc_attr($city_data['city']); ?>"
                                        data-state="<?php echo esc_attr($city_data['state']); ?>"
                                        <?php selected($selected_city, $city_data['city']); ?>>
                                    <?php echo esc_html($city_data['city']); ?>, <?php echo esc_html($city_data['state']); ?>
                                    (<?php echo number_format($city_data['listing_count']); ?> listings)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <input type="hidden" name="state" id="state-input" value="<?php echo esc_attr($selected_state); ?>">
                </div>

                <div class="mld-filter-group">
                    <label for="property-type-select">Property Type:</label>
                    <select name="property_type" id="property-type-select">
                        <option value="all" <?php selected($selected_property_type, 'all'); ?>>All Types</option>
                        <?php foreach ($available_property_types as $type_data): ?>
                            <?php
                            $type_value = $type_data['property_type'];
                            $active_count = isset($type_data['active_count']) ? $type_data['active_count'] : $type_data['count'];
                            $archive_count = isset($type_data['archive_count']) ? $type_data['archive_count'] : 0;
                            $total_count = $active_count + $archive_count;
                            ?>
                            <option value="<?php echo esc_attr($type_value); ?>"
                                    <?php selected($selected_property_type, $type_value); ?>>
                                <?php echo esc_html($type_value); ?>
                                (<?php echo number_format($active_count); ?> active<?php echo $archive_count > 0 ? ', ' . number_format($archive_count) . ' sold' : ''; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mld-filter-group">
                    <label for="date-range-select">Date Range:</label>
                    <select name="date_range" id="date-range-select">
                        <option value="3" <?php selected($selected_date_range, 3); ?>>Last 3 Months</option>
                        <option value="6" <?php selected($selected_date_range, 6); ?>>Last 6 Months</option>
                        <option value="12" <?php selected($selected_date_range, 12); ?>>Last 12 Months</option>
                        <option value="24" <?php selected($selected_date_range, 24); ?>>Last 2 Years</option>
                        <option value="36" <?php selected($selected_date_range, 36); ?>>Last 3 Years</option>
                    </select>
                </div>

                <button type="submit" class="button button-primary">Apply Filters</button>
            </form>
        </div>

        <!-- Navigation Tabs -->
        <?php
        // Build base URL params for tabs
        $base_params = array(
            'page' => 'mld-neighborhood-analytics',
            'city' => $selected_city,
            'state' => $selected_state,
            'property_type' => $selected_property_type,
            'date_range' => $selected_date_range
        );
        $tabs = array(
            'overview' => 'Overview',
            'trends' => 'Price Trends',
            'supply' => 'Supply & Demand',
            'velocity' => 'Market Velocity',
            'comparison' => 'City Comparison',
            'agents' => 'Agent Performance',
            'yoy' => 'Year-over-Year',
            'property' => 'Property Analysis',
            'features' => 'Feature Premiums'
        );
        ?>
        <nav class="mld-tabs-nav">
            <?php foreach ($tabs as $tab_key => $tab_label): ?>
                <?php $tab_params = array_merge($base_params, array('tab' => $tab_key)); ?>
                <a href="?<?php echo http_build_query($tab_params); ?>"
                   class="mld-tab <?php echo $selected_tab === $tab_key ? 'active' : ''; ?>">
                    <?php echo esc_html($tab_label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Tab Content -->
        <div class="mld-tab-content">
            <?php
            switch ($selected_tab) {
                case 'overview':
                    mld_render_overview_tab($market_summary, $monthly_trends, $appreciation, $city_summary, $market_heat, $selected_date_range);
                    break;
                case 'trends':
                    mld_render_trends_tab($monthly_trends, $quarterly_trends, $selected_date_range);
                    break;
                case 'supply':
                    mld_render_supply_demand_tab($selected_city, $selected_state, $city_summary, $market_heat, $selected_property_type);
                    break;
                case 'velocity':
                    mld_render_velocity_tab($monthly_trends, $market_summary, $selected_date_range);
                    break;
                case 'comparison':
                    mld_render_comparison_tab($selected_city, $selected_state, $available_cities, $selected_property_type);
                    break;
                case 'agents':
                    mld_render_agents_tab($selected_city, $selected_state, $selected_date_range);
                    break;
                case 'yoy':
                    mld_render_yoy_tab($yoy_comparison);
                    break;
                case 'property':
                    mld_render_property_analysis_tab($selected_city, $selected_state, $selected_property_type, $selected_date_range);
                    break;
                case 'features':
                    mld_render_feature_premiums_tab($selected_city, $selected_state, $selected_property_type, $selected_date_range);
                    break;
            }
            ?>
        </div>
    </div>

    <script>
    // Update state input when city changes
    document.getElementById('city-select').addEventListener('change', function() {
        var selectedOption = this.options[this.selectedIndex];
        document.getElementById('state-input').value = selectedOption.dataset.state || 'MA';
    });
    </script>

    <style>
        .mld-analytics-dashboard {
            margin: 20px 20px 20px 0;
        }

        .mld-dashboard-title {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .mld-city-badge {
            background: #2c5aa0;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: normal;
        }

        .mld-heat-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .mld-heat-hot {
            background: #dc3545;
            color: white;
        }

        .mld-heat-warm {
            background: #fd7e14;
            color: white;
        }

        .mld-heat-balanced {
            background: #28a745;
            color: white;
        }

        .mld-heat-cool {
            background: #17a2b8;
            color: white;
        }

        .mld-heat-cold {
            background: #6c757d;
            color: white;
        }

        .mld-filter-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }

        .mld-filter-form {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .mld-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mld-filter-group label {
            font-weight: 500;
            color: #333;
        }

        .mld-filter-group select {
            min-width: 200px;
            padding: 6px 10px;
        }

        .mld-tabs-nav {
            background: white;
            border-bottom: 2px solid #2c5aa0;
            margin-bottom: 20px;
            display: flex;
            gap: 0;
            flex-wrap: wrap;
        }

        .mld-tab {
            padding: 15px 20px;
            text-decoration: none;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 14px;
        }

        .mld-tab:hover {
            color: #2c5aa0;
            background: #f8f9fa;
        }

        .mld-tab.active {
            color: #2c5aa0;
            border-bottom-color: #2c5aa0;
            background: #f8f9fa;
        }

        .mld-stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .mld-stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #2c5aa0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .mld-stat-card.positive {
            border-left-color: #28a745;
        }

        .mld-stat-card.negative {
            border-left-color: #dc3545;
        }

        .mld-stat-card.highlight {
            border-left-color: #fd7e14;
            background: linear-gradient(135deg, #fff 0%, #fff8f0 100%);
        }

        .mld-stat-label {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .mld-stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #1e3d6f;
            margin-bottom: 5px;
        }

        .mld-stat-change {
            font-size: 14px;
            font-weight: 500;
        }

        .mld-stat-change.positive {
            color: #28a745;
        }

        .mld-stat-change.negative {
            color: #dc3545;
        }

        .mld-chart-container {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .mld-chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3d6f;
            margin-bottom: 20px;
        }

        .mld-chart-canvas {
            max-height: 400px;
        }

        .mld-data-table {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .mld-data-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .mld-data-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }

        .mld-data-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .mld-data-table tr:hover {
            background: #f8f9fa;
        }

        .mld-market-health {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .mld-health-score {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .mld-health-gauge {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
            color: white;
        }

        .mld-health-details {
            flex: 1;
        }

        .mld-health-factors {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .mld-health-factor {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
        }

        .mld-health-factor-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }

        .mld-health-factor-value {
            font-size: 18px;
            font-weight: 600;
            color: #1e3d6f;
        }

        .mld-comparison-selector {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }

        .mld-comparison-cities {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .mld-city-chip {
            background: #e9ecef;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .mld-city-chip:hover {
            background: #2c5aa0;
            color: white;
        }

        .mld-city-chip.selected {
            background: #2c5aa0;
            color: white;
        }

        .mld-agents-table {
            margin-top: 20px;
        }

        .mld-agents-table .rank {
            font-weight: bold;
            color: #2c5aa0;
            width: 50px;
        }

        .mld-agents-table .agent-name {
            font-weight: 600;
        }

        .mld-agents-table .volume {
            color: #28a745;
            font-weight: 600;
        }

        .mld-two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 1200px) {
            .mld-two-column {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <?php
}

/**
 * Render Overview Tab
 */
function mld_render_overview_tab($market_summary, $monthly_trends, $appreciation, $city_summary, $market_heat, $date_range = 12) {
    if (isset($market_summary['error'])) {
        echo '<div class="notice notice-error"><p>' . esc_html($market_summary['error']) . '</p></div>';
        return;
    }

    // Calculate trends
    $latest_month = end($monthly_trends);
    $previous_month = $monthly_trends[count($monthly_trends) - 2] ?? null;
    ?>

    <!-- Market Health Score Card -->
    <?php if (!empty($market_heat) && isset($market_heat['heat_index'])): ?>
    <div class="mld-market-health">
        <h2 class="mld-chart-title">Market Health Score</h2>
        <div class="mld-health-score">
            <div class="mld-health-gauge" style="background: <?php echo mld_get_heat_color($market_heat['heat_index']); ?>">
                <?php echo number_format($market_heat['heat_index']); ?>
            </div>
            <div class="mld-health-details">
                <h3 style="margin: 0 0 10px 0; font-size: 24px;">
                    <?php echo esc_html($market_heat['classification']); ?> Market
                </h3>
                <p style="margin: 0; color: #666;">
                    <?php echo esc_html($market_heat['interpretation']); ?>
                </p>
                <div class="mld-health-factors">
                    <?php if (isset($market_heat['components'])): ?>
                        <div class="mld-health-factor">
                            <div class="mld-health-factor-label">Days on Market</div>
                            <div class="mld-health-factor-value"><?php echo number_format($market_heat['components']['avg_dom'] ?? 0); ?> days</div>
                        </div>
                        <div class="mld-health-factor">
                            <div class="mld-health-factor-label">Sale-to-List Ratio</div>
                            <div class="mld-health-factor-value"><?php echo number_format($market_heat['components']['sp_lp_ratio'] ?? 0, 1); ?>%</div>
                        </div>
                        <div class="mld-health-factor">
                            <div class="mld-health-factor-label">Months of Supply</div>
                            <div class="mld-health-factor-value"><?php echo number_format($market_heat['components']['months_supply'] ?? 0, 1); ?></div>
                        </div>
                        <div class="mld-health-factor">
                            <div class="mld-health-factor-label">Absorption Rate</div>
                            <div class="mld-health-factor-value"><?php echo number_format($market_heat['components']['absorption_rate'] ?? 0, 1); ?>%</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Key Statistics Cards -->
    <div class="mld-stat-cards">
        <div class="mld-stat-card">
            <div class="mld-stat-label">Average Sale Price (12mo)</div>
            <div class="mld-stat-value">$<?php echo number_format($market_summary['avg_close_price'], 0); ?></div>
            <?php if ($appreciation && !isset($appreciation['error'])): ?>
                <div class="mld-stat-change <?php echo $appreciation['total_change_pct'] > 0 ? 'positive' : 'negative'; ?>">
                    <?php echo $appreciation['total_change_pct'] > 0 ? '+' : ''; ?><?php echo abs($appreciation['total_change_pct']); ?>% YoY
                </div>
            <?php endif; ?>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Total Sales (12mo)</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['total_sales']); ?></div>
            <div class="mld-stat-change">
                <?php echo number_format($market_summary['monthly_sales_velocity'], 1); ?> per month
            </div>
        </div>

        <div class="mld-stat-card <?php echo $market_summary['avg_dom'] < 60 ? 'positive' : ''; ?>">
            <div class="mld-stat-label">Average Days on Market</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['avg_dom'], 0); ?></div>
            <div class="mld-stat-change">
                <?php echo $market_summary['avg_dom'] < 60 ? 'Fast-moving market' : 'Normal velocity'; ?>
            </div>
        </div>

        <div class="mld-stat-card <?php echo $market_summary['avg_sp_lp_ratio'] > 100 ? 'positive' : ''; ?>">
            <div class="mld-stat-label">Sale-to-List Ratio</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['avg_sp_lp_ratio'], 1); ?>%</div>
            <div class="mld-stat-change">
                <?php echo $market_summary['avg_sp_lp_ratio'] > 100 ? "Seller's market" : "Balanced market"; ?>
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Price per Sq Ft</div>
            <div class="mld-stat-value">$<?php echo number_format($market_summary['avg_price_per_sqft'], 0); ?></div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Total Sales Volume</div>
            <div class="mld-stat-value">$<?php echo number_format($market_summary['total_volume'] / 1000000, 1); ?>M</div>
        </div>
    </div>

    <!-- Monthly Price Trend Chart -->
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Monthly Price Trends (Last 12 Months)</h2>
        <canvas id="monthly-price-chart" class="mld-chart-canvas"></canvas>
    </div>

    <!-- Sales Volume Chart -->
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Monthly Sales Volume</h2>
        <canvas id="monthly-volume-chart" class="mld-chart-canvas"></canvas>
    </div>

    <script>
    // Prepare data for charts
    const monthlyData = <?php echo json_encode($monthly_trends); ?>;

    // Monthly Price Chart
    const priceCtx = document.getElementById('monthly-price-chart').getContext('2d');
    new Chart(priceCtx, {
        type: 'line',
        data: {
            labels: monthlyData.map(d => d.month_name),
            datasets: [{
                label: 'Average Close Price',
                data: monthlyData.map(d => d.avg_close_price),
                borderColor: '#2c5aa0',
                backgroundColor: 'rgba(44, 90, 160, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });

    // Monthly Volume Chart
    const volumeCtx = document.getElementById('monthly-volume-chart').getContext('2d');
    new Chart(volumeCtx, {
        type: 'bar',
        data: {
            labels: monthlyData.map(d => d.month_name),
            datasets: [{
                label: 'Number of Sales',
                data: monthlyData.map(d => d.sales_count),
                backgroundColor: '#28a745',
                borderColor: '#218838',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 5
                    }
                }
            }
        }
    });
    </script>
    <?php
}

/**
 * Get heat index color
 */
function mld_get_heat_color($heat_index) {
    if ($heat_index >= 80) return '#dc3545'; // Hot - Red
    if ($heat_index >= 60) return '#fd7e14'; // Warm - Orange
    if ($heat_index >= 40) return '#28a745'; // Balanced - Green
    if ($heat_index >= 20) return '#17a2b8'; // Cool - Teal
    return '#6c757d'; // Cold - Gray
}

/**
 * Render Price Trends Tab
 */
function mld_render_trends_tab($monthly_trends, $quarterly_trends, $date_range = 12) {
    ?>
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Quarterly Price Trends</h2>
        <canvas id="quarterly-price-chart" class="mld-chart-canvas"></canvas>
    </div>

    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Price per Square Foot Trends</h2>
        <canvas id="price-per-sqft-chart" class="mld-chart-canvas"></canvas>
    </div>

    <!-- Monthly Data Table -->
    <div class="mld-data-table">
        <h2 class="mld-chart-title">Monthly Breakdown</h2>
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Sales</th>
                    <th>Avg Price</th>
                    <th>$/SqFt</th>
                    <th>Avg DOM</th>
                    <th>SP/LP Ratio</th>
                    <th>MoM Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($monthly_trends) as $month): ?>
                <tr>
                    <td><?php echo esc_html($month['month_name']); ?></td>
                    <td><?php echo number_format($month['sales_count']); ?></td>
                    <td>$<?php echo number_format($month['avg_close_price']); ?></td>
                    <td>$<?php echo number_format($month['avg_price_per_sqft']); ?></td>
                    <td><?php echo number_format($month['avg_dom'], 1); ?></td>
                    <td><?php echo number_format($month['avg_sp_lp_ratio'], 1); ?>%</td>
                    <td style="color: <?php echo $month['mom_price_change_pct'] > 0 ? '#28a745' : '#dc3545'; ?>">
                        <?php echo $month['mom_price_change_pct'] > 0 ? '+' : ''; ?><?php echo number_format($month['mom_price_change_pct'], 1); ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    const monthlyData = <?php echo json_encode($monthly_trends); ?>;
    const quarterlyData = <?php echo json_encode($quarterly_trends); ?>;

    // Quarterly Price Chart
    const qtrCtx = document.getElementById('quarterly-price-chart').getContext('2d');
    new Chart(qtrCtx, {
        type: 'line',
        data: {
            labels: quarterlyData.map(d => d.quarter_name),
            datasets: [{
                label: 'Average Close Price',
                data: quarterlyData.map(d => d.avg_close_price),
                borderColor: '#2c5aa0',
                backgroundColor: 'rgba(44, 90, 160, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });

    // Price per SqFt Chart
    const sqftCtx = document.getElementById('price-per-sqft-chart').getContext('2d');
    new Chart(sqftCtx, {
        type: 'line',
        data: {
            labels: monthlyData.map(d => d.month_name),
            datasets: [{
                label: 'Price per Sq Ft',
                data: monthlyData.map(d => d.avg_price_per_sqft),
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toFixed(2) + '/sqft';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toFixed(0);
                        }
                    }
                }
            }
        }
    });
    </script>
    <?php
}

/**
 * Render Supply & Demand Tab
 */
function mld_render_supply_demand_tab($city, $state, $city_summary, $market_heat, $property_type = 'all') {
    // Get supply/demand metrics
    $supply_demand = MLD_Extended_Analytics::get_supply_demand_metrics($city, $state, $property_type);
    ?>

    <!-- Supply/Demand Overview -->
    <div class="mld-stat-cards">
        <div class="mld-stat-card highlight">
            <div class="mld-stat-label">Active Listings</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['active_count'] ?? 0); ?></div>
            <div class="mld-stat-change">Current inventory</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Pending Sales</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['pending_count'] ?? 0); ?></div>
            <div class="mld-stat-change">Under contract</div>
        </div>

        <div class="mld-stat-card <?php echo ($supply_demand['months_supply'] ?? 6) < 4 ? 'positive' : (($supply_demand['months_supply'] ?? 6) > 6 ? 'negative' : ''); ?>">
            <div class="mld-stat-label">Months of Supply</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['months_supply'] ?? 0, 1); ?></div>
            <div class="mld-stat-change">
                <?php
                $mos = $supply_demand['months_supply'] ?? 6;
                if ($mos < 4) echo "Seller's market";
                elseif ($mos <= 6) echo "Balanced market";
                else echo "Buyer's market";
                ?>
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Absorption Rate</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['absorption_rate'] ?? 0, 1); ?>%</div>
            <div class="mld-stat-change">Monthly turnover</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">New This Month</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['new_listings_month'] ?? 0); ?></div>
            <div class="mld-stat-change">New listings</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Sold This Month</div>
            <div class="mld-stat-value"><?php echo number_format($supply_demand['sold_month'] ?? 0); ?></div>
            <div class="mld-stat-change">Closings</div>
        </div>
    </div>

    <!-- Supply vs Demand Chart -->
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Supply vs Demand Trend</h2>
        <canvas id="supply-demand-chart" class="mld-chart-canvas"></canvas>
    </div>

    <!-- Market Balance Indicator -->
    <div class="mld-market-health">
        <h2 class="mld-chart-title">Market Balance Analysis</h2>
        <div style="display: flex; align-items: center; gap: 40px;">
            <div style="flex: 1;">
                <div style="background: #e9ecef; height: 30px; border-radius: 15px; position: relative; overflow: hidden;">
                    <?php
                    $balance = min(100, max(0, ($supply_demand['months_supply'] ?? 6) / 12 * 100));
                    $balance_color = $balance < 33 ? '#dc3545' : ($balance > 66 ? '#28a745' : '#ffc107');
                    ?>
                    <div style="background: <?php echo $balance_color; ?>; height: 100%; width: <?php echo $balance; ?>%; transition: width 0.5s;"></div>
                    <div style="position: absolute; left: 33%; top: 0; bottom: 0; border-left: 2px dashed #666;"></div>
                    <div style="position: absolute; left: 66%; top: 0; bottom: 0; border-left: 2px dashed #666;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 12px; color: #666;">
                    <span>Seller's Market</span>
                    <span>Balanced</span>
                    <span>Buyer's Market</span>
                </div>
            </div>
            <div style="text-align: center; min-width: 150px;">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Market Status</div>
                <div style="font-size: 24px; font-weight: bold; color: <?php echo $balance_color; ?>;">
                    <?php
                    if ($balance < 33) echo "HOT";
                    elseif ($balance > 66) echo "COOL";
                    else echo "BALANCED";
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Supply vs Demand mock data (would come from monthly stats)
    const supplyDemandData = {
        labels: ['6 months ago', '5 months ago', '4 months ago', '3 months ago', '2 months ago', 'Last month'],
        supply: [<?php echo $supply_demand['active_count'] ?? 50; ?>, <?php echo ($supply_demand['active_count'] ?? 50) * 1.1; ?>, <?php echo ($supply_demand['active_count'] ?? 50) * 0.95; ?>, <?php echo ($supply_demand['active_count'] ?? 50) * 1.05; ?>, <?php echo ($supply_demand['active_count'] ?? 50) * 0.9; ?>, <?php echo $supply_demand['active_count'] ?? 50; ?>],
        demand: [<?php echo ($supply_demand['sold_month'] ?? 10) * 0.8; ?>, <?php echo ($supply_demand['sold_month'] ?? 10) * 0.9; ?>, <?php echo ($supply_demand['sold_month'] ?? 10) * 1.1; ?>, <?php echo $supply_demand['sold_month'] ?? 10; ?>, <?php echo ($supply_demand['sold_month'] ?? 10) * 1.2; ?>, <?php echo $supply_demand['sold_month'] ?? 10; ?>]
    };

    const sdCtx = document.getElementById('supply-demand-chart').getContext('2d');
    new Chart(sdCtx, {
        type: 'line',
        data: {
            labels: supplyDemandData.labels,
            datasets: [{
                label: 'Active Listings (Supply)',
                data: supplyDemandData.supply,
                borderColor: '#2c5aa0',
                backgroundColor: 'rgba(44, 90, 160, 0.1)',
                borderWidth: 2,
                fill: true,
                yAxisID: 'y'
            }, {
                label: 'Monthly Sales (Demand)',
                data: supplyDemandData.demand,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 2,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Active Listings'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Monthly Sales'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
    </script>
    <?php
}

/**
 * Render Market Velocity Tab
 */
function mld_render_velocity_tab($monthly_trends, $market_summary, $date_range = 12) {
    ?>
    <div class="mld-stat-cards">
        <div class="mld-stat-card">
            <div class="mld-stat-label">Monthly Sales Velocity</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['monthly_sales_velocity'], 1); ?></div>
            <div class="mld-stat-change">Sales per month</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Average Days on Market</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['avg_dom'], 0); ?></div>
            <div class="mld-stat-change">Days to sale</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Sale-to-List Ratio</div>
            <div class="mld-stat-value"><?php echo number_format($market_summary['avg_sp_lp_ratio'], 1); ?>%</div>
            <div class="mld-stat-change">
                <?php echo $market_summary['avg_sp_lp_ratio'] > 100 ? 'Above asking' : 'At/below asking'; ?>
            </div>
        </div>
    </div>

    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Days on Market Trend</h2>
        <canvas id="dom-trend-chart" class="mld-chart-canvas"></canvas>
    </div>

    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Sale-to-List Ratio Trend</h2>
        <canvas id="splp-trend-chart" class="mld-chart-canvas"></canvas>
    </div>

    <script>
    const monthlyData = <?php echo json_encode($monthly_trends); ?>;

    // DOM Trend Chart
    const domCtx = document.getElementById('dom-trend-chart').getContext('2d');
    new Chart(domCtx, {
        type: 'line',
        data: {
            labels: monthlyData.map(d => d.month_name),
            datasets: [{
                label: 'Average Days on Market',
                data: monthlyData.map(d => d.avg_dom),
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Days'
                    }
                }
            }
        }
    });

    // SP/LP Ratio Chart
    const splpCtx = document.getElementById('splp-trend-chart').getContext('2d');
    new Chart(splpCtx, {
        type: 'line',
        data: {
            labels: monthlyData.map(d => d.month_name),
            datasets: [{
                label: 'Sale-to-List Ratio',
                data: monthlyData.map(d => d.avg_sp_lp_ratio),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    min: 95,
                    max: 110,
                    title: {
                        display: true,
                        text: 'Percentage'
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
    </script>
    <?php
}

/**
 * Render City Comparison Tab
 */
function mld_render_comparison_tab($selected_city, $selected_state, $available_cities, $property_type = 'all') {
    // Get comparison cities from query string or default to top 5
    $compare_cities = isset($_GET['compare']) ? array_map('sanitize_text_field', (array)$_GET['compare']) : array();

    // Ensure selected city is included
    if (!in_array($selected_city, $compare_cities)) {
        array_unshift($compare_cities, $selected_city);
    }

    // Limit to 5 cities
    $compare_cities = array_slice($compare_cities, 0, 5);

    // Get comparison data
    $comparison_data = array();
    foreach ($compare_cities as $city) {
        $city_state = $selected_state; // For simplicity, use same state
        foreach ($available_cities as $ac) {
            if ($ac['city'] === $city) {
                $city_state = $ac['state'];
                break;
            }
        }
        $comparison_data[] = MLD_Extended_Analytics::get_city_summary($city, $city_state, $property_type);
    }
    ?>

    <!-- City Selector -->
    <div class="mld-comparison-selector">
        <h3 style="margin: 0 0 10px 0;">Compare Cities (select up to 5)</h3>
        <form method="get" action="">
            <input type="hidden" name="page" value="mld-neighborhood-analytics">
            <input type="hidden" name="tab" value="comparison">
            <input type="hidden" name="city" value="<?php echo esc_attr($selected_city); ?>">
            <input type="hidden" name="state" value="<?php echo esc_attr($selected_state); ?>">

            <div class="mld-comparison-cities">
                <?php foreach (array_slice($available_cities, 0, 15) as $city_data): ?>
                    <label class="mld-city-chip <?php echo in_array($city_data['city'], $compare_cities) ? 'selected' : ''; ?>">
                        <input type="checkbox" name="compare[]" value="<?php echo esc_attr($city_data['city']); ?>"
                               <?php checked(in_array($city_data['city'], $compare_cities)); ?>
                               style="display: none;">
                        <?php echo esc_html($city_data['city']); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary" style="margin-top: 15px;">Update Comparison</button>
        </form>
    </div>

    <!-- Comparison Table -->
    <div class="mld-data-table">
        <h2 class="mld-chart-title">City Comparison</h2>
        <table>
            <thead>
                <tr>
                    <th>City</th>
                    <th>Median Price</th>
                    <th>Avg DOM</th>
                    <th>SP/LP Ratio</th>
                    <th>Active Listings</th>
                    <th>Months Supply</th>
                    <th>YoY Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comparison_data as $city_data): ?>
                    <?php if (!empty($city_data) && !isset($city_data['error'])): ?>
                    <tr>
                        <td><strong><?php echo esc_html($city_data['city'] ?? 'N/A'); ?>, <?php echo esc_html($city_data['state'] ?? ''); ?></strong></td>
                        <td>$<?php echo number_format($city_data['median_list_price'] ?? 0); ?></td>
                        <td><?php echo number_format($city_data['avg_dom_12m'] ?? 0); ?> days</td>
                        <td><?php echo number_format($city_data['avg_sp_lp_12m'] ?? 0, 1); ?>%</td>
                        <td><?php echo number_format($city_data['active_count'] ?? 0); ?></td>
                        <td><?php echo number_format($city_data['months_of_supply'] ?? 0, 1); ?></td>
                        <td style="color: <?php echo ($city_data['yoy_price_change_pct'] ?? 0) > 0 ? '#28a745' : '#dc3545'; ?>">
                            <?php echo ($city_data['yoy_price_change_pct'] ?? 0) > 0 ? '+' : ''; ?><?php echo number_format($city_data['yoy_price_change_pct'] ?? 0, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Comparison Chart -->
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Price Comparison</h2>
        <canvas id="comparison-chart" class="mld-chart-canvas"></canvas>
    </div>

    <script>
    const comparisonData = <?php echo json_encode($comparison_data); ?>;

    const compCtx = document.getElementById('comparison-chart').getContext('2d');
    new Chart(compCtx, {
        type: 'bar',
        data: {
            labels: comparisonData.map(d => d.city || 'Unknown'),
            datasets: [{
                label: 'Median List Price',
                data: comparisonData.map(d => d.median_list_price || 0),
                backgroundColor: ['#2c5aa0', '#28a745', '#ffc107', '#dc3545', '#17a2b8'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });
    </script>
    <?php
}

/**
 * Render Agent Performance Tab
 */
function mld_render_agents_tab($city, $state, $date_range = 12) {
    $top_agents = MLD_Extended_Analytics::get_top_agents($city, $state, 20, $date_range);
    $top_offices = MLD_Extended_Analytics::get_top_offices($city, $state, 10, $date_range);

    // Format date range for display
    $date_label = $date_range . ' month' . ($date_range > 1 ? 's' : '');
    if ($date_range == 24) $date_label = '2 years';
    if ($date_range == 36) $date_label = '3 years';
    ?>

    <div class="mld-two-column">
        <!-- Top Agents -->
        <div class="mld-data-table mld-agents-table">
            <h2 class="mld-chart-title">Top 20 Agents by Volume (<?php echo esc_html($date_label); ?>)</h2>
            <?php if (empty($top_agents)): ?>
                <p style="color: #666; padding: 20px;">No agent data available for this city. Agent performance data will populate after the daily analytics job runs.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="rank">#</th>
                        <th>Agent</th>
                        <th>Transactions</th>
                        <th>Volume</th>
                        <th>Avg Price</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_agents as $rank => $agent): ?>
                    <tr>
                        <td class="rank"><?php echo $rank + 1; ?></td>
                        <td class="agent-name"><?php echo esc_html($agent['agent_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo number_format($agent['transaction_count'] ?? 0); ?></td>
                        <td class="volume">$<?php echo number_format(($agent['total_volume'] ?? 0) / 1000000, 2); ?>M</td>
                        <td>$<?php echo number_format($agent['avg_sale_price'] ?? 0); ?></td>
                        <td><?php echo number_format($agent['market_share_pct'] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Top Offices -->
        <div class="mld-data-table mld-agents-table">
            <h2 class="mld-chart-title">Top 10 Offices by Volume (<?php echo esc_html($date_label); ?>)</h2>
            <?php if (empty($top_offices)): ?>
                <p style="color: #666; padding: 20px;">No office data available for this city. Office performance data will populate after the daily analytics job runs.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="rank">#</th>
                        <th>Office</th>
                        <th>Transactions</th>
                        <th>Volume</th>
                        <th>Avg Price</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_offices as $rank => $office): ?>
                    <tr>
                        <td class="rank"><?php echo $rank + 1; ?></td>
                        <td class="agent-name"><?php echo esc_html($office['office_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo number_format($office['transaction_count'] ?? 0); ?></td>
                        <td class="volume">$<?php echo number_format(($office['total_volume'] ?? 0) / 1000000, 2); ?>M</td>
                        <td>$<?php echo number_format($office['avg_sale_price'] ?? 0); ?></td>
                        <td><?php echo number_format($office['market_share_pct'] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($top_agents)): ?>
    <!-- Market Share Chart -->
    <div class="mld-chart-container">
        <h2 class="mld-chart-title">Top 10 Agent Market Share</h2>
        <canvas id="market-share-chart" class="mld-chart-canvas" style="max-height: 300px;"></canvas>
    </div>

    <script>
    const topAgents = <?php echo json_encode(array_slice($top_agents, 0, 10)); ?>;

    const shareCtx = document.getElementById('market-share-chart').getContext('2d');
    new Chart(shareCtx, {
        type: 'doughnut',
        data: {
            labels: topAgents.map(a => a.agent_name || 'Unknown'),
            datasets: [{
                data: topAgents.map(a => a.market_share_pct || 0),
                backgroundColor: [
                    '#2c5aa0', '#28a745', '#ffc107', '#dc3545', '#17a2b8',
                    '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.toFixed(1) + '%';
                        }
                    }
                }
            }
        }
    });
    </script>
    <?php endif; ?>
    <?php
}

/**
 * Render Year-over-Year Tab
 */
function mld_render_yoy_tab($yoy_comparison) {
    if (isset($yoy_comparison['error'])) {
        echo '<div class="notice notice-info"><p>' . esc_html($yoy_comparison['error']) . '</p></div>';
        return;
    }

    $latest_yoy = $yoy_comparison[0] ?? null;
    if (!$latest_yoy) {
        echo '<div class="notice notice-info"><p>Not enough historical data for year-over-year comparison.</p></div>';
        return;
    }
    ?>

    <div class="mld-stat-cards">
        <div class="mld-stat-card <?php echo $latest_yoy['price_change_pct'] > 0 ? 'positive' : 'negative'; ?>">
            <div class="mld-stat-label">Price Change (YoY)</div>
            <div class="mld-stat-value">
                <?php echo $latest_yoy['price_change_pct'] > 0 ? '+' : ''; ?><?php echo number_format($latest_yoy['price_change_pct'], 1); ?>%
            </div>
            <div class="mld-stat-change">
                <?php echo number_format($latest_yoy['current_year']); ?> vs <?php echo number_format($latest_yoy['previous_year']); ?>
            </div>
        </div>

        <div class="mld-stat-card <?php echo $latest_yoy['volume_change_pct'] > 0 ? 'positive' : 'negative'; ?>">
            <div class="mld-stat-label">Sales Volume Change (YoY)</div>
            <div class="mld-stat-value">
                <?php echo $latest_yoy['volume_change_pct'] > 0 ? '+' : ''; ?><?php echo number_format($latest_yoy['volume_change_pct'], 1); ?>%
            </div>
            <div class="mld-stat-change">
                <?php echo number_format($latest_yoy['volume_change']); ?> more sales
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Current Year Avg Price</div>
            <div class="mld-stat-value">$<?php echo number_format($latest_yoy['current_avg_price']); ?></div>
            <div class="mld-stat-change">vs $<?php echo number_format($latest_yoy['previous_avg_price']); ?> last year</div>
        </div>
    </div>

    <div class="mld-data-table">
        <h2 class="mld-chart-title">Year-over-Year Comparison</h2>
        <table>
            <thead>
                <tr>
                    <th>Comparison</th>
                    <th>Current Year</th>
                    <th>Previous Year</th>
                    <th>Change</th>
                    <th>% Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($yoy_comparison as $yoy): ?>
                <tr>
                    <td><strong><?php echo $yoy['current_year']; ?> vs <?php echo $yoy['previous_year']; ?></strong></td>
                    <td>$<?php echo number_format($yoy['current_avg_price']); ?></td>
                    <td>$<?php echo number_format($yoy['previous_avg_price']); ?></td>
                    <td style="color: <?php echo $yoy['price_change'] > 0 ? '#28a745' : '#dc3545'; ?>">
                        <?php echo $yoy['price_change'] > 0 ? '+' : ''; ?>$<?php echo number_format(abs($yoy['price_change'])); ?>
                    </td>
                    <td style="color: <?php echo $yoy['price_change_pct'] > 0 ? '#28a745' : '#dc3545'; ?>">
                        <?php echo $yoy['price_change_pct'] > 0 ? '+' : ''; ?><?php echo number_format($yoy['price_change_pct'], 1); ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Render Property Analysis Tab - Comprehensive metrics including DOM distribution,
 * price by bedrooms, property characteristics
 */
function mld_render_property_analysis_tab($city, $state, $property_type, $months = 12) {
    // Get comprehensive property data using selected date range
    $price_analysis = MLD_Extended_Analytics::get_price_analysis($city, $state, $property_type, $months);
    $dom_analysis = MLD_Extended_Analytics::get_dom_analysis($city, $state, $property_type, $months);
    $price_by_beds = MLD_Extended_Analytics::get_price_by_bedrooms($city, $state, $months);
    $characteristics = MLD_Extended_Analytics::get_property_characteristics($city, $state, $months);
    $property_types = MLD_Extended_Analytics::get_property_type_performance($city, $state, $months);

    // Format date range label
    $date_label = $months . ' Month' . ($months > 1 ? 's' : '');
    if ($months == 24) $date_label = '2 Years';
    if ($months == 36) $date_label = '3 Years';
    ?>

    <!-- Price Analysis Section -->
    <h2 class="mld-chart-title" style="margin-top: 0;">Price Analysis (Last <?php echo esc_html($date_label); ?>)</h2>
    <?php if (!isset($price_analysis['error'])): ?>
    <div class="mld-stat-cards">
        <div class="mld-stat-card">
            <div class="mld-stat-label">Total Sales</div>
            <div class="mld-stat-value"><?php echo number_format($price_analysis['total_sales']); ?></div>
            <div class="mld-stat-change">$<?php echo number_format($price_analysis['total_volume'] / 1000000, 1); ?>M volume</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Sale Price</div>
            <div class="mld-stat-value">$<?php echo number_format($price_analysis['avg_sale_price']); ?></div>
            <div class="mld-stat-change">$<?php echo number_format($price_analysis['avg_price_per_sqft'], 2); ?>/sqft</div>
        </div>

        <div class="mld-stat-card <?php echo ($price_analysis['avg_sp_lp_ratio'] ?? 0) >= 100 ? 'positive' : ''; ?>">
            <div class="mld-stat-label">Sale-to-List Ratio</div>
            <div class="mld-stat-value"><?php echo number_format($price_analysis['avg_sp_lp_ratio'], 1); ?>%</div>
            <div class="mld-stat-change"><?php echo ($price_analysis['avg_sp_lp_ratio'] ?? 0) >= 100 ? 'At/Above asking' : 'Below asking'; ?></div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Sale-to-Original Price</div>
            <div class="mld-stat-value"><?php echo number_format($price_analysis['avg_sp_olp_ratio'], 1); ?>%</div>
            <div class="mld-stat-change">After price reductions</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Price Reductions</div>
            <div class="mld-stat-value"><?php echo number_format($price_analysis['price_reduction_rate'], 1); ?>%</div>
            <div class="mld-stat-change"><?php echo number_format($price_analysis['listings_with_reduction']); ?> of <?php echo number_format($price_analysis['total_sales']); ?> had reductions</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Reduction</div>
            <div class="mld-stat-value"><?php echo number_format($price_analysis['avg_reduction_pct'] ?? 0, 1); ?>%</div>
            <div class="mld-stat-change">For reduced listings</div>
        </div>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No price analysis data available for this location.</p></div>
    <?php endif; ?>

    <!-- DOM Analysis Section -->
    <h2 class="mld-chart-title">Days on Market Distribution</h2>
    <?php if (!isset($dom_analysis['error'])): ?>
    <div class="mld-two-column">
        <div class="mld-stat-cards" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div class="mld-stat-card">
                <div class="mld-stat-label">Average DOM</div>
                <div class="mld-stat-value"><?php echo number_format($dom_analysis['avg_dom']); ?></div>
                <div class="mld-stat-change"><?php echo esc_html($dom_analysis['market_speed']); ?> market</div>
            </div>
            <div class="mld-stat-card">
                <div class="mld-stat-label">Minimum DOM</div>
                <div class="mld-stat-value"><?php echo number_format($dom_analysis['min_dom']); ?></div>
                <div class="mld-stat-change">Fastest sale</div>
            </div>
            <div class="mld-stat-card">
                <div class="mld-stat-label">Maximum DOM</div>
                <div class="mld-stat-value"><?php echo number_format($dom_analysis['max_dom']); ?></div>
                <div class="mld-stat-change">Longest sale</div>
            </div>
        </div>

        <div class="mld-chart-container" style="margin-bottom: 0;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px;">DOM Distribution</h3>
            <canvas id="dom-distribution-chart" class="mld-chart-canvas" style="max-height: 250px;"></canvas>
        </div>
    </div>

    <div class="mld-data-table" style="margin-top: 20px;">
        <h3 class="mld-chart-title" style="font-size: 16px;">DOM Breakdown</h3>
        <table>
            <thead>
                <tr>
                    <th>Time to Sale</th>
                    <th>Number</th>
                    <th>Percentage</th>
                    <th>Visual</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Under 7 days</td>
                    <td><?php echo number_format($dom_analysis['sold_under_7_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_under_7_days'], 1); ?>%</td>
                    <td><div style="background: #28a745; height: 20px; width: <?php echo min(100, $dom_analysis['pct_under_7_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
                <tr>
                    <td>7-14 days</td>
                    <td><?php echo number_format($dom_analysis['sold_under_14_days'] - $dom_analysis['sold_under_7_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_under_14_days'] - $dom_analysis['pct_under_7_days'], 1); ?>%</td>
                    <td><div style="background: #20c997; height: 20px; width: <?php echo min(100, $dom_analysis['pct_under_14_days'] - $dom_analysis['pct_under_7_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
                <tr>
                    <td>14-30 days</td>
                    <td><?php echo number_format($dom_analysis['sold_under_30_days'] - $dom_analysis['sold_under_14_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_under_30_days'] - $dom_analysis['pct_under_14_days'], 1); ?>%</td>
                    <td><div style="background: #17a2b8; height: 20px; width: <?php echo min(100, $dom_analysis['pct_under_30_days'] - $dom_analysis['pct_under_14_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
                <tr>
                    <td>30-60 days</td>
                    <td><?php echo number_format($dom_analysis['sold_under_60_days'] - $dom_analysis['sold_under_30_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_under_60_days'] - $dom_analysis['pct_under_30_days'], 1); ?>%</td>
                    <td><div style="background: #ffc107; height: 20px; width: <?php echo min(100, $dom_analysis['pct_under_60_days'] - $dom_analysis['pct_under_30_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
                <tr>
                    <td>60-90 days</td>
                    <td><?php echo number_format($dom_analysis['sold_under_90_days'] - $dom_analysis['sold_under_60_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_under_90_days'] - $dom_analysis['pct_under_60_days'], 1); ?>%</td>
                    <td><div style="background: #fd7e14; height: 20px; width: <?php echo min(100, $dom_analysis['pct_under_90_days'] - $dom_analysis['pct_under_60_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
                <tr>
                    <td>Over 90 days</td>
                    <td><?php echo number_format($dom_analysis['sold_over_90_days']); ?></td>
                    <td><?php echo number_format($dom_analysis['pct_over_90_days'], 1); ?>%</td>
                    <td><div style="background: #dc3545; height: 20px; width: <?php echo min(100, $dom_analysis['pct_over_90_days']); ?>%; border-radius: 4px;"></div></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No DOM analysis data available for this location.</p></div>
    <?php endif; ?>

    <!-- Price by Bedrooms -->
    <h2 class="mld-chart-title">Price by Bedroom Count</h2>
    <?php if (!empty($price_by_beds)): ?>
    <div class="mld-two-column">
        <div class="mld-data-table">
            <table>
                <thead>
                    <tr>
                        <th>Bedrooms</th>
                        <th>Sales</th>
                        <th>Avg Price</th>
                        <th>$/SqFt</th>
                        <th>Avg SqFt</th>
                        <th>Avg DOM</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($price_by_beds as $bed_data): ?>
                    <tr>
                        <td><strong><?php echo esc_html($bed_data['bedrooms']); ?> BR</strong></td>
                        <td><?php echo number_format($bed_data['sales_count']); ?></td>
                        <td>$<?php echo number_format($bed_data['avg_price']); ?></td>
                        <td>$<?php echo number_format($bed_data['price_per_sqft'], 2); ?></td>
                        <td><?php echo number_format($bed_data['avg_sqft']); ?></td>
                        <td><?php echo number_format($bed_data['avg_dom']); ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mld-chart-container">
            <h3 style="margin: 0 0 15px 0; font-size: 16px;">Price by Bedroom Chart</h3>
            <canvas id="price-by-beds-chart" class="mld-chart-canvas" style="max-height: 250px;"></canvas>
        </div>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No bedroom price data available for this location.</p></div>
    <?php endif; ?>

    <!-- Property Characteristics -->
    <h2 class="mld-chart-title">Property Characteristics</h2>
    <?php if (!empty($characteristics) && isset($characteristics['avg_sqft'])): ?>
    <div class="mld-stat-cards">
        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Living Area</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['avg_sqft']); ?></div>
            <div class="mld-stat-change">sq ft</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Bedrooms</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['avg_bedrooms'], 1); ?></div>
            <div class="mld-stat-change">Most common: <?php echo esc_html($characteristics['most_common_bedrooms']); ?> BR</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Bathrooms</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['avg_bathrooms'], 1); ?></div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Home Age</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['avg_age']); ?></div>
            <div class="mld-stat-change">years (<?php echo $characteristics['oldest_year_built']; ?>-<?php echo $characteristics['newest_year_built']; ?>)</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Has Garage</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['pct_has_garage'], 1); ?>%</div>
            <div class="mld-stat-change"><?php echo number_format($characteristics['has_garage_count']); ?> properties</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">New Construction</div>
            <div class="mld-stat-value"><?php echo number_format($characteristics['pct_new_construction'], 1); ?>%</div>
            <div class="mld-stat-change"><?php echo number_format($characteristics['new_construction_count']); ?> built 2020+</div>
        </div>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No property characteristics data available.</p></div>
    <?php endif; ?>

    <!-- Property Type Performance -->
    <h2 class="mld-chart-title">Property Type Performance</h2>
    <?php if (!empty($property_types['by_volume'])): ?>
    <div class="mld-data-table">
        <table>
            <thead>
                <tr>
                    <th>Property Type</th>
                    <th>Sales</th>
                    <th>Avg Price</th>
                    <th>Avg DOM</th>
                    <th>SP/LP Ratio</th>
                    <th>$/SqFt</th>
                    <th>Total Volume</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($property_types['by_volume'] as $type): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($type['property_type']); ?></strong>
                        <?php if ($type['property_type'] === $property_types['fastest_selling']): ?>
                            <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px;">Fastest</span>
                        <?php endif; ?>
                        <?php if ($type['property_type'] === $property_types['highest_sp_lp']): ?>
                            <span style="background: #2c5aa0; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px;">Best Ratio</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format($type['sales_count']); ?></td>
                    <td>$<?php echo number_format($type['avg_price']); ?></td>
                    <td><?php echo number_format($type['avg_dom']); ?> days</td>
                    <td style="color: <?php echo $type['sp_lp_ratio'] >= 100 ? '#28a745' : '#666'; ?>;">
                        <?php echo number_format($type['sp_lp_ratio'], 1); ?>%
                    </td>
                    <td>$<?php echo number_format($type['price_per_sqft'] ?? 0, 2); ?></td>
                    <td>$<?php echo number_format($type['total_volume'] / 1000000, 2); ?>M</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No property type performance data available.</p></div>
    <?php endif; ?>

    <script>
    // DOM Distribution Chart
    <?php if (!isset($dom_analysis['error'])): ?>
    const domDistData = {
        labels: ['<7 days', '7-14', '14-30', '30-60', '60-90', '90+'],
        data: [
            <?php echo $dom_analysis['sold_under_7_days']; ?>,
            <?php echo $dom_analysis['sold_under_14_days'] - $dom_analysis['sold_under_7_days']; ?>,
            <?php echo $dom_analysis['sold_under_30_days'] - $dom_analysis['sold_under_14_days']; ?>,
            <?php echo $dom_analysis['sold_under_60_days'] - $dom_analysis['sold_under_30_days']; ?>,
            <?php echo $dom_analysis['sold_under_90_days'] - $dom_analysis['sold_under_60_days']; ?>,
            <?php echo $dom_analysis['sold_over_90_days']; ?>
        ]
    };

    const domDistCtx = document.getElementById('dom-distribution-chart').getContext('2d');
    new Chart(domDistCtx, {
        type: 'doughnut',
        data: {
            labels: domDistData.labels,
            datasets: [{
                data: domDistData.data,
                backgroundColor: ['#28a745', '#20c997', '#17a2b8', '#ffc107', '#fd7e14', '#dc3545']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' }
            }
        }
    });
    <?php endif; ?>

    // Price by Bedrooms Chart
    <?php if (!empty($price_by_beds)): ?>
    const bedsData = <?php echo json_encode($price_by_beds); ?>;
    const bedsCtx = document.getElementById('price-by-beds-chart').getContext('2d');
    new Chart(bedsCtx, {
        type: 'bar',
        data: {
            labels: bedsData.map(d => d.bedrooms + ' BR'),
            datasets: [{
                label: 'Avg Price',
                data: bedsData.map(d => d.avg_price),
                backgroundColor: '#2c5aa0',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    </script>
    <?php
}

/**
 * Render Feature Premiums Tab - Shows premium/discount for property features
 */
function mld_render_feature_premiums_tab($city, $state, $property_type, $date_range = 12) {
    // Get feature premiums
    $feature_premiums = MLD_Extended_Analytics::get_all_feature_premiums($city, $state, $property_type, $date_range);
    $financial = MLD_Extended_Analytics::get_financial_analysis($city, $state, $property_type, $date_range);

    // Format date range for display
    $date_label = $date_range . ' month' . ($date_range > 1 ? 's' : '');
    if ($date_range == 24) $date_label = '2 years';
    if ($date_range == 36) $date_label = '3 years';
    ?>

    <h2 class="mld-chart-title" style="margin-top: 0;">Feature Value Analysis</h2>
    <p style="color: #666; margin-bottom: 20px;">
        Analysis of how specific property features affect sale prices. Premium percentages show how much more (or less)
        properties with these features sell for compared to those without. Based on sales from the last <?php echo esc_html($date_label); ?>.
    </p>

    <?php if (!empty($feature_premiums)): ?>
    <div class="mld-stat-cards">
        <?php foreach ($feature_premiums as $key => $premium): ?>
            <?php if (!isset($premium['error'])): ?>
            <div class="mld-stat-card <?php echo $premium['premium_pct'] > 0 ? 'positive' : ($premium['premium_pct'] < 0 ? 'negative' : ''); ?>">
                <div class="mld-stat-label"><?php echo esc_html($premium['label']); ?> Premium</div>
                <div class="mld-stat-value">
                    <?php echo $premium['premium_pct'] >= 0 ? '+' : ''; ?><?php echo number_format($premium['premium_pct'], 1); ?>%
                </div>
                <div class="mld-stat-change">
                    $<?php echo number_format(abs($premium['premium_amount'])); ?> difference
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="mld-two-column">
        <div class="mld-data-table">
            <h3 class="mld-chart-title" style="font-size: 16px;">Feature Premium Details</h3>
            <table>
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>With Feature</th>
                        <th>Without Feature</th>
                        <th>Premium</th>
                        <th>$/SqFt Diff</th>
                        <th>Confidence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feature_premiums as $key => $premium): ?>
                        <?php if (!isset($premium['error'])): ?>
                        <tr>
                            <td><strong><?php echo esc_html($premium['label']); ?></strong></td>
                            <td>
                                $<?php echo number_format($premium['with_feature_avg_price']); ?>
                                <br><small style="color: #666;">(<?php echo $premium['with_feature_count']; ?> sales)</small>
                            </td>
                            <td>
                                $<?php echo number_format($premium['without_feature_avg_price']); ?>
                                <br><small style="color: #666;">(<?php echo $premium['without_feature_count']; ?> sales)</small>
                            </td>
                            <td style="color: <?php echo $premium['premium_pct'] > 0 ? '#28a745' : '#dc3545'; ?>; font-weight: 600;">
                                <?php echo $premium['premium_pct'] >= 0 ? '+' : ''; ?><?php echo number_format($premium['premium_pct'], 1); ?>%
                                <br>$<?php echo number_format($premium['premium_amount']); ?>
                            </td>
                            <td>
                                <?php echo $premium['premium_per_sqft'] >= 0 ? '+' : ''; ?>$<?php echo number_format($premium['premium_per_sqft'], 2); ?>
                            </td>
                            <td>
                                <span style="background: <?php
                                    echo $premium['confidence'] === 'High' ? '#28a745' :
                                        ($premium['confidence'] === 'Medium' ? '#ffc107' :
                                        ($premium['confidence'] === 'Low' ? '#fd7e14' : '#dc3545'));
                                ?>; color: white; padding: 3px 8px; border-radius: 10px; font-size: 11px;">
                                    <?php echo esc_html($premium['confidence']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mld-chart-container">
            <h3 style="margin: 0 0 15px 0; font-size: 16px;">Feature Premium Comparison</h3>
            <canvas id="feature-premium-chart" class="mld-chart-canvas" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <?php else: ?>
    <div class="notice notice-info">
        <p>Insufficient data to calculate feature premiums for this location. Feature premium analysis requires at least 5 sales with the feature and 10 sales without to ensure statistical reliability.</p>
    </div>
    <?php endif; ?>

    <!-- Financial Analysis Section -->
    <h2 class="mld-chart-title">Financial Analysis (Tax & HOA)</h2>
    <?php if (!empty($financial) && isset($financial['avg_annual_tax'])): ?>
    <div class="mld-stat-cards">
        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg Annual Tax</div>
            <div class="mld-stat-value">$<?php echo number_format($financial['avg_annual_tax']); ?></div>
            <div class="mld-stat-change"><?php echo number_format($financial['properties_with_tax_data']); ?> properties with data</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Effective Tax Rate</div>
            <div class="mld-stat-value"><?php echo number_format($financial['avg_effective_tax_rate'], 2); ?>%</div>
            <div class="mld-stat-change">Tax as % of sale price</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Tax Range</div>
            <div class="mld-stat-value">$<?php echo number_format($financial['min_annual_tax']); ?> - $<?php echo number_format($financial['max_annual_tax']); ?></div>
            <div class="mld-stat-change">Min to max</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">Properties with HOA</div>
            <div class="mld-stat-value"><?php echo number_format($financial['hoa_percentage'], 1); ?>%</div>
            <div class="mld-stat-change"><?php echo number_format($financial['properties_with_hoa']); ?> of <?php echo number_format($financial['total_properties']); ?></div>
        </div>

        <?php if (($financial['avg_hoa_fee'] ?? 0) > 0): ?>
        <div class="mld-stat-card">
            <div class="mld-stat-label">Avg HOA Fee</div>
            <div class="mld-stat-value">$<?php echo number_format($financial['avg_hoa_fee']); ?></div>
            <div class="mld-stat-change">Monthly</div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-label">HOA Fee Range</div>
            <div class="mld-stat-value">$<?php echo number_format($financial['min_hoa_fee']); ?> - $<?php echo number_format($financial['max_hoa_fee']); ?></div>
            <div class="mld-stat-change">Min to max monthly</div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="notice notice-info"><p>No financial analysis data available for this location.</p></div>
    <?php endif; ?>

    <script>
    // Feature Premium Chart
    <?php if (!empty($feature_premiums)): ?>
    const featureData = <?php echo json_encode(array_values(array_filter($feature_premiums, function($p) { return !isset($p['error']); }))); ?>;

    const featureCtx = document.getElementById('feature-premium-chart').getContext('2d');
    new Chart(featureCtx, {
        type: 'bar',
        data: {
            labels: featureData.map(f => f.label),
            datasets: [{
                label: 'Premium %',
                data: featureData.map(f => f.premium_pct),
                backgroundColor: featureData.map(f => f.premium_pct >= 0 ? '#28a745' : '#dc3545'),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const feature = featureData[context.dataIndex];
                            return [
                                (context.parsed.x >= 0 ? '+' : '') + context.parsed.x.toFixed(1) + '% premium',
                                '$' + feature.premium_amount.toLocaleString() + ' difference'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return (value >= 0 ? '+' : '') + value + '%';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    </script>
    <?php
}
