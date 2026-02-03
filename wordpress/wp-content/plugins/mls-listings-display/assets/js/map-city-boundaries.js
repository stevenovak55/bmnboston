/**
 * MLD City & Neighborhood Boundaries Module
 * Handles fetching and displaying city and neighborhood boundary polygons on the map
 * Version 2.0
 */
const MLD_CityBoundaries = {
  // Store current boundaries on the map
  currentBoundaries: [],
  currentCities: new Set(), // Changed to store multiple cities
  currentNeighborhoods: [],
  neighborhoodLabels: [],
  // Flag for explicit user selection - forces fit to bounds
  explicitFitRequested: false,

  /**
   * Initialize the city boundaries system
   */
  init() {
    // Listen for city/neighborhood filter changes
    this.attachFilterListeners();

    // Listen for zoom changes to show/hide labels
    const app = MLD_Map_App;
    if (app.map) {
      if (bmeMapData.provider === 'google') {
        app.map.addListener('zoom_changed', () => {
          this.updateLabelVisibility();
        });
      }
      // Mapbox provider removed - Google Maps only for performance optimization
    }
  },

  /**
   * Attach listeners to filter system
   */
  attachFilterListeners() {
    // We'll hook into the filter system to detect when cities/neighborhoods are selected
    // This will be called from the main filter update functions
  },

  /**
   * Check current filters and update boundaries
   */
  updateBoundariesFromFilters() {
    const app = MLD_Map_App;

    // Check for city filters
    const cities = app.keywordFilters.City || new Set();
    const neighborhoods = app.keywordFilters.Neighborhood || new Set();

    // Handle city boundaries - process multiple cities
    this.updateCityBoundaries(cities);

    // Handle neighborhood boundaries
    this.updateNeighborhoodBoundaries(neighborhoods);
  },

  /**
   * Update city boundaries for multiple cities
   */
  updateCityBoundaries(cities) {
    // Find cities to remove (in current but not in new selection)
    const citiesToRemove = new Set([...this.currentCities].filter(city => !cities.has(city)));

    // Find cities to add (in new selection but not in current)
    const citiesToAdd = new Set([...cities].filter(city => !this.currentCities.has(city)));

    // Remove boundaries for deselected cities
    citiesToRemove.forEach(city => {
      this.removeCityBoundary(city);
    });

    // Add boundaries for newly selected cities
    citiesToAdd.forEach(city => {
      this.addCityBoundary(city);
    });

    // Update current cities set
    this.currentCities = new Set(cities);

    // If no cities selected, clear all
    if (cities.size === 0) {
      this.clearAllCityBoundaries();
    }
  },

  /**
   * Update neighborhood boundaries
   */
  updateNeighborhoodBoundaries(neighborhoods) {
    // Clear existing neighborhood boundaries
    this.clearNeighborhoodBoundaries();

    if (neighborhoods.size === 0) {
      return;
    }

    // Show boundaries for all selected neighborhoods
    neighborhoods.forEach(neighborhood => {
      this.showNeighborhoodBoundary(neighborhood);
    });
  },

  /**
   * Fetch and display boundary for a neighborhood
   */
  showNeighborhoodBoundary(neighborhoodName, state = 'Massachusetts') {
    const self = this;
    const currentCity = this.currentCity || 'Boston'; // Default to Boston or use current city

    jQuery.ajax({
      url: bmeMapData.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_get_city_boundary',
        security: bmeMapData.security,
        location: neighborhoodName,
        parent_city: currentCity,
        state: state,
        type: 'neighborhood'
      },
      success: function(response) {
        if (response.success && response.data) {
          self.drawNeighborhoodBoundary(response.data, neighborhoodName);
        } else {
          MLDLogger.debug('Could not fetch boundary for neighborhood: ' + neighborhoodName);
        }
      },
      error: function() {
        MLDLogger.error('Failed to fetch neighborhood boundary');
      }
    });
  },

  /**
   * Add a city boundary to the map (without clearing existing ones)
   */
  addCityBoundary(cityName, state = 'Massachusetts') {
    const self = this;

    // Track this city
    this.currentCities.add(cityName);

    // Make AJAX request to get city boundary
    jQuery.ajax({
      url: bmeMapData.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_get_city_boundary',
        security: bmeMapData.security,
        location: cityName,
        state: state,
        type: 'city'
      },
      success: function(response) {
        if (response.success && response.data) {
          self.drawBoundary(response.data, cityName);
        } else {
          MLDLogger.debug('Could not fetch boundary for ' + cityName);
        }
      },
      error: function() {
        MLDLogger.error('Failed to fetch city boundary for ' + cityName);
      }
    });
  },

  /**
   * Remove a specific city boundary from the map
   */
  removeCityBoundary(cityName) {
    const app = MLD_Map_App;

    if (bmeMapData.provider === 'google') {
      // Find and remove Google Maps polygons for this city
      this.currentBoundaries = this.currentBoundaries.filter(item => {
        if (item.cityName === cityName) {
          item.polygon.setMap(null);
          return false;
        }
        return true;
      });
    } // Mapbox provider code removed - Google Maps only for performance optimization

    // Remove from current cities set
    this.currentCities.delete(cityName);
  },

  /**
   * Draw city boundary on the map
   */
  drawBoundary(boundaryData, cityName) {
    const app = MLD_Map_App;
    if (!app.map) return;

    const geometry = boundaryData.geometry;
    const bbox = boundaryData.bbox;

    if (bmeMapData.provider === 'google') {
      this.drawGoogleMapsBoundary(geometry, bbox, 'city', cityName);
    } // Mapbox provider removed - Google Maps only for performance optimization
  },

  /**
   * Draw neighborhood boundary on the map
   */
  drawNeighborhoodBoundary(boundaryData, neighborhoodName) {
    const app = MLD_Map_App;
    if (!app.map) return;

    const geometry = boundaryData.geometry;
    const bbox = boundaryData.bbox;

    // Always use Google Maps now (Mapbox removed for performance optimization)
    this.drawGoogleMapsBoundary(geometry, bbox, 'neighborhood', neighborhoodName);

    // Add neighborhood label
    if (neighborhoodName) {
      this.addNeighborhoodLabel(bbox, neighborhoodName);
    }
  },

  /**
   * Draw boundary on Google Maps
   */
  drawGoogleMapsBoundary(geometry, bbox, type = 'city', name = '') {
    const app = MLD_Map_App;

    // Different styles for city vs neighborhood
    const styles = {
      city: {
        strokeColor: '#4A90E2',  // Blue for cities
        strokeOpacity: 0.8,
        strokeWeight: 3,
        fillColor: '#4A90E2',
        fillOpacity: 0.03,  // Very light fill
        clickable: false,
        zIndex: 1
      },
      neighborhood: {
        strokeColor: '#9B59B6',  // Purple for neighborhoods
        strokeOpacity: 0.7,
        strokeWeight: 2,
        fillColor: '#9B59B6',
        fillOpacity: 0.05,  // Slightly more visible fill
        clickable: false,
        zIndex: 2  // Higher than city
      }
    };

    const boundaryStyle = styles[type] || styles.city;

    if (geometry.type === 'Polygon') {
      const paths = this.convertGeoJsonToGooglePaths(geometry.coordinates[0]);
      const polygon = new google.maps.Polygon({
        paths: paths,
        ...boundaryStyle,
        map: app.map
      });

      if (type === 'neighborhood') {
        this.currentNeighborhoods.push(polygon);
      } else {
        this.currentBoundaries.push({ polygon, cityName: name });
      }

    } else if (geometry.type === 'MultiPolygon') {
      // Handle multiple polygons (some cities/neighborhoods have disconnected areas)
      geometry.coordinates.forEach(polygonCoords => {
        const paths = this.convertGeoJsonToGooglePaths(polygonCoords[0]);
        const polygon = new google.maps.Polygon({
          paths: paths,
          ...boundaryStyle,
          map: app.map
        });

        if (type === 'neighborhood') {
          this.currentNeighborhoods.push(polygon);
        } else {
          this.currentBoundaries.push({ polygon, cityName: name });
        }
      });
    }

    // Optionally fit map to bounds (only for city, not neighborhoods)
    if (bbox && type === 'city' && this.shouldFitToBounds()) {
      const bounds = new google.maps.LatLngBounds(
        new google.maps.LatLng(bbox.south, bbox.west),
        new google.maps.LatLng(bbox.north, bbox.east)
      );
      app.map.fitBounds(bounds, { padding: 50 });
    }
  },

  /**
   * Draw boundary on Mapbox
   */
  drawMapboxBoundary(geometry, bbox, type = 'city', name = '') {
    const app = MLD_Map_App;

    // Create unique IDs for each boundary
    const nameSlug = name.replace(/\s+/g, '-').toLowerCase();
    const sourceId = type === 'neighborhood'
      ? `neighborhood-boundary-source-${nameSlug}`
      : `city-boundary-source-${nameSlug}`;
    const layerId = type === 'neighborhood'
      ? `neighborhood-boundary-layer-${nameSlug}`
      : `city-boundary-layer-${nameSlug}`;
    const fillLayerId = type === 'neighborhood'
      ? `neighborhood-boundary-fill-${nameSlug}`
      : `city-boundary-fill-${nameSlug}`;

    // Different styles for city vs neighborhood
    const styles = {
      city: {
        lineColor: '#4A90E2',
        lineWidth: 3,
        fillColor: '#4A90E2',
        fillOpacity: 0.03
      },
      neighborhood: {
        lineColor: '#9B59B6',
        lineWidth: 2,
        fillColor: '#9B59B6',
        fillOpacity: 0.05
      }
    };

    const style = styles[type] || styles.city;

    // Remove existing layers and source if they exist
    if (app.map.getLayer(layerId)) {
      app.map.removeLayer(layerId);
    }
    if (app.map.getLayer(fillLayerId)) {
      app.map.removeLayer(fillLayerId);
    }
    if (app.map.getSource(sourceId)) {
      app.map.removeSource(sourceId);
    }

    // Add new source
    app.map.addSource(sourceId, {
      type: 'geojson',
      data: {
        type: 'Feature',
        geometry: geometry
      }
    });

    // Add fill layer
    app.map.addLayer({
      id: fillLayerId,
      type: 'fill',
      source: sourceId,
      paint: {
        'fill-color': style.fillColor,
        'fill-opacity': style.fillOpacity
      }
    });

    // Add outline layer
    app.map.addLayer({
      id: layerId,
      type: 'line',
      source: sourceId,
      paint: {
        'line-color': style.lineColor,
        'line-width': style.lineWidth,
        'line-opacity': 0.8
      }
    });

    // Store references for cleanup
    if (type === 'neighborhood') {
      this.currentNeighborhoods.push({ sourceId, layerId, fillLayerId, name });
    } else {
      this.currentBoundaries.push({ sourceId, layerId, fillLayerId, cityName: name });
    }

    // Optionally fit map to city bounds (not neighborhoods)
    if (bbox && type === 'city' && this.shouldFitToBounds()) {
      app.map.fitBounds([
        [bbox.west, bbox.south],
        [bbox.east, bbox.north]
      ], { padding: 50 });
    }
  },

  /**
   * Add neighborhood label to the map
   */
  addNeighborhoodLabel(bbox, name) {
    const app = MLD_Map_App;
    if (!app.map || !bbox || !name) return;

    // Calculate center of bbox for label placement
    const centerLat = (bbox.north + bbox.south) / 2;
    const centerLng = (bbox.east + bbox.west) / 2;

    if (bmeMapData.provider === 'google') {
      // Create custom overlay for label
      const labelDiv = document.createElement('div');
      labelDiv.className = 'mld-neighborhood-label';
      labelDiv.innerHTML = name;
      labelDiv.style.cssText = `
        position: absolute;
        background: rgba(255, 255, 255, 0.9);
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        color: #9B59B6;
        border: 1px solid #9B59B6;
        pointer-events: none;
        z-index: 100;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      `;

      // Only show labels when zoomed in enough
      const currentZoom = app.map.getZoom();
      if (currentZoom < 12) {
        labelDiv.style.display = 'none';
      }

      const overlay = new google.maps.OverlayView();
      overlay.onAdd = function() {
        const panes = this.getPanes();
        panes.overlayLayer.appendChild(labelDiv);
      };
      overlay.draw = function() {
        const projection = this.getProjection();
        const position = projection.fromLatLngToDivPixel(new google.maps.LatLng(centerLat, centerLng));
        if (position) {
          labelDiv.style.left = (position.x - labelDiv.offsetWidth / 2) + 'px';
          labelDiv.style.top = (position.y - labelDiv.offsetHeight / 2) + 'px';
        }
      };
      overlay.onRemove = function() {
        if (labelDiv.parentNode) {
          labelDiv.parentNode.removeChild(labelDiv);
        }
      };
      overlay.setMap(app.map);

      // Store both overlay and div for zoom control
      this.neighborhoodLabels.push({ overlay, labelDiv, name });

    } else if (bmeMapData.provider === 'mapbox') {
      // Add text layer for Mapbox
      const textLayerId = `neighborhood-text-${name.replace(/\s+/g, '-')}`;

      // Remove if exists
      if (app.map.getLayer(textLayerId)) {
        app.map.removeLayer(textLayerId);
      }
      if (app.map.getSource(textLayerId)) {
        app.map.removeSource(textLayerId);
      }

      app.map.addLayer({
        id: textLayerId,
        type: 'symbol',
        source: {
          type: 'geojson',
          data: {
            type: 'Feature',
            geometry: {
              type: 'Point',
              coordinates: [centerLng, centerLat]
            },
            properties: {
              title: name
            }
          }
        },
        layout: {
          'text-field': ['get', 'title'],
          'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
          'text-size': 12,
          'text-transform': 'uppercase',
          'text-letter-spacing': 0.05,
          'text-anchor': 'center'
        },
        paint: {
          'text-color': '#9B59B6',
          'text-halo-color': 'rgba(255, 255, 255, 0.9)',
          'text-halo-width': 2
        },
        minzoom: 12  // Only show when zoomed in
      });
      this.neighborhoodLabels.push({ layerId: textLayerId, name });
    }
  },

  /**
   * Update label visibility based on zoom
   */
  updateLabelVisibility() {
    const app = MLD_Map_App;
    if (!app.map) return;

    const currentZoom = app.map.getZoom();
    const showLabels = currentZoom >= 12;

    if (bmeMapData.provider === 'google') {
      this.neighborhoodLabels.forEach(item => {
        if (item.labelDiv) {
          item.labelDiv.style.display = showLabels ? 'block' : 'none';
        }
      });
    }
    // Mapbox handles this with minzoom property
  },

  /**
   * Convert GeoJSON coordinates to Google Maps LatLng
   */
  convertGeoJsonToGooglePaths(coordinates) {
    return coordinates.map(coord => ({
      lat: coord[1],
      lng: coord[0]
    }));
  },

  /**
   * Check if we should fit map to bounds
   */
  shouldFitToBounds() {
    // If explicit fit was requested (user selected from autocomplete), always fit
    if (this.explicitFitRequested) {
      this.explicitFitRequested = false; // Clear the flag after use
      return true;
    }
    // Otherwise, only fit to bounds when first selecting a city
    // Not when panning around or applying other filters
    return MLD_Map_App.isInitialLoad || !MLD_Map_App.hasInitialFetch;
  },

  /**
   * Request explicit fit to bounds for next city selection
   * Call this when user explicitly selects a city from autocomplete
   */
  requestFitToBounds() {
    this.explicitFitRequested = true;
  },

  /**
   * Clear all city boundaries from the map
   */
  clearAllCityBoundaries() {
    const app = MLD_Map_App;

    if (bmeMapData.provider === 'google') {
      // Remove Google Maps city polygons
      this.currentBoundaries.forEach(item => {
        if (item.polygon) {
          item.polygon.setMap(null);
        }
      });
    } else if (bmeMapData.provider === 'mapbox') {
      // Remove Mapbox city layers and sources
      this.currentBoundaries.forEach(item => {
        if (app.map.getLayer(item.layerId)) {
          app.map.removeLayer(item.layerId);
        }
        if (app.map.getLayer(item.fillLayerId)) {
          app.map.removeLayer(item.fillLayerId);
        }
        if (app.map.getSource(item.sourceId)) {
          app.map.removeSource(item.sourceId);
        }
      });
    }

    this.currentBoundaries = [];
    this.currentCities.clear();
  },

  /**
   * Legacy support - redirect to clearAllCityBoundaries
   */
  clearCityBoundary() {
    this.clearAllCityBoundaries();
  },

  /**
   * Clear neighborhood boundaries from the map
   */
  clearNeighborhoodBoundaries() {
    const app = MLD_Map_App;

    if (bmeMapData.provider === 'google') {
      // Remove Google Maps neighborhood polygons
      this.currentNeighborhoods.forEach(polygon => {
        polygon.setMap(null);
      });
      // Remove labels
      this.neighborhoodLabels.forEach(item => {
        if (item.overlay) {
          item.overlay.setMap(null);
        }
      });
    } else if (bmeMapData.provider === 'mapbox') {
      // Remove Mapbox neighborhood layers and sources
      this.currentNeighborhoods.forEach(item => {
        if (app.map.getLayer(item.layerId)) {
          app.map.removeLayer(item.layerId);
        }
        if (app.map.getLayer(item.fillLayerId)) {
          app.map.removeLayer(item.fillLayerId);
        }
        if (app.map.getSource(item.sourceId)) {
          app.map.removeSource(item.sourceId);
        }
      });
      // Remove labels
      this.neighborhoodLabels.forEach(item => {
        if (app.map.getLayer(item.layerId)) {
          app.map.removeLayer(item.layerId);
        }
        const sourceId = item.layerId;
        if (app.map.getSource(sourceId)) {
          app.map.removeSource(sourceId);
        }
      });
    }

    this.currentNeighborhoods = [];
    this.neighborhoodLabels = [];
  },

  /**
   * Clear all boundaries from the map
   */
  clearBoundaries() {
    this.clearCityBoundary();
    this.clearNeighborhoodBoundaries();
  },

  /**
   * Get current boundary state
   */
  hasActiveBoundary() {
    return this.currentBoundaries.length > 0 || this.currentNeighborhoods.length > 0;
  },

  /**
   * Get current cities
   */
  getCurrentCities() {
    return Array.from(this.currentCities);
  },

  /**
   * Get first current city (for backward compatibility)
   */
  getCurrentCity() {
    return this.getCurrentCities()[0] || null;
  },

  /**
   * Get current neighborhoods
   */
  getCurrentNeighborhoods() {
    const app = MLD_Map_App;
    return Array.from(app.keywordFilters.Neighborhood || new Set());
  }
};

// Expose globally
window.MLD_CityBoundaries = MLD_CityBoundaries;