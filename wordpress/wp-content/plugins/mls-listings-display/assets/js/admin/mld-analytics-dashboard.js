/**
 * MLD Analytics Dashboard
 *
 * Real-time analytics dashboard with Chart.js visualizations.
 *
 * @package MLS_Listings_Display
 * @since 6.39.0
 */

(function($) {
    'use strict';

    // Dashboard state
    const state = {
        charts: {},
        refreshInterval: null,
        isPaused: false,
        filters: {
            web: true,
            ios: true
        },
        dateRange: '7d',
        activityItems: [],
        // v6.47.0: Activity stream state
        activity: {
            range: '15m',
            platform: '',
            loggedInOnly: false,
            page: 1,
            perPage: 50,
            total: 0,
            hasMore: false
        }
    };

    // Chart color palette
    const colors = {
        primary: '#2271b1',
        secondary: '#72aee6',
        success: '#00a32a',
        warning: '#dba617',
        danger: '#d63638',
        gray: '#8c8f94',
        web: '#2271b1',
        ios: '#00a32a',
        desktop: '#2271b1',
        mobile: '#72aee6',
        tablet: '#9ec2e6'
    };

    /**
     * Initialize dashboard
     */
    function init() {
        initCharts();
        bindEvents();
        loadInitialData();
        startAutoRefresh();
    }

    /**
     * Initialize Chart.js charts
     */
    function initCharts() {
        // Traffic trends chart
        const trafficCtx = document.getElementById('mld-traffic-chart');
        if (trafficCtx) {
            state.charts.traffic = new Chart(trafficCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Sessions',
                            data: [],
                            borderColor: colors.primary,
                            backgroundColor: colors.primary + '20',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Page Views',
                            data: [],
                            borderColor: colors.secondary,
                            backgroundColor: 'transparent',
                            borderDash: [5, 5],
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end'
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f0f0f1' }
                        }
                    }
                }
            });
        }

        // Traffic sources doughnut
        const sourcesCtx = document.getElementById('mld-sources-chart');
        if (sourcesCtx) {
            state.charts.sources = new Chart(sourcesCtx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [colors.primary, colors.success, colors.warning, colors.secondary, colors.gray]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // Devices doughnut
        const devicesCtx = document.getElementById('mld-devices-chart');
        if (devicesCtx) {
            state.charts.devices = new Chart(devicesCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Desktop', 'Mobile', 'Tablet'],
                    datasets: [{
                        data: [0, 0, 0],
                        backgroundColor: [colors.desktop, colors.mobile, colors.tablet]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Browsers doughnut
        const browsersCtx = document.getElementById('mld-browsers-chart');
        if (browsersCtx) {
            state.charts.browsers = new Chart(browsersCtx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [colors.primary, colors.success, colors.warning, colors.secondary, colors.danger]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Platforms bar chart
        const platformsCtx = document.getElementById('mld-platforms-chart');
        if (platformsCtx) {
            state.charts.platforms = new Chart(platformsCtx, {
                type: 'bar',
                data: {
                    labels: ['Web Desktop', 'Web Mobile', 'Web Tablet', 'iOS App'],
                    datasets: [{
                        data: [0, 0, 0, 0],
                        backgroundColor: [colors.desktop, colors.mobile, colors.tablet, colors.ios]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { beginAtZero: true }
                    }
                }
            });
        }
    }

    /**
     * Bind UI events
     */
    function bindEvents() {
        // Platform filters
        $('#mld-filter-web').on('change', function() {
            state.filters.web = this.checked;
            refreshData();
        });

        $('#mld-filter-ios').on('change', function() {
            state.filters.ios = this.checked;
            refreshData();
        });

        // Date range selector
        $('#mld-chart-range').on('change', function() {
            state.dateRange = this.value;
            loadTrendsData();
        });

        // Pause/resume activity stream
        $('#mld-pause-stream').on('click', function() {
            state.isPaused = !state.isPaused;
            const icon = state.isPaused ? 'controls-play' : 'controls-pause';
            $(this).find('.dashicons').removeClass('dashicons-controls-pause dashicons-controls-play')
                .addClass('dashicons-' + icon);
        });

        // Geo tabs
        $('.mld-geo-tab').on('click', function() {
            $('.mld-geo-tab').removeClass('active');
            $(this).addClass('active');
            loadGeoData($(this).data('tab'));
        });

        // v6.47.0: Activity stream filters
        $('#mld-activity-range').on('change', function() {
            state.activity.range = this.value;
            state.activity.page = 1; // Reset to first page
            loadActivityStream();
        });

        $('#mld-activity-platform').on('change', function() {
            state.activity.platform = this.value;
            state.activity.page = 1;
            loadActivityStream();
        });

        $('#mld-activity-logged-in-only').on('change', function() {
            state.activity.loggedInOnly = this.checked;
            state.activity.page = 1;
            loadActivityStream();
        });

        // v6.47.0: Pagination controls
        $('#mld-prev-page').on('click', function() {
            if (state.activity.page > 1) {
                state.activity.page--;
                loadActivityStream();
            }
        });

        $('#mld-next-page').on('click', function() {
            if (state.activity.hasMore) {
                state.activity.page++;
                loadActivityStream();
            }
        });

        // v6.47.0: Session journey panel
        $('#mld-close-journey').on('click', function() {
            $('#mld-journey-panel').hide();
        });

        // v6.47.0: Click on activity item to show journey
        $(document).on('click', '.mld-activity-item .mld-view-journey', function(e) {
            e.preventDefault();
            const sessionId = $(this).data('session-id');
            if (sessionId) {
                loadSessionJourney(sessionId);
            }
        });
    }

    /**
     * Start auto-refresh interval
     */
    function startAutoRefresh() {
        state.refreshInterval = setInterval(function() {
            if (!state.isPaused) {
                refreshData();
            }
        }, mldAnalytics.refreshRate);
    }

    /**
     * Load initial data
     */
    function loadInitialData() {
        loadRealtimeData();
        loadTrendsData();
        loadActivityStream();
        loadTopContent();
        loadTrafficSources();
        loadGeoData('cities');
        loadDbStats();
    }

    /**
     * Refresh all data
     */
    function refreshData() {
        loadRealtimeData();
        loadActivityStream();
        updateLastUpdated();
    }

    /**
     * Update last updated timestamp
     */
    function updateLastUpdated() {
        const now = new Date();
        const time = now.toLocaleTimeString();
        $('#mld-last-update-time').text(time);
    }

    /**
     * Make API request
     */
    function apiRequest(endpoint, params) {
        params = params || {};

        // Add platform filters from top checkboxes - but only if not already set
        // v6.47.1: Don't override specific platform filters (e.g., from activity stream dropdown)
        const originalPlatform = params.platform;
        if (!params.platform || params.platform === '') {
            if (state.filters.web && state.filters.ios) {
                params.platform = 'all';
            } else if (state.filters.web) {
                params.platform = 'web';
            } else if (state.filters.ios) {
                params.platform = 'ios_app';
            } else {
                params.platform = 'none';
            }
        }

        return $.ajax({
            url: mldAnalytics.restUrl + endpoint,
            method: 'GET',
            data: params,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', mldAnalytics.nonce);
            }
        });
    }

    /**
     * Load realtime data
     */
    function loadRealtimeData() {
        apiRequest('realtime').done(function(response) {
            if (response.success) {
                const data = response.data;

                // Update active count
                $('#mld-active-count').text(data.total || 0);

                // Update platform breakdown
                $('#mld-active-web .mld-count').text(data.web || 0);
                $('#mld-active-ios .mld-count').text(data.ios_app || 0);

                // Update current pages
                if (data.current_pages && data.current_pages.length > 0) {
                    let html = '';
                    data.current_pages.slice(0, 5).forEach(function(page) {
                        const platformClass = page.platform === 'ios_app' ? 'mld-dot-ios' : 'mld-dot-web';
                        html += '<div class="mld-current-page">';
                        html += '<span class="mld-platform-dot ' + platformClass + '"></span>';
                        html += '<span class="mld-page-path">' + escapeHtml(page.page || 'Unknown') + '</span>';
                        html += '</div>';
                    });
                    $('#mld-current-pages').html(html);
                } else {
                    $('#mld-current-pages').html('<div class="mld-no-activity">No active visitors</div>');
                }
            }
        });
    }

    /**
     * Load traffic trends data
     */
    function loadTrendsData() {
        apiRequest('trends', { range: state.dateRange }).done(function(response) {
            if (response.success && state.charts.traffic) {
                const data = response.data;

                state.charts.traffic.data.labels = data.labels || [];
                state.charts.traffic.data.datasets[0].data = data.sessions || [];
                state.charts.traffic.data.datasets[1].data = data.page_views || [];
                state.charts.traffic.update();
            }
        });
    }

    /**
     * Load activity stream
     * v6.47.0: Enhanced with filters, pagination, and user/referrer data
     */
    function loadActivityStream() {
        if (state.isPaused) return;

        const params = {
            limit: state.activity.perPage,
            page: state.activity.page,
            range: state.activity.range,
            platform: state.activity.platform,
            logged_in_only: state.activity.loggedInOnly ? 1 : 0
        };

        apiRequest('activity-stream', params).done(function(response) {
            if (response.success) {
                const data = response.data;
                const activities = data.events || [];

                // Update state
                state.activity.total = data.total || 0;
                state.activity.hasMore = data.has_more || false;

                let html = '';

                if (activities.length === 0) {
                    html = '<div class="mld-no-activity">No recent activity</div>';
                } else {
                    activities.forEach(function(activity) {
                        html += renderActivityItem(activity);
                    });
                }

                $('#mld-activity-stream').html(html);

                // Update pagination
                updateActivityPagination();
            }
        });
    }

    /**
     * Update activity pagination controls
     * v6.47.0
     */
    function updateActivityPagination() {
        const total = state.activity.total;
        const page = state.activity.page;
        const perPage = state.activity.perPage;
        const hasMore = state.activity.hasMore;

        // Show pagination if we have data
        if (total > 0) {
            const start = (page - 1) * perPage + 1;
            const end = Math.min(page * perPage, total);
            $('#mld-pagination-info').text('Showing ' + start + '-' + end + ' of ' + total);
            $('#mld-prev-page').prop('disabled', page <= 1);
            $('#mld-next-page').prop('disabled', !hasMore);
            $('#mld-activity-pagination').show();
        } else {
            $('#mld-activity-pagination').hide();
        }
    }

    /**
     * Load session journey
     * v6.47.0
     */
    function loadSessionJourney(sessionId) {
        $('#mld-journey-timeline').html('<div class="mld-loading">Loading journey...</div>');
        $('#mld-journey-panel').show();

        $.ajax({
            url: mldAnalytics.restUrl + 'session/' + sessionId + '/journey',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', mldAnalytics.nonce);
            }
        }).done(function(response) {
            if (response.success) {
                renderSessionJourney(response.data);
            } else {
                $('#mld-journey-timeline').html('<div class="mld-error">Failed to load journey</div>');
            }
        }).fail(function() {
            $('#mld-journey-timeline').html('<div class="mld-error">Failed to load journey</div>');
        });
    }

    /**
     * Render session journey in side panel
     * v6.47.0
     */
    function renderSessionJourney(data) {
        // Render session metadata
        let metaHtml = '<div class="mld-journey-header">';

        // User info
        if (data.session && data.session.user_id && data.session.display_name) {
            metaHtml += '<div class="mld-journey-user">';
            metaHtml += '<span class="mld-user-badge mld-user-logged-in">';
            metaHtml += '<span class="dashicons dashicons-admin-users"></span> ';
            metaHtml += escapeHtml(data.session.display_name);
            metaHtml += '</span>';
            if (data.session.user_email) {
                metaHtml += '<div class="mld-user-email">' + escapeHtml(data.session.user_email) + '</div>';
            }
            metaHtml += '</div>';
        } else if (data.session && data.session.visitor_hash) {
            metaHtml += '<div class="mld-journey-user">';
            metaHtml += '<span class="mld-user-badge mld-user-anonymous">';
            metaHtml += '<span class="dashicons dashicons-admin-users"></span> ';
            metaHtml += 'Visitor ' + data.session.visitor_hash.substring(0, 8) + '...';
            metaHtml += '</span>';
            if (data.is_returning) {
                metaHtml += '<span class="mld-returning-badge">Returning</span>';
            }
            metaHtml += '</div>';
        }

        // Location & device
        if (data.session) {
            metaHtml += '<div class="mld-journey-info">';
            if (data.session.city || data.session.country_code) {
                metaHtml += '<span class="mld-journey-location">';
                metaHtml += '<span class="dashicons dashicons-location"></span> ';
                metaHtml += escapeHtml([data.session.city, data.session.region, data.session.country_code].filter(Boolean).join(', '));
                metaHtml += '</span>';
            }
            if (data.session.device_type) {
                const deviceIcon = data.session.device_type === 'mobile' ? 'smartphone' :
                                  data.session.device_type === 'tablet' ? 'tablet' : 'desktop';
                metaHtml += '<span class="mld-journey-device">';
                metaHtml += '<span class="dashicons dashicons-' + deviceIcon + '"></span> ';
                metaHtml += escapeHtml(data.session.browser || 'Unknown');
                metaHtml += '</span>';
            }
            metaHtml += '</div>';

            // Source
            if (data.session.referrer_domain || data.source_name) {
                metaHtml += '<div class="mld-journey-source">';
                metaHtml += '<span class="mld-source-badge">';
                metaHtml += getSourceIcon(data.source_name || data.session.referrer_domain);
                metaHtml += ' ' + escapeHtml(data.source_name || data.session.referrer_domain || 'Direct');
                metaHtml += '</span>';
                metaHtml += '</div>';
            }
        }

        metaHtml += '</div>';
        $('#mld-journey-meta').html(metaHtml);

        // Render timeline
        const events = data.events || [];
        let timelineHtml = '<div class="mld-timeline">';

        if (events.length === 0) {
            timelineHtml += '<div class="mld-no-activity">No events recorded</div>';
        } else {
            events.forEach(function(event, index) {
                const isFirst = index === 0;
                const isLast = index === events.length - 1;
                const icon = getEventIcon(event.event_type);
                const label = getEventLabel(event.event_type);
                const time = formatJourneyTime(event.event_timestamp);

                timelineHtml += '<div class="mld-timeline-item' + (isLast ? ' mld-timeline-current' : '') + '">';
                timelineHtml += '<div class="mld-timeline-marker">';
                timelineHtml += '<span class="dashicons dashicons-' + icon + '"></span>';
                timelineHtml += '</div>';
                timelineHtml += '<div class="mld-timeline-content">';
                timelineHtml += '<div class="mld-timeline-time">' + time + '</div>';
                timelineHtml += '<div class="mld-timeline-event">' + label + '</div>';

                // Event details
                if (event.page_title || event.page_path) {
                    timelineHtml += '<div class="mld-timeline-detail">' + escapeHtml(event.page_title || event.page_path) + '</div>';
                }
                if (event.scroll_depth) {
                    timelineHtml += '<div class="mld-timeline-meta">Scrolled ' + event.scroll_depth + '%</div>';
                }
                if (event.time_on_page) {
                    timelineHtml += '<div class="mld-timeline-meta">' + formatDuration(event.time_on_page) + ' on page</div>';
                }

                timelineHtml += '</div></div>';
            });
        }

        timelineHtml += '</div>';
        $('#mld-journey-timeline').html(timelineHtml);
    }

    /**
     * Format timestamp for journey timeline
     * v6.47.0
     */
    function formatJourneyTime(timestamp) {
        if (!timestamp) return '';
        let normalizedTimestamp = timestamp;
        if (typeof timestamp === 'string' && timestamp.match(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)) {
            normalizedTimestamp = timestamp.replace(' ', 'T');
        }
        const date = new Date(normalizedTimestamp);
        if (isNaN(date.getTime())) return '';
        return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    /**
     * Get icon for traffic source
     * v6.47.0
     */
    function getSourceIcon(source) {
        if (!source) return '<span class="dashicons dashicons-admin-links"></span>';
        const sourceLower = source.toLowerCase();
        if (sourceLower.includes('google')) return '<span class="mld-source-icon mld-source-google">G</span>';
        if (sourceLower.includes('bing')) return '<span class="mld-source-icon mld-source-bing">b</span>';
        if (sourceLower.includes('facebook')) return '<span class="mld-source-icon mld-source-facebook">f</span>';
        if (sourceLower.includes('instagram')) return '<span class="mld-source-icon mld-source-instagram">📷</span>';
        if (sourceLower.includes('twitter') || sourceLower.includes('x.com')) return '<span class="mld-source-icon mld-source-twitter">𝕏</span>';
        if (sourceLower.includes('linkedin')) return '<span class="mld-source-icon mld-source-linkedin">in</span>';
        if (sourceLower.includes('zillow')) return '<span class="mld-source-icon mld-source-zillow">Z</span>';
        if (sourceLower.includes('realtor')) return '<span class="mld-source-icon mld-source-realtor">R</span>';
        if (sourceLower === 'direct') return '<span class="dashicons dashicons-migrate"></span>';
        return '<span class="dashicons dashicons-admin-links"></span>';
    }

    /**
     * Render single activity item - enhanced version with property details
     * v6.47.0: Added user badge, source badge, and journey link
     */
    function renderActivityItem(activity) {
        const platformClass = (activity.platform || '').includes('ios') ? 'mld-dot-ios' : 'mld-dot-web';
        const platformLabel = (activity.platform || '').includes('ios') ? 'iOS' : 'Web';
        const icon = getEventIcon(activity.event_type);
        const time = formatTime(activity.event_timestamp || activity.timestamp);
        const eventLabel = getEventLabel(activity.event_type);

        // v6.47.0: Build user badge
        let userBadge = '';
        if (activity.user_id && activity.user_display_name) {
            // Logged-in user
            userBadge = '<span class="mld-user-badge mld-user-logged-in" title="' + escapeHtml(activity.user_email || '') + '">';
            userBadge += '<span class="dashicons dashicons-admin-users"></span> ';
            userBadge += escapeHtml(activity.user_display_name);
            userBadge += '</span>';
        } else if (activity.visitor_hash) {
            // Anonymous visitor
            userBadge = '<span class="mld-user-badge mld-user-anonymous">';
            userBadge += 'Visitor ' + activity.visitor_hash.substring(0, 8) + '...';
            userBadge += '</span>';
            if (activity.is_returning) {
                userBadge += '<span class="mld-returning-badge">Returning</span>';
            }
        }

        // v6.47.0: Build source badge
        let sourceBadge = '';
        if (activity.source_name || activity.referrer_domain) {
            const sourceName = activity.source_name || activity.referrer_domain || 'Direct';
            sourceBadge = '<span class="mld-source-badge">';
            sourceBadge += getSourceIcon(sourceName);
            sourceBadge += ' ' + escapeHtml(sourceName);
            sourceBadge += '</span>';
        }

        // Build visitor info (location & device)
        let visitorInfo = '';
        if (activity.visitor_city || activity.visitor_country) {
            const location = activity.visitor_city || activity.visitor_country || '';
            visitorInfo = '<span class="mld-visitor-location"><span class="dashicons dashicons-location"></span> ' + escapeHtml(location) + '</span>';
        }
        if (activity.device_type) {
            const deviceIcon = activity.device_type === 'mobile' ? 'smartphone' :
                              activity.device_type === 'tablet' ? 'tablet' : 'desktop';
            visitorInfo += '<span class="dashicons dashicons-' + deviceIcon + '" title="' + escapeHtml(activity.device_type) + '"></span>';
        }

        // v6.47.0: Journey link (if session has multiple pages)
        let journeyLink = '';
        if (activity.session_id && activity.session_page_views && activity.session_page_views > 1) {
            journeyLink = '<a href="#" class="mld-view-journey" data-session-id="' + escapeHtml(activity.session_id) + '">';
            journeyLink += 'View journey (' + activity.session_page_views + ' pages) →';
            journeyLink += '</a>';
        }

        // Check if this is a property-related event
        const hasProperty = activity.listing_id && (activity.street_name || activity.list_price);

        if (hasProperty) {
            return renderPropertyActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink);
        } else if (activity.event_type === 'search_execute' || activity.event_type === 'search') {
            return renderSearchActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink);
        } else {
            return renderGenericActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink);
        }
    }

    /**
     * Render property-related activity item with photo and details
     * v6.47.0: Added user badge, source badge, journey link
     */
    function renderPropertyActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink) {
        const address = buildPropertyAddress(activity);
        const price = activity.list_price ? formatPrice(activity.list_price) : '';
        const beds = activity.bedrooms_total ? activity.bedrooms_total + ' bed' : '';
        const baths = activity.bathrooms_total ? activity.bathrooms_total + ' bath' : '';
        const propType = activity.property_sub_type || '';
        const photo = activity.main_photo_url || '';

        let details = [beds, baths, propType].filter(Boolean).join(' · ');

        let photoHtml = '';
        if (photo) {
            photoHtml = '<div class="mld-activity-photo"><img src="' + escapeHtml(photo) + '" alt="" loading="lazy"></div>';
        }

        return '<div class="mld-activity-item mld-activity-property">' +
            '<div class="mld-activity-header">' +
                '<span class="mld-platform-badge ' + platformClass + '">' + platformLabel + '</span>' +
                (userBadge ? userBadge : '') +
                (sourceBadge ? sourceBadge : '') +
                '<span class="mld-activity-time">' + time + '</span>' +
            '</div>' +
            '<div class="mld-activity-subheader">' +
                '<span class="mld-event-label mld-event-' + activity.event_type + '">' +
                    '<span class="dashicons dashicons-' + icon + '"></span> ' + eventLabel +
                '</span>' +
                (visitorInfo ? '<span class="mld-visitor-info">' + visitorInfo + '</span>' : '') +
            '</div>' +
            '<div class="mld-activity-body">' +
                photoHtml +
                '<div class="mld-activity-details">' +
                    '<div class="mld-property-address">' + escapeHtml(address) + '</div>' +
                    (price ? '<div class="mld-property-price">' + price + '</div>' : '') +
                    (details ? '<div class="mld-property-meta">' + escapeHtml(details) + '</div>' : '') +
                '</div>' +
            '</div>' +
            (journeyLink ? '<div class="mld-activity-footer">' + journeyLink + '</div>' : '') +
        '</div>';
    }

    /**
     * Render search activity item
     * v6.47.0: Added user badge, source badge, journey link
     */
    function renderSearchActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink) {
        let searchInfo = '';
        if (activity.search_query) {
            try {
                const query = typeof activity.search_query === 'string' ?
                    JSON.parse(activity.search_query) : activity.search_query;
                searchInfo = formatSearchQuery(query);
            } catch (e) {
                searchInfo = activity.search_query;
            }
        }
        const resultsCount = activity.search_results_count ?
            activity.search_results_count + ' results' : '';

        return '<div class="mld-activity-item mld-activity-search">' +
            '<div class="mld-activity-header">' +
                '<span class="mld-platform-badge ' + platformClass + '">' + platformLabel + '</span>' +
                (userBadge ? userBadge : '') +
                (sourceBadge ? sourceBadge : '') +
                '<span class="mld-activity-time">' + time + '</span>' +
            '</div>' +
            '<div class="mld-activity-subheader">' +
                '<span class="mld-event-label mld-event-search">' +
                    '<span class="dashicons dashicons-' + icon + '"></span> ' + eventLabel +
                '</span>' +
                (visitorInfo ? '<span class="mld-visitor-info">' + visitorInfo + '</span>' : '') +
            '</div>' +
            '<div class="mld-activity-body">' +
                '<div class="mld-search-info">' +
                    (searchInfo ? '<div class="mld-search-query">' + escapeHtml(searchInfo) + '</div>' : '<div class="mld-search-query">Browsing listings</div>') +
                    (resultsCount ? '<div class="mld-search-results">' + resultsCount + '</div>' : '') +
                '</div>' +
            '</div>' +
            (journeyLink ? '<div class="mld-activity-footer">' + journeyLink + '</div>' : '') +
        '</div>';
    }

    /**
     * Render generic activity item
     * v6.47.0: Added user badge, source badge, journey link
     */
    function renderGenericActivityItem(activity, platformClass, platformLabel, icon, eventLabel, time, visitorInfo, userBadge, sourceBadge, journeyLink) {
        let context = '';
        if (activity.page_title) {
            // Clean up page title
            context = activity.page_title.split('–')[0].trim();
        } else if (activity.page_path) {
            context = activity.page_path;
        }

        // Add extra info for specific event types
        let extraInfo = '';
        if (activity.event_type === 'scroll_depth' && activity.scroll_depth) {
            extraInfo = '<span class="mld-scroll-depth">' + activity.scroll_depth + '% scrolled</span>';
        } else if (activity.event_type === 'time_on_page' && activity.time_on_page) {
            extraInfo = '<span class="mld-time-spent">' + formatDuration(activity.time_on_page) + ' on page</span>';
        }

        return '<div class="mld-activity-item mld-activity-generic">' +
            '<div class="mld-activity-header">' +
                '<span class="mld-platform-badge ' + platformClass + '">' + platformLabel + '</span>' +
                (userBadge ? userBadge : '') +
                (sourceBadge ? sourceBadge : '') +
                '<span class="mld-activity-time">' + time + '</span>' +
            '</div>' +
            '<div class="mld-activity-subheader">' +
                '<span class="mld-event-label">' +
                    '<span class="dashicons dashicons-' + icon + '"></span> ' + eventLabel +
                '</span>' +
                (visitorInfo ? '<span class="mld-visitor-info">' + visitorInfo + '</span>' : '') +
            '</div>' +
            (context || extraInfo ? '<div class="mld-activity-body">' +
                (context ? '<div class="mld-activity-context">' + escapeHtml(context) + '</div>' : '') +
                extraInfo +
            '</div>' : '') +
            (journeyLink ? '<div class="mld-activity-footer">' + journeyLink + '</div>' : '') +
        '</div>';
    }

    /**
     * Build property address from activity data
     */
    function buildPropertyAddress(activity) {
        if (activity.street_number && activity.street_name) {
            let addr = activity.street_number + ' ' + activity.street_name;
            // Handle both field names: property_city_db (activity stream) and property_city (top properties)
            const city = activity.property_city_db || activity.property_city;
            if (city) {
                addr += ', ' + city;
            }
            return addr;
        }
        // Fallback: try to extract from page_title
        if (activity.page_title) {
            const match = activity.page_title.match(/^([^–]+)/);
            if (match) return match[1].trim();
        }
        return activity.listing_id ? 'Property #' + activity.listing_id : null;
    }

    /**
     * Format search query object into readable string
     */
    function formatSearchQuery(query) {
        const parts = [];
        if (query.city || query.City) parts.push(query.city || query.City);
        if (query.min_price || query.price_min) {
            parts.push('$' + formatNumber(query.min_price || query.price_min) + '+');
        }
        if (query.max_price || query.price_max) {
            parts.push('up to $' + formatNumber(query.max_price || query.price_max));
        }
        if (query.beds || query.bedrooms) {
            parts.push((query.beds || query.bedrooms) + '+ beds');
        }
        if (query.property_type || query.PropertyType) {
            parts.push(query.property_type || query.PropertyType);
        }
        return parts.length > 0 ? parts.join(', ') : 'All properties';
    }

    /**
     * Get human-readable label for event type
     */
    function getEventLabel(eventType) {
        const labels = {
            'page_view': 'Viewed page',
            'property_view': 'Viewed property',
            'search_execute': 'Searched',
            'search': 'Searched',
            'contact_click': 'Clicked contact',
            'contact_submit': 'Sent inquiry',
            'share_click': 'Shared property',
            'schedule_click': 'Scheduling tour',
            'favorite_add': 'Saved to favorites',
            'favorite_remove': 'Removed favorite',
            'photo_view': 'Viewing photos',
            'scroll_depth': 'Reading page',
            'time_on_page': 'Time spent',
            'map_zoom': 'Using map',
            'map_pan': 'Exploring map',
            'property_click': 'Clicked property',
            'cta_click': 'Clicked CTA',
            'external_click': 'Clicked external link'
        };
        return labels[eventType] || eventType.replace(/_/g, ' ');
    }

    /**
     * Get icon for event type
     */
    function getEventIcon(eventType) {
        const icons = {
            'page_view': 'visibility',
            'property_view': 'admin-home',
            'search': 'search',
            'search_execute': 'search',
            'contact_click': 'email',
            'contact_submit': 'email-alt',
            'share_click': 'share',
            'schedule_click': 'calendar-alt',
            'favorite_add': 'heart',
            'favorite_remove': 'heart',
            'photo_view': 'format-gallery',
            'scroll_depth': 'sort',
            'time_on_page': 'clock',
            'map_zoom': 'location',
            'map_pan': 'location-alt',
            'property_click': 'admin-home',
            'cta_click': 'megaphone',
            'external_click': 'external'
        };
        return icons[eventType] || 'marker';
    }

    /**
     * Format price
     */
    function formatPrice(price) {
        if (!price) return '';
        return '$' + new Intl.NumberFormat().format(price);
    }

    /**
     * Load top content
     */
    function loadTopContent() {
        // Top pages
        apiRequest('top-content', { type: 'pages' }).done(function(response) {
            if (response.success) {
                renderTopTable('#mld-top-pages tbody', response.data, ['page_path', 'views', 'avg_time_on_page']);
            }
        });

        // Top properties - use enhanced renderer
        apiRequest('top-content', { type: 'properties' }).done(function(response) {
            if (response.success) {
                renderTopProperties('#mld-top-properties tbody', response.data);
            }
        });

        // Top searches (v6.54.0)
        loadTopSearches();
    }

    /**
     * Load top searches (v6.54.0)
     */
    function loadTopSearches() {
        apiRequest('top-searches', { limit: 10 }).done(function(response) {
            if (response.success) {
                renderTopSearches('#mld-top-searches tbody', response.data);
            }
        });
    }

    /**
     * Render top searches table (v6.54.0)
     */
    function renderTopSearches(selector, data) {
        const $tbody = $(selector);
        const searches = data.searches || [];

        if (!searches || searches.length === 0) {
            $tbody.html('<tr><td colspan="3" class="mld-no-data">No search data available</td></tr>');
            return;
        }

        let html = '';
        searches.forEach(function(item) {
            html += '<tr>';
            html += '<td class="mld-search-query-cell">';
            html += '<span class="dashicons dashicons-search mld-search-icon"></span> ';
            html += escapeHtml(item.query || 'Unknown');
            html += '</td>';
            html += '<td class="mld-count-cell">' + formatNumber(item.count || 0) + '</td>';
            html += '<td class="mld-percent-cell">' + (item.percentage || 0) + '%</td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * Render top properties table with enhanced data
     */
    function renderTopProperties(selector, data) {
        const $tbody = $(selector);

        if (!data || data.length === 0) {
            $tbody.html('<tr><td colspan="3" class="mld-no-data">No data available</td></tr>');
            return;
        }

        let html = '';
        data.slice(0, 10).forEach(function(item) {
            // Build address from components
            let address = buildPropertyAddress(item);
            if (!address) {
                address = 'MLS# ' + (item.listing_id || 'Unknown');
            }

            // Format price
            let price = '';
            if (item.list_price) {
                price = '$' + Number(item.list_price).toLocaleString();
            }

            // Format bed/bath
            let bedBath = '';
            if (item.bedrooms_total || item.bathrooms_total) {
                bedBath = (item.bedrooms_total || 0) + ' bed • ' + (item.bathrooms_total || 0) + ' bath';
            }

            // Property URL
            const propertyUrl = mldAnalytics.siteUrl + '/property/' + item.listing_id + '/';

            html += '<tr class="mld-property-row">';
            html += '<td class="mld-property-cell">';

            // Photo thumbnail if available
            if (item.main_photo_url) {
                html += '<img src="' + escapeHtml(item.main_photo_url) + '" class="mld-property-thumb" alt="" />';
            }

            // Property info
            html += '<div class="mld-property-info">';
            html += '<a href="' + escapeHtml(propertyUrl) + '" target="_blank" class="mld-property-link">' + escapeHtml(address) + '</a>';
            if (price || bedBath) {
                html += '<div class="mld-property-meta">';
                if (price) html += '<span class="mld-property-price">' + price + '</span>';
                if (price && bedBath) html += ' • ';
                if (bedBath) html += '<span class="mld-property-beds">' + bedBath + '</span>';
                html += '</div>';
            }
            html += '</div>';

            html += '</td>';
            html += '<td class="mld-views-cell">' + (item.views || 0) + '</td>';
            html += '<td class="mld-viewers-cell">' + (item.unique_viewers || 0) + '</td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * Render top content table (for Top Pages)
     */
    function renderTopTable(selector, data, fields) {
        const $tbody = $(selector);

        if (!data || data.length === 0) {
            $tbody.html('<tr><td colspan="3" class="mld-no-data">No data available</td></tr>');
            return;
        }

        let html = '';
        data.slice(0, 10).forEach(function(item) {
            html += '<tr>';
            fields.forEach(function(field) {
                let value = item[field] || '--';

                if (field === 'page_path') {
                    // Render page path with better labels
                    value = renderPagePath(item);
                } else if (field === 'listing_id') {
                    value = '<span title="' + escapeHtml(value) + '">' + truncate(value, 40) + '</span>';
                } else if (field === 'avg_time_on_page') {
                    value = formatDuration(value);
                }
                html += '<td>' + value + '</td>';
            });
            html += '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * Render page path with better labels
     */
    function renderPagePath(item) {
        const path = item.page_path || '';
        const title = item.page_title || '';

        // Skip malformed wp-admin URLs (from previous bugs)
        if (path.includes('/wp-admin/') || path.includes('undefined')) {
            return '<span class="mld-invalid-path" title="Invalid tracking data">Invalid URL</span>';
        }

        // Property page - make it a link
        if (path.startsWith('/property/')) {
            // Extract listing ID from path
            const match = path.match(/\/property\/(\d+)\/?$/);
            if (match) {
                const listingId = match[1];
                const url = mldAnalytics.siteUrl + path;
                // Use page title if available, otherwise show "Property #ID"
                const label = title ? truncate(title.split('–')[0].trim(), 35) : 'Property #' + listingId;
                return '<a href="' + escapeHtml(url) + '" target="_blank" class="mld-page-link">' + escapeHtml(label) + '</a>';
            }
            // SEO-friendly URL - extract address from slug
            const seoMatch = path.match(/\/property\/([^\/]+)\/?$/);
            if (seoMatch) {
                const slug = seoMatch[1];
                // Extract listing ID from end of slug (after last hyphen)
                const idMatch = slug.match(/-(\d+)$/);
                const listingId = idMatch ? idMatch[1] : null;
                const url = listingId ? (mldAnalytics.siteUrl + '/property/' + listingId + '/') : (mldAnalytics.siteUrl + path);
                const label = title ? truncate(title.split('–')[0].trim(), 35) : formatSlugAsTitle(slug);
                return '<a href="' + escapeHtml(url) + '" target="_blank" class="mld-page-link">' + escapeHtml(label) + '</a>';
            }
        }

        // Search page
        if (path === '/search/' || path === '/search') {
            return '<span class="mld-page-type">Search Page</span>';
        }

        // Home page
        if (path === '/' || path === '') {
            return '<span class="mld-page-type">Home Page</span>';
        }

        // Schools page
        if (path.startsWith('/schools/')) {
            const label = title || 'Schools: ' + path.replace('/schools/', '').replace(/\/$/, '');
            return '<span class="mld-page-type" title="' + escapeHtml(path) + '">' + truncate(label, 35) + '</span>';
        }

        // Use page title if available
        if (title && title !== path) {
            return '<span title="' + escapeHtml(path) + '">' + truncate(title, 35) + '</span>';
        }

        // Fallback to path
        return '<span title="' + escapeHtml(path) + '">' + truncate(path, 35) + '</span>';
    }

    /**
     * Format URL slug as readable title
     */
    function formatSlugAsTitle(slug) {
        // Remove listing ID from end
        slug = slug.replace(/-\d+$/, '');
        // Replace hyphens with spaces and capitalize
        return slug.split('-').map(function(word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }

    /**
     * Load traffic sources
     */
    function loadTrafficSources() {
        apiRequest('traffic-sources').done(function(response) {
            if (response.success) {
                const data = response.data || [];

                // Update chart
                if (state.charts.sources && data.length > 0) {
                    const labels = data.slice(0, 5).map(function(d) { return d.source || 'Direct'; });
                    const values = data.slice(0, 5).map(function(d) { return d.sessions || 0; });

                    state.charts.sources.data.labels = labels;
                    state.charts.sources.data.datasets[0].data = values;
                    state.charts.sources.update();
                }

                // Update table
                const total = data.reduce(function(sum, d) { return sum + (d.sessions || 0); }, 0);
                let html = '';
                data.slice(0, 8).forEach(function(item) {
                    const pct = total > 0 ? Math.round((item.sessions / total) * 100) : 0;
                    html += '<tr>';
                    html += '<td>' + escapeHtml(item.source || 'Direct') + '</td>';
                    html += '<td>' + (item.sessions || 0) + '</td>';
                    html += '<td>' + pct + '%</td>';
                    html += '</tr>';
                });

                if (html === '') {
                    html = '<tr><td colspan="3" class="mld-no-data">No data available</td></tr>';
                }

                $('#mld-traffic-sources tbody').html(html);
            }
        });
    }

    /**
     * Load geographic data
     */
    function loadGeoData(type) {
        apiRequest('geographic', { type: type }).done(function(response) {
            if (response.success) {
                const data = response.data || [];
                const total = data.reduce(function(sum, d) { return sum + (parseInt(d.sessions) || 0); }, 0);

                let html = '';
                data.slice(0, 10).forEach(function(item) {
                    const sessions = parseInt(item.sessions) || 0;
                    const pct = total > 0 ? Math.round((sessions / total) * 100) : 0;

                    // Build location string from city/region/country fields
                    let location = '';
                    if (type === 'cities') {
                        location = item.city || '';
                        if (item.region) {
                            location += (location ? ', ' : '') + item.region;
                        }
                    } else {
                        location = item.country_name || item.country_code || '';
                    }
                    location = location || 'Unknown';

                    html += '<tr>';
                    html += '<td>' + escapeHtml(location) + '</td>';
                    html += '<td>' + sessions + '</td>';
                    html += '<td>' + pct + '%</td>';
                    html += '</tr>';
                });

                if (html === '') {
                    html = '<tr><td colspan="3" class="mld-no-data">No data available</td></tr>';
                }

                $('#mld-geo-table tbody').html(html);
            }
        });
    }

    /**
     * Load database stats
     */
    function loadDbStats() {
        apiRequest('db-stats').done(function(response) {
            if (response.success) {
                const data = response.data;
                $('#mld-db-sessions').text(formatNumber(data.sessions || 0));
                $('#mld-db-events').text(formatNumber(data.events || 0));
                $('#mld-db-hourly').text(formatNumber(data.hourly || 0));
                $('#mld-db-daily').text(formatNumber(data.daily || 0));

                // Update device charts if data available
                if (data.devices && state.charts.devices) {
                    state.charts.devices.data.datasets[0].data = [
                        data.devices.desktop || 0,
                        data.devices.mobile || 0,
                        data.devices.tablet || 0
                    ];
                    state.charts.devices.update();
                }

                if (data.browsers && state.charts.browsers) {
                    const labels = Object.keys(data.browsers).slice(0, 5);
                    const values = labels.map(function(b) { return data.browsers[b]; });
                    state.charts.browsers.data.labels = labels;
                    state.charts.browsers.data.datasets[0].data = values;
                    state.charts.browsers.update();
                }

                if (data.platforms) {
                    $('#mld-platform-desktop').text(formatNumber(data.platforms.web_desktop || 0));
                    $('#mld-platform-mobile').text(formatNumber(data.platforms.web_mobile || 0));
                    $('#mld-platform-ios').text(formatNumber(data.platforms.ios_app || 0));

                    if (state.charts.platforms) {
                        state.charts.platforms.data.datasets[0].data = [
                            data.platforms.web_desktop || 0,
                            data.platforms.web_mobile || 0,
                            data.platforms.web_tablet || 0,
                            data.platforms.ios_app || 0
                        ];
                        state.charts.platforms.update();
                    }
                }
            }
        });
    }

    // Utility functions

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num || 0);
    }

    function formatDuration(seconds) {
        if (!seconds || isNaN(seconds) || seconds < 1) return '--';
        if (seconds < 60) return Math.round(seconds) + 's';
        const mins = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);
        return mins + 'm ' + secs + 's';
    }

    function formatTime(timestamp) {
        if (!timestamp) return '';
        // Handle MySQL datetime format (2026-01-05 12:00:00) by converting to ISO 8601
        let normalizedTimestamp = timestamp;
        if (typeof timestamp === 'string' && timestamp.match(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)) {
            normalizedTimestamp = timestamp.replace(' ', 'T');
        }
        const date = new Date(normalizedTimestamp);
        // Check for invalid date
        if (isNaN(date.getTime())) {
            return '';
        }
        const now = new Date();
        const diff = (now - date) / 1000;

        if (diff < 0) return 'Just now'; // Future timestamps (clock skew)
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleDateString();
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
