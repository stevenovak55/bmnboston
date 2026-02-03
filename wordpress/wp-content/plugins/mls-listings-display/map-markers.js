/**
 * MLD Map Markers Module
 * Version 4.0
 * Handles the creation, rendering, and interaction of map markers and popups.
 *
 * Version 4.0 Changes:
 * - Improved marker update logic with proper lifecycle management
 * - Added validation for undefined listing data
 * - Fixed marker type detection and content update logic
 * - Added coordinate validation (range checking)
 * - Improved error handling for invalid marker data
 * - Fixed multi-unit marker click handler to use modal
 * - Added helper functions: getMarkerType, createMarkerByType, markerNeedsContentUpdate
 */
const MLD_Markers = {
  userLocationMarker: null, // To hold the user's location marker

  /**
   * Creates a special marker for the user's current location.
   * This marker is separate from the property markers.
   * @param {Object}  position - The lat/lng coordinates for the marker.
   * @param {boolean} isMapbox - Flag to determine which map provider is used.
   */
  createUserLocationMarker(position, isMapbox = false) {
    const app = MLD_Map_App;

    // If a user marker already exists, remove it before creating a new one.
    if (this.userLocationMarker) {
      this.removeUserLocationMarker();
    }

    // Create the custom HTML element for the marker (blue dot)
    const userMarkerPin = document.createElement('div');
    userMarkerPin.style.cssText =
      'width: 20px; height: 20px; border-radius: 50%; background-color: #4285F4; border: 2px solid white; box-shadow: 0 0 5px rgba(0,0,0,0.5); cursor: pointer;';
    userMarkerPin.title = 'Your Location';

    // Always use Google Maps now (Mapbox removed for performance optimization)
    // Ensure AdvancedMarkerElement is available
    if (app.AdvancedMarkerElement) {
      this.userLocationMarker = new app.AdvancedMarkerElement({
        map: app.map,
        position,
        content: userMarkerPin,
        title: 'Your Location',
        zIndex: 9999, // Ensure it's on top of other markers
      });
    }
  },

  /**
   * Removes the user location marker from the map.
   */
  removeUserLocationMarker() {
    if (this.userLocationMarker) {
      if (typeof this.userLocationMarker.map !== 'undefined') {
        // Google Maps AdvancedMarkerElement
        this.userLocationMarker.map = null;
      } else if (this.userLocationMarker.remove) {
        // Mapbox GL JS Marker
        this.userLocationMarker.remove();
      }
      this.userLocationMarker = null;
    }
  },

  /**
   * Returns the current count of markers on the map.
   * Used by safety net checks in map-core.js to verify markers were rendered.
   * @returns {number} The number of markers currently on the map.
   */
  getMarkerCount() {
    return MLD_Map_App.markers ? MLD_Map_App.markers.length : 0;
  },

  /**
   * Renders a completely new set of markers on the map.
   * v6.20.14: Added retry logic if marker library isn't ready yet.
   * @param {Array} listings - The listings to render.
   * @param {number} retryCount - Internal retry counter (default 0).
   */
  renderNewMarkers(listings, retryCount = 0) {
    const app = MLD_Map_App;

    // v6.20.14: Check if marker library is ready before attempting to create markers
    if (!app.markerLibraryReady || !app.AdvancedMarkerElement) {
      const maxRetries = 10;
      const retryDelay = 200; // ms

      if (retryCount < maxRetries) {
        setTimeout(() => {
          this.renderNewMarkers(listings, retryCount + 1);
        }, retryDelay);
        return;
      } else {
        console.error('%c[MLD Markers] CRITICAL: Marker library still not ready after ' + maxRetries + ' retries!',
          'color: #F44336; font-weight: bold', {
            markerLibraryReady: app.markerLibraryReady,
            hasAdvancedMarkerElement: !!app.AdvancedMarkerElement
          });
        // Continue anyway to see what happens - might help diagnose the issue
      }
    }

    this.clearMarkers();
    const markerData = this.getMarkerDataForListings(listings);

    // v6.20.14: Track marker creation stats for debugging
    let markersCreated = 0;
    let markersFailed = 0;

    markerData.forEach((data) => {
      const markerCountBefore = app.markers.length;

      // Only show price and cluster markers, no dots
      if (data.type === 'price') {
        this.createPriceMarker(data.listing, data.lng, data.lat, data.id, data.group || null);
      } else if (data.type === 'cluster') {
        this.createUnitClusterMarker(data.group, data.lng, data.lat, data.id);
      }
      // Skip dot markers entirely

      // Check if marker was actually created
      if (app.markers.length > markerCountBefore) {
        markersCreated++;
      } else if (data.type === 'price' || data.type === 'cluster') {
        markersFailed++;
      }
    });

    this.reapplyActiveHighlights();
  },

  /**
   * Efficiently updates markers on the map based on the current view.
   * @param {Array} listingsInView - The listings currently visible.
   */
  updateMarkersOnMap(listingsInView) {
    const app = MLD_Map_App;
    const requiredMarkerData = this.getMarkerDataForListings(listingsInView);
    const requiredMarkerMap = new Map(requiredMarkerData.map((m) => [m.id, m]));

    const markersToRemove = [];
    const markersToKeep = [];
    const markersToCreate = new Set(); // Use Set to avoid duplicates

    // First pass: categorize existing markers
    app.markers.forEach((markerInfo) => {
      const desiredData = requiredMarkerMap.get(markerInfo.id);

      if (!desiredData) {
        // Marker no longer needed
        markersToRemove.push(markerInfo);
      } else {
        // Check if marker needs updating
        const currentType = this.getMarkerType(markerInfo.element);

        if (
          desiredData.type !== currentType ||
          this.markerNeedsContentUpdate(markerInfo, desiredData)
        ) {
          // Needs update: remove old and create new
          markersToRemove.push(markerInfo);
          markersToCreate.add(desiredData.id); // Track that we need to create this
        } else {
          // Keep as-is
          markersToKeep.push(markerInfo);
        }
      }
    });

    // Remove markers that are no longer needed or need updating
    markersToRemove.forEach(({ marker }) => {
      try {
        if (bmeMapData.provider === 'google') {
          if (marker && marker.map) {
            marker.map = null;
          }
        } else if (marker && marker.remove) {
          marker.remove();
        }
      } catch (e) {
        MLDLogger.error('Error removing marker:', e);
      }
    });

    // Update the markers array
    app.markers = markersToKeep;

    // Create new markers
    requiredMarkerData.forEach((data) => {
      // Check if this marker already exists in keepList
      const existingMarker = markersToKeep.find((m) => m.id === data.id);

      // Only create if it doesn't exist or was marked for recreation
      if (!existingMarker || markersToCreate.has(data.id)) {
        this.createMarkerByType(data);
      }
    });

    this.reapplyActiveHighlights();
  },

  /**
   * Helper function to determine marker type from element
   * @param element
   */
  getMarkerType(element) {
    if (!element) return null;
    if (element.classList.contains('bme-price-marker')) return 'price';
    if (element.classList.contains('bme-dot-marker')) return 'dot';
    if (element.classList.contains('bme-unit-cluster-marker')) return 'cluster';
    // Check for container with dot marker child
    if (element.querySelector && element.querySelector('.bme-dot-marker')) return 'dot';
    return null;
  },

  /**
   * Helper function to create marker by type
   * @param data
   */
  createMarkerByType(data) {
    try {
      // Only show price and cluster markers, no dots
      if (data.type === 'price') {
        this.createPriceMarker(data.listing, data.lng, data.lat, data.id, data.group || null);
      } else if (data.type === 'cluster') {
        this.createUnitClusterMarker(data.group, data.lng, data.lat, data.id);
      }
      // Skip dot markers entirely
    } catch (e) {
      MLDLogger.error('Error creating marker:', e, data);
    }
  },

  /**
   * Helper to check if marker content needs updating
   * @param markerInfo
   * @param desiredData
   */
  markerNeedsContentUpdate(markerInfo, desiredData) {
    if (desiredData.type === 'cluster') {
      const app = MLD_Map_App;
      const firstListing = desiredData.group[0];
      const streetNumber = firstListing.StreetNumber || '';
      const streetName = firstListing.StreetName || '';
      const expectedText =
        `${desiredData.group.length} Units` +
        (app.map.getZoom() >= 17 ? ` at ${streetNumber} ${streetName}` : '');
      const currentText = markerInfo.element.textContent
        ? markerInfo.element.textContent.trim()
        : '';
      return currentText !== expectedText.trim();
    }
    return false;
  },

  /**
   * Normalizes street names by replacing common abbreviations and optionally
   * removing common street postfixes for more robust grouping.
   * @param {string} streetName - The street name to normalize.
   * @return {string} The normalized street name.
   */
  normalizeStreetName(streetName) {
    if (typeof streetName !== 'string') {
      return '';
    }
    let normalized = streetName.trim().toLowerCase();

    // Define common abbreviations and their full forms
    const abbreviations = {
      st: 'street',
      ave: 'avenue',
      blvd: 'boulevard',
      dr: 'drive',
      ln: 'lane',
      rd: 'road',
      sq: 'square',
      ter: 'terrace',
      ct: 'court',
      cir: 'circle',
      pl: 'place',
      pkwy: 'parkway',
      hwy: 'highway',
      fwy: 'freeway',
      trl: 'trail',
      way: 'way',
      aly: 'alley',
      anx: 'annex',
      arc: 'arcade',
      bch: 'beach',
      bnd: 'bend',
      brg: 'bridge',
      byp: 'bypass',
      cmn: 'common',
      cor: 'corner',
      crs: 'crossing',
      curv: 'curve',
      est: 'estate',
      expy: 'expressway',
      ext: 'extension',
      frry: 'ferry',
      gln: 'glen',
      grn: 'green',
      grv: 'grove',
      hbr: 'harbor',
      htg: 'heights',
      holw: 'hollow',
      jct: 'junction',
      ldg: 'lodging',
      mdws: 'meadows',
      mnt: 'mount',
      mtn: 'mountain',
      pk: 'park',
      pt: 'point',
      prt: 'port',
      ranch: 'ranch',
      rdg: 'ridge',
      riv: 'river',
      shrs: 'shores',
      spg: 'spring',
      sumt: 'summit',
      tunl: 'tunnel',
      vly: 'valley',
      vis: 'vista',
      wkway: 'walkway',
      xng: 'crossing',
    };

    // First, replace abbreviations at the end of the string
    for (const abbr in abbreviations) {
      const regex = new RegExp(`\\b${abbr}\\.?$`); // Matches abbreviation at word boundary, optional dot, at end of string
      if (regex.test(normalized)) {
        normalized = normalized.replace(regex, abbreviations[abbr]);
        break; // Assuming one abbreviation per street name
      }
    }

    // Second, remove common full street type postfixes if present at the end
    const streetTypes = [
      'street',
      'avenue',
      'boulevard',
      'drive',
      'lane',
      'road',
      'square',
      'terrace',
      'court',
      'circle',
      'place',
      'parkway',
      'highway',
      'freeway',
      'trail',
      'way',
      'alley',
      'annex',
      'arcade',
      'beach',
      'bend',
      'bridge',
      'bypass',
      'common',
      'corner',
      'crossing',
      'curve',
      'estate',
      'expressway',
      'extension',
      'ferry',
      'glen',
      'green',
      'grove',
      'harbor',
      'heights',
      'hollow',
      'junction',
      'lodging',
      'meadows',
      'mount',
      'mountain',
      'park',
      'point',
      'port',
      'ranch',
      'ridge',
      'river',
      'shores',
      'spring',
      'summit',
      'tunnel',
      'valley',
      'vista',
      'walkway',
    ];

    for (const type of streetTypes) {
      const regex = new RegExp(`\\b${type}$`); // Matches full street type at word boundary, at end of string
      if (regex.test(normalized)) {
        normalized = normalized.replace(regex, '').trim();
        break; // Assuming one street type per street name
      }
    }

    return normalized;
  },

  /**
   * Determines which type of marker to show based on zoom level and density.
   * Now groups listings by normalized Street Number, Street Name, and City,
   * using the most common GPS coordinate for the group's pin location.
   * @param {Array} listings - The listings to analyze.
   * @return {Array} An array of marker data objects.
   */
  getMarkerDataForListings(listings) {
    const app = MLD_Map_App;
    const MAX_PINS = 200; // Optimized to 200 for better performance
    const CLUSTER_ZOOM_THRESHOLD = 16;
    const currentZoom = app.map.getZoom();
    const markerData = [];

    if (!listings || listings.length === 0) {
      return markerData;
    }

    const addressGroups = {}; // Key: "StreetNumber-NormalizedStreetName-City", Value: { group: [listing, ...], latLngCounts: {}, mostCommonLatLng: {lat,lng} }
    listings.forEach((listing) => {
      const streetNumber = listing.StreetNumber ? String(listing.StreetNumber).trim() : '';
      const normalizedStreetName = this.normalizeStreetName(listing.StreetName);
      const city = listing.City ? String(listing.City).trim().toLowerCase() : '';
      const addressKey = `${streetNumber}-${normalizedStreetName}-${city}`;

      if (!addressGroups[addressKey]) {
        addressGroups[addressKey] = {
          group: [],
          latLngCounts: {},
          mostCommonLatLng: null,
        };
      }
      addressGroups[addressKey].group.push(listing);

      // Only consider valid coordinates for the most common calculation
      const lat = parseFloat(listing.Latitude);
      const lng = parseFloat(listing.Longitude);
      if (!isNaN(lat) && !isNaN(lng)) {
        const latLngKey = `${lat.toFixed(6)},${lng.toFixed(6)}`;
        addressGroups[addressKey].latLngCounts[latLngKey] =
          (addressGroups[addressKey].latLngCounts[latLngKey] || 0) + 1;
      }
    });

    const processedAddressGroups = [];
    for (const key in addressGroups) {
      const { group, latLngCounts } = addressGroups[key];
      let mostCommonLatLng = null;
      let maxCount = 0;

      // Find the most common Lat/Lng within this address group
      for (const llKey in latLngCounts) {
        if (latLngCounts[llKey] > maxCount) {
          maxCount = latLngCounts[llKey];
          const [latStr, lngStr] = llKey.split(',');
          mostCommonLatLng = { lat: parseFloat(latStr), lng: parseFloat(lngStr) };
        }
      }

      // Fallback: If no common coordinate found (e.g., all invalid or only one listing),
      // use the coordinate of the first valid listing in the group.
      if (!mostCommonLatLng && group.length > 0) {
        for (const listing of group) {
          const lat = parseFloat(listing.Latitude);
          const lng = parseFloat(listing.Longitude);
          if (!isNaN(lat) && !isNaN(lng)) {
            mostCommonLatLng = { lat, lng };
            break;
          }
        }
      }

      // Ensure mostCommonLatLng is valid before proceeding
      if (!mostCommonLatLng || isNaN(mostCommonLatLng.lat) || isNaN(mostCommonLatLng.lng)) {
        MLDLogger.warning(
          `Skipping group with invalid or missing coordinates after fallback: ${key}`,
          group
        );
        continue;
      }

      // Additional validation for coordinate ranges
      if (
        mostCommonLatLng.lat < -90 ||
        mostCommonLatLng.lat > 90 ||
        mostCommonLatLng.lng < -180 ||
        mostCommonLatLng.lng > 180
      ) {
        MLDLogger.error(
          `Invalid coordinates detected: lat=${mostCommonLatLng.lat}, lng=${mostCommonLatLng.lng} for group: ${key}`
        );
        continue;
      }

      processedAddressGroups.push({
        group,
        markerPosition: mostCommonLatLng,
        isMultiUnit: group.length > 1,
        // Unique ID for the group marker, combining slugified address and most common lat/lng
        id: `group-${MLD_Core.slugify(key)}-${mostCommonLatLng.lat.toFixed(6)}-${mostCommonLatLng.lng.toFixed(6)}`,
      });
    }

    // Separate groups into candidates for 'detailed' pins (price, cluster) and 'dot' pins based on *initial* preference
    const candidatesForDetailedPins = [];
    const candidatesForDotPins = [];

    processedAddressGroups.forEach((processedGroup) => {
      const { group, markerPosition, isMultiUnit, id } = processedGroup;
      const listingForPriceDisplay = group[0]; // Use the first listing in the group for price/general data

      if (isMultiUnit) {
        if (currentZoom >= CLUSTER_ZOOM_THRESHOLD) {
          // Show cluster marker at high zoom
          candidatesForDetailedPins.push({
            type: 'cluster',
            id,
            group,
            lng: markerPosition.lng,
            lat: markerPosition.lat,
          });
        } else {
          // Show price marker for multi-unit at low zoom instead of dot
          candidatesForDotPins.push({
            type: 'price',
            id,
            listing: listingForPriceDisplay,
            group,
            lng: markerPosition.lng,
            lat: markerPosition.lat,
          });
        }
      } else {
        // Single-unit always a price marker
        candidatesForDetailedPins.push({
          type: 'price',
          id: `price-${listingForPriceDisplay.ListingId}`,
          listing: listingForPriceDisplay,
          lng: markerPosition.lng,
          lat: markerPosition.lat,
        });
      }
    });

    // Apply MAX_PINS limit and convert multi-units to price markers instead of dots
    let detailedPinsCount = 0;
    for (let i = 0; i < candidatesForDetailedPins.length; i++) {
      if (detailedPinsCount < MAX_PINS) {
        markerData.push(candidatesForDetailedPins[i]);
        detailedPinsCount++;
      }
      // No longer convert to dots when over limit - just don't show
    }

    // Convert dot candidates to price markers for multi-units at lower zoom
    for (const dotCandidate of candidatesForDotPins) {
      if (detailedPinsCount < MAX_PINS) {
        // Show as price marker using first listing in group
        const priceMarker = {
          type: 'price',
          id: dotCandidate.id,
          listing: dotCandidate.listing,
          group: dotCandidate.group, // Keep group for multi-unit handling
          lng: dotCandidate.lng,
          lat: dotCandidate.lat,
        };
        markerData.push(priceMarker);
        detailedPinsCount++;
      }
    }

    return markerData;
  },

  /**
   * Creates a small dot marker, used at high density or for grouped listings at low zoom.
   * @param {Object} listing      - The listing data (used for price display on hover if single unit).
   * @param {number} lng          - Longitude.
   * @param {number} lat          - Latitude.
   * @param {Array}  [group=null] - The array of listings if this is a multi-unit group marker.
   * @param {string} id           - The unique ID for this marker.
   */
  createDotMarker(listing, lng, lat, group = null, id) {
    // Validate listing data - use first item from group if listing is undefined
    if (!listing && group && group.length > 0) {
      listing = group[0];
    }

    // If still no valid listing, skip creation
    if (!listing) {
      MLDLogger.error('Cannot create dot marker: no listing data provided', { id, group });
      return;
    }

    const container = document.createElement('div');
    container.className = 'bme-marker-container';

    const dot = document.createElement('div');
    dot.className = 'bme-dot-marker';

    const pricePin = document.createElement('div');
    pricePin.className = 'bme-price-marker bme-marker-hover-reveal';
    pricePin.textContent = MLD_Core.formatPrice(listing.ListPrice || 0);

    container.appendChild(dot);
    container.appendChild(pricePin);

    if (group) {
      container.onclick = () => {
        // Use multi-unit modal instead of focus view
        if (typeof MLD_MultiUnitModal !== 'undefined' && MLD_MultiUnitModal.open) {
          MLD_MultiUnitModal.open(group);
        } else {
          MLDLogger.error('MLD_MultiUnitModal not loaded or open method not available');
        }
      };
    } else {
      container.onclick = () => this.handleMarkerClick(listing);
    }

    this.createMarkerElement(container, lng, lat, id, group || listing, 1); // zIndex 1 for dots
  },

  /**
   * Creates a marker showing the listing price.
   * @param {Object} listing - The listing data.
   * @param {number} lng     - Longitude.
   * @param {number} lat     - Latitude.
   * @param {string} id      - The unique ID for this marker.
   */
  createPriceMarker(listing, lng, lat, id, group = null) {
    // Validate listing data
    if (!listing) {
      MLDLogger.error('Cannot create price marker: no listing data provided', { id });
      return;
    }

    const el = document.createElement('div');
    // For multi-unit groups, only apply gray if ALL are archived
    // For single listings, check if the listing itself is archived
    let isArchive = false;
    if (group && group.length > 1) {
      // Multi-unit: check if all listings are archived
      isArchive = group.every(item => item.IsArchive === '1' || item.IsArchive === 1);
    } else {
      // Single listing: check if this listing is archived
      isArchive = listing.IsArchive === '1' || listing.IsArchive === 1;
    }
    el.className = isArchive ? 'bme-price-marker bme-price-marker-archive' : 'bme-price-marker';

    // v6.65.0: Check if exclusive listing (listing_id < 1,000,000)
    const listingId = parseInt(listing.ListingId || listing.listing_id || 0, 10);
    const isExclusive = listingId > 0 && listingId < 1000000;
    if (isExclusive) {
      el.classList.add('bme-price-marker-exclusive');
      // Add star banner element
      const starBanner = document.createElement('div');
      starBanner.className = 'bme-exclusive-star-banner';
      starBanner.innerHTML = '<svg width="10" height="10" fill="currentColor" viewBox="0 0 16 16"><path d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z"/></svg>';
      el.appendChild(starBanner);
    }

    // Check if listing has open houses
    const openHouseData = typeof listing.OpenHouseData === 'string' 
      ? JSON.parse(listing.OpenHouseData || '[]') 
      : (listing.OpenHouseData || []);
    const hasOpenHouse = Array.isArray(openHouseData) && openHouseData.length > 0;
    
    // Set price text with optional open house icon
    const priceText = MLD_Core.formatPrice(listing.ListPrice || 0);
    if (hasOpenHouse) {
      el.innerHTML = `${priceText} <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="margin-left: 4px; vertical-align: middle; opacity: 0.9;"><path d="M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L2 8.207V13.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V8.207l.646.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.707 1.5ZM13 7.207V13.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V7.207l5-5 5 5Z"/><path d="M6 12.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5V15H9v-1.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5V15H6v-2.5Z"/></svg>`;
    } else {
      el.textContent = priceText;
    }
    
    el.onclick = (e) => {
      e.stopPropagation();
      // If this is a multi-unit marker (has group), open modal instead
      if (group && group.length > 1) {
        if (typeof MLD_MultiUnitModal !== 'undefined' && MLD_MultiUnitModal.open) {
          MLD_MultiUnitModal.open(group);
        }
      } else {
        this.handleMarkerClick(listing);
      }
    };
    // Set z-index: archived pins get 1, active pins get 2
    const zIndexValue = isArchive ? 1 : 2;
    this.createMarkerElement(el, lng, lat, id, group || listing, zIndexValue);
  },

  /**
   * Creates a cluster marker for multiple units at the same location.
   * @param {Array}  group - The array of listings in the cluster.
   * @param {number} lng   - Longitude.
   * @param {number} lat   - Latitude.
   * @param {string} id    - The unique ID for this marker.
   */
  createUnitClusterMarker(group, lng, lat, id) {
    // Validate group data
    if (!group || !Array.isArray(group) || group.length === 0) {
      MLDLogger.error('Cannot create cluster marker: invalid group data', { id, group });
      return;
    }

    const el = document.createElement('div');
    // Only apply gray styling if ALL listings in group are archived
    // If at least one is active, use normal color
    const allArchived = group.every(listing => listing.IsArchive === '1' || listing.IsArchive === 1);
    el.className = allArchived ? 'bme-unit-cluster-marker bme-unit-cluster-marker-archive' : 'bme-unit-cluster-marker';

    const currentZoom = MLD_Map_App.map.getZoom();
    let markerText = `${group.length} Units`;

    if (currentZoom >= 17) {
      const firstListing = group[0];
      const streetNumber = firstListing.StreetNumber || '';
      const streetName = firstListing.StreetName || '';
      markerText = `${group.length} Units at ${streetNumber} ${streetName}`;
    }

    el.textContent = markerText;
    el.onclick = (e) => {
      e.stopPropagation();
      // Analytics: Track cluster click (v6.38.0)
      document.dispatchEvent(new CustomEvent('mld:cluster_click', {
        detail: { propertyCount: group.length }
      }));
      // Use multi-unit modal instead of focus mode
      if (typeof MLD_MultiUnitModal !== 'undefined') {
        MLD_MultiUnitModal.open(group);
      } else {
        MLDLogger.error('MLD_MultiUnitModal not loaded');
      }
    };
    // Set z-index: archived clusters get 1, active clusters get 2
    const zIndexValue = allArchived ? 1 : 2;
    this.createMarkerElement(el, lng, lat, id, group, zIndexValue);
  },

  /**
   * Generic function to create a marker element for either Google Maps or Mapbox.
   * v6.20.14: Added diagnostic logging for marker creation failures.
   * @param {HTMLElement}  element - The custom HTML element for the marker.
   * @param {number}       lng     - Longitude.
   * @param {number}       lat     - Latitude.
   * @param {string}       id      - The unique ID for the marker (from markerData.id).
   * @param {object|Array} data    - The raw listing object or the group array associated with the marker.
   * @param {number}       zIndex  - The base stacking order for the marker.
   */
  createMarkerElement(element, lng, lat, id, data, zIndex = 2) {
    const app = MLD_Map_App;
    let marker;
    let rawListingId = null;

    // Determine rawListingId only if it's a single listing marker
    if (!Array.isArray(data) && data.ListingId) {
      rawListingId = data.ListingId;
    }

    // v6.20.14: Check prerequisites before attempting marker creation
    if (bmeMapData.provider === 'google') {
      if (!app.AdvancedMarkerElement) {
        return;
      }

      if (!app.map) {
        return;
      }

      try {
        marker = new app.AdvancedMarkerElement({
          position: { lat, lng },
          map: app.map,
          content: element,
          zIndex,
        });
      } catch (err) {
        console.error('%c[MLD Markers] Error creating AdvancedMarkerElement', 'color: #F44336', {
          id,
          error: err.message,
          lat,
          lng
        });
        return;
      }
    }

    if (marker) {
      app.markers.push({
        marker,
        id,
        element,
        data,
        rawListingId,
        baseZIndex: zIndex, // Store the base z-index for later
      });
      // Clear the warning flag once we successfully create a marker
      this._markerWarningLogged = false;
    }
  },

  /**
   * Clears all markers from the map.
   */
  clearMarkers() {
    const app = MLD_Map_App;
    app.markers.forEach(({ marker }) => {
      if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
      else if (marker.remove) marker.remove();
    });
    app.markers = [];
  },

  /**
   * Handles a click on a marker.
   * For group markers, this function is not directly used; the multi-unit modal is opened instead.
   * @param listing
   */
  handleMarkerClick(listing) {
    // Analytics: Track marker click (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:marker_click', {
      detail: { listingId: listing.ListingId }
    }));

    if (MLD_Map_App.openPopupIds.has(listing.ListingId)) {
      this.closeListingPopup(listing.ListingId);
    } else {
      MLD_Core.panTo(listing);
      this.showListingPopup(listing);
    }
  },

  /**
   * Shows a listing popup card on the map.
   * @param listing
   */
  showListingPopup(listing) {
    const app = MLD_Map_App;
    if (app.openPopupIds.has(listing.ListingId)) return;
    app.openPopupIds.add(listing.ListingId);
    this.highlightMarker(listing.ListingId, 'active');
    const $popupWrapper = jQuery(
      `<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`
    )
      .data('listingData', listing)
      .html(MLD_Core.createCardHTML(listing, 'popup'));

    // Create a modern close button with simple X
    const $closeButton = jQuery('<button class="bme-popup-close" aria-label="Close"></button>').on(
      'click',
      (e) => {
        e.stopPropagation();
        this.closeListingPopup(listing.ListingId);
      }
    );

    // Append close button inside the card
    $popupWrapper.find('.bme-listing-card').append($closeButton);

    // Different positioning for mobile vs desktop
    const isMobile = window.innerWidth <= 768;

    // Add resize handle for desktop
    if (!isMobile) {
      const $resizeHandle = jQuery(
        '<div class="bme-resize-handle-corner" aria-label="Resize"></div>'
      );
      $popupWrapper.find('.bme-listing-card').append($resizeHandle);
    }
    if (isMobile) {
      // On mobile, position from top with smaller stagger
      const stagger = (app.openPopupIds.size - 1) * 20;
      $popupWrapper.css({
        bottom: 'auto',
        top: `${80 + stagger}px`,
        left: '50%',
        transform: 'translateX(-50%)',
      });
    } else {
      // Desktop positioning from bottom
      const stagger = (app.openPopupIds.size - 1) * 15;
      $popupWrapper.css({
        bottom: `${20 + stagger}px`,
        left: `calc(50% - ${stagger}px)`,
        transform: 'translateX(-50%)',
      });
    }
    jQuery('#bme-popup-container').append($popupWrapper).show();

    // Make draggable on desktop and larger touch screens (tablets)
    // Only skip dragging on small mobile phones
    const isSmallMobile = window.innerWidth <= 768 && window.innerHeight <= 768;
    if (!isSmallMobile) {
      this.makeDraggable($popupWrapper);
    }

    this.updateCloseAllButton();
  },

  /**
   * Closes a specific listing popup.
   * @param listingId
   */
  closeListingPopup(listingId) {
    jQuery(`.bme-popup-card-wrapper[data-listing-id="${listingId}"]`).remove();
    MLD_Map_App.openPopupIds.delete(listingId);
    this.highlightMarker(listingId, 'none');
    if (MLD_Map_App.openPopupIds.size === 0) jQuery('#bme-popup-container').hide();
    this.updateCloseAllButton();
  },

  /**
   * Highlights a marker on the map (e.g., on hover or when active).
   * @param listingId
   * @param state
   */
  highlightMarker(listingId, state) {
    const markerData = MLD_Map_App.markers.find((m) => m.rawListingId === listingId);
    if (!markerData) return;

    const { element, marker, baseZIndex } = markerData;
    element.classList.remove('highlighted-active', 'highlighted-hover');

    let newZIndex = baseZIndex; // Default to the marker's base z-index

    if (state === 'active') {
      element.classList.add('highlighted-active');
      newZIndex = 5; // Active/clicked markers are always on top
    } else if (state === 'hover' && !element.classList.contains('highlighted-active')) {
      element.classList.add('highlighted-hover');
      // Hovered markers: archived get 3, active get 4
      newZIndex = baseZIndex === 1 ? 3 : 4;
    }

    if (bmeMapData.provider === 'google') {
      marker.zIndex = newZIndex;
    } else {
      element.style.zIndex = newZIndex;
    }
  },

  /**
   * Reapplies the 'active' highlight to markers whose popups are open.
   */
  reapplyActiveHighlights() {
    MLD_Map_App.openPopupIds.forEach((id) => this.highlightMarker(id, 'active'));
  },

  /**
   * Makes a popup draggable.
   * @param $element
   */
  makeDraggable($element) {
    let p1 = 0,
      p2 = 0,
      p3 = 0,
      p4 = 0,
      isDragging = false,
      isPinching = false,
      isResizing = false,
      initialDistance = 0,
      currentScale = 1,
      startWidth = 0,
      startHeight = 0,
      startX = 0,
      startY = 0;
    const handle = $element.find('.bme-listing-card');
    const $resizeHandle = $element.find('.bme-resize-handle-corner');

    // Get stored scale or default to 1
    const storedScale = $element.data('scale') || 1;
    currentScale = storedScale;
    if (storedScale !== 1) {
      handle.css('transform', `scale(${storedScale})`);
    }

    // Helper to get coordinates from mouse or touch event
    const getCoords = (e) => {
      if (e.type.includes('touch')) {
        const touch = e.originalEvent.touches[0] || e.originalEvent.changedTouches[0];
        return { x: touch.clientX, y: touch.clientY };
      }
      return { x: e.clientX, y: e.clientY };
    };

    // Helper to get distance between two touch points
    const getTouchDistance = (e) => {
      if (e.originalEvent.touches.length < 2) return 0;
      const touch1 = e.originalEvent.touches[0];
      const touch2 = e.originalEvent.touches[1];
      return Math.sqrt(
        Math.pow(touch2.clientX - touch1.clientX, 2) + Math.pow(touch2.clientY - touch1.clientY, 2)
      );
    };

    // Start drag handler for both mouse and touch
    const startDrag = (e) => {
      // Check for pinch gesture (two fingers)
      if (e.type === 'touchstart' && e.originalEvent.touches.length === 2) {
        isPinching = true;
        initialDistance = getTouchDistance(e);
        e.preventDefault();
        return;
      }

      // Don't start drag if clicking on interactive elements
      const isInteractive =
        jQuery(e.target).closest(
          'button, a, .bme-view-photos-btn, .bme-view-details-btn, .bme-popup-close'
        ).length > 0;
      if (isInteractive) {
        return;
      }

      // Only prevent default for mouse to allow touch scrolling
      if (e.type === 'mousedown') {
        e.preventDefault();
      }

      isDragging = true;
      const coords = getCoords(e);
      p3 = coords.x;
      p4 = coords.y;
      jQuery('.bme-popup-card-wrapper').css('z-index', 1001);
      $element.css('z-index', 1002);
      handle.addClass('is-dragging');

      // Add appropriate event listeners
      if (e.type === 'mousedown') {
        jQuery(document).on('mouseup', stopDrag).on('mousemove', drag);
      } else {
        jQuery(document).on('touchend', stopDrag).on('touchmove', handleTouchMove);
      }
    };

    // Handle touch move for both drag and pinch
    const handleTouchMove = (e) => {
      if (isPinching && e.originalEvent.touches.length === 2) {
        handle.addClass('is-scaling');
        const currentDistance = getTouchDistance(e);
        const scale = currentDistance / initialDistance;
        const newScale = Math.min(Math.max(currentScale * scale, 0.5), 2.5); // Limit scale between 0.5x and 2.5x

        handle.css({
          transform: `scale(${newScale})`,
          'transform-origin': 'center center',
        });
        $element.data('scale', newScale);
        e.preventDefault();
      } else if (isDragging) {
        drag(e);
      }
    };

    // Drag handler
    const drag = (e) => {
      if (!isDragging) return;

      const coords = getCoords(e);
      p1 = p3 - coords.x;
      p2 = p4 - coords.y;
      p3 = coords.x;
      p4 = coords.y;

      if ($element.css('bottom') !== 'auto') {
        // Get position relative to parent container, not document
        const parentOffset = $element.parent().offset();
        const elementOffset = $element.offset();
        $element.css({
          top: (elementOffset.top - parentOffset.top) + 'px',
          left: (elementOffset.left - parentOffset.left) + 'px',
          bottom: 'auto',
          transform: 'none',
        });
      }

      $element.css({
        top: $element.get(0).offsetTop - p2 + 'px',
        left: $element.get(0).offsetLeft - p1 + 'px',
      });
    };

    // Stop drag handler
    const stopDrag = (e) => {
      if (isPinching) {
        isPinching = false;
        currentScale = $element.data('scale') || 1;
        handle.removeClass('is-scaling');
      }
      if (isDragging) {
        isDragging = false;
        handle.removeClass('is-dragging');
      }
      jQuery(document)
        .off('mouseup touchend', stopDrag)
        .off('mousemove touchmove', drag)
        .off('touchmove', handleTouchMove);
    };

    // Bind both mouse and touch events
    handle.on('mousedown', startDrag);
    handle.on('touchstart', startDrag);

    // Add resize functionality for desktop
    if ($resizeHandle.length) {
      $resizeHandle.on('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        isResizing = true;
        startX = e.clientX;
        startY = e.clientY;
        startWidth = handle.outerWidth();
        startHeight = handle.outerHeight();
        handle.addClass('is-resizing');

        jQuery(document).on('mousemove', resizeMove).on('mouseup', resizeStop);
      });

      const resizeMove = (e) => {
        if (!isResizing) return;

        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;

        // Calculate new scale based on diagonal movement (maintains aspect ratio)
        const avgDelta = (deltaX + deltaY) / 2;
        const scaleFactor = 1 + avgDelta / startWidth;
        const newScale = Math.min(Math.max(currentScale * scaleFactor, 0.5), 2.5);

        handle.css({
          transform: `scale(${newScale})`,
          'transform-origin': 'center center',
        });
        $element.data('scale', newScale);
      };

      const resizeStop = () => {
        if (!isResizing) return;
        isResizing = false;
        currentScale = $element.data('scale') || 1;
        handle.removeClass('is-resizing');
        jQuery(document).off('mousemove', resizeMove).off('mouseup', resizeStop);
      };
    }
  },

  /**
   * Shows or hides the "Close All" button for popups.
   */
  updateCloseAllButton() {
    const btn = jQuery('#bme-close-all-btn');
    if (MLD_Map_App.openPopupIds.size > 1) {
      if (btn.length === 0)
        jQuery('<button id="bme-close-all-btn">Close All</button>')
          .on('click', () =>
            new Set(MLD_Map_App.openPopupIds).forEach((id) => this.closeListingPopup(id))
          )
          .appendTo('body');
    } else {
      btn.remove();
    }
  },
};

// Expose globally
window.MLD_Markers = MLD_Markers;
