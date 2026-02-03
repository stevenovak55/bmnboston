/**
 * MLD Similar Homes Module
 * Displays similar/nearby properties based on criteria
 *
 * @version 1.0.0
 */

class MLDSimilarHomes {
  constructor(options = {}) {
    this.options = {
      container: options.container || null,
      propertyId: options.propertyId || null,
      lat: options.lat || null,
      lng: options.lng || null,
      price: options.price || 0,
      beds: options.beds || 0,
      baths: options.baths || 0,
      sqft: options.sqft || 0,
      propertyType: options.propertyType || '',
      propertySubType: options.propertySubType || '',
      status: options.status || 'Active',
      closeDate: options.closeDate || '',
      daysOnMarket: options.daysOnMarket || 0,
      originalEntryTimestamp: options.originalEntryTimestamp || '',
      offMarketDate: options.offMarketDate || '',
      yearBuilt: options.yearBuilt || null,
      lotSizeAcres: options.lotSizeAcres || null,
      lotSizeSquareFeet: options.lotSizeSquareFeet || null,
      garageSpaces: options.garageSpaces || 0,
      parkingTotal: options.parkingTotal || 0,
      isWaterfront: options.isWaterfront || false,
      entryLevel: options.entryLevel || null,
      city: options.city || '',
      onPropertyClick: options.onPropertyClick || null,
      onLoadComplete: options.onLoadComplete || null,
    };

    this.properties = [];
    this.isLoading = false;
    this.container = null;
    this.currentPage = 1;
    this.totalPages = 1;
    this.perPage = 9;
    this.selectedProperties = new Set();
    this.removedProperties = new Set();
    this.allPropertiesData = [];

    // Initialize selected statuses based on current property status
    this.selectedStatuses = this.getDefaultStatuses();

    if (this.options.container) {
      this.init();
    }
  }

  getDefaultStatuses() {
    // Check if we have saved preferences
    const savedStatuses = this.getSavedStatuses();
    if (savedStatuses) {
      return savedStatuses;
    }

    // Default to all statuses checked
    return ['Active', 'Pending', 'Active Under Contract', 'Closed'];
  }

  getSavedStatuses() {
    // Try to get saved preferences from localStorage
    try {
      const saved = localStorage.getItem('mld_similar_homes_statuses');
      if (saved) {
        return JSON.parse(saved);
      }
    } catch (e) {
      MLDLogger.error('Error reading saved statuses:', e);
    }
    return null;
  }

  saveStatuses() {
    // Save current status selections to localStorage
    try {
      localStorage.setItem('mld_similar_homes_statuses', JSON.stringify(this.selectedStatuses));
    } catch (e) {
      MLDLogger.error('Error saving statuses:', e);
    }
  }

  init() {
    // Get container element
    this.container =
      typeof this.options.container === 'string'
        ? document.querySelector(this.options.container)
        : this.options.container;

    if (!this.container) {
      MLDLogger.error('Similar homes container not found');
      return;
    }

    // Show loading state
    this.showLoading();

    // Fetch similar properties
    this.fetchSimilarHomes();
  }

