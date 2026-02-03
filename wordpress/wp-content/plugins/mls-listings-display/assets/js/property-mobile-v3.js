/**
 * Mobile Single Property JavaScript V3
 * Combines V2 bottom sheet mechanics with V3 functionality
 * Version: 4.3.0
 *
 * v4.2.0 Changes:
 * - Added YouTubeVideoHandler class for video modal functionality
 * - Updated photo counter to include YouTube video in count
 * - Removed infinite scroll for better UX
 * - Fixed modal close button functionality
 * - Improved scroll handling for natural touch scrolling
 * @param $
 */

(function ($) {
  'use strict';

  // Global error handler to catch any unhandled errors
  window.addEventListener('error', function(e) {
    // ERROR: commented
    return false; // Prevent default error handling
  });

  // Use SafeLogger from core classes or fallback (production: only error outputs to console)
  const SafeLogger = window.SafeLogger || {
    debug: function() {},
    info: function() {},
    warning: function() {},
    error: function(msg, context) {
      if (console && console.error) {
        console.error('[MLD Error]', msg, context || '');
      }
    }
  };

  // Bottom Sheet Class (from V2)
  class SimpleBottomSheet {
    constructor(element) {
      this.sheet = element;
      this.handle = element.querySelector('.mld-sheet-handle');
      this.content = element.querySelector('.mld-sheet-content');
      this.galleryControls = document.getElementById('galleryControls');

      // Configuration - only two positions (v6.25.9)
      // v6.25.18: Reduce open position on iPhone to stay below browser navigation bar
      const isIPhone = /iPhone/.test(navigator.userAgent);
      this.positions = {
        closed: 15, // Bottom position - handle visible above browser bottom UI
        open: isIPhone ? 85 : 95, // iPhone: 85% to stay below browser UI, Others: 95%
      };

      // State - start at closed position
      this.currentPosition = this.positions.closed;
      this.startY = 0;
      this.startHeight = 0;
      this.isDragging = false;

      // v6.25.19: Velocity tracking for sensitive gesture detection
      this.lastY = 0;
      this.lastTime = 0;
      this.velocity = 0;

      // v6.25.20: Pull-to-dismiss from content area
      this.isPullToDismiss = false;
      this.contentStartY = 0;

      this.init();
    }

    init() {
      // Ensure sheet element is properly configured
      this.sheet.style.position = 'fixed';
      this.sheet.style.bottom = '0';
      this.sheet.style.left = '0';
      this.sheet.style.right = '0';
      this.sheet.style.display = 'flex';
      this.sheet.style.visibility = 'visible';
      this.sheet.style.zIndex = '1000';

      // Set initial position with robust error handling
      try {
        this.setPosition(this.positions.closed);
        SafeLogger.debug('Bottom sheet initialized at closed position');
      } catch (e) {
        SafeLogger.error('Error setting initial position:', e);
        // Fallback: set transform directly
        this.sheet.style.transform = 'translateY(85%)';
        this.sheet.style.height = '100vh';
        this.currentPosition = 15;
      }

      // Force update gallery controls position after a small delay for iOS
      setTimeout(() => {
        this.updateGalleryControlsPosition();
        this.validateBottomSheetPosition();
      }, 100);

      // Only allow dragging from the handle
      this.handle.addEventListener('touchstart', this.onTouchStart.bind(this), { passive: true });
      this.handle.addEventListener('touchmove', this.onTouchMove.bind(this), { passive: false });
      this.handle.addEventListener('touchend', this.onTouchEnd.bind(this), { passive: true });

      // Add click to toggle
      this.handle.addEventListener('click', this.toggle.bind(this));

      // v6.25.20: Pull-to-dismiss from content area when scrolled to top
      if (this.content) {
        this.content.addEventListener('touchstart', this.onContentTouchStart.bind(this), { passive: true });
        this.content.addEventListener('touchmove', this.onContentTouchMove.bind(this), { passive: false });
        this.content.addEventListener('touchend', this.onContentTouchEnd.bind(this), { passive: true });
      }

      // Update on orientation change (important for iOS)
      window.addEventListener('orientationchange', () => {
        setTimeout(() => {
          this.updateGalleryControlsPosition();
        }, 300);
      });
    }

    updateGalleryControlsPosition() {
      // v6.25.23: Gallery controls are now inline inside the sheet, no position updates needed
      // Keeping method as no-op for backwards compatibility
    }

    validateBottomSheetPosition() {
      // Double-check position is correct
      const currentTransform = window.getComputedStyle(this.sheet).transform;
      const currentDisplay = window.getComputedStyle(this.sheet).display;

      if (currentTransform === 'none') {
        SafeLogger.warning('Bottom sheet transform not applied, reapplying...');
        this.sheet.style.transform = 'translateY(85%)'; // closed position fallback
      }

      if (currentDisplay === 'none') {
        SafeLogger.warning('Bottom sheet display is none, fixing...');
        this.sheet.style.display = 'flex';
      }

      SafeLogger.debug(`Bottom sheet validation complete - Transform: ${currentTransform}, Display: ${currentDisplay}`);
    }

    onTouchStart(e) {
      this.isDragging = true;
      this.startY = e.touches[0].clientY;
      this.startHeight = (this.currentPosition / 100) * window.innerHeight;
      this.sheet.style.transition = 'none';

      // v6.25.19: Initialize velocity tracking
      this.lastY = this.startY;
      this.lastTime = Date.now();
      this.velocity = 0;
    }

    onTouchMove(e) {
      if (!this.isDragging) return;

      e.preventDefault(); // Prevent scrolling while dragging

      const currentY = e.touches[0].clientY;
      const currentTime = Date.now();

      // v6.25.19: Track velocity (pixels per millisecond)
      const timeDelta = currentTime - this.lastTime;
      if (timeDelta > 0) {
        // Negative velocity = swiping up (opening), positive = swiping down (closing)
        this.velocity = (currentY - this.lastY) / timeDelta;
      }
      this.lastY = currentY;
      this.lastTime = currentTime;

      const deltaY = this.startY - currentY;
      const newHeight = this.startHeight + deltaY;
      const percentHeight = (newHeight / window.innerHeight) * 100;

      // Constrain between min (20%) and max position (80%)
      const constrainedPercent = Math.max(
        this.positions.closed,
        Math.min(this.positions.open, percentHeight)
      );
      this.setPosition(constrainedPercent);
    }

    onTouchEnd(e) {
      if (!this.isDragging) return;

      this.isDragging = false;
      this.sheet.style.transition = 'transform 0.3s ease-out';

      // v6.25.19: Use velocity-based gesture detection for sensitive response
      // Velocity threshold: 0.15 pixels/ms is a light flick gesture
      // Lower = more sensitive, higher = requires more deliberate swipe
      const velocityThreshold = 0.15;

      let snapPosition;

      if (this.velocity < -velocityThreshold) {
        // Swiping up (negative velocity) - open the sheet
        snapPosition = this.positions.open;
      } else if (this.velocity > velocityThreshold) {
        // Swiping down (positive velocity) - close the sheet
        snapPosition = this.positions.closed;
      } else {
        // Slow/no velocity - snap to nearest position (original behavior)
        snapPosition = this.getNearestSnapPosition(this.currentPosition);
      }

      this.setPosition(snapPosition);
    }

    // v6.25.20: Content area touch handlers for pull-to-dismiss
    onContentTouchStart(e) {
      // Only enable pull-to-dismiss when content is at the very top
      if (this.content.scrollTop <= 0) {
        this.contentStartY = e.touches[0].clientY;
        this.isPullToDismiss = false; // Will be set true if user swipes down
      }
    }

    onContentTouchMove(e) {
      // Only process if content is at top
      if (this.content.scrollTop > 0) {
        this.isPullToDismiss = false;
        return;
      }

      const currentY = e.touches[0].clientY;
      const deltaY = currentY - this.contentStartY;

      // If user is swiping down (positive delta) and content is at top
      if (deltaY > 10 && this.content.scrollTop <= 0) {
        // Activate pull-to-dismiss mode
        if (!this.isPullToDismiss) {
          this.isPullToDismiss = true;
          this.isDragging = true;
          this.startY = this.contentStartY;
          this.startHeight = (this.currentPosition / 100) * window.innerHeight;
          this.sheet.style.transition = 'none';

          // Initialize velocity tracking
          this.lastY = currentY;
          this.lastTime = Date.now();
          this.velocity = 0;
        }

        // Prevent content scrolling, move the sheet instead
        e.preventDefault();

        // Calculate and track velocity
        const currentTime = Date.now();
        const timeDelta = currentTime - this.lastTime;
        if (timeDelta > 0) {
          this.velocity = (currentY - this.lastY) / timeDelta;
        }
        this.lastY = currentY;
        this.lastTime = currentTime;

        // Move the sheet
        const newHeight = this.startHeight - deltaY;
        const percentHeight = (newHeight / window.innerHeight) * 100;
        const constrainedPercent = Math.max(
          this.positions.closed,
          Math.min(this.positions.open, percentHeight)
        );
        this.setPosition(constrainedPercent);
      }
    }

    onContentTouchEnd(e) {
      if (!this.isPullToDismiss) return;

      this.isPullToDismiss = false;
      this.isDragging = false;
      this.sheet.style.transition = 'transform 0.3s ease-out';

      // Use velocity-based detection (same as handle)
      const velocityThreshold = 0.15;
      let snapPosition;

      if (this.velocity > velocityThreshold) {
        // Swiping down - close the sheet
        snapPosition = this.positions.closed;
      } else if (this.velocity < -velocityThreshold) {
        // Swiping up - keep open
        snapPosition = this.positions.open;
      } else {
        // Slow/no velocity - snap to nearest
        snapPosition = this.getNearestSnapPosition(this.currentPosition);
      }

      this.setPosition(snapPosition);
    }

    toggle() {
      // Simple toggle between two positions: closed <-> open (v6.25.9)
      if (this.currentPosition < 50) {
        this.animateTo(this.positions.open);
      } else {
        this.animateTo(this.positions.closed);
      }
    }

    getNearestSnapPosition(percent) {
      const positions = Object.values(this.positions);
      return positions.reduce((prev, curr) =>
        Math.abs(curr - percent) < Math.abs(prev - percent) ? curr : prev
      );
    }

    setPosition(percent) {
      this.currentPosition = percent;
      const translateY = 100 - percent;
      this.sheet.style.transform = `translateY(${translateY}%)`;

      // v6.25.23: Gallery controls are now inline inside sheet, no position update needed
    }

    animateTo(percent) {
      this.sheet.style.transition = 'transform 0.3s ease-out';
      this.setPosition(percent);
    }
  }

  // Section Collapse Management
  class SectionManager {
    constructor() {
      this.sections = document.querySelectorAll('.mld-v3-section-mobile');
      this.locationMapInitialized = false;
      this.init();
    }

    init() {
      // Add collapse functionality to sections with toggle buttons
      this.sections.forEach((section) => {
        const toggleBtn = section.querySelector('.mld-v3-section-toggle');
        if (toggleBtn) {
          toggleBtn.addEventListener('click', () => this.toggleSection(section));

          // Facts section now starts expanded by default
          // (removed auto-collapse for better user experience)
        }
      });

      // Initialize visible maps on scroll
      this.initializeVisibleMaps();

      // Listen for scroll to lazy-load maps
      const sheetContent = document.querySelector('.mld-sheet-content');
      if (sheetContent) {
        let scrollTimeout;
        sheetContent.addEventListener('scroll', () => {
          clearTimeout(scrollTimeout);
          scrollTimeout = setTimeout(() => {
            this.initializeVisibleMaps();
          }, 100);
        });
      }
    }

    toggleSection(section) {
      section.classList.toggle('collapsed');

      // If expanding a section with a map, initialize it
      if (!section.classList.contains('collapsed') && section.id === 'location' && !this.locationMapInitialized) {
        this.initializeLocationMap();
      }
    }

    initializeVisibleMaps() {
      const locationSection = document.getElementById('location');
      if (locationSection && !this.locationMapInitialized && this.isElementInViewport(locationSection)) {
        this.initializeLocationMap();
      }
    }

    isElementInViewport(el) {
      const rect = el.getBoundingClientRect();
      return (
        rect.top <= window.innerHeight &&
        rect.bottom >= 0
      );
    }

    // v6.26.0: Initialize interactive inline map with skeleton loader and expand functionality
    initializeLocationMap() {
      const mapContainer = document.getElementById('propertyMapTab');
      const container = document.getElementById('inlineMapContainer');
      const skeleton = document.getElementById('mapSkeleton');
      const expandBtn = document.getElementById('expandMapBtn');

      if (!mapContainer || !window.mldPropertyData?.coordinates) return;

      const { lat, lng } = window.mldPropertyData.coordinates;
      const latNum = parseFloat(lat);
      const lngNum = parseFloat(lng);
      const address = mapContainer.getAttribute('data-address') || window.mldPropertyData?.address;
      const price = mapContainer.getAttribute('data-price') || window.mldPropertyData?.price;
      const photoUrl = mapContainer.getAttribute('data-photo') || window.mldPropertyData?.mainPhoto;

      // Check if Google Maps API is available
      if (window.google && window.google.maps && window.bmeMapData?.google_key) {
        this.createInlineInteractiveMap(mapContainer, latNum, lngNum, address, price, photoUrl, container, skeleton);
      } else if (window.bmeMapData?.google_key) {
        // Wait for API then create map
        const checkApi = setInterval(() => {
          if (window.google && window.google.maps) {
            clearInterval(checkApi);
            this.createInlineInteractiveMap(mapContainer, latNum, lngNum, address, price, photoUrl, container, skeleton);
          }
        }, 100);
        // Timeout after 5 seconds and fall back to iframe
        setTimeout(() => {
          clearInterval(checkApi);
          if (!this.inlineMapInitialized) {
            this.showInlineMapFallback(mapContainer, latNum, lngNum);
            if (container) container.classList.add('map-loaded');
          }
        }, 5000);
      } else {
        this.showInlineMapFallback(mapContainer, latNum, lngNum);
        if (container) container.classList.add('map-loaded');
      }

      // Set up expand button
      if (expandBtn) {
        expandBtn.addEventListener('click', () => {
          this.expandInlineMap();
          if (navigator.vibrate) navigator.vibrate(30);
        });
      }

      this.locationMapInitialized = true;
    }

    // v6.26.0: Create interactive inline map with custom marker
    createInlineInteractiveMap(container, lat, lng, address, price, photoUrl, wrapperContainer, skeleton) {
      try {
        container.innerHTML = '';

        const useAdvancedMarker = window.google.maps.marker && window.google.maps.marker.AdvancedMarkerElement;

        // v6.26.2: Disable ALL Google controls - we use our own FABs
        const mapOptions = {
          center: { lat, lng },
          zoom: 16,
          mapTypeControl: false,
          zoomControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          scaleControl: false,
          gestureHandling: 'greedy', // Single finger navigation
          clickableIcons: false,
        };

        if (useAdvancedMarker) {
          mapOptions.mapId = 'PROPERTY_MAP_INLINE';
        }

        this.inlineMap = new google.maps.Map(container, mapOptions);

        // v6.26.1: Create simple price pin marker
        if (useAdvancedMarker) {
          const markerContent = this.createPriceMarker(price);
          this.inlineMapMarker = new google.maps.marker.AdvancedMarkerElement({
            position: { lat, lng },
            map: this.inlineMap,
            content: markerContent,
            title: address || 'Property Location',
          });

          // Add click handler to show info window
          this.inlineMapMarker.addListener('click', () => {
            this.expandInlineMap();
          });
        } else {
          this.inlineMapMarker = new google.maps.Marker({
            position: { lat, lng },
            map: this.inlineMap,
            title: address || 'Property Location',
          });

          this.inlineMapMarker.addListener('click', () => {
            this.expandInlineMap();
          });
        }

        // Hide skeleton and show map when tiles loaded
        google.maps.event.addListenerOnce(this.inlineMap, 'tilesloaded', () => {
          if (wrapperContainer) wrapperContainer.classList.add('map-loaded');
        });

        this.inlineMapInitialized = true;
        SafeLogger.debug('[MLD] Inline interactive map created');
      } catch (error) {
        SafeLogger.error('[MLD] Inline map error:', error);
        this.showInlineMapFallback(container, lat, lng);
        if (wrapperContainer) wrapperContainer.classList.add('map-loaded');
      }
    }

    // v6.26.1: Create simple price pin marker (Zillow-style)
    createPriceMarker(price) {
      const marker = document.createElement('div');
      marker.className = 'mld-price-marker';
      marker.textContent = this.formatCompactPrice(price) || 'View';
      return marker;
    }

    // v6.26.2: Format price compactly for marker badge (e.g., $525K, $1.2M)
    formatCompactPrice(price) {
      if (!price) return '';
      const num = parseInt(price);
      if (isNaN(num)) return '';
      if (num >= 1000000) {
        return '$' + (num / 1000000).toFixed(num % 1000000 === 0 ? 0 : 1) + 'M';
      } else if (num >= 1000) {
        return '$' + Math.round(num / 1000) + 'K';
      }
      return '$' + num.toLocaleString();
    }

    // v6.26.0: Fallback to iframe for inline map
    showInlineMapFallback(container, lat, lng) {
      container.innerHTML = '';
      if (window.bmeMapData?.google_key) {
        const iframe = document.createElement('iframe');
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.frameBorder = '0';
        iframe.allowFullscreen = true;
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
        iframe.src = `https://www.google.com/maps/embed/v1/place?key=${window.bmeMapData.google_key}&q=${lat},${lng}&zoom=16`;
        container.appendChild(iframe);
      } else {
        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Map API key not configured.</div>';
      }
    }

    // v6.26.6: Expand inline map to fullscreen modal
    expandInlineMap() {
      const modal = document.getElementById('mapModal');
      if (!modal) return;

      // v6.26.6: Use galleryControlsInstance (correct variable name)
      if (window.galleryControlsInstance) {
        window.galleryControlsInstance.showMapModal();
      } else {
        // Fallback if GalleryControls not available
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        const mapPanel = document.getElementById('modalMap');
        if (mapPanel) mapPanel.classList.add('active');

        requestAnimationFrame(() => {
          modal.classList.add('active');
        });
      }
    }
  }

  // Gallery Controls Class
  class GalleryControls {
    constructor() {
      this.galleryScroll = document.querySelector('.mld-gallery-scroll');
      this.controls = document.querySelectorAll('.mld-gallery-control');
      this.photos = document.querySelectorAll('.mld-photo-item');
      this.mapModal = document.getElementById('mapModal');
      this.virtualTourModal = document.getElementById('virtualTourModal');
      this.currentView = 'photos';
      this.currentMapView = 'map'; // v6.25.34: Track current view within map modal

      // v6.25.34: Google Maps API instances
      this.map = null;
      this.mapMarker = null;
      this.mapInfoWindow = null;
      this.panorama = null;
      this.isMapInitialized = false;
      this.isStreetViewInitialized = false;

      this.init();
    }

    init() {
      this.controls.forEach((control) => {
        const view = control.getAttribute('data-view');
        control.addEventListener('click', () => {
          this.switchView(view);
        });
      });

      // Update photo counter
      this.updatePhotoCounter();
      if (this.galleryScroll) {
        this.galleryScroll.addEventListener('scroll', () => this.updatePhotoCounter());
      }

      // v6.25.26: Set up modal close handlers ONCE in init (not on each open)
      this.initModalCloseHandlers();

      // v6.26.0: Initialize FAB event handlers
      this.initMapFabs();
    }

    initModalCloseHandlers() {
      // Map modal close handlers (v6.26.1: back button instead of X close)
      if (this.mapModal) {
        const mapBack = this.mapModal.querySelector('.mld-modal-back');
        const mapClose = this.mapModal.querySelector('.mld-modal-close');
        const mapBackdrop = this.mapModal.querySelector('.mld-modal-backdrop');
        const mapContent = this.mapModal.querySelector('.mld-modal-content');

        // v6.26.1: Back button handler (new)
        if (mapBack) mapBack.addEventListener('click', () => this.closeModal(this.mapModal));
        // Legacy X close button (fallback)
        if (mapClose) mapClose.addEventListener('click', () => this.closeModal(this.mapModal));

        // v6.26.4: Only close on direct backdrop click, not bubbled events
        if (mapBackdrop) {
          mapBackdrop.addEventListener('click', (e) => {
            if (e.target === mapBackdrop) {
              this.closeModal(this.mapModal);
            }
          });
        }

        // v6.26.4: Prevent touch/click events on content from reaching backdrop
        if (mapContent) {
          mapContent.addEventListener('click', (e) => e.stopPropagation());
          mapContent.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });
          mapContent.addEventListener('touchmove', (e) => e.stopPropagation(), { passive: true });
          mapContent.addEventListener('touchend', (e) => e.stopPropagation(), { passive: true });
        }

        // v6.25.34: Add view toggle tab handlers
        const viewTabs = this.mapModal.querySelectorAll('.mld-view-tab');
        viewTabs.forEach((tab) => {
          tab.addEventListener('click', (e) => {
            const view = e.target.getAttribute('data-view');
            this.switchMapView(view);
          });
        });
      }

      // Virtual tour modal close handlers
      if (this.virtualTourModal) {
        const tourClose = this.virtualTourModal.querySelector('.mld-modal-close');
        const tourBackdrop = this.virtualTourModal.querySelector('.mld-modal-backdrop');
        if (tourClose) tourClose.addEventListener('click', () => this.closeModal(this.virtualTourModal));
        if (tourBackdrop) {
          tourBackdrop.addEventListener('click', (e) => {
            if (e.target === tourBackdrop) {
              this.closeModal(this.virtualTourModal);
            }
          });
        }
      }
    }

    // v6.25.34: Switch between map and street view within unified modal
    switchMapView(view) {
      this.currentMapView = view;

      // v6.26.0: Update FAB visibility based on current view
      this.updateModalFabVisibility();

      // Update tab active states
      const tabs = this.mapModal.querySelectorAll('.mld-view-tab');
      tabs.forEach((tab) => {
        tab.classList.toggle('active', tab.getAttribute('data-view') === view);
      });

      // Update panel active states
      const mapPanel = document.getElementById('modalMap');
      const streetViewPanel = document.getElementById('modalStreetView');

      if (view === 'map') {
        if (streetViewPanel) streetViewPanel.classList.remove('active');
        if (mapPanel) mapPanel.classList.add('active');

        // v6.25.39: Restore body styles and remove touch handler when switching to map
        if (this.streetViewBodyLocked) {
          this.streetViewBodyLocked = false;

          // Restore original body styles
          if (this.originalBodyStyles) {
            document.body.style.overflow = this.originalBodyStyles.overflow;
            document.body.style.position = this.originalBodyStyles.position;
            document.body.style.touchAction = this.originalBodyStyles.touchAction;
            document.body.style.width = this.originalBodyStyles.width;
            document.body.style.height = this.originalBodyStyles.height;
            document.body.style.top = this.originalBodyStyles.top;
            document.body.style.left = this.originalBodyStyles.left;
          }

          // Remove touch handler
          if (this.streetViewTouchPreventHandler) {
            document.removeEventListener('touchmove', this.streetViewTouchPreventHandler, {
              passive: false,
              capture: true
            });
            this.streetViewTouchPreventHandler = null;
          }
        }

        // Trigger map resize if already initialized (wait for CSS transition)
        setTimeout(() => {
          if (this.map && this.isMapInitialized) {
            google.maps.event.trigger(this.map, 'resize');
          } else {
            // Initialize map if not done yet
            this.initializeModalMap();
          }
        }, 350); // Wait for CSS transition (0.3s) plus buffer
      } else if (view === 'streetview') {
        if (mapPanel) mapPanel.classList.remove('active');
        if (streetViewPanel) streetViewPanel.classList.add('active');

        // v6.25.39: Completely lock down page scrolling when Street View is active
        // This prevents the browser from interpreting ANY touch as a scroll
        if (!this.streetViewBodyLocked) {
          this.streetViewBodyLocked = true;

          // Save original body styles
          this.originalBodyStyles = {
            overflow: document.body.style.overflow,
            position: document.body.style.position,
            touchAction: document.body.style.touchAction,
            width: document.body.style.width,
            height: document.body.style.height,
            top: document.body.style.top,
            left: document.body.style.left
          };

          // Lock the body completely
          document.body.style.overflow = 'hidden';
          document.body.style.position = 'fixed';
          document.body.style.touchAction = 'none';
          document.body.style.width = '100%';
          document.body.style.height = '100%';
          document.body.style.top = '0';
          document.body.style.left = '0';

          // Also add document-level touch handler
          this.streetViewTouchPreventHandler = (e) => {
            if (e.target.closest('#modalStreetView')) {
              e.preventDefault();
            }
          };
          document.addEventListener('touchmove', this.streetViewTouchPreventHandler, {
            passive: false,
            capture: true
          });
        }

        // Wait for panel to be visible before initializing Street View
        setTimeout(() => {
          if (!this.isStreetViewInitialized) {
            this.initializeModalStreetView();
          } else if (this.panorama) {
            // Trigger resize on existing panorama
            google.maps.event.trigger(this.panorama, 'resize');
          }
        }, 350); // Wait for CSS transition (0.3s) plus buffer
      }
    }

    switchView(view) {
      this.currentView = view;

      // v6.25.25: Photos is just a trigger (opens lightbox), don't highlight it
      // Only highlight map/street/tour buttons when their modals are open
      if (view !== 'photos') {
        this.controls.forEach((control) => {
          control.classList.toggle('active', control.getAttribute('data-view') === view);
        });
      }

      // Handle different views
      switch (view) {
        case 'photos':
          this.showPhotos();
          break;
        case 'map':
          this.showMapModal();
          break;
        case 'street':
          this.showStreetViewModal();
          break;
        case 'tour':
          this.show3DTourModal();
          break;
      }
    }

    showPhotos() {
      // v6.25.25: Open fullscreen lightbox when Photos button is clicked
      if (typeof window.mldOpenLightbox === 'function') {
        window.mldOpenLightbox(0); // Open lightbox at first photo
      }
      // Don't set active state - the button is just a trigger
    }

    showMapModal() {
      // v6.25.30: Re-query modal element fresh to avoid stale references
      const modal = document.getElementById('mapModal');
      if (!modal) return;

      // Update the cached reference
      this.mapModal = modal;

      // Clear any existing map first
      this.destroyMap();

      // v6.26.3: Show modal with CSS animation
      modal.style.display = 'block';
      document.body.style.overflow = 'hidden';

      // v6.26.5: Trigger slide-in animation
      requestAnimationFrame(() => {
        modal.classList.add('active');
      });

      // v6.26.5: Use switchMapView to handle panel activation AND map initialization
      // This is the same code path that works when clicking the Map tab
      setTimeout(() => {
        this.switchMapView('map');
      }, 100);
    }

    showStreetViewModal() {
      // v6.25.34: Street View is now in unified mapModal - open it and switch to streetview tab
      const modal = document.getElementById('mapModal');
      if (!modal) return;

      // Update the cached reference
      this.mapModal = modal;

      // v6.26.3: Show modal with CSS animation
      modal.style.display = 'block';
      document.body.style.overflow = 'hidden';

      // v6.26.3: Trigger slide-in animation after brief delay
      requestAnimationFrame(() => {
        modal.classList.add('active');
      });

      // v6.25.34: Switch to street view tab after modal opens
      setTimeout(() => {
        this.switchMapView('streetview');
      }, 200);
    }

    show3DTourModal() {
      // v6.25.30: Re-query modal element fresh to avoid stale references
      const modal = document.getElementById('virtualTourModal');

      // v6.25.25: Enhanced 3D Tour modal with better error handling
      const tourBtn = document.querySelector('.mld-gallery-control[data-view="tour"]');

      if (!tourBtn) {
        return;
      }

      if (!modal) {
        return;
      }

      // Update the cached reference
      this.virtualTourModal = modal;

      const embedUrl = tourBtn.getAttribute('data-embed-url');

      if (!embedUrl) {
        return;
      }

      // Show modal - v6.25.29: Force all visibility properties
      modal.style.display = 'block';
      modal.style.visibility = 'visible';
      modal.style.opacity = '1';
      modal.style.pointerEvents = 'auto';
      document.body.style.overflow = 'hidden';

      // v6.25.29: Also force visibility on child elements
      const backdrop = modal.querySelector('.mld-modal-backdrop');
      const content = modal.querySelector('.mld-modal-content');
      if (backdrop) {
        backdrop.style.display = 'block';
        backdrop.style.visibility = 'visible';
        backdrop.style.opacity = '1';
      }
      if (content) {
        content.style.display = 'block';
        content.style.visibility = 'visible';
        content.style.opacity = '1';
      }

      // Load tour iframe
      const viewer = document.getElementById('tourViewer');
      if (viewer && !viewer.querySelector('iframe')) {
        const iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('allow', 'xr-spatial-tracking; vr; gyroscope; accelerometer');
        viewer.appendChild(iframe);
      }
      // v6.25.26: Close handlers moved to initModalCloseHandlers()
    }

    closeModal(modal) {
      const modalId = modal ? modal.id : 'N/A';

      // v6.26.3: Remove active class and wait for slide-out animation
      modal.classList.remove('active');

      // v6.26.3: Wait for animation to complete before hiding (350ms matches CSS transition)
      setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = '';
      }, 350);

      // v6.25.27: Just remove active states, don't call showPhotos (which opens lightbox)
      this.controls.forEach((control) => {
        control.classList.remove('active');
      });

      // v6.25.34: Unified modal - destroy both map and street view when mapModal closes
      if (modalId === 'mapModal') {
        this.destroyMap();
        this.destroyStreetView();

        // v6.25.39: Restore body styles when closing modal
        if (this.streetViewBodyLocked) {
          this.streetViewBodyLocked = false;
          if (this.originalBodyStyles) {
            document.body.style.overflow = this.originalBodyStyles.overflow;
            document.body.style.position = this.originalBodyStyles.position;
            document.body.style.touchAction = this.originalBodyStyles.touchAction;
            document.body.style.width = this.originalBodyStyles.width;
            document.body.style.height = this.originalBodyStyles.height;
            document.body.style.top = this.originalBodyStyles.top;
            document.body.style.left = this.originalBodyStyles.left;
          }
          if (this.streetViewTouchPreventHandler) {
            document.removeEventListener('touchmove', this.streetViewTouchPreventHandler, {
              passive: false,
              capture: true
            });
            this.streetViewTouchPreventHandler = null;
          }
        }

        // Reset view to map tab for next open
        this.currentMapView = 'map';
        const mapPanel = document.getElementById('modalMap');
        const streetViewPanel = document.getElementById('modalStreetView');
        const tabs = modal.querySelectorAll('.mld-view-tab');

        if (mapPanel) mapPanel.classList.add('active');
        if (streetViewPanel) streetViewPanel.classList.remove('active');
        tabs.forEach((tab) => {
          tab.classList.toggle('active', tab.getAttribute('data-view') === 'map');
        });
      }
    }

    destroyMap() {
      // v6.25.34: Clean up Google Maps API instances
      if (this.mapMarker) {
        this.mapMarker.setMap(null);
        this.mapMarker = null;
      }
      if (this.mapInfoWindow) {
        this.mapInfoWindow.close();
        this.mapInfoWindow = null;
      }
      // Note: Google Maps doesn't have a destroy method - setting to null for GC
      this.map = null;
      this.isMapInitialized = false;

      // Clear container (also handles fallback iframes)
      const mapContainer = document.getElementById('modalMap');
      if (mapContainer) {
        const iframes = mapContainer.querySelectorAll('iframe');
        iframes.forEach((iframe) => {
          iframe.src = 'about:blank';
        });
        mapContainer.innerHTML = '';
      }
    }

    destroyStreetView() {
      // v6.25.34: Clean up StreetViewPanorama instance
      if (this.panorama) {
        this.panorama.setVisible(false);
        this.panorama = null;
      }
      this.isStreetViewInitialized = false;

      // Clear container (also handles fallback iframes)
      const streetViewContainer = document.getElementById('modalStreetView');
      if (streetViewContainer) {
        const iframes = streetViewContainer.querySelectorAll('iframe');
        iframes.forEach((iframe) => {
          iframe.src = 'about:blank';
        });
        streetViewContainer.innerHTML = '';
      }
    }

    // Method removed in v4.2.0 - infinite scroll disabled for better UX

    initializeModalMap() {
      const mapContainer = document.getElementById('modalMap');
      if (!mapContainer) return;

      // Get coordinates from data attributes as fallback
      const lat = mapContainer.getAttribute('data-lat') || window.mldPropertyData?.coordinates?.lat;
      const lng = mapContainer.getAttribute('data-lng') || window.mldPropertyData?.coordinates?.lng;
      const address = mapContainer.getAttribute('data-address') || window.mldPropertyData?.address;
      const price = mapContainer.getAttribute('data-price') || window.mldPropertyData?.price;

      if (!lat || !lng) return;

      const latNum = parseFloat(lat);
      const lngNum = parseFloat(lng);

      // v6.25.34: Use Google Maps JavaScript API instead of iframe
      if (window.google && window.google.maps && window.google.maps.Map) {
        this.createInteractiveMap(mapContainer, latNum, lngNum, address, price);
      } else if (window.bmeMapData?.google_key) {
        // Wait for Google Maps API to load
        const checkGoogleMaps = setInterval(() => {
          if (window.google && window.google.maps && window.google.maps.Map) {
            clearInterval(checkGoogleMaps);
            this.createInteractiveMap(mapContainer, latNum, lngNum, address, price);
          }
        }, 100);

        // Timeout after 5 seconds and fall back to iframe
        setTimeout(() => {
          clearInterval(checkGoogleMaps);
          if (!this.isMapInitialized) {
            SafeLogger.warn('Google Maps API not loaded, falling back to iframe');
            this.showIframeMapFallback(mapContainer, latNum, lngNum);
          }
        }, 5000);
      } else {
        // No API key - show static map
        this.showStaticMapFallback(mapContainer, latNum, lngNum);
      }
    }

    // v6.25.34: Create interactive Google Maps using JavaScript API
    createInteractiveMap(container, lat, lng, address, price) {
      try {
        // Clear container
        container.innerHTML = '';

        // v6.26.5: Always use classic markers for reliability (AdvancedMarkerElement has mapId issues)
        const useAdvancedMarker = false;

        // v6.26.7: Build map options with satellite control
        const mapOptions = {
          center: { lat, lng },
          zoom: 16,
          mapTypeControl: true, // v6.26.7: Enable satellite/map toggle
          mapTypeControlOptions: {
            style: google.maps.MapTypeControlStyle.DROPDOWN_MENU,
            position: google.maps.ControlPosition.TOP_LEFT,
            mapTypeIds: ['roadmap', 'satellite', 'hybrid'],
          },
          zoomControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          scaleControl: false,
          rotateControl: false, // v6.26.7: Start with rotate off, enable for satellite
          gestureHandling: 'greedy', // Single finger pan on mobile
          clickableIcons: false,
          styles: [
            {
              featureType: 'poi',
              elementType: 'labels',
              stylers: [{ visibility: 'off' }],
            },
          ],
        };

        // Create map
        this.map = new google.maps.Map(container, mapOptions);

        // v6.26.7: Enable rotate control when satellite/hybrid view is activated
        this.map.addListener('maptypeid_changed', () => {
          const mapType = this.map.getMapTypeId();
          const isSatellite = mapType === 'satellite' || mapType === 'hybrid';
          this.map.setOptions({
            rotateControl: isSatellite,
            tilt: isSatellite ? 45 : 0, // Enable 45-degree tilt for satellite
          });
        });

        // v6.26.1: Get photo URL for info window only
        const photoUrl = window.mldPropertyData?.mainPhoto || null;

        // v6.26.5: Use classic marker for reliability
        this.mapMarker = new google.maps.Marker({
          position: { lat, lng },
          map: this.map,
          title: address || 'Property Location',
        });

        // v6.26.1: Create enhanced info window content (card style)
        const infoContent = this.createEnhancedInfoWindow(lat, lng, address, price, photoUrl);

        this.mapInfoWindow = new google.maps.InfoWindow({
          content: infoContent,
          maxWidth: 280,
        });

        // v6.26.5: Show info window on marker click (classic marker syntax)
        this.mapMarker.addListener('click', () => {
          this.mapInfoWindow.open(this.map, this.mapMarker);
        });

        // v6.26.5: Wrap Street View handling in try-catch so it doesn't break map
        try {
          const streetView = this.map.getStreetView();
          streetView.addListener('visible_changed', () => {
            if (streetView.getVisible()) {
              streetView.setVisible(false);
              this.switchMapView('streetview');
            }
          });
        } catch (svError) {
          // Street view handler setup failed - non-critical
        }

        this.isMapInitialized = true;
      } catch (error) {
        console.error('[MLD Map] Error creating interactive map:', error);
        SafeLogger.error('[MLD] Error creating interactive map:', error);
        this.showIframeMapFallback(container, lat, lng);
      }
    }

    // v6.25.34: Fallback to iframe embed
    showIframeMapFallback(container, lat, lng) {
      container.innerHTML = '';

      try {
        const iframe = document.createElement('iframe');
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.frameBorder = '0';
        iframe.allowFullscreen = true;
        iframe.setAttribute('loading', 'eager');
        iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');

        const embedUrl = `https://www.google.com/maps/embed/v1/place?key=${window.bmeMapData.google_key}&q=${lat},${lng}&zoom=16`;
        iframe.src = embedUrl;
        container.appendChild(iframe);
      } catch (error) {
        SafeLogger.error('Iframe map fallback error:', error);
        this.showStaticMapFallback(container, lat, lng);
      }
    }

    showStaticMapFallback(container, lat, lng) {
      // Show a static map image as fallback
      const staticMapUrl = `https://maps.googleapis.com/maps/api/staticmap?center=${lat},${lng}&zoom=16&size=600x400&markers=color:red%7C${lat},${lng}&key=${window.bmeMapData?.google_key || ''}`;

      container.innerHTML = `
                <div style="width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #f0f0f0;">
                    <img src="${staticMapUrl}" alt="Property Location" style="max-width: 100%; max-height: 80%; object-fit: contain;">
                    <a href="https://www.google.com/maps/search/?api=1&query=${lat},${lng}" target="_blank" rel="noopener" style="margin-top: 20px; padding: 10px 20px; background: #1a73e8; color: white; text-decoration: none; border-radius: 4px;">Open in Google Maps</a>
                </div>
            `;
    }

    // v6.26.2: Create simple price pin marker (Zillow-style)
    createPriceMarker(price) {
      const marker = document.createElement('div');
      marker.className = 'mld-price-marker';
      marker.textContent = this.formatCompactPrice(price) || 'View';
      return marker;
    }

    // v6.26.1: Format price compactly for marker badge (e.g., $525K, $1.2M)
    formatCompactPrice(price) {
      if (!price) return '';
      const num = parseInt(price);
      if (isNaN(num)) return '';
      if (num >= 1000000) {
        return '$' + (num / 1000000).toFixed(num % 1000000 === 0 ? 0 : 1) + 'M';
      } else if (num >= 1000) {
        return '$' + Math.round(num / 1000) + 'K';
      }
      return '$' + num.toLocaleString();
    }

    // v6.26.0: Create enhanced info window content (card style)
    createEnhancedInfoWindow(lat, lng, address, price, photoUrl) {
      const formattedPrice = price ? '$' + parseInt(price).toLocaleString() : '';
      const photoHtml = photoUrl
        ? `<img src="${photoUrl}" alt="Property" class="mld-info-card-image">`
        : '';

      return `
        <div class="mld-map-info-card">
          ${photoHtml}
          <div class="mld-info-card-content">
            ${formattedPrice ? `<p class="mld-info-card-price">${formattedPrice}</p>` : ''}
            <p class="mld-info-card-address">${address || 'Property Location'}</p>
            <div class="mld-info-card-actions">
              <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}"
                 target="_blank"
                 class="mld-info-card-btn mld-info-card-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M21.71 11.29l-9-9a.996.996 0 00-1.41 0l-9 9a.996.996 0 000 1.41l9 9c.39.39 1.02.39 1.41 0l9-9a.996.996 0 000-1.41zM14 14.5V12h-4v3H8v-4c0-.55.45-1 1-1h5V7.5l3.5 3.5-3.5 3.5z"/>
                </svg>
                Directions
              </a>
              <button class="mld-info-card-btn mld-info-card-btn-secondary" onclick="window.mldShareLocation()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/>
                </svg>
                Share
              </button>
            </div>
          </div>
        </div>
      `;
    }

    // v6.26.0: Initialize FAB event handlers
    initMapFabs() {
      // Get all direction buttons
      document.querySelectorAll('.mld-map-fab-directions').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const lat = btn.getAttribute('data-lat') || window.mldPropertyData?.coordinates?.lat;
          const lng = btn.getAttribute('data-lng') || window.mldPropertyData?.coordinates?.lng;
          if (lat && lng) {
            const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
            window.open(url, '_blank');
            // Haptic feedback
            if (navigator.vibrate) navigator.vibrate(30);
          }
        });
      });

      // Show FABs in modal when map tab is active
      this.updateModalFabVisibility();
    }

    // v6.26.0: Update modal FAB visibility based on current view
    updateModalFabVisibility() {
      const fabContainer = document.querySelector('.mld-map-body .mld-map-fab-container');
      if (fabContainer) {
        if (this.currentMapView === 'map') {
          fabContainer.classList.add('visible');
        } else {
          fabContainer.classList.remove('visible');
        }
      }
    }

    // v6.26.0: Share location function
    async shareLocation() {
      const address = window.mldPropertyData?.address || 'Property Location';
      const lat = window.mldPropertyData?.coordinates?.lat;
      const lng = window.mldPropertyData?.coordinates?.lng;
      const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

      if (navigator.share) {
        try {
          await navigator.share({
            title: address,
            text: `Location: ${address}`,
            url: mapsUrl
          });
          if (navigator.vibrate) navigator.vibrate(50);
        } catch (err) {
          if (err.name !== 'AbortError') {
            SafeLogger.error('Share error:', err);
          }
        }
      } else {
        // Fallback: copy to clipboard
        try {
          await navigator.clipboard.writeText(mapsUrl);
          this.showFabToast('Location copied!');
          if (navigator.vibrate) navigator.vibrate(50);
        } catch (err) {
          SafeLogger.error('Copy error:', err);
        }
      }
    }

    // v6.26.0: Helper to show temporary toast message
    showFabToast(message) {
      // Remove any existing toast
      const existingToast = document.querySelector('.mld-fab-toast');
      if (existingToast) existingToast.remove();

      const toast = document.createElement('div');
      toast.className = 'mld-fab-toast';
      toast.textContent = message;
      document.body.appendChild(toast);

      setTimeout(() => {
        toast.style.animation = 'mld-fadeOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
      }, 2000);
    }

    initializeModalStreetView() {
      const streetViewContainer = document.getElementById('modalStreetView');
      if (!streetViewContainer) return;

      // Get coordinates from multiple sources
      const mapContainer = document.getElementById('modalMap');
      let lat = mapContainer?.getAttribute('data-lat') || window.mldPropertyData?.coordinates?.lat;
      let lng = mapContainer?.getAttribute('data-lng') || window.mldPropertyData?.coordinates?.lng;

      if (!lat || !lng) return;

      const latNum = parseFloat(lat);
      const lngNum = parseFloat(lng);

      // Clear any existing content
      streetViewContainer.innerHTML = '';

      // v6.25.34: Use Google Maps StreetViewPanorama API
      if (window.google && window.google.maps && window.google.maps.StreetViewPanorama) {
        this.createInteractiveStreetView(streetViewContainer, latNum, lngNum);
      } else if (window.bmeMapData?.google_key) {
        // Wait for Google Maps API to load
        const checkGoogleMaps = setInterval(() => {
          if (window.google && window.google.maps && window.google.maps.StreetViewPanorama) {
            clearInterval(checkGoogleMaps);
            this.createInteractiveStreetView(streetViewContainer, latNum, lngNum);
          }
        }, 100);

        // Timeout after 5 seconds and fall back to iframe
        setTimeout(() => {
          clearInterval(checkGoogleMaps);
          if (!this.isStreetViewInitialized) {
            SafeLogger.warn('Google Maps StreetView API not loaded, falling back to iframe');
            this.showIframeStreetViewFallback(streetViewContainer, latNum, lngNum);
          }
        }, 5000);
      } else {
        this.showStreetViewUnavailable(streetViewContainer, latNum, lngNum, 'Street view not configured.');
      }
    }

    // v6.25.34: Create interactive Street View using StreetViewPanorama API
    createInteractiveStreetView(container, lat, lng) {
      try {
        // First check if Street View is available at this location
        const streetViewService = new google.maps.StreetViewService();

        streetViewService.getPanorama(
          {
            location: { lat, lng },
            radius: 50, // Search within 50 meters
            source: google.maps.StreetViewSource.OUTDOOR,
          },
          (data, status) => {
            if (status === google.maps.StreetViewStatus.OK) {
              // Street View is available - create panorama
              this.panorama = new google.maps.StreetViewPanorama(container, {
                position: { lat, lng },
                pov: {
                  heading: 34, // Initial direction
                  pitch: 0, // Initial vertical angle
                },
                zoom: 1,

                // Address display
                addressControl: true,
                addressControlOptions: {
                  position: google.maps.ControlPosition.BOTTOM_CENTER,
                },

                // Enable close button (returns to map if linked)
                enableCloseButton: false, // We use modal close

                // Fullscreen
                fullscreenControl: true,
                fullscreenControlOptions: {
                  position: google.maps.ControlPosition.TOP_RIGHT,
                },

                // Motion tracking on mobile devices
                motionTracking: true,
                motionTrackingControl: true,
                motionTrackingControlOptions: {
                  position: google.maps.ControlPosition.RIGHT_BOTTOM,
                },

                // Navigation links to connected panoramas
                linksControl: true,

                // Pan control
                panControl: true,
                panControlOptions: {
                  position: google.maps.ControlPosition.RIGHT_CENTER,
                },

                // Zoom control
                zoomControl: true,
                zoomControlOptions: {
                  position: google.maps.ControlPosition.RIGHT_CENTER,
                },

                // Show roads toggle
                showRoadLabels: true,

                // Scroll wheel zoom
                scrollwheel: true,

                // Click to go
                clickToGo: true,
              });

              // Use the actual panorama location for best view
              if (data.location && data.location.pano) {
                this.panorama.setPano(data.location.pano);
              }

              this.isStreetViewInitialized = true;
              SafeLogger.log('[MLD] Interactive Street View created successfully');

              // Trigger resize after a short delay to ensure proper rendering
              setTimeout(() => {
                if (this.panorama) {
                  google.maps.event.trigger(this.panorama, 'resize');
                }
              }, 100);
            } else {
              // Street View not available at this location
              SafeLogger.warn('[MLD] Street View not available:', status);
              this.showStreetViewUnavailable(container, lat, lng, 'Street View is not available for this location.');
            }
          }
        );
      } catch (error) {
        SafeLogger.error('[MLD] Error creating Street View:', error);
        this.showIframeStreetViewFallback(container, lat, lng);
      }
    }

    // v6.25.34: Fallback to iframe embed for Street View
    showIframeStreetViewFallback(container, lat, lng) {
      container.innerHTML = '';

      try {
        const iframe = document.createElement('iframe');
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.frameBorder = '0';
        iframe.allowFullscreen = true;
        iframe.setAttribute('loading', 'eager');
        iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');

        const embedUrl = `https://www.google.com/maps/embed/v1/streetview?key=${window.bmeMapData.google_key}&location=${lat},${lng}&heading=34&pitch=10&fov=90`;
        iframe.src = embedUrl;
        container.appendChild(iframe);
        this.isStreetViewInitialized = true;
      } catch (error) {
        SafeLogger.error('Street view iframe fallback error:', error);
        this.showStreetViewUnavailable(container, lat, lng, 'Street view not available.');
      }
    }

    // v6.25.34: Show message when Street View is unavailable
    showStreetViewUnavailable(container, lat, lng, message) {
      container.innerHTML = `
        <div class="mld-streetview-unavailable">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <p>${message}</p>
          <a href="https://www.google.com/maps/@${lat},${lng},3a,75y,34h,90t/data=!3m6!1e1!3m4!1s0x0:0x0!2e0!7i16384!8i8192"
             target="_blank"
             rel="noopener"
             class="mld-fallback-link">
            View on Google Maps
          </a>
        </div>
      `;
    }

    updatePhotoCounter() {
      const counter = document.getElementById('current-photo');
      if (!counter) return;

      const counterContainer = document.querySelector('.mld-photo-counter');
      const hasVideo = counterContainer && counterContainer.dataset.hasVideo === 'true';
      const allItems = this.galleryScroll.querySelectorAll('.mld-photo-item, .mld-youtube-preview');

      if (allItems.length === 0) return;

      const viewportHeight = window.innerHeight;
      const viewportCenter = viewportHeight / 2;

      // Find which item is currently in view
      let currentIndex = 1;

      // Check all items to find the one in the center of viewport
      for (let i = 0; i < allItems.length; i++) {
        const item = allItems[i];
        const rect = item.getBoundingClientRect();

        // Check if item center is in viewport
        if (rect.top <= viewportCenter && rect.bottom >= viewportCenter) {
          // Don't count cloned items
          if (!item.classList.contains('cloned')) {
            currentIndex = i + 1;
          }
          break;
        }
      }

      counter.textContent = currentIndex;
    }
  }

  // Payment Calculator
  class PaymentCalculator {
    constructor() {
      this.priceInput = document.getElementById('calcPrice');
      this.downPercentInput = document.getElementById('calcDownPercent');
      this.downAmountInput = document.getElementById('calcDownAmount');
      this.rateInput = document.getElementById('calcRate');
      this.termSelect = document.getElementById('calcTerm');
      this.paymentDisplay = document.getElementById('calcPayment');
      this.piDisplay = document.getElementById('calcPI');
      this.pmiDisplay = document.getElementById('calcPMI');
      this.pmiRow = document.getElementById('calcPMIRow');
      this.insuranceDisplay = document.getElementById('calcInsurance');

      if (!this.priceInput) return;

      this.init();
    }

    init() {
      this.priceInput.addEventListener('input', () => this.calculate());
      this.downPercentInput.addEventListener('input', () => this.calculate());
      this.rateInput.addEventListener('input', () => this.calculate());
      this.termSelect.addEventListener('change', () => this.calculate());

      this.calculate();
    }

    calculate() {
      const price = parseFloat(this.priceInput.value) || 0;
      const downPercent = parseFloat(this.downPercentInput.value) || 0;
      const rate = parseFloat(this.rateInput.value) || 0;
      const years = parseInt(this.termSelect.value) || 30;

      const downAmount = price * (downPercent / 100);
      const loanAmount = price - downAmount;
      const monthlyRate = rate / 100 / 12;
      const numPayments = years * 12;

      this.downAmountInput.value = Math.round(downAmount);

      if (monthlyRate > 0 && loanAmount > 0) {
        const monthlyPayment =
          (loanAmount * monthlyRate * Math.pow(1 + monthlyRate, numPayments)) /
          (Math.pow(1 + monthlyRate, numPayments) - 1);

        // Calculate PMI (if down payment < 20%)
        let pmi = 0;
        if (downPercent < 20) {
          pmi = (loanAmount * 0.005) / 12; // 0.5% annually
        }

        // Get other costs from property data
        const propertyTax = ((window.mldPropertyDataV3 && window.mldPropertyDataV3.propertyTax) || 0) / 12;
        const insurance = 200; // Default estimate
        const hoa = (window.mldPropertyDataV3 && window.mldPropertyDataV3.hoaFees) || 0;

        const totalPayment = monthlyPayment + propertyTax + insurance + hoa + pmi;

        this.paymentDisplay.textContent = '$' + Math.round(totalPayment).toLocaleString();
        this.piDisplay.textContent = '$' + Math.round(monthlyPayment).toLocaleString();

        // Update insurance display
        if (this.insuranceDisplay) {
          this.insuranceDisplay.textContent = '$' + Math.round(insurance).toLocaleString();
        }

        // Update PMI display
        if (this.pmiDisplay && this.pmiRow) {
          this.pmiDisplay.textContent = '$' + Math.round(pmi).toLocaleString();
          this.pmiRow.style.display = pmi > 0 ? 'flex' : 'none';
        }
      } else {
        this.paymentDisplay.textContent = '$0';
        this.piDisplay.textContent = '$0';
      }
    }
  }

  // Modal Handler
  class ModalHandler {
    constructor() {
      this.contactModal = document.getElementById('contactModal');
      this.tourModal = document.getElementById('tourModal');
      this.init();
    }

    init() {
      // Schedule Tour button
      const scheduleTourBtn = document.querySelector('.mld-schedule-tour');
      if (scheduleTourBtn) {
        scheduleTourBtn.addEventListener('click', () => {
          // Analytics: Track contact click for tour (v6.38.0)
          document.dispatchEvent(new CustomEvent('mld:contact_click', {
            detail: {
              listingId: window.mldPropertyDataV3?.listing_id || null,
              contactType: 'tour'
            }
          }));
          this.openTourModal();
        });
      }

      // Contact Agent button
      const contactAgentBtn = document.querySelector('.mld-contact-agent');
      if (contactAgentBtn) {
        contactAgentBtn.addEventListener('click', () => {
          // Analytics: Track contact click for message (v6.38.0)
          document.dispatchEvent(new CustomEvent('mld:contact_click', {
            detail: {
              listingId: window.mldPropertyDataV3?.listing_id || null,
              contactType: 'message'
            }
          }));
          this.openContactModal();
        });
      }

      // Add to Calendar buttons
      const calendarButtons = document.querySelectorAll('.mld-v3-add-calendar');
      calendarButtons.forEach((btn) => {
        btn.addEventListener('click', (e) => this.handleAddToCalendar(e));
      });

      // Modal close handlers
      this.setupModalCloseHandlers();
    }

    openContactModal() {
      if (this.contactModal) {
        this.contactModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      }
    }

    openTourModal() {
      if (this.tourModal) {
        this.tourModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      }
    }

    closeModal(modal) {
      if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
      }
    }

    setupModalCloseHandlers() {
      // Contact modal close handlers
      if (this.contactModal) {
        const closeBtn = this.contactModal.querySelector('.mld-modal-close');
        const backdrop = this.contactModal.querySelector('.mld-modal-backdrop');

        if (closeBtn) {
          closeBtn.addEventListener('click', () => this.closeModal(this.contactModal));
        }
        if (backdrop) {
          backdrop.addEventListener('click', () => this.closeModal(this.contactModal));
        }
      }

      // Tour modal close handlers
      if (this.tourModal) {
        const closeBtn = this.tourModal.querySelector('.mld-modal-close');
        const backdrop = this.tourModal.querySelector('.mld-modal-backdrop');

        if (closeBtn) {
          closeBtn.addEventListener('click', () => this.closeModal(this.tourModal));
        }
        if (backdrop) {
          backdrop.addEventListener('click', () => this.closeModal(this.tourModal));
        }
      }
    }

    handleAddToCalendar(e) {
      const button = e.target.closest('.mld-v3-add-calendar');
      if (!button) return;

      const title = button.dataset.title;
      const startDateStr = button.dataset.start;
      const endDateStr = button.dataset.end;
      const location = button.dataset.location;
      const timezone = button.dataset.timezone || 'America/New_York';

      // Parse the dates - they're already in Eastern Time
      const startDate = new Date(startDateStr);
      const endDate = new Date(endDateStr);

      // Format dates for calendar URL
      const formatDateForCalendar = (date) => {
        // The date is already in Eastern Time
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');

        // Return in local time format (without Z) and let Google Calendar handle timezone
        return `${year}${month}${day}T${hours}${minutes}${seconds}`;
      };

      // Create calendar event URL (Google Calendar) - exactly like desktop
      const googleUrl = new URL('https://calendar.google.com/calendar/render');
      googleUrl.searchParams.append('action', 'TEMPLATE');
      googleUrl.searchParams.append('text', title);
      googleUrl.searchParams.append(
        'dates',
        `${formatDateForCalendar(startDate)}/${formatDateForCalendar(endDate)}`
      );
      googleUrl.searchParams.append('location', location);
      googleUrl.searchParams.append('details', `Open house at ${location}`);
      googleUrl.searchParams.append('ctz', timezone); // Add timezone parameter

      // Send tracking notification to admin
      const formData = new FormData();
      formData.append('action', 'mld_track_calendar_add');
      formData.append('nonce', window.mldPropertyData?.nonce || window.mldSettings?.ajax_nonce);
      formData.append(
        'mls_number',
        window.mldPropertyData?.listingId || window.mldPropertyData?.mls_number || ''
      );
      formData.append('property_address', location);
      formData.append(
        'open_house_date',
        startDate.toLocaleDateString('en-US', {
          weekday: 'long',
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        })
      );
      formData.append(
        'open_house_time',
        `${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`
      );

      // Send the tracking request (don't wait for response)
      fetch(
        window.mldPropertyData?.ajaxUrl ||
          window.mldSettings?.ajax_url ||
          '/wp-admin/admin-ajax.php',
        {
          method: 'POST',
          body: formData,
        }
      ).catch((error) => {
        // Silently fail - don't interrupt user experience
        SafeLogger.error('Calendar tracking error:', error);
      });

      window.open(googleUrl, '_blank');
    }

    generateICS(event) {
      const ics = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//MLS Listings Display//Open House//EN',
        'BEGIN:VEVENT',
        `DTSTART:${event.start}`,
        `DTEND:${event.end}`,
        `SUMMARY:${event.title}`,
        `LOCATION:${event.location}`,
        `DESCRIPTION:${event.description}`,
        `UID:${Date.now()}@mlslistings.com`,
        'END:VEVENT',
        'END:VCALENDAR',
      ].join('\r\n');

      return ics;
    }
  }

  // Form Handlers
  class FormHandler {
    constructor() {
      this.contactForm = document.getElementById('contactForm');
      this.tourForm = document.getElementById('tourForm');
      this.init();
    }

    init() {
      if (this.contactForm) {
        this.contactForm.addEventListener('submit', (e) => this.handleContactSubmit(e));
      }

      if (this.tourForm) {
        this.tourForm.addEventListener('submit', (e) => this.handleTourSubmit(e));
      }
    }

    handleContactSubmit(e) {
      e.preventDefault();
      const form = e.target;
      const submitBtn = form.querySelector('[type="submit"]');
      const errorDiv = form.querySelector('.mld-form-error') || form.querySelector('.mld-form-status');
      const loadingDiv = form.querySelector('.mld-form-loading');

      // Clear previous errors
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }

      // Show loading
      if (submitBtn) submitBtn.disabled = true;
      if (loadingDiv) loadingDiv.classList.add('show');

      const formData = new FormData(form);
      formData.append('action', 'mld_contact_agent');

      fetch(
        window.mldPropertyData?.ajaxUrl ||
          window.mldSettings?.ajax_url ||
          '/wp-admin/admin-ajax.php',
        {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        }
      )
        .then((response) => response.json())
        .then((data) => {
          if (loadingDiv) loadingDiv.classList.remove('show');
          if (submitBtn) submitBtn.disabled = false;

          if (data.success) {
            form.reset();
            // Show success message
            if (errorDiv) {
              errorDiv.style.display = 'block';
              errorDiv.style.color = '#059669';
              errorDiv.textContent = 'Message sent successfully!';
            }

            // Close modal after delay
            setTimeout(() => {
              const modal = form.closest('.mld-modal');
              if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
              }
            }, 2000);
          } else if (errorDiv) {
            errorDiv.style.display = 'block';
            errorDiv.style.color = '#dc2626';
            errorDiv.textContent = data.data || 'An error occurred. Please try again.';
          }
        })
        .catch((error) => {
          SafeLogger.error('Contact form error:', error);
          if (loadingDiv) loadingDiv.classList.remove('show');
          if (submitBtn) submitBtn.disabled = false;
          if (errorDiv) {
            errorDiv.style.display = 'block';
            errorDiv.style.color = '#dc2626';
            errorDiv.textContent = 'Network error. Please try again.';
          }
        });
    }

    handleTourSubmit(e) {
      e.preventDefault();
      const form = e.target;
      const submitBtn = form.querySelector('[type="submit"]');
      const errorDiv = form.querySelector('.mld-form-error') || form.querySelector('.mld-form-status');
      const loadingDiv = form.querySelector('.mld-form-loading');

      // Clear previous errors
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }

      // Show loading
      if (submitBtn) submitBtn.disabled = true;
      if (loadingDiv) loadingDiv.classList.add('show');

      const formData = new FormData(form);
      formData.append('action', 'mld_schedule_tour');

      fetch(
        window.mldPropertyData?.ajaxUrl ||
          window.mldSettings?.ajax_url ||
          '/wp-admin/admin-ajax.php',
        {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        }
      )
        .then((response) => response.json())
        .then((data) => {
          if (loadingDiv) loadingDiv.classList.remove('show');
          if (submitBtn) submitBtn.disabled = false;

          if (data.success) {
            form.reset();
            // Show success message
            if (errorDiv) {
              errorDiv.style.display = 'block';
              errorDiv.style.color = '#059669';
              errorDiv.textContent = 'Tour request sent successfully!';
            }

            // Close modal after delay
            setTimeout(() => {
              const modal = form.closest('.mld-modal');
              if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
              }
            }, 2000);
          } else if (errorDiv) {
            errorDiv.style.display = 'block';
            errorDiv.style.color = '#dc2626';
            errorDiv.textContent = data.data || 'An error occurred. Please try again.';
          }
        })
        .catch((error) => {
          SafeLogger.error('Tour form error:', error);
          if (loadingDiv) loadingDiv.classList.remove('show');
          if (submitBtn) submitBtn.disabled = false;
          if (errorDiv) {
            errorDiv.style.display = 'block';
            errorDiv.style.color = '#dc2626';
            errorDiv.textContent = 'Network error. Please try again.';
          }
        });
    }
  }

  // Virtual Tour Handler
  class VirtualTourHandler {
    constructor() {
      this.buttons = document.querySelectorAll('.mld-virtual-tour-overlay');
      this.modal = document.getElementById('virtualTourModal');
      this.viewer = document.getElementById('tourViewer');
      this.title = document.getElementById('tourModalTitle');
      this.closeBtn = this.modal ? this.modal.querySelector('.mld-modal-close') : null;

      this.init();
    }

    init() {
      this.buttons.forEach((button) => {
        button.addEventListener('click', (e) => {
          const embedUrl = button.getAttribute('data-embed-url');
          const tourType = button.getAttribute('data-tour-type');
          this.openTour(embedUrl, tourType);
        });
      });

      if (this.closeBtn) {
        this.closeBtn.addEventListener('click', () => this.closeTour());
      }

      if (this.modal) {
        this.modal.addEventListener('click', (e) => {
          if (e.target === this.modal || e.target.classList.contains('mld-modal-backdrop')) {
            this.closeTour();
          }
        });
      }
    }

    openTour(embedUrl, tourType) {
      if (!this.modal || !embedUrl) return;

      // Analytics: Track video/tour play (v6.38.0)
      document.dispatchEvent(new CustomEvent('mld:video_play', {
        detail: {
          listingId: window.mldPropertyDataV3?.listing_id || null,
          videoType: tourType || 'virtual_tour'
        }
      }));

      const iframe = document.createElement('iframe');
      iframe.src = embedUrl;
      iframe.setAttribute('allowfullscreen', '');
      iframe.setAttribute('allow', 'xr-spatial-tracking');

      this.viewer.innerHTML = '';
      this.viewer.appendChild(iframe);

      const titles = {
        matterport: '3D Virtual Tour',
        youtube: 'Video Tour',
        vimeo: 'Video Tour',
        zillow: '3D Home Tour',
      };

      this.title.textContent = titles[tourType] || 'Virtual Tour';
      this.modal.style.display = 'block';
      document.body.style.overflow = 'hidden';
    }

    closeTour() {
      if (!this.modal) return;

      this.modal.style.display = 'none';
      this.viewer.innerHTML = '';
      document.body.style.overflow = '';
    }
  }

  // YouTube Video Handler Class
  class YouTubeVideoHandler {
    constructor() {
      this.modal = document.getElementById('youtubeVideoModal');
      this.modalClose = document.getElementById('youtubeModalClose');
      this.iframeContainer = document.getElementById('youtubeIframeContainer');
      this.videoPreview = document.querySelector('.mld-youtube-preview');
      this.currentIframe = null;

      if (this.videoPreview) {
        this.init();
      }
    }

    init() {
      // Handle click on video preview
      this.videoPreview.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.openModal();
      });

      // Handle close button
      if (this.modalClose) {
        this.modalClose.addEventListener('click', () => {
          this.closeModal();
        });
      }

      // Handle overlay click to close
      if (this.modal) {
        const overlay = this.modal.querySelector('.mld-youtube-modal-overlay');
        if (overlay) {
          overlay.addEventListener('click', () => {
            this.closeModal();
          });
        }
      }

      // Handle back button on mobile
      window.addEventListener('popstate', () => {
        if (this.modal && this.modal.style.display !== 'none') {
          this.closeModal();
        }
      });
    }

    openModal() {
      if (!this.modal || !this.videoPreview) return;

      const embedUrl = this.videoPreview.dataset.embedUrl;
      if (!embedUrl) return;

      // Create and insert iframe
      const iframe = document.createElement('iframe');
      iframe.src = embedUrl + '&autoplay=1&playsinline=1';
      iframe.allow =
        'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
      iframe.allowFullscreen = true;
      iframe.frameBorder = '0';

      // Clear container and add iframe
      this.iframeContainer.innerHTML = '';
      this.iframeContainer.appendChild(iframe);
      this.currentIframe = iframe;

      // Show modal with animation
      this.modal.style.display = 'flex';
      this.modal.offsetHeight; // Force reflow
      this.modal.classList.add('show');

      // Prevent body scroll
      document.body.style.overflow = 'hidden';

      // Add history state for back button
      history.pushState({ videoModal: true }, '');
    }

    closeModal() {
      if (!this.modal) return;

      // Remove animation class
      this.modal.classList.remove('show');

      // Hide modal after animation
      setTimeout(() => {
        this.modal.style.display = 'none';

        // Clear iframe to stop video
        if (this.currentIframe) {
          this.currentIframe.src = 'about:blank';
          this.currentIframe = null;
        }
        this.iframeContainer.innerHTML = '';

        // Restore body scroll
        document.body.style.overflow = '';
      }, 300);

      // Go back if we pushed a state
      if (window.history.state && window.history.state.videoModal) {
        history.back();
      }
    }
  }

  // Sticky Contact Bar Handler
  class StickyContactBar {
    constructor() {
      this.stickyBar = document.getElementById('stickyContactBar');
      // Try multiple selectors for the contact section
      this.contactSection = document.querySelector('.mld-cta-actions') ||
                           document.querySelector('.mld-schedule-tour') ||
                           document.querySelector('#overview');
      this.isVisible = false;

      if (!this.stickyBar) {
        return;
      }

      if (!this.contactSection) {
        // Use a default offset if contact section isn't found
        this.useDefaultOffset = true;
      }

      this.init();
    }

    init() {
      // Setup button click handlers
      const scheduleBtn = this.stickyBar.querySelector('.mld-sticky-schedule');
      const contactBtn = this.stickyBar.querySelector('.mld-sticky-contact');

      if (scheduleBtn) {
        scheduleBtn.addEventListener('click', () => {
          const tourModal = document.getElementById('tourModal');
          if (tourModal) {
            tourModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
          }
        });
      }

      if (contactBtn) {
        contactBtn.addEventListener('click', () => {
          const contactModal = document.getElementById('contactModal');
          if (contactModal) {
            contactModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
          }
        });
      }

      // Setup scroll handler
      this.setupScrollHandler();
    }

    setupScrollHandler() {
      let ticking = false;

      const checkScroll = () => {
        let shouldShow = false;

        if (this.useDefaultOffset) {
          // Show after scrolling 800px if contact section not found
          shouldShow = window.pageYOffset > 800;
        } else if (this.contactSection) {
          const rect = this.contactSection.getBoundingClientRect();
          // Show when contact section is above viewport
          shouldShow = rect.bottom < 0;
        }

        if (shouldShow !== this.isVisible) {
          this.isVisible = shouldShow;
          if (shouldShow) {
            this.show();
          } else {
            this.hide();
          }
        }

        ticking = false;
      };

      window.addEventListener('scroll', () => {
        if (!ticking) {
          requestAnimationFrame(checkScroll);
          ticking = true;
        }
      });

      // Add touch scroll support for mobile
      window.addEventListener('touchmove', () => {
        if (!ticking) {
          requestAnimationFrame(checkScroll);
          ticking = true;
        }
      }, { passive: true });

      // Initial check
      setTimeout(checkScroll, 100);
    }

    show() {
      this.stickyBar.style.display = 'block';
      // Force reflow before adding class
      this.stickyBar.offsetHeight;
      setTimeout(() => {
        this.stickyBar.classList.add('visible');
      }, 10);
    }

    hide() {
      this.stickyBar.classList.remove('visible');
      setTimeout(() => {
        if (!this.stickyBar.classList.contains('visible')) {
          this.stickyBar.style.display = 'none';
        }
      }, 300);
    }
  }

  // Initialize everything when DOM is ready
  function initializeApp() {
    try {
      SafeLogger.info('Initializing Mobile V3 App');

      // Debug: Check what scripts and elements are available
      SafeLogger.info('jQuery available:', typeof jQuery !== 'undefined');
      SafeLogger.info('MLDLogger available:', typeof MLDLogger !== 'undefined');
      SafeLogger.info('Admin section present:', document.getElementById('adminSection') !== null);
      SafeLogger.info('User agent:', navigator.userAgent);

      // Detect iOS for special handling
      const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
      const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

      // Check if we're navigating to a new property
      const currentPropertyId =
        window.mldPropertyData?.listingId || window.mldPropertyData?.mls_number;
      if (window.lastPropertyId && window.lastPropertyId !== currentPropertyId) {
        SafeLogger.info('New property detected, updating map instances');
      }
      window.lastPropertyId = currentPropertyId;
    } catch (error) {
      SafeLogger.error('Error in initial setup:', error);
    }

    // Initialize back button
    try {
      const backButton = document.getElementById('backButton');
      if (backButton) {
        backButton.addEventListener('click', function() {
          // Check if we can go back in history
          if (window.history.length > 1 && document.referrer && document.referrer !== '') {
            // Check if referrer is from the same domain
            try {
              const referrerUrl = new URL(document.referrer);
              const currentUrl = new URL(window.location.href);

              if (referrerUrl.hostname === currentUrl.hostname) {
                // Same domain, safe to go back
                window.history.back();
                return;
              }
            } catch (e) {
              // URL parsing failed, fall through to home navigation
            }
          }

          // No valid history or came from external site, go to home page
          window.location.href = window.location.origin + '/';
        });
      }
    } catch (error) {
      SafeLogger.error('Error initializing back button:', error);
    }

    // Initialize bottom sheet
    try {
      const bottomSheet = document.getElementById('bottomSheet');
      if (bottomSheet) {
        SafeLogger.info('Initializing bottom sheet');
        window.bottomSheetInstance = new SimpleBottomSheet(bottomSheet);


        // Verify initialization worked
        setTimeout(() => {
          const transform = window.getComputedStyle(bottomSheet).transform;
          const display = window.getComputedStyle(bottomSheet).display;
          SafeLogger.info('Bottom sheet after init - Display:', display, 'Transform:', transform);

          // If the sheet isn't visible or positioned correctly, fix it
          if (display === 'none' || transform === 'none' || transform.includes('translateY(100%)')) {
            SafeLogger.warning('Bottom sheet not visible, applying fix...');
            bottomSheet.style.display = 'flex';
            bottomSheet.style.transform = 'translateY(50%)';
            bottomSheet.style.visibility = 'visible';
          }
        }, 200);
      } else {
        SafeLogger.error('Bottom sheet element not found');
      }
    } catch (error) {
      SafeLogger.error('Error initializing bottom sheet:', error);
      // Fallback: try to make sheet visible directly
      const bottomSheet = document.getElementById('bottomSheet');
      if (bottomSheet) {
        bottomSheet.style.display = 'flex';
        bottomSheet.style.transform = 'translateY(50%)';
        bottomSheet.style.visibility = 'visible';
      }
    }

    // Initialize section management instead of tabs
    try {
      if (typeof SectionManager !== 'undefined') {
        window.sectionManager = new SectionManager();
        SafeLogger.debug('SectionManager initialized successfully');
      } else {
        SafeLogger.warning('SectionManager class not defined');
      }
    } catch (error) {
      SafeLogger.error('Error initializing section manager:', error);
    }

    // Initialize admin section toggle with error handling
    try {
      const adminToggleBtn = document.getElementById('adminToggleBtn');
      const adminSection = document.getElementById('adminSection');

      if (adminToggleBtn && adminSection) {
        SafeLogger.info('Admin section found, initializing toggle');

        // Function to toggle admin section
        const toggleAdminSection = (e) => {
          e.preventDefault();
          e.stopPropagation();

          const isMinimized = adminSection.classList.contains('minimized');

          if (isMinimized) {
            adminSection.classList.remove('minimized');
            localStorage.setItem('mld_admin_section_minimized', 'false');
            SafeLogger.info('Admin section expanded');
          } else {
            adminSection.classList.add('minimized');
            localStorage.setItem('mld_admin_section_minimized', 'true');
            SafeLogger.info('Admin section minimized');
          }
        };

        // Add click event listener
        adminToggleBtn.addEventListener('click', toggleAdminSection);

        // Check saved state on page load
        const isMinimized = localStorage.getItem('mld_admin_section_minimized') === 'true';
        if (isMinimized) {
          adminSection.classList.add('minimized');
        }

        SafeLogger.info('Admin section toggle initialized successfully');
      }
    } catch (error) {
      SafeLogger.error('Error initializing admin section:', error);
    }

    // Initialize gallery controls
    try {
      // v6.25.23: Check for both old and new gallery controls class names
      if ((document.querySelector('.mld-gallery-controls') || document.querySelector('.mld-gallery-controls-inline')) && typeof GalleryControls !== 'undefined') {
        window.galleryControlsInstance = new GalleryControls();
      }
    } catch (error) {
      SafeLogger.error('Error initializing gallery controls:', error);
    }

    // Initialize YouTube video handler
    try {
      if (document.querySelector('.mld-youtube-preview') && typeof YouTubeVideoHandler !== 'undefined') {
        window.youtubeHandlerInstance = new YouTubeVideoHandler();
      }
    } catch (error) {
      SafeLogger.error('Error initializing YouTube handler:', error);
    }

    // Initialize payment calculator
    try {
      if (document.getElementById('calcPrice') && typeof PaymentCalculator !== 'undefined') {
        new PaymentCalculator();
      }
    } catch (error) {
      SafeLogger.error('Error initializing payment calculator:', error);
    }

    // Initialize virtual tours
    try {
      if (document.querySelector('.mld-virtual-tour-overlay') && typeof VirtualTourHandler !== 'undefined') {
        new VirtualTourHandler();
      }
    } catch (error) {
      SafeLogger.error('Error initializing virtual tour handler:', error);
    }

    // Initialize modal handlers
    try {
      if (typeof ModalHandler !== 'undefined') {
        window.modalHandler = new ModalHandler();
        SafeLogger.debug('ModalHandler initialized successfully');
      } else {
        SafeLogger.warning('ModalHandler class not defined');
      }
    } catch (error) {
      SafeLogger.error('Error initializing modal handlers:', error);
    }

    // Initialize forms
    try {
      if (typeof FormHandler !== 'undefined') {
        window.formHandler = new FormHandler();
        SafeLogger.debug('FormHandler initialized successfully');
      } else {
        SafeLogger.warning('FormHandler class not defined');
      }
    } catch (error) {
      SafeLogger.error('Error initializing form handlers:', error);
    }

    // Initialize sticky contact bar
    try {
      if (typeof StickyContactBar !== 'undefined') {
        new StickyContactBar();
      }
    } catch (error) {
      SafeLogger.error('Error initializing sticky contact bar:', error);
    }

    // Initialize similar homes
    try {
      if (window.MLD_Similar_Homes && document.getElementById('v3-similar-homes-container')) {
        new MLD_Similar_Homes();
      }
    } catch (error) {
      SafeLogger.error('Error initializing similar homes:', error);
    }

    // Initialize Walk Score
    try {
      if (window.MLD_Walk_Score && document.getElementById('walk-score-container')) {
        new MLD_Walk_Score();
      }
    } catch (error) {
      SafeLogger.error('Error initializing Walk Score:', error);
    }

    // Load Google Maps API if needed
    if (window.bmeMapData && window.bmeMapData.mapProvider === 'google' && !window.google) {
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${window.bmeMapData.google_key}&libraries=marker,geometry`;
      script.async = true;
      script.defer = true;
      document.head.appendChild(script);
    }
  }

  // Try multiple initialization methods to ensure it runs
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApp);
  } else {
    initializeApp();
  }

  // Also use jQuery ready as fallback
  if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function ($) {
      try {
        if (!window.bottomSheetInstance) {
          initializeApp();
        }

        // Initialize MLS copy to clipboard
        initializeMLSCopyToClipboard();
      } catch (error) {
        SafeLogger.error('Error in jQuery ready handler:', error);
      }
    });
  } else {
    // Fallback if jQuery is not available
    SafeLogger.warning('jQuery not available, using native initialization');
    if (document.readyState === 'complete') {
      initializeApp();
      initializeMLSCopyToClipboard();
    }
  }

  /**
   * Initialize MLS Number Copy to Clipboard for Mobile
   */
  function initializeMLSCopyToClipboard() {
    const mlsElement = document.querySelector('.mld-v3-mls-number-mobile');

    if (!mlsElement) return;

    mlsElement.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();

      const mlsNumber = this.dataset.mls;

      // Copy to clipboard using modern API with fallback
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(mlsNumber).then(() => {
          showCopyFeedback(this);
        }).catch(() => {
          fallbackCopyToClipboard(mlsNumber, this);
        });
      } else {
        fallbackCopyToClipboard(mlsNumber, this);
      }
    });
  }

  /**
   * Fallback copy method for older browsers
   */
  function fallbackCopyToClipboard(text, element) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2em';
    textArea.style.height = '2em';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand('copy');
      showCopyFeedback(element);
    } catch (err) {
      // ERROR: commented
    }

    document.body.removeChild(textArea);
  }

  /**
   * Show copy feedback
   */
  function showCopyFeedback(element) {
    element.classList.add('copied');

    // Provide haptic feedback on mobile if available
    if (window.navigator && window.navigator.vibrate) {
      window.navigator.vibrate(50);
    }

    setTimeout(() => {
      element.classList.remove('copied');
    }, 2000);
  }

  /**
   * Initialize Save & Share Buttons (v5.6.0)
   */
  function initializeSaveShareButtons() {
    const saveBtn = document.querySelector('.mld-v3-save-btn-mobile');
    const shareBtn = document.querySelector('.mld-v3-share-btn-mobile');

    // Save button functionality
    if (saveBtn) {
      saveBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Disable button during request
        saveBtn.disabled = true;

        try {
          const formData = new FormData();
          formData.append('action', 'mld_save_property');
          formData.append('nonce', window.mldPropertyData?.nonce || '');
          formData.append('mls_number', window.mldPropertyDataV3?.mlsNumber || saveBtn.dataset.mls || '');
          formData.append('action_type', 'toggle');

          const response = await fetch(
            window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php',
            {
              method: 'POST',
              body: formData,
            }
          );

          const result = await response.json();

          if (result.success) {
            const isSaved = result.data.is_saved;

            if (isSaved) {
              saveBtn.classList.add('saved');
              saveBtn.querySelector('span').textContent = 'Saved';
            } else {
              saveBtn.classList.remove('saved');
              saveBtn.querySelector('span').textContent = 'Save';
            }

            // Haptic feedback
            if (window.navigator && window.navigator.vibrate) {
              window.navigator.vibrate(50);
            }
          } else {
            // Fallback to localStorage if AJAX fails
            handleLocalStorageSave(saveBtn);
          }
        } catch (error) {
          SafeLogger.error('Error saving property:', error);
          // Fallback to localStorage on error
          handleLocalStorageSave(saveBtn);
        } finally {
          saveBtn.disabled = false;
        }
      });

      // Check if already saved on page load
      checkSavedStatus(saveBtn);
    }

    // Share button functionality
    if (shareBtn) {
      shareBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Analytics: Track share click (v6.38.0)
        const shareMethod = navigator.share ? 'native' : 'clipboard';
        document.dispatchEvent(new CustomEvent('mld:share_click', {
          detail: {
            listingId: window.mldPropertyDataV3?.listing_id || null,
            shareMethod: shareMethod
          }
        }));

        const propertyData = {
          title: window.mldPropertyDataV3?.address || 'Property Listing',
          text: `Check out this property: ${window.mldPropertyDataV3?.address || ''}`,
          url: window.location.href,
        };

        // Use native share if available (excellent for mobile!)
        if (navigator.share) {
          try {
            await navigator.share(propertyData);
            // Haptic feedback on successful share
            if (window.navigator && window.navigator.vibrate) {
              window.navigator.vibrate(50);
            }
          } catch (err) {
            // User cancelled share or error occurred
            if (err.name !== 'AbortError') {
              SafeLogger.error('Error sharing:', err);
            }
          }
        } else {
          // Fallback - copy to clipboard
          try {
            if (navigator.clipboard && window.isSecureContext) {
              await navigator.clipboard.writeText(window.location.href);
            } else {
              // Old browser fallback
              fallbackCopyToClipboard(window.location.href, shareBtn);
            }

            // Show feedback
            const originalText = shareBtn.querySelector('span').textContent;
            shareBtn.querySelector('span').textContent = 'Link Copied!';

            // Haptic feedback
            if (window.navigator && window.navigator.vibrate) {
              window.navigator.vibrate(50);
            }

            setTimeout(() => {
              shareBtn.querySelector('span').textContent = originalText;
            }, 2000);
          } catch (err) {
            SafeLogger.error('Error copying link:', err);
          }
        }
      });
    }
  }

  /**
   * Handle localStorage save fallback
   */
  function handleLocalStorageSave(saveBtn) {
    try {
      const mlsNumber = window.mldPropertyDataV3?.mlsNumber || saveBtn.dataset.mls;
      const savedProperties = JSON.parse(localStorage.getItem('mld_saved_properties') || '[]');
      const index = savedProperties.indexOf(mlsNumber);

      if (index > -1) {
        // Already saved, remove it
        savedProperties.splice(index, 1);
        saveBtn.classList.remove('saved');
        saveBtn.querySelector('span').textContent = 'Save';
      } else {
        // Not saved, add it
        savedProperties.push(mlsNumber);
        saveBtn.classList.add('saved');
        saveBtn.querySelector('span').textContent = 'Saved';
      }

      localStorage.setItem('mld_saved_properties', JSON.stringify(savedProperties));

      // Haptic feedback
      if (window.navigator && window.navigator.vibrate) {
        window.navigator.vibrate(50);
      }
    } catch (err) {
      SafeLogger.error('Error with localStorage save:', err);
    }
  }

  /**
   * Check if property is already saved
   */
  function checkSavedStatus(saveBtn) {
    try {
      const mlsNumber = window.mldPropertyDataV3?.mlsNumber || saveBtn.dataset.mls;
      const savedProperties = JSON.parse(localStorage.getItem('mld_saved_properties') || '[]');

      if (savedProperties.includes(mlsNumber)) {
        saveBtn.classList.add('saved');
        saveBtn.querySelector('span').textContent = 'Saved';
      }
    } catch (err) {
      SafeLogger.error('Error checking saved status:', err);
    }
  }

  // Initialize Save & Share buttons when ready
  if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function() {
      initializeSaveShareButtons();
    });
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSaveShareButtons);
  } else {
    initializeSaveShareButtons();
  }

  // v6.26.0: Global share location function for info window button
  window.mldShareLocation = async function() {
    const address = window.mldPropertyData?.address || 'Property Location';
    const lat = window.mldPropertyData?.coordinates?.lat;
    const lng = window.mldPropertyData?.coordinates?.lng;
    const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

    if (navigator.share) {
      try {
        await navigator.share({
          title: address,
          text: `Location: ${address}`,
          url: mapsUrl
        });
      } catch (err) {
        if (err.name !== 'AbortError') {
          console.error('[MLD] Share error:', err);
        }
      }
    } else {
      try {
        await navigator.clipboard.writeText(mapsUrl);
        // Show toast notification
        const toast = document.createElement('div');
        toast.className = 'mld-fab-toast';
        toast.textContent = 'Location copied!';
        document.body.appendChild(toast);
        setTimeout(() => {
          toast.style.animation = 'mld-fadeOut 0.3s ease forwards';
          setTimeout(() => toast.remove(), 300);
        }, 2000);
      } catch (err) {
        console.error('[MLD] Copy error:', err);
      }
    }
  };

})(typeof jQuery !== 'undefined' ? jQuery : (function() {
  // WARN: commented
  return {
    ready: function(fn) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
      } else {
        fn();
      }
    },
    fn: {}
  };
})());
