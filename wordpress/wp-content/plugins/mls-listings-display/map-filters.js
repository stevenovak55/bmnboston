/**
 * MLD Map Filters Module
 * v4.3.0
 * - FEAT: Added populateAmenityCheckboxes to display counts for boolean filters.
 */
const MLD_Filters = {
  keywordFilters: {},

  initSearchAndFilters() {
    const $ = jQuery;

    // Initialize collapsible sections
    this.initCollapsibleSections();

    const setupSearchListener = (inputId, suggestionsId) => {
      $(inputId).on(
        'keyup',
        MLD_Core.debounce((e) => {
          const term = $(e.target).val();
          if (term.length >= 2) {
            MLD_API.fetchAutocompleteSuggestions(term, suggestionsId);
          } else {
            $(suggestionsId).hide().empty();
          }
        }, 250)
      );
    };

    setupSearchListener('#bme-search-input', '#bme-autocomplete-suggestions');
    setupSearchListener('#bme-search-input-modal', '#bme-autocomplete-suggestions-modal');

    $('#bme-search-input').on('input', function () {
      $('#bme-search-input-modal').val($(this).val());
    });
    $('#bme-search-input-modal').on('input', function () {
      $('#bme-search-input').val($(this).val());
    });

    $('#bme-property-type-select').on('change', function (e, isProgrammatic) {
      MLD_Map_App.selectedPropertyType = $(this).val();
      // State persistence removed - property type is not saved
      if (!isProgrammatic) {
        MLD_Map_App.modalFilters = MLD_Filters.getModalDefaults();
        MLD_Filters.restoreModalUIToState();
        MLD_Core.updateUrlHash();
        MLD_API.refreshMapListings(true);
      }
      MLD_Core.updateModalVisibility();
      MLD_API.fetchDynamicFilterOptions();
      MLD_Filters.renderFilterTags();
      
      // Update count when property type changes
      if (!isProgrammatic) {
        clearTimeout(MLD_Map_App.countUpdateTimer);
        MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
      }
    });

    // Use namespaced event to prevent memory leaks
    $(document)
      .off('click.autocomplete')
      .on('click.autocomplete', (e) => {
        if (!$(e.target).closest('#bme-search-bar-wrapper, #bme-search-bar-wrapper-modal').length) {
          $('#bme-autocomplete-suggestions, #bme-autocomplete-suggestions-modal').hide();
        }
      });

    const $filtersModal = $('#bme-filters-modal-overlay');
    $('#bme-filters-button').on('click', () => {
      $filtersModal.css('display', 'flex');
      // Clear user-modified flags when modal opens so prices can update if needed
      $('#bme-filter-price-min, #bme-filter-price-max').removeData('user-modified');
      MLD_API.updateFilterCount();
      MLD_API.fetchDynamicFilterOptions();

      // Analytics: Track filter modal open (v6.38.0)
      const activeFilterCount = Object.keys(MLD_Filters.getCombinedFilters()).length - 1; // -1 for PropertyType
      document.dispatchEvent(new CustomEvent('mld:filter_modal_open', {
        detail: { filterCount: activeFilterCount }
      }));
    });
    $('#bme-filters-modal-close, #bme-filters-modal-overlay').on('click', function (e) {
      if (e.target === this) {
        if (!$filtersModal.hasClass('is-dragging')) {
          $filtersModal.hide();

          // Analytics: Track filter modal close (v6.38.0)
          document.dispatchEvent(new CustomEvent('mld:filter_modal_close', {
            detail: { filtersChanged: false }
          }));
        }
      }
    });

    $('#bme-apply-filters-btn').on('click', this.applyModalFilters);
    $('#bme-clear-filters-btn').on('click', this.clearAllFilters);

    $('body').on('click', '.bme-home-type-btn', function () {
      $(this).toggleClass('active');
      // Update modal state immediately
      if (!MLD_Map_App.modalFilters.home_type) {
        MLD_Map_App.modalFilters.home_type = [];
      }
      const value = $(this).data('value');
      if ($(this).hasClass('active')) {
        if (!MLD_Map_App.modalFilters.home_type.includes(value)) {
          MLD_Map_App.modalFilters.home_type.push(value);
        }
      } else {
        MLD_Map_App.modalFilters.home_type = MLD_Map_App.modalFilters.home_type.filter(
          (v) => v !== value
        );
      }
      // Immediately update filter tags
      MLD_Filters.renderFilterTags();
    });

    // v6.72.1: Beds now uses min-only selection to align with iOS
    $('#bme-filter-beds, #bme-filter-baths, #bme-filter-garage-spaces, #bme-filter-parking-total').on(
      'click',
      'button',
      this.handleMinOnlySelection
    );

    const debouncedUpdate = MLD_Core.debounce(MLD_API.updateFilterCount, 400);
    const debouncedDynamicUpdate = MLD_Core.debounce(() => {
      MLD_API.fetchDynamicFilterOptions();
    }, 600);

    // Handle non-price inputs with keyup for immediate feedback
    $('#bme-filters-modal-body').on('change keyup', 'input:not(#bme-filter-price-min, #bme-filter-price-max), select', function() {
      const $input = $(this);
      const inputId = $input.attr('id');

      // Update modal filters for text inputs
      if ($input.is('input[type="number"], input[type="text"]')) {
        const fieldMap = {
          'bme-filter-sqft-min': 'sqft_min',
          'bme-filter-sqft-max': 'sqft_max',
          'bme-filter-year-built-min': 'year_built_min',
          'bme-filter-year-built-max': 'year_built_max',
          'bme-filter-lot-size-min': 'lot_size_min',
          'bme-filter-lot-size-max': 'lot_size_max',
          'bme-filter-entry-level-min': 'entry_level_min',
          'bme-filter-entry-level-max': 'entry_level_max'
        };

        if (fieldMap[inputId]) {
          MLD_Map_App.modalFilters[fieldMap[inputId]] = $input.val();
          // Update tags on change event (not keyup to avoid too frequent updates)
          if (event.type === 'change') {
            MLD_Filters.renderFilterTags();
          }
        }
      }

      debouncedUpdate();
      debouncedDynamicUpdate();
    });

    // Handle price inputs only on blur/change to prevent value resets while typing
    $('#bme-filter-price-min, #bme-filter-price-max').on('change', () => {
      debouncedUpdate();
      debouncedDynamicUpdate();
    });

    $('#bme-filters-modal-body').on('click', 'button, input[type="checkbox"]', () => {
      debouncedUpdate();
      debouncedDynamicUpdate();
    });

    // Custom dropdown toggle for Status filter with ARIA support
    $('#bme-filter-status-display').on('click', function (e) {
      e.stopPropagation();
      const $dropdown = $('#bme-filter-status-dropdown');
      const $wrapper = $('#bme-filter-status-wrapper');
      const isVisible = $dropdown.is(':visible');

      // Close all other dropdowns
      $('.bme-select-dropdown').not($dropdown).hide();

      // Toggle this dropdown
      $dropdown.toggle(!isVisible);
      $(this).toggleClass('active', !isVisible);

      // Update ARIA attribute
      $wrapper.attr('aria-expanded', !isVisible ? 'true' : 'false');
    });

    // Handle checkbox changes in Status dropdown
    $('#bme-filter-status-dropdown input[type="checkbox"]').on('change', function () {
      // Cache jQuery objects for performance
      const $statusCheckboxes = $('#bme-filter-status-dropdown input[type="checkbox"]');
      const $checkedBoxes = $statusCheckboxes.filter(':checked');

      // Validate that at least one status is selected
      if ($checkedBoxes.length === 0) {
        // Re-check this box to prevent having no selection
        $(this).prop('checked', true);

        // Show user-friendly message
        const $dropdown = $('#bme-filter-status-dropdown');
        const $message = $(
          '<div class="bme-status-warning">Please select at least one status</div>'
        );
        $dropdown.prepend($message);
        setTimeout(() => $message.fadeOut(() => $message.remove()), 2000);
        return false;
      }

      const selectedStatuses = [];
      $checkedBoxes.each(function () {
        selectedStatuses.push($(this).val());
      });

      // Update modal filters
      MLD_Map_App.modalFilters.status = selectedStatuses;

      // Update display text
      MLD_Filters.updateStatusDisplayText();

      // Immediately update filter tags
      MLD_Filters.renderFilterTags();

      // Trigger count update and dynamic filter options update
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);

      // Also update dynamic filter options
      clearTimeout(MLD_Map_App.dynamicUpdateTimer);
      MLD_Map_App.dynamicUpdateTimer = setTimeout(() => {
        MLD_API.fetchDynamicFilterOptions();
      }, 600);
    });

    // Close dropdown when clicking outside - use namespaced event
    $(document)
      .off('click.statusDropdown')
      .on('click.statusDropdown', function (e) {
        if (!$(e.target).closest('#bme-filter-status-wrapper').length) {
          $('#bme-filter-status-dropdown').hide();
          $('#bme-filter-status-display').removeClass('active');
          $('#bme-filter-status-wrapper').attr('aria-expanded', 'false');
        }
      });

    // Special handler for Open House Only checkbox to update modal state immediately
    $('#bme-filter-open-house-only').on('change', function () {
      // Update the modal state immediately to prevent the checkbox from resetting
      MLD_Map_App.modalFilters.open_house_only = $(this).is(':checked');
      // Immediately update filter tags
      MLD_Filters.renderFilterTags();
    });

    // Special handlers for dynamic checkboxes to update modal state immediately
    $(document).on('change', '#bme-filter-structure-type input[type="checkbox"]', function () {
      const value = $(this).val();
      if (!MLD_Map_App.modalFilters.structure_type) {
        MLD_Map_App.modalFilters.structure_type = [];
      }
      if ($(this).is(':checked')) {
        if (!MLD_Map_App.modalFilters.structure_type.includes(value)) {
          MLD_Map_App.modalFilters.structure_type.push(value);
        }
      } else {
        MLD_Map_App.modalFilters.structure_type = MLD_Map_App.modalFilters.structure_type.filter(
          (v) => v !== value
        );
      }
      // Immediately update filter tags
      MLD_Filters.renderFilterTags();
      
      // Update count
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
    });

    $(document).on('change', '#bme-filter-architectural-style input[type="checkbox"]', function () {
      const value = $(this).val();
      if (!MLD_Map_App.modalFilters.architectural_style) {
        MLD_Map_App.modalFilters.architectural_style = [];
      }
      if ($(this).is(':checked')) {
        if (!MLD_Map_App.modalFilters.architectural_style.includes(value)) {
          MLD_Map_App.modalFilters.architectural_style.push(value);
        }
      } else {
        MLD_Map_App.modalFilters.architectural_style =
          MLD_Map_App.modalFilters.architectural_style.filter((v) => v !== value);
      }
      // Immediately update filter tags
      MLD_Filters.renderFilterTags();
      
      // Update count
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
    });

    $(document).on('change', '#bme-filter-amenities input[type="checkbox"]', function () {
      const value = $(this).val();
      MLD_Map_App.modalFilters[value] = $(this).is(':checked');
      // Immediately update filter tags
      MLD_Filters.renderFilterTags();
    });

    // District rating picker handler (v6.30.6) - iOS-style segmented control
    $(document).on('click', '.bme-district-grade-btn', function () {
      const $btn = $(this);
      const grade = $btn.data('grade');

      // Update button active states
      $('.bme-district-grade-btn').removeClass('active');
      $btn.addClass('active');

      // Update hidden input and modal state
      $('#school_grade').val(grade);
      MLD_Map_App.modalFilters.school_grade = grade;

      console.log('[District Rating Debug] Selected grade:', grade);

      // Immediately update filter tags
      MLD_Filters.renderFilterTags();

      // Update count
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
    });

    // School quality toggle handlers (v6.30.3) - mutually exclusive within each level
    // When A-rated is checked, uncheck A/B-rated and vice versa
    $(document).on('change', '.bme-school-toggles input[type="checkbox"]', function () {
      const $toggle = $(this);
      const toggleId = $toggle.attr('id');
      const isChecked = $toggle.is(':checked');

      console.log('[School Filter Debug] Toggle changed:', toggleId, 'checked:', isChecked);

      // Update modal state
      MLD_Map_App.modalFilters[toggleId] = isChecked;

      // Handle mutually exclusive toggles within the same school level
      if (isChecked) {
        // Determine the paired toggle ID
        let pairedId = null;
        if (toggleId.startsWith('near_a_') && !toggleId.startsWith('near_ab_')) {
          // This is an A-only toggle, find the A/B toggle for same level
          pairedId = toggleId.replace('near_a_', 'near_ab_');
        } else if (toggleId.startsWith('near_ab_')) {
          // This is an A/B toggle, find the A-only toggle for same level
          pairedId = toggleId.replace('near_ab_', 'near_a_');
        }

        if (pairedId) {
          $('#' + pairedId).prop('checked', false);
          MLD_Map_App.modalFilters[pairedId] = false;
        }
      }

      // Immediately update filter tags
      MLD_Filters.renderFilterTags();

      // Update count
      console.log('[School Filter Debug] Triggering count update');
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(function() {
        console.log('[School Filter Debug] Count update timer fired');
        MLD_API.updateFilterCount();
      }, 400);
    });

    // Initialize agent search functionality
    this.initAgentSearch();

    // Handle window resize for filter tags
    let resizeTimer;
    $(window).on('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => {
        this.renderFilterTags();
      }, 250);
    });
  },

  initAgentSearch() {
    const $ = jQuery;
    let searchTimeout;

    // Agent search input
    $('#bme-agent-search-input').on('input', function() {
      const term = $(this).val().trim();

      clearTimeout(searchTimeout);

      if (term.length < 2) {
        $('#bme-agent-suggestions').hide().empty();
        return;
      }

      searchTimeout = setTimeout(() => {
        $.post(bmeMapData.ajax_url, {
          action: 'get_agent_suggestions',
          security: bmeMapData.security,
          term: term
        })
        .done(function(response) {
          if (response.success && response.data && response.data.length > 0) {
            const html = response.data.map(agent =>
              `<div class="bme-suggestion-item bme-agent-suggestion" data-value="${agent.value}" data-label="${agent.label}">
                ${agent.label}
              </div>`
            ).join('');

            $('#bme-agent-suggestions').html(html).show();
          } else {
            $('#bme-agent-suggestions').hide().empty();
          }
        })
        .fail(function() {
          $('#bme-agent-suggestions').hide().empty();
        });
      }, 300);
    });

    // Select agent from suggestions
    $(document).on('click', '.bme-agent-suggestion', function() {
      const agentId = $(this).data('value');
      const agentLabel = $(this).data('label');

      // Add to selected agents if not already selected
      if (!MLD_Map_App.modalFilters.agent_ids) {
        MLD_Map_App.modalFilters.agent_ids = [];
      }

      if (!MLD_Map_App.modalFilters.agent_ids.includes(agentId)) {
        MLD_Map_App.modalFilters.agent_ids.push(agentId);
        MLD_Filters.addSelectedAgent(agentId, agentLabel);

        // Store the agent label for later use
        if (!MLD_Map_App.agentLabels) {
          MLD_Map_App.agentLabels = {};
        }
        MLD_Map_App.agentLabels[agentId] = agentLabel;
      }

      // Clear search and hide suggestions
      $('#bme-agent-search-input').val('');
      $('#bme-agent-suggestions').hide().empty();

      // Update filter tags
      MLD_Filters.renderFilterTags();

      // Trigger filter count update and dynamic options update
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);

      // Also update dynamic filter options
      clearTimeout(MLD_Map_App.dynamicUpdateTimer);
      MLD_Map_App.dynamicUpdateTimer = setTimeout(() => {
        MLD_API.fetchDynamicFilterOptions();
      }, 600);
    });

    // Remove selected agent
    $(document).on('click', '.bme-agent-chip-remove', function() {
      const agentId = $(this).parent().data('agent-id');
      MLD_Filters.removeSelectedAgent(agentId);
    });

    // Hide suggestions when clicking outside
    $(document).on('click', function(e) {
      if (!$(e.target).closest('#bme-agent-search-input, #bme-agent-suggestions').length) {
        $('#bme-agent-suggestions').hide();
      }
    });
  },

  addSelectedAgent(agentId, agentLabel) {
    const $ = jQuery;
    const $container = $('#bme-selected-agents');

    // Don't add if already exists
    if ($container.find(`[data-agent-id="${agentId}"]`).length > 0) {
      return;
    }

    const $chip = $(`
      <div class="bme-agent-chip" data-agent-id="${agentId}">
        <span class="bme-agent-chip-label">${agentLabel}</span>
        <span class="bme-agent-chip-remove">&times;</span>
      </div>
    `);

    $container.append($chip);
  },

  removeSelectedAgent(agentId) {
    const $ = jQuery;

    // Remove from modal filters
    if (MLD_Map_App.modalFilters.agent_ids) {
      MLD_Map_App.modalFilters.agent_ids = MLD_Map_App.modalFilters.agent_ids.filter(
        id => id !== agentId
      );
    }

    // Remove from stored labels
    if (MLD_Map_App.agentLabels && MLD_Map_App.agentLabels[agentId]) {
      delete MLD_Map_App.agentLabels[agentId];
    }

    // Remove from UI
    $(`#bme-selected-agents [data-agent-id="${agentId}"]`).remove();

    // Update filter tags
    MLD_Filters.renderFilterTags();

    // Trigger filter count update and dynamic options update
    clearTimeout(MLD_Map_App.countUpdateTimer);
    MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);

    // Also update dynamic filter options
    clearTimeout(MLD_Map_App.dynamicUpdateTimer);
    MLD_Map_App.dynamicUpdateTimer = setTimeout(() => {
      MLD_API.fetchDynamicFilterOptions();
    }, 600);
  },

  initPriceSlider() {
    const $ = jQuery;
    const slider = document.getElementById('bme-price-slider');
    if (!slider) return;
    const minHandle = document.getElementById('bme-price-slider-handle-min');
    const maxHandle = document.getElementById('bme-price-slider-handle-max');
    const minInput = document.getElementById('bme-filter-price-min');
    const maxInput = document.getElementById('bme-filter-price-max');
    let activeHandle = null;

    function startDrag(e) {
      e.preventDefault();
      activeHandle = e.target;
      $('#bme-filters-modal-overlay').addClass('is-dragging');
      document.addEventListener('mousemove', drag);
      document.addEventListener('mouseup', stopDrag);
      document.addEventListener('touchmove', drag, { passive: false });
      document.addEventListener('touchend', stopDrag);
    }

    function drag(e) {
      if (!activeHandle) return;
      e.preventDefault();
      const rect = slider.getBoundingClientRect();
      const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
      let percent = Math.max(0, Math.min(100, (x / rect.width) * 100));

      const minPercent = parseFloat(minHandle.style.left) || 0;
      const maxPercent = parseFloat(maxHandle.style.left) || 100;

      if (activeHandle === minHandle) {
        percent = Math.min(percent, maxPercent);
      } else {
        percent = Math.max(percent, minPercent);
      }

      activeHandle.style.left = percent + '%';
      MLD_Filters.updatePriceFromSlider();
    }

    function stopDrag() {
      activeHandle = null;
      setTimeout(() => {
        $('#bme-filters-modal-overlay').removeClass('is-dragging');
      }, 50);
      document.removeEventListener('mousemove', drag);
      document.removeEventListener('mouseup', stopDrag);
      document.removeEventListener('touchmove', drag);
      document.removeEventListener('touchend', stopDrag);
    }

    minHandle.addEventListener('mousedown', startDrag);
    maxHandle.addEventListener('mousedown', startDrag);
    minHandle.addEventListener('touchstart', startDrag, { passive: false });
    maxHandle.addEventListener('touchstart', startDrag, { passive: false });

    function handleInputBlur(e) {
      const input = e.target;
      let rawValue = input.value.replace(/[^0-9]/g, '');
      if (rawValue === '') {
        $(input).data('raw-value', '');
        // Update modal state for empty value
        if (input.id === 'bme-filter-price-min') {
          MLD_Map_App.modalFilters.price_min = '';
        } else {
          MLD_Map_App.modalFilters.price_max = '';
        }
      } else {
        rawValue = parseInt(rawValue, 10);
        $(input).data('raw-value', rawValue);
        input.value = MLD_Core.formatCurrency(rawValue);
        // Update modal state with new value
        if (input.id === 'bme-filter-price-min') {
          MLD_Map_App.modalFilters.price_min = rawValue;
        } else {
          MLD_Map_App.modalFilters.price_max = rawValue;
        }
      }
      // Mark that user has manually entered value
      $(input).data('user-modified', true);
      MLD_Filters.updateSliderFromInput();
      // Update filter tags immediately
      MLD_Filters.renderFilterTags();
    }

    $(minInput).on('blur', handleInputBlur);
    $(maxInput).on('blur', handleInputBlur);

    function handleInputFocus(e) {
      const input = e.target;
      const rawValue = $(input).data('raw-value');
      if (rawValue !== '') {
        input.value = rawValue;
      }
    }

    $(minInput).on('focus', handleInputFocus);
    $(maxInput).on('focus', handleInputFocus);
  },

  getModalDefaults() {
    return {
      price_min: '',
      price_max: '',
      beds_min: 0,  // v6.72.1: Changed from array to min-only to align with iOS
      baths_min: 0,
      home_type: [],
      status: ['Active'],
      sqft_min: '',
      sqft_max: '',
      year_built_min: '',
      year_built_max: '',
      lot_size_min: '',
      lot_size_max: '',
      entry_level_min: '',
      entry_level_max: '',
      garage_spaces_min: 0,
      parking_total_min: 0,
      structure_type: [],
      architectural_style: [],
      SpaYN: false,
      WaterfrontYN: false,
      ViewYN: false,
      MLSPIN_WATERVIEW_FLAG: false,
      PropertyAttachedYN: false,
      MLSPIN_LENDER_OWNED: false,
      available_by: '',
      MLSPIN_AvailableNow: false,
      SeniorCommunityYN: false,
      MLSPIN_OUTDOOR_SPACE_AVAILABLE: false,
      MLSPIN_DPR_Flag: false,
      CoolingYN: false,
      open_house_only: false,
      agent_ids: [],
      // School quality filters (v6.30.3) - iOS-matching toggle design
      school_grade: '',  // v6.30.6 - Minimum district rating (A, B+, B, C+)
      near_a_elementary: false,
      near_ab_elementary: false,
      near_a_middle: false,
      near_ab_middle: false,
      near_a_high: false,
      near_ab_high: false,
    };
  },

  getModalState(isForCountOrOptions = false) {
    const $ = jQuery;
    const state = {};
    state.price_min = $('#bme-filter-price-min').data('raw-value') || '';
    state.price_max = $('#bme-filter-price-max').data('raw-value') || '';
    console.log('[getModalState Debug] Price inputs - min:', state.price_min, 'max:', state.price_max);
    // v6.72.1: Beds now uses min-only to align with iOS
    state.beds_min = $('#bme-filter-beds button.active').data('value') || 0;
    state.baths_min = $('#bme-filter-baths button.active').data('value') || 0;

    // For dynamic filters, prefer the modal state over DOM state when fetching options
    if (isForCountOrOptions && MLD_Map_App.modalFilters) {
      state.home_type = MLD_Map_App.modalFilters.home_type || [];
      state.structure_type = MLD_Map_App.modalFilters.structure_type || [];
      state.architectural_style = MLD_Map_App.modalFilters.architectural_style || [];
    } else {
      state.home_type = $('#bme-filter-home-type .active')
        .map((_, el) => $(el).data('value'))
        .get();
      state.structure_type = $('#bme-filter-structure-type input:checked')
        .map((_, el) => el.value)
        .get();
      state.architectural_style = $('#bme-filter-architectural-style input:checked')
        .map((_, el) => el.value)
        .get();
    }

    state.status = $('#bme-filter-status input:checked')
      .map((_, el) => el.value)
      .get();
    state.sqft_min = $('#bme-filter-sqft-min').val();
    state.sqft_max = $('#bme-filter-sqft-max').val();
    state.year_built_min = $('#bme-filter-year-built-min').val();
    state.year_built_max = $('#bme-filter-year-built-max').val();
    state.lot_size_min = $('#bme-filter-lot-size-min').val();
    state.lot_size_max = $('#bme-filter-lot-size-max').val();
    state.entry_level_min = $('#bme-filter-entry-level-min').val();
    state.entry_level_max = $('#bme-filter-entry-level-max').val();
    state.garage_spaces_min = $('#bme-filter-garage-spaces button.active').data('value') || 0;
    state.parking_total_min = $('#bme-filter-parking-total button.active').data('value') || 0;

    // Handle special filters separately
    // Always prefer modalFilters for special filters to ensure consistency
    if (isForCountOrOptions && MLD_Map_App.modalFilters) {
      state.open_house_only = MLD_Map_App.modalFilters.open_house_only || false;
      // Get status from modalFilters
      state.status = MLD_Map_App.modalFilters.status || ['Active'];
    } else {
      state.open_house_only = $('#bme-filter-open-house-only').is(':checked');
      // Get status from custom dropdown checkboxes
      const selectedStatuses = [];
      $('#bme-filter-status-dropdown input[type="checkbox"]:checked').each(function () {
        selectedStatuses.push($(this).val());
      });
      state.status = selectedStatuses.length > 0 ? selectedStatuses : ['Active'];
    }

    // Handle other amenities
    if (isForCountOrOptions && MLD_Map_App.modalFilters) {
      // For dynamic updates, use the stored modal state for amenities
      const amenityFields = $('#bme-filter-amenities input[type="checkbox"]')
        .map((_, el) => el.value)
        .get();
      amenityFields.forEach((field) => {
        if (MLD_Map_App.modalFilters.hasOwnProperty(field)) {
          state[field] = MLD_Map_App.modalFilters[field];
        }
      });
    } else {
      $('#bme-filter-amenities input[type="checkbox"]').each(function () {
        state[this.value] = this.checked;
      });
    }

    state.available_by = $('#bme-filter-available-by').val();
    state.MLSPIN_AvailableNow = $('#bme-filter-available-now').is(':checked');

    // Include agent_ids from the modal filters if they exist
    if (MLD_Map_App.modalFilters && MLD_Map_App.modalFilters.agent_ids) {
      state.agent_ids = MLD_Map_App.modalFilters.agent_ids;
    }

    // School quality filters (v6.30.3) - iOS-matching toggle design
    // v6.30.6 - For count updates, prefer modalFilters to get the latest value
    if (isForCountOrOptions && MLD_Map_App.modalFilters) {
      state.school_grade = MLD_Map_App.modalFilters.school_grade || '';
      state.near_a_elementary = MLD_Map_App.modalFilters.near_a_elementary || false;
      state.near_ab_elementary = MLD_Map_App.modalFilters.near_ab_elementary || false;
      state.near_a_middle = MLD_Map_App.modalFilters.near_a_middle || false;
      state.near_ab_middle = MLD_Map_App.modalFilters.near_ab_middle || false;
      state.near_a_high = MLD_Map_App.modalFilters.near_a_high || false;
      state.near_ab_high = MLD_Map_App.modalFilters.near_ab_high || false;
    } else {
      state.school_grade = $('#school_grade').val() || '';
      state.near_a_elementary = $('#near_a_elementary').is(':checked');
      state.near_ab_elementary = $('#near_ab_elementary').is(':checked');
      state.near_a_middle = $('#near_a_middle').is(':checked');
      state.near_ab_middle = $('#near_ab_middle').is(':checked');
      state.near_a_high = $('#near_a_high').is(':checked');
      state.near_ab_high = $('#near_ab_high').is(':checked');
    }

    if (isForCountOrOptions) return state;
    MLD_Map_App.modalFilters = state;
    return state;
  },

  applyModalFilters() {
    console.log('[Apply Filters Debug] BEFORE getModalState, modalFilters:', JSON.parse(JSON.stringify(MLD_Map_App.modalFilters)));
    MLD_Filters.getModalState();
    console.log('[Apply Filters Debug] AFTER getModalState, modalFilters:', JSON.parse(JSON.stringify(MLD_Map_App.modalFilters)));
    // Clear specific property search flags when applying other filters
    MLD_Map_App.isSpecificPropertySearch = false;
    MLD_Map_App.lastSearchType = null;

    // Flag to fit map bounds to filter results
    MLD_Map_App.shouldFitToFilterResults = true;

    jQuery('#bme-filters-modal-overlay').hide();
    MLD_Filters.renderFilterTags();
    MLD_Core.updateUrlHash();

    // Analytics: Track filter modal close with changes (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:filter_modal_close', {
      detail: { filtersChanged: true }
    }));

    // Analytics: Track search execute (v6.38.0)
    const filters = MLD_Filters.getCombinedFilters();
    document.dispatchEvent(new CustomEvent('mld:search_execute', {
      detail: {
        filters: filters,
        resultCount: 0, // Will be updated when results come back
        searchType: 'map'
      }
    }));

    // Visitor state persistence removed - filter states are not saved

    MLD_API.refreshMapListings(true, 0, true); // Third param = fitToResults
  },

  clearAllFilters() {
    // Analytics: Track filter clear (v6.38.0)
    const clearedFilters = MLD_Filters.getCombinedFilters();
    document.dispatchEvent(new CustomEvent('mld:filter_clear', {
      detail: { filterName: 'all', clearedValue: clearedFilters }
    }));

    MLD_Map_App.keywordFilters = {};
    MLD_Filters.keywordFilters = {};  // Also clear MLD_Filters.keywordFilters
    MLD_Map_App.modalFilters = MLD_Filters.getModalDefaults();
    // Clear specific property search flags
    MLD_Map_App.isSpecificPropertySearch = false;
    MLD_Map_App.lastSearchType = null;

    // Clear user-modified flags on price inputs
    jQuery('#bme-filter-price-min, #bme-filter-price-max').removeData('user-modified');

    // Clear selected agents UI and stored labels
    jQuery('#bme-selected-agents').empty();
    MLD_Map_App.agentLabels = {};

    // Clear all drawn polygon shapes
    if (MLD_Map_App.clearAllPolygons && typeof MLD_Map_App.clearAllPolygons === 'function') {
      MLD_Map_App.clearAllPolygons();
    }

    // Clear city and neighborhood boundaries
    if (typeof MLD_CityBoundaries !== 'undefined') {
      MLD_CityBoundaries.clearBoundaries();
    }

    MLD_Filters.renderFilterTags();
    MLD_Filters.restoreModalUIToState();
    jQuery('#bme-filters-modal-overlay').hide();
    MLD_Core.updateUrlHash();

    // Visitor state persistence removed - cleared filters are not saved

    MLD_API.refreshMapListings(true);
  },

  restoreModalUIToState() {
    const $ = jQuery;
    const modalFilters = MLD_Map_App.modalFilters;
    this.updatePriceSliderUI();

    // v6.72.1: Beds now uses min-only selection to align with iOS
    $('#bme-filter-beds button')
      .removeClass('active')
      .filter(`[data-value="${modalFilters.beds_min || 0}"]`)
      .addClass('active');

    $('#bme-filter-baths button')
      .removeClass('active')
      .filter(`[data-value="${modalFilters.baths_min || 0}"]`)
      .addClass('active');
    $('#bme-filter-garage-spaces button')
      .removeClass('active')
      .filter(`[data-value="${modalFilters.garage_spaces_min || 0}"]`)
      .addClass('active');
    $('#bme-filter-parking-total button')
      .removeClass('active')
      .filter(`[data-value="${modalFilters.parking_total_min || 0}"]`)
      .addClass('active');

    $('#bme-filter-home-type .bme-home-type-btn').removeClass('active');
    modalFilters.home_type.forEach((ht) =>
      $(`.bme-home-type-btn[data-value="${ht}"]`).addClass('active')
    );

    $(
      '#bme-filter-structure-type input, #bme-filter-architectural-style input, #bme-filter-amenities input'
    ).prop('checked', false);
    modalFilters.structure_type.forEach((s) =>
      $(`#bme-filter-structure-type input[value="${s}"]`).prop('checked', true)
    );
    modalFilters.architectural_style.forEach((s) =>
      $(`#bme-filter-architectural-style input[value="${s}"]`).prop('checked', true)
    );

    // Handle special filters separately
    $('#bme-filter-open-house-only').prop('checked', modalFilters.open_house_only || false);

    // Handle status custom dropdown checkboxes
    $('#bme-filter-status-dropdown input[type="checkbox"]').prop('checked', false);
    if (modalFilters.status && modalFilters.status.length > 0) {
      modalFilters.status.forEach((status) => {
        $(`#bme-filter-status-dropdown input[value="${status}"]`).prop('checked', true);
      });
    } else {
      // Default to Active if no status selected
      $('#bme-filter-status-dropdown input[value="Active"]').prop('checked', true);
    }

    // Update status display text
    this.updateStatusDisplayText();

    // Handle other amenities
    $('#bme-filter-amenities input').each(function () {
      if (modalFilters[this.value]) $(this).prop('checked', true);
    });

    $('#bme-filter-sqft-min').val(modalFilters.sqft_min);
    $('#bme-filter-sqft-max').val(modalFilters.sqft_max);
    $('#bme-filter-year-built-min').val(modalFilters.year_built_min);
    $('#bme-filter-year-built-max').val(modalFilters.year_built_max);
    $('#bme-filter-lot-size-min').val(modalFilters.lot_size_min);
    $('#bme-filter-lot-size-max').val(modalFilters.lot_size_max);
    $('#bme-filter-entry-level-min').val(modalFilters.entry_level_min);
    $('#bme-filter-entry-level-max').val(modalFilters.entry_level_max);

    $('#bme-filter-available-by').val(modalFilters.available_by);
    $('#bme-filter-available-now').prop('checked', modalFilters.MLSPIN_AvailableNow);

    // Restore school quality filters (v6.30.3) - iOS-matching toggle design
    $('#near_a_elementary').prop('checked', modalFilters.near_a_elementary || false);
    $('#near_ab_elementary').prop('checked', modalFilters.near_ab_elementary || false);
    $('#near_a_middle').prop('checked', modalFilters.near_a_middle || false);
    $('#near_ab_middle').prop('checked', modalFilters.near_ab_middle || false);
    $('#near_a_high').prop('checked', modalFilters.near_a_high || false);
    $('#near_ab_high').prop('checked', modalFilters.near_ab_high || false);

    // Restore district rating picker (v6.30.6)
    const schoolGrade = modalFilters.school_grade || '';
    $('#school_grade').val(schoolGrade);
    $('.bme-district-grade-btn').removeClass('active');
    if (schoolGrade) {
      $(`.bme-district-grade-btn[data-grade="${schoolGrade}"]`).addClass('active');
    } else {
      $('.bme-district-grade-btn[data-grade=""]').addClass('active');  // "Any" button
    }

    // Restore selected agents
    $('#bme-selected-agents').empty();
    if (modalFilters.agent_ids && modalFilters.agent_ids.length > 0) {
      modalFilters.agent_ids.forEach(agentId => {
        // Try to get label from stored labels first
        let agentLabel = agentId;

        if (MLD_Map_App.agentLabels && MLD_Map_App.agentLabels[agentId]) {
          agentLabel = MLD_Map_App.agentLabels[agentId];
        } else {
          // Fall back to finding the label from the existing tags
          const $existingTag = $(`.bme-filter-tag[data-type="agent_ids"][data-value="${agentId}"]`);
          if ($existingTag.length > 0) {
            const tagText = $existingTag.text().replace('×', '').trim();
            agentLabel = tagText.replace('Agent: ', '');
          }
        }

        this.addSelectedAgent(agentId, agentLabel);
      });
    }
  },

  getCombinedFilters(currentModalState = MLD_Map_App.modalFilters, excludeKeys = []) {
    console.log('[getCombinedFilters Debug] Input modalState:', JSON.parse(JSON.stringify(currentModalState)));
    const combined = {};
    for (const type in MLD_Map_App.keywordFilters) {
      if (MLD_Map_App.keywordFilters[type].size > 0)
        combined[type] = Array.from(MLD_Map_App.keywordFilters[type]);
    }

    const tempCombined = { ...combined, ...currentModalState };
    const finalFilters = {};

    for (const key in tempCombined) {
      if (excludeKeys.includes(key)) continue;

      const value = tempCombined[key];
      const defaultValue = this.getModalDefaults()[key];

      if (JSON.stringify(value) !== JSON.stringify(defaultValue)) {
        if (
          (Array.isArray(value) && value.length > 0) ||
          (!Array.isArray(value) && value && value != 0)
        ) {
          finalFilters[key] = value;
        }
      }
    }

    finalFilters.PropertyType = MLD_Map_App.selectedPropertyType;

    const rentalTypes = ['Residential Lease', 'Commercial Lease'];
    if (rentalTypes.includes(MLD_Map_App.selectedPropertyType)) {
      // For rentals, only allow Active status
      finalFilters.status = ['Active'];
      // Keep available_by and MLSPIN_AvailableNow for rentals
    } else {
      // For non-rentals, remove rental-specific filters
      delete finalFilters.available_by;
      delete finalFilters.MLSPIN_AvailableNow;

      // Use the status filters as selected by the user
      // If no status is selected, default to Active
      if (!finalFilters.status || finalFilters.status.length === 0) {
        finalFilters.status = ['Active'];
      }
    }

    // Add direct property selection flag when user selected a specific MLS # or Address
    // This tells the backend to bypass status filters for direct property lookups
    if (MLD_Map_App.isSpecificPropertySearch) {
      const hasMLSNumber = combined['MLS Number'] && combined['MLS Number'].length > 0;
      const hasSpecificAddress = combined['Address'] && combined['Address'].length > 0 &&
        !combined['Address'].some(addr => addr.includes('(All Units)'));

      if (hasMLSNumber || hasSpecificAddress) {
        finalFilters.direct_property_selection = true;
      }
    }

    // Add polygon shapes to filters
    const allPolygonCoordinates = [];

    // Add drawn polygons
    if (MLD_Map_App.drawnPolygons && MLD_Map_App.drawnPolygons.length > 0) {
      const drawnCoordinates = MLD_Map_App.getPolygonCoordinates();
      if (drawnCoordinates && drawnCoordinates.length > 0) {
        allPolygonCoordinates.push(...drawnCoordinates);
      }
    }


    // Only add polygon_shapes if we have any polygons
    if (allPolygonCoordinates.length > 0) {
      finalFilters.polygon_shapes = allPolygonCoordinates;
    }

    // School quality filters (v6.30.3) - now use direct API parameter names
    // No mapping needed - near_a_elementary, near_ab_elementary, etc. pass through directly

    console.log('[getCombinedFilters Debug] Returning finalFilters:', JSON.parse(JSON.stringify(finalFilters)));
    return finalFilters;
  },

  populateHomeTypes(subtypes) {
    const $ = jQuery;
    const container = $('#bme-filter-home-type');

    // Get current selections before clearing
    const currentSelections = MLD_Map_App.modalFilters.home_type || [];

    container.empty();
    if (!subtypes || subtypes.length === 0) {
      container.html(
        `<p class="bme-placeholder">No specific home types available for this selection.</p>`
      );
      return;
    }

    const html = subtypes
      .map((type) => {
        const subtypeSlug = MLD_Core.slugify(type);
        const custom = MLD_Map_App.subtypeCustomizations[subtypeSlug] || {};

        const label = custom.label || type;
        const iconHTML = custom.icon
          ? `<img src="${custom.icon}" alt="${label}" class="bme-custom-icon">`
          : MLD_Core.getIconForType(type);

        // Check if this type was previously selected
        const isActive = currentSelections.includes(type) ? ' active' : '';
        return `<button class="bme-home-type-btn${isActive}" data-value="${type}">${iconHTML}<span>${label}</span></button>`;
      })
      .join('');

    container.html(html);
  },

  populateStatusTypes(statuses) {
    const container = jQuery('#bme-filter-status');

    // Get current selections before clearing
    const currentSelections = MLD_Map_App.modalFilters.status || [];

    container.empty();
    if (!statuses || statuses.length === 0) {
      container.html(
        `<p class="bme-placeholder">No statuses available for the current selection.</p>`
      );
      return;
    }

    const html = statuses
      .map((status) => {
        const isChecked = currentSelections.includes(status) ? ' checked' : '';
        return `<label><input type="checkbox" value="${status}"${isChecked}> ${status}</label>`;
      })
      .join('');

    container.html(html);
  },

  populateDynamicCheckboxes(containerId, options) {
    const container = jQuery(containerId);

    // Get current selections based on container ID
    let currentSelections = [];
    if (containerId === '#bme-filter-structure-type') {
      currentSelections = MLD_Map_App.modalFilters.structure_type || [];
    } else if (containerId === '#bme-filter-architectural-style') {
      currentSelections = MLD_Map_App.modalFilters.architectural_style || [];
    }

    container.empty();
    if (!options || options.length === 0) {
      container.html(`<p class="bme-placeholder">No options available.</p>`);
      return;
    }
    const html = options
      .map((opt) => {
        const isChecked = currentSelections.includes(opt.value) ? ' checked' : '';
        return `
            <label>
                <input type="checkbox" value="${opt.value}"${isChecked}> 
                <span class="bme-label-text">${opt.label}</span>
                <span class="bme-filter-count">(${opt.count})</span>
            </label>
        `;
      })
      .join('');
    container.html(html);
  },

  populateDynamicAmenityCheckboxes(amenities) {
    const container = jQuery('#bme-filter-amenities');

    // Get current selections before clearing
    const currentSelections = MLD_Map_App.modalFilters || {};

    container.empty();

    // Update Open House count separately
    if (amenities && amenities.open_house_only) {
      jQuery('#bme-open-house-count').text(`(${amenities.open_house_only.count})`);
      delete amenities.open_house_only; // Remove from amenities list
    } else {
      jQuery('#bme-open-house-count').text('(0)');
    }

    if (!amenities || Object.keys(amenities).length === 0) {
      container.html(`<p class="bme-placeholder">No amenities available for this selection.</p>`);
      return;
    }

    let html = '';
    for (const field in amenities) {
      const amenity = amenities[field];
      const isChecked = currentSelections[field] === true ? ' checked' : '';
      html += `
                <label>
                    <input type="checkbox" value="${field}"${isChecked}> 
                    <span class="bme-label-text">${amenity.label}</span>
                    <span class="bme-filter-count">(${amenity.count})</span>
                </label>
            `;
    }

    container.html(html);
  },

  handleBedsSelection(e) {
    const $ = jQuery;
    const $button = $(e.currentTarget);
    const $group = $button.closest('.bme-button-group');
    const isAnyButton = $button.data('value') == 0;

    if (isAnyButton) {
      $group.find('button').removeClass('active');
      $button.addClass('active');
      MLD_Map_App.modalFilters.beds = [];
    } else {
      $group.find('button[data-value="0"]').removeClass('active');
      $button.toggleClass('active');
      if ($group.find('.active').length === 0) {
        $group.find('button[data-value="0"]').addClass('active');
      }
      // Update modal state
      MLD_Map_App.modalFilters.beds = $group.find('button.active:not([data-value="0"])')
        .map((_, el) => $(el).data('value'))
        .get();
    }
    // Immediately update filter tags
    MLD_Filters.renderFilterTags();
    
    // Update count
    clearTimeout(MLD_Map_App.countUpdateTimer);
    MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
  },

  handleMinOnlySelection(e) {
    const $ = jQuery;
    const $button = $(e.currentTarget);
    const $group = $button.closest('.bme-button-group');
    $group.find('button').removeClass('active');
    $button.addClass('active');

    // Update modal state based on which filter group this is
    const groupId = $group.attr('id');
    const value = $button.data('value') || 0;

    if (groupId === 'bme-filter-beds') {
      MLD_Map_App.modalFilters.beds_min = value;  // v6.72.1: Min-only to align with iOS
    } else if (groupId === 'bme-filter-baths') {
      MLD_Map_App.modalFilters.baths_min = value;
    } else if (groupId === 'bme-filter-garage-spaces') {
      MLD_Map_App.modalFilters.garage_spaces_min = value;
    } else if (groupId === 'bme-filter-parking-total') {
      MLD_Map_App.modalFilters.parking_total_min = value;
    }

    // Immediately update filter tags
    MLD_Filters.renderFilterTags();
    
    // Update count
    clearTimeout(MLD_Map_App.countUpdateTimer);
    MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
  },

  renderAutocompleteSuggestions(suggestions, suggestionsId) {
    const $ = jQuery;
    const $container = $(suggestionsId);
    if (!$container.length) return;

    if (!suggestions || suggestions.length === 0) {
      $container.hide().empty();
      return;
    }
    const html = suggestions
      .map(
        (s) =>
          `<div class="bme-suggestion-item" data-type="${s.type}" data-value="${s.value}"><span>${s.value}</span><span class="bme-suggestion-type">${s.type}</span></div>`
      )
      .join('');
    $container.html(html).show();

    $container.find('.bme-suggestion-item').on('click', function () {
      const type = $(this).data('type');
      const value = $(this).data('value');

      // Analytics: Track autocomplete selection (v6.38.0)
      document.dispatchEvent(new CustomEvent('mld:autocomplete_select', {
        detail: { type: type, value: value }
      }));

      MLD_Filters.addKeywordFilter(type, value);
    });
  },

  addKeywordFilter(type, value) {
    const app = MLD_Map_App;
    if (!app.keywordFilters[type]) app.keywordFilters[type] = new Set();
    app.keywordFilters[type].add(value);

    // Also update MLD_Filters.keywordFilters to keep in sync
    if (!MLD_Filters.keywordFilters) MLD_Filters.keywordFilters = {};
    if (!MLD_Filters.keywordFilters[type]) MLD_Filters.keywordFilters[type] = new Set();
    MLD_Filters.keywordFilters[type].add(value);

    // Track if this is a specific property search (MLS Number or exact Address)
    // This will be used to determine if we should redirect to property details
    // Note: Street Address searches show multiple units, so they should NOT redirect
    app.lastSearchType = type;
    app.isSpecificPropertySearch =
      type === 'MLS Number' || (type === 'Address' && !value.includes('(All Units)'));

    // Flag to fit map bounds to filter results - applies to all keyword filter types
    // This ensures the map zooms/pans to show all matching results
    app.shouldFitToFilterResults = true;
    // Also store the timestamp to help debug timing issues
    app.fitToFilterResultsTimestamp = Date.now();
    console.log('[FIT BOUNDS] Flag SET in addKeywordFilter for type:', type, 'value:', value, 'timestamp:', app.fitToFilterResultsTimestamp);

    jQuery('#bme-search-input, #bme-search-input-modal').val('');
    jQuery('#bme-autocomplete-suggestions, #bme-autocomplete-suggestions-modal').hide().empty();

    this.renderFilterTags();
    MLD_Core.updateUrlHash();

    // For non-City/Neighborhood filters, we need to ensure fit bounds happens
    // Pass a flag to indicate this is an explicit filter selection
    MLD_API.refreshMapListings(true, 0, true); // Third param = fitToResults

    // Update boundaries if City or Neighborhood filter changed
    if ((type === 'City' || type === 'Neighborhood') && typeof MLD_CityBoundaries !== 'undefined') {
      // Request fit to bounds BEFORE updating boundaries - this ensures map pans to the selected location
      MLD_CityBoundaries.requestFitToBounds();
      MLD_CityBoundaries.updateBoundariesFromFilters();
    }

    // Visitor state persistence removed - filter states are not saved

  },

  removeFilter(type, value) {
    const app = MLD_Map_App;
    const defaults = this.getModalDefaults();

    // Clear specific property search flags when removing filters
    app.isSpecificPropertySearch = false;
    app.lastSearchType = null;

    if (type === 'polygon_shapes') {
      // Clear all polygon shapes
      app.drawnPolygons.forEach((polygonData) => {
        if (bmeMapData.provider === 'google' && polygonData.googlePolygon) {
          polygonData.googlePolygon.setMap(null);
        }
      });

      app.drawnPolygons = [];

      // Update UI and refresh
      MLD_Filters.renderFilterTags();
      MLD_API.refreshMapListings(true);
      return;
    } else if (type === 'agent_ids') {
      // Remove agent from filter
      MLD_Filters.removeSelectedAgent(value);
      // Also trigger a map refresh
      MLD_Core.updateUrlHash();
      MLD_API.refreshMapListings(true);
      return; // removeSelectedAgent already handles tag update
    } else if (type === 'price') {
      app.modalFilters.price_min = defaults.price_min;
      app.modalFilters.price_max = defaults.price_max;
    } else if (type.endsWith('_min') || type.endsWith('_max')) {
      app.modalFilters[type] = defaults[type];
      // If removing a min, also remove the corresponding max and vice versa for range filters
      if (type.endsWith('_min')) {
        const max_key = type.replace('_min', '_max');
        app.modalFilters[max_key] = defaults[max_key];
      } else {
        const min_key = type.replace('_max', '_min');
        app.modalFilters[min_key] = defaults[min_key];
      }
    } else if (app.keywordFilters[type]) {
      app.keywordFilters[type].delete(value);
      if (app.keywordFilters[type].size === 0) {
        delete app.keywordFilters[type];
      }

      // Also update MLD_Filters.keywordFilters to keep in sync
      if (MLD_Filters.keywordFilters && MLD_Filters.keywordFilters[type]) {
        MLD_Filters.keywordFilters[type].delete(value);
        if (MLD_Filters.keywordFilters[type].size === 0) {
          delete MLD_Filters.keywordFilters[type];
        }
      }

    } else {
      const filterType = typeof defaults[type];
      if (filterType === 'boolean') {
        app.modalFilters[type] = false;
      } else if (Array.isArray(defaults[type])) {
        app.modalFilters[type] = app.modalFilters[type].filter(
          (item) => String(item) !== String(value)
        );
      } else {
        app.modalFilters[type] = defaults[type];
      }
    }

    this.restoreModalUIToState();
    this.renderFilterTags();
    MLD_Core.updateUrlHash();

    // For City/Neighborhood filter changes, fit bounds to show the new area
    if (type === 'City' || type === 'Neighborhood') {
      MLD_Map_App.shouldFitToFilterResults = true;
      console.log('[FIT BOUNDS] Flag SET in removeFilter for type:', type, 'value:', value);
    }

    MLD_API.refreshMapListings(true);

    // Update boundaries if City or Neighborhood filter was removed
    if ((type === 'City' || type === 'Neighborhood') && typeof MLD_CityBoundaries !== 'undefined') {
      // Request fit to bounds when removing a city/neighborhood - pans to remaining selection or zooms out
      MLD_CityBoundaries.requestFitToBounds();
      MLD_CityBoundaries.updateBoundariesFromFilters();
    }

    // Visitor state persistence removed - filter states are not saved
  },

  updateStatusDisplayText() {
    const selectedStatuses = [];
    jQuery('#bme-filter-status-dropdown input[type="checkbox"]:checked').each(function () {
      selectedStatuses.push(jQuery(this).val());
    });

    let displayText = 'Select Status';
    if (selectedStatuses.length === 1) {
      displayText = selectedStatuses[0];
    } else if (selectedStatuses.length > 1) {
      displayText = selectedStatuses.length + ' selected';
    }

    jQuery('#bme-filter-status-display .bme-select-text').text(displayText);
  },

  renderFilterTags() {
    const $ = jQuery;
    // Render tags in both main container and modal container
    const $mainContainer = $('#bme-filter-tags-container');
    const $modalContainer = $('#bme-filter-tags-container-modal');

    // Clear both containers
    $mainContainer.empty();
    $modalContainer.empty();

    // Remove scroll detection classes
    $mainContainer.removeClass('has-overflow can-scroll');
    $modalContainer.removeClass('has-overflow can-scroll');

    const modalFilters = MLD_Map_App.modalFilters;
    const defaults = this.getModalDefaults();

    // Check if mobile
    const isMobile = window.innerWidth <= 768;
    const allTags = [];

    const createTag = (type, value, label) => {
      if (isMobile) {
        // Store tag data for mobile processing
        allTags.push({ type, value, label });
      } else {
        // Desktop behavior - render to both containers
        const $tag = $(
          `<div class="bme-filter-tag" data-type="${type}" data-value="${value}">${label} <span class="bme-filter-tag-remove">&times;</span></div>`
        );
        const $tagClone = $tag.clone();

        // Add click handlers to both
        $tag.find('.bme-filter-tag-remove').on('click', () => this.removeFilter(type, value));
        $tagClone.find('.bme-filter-tag-remove').on('click', () => this.removeFilter(type, value));

        $mainContainer.append($tag);
        $modalContainer.append($tagClone);
      }
    };

    for (const type in MLD_Map_App.keywordFilters) {
      MLD_Map_App.keywordFilters[type].forEach((value) => createTag(type, value, value));
    }

    // Add polygon filter tag if shapes exist
    if (MLD_Map_App.drawnPolygons && MLD_Map_App.drawnPolygons.length > 0) {
      const label =
        MLD_Map_App.drawnPolygons.length === 1
          ? 'Custom area (1 shape)'
          : `Custom area (${MLD_Map_App.drawnPolygons.length} shapes)`;
      createTag('polygon_shapes', 'all', label);
    }

    // Add agent filter tags
    if (modalFilters.agent_ids && modalFilters.agent_ids.length > 0) {
      modalFilters.agent_ids.forEach(agentId => {
        // Try to get label from stored labels first
        let agentLabel = agentId;

        if (MLD_Map_App.agentLabels && MLD_Map_App.agentLabels[agentId]) {
          agentLabel = MLD_Map_App.agentLabels[agentId];
        } else {
          // Fall back to getting from the selected agents container
          const $agentChip = $(`#bme-selected-agents [data-agent-id="${agentId}"]`);
          if ($agentChip.length > 0) {
            agentLabel = $agentChip.find('.bme-agent-chip-label').text();
          }
        }

        createTag('agent_ids', agentId, `Agent: ${agentLabel}`);
      });
    }


    if (modalFilters.price_min || modalFilters.price_max) {
      const min = MLD_Core.formatCurrency(modalFilters.price_min || 0);
      const max = modalFilters.price_max ? MLD_Core.formatCurrency(modalFilters.price_max) : 'Any';
      createTag('price', 'all', `Price: ${min} - ${max}`);
    }
    // v6.72.1: Beds now uses min-only to align with iOS
    if (modalFilters.beds_min != defaults.beds_min)
      createTag('beds_min', modalFilters.beds_min, `Beds: ${modalFilters.beds_min}+`);
    if (modalFilters.baths_min != defaults.baths_min)
      createTag('baths_min', modalFilters.baths_min, `Baths: ${modalFilters.baths_min}+`);
    if (modalFilters.garage_spaces_min != defaults.garage_spaces_min)
      createTag(
        'garage_spaces_min',
        modalFilters.garage_spaces_min,
        `Garage: ${modalFilters.garage_spaces_min}+`
      );
    if (modalFilters.parking_total_min != defaults.parking_total_min)
      createTag(
        'parking_total_min',
        modalFilters.parking_total_min,
        `Parking: ${modalFilters.parking_total_min}+`
      );

    modalFilters.home_type.forEach((ht) => createTag('home_type', ht, ht));
    modalFilters.status.forEach((s) => createTag('status', s, s));
    modalFilters.structure_type.forEach((s) => createTag('structure_type', s, s));
    modalFilters.architectural_style.forEach((s) => createTag('architectural_style', s, s));

    // School toggle keys to exclude from general boolean loop (handled separately below)
    const schoolToggleKeys = ['near_a_elementary', 'near_ab_elementary', 'near_a_middle', 'near_ab_middle', 'near_a_high', 'near_ab_high'];

    for (const key in modalFilters) {
      if (
        typeof modalFilters[key] === 'boolean' &&
        modalFilters[key] === true &&
        defaults.hasOwnProperty(key) &&
        !schoolToggleKeys.includes(key)  // v6.30.6 - Skip school toggles (handled below)
      ) {
        const label = MLD_Utils.get_field_label(key);
        createTag(key, true, label);
      }
    }

    const rangeFilters = {
      sqft: 'Sq Ft',
      lot_size: 'Lot Size',
      year_built: 'Year Built',
      entry_level: 'Unit Level',
    };

    for (const base in rangeFilters) {
      const minKey = `${base}_min`;
      const maxKey = `${base}_max`;
      const minVal = modalFilters[minKey];
      const maxVal = modalFilters[maxKey];
      const label = rangeFilters[base];

      if (minVal && maxVal) {
        createTag(minKey, `${minVal}-${maxVal}`, `${label}: ${minVal} - ${maxVal}`);
      } else if (minVal) {
        createTag(minKey, minVal, `${label}: ${minVal}+`);
      } else if (maxVal) {
        createTag(maxKey, maxVal, `${label}: Up to ${maxVal}`);
      }
    }

    // District rating chip (v6.30.6)
    if (modalFilters.school_grade) {
      createTag('school_grade', modalFilters.school_grade, `${modalFilters.school_grade}+ District`);
    }

    // School quality filter tags (v6.30.3) - iOS-matching toggle design
    // Updated v6.30.6: Use format "A/B - Rated High" per user request
    const schoolToggleLabels = {
      near_a_elementary: 'A - Rated Elementary',
      near_ab_elementary: 'A/B - Rated Elementary',
      near_a_middle: 'A - Rated Middle',
      near_ab_middle: 'A/B - Rated Middle',
      near_a_high: 'A - Rated High',
      near_ab_high: 'A/B - Rated High'
    };
    for (const toggleKey in schoolToggleLabels) {
      if (modalFilters[toggleKey] === true) {
        createTag(toggleKey, true, schoolToggleLabels[toggleKey]);
      }
    }

    $('#bme-search-input').attr('placeholder', 'City, Address, Neighborhood, Building, MLS#');

    // Mobile-specific tag rendering
    if (isMobile && allTags.length > 0) {
      // Show only first 2 tags on main container mobile
      const tagsToShow = allTags.slice(0, 2);
      const remainingCount = allTags.length - 2;

      // Function to render tags to a specific container
      const renderTagsToContainer = ($container, showAll = false) => {
        const tagsToRender = showAll ? allTags : tagsToShow;

        tagsToRender.forEach((tagData) => {
          const $tag = $(
            `<div class="bme-filter-tag" data-type="${tagData.type}" data-value="${tagData.value}">${tagData.label} <span class="bme-filter-tag-remove">&times;</span></div>`
          );
          $tag
            .find('.bme-filter-tag-remove')
            .on('click', () => this.removeFilter(tagData.type, tagData.value));
          $container.append($tag);
        });

        // Add "+X more" tag only to main container if there are more than 2 filters
        if (!showAll && remainingCount > 0) {
          const $moreTag = $(
            `<div class="bme-filter-tag bme-filter-tag-more">+${remainingCount} more</div>`
          );
          $moreTag.on('click', () => {
            // Open filter modal when clicking the more tag
            $('#bme-filters-modal-overlay').css('display', 'flex');
          });
          $container.append($moreTag);
        }
      };

      // Render limited tags to main container
      renderTagsToContainer($mainContainer, false);

      // Render all tags to modal container
      renderTagsToContainer($modalContainer, true);

      // Enhance touch interaction for filter removal
      $('.bme-filter-tag-remove').on('touchend', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).trigger('click');
      });
    }
  },

  updatePriceFromSlider() {
    const $ = jQuery;
    const priceSliderData = MLD_Map_App.priceSliderData;
    const minPercent =
      parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
    const maxPercent =
      parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;

    const sliderRange = priceSliderData.display_max - priceSliderData.min;

    const currentMin =
      sliderRange > 0
        ? Math.round(priceSliderData.min + (minPercent / 100) * sliderRange)
        : priceSliderData.min;
    $('#bme-filter-price-min')
      .val(MLD_Core.formatCurrency(currentMin))
      .data('raw-value', currentMin);

    if (maxPercent >= 100) {
      $('#bme-filter-price-max')
        .val(MLD_Core.formatCurrency(priceSliderData.display_max) + '+')
        .data('raw-value', '');
    } else {
      const currentMax =
        sliderRange > 0
          ? Math.round(priceSliderData.min + (maxPercent / 100) * sliderRange)
          : priceSliderData.display_max;
      $('#bme-filter-price-max')
        .val(MLD_Core.formatCurrency(currentMax))
        .data('raw-value', currentMax);
    }

    this.updatePriceSliderRangeAndHistogram();

    clearTimeout(MLD_Map_App.countUpdateTimer);
    MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
  },

  updateSliderFromInput() {
    const $ = jQuery;
    let minVal = parseFloat($('#bme-filter-price-min').data('raw-value'));
    let maxVal = parseFloat($('#bme-filter-price-max').data('raw-value'));

    const priceSliderData = MLD_Map_App.priceSliderData;
    const sliderMin = priceSliderData.min;
    const sliderMax = priceSliderData.display_max;
    const sliderRange = sliderMax - sliderMin;

    if (isNaN(minVal) && isNaN(maxVal)) {
      document.getElementById('bme-price-slider-handle-min').style.left = '0%';
      document.getElementById('bme-price-slider-handle-max').style.left = '100%';
      this.updatePriceSliderRangeAndHistogram();
      clearTimeout(MLD_Map_App.countUpdateTimer);
      MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
      return;
    }

    if (isNaN(minVal)) minVal = sliderMin;
    if (isNaN(maxVal)) maxVal = sliderMax;

    let minPercent = 0;
    let maxPercent = 100;

    if (sliderRange > 0) {
      minPercent = ((minVal - sliderMin) / sliderRange) * 100;
      maxPercent = ((maxVal - sliderMin) / sliderRange) * 100;

      minPercent = Math.max(0, Math.min(100, minPercent));
      maxPercent = Math.max(0, Math.min(100, maxPercent));
    }

    if (maxVal > sliderMax) {
      maxPercent = 100;
    }

    document.getElementById('bme-price-slider-handle-min').style.left = minPercent + '%';
    document.getElementById('bme-price-slider-handle-max').style.left = maxPercent + '%';

    this.updatePriceSliderRangeAndHistogram();

    clearTimeout(MLD_Map_App.countUpdateTimer);
    MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
  },

  updatePriceSliderRangeAndHistogram() {
    const $ = jQuery;
    const minPercent =
      parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
    const maxPercent =
      parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;

    const rangeEl = document.getElementById('bme-price-slider-range');
    rangeEl.style.left = minPercent + '%';
    rangeEl.style.width = maxPercent - minPercent + '%';

    $('#bme-price-histogram .bme-histogram-bar').each(function (index) {
      const barPercent = (index / (MLD_Map_App.priceSliderData.distribution.length || 1)) * 100;
      $(this).toggleClass('in-range', barPercent >= minPercent && barPercent < maxPercent);
    });
    const $outlierBar = $('.bme-histogram-bar-outlier');
    if ($outlierBar.length > 0) {
      $outlierBar.toggleClass('in-range', maxPercent >= 100);
    }
  },

  updatePriceSliderUI() {
    const $ = jQuery;
    const { min, display_max, distribution, outlier_count } = MLD_Map_App.priceSliderData;
    const modalFilters = MLD_Map_App.modalFilters;

    // Don't update inputs if user has manually modified them
    const $minInput = $('#bme-filter-price-min');
    const $maxInput = $('#bme-filter-price-max');

    if (!$minInput.data('user-modified')) {
      const currentMin = modalFilters.price_min !== '' ? modalFilters.price_min : min;
      $minInput
        .val(MLD_Core.formatCurrency(currentMin))
        .data('raw-value', currentMin);
    }

    if (!$maxInput.data('user-modified')) {
      const currentMax = modalFilters.price_max !== '' ? modalFilters.price_max : display_max;
      if (modalFilters.price_max === '' && currentMax >= display_max) {
        $maxInput
          .val(MLD_Core.formatCurrency(display_max) + '+')
          .data('raw-value', '');
      } else {
        $maxInput
          .val(MLD_Core.formatCurrency(currentMax))
          .data('raw-value', currentMax);
      }
    }

    const histogramContainer = $('#bme-price-histogram');
    histogramContainer.empty();

    if (!distribution || (distribution.length === 0 && outlier_count === 0) || display_max === 0) {
      histogramContainer.html('<div class="bme-placeholder">No price data available.</div>');
      $('#bme-price-slider').hide();
      return;
    }
    $('#bme-price-slider').show();

    const maxCount = Math.max(...distribution, outlier_count);
    distribution.forEach((count) => {
      const height = maxCount > 0 ? (count / maxCount) * 100 : 0;
      histogramContainer.append(`<div class="bme-histogram-bar" style="height: ${height}%"></div>`);
    });

    if (outlier_count > 0) {
      const height = maxCount > 0 ? (outlier_count / maxCount) * 100 : 0;
      const outlierLabel = `${outlier_count} listings above ${MLD_Core.formatCurrency(display_max)}`;
      const outlierBarHTML = `
                <div class="bme-histogram-bar bme-histogram-bar-outlier" style="height: ${height}%">
                    <span class="bme-histogram-bar-label">${outlierLabel}</span>
                </div>`;
      histogramContainer.append(outlierBarHTML);
    }

    this.updateSliderFromInput();
  },

  initCollapsibleSections() {
    const $ = jQuery;

    // Add click handlers to collapsible headers - use namespaced event
    $(document)
      .off('click.collapsible')
      .on('click.collapsible', '.bme-filter-header', function () {
        const $header = $(this);
        const $content = $header.next('.bme-filter-content');
        const $icon = $header.find('.bme-toggle-icon');

        // Toggle expanded class and content visibility
        $header.toggleClass('expanded');
        $content.slideToggle(200);

        // Update icon
        if ($header.hasClass('expanded')) {
          $icon.text('−'); // Minus sign
        } else {
          $icon.text('+'); // Plus sign
        }
      });
  },
};

// Expose globally
window.MLD_Filters = MLD_Filters;
