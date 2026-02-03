/**
 * Enhanced Comparable Sales JavaScript
 *
 * Handles filter interactions and AJAX calls for comparable sales
 *
 * @package    MLS_Listings_Display
 * @since      5.3.0
 */

(function ($) {
  'use strict';

  // Production: All debug logging disabled
  const cmaLog = () => {};

  class MLDComparableSales {
    constructor() {
      cmaLog('[MLD Comparable Sales] Constructor called');
      this.subjectProperty = window.mldSubjectProperty || {};
      cmaLog('[MLD Comparable Sales] Subject property:', this.subjectProperty);
      this.resultsContainer = $('#mld-comp-results');
      this.filterForm = $('#mld-comp-filter-form');
      this.toolbar = $('#mld-comp-toolbar');
      this.isLoading = false;
      this.currentComparables = [];
      this.selectedComparables = [];
      this.activeQuickFilters = [];
      this.currentSort = 'similarity';

      // UX enhancements
      this.map = null;
      this.mapMarkers = [];
      this.mapVisible = false;
      this.favorites = this.loadFavoritesFromSession();
      this.currentPage = 1;
      this.itemsPerPage = 20;
      this.filteredComparables = [];

      // ARV (After Repair Value) properties
      this.isARVMode = false;
      this.originalSubjectProperty = null;
      this.arvOverrides = null;

      // Save/Load session properties
      this.currentSessionId = null;
      this.currentSummary = null;

      // Weight override storage for session restoration (v6.19.0)
      this.savedWeightOverrides = {};

      // Selection storage for session restoration (v6.20.1)
      this.savedSelectedComparables = [];

      // Re-run CMA tracking (v6.20.1)
      this.loadedSessionData = null;  // Stores the full loaded session for re-run
      this.previousValueEstimate = null;  // Stores previous value for comparison

      // Standalone CMA tracking (v6.20.2)
      this.isStandaloneCMA = false;
      this.standaloneSessionId = null;
      this.showRerunAfterLoad = false;

      this.init();
    }

    init() {
      cmaLog('[MLD Comparable Sales] init() called');
      // Setup event listeners
      this.setupFilterToggle();
      this.setupRangeSliders();
      this.setupFormHandlers();
      this.setupQuickFilters();
      this.setupSortButtons();
      this.setupCompareButton();

      // Setup UX enhancements
      this.setupMapToggle();
      this.setupShareButton();
      this.setupPagination();

      // Setup ARV and Save/Load features
      this.setupARVModal();
      this.setupSaveLoadCMA();

      // Check for preloaded session data (standalone CMA pages) - v6.20.2
      if (window.mldPreloadedSession && window.mldPreloadedSession.id) {
        cmaLog('[MLD Comparable Sales] Found preloaded session:', window.mldPreloadedSession.id);
        // Restore the preloaded session after a short delay to ensure page is ready
        setTimeout(() => {
          this.restorePreloadedSession(window.mldPreloadedSession);
        }, 300);
        return;
      }

      // Check for load_cma URL parameter (for cross-property CMA loading)
      const urlParams = new URLSearchParams(window.location.search);
      const loadCmaId = urlParams.get('load_cma');

      if (loadCmaId) {
        // Clean the URL by removing the load_cma parameter
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('load_cma');
        window.history.replaceState({}, '', cleanUrl.toString());

        // Load the CMA session after a short delay to ensure page is ready
        setTimeout(() => {
          this.loadCMASessionDirect(loadCmaId);
        }, 500);
      } else {
        // Load initial results with defaults
        this.loadComparables();
      }
    }

    /**
     * Load a CMA session directly without redirect check
     * Used when redirected from another property page
     */
    loadCMASessionDirect(sessionId) {
      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_load_cma_session',
          nonce: mldAjax.nonce,
          session_id: sessionId
        },
        success: (response) => {
          if (response.success && response.data.session) {
            this.restoreCMASession(response.data.session);

            // Scroll to the CMA section after loading
            setTimeout(() => {
              const cmaSection = document.getElementById('mld-comparable-sales');
              if (cmaSection) {
                cmaSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }
            }, 300);
          } else {
            alert('Error loading CMA: ' + (response.data.message || 'Session not found'));
            // Load default comparables on error
            this.loadComparables();
          }
        },
        error: () => {
          alert('Failed to load CMA session. Loading default comparables.');
          this.loadComparables();
        }
      });
    }

    /**
     * Restore a preloaded session (for standalone CMA pages)
     * This is called when window.mldPreloadedSession is set by the PHP template
     * @since 6.20.2
     */
    restorePreloadedSession(preloadedSession) {
      cmaLog('[MLD Comparable Sales] Restoring preloaded session:', preloadedSession);

      // Mark this as a standalone CMA
      this.isStandaloneCMA = true;
      this.standaloneSessionId = preloadedSession.id;

      // Restore filters if available
      if (preloadedSession.cma_filters) {
        this.restoreFilters(preloadedSession.cma_filters);
      }

      // Restore subject overrides if available
      if (preloadedSession.subject_overrides) {
        this.currentOverrides = preloadedSession.subject_overrides;
      }

      // Store session data for Re-run CMA feature (always for standalone CMAs)
      this.loadedSessionData = {
        ...preloadedSession,
        session_name: preloadedSession.session_name || 'Standalone CMA',
        estimated_value_mid: preloadedSession.estimated_value_mid,
        summary_statistics: preloadedSession.summary_statistics
      };
      this.previousValueEstimate = preloadedSession.estimated_value_mid ||
        (preloadedSession.summary_statistics?.estimated_value?.mid) || null;

      // Check if we have saved comparables data
      if (preloadedSession.comparables_data && preloadedSession.comparables_data.length > 0) {
        // Use the saved comparables directly
        this.currentComparables = preloadedSession.comparables_data;
        this.currentSummary = preloadedSession.summary_statistics || {};

        // Restore selected comparables from saved data
        this.selectedComparables = [];
        this.savedSelectedComparables = [];
        preloadedSession.comparables_data.forEach(comp => {
          // If comparable has selected=true, add to saved selections
          if (comp.selected === true) {
            this.savedSelectedComparables.push(String(comp.listing_id));
          }
        });

        // Show banner indicating session was restored
        this.showSessionRestoredBanner(preloadedSession.session_name || 'Saved CMA');

        // Render the results
        this.renderResults(this.currentComparables, this.currentSummary);

        // Show Re-run button since we loaded a session with saved data
        this.showRerunButton();
      } else {
        // No saved comparables - load fresh with the saved filters
        cmaLog('[MLD Comparable Sales] No saved comparables, loading fresh...');
        // For standalone CMAs, show Re-run button after initial load completes
        this.showRerunAfterLoad = true;
        this.loadComparables();
      }
    }

    /**
     * Show banner indicating session was restored
     * @since 6.20.2
     */
    showSessionRestoredBanner(sessionName) {
      const bannerHtml = `
        <div class="mld-session-restored-banner" style="
          background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
          border: 1px solid #64b5f6;
          border-radius: 8px;
          padding: 12px 16px;
          margin-bottom: 16px;
          display: flex;
          align-items: center;
          gap: 12px;
          animation: fadeIn 0.5s ease-out;
        ">
          <span style="font-size: 1.5rem;">📊</span>
          <div>
            <strong style="color: #1565c0;">Saved CMA Loaded</strong>
            <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #1976d2;">
              Viewing saved analysis: ${sessionName}
            </p>
          </div>
        </div>
      `;

      // Insert before filter panel
      const filterPanel = $('#mld-comp-filters');
      if (filterPanel.length && !$('.mld-session-restored-banner').length) {
        filterPanel.before(bannerHtml);
      }
    }

    setupFilterToggle() {
      const toggleBtn = $('.mld-comp-filter-toggle');
      const filterContent = $('.mld-comp-filter-content');

      toggleBtn.on('click', (e) => {
        e.preventDefault();
        const isExpanded = toggleBtn.attr('aria-expanded') === 'true';

        if (isExpanded) {
          filterContent.slideUp(300);
          toggleBtn.attr('aria-expanded', 'false');
          toggleBtn.find('.filter-label').text('Show Filters');
        } else {
          filterContent.slideDown(300);
          toggleBtn.attr('aria-expanded', 'true');
          toggleBtn.find('.filter-label').text('Hide Filters');
        }
      });
    }

    setupRangeSliders() {
      // Radius slider - show "25+" when at max (no limit)
      $('#comp-radius').on('input', function () {
        var val = parseFloat($(this).val());
        $('#radius-value').text(val >= 25 ? '25+' : val);
      });

      // Price range slider - show "50+" when at max (no limit)
      $('#comp-price-range').on('input', function () {
        var val = parseInt($(this).val());
        $('#price-value').text(val >= 50 ? '50+' : val);
      });

      // Sqft range slider - show "50+" when at max (no limit)
      $('#comp-sqft-range').on('input', function () {
        var val = parseInt($(this).val());
        $('#sqft-value').text(val >= 50 ? '50+' : val);
      });

      // Year built range slider - show "30+" when at max (no limit)
      $('#comp-year-range').on('input', function () {
        var val = parseInt($(this).val());
        $('#year-value').text(val >= 30 ? '30+' : val);
      });
    }

    setupFormHandlers() {
      // Form submission
      this.filterForm.on('submit', (e) => {
        e.preventDefault();
        this.loadComparables();
      });

      // Form reset
      this.filterForm.on('reset', (e) => {
        setTimeout(() => {
          // Update slider displays after reset
          $('#radius-value').text('3');
          $('#price-value').text('15');
          $('#sqft-value').text('20');
          $('#year-value').text('10');

          // Reload with defaults
          this.loadComparables();
        }, 100);
      });
    }

    setupQuickFilters() {
      $('.mld-quick-filter').on('click', (e) => {
        const $btn = $(e.currentTarget);
        const filter = $btn.data('filter');

        if (filter === 'reset') {
          // Clear all quick filters
          this.activeQuickFilters = [];
          $('.mld-quick-filter').removeClass('active');
          this.applyClientSideFilters();
        } else {
          // Toggle filter
          const index = this.activeQuickFilters.indexOf(filter);
          if (index > -1) {
            this.activeQuickFilters.splice(index, 1);
            $btn.removeClass('active');
          } else {
            this.activeQuickFilters.push(filter);
            $btn.addClass('active');
          }
          this.applyClientSideFilters();
        }
      });
    }

    setupSortButtons() {
      $('.mld-sort-btn').on('click', (e) => {
        const $btn = $(e.currentTarget);
        const sort = $btn.data('sort');

        $('.mld-sort-btn').removeClass('active');
        $btn.addClass('active');

        this.currentSort = sort;
        this.applySorting();
      });
    }

    setupCompareButton() {
      // Hide the compare button since we're using checkboxes for CMA calculations now
      $('#mld-compare-btn').hide();
    }

    applyClientSideFilters() {
      let filtered = [...this.currentComparables];

      // Apply each active quick filter
      this.activeQuickFilters.forEach(filter => {
        switch(filter) {
          case 'grade-a':
            filtered = filtered.filter(c => c.comparability_grade === 'A');
            break;
          case 'nearby':
            filtered = filtered.filter(c => c.distance_miles <= 1);
            break;
          case 'recent':
            filtered = filtered.filter(c => {
              if (c.close_date) {
                const months = (Date.now() - new Date(c.close_date)) / (1000 * 60 * 60 * 24 * 30);
                return months <= 3;
              }
              return false;
            });
            break;
          case 'pool':
            filtered = filtered.filter(c => c.pool_private_yn == 1);
            break;
        }
      });

      // Re-render with filtered results
      this.renderFilteredResults(filtered);
    }

    applySorting() {
      let sorted = [...this.currentComparables];

      // Apply active filters first
      if (this.activeQuickFilters.length > 0) {
        sorted = this.applyFiltersToArray(sorted);
      }

      // Then sort
      switch(this.currentSort) {
        case 'similarity':
          sorted.sort((a, b) => b.comparability_score - a.comparability_score);
          break;
        case 'price_asc':
          sorted.sort((a, b) => (a.close_price || a.list_price) - (b.close_price || b.list_price));
          break;
        case 'price_desc':
          sorted.sort((a, b) => (b.close_price || b.list_price) - (a.close_price || a.list_price));
          break;
        case 'distance':
          sorted.sort((a, b) => a.distance_miles - b.distance_miles);
          break;
        case 'date_desc':
          sorted.sort((a, b) => {
            const dateA = a.close_date ? new Date(a.close_date) : new Date(0);
            const dateB = b.close_date ? new Date(b.close_date) : new Date(0);
            return dateB - dateA;
          });
          break;
      }

      this.renderFilteredResults(sorted);
    }

    applyFiltersToArray(comps) {
      let filtered = [...comps];
      this.activeQuickFilters.forEach(filter => {
        switch(filter) {
          case 'grade-a':
            filtered = filtered.filter(c => c.comparability_grade === 'A');
            break;
          case 'nearby':
            filtered = filtered.filter(c => c.distance_miles <= 1);
            break;
          case 'recent':
            filtered = filtered.filter(c => {
              if (c.close_date) {
                const months = (Date.now() - new Date(c.close_date)) / (1000 * 60 * 60 * 24 * 30);
                return months <= 3;
              }
              return false;
            });
            break;
          case 'pool':
            filtered = filtered.filter(c => c.pool_private_yn == 1);
            break;
        }
      });
      return filtered;
    }

    renderFilteredResults(comparables) {
      this.filteredComparables = comparables;
      this.currentPage = 1;

      const startIdx = (this.currentPage - 1) * this.itemsPerPage;
      const endIdx = startIdx + this.itemsPerPage;
      const pageComps = comparables.slice(startIdx, endIdx);

      const resultsHtml = '<div class="mld-comp-grid">' +
        pageComps.map(comp => this.renderComparable(comp)).join('') +
        '</div>';

      // Find the grid container and update it
      const $grid = $('.mld-comp-grid');
      if ($grid.length) {
        $grid.html(pageComps.map(comp => this.renderComparable(comp)).join(''));
      } else {
        // If grid doesn't exist, replace entire results container except summary
        const $summary = $('.mld-comp-summary');
        const summaryHtml = $summary.length ? $summary.prop('outerHTML') : '';
        this.resultsContainer.html(summaryHtml + resultsHtml);
      }

      this.updatePagination();
      this.setupAdjustmentToggles();
      this.setupCompareCheckboxes();
      this.setupFavoriteButtons();

      // Update map if visible
      if (this.mapVisible) {
        this.renderMap();
      }
    }

    setupCompareCheckboxes() {
      cmaLog('=== setupCompareCheckboxes called ===');

      // Use event delegation so it works for dynamically added elements
      $(document).off('change', '.mld-comp-compare-checkbox').on('change', '.mld-comp-compare-checkbox', (e) => {
        cmaLog('🔔 CHECKBOX CLICKED!');
        cmaLog('Event:', e);

        const $checkbox = $(e.currentTarget);
        // IMPORTANT: Convert to string to match the type in selectedComparables array
        const listingId = String($checkbox.data('listing-id'));
        const $card = $checkbox.closest('.mld-comp-card');

        cmaLog('Checkbox listing ID:', listingId, '(type:', typeof listingId + ')');
        cmaLog('Is checked:', $checkbox.is(':checked'));

        if ($checkbox.is(':checked')) {
          // Add to selected if not already there
          if (!this.selectedComparables.includes(listingId)) {
            this.selectedComparables.push(listingId);
            cmaLog('Added to selection');
          }
          $card.addClass('selected');
          $checkbox.parent().addClass('checked');
        } else {
          const index = this.selectedComparables.indexOf(listingId);
          if (index > -1) {
            this.selectedComparables.splice(index, 1);
            cmaLog('Removed from selection');
          }
          $card.removeClass('selected');
          $checkbox.parent().removeClass('checked');
        }

        // Recalculate CMA statistics based on selected properties
        cmaLog('About to recalculate. Selected properties:', this.selectedComparables);
        this.recalculateCMAStatistics();
      });

      cmaLog('Event handler attached. Current checkboxes:', $('.mld-comp-compare-checkbox').length);
    }

    setupPropertyCharacteristics() {
      // Handle road type changes
      $(document).off('change', '.mld-road-type-input').on('change', '.mld-road-type-input', (e) => {
        const $input = $(e.currentTarget);
        const listingId = String($input.data('listing-id'));
        const roadType = $input.val();

        cmaLog('Road type changed:', listingId, roadType);

        // Save to database
        this.savePropertyCharacteristic(listingId, 'road_type', roadType);
      });

      // Handle condition changes
      $(document).off('change', '.mld-condition-input').on('change', '.mld-condition-input', (e) => {
        const $input = $(e.currentTarget);
        const listingId = String($input.data('listing-id'));
        const condition = $input.val();

        cmaLog('Condition changed:', listingId, condition);

        // Save to database
        this.savePropertyCharacteristic(listingId, 'property_condition', condition);
      });

      cmaLog('Property characteristics handlers attached');
    }

    /**
     * Setup weight controls for weighted averaging (v6.19.0)
     */
    setupWeightControls() {
      // Handle weight button clicks
      $(document).off('click', '.mld-weight-btn').on('click', '.mld-weight-btn', (e) => {
        e.preventDefault();
        const $btn = $(e.currentTarget);
        const $container = $btn.closest('.mld-weight-buttons');
        const listingId = String($container.data('listing-id'));
        const newWeight = parseFloat($btn.data('weight'));
        const defaultWeight = parseFloat($container.data('default-weight'));

        cmaLog('Weight button clicked:', listingId, 'weight:', newWeight);

        // Update UI
        $container.find('.mld-weight-btn').removeClass('active');
        $btn.addClass('active');
        $container.data('current-weight', newWeight);

        // Update display text
        const $valueSpan = $(`.mld-weight-value[data-listing-id="${listingId}"]`);
        if (newWeight === defaultWeight) {
          $valueSpan.text(newWeight + 'x');
        } else {
          $valueSpan.text(newWeight + 'x (manual)');
        }

        // Enable/disable reset button
        const $resetBtn = $container.find('.mld-weight-reset-btn');
        $resetBtn.prop('disabled', newWeight === defaultWeight);

        // Update comparable in memory
        this.updateComparableWeight(listingId, newWeight === defaultWeight ? null : newWeight);
      });

      // Handle reset button clicks
      $(document).off('click', '.mld-weight-reset-btn').on('click', '.mld-weight-reset-btn', (e) => {
        e.preventDefault();
        const $btn = $(e.currentTarget);
        const listingId = String($btn.data('listing-id'));
        const $container = $btn.closest('.mld-weight-buttons');
        const defaultWeight = parseFloat($container.data('default-weight'));

        cmaLog('Weight reset clicked:', listingId, 'resetting to:', defaultWeight);

        // Update UI
        $container.find('.mld-weight-btn').removeClass('active');
        $container.find(`.mld-weight-btn[data-weight="${defaultWeight}"]`).addClass('active');
        $container.data('current-weight', defaultWeight);

        // Update display text
        const $valueSpan = $(`.mld-weight-value[data-listing-id="${listingId}"]`);
        $valueSpan.text(defaultWeight + 'x');

        // Disable reset button
        $btn.prop('disabled', true);

        // Reset weight override in memory
        this.updateComparableWeight(listingId, null);
      });

      cmaLog('Weight controls handlers attached');
    }

    /**
     * Update comparable weight and recalculate summary (v6.19.0)
     */
    updateComparableWeight(listingId, weightOverride) {
      // Find and update the comparable in currentComparables
      const comp = this.currentComparables.find(c => String(c.listing_id) === String(listingId));
      if (comp) {
        comp.weight_override = weightOverride;
        cmaLog('Updated comparable weight:', listingId, 'override:', weightOverride);

        // Recalculate summary with new weights
        this.recalculateSummaryWithWeights();
      }
    }

    /**
     * Recalculate summary statistics with current weights (v6.19.0)
     */
    recalculateSummaryWithWeights() {
      if (!this.currentComparables || this.currentComparables.length === 0) {
        return;
      }

      // Get top comparables (A/B grades)
      const topComps = this.currentComparables.filter(c =>
        c.comparability_grade === 'A' || c.comparability_grade === 'B'
      );

      const compsToUse = topComps.length > 0 ? topComps : this.currentComparables;

      // Calculate weighted average
      let weightedSum = 0;
      let weightTotal = 0;
      let unweightedSum = 0;

      compsToUse.forEach(comp => {
        const weight = comp.weight_override !== null ? comp.weight_override : comp.weight;
        const adjustedPrice = comp.adjusted_price || comp.close_price || comp.list_price;

        weightedSum += adjustedPrice * weight;
        weightTotal += weight;
        unweightedSum += adjustedPrice;
      });

      const weightedMid = weightTotal > 0 ? weightedSum / weightTotal : 0;
      const unweightedMid = compsToUse.length > 0 ? unweightedSum / compsToUse.length : 0;
      const difference = weightedMid - unweightedMid;

      cmaLog('Recalculated weighted average:', {
        weighted: weightedMid,
        unweighted: unweightedMid,
        difference: difference,
        compsUsed: compsToUse.length
      });

      // Update the summary display
      this.updateWeightedValueDisplay(weightedMid, unweightedMid, difference);
    }

    /**
     * Update weighted value display in summary (v6.19.0)
     */
    updateWeightedValueDisplay(weightedMid, unweightedMid, difference) {
      // Update the primary estimated value
      const $estimatedMid = $('.mld-summary-stats .mld-estimate-mid');
      if ($estimatedMid.length) {
        $estimatedMid.text(this.formatPrice(Math.round(weightedMid / 1000) * 1000));
      }

      // Update or create weighted comparison display
      let $weightedComparison = $('.mld-weighted-comparison');
      if ($weightedComparison.length === 0) {
        // Create the weighted comparison section
        const html = `
          <div class="mld-weighted-comparison" style="margin-top: 1rem; padding: 0.8rem; background: #f8f9fa; border-radius: 6px; font-size: 0.85rem;">
            <div style="font-weight: 600; margin-bottom: 0.5rem;">Weighted vs Unweighted Analysis:</div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
              <span>Weighted Value:</span>
              <strong class="mld-weighted-value">${this.formatPrice(Math.round(weightedMid / 1000) * 1000)}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
              <span>Simple Average:</span>
              <span class="mld-unweighted-value">${this.formatPrice(Math.round(unweightedMid / 1000) * 1000)}</span>
            </div>
            <div style="display: flex; justify-content: space-between; color: ${difference >= 0 ? '#28a745' : '#dc3545'};">
              <span>Difference:</span>
              <strong class="mld-weight-difference">${difference >= 0 ? '+' : ''}${this.formatPrice(Math.round(difference / 1000) * 1000)}</strong>
            </div>
          </div>
        `;
        $('.mld-cma-summary-section').append(html);
      } else {
        // Update existing values
        $weightedComparison.find('.mld-weighted-value').text(this.formatPrice(Math.round(weightedMid / 1000) * 1000));
        $weightedComparison.find('.mld-unweighted-value').text(this.formatPrice(Math.round(unweightedMid / 1000) * 1000));
        const $diff = $weightedComparison.find('.mld-weight-difference');
        $diff.text((difference >= 0 ? '+' : '') + this.formatPrice(Math.round(difference / 1000) * 1000));
        $diff.css('color', difference >= 0 ? '#28a745' : '#dc3545');
      }
    }

    savePropertyCharacteristic(listingId, field, value) {
      // Build the data object
      const data = {
        action: 'mld_save_user_property_data',
        listing_id: listingId,
        nonce: (typeof mldAjax !== 'undefined' && mldAjax.nonce) ? mldAjax.nonce : ''
      };

      if (field === 'road_type') {
        data.road_type = value;
      } else if (field === 'property_condition') {
        data.property_condition = value;
      }

      // Use mldAjax which is localized in class-mld-rewrites.php
      const ajaxurl = (typeof mldAjax !== 'undefined' && mldAjax.ajaxurl)
        ? mldAjax.ajaxurl
        : '/wp-admin/admin-ajax.php';

      cmaLog('Saving property characteristic with nonce:', data.nonce ? 'present' : 'MISSING');

      // Save via AJAX
      $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: data,
        success: (response) => {
          cmaLog('AJAX Response:', response);
          if (response.success) {
            cmaLog('✅ Property characteristic saved successfully:', field, value);

            // Check if this is the SUBJECT property
            const isSubjectProperty = listingId === this.subjectProperty.listing_id;

            if (isSubjectProperty) {
              cmaLog('📍 Subject property changed - reloading all comparables');

              // Update subject property data (set both snake_case and camelCase for compatibility)
              if (field === 'road_type') {
                this.subjectProperty.road_type = value;
                this.subjectProperty.roadType = value;
              } else if (field === 'property_condition') {
                this.subjectProperty.property_condition = value;
                this.subjectProperty.propertyCondition = value;
              }

              // Save current UI state
              const expandedSections = [];
              $('.mld-adjustment-list').each(function() {
                if ($(this).is(':visible')) {
                  const cardListingId = $(this).closest('.mld-comp-card').data('listing-id');
                  expandedSections.push(cardListingId);
                }
              });

              // Reload ALL comparables because subject property changed
              // All adjustments need to recalculate against new subject values
              this.loadComparablesPreservingState(expandedSections);

            } else {
              // This is a COMPARABLE property - update just this one
              cmaLog('📋 Comparable property changed - updating single property');

              // Update the comparable data in memory
              const comp = this.currentComparables.find(c => c.listing_id === listingId);
              if (comp) {
                if (field === 'road_type') {
                  comp.road_type = value;
                } else if (field === 'property_condition') {
                  comp.property_condition = value;
                }
              }

              // Update the comparable in filtered list too
              const filteredComp = this.filteredComparables.find(c => c.listing_id === listingId);
              if (filteredComp) {
                if (field === 'road_type') {
                  filteredComp.road_type = value;
                } else if (field === 'property_condition') {
                  filteredComp.property_condition = value;
                }
              }

              // Recalculate adjustments for this single property
              // Note: updateSinglePropertyAdjustments now handles summary update internally
              this.updateSinglePropertyAdjustments(listingId);
            }
          } else {
            console.error('❌ Save failed:', response.data || 'Unknown error');
            alert('Failed to save: ' + (response.data || 'Unknown error'));
          }
        },
        error: (xhr, status, error) => {
          console.error('❌ AJAX Error saving property characteristic:', error);
          console.error('Response:', xhr.responseText);
          alert('Error saving property data. Check console for details.');
        }
      });
    }

    updateSinglePropertyAdjustments(listingId) {
      cmaLog('Updating adjustments for single property:', listingId);

      // Save current UI state
      const expandedSections = [];
      $('.mld-adjustment-list').each(function() {
        if ($(this).is(':visible')) {
          const cardListingId = $(this).closest('.mld-comp-card').data('listing-id');
          expandedSections.push(cardListingId);
        }
      });

      // Store current scroll position
      const scrollTop = $(window).scrollTop();

      // Find the card
      const $card = $(`.mld-comp-card[data-listing-id="${listingId}"]`);
      if (!$card.length) {
        this.loadComparablesPreservingState(expandedSections);
        return;
      }

      // Get comparable data
      const comp = this.currentComparables.find(c => String(c.listing_id) === String(listingId));
      if (!comp) {
        this.loadComparablesPreservingState(expandedSections);
        return;
      }

      // Show loading
      $card.css({'opacity': '0.7', 'pointer-events': 'none'});

      // Fetch fresh adjustments for ONE property only
      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'get_single_comparable_adjustments',
          nonce: mldAjax.nonce,
          listing_id: listingId,
          subject_property: JSON.stringify(this.subjectProperty)
        },
        success: (response) => {
          if (response.success && response.data) {
            // Update data
            comp.adjustments = response.data.adjustments;
            comp.adjusted_price = response.data.adjusted_price;
            comp.comparability_score = response.data.comparability_score;
            comp.comparability_grade = response.data.comparability_grade;

            // Re-render ONE card
            const newCardHtml = this.renderComparable(comp);
            const wasExpanded = expandedSections.includes(listingId);
            $card.replaceWith(newCardHtml);

            // Restore state
            if (wasExpanded) {
              const $newCard = $(`.mld-comp-card[data-listing-id="${listingId}"]`);
              $newCard.find('.mld-adjustment-list').show();
              $newCard.find('.mld-adjustments-toggle').find('span').html('▲');
            }

            // Restore scroll
            $(window).scrollTop(scrollTop);

            // Update summary stats only (don't touch other cards)
            this.updateSummaryStatsOnly();

            cmaLog('✅ Card updated in place - position maintained');
          } else {
            this.loadComparablesPreservingState(expandedSections);
          }
        },
        error: () => {
          this.loadComparablesPreservingState(expandedSections);
        },
        complete: () => {
          $card.css({'opacity': '1', 'pointer-events': 'auto'});
        }
      });
    }

    loadComparablesPreservingState(expandedSections) {
      if (this.isLoading) return;

      this.isLoading = true;

      cmaLog('Preserving state - current selections:', this.selectedComparables);

      // Add subtle loading overlay
      const $resultsContainer = $('#mld-comp-results');
      $resultsContainer.css({
        'opacity': '0.6',
        'pointer-events': 'none',
        'transition': 'opacity 0.2s ease'
      });

      const data = {
        action: 'get_enhanced_comparables',
        nonce: mldAjax.nonce,
        ...this.getFilterData(),
      };

      // Convert statuses array to JSON
      if (data.statuses) {
        data.statuses = JSON.stringify(data.statuses);
      }

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: data,
        success: (response) => {
          if (response.success) {
            // Render with preserveSelections=true to keep current selectedComparables
            this.renderResults(response.data, true);

            // Restore expanded sections
            if (expandedSections && expandedSections.length > 0) {
              expandedSections.forEach(listingId => {
                const $card = $(`.mld-comp-card[data-listing-id="${listingId}"]`);
                const $list = $card.find('.mld-adjustment-list');
                const $header = $card.find('.mld-comp-adjustments-header');
                if ($list.length && $header.length) {
                  $list.show();
                  $header.find('span').html('▲');
                }
              });
            }

            // Remove loading overlay with smooth transition
            $resultsContainer.css({
              'opacity': '1',
              'pointer-events': 'auto'
            });

            cmaLog('✅ Comparables reloaded - selections preserved:', this.selectedComparables);
          } else {
            this.showError(response.data || 'Failed to load comparables');
          }
        },
        error: (xhr, status, error) => {
          console.error('AJAX Error:', error);
          this.showError('An error occurred while loading comparable properties.');
        },
        complete: () => {
          this.isLoading = false;
          // Ensure overlay is removed even on error
          $resultsContainer.css({
            'opacity': '1',
            'pointer-events': 'auto'
          });
        },
      });
    }

    updateCompareButton() {
      const $btn = $('#mld-compare-btn');
      const count = this.selectedComparables.length;

      $('#compare-count').text(count);

      if (count >= 2 && count <= 3) {
        $btn.prop('disabled', false);
      } else {
        $btn.prop('disabled', true);
      }
    }

    applyPropertyCharacteristicsToComparables(comparables) {
      cmaLog('Applying property characteristics adjustments...');
      cmaLog('Subject property:', this.subjectProperty);

      // Road type values (percentage impact)
      // Unknown/Main road is baseline (0%), neighborhood road is premium
      // Get premium percentage from admin settings (passed via mldAjax)
      const roadTypePremium = (typeof mldAjax !== 'undefined' && mldAjax.roadTypePremium)
        ? parseFloat(mldAjax.roadTypePremium)
        : 25; // Fallback to 25 if not set

      const roadTypeValues = {
        'unknown': 0,                    // Unknown - baseline (no data)
        'main_road': 0,                  // Main road - baseline
        'neighborhood_road': roadTypePremium,   // Neighborhood road premium (from admin settings)
        '': 0,                           // Empty - treat as unknown
        null: 0                          // Null - treat as unknown
      };

      // Condition values (percentage impact)
      const conditionValues = {
        'unknown': 0,
        'new': 20,
        'fully_renovated': 12,
        'some_updates': 0,        // Baseline
        'needs_updating': -12,
        'distressed': -30,
        '': 0,
        null: 0
      };

      // Get subject property characteristics (with defaults to 'unknown' if not set)
      const subjectRoadType = this.subjectProperty.road_type || this.subjectProperty.roadType || 'unknown';
      const subjectCondition = this.subjectProperty.property_condition || this.subjectProperty.propertyCondition || 'unknown';

      cmaLog(`Subject characteristics: road=${subjectRoadType}, condition=${subjectCondition}`);

      comparables.forEach(comp => {
        // Get the base price (before ANY adjustments)
        const basePrice = parseFloat(comp.close_price || comp.list_price || 0);

        // Preserve PHP-calculated adjusted_price on first run
        if (!comp.php_base_adjusted_price) {
          // Use adjusted_price from PHP, or fallback to base price if not available
          comp.php_base_adjusted_price = parseFloat(comp.adjusted_price || basePrice || 0);
          // Store DB values with defaults to 'unknown' if not set
          comp.db_road_type = comp.road_type || 'unknown';  // Default to unknown (no data yet)
          comp.db_property_condition = comp.property_condition || 'unknown';  // Default to unknown (no data yet)
        }

        // Get comp's current road type and condition from DOM or database
        const roadTypeInput = $(`.mld-road-type-input[data-listing-id="${comp.listing_id}"]:checked`);
        // Apply same default as UI: if empty, default to unknown
        const currentRoadType = roadTypeInput.length ? roadTypeInput.val() : (comp.road_type || 'unknown');

        const conditionInput = $(`.mld-condition-input[data-listing-id="${comp.listing_id}"]:checked`);
        // Apply same default as UI: if empty, default to unknown
        const currentCondition = conditionInput.length ? conditionInput.val() : (comp.property_condition || 'unknown');

        cmaLog(`Comp ${comp.listing_id}: db_road=${comp.db_road_type}, current_road=${currentRoadType}, db_cond=${comp.db_property_condition}, current_cond=${currentCondition}`);

        // Calculate OLD adjustments (what PHP used based on database values)
        const calculateAdjustment = (roadType, condition, subRoad, subCond) => {
          let total = 0;

          // Road adjustment - skip if subject road type is unknown
          if (subRoad && subRoad !== 'unknown') {
            const compRoadValue = roadTypeValues[roadType] || 0;
            const subjectRoadValue = roadTypeValues[subRoad] || 0;
            const roadDiff = compRoadValue - subjectRoadValue;
            if (roadDiff !== 0) {
              total += -(basePrice * (roadDiff / 100));
            }
          }

          // Condition adjustment - skip if subject condition is unknown
          if (subCond && subCond !== 'unknown') {
            const compCondValue = conditionValues[condition] || 0;
            const subjectCondValue = conditionValues[subCond] || 0;
            const condDiff = compCondValue - subjectCondValue;
            if (condDiff !== 0) {
              total += -(basePrice * (condDiff / 100));
            }
          }

          return total;
        };

        // Calculate old adjustments (based on database values)
        const oldAdjustments = calculateAdjustment(
          comp.db_road_type,
          comp.db_property_condition,
          subjectRoadType,
          subjectCondition
        );

        // Calculate new adjustments (based on current UI values)
        const newAdjustments = calculateAdjustment(
          currentRoadType,
          currentCondition,
          subjectRoadType,
          subjectCondition
        );

        // Calculate the delta (difference between new and old)
        const delta = newAdjustments - oldAdjustments;

        // Apply delta to PHP's base adjusted price
        // This preserves ALL other PHP adjustments (sqft, beds, baths, garage, pool, year, location, etc.)
        comp.adjusted_price = comp.php_base_adjusted_price + delta;

        // Update the adjustments items array to include road type and condition
        // Start with PHP adjustments (or empty if subject property)
        if (!comp.adjustments) {
          comp.adjustments = { items: [], total_adjustment: 0 };
        }

        // Remove any existing road type and condition adjustments from display
        comp.adjustments.items = comp.adjustments.items.filter(item =>
          item.feature !== 'Road Type' && item.feature !== 'Condition'
        );

        // Calculate individual road and condition adjustments for display
        const roadTypeLabels = {
          'unknown': 'Unknown',
          'main_road': 'Main Road',
          'neighborhood_road': 'Neighborhood Road',
          '': 'Unknown',
          null: 'Unknown'
        };

        const conditionLabels = {
          'unknown': 'Unknown',
          'new': 'New Construction',
          'fully_renovated': 'Fully Renovated',
          'some_updates': 'Some Updates',
          'needs_updating': 'Needs Updating',
          'distressed': 'Distressed',
          '': 'Unknown',
          null: 'Unknown'
        };

        // Add road type adjustment if applicable - skip if subject is unknown
        if (subjectRoadType && subjectRoadType !== 'unknown') {
          const compRoadValue = roadTypeValues[currentRoadType] || 0;
          const subjectRoadValue = roadTypeValues[subjectRoadType] || 0;
          const roadDiff = compRoadValue - subjectRoadValue;
          if (roadDiff !== 0) {
            const roadAdjustment = -(basePrice * (roadDiff / 100));
            const compLabel = roadTypeLabels[currentRoadType] || currentRoadType;
            const subjectLabel = roadTypeLabels[subjectRoadType] || subjectRoadType;

            comp.adjustments.items.push({
              feature: 'Road Type',
              difference: `${compLabel} vs ${subjectLabel}`,
              adjustment: Math.round(roadAdjustment),
              explanation: `${compLabel} (${roadDiff > 0 ? '+' : ''}${roadDiff}%) vs ${subjectLabel}`
            });
          }
        }

        // Add condition adjustment if applicable - skip if subject is unknown
        if (subjectCondition && subjectCondition !== 'unknown') {
          const compCondValue = conditionValues[currentCondition] || 0;
          const subjectCondValue = conditionValues[subjectCondition] || 0;
          const condDiff = compCondValue - subjectCondValue;
          if (condDiff !== 0) {
            const condAdjustment = -(basePrice * (condDiff / 100));
            const compLabel = conditionLabels[currentCondition] || currentCondition;
            const subjectLabel = conditionLabels[subjectCondition] || subjectCondition;

            comp.adjustments.items.push({
              feature: 'Condition',
              difference: `${compLabel} vs ${subjectLabel}`,
              adjustment: Math.round(condAdjustment),
              explanation: `${compLabel} (${condDiff > 0 ? '+' : ''}${condDiff}%) vs ${subjectLabel}`
            });
          }
        }

        // Update total adjustment
        comp.adjustments.total_adjustment = comp.adjustments.items.reduce((sum, item) => sum + item.adjustment, 0);

        cmaLog(`  Base price: ${basePrice.toFixed(0)}`);
        cmaLog(`  PHP adjusted price (with all factors): ${(comp.php_base_adjusted_price || 0).toFixed(0)}`);
        cmaLog(`  Old road/condition adjustments: ${oldAdjustments.toFixed(0)}`);
        cmaLog(`  New road/condition adjustments: ${newAdjustments.toFixed(0)}`);
        cmaLog(`  Delta: ${delta.toFixed(0)}`);
        cmaLog(`  Final adjusted price: ${(comp.adjusted_price || 0).toFixed(0)}`);
        cmaLog(`  Total adjustment in items: ${(comp.adjustments.total_adjustment || 0).toFixed(0)}`);

        // Store current values for future delta calculations
        comp.current_road_type = currentRoadType;
        comp.current_property_condition = currentCondition;
      });

      cmaLog('Property characteristics applied to all comparables');
    }

    updateAdjustmentsDisplay(comparables) {
      cmaLog('Updating adjustments display for comparables');

      comparables.forEach(comp => {
        const $card = $(`.mld-comp-card[data-listing-id="${comp.listing_id}"]`);
        if (!$card.length) return;

        // Find the adjustments section
        const $adjustmentsContainer = $card.find('.mld-comp-adjustments');
        if (!$adjustmentsContainer.length) return;

        // Check if adjustments section was expanded
        const wasExpanded = $adjustmentsContainer.find('.mld-adjustment-list').is(':visible');

        // Re-render adjustments HTML
        const adjustmentsHtml = this.renderAdjustments(comp.adjustments, comp.adjusted_price);

        if (adjustmentsHtml) {
          // Replace the old adjustments with new ones
          $adjustmentsContainer.replaceWith(adjustmentsHtml);

          // Restore expanded state if it was open
          if (wasExpanded) {
            // Use setTimeout to ensure DOM is updated before trying to expand
            setTimeout(() => {
              const $newContainer = $card.find('.mld-comp-adjustments');
              const $list = $newContainer.find('.mld-adjustment-list');
              const $header = $newContainer.find('.mld-comp-adjustments-header span');
              if ($list.length && $header.length) {
                $list.show();
                $header.html('▲');
              }
            }, 0);
          }
        }

        cmaLog(`  Updated adjustments display for ${comp.listing_id}`);
      });
    }

    updateSummaryStatsOnly() {
      // Lightweight update that ONLY recalculates summary statistics
      // Does NOT touch any card displays or call any methods that might re-render
      cmaLog('=== updateSummaryStatsOnly called ===');

      // Get only the selected comparables for summary calculation
      const selectedComps = this.filteredComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );

      cmaLog('Updating summary for', selectedComps.length, 'selected comparables');

      // Recalculate summary statistics based on selected properties
      const summary = this.calculateSummaryFromComparables(selectedComps);

      // Update the summary display
      const summaryHtml = this.renderSummary(summary, selectedComps.length);
      const $existingSummary = $('.mld-comp-summary');

      if ($existingSummary.length) {
        $existingSummary.remove();
        const $resultsContainer = $('#mld-comp-results');
        if ($resultsContainer.length) {
          $resultsContainer.prepend(summaryHtml);
          cmaLog('✅ Summary updated without touching cards');
        }
      }

      // Update the count display
      $('#compare-count').text(selectedComps.length);

      cmaLog('=== updateSummaryStatsOnly complete ===');
    }

    recalculateCMAStatistics() {
      cmaLog('=== recalculateCMAStatistics called ===');
      cmaLog('Selected listing IDs:', this.selectedComparables);
      cmaLog('Total filtered comparables:', this.filteredComparables.length);

      // Apply road type and condition adjustments to ALL comparables (not just selected)
      // This ensures the adjustments display is correct for all visible properties
      this.applyPropertyCharacteristicsToComparables(this.filteredComparables);

      // Update the adjustments display in the DOM for all comparables
      this.updateAdjustmentsDisplay(this.filteredComparables);

      // Get only the selected comparables for summary calculation
      const selectedComps = this.filteredComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );

      cmaLog('Selected comparables after filter:', selectedComps.length);
      cmaLog('Selected comp IDs:', selectedComps.map(c => c.listing_id));

      // Recalculate summary statistics based on selected properties
      const summary = this.calculateSummaryFromComparables(selectedComps);

      cmaLog('Calculated summary:', {
        estimatedValue: summary.estimated_value,
        avgPrice: summary.avg_adjusted_price,
        medianPrice: summary.median_adjusted_price
      });

      cmaLog('🎯 VALUES TO BE RENDERED:');
      cmaLog('  - Low:', this.formatPrice(summary.estimated_value.low));
      cmaLog('  - Mid:', this.formatPrice(summary.estimated_value.mid));
      cmaLog('  - High:', this.formatPrice(summary.estimated_value.high));
      cmaLog('  - Comparables count:', selectedComps.length);

      // Update the summary display
      const summaryHtml = this.renderSummary(summary, selectedComps.length);
      const $existingSummary = $('.mld-comp-summary');

      cmaLog('Found existing summary element:', $existingSummary.length);
      cmaLog('Current summary HTML before removal:', $existingSummary.find('.mld-comp-stat-value').first().text());

      if ($existingSummary.length) {
        // Get parent for debugging
        const $parent = $existingSummary.parent();
        cmaLog('Parent element:', $parent.length);

        // Remove old summary and insert new one
        $existingSummary.remove();

        // Find where to insert - should be at the beginning of results container
        const $resultsContainer = $('#mld-comp-results');
        if ($resultsContainer.length) {
          $resultsContainer.prepend(summaryHtml);
          cmaLog('Summary prepended to results container');

          // Verify the new summary is in the DOM
          const $newSummary = $('.mld-comp-summary');
          cmaLog('✅ NEW SUMMARY IN DOM:');
          cmaLog('  - Found:', $newSummary.length, 'summary element(s)');
          cmaLog('  - First value shows:', $newSummary.first().find('.mld-comp-stat-value').first().text());
        } else {
          console.error('Results container not found!');
        }
      } else {
        console.error('Existing summary element not found!');
      }

      // Update the count display
      $('#compare-count').text(selectedComps.length);

      cmaLog('=== recalculateCMAStatistics complete ===');
    }

    calculateSummaryFromComparables(comparables) {
      if (!comparables || comparables.length === 0) {
        return {
          estimated_value: { low: 0, mid: 0, high: 0, confidence: 'Low', confidence_score: 0 },
          price_per_sqft: { avg: 0, median: 0, range: { min: 0, max: 0 } },
          median_price: 0,
          median_adjusted_price: 0,
          avg_price: 0,
          avg_adjusted_price: 0,
          recent_sales_count: 0
        };
      }

      const prices = comparables.map(c => c.close_price || c.list_price).filter(p => p > 0);
      const adjustedPrices = comparables.map(c => c.adjusted_price || c.close_price || c.list_price).filter(p => p > 0);
      const pricesPerSqft = comparables
        .filter(c => c.price_per_sqft && c.price_per_sqft > 0)
        .map(c => c.price_per_sqft);

      // Calculate median
      const sortedPrices = [...prices].sort((a, b) => a - b);
      const sortedAdjusted = [...adjustedPrices].sort((a, b) => a - b);
      const median = sortedPrices[Math.floor(sortedPrices.length / 2)] || 0;
      const medianAdjusted = sortedAdjusted[Math.floor(sortedAdjusted.length / 2)] || 0;

      // Calculate average
      const avgPrice = prices.reduce((sum, p) => sum + p, 0) / prices.length;
      const avgAdjusted = adjustedPrices.reduce((sum, p) => sum + p, 0) / adjustedPrices.length;

      // Calculate price per sqft stats
      let pricePerSqftStats = { avg: 0, median: 0, range: { min: 0, max: 0 } };
      if (pricesPerSqft.length > 0) {
        const sortedPPSF = [...pricesPerSqft].sort((a, b) => a - b);
        pricePerSqftStats = {
          avg: pricesPerSqft.reduce((sum, p) => sum + p, 0) / pricesPerSqft.length,
          median: sortedPPSF[Math.floor(sortedPPSF.length / 2)],
          range: {
            min: sortedPPSF[0],
            max: sortedPPSF[sortedPPSF.length - 1]
          }
        };
      }

      // Calculate estimated value range
      const stdDev = Math.sqrt(adjustedPrices.reduce((sum, p) => sum + Math.pow(p - avgAdjusted, 2), 0) / adjustedPrices.length);
      const confidenceScore = Math.min(100, Math.max(0, 100 - (stdDev / avgAdjusted * 100)));

      return {
        estimated_value: {
          low: Math.round(medianAdjusted - stdDev),
          mid: Math.round(medianAdjusted),
          high: Math.round(medianAdjusted + stdDev),
          confidence: confidenceScore >= 80 ? 'High' : (confidenceScore >= 60 ? 'Medium' : 'Low'),
          confidence_score: Math.round(confidenceScore)
        },
        price_per_sqft: pricePerSqftStats,
        median_price: median,
        median_adjusted_price: medianAdjusted,
        avg_price: avgPrice,
        avg_adjusted_price: avgAdjusted,
        recent_sales_count: comparables.filter(c => {
          if (c.close_date) {
            const months = (Date.now() - new Date(c.close_date)) / (1000 * 60 * 60 * 24 * 30);
            return months <= 3;
          }
          return false;
        }).length
      };
    }

    showComparisonModal() {
      const selected = this.currentComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );

      // Create comparison modal HTML
      const modalHtml = this.renderComparisonModal(selected);

      // Show modal
      $('body').append(modalHtml);
      $('#mld-compare-modal').stop(true, true).fadeIn(300);
    }

    renderComparisonModal(comparables) {
      return `
        <div id="mld-compare-modal" class="mld-modal" style="display:none;">
          <div class="mld-modal-overlay"></div>
          <div class="mld-modal-content">
            <div class="mld-modal-header">
              <h3>⚖️ Property Comparison</h3>
              <button class="mld-modal-close">&times;</button>
            </div>
            <div class="mld-modal-body">
              <div class="mld-comparison-grid">
                ${comparables.map(comp => this.renderComparisonCard(comp)).join('')}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    renderComparisonCard(comp) {
      const price = comp.close_price || comp.list_price;
      return `
        <div class="mld-comparison-card">
          <div class="mld-comparison-header">
            <img src="${comp.main_photo_url || ''}" alt="${comp.unparsed_address}">
            <div class="mld-comparison-grade ${comp.comparability_grade}">${comp.comparability_grade}</div>
          </div>
          <div class="mld-comparison-details">
            <h4>${comp.unparsed_address}</h4>
            <div class="mld-comparison-price">${this.formatPrice(price)}</div>
            <table class="mld-comparison-table">
              <tr><td>Beds:</td><td>${comp.bedrooms_total}</td></tr>
              <tr><td>Baths:</td><td>${comp.bathrooms_total}</td></tr>
              <tr><td>Sqft:</td><td>${this.formatNumber(comp.building_area_total)}</td></tr>
              <tr><td>Price/SF:</td><td>$${comp.price_per_sqft.toFixed(2)}</td></tr>
              <tr><td>Year Built:</td><td>${comp.year_built || 'N/A'}</td></tr>
              <tr><td>Lot Size:</td><td>${comp.lot_size_acres ? comp.lot_size_acres + ' ac' : 'N/A'}</td></tr>
              <tr><td>Distance:</td><td>${comp.distance_miles.toFixed(2)} mi</td></tr>
              <tr><td>Score:</td><td>${comp.comparability_score}/100</td></tr>
              <tr><td>Status:</td><td>${this.formatStatusLabel(comp.standard_status)}</td></tr>
              ${comp.close_date ? `<tr><td>Sold:</td><td>${this.formatDate(comp.close_date)}</td></tr>` : ''}
            </table>
          </div>
        </div>
      `;
    }

    getFilterData() {
      const formData = this.filterForm.serializeArray();
      const filters = {};

      // Parse form data
      formData.forEach((field) => {
        if (field.name === 'statuses[]') {
          if (!filters.statuses) filters.statuses = [];
          filters.statuses.push(field.value);
        } else if (field.value !== '') {
          filters[field.name] = field.value;
        }
      });

      // Combine with subject property data
      return {
        ...this.subjectProperty,
        ...filters,
      };
    }

    loadComparables() {
      cmaLog('[MLD Comparable Sales] loadComparables() called');
      if (this.isLoading) {
        cmaLog('[MLD Comparable Sales] Already loading, skipping');
        return;
      }

      this.isLoading = true;
      this.showLoading();

      const data = {
        action: 'get_enhanced_comparables',
        nonce: mldAjax.nonce,
        ...this.getFilterData(),
      };

      cmaLog('[MLD Comparable Sales] AJAX data:', data);

      // Convert statuses array to JSON
      if (data.statuses) {
        data.statuses = JSON.stringify(data.statuses);
      }

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: data,
        success: (response) => {
          cmaLog('[MLD Comparable Sales] AJAX response:', response);
          if (response.data && response.data._debug) {
            cmaLog('[MLD Comparable Sales] DEBUG INFO:', response.data._debug);
          }
          if (response.success) {
            this.renderResults(response.data);
          } else {
            this.showError(response.data || 'Failed to load comparables');
          }
        },
        error: (xhr, status, error) => {
          console.error('[MLD Comparable Sales] AJAX Error:', error, 'Status:', xhr.status);

          // v6.68.23: Detect specific error types for better user messaging
          let errorMessage = 'An error occurred while loading comparable properties.';

          if (xhr.status === 429) {
            // Rate limit exceeded
            const retryAfter = xhr.responseJSON?.retry_after || 60;
            errorMessage = `Too many requests. Please wait ${retryAfter} seconds before trying again.`;
          } else if (xhr.status === 403) {
            // Forbidden (nonce expired, bot detected, etc.)
            errorMessage = 'Session expired. Please refresh the page and try again.';
          } else if (xhr.status === 500 || xhr.status === 502 || xhr.status === 503) {
            // Server errors
            errorMessage = 'Server temporarily unavailable. Please try again in a few moments.';
          } else if (xhr.status === 504) {
            // Gateway timeout
            errorMessage = 'Request timed out. Try narrowing your search filters.';
          } else if (status === 'timeout') {
            // Client-side timeout
            errorMessage = 'Request took too long. Try narrowing your search filters.';
          } else if (status === 'error' && !xhr.status) {
            // Network error (no connection)
            errorMessage = 'Network error. Please check your internet connection.';
          }

          this.showError(errorMessage);
        },
        complete: () => {
          this.isLoading = false;
        },
      });
    }

    showLoading() {
      // Show skeleton loading states
      $('.mld-comp-skeleton-container').show();
      $('.mld-comp-loading').hide();
    }

    showError(message) {
      this.resultsContainer.html(`
                <div class="mld-comp-error" style="padding: 2rem; text-align: center; color: #dc3545;">
                    <p><strong>Error:</strong> ${message}</p>
                </div>
            `);
    }

    renderResults(data, preserveSelections = false) {
      // Hide skeleton loading
      $('.mld-comp-skeleton-container').hide();
      $('.mld-comp-loading').hide();

      const { comparables, summary, market_context } = data;

      if (!comparables || comparables.length === 0) {
        this.resultsContainer.html(`
                    <div class="mld-comp-empty" style="padding: 3rem; text-align: center; color: #6c757d;">
                        <p>No comparable properties found with the current filters.</p>
                        <p>Try adjusting your search criteria.</p>
                    </div>
                `);
        this.toolbar.hide();
        $('#mld-comp-pagination').hide();
        return;
      }

      // Store current comparables for client-side filtering/sorting
      this.currentComparables = comparables;
      this.filteredComparables = comparables;
      this.currentPage = 1;

      // Restore weight overrides from saved session if any (v6.19.0)
      if (this.savedWeightOverrides && Object.keys(this.savedWeightOverrides).length > 0) {
        cmaLog('[CMA Restore] Restoring weight overrides from saved session');
        this.currentComparables.forEach(comp => {
          const savedWeight = this.savedWeightOverrides[comp.listing_id];
          if (savedWeight !== undefined) {
            comp.weight_override = savedWeight;
            cmaLog(`[CMA Restore] Restored weight override for ${comp.listing_id}: ${savedWeight}`);
          }
        });
        // Clear saved overrides after restoration
        this.savedWeightOverrides = {};
      }

      // Restore selections from saved session if any (v6.20.1)
      // This takes precedence over default A/B grade selection
      if (this.savedSelectedComparables && this.savedSelectedComparables.length > 0) {
        cmaLog('[CMA Restore] Restoring selections from saved session:', this.savedSelectedComparables);
        // Filter to only include comparables that still exist in the new results
        const availableListingIds = comparables.map(c => String(c.listing_id));
        this.selectedComparables = this.savedSelectedComparables.filter(id =>
          availableListingIds.includes(id)
        );
        cmaLog('[CMA Restore] Applied selections (filtered to available):', this.selectedComparables);
        // Clear saved selections after restoration
        this.savedSelectedComparables = [];
      } else if (!preserveSelections) {
        // Initialize only A and B grade properties as selected for CMA calculations
        // UNLESS we're preserving user selections from a previous state
        this.selectedComparables = comparables
          .filter(c => c.comparability_grade === 'A' || c.comparability_grade === 'B')
          .map(c => String(c.listing_id));  // Ensure string type for consistency
      }

      // Show toolbar
      this.toolbar.show();

      let html = '';

      // Apply road type and condition adjustments to ALL comparables
      // This ensures proper calculation even before inputs are in DOM
      this.applyPropertyCharacteristicsToComparables(comparables);

      // Calculate summary based on ONLY the selected/checked properties
      const selectedComps = comparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );

      const recalculatedSummary = this.calculateSummaryFromComparables(selectedComps);

      // Store summary for save/load feature
      this.currentSummary = recalculatedSummary;

      // Summary section - use recalculated summary based on selected properties
      html += this.renderSummary(recalculatedSummary, selectedComps.length);

      // Market context
      if (market_context) {
        html += this.renderMarketContext(market_context);
      }

      // Comparables grid with pagination
      html += '<div class="mld-comp-grid">';
      const startIdx = (this.currentPage - 1) * this.itemsPerPage;
      const endIdx = startIdx + this.itemsPerPage;
      const pageComps = comparables.slice(startIdx, endIdx);

      pageComps.forEach((comp) => {
        html += this.renderComparable(comp);
      });
      html += '</div>';

      this.resultsContainer.html(html);

      // Update pagination
      this.updatePagination();

      // Setup adjustment toggles
      this.setupAdjustmentToggles();

      // Setup compare checkboxes
      this.setupCompareCheckboxes();

      // Setup property characteristics (road type & condition)
      this.setupPropertyCharacteristics();

      // Setup weight controls for weighted averaging (v6.19.0)
      this.setupWeightControls();

      // Setup favorite buttons
      this.setupFavoriteButtons();

      // Update adjustments display now that DOM is ready
      // This ensures road type and condition adjustments are visible
      this.updateAdjustmentsDisplay(comparables);

      // Update map if visible
      if (this.mapVisible) {
        this.renderMap();
      }

      // Load market conditions analysis (v6.18.0)
      this.loadMarketConditions();

      // Load value history trend (v6.20.0)
      this.loadValueHistory();

      // Show Re-run comparison if this was a re-run (v6.20.1)
      if (this.showRerunComparison && this.rerunPreviousValue) {
        const newValue = this.currentSummary?.estimated_value?.mid || 0;
        this.renderRerunComparison(this.rerunPreviousValue, newValue);
        // Clear the flags
        this.showRerunComparison = false;
        this.rerunPreviousValue = null;
        // Hide the re-run button since this is now a fresh CMA
        this.hideRerunButton();
      }

      // For standalone CMAs, show Re-run button after initial load (v6.20.2)
      if (this.showRerunAfterLoad && this.isStandaloneCMA) {
        // Update the previous value estimate with the fresh data
        this.previousValueEstimate = this.currentSummary?.estimated_value?.mid || 0;
        if (this.loadedSessionData) {
          this.loadedSessionData.estimated_value_mid = this.previousValueEstimate;
          this.loadedSessionData.summary_statistics = this.currentSummary;
        }
        this.showRerunButton();
        this.showRerunAfterLoad = false;
      }
    }

    renderSummary(summary, count) {
      const { estimated_value, price_per_sqft, median_price, median_adjusted_price, avg_price, avg_adjusted_price } = summary;

      // Calculate confidence color
      const confScore = estimated_value.confidence_score || 70;
      const confColor = confScore >= 80 ? '#28a745' : (confScore >= 60 ? '#ffc107' : '#dc3545');
      const confBarWidth = Math.max(0, Math.min(100, confScore));

      return `
            <div class="mld-comp-summary">
                <h3>📊 Market Value Analysis</h3>
                <div class="mld-comp-summary-stats">
                    <div class="mld-comp-stat">
                        <div class="mld-comp-stat-label">Estimated Range</div>
                        <div class="mld-comp-stat-value">
                            ${this.formatPrice(estimated_value.low)} - ${this.formatPrice(estimated_value.high)}
                        </div>
                    </div>
                    <div class="mld-comp-stat">
                        <div class="mld-comp-stat-label">Most Likely Value</div>
                        <div class="mld-comp-stat-value">${this.formatPrice(estimated_value.mid)}</div>
                        <div class="mld-comp-stat-sublabel">Median: ${this.formatPrice(median_adjusted_price || 0)}</div>
                    </div>
                    <div class="mld-comp-stat">
                        <div class="mld-comp-stat-label">Confidence Level</div>
                        <div class="mld-comp-stat-value">${estimated_value.confidence || 'Medium'}</div>
                        <div class="mld-comp-confidence-bar">
                            <div class="mld-comp-confidence-fill" style="width: ${confBarWidth}%; background: ${confColor};"></div>
                        </div>
                    </div>
                    <div class="mld-comp-stat">
                        <div class="mld-comp-stat-label">Comparables Used</div>
                        <div class="mld-comp-stat-value">${count}</div>
                        <div class="mld-comp-stat-sublabel">${summary.recent_sales_count || 0} recent sales</div>
                    </div>
                </div>
                ${price_per_sqft && price_per_sqft.avg > 0 ? `
                <div class="mld-comp-summary-secondary">
                    <div class="mld-comp-stat-secondary">
                        <span class="mld-stat-sec-label">Avg Price/SF:</span>
                        <span class="mld-stat-sec-value">$${price_per_sqft.avg.toFixed(2)}</span>
                    </div>
                    <div class="mld-comp-stat-secondary">
                        <span class="mld-stat-sec-label">Median Price/SF:</span>
                        <span class="mld-stat-sec-value">$${price_per_sqft.median.toFixed(2)}</span>
                    </div>
                    <div class="mld-comp-stat-secondary">
                        <span class="mld-stat-sec-label">Price/SF Range:</span>
                        <span class="mld-stat-sec-value">$${price_per_sqft.range.min.toFixed(2)} - $${price_per_sqft.range.max.toFixed(2)}</span>
                    </div>
                    <div class="mld-comp-stat-secondary">
                        <span class="mld-stat-sec-label">Avg vs Median Price:</span>
                        <span class="mld-stat-sec-value">${this.formatPrice(avg_adjusted_price || 0)} vs ${this.formatPrice(median_adjusted_price || 0)}</span>
                    </div>
                </div>
                ` : ''}
            </div>
        `;
    }

    renderMarketContext(context) {
      if (!context || !context.avg_price) return '';

      return `
            <div class="mld-market-context" style="background: #e7f1ff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 2rem; border-left: 4px solid #2c5aa0;">
                <strong>Market Context:</strong>
                ${context.total_sales || 0} sales in the last ${
        context.months || 12
      } months
                with an average price of ${this.formatPrice(context.avg_price)}
                and average days on market of ${Math.round(
                  context.avg_dom || 0
                )} days.
            </div>
        `;
    }

    /**
     * Load comprehensive market conditions analysis
     * @since 6.18.0
     */
    loadMarketConditions() {
      const city = this.subjectProperty.city || '';
      const state = this.subjectProperty.state || '';

      cmaLog('[MLD Market Conditions v6.20.2] Starting load...');
      cmaLog('[MLD Market Conditions] Subject property:', this.subjectProperty);

      if (!city) {
        cmaLog('[MLD Market Conditions] No city available, skipping market conditions load');
        return;
      }

      cmaLog('[MLD Market Conditions] Loading conditions for:', city, state);
      cmaLog('[MLD Market Conditions] AJAX URL:', mldAjax?.ajaxurl);
      cmaLog('[MLD Market Conditions] Nonce:', mldAjax?.nonce);

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_get_market_conditions',
          nonce: mldAjax.nonce,
          city: city,
          state: state,
          property_type: this.subjectProperty.property_type || 'all',
          months: 12
        },
        success: (response) => {
          cmaLog('[MLD Market Conditions] AJAX Success! Response:', response);
          cmaLog('[MLD Market Conditions] Response.success:', response?.success);
          cmaLog('[MLD Market Conditions] Response.data:', response?.data);
          if (response.success) {
            cmaLog('[MLD Market Conditions] Calling renderMarketConditions with data');
            this.renderMarketConditions(response.data);
          }
        },
        error: (xhr, status, error) => {
          console.error('[MLD Market Conditions] AJAX Error:', error);
          console.error('[MLD Market Conditions] XHR:', xhr);
          console.error('[MLD Market Conditions] Status:', status);
        }
      });
    }

    /**
     * Render market conditions analysis section
     * @since 6.18.0
     */
    renderMarketConditions(data) {
      cmaLog('[MLD Market Conditions] renderMarketConditions called with:', data);

      // The data parameter IS the response.data object - no need to check for success property
      // success was already verified in loadMarketConditions() before calling this function
      if (!data) {
        cmaLog('[MLD Market Conditions] No data provided, returning early');
        return;
      }

      const marketHealth = data.market_health || {};
      const inventory = data.inventory || {};
      const domTrends = data.days_on_market || {};
      const listSaleRatio = data.list_to_sale_ratio || {};
      const priceTrends = data.price_trends || {};

      cmaLog('[MLD Market Conditions] Extracted data:', {
        marketHealth,
        inventory,
        domTrends,
        listSaleRatio,
        priceTrends
      });

      // Get status color
      const statusColor = marketHealth.status_color || '#6c757d';

      const html = `
        <div class="mld-market-conditions" id="mld-market-conditions">
          <div class="mld-mc-header">
            <h3>📈 Market Conditions Analysis</h3>
            <button class="mld-mc-toggle" aria-expanded="true" aria-controls="mld-mc-content">
              <span class="toggle-text">Hide Details</span>
              <span class="toggle-icon">▼</span>
            </button>
          </div>

          <div class="mld-mc-content" id="mld-mc-content">
            <!-- Market Health Indicator -->
            <div class="mld-mc-health-indicator" style="border-left: 4px solid ${statusColor};">
              <div class="mld-mc-health-status">
                <span class="mld-mc-health-label">Market Status:</span>
                <span class="mld-mc-health-value" style="color: ${statusColor};">${marketHealth.status || 'Unknown'}</span>
              </div>
              <div class="mld-mc-health-summary">${marketHealth.summary || ''}</div>
            </div>

            <!-- Key Metrics Grid -->
            <div class="mld-mc-metrics-grid">
              <!-- Inventory -->
              <div class="mld-mc-metric-card">
                <div class="mld-mc-metric-icon">🏠</div>
                <div class="mld-mc-metric-content">
                  <div class="mld-mc-metric-label">Inventory</div>
                  <div class="mld-mc-metric-value">${inventory.months_of_supply || 'N/A'} months</div>
                  <div class="mld-mc-metric-subtext">${inventory.active_listings || 0} active listings</div>
                </div>
              </div>

              <!-- Days on Market -->
              <div class="mld-mc-metric-card">
                <div class="mld-mc-metric-icon">⏱️</div>
                <div class="mld-mc-metric-content">
                  <div class="mld-mc-metric-label">Avg Days on Market</div>
                  <div class="mld-mc-metric-value">${domTrends.average || 'N/A'} days</div>
                  <div class="mld-mc-metric-subtext">${domTrends.trend?.direction === 'increasing' ? '↑ Increasing' : domTrends.trend?.direction === 'decreasing' ? '↓ Decreasing' : '→ Stable'}</div>
                </div>
              </div>

              <!-- List-to-Sale Ratio -->
              <div class="mld-mc-metric-card">
                <div class="mld-mc-metric-icon">💰</div>
                <div class="mld-mc-metric-content">
                  <div class="mld-mc-metric-label">List-to-Sale Ratio</div>
                  <div class="mld-mc-metric-value">${listSaleRatio.average_percentage || 'N/A'}%</div>
                  <div class="mld-mc-metric-subtext">${listSaleRatio.average >= 1.0 ? 'At or above asking' : 'Below asking'}</div>
                </div>
              </div>

              <!-- Price Appreciation -->
              <div class="mld-mc-metric-card">
                <div class="mld-mc-metric-icon">📊</div>
                <div class="mld-mc-metric-content">
                  <div class="mld-mc-metric-label">Annual Appreciation</div>
                  <div class="mld-mc-metric-value ${priceTrends.annualized_appreciation >= 0 ? 'positive' : 'negative'}">
                    ${priceTrends.annualized_appreciation ? (priceTrends.annualized_appreciation >= 0 ? '+' : '') + priceTrends.annualized_appreciation.toFixed(1) + '%' : 'N/A'}
                  </div>
                  <div class="mld-mc-metric-subtext">${priceTrends.trend_description || ''}</div>
                </div>
              </div>
            </div>

            <!-- Trend Charts Section (Sparklines) -->
            <div class="mld-mc-trends">
              <h4>Monthly Trends</h4>
              <div class="mld-mc-trend-charts">
                ${this.renderSparkline(data.sparklines?.price || [], 'Avg Price', '$')}
                ${this.renderSparkline(data.sparklines?.dom || [], 'Days on Market', '')}
                ${this.renderSparkline(data.sparklines?.ratio || [], 'Sale/List %', '%')}
              </div>
            </div>

            <!-- Market Factors -->
            ${marketHealth.factors && marketHealth.factors.length > 0 ? `
            <div class="mld-mc-factors">
              <h4>Market Factors</h4>
              <ul>
                ${marketHealth.factors.map(f => `<li>${f}</li>`).join('')}
              </ul>
            </div>
            ` : ''}
          </div>
        </div>
      `;

      // Insert after market context or after summary
      const $marketContext = this.resultsContainer.find('.mld-market-context');
      const $summary = this.resultsContainer.find('.mld-comp-summary');

      // Remove existing market conditions if any
      this.resultsContainer.find('.mld-market-conditions').remove();

      if ($marketContext.length) {
        $marketContext.after(html);
      } else if ($summary.length) {
        $summary.after(html);
      } else {
        this.resultsContainer.prepend(html);
      }

      // Setup toggle functionality
      this.setupMarketConditionsToggle();
    }

    /**
     * Render a simple sparkline chart
     * @since 6.18.0
     */
    renderSparkline(data, label, prefix) {
      if (!data || data.length === 0) {
        return `<div class="mld-mc-sparkline-container">
          <div class="mld-mc-sparkline-label">${label}</div>
          <div class="mld-mc-sparkline-empty">No data</div>
        </div>`;
      }

      const values = data.map(d => d.y).filter(v => v !== null && v !== undefined);
      if (values.length === 0) return '';

      const max = Math.max(...values);
      const min = Math.min(...values);
      const range = max - min || 1;

      // Create SVG sparkline
      const width = 150;
      const height = 40;
      const padding = 5;
      const chartWidth = width - padding * 2;
      const chartHeight = height - padding * 2;

      const points = values.map((v, i) => {
        const x = padding + (i / (values.length - 1)) * chartWidth;
        const y = height - padding - ((v - min) / range) * chartHeight;
        return `${x},${y}`;
      }).join(' ');

      const latestValue = values[values.length - 1];
      const formattedValue = prefix === '$' ?
        this.formatPrice(latestValue) :
        (prefix ? latestValue.toFixed(1) + prefix : Math.round(latestValue));

      return `
        <div class="mld-mc-sparkline-container">
          <div class="mld-mc-sparkline-label">${label}</div>
          <svg class="mld-mc-sparkline" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
            <polyline
              fill="none"
              stroke="#2c5aa0"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              points="${points}"
            />
          </svg>
          <div class="mld-mc-sparkline-value">${formattedValue}</div>
        </div>
      `;
    }

    /**
     * Setup toggle for market conditions section
     * @since 6.18.0
     */
    setupMarketConditionsToggle() {
      $(document).on('click', '.mld-mc-toggle', function() {
        const $button = $(this);
        const $content = $('#mld-mc-content');
        const isExpanded = $button.attr('aria-expanded') === 'true';

        if (isExpanded) {
          $content.slideUp(200);
          $button.attr('aria-expanded', 'false');
          $button.find('.toggle-text').text('Show Details');
          $button.find('.toggle-icon').text('▶');
        } else {
          $content.slideDown(200);
          $button.attr('aria-expanded', 'true');
          $button.find('.toggle-text').text('Hide Details');
          $button.find('.toggle-icon').text('▼');
        }
      });
    }

    /**
     * Load value history trend for the subject property
     * @since 6.20.0
     */
    loadValueHistory() {
      const listingId = this.subjectProperty.listing_id;

      if (!listingId) {
        cmaLog('[MLD Value History] No listing_id available');
        return;
      }

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_get_cma_value_trend',
          nonce: mldAjax.nonce,
          listing_id: listingId,
          months: 12
        },
        success: (response) => {
          if (response.success && response.data.has_history) {
            cmaLog('[MLD Value History] Loaded trend data:', response.data);
            this.renderValueHistory(response.data);
          } else {
            cmaLog('[MLD Value History] No history available');
          }
        },
        error: (xhr, status, error) => {
          console.error('[MLD Value History] Failed to load:', error);
        }
      });
    }

    /**
     * Render value history section
     * @since 6.20.0
     */
    renderValueHistory(data) {
      const { data_points, summary, statistics } = data;

      if (!data_points || data_points.length < 2) {
        return; // Need at least 2 data points for trend
      }

      // Determine trend direction
      const trendIcon = summary.trend_direction === 'up' ? '📈' : (summary.trend_direction === 'down' ? '📉' : '➡️');
      const trendColor = summary.trend_direction === 'up' ? '#28a745' : (summary.trend_direction === 'down' ? '#dc3545' : '#6c757d');
      const trendSign = summary.value_change >= 0 ? '+' : '';

      // Generate mini chart
      const chartHtml = this.renderValueHistoryChart(data_points);

      const html = `
        <div class="mld-value-history" style="margin-top: 1.5rem; padding: 1rem; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; border: 1px solid #dee2e6;">
          <div class="mld-vh-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h4 style="margin: 0; font-size: 1rem; color: #2c5aa0;">
              ${trendIcon} Value History
            </h4>
            <span style="font-size: 0.8rem; color: #6c757d;">
              ${summary.total_assessments} assessments
            </span>
          </div>

          <div class="mld-vh-chart" style="margin-bottom: 1rem;">
            ${chartHtml}
          </div>

          <div class="mld-vh-summary" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.8rem; font-size: 0.85rem;">
            <div style="text-align: center; padding: 0.5rem; background: #fff; border-radius: 4px;">
              <div style="color: #6c757d; font-size: 0.7rem;">First Value</div>
              <div style="font-weight: 600;">${this.formatPrice(summary.first_value)}</div>
              <div style="color: #6c757d; font-size: 0.65rem;">${data_points[0].date_formatted}</div>
            </div>
            <div style="text-align: center; padding: 0.5rem; background: #fff; border-radius: 4px;">
              <div style="color: #6c757d; font-size: 0.7rem;">Latest Value</div>
              <div style="font-weight: 600;">${this.formatPrice(summary.last_value)}</div>
              <div style="color: #6c757d; font-size: 0.65rem;">${data_points[data_points.length - 1].date_formatted}</div>
            </div>
            <div style="text-align: center; padding: 0.5rem; background: #fff; border-radius: 4px;">
              <div style="color: #6c757d; font-size: 0.7rem;">Change</div>
              <div style="font-weight: 600; color: ${trendColor};">
                ${trendSign}${this.formatPrice(Math.abs(summary.value_change))}
              </div>
              <div style="color: ${trendColor}; font-size: 0.65rem;">${trendSign}${summary.value_change_pct}%</div>
            </div>
          </div>

          ${statistics && statistics.has_data ? `
          <div class="mld-vh-stats" style="margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid #dee2e6; font-size: 0.75rem; color: #6c757d;">
            <span title="Range of all historical valuations">Range: ${this.formatPrice(statistics.min_value)} - ${this.formatPrice(statistics.max_value)}</span>
            <span style="margin-left: 1rem;" title="Average confidence across all assessments">Avg Confidence: ${statistics.avg_confidence}%</span>
          </div>
          ` : ''}
        </div>
      `;

      // Insert after market conditions or at end of summary section
      const $marketConditions = $('.mld-market-conditions');
      if ($marketConditions.length) {
        $marketConditions.after(html);
      } else {
        $('.mld-cma-summary-section').append(html);
      }
    }

    /**
     * Render value history SVG chart
     * @since 6.20.0
     */
    renderValueHistoryChart(dataPoints) {
      if (!dataPoints || dataPoints.length < 2) {
        return '<div style="text-align: center; color: #6c757d; padding: 1rem;">Not enough data for chart</div>';
      }

      const width = 300;
      const height = 80;
      const padding = { top: 10, right: 10, bottom: 20, left: 10 };
      const chartWidth = width - padding.left - padding.right;
      const chartHeight = height - padding.top - padding.bottom;

      // Get value range
      const values = dataPoints.map(d => d.value);
      const minValue = Math.min(...values);
      const maxValue = Math.max(...values);
      const valueRange = maxValue - minValue || 1;

      // Calculate points
      const points = dataPoints.map((d, i) => {
        const x = padding.left + (i / (dataPoints.length - 1)) * chartWidth;
        const y = padding.top + chartHeight - ((d.value - minValue) / valueRange) * chartHeight;
        return `${x},${y}`;
      }).join(' ');

      // Area path
      const areaPoints = [
        `${padding.left},${padding.top + chartHeight}`,
        ...dataPoints.map((d, i) => {
          const x = padding.left + (i / (dataPoints.length - 1)) * chartWidth;
          const y = padding.top + chartHeight - ((d.value - minValue) / valueRange) * chartHeight;
          return `${x},${y}`;
        }),
        `${padding.left + chartWidth},${padding.top + chartHeight}`
      ].join(' ');

      // Determine line color based on trend
      const firstValue = dataPoints[0].value;
      const lastValue = dataPoints[dataPoints.length - 1].value;
      const lineColor = lastValue >= firstValue ? '#28a745' : '#dc3545';
      const fillColor = lastValue >= firstValue ? 'rgba(40, 167, 69, 0.1)' : 'rgba(220, 53, 69, 0.1)';

      return `
        <svg width="100%" height="${height}" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMidYMid meet">
          <!-- Area fill -->
          <polygon points="${areaPoints}" fill="${fillColor}" />

          <!-- Line -->
          <polyline
            points="${points}"
            fill="none"
            stroke="${lineColor}"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />

          <!-- Data points -->
          ${dataPoints.map((d, i) => {
            const x = padding.left + (i / (dataPoints.length - 1)) * chartWidth;
            const y = padding.top + chartHeight - ((d.value - minValue) / valueRange) * chartHeight;
            return `<circle cx="${x}" cy="${y}" r="3" fill="${lineColor}" stroke="#fff" stroke-width="1">
              <title>${d.date_formatted}: ${this.formatPrice(d.value)}</title>
            </circle>`;
          }).join('')}

          <!-- X-axis labels -->
          <text x="${padding.left}" y="${height - 2}" font-size="8" fill="#6c757d">${dataPoints[0].date_formatted}</text>
          <text x="${width - padding.right}" y="${height - 2}" font-size="8" fill="#6c757d" text-anchor="end">${dataPoints[dataPoints.length - 1].date_formatted}</text>
        </svg>
      `;
    }

    renderComparable(comp) {
      const gradeClass = `grade-${comp.comparability_grade || 'C'}`;
      const imageUrl =
        comp.main_photo_url ||
        'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300"%3E%3Crect fill="%23e9ecef" width="400" height="300"/%3E%3Ctext fill="%23adb5bd" font-family="sans-serif" font-size="24" dy="150" dx="120" text-anchor="middle"%3ENo Image%3C/text%3E%3C/svg%3E';
      const isFavorited = this.favorites.includes(comp.listing_id);
      const isSelected = this.selectedComparables.includes(String(comp.listing_id));

      // Check subject property characteristics to determine what controls to show
      const subjectRoadType = this.subjectProperty.road_type || this.subjectProperty.roadType || 'unknown';
      const subjectCondition = this.subjectProperty.property_condition || this.subjectProperty.propertyCondition || 'unknown';
      // Always show controls for subject property itself, only hide for comparables when subject is unknown
      const showRoadType = comp.is_subject || subjectRoadType !== 'unknown';
      const showCondition = comp.is_subject || subjectCondition !== 'unknown';

      return `
            <div class="mld-comp-card ${gradeClass} ${isSelected ? 'selected' : ''}" data-listing-id="${comp.listing_id}">
                <div class="mld-comp-checkbox ${isSelected ? 'checked' : ''}">
                    <input type="checkbox" class="mld-comp-compare-checkbox" data-listing-id="${comp.listing_id}" ${isSelected ? 'checked' : ''}>
                </div>
                <button class="mld-comp-favorite ${isFavorited ? 'favorited' : ''}" data-listing-id="${comp.listing_id}" title="${isFavorited ? 'Remove from favorites' : 'Add to favorites'}">
                    <span class="star-icon">⭐</span>
                </button>
                <div class="mld-comp-grade ${gradeClass}">${
        comp.comparability_grade || 'C'
      }</div>

                <a href="${
                  comp.property_url || '#'
                }" target="_blank" rel="noopener noreferrer" class="mld-comp-image-link">
                    <img src="${imageUrl}" alt="${
        comp.unparsed_address || 'Property'
      }" class="mld-comp-image"
                         onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22%3E%3Crect fill=%22%23e9ecef%22 width=%22400%22 height=%22300%22/%3E%3C/svg%3E'">
                </a>

                <div class="mld-comp-content">
                    <div class="mld-comp-address">${
                      comp.unparsed_address || 'Address Not Available'
                    }</div>

                    <div class="mld-comp-price">
                        ${this.formatPrice(comp.close_price || comp.list_price)}
                        ${
                          comp.standard_status !== 'Closed'
                            ? `<span style="font-size: 0.7em; color: #6c757d;">(${this.formatStatusLabel(comp.standard_status)})</span>`
                            : ''
                        }
                    </div>

                    ${comp.price_per_sqft > 0 ? `
                    <div class="mld-comp-price-per-sqft">
                        <span class="mld-ppsf-label">$${comp.price_per_sqft.toFixed(2)}/sqft</span>
                        ${comp.price_per_sqft_diff_pct !== 0 ? `
                            <span class="mld-ppsf-diff ${comp.price_per_sqft_diff_pct > 0 ? 'positive' : 'negative'}">
                                ${comp.price_per_sqft_diff_pct > 0 ? '+' : ''}${comp.price_per_sqft_diff_pct.toFixed(1)}%
                            </span>
                        ` : ''}
                    </div>
                    ` : ''}

                    <div class="mld-comp-details">
                        <span>${comp.bedrooms_total || 0} beds</span>
                        <span>•</span>
                        <span>${comp.bathrooms_total || 0} baths</span>
                        <span>•</span>
                        <span>${this.formatNumber(
                          comp.building_area_total || 0
                        )} sqft</span>
                        ${
                          comp.year_built
                            ? `<span>•</span><span>Built ${comp.year_built}</span>`
                            : ''
                        }
                    </div>

                    ${
                      comp.distance_miles
                        ? `<div class="mld-comp-distance">${comp.distance_miles.toFixed(
                            2
                          )} miles away</div>`
                        : ''
                    }

                    ${
                      comp.adjustments
                        ? this.renderAdjustments(
                            comp.adjustments,
                            comp.adjusted_price
                          )
                        : ''
                    }

                    ${showRoadType || showCondition ? `
                    <div class="mld-property-characteristics" style="margin-top: 1rem; padding: 0.8rem; background: #f8f9fa; border-radius: 4px; font-size: 0.75rem;">
                        ${showRoadType ? `
                        <div style="margin-bottom: ${showCondition ? '0.7rem' : '0'};">
                            <label style="display: block; font-weight: 600; color: #495057; margin-bottom: 0.3rem;">Road Type:</label>
                            <div style="display: flex; gap: 0.7rem; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="road_type_${comp.listing_id}" value="unknown" data-listing-id="${comp.listing_id}" class="mld-road-type-input" ${!comp.road_type || comp.road_type === 'unknown' || comp.road_type === '' ? 'checked' : ''}>
                                    <span>Unknown</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="road_type_${comp.listing_id}" value="main_road" data-listing-id="${comp.listing_id}" class="mld-road-type-input" ${comp.road_type === 'main_road' ? 'checked' : ''}>
                                    <span>Main Road</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="road_type_${comp.listing_id}" value="neighborhood_road" data-listing-id="${comp.listing_id}" class="mld-road-type-input" ${comp.road_type === 'neighborhood_road' ? 'checked' : ''}>
                                    <span>Neighborhood</span>
                                </label>
                            </div>
                        </div>
                        ` : ''}
                        ${showCondition ? `
                        <div>
                            <label style="display: block; font-weight: 600; color: #495057; margin-bottom: 0.3rem;">Condition:</label>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="unknown" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${!comp.property_condition || comp.property_condition === 'unknown' || comp.property_condition === '' ? 'checked' : ''}>
                                    <span>Unknown</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="new" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${comp.property_condition === 'new' ? 'checked' : ''}>
                                    <span>New Construction</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="fully_renovated" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${comp.property_condition === 'fully_renovated' ? 'checked' : ''}>
                                    <span>Fully Renovated</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="some_updates" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${comp.property_condition === 'some_updates' ? 'checked' : ''}>
                                    <span>Some Updates</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="needs_updating" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${comp.property_condition === 'needs_updating' ? 'checked' : ''}>
                                    <span>Needs Updating</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <input type="radio" name="condition_${comp.listing_id}" value="distressed" data-listing-id="${comp.listing_id}" class="mld-condition-input" ${comp.property_condition === 'distressed' ? 'checked' : ''}>
                                    <span>Distressed</span>
                                </label>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    ` : ''}

                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #dee2e6; font-size: 0.9rem; color: #6c757d;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Comparability Score:</span>
                            <strong style="color: #2c5aa0;">${
                              comp.comparability_score || 0
                            }/100</strong>
                        </div>
                        ${
                          comp.close_date
                            ? `<div>Sold: ${this.formatDate(
                                comp.close_date
                              )}</div>`
                            : ''
                        }
                    </div>

                    <!-- Weight Controls (v6.19.0) -->
                    <div class="mld-weight-controls" style="margin-top: 0.8rem; padding: 0.6rem; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border-radius: 6px; border: 1px solid #d0d9e4;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="font-size: 0.75rem; font-weight: 600; color: #495057;">Weighting:</span>
                            <span class="mld-weight-value" data-listing-id="${comp.listing_id}" style="font-size: 0.8rem; font-weight: 700; color: #2c5aa0;">
                                ${comp.weight_override !== null ? comp.weight_override + 'x (manual)' : comp.weight + 'x'}
                            </span>
                        </div>
                        <div class="mld-weight-buttons" style="display: flex; gap: 0.3rem; flex-wrap: wrap;" data-listing-id="${comp.listing_id}" data-current-weight="${comp.weight_override !== null ? comp.weight_override : comp.weight}" data-default-weight="${comp.weight}">
                            <button type="button" class="mld-weight-btn ${(comp.weight_override !== null ? comp.weight_override : comp.weight) === 0.25 ? 'active' : ''}" data-weight="0.25" title="Very Low Weight (0.25x)">−−</button>
                            <button type="button" class="mld-weight-btn ${(comp.weight_override !== null ? comp.weight_override : comp.weight) === 0.5 ? 'active' : ''}" data-weight="0.5" title="Low Weight (0.5x)">−</button>
                            <button type="button" class="mld-weight-btn ${(comp.weight_override !== null ? comp.weight_override : comp.weight) === 1 ? 'active' : ''}" data-weight="1" title="Normal Weight (1x)">1x</button>
                            <button type="button" class="mld-weight-btn ${(comp.weight_override !== null ? comp.weight_override : comp.weight) === 1.5 ? 'active' : ''}" data-weight="1.5" title="High Weight (1.5x)">+</button>
                            <button type="button" class="mld-weight-btn ${(comp.weight_override !== null ? comp.weight_override : comp.weight) === 2 ? 'active' : ''}" data-weight="2" title="Very High Weight (2x)">++</button>
                            <button type="button" class="mld-weight-reset-btn" data-listing-id="${comp.listing_id}" title="Reset to auto weight (${comp.weight}x based on grade ${comp.comparability_grade})" ${comp.weight_override === null ? 'disabled' : ''}>↺</button>
                        </div>
                        <div style="font-size: 0.65rem; color: #6c757d; margin-top: 0.4rem;">
                            Auto weight based on Grade ${comp.comparability_grade}: ${comp.weight}x
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    renderAdjustments(adjustments, adjustedPrice) {
      if (
        !adjustments ||
        !adjustments.items ||
        adjustments.items.length === 0
      ) {
        return '';
      }

      let html = `
            <div class="mld-comp-adjustments">
                <div class="mld-comp-adjustments-header" data-toggle="adjustments">
                    Price Adjustments
                    <span style="font-size: 0.85em; color: #6c757d;">▼</span>
                </div>
                <div class="mld-adjustment-list" style="display: none;">
        `;

      adjustments.items.forEach((item) => {
        const className = item.adjustment > 0
          ? 'mld-adjustment-positive'
          : item.adjustment < 0
          ? 'mld-adjustment-negative'
          : '';

        html += `
                    <div class="mld-adjustment-item">
                        <span>${item.feature}</span>
                        <span class="${className}">${this.formatAdjustment(
          item.adjustment
        )}</span>
                    </div>
            `;
      });

      const totalClass = adjustments.total_adjustment > 0
        ? 'mld-adjustment-positive'
        : adjustments.total_adjustment < 0
        ? 'mld-adjustment-negative'
        : '';

      html += `
                    <div class="mld-adjustment-item">
                        <span><strong>Total Adjustment</strong></span>
                        <span class="${totalClass}"><strong>${this.formatAdjustment(
        adjustments.total_adjustment
      )}</strong></span>
                    </div>
                    <div class="mld-adjustment-item">
                        <span><strong>Adjusted Price</strong></span>
                        <span style="color: #2c5aa0;"><strong>${this.formatPrice(
                          adjustedPrice
                        )}</strong></span>
                    </div>
                </div>
            </div>
        `;

      return html;
    }

    setupAdjustmentToggles() {
      // Use event delegation so handlers persist after DOM updates
      $(document).off('click', '[data-toggle="adjustments"]').on('click', '[data-toggle="adjustments"]', function () {
        const $list = $(this).next('.mld-adjustment-list');
        $list.slideToggle(200);
        const icon = $(this).find('span').last();
        icon.text($list.is(':visible') ? '▲' : '▼');
      });
    }

    // ============================================
    // UX Enhancement Methods
    // ============================================

    setupMapToggle() {
      $('#mld-map-toggle-btn').on('click', () => {
        this.mapVisible = !this.mapVisible;
        const $container = $('#mld-comp-map-container');
        const $btn = $('#mld-map-toggle-btn');

        if (this.mapVisible) {
          $container.slideDown(300, () => {
            this.renderMap();
          });
          $btn.addClass('active');
        } else {
          $container.slideUp(300);
          $btn.removeClass('active');
        }
      });
    }

    async renderMap() {
      if (!window.google?.maps) {
        console.error('Google Maps API not loaded');
        return;
      }

      const mapDiv = document.getElementById('mld-comp-map');
      if (!mapDiv) return;

      // Initialize map and load marker library if not already created
      if (!this.map) {
        try {
          // Import marker library for AdvancedMarkerElement
          const markerLibrary = await google.maps.importLibrary('marker');
          this.AdvancedMarkerElement = markerLibrary.AdvancedMarkerElement;

          this.map = new google.maps.Map(mapDiv, {
            center: { lat: this.subjectProperty.lat || 42.5795, lng: this.subjectProperty.lng || -71.0781 },
            zoom: 13,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            mapId: 'BME_MAP_ID' // Use same map ID as search page for AdvancedMarkerElement
          });
          this.mapMarkers = [];
          this.infoWindow = new google.maps.InfoWindow();
        } catch (error) {
          console.error('Failed to initialize map:', error);
          return;
        }
      }

      // Clear existing markers
      this.mapMarkers.forEach(({ marker }) => {
        if (marker.map) marker.map = null;
      });
      this.mapMarkers = [];

      const bounds = new google.maps.LatLngBounds();

      // Add subject property marker with distinctive styling
      if (this.subjectProperty.lat && this.subjectProperty.lng) {
        const subjectMarker = this.createPriceMarker(
          this.subjectProperty.lat,
          this.subjectProperty.lng,
          this.subjectProperty.price || 0,
          'Subject Property',
          true, // isSubject flag
          {
            unparsed_address: this.subjectProperty.address || 'Subject Property',
            price: this.subjectProperty.price || 0,
            bedrooms_total: this.subjectProperty.beds || 0,
            bathrooms_total: this.subjectProperty.baths || 0,
            building_area_total: this.subjectProperty.sqft || 0,
            comparability_grade: 'Subject'
          }
        );
        if (subjectMarker) {
          bounds.extend({ lat: this.subjectProperty.lat, lng: this.subjectProperty.lng });
        }
      }

      // Add comparable property markers
      this.filteredComparables.forEach(comp => {
        if (comp.latitude && comp.longitude) {
          const lat = parseFloat(comp.latitude);
          const lng = parseFloat(comp.longitude);
          const price = comp.close_price || comp.list_price || 0;

          const marker = this.createPriceMarker(
            lat,
            lng,
            price,
            comp.unparsed_address || 'Property',
            false, // not subject
            comp
          );

          if (marker) {
            bounds.extend({ lat, lng });
          }
        }
      });

      // Fit map to show all markers
      if (this.mapMarkers.length > 0) {
        this.map.fitBounds(bounds);
        // Zoom out slightly if only one marker
        if (this.mapMarkers.length === 1) {
          this.map.setZoom(15);
        }
      }
    }

    /**
     * Creates a price marker using the exact same approach as map-markers.js
     * @param {number} lat - Latitude
     * @param {number} lng - Longitude
     * @param {number} price - Property price
     * @param {string} title - Marker title
     * @param {boolean} isSubject - Whether this is the subject property
     * @param {object} propertyData - Full property data for popup
     * @returns {object} Marker object
     */
    createPriceMarker(lat, lng, price, title, isSubject, propertyData) {
      // Create the price pin element (exactly like map-markers.js)
      const el = document.createElement('div');

      // Use the exact same classes as the map search
      el.className = isSubject ? 'bme-unit-cluster-marker' : 'bme-price-marker';
      el.textContent = this.formatPrice(price);

      // Add click handler
      el.onclick = (e) => {
        e.stopPropagation();
        this.showMarkerPopup(propertyData, lat, lng);

        // Highlight the corresponding card
        if (propertyData.listing_id) {
          $(`.mld-comp-card[data-listing-id="${propertyData.listing_id}"]`).addClass('highlight');
          setTimeout(() => {
            $(`.mld-comp-card[data-listing-id="${propertyData.listing_id}"]`).removeClass('highlight');
          }, 2000);
        }
      };

      // Create marker element (exactly like map-markers.js createMarkerElement function)
      const marker = this.createMarkerElement(
        el,
        lng,
        lat,
        propertyData.listing_id || 'subject',
        propertyData,
        isSubject ? 5 : 2  // Higher z-index for subject
      );

      return marker;
    }

    /**
     * Creates a marker element using AdvancedMarkerElement (matching map-markers.js)
     */
    createMarkerElement(element, lng, lat, id, data, zIndex = 2) {
      if (!this.AdvancedMarkerElement) {
        console.error('AdvancedMarkerElement not loaded');
        return null;
      }

      const marker = new this.AdvancedMarkerElement({
        position: { lat, lng },
        map: this.map,
        content: element,
        zIndex
      });

      // Store marker reference
      this.mapMarkers.push({
        marker,
        element,
        data,
        id,
        baseZIndex: zIndex
      });

      return marker;
    }

    /**
     * Shows an info window popup for a marker
     */
    showMarkerPopup(propertyData, lat, lng) {
      const grade = propertyData.comparability_grade || 'C';
      const gradeClass = grade === 'Subject' ? 'subject' : grade;

      const content = `
        <div class="mld-map-popup">
          <div class="mld-map-popup-address">${propertyData.unparsed_address || 'Address Not Available'}</div>
          <div class="mld-map-popup-price">${this.formatPrice(propertyData.close_price || propertyData.price || 0)}</div>
          <div class="mld-map-popup-details">
            ${propertyData.bedrooms_total || 0} beds •
            ${propertyData.bathrooms_total || 0} baths •
            ${this.formatNumber(propertyData.building_area_total || 0)} sqft
          </div>
          ${grade !== 'Subject' ? `<div class="mld-map-popup-grade ${gradeClass}">Grade: ${grade} (${propertyData.comparability_score || 0}/100)</div>` : '<div class="mld-map-popup-grade subject"><strong>Subject Property</strong></div>'}
        </div>
      `;

      this.infoWindow.setContent(content);
      this.infoWindow.setPosition({ lat, lng });
      this.infoWindow.open(this.map);
    }

    getGradeColor(grade) {
      const colors = {
        'A': '#28a745',
        'B': '#5cb85c',
        'C': '#ffc107',
        'D': '#fd7e14',
        'F': '#dc3545'
      };
      return colors[grade] || colors['C'];
    }

    setupFavoriteButtons() {
      $('.mld-comp-favorite').on('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(e.currentTarget);
        const listingId = $btn.data('listing-id');

        this.toggleFavorite(listingId);
        $btn.toggleClass('favorited');
        $btn.attr('title', $btn.hasClass('favorited') ? 'Remove from favorites' : 'Add to favorites');
      });
    }

    toggleFavorite(listingId) {
      const index = this.favorites.indexOf(listingId);

      if (index > -1) {
        this.favorites.splice(index, 1);
      } else {
        this.favorites.push(listingId);
      }

      this.saveFavoritesToSession();
    }

    loadFavoritesFromSession() {
      try {
        const saved = sessionStorage.getItem('mld_comp_favorites');
        return saved ? JSON.parse(saved) : [];
      } catch (e) {
        return [];
      }
    }

    saveFavoritesToSession() {
      try {
        sessionStorage.setItem('mld_comp_favorites', JSON.stringify(this.favorites));
      } catch (e) {
        console.error('Failed to save favorites to session storage');
      }
    }

    setupPagination() {
      $('#mld-page-prev').on('click', () => {
        if (this.currentPage > 1) {
          this.currentPage--;
          this.renderCurrentPage();
        }
      });

      $('#mld-page-next').on('click', () => {
        const totalPages = Math.ceil(this.filteredComparables.length / this.itemsPerPage);
        if (this.currentPage < totalPages) {
          this.currentPage++;
          this.renderCurrentPage();
        }
      });
    }

    updatePagination() {
      const totalPages = Math.ceil(this.filteredComparables.length / this.itemsPerPage);

      if (totalPages <= 1) {
        $('#mld-comp-pagination').hide();
        return;
      }

      $('#mld-comp-pagination').show();
      $('#current-page').text(this.currentPage);
      $('#total-pages').text(totalPages);

      $('#mld-page-prev').prop('disabled', this.currentPage === 1);
      $('#mld-page-next').prop('disabled', this.currentPage === totalPages);
    }

    renderCurrentPage() {
      const startIdx = (this.currentPage - 1) * this.itemsPerPage;
      const endIdx = startIdx + this.itemsPerPage;
      const pageComps = this.filteredComparables.slice(startIdx, endIdx);

      const html = pageComps.map(comp => this.renderComparable(comp)).join('');
      $('.mld-comp-grid').html(html);

      this.updatePagination();
      this.setupAdjustmentToggles();
      this.setupCompareCheckboxes();
      this.setupFavoriteButtons();

      // Scroll to top of results
      $('html, body').animate({
        scrollTop: this.resultsContainer.offset().top - 100
      }, 300);
    }

    setupShareButton() {
      $('#mld-share-btn').on('click', () => {
        this.showShareModal();
      });
    }

    showShareModal() {
      const modalHtml = `
        <div id="mld-share-modal" class="mld-modal" style="display:none;">
          <div class="mld-modal-overlay"></div>
          <div class="mld-modal-content">
            <div class="mld-modal-header">
              <h3>📤 Share or Print</h3>
              <button class="mld-modal-close">&times;</button>
            </div>
            <div class="mld-modal-body mld-share-modal-body">
              <div class="mld-share-options">
                <div class="mld-share-option" data-action="print">
                  <div class="mld-share-option-icon">🖨️</div>
                  <div class="mld-share-option-label">Print Results</div>
                </div>
                <div class="mld-share-option" data-action="copy-link">
                  <div class="mld-share-option-icon">🔗</div>
                  <div class="mld-share-option-label">Copy Link</div>
                </div>
                <div class="mld-share-option" data-action="email">
                  <div class="mld-share-option-icon">✉️</div>
                  <div class="mld-share-option-label">Share via Email</div>
                </div>
                <div class="mld-share-option" data-action="pdf">
                  <div class="mld-share-option-icon">📄</div>
                  <div class="mld-share-option-label">Export to PDF</div>
                </div>
              </div>

              <div class="mld-share-link-container">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.85rem;">Shareable Link:</label>
                <input type="text" class="mld-share-link-input" id="mld-share-link" value="${window.location.href}" readonly>
                <button class="mld-share-copy-btn" id="mld-copy-link-btn">Copy to Clipboard</button>
              </div>
            </div>
          </div>
        </div>
      `;

      $('body').append(modalHtml);
      $('#mld-share-modal').stop(true, true).fadeIn(300);

      // Setup share option handlers
      $('.mld-share-option').on('click', (e) => {
        const action = $(e.currentTarget).data('action');
        this.handleShareAction(action);
      });

      // Setup copy button
      $('#mld-copy-link-btn').on('click', () => {
        const input = document.getElementById('mld-share-link');
        input.select();
        document.execCommand('copy');

        const $btn = $('#mld-copy-link-btn');
        $btn.addClass('copied').text('✓ Copied!');

        setTimeout(() => {
          $btn.removeClass('copied').text('Copy to Clipboard');
        }, 2000);
      });
    }

    handleShareAction(action) {
      switch(action) {
        case 'print':
          window.print();
          break;

        case 'copy-link':
          const input = document.getElementById('mld-share-link');
          input.select();
          document.execCommand('copy');
          alert('Link copied to clipboard!');
          break;

        case 'email':
          const subject = encodeURIComponent('Comparable Properties Analysis');
          const body = encodeURIComponent(`Check out these comparable properties:\n\n${window.location.href}`);
          window.location.href = `mailto:?subject=${subject}&body=${body}`;
          break;

        case 'pdf':
          this.generateCMAPDF();
          break;
      }
    }

    // ============================================
    // ARV (After Repair Value) Methods
    // ============================================

    setupARVModal() {
      const self = this;

      // ARV button click
      $('#mld-arv-btn').on('click', () => {
        this.openARVModal();
      });

      // Close modal
      $('#mld-arv-close, #mld-arv-modal .mld-modal-overlay').on('click', () => {
        this.closeARVModal();
      });

      // Apply button
      $('#arv-apply').on('click', () => {
        this.applyARVAdjustments();
      });

      // Reset button
      $('#arv-reset').on('click', () => {
        this.resetARVAdjustments();
      });

      // Edit buttons focus their input
      $('.mld-arv-edit-btn').on('click', function() {
        const field = $(this).data('field');
        const inputId = field === 'year_built' ? '#arv-year-built' :
                        field === 'garage_spaces' ? '#arv-garage' :
                        `#arv-${field}`;
        $(inputId).focus().select();
      });

      // Pool toggle label update
      $('#arv-pool').on('change', function() {
        $('#arv-pool-label').text(this.checked ? 'Yes' : 'No');
      });

      // Track field modifications
      $('#mld-arv-modal input, #mld-arv-modal select').on('change', function() {
        self.checkARVFieldModified($(this));
      });
    }

    openARVModal() {
      // Store original if not already stored
      if (!this.originalSubjectProperty) {
        this.originalSubjectProperty = { ...this.subjectProperty };
      }

      // Populate modal fields with current values
      $('#arv-beds').val(this.subjectProperty.beds || 0);
      $('#arv-baths').val(this.subjectProperty.baths || 0);
      $('#arv-sqft').val(this.subjectProperty.sqft || 0);
      $('#arv-year-built').val(this.subjectProperty.year_built || new Date().getFullYear());
      $('#arv-garage').val(this.subjectProperty.garage_spaces || 0);
      $('#arv-pool').prop('checked', this.subjectProperty.pool || false);
      $('#arv-pool-label').text(this.subjectProperty.pool ? 'Yes' : 'No');
      $('#arv-condition').val(this.subjectProperty.property_condition || 'unknown');

      // Show original values
      $('#arv-beds-original').text(`Original: ${this.originalSubjectProperty.beds || 0}`);
      $('#arv-baths-original').text(`Original: ${this.originalSubjectProperty.baths || 0}`);
      $('#arv-sqft-original').text(`Original: ${this.formatNumber(this.originalSubjectProperty.sqft || 0)}`);
      $('#arv-year-built-original').text(`Original: ${this.originalSubjectProperty.year_built || '--'}`);
      $('#arv-garage-original').text(`Original: ${this.originalSubjectProperty.garage_spaces || 0}`);
      $('#arv-pool-original').text(`Original: ${this.originalSubjectProperty.pool ? 'Yes' : 'No'}`);
      $('#arv-condition-original').text(`Original: ${this.formatConditionLabel(this.originalSubjectProperty.property_condition)}`);

      // Show/hide ARV indicator
      if (this.isARVMode) {
        $('#mld-arv-indicator').show();
      } else {
        $('#mld-arv-indicator').hide();
      }

      // Check which fields are modified
      this.updateARVFieldModifications();

      // Show modal - stop() clears any pending animation to prevent getting stuck
      $('#mld-arv-modal').stop(true, true).fadeIn(300);
    }

    closeARVModal() {
      // stop() clears any pending animation to prevent getting stuck
      $('#mld-arv-modal').stop(true, true).fadeOut(300);
    }

    applyARVAdjustments() {
      // Store original if not already stored
      if (!this.originalSubjectProperty) {
        this.originalSubjectProperty = { ...this.subjectProperty };
      }

      // Get values from modal
      const newValues = {
        beds: parseInt($('#arv-beds').val()) || 0,
        baths: parseFloat($('#arv-baths').val()) || 0,
        sqft: parseInt($('#arv-sqft').val()) || 0,
        year_built: parseInt($('#arv-year-built').val()) || new Date().getFullYear(),
        garage_spaces: parseInt($('#arv-garage').val()) || 0,
        pool: $('#arv-pool').prop('checked'),
        property_condition: $('#arv-condition').val()
      };

      // Store ARV overrides
      this.arvOverrides = { ...newValues };

      // Update subject property with new values
      Object.assign(this.subjectProperty, newValues);

      // Check if any values actually changed
      this.isARVMode = this.hasARVChanges();

      // Update ARV button state
      this.updateARVButtonState();

      // Close modal
      this.closeARVModal();

      // Recalculate comparables with new values
      this.loadComparables();
    }

    resetARVAdjustments() {
      if (!this.originalSubjectProperty) return;

      // Restore original values
      this.subjectProperty = { ...this.originalSubjectProperty };
      this.arvOverrides = null;
      this.isARVMode = false;

      // Update modal fields
      this.openARVModal();

      // Update ARV button state
      this.updateARVButtonState();
    }

    hasARVChanges() {
      if (!this.originalSubjectProperty) return false;

      const fields = ['beds', 'baths', 'sqft', 'year_built', 'garage_spaces', 'pool', 'property_condition'];
      return fields.some(field => {
        return this.subjectProperty[field] !== this.originalSubjectProperty[field];
      });
    }

    updateARVButtonState() {
      const $btn = $('#mld-arv-btn');
      if (this.isARVMode) {
        $btn.addClass('arv-active');
        $btn.html('<span class="arv-icon">🔧</span> ARV Active');
      } else {
        $btn.removeClass('arv-active');
        $btn.html('<span class="arv-icon">🔧</span> Adjust Details');
      }
    }

    checkARVFieldModified($input) {
      if (!this.originalSubjectProperty) return;

      const $fieldInput = $input.closest('.mld-arv-field-input');
      const fieldId = $input.attr('id');

      const fieldMap = {
        'arv-beds': 'beds',
        'arv-baths': 'baths',
        'arv-sqft': 'sqft',
        'arv-year-built': 'year_built',
        'arv-garage': 'garage_spaces',
        'arv-pool': 'pool',
        'arv-condition': 'property_condition'
      };

      const field = fieldMap[fieldId];
      if (!field) return;

      let currentValue = $input.is(':checkbox') ? $input.prop('checked') : $input.val();
      let originalValue = this.originalSubjectProperty[field];

      // Type conversion for comparison
      if (typeof originalValue === 'number') {
        currentValue = parseFloat(currentValue);
      }

      if (currentValue !== originalValue) {
        $fieldInput.addClass('modified');
      } else {
        $fieldInput.removeClass('modified');
      }
    }

    updateARVFieldModifications() {
      $('#mld-arv-modal input, #mld-arv-modal select').each((i, el) => {
        this.checkARVFieldModified($(el));
      });
    }

    formatConditionLabel(condition) {
      const labels = {
        'unknown': 'Unknown',
        'new': 'New Construction',
        'fully_renovated': 'Fully Renovated',
        'some_updates': 'Some Updates',
        'needs_updating': 'Needs Updating',
        'distressed': 'Distressed'
      };
      return labels[condition] || condition || 'Unknown';
    }

    // ============================================
    // Save/Load CMA Session Methods
    // ============================================

    setupSaveLoadCMA() {
      // Only setup if user is logged in
      if (!window.mldUserLoggedIn) return;

      const self = this;

      // Save button
      $('#mld-save-cma-btn').on('click', () => {
        this.openSaveCMAModal();
      });

      // Load button
      $('#mld-load-cma-btn').on('click', () => {
        this.openMySavedCMAsModal();
      });

      // Save modal close
      $('#mld-save-cma-close, #mld-save-cma-modal .mld-modal-overlay, #save-cma-cancel').on('click', () => {
        this.closeSaveCMAModal();
      });

      // My CMAs modal close
      $('#mld-my-cmas-close, #mld-my-cmas-modal .mld-modal-overlay').on('click', () => {
        this.closeMySavedCMAsModal();
      });

      // Save form submission
      $('#mld-save-cma-form').on('submit', (e) => {
        e.preventDefault();
        this.saveCMASession();
      });

      // Session item actions (event delegation)
      $(document).on('click', '.mld-cma-load-session-btn', function(e) {
        e.stopPropagation();
        const sessionId = $(this).closest('.mld-cma-session-item').data('session-id');
        self.loadCMASession(sessionId);
      });

      $(document).on('click', '.mld-cma-delete-session-btn', function(e) {
        e.stopPropagation();
        const sessionId = $(this).closest('.mld-cma-session-item').data('session-id');
        self.deleteCMASession(sessionId);
      });

      $(document).on('click', '.mld-cma-toggle-favorite-btn', function(e) {
        e.stopPropagation();
        const sessionId = $(this).closest('.mld-cma-session-item').data('session-id');
        self.toggleCMAFavorite(sessionId, $(this));
      });
    }

    openSaveCMAModal() {
      // Populate summary with CURRENT state (selected comparables, not all)
      const address = this.subjectProperty.city ?
        `${this.subjectProperty.city}, ${this.subjectProperty.state || 'MA'}` :
        `Listing ${this.subjectProperty.listing_id}`;

      // Get currently selected comparables and recalculate fresh summary
      const selectedComps = this.currentComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );
      const freshSummary = this.calculateSummaryFromComparables(selectedComps);

      $('#save-summary-address').text(address);
      $('#save-summary-comps').text(selectedComps.length);  // Show SELECTED count, not total

      if (freshSummary && freshSummary.estimated_value) {
        const midValue = freshSummary.estimated_value.mid ||
          (freshSummary.estimated_value.low + freshSummary.estimated_value.high) / 2;
        $('#save-summary-value').text(this.formatPrice(midValue));
      } else {
        $('#save-summary-value').text('--');
      }

      // Show ARV indicator if active
      if (this.isARVMode) {
        $('.mld-arv-mode-note').show();
      } else {
        $('.mld-arv-mode-note').hide();
      }

      // Suggest session name
      const defaultName = `CMA - ${address} - ${new Date().toLocaleDateString()}`;
      $('#cma-session-name').val(defaultName);
      $('#cma-session-description').val('');

      $('#mld-save-cma-modal').stop(true, true).fadeIn(300);
    }

    closeSaveCMAModal() {
      $('#mld-save-cma-modal').stop(true, true).fadeOut(300);
    }

    saveCMASession() {
      const sessionName = $('#cma-session-name').val().trim();
      const description = $('#cma-session-description').val().trim();

      if (!sessionName) {
        alert('Please enter a session name.');
        return;
      }

      const $submitBtn = $('#save-cma-submit');
      $submitBtn.prop('disabled', true).html('Saving...');

      // IMPORTANT: Update comparables with current selection and weight state before saving
      // This ensures user modifications (check/uncheck, weight adjustments) are captured
      const comparablesToSave = this.currentComparables.map(comp => {
        const isSelected = this.selectedComparables.includes(String(comp.listing_id));
        return {
          ...comp,
          selected: isSelected  // Mark whether this comparable is currently selected
        };
      });

      // Recalculate summary to ensure it reflects current selections and weights
      const selectedComps = this.currentComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );
      const currentSummaryRecalculated = this.calculateSummaryFromComparables(selectedComps);

      // Calculate mid value from recalculated summary
      let estimatedValueMid = 0;
      if (currentSummaryRecalculated && currentSummaryRecalculated.estimated_value) {
        estimatedValueMid = currentSummaryRecalculated.estimated_value.mid ||
          (currentSummaryRecalculated.estimated_value.low + currentSummaryRecalculated.estimated_value.high) / 2;
      }

      cmaLog('[CMA Save] Saving session with:', {
        totalComparables: comparablesToSave.length,
        selectedCount: selectedComps.length,
        selectedIds: this.selectedComparables,
        estimatedValue: estimatedValueMid
      });

      const data = {
        action: 'mld_save_cma_session',
        nonce: mldAjax.nonce,
        session_name: sessionName,
        description: description,
        subject_listing_id: this.subjectProperty.listing_id,
        subject_property_data: this.subjectProperty,
        subject_overrides: this.isARVMode ? this.arvOverrides : null,
        cma_filters: this.getFilterData(),
        comparables_data: comparablesToSave,  // Now includes 'selected' property
        summary_statistics: currentSummaryRecalculated,  // Fresh calculation
        comparables_count: selectedComps.length,  // Count of SELECTED comparables
        estimated_value_mid: estimatedValueMid,
        // For standalone CMAs, include the session ID to update existing session (v6.20.2)
        standalone_session_id: this.isStandaloneCMA ? this.standaloneSessionId : null
      };

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: data,
        success: (response) => {
          if (response.success) {
            this.currentSessionId = response.data.session_id;
            this.closeSaveCMAModal();
            alert('CMA session saved successfully!');
          } else {
            alert('Error saving CMA: ' + (response.data.message || 'Unknown error'));
          }
        },
        error: () => {
          alert('Failed to save CMA session. Please try again.');
        },
        complete: () => {
          $submitBtn.prop('disabled', false).html('<span class="save-icon">💾</span> Save CMA');
        }
      });
    }

    openMySavedCMAsModal() {
      $('#mld-my-cmas-modal').stop(true, true).fadeIn(300);
      this.loadMySavedCMAs();
    }

    closeMySavedCMAsModal() {
      $('#mld-my-cmas-modal').stop(true, true).fadeOut(300);
    }

    loadMySavedCMAs() {
      const $list = $('#mld-my-cmas-list');
      const $loading = $('.mld-my-cmas-loading');
      const $empty = $('.mld-my-cmas-empty');

      $list.hide();
      $empty.hide();
      $loading.show();

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_list_cma_sessions',
          nonce: mldAjax.nonce,
          limit: 50
        },
        success: (response) => {
          $loading.hide();

          if (response.success && response.data.sessions && response.data.sessions.length > 0) {
            this.renderMySavedCMAs(response.data.sessions);
            $list.show();
          } else {
            $empty.show();
          }
        },
        error: () => {
          $loading.hide();
          $list.html('<p class="error">Failed to load saved CMAs.</p>').show();
        }
      });
    }

    renderMySavedCMAs(sessions) {
      const $list = $('#mld-my-cmas-list');
      let html = '';

      sessions.forEach(session => {
        const formattedDate = new Date(session.created_at).toLocaleDateString('en-US', {
          year: 'numeric', month: 'short', day: 'numeric'
        });
        const formattedValue = session.estimated_value_mid ?
          this.formatPrice(session.estimated_value_mid) : '--';

        // Check if this is a standalone CMA
        const isStandalone = session.is_standalone == 1;
        const standaloneBadge = isStandalone ? '<span class="mld-cma-standalone-badge">Standalone</span>' : '';
        const propertyLabel = isStandalone ? 'Address' : 'Property';
        const propertyValue = isStandalone ?
          this.escapeHtml(session.subject_property_data?.address || session.standalone_slug || 'N/A') :
          this.escapeHtml(session.subject_listing_id);

        html += `
          <div class="mld-cma-session-item" data-session-id="${session.id}" data-is-standalone="${isStandalone ? '1' : '0'}" data-standalone-slug="${session.standalone_slug || ''}">
            <div class="mld-cma-session-header">
              <div class="mld-cma-session-name">
                ${session.is_favorite == 1 ? '<span class="mld-cma-session-favorite">⭐</span>' : ''}
                ${this.escapeHtml(session.session_name)}
                ${standaloneBadge}
              </div>
              <div class="mld-cma-session-actions">
                <button class="mld-cma-toggle-favorite-btn" title="Toggle Favorite">
                  ${session.is_favorite == 1 ? '★' : '☆'}
                </button>
                <button class="mld-cma-load-session-btn">Load</button>
                <button class="mld-cma-delete-session-btn">Delete</button>
              </div>
            </div>
            <div class="mld-cma-session-meta">
              <span>Created: ${formattedDate}</span>
              <span>${propertyLabel}: ${propertyValue}</span>
            </div>
            ${session.description ? `<div class="mld-cma-session-description">${this.escapeHtml(session.description)}</div>` : ''}
            <div class="mld-cma-session-stats">
              <div class="mld-cma-session-stat">
                <span class="mld-cma-session-stat-label">Comparables</span>
                <span class="mld-cma-session-stat-value">${session.comparables_count || 0}</span>
              </div>
              <div class="mld-cma-session-stat">
                <span class="mld-cma-session-stat-label">Est. Value</span>
                <span class="mld-cma-session-stat-value">${formattedValue}</span>
              </div>
            </div>
          </div>
        `;
      });

      $list.html(html);
    }

    loadCMASession(sessionId) {
      const $modal = $('#mld-my-cmas-modal');

      // Check if this is a standalone CMA (from the DOM data)
      const $item = $(`.mld-cma-session-item[data-session-id="${sessionId}"]`);
      const isStandalone = $item.data('is-standalone') == '1';
      const standaloneSlug = $item.data('standalone-slug');

      // If standalone CMA, redirect directly to the standalone CMA page
      if (isStandalone && standaloneSlug) {
        window.location.href = `/cma/${standaloneSlug}/`;
        return;
      }

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_load_cma_session',
          nonce: mldAjax.nonce,
          session_id: sessionId
        },
        success: (response) => {
          if (response.success && response.data.session) {
            const session = response.data.session;

            // Double-check for standalone CMAs loaded via AJAX (fallback)
            if (session.is_standalone == 1 && session.standalone_slug) {
              window.location.href = `/cma/${session.standalone_slug}/`;
              return;
            }

            const currentListingId = this.subjectProperty.listing_id;
            const sessionListingId = session.subject_listing_id;

            // Check if we need to redirect to the subject property page
            if (currentListingId !== sessionListingId) {
              // Construct the property URL using the standard pattern: /property/[listing_id]
              const propertyUrl = `/property/${sessionListingId}/`;

              // Redirect to the subject property page with the CMA session ID
              const redirectUrl = new URL(propertyUrl, window.location.origin);
              redirectUrl.searchParams.set('load_cma', sessionId);
              window.location.href = redirectUrl.toString();
              return;
            }

            // Same property - just restore the session
            this.restoreCMASession(session);
            $modal.stop(true, true).fadeOut(300);
          } else {
            alert('Error loading CMA: ' + (response.data.message || 'Session not found'));
          }
        },
        error: () => {
          alert('Failed to load CMA session. Please try again.');
        }
      });
    }

    restoreCMASession(session) {
      // Restore subject property
      if (session.subject_property_data) {
        this.subjectProperty = session.subject_property_data;
        this.originalSubjectProperty = { ...session.subject_property_data };
      }

      // Restore ARV overrides if any
      if (session.subject_overrides) {
        this.arvOverrides = session.subject_overrides;
        Object.assign(this.subjectProperty, session.subject_overrides);
        this.isARVMode = true;
        this.updateARVButtonState();
      } else {
        this.arvOverrides = null;
        this.isARVMode = false;
        this.updateARVButtonState();
      }

      // Restore filters
      if (session.cma_filters) {
        this.restoreFilters(session.cma_filters);
      }

      // Store session ID
      this.currentSessionId = session.id;

      // Store full session data for Re-run CMA feature (v6.20.1)
      this.loadedSessionData = session;
      this.previousValueEstimate = session.estimated_value_mid ||
        (session.summary_statistics?.estimated_value?.mid) || null;
      cmaLog('[CMA Restore] Stored session for re-run, previous value:', this.previousValueEstimate);

      // Store weight overrides and selections from saved session for restoration after loading
      this.savedWeightOverrides = {};
      this.savedSelectedComparables = [];  // Track which comparables were selected (v6.20.1)

      if (session.comparables_data && Array.isArray(session.comparables_data)) {
        session.comparables_data.forEach(comp => {
          // Restore weight overrides (v6.19.0)
          if (comp.weight_override !== null && comp.weight_override !== undefined) {
            this.savedWeightOverrides[comp.listing_id] = comp.weight_override;
          }
          // Restore selections (v6.20.1)
          if (comp.selected === true) {
            this.savedSelectedComparables.push(String(comp.listing_id));
          }
        });
        cmaLog('[CMA Restore] Weight overrides to restore:', this.savedWeightOverrides);
        cmaLog('[CMA Restore] Selected comparables to restore:', this.savedSelectedComparables);
      }

      // Show Re-run CMA button since we loaded a session
      this.showRerunButton();

      // Reload comparables with restored settings
      this.loadComparables();

      alert('CMA session loaded successfully!');
    }

    restoreFilters(filters) {
      // Restore filter form values
      if (filters.radius) $('#comp-radius').val(filters.radius).trigger('input');
      if (filters.price_range_pct) $('#comp-price-range').val(filters.price_range_pct).trigger('input');
      if (filters.sqft_range_pct) $('#comp-sqft-range').val(filters.sqft_range_pct).trigger('input');
      if (filters.year_built_range) $('#comp-year-range').val(filters.year_built_range).trigger('input');
      if (filters.months_back) $('#comp-months-back').val(filters.months_back);
      if (filters.beds_min) $('input[name="beds_min"]').val(filters.beds_min);
      if (filters.beds_max) $('input[name="beds_max"]').val(filters.beds_max);
      if (filters.baths_min) $('input[name="baths_min"]').val(filters.baths_min);
      if (filters.baths_max) $('input[name="baths_max"]').val(filters.baths_max);
    }

    // ============================================
    // Re-run CMA Methods (v6.20.1)
    // ============================================

    /**
     * Show the Re-run CMA button in the toolbar
     */
    showRerunButton() {
      // Check if button already exists
      if ($('#mld-rerun-cma-btn').length > 0) {
        $('#mld-rerun-cma-btn').show();
        return;
      }

      // Add button to toolbar after the save/load buttons
      const $toolbar = $('.mld-comp-toolbar');
      if ($toolbar.length) {
        const rerunBtn = `
          <button type="button" id="mld-rerun-cma-btn" class="mld-comp-toolbar-btn mld-rerun-btn" title="Re-run CMA with fresh data">
            <span class="rerun-icon">🔄</span> Re-run CMA
          </button>
        `;
        // Insert after the save/load buttons or at the end
        const $saveBtn = $('#mld-save-cma-btn');
        if ($saveBtn.length) {
          $saveBtn.after(rerunBtn);
        } else {
          $toolbar.find('.mld-comp-toolbar-actions').append(rerunBtn);
        }

        // Bind click handler
        $('#mld-rerun-cma-btn').on('click', () => this.rerunCMA());
      }
    }

    /**
     * Hide the Re-run CMA button
     */
    hideRerunButton() {
      $('#mld-rerun-cma-btn').hide();
    }

    /**
     * Re-run the CMA with fresh comparables using saved filters
     */
    rerunCMA() {
      if (!this.loadedSessionData) {
        alert('No saved CMA session to re-run. Please load a saved CMA first.');
        return;
      }

      const previousValue = this.previousValueEstimate;
      const sessionName = this.loadedSessionData.session_name || 'Saved CMA';

      // Confirm re-run
      if (!confirm(`Re-run "${sessionName}" with fresh market data?\n\nThis will fetch current comparables using your saved filters and show how the value has changed.`)) {
        return;
      }

      cmaLog('[Re-run CMA] Starting re-run with previous value:', previousValue);

      // Store the previous summary for comparison
      this.previousSummary = this.loadedSessionData.summary_statistics;

      // Clear saved selections/weights so we get fresh defaults
      // (User can then modify and save as new session)
      this.savedWeightOverrides = {};
      this.savedSelectedComparables = [];

      // Clear the loaded session to prevent confusion
      // (This is now a fresh CMA based on previous filters)
      this.loadedSessionData = null;
      this.currentSessionId = null;

      // Re-load comparables - this will fetch fresh data
      this.loadComparables();

      // After loading completes, show comparison
      // We'll use a one-time event or check in renderResults
      this.showRerunComparison = true;
      this.rerunPreviousValue = previousValue;
    }

    /**
     * Render comparison between previous and new CMA values
     */
    renderRerunComparison(previousValue, newValue) {
      if (!previousValue || !newValue) return;

      const diff = newValue - previousValue;
      const diffPct = previousValue > 0 ? ((diff / previousValue) * 100).toFixed(2) : 0;
      const isUp = diff > 0;
      const isDown = diff < 0;

      const trendIcon = isUp ? '📈' : (isDown ? '📉' : '➡️');
      const trendColor = isUp ? '#28a745' : (isDown ? '#dc3545' : '#6c757d');
      const diffSign = diff >= 0 ? '+' : '';

      const html = `
        <div class="mld-rerun-comparison" style="margin: 1rem 0; padding: 1rem; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 8px; border: 2px solid #2196f3;">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <div style="text-align: center;">
              <div style="font-size: 0.75rem; color: #1565c0; text-transform: uppercase; font-weight: 600;">Previous Value</div>
              <div style="font-size: 1.25rem; font-weight: 700; color: #1976d2;">${this.formatPrice(previousValue)}</div>
            </div>
            <div style="font-size: 2rem;">${trendIcon}</div>
            <div style="text-align: center;">
              <div style="font-size: 0.75rem; color: #1565c0; text-transform: uppercase; font-weight: 600;">New Value</div>
              <div style="font-size: 1.25rem; font-weight: 700; color: #1976d2;">${this.formatPrice(newValue)}</div>
            </div>
            <div style="text-align: center; padding: 0.5rem 1rem; background: ${trendColor}15; border-radius: 6px;">
              <div style="font-size: 0.75rem; color: ${trendColor}; text-transform: uppercase; font-weight: 600;">Change</div>
              <div style="font-size: 1.1rem; font-weight: 700; color: ${trendColor};">${diffSign}${this.formatPrice(Math.abs(diff))}</div>
              <div style="font-size: 0.8rem; color: ${trendColor};">${diffSign}${diffPct}%</div>
            </div>
          </div>
          <div style="margin-top: 0.75rem; text-align: center;">
            <small style="color: #1565c0;">
              💡 This comparison shows how the market value has changed since your last saved CMA.
              <br>Save this new analysis to track the trend over time.
            </small>
          </div>
        </div>
      `;

      // Insert after summary section
      const $summary = $('.mld-comp-summary');
      if ($summary.length) {
        // Remove any existing comparison
        $('.mld-rerun-comparison').remove();
        $summary.after(html);
      }
    }

    deleteCMASession(sessionId) {
      if (!confirm('Are you sure you want to delete this CMA session?')) {
        return;
      }

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_delete_cma_session',
          nonce: mldAjax.nonce,
          session_id: sessionId
        },
        success: (response) => {
          if (response.success) {
            // Remove from list
            $(`.mld-cma-session-item[data-session-id="${sessionId}"]`).fadeOut(300, function() {
              $(this).remove();
              // Check if list is now empty
              if ($('#mld-my-cmas-list').children().length === 0) {
                $('#mld-my-cmas-list').hide();
                $('.mld-my-cmas-empty').show();
              }
            });
          } else {
            alert('Error deleting CMA: ' + (response.data.message || 'Unknown error'));
          }
        },
        error: () => {
          alert('Failed to delete CMA session. Please try again.');
        }
      });
    }

    toggleCMAFavorite(sessionId, $btn) {
      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: {
          action: 'mld_toggle_cma_favorite',
          nonce: mldAjax.nonce,
          session_id: sessionId
        },
        success: (response) => {
          if (response.success) {
            const $item = $(`.mld-cma-session-item[data-session-id="${sessionId}"]`);
            const $name = $item.find('.mld-cma-session-name');

            if (response.data.is_favorite) {
              $btn.text('★');
              if (!$name.find('.mld-cma-session-favorite').length) {
                $name.prepend('<span class="mld-cma-session-favorite">⭐</span>');
              }
            } else {
              $btn.text('☆');
              $name.find('.mld-cma-session-favorite').remove();
            }
          }
        }
      });
    }

    escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // ============================================
    // PDF Generation Methods
    // ============================================

    generateCMAPDF() {
      // Show loading state
      const $shareModal = $('.mld-modal:visible');
      const $pdfOption = $shareModal.find('.mld-share-option[data-action="pdf"]');

      if ($pdfOption.length) {
        $pdfOption.html('<div class="mld-share-option-icon">⏳</div><div class="mld-share-option-label">Generating PDF...</div>');
      }

      // Get selected comparables
      const selectedComps = this.currentComparables.filter(c =>
        this.selectedComparables.includes(String(c.listing_id))
      );

      const data = {
        action: 'mld_generate_cma_pdf',
        nonce: mldAjax.nonce,
        subject_property: this.subjectProperty,
        filters: this.getFilterData(),
        comparables: selectedComps,
        summary: this.currentSummary,
        is_arv_mode: this.isARVMode,
        arv_overrides: this.arvOverrides,
        pdf_options: {
          report_title: 'Comparative Market Analysis',
          include_photos: true,
          include_forecast: true,
          include_investment: true
        }
      };

      $.ajax({
        url: mldAjax.ajaxurl,
        type: 'POST',
        data: data,
        success: (response) => {
          if (response.success && response.data.pdf_url) {
            // Open PDF in new tab
            window.open(response.data.pdf_url, '_blank');

            // Reset button
            if ($pdfOption.length) {
              $pdfOption.html('<div class="mld-share-option-icon">📄</div><div class="mld-share-option-label">Download PDF</div>');
            }

            // Close share modal after short delay
            setTimeout(() => {
              $shareModal.stop(true, true).fadeOut(300);
            }, 500);
          } else {
            alert('Error generating PDF: ' + (response.data.message || 'Unknown error'));
            if ($pdfOption.length) {
              $pdfOption.html('<div class="mld-share-option-icon">📄</div><div class="mld-share-option-label">Download PDF</div>');
            }
          }
        },
        error: () => {
          alert('Failed to generate PDF. Please try again or use Print to PDF.');
          if ($pdfOption.length) {
            $pdfOption.html('<div class="mld-share-option-icon">📄</div><div class="mld-share-option-label">Download PDF</div>');
          }
        }
      });
    }

    // ============================================
    // Utility functions
    // ============================================

    formatPrice(price) {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      }).format(price);
    }

    formatNumber(num) {
      return new Intl.NumberFormat('en-US').format(num);
    }

    formatAdjustment(amount) {
      const formatted = this.formatPrice(Math.abs(amount));
      if (amount > 0) return `+${formatted}`;
      if (amount < 0) return `-${formatted}`;
      return formatted;
    }

    formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });
    }

    formatStatusLabel(status) {
      // Map database status values to user-friendly labels
      const statusMap = {
        'Closed': 'Sold',
        'Active Under Contract': 'Under Agreement',
        'Active': 'Active',
        'Pending': 'Pending'
      };
      return statusMap[status] || status;
    }

    getRoadTypePremium() {
      // Get road type premium from admin settings (passed via mldAjax)
      return (typeof mldAjax !== 'undefined' && mldAjax.roadTypePremium)
        ? parseFloat(mldAjax.roadTypePremium)
        : 25; // Fallback to 25 if not set
    }
  }

  // Initialize when DOM is ready
  $(document).ready(function () {
    cmaLog('[MLD Comparable Sales] Document ready');
    cmaLog('[MLD Comparable Sales] #mld-comparable-sales found:', $('#mld-comparable-sales').length);
    cmaLog('[MLD Comparable Sales] #mld-comp-results found:', $('#mld-comp-results').length);
    cmaLog('[MLD Comparable Sales] mldSubjectProperty at ready:', window.mldSubjectProperty);

    if ($('#mld-comparable-sales').length) {
      cmaLog('[MLD Comparable Sales] Creating MLDComparableSales instance...');
      new MLDComparableSales();
    } else {
      cmaLog('[MLD Comparable Sales] NOT initializing - #mld-comparable-sales not found');
    }

    // Handle modal close (event delegation for dynamically created modals)
    // Static modals (ARV, Save CMA, My CMAs) have their own handlers and should NOT be removed
    // v6.25.32: Added property page modals (mapModal, streetViewModal, virtualTourModal) to prevent removal
    $(document).on('click', '.mld-modal-close, .mld-modal-overlay', function(e) {
      const $modal = $(this).closest('.mld-modal');
      const modalId = $modal.attr('id');

      // Static modals have their own close handlers - don't process them here
      // v6.25.32: Added property page modals to this list - they should persist in DOM
      const staticModals = [
        'mld-arv-modal',
        'mld-save-cma-modal',
        'mld-my-cmas-modal',
        'mapModal',           // Property page map modal
        'streetViewModal',    // Property page street view modal
        'virtualTourModal'    // Property page 3D tour modal
      ];
      if (staticModals.includes(modalId)) {
        return; // Let the specific handler manage these
      }

      // Dynamically created modals should be removed after closing
      $modal.stop(true, true).fadeOut(300, function() {
        $(this).remove();
      });
    });
  });
})(jQuery);
