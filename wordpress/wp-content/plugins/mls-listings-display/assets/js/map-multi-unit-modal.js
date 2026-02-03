/**
 * MLD Multi-Unit Modal
 * Handles display of multiple properties at the same location in a responsive modal
 *
 * @param $
 * @version 1.0.0
 */

(function ($) {
  'use strict';

  window.MLD_MultiUnitModal = {
    // Modal state
    isOpen: false,
    currentProperties: [],
    modalElement: null,

    /**
     * Initialize the modal
     */
    init() {
      this.createModalHTML();
      this.bindEvents();
    },

    /**
     * Create modal HTML structure
     */
    createModalHTML() {
      const modalHTML = `
                <div id="mld-multi-unit-modal" class="mld-modal-overlay" style="display: none;">
                    <div class="mld-modal-container">
                        <div class="mld-modal-header">
                            <h2 class="mld-modal-title">
                                <span class="mld-modal-count">0</span> Properties Available at <span class="mld-modal-address"></span>
                            </h2>
                            <button class="mld-modal-close" aria-label="Close modal">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </button>
                        </div>
                        <div class="mld-modal-body">
                            <div class="mld-property-grid" role="list">
                                <!-- Property cards will be inserted here -->
                            </div>
                        </div>
                    </div>
                </div>
            `;

      $('body').append(modalHTML);
      this.modalElement = $('#mld-multi-unit-modal');
    },

    /**
     * Bind modal events
     */
    bindEvents() {
      const self = this;

      // Close button
      $(document).on('click', '.mld-modal-close', function () {
        self.close();
      });

      // Overlay click
      $(document).on('click', '#mld-multi-unit-modal', function (e) {
        if (e.target === this) {
          self.close();
        }
      });

      // ESC key
      $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && self.isOpen) {
          self.close();
        }
      });

      // Touch gestures disabled per user request
      // if (this.isMobile()) {
      //     this.initTouchGestures();
      // }
    },

    /**
     * Initialize touch gestures for mobile
     */
    initTouchGestures() {
      let startY = 0;
      let currentY = 0;
      let modalContainer = null;

      $(document).on('touchstart', '.mld-modal-container', function (e) {
        startY = e.touches[0].clientY;
        modalContainer = $(this);
      });

      $(document).on('touchmove', '.mld-modal-container', function (e) {
        if (!modalContainer) return;

        currentY = e.touches[0].clientY;
        const deltaY = currentY - startY;

        // Only allow downward swipe
        if (deltaY > 0 && $(e.target).closest('.mld-modal-body').scrollTop() === 0) {
          modalContainer.css('transform', `translateY(${deltaY}px)`);
        }
      });

      $(document).on('touchend', '.mld-modal-container', function () {
        if (!modalContainer) return;

        const deltaY = currentY - startY;

        // Close if swiped down more than 100px
        if (deltaY > 100) {
          MLD_MultiUnitModal.close();
        } else {
          modalContainer.css('transform', '');
        }

        modalContainer = null;
      });
    },

    /**
     * Open modal with properties
     * @param {Array} properties - Array of property objects
     */
    open(properties) {
      if (!properties || properties.length === 0) return;

      this.currentProperties = properties;
      this.isOpen = true;

      // Update count and address
      $('.mld-modal-count').text(properties.length);

      // Get address from first property (same logic as marker)
      const firstListing = properties[0];
      const streetNumber = firstListing.StreetNumber || '';
      const streetName = firstListing.StreetName || '';
      const address = `${streetNumber} ${streetName}`.trim();
      $('.mld-modal-address').text(address);

      // Clear and populate property grid
      const grid = $('.mld-property-grid');
      grid.empty();

      // Add property cards using existing card HTML generator
      properties.forEach((property) => {
        // Use the existing MLD_Core.createCardHTML function
        const cardHTML = MLD_Core.createCardHTML(property, 'sidebar');
        grid.append(cardHTML);
      });

      // Re-bind any card event handlers if needed (photo viewer, etc.)
      // The existing handlers should work since they use event delegation

      // Show modal
      this.modalElement.fadeIn(300);
      $('body').addClass('mld-modal-open');

      // Focus management
      $('.mld-modal-close').focus();

      // Track event
      if (typeof gtag !== 'undefined') {
        gtag('event', 'multi_unit_modal_open', {
          property_count: properties.length,
        });
      }
    },

    /**
     * Close modal
     */
    close() {
      this.isOpen = false;
      this.modalElement.fadeOut(300);
      $('body').removeClass('mld-modal-open');

      // Clear properties
      this.currentProperties = [];
      $('.mld-property-grid').empty();
    },

    /**
     * Check if mobile device
     */
    isMobile() {
      return window.innerWidth <= 768;
    },
  };

  // Initialize when DOM is ready
  $(document).ready(function () {
    MLD_MultiUnitModal.init();
  });
})(jQuery);