  async fetchSimilarHomes(page = 1) {
    this.isLoading = true;
    this.currentPage = page;

    try {
      const ajaxUrl = window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php';
      const nonce = window.mldPropertyData?.nonce || '';

      const formData = new FormData();
      formData.append('action', 'get_similar_homes');
      formData.append('nonce', nonce);
      formData.append('property_id', this.options.propertyId);
      formData.append('lat', this.options.lat);
      formData.append('lng', this.options.lng);
      formData.append('price', this.options.price);
      formData.append('beds', this.options.beds);
      formData.append('baths', this.options.baths);
      formData.append('sqft', this.options.sqft);
      formData.append('property_type', this.options.propertyType);
      formData.append('property_sub_type', this.options.propertySubType);
      formData.append('status', this.options.status);
      formData.append('close_date', this.options.closeDate);
      formData.append('year_built', this.options.yearBuilt || 0);
      formData.append('lot_size', this.options.lotSizeAcres || this.options.lotSizeSquareFeet || 0);
      formData.append('is_waterfront', this.options.isWaterfront);
      formData.append('garage_spaces', this.options.garageSpaces || 0);
      formData.append('parking_total', this.options.parkingTotal || 0);
      formData.append('entry_level', this.options.entryLevel || 0);
      formData.append('city', this.options.city);
      formData.append('page', page);
      formData.append('selected_statuses', JSON.stringify(this.selectedStatuses));

      const response = await fetch(ajaxUrl, {
        method: 'POST',
        body: formData,
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();

      if (result.success && result.data) {
        this.properties = result.data.properties || [];
        this.totalPages = result.data.total_pages || 1;
        this.perPage = result.data.per_page || 9;
        this.marketStats = result.data.market_stats || null;

        // Store all properties data if first page
        if (this.currentPage === 1) {
          this.allPropertiesData = result.data.all_properties || this.properties;
          // Initialize selected properties with only top 5
          this.allPropertiesData.slice(0, 5).forEach((prop) => {
            if (!this.removedProperties.has(prop.listing_id)) {
              this.selectedProperties.add(prop.listing_id);
            }
          });
        }

        this.render();

        if (this.options.onLoadComplete) {
          this.options.onLoadComplete({
            properties: this.properties,
            total: result.data.total,
            page: this.currentPage,
            totalPages: this.totalPages,
            marketStats: this.marketStats,
          });
        }
      } else {
        throw new Error(result.data || 'Failed to get similar homes');
      }
    } catch (error) {
      MLDLogger.error('Similar homes error:', error);
      this.showError(error.message);
    } finally {
      this.isLoading = false;
    }
  }

  render() {
    if (!this.container) {
      return;
    }

    // Show status filters even if no properties found yet
    const html = `
            <div class="mld-similar-homes">
                ${this.renderStatusFilters()}
                ${
                  this.properties.length === 0
                    ? this.renderNoResults()
                    : `
                    ${this.marketStats ? this.renderMarketStats() : ''}
                    <div class="mld-sh-grid">
                        ${this.renderProperties()}
                    </div>
                    ${this.totalPages > 1 ? this.renderPagination() : ''}
                `
                }
            </div>
        `;

    this.container.innerHTML = html;
    this.attachEventListeners();
  }

  renderStatusFilters() {
    const statuses = ['Active', 'Pending', 'Active Under Contract', 'Closed'];

    return `
            <div class="mld-sh-status-filters">
                <h4 class="mld-sh-filter-title">Include Properties with Status:</h4>
                <div class="mld-sh-status-checkboxes">
                    ${statuses
                      .map(
                        (status) => `
                        <label class="mld-sh-status-label">
                            <input type="checkbox" 
                                class="mld-sh-status-checkbox" 
                                value="${status}"
                                ${this.selectedStatuses.includes(status) ? 'checked' : ''}>
                            <span>${status === 'Closed' ? 'Sold' : status}</span>
                        </label>
                    `
                      )
                      .join('')}
                </div>
                <button class="mld-sh-apply-filters-btn">Update Results</button>
            </div>
        `;
  }

  renderNoResults() {
    return `
            <div class="mld-similar-homes-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                    <path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <p>No similar homes found with selected statuses</p>
                <small>Try selecting different property statuses above</small>
            </div>
        `;
  }

  renderMarketStats() {
    const selectedCount = this.selectedProperties.size;
    const totalCount = this.allPropertiesData.filter(
      (p) => !this.removedProperties.has(p.listing_id)
    ).length;
    const stats = this.calculateMarketStats();

    // Calculate subject property metrics
    const subjectPrice = this.options.price || 0;
    const subjectPricePerSqft =
      this.options.price && this.options.sqft > 0
        ? Math.round(this.options.price / this.options.sqft)
        : 0;
    const subjectDOM = this.options.daysOnMarket || 0;

    MLDLogger.debug('Subject property metrics:', {
      price: subjectPrice,
      pricePerSqft: subjectPricePerSqft,
      daysOnMarket: subjectDOM,
      optionsDaysOnMarket: this.options.daysOnMarket,
      allOptions: this.options,
    });

    return `
            <div class="mld-sh-market-stats">
                <div class="mld-sh-stats-header">
                    <div>
                        <h3 class="mld-sh-stats-title">Property Valuation Analysis</h3>
                        <p class="mld-sh-stats-subtitle">Comparing to ${selectedCount} of ${totalCount} similar properties</p>
                    </div>
                    <div class="mld-sh-stats-controls">
                        <button class="mld-sh-select-all-btn" ${selectedCount === totalCount ? 'disabled' : ''}>
                            Select All
                        </button>
                        <button class="mld-sh-deselect-all-btn" ${selectedCount === 0 ? 'disabled' : ''}>
                            Deselect All
                        </button>
                    </div>
                </div>
                
                ${this.renderSubjectPropertyCard(subjectPrice, subjectPricePerSqft, subjectDOM, stats)}
                
                ${
                  !stats
                    ? `
                    <div class="mld-sh-stats-empty">
                        <p>Select properties to see market averages</p>
                        <small>Check the boxes below properties to include them in the analysis</small>
                    </div>
                `
                    : `
                    <div class="mld-sh-stats-grid">
                        <div class="mld-sh-stat-card">
                            <div class="mld-sh-stat-value">${stats.total_homes}</div>
                            <div class="mld-sh-stat-label">Properties Analyzed</div>
                            <div class="mld-sh-stat-range">
                                Within 3 miles
                            </div>
                        </div>
                        
                        <div class="mld-sh-stat-card">
                            <div class="mld-sh-stat-value">${this.formatPrice(stats.price_range.min)} - ${this.formatPrice(stats.price_range.max)}</div>
                            <div class="mld-sh-stat-label">Price Range</div>
                            <div class="mld-sh-stat-range">
                                In selected properties
                            </div>
                        </div>
                        
                        <div class="mld-sh-stat-card">
                            <div class="mld-sh-stat-value">${stats.avg_beds} / ${stats.avg_baths}</div>
                            <div class="mld-sh-stat-label">Average Beds / Baths</div>
                            <div class="mld-sh-stat-range">Typical configuration</div>
                        </div>
                        
                        ${
                          stats.avg_sqft > 0
                            ? `
                            <div class="mld-sh-stat-card">
                                <div class="mld-sh-stat-value">${this.formatNumber(Math.round(stats.avg_sqft))} sqft</div>
                                <div class="mld-sh-stat-label">Average Size</div>
                                <div class="mld-sh-stat-range">Living area</div>
                            </div>
                        `
                            : ''
                        }
                    </div>
                `
                }
            </div>
        `;
  }

  renderPagination() {
    const prevDisabled = this.currentPage === 1;
    const nextDisabled = this.currentPage === this.totalPages;

    return `
            <div class="mld-sh-pagination">
                <button class="mld-sh-pagination-btn mld-sh-prev-page" ${prevDisabled ? 'disabled' : ''} data-page="${this.currentPage - 1}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Previous
                </button>
                <span class="mld-sh-page-info">
                    Page ${this.currentPage} of ${this.totalPages}
                </span>
                <button class="mld-sh-pagination-btn mld-sh-next-page" ${nextDisabled ? 'disabled' : ''} data-page="${this.currentPage + 1}">
                    Next
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        `;
  }

  renderProperties() {
    return this.properties
      .filter((property) => !this.removedProperties.has(property.listing_id))
      .map((property, index) => {
        const isSelected = this.selectedProperties.has(property.listing_id);
        // Get property's position in all properties to determine similarity rank
        const similarityRank =
          this.allPropertiesData.findIndex((p) => p.listing_id === property.listing_id) + 1;
        const similarityScore = property.similarity_score || 100 - similarityRank * 5; // Fallback score

        return `
                    <div class="mld-sh-property-wrapper">
                        <div class="mld-sh-property-card ${isSelected ? 'selected' : ''}" data-property-id="${property.listing_id}" data-index="${index}">
                            <button class="mld-sh-remove-btn" data-property-id="${property.listing_id}" title="Remove property">
                                <span class="mld-sh-remove-icon">×</span>
                            </button>
                            <a href="${property.url || `/property/${property.listing_id}/`}" class="mld-sh-property-link">
                                <div class="mld-sh-property-image">
                        ${
                          property.photo_url
                            ? `
                            <img src="${property.photo_url}" alt="${property.address}" loading="lazy">
                        `
                            : `
                            <div class="mld-sh-no-image">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                                    <rect x="3" y="8" width="18" height="13" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M3 10L9 4M9 4L15 10M9 4V8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </div>
                        `
                        }
                        ${
                          property.status_badge
                            ? `
                            <span class="mld-sh-status-badge ${property.status_badge.class}">
                                ${property.status_badge.text}
                            </span>
                        `
                            : ''
                        }
                        <div class="mld-sh-price-tag">
                            ${this.formatPrice(property.price)}
                        </div>
                    </div>
                    <div class="mld-sh-property-details">
                        <div class="mld-sh-property-stats">
                            ${property.beds ? `<span>${property.beds} bed${property.beds > 1 ? 's' : ''}</span>` : ''}
                            ${property.baths ? `<span>${property.baths} bath${property.baths > 1 ? 's' : ''}</span>` : ''}
                            ${property.sqft ? `<span>${this.formatNumber(property.sqft)} sqft</span>` : ''}
                        </div>
                        <div class="mld-sh-property-address">
                            ${property.address}
                        </div>
                        ${
                          property.city
                            ? `
                            <div class="mld-sh-property-city">
                                ${property.city}, ${property.state_or_province} ${property.postal_code}
                            </div>
                        `
                            : ''
                        }
                        ${
                          property.distance
                            ? `
                            <div class="mld-sh-property-distance">
                                ${property.distance} miles away
                            </div>
                        `
                            : ''
                        }
                        ${
                          property.status?.toLowerCase() === 'closed' && property.close_date
                            ? `
                            <div class="mld-sh-property-sold-date">
                                Sold ${this.formatDate(property.close_date)}
                            </div>
                        `
                            : ''
                        }
                    </div>
                </a>
            </div>
            <div class="mld-sh-card-footer">
                <label class="mld-sh-checkbox-wrapper">
                    <input type="checkbox" class="mld-sh-property-checkbox" 
                        data-property-id="${property.listing_id}" 
                        ${isSelected ? 'checked' : ''}>
                    <span class="mld-sh-checkbox-label">Include in market stats</span>
                </label>
                <span class="mld-sh-similarity-score" title="Similarity score based on price, size, location, and features">
                    ${Math.round(similarityScore)}% match
                </span>
            </div>
        </div>
        `;
      })
      .join('');
  }

  showLoading() {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="mld-similar-homes-loading">
                <div class="mld-sh-spinner"></div>
                <p>Finding similar homes...</p>
            </div>
        `;
  }

  showError(message) {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="mld-similar-homes-error">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <p>Unable to load similar homes</p>
                <small>${message}</small>
            </div>
        `;
  }

  showNoResults() {
    if (!this.container) return;

    // If we're showing no results in the render method, don't overwrite
    // Just render the whole component with status filters
    this.render();
  }

  attachEventListeners() {
    // Status filter events
    const applyFiltersBtn = this.container.querySelector('.mld-sh-apply-filters-btn');
    if (applyFiltersBtn) {
      applyFiltersBtn.addEventListener('click', () => {
        // Get selected statuses
        const checkboxes = this.container.querySelectorAll('.mld-sh-status-checkbox:checked');
        this.selectedStatuses = Array.from(checkboxes).map((cb) => cb.value);

        // Save preferences
        this.saveStatuses();

        // Reset to page 1 and fetch with new filters
        this.currentPage = 1;
        this.fetchSimilarHomes(1);
      });
    }

    // Pagination events
    const prevBtn = this.container.querySelector('.mld-sh-prev-page');
    const nextBtn = this.container.querySelector('.mld-sh-next-page');

    if (prevBtn && !prevBtn.disabled) {
      prevBtn.addEventListener('click', () => {
        const page = parseInt(prevBtn.dataset.page);
        this.fetchSimilarHomes(page);
      });
    }

    if (nextBtn && !nextBtn.disabled) {
      nextBtn.addEventListener('click', () => {
        const page = parseInt(nextBtn.dataset.page);
        this.fetchSimilarHomes(page);
      });
    }

    // Checkbox events
    const checkboxes = this.container.querySelectorAll('.mld-sh-property-checkbox');
    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', (e) => {
        const propertyId = e.target.dataset.propertyId;
        if (e.target.checked) {
          this.selectedProperties.add(propertyId);
        } else {
          this.selectedProperties.delete(propertyId);
        }
        this.updateMarketStats();
        this.updateCardSelection(propertyId, e.target.checked);
      });
    });

    // Remove button events
    const removeButtons = this.container.querySelectorAll('.mld-sh-remove-btn');
    removeButtons.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const propertyId = btn.dataset.propertyId;
        this.removedProperties.add(propertyId);
        this.selectedProperties.delete(propertyId);
        this.render();
      });
    });

    // Select all/deselect all buttons
    const selectAllBtn = this.container.querySelector('.mld-sh-select-all-btn');
    const deselectAllBtn = this.container.querySelector('.mld-sh-deselect-all-btn');

    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', () => {
        this.allPropertiesData.forEach((prop) => {
          if (!this.removedProperties.has(prop.listing_id)) {
            this.selectedProperties.add(prop.listing_id);
          }
        });
        this.render();
      });
    }

    if (deselectAllBtn) {
      deselectAllBtn.addEventListener('click', () => {
        this.selectedProperties.clear();
        this.render();
      });
    }

    // Property click events
    const propertyCards = this.container.querySelectorAll('.mld-sh-property-card');
    propertyCards.forEach((card) => {
      card.addEventListener('click', (e) => {
        // Don't trigger if clicking on remove button, checkbox, or link
        if (
          e.target.closest('.mld-sh-remove-btn') ||
          e.target.closest('.mld-sh-card-footer') ||
          e.target.closest('a')
        ) {
          return;
        }

        if (this.options.onPropertyClick) {
          e.preventDefault();
          const propertyId = card.dataset.propertyId;
          const index = parseInt(card.dataset.index);
          this.options.onPropertyClick(this.properties[index]);
        }
      });
    });
  }

  formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const options = { month: 'short', day: 'numeric', year: 'numeric' };
    return date.toLocaleDateString('en-US', options);
  }

  formatPrice(price) {
    if (!price) return 'Price N/A';
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

  getDOMDescription(days) {
    if (days < 7) return 'Very fast moving market';
    if (days < 14) return 'Fast moving market';
    if (days < 30) return 'Active market';
    if (days < 60) return 'Moderate market';
    return 'Slower market';
  }

  renderSubjectPropertyCard(price, pricePerSqft, dom, stats) {
    // Get the main photo URL from the page
    const photoUrl =
      window.mldPropertyDataV3?.photos?.[0] || window.mldPropertyData?.photos?.[0] || '';
    const address = window.mldPropertyDataV3?.address || window.mldPropertyData?.address || '';
    const beds = this.options.beds || 0;
    const baths = this.options.baths || 0;
    const sqft = this.options.sqft || 0;
    const status = this.options.status || 'Active';
    const yearBuilt = this.options.yearBuilt || null;
    const lotSize = this.options.lotSizeAcres || this.options.lotSizeSquareFeet || null;
    const lotSizeUnit = this.options.lotSizeAcres ? 'acres' : 'sqft';
    const garageSpaces = this.options.garageSpaces || 0;
    const parkingTotal = this.options.parkingTotal || 0;
    const isWaterfront = this.options.isWaterfront || false;
    const entryLevel = this.options.entryLevel || null;
    const isCondoType =
      this.options.propertySubType &&
      (this.options.propertySubType.toLowerCase().includes('condo') ||
        this.options.propertySubType.toLowerCase().includes('condominium'));

    return `
            <div class="mld-sh-subject-property">
                <h4 class="mld-sh-subject-title">Subject Property Analysis</h4>
                <div class="mld-sh-subject-card">
                    <div class="mld-sh-subject-image">
                        ${
                          photoUrl
                            ? `
                            <img src="${photoUrl}" alt="${address}" loading="lazy">
                        `
                            : `
                            <div class="mld-sh-no-image">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                                    <rect x="3" y="8" width="18" height="13" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M3 10L9 4M9 4L15 10M9 4V8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </div>
                        `
                        }
                    </div>
                    <div class="mld-sh-subject-details">
                        <div class="mld-sh-subject-price-section">
                            <div class="mld-sh-subject-price">${this.formatPrice(price)}</div>
                            ${
                              stats
                                ? `
                                <div class="mld-sh-market-comparison">
                                    <span class="mld-sh-avg-label">Market Avg:</span> ${this.formatPrice(stats.avg_price)}
                                    ${this.renderComparisonInline(price, stats.avg_price, 'price')}
                                </div>
                            `
                                : ''
                            }
                        </div>
                        
                        <div class="mld-sh-subject-address">${address}</div>
                        
                        <div class="mld-sh-subject-stats">
                            ${beds > 0 ? `<span>${beds} bed${beds > 1 ? 's' : ''}</span>` : ''}
                            ${baths > 0 ? `<span>${baths} bath${baths > 1 ? 's' : ''}</span>` : ''}
                            ${sqft > 0 ? `<span>${this.formatNumber(sqft)} sqft</span>` : ''}
                            ${yearBuilt ? `<span>Built ${yearBuilt}</span>` : ''}
                        </div>
                        
                        <div class="mld-sh-subject-details-extra">
                            ${
                              lotSize && this.options.propertyType?.toLowerCase() === 'residential'
                                ? `
                                <div class="mld-sh-detail-item">
                                    <span class="mld-sh-detail-label">Lot Size:</span>
                                    <span class="mld-sh-detail-value">${this.formatNumber(lotSize)} ${lotSizeUnit}</span>
                                </div>
                            `
                                : ''
                            }
                            ${
                              garageSpaces > 0
                                ? `
                                <div class="mld-sh-detail-item">
                                    <span class="mld-sh-detail-label">Garage:</span>
                                    <span class="mld-sh-detail-value">${garageSpaces} space${garageSpaces > 1 ? 's' : ''}</span>
                                </div>
                            `
                                : ''
                            }
                            ${
                              parkingTotal > 0
                                ? `
                                <div class="mld-sh-detail-item">
                                    <span class="mld-sh-detail-label">Total Parking:</span>
                                    <span class="mld-sh-detail-value">${parkingTotal} space${parkingTotal > 1 ? 's' : ''}</span>
                                </div>
                            `
                                : ''
                            }
                            ${
                              entryLevel && isCondoType
                                ? `
                                <div class="mld-sh-detail-item">
                                    <span class="mld-sh-detail-label">Unit Level:</span>
                                    <span class="mld-sh-detail-value">${entryLevel}</span>
                                </div>
                            `
                                : ''
                            }
                            ${
                              isWaterfront
                                ? `
                                <div class="mld-sh-detail-item mld-sh-waterfront">
                                    <span class="mld-sh-detail-label">🌊</span>
                                    <span class="mld-sh-detail-value">Waterfront Property</span>
                                </div>
                            `
                                : ''
                            }
                        </div>
                        
                        <div class="mld-sh-subject-comparisons">
                            ${
                              pricePerSqft > 0
                                ? `
                                <div class="mld-sh-comparison-row">
                                    <div class="mld-sh-metric-label">Price/SqFt:</div>
                                    <div class="mld-sh-metric-value">
                                        <span class="mld-sh-subject-value">$${pricePerSqft}</span>
                                        ${
                                          stats && stats.avg_price_per_sqft > 0
                                            ? `
                                            <span class="mld-sh-avg-value">(Avg: $${stats.avg_price_per_sqft})</span>
                                            ${this.renderComparisonInline(pricePerSqft, stats.avg_price_per_sqft, 'price')}
                                        `
                                            : ''
                                        }
                                    </div>
                                </div>
                            `
                                : ''
                            }
                            
                            <div class="mld-sh-comparison-row">
                                <div class="mld-sh-metric-label">Days on Market:</div>
                                <div class="mld-sh-metric-value">
                                    <span class="mld-sh-subject-value">${dom} days</span>
                                    ${
                                      stats
                                        ? `
                                        <span class="mld-sh-avg-value">(Avg: ${Math.round(stats.avg_days_on_market)} days)</span>
                                        ${this.renderComparisonInline(dom, stats.avg_days_on_market, 'days')}
                                    `
                                        : ''
                                    }
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
  }

  renderComparison(subjectValue, avgValue, type) {
    if (!subjectValue || !avgValue) return '';

    const diff = subjectValue - avgValue;
    const percent = Math.round((diff / avgValue) * 100);
    const isHigherGood = type !== 'days'; // For days on market, lower is better

    let className = '';
    let prefix = '';

    if (diff > 0) {
      className = isHigherGood ? 'above' : 'worse';
      prefix = '+';
    } else if (diff < 0) {
      className = isHigherGood ? 'below' : 'better';
    } else {
      return '<div class="mld-sh-comparison-diff equal">At market average</div>';
    }

    const formattedDiff =
      type === 'price' ? this.formatPrice(Math.abs(diff)) : `${Math.abs(diff)} days`;

    return `
            <div class="mld-sh-comparison-diff ${className}">
                ${diff > 0 ? prefix : ''}${formattedDiff} (${prefix}${percent}%)
            </div>
        `;
  }

  renderComparisonInline(subjectValue, avgValue, type) {
    if (!subjectValue || !avgValue) return '';

    const diff = subjectValue - avgValue;
    const percent = Math.round((diff / avgValue) * 100);
    const isHigherGood = type !== 'days'; // For days on market, lower is better

    let className = '';
    let prefix = '';

    if (diff > 0) {
      className = isHigherGood ? 'above' : 'worse';
      prefix = '+';
    } else if (diff < 0) {
      className = isHigherGood ? 'below' : 'better';
    } else {
      return '<span class="mld-sh-inline-diff equal">At avg</span>';
    }

    return `
            <span class="mld-sh-inline-diff ${className}">
                ${prefix}${percent}%
            </span>
        `;
  }

  calculateSubjectDOM() {
    // Use the same logic as V3 display for the subject property
    const status = (this.options.status || 'Active').toLowerCase();

    // Try to get the date values from the page data
    const originalEntry =
      window.mldPropertyDataV3?.originalEntryTimestamp ||
      window.mldPropertyData?.originalEntryTimestamp ||
      this.options.originalEntryTimestamp;
    const offMarketDate =
      window.mldPropertyDataV3?.offMarketDate ||
      window.mldPropertyData?.offMarketDate ||
      this.options.offMarketDate;
    const closeDate =
      window.mldPropertyDataV3?.closeDate ||
      window.mldPropertyData?.closeDate ||
      this.options.closeDate;

    if (!originalEntry) return 0;

    const startTimestamp = new Date(originalEntry).getTime();
    if (isNaN(startTimestamp)) return 0;

    let endTimestamp;

    if (status === 'active') {
      endTimestamp = Date.now();
    } else if (['closed', 'pending', 'active under contract'].includes(status)) {
      if (offMarketDate) {
        endTimestamp = new Date(offMarketDate).getTime();
      } else if (status === 'closed' && closeDate) {
        endTimestamp = new Date(closeDate).getTime();
      } else {
        endTimestamp = Date.now();
      }
    } else {
      endTimestamp = Date.now();
    }

    if (isNaN(endTimestamp)) return 0;

    const diffDays = Math.floor((endTimestamp - startTimestamp) / (1000 * 60 * 60 * 24));
    return Math.max(0, diffDays);
  }

  calculateMarketStats() {
    const selectedProps = this.allPropertiesData.filter(
      (p) => this.selectedProperties.has(p.listing_id) && !this.removedProperties.has(p.listing_id)
    );

    if (selectedProps.length === 0) {
      return null;
    }

    let totalPrice = 0;
    let totalPricePerSqft = 0;
    let totalDOM = 0;
    let totalBeds = 0;
    let totalBaths = 0;
    let totalSqft = 0;
    let validPricePerSqft = 0;
    let validDOM = 0;
    let minPrice = Infinity;
    let maxPrice = 0;

    selectedProps.forEach((prop) => {
      const price = parseFloat(prop.price) || 0;
      const sqft = parseFloat(prop.sqft) || 0;
      const beds = parseFloat(prop.beds) || 0;
      const baths = parseFloat(prop.baths) || 0;
      const dom = parseFloat(prop.days_on_market) || 0;

      totalPrice += price;
      totalBeds += beds;
      totalBaths += baths;

      if (price > 0) {
        minPrice = Math.min(minPrice, price);
        maxPrice = Math.max(maxPrice, price);
      }

      if (sqft > 0) {
        totalSqft += sqft;
        const pricePerSqft = price / sqft;
        totalPricePerSqft += pricePerSqft;
        validPricePerSqft++;
      }

      if (dom > 0) {
        totalDOM += dom;
        validDOM++;
      }
    });

    return {
      total_homes: selectedProps.length,
      avg_price: totalPrice / selectedProps.length,
      avg_price_per_sqft:
        validPricePerSqft > 0 ? Math.round(totalPricePerSqft / validPricePerSqft) : 0,
      avg_days_on_market: validDOM > 0 ? totalDOM / validDOM : 0,
      avg_beds: Math.round((totalBeds / selectedProps.length) * 10) / 10,
      avg_baths: Math.round((totalBaths / selectedProps.length) * 10) / 10,
      avg_sqft: validPricePerSqft > 0 ? Math.round(totalSqft / validPricePerSqft) : 0,
      price_range: {
        min: minPrice === Infinity ? 0 : minPrice,
        max: maxPrice,
      },
    };
  }

  updateMarketStats() {
    const statsContainer = this.container.querySelector('.mld-sh-market-stats');
    if (statsContainer) {
      const newStatsHtml = this.renderMarketStats();
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = newStatsHtml;
      const newStats = tempDiv.firstElementChild;

      if (newStats) {
        statsContainer.parentNode.replaceChild(newStats, statsContainer);

        // Re-attach event listeners for the new buttons
        const selectAllBtn = this.container.querySelector('.mld-sh-select-all-btn');
        const deselectAllBtn = this.container.querySelector('.mld-sh-deselect-all-btn');

        if (selectAllBtn) {
          selectAllBtn.addEventListener('click', () => {
            this.allPropertiesData.forEach((prop) => {
              if (!this.removedProperties.has(prop.listing_id)) {
                this.selectedProperties.add(prop.listing_id);
              }
            });
            this.render();
          });
        }

        if (deselectAllBtn) {
          deselectAllBtn.addEventListener('click', () => {
            this.selectedProperties.clear();
            this.render();
          });
        }
      }
    }
  }

  updateCardSelection(propertyId, selected) {
    const card = this.container.querySelector(
      `.mld-sh-property-card[data-property-id="${propertyId}"]`
    );
    if (card) {
      if (selected) {
        card.classList.add('selected');
      } else {
        card.classList.remove('selected');
      }
    }
  }

  // Public methods
  refresh() {
    this.fetchSimilarHomes();
  }

  updateOptions(options) {
    Object.assign(this.options, options);
    this.init();
  }

  getProperties() {
    return this.properties;
  }

  destroy() {
    if (this.container) {
      this.container.innerHTML = '';
    }
    this.properties = [];
    this.currentIndex = 0;
  }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MLDSimilarHomes;
} else {
  window.MLDSimilarHomes = MLDSimilarHomes;
}
