/**
 * MLD Map Module V2
 * Advanced map integration with clustering, nearby POIs, and commute times
 * Supports both Google Maps and Mapbox
 *
 * @version 2.0.0
 */

class MLDMap {
  constructor(options = {}) {
    this.options = {
      container: options.container || 'mld-map',
      provider: options.provider || 'google', // 'google' or 'mapbox'
      apiKey: options.apiKey || '',
      center: options.center || { lat: 40.7128, lng: -74.006 },
      zoom: options.zoom || 15,
      markers: options.markers || [],
      enableClustering: options.enableClustering !== false,
      enableStreetView: options.enableStreetView !== false,
      enableDrawing: options.enableDrawing || false,
      enablePOIs: options.enablePOIs !== false,
      enableCommute: options.enableCommute || false,
      enableBoundaries: options.enableBoundaries || false,
      style: options.style || 'default',
      controls: options.controls || ['zoom', 'fullscreen', 'mapType'],
      onMarkerClick: options.onMarkerClick || null,
      onMapClick: options.onMapClick || null,
      onBoundsChange: options.onBoundsChange || null,
    };

    this.map = null;
    this.markers = [];
    this.clusters = null;
    this.infoWindow = null;
    this.drawingManager = null;
    this.boundaries = [];
    this.pois = [];
    this.commuteLayer = null;

    this.init();
  }

  init() {
    const container = document.getElementById(this.options.container);
    if (!container) {
      MLDLogger.error('Map container not found');
      return;
    }

    if (this.options.provider === 'google') {
      this.initGoogleMap();
    } else if (this.options.provider === 'mapbox') {
      this.initMapboxMap();
    }
  }

