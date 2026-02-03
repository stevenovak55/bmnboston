/**
 * Lazy Images Module
 * Handles lazy loading of images for better performance
 */

(function() {
    'use strict';

    const LazyImages = {
        init: function() {
            this.images = document.querySelectorAll('img[data-lazy]');
            this.setupIntersectionObserver();
        },

        setupIntersectionObserver: function() {
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const image = entry.target;
                            this.loadImage(image);
                            observer.unobserve(image);
                        }
                    });
                });

                this.images.forEach(img => imageObserver.observe(img));
            } else {
                // Fallback for browsers without IntersectionObserver
                this.loadAllImages();
            }
        },

        loadImage: function(img) {
            const src = img.getAttribute('data-lazy');
            if (src) {
                img.src = src;
                img.removeAttribute('data-lazy');
            }
        },

        loadAllImages: function() {
            this.images.forEach(img => this.loadImage(img));
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => LazyImages.init());
    } else {
        LazyImages.init();
    }

})();