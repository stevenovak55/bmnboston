/**
 * BME Advanced Search - Frontend JavaScript
 * Version: 1.0.0
 * @param $
 */

(function ($) {
  'use strict';

  const BMEAdvancedSearch = {
    init() {
      this.bindEvents();
      this.initAutocomplete();
      this.initFilterToggles();
      this.initMapIntegration();
    },

    bindEvents() {
      $(document).on('submit', '.bme-advanced-search-form', this.handleSearchSubmit);
      $(document).on('click', '.bme-clear-filters', this.clearFilters);
      $(document).on('change', '.bme-filter-toggle', this.toggleFilterSection);
      $(document).on('click', '.bme-save-search', this.saveSearch);
      $(document).on('input', '.bme-price-range', this.updatePriceDisplay);
    },

    initAutocomplete() {
      $('.bme-autocomplete').each(function () {
        const $input = $(this);
        const type = $input.data('autocomplete-type');

        $input.autocomplete({
          source(request, response) {
            $.ajax({
              url: bme_search_ajax.ajax_url,
              dataType: 'json',
              data: {
                action: 'bme_autocomplete',
                nonce: bme_search_ajax.nonce,
                type,
                term: request.term,
              },
              success(data) {
                if (data.success) {
                  response(data.data.suggestions);
                } else {
                  response([]);
                }
              },
            });
          },
          minLength: 2,
          delay: 300,
          select(event, ui) {
            $input.val(ui.item.value);
            $input.data('selected-id', ui.item.id);
          },
        });
      });
    },

    initFilterToggles() {
      $('.bme-filter-section').each(function () {
        const $section = $(this);
        const $toggle = $section.find('.bme-filter-toggle');
        const $content = $section.find('.bme-filter-content');

        if ($toggle.is(':checked')) {
          $content.show();
        } else {
          $content.hide();
        }
      });
    },

    initMapIntegration() {
      if (typeof google !== 'undefined' && google.maps) {
        this.initGoogleMaps();
      }
    },

    initGoogleMaps() {
      const mapContainer = document.getElementById('bme-search-map');
      if (!mapContainer) return;

      const map = new google.maps.Map(mapContainer, {
        zoom: 10,
        center: { lat: 42.3601, lng: -71.0589 }, // Boston default
      });

      const drawingManager = new google.maps.drawing.DrawingManager({
        drawingMode: null,
        drawingControl: true,
        drawingControlOptions: {
          position: google.maps.ControlPosition.TOP_CENTER,
          drawingModes: ['polygon', 'rectangle', 'circle'],
        },
      });

      drawingManager.setMap(map);

      google.maps.event.addListener(drawingManager, 'overlaycomplete', function (event) {
        const overlay = event.overlay;
        const type = event.type;

        let coordinates;
        if (type === 'polygon') {
          coordinates = overlay
            .getPath()
            .getArray()
            .map((coord) => ({
              lat: coord.lat(),
              lng: coord.lng(),
            }));
        } else if (type === 'rectangle') {
          const bounds = overlay.getBounds();
          coordinates = {
            north: bounds.getNorthEast().lat(),
            south: bounds.getSouthWest().lat(),
            east: bounds.getNorthEast().lng(),
            west: bounds.getSouthWest().lng(),
          };
        } else if (type === 'circle') {
          coordinates = {
            center: {
              lat: overlay.getCenter().lat(),
              lng: overlay.getCenter().lng(),
            },
            radius: overlay.getRadius(),
          };
        }

        $('#bme-map-filter-type').val(type);
        $('#bme-map-filter-data').val(JSON.stringify(coordinates));
      });
    },

    handleSearchSubmit(e) {
      e.preventDefault();

      const $form = $(this);
      const $submitBtn = $form.find('[type="submit"]');
      const $resultsContainer = $('#bme-search-results');

      $submitBtn.prop('disabled', true).text('Searching...');
      $resultsContainer.html('<div class="bme-loading">Loading results...</div>');

      const formData = BMEAdvancedSearch.serializeFormData($form);

      $.ajax({
        url: bme_search_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_advanced_search',
          nonce: bme_search_ajax.nonce,
          ...formData,
        },
        success(response) {
          if (response.success) {
            BMEAdvancedSearch.displayResults(response.data);
            BMEAdvancedSearch.updateSearchAnalytics(formData, response.data.total);
          } else {
            $resultsContainer.html(
              '<div class="bme-error">Search failed: ' + response.data + '</div>'
            );
          }
        },
        error() {
          $resultsContainer.html(
            '<div class="bme-error">Search request failed. Please try again.</div>'
          );
        },
        complete() {
          $submitBtn.prop('disabled', false).text('Search Properties');
        },
      });
    },

    serializeFormData($form) {
      const data = {};

      $form.find('input, select, textarea').each(function () {
        const $field = $(this);
        const name = $field.attr('name');
        const type = $field.attr('type');

        if (!name || $field.is(':disabled')) return;

        if (type === 'checkbox' || type === 'radio') {
          if ($field.is(':checked')) {
            if (data[name]) {
              if (!Array.isArray(data[name])) {
                data[name] = [data[name]];
              }
              data[name].push($field.val());
            } else {
              data[name] = $field.val();
            }
          }
        } else {
          const value = $field.val();
          if (value !== '' && value !== null) {
            data[name] = value;
          }
        }
      });

      return data;
    },

    displayResults(data) {
      const $resultsContainer = $('#bme-search-results');

      if (data.properties.length === 0) {
        $resultsContainer.html(
          '<div class="bme-no-results">No properties found matching your criteria.</div>'
        );
        return;
      }

      let html = `
                <div class="bme-results-header">
                    <div class="bme-results-count">${data.total} properties found</div>
                    <div class="bme-results-controls">
                        <select class="bme-sort-select">
                            <option value="price_asc">Price: Low to High</option>
                            <option value="price_desc">Price: High to Low</option>
                            <option value="date_desc">Newest First</option>
                            <option value="bedrooms_desc">Most Bedrooms</option>
                            <option value="sqft_desc">Largest First</option>
                        </select>
                        <button class="bme-toggle-view" data-view="grid">Grid View</button>
                    </div>
                </div>
                <div class="bme-results-grid">
            `;

      data.properties.forEach((property) => {
        html += this.renderPropertyCard(property);
      });

      html += '</div>';

      if (data.pagination && data.pagination.total_pages > 1) {
        html += this.renderPagination(data.pagination);
      }

      $resultsContainer.html(html);
      this.bindResultsEvents();
    },

    renderPropertyCard(property) {
      const imageUrl =
        property.images && property.images.length > 0
          ? property.images[0]
          : bme_search_ajax.default_image;

      return `
                <div class="bme-property-card" data-property-id="${property.id}">
                    <div class="bme-property-image">
                        <img src="${imageUrl}" alt="${property.address}" loading="lazy">
                        <div class="bme-property-actions">
                            <button class="bme-favorite-btn" data-property-id="${property.id}">
                                <span class="dashicons dashicons-heart"></span>
                            </button>
                            <button class="bme-compare-btn" data-property-id="${property.id}">
                                <span class="dashicons dashicons-controls-repeat"></span>
                            </button>
                        </div>
                    </div>
                    <div class="bme-property-details">
                        <div class="bme-property-price">$${this.formatPrice(property.price)}</div>
                        <div class="bme-property-address">${property.address}</div>
                        <div class="bme-property-specs">
                            <span class="bme-bedrooms">${property.bedrooms} bed</span>
                            <span class="bme-bathrooms">${property.bathrooms} bath</span>
                            <span class="bme-sqft">${this.formatNumber(property.sqft)} sqft</span>
                        </div>
                        <div class="bme-property-status">${property.status}</div>
                        <div class="bme-property-mls">MLS: ${property.mls_id}</div>
                    </div>
                </div>
            `;
    },

    renderPagination(pagination) {
      let html = '<div class="bme-pagination">';

      if (pagination.current_page > 1) {
        html += `<button class="bme-page-btn" data-page="${pagination.current_page - 1}">Previous</button>`;
      }

      for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === pagination.current_page) {
          html += `<button class="bme-page-btn active" data-page="${i}">${i}</button>`;
        } else if (
          i === 1 ||
          i === pagination.total_pages ||
          Math.abs(i - pagination.current_page) <= 2
        ) {
          html += `<button class="bme-page-btn" data-page="${i}">${i}</button>`;
        } else if (i === pagination.current_page - 3 || i === pagination.current_page + 3) {
          html += '<span class="bme-pagination-dots">...</span>';
        }
      }

      if (pagination.current_page < pagination.total_pages) {
        html += `<button class="bme-page-btn" data-page="${pagination.current_page + 1}">Next</button>`;
      }

      html += '</div>';
      return html;
    },

    bindResultsEvents() {
      $(document).off('.bmeResults');

      $(document).on('click.bmeResults', '.bme-page-btn', function () {
        const page = $(this).data('page');
        BMEAdvancedSearch.loadPage(page);
      });

      $(document).on('change.bmeResults', '.bme-sort-select', function () {
        const sortBy = $(this).val();
        BMEAdvancedSearch.sortResults(sortBy);
      });

      $(document).on('click.bmeResults', '.bme-favorite-btn', function () {
        const propertyId = $(this).data('property-id');
        BMEAdvancedSearch.toggleFavorite(propertyId);
      });

      $(document).on('click.bmeResults', '.bme-compare-btn', function () {
        const propertyId = $(this).data('property-id');
        BMEAdvancedSearch.addToComparison(propertyId);
      });
    },

    clearFilters() {
      const $form = $('.bme-advanced-search-form');
      $form[0].reset();
      $('.bme-filter-content').hide();
      $('.bme-filter-toggle').prop('checked', false);
      $('#bme-search-results').empty();
    },

    toggleFilterSection() {
      const $toggle = $(this);
      const $content = $toggle.closest('.bme-filter-section').find('.bme-filter-content');

      if ($toggle.is(':checked')) {
        $content.slideDown();
      } else {
        $content.slideUp();
      }
    },

    updatePriceDisplay() {
      const $slider = $(this);
      const value = parseInt($slider.val());
      const $display = $slider.siblings('.bme-price-display');
      $display.text('$' + BMEAdvancedSearch.formatPrice(value));
    },

    saveSearch() {
      const $form = $('.bme-advanced-search-form');
      const searchData = BMEAdvancedSearch.serializeFormData($form);

      const searchName = prompt('Enter a name for this saved search:');
      if (!searchName) return;

      $.ajax({
        url: bme_search_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_save_search',
          nonce: bme_search_ajax.nonce,
          name: searchName,
          criteria: searchData,
        },
        success(response) {
          if (response.success) {
            alert('Search saved successfully!');
            BMEAdvancedSearch.updateSavedSearchesList();
          } else {
            alert('Failed to save search: ' + response.data);
          }
        },
      });
    },

    updateSearchAnalytics(searchData, totalResults) {
      $.ajax({
        url: bme_search_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'bme_search_analytics',
          nonce: bme_search_ajax.nonce,
          criteria: searchData,
          results_count: totalResults,
          timestamp: new Date().toISOString(),
        },
      });
    },

    formatPrice(price) {
      return parseInt(price).toLocaleString();
    },

    formatNumber(num) {
      return parseInt(num).toLocaleString();
    },
  };

  $(document).ready(function () {
    BMEAdvancedSearch.init();
  });
})(jQuery);