  // ========================================
  // Google Maps Implementation
  // ========================================
  initGoogleMap() {
    if (!window.google?.maps) {
      MLDLogger.error('Google Maps API not loaded');
      return;
    }

    // Map styles
    const styles = {
      default: [],
      minimal: [
        {
          featureType: 'poi',
          elementType: 'labels',
          stylers: [{ visibility: 'off' }],
        },
        {
          featureType: 'transit',
          elementType: 'labels',
          stylers: [{ visibility: 'off' }],
        },
      ],
      dark: [
        { elementType: 'geometry', stylers: [{ color: '#242f3e' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#242f3e' }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#746855' }] },
      ],
    };

    // Create map
    this.map = new google.maps.Map(document.getElementById(this.options.container), {
      center: this.options.center,
      zoom: this.options.zoom,
      styles: styles[this.options.style] || styles.default,
      disableDefaultUI: true,
      zoomControl: this.options.controls.includes('zoom'),
      fullscreenControl: this.options.controls.includes('fullscreen'),
      mapTypeControl: this.options.controls.includes('mapType'),
      streetViewControl: this.options.enableStreetView,
      gestureHandling: 'cooperative',
    });

    // Create info window
    this.infoWindow = new google.maps.InfoWindow();

    // Add markers
    this.addGoogleMarkers();

    // Setup clustering
    if (this.options.enableClustering && this.markers.length > 1) {
      this.setupGoogleClustering();
    }

    // Setup drawing
    if (this.options.enableDrawing) {
      this.setupGoogleDrawing();
    }

    // Load POIs
    if (this.options.enablePOIs) {
      this.loadGooglePOIs();
    }

    // Setup commute overlay
    if (this.options.enableCommute) {
      this.setupGoogleCommute();
    }

    // Setup boundaries
    if (this.options.enableBoundaries) {
      this.loadGoogleBoundaries();
    }

    // Map events
    this.map.addListener('click', (e) => {
      if (this.options.onMapClick) {
        this.options.onMapClick({
          lat: e.latLng.lat(),
          lng: e.latLng.lng(),
        });
      }
    });

    this.map.addListener('bounds_changed', () => {
      if (this.options.onBoundsChange) {
        const bounds = this.map.getBounds();
        this.options.onBoundsChange({
          north: bounds.getNorthEast().lat(),
          south: bounds.getSouthWest().lat(),
          east: bounds.getNorthEast().lng(),
          west: bounds.getSouthWest().lng(),
        });
      }
    });
  }

  addGoogleMarkers() {
    this.options.markers.forEach((markerData) => {
      const marker = new google.maps.Marker({
        position: { lat: markerData.lat, lng: markerData.lng },
        map: this.map,
        title: markerData.title || '',
        icon: this.getGoogleMarkerIcon(markerData),
        animation: markerData.animation ? google.maps.Animation.DROP : null,
      });

      // Store reference
      this.markers.push(marker);

      // Click event
      marker.addListener('click', () => {
        if (markerData.content) {
          this.infoWindow.setContent(this.createInfoWindowContent(markerData));
          this.infoWindow.open(this.map, marker);
        }

        if (this.options.onMarkerClick) {
          this.options.onMarkerClick(markerData);
        }
      });
    });
  }

  getGoogleMarkerIcon(markerData) {
    if (markerData.icon) return markerData.icon;

    // Custom property marker
    if (markerData.type === 'property') {
      return {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 10,
        fillColor: markerData.color || '#0066FF',
        fillOpacity: 1,
        strokeColor: '#FFFFFF',
        strokeWeight: 2,
        labelOrigin: new google.maps.Point(0, 0),
      };
    }

    // POI markers
    const poiIcons = {
      school: '🏫',
      restaurant: '🍽️',
      shopping: '🛍️',
      transit: '🚇',
      park: '🌳',
      hospital: '🏥',
    };

    if (poiIcons[markerData.type]) {
      return {
        url: `data:image/svg+xml,${encodeURIComponent(`
                    <svg width="40" height="40" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="20" cy="20" r="18" fill="#FFFFFF" stroke="#E5E7EB" stroke-width="2"/>
                        <text x="20" y="26" text-anchor="middle" font-size="20">${poiIcons[markerData.type]}</text>
                    </svg>
                `)}`,
        scaledSize: new google.maps.Size(40, 40),
        anchor: new google.maps.Point(20, 20),
      };
    }

    return null;
  }

  setupGoogleClustering() {
    if (!window.MarkerClusterer) {
      MLDLogger.warning('MarkerClusterer library not loaded');
      return;
    }

    this.clusters = new MarkerClusterer(this.map, this.markers, {
      gridSize: 60,
      maxZoom: 14,
      styles: [
        {
          textColor: 'white',
          url:
            'data:image/svg+xml,' +
            encodeURIComponent(`
                    <svg width="53" height="53" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="26.5" cy="26.5" r="25" fill="#0066FF" stroke="#FFFFFF" stroke-width="3"/>
                    </svg>
                `),
          height: 53,
          width: 53,
        },
      ],
    });
  }

  setupGoogleDrawing() {
    this.drawingManager = new google.maps.drawing.DrawingManager({
      drawingMode: null,
      drawingControl: true,
      drawingControlOptions: {
        position: google.maps.ControlPosition.TOP_CENTER,
        drawingModes: ['polygon', 'circle', 'rectangle'],
      },
      polygonOptions: {
        fillColor: '#0066FF',
        fillOpacity: 0.2,
        strokeWeight: 2,
        strokeColor: '#0066FF',
        editable: true,
        draggable: true,
      },
      circleOptions: {
        fillColor: '#0066FF',
        fillOpacity: 0.2,
        strokeWeight: 2,
        strokeColor: '#0066FF',
        editable: true,
        draggable: true,
      },
      rectangleOptions: {
        fillColor: '#0066FF',
        fillOpacity: 0.2,
        strokeWeight: 2,
        strokeColor: '#0066FF',
        editable: true,
        draggable: true,
      },
    });

    this.drawingManager.setMap(this.map);

    // Drawing complete event
    google.maps.event.addListener(this.drawingManager, 'overlaycomplete', (event) => {
      const shape = event.overlay;
      const type = event.type;

      // Calculate area/bounds
      let area = 0;
      let bounds = null;

      if (type === 'polygon') {
        area = google.maps.geometry.spherical.computeArea(shape.getPath());
        bounds = this.getPolygonBounds(shape.getPath());
      } else if (type === 'circle') {
        area = Math.PI * Math.pow(shape.getRadius(), 2);
        bounds = shape.getBounds();
      } else if (type === 'rectangle') {
        bounds = shape.getBounds();
        area = google.maps.geometry.spherical.computeArea([
          bounds.getNorthEast(),
          new google.maps.LatLng(bounds.getNorthEast().lat(), bounds.getSouthWest().lng()),
          bounds.getSouthWest(),
          new google.maps.LatLng(bounds.getSouthWest().lat(), bounds.getNorthEast().lng()),
        ]);
      }

      MLDLogger.debug('Drawing complete:', { type, area, bounds });
    });
  }

  loadGooglePOIs() {
    // Places API functionality removed to avoid API charges
    MLDLogger.debug('POI loading disabled - Places API removed');
  }

  setupGoogleCommute() {
    // Create commute time overlay
    const commuteControl = document.createElement('div');
    commuteControl.className = 'mld-map-commute-control';
    commuteControl.innerHTML = `
            <div class="mld-commute-panel">
                <h4>Commute Times</h4>
                <div class="mld-commute-destination">
                    <input type="text" id="commute-destination" placeholder="Enter work address">
                    <button id="commute-calculate">Calculate</button>
                </div>
                <div class="mld-commute-results" id="commute-results"></div>
            </div>
        `;

    this.map.controls[google.maps.ControlPosition.TOP_LEFT].push(commuteControl);

    // Calculate commute times
    document.getElementById('commute-calculate')?.addEventListener('click', () => {
      const destination = document.getElementById('commute-destination').value;
      if (!destination) return;

      const service = new google.maps.DistanceMatrixService();
      const origin = this.options.center;

      service.getDistanceMatrix(
        {
          origins: [origin],
          destinations: [destination],
          travelMode: google.maps.TravelMode.DRIVING,
          transitOptions: {
            departureTime: new Date(Date.now() + 24 * 60 * 60 * 1000), // Tomorrow at 8am
            modes: ['BUS', 'RAIL', 'SUBWAY', 'TRAIN', 'TRAM'],
          },
          drivingOptions: {
            departureTime: new Date(Date.now() + 24 * 60 * 60 * 1000),
            trafficModel: google.maps.TrafficModel.BEST_GUESS,
          },
          unitSystem: google.maps.UnitSystem.IMPERIAL,
          avoidHighways: false,
          avoidTolls: false,
        },
        (response, status) => {
          if (status === 'OK') {
            const results = response.rows[0].elements[0];
            if (results.status === 'OK') {
              document.getElementById('commute-results').innerHTML = `
                            <div class="mld-commute-time">
                                <strong>Driving:</strong> ${results.duration.text}<br>
                                <strong>Distance:</strong> ${results.distance.text}
                            </div>
                        `;

              // Draw route
              this.drawRoute(origin, destination);
            }
          }
        }
      );
    });
  }

  drawRoute(origin, destination) {
    const directionsService = new google.maps.DirectionsService();
    const directionsRenderer = new google.maps.DirectionsRenderer({
      map: this.map,
      suppressMarkers: true,
      polylineOptions: {
        strokeColor: '#0066FF',
        strokeWeight: 4,
        strokeOpacity: 0.8,
      },
    });

    directionsService.route(
      {
        origin,
        destination,
        travelMode: google.maps.TravelMode.DRIVING,
      },
      (result, status) => {
        if (status === 'OK') {
          directionsRenderer.setDirections(result);
        }
      }
    );
  }

  loadGoogleBoundaries() {
    // Load neighborhood/city boundaries
    // This would typically load from a GeoJSON source
    const boundaryLayer = new google.maps.Data();
    boundaryLayer.setStyle({
      fillColor: '#0066FF',
      fillOpacity: 0.1,
      strokeColor: '#0066FF',
      strokeWeight: 2,
      strokeOpacity: 0.5,
    });
    boundaryLayer.setMap(this.map);

    // Example: Load from URL
    // boundaryLayer.loadGeoJson('/api/boundaries/neighborhood.json');
  }

  // ========================================
  // Mapbox Implementation
  // ========================================
  initMapboxMap() {
    if (!window.mapboxgl) {
      MLDLogger.error('Mapbox GL JS not loaded');
      return;
    }

    mapboxgl.accessToken = this.options.apiKey;

    this.map = new mapboxgl.Map({
      container: this.options.container,
      style: this.getMapboxStyle(),
      center: [this.options.center.lng, this.options.center.lat],
      zoom: this.options.zoom,
    });

    // Add controls
    if (this.options.controls.includes('zoom')) {
      this.map.addControl(new mapboxgl.NavigationControl());
    }

    if (this.options.controls.includes('fullscreen')) {
      this.map.addControl(new mapboxgl.FullscreenControl());
    }

    // Wait for map to load
    this.map.on('load', () => {
      this.addMapboxMarkers();

      if (this.options.enablePOIs) {
        this.loadMapboxPOIs();
      }

      if (this.options.enableBoundaries) {
        this.loadMapboxBoundaries();
      }
    });

    // Map events
    this.map.on('click', (e) => {
      if (this.options.onMapClick) {
        this.options.onMapClick({
          lat: e.lngLat.lat,
          lng: e.lngLat.lng,
        });
      }
    });
  }

  getMapboxStyle() {
    const styles = {
      default: 'mapbox://styles/mapbox/streets-v11',
      minimal: 'mapbox://styles/mapbox/light-v10',
      dark: 'mapbox://styles/mapbox/dark-v10',
      satellite: 'mapbox://styles/mapbox/satellite-streets-v11',
    };

    return styles[this.options.style] || styles.default;
  }

  addMapboxMarkers() {
    this.options.markers.forEach((markerData) => {
      // Create marker element
      const el = document.createElement('div');
      el.className = 'mld-mapbox-marker';

      if (markerData.type === 'property') {
        el.innerHTML = `
                    <div class="mld-marker-property" style="background: ${markerData.color || '#0066FF'}">
                        ${markerData.label || ''}
                    </div>
                `;
      } else {
        el.innerHTML = `<div class="mld-marker-poi">${this.getPOIEmoji(markerData.type)}</div>`;
      }

      // Create marker
      const marker = new mapboxgl.Marker(el)
        .setLngLat([markerData.lng, markerData.lat])
        .addTo(this.map);

      // Add popup
      if (markerData.content) {
        const popup = new mapboxgl.Popup({ offset: 25 }).setHTML(
          this.createInfoWindowContent(markerData)
        );
        marker.setPopup(popup);
      }

      // Click event
      el.addEventListener('click', () => {
        if (this.options.onMarkerClick) {
          this.options.onMarkerClick(markerData);
        }
      });

      this.markers.push(marker);
    });
  }

  getPOIEmoji(type) {
    const emojis = {
      school: '🏫',
      restaurant: '🍽️',
      shopping: '🛍️',
      transit: '🚇',
      park: '🌳',
      hospital: '🏥',
    };
    return emojis[type] || '📍';
  }

  loadMapboxPOIs() {
    // Mapbox POIs are included in the map style
    // We can add custom POI layers here if needed
  }

  loadMapboxBoundaries() {
    // Add boundary layer
    this.map.addSource('boundaries', {
      type: 'geojson',
      data: {
        type: 'FeatureCollection',
        features: [],
      },
    });

    this.map.addLayer({
      id: 'boundaries-fill',
      type: 'fill',
      source: 'boundaries',
      paint: {
        'fill-color': '#0066FF',
        'fill-opacity': 0.1,
      },
    });

    this.map.addLayer({
      id: 'boundaries-line',
      type: 'line',
      source: 'boundaries',
      paint: {
        'line-color': '#0066FF',
        'line-width': 2,
        'line-opacity': 0.5,
      },
    });
  }

  // ========================================
  // Shared Methods
  // ========================================
  createInfoWindowContent(markerData) {
    if (markerData.type === 'property') {
      return `
                <div class="mld-map-popup">
                    ${markerData.photo ? `<img src="${markerData.photo}" alt="${markerData.title}">` : ''}
                    <div class="mld-popup-content">
                        <h4>${markerData.title}</h4>
                        <p class="price">${markerData.price || ''}</p>
                        <p class="details">${markerData.details || ''}</p>
                        ${markerData.link ? `<a href="${markerData.link}" class="mld-popup-link">View Details</a>` : ''}
                    </div>
                </div>
            `;
    }
    return `
                <div class="mld-map-popup poi">
                    <h4>${markerData.title}</h4>
                    <p>${markerData.address || ''}</p>
                    ${markerData.rating ? `<p class="rating">★ ${markerData.rating}</p>` : ''}
                </div>
            `;
  }

  getPolygonBounds(path) {
    const bounds = new google.maps.LatLngBounds();
    path.forEach((latLng) => bounds.extend(latLng));
    return bounds;
  }

  // ========================================
  // Public Methods
  // ========================================
  setCenter(lat, lng) {
    if (this.options.provider === 'google') {
      this.map.setCenter({ lat, lng });
    } else {
      this.map.setCenter([lng, lat]);
    }
  }

  setZoom(zoom) {
    if (this.options.provider === 'google') {
      this.map.setZoom(zoom);
    } else {
      this.map.setZoom(zoom);
    }
  }

  addMarker(markerData) {
    this.options.markers.push(markerData);
    if (this.options.provider === 'google') {
      this.addGoogleMarkers();
    } else {
      this.addMapboxMarkers();
    }
  }

  clearMarkers() {
    if (this.options.provider === 'google') {
      this.markers.forEach((marker) => marker.setMap(null));
      if (this.clusters) {
        this.clusters.clearMarkers();
      }
    } else {
      this.markers.forEach((marker) => marker.remove());
    }
    this.markers = [];
  }

  togglePOIs(show) {
    if (this.options.provider === 'google') {
      this.pois.forEach((marker) => marker.setVisible(show));
    }
  }

  fitBounds(bounds) {
    if (this.options.provider === 'google') {
      const googleBounds = new google.maps.LatLngBounds(
        new google.maps.LatLng(bounds.south, bounds.west),
        new google.maps.LatLng(bounds.north, bounds.east)
      );
      this.map.fitBounds(googleBounds);
    } else {
      this.map.fitBounds([
        [bounds.west, bounds.south],
        [bounds.east, bounds.north],
      ]);
    }
  }

  destroy() {
    this.clearMarkers();
    if (this.drawingManager) {
      this.drawingManager.setMap(null);
    }
    this.map = null;
  }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MLDMap;
} else {
  window.MLDMap = MLDMap;
}
