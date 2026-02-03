/**
 * MLD Service Worker for Performance Optimization
 * Caches static assets for faster page loads
 */

const CACHE_NAME = 'mld-cache-v4.6.0';
const STATIC_CACHE_URLS = [
    // Core plugin assets
    '/wp-content/plugins/mls-listings-display/assets/css/main.css',
    '/wp-content/plugins/mls-listings-display/assets/css/search-mobile.css',
    '/wp-content/plugins/mls-listings-display/assets/css/property-desktop-v3.css',
    '/wp-content/plugins/mls-listings-display/assets/css/property-mobile-v3.css',

    // Fonts and icons
    '/wp-content/plugins/mls-listings-display/assets/css/font-awesome.min.css',

    // Critical JavaScript bundles (when optimization is enabled)
    '/wp-content/uploads/mld-cache/mld-map-core-bundle.min.js',
    '/wp-content/uploads/mld-cache/mld-map-features-bundle.min.js',
    '/wp-content/uploads/mld-cache/mld-property-detail-bundle.min.js'
];

// Images and media patterns to cache
const CACHE_PATTERNS = [
    /\.(?:png|jpg|jpeg|svg|gif|webp|ico)$/,
    /fonts\.googleapis\.com/,
    /fonts\.gstatic\.com/,
    /cloudfront\.net.*\.(jpg|jpeg|png|webp)$/
];

// Install event - cache static assets
self.addEventListener('install', event => {
    // Installing service worker

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                // Caching static assets
                // Don't fail if some assets don't exist yet
                return Promise.allSettled(
                    STATIC_CACHE_URLS.map(url =>
                        cache.add(url).catch(err => {
                            // Silently ignore cache failures for optional assets
                        })
                    )
                );
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    // Activating service worker

    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName.startsWith('mld-cache-') && cacheName !== CACHE_NAME) {
                        // Deleting old cache
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache with network fallback
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);

    // Only handle GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip admin and API requests
    if (url.pathname.includes('/wp-admin/') ||
        url.pathname.includes('/wp-json/') ||
        url.pathname.includes('wp-login.php')) {
        return;
    }

    // Handle different types of requests
    if (shouldCacheRequest(request)) {
        event.respondWith(cacheFirstStrategy(request));
    } else if (isNavigationRequest(request)) {
        event.respondWith(networkFirstStrategy(request));
    }
});

/**
 * Determine if a request should be cached
 */
function shouldCacheRequest(request) {
    const url = request.url;

    // Cache static assets
    if (CACHE_PATTERNS.some(pattern => pattern.test(url))) {
        return true;
    }

    // Cache MLS plugin assets
    if (url.includes('/wp-content/plugins/mls-listings-display/assets/')) {
        return true;
    }

    // Cache optimized bundles
    if (url.includes('/wp-content/uploads/mld-cache/')) {
        return true;
    }

    return false;
}

/**
 * Check if this is a navigation request
 */
function isNavigationRequest(request) {
    return request.mode === 'navigate' ||
           (request.method === 'GET' && request.headers.get('accept').includes('text/html'));
}

/**
 * Cache first strategy - try cache, fallback to network
 */
async function cacheFirstStrategy(request) {
    try {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        return fetch(request);
    }
}

/**
 * Network first strategy - try network, fallback to cache
 */
async function networkFirstStrategy(request) {
    try {
        const networkResponse = await fetch(request);
        return networkResponse;
    } catch (error) {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        throw error;
    }
}

/**
 * Background sync for updating cache
 */
self.addEventListener('sync', event => {
    if (event.tag === 'mld-cache-update') {
        event.waitUntil(updateCache());
    }
});

/**
 * Update cache in background
 */
async function updateCache() {
    try {
        const cache = await caches.open(CACHE_NAME);
        await Promise.allSettled(
            STATIC_CACHE_URLS.map(url =>
                fetch(url).then(response => {
                    if (response.ok) {
                        cache.put(url, response);
                    }
                })
            )
        );
    } catch (error) {
        // Silently fail background cache updates
    }
}