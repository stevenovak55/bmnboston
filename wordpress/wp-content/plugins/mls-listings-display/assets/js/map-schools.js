/**
 * MLD Schools Module
 * Handles displaying schools on the map and property details
 * Version 1.0
 */
const MLD_Schools = {
  // Store school markers
  schoolMarkers: [],
  schoolsVisible: false,
  selectedSchoolTypes: ['elementary', 'middle', 'high', 'private', 'preschool'],
  currentPropertySchools: null,

  /**
   * Initialize the schools system
   */
  init() {
    this.addSchoolToggleButton();
    this.attachEventListeners();
    this.attachMapMoveListeners();
  },

  /**
   * Add toggle button for schools layer
   */
  addSchoolToggleButton() {
    // Check if we're using the new control panel
    const newControlPanel = document.querySelector('#bme-schools-control-placeholder');
    if (newControlPanel) {
      // New panel exists, it will be handled by map-controls-panel.js
      this.initialized = true;
      return;
    }

    // Fallback to old method if new panel doesn't exist
    const controlsContainer = document.querySelector('#bme-map-controls');

    if (!controlsContainer) return;

    // Create schools toggle container
    const schoolsContainer = document.createElement('div');
    schoolsContainer.className = 'bme-schools-toggle-container';
    schoolsContainer.innerHTML = `
      <label class="bme-schools-toggle-label">Schools</label>
      <div id="bme-schools-toggle" class="bme-schools-toggle">
        <div class="bme-schools-toggle-slider"></div>
      </div>
    `;

    // Create school types dropdown (initially hidden)
    const schoolTypesDropdown = document.createElement('div');
    schoolTypesDropdown.id = 'bme-schools-types-container';
    schoolTypesDropdown.className = 'bme-schools-types-container';
    schoolTypesDropdown.style.display = 'none';
    schoolTypesDropdown.innerHTML = `
      <div class="bme-schools-types">
        <label><input type="checkbox" value="elementary" checked> Elementary</label>
        <label><input type="checkbox" value="middle" checked> Middle School</label>
        <label><input type="checkbox" value="high" checked> High School</label>
        <label><input type="checkbox" value="private"> Private Schools</label>
        <label><input type="checkbox" value="preschool"> Preschool</label>
      </div>
    `;

    // Add styles
    if (!document.querySelector('#mld-schools-styles')) {
      const styles = document.createElement('style');
      styles.id = 'mld-schools-styles';
      styles.innerHTML = `
        /* Schools toggle container - positioned below draw toggle */
        .bme-schools-toggle-container {
          display: flex;
          align-items: center;
          gap: 8px;
          background: white;
          padding: 6px 10px;
          border-radius: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
          margin-top: 8px;
          pointer-events: auto !important; /* Critical: Override parent's pointer-events: none */
        }

        .bme-schools-toggle-label {
          font-size: 13px;
          font-weight: 500;
          color: #333;
          white-space: nowrap;
        }

        /* Schools toggle switch */
        .bme-schools-toggle {
          position: relative;
          width: 40px;
          height: 24px;
          background-color: #ccc;
          border-radius: 24px;
          cursor: pointer;
          transition: background-color 0.3s ease;
          -webkit-tap-highlight-color: transparent;
          touch-action: manipulation;
          user-select: none;
          -webkit-user-select: none;
          pointer-events: auto !important; /* Ensure toggle captures events */
        }

        .bme-schools-toggle.active {
          background-color: #2196F3;
        }

        .bme-schools-toggle-slider {
          position: absolute;
          top: 2px;
          left: 2px;
          width: 20px;
          height: 20px;
          background-color: white;
          border-radius: 50%;
          transition: transform 0.3s ease;
          box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .bme-schools-toggle.active .bme-schools-toggle-slider {
          transform: translateX(16px);
        }

        /* School types container - appears below toggle when active */
        .bme-schools-types-container {
          background: white;
          padding: 10px;
          border-radius: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
          margin-top: 8px;
          pointer-events: auto !important; /* Ensure dropdown captures events */
        }

        .bme-schools-types label {
          display: block;
          padding: 4px 0;
          cursor: pointer;
          font-size: 13px;
          color: #333;
        }

        .bme-schools-types input[type="checkbox"] {
          margin-right: 8px;
        }

        .mld-schools-dropdown {
          position: absolute;
          top: 100%;
          right: 0;
          background: white;
          border: 1px solid #ddd;
          border-radius: 4px;
          padding: 12px;
          margin-top: 5px;
          box-shadow: 0 4px 6px rgba(0,0,0,0.1);
          min-width: 200px;
        }

        .mld-schools-types label {
          display: block;
          padding: 4px 0;
          cursor: pointer;
        }

        .mld-schools-types input[type="checkbox"] {
          margin-right: 8px;
        }

        .mld-schools-legend {
          margin-top: 10px;
          padding-top: 10px;
          border-top: 1px solid #eee;
        }

        .legend-item {
          display: flex;
          align-items: center;
          gap: 8px;
          padding: 2px 0;
          font-size: 12px;
        }

        .legend-color {
          width: 12px;
          height: 12px;
          border-radius: 50%;
          border: 2px solid white;
          box-shadow: 0 0 2px rgba(0,0,0,0.3);
        }

        .legend-color.elementary { background: #4CAF50; }
        .legend-color.middle { background: #2196F3; }
        .legend-color.high { background: #FF9800; }
        .legend-color.private { background: #FF69B4; }

        /* School markers */
        .mld-school-marker {
          width: 32px;
          height: 32px;
          border-radius: 50%;
          border: 3px solid white;
          box-shadow: 0 4px 8px rgba(0,0,0,0.5);
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 16px;
          font-weight: bold;
          color: white;
          z-index: 100;
          transition: transform 0.2s;
        }

        .mld-school-marker:hover {
          transform: scale(1.15);
          box-shadow: 0 6px 12px rgba(0,0,0,0.7);
          z-index: 101;
        }

        .mld-school-marker.elementary { background: #4CAF50; }
        .mld-school-marker.middle { background: #2196F3; }
        .mld-school-marker.high { background: #FF9800; }
        .mld-school-marker.private { background: #FF69B4; }
        .mld-school-marker.preschool { background: #00BCD4; }

        /* School info popup */
        .mld-school-popup {
          padding: 10px;
          min-width: 200px;
        }

        .mld-school-popup h4 {
          margin: 0 0 8px 0;
          font-size: 14px;
          color: #333;
        }

        .mld-school-popup .school-type {
          display: inline-block;
          padding: 2px 6px;
          background: #f0f0f0;
          border-radius: 3px;
          font-size: 11px;
          margin-bottom: 5px;
        }

        .mld-school-popup .school-rating {
          display: flex;
          align-items: center;
          gap: 5px;
          margin: 5px 0;
        }

        .mld-school-popup .rating-stars {
          color: #FFB400;
        }

        .mld-school-popup .school-details {
          font-size: 12px;
          color: #666;
        }

        .mld-school-popup .school-details div {
          margin: 3px 0;
        }

        /* Property schools section */
        .mld-property-schools {
          padding: 20px;
          border-top: 1px solid #eee;
        }

        .mld-property-schools h3 {
          margin: 0 0 15px 0;
          font-size: 18px;
          color: #333;
        }

        .mld-school-card {
          border: 1px solid #ddd;
          border-radius: 8px;
          padding: 15px;
          margin-bottom: 15px;
          background: #f9f9f9;
        }

        .mld-school-card-header {
          display: flex;
          justify-content: space-between;
          align-items: start;
          margin-bottom: 10px;
        }

        .mld-school-card h4 {
          margin: 0;
          font-size: 16px;
          color: #333;
        }

        .mld-school-distance {
          background: #4A90E2;
          color: white;
          padding: 4px 8px;
          border-radius: 4px;
          font-size: 12px;
          font-weight: bold;
        }

        .mld-school-info {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
          gap: 10px;
          margin-top: 10px;
          font-size: 13px;
        }

        .mld-school-info-item {
          display: flex;
          flex-direction: column;
        }

        .mld-school-info-item label {
          color: #999;
          font-size: 11px;
          margin-bottom: 2px;
        }

        .mld-school-info-item span {
          color: #333;
          font-weight: 500;
        }
      `;
      document.head.appendChild(styles);
    }

    // Insert schools toggle after draw toggle
    const drawToggle = controlsContainer.querySelector('.bme-draw-toggle-container');
    if (drawToggle && drawToggle.parentNode) {
      drawToggle.parentNode.insertBefore(schoolsContainer, drawToggle.nextSibling);
      schoolsContainer.parentNode.insertBefore(schoolTypesDropdown, schoolsContainer.nextSibling);
    } else {
      controlsContainer.appendChild(schoolsContainer);
      controlsContainer.appendChild(schoolTypesDropdown);
    }
  },

  /**
   * Attach event listeners
   */
  attachEventListeners() {
    // Use jQuery for consistency with Draw toggle
    const $ = jQuery || $;

    // Schools toggle handler - use delegated event for dynamically created elements
    $(document).off('click.mldSchools', '#bme-schools-toggle'); // Remove any existing handlers
    $(document).on('click.mldSchools', '#bme-schools-toggle', (e) => {
      e.preventDefault();
      e.stopPropagation();

      const toggleBtn = $('#bme-schools-toggle');
      const schoolTypesContainer = $('#bme-schools-types-container');
      const isActive = toggleBtn.hasClass('active');

      // Check if we're in list view and switch to map view if needed
      const $wrapper = $('#bme-half-map-wrapper');
      const $mapViewBtn = $('.bme-view-mode-btn[data-mode="map"]');
      const isListView = $wrapper.hasClass('list-view') ||
                         $wrapper.hasClass('view-mode-list') ||
                         $('.bme-view-mode-btn[data-mode="list"]').hasClass('active');

      if (isListView && !isActive) {
        // Switch to map view when activating schools in list view
        // DEBUG: '[MLD Schools] Switching to map view for schools functionality');
        $mapViewBtn.trigger('click');
      }

      // DEBUG: '[MLD Schools] Toggle clicked, current state:', isActive ? 'active' : 'inactive');

      if (!isActive) {
        // Turning on
        toggleBtn.addClass('active');
        this.schoolsVisible = true;
        this.showSchools();
        schoolTypesContainer.show();
      } else {
        // Turning off
        toggleBtn.removeClass('active');
        this.schoolsVisible = false;
        this.hideSchools();
        schoolTypesContainer.hide();
      }
    });

    // School type checkboxes - use jQuery for consistency
    $('body').on('change', '.bme-schools-types input[type="checkbox"]', () => {
      this.updateSchoolTypes();
    });

    // Hide schools submenu when clicking outside of it or the control panel
    $(document).on('click', (e) => {
      const $target = $(e.target);
      const $schoolsContainer = $('#bme-schools-types-container');
      const $controlPanel = $('.bme-map-controls-panel');

      // Check if click is outside schools submenu and control panel
      if (!$target.closest('#bme-schools-types-container, .bme-map-controls-panel').length) {
        // Hide the schools submenu
        $schoolsContainer.hide();
      }
    });
  },

  /**
   * Attach map move listeners to refresh schools when map moves
   */
  attachMapMoveListeners() {
    // Wait for map to be initialized
    const checkMapInterval = setInterval(() => {
      if (window.MLD_Map_App && window.MLD_Map_App.map) {
        clearInterval(checkMapInterval);

        const map = window.MLD_Map_App.map;

        if (bmeMapData.provider === 'google') {
          // Google Maps idle event (fires after map stops moving)
          google.maps.event.addListener(map, 'idle', () => {
            if (this.schoolsVisible) {
              MLDLogger.debug('Map moved - refreshing schools');
              this.refreshSchoolsInView();
            }
          });
        } else {
          // Mapbox moveend event
          map.on('moveend', () => {
            if (this.schoolsVisible) {
              MLDLogger.debug('Map moved - refreshing schools');
              this.refreshSchoolsInView();
            }
          });
        }
      }
    }, 500);
  },

  /**
   * Refresh schools in current view
   */
  refreshSchoolsInView() {
    // Hide current schools and show new ones
    this.hideSchools();
    this.showSchools();
  },


  /**
   * Update selected school types
   */
  updateSchoolTypes() {
    const checkboxes = document.querySelectorAll('.bme-schools-types input[type="checkbox"]:checked');
    this.selectedSchoolTypes = Array.from(checkboxes).map(cb => cb.value);
    MLDLogger.debug('Updated selected school types:', this.selectedSchoolTypes);

    if (this.schoolsVisible) {
      this.hideSchools();
      this.showSchools();
    }
  },

  /**
   * Show schools on the map
   */
  showSchools() {
    const app = MLD_Map_App;
    if (!app.map) {
      MLDLogger.error('Map not initialized');
      return;
    }

    // Get current map bounds
    let bounds;
    if (bmeMapData.provider === 'google') {
      const mapBounds = app.map.getBounds();
      if (!mapBounds) {
        MLDLogger.error('Could not get map bounds');
        return;
      }
      bounds = {
        north: mapBounds.getNorthEast().lat(),
        south: mapBounds.getSouthWest().lat(),
        east: mapBounds.getNorthEast().lng(),
        west: mapBounds.getSouthWest().lng()
      };
    } else if (bmeMapData.provider === 'mapbox') {
      const mapBounds = app.map.getBounds();
      bounds = {
        north: mapBounds.getNorth(),
        south: mapBounds.getSouth(),
        east: mapBounds.getEast(),
        west: mapBounds.getWest()
      };
    }

    MLDLogger.debug('Fetching schools with bounds:', bounds);
    MLDLogger.debug('Selected types:', this.selectedSchoolTypes);

    // Fetch schools from server
    jQuery.ajax({
      url: bmeMapData.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_toggle_schools_layer',
        security: bmeMapData.security,
        show: true,
        types: this.selectedSchoolTypes,
        bounds: JSON.stringify(bounds)
      },
      success: (response) => {
        MLDLogger.debug('Schools response:', response);
        if (response.success && response.data.schools) {
          MLDLogger.debug('Found', response.data.schools.length, 'schools');
          this.displaySchools(response.data.schools);
        } else {
          MLDLogger.error('No schools in response');
        }
      },
      error: (xhr, status, error) => {
        MLDLogger.error('Failed to fetch schools:', error);
        MLDLogger.error('Response:', xhr.responseText);
      }
    });
  },

  /**
   * Display schools on the map
   */
  displaySchools(schools) {
    const app = MLD_Map_App;

    schools.forEach(school => {
      if (bmeMapData.provider === 'google') {
        this.createGoogleSchoolMarker(school);
      } else if (bmeMapData.provider === 'mapbox') {
        this.createMapboxSchoolMarker(school);
      }
    });
  },

  /**
   * Create school marker for Google Maps
   */
  createGoogleSchoolMarker(school) {
    const app = MLD_Map_App;

    // Create marker element
    const effectiveType = this.getEffectiveSchoolType(school);
    const markerDiv = document.createElement('div');
    markerDiv.className = `mld-school-marker ${effectiveType || 'other'}`;
    markerDiv.innerHTML = this.getSchoolIcon(effectiveType);

    try {
      // Try to use AdvancedMarkerElement if available
      if (app.AdvancedMarkerElement) {
        const marker = new app.AdvancedMarkerElement({
          position: { lat: parseFloat(school.latitude), lng: parseFloat(school.longitude) },
          map: app.map,
          content: markerDiv,
          title: school.name
        });

        // Add click listener
        markerDiv.onclick = () => {
          const infoWindow = new google.maps.InfoWindow({
            content: this.createSchoolPopupContent(school)
          });
          infoWindow.open(app.map, marker);
        };

        this.schoolMarkers.push(marker);
      } else {
        // Fallback to regular marker
        const marker = new google.maps.Marker({
          position: { lat: parseFloat(school.latitude), lng: parseFloat(school.longitude) },
          map: app.map,
          title: school.name,
          icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 8,
            fillColor: this.getSchoolColor(school),
            fillOpacity: 0.8,
            strokeColor: 'white',
            strokeWeight: 2
          }
        });

        // Create info window
        const infoWindow = new google.maps.InfoWindow({
          content: this.createSchoolPopupContent(school)
        });

        // Add click listener
        marker.addListener('click', () => {
          infoWindow.open(app.map, marker);
        });

        this.schoolMarkers.push(marker);
      }
    } catch (error) {
      MLDLogger.error('Error creating school marker:', error, school);
    }
  },

  /**
   * Determine if a school is private based on its name
   */
  isPrivateSchool(school) {
    const privateKeywords = ['Private', 'Catholic', 'Christian', 'Academy', 'Montessori', 'Prep', 'Parochial'];
    const name = school.name || '';
    return privateKeywords.some(keyword => name.includes(keyword));
  },

  /**
   * Get effective school type (considering private schools)
   */
  getEffectiveSchoolType(school) {
    // Check if it's a private school first
    if (this.isPrivateSchool(school)) {
      return 'private';
    }
    return school.school_level;
  },

  /**
   * Get school color based on level
   */
  getSchoolColor(school) {
    const effectiveType = this.getEffectiveSchoolType(school);
    const colors = {
      elementary: '#4CAF50',
      middle: '#2196F3',
      high: '#FF9800',
      private: '#FF69B4',  // Hot Pink
      preschool: '#00BCD4'
    };
    return colors[effectiveType] || '#757575';
  },

  /**
   * Create school marker for Mapbox
   */
  createMapboxSchoolMarker(school) {
    const app = MLD_Map_App;

    // Create marker element
    const effectiveType = this.getEffectiveSchoolType(school);
    const el = document.createElement('div');
    el.className = `mld-school-marker ${effectiveType || 'other'}`;
    el.innerHTML = this.getSchoolIcon(effectiveType);

    // Create marker
    const marker = new mapboxgl.Marker({ element: el })
      .setLngLat([parseFloat(school.longitude), parseFloat(school.latitude)])
      .setPopup(new mapboxgl.Popup({ offset: 25 })
        .setHTML(this.createSchoolPopupContent(school)))
      .addTo(app.map);

    this.schoolMarkers.push(marker);
  },

  /**
   * Get school icon based on type
   */
  getSchoolIcon(level) {
    const icons = {
      elementary: 'E',
      middle: 'M',
      high: 'H',
      private: 'P',
      preschool: 'K',
      university: 'U'
    };
    return icons[level] || 'S';
  },

  /**
   * Create school popup content
   */
  createSchoolPopupContent(school) {
    const rating = school.rating ?
      `<div class="school-rating">
        <span class="rating-stars">${'★'.repeat(Math.round(school.rating))}</span>
        <span>${school.rating}/5</span>
      </div>` : '';

    return `
      <div class="mld-school-popup">
        <h4>${school.name}</h4>
        <span class="school-type">${school.school_type || 'Public'} ${school.school_level || ''}</span>
        ${rating}
        <div class="school-details">
          ${school.grades ? `<div>Grades: ${school.grades}</div>` : ''}
          ${school.student_count ? `<div>Students: ${school.student_count}</div>` : ''}
          ${school.address ? `<div>${school.address}</div>` : ''}
          ${school.phone ? `<div>📞 ${school.phone}</div>` : ''}
        </div>
      </div>
    `;
  },

  /**
   * Hide schools from the map
   */
  hideSchools() {
    if (bmeMapData.provider === 'google') {
      this.schoolMarkers.forEach(marker => {
        marker.setMap(null);
      });
    } else if (bmeMapData.provider === 'mapbox') {
      this.schoolMarkers.forEach(marker => {
        marker.remove();
      });
    }
    this.schoolMarkers = [];
  },

  /**
   * Load schools for a specific property
   */
  loadPropertySchools(lat, lng, listingId) {
    jQuery.ajax({
      url: bmeMapData.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_get_nearby_schools',
        security: bmeMapData.security,
        lat: lat,
        lng: lng,
        listing_id: listingId,
        radius: 2
      },
      success: (response) => {
        if (response.success) {
          this.currentPropertySchools = response.data;
          this.displayPropertySchools(response.data);
        }
      }
    });
  },

  /**
   * Display schools in property details
   */
  displayPropertySchools(data) {
    const container = document.getElementById('mld-property-schools');
    if (!container) return;

    const { categorized } = data;

    let html = '<h3>🏫 Nearby Schools</h3>';

    // Elementary Schools
    if (categorized.elementary && categorized.elementary.length > 0) {
      html += this.createSchoolSection('Elementary Schools', categorized.elementary, '#4CAF50');
    }

    // Middle Schools
    if (categorized.middle && categorized.middle.length > 0) {
      html += this.createSchoolSection('Middle Schools', categorized.middle, '#2196F3');
    }

    // High Schools
    if (categorized.high && categorized.high.length > 0) {
      html += this.createSchoolSection('High Schools', categorized.high, '#FF9800');
    }

    // Private Schools
    if (categorized.private && categorized.private.length > 0) {
      html += this.createSchoolSection('Private Schools', categorized.private, '#9C27B0');
    }

    container.innerHTML = html;
  },

  /**
   * Create school section HTML
   */
  createSchoolSection(title, schools, color) {
    let html = `<div class="mld-schools-section">
      <h4 style="color: ${color}; margin: 20px 0 10px 0;">${title}</h4>`;

    schools.slice(0, 3).forEach(school => {
      html += `
        <div class="mld-school-card">
          <div class="mld-school-card-header">
            <div>
              <h4>${school.name}</h4>
              ${school.rating ? `<div class="school-rating">${'★'.repeat(Math.round(school.rating))} ${school.rating}/5</div>` : ''}
            </div>
            <div class="mld-school-distance">${school.distance_miles} mi</div>
          </div>
          <div class="mld-school-info">
            <div class="mld-school-info-item">
              <label>Type</label>
              <span>${school.school_type || 'Public'}</span>
            </div>
            ${school.grades ? `
            <div class="mld-school-info-item">
              <label>Grades</label>
              <span>${school.grades}</span>
            </div>` : ''}
            <div class="mld-school-info-item">
              <label>Walk Time</label>
              <span>${school.walk_time || school.walk_time_minutes} min</span>
            </div>
            <div class="mld-school-info-item">
              <label>Drive Time</label>
              <span>${school.drive_time || school.drive_time_minutes} min</span>
            </div>
          </div>
        </div>
      `;
    });

    html += '</div>';
    return html;
  }
};

// Initialize when map is ready
document.addEventListener('DOMContentLoaded', () => {
  // Wait for map to be initialized
  const checkMap = setInterval(() => {
    if (typeof MLD_Map_App !== 'undefined' && MLD_Map_App.map) {
      clearInterval(checkMap);
      MLD_Schools.init();
    }
  }, 100);

  // Also ensure event listeners are attached after controls panel creates the toggle
  setTimeout(() => {
    // Use jQuery instead of $ to avoid conflicts
    if (jQuery('#bme-schools-toggle').length > 0) {
      // Re-attach event listeners in case the toggle was created dynamically
      MLD_Schools.attachEventListeners();
      // DEBUG: '[MLD Schools] Event listeners re-attached');
    }
  }, 1500);
});

// Expose globally
window.MLD_Schools = MLD_Schools;