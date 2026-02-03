/**
 * MLD Analytics Tracker
 * Sprint 5: Client Analytics (Enhanced v6.38.0)
 *
 * Tracks comprehensive user activity for agent client analytics dashboard.
 * Events are queued and flushed in batches to minimize API calls.
 *
 * Tracks: page views, property views, searches, filters, map interactions,
 * photo gallery, contact clicks, saved searches, scroll depth, time on page.
 */
(function() {
    'use strict';

    // Check if we should track (requires user to be logged in)
    if (typeof mldAnalyticsConfig === 'undefined' || !mldAnalyticsConfig.userId) {
        return;
    }

    const MLD_Analytics = {
        // Configuration
        config: {
            flushInterval: 30000, // 30 seconds
            maxQueueSize: 10,
            apiBase: mldAnalyticsConfig.apiBase || '/wp-json/mld-mobile/v1',
            userId: mldAnalyticsConfig.userId,
            nonce: mldAnalyticsConfig.nonce,
            engagementInterval: 30000, // Time on page ping every 30s
            debounceDelay: 1000 // Debounce for high-frequency events
        },

        // Event queue
        queue: [],

        // Session management
        sessionId: null,
        sessionStartTime: null,
        lastActivityTime: null,
        sessionTimeout: 30 * 60 * 1000, // 30 minutes

        // Engagement tracking state
        pageLoadTime: null,
        maxScrollDepth: 0,
        scrollMilestones: { 25: false, 50: false, 75: false, 100: false },
        engagementTimer: null,

        // Debounce timers
        debounceTimers: {},

        /**
         * Initialize the tracker
         */
        init: function() {
            this.initSession();
            this.startFlushTimer();
            this.bindEvents();

            // Initialize engagement tracking (scroll depth, time on page)
            this.initEngagementTracking();

            // Track initial page view
            this.trackPageView();

            // Auto-detect and track property detail pages
            this.detectPropertyView();

            // Handle page unload
            window.addEventListener('beforeunload', () => {
                this.flush(true);
            });

            // Handle visibility change for session management
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.flush(true);
                } else if (document.visibilityState === 'visible') {
                    this.checkSessionTimeout();
                }
            });
        },

        /**
         * Initialize or restore session
         */
        initSession: function() {
            const stored = sessionStorage.getItem('mld_analytics_session');
            const now = Date.now();

            if (stored) {
                const session = JSON.parse(stored);
                const elapsed = now - session.lastActivity;

                if (elapsed < this.sessionTimeout) {
                    // Restore existing session
                    this.sessionId = session.id;
                    this.sessionStartTime = session.startTime;
                    this.lastActivityTime = now;
                    this.saveSession();
                    return;
                }
            }

            // Start new session
            this.startNewSession();
        },

        /**
         * Start a new session
         */
        startNewSession: function() {
            const now = Date.now();
            this.sessionId = 'web_' + now + '_' + Math.random().toString(36).substr(2, 9);
            this.sessionStartTime = now;
            this.lastActivityTime = now;
            this.saveSession();

            // Send session start event to server
            this.sendSessionEvent('start');
        },

        /**
         * Save session to sessionStorage
         */
        saveSession: function() {
            sessionStorage.setItem('mld_analytics_session', JSON.stringify({
                id: this.sessionId,
                startTime: this.sessionStartTime,
                lastActivity: this.lastActivityTime
            }));
        },

        /**
         * Check if session has timed out
         */
        checkSessionTimeout: function() {
            const now = Date.now();
            const elapsed = now - this.lastActivityTime;

            if (elapsed >= this.sessionTimeout) {
                // Session timed out, start new one
                this.startNewSession();
            } else {
                this.lastActivityTime = now;
                this.saveSession();
            }
        },

        /**
         * Send session event to server
         */
        sendSessionEvent: function(action) {
            const data = {
                session_id: this.sessionId,
                action: action,
                platform: 'web',
                device_info: this.getDeviceInfo(),
                user_id: this.config.userId  // Include for sendBeacon auth
            };

            if (action === 'end') {
                data.duration = Math.round((Date.now() - this.sessionStartTime) / 1000);
            }

            this.sendToServer('/analytics/session', data, true);
        },

        /**
         * Get device info string
         */
        getDeviceInfo: function() {
            const ua = navigator.userAgent;
            const mobile = /Mobile|Android|iPhone|iPad/.test(ua);
            return (mobile ? 'Mobile' : 'Desktop') + ' Web';
        },

        /**
         * Track an activity event
         */
        track: function(activityType, entityId, entityType, metadata) {
            this.lastActivityTime = Date.now();
            this.saveSession();

            const event = {
                activity_type: activityType,
                entity_id: entityId || null,
                entity_type: entityType || null,
                metadata: metadata || {},
                timestamp: new Date().toISOString()
            };

            this.queue.push(event);

            // Flush if queue is full
            if (this.queue.length >= this.config.maxQueueSize) {
                this.flush();
            }
        },

        /**
         * Track page view
         */
        trackPageView: function() {
            const path = window.location.pathname;
            this.track('page_view', path, 'page', {
                url: window.location.href,
                referrer: document.referrer
            });
        },

        /**
         * Auto-detect property detail pages and track property view
         * Property URLs: /property/{listing_id}/ or /listing/{listing_key}/
         */
        detectPropertyView: function() {
            const path = window.location.pathname;

            // Match /property/12345678/ pattern (listing_id)
            const propertyMatch = path.match(/\/property\/(\d+)\/?/);
            if (propertyMatch) {
                const listingId = propertyMatch[1];
                this.trackPropertyView(listingId, {
                    source: 'page_load',
                    url: window.location.href
                });
                return;
            }

            // Match /listing/{hash}/ pattern (listing_key)
            const listingMatch = path.match(/\/listing\/([a-f0-9]+)\/?/i);
            if (listingMatch) {
                const listingKey = listingMatch[1];
                this.trackPropertyView(listingKey, {
                    source: 'page_load',
                    url: window.location.href
                });
            }
        },

        /**
         * Track property view
         */
        trackPropertyView: function(listingKey, metadata) {
            this.track('property_view', listingKey, 'property', metadata || {});
        },

        /**
         * Track search run
         */
        trackSearch: function(filters, resultCount) {
            this.track('search_run', null, 'search', {
                filters: filters,
                result_count: resultCount
            });
        },

        /**
         * Track filter used
         */
        trackFilterUsed: function(filterName, filterValue) {
            this.track('filter_used', filterName, 'filter', {
                value: filterValue
            });
        },

        /**
         * Track favorite add/remove
         */
        trackFavorite: function(listingKey, added, metadata) {
            this.track(added ? 'favorite_add' : 'favorite_remove', listingKey, 'property', metadata || {});
        },

        /**
         * Track hidden add/remove
         */
        trackHidden: function(listingKey, added, metadata) {
            this.track(added ? 'hidden_add' : 'hidden_remove', listingKey, 'property', metadata || {});
        },

        /**
         * Track saved search creation
         */
        trackSavedSearch: function(searchId, searchName) {
            this.track('search_save', searchId, 'saved_search', {
                name: searchName
            });
        },

        // ==========================================
        // ENHANCED TRACKING METHODS (v6.38.0)
        // ==========================================

        /**
         * Debounce helper for high-frequency events
         */
        debounce: function(key, callback, delay) {
            if (this.debounceTimers[key]) {
                clearTimeout(this.debounceTimers[key]);
            }
            this.debounceTimers[key] = setTimeout(() => {
                callback();
                delete this.debounceTimers[key];
            }, delay || this.config.debounceDelay);
        },

        // --- Search & Filter Events ---

        /**
         * Track search execution
         */
        trackSearchExecute: function(filters, resultCount, searchType) {
            this.track('search_execute', null, 'search', {
                filters: filters || {},
                result_count: resultCount || 0,
                search_type: searchType || 'list' // 'list' or 'map'
            });
        },

        /**
         * Track filter apply
         */
        trackFilterApply: function(filterName, filterValue, previousValue) {
            this.track('filter_apply', filterName, 'filter', {
                value: filterValue,
                previous_value: previousValue || null
            });
        },

        /**
         * Track filter clear
         */
        trackFilterClear: function(filterName, clearedValue) {
            this.track('filter_clear', filterName, 'filter', {
                cleared_value: clearedValue
            });
        },

        /**
         * Track filter modal open
         */
        trackFilterModalOpen: function(currentFilterCount) {
            this.track('filter_modal_open', null, 'ui', {
                current_filter_count: currentFilterCount || 0
            });
        },

        /**
         * Track filter modal close
         */
        trackFilterModalClose: function(filtersChanged) {
            this.track('filter_modal_close', null, 'ui', {
                filters_changed: filtersChanged || false
            });
        },

        /**
         * Track autocomplete selection
         */
        trackAutocompleteSelect: function(suggestionType, suggestionValue) {
            this.track('autocomplete_select', suggestionValue, 'search', {
                suggestion_type: suggestionType
            });
        },

        // --- Map Interaction Events ---

        /**
         * Track map zoom (debounced)
         */
        trackMapZoom: function(zoomLevel, bounds) {
            this.debounce('map_zoom', () => {
                this.track('map_zoom', null, 'map', {
                    zoom_level: zoomLevel,
                    bounds: bounds || null
                });
            });
        },

        /**
         * Track map pan (debounced)
         */
        trackMapPan: function(centerLat, centerLng) {
            this.debounce('map_pan', () => {
                this.track('map_pan', null, 'map', {
                    center_lat: centerLat,
                    center_lng: centerLng
                });
            });
        },

        /**
         * Track draw mode start
         */
        trackMapDrawStart: function() {
            this.track('map_draw_start', null, 'map', {});
        },

        /**
         * Track draw complete
         */
        trackMapDrawComplete: function(vertexCount, areaSqMi) {
            this.track('map_draw_complete', null, 'map', {
                vertex_count: vertexCount || 0,
                area_sqmi: areaSqMi || null
            });
        },

        /**
         * Track marker click
         */
        trackMarkerClick: function(listingId) {
            this.track('marker_click', listingId, 'property', {});
        },

        /**
         * Track cluster click
         */
        trackClusterClick: function(propertyCount) {
            this.track('cluster_click', null, 'map', {
                property_count: propertyCount
            });
        },

        // --- Property Detail Events ---

        /**
         * Track photo view in gallery
         */
        trackPhotoView: function(listingId, photoIndex, totalPhotos) {
            this.track('photo_view', listingId, 'property', {
                photo_index: photoIndex,
                total_photos: totalPhotos
            });
        },

        /**
         * Track lightbox open
         */
        trackPhotoLightboxOpen: function(listingId, photoIndex) {
            this.track('photo_lightbox_open', listingId, 'property', {
                photo_index: photoIndex
            });
        },

        /**
         * Track lightbox close
         */
        trackPhotoLightboxClose: function(listingId, photosViewedCount) {
            this.track('photo_lightbox_close', listingId, 'property', {
                photos_viewed_count: photosViewedCount
            });
        },

        /**
         * Track tab click on property page
         */
        trackTabClick: function(listingId, tabName) {
            this.track('tab_click', listingId, 'property', {
                tab_name: tabName
            });
        },

        /**
         * Track video play
         */
        trackVideoPlay: function(listingId, videoType) {
            this.track('video_play', listingId, 'property', {
                video_type: videoType || 'tour'
            });
        },

        /**
         * Track street view open
         */
        trackStreetViewOpen: function(listingId) {
            this.track('street_view_open', listingId, 'property', {});
        },

        /**
         * Track mortgage calculator use
         */
        trackCalculatorUse: function(listingId, downPaymentPct, loanTerm) {
            this.track('calculator_use', listingId, 'property', {
                down_payment_pct: downPaymentPct,
                loan_term: loanTerm
            });
        },

        /**
         * Track school info section view
         */
        trackSchoolInfoView: function(listingId, schoolCount) {
            this.track('school_info_view', listingId, 'property', {
                school_count: schoolCount
            });
        },

        /**
         * Track similar homes click
         */
        trackSimilarHomesClick: function(listingId, clickedListingId) {
            this.track('similar_homes_click', listingId, 'property', {
                clicked_listing_id: clickedListingId
            });
        },

        // --- User Action Events ---

        /**
         * Track contact button click
         */
        trackContactClick: function(listingId, contactType) {
            this.track('contact_click', listingId, 'property', {
                contact_type: contactType || 'message' // message, call, tour
            });
        },

        /**
         * Track contact form submission
         */
        trackContactFormSubmit: function(listingId, formType) {
            this.track('contact_form_submit', listingId, 'property', {
                form_type: formType || 'inquiry'
            });
        },

        /**
         * Track share button click
         */
        trackShareClick: function(listingId, shareMethod) {
            this.track('share_click', listingId, 'property', {
                share_method: shareMethod || 'copy_link'
            });
        },

        // --- Saved Search Events ---

        /**
         * Track saved search view (when executed)
         */
        trackSavedSearchView: function(searchId, resultCount) {
            this.track('saved_search_view', searchId, 'saved_search', {
                result_count: resultCount
            });
        },

        /**
         * Track saved search edit
         */
        trackSavedSearchEdit: function(searchId, changes) {
            this.track('saved_search_edit', searchId, 'saved_search', {
                changes: changes
            });
        },

        /**
         * Track saved search delete
         */
        trackSavedSearchDelete: function(searchId) {
            this.track('saved_search_delete', searchId, 'saved_search', {});
        },

        /**
         * Track alert toggle
         */
        trackAlertToggle: function(searchId, enabled) {
            this.track('alert_toggle', searchId, 'saved_search', {
                enabled: enabled
            });
        },

        // --- Engagement Tracking ---

        /**
         * Initialize engagement tracking (scroll depth, time on page)
         */
        initEngagementTracking: function() {
            this.pageLoadTime = Date.now();
            this.maxScrollDepth = 0;
            this.scrollMilestones = { 25: false, 50: false, 75: false, 100: false };

            // Scroll depth tracking
            window.addEventListener('scroll', () => {
                this.updateScrollDepth();
            }, { passive: true });

            // Time on page tracking (ping every 30s)
            this.startEngagementTimer();
        },

        /**
         * Update and track scroll depth milestones
         */
        updateScrollDepth: function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const docHeight = Math.max(
                document.body.scrollHeight,
                document.documentElement.scrollHeight
            ) - window.innerHeight;

            if (docHeight <= 0) return;

            const scrollPercent = Math.round((scrollTop / docHeight) * 100);
            this.maxScrollDepth = Math.max(this.maxScrollDepth, scrollPercent);

            // Check milestones
            const milestones = [25, 50, 75, 100];
            for (const milestone of milestones) {
                if (scrollPercent >= milestone && !this.scrollMilestones[milestone]) {
                    this.scrollMilestones[milestone] = true;
                    this.trackScrollDepth(milestone);
                }
            }
        },

        /**
         * Track scroll depth milestone
         */
        trackScrollDepth: function(depthPct) {
            const pageType = this.detectPageType();
            this.track('scroll_depth', window.location.pathname, 'page', {
                depth_pct: depthPct,
                page_type: pageType
            });
        },

        /**
         * Start engagement timer for time-on-page pings
         */
        startEngagementTimer: function() {
            if (this.engagementTimer) {
                clearInterval(this.engagementTimer);
            }

            this.engagementTimer = setInterval(() => {
                // Only ping if page is visible
                if (document.visibilityState === 'visible') {
                    this.trackTimeOnPage();
                }
            }, this.config.engagementInterval);
        },

        /**
         * Track time on page
         */
        trackTimeOnPage: function() {
            const durationSeconds = Math.round((Date.now() - this.pageLoadTime) / 1000);
            const pageType = this.detectPageType();

            this.track('time_on_page', window.location.pathname, 'page', {
                duration_seconds: durationSeconds,
                scroll_depth_pct: this.maxScrollDepth,
                page_type: pageType
            });
        },

        /**
         * Detect page type from URL
         */
        detectPageType: function() {
            const path = window.location.pathname;
            if (path.match(/\/property\/\d+/)) return 'property_detail';
            if (path.match(/\/listing\/[a-f0-9]+/i)) return 'property_detail';
            if (path.includes('/search')) return 'search';
            if (path.includes('/schools')) return 'schools';
            if (path.includes('/my-dashboard')) return 'dashboard';
            if (path.includes('/saved-searches')) return 'saved_searches';
            if (path === '/' || path === '') return 'home';
            return 'other';
        },

        /**
         * Start the flush timer
         */
        startFlushTimer: function() {
            setInterval(() => {
                this.flush();
            }, this.config.flushInterval);
        },

        /**
         * Flush the event queue
         */
        flush: function(sync) {
            if (this.queue.length === 0) {
                return;
            }

            const events = this.queue.splice(0, this.queue.length);

            const data = {
                session_id: this.sessionId,
                events: events,
                // Include user_id for sendBeacon (can't send headers)
                user_id: this.config.userId,
                // Include platform for activity tracking (v6.74.2)
                platform: 'web',
                device_info: this.getDeviceInfo()
            };

            this.sendToServer('/analytics/activity/batch', data, sync);
        },

        /**
         * Send data to server
         */
        sendToServer: function(endpoint, data, sync) {
            const url = this.config.apiBase + endpoint;

            // Always include user_id in data for sendBeacon fallback
            data.user_id = this.config.userId;

            // Use sendBeacon for sync requests (page unload)
            // Note: sendBeacon can't send custom headers, so we include user_id in body
            if (sync && navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
                navigator.sendBeacon(url, blob);
                return;
            }

            // Use fetch for async requests with nonce header
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce
                },
                body: JSON.stringify(data),
                credentials: 'same-origin'
            }).catch(function(error) {
                console.error('MLD Analytics error:', error);
            });
        },

        /**
         * Bind to MLD events
         */
        bindEvents: function() {
            // ==========================================
            // BASIC EVENTS (existing)
            // ==========================================

            // Property view
            document.addEventListener('mld:property_view', (e) => {
                if (e.detail && e.detail.listingKey) {
                    this.trackPropertyView(e.detail.listingKey, e.detail);
                }
            });

            // Basic search (legacy)
            document.addEventListener('mld:search', (e) => {
                if (e.detail) {
                    this.trackSearch(e.detail.filters || {}, e.detail.resultCount || 0);
                }
            });

            // Filter change (legacy)
            document.addEventListener('mld:filter_change', (e) => {
                if (e.detail && e.detail.filterName) {
                    this.trackFilterUsed(e.detail.filterName, e.detail.filterValue);
                }
            });

            // Favorite add/remove
            document.addEventListener('mld:favorite', (e) => {
                if (e.detail && e.detail.listingKey) {
                    this.trackFavorite(e.detail.listingKey, e.detail.added, e.detail);
                }
            });

            // Hidden add/remove
            document.addEventListener('mld:hidden', (e) => {
                if (e.detail && e.detail.listingKey) {
                    this.trackHidden(e.detail.listingKey, e.detail.added, e.detail);
                }
            });

            // Saved search create (legacy)
            document.addEventListener('mld:saved_search', (e) => {
                if (e.detail && e.detail.searchId) {
                    this.trackSavedSearch(e.detail.searchId, e.detail.searchName);
                }
            });

            // ==========================================
            // ENHANCED SEARCH & FILTER EVENTS (v6.38.0)
            // ==========================================

            // Search execute (enhanced)
            document.addEventListener('mld:search_execute', (e) => {
                if (e.detail) {
                    this.trackSearchExecute(
                        e.detail.filters,
                        e.detail.resultCount,
                        e.detail.searchType
                    );
                }
            });

            // Filter apply
            document.addEventListener('mld:filter_apply', (e) => {
                if (e.detail && e.detail.filterName) {
                    this.trackFilterApply(
                        e.detail.filterName,
                        e.detail.filterValue,
                        e.detail.previousValue
                    );
                }
            });

            // Filter clear
            document.addEventListener('mld:filter_clear', (e) => {
                if (e.detail && e.detail.filterName) {
                    this.trackFilterClear(e.detail.filterName, e.detail.clearedValue);
                }
            });

            // Filter modal open
            document.addEventListener('mld:filter_modal_open', (e) => {
                this.trackFilterModalOpen(e.detail ? e.detail.filterCount : 0);
            });

            // Filter modal close
            document.addEventListener('mld:filter_modal_close', (e) => {
                this.trackFilterModalClose(e.detail ? e.detail.filtersChanged : false);
            });

            // Autocomplete select
            document.addEventListener('mld:autocomplete_select', (e) => {
                if (e.detail) {
                    this.trackAutocompleteSelect(e.detail.type, e.detail.value);
                }
            });

            // ==========================================
            // MAP INTERACTION EVENTS
            // ==========================================

            // Map zoom
            document.addEventListener('mld:map_zoom', (e) => {
                if (e.detail) {
                    this.trackMapZoom(e.detail.zoomLevel, e.detail.bounds);
                }
            });

            // Map pan
            document.addEventListener('mld:map_pan', (e) => {
                if (e.detail) {
                    this.trackMapPan(e.detail.centerLat, e.detail.centerLng);
                }
            });

            // Map draw start
            document.addEventListener('mld:map_draw_start', () => {
                this.trackMapDrawStart();
            });

            // Map draw complete
            document.addEventListener('mld:map_draw_complete', (e) => {
                if (e.detail) {
                    this.trackMapDrawComplete(e.detail.vertexCount, e.detail.areaSqMi);
                }
            });

            // Marker click
            document.addEventListener('mld:marker_click', (e) => {
                if (e.detail && e.detail.listingId) {
                    this.trackMarkerClick(e.detail.listingId);
                }
            });

            // Cluster click
            document.addEventListener('mld:cluster_click', (e) => {
                if (e.detail) {
                    this.trackClusterClick(e.detail.propertyCount);
                }
            });

            // ==========================================
            // PROPERTY DETAIL EVENTS
            // ==========================================

            // Photo view
            document.addEventListener('mld:photo_view', (e) => {
                if (e.detail) {
                    this.trackPhotoView(
                        e.detail.listingId,
                        e.detail.photoIndex,
                        e.detail.totalPhotos
                    );
                }
            });

            // Lightbox open
            document.addEventListener('mld:photo_lightbox_open', (e) => {
                if (e.detail) {
                    this.trackPhotoLightboxOpen(e.detail.listingId, e.detail.photoIndex);
                }
            });

            // Lightbox close
            document.addEventListener('mld:photo_lightbox_close', (e) => {
                if (e.detail) {
                    this.trackPhotoLightboxClose(e.detail.listingId, e.detail.photosViewedCount);
                }
            });

            // Tab click
            document.addEventListener('mld:tab_click', (e) => {
                if (e.detail) {
                    this.trackTabClick(e.detail.listingId, e.detail.tabName);
                }
            });

            // Video play
            document.addEventListener('mld:video_play', (e) => {
                if (e.detail) {
                    this.trackVideoPlay(e.detail.listingId, e.detail.videoType);
                }
            });

            // Street view open
            document.addEventListener('mld:street_view_open', (e) => {
                if (e.detail && e.detail.listingId) {
                    this.trackStreetViewOpen(e.detail.listingId);
                }
            });

            // Calculator use
            document.addEventListener('mld:calculator_use', (e) => {
                if (e.detail) {
                    this.trackCalculatorUse(
                        e.detail.listingId,
                        e.detail.downPaymentPct,
                        e.detail.loanTerm
                    );
                }
            });

            // School info view
            document.addEventListener('mld:school_info_view', (e) => {
                if (e.detail) {
                    this.trackSchoolInfoView(e.detail.listingId, e.detail.schoolCount);
                }
            });

            // Similar homes click
            document.addEventListener('mld:similar_homes_click', (e) => {
                if (e.detail) {
                    this.trackSimilarHomesClick(e.detail.listingId, e.detail.clickedListingId);
                }
            });

            // ==========================================
            // USER ACTION EVENTS
            // ==========================================

            // Contact click
            document.addEventListener('mld:contact_click', (e) => {
                if (e.detail) {
                    this.trackContactClick(e.detail.listingId, e.detail.contactType);
                }
            });

            // Contact form submit
            document.addEventListener('mld:contact_form_submit', (e) => {
                if (e.detail) {
                    this.trackContactFormSubmit(e.detail.listingId, e.detail.formType);
                }
            });

            // Share click
            document.addEventListener('mld:share_click', (e) => {
                if (e.detail) {
                    this.trackShareClick(e.detail.listingId, e.detail.shareMethod);
                }
            });

            // ==========================================
            // SAVED SEARCH EVENTS
            // ==========================================

            // Saved search create (enhanced)
            document.addEventListener('mld:saved_search_create', (e) => {
                if (e.detail) {
                    this.trackSavedSearch(e.detail.searchId, e.detail.searchName);
                }
            });

            // Saved search view
            document.addEventListener('mld:saved_search_view', (e) => {
                if (e.detail) {
                    this.trackSavedSearchView(e.detail.searchId, e.detail.resultCount);
                }
            });

            // Saved search edit
            document.addEventListener('mld:saved_search_edit', (e) => {
                if (e.detail) {
                    this.trackSavedSearchEdit(e.detail.searchId, e.detail.changes);
                }
            });

            // Saved search delete
            document.addEventListener('mld:saved_search_delete', (e) => {
                if (e.detail && e.detail.searchId) {
                    this.trackSavedSearchDelete(e.detail.searchId);
                }
            });

            // Alert toggle
            document.addEventListener('mld:alert_toggle', (e) => {
                if (e.detail) {
                    this.trackAlertToggle(e.detail.searchId, e.detail.enabled);
                }
            });
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            MLD_Analytics.init();
        });
    } else {
        MLD_Analytics.init();
    }

    // Expose globally for manual tracking
    window.MLD_Analytics = MLD_Analytics;

})();
