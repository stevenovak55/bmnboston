/**
 * Homepage JavaScript
 *
 * Initializes carousels, scroll animations, header scroll state,
 * quick search form functionality, and location autocomplete.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.7
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        headerScrollThreshold: 50,
        animationRootMargin: '-80px',
        animationThreshold: 0.15,
        searchDebounceMs: 300,
        autocompleteDebounceMs: 250,
        autocompleteMinChars: 2
    };

    /**
     * Initialize when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        initHeaderScrollState();
        initListingsCarousel();
        initTestimonialsCarousel();
        initSmoothScroll();
        initLazyLoad();
        initScrollAnimations();
        initQuickSearchForm();
        initQuickSearchAutocomplete();
        initAnalyticsSection();
    });

    /**
     * Initialize Header Scroll State
     * Adds 'scrolled' class when user scrolls past threshold
     */
    function initHeaderScrollState() {
        const header = document.querySelector('.bne-header');

        if (!header) {
            return;
        }

        let ticking = false;

        function updateHeaderState() {
            if (window.pageYOffset > CONFIG.headerScrollThreshold) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
            ticking = false;
        }

        function onScroll() {
            if (!ticking) {
                window.requestAnimationFrame(updateHeaderState);
                ticking = true;
            }
        }

        // Set initial state
        updateHeaderState();

        // Listen for scroll
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /**
     * Initialize Listings Carousel
     */
    function initListingsCarousel() {
        const listingsSwiper = document.querySelector('.listings-swiper');

        if (!listingsSwiper || typeof Swiper === 'undefined') {
            return;
        }

        new Swiper('.listings-swiper', {
            // Slides per view
            slidesPerView: 1,
            spaceBetween: 20,

            // Responsive breakpoints
            breakpoints: {
                // When window width is >= 640px
                640: {
                    slidesPerView: 2,
                    spaceBetween: 20,
                },
                // When window width is >= 1024px
                1024: {
                    slidesPerView: 3,
                    spaceBetween: 24,
                },
                // When window width is >= 1280px
                1280: {
                    slidesPerView: 4,
                    spaceBetween: 24,
                },
            },

            // Navigation arrows
            navigation: {
                nextEl: '.listings-swiper .swiper-button-next',
                prevEl: '.listings-swiper .swiper-button-prev',
            },

            // Pagination dots
            pagination: {
                el: '.listings-swiper .swiper-pagination',
                clickable: true,
                dynamicBullets: true,
            },

            // Accessibility
            a11y: {
                prevSlideMessage: 'Previous listing',
                nextSlideMessage: 'Next listing',
                firstSlideMessage: 'This is the first listing',
                lastSlideMessage: 'This is the last listing',
            },

            // Loop mode
            loop: true,

            // Autoplay
            autoplay: {
                delay: 5000,
                disableOnInteraction: true,
                pauseOnMouseEnter: true,
            },

            // Grab cursor
            grabCursor: true,

            // Keyboard control
            keyboard: {
                enabled: true,
                onlyInViewport: true,
            },
        });
    }

    /**
     * Initialize Testimonials Carousel
     */
    function initTestimonialsCarousel() {
        const testimonialsSwiper = document.querySelector('.testimonials-swiper');

        if (!testimonialsSwiper || typeof Swiper === 'undefined') {
            return;
        }

        new Swiper('.testimonials-swiper', {
            // Slides per view
            slidesPerView: 1,
            spaceBetween: 24,

            // Responsive breakpoints
            breakpoints: {
                // When window width is >= 768px
                768: {
                    slidesPerView: 2,
                    spaceBetween: 24,
                },
                // When window width is >= 1024px
                1024: {
                    slidesPerView: 3,
                    spaceBetween: 30,
                },
            },

            // Navigation arrows
            navigation: {
                nextEl: '.testimonials-swiper .swiper-button-next',
                prevEl: '.testimonials-swiper .swiper-button-prev',
            },

            // Pagination dots
            pagination: {
                el: '.testimonials-swiper .swiper-pagination',
                clickable: true,
            },

            // Accessibility
            a11y: {
                prevSlideMessage: 'Previous testimonial',
                nextSlideMessage: 'Next testimonial',
                firstSlideMessage: 'This is the first testimonial',
                lastSlideMessage: 'This is the last testimonial',
                paginationBulletMessage: 'Go to testimonial {{index}}',
            },

            // Loop mode
            loop: true,

            // Autoplay (slower for reading)
            autoplay: {
                delay: 7000,
                disableOnInteraction: true,
                pauseOnMouseEnter: true,
            },

            // Grab cursor
            grabCursor: true,

            // Center slides
            centeredSlides: false,
        });
    }

    /**
     * Initialize Smooth Scroll for anchor links
     */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var href = this.getAttribute('href');

                if (href === '#' || href === '#0') {
                    return;
                }

                var target = document.querySelector(href);

                if (target) {
                    e.preventDefault();

                    // Account for fixed header height
                    var headerHeight = document.querySelector('.bne-header')?.offsetHeight || 0;
                    var targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;

                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    }

    /**
     * Initialize Lazy Loading for images
     * (Enhancement for browsers that don't support native lazy loading)
     */
    function initLazyLoad() {
        // Check if IntersectionObserver is supported
        if (!('IntersectionObserver' in window)) {
            return;
        }

        var lazyImages = document.querySelectorAll('img[loading="lazy"]');

        if (!lazyImages.length) {
            return;
        }

        var imageObserver = new IntersectionObserver(function(entries, observer) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;

                    // If there's a data-src, use it
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }

                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        }, {
            rootMargin: '200px 0px',
            threshold: 0.01,
        });

        lazyImages.forEach(function(img) {
            imageObserver.observe(img);
        });
    }

    /**
     * Initialize Scroll Animations
     * Adds staggered reveal animations to sections and elements
     */
    function initScrollAnimations() {
        if (!('IntersectionObserver' in window)) {
            // Fallback: make everything visible immediately
            document.querySelectorAll('.bne-animate').forEach(function(el) {
                el.classList.add('bne-animate--visible');
            });
            return;
        }

        // Check for reduced motion preference
        var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (prefersReducedMotion) {
            document.querySelectorAll('.bne-animate').forEach(function(el) {
                el.classList.add('bne-animate--visible');
            });
            return;
        }

        // Section observer
        var sectionObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('bne-section--visible');

                    // Stagger animate children
                    var animateChildren = entry.target.querySelectorAll('.bne-animate');
                    animateChildren.forEach(function(child, index) {
                        setTimeout(function() {
                            child.classList.add('bne-animate--visible');
                        }, index * 100);
                    });
                }
            });
        }, {
            rootMargin: CONFIG.animationRootMargin,
            threshold: CONFIG.animationThreshold,
        });

        // Observe sections
        document.querySelectorAll('.bne-section').forEach(function(section) {
            sectionObserver.observe(section);
        });

        // Individual element observer for elements outside sections
        var elementObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('bne-animate--visible');
                    elementObserver.unobserve(entry.target);
                }
            });
        }, {
            rootMargin: '-50px',
            threshold: 0.1,
        });

        // Observe standalone animated elements
        document.querySelectorAll('.bne-animate:not(.bne-section .bne-animate)').forEach(function(el) {
            elementObserver.observe(el);
        });
    }

    /**
     * Initialize Quick Search Form
     * Handles the hero section search form with MLD-compatible hash URLs
     */
    function initQuickSearchForm() {
        var searchForm = document.getElementById('bne-hero-search-form');

        if (!searchForm) {
            return;
        }

        var locationInput = searchForm.querySelector('input[name="location"]');
        var typeSelect = searchForm.querySelector('select[name="property_type"]');
        var priceSelect = searchForm.querySelector('select[name="price_range"]');
        var submitBtn = searchForm.querySelector('button[type="submit"]');

        // Get search URL from data attribute or fallback
        var searchPageUrl = searchForm.dataset.searchUrl ||
                           (typeof bneTheme !== 'undefined' ? bneTheme.homeUrl + 'property-search/' : '/property-search/');

        // Handle form submission
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();

            var searchUrl = buildSearchUrl();
            if (searchUrl) {
                window.location.href = searchUrl;
            }
        });

        /**
         * Build MLD-compatible search URL with hash parameters
         * MLD expects: /property-search/#City=Boston&PropertyType=Residential&PriceMin=500000
         */
        function buildSearchUrl() {
            var hashParams = [];

            // Location - Use autocomplete selected data if available
            if (locationInput) {
                var selectedType = locationInput.dataset.selectedType;
                var selectedValue = locationInput.dataset.selectedValue;

                if (selectedType && selectedValue) {
                    // Use the suggestion type to build proper MLD parameter
                    // MLD expects these exact parameter names (with spaces where shown):
                    // City, Postal Code, Street Name, Street Address, MLS Number, Address, Building, Neighborhood
                    var typeLower = selectedType.toLowerCase();
                    var isDirectPropertySearch = false;

                    switch (typeLower) {
                        case 'city':
                            hashParams.push('City=' + encodeURIComponent(selectedValue));
                            break;
                        case 'neighborhood':
                            hashParams.push('Neighborhood=' + encodeURIComponent(selectedValue));
                            break;
                        case 'postal code':
                            // MLD expects "Postal Code" with space
                            hashParams.push('Postal Code=' + encodeURIComponent(selectedValue));
                            break;
                        case 'street address':
                            // Street Address type - keep full value including "(All Units)"
                            // Use "Street Address" parameter for multi-unit searches
                            hashParams.push('Street Address=' + encodeURIComponent(selectedValue));
                            break;
                        case 'street name':
                            // MLD expects "Street Name" with space
                            hashParams.push('Street Name=' + encodeURIComponent(selectedValue));
                            break;
                        case 'address':
                            // Full specific address - use "Address" and flag as direct property search
                            hashParams.push('Address=' + encodeURIComponent(selectedValue));
                            isDirectPropertySearch = true;
                            break;
                        case 'mls number':
                            // MLD expects "MLS Number" not "ListingId"
                            hashParams.push('MLS Number=' + encodeURIComponent(selectedValue));
                            isDirectPropertySearch = true;
                            break;
                        case 'building':
                            hashParams.push('Building=' + encodeURIComponent(selectedValue));
                            break;
                        default:
                            // Fallback: use City as default filter type
                            hashParams.push('City=' + encodeURIComponent(selectedValue));
                    }

                    // Add direct_property_selection flag for specific address/MLS lookups
                    if (isDirectPropertySearch) {
                        hashParams.push('direct_property_selection=true');
                    }
                } else if (locationInput.value.trim()) {
                    // Fallback: treat as city or ZIP based on pattern
                    var location = locationInput.value.trim();
                    if (/^\d{5}$/.test(location)) {
                        // MLD expects "Postal Code" with space
                        hashParams.push('Postal Code=' + encodeURIComponent(location));
                    } else {
                        hashParams.push('City=' + encodeURIComponent(location));
                    }
                }
            }

            // Property Type -> PropertyType parameter
            if (typeSelect && typeSelect.value) {
                hashParams.push('PropertyType=' + encodeURIComponent(typeSelect.value));
            }

            // Price Range -> price_min and price_max parameters (lowercase with underscore)
            if (priceSelect && priceSelect.value) {
                var priceRange = priceSelect.value.split('-');
                if (priceRange[0]) {
                    hashParams.push('price_min=' + priceRange[0]);
                }
                if (priceRange[1]) {
                    hashParams.push('price_max=' + priceRange[1]);
                }
            }

            // Default status to Active for quicksearch
            hashParams.push('status=Active');

            // Build URL with hash parameters
            var hashString = hashParams.join('&');
            return searchPageUrl + (hashString ? '#' + hashString : '');
        }

        // Add loading state on submit
        if (submitBtn) {
            searchForm.addEventListener('submit', function() {
                submitBtn.classList.add('is-loading');
                submitBtn.disabled = true;
            });
        }

        // Clear selected autocomplete data when user types
        if (locationInput) {
            locationInput.addEventListener('input', function() {
                // Clear selected suggestion data when user modifies input
                delete this.dataset.selectedType;
                delete this.dataset.selectedValue;
            });
        }
    }

    /**
     * Initialize Quick Search Autocomplete
     * Uses MLD's AJAX endpoint for location suggestions
     */
    function initQuickSearchAutocomplete() {
        var locationInput = document.getElementById('hero-search-location');
        var autocompleteContainer = document.getElementById('hero-search-autocomplete');

        if (!locationInput || !autocompleteContainer) {
            return;
        }

        var debounceTimer = null;
        var activeIndex = -1;
        var suggestions = [];

        // Input event - fetch suggestions with debounce
        locationInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var query = this.value.trim();

            if (query.length < CONFIG.autocompleteMinChars) {
                hideAutocomplete();
                return;
            }

            debounceTimer = setTimeout(function() {
                fetchSuggestions(query);
            }, CONFIG.autocompleteDebounceMs);
        });

        // Focus event - show existing suggestions
        locationInput.addEventListener('focus', function() {
            if (suggestions.length > 0 && this.value.length >= CONFIG.autocompleteMinChars) {
                showAutocomplete();
            }
        });

        // Keyboard navigation
        locationInput.addEventListener('keydown', function(e) {
            if (autocompleteContainer.style.display === 'none') {
                return;
            }

            var items = autocompleteContainer.querySelectorAll('.bne-hero-search__autocomplete-item');

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActiveItem(items);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, -1);
                    updateActiveItem(items);
                    break;

                case 'Enter':
                    if (activeIndex >= 0 && suggestions[activeIndex]) {
                        e.preventDefault();
                        selectSuggestion(suggestions[activeIndex]);
                    }
                    break;

                case 'Escape':
                    hideAutocomplete();
                    break;
            }
        });

        // Click outside to close
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.bne-hero-search__field--location')) {
                hideAutocomplete();
            }
        });

        /**
         * Fetch suggestions from theme's AJAX endpoint
         * Uses bne_hero_autocomplete action which wraps MLD_Query
         */
        function fetchSuggestions(query) {
            // Check if bneTheme is available
            if (typeof bneTheme === 'undefined' || !bneTheme.ajaxUrl) {
                console.warn('bneTheme AJAX URL not available');
                return;
            }

            // Show loading state
            autocompleteContainer.innerHTML = '<div class="bne-hero-search__autocomplete-loading">Searching...</div>';
            showAutocomplete();

            var xhr = new XMLHttpRequest();
            xhr.open('POST', bneTheme.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success && response.data) {
                                // MLD returns array directly in data, not data.suggestions
                                suggestions = Array.isArray(response.data) ? response.data : [];
                                renderSuggestions(suggestions);
                            } else {
                                suggestions = [];
                                showNoResults();
                            }
                        } catch (e) {
                            console.error('Failed to parse autocomplete response', e);
                            suggestions = [];
                            showNoResults();
                        }
                    } else {
                        console.error('Autocomplete request failed', xhr.status);
                        suggestions = [];
                        showNoResults();
                    }
                }
            };

            // Use our theme's AJAX action with 'term' parameter (MLD convention)
            var params = 'action=bne_hero_autocomplete&term=' + encodeURIComponent(query);

            xhr.send(params);
        }

        /**
         * Render suggestions in dropdown
         * MLD returns: { value: "Boston", type: "City" }
         */
        function renderSuggestions(items) {
            autocompleteContainer.innerHTML = '';
            activeIndex = -1;

            if (items.length === 0) {
                showNoResults();
                return;
            }

            items.forEach(function(item, index) {
                var div = document.createElement('div');
                div.className = 'bne-hero-search__autocomplete-item';
                div.dataset.index = index;

                // MLD uses 'type' for category and 'value' for the actual value
                var typeLabel = item.type || 'Location';
                var displayValue = item.value || '';
                var typeLower = typeLabel.toLowerCase();

                // Determine badge class based on type
                var badgeClass = 'bne-hero-search__autocomplete-badge';

                if (typeLower === 'city') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--city';
                } else if (typeLower === 'neighborhood') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--neighborhood';
                } else if (typeLower === 'postal code' || typeLower === 'zip') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--zip';
                    typeLabel = 'ZIP'; // Shorten for display
                } else if (typeLower === 'street address' || typeLower === 'address') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--address';
                    typeLabel = 'Address';
                } else if (typeLower === 'street name') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--address';
                    typeLabel = 'Street';
                } else if (typeLower === 'mls number') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--mls';
                    typeLabel = 'MLS';
                } else if (typeLower === 'building') {
                    badgeClass += ' bne-hero-search__autocomplete-badge--neighborhood';
                    typeLabel = 'Building';
                }

                div.innerHTML = '<span class="' + badgeClass + '">' + escapeHtml(typeLabel) + '</span>' +
                               '<span class="bne-hero-search__autocomplete-label">' + escapeHtml(displayValue) + '</span>';

                div.addEventListener('click', function() {
                    selectSuggestion(item);
                });

                div.addEventListener('mouseenter', function() {
                    activeIndex = index;
                    updateActiveItem(autocompleteContainer.querySelectorAll('.bne-hero-search__autocomplete-item'));
                });

                autocompleteContainer.appendChild(div);
            });

            showAutocomplete();
        }

        /**
         * Update active item styling
         */
        function updateActiveItem(items) {
            items.forEach(function(item, index) {
                if (index === activeIndex) {
                    item.classList.add('is-active');
                    // Scroll into view if needed
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('is-active');
                }
            });
        }

        /**
         * Select a suggestion
         * MLD uses { value: "Boston", type: "City" }
         */
        function selectSuggestion(item) {
            locationInput.value = item.value || '';
            locationInput.dataset.selectedType = item.type || '';
            locationInput.dataset.selectedValue = item.value || '';
            suggestions = []; // Clear suggestions to prevent focus from re-showing dropdown
            hideAutocomplete();
        }

        /**
         * Show no results message
         */
        function showNoResults() {
            autocompleteContainer.innerHTML = '<div class="bne-hero-search__autocomplete-empty">No results found</div>';
            showAutocomplete();
        }

        /**
         * Show autocomplete dropdown
         */
        function showAutocomplete() {
            autocompleteContainer.style.display = 'block';
        }

        /**
         * Hide autocomplete dropdown
         */
        function hideAutocomplete() {
            autocompleteContainer.style.display = 'none';
            activeIndex = -1;
        }

        /**
         * Escape HTML entities
         */
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    /**
     * Initialize Analytics Section
     * Handles neighborhood analytics cards interactions
     */
    function initAnalyticsSection() {
        var analyticsSection = document.querySelector('.bne-analytics');

        if (!analyticsSection) {
            return;
        }

        var neighborhoodCards = analyticsSection.querySelectorAll('.bne-analytics__card');

        // Add hover/touch interactions
        neighborhoodCards.forEach(function(card) {
            // Keyboard accessibility
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var link = card.querySelector('a');
                    if (link) {
                        link.click();
                    }
                }
            });
        });

        // Animate stat numbers when visible
        var statNumbers = analyticsSection.querySelectorAll('.bne-analytics__stat-value');

        if (statNumbers.length && 'IntersectionObserver' in window) {
            var statsObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        animateNumber(entry.target);
                        statsObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.5
            });

            statNumbers.forEach(function(stat) {
                statsObserver.observe(stat);
            });
        }
    }

    /**
     * Animate a number from 0 to its final value
     * Skips animation for price values (contain decimal points) to preserve formatting
     */
    function animateNumber(element) {
        var text = element.textContent;

        // Skip animation for price values with decimals (e.g., $3.6M) - preserve original
        if (text.indexOf('.') !== -1) {
            return;
        }

        var finalValue = parseInt(text.replace(/[^0-9]/g, ''), 10);
        var prefix = text.match(/^[^0-9]*/)[0] || '';
        var suffix = text.match(/[^0-9]*$/)[0] || '';

        if (isNaN(finalValue)) {
            return;
        }

        var duration = 1500;
        var startTime = null;

        function animate(currentTime) {
            if (!startTime) {
                startTime = currentTime;
            }

            var elapsed = currentTime - startTime;
            var progress = Math.min(elapsed / duration, 1);

            // Ease out cubic
            var easeProgress = 1 - Math.pow(1 - progress, 3);

            var currentValue = Math.floor(easeProgress * finalValue);
            element.textContent = prefix + currentValue.toLocaleString() + suffix;

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                element.textContent = prefix + finalValue.toLocaleString() + suffix;
            }
        }

        // Check for reduced motion
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        requestAnimationFrame(animate);
    }

})();
