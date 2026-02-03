/**
 * Property Page Market Analytics
 *
 * Lazy-loaded market analytics for property detail pages.
 * Uses IntersectionObserver for performance and REST API for data.
 *
 * @package    MLS_Listings_Display
 * @version    6.12.8
 */
(function() {
    'use strict';

    const PropertyAnalytics = {
        container: null,
        config: {},
        data: {},
        loaded: false,
        chartJsLoaded: false,
        tabsLoaded: {},

        /**
         * Initialize the analytics component
         */
        init: function() {
            this.container = document.getElementById('mld-property-analytics');
            if (!this.container) {
                return;
            }

            // Parse configuration from data attributes
            this.config = {
                city: this.container.dataset.city,
                state: this.container.dataset.state,
                propertyType: this.container.dataset.propertyType || 'all',
                lite: this.container.dataset.lite === 'true',
                nonce: this.container.dataset.nonce,
                restUrl: (typeof mldPropertyAnalytics !== 'undefined' && mldPropertyAnalytics.restUrl)
                    ? mldPropertyAnalytics.restUrl
                    : '/wp-json/'
            };

            if (!this.config.city) {
                return;
            }

            this.setupIntersectionObserver();
        },

        /**
         * Setup IntersectionObserver for lazy loading
         */
        setupIntersectionObserver: function() {
            const options = {
                root: null,
                rootMargin: '300px',
                threshold: 0.1
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !this.loaded) {
                        this.loadAnalytics();
                        observer.unobserve(entry.target);
                    }
                });
            }, options);

            observer.observe(this.container);
        },

        /**
         * Load analytics data
         */
        loadAnalytics: async function() {
            if (this.loaded) return;
            this.loaded = true;

            try {
                const summaryData = await this.fetchTab('overview');

                if (summaryData.error) {
                    throw new Error(summaryData.error);
                }

                this.data.summary = summaryData;
                this.renderSummary(summaryData);

                const skeleton = this.container.querySelector('.mld-analytics-skeleton');
                const content = this.container.querySelector('.mld-analytics-content');

                if (skeleton) skeleton.style.display = 'none';
                if (content) content.style.display = 'block';

                if (!this.config.lite) {
                    this.setupTabListeners();
                    this.tabsLoaded['overview'] = true;
                    // Render overview panel content
                    var overviewPanel = this.container.querySelector('#panel-overview');
                    if (overviewPanel) {
                        this.renderTabContent('overview', summaryData, overviewPanel);
                    }
                }
            } catch (error) {
                console.error('PropertyAnalytics: Load error', error);
                this.showError(error.message || 'Failed to load analytics');
            }
        },

        /**
         * Fetch data for a specific tab
         */
        fetchTab: async function(tab) {
            const url = new URL(
                this.config.restUrl + 'property-analytics/' + encodeURIComponent(this.config.city),
                window.location.origin
            );
            url.searchParams.set('state', this.config.state);
            url.searchParams.set('tab', tab);
            url.searchParams.set('lite', this.config.lite);
            url.searchParams.set('property_type', this.config.propertyType);

            const response = await fetch(url, {
                headers: {
                    'X-WP-Nonce': this.config.nonce
                }
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.message || 'API error: ' + response.status);
            }

            return response.json();
        },

        /**
         * Render summary metrics
         */
        renderSummary: function(data) {
            const metricsGrid = this.container.querySelector('.mld-market-metrics-grid');
            if (!metricsGrid) return;

            const summary = data.city_summary || {};
            const heat = data.market_heat || {};

            const metrics = [
                {
                    label: 'Median Price',
                    value: this.formatCurrency(summary.median_list_price || summary.avg_close_price_12m),
                    trend: summary.yoy_price_change_pct,
                    icon: 'dashicons-chart-area'
                },
                {
                    label: 'Avg Days on Market',
                    value: Math.round(summary.avg_dom_12m || 0) + ' days',
                    trend: summary.yoy_dom_change_pct,
                    icon: 'dashicons-clock',
                    invertTrend: true
                },
                {
                    label: 'Sale-to-List Ratio',
                    value: parseFloat(summary.avg_sp_lp_12m || 0).toFixed(1) + '%',
                    icon: 'dashicons-chart-bar'
                },
                {
                    label: 'Months of Supply',
                    value: parseFloat(summary.months_of_supply || 0).toFixed(1) + ' mos',
                    classification: heat.classification || summary.market_classification || 'Balanced',
                    icon: 'dashicons-building'
                }
            ];

            metricsGrid.innerHTML = metrics.map(m => this.renderMetricCard(m)).join('');

            if (!this.config.lite && heat.score) {
                const heatContainer = this.container.querySelector('.mld-market-heat-container');
                if (heatContainer) {
                    heatContainer.innerHTML = this.renderHeatGauge(heat);
                }
            }
        },

        /**
         * Render a single metric card
         */
        renderMetricCard: function(metric) {
            let trendHtml = '';
            if (metric.trend !== undefined && metric.trend !== null) {
                const isPositive = metric.trend > 0;
                const trendClass = isPositive
                    ? (metric.invertTrend ? 'trend-down' : 'trend-up')
                    : (metric.invertTrend ? 'trend-up' : 'trend-down');
                const trendIcon = isPositive ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt';

                trendHtml = '<div class="mld-metric-trend ' + trendClass + '">' +
                    '<span class="dashicons ' + trendIcon + '"></span>' +
                    Math.abs(metric.trend).toFixed(1) + '% YoY</div>';
            }

            let classificationHtml = '';
            if (metric.classification) {
                const classLower = metric.classification.toLowerCase();
                classificationHtml = '<div class="mld-metric-classification mld-heat-' + classLower + '">' +
                    metric.classification + ' Market</div>';
            }

            return '<div class="mld-market-metric-card">' +
                '<span class="dashicons ' + metric.icon + ' mld-metric-icon"></span>' +
                '<div class="mld-metric-value">' + metric.value + '</div>' +
                '<div class="mld-metric-label">' + metric.label + '</div>' +
                trendHtml + classificationHtml + '</div>';
        },

        /**
         * Render market heat gauge
         */
        renderHeatGauge: function(heat) {
            const position = Math.min(100, Math.max(0, heat.score || 50));
            const classification = heat.classification || 'balanced';
            const classLower = classification.toLowerCase();

            return '<div class="mld-market-heat-gauge">' +
                '<div class="mld-heat-header">' +
                '<span class="mld-heat-label">Market Heat Index</span>' +
                '<span class="mld-heat-value mld-heat-' + classLower + '">' + heat.score + ' - ' + classification + '</span>' +
                '</div>' +
                '<div class="mld-heat-bar">' +
                '<div class="mld-heat-marker" style="left: ' + position + '%"></div>' +
                '</div>' +
                '<div class="mld-heat-labels">' +
                '<span>Buyer\'s Market</span><span>Balanced</span><span>Seller\'s Market</span>' +
                '</div></div>';
        },

        /**
         * Setup tab click listeners
         */
        setupTabListeners: function() {
            const tabs = this.container.querySelectorAll('.mld-market-tab');
            const panels = this.container.querySelectorAll('.mld-market-tab-panel');
            const self = this;

            tabs.forEach(function(tab) {
                tab.addEventListener('click', async function(e) {
                    const tabName = e.currentTarget.dataset.tab;

                    tabs.forEach(function(t) {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                        t.setAttribute('tabindex', '-1');
                    });
                    e.currentTarget.classList.add('active');
                    e.currentTarget.setAttribute('aria-selected', 'true');
                    e.currentTarget.setAttribute('tabindex', '0');

                    panels.forEach(function(p) {
                        p.classList.remove('active');
                        p.setAttribute('hidden', '');
                    });
                    const panel = self.container.querySelector('#panel-' + tabName);
                    if (panel) {
                        panel.classList.add('active');
                        panel.removeAttribute('hidden');
                    }

                    if (!self.tabsLoaded[tabName]) {
                        await self.loadTabContent(tabName);
                    }
                });

                tab.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                        e.preventDefault();
                        const allTabs = Array.from(tabs);
                        const currentIndex = allTabs.indexOf(e.currentTarget);
                        let newIndex;

                        if (e.key === 'ArrowRight') {
                            newIndex = (currentIndex + 1) % allTabs.length;
                        } else {
                            newIndex = (currentIndex - 1 + allTabs.length) % allTabs.length;
                        }

                        allTabs[newIndex].focus();
                        allTabs[newIndex].click();
                    }
                });
            });
        },

        /**
         * Load content for a specific tab
         */
        loadTabContent: async function(tabName) {
            const panel = this.container.querySelector('#panel-' + tabName);
            if (!panel) return;

            panel.innerHTML = '<div class="mld-panel-loading">' +
                '<span class="dashicons dashicons-update mld-spinner"></span>Loading...</div>';

            try {
                const data = await this.fetchTab(tabName);

                if (data.error) {
                    throw new Error(data.error);
                }

                this.data[tabName] = data;
                this.tabsLoaded[tabName] = true;
                this.renderTabContent(tabName, data, panel);
            } catch (error) {
                console.error('PropertyAnalytics: Tab load error', tabName, error);
                panel.innerHTML = '<div class="mld-panel-error">' +
                    '<span class="dashicons dashicons-warning"></span>' +
                    (error.message || 'Failed to load data') + '</div>';
            }
        },

        /**
         * Render content for a specific tab
         */
        renderTabContent: function(tabName, data, panel) {
            switch (tabName) {
                case 'overview':
                    panel.innerHTML = '<p>See the metrics above for an overview of the market.</p>';
                    break;
                case 'trends':
                    this.renderTrendsTab(data, panel);
                    break;
                case 'supply':
                    this.renderSupplyTab(data, panel);
                    break;
                case 'velocity':
                    this.renderVelocityTab(data, panel);
                    break;
                case 'comparison':
                    this.renderComparisonTab(data, panel);
                    break;
                case 'agents':
                    this.renderAgentsTab(data, panel);
                    break;
                case 'yoy':
                    this.renderYoYTab(data, panel);
                    break;
                case 'property':
                    this.renderPropertyTab(data, panel);
                    break;
                case 'features':
                    this.renderFeaturesTab(data, panel);
                    break;
                default:
                    panel.innerHTML = '<p>No data available for this tab.</p>';
            }
        },

        /**
         * Render Price Trends tab
         */
        renderTrendsTab: async function(data, panel) {
            if (!data.monthly_trends || data.monthly_trends.length === 0) {
                panel.innerHTML = '<p class="mld-no-data">No trend data available for this city.</p>';
                return;
            }

            panel.innerHTML = '<div class="mld-chart-container" style="height: 300px;">' +
                '<canvas id="mld-price-trends-chart"></canvas></div>' +
                '<div class="mld-trends-table-container">' + this.renderTrendsTable(data.monthly_trends) + '</div>';

            await this.loadChartJs();
            this.renderTrendsChart(data.monthly_trends);
        },

        /**
         * Render trends data table
         */
        renderTrendsTable: function(trends) {
            var self = this;
            var rows = trends.slice(-6).reverse().map(function(t) {
                return '<tr><td>' + (t.month_name || t.month) + '</td>' +
                    '<td>' + self.formatCurrency(t.avg_close_price) + '</td>' +
                    '<td>' + t.sales_count + '</td>' +
                    '<td>' + Math.round(t.avg_dom || 0) + ' days</td>' +
                    '<td>' + parseFloat(t.avg_sp_lp_ratio || 0).toFixed(1) + '%</td></tr>';
            }).join('');

            return '<table class="mld-data-table"><thead><tr>' +
                '<th>Month</th><th>Avg Price</th><th>Sales</th><th>Avg DOM</th><th>SP/LP</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';
        },

        /**
         * Render trends chart
         */
        renderTrendsChart: function(trends) {
            var self = this;
            var ctx = document.getElementById('mld-price-trends-chart');
            if (!ctx || !window.Chart) return;

            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: trends.map(function(t) { return t.month_name || t.month; }),
                    datasets: [{
                        label: 'Average Price',
                        data: trends.map(function(t) { return t.avg_close_price; }),
                        borderColor: '#0891B2',
                        backgroundColor: 'rgba(8, 145, 178, 0.1)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        y: {
                            ticks: {
                                callback: function(value) { return self.formatCurrency(value); }
                            }
                        }
                    }
                }
            });
        },

        /**
         * Render Supply & Demand tab
         */
        renderSupplyTab: function(data, panel) {
            var sd = data.supply_demand || {};

            panel.innerHTML = '<div class="mld-supply-demand-grid">' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + (sd.active_count || 0) + '</span><span class="mld-sd-label">Active Listings</span></div>' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + (sd.pending_count || 0) + '</span><span class="mld-sd-label">Pending</span></div>' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + parseFloat(sd.months_of_supply || 0).toFixed(1) + '</span><span class="mld-sd-label">Months of Supply</span></div>' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + parseFloat(sd.absorption_rate || 0).toFixed(1) + '%</span><span class="mld-sd-label">Absorption Rate</span></div>' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + (sd.new_this_week || 0) + '</span><span class="mld-sd-label">New This Week</span></div>' +
                '<div class="mld-sd-metric"><span class="mld-sd-value">' + (sd.sold_12m || 0) + '</span><span class="mld-sd-label">Sold (12 mo)</span></div>' +
                '</div>';
        },

        /**
         * Render Market Velocity tab
         */
        renderVelocityTab: function(data, panel) {
            var dom = data.dom_analysis || {};

            panel.innerHTML = '<div class="mld-velocity-content"><h4>Days on Market Distribution</h4>' +
                '<div class="mld-dom-distribution">' + this.renderDOMBars(dom) + '</div>' +
                '<div class="mld-velocity-summary">' +
                '<p>Average DOM: <strong>' + Math.round(parseInt(dom.avg_dom) || 0) + ' days</strong></p>' +
                '<p>Median DOM: <strong>' + Math.round(parseInt(dom.median_dom) || parseInt(dom.avg_dom) || 0) + ' days</strong></p>' +
                '</div></div>';
        },

        /**
         * Render DOM distribution bars
         */
        renderDOMBars: function(dom) {
            // Calculate exclusive counts from cumulative data
            var under7 = parseInt(dom.sold_under_7_days) || 0;
            var under14 = parseInt(dom.sold_under_14_days) || 0;
            var under30 = parseInt(dom.sold_under_30_days) || 0;
            var under60 = parseInt(dom.sold_under_60_days) || 0;
            var under90 = parseInt(dom.sold_under_90_days) || 0;
            var over90 = parseInt(dom.sold_over_90_days) || 0;

            var ranges = [
                { label: '< 7 days', count: under7, color: '#10b981' },
                { label: '7-14 days', count: under14 - under7, color: '#34d399' },
                { label: '14-30 days', count: under30 - under14, color: '#fbbf24' },
                { label: '30-60 days', count: under60 - under30, color: '#f59e0b' },
                { label: '60-90 days', count: under90 - under60, color: '#ef4444' },
                { label: '90+ days', count: over90, color: '#dc2626' }
            ];

            var total = parseInt(dom.total_sales) || ranges.reduce(function(sum, r) { return sum + r.count; }, 0) || 1;

            return ranges.map(function(r) {
                var count = Math.max(0, r.count);
                var pct = (count / total * 100).toFixed(1);
                return '<div class="mld-dom-bar-container">' +
                    '<span class="mld-dom-label">' + r.label + '</span>' +
                    '<div class="mld-dom-bar-bg"><div class="mld-dom-bar" style="width: ' + pct + '%; background: ' + r.color + '"></div></div>' +
                    '<span class="mld-dom-pct">' + count + ' (' + pct + '%)</span></div>';
            }).join('');
        },

        /**
         * Render City Comparison tab
         */
        renderComparisonTab: async function(data, panel) {
            var self = this;
            var cities = [];
            try {
                var response = await fetch(this.config.restUrl + 'available-cities',
                    { headers: { 'X-WP-Nonce': this.config.nonce } });
                var result = await response.json(); cities = Array.isArray(result) ? result : [];
            } catch (e) {
                console.error('Failed to fetch cities', e);
            }

            var cityOptions = cities.filter(function(c) { return c.city !== self.config.city; })
                .slice(0, 20).map(function(c) {
                    return '<option value="' + c.city + '">' + c.city + ', ' + c.state + ' (' + c.listing_count + ' listings)</option>';
                }).join('');

            panel.innerHTML = '<div class="mld-comparison-controls">' +
                '<label for="mld-compare-cities">Compare ' + this.config.city + ' with:</label>' +
                '<select id="mld-compare-cities" multiple size="5" class="mld-city-select">' + cityOptions + '</select>' +
                '<button type="button" id="mld-compare-btn" class="mld-btn mld-btn-primary">Compare Cities</button>' +
                '</div><div id="mld-comparison-results" class="mld-comparison-results"></div>';

            var btn = panel.querySelector('#mld-compare-btn');
            btn.addEventListener('click', function() { self.runCityComparison(panel); });
        },

        /**
         * Run city comparison
         */
        runCityComparison: async function(panel) {
            var self = this;
            var select = panel.querySelector('#mld-compare-cities');
            var results = panel.querySelector('#mld-comparison-results');
            var selectedCities = Array.from(select.selectedOptions).map(function(o) { return o.value; });

            if (selectedCities.length === 0) {
                results.innerHTML = '<p class="mld-warning">Please select at least one city to compare.</p>';
                return;
            }

            results.innerHTML = '<div class="mld-panel-loading"><span class="dashicons dashicons-update mld-spinner"></span> Loading comparison...</div>';

            try {
                var cities = [this.config.city].concat(selectedCities).join(',');
                var url = new URL(this.config.restUrl + 'city-comparison', window.location.origin);
                url.searchParams.set('cities', cities);
                url.searchParams.set('state', this.config.state);

                var response = await fetch(url, { headers: { 'X-WP-Nonce': this.config.nonce } });
                var data = await response.json();

                results.innerHTML = self.renderComparisonTable(data.comparison, data.metrics);
            } catch (e) {
                console.error('Comparison error', e);
                results.innerHTML = '<p class="mld-error">Failed to load comparison data.</p>';
            }
        },

        /**
         * Render comparison table
         */
        renderComparisonTable: function(comparison, metrics) {
            var self = this;
            if (!comparison || comparison.length === 0) {
                return '<p>No comparison data available.</p>';
            }

            var headers = comparison.map(function(c) { return '<th>' + c.city + '</th>'; }).join('');
            var metricKeys = ['median_list_price', 'avg_price_per_sqft', 'avg_dom_12m', 'avg_sp_lp_12m', 'months_of_supply', 'market_heat_index'];

            var rows = metricKeys.map(function(key) {
                var meta = metrics[key] || { label: key, format: 'number' };
                var cells = comparison.map(function(c) {
                    var value = c[key];
                    if (meta.format === 'currency') {
                        value = self.formatCurrency(value);
                    } else if (meta.format === 'percent') {
                        value = parseFloat(value || 0).toFixed(1) + '%';
                    } else if (meta.format === 'decimal') {
                        value = parseFloat(value || 0).toFixed(1);
                    } else {
                        value = Math.round(value || 0);
                    }
                    return '<td>' + value + '</td>';
                }).join('');
                return '<tr><td><strong>' + meta.label + '</strong></td>' + cells + '</tr>';
            }).join('');

            return '<table class="mld-data-table mld-comparison-table"><thead><tr><th>Metric</th>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table>';
        },

        /**
         * Render Agent Performance tab
         */
        renderAgentsTab: function(data, panel) {
            var self = this;
            var agents = data.top_agents || [];

            if (agents.length === 0) {
                panel.innerHTML = '<p class="mld-no-data">No agent performance data available.</p>';
                return;
            }

            var rows = agents.slice(0, 10).map(function(a, i) {
                return '<tr><td>' + (i + 1) + '</td>' +
                    '<td>' + (a.agent_name || 'Unknown') + '</td>' +
                    '<td>' + (a.office_name || '-') + '</td>' +
                    '<td>' + a.transaction_count + '</td>' +
                    '<td>' + self.formatCurrency(a.total_volume) + '</td>' +
                    '<td>' + self.formatCurrency(a.avg_sale_price) + '</td></tr>';
            }).join('');

            panel.innerHTML = '<h4>Top Listing Agents (Last 12 Months)</h4>' +
                '<table class="mld-data-table"><thead><tr>' +
                '<th>#</th><th>Agent</th><th>Office</th><th>Sales</th><th>Volume</th><th>Avg Price</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';
        },

        /**
         * Render Year-over-Year tab
         */
        renderYoYTab: function(data, panel) {
            var yoyArr = data.yoy_comparison || []; var yoy = yoyArr[0] || {};

            var priceClass = (yoy.price_change_pct || 0) >= 0 ? 'positive' : 'negative';
            var volClass = (yoy.volume_change_pct || 0) >= 0 ? 'positive' : 'negative';
            var domChangePct = yoy.previous_avg_dom ? ((yoy.current_avg_dom - yoy.previous_avg_dom) / yoy.previous_avg_dom * 100) : 0; var domClass = (domChangePct || 0) <= 0 ? 'positive' : 'negative';

            panel.innerHTML = '<div class="mld-yoy-grid">' +
                '<div class="mld-yoy-metric"><span class="mld-yoy-value ' + priceClass + '">' +
                ((yoy.price_change_pct || 0) >= 0 ? '+' : '') + parseFloat(yoy.price_change_pct || 0).toFixed(1) + '%</span>' +
                '<span class="mld-yoy-label">Price Change</span></div>' +
                '<div class="mld-yoy-metric"><span class="mld-yoy-value ' + volClass + '">' +
                ((yoy.volume_change_pct || 0) >= 0 ? '+' : '') + parseFloat(yoy.volume_change_pct || 0).toFixed(1) + '%</span>' +
                '<span class="mld-yoy-label">Sales Volume</span></div>' +
                '<div class="mld-yoy-metric"><span class="mld-yoy-value ' + domClass + '">' +
                ((domChangePct || 0) >= 0 ? '+' : '') + (domChangePct || 0).toFixed(1) + '%</span>' +
                '<span class="mld-yoy-label">Days on Market</span></div></div>';
        },

        /**
         * Render Property Analysis tab
         */
        renderPropertyTab: function(data, panel) {
            var self = this;
            var priceByBeds = data.price_by_beds || [];

            if (priceByBeds.length === 0) {
                panel.innerHTML = '<p class="mld-no-data">No property analysis data available.</p>';
                return;
            }

            var rows = priceByBeds.map(function(p) {
                return '<tr><td>' + p.bedrooms + ' BR</td>' +
                    '<td>' + p.count + '</td>' +
                    '<td>' + self.formatCurrency(p.avg_price) + '</td>' +
                    '<td>' + self.formatCurrency(p.median_price) + '</td>' +
                    '<td>' + Math.round(p.avg_dom || 0) + ' days</td></tr>';
            }).join('');

            panel.innerHTML = '<h4>Price by Bedroom Count</h4>' +
                '<table class="mld-data-table"><thead><tr>' +
                '<th>Bedrooms</th><th>Sales</th><th>Avg Price</th><th>Median</th><th>Avg DOM</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';
        },

        /**
         * Render Feature Premiums tab
         */
        renderFeaturesTab: function(data, panel) {
            var self = this;
            var premiumsData = data.feature_premiums || {};
            var premiums = [];

            // Convert object to array if needed (API returns object keyed by feature name)
            if (Array.isArray(premiumsData)) {
                premiums = premiumsData;
            } else if (typeof premiumsData === 'object' && premiumsData !== null) {
                premiums = Object.keys(premiumsData).map(function(key) {
                    var p = premiumsData[key];
                    return {
                        feature_name: p.label || key,
                        premium_pct: parseFloat(p.premium_pct) || 0,
                        premium_amount: parseFloat(p.premium_amount) || 0,
                        sample_size_with: p.with_feature_count || 0,
                        confidence_score: p.confidence === 'High' ? 0.9 : (p.confidence === 'Medium' ? 0.7 : 0.5)
                    };
                });
            }

            if (premiums.length === 0) {
                panel.innerHTML = '<p class="mld-no-data">No feature premium data available for this market.</p>';
                return;
            }

            var rows = premiums.map(function(p) {
                var pctClass = parseFloat(p.premium_pct) >= 0 ? 'positive' : 'negative';
                return '<tr><td>' + self.formatFeatureName(p.feature_name) + '</td>' +
                    '<td class="' + pctClass + '">' + (parseFloat(p.premium_pct) >= 0 ? '+' : '') + parseFloat(p.premium_pct).toFixed(1) + '%</td>' +
                    '<td>' + self.formatCurrency(p.premium_amount) + '</td>' +
                    '<td>' + p.sample_size_with + '</td>' +
                    '<td>' + (parseFloat(p.confidence_score) * 100).toFixed(0) + '%</td></tr>';
            }).join('');

            panel.innerHTML = '<h4>Feature Value Premiums</h4>' +
                '<p class="mld-feature-note">Price premium for properties with these features compared to those without.</p>' +
                '<table class="mld-data-table"><thead><tr>' +
                '<th>Feature</th><th>Premium %</th><th>Premium $</th><th>Sample Size</th><th>Confidence</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';
        },

        /**
         * Format feature name for display
         */
        formatFeatureName: function(name) {
            var names = {
                'waterfront': 'Waterfront',
                'pool': 'Pool',
                'view': 'View',
                'garage_2plus': '2+ Car Garage',
                'finished_basement': 'Finished Basement'
            };
            return names[name] || name.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
        },

        /**
         * Load Chart.js dynamically
         */
        loadChartJs: function() {
            var self = this;
            return new Promise(function(resolve, reject) {
                if (window.Chart) {
                    self.chartJsLoaded = true;
                    resolve();
                    return;
                }

                var script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
                script.async = true;
                script.onload = function() {
                    self.chartJsLoaded = true;
                    resolve();
                };
                script.onerror = function() { reject(new Error('Failed to load Chart.js')); };
                document.head.appendChild(script);
            });
        },

        /**
         * Format currency value
         */
        formatCurrency: function(value) {
            if (!value && value !== 0) return 'N/A';
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                maximumFractionDigits: 0
            }).format(value);
        },

        /**
         * Show error message
         */
        showError: function(message) {
            var skeleton = this.container.querySelector('.mld-analytics-skeleton');
            if (skeleton) {
                skeleton.innerHTML = '<div class="mld-analytics-error">' +
                    '<span class="dashicons dashicons-warning"></span>' +
                    '<p>' + (message || 'Unable to load market analytics. Please try again later.') + '</p></div>';
            }
        }
    };

    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { PropertyAnalytics.init(); });
    } else {
        PropertyAnalytics.init();
    }
})();
