/**
 * Landing Pages JavaScript
 *
 * Handles interactive features for neighborhood and school district landing pages
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 */

(function() {
    'use strict';

    /**
     * Landing Pages Module
     */
    const LandingPages = {
        /**
         * Initialize all landing page functionality
         */
        init: function() {
            this.initFAQAccessibility();
            this.initSmoothScroll();
            this.initLazyImages();
            this.initSearchForm();
        },

        /**
         * Enhance FAQ accessibility
         * Ensures keyboard navigation works properly
         */
        initFAQAccessibility: function() {
            const faqItems = document.querySelectorAll('.bne-landing-faq__item');

            faqItems.forEach(function(item) {
                const summary = item.querySelector('.bne-landing-faq__question');

                if (summary) {
                    // Add keyboard support
                    summary.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            item.open = !item.open;
                        }
                    });
                }
            });
        },

        /**
         * Initialize smooth scrolling for anchor links
         */
        initSmoothScroll: function() {
            const links = document.querySelectorAll('a[href^="#"]');

            links.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    const targetId = this.getAttribute('href');

                    if (targetId !== '#') {
                        const target = document.querySelector(targetId);

                        if (target) {
                            e.preventDefault();
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    }
                });
            });
        },

        /**
         * Initialize lazy loading for listing images
         * Uses native loading="lazy" with fallback
         */
        initLazyImages: function() {
            // Check if browser supports native lazy loading
            if ('loading' in HTMLImageElement.prototype) {
                // Native lazy loading is supported
                const images = document.querySelectorAll('.bne-landing-listing-card__image img');
                images.forEach(function(img) {
                    if (!img.hasAttribute('loading')) {
                        img.setAttribute('loading', 'lazy');
                    }
                });
            } else {
                // Fallback: Use Intersection Observer
                const images = document.querySelectorAll('.bne-landing-listing-card__image img[data-src]');

                if (images.length > 0 && 'IntersectionObserver' in window) {
                    const imageObserver = new IntersectionObserver(function(entries) {
                        entries.forEach(function(entry) {
                            if (entry.isIntersecting) {
                                const img = entry.target;
                                if (img.dataset.src) {
                                    img.src = img.dataset.src;
                                    img.removeAttribute('data-src');
                                }
                                imageObserver.unobserve(img);
                            }
                        });
                    }, {
                        rootMargin: '50px 0px'
                    });

                    images.forEach(function(img) {
                        imageObserver.observe(img);
                    });
                }
            }
        },

        /**
         * Initialize search form enhancements
         */
        initSearchForm: function() {
            const form = document.querySelector('.bne-landing-search-form');

            if (!form) return;

            // Track search form submissions
            form.addEventListener('submit', function(e) {
                // Optional: Track with analytics
                if (typeof gtag === 'function') {
                    const city = form.querySelector('input[name="city"]');
                    const propertyType = form.querySelector('select[name="property_type"]');

                    gtag('event', 'search', {
                        'search_term': city ? city.value : '',
                        'property_type': propertyType ? propertyType.value : ''
                    });
                }
            });

            // Validate price range
            const minPrice = form.querySelector('select[name="min_price"]');
            const maxPrice = form.querySelector('select[name="max_price"]');

            if (minPrice && maxPrice) {
                minPrice.addEventListener('change', function() {
                    LandingPages.validatePriceRange(minPrice, maxPrice);
                });

                maxPrice.addEventListener('change', function() {
                    LandingPages.validatePriceRange(minPrice, maxPrice);
                });
            }
        },

        /**
         * Validate price range selection
         */
        validatePriceRange: function(minSelect, maxSelect) {
            const minVal = parseInt(minSelect.value) || 0;
            const maxVal = parseInt(maxSelect.value) || Infinity;

            if (minVal > 0 && maxVal > 0 && minVal > maxVal) {
                // Swap values
                maxSelect.value = minSelect.value;
            }
        },

        /**
         * Format price for display
         */
        formatPrice: function(price) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(price);
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            LandingPages.init();
        });
    } else {
        LandingPages.init();
    }

    // Expose to global scope if needed
    window.BNELandingPages = LandingPages;

})();
