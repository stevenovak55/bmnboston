/**
 * Map Controls Panel Handler
 * Manages the unified control panel for Nearby, Draw, and School toggles
 */
(function($) {
    'use strict';

    const MapControlsPanel = {
        init() {
            this.bindEvents();
            this.restoreCollapsedState();
            this.updateSchoolsToggle();
        },

        bindEvents() {
            // Collapse/expand handler
            $(document).on('click', '.bme-controls-header', (e) => {
                e.preventDefault();
                this.togglePanel();
            });

            // Don't handle Draw toggle here - let map-core.js handle it
            // The draw functionality is managed by map-core.js

            // Prevent propagation on control items, but not on interactive elements
            $(document).on('click', '.bme-control-item', (e) => {
                // Don't stop propagation if clicking on toggles or inputs
                if (!$(e.target).closest('#bme-schools-toggle, #bme-draw-toggle, #bme-nearby-toggle, input').length) {
                    e.stopPropagation();
                }
            });
        },

        togglePanel() {
            const $panel = $('.bme-map-controls-panel');
            const isCollapsed = $panel.hasClass('collapsed');

            if (isCollapsed) {
                $panel.removeClass('collapsed');
                localStorage.setItem('mld_controls_collapsed', 'false');
            } else {
                $panel.addClass('collapsed');
                localStorage.setItem('mld_controls_collapsed', 'true');

                // Hide schools submenu when collapsing the panel
                $('#bme-schools-types-container').hide();
            }
        },

        restoreCollapsedState() {
            const isCollapsed = localStorage.getItem('mld_controls_collapsed') === 'true';

            // Default to expanded on first load
            if (isCollapsed) {
                $('.bme-map-controls-panel').addClass('collapsed');
            }
        },

        updateSchoolsToggle() {
            // Wait for schools module to initialize
            const checkInterval = setInterval(() => {
                if (window.MLD_Schools && window.MLD_Schools.initialized) {
                    clearInterval(checkInterval);
                    this.integrateSchoolsToggle();
                }
            }, 100);

            // Stop checking after 5 seconds
            setTimeout(() => clearInterval(checkInterval), 5000);
        },

        integrateSchoolsToggle() {
            // Don't create a schools toggle here - map-schools.js handles it
            // Just remove the placeholder since map-schools.js adds its own toggle
            const placeholder = document.getElementById('bme-schools-control-placeholder');
            if (placeholder) {
                // Create a simple schools control that will work with map-schools.js
                const schoolsControl = document.createElement('div');
                schoolsControl.className = 'bme-control-item';
                schoolsControl.id = 'bme-schools-control-wrapper';
                schoolsControl.innerHTML = `
                    <div class="bme-control-label">
                        <span class="bme-control-icon">🏫</span>
                        <span>Schools</span>
                    </div>
                    <div id="bme-schools-toggle" class="bme-toggle-custom">
                        <div class="bme-toggle-custom-slider"></div>
                    </div>
                `;
                placeholder.replaceWith(schoolsControl);

                // Trigger schools module initialization if it exists
                if (window.MLD_Schools && typeof window.MLD_Schools.init === 'function') {
                    // Re-attach the event listeners since we just created the toggle
                    window.MLD_Schools.attachEventListeners();
                }
            }
        }
    };

    // Initialize when DOM is ready
    $(document).ready(() => {
        MapControlsPanel.init();
    });

    // Make available globally for debugging
    window.MapControlsPanel = MapControlsPanel;

})(jQuery);