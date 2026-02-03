/**
 * MLD Public Analytics Tracker
 *
 * Lightweight tracker for all site visitors (anonymous + logged-in).
 * Cross-platform compatible (Web + iOS via REST API).
 *
 * @package MLS_Listings_Display
 * @since 6.39.0
 */

(function() {
    'use strict';

    // Bail if already loaded or config missing
    if (window.mldTracker) {
        return;
    }
    if (!window.mldTrackerConfig) {
        return;
    }

    const config = window.mldTrackerConfig;
    const STORAGE_KEY = 'mld_analytics';
    const SESSION_KEY = 'mld_session_id';
    const VISITOR_KEY = 'mld_visitor_id';

    /**
     * MLD Analytics Tracker
     */
    const mldTracker = {
        sessionId: null,
        visitorId: null,
        eventQueue: [],
        flushTimer: null,
        heartbeatTimer: null,
        pageStartTime: Date.now(),
        maxScrollDepth: 0,
        isInitialized: false,
        // v6.46.0: Capture referrer at init time, not flush time
        // This prevents internal navigation from overwriting external referrers
        originalReferrer: null,
        originalUtmParams: null,

        /**
         * Initialize tracker
         */
        init: function() {
            if (this.isInitialized) return;

            // v6.46.0: Capture referrer and UTM params IMMEDIATELY before any navigation
            // document.referrer changes when user navigates to another page on the site
            this.originalReferrer = document.referrer || null;
            this.originalUtmParams = {
                utm_source: this.getUrlParam('utm_source'),
                utm_medium: this.getUrlParam('utm_medium'),
                utm_campaign: this.getUrlParam('utm_campaign'),
                utm_term: this.getUrlParam('utm_term'),
                utm_content: this.getUrlParam('utm_content')
            };
            this.log('Captured original referrer', { referrer: this.originalReferrer, utm: this.originalUtmParams });

            this.sessionId = this.getOrCreateSession();
            this.visitorId = this.getOrCreateVisitor();
            this.restoreQueue();
            this.bindEvents();
            this.startTimers();
            this.trackPageView();

            this.isInitialized = true;
            this.log('Tracker initialized', { sessionId: this.sessionId });
        },

        /**
         * Get or create session ID
         */
        getOrCreateSession: function() {
            const stored = localStorage.getItem(SESSION_KEY);
            if (stored) {
                try {
                    const data = JSON.parse(stored);
                    const age = Date.now() - data.timestamp;
                    const timeout = (config.session_timeout || 30) * 60 * 1000;

                    if (age < timeout) {
                        // Update timestamp
                        data.timestamp = Date.now();
                        localStorage.setItem(SESSION_KEY, JSON.stringify(data));
                        return data.id;
                    }
                } catch (e) {}
            }

            // Create new session
            const newSession = {
                id: this.generateUUID(),
                timestamp: Date.now()
            };
            localStorage.setItem(SESSION_KEY, JSON.stringify(newSession));
            return newSession.id;
        },

        /**
         * Get or create persistent visitor ID
         */
        getOrCreateVisitor: function() {
            let visitorId = localStorage.getItem(VISITOR_KEY);
            if (!visitorId) {
                visitorId = this.generateUUID();
                localStorage.setItem(VISITOR_KEY, visitorId);
            }
            return visitorId;
        },

        /**
         * Generate UUID v4
         */
        generateUUID: function() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        /**
         * Bind event listeners
         */
        bindEvents: function() {
            // Track scroll depth
            let scrollTimeout;
            window.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => this.updateScrollDepth(), 100);
            }, { passive: true });

            // Track page visibility
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.trackEngagement();
                    this.flush(true);
                }
            });

            // Track before unload
            window.addEventListener('beforeunload', () => {
                this.trackEngagement();
                this.flush(true);
            });

            // Track property card clicks
            document.addEventListener('click', (e) => this.handleClick(e));

            // Track search events (listen for custom events from existing search code)
            document.addEventListener('mld:search_execute', (e) => {
                this.track('search_execute', {
                    search_query: e.detail?.filters || {},
                    search_results_count: e.detail?.count || 0
                });
            });

            // Track filter changes
            document.addEventListener('mld:filter_apply', (e) => {
                this.track('filter_apply', {
                    search_query: e.detail?.filters || {}
                });
            });

            // Track map interactions
            document.addEventListener('mld:map_zoom', (e) => {
                this.track('map_zoom', { data: e.detail });
            });

            document.addEventListener('mld:map_pan', (e) => {
                this.track('map_pan', { data: e.detail });
            });

            // Track photo views
            document.addEventListener('mld:photo_view', (e) => {
                this.track('photo_view', {
                    listing_id: e.detail?.listingId,
                    data: { photo_index: e.detail?.index }
                });
            });

            // Track contact form submissions
            document.addEventListener('mld:contact_form_submit', (e) => {
                this.track('contact_submit', {
                    listing_id: e.detail?.listingId,
                    data: { form_type: e.detail?.formType }
                });
            });

            // Track favorite actions
            document.addEventListener('mld:favorite_add', (e) => {
                this.track('favorite_add', { listing_id: e.detail?.listingId });
            });

            document.addEventListener('mld:favorite_remove', (e) => {
                this.track('favorite_remove', { listing_id: e.detail?.listingId });
            });
        },

        /**
         * Handle click events
         */
        handleClick: function(e) {
            const target = e.target.closest('a, button, [data-track]');
            if (!target) return;

            // Property card click
            const propertyCard = target.closest('[data-listing-id], .bme-listing-card, .property-card');
            if (propertyCard) {
                const listingId = propertyCard.dataset.listingId ||
                                  propertyCard.querySelector('[data-listing-id]')?.dataset.listingId;
                if (listingId) {
                    this.track('property_click', {
                        listing_id: listingId,
                        click_element: 'property_card'
                    });
                }
            }

            // Contact button click
            if (target.matches('.contact-agent-btn, .mld-contact-btn, [data-action="contact"]')) {
                this.track('contact_click', {
                    listing_id: config.property?.listing_id,
                    click_element: 'contact_button'
                });
            }

            // Share button click
            if (target.matches('.share-btn, .mld-share-btn, [data-action="share"]')) {
                this.track('share_click', {
                    listing_id: config.property?.listing_id,
                    click_element: 'share_button'
                });
            }

            // Schedule showing click
            if (target.matches('.schedule-btn, .book-showing-btn, [data-action="schedule"]')) {
                this.track('schedule_click', {
                    listing_id: config.property?.listing_id,
                    click_element: 'schedule_button'
                });
            }

            // External link click
            if (target.tagName === 'A' && target.hostname !== window.location.hostname) {
                this.track('external_click', {
                    click_target: target.href,
                    click_element: 'external_link'
                });
            }

            // CTA button click
            if (target.matches('.cta-btn, .btn-primary, [data-track="cta"]')) {
                this.track('cta_click', {
                    click_target: target.href || target.textContent.trim().substring(0, 50),
                    click_element: target.className
                });
            }
        },

        /**
         * Update max scroll depth
         */
        updateScrollDepth: function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const docHeight = Math.max(
                document.body.scrollHeight,
                document.documentElement.scrollHeight
            ) - window.innerHeight;

            if (docHeight > 0) {
                const depth = Math.round((scrollTop / docHeight) * 100);
                this.maxScrollDepth = Math.max(this.maxScrollDepth, Math.min(depth, 100));
            }
        },

        /**
         * Track page view
         */
        trackPageView: function() {
            const eventData = {
                page_url: config.page_url,
                page_path: config.page_path,
                page_title: config.page_title,
                page_type: config.page_type
            };

            // Add property data if available
            if (config.property) {
                eventData.listing_id = config.property.listing_id;
                eventData.listing_key = config.property.listing_key;
                eventData.property_city = config.property.city;
                eventData.property_price = config.property.price;
                eventData.property_beds = config.property.beds;
                eventData.property_baths = config.property.baths;
            }

            this.track('page_view', eventData);

            // Also track property_view separately for property pages
            if (config.page_type === 'property_detail' && config.property) {
                this.track('property_view', eventData);
            }
        },

        /**
         * Track engagement metrics (scroll depth + time on page)
         */
        trackEngagement: function() {
            const timeOnPage = Math.round((Date.now() - this.pageStartTime) / 1000);

            if (this.maxScrollDepth > 0) {
                this.track('scroll_depth', {
                    scroll_depth: this.maxScrollDepth,
                    page_type: config.page_type
                });
            }

            if (timeOnPage > 5) { // Only track if > 5 seconds
                this.track('time_on_page', {
                    time_on_page: timeOnPage,
                    page_type: config.page_type
                });
            }
        },

        /**
         * Track an event
         */
        track: function(eventType, data = {}) {
            const event = {
                type: eventType,
                timestamp: new Date().toISOString(),
                page_url: data.page_url || config.page_url,
                page_path: data.page_path || config.page_path,
                page_title: data.page_title || config.page_title,
                page_type: data.page_type || config.page_type,
                ...data
            };

            this.eventQueue.push(event);
            this.saveQueue();
            this.log('Event tracked', event);

            // Flush immediately for important events
            const immediateEvents = ['contact_submit', 'favorite_add', 'schedule_click'];
            if (immediateEvents.includes(eventType)) {
                this.flush();
            }
        },

        /**
         * Start flush and heartbeat timers
         */
        startTimers: function() {
            // Flush events periodically
            const flushInterval = (config.flush_interval || 30) * 1000;
            this.flushTimer = setInterval(() => this.flush(), flushInterval);

            // Send heartbeat for real-time tracking
            const heartbeatInterval = (config.heartbeat_interval || 60) * 1000;
            this.heartbeatTimer = setInterval(() => this.sendHeartbeat(), heartbeatInterval);

            // Initial heartbeat
            setTimeout(() => this.sendHeartbeat(), 1000);
        },

        /**
         * Flush event queue to server
         */
        flush: function(useBeacon = false) {
            if (this.eventQueue.length === 0) return;

            const events = [...this.eventQueue];
            this.eventQueue = [];
            this.saveQueue();

            const payload = {
                session_id: this.sessionId,
                visitor_hash: this.visitorId,
                events: events,
                session_data: this.getSessionData()
            };

            if (useBeacon && navigator.sendBeacon) {
                // Use sendBeacon for page unload (guaranteed delivery)
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                const sent = navigator.sendBeacon(config.endpoint, blob);
                this.log('Beacon sent', { count: events.length, success: sent });
            } else {
                // Use fetch for normal flush
                fetch(config.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce
                    },
                    body: JSON.stringify(payload),
                    keepalive: true
                })
                .then(response => response.json())
                .then(data => {
                    this.log('Events flushed', { count: events.length, response: data });
                })
                .catch(error => {
                    // Re-queue events on failure
                    this.eventQueue = [...events, ...this.eventQueue];
                    this.saveQueue();
                    this.log('Flush failed, events re-queued', { error: error.message });
                });
            }
        },

        /**
         * Send heartbeat for real-time presence
         */
        sendHeartbeat: function() {
            const payload = {
                session_id: this.sessionId,
                page_url: config.page_url,
                page_type: config.page_type,
                listing_id: config.property?.listing_id || null
            };

            // v6.45.8 fix: Don't send nonce - endpoint is public and nonce causes 403 when expired
            fetch(config.heartbeat_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                this.log('Heartbeat sent', data);
            })
            .catch(() => {
                // Silent fail for heartbeats
            });
        },

        /**
         * Get session metadata
         * v6.46.0: Use originalReferrer and originalUtmParams captured at init time
         * This ensures external referrers aren't overwritten by internal navigation
         */
        getSessionData: function() {
            return {
                visitor_hash: this.visitorId,
                user_id: config.user_id,
                // Use the referrer captured at init, not current document.referrer
                referrer: this.originalReferrer,
                // Use UTM params captured at init (from landing page URL)
                utm_source: this.originalUtmParams?.utm_source || null,
                utm_medium: this.originalUtmParams?.utm_medium || null,
                utm_campaign: this.originalUtmParams?.utm_campaign || null,
                utm_term: this.originalUtmParams?.utm_term || null,
                utm_content: this.originalUtmParams?.utm_content || null,
                screen_width: window.screen.width,
                screen_height: window.screen.height
            };
        },

        /**
         * Get URL parameter
         */
        getUrlParam: function(name) {
            const params = new URLSearchParams(window.location.search);
            return params.get(name) || null;
        },

        /**
         * Save queue to localStorage
         */
        saveQueue: function() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(this.eventQueue));
            } catch (e) {
                // Storage full or unavailable
            }
        },

        /**
         * Restore queue from localStorage
         */
        restoreQueue: function() {
            try {
                const stored = localStorage.getItem(STORAGE_KEY);
                if (stored) {
                    const events = JSON.parse(stored);
                    if (Array.isArray(events) && events.length > 0) {
                        this.eventQueue = events;
                        this.log('Queue restored', { count: events.length });
                    }
                }
            } catch (e) {}
        },

        /**
         * Debug logging
         */
        log: function(message, data = {}) {
            if (config.debug) {
                console.log('[MLD Tracker]', message, data);
            }
        },

        /**
         * Public API: Track custom event
         */
        trackEvent: function(type, data = {}) {
            this.track(type, data);
        },

        /**
         * Public API: Get session ID
         */
        getSessionId: function() {
            return this.sessionId;
        },

        /**
         * Public API: Force flush
         */
        forceFlush: function() {
            this.flush();
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mldTracker.init());
    } else {
        mldTracker.init();
    }

    // Expose globally
    window.mldTracker = mldTracker;

})();
