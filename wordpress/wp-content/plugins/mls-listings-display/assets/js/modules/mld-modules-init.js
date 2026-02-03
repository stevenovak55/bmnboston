/**
 * MLD Modules Initialization
 * Example of how to use the shared components together
 *
 * @version 2.0.0
 */

// Initialize modules after a short delay to ensure all data is loaded
function initializeModules() {
  // ========================================
  // Initialize Gallery Module
  // ========================================
  const initGallery = () => {
    const photos = window.mldPropertyData?.photos || [];

    if (photos.length > 0) {
      const gallery = new MLDGallery({
        container: '#property-gallery',
        photos,
        enableLightbox: true,
        enableZoom: true,
        enableSwipe: true,
        enableKeyboard: true,
        enableThumbnails: true,
        lazyLoad: true,
        onPhotoChange: (index) => {
          MLDLogger.debug('Photo changed', { index });

          // Update any external photo counters
          const counter = document.querySelector('.photo-counter');
          if (counter) {
            counter.textContent = `${index + 1} / ${photos.length}`;
          }
        },
        onLightboxOpen: () => {
          MLDLogger.debug('Lightbox opened');

          // Track analytics event
          if (window.gtag) {
            gtag('event', 'view_photos', {
              property_id: window.mldPropertyData?.propertyId,
            });
          }
        },
      });

      // Make gallery available globally
      window.mldGallery = gallery;
    }
  };

  // ========================================
  // Initialize Calculator Module
  // ========================================
  const initCalculator = () => {
    const price = window.mldPropertyData?.price || 500000;
    const taxes = window.mldPropertyData?.propertyTax || null;
    const hoa = window.mldPropertyData?.hoaFees || 0;

    const calculator = new MLDCalculator({
      container: '#mortgage-calculator',
      price,
      propertyTax: taxes,
      hoaFees: hoa,
      enableChart: true,
      onChange: (state) => {
        MLDLogger.debug('Calculator updated:', state);

        // Update any external displays
        const monthlyDisplay = document.querySelector('.monthly-payment-display');
        if (monthlyDisplay) {
          monthlyDisplay.textContent = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
          }).format(state.totalMonthlyPayment);
        }
      },
    });

    // Make calculator available globally
    window.mldCalculator = calculator;
  };

  // ========================================
  // Initialize Walk Score Module
  // ========================================
  const initWalkScore = () => {
    // Ensure property data is available
    if (!window.mldPropertyData || !window.mldSettings) {
      MLDLogger.warning('Property data not yet available for Walk Score');
      // Try again after a short delay
      setTimeout(initWalkScore, 500);
      return;
    }

    // Check if we have Walk Score API key
    const apiKey = window.mldSettings?.walkScoreApiKey || window.mldPropertyData?.walkScoreApiKey;

    if (!apiKey) {
      MLDLogger.debug('Walk Score API key not configured');
      // Show placeholder message
      const container = document.getElementById('walk-score-container');
      if (container) {
        container.innerHTML =
          '<div class="mld-walk-score-placeholder">Walk Score API key not configured</div>';
      }
      return;
    }

    // Get property data
    const address = window.mldPropertyData?.address || '';
    const coordinates = window.mldPropertyData?.coordinates || null;

    // Debug log to see what data we have
    MLDLogger.debug('Walk Score init - checking data:', {
      hasPropertyData: !!window.mldPropertyData,
      propertyDataKeys: window.mldPropertyData ? Object.keys(window.mldPropertyData) : [],
      coordinates,
      coordinatesType: typeof coordinates,
      hasCoordinates: !!coordinates,
      lat: coordinates?.lat,
      lng: coordinates?.lng,
      rawCoordinates: JSON.stringify(coordinates),
    });

    if (!coordinates || !coordinates.lat || !coordinates.lng) {
      MLDLogger.warning('No coordinates available for Walk Score');
      // Show placeholder message
      const container = document.getElementById('walk-score-container');
      if (container) {
        container.innerHTML =
          '<div class="mld-walk-score-placeholder">Location data not available</div>';
      }
      return;
    }

    // Initialize Walk Score
    const walkScore = new MLDWalkScore({
      container: '#walk-score-container',
      apiKey,
      address,
      lat: coordinates.lat,
      lng: coordinates.lng,
      enableTransit: true,
      enableBike: true,
      showNearby: true,
      theme: 'modern',
      onScoreLoad: (scores) => {
        MLDLogger.debug('Walk Scores loaded:', scores);

        // Track analytics event
        if (window.gtag) {
          gtag('event', 'walk_score_loaded', {
            property_id: window.mldPropertyData?.propertyId,
            walk_score: scores.walk,
            transit_score: scores.transit,
            bike_score: scores.bike,
          });
        }

        // Update any external displays
        updatePropertyScoreBadges(scores);
      },
      onError: (error) => {
        MLDLogger.error('Walk Score error:', error);
      },
    });

    // Make available globally
    window.mldWalkScore = walkScore;
  };

  // Helper function to update score badges elsewhere on page
  const updatePropertyScoreBadges = (scores) => {
    // Update walk score badge
    const walkBadge = document.querySelector('.property-walk-score-badge');
    if (walkBadge && scores.walk !== null) {
      walkBadge.textContent = scores.walk;
      walkBadge.style.backgroundColor = getScoreColor(scores.walk);
      walkBadge.classList.remove('hidden');
    }

    // Update transit score badge
    const transitBadge = document.querySelector('.property-transit-score-badge');
    if (transitBadge && scores.transit !== null) {
      transitBadge.textContent = scores.transit;
      transitBadge.style.backgroundColor = getScoreColor(scores.transit);
      transitBadge.classList.remove('hidden');
    }

    // Update bike score badge
    const bikeBadge = document.querySelector('.property-bike-score-badge');
    if (bikeBadge && scores.bike !== null) {
      bikeBadge.textContent = scores.bike;
      bikeBadge.style.backgroundColor = getScoreColor(scores.bike);
      bikeBadge.classList.remove('hidden');
    }
  };

  const getScoreColor = (score) => {
    if (score >= 90) return '#10B981';
    if (score >= 70) return '#3B82F6';
    if (score >= 50) return '#F59E0B';
    if (score >= 25) return '#F97316';
    return '#EF4444';
  };

  // ========================================
  // Initialize Map Module
  // ========================================
  const initMap = () => {
    const coordinates = window.mldPropertyData?.coordinates || null;

    if (!coordinates || !coordinates.lat || !coordinates.lng) {
      MLDLogger.warning('No coordinates available for map');
      return;
    }

    // Determine map provider
    const provider = window.bmeMapData?.mapProvider || 'google';
    const apiKey =
      provider === 'google' ? window.bmeMapData?.google_key : window.bmeMapData?.mapbox_key;

    if (!apiKey) {
      MLDLogger.warning('No API key available for map provider:', provider);
      return;
    }

    // Property marker
    const propertyMarker = {
      lat: coordinates.lat,
      lng: coordinates.lng,
      type: 'property',
      title: window.mldPropertyData?.address || 'Property',
      price: window.mldPropertyData?.priceFormatted,
      details: window.mldPropertyData?.details || '',
      photo: window.mldPropertyData?.mainPhoto,
      link: window.location.href,
      color: '#0066FF',
      content: true,
    };

    // Initialize map
    const map = new MLDMap({
      container: 'property-map',
      provider,
      apiKey,
      center: coordinates,
      zoom: 15,
      markers: [propertyMarker],
      enableClustering: false,
      enableStreetView: true,
      enablePOIs: true,
      enableCommute: true,
      style: 'minimal',
      onMarkerClick: (marker) => {
        MLDLogger.debug('Marker clicked:', marker);

        // Track analytics
        if (window.gtag) {
          gtag('event', 'map_interaction', {
            action: 'marker_click',
            marker_type: marker.type,
          });
        }
      },
    });

    // Add nearby properties if available
    const nearbyProperties = window.mldPropertyData?.nearbyProperties || [];
    nearbyProperties.forEach((property, index) => {
      if (property.coordinates?.lat && property.coordinates?.lng) {
        map.addMarker({
          lat: property.coordinates.lat,
          lng: property.coordinates.lng,
          type: 'property',
          title: property.address,
          price: property.price,
          details: `${property.beds} beds, ${property.baths} baths`,
          photo: property.photo,
          link: property.url,
          color: '#6B7280',
          label: (index + 1).toString(),
          content: true,
        });
      }
    });

    // Make map available globally
    window.mldMap = map;
  };

  // ========================================
  // Initialize Similar Homes Module
  // ========================================
  const initSimilarHomes = () => {
    // Try both data sources
    const v3Data = window.mldPropertyDataV3;
    const moduleData = window.mldPropertyData;

    // Get coordinates - check both possible locations
    let lat = null,
      lng = null;

    // First try V3 data (direct lat/lng)
    if (v3Data && v3Data.lat && v3Data.lng) {
      lat = v3Data.lat;
      lng = v3Data.lng;
    }
    // Then try module data (coordinates object)
    else if (
      moduleData &&
      moduleData.coordinates &&
      moduleData.coordinates.lat &&
      moduleData.coordinates.lng
    ) {
      lat = moduleData.coordinates.lat;
      lng = moduleData.coordinates.lng;
    }

    // Debug: Log what data we have
    MLDLogger.debug('Similar Homes init - checking data:', {
      hasV3Data: !!v3Data,
      hasModuleData: !!moduleData,
      v3Lat: v3Data?.lat,
      v3Lng: v3Data?.lng,
      moduleCoordinates: moduleData?.coordinates,
      finalLat: lat,
      finalLng: lng,
      v3DOM: v3Data?.daysOnMarket,
      moduleDOM: moduleData?.daysOnMarket,
    });

    if (!lat || !lng) {
      MLDLogger.warning('No coordinates available for similar homes');

      // Show a message in the container
      const container = document.getElementById('v3-similar-homes-container');
      if (container) {
        container.innerHTML =
          '<div class="mld-similar-homes-empty"><p>Location data not available for similar homes</p></div>';
      }
      return;
    }

    // Get other property data from both sources
    const propertyId = v3Data?.mlsNumber || moduleData?.propertyId;
    const price = v3Data?.price || moduleData?.price || 0;
    const beds = v3Data?.beds || 0;
    const baths = v3Data?.baths || 0;
    const sqft = v3Data?.sqft || 0;
    const propertyType = v3Data?.propertyType || '';
    const propertySubType = v3Data?.propertySubType || '';
    const status = v3Data?.status || 'Active';
    const closeDate = v3Data?.closeDate || '';
    const originalEntryTimestamp = v3Data?.originalEntryTimestamp || '';
    const offMarketDate = v3Data?.offMarketDate || '';
    const daysOnMarket = v3Data?.daysOnMarket || 0;
    const yearBuilt = v3Data?.yearBuilt || null;
    const lotSizeAcres = v3Data?.lotSizeAcres || null;
    const lotSizeSquareFeet = v3Data?.lotSizeSquareFeet || null;
    const garageSpaces = v3Data?.garageSpaces || 0;
    const parkingTotal = v3Data?.parkingTotal || 0;
    const isWaterfront = v3Data?.isWaterfront || false;
    const entryLevel = v3Data?.entryLevel || null;
    const city = v3Data?.city || moduleData?.city || '';

    const similarHomes = new MLDSimilarHomes({
      container: '#v3-similar-homes-container',
      propertyId,
      lat,
      lng,
      price,
      beds,
      baths,
      sqft,
      propertyType,
      propertySubType,
      status,
      closeDate,
      originalEntryTimestamp,
      offMarketDate,
      daysOnMarket,
      yearBuilt,
      lotSizeAcres,
      lotSizeSquareFeet,
      garageSpaces,
      parkingTotal,
      isWaterfront,
      entryLevel,
      city,
      onPropertyClick: (property) => {
        MLDLogger.debug('Property clicked:', property);
        // Could open in new tab or navigate
        window.open(property.url, '_blank');
      },
      onLoadComplete: (data) => {
        MLDLogger.debug(
          'Similar homes loaded:',
          data.total,
          'total,',
          data.properties.length,
          'on page',
          data.page
        );
      },
    });

    // Make available globally
    window.mldSimilarHomes = similarHomes;
  };

  // ========================================
  // Initialize All Modules
  // ========================================
  const initModules = () => {
    // Initialize based on what containers exist
    if (
      document.getElementById('property-gallery') ||
      document.querySelector('[data-gallery-trigger]')
    ) {
      initGallery();
    }

    if (document.getElementById('mortgage-calculator')) {
      initCalculator();
    }

    if (document.getElementById('walk-score-container')) {
      initWalkScore();
    }

    if (document.getElementById('v3-similar-homes-container')) {
      initSimilarHomes();
    }

    if (document.getElementById('property-map')) {
      // For Google Maps, wait for API to load
      if (window.bmeMapData?.mapProvider === 'google') {
        if (window.google?.maps) {
          initMap();
        } else {
          // Wait for Google Maps to load asynchronously
          window.addEventListener('googleMapsReady', function () {
            if (window.google?.maps) {
              initMap();
            }
          });
          // Also set up callback for legacy support
          window.initPropertyMapModules = initMap;
        }
      } else {
        // Mapbox loads synchronously
        initMap();
      }
    }
  };

  // ========================================
  // Utility Functions
  // ========================================

  // Load CSS for modules
  const loadModuleStyles = () => {
    const styles = [
      '/wp-content/plugins/mls-listings-display/assets/css/modules/mld-gallery-v2.css',
      '/wp-content/plugins/mls-listings-display/assets/css/modules/mld-calculator-v2.css',
      '/wp-content/plugins/mls-listings-display/assets/css/modules/mld-map-v2.css',
      '/wp-content/plugins/mls-listings-display/assets/css/modules/mld-walk-score.css',
      '/wp-content/plugins/mls-listings-display/assets/css/modules/mld-similar-homes.css',
    ];

    styles.forEach((href) => {
      if (!document.querySelector(`link[href="${href}"]`)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
      }
    });
  };

  // Load module scripts
  const loadModuleScripts = () => {
    return new Promise((resolve) => {
      const scripts = [
        '/wp-content/plugins/mls-listings-display/assets/js/modules/mld-gallery-v2.js',
        '/wp-content/plugins/mls-listings-display/assets/js/modules/mld-calculator-v2.js',
        '/wp-content/plugins/mls-listings-display/assets/js/modules/mld-map-v2.js',
        '/wp-content/plugins/mls-listings-display/assets/js/modules/mld-walk-score.js',
        '/wp-content/plugins/mls-listings-display/assets/js/modules/mld-similar-homes.js',
      ];

      let loaded = 0;

      scripts.forEach((src) => {
        if (!document.querySelector(`script[src="${src}"]`)) {
          const script = document.createElement('script');
          script.src = src;
          script.async = true;
          script.onload = () => {
            loaded++;
            if (loaded === scripts.length) {
              resolve();
            }
          };
          document.body.appendChild(script);
        } else {
          loaded++;
          if (loaded === scripts.length) {
            resolve();
          }
        }
      });
    });
  };

  // ========================================
  // Initialize Everything
  // ========================================

  // Load styles immediately
  loadModuleStyles();

  // Load scripts then initialize
  loadModuleScripts().then(() => {
    initModules();
  });

  // Export init function for manual initialization
  window.initMLDModules = initModules;
}

// ========================================
// Global Helper Functions
// ========================================

// Format currency
window.formatCurrency = (amount, decimals = 0) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);
};

// Format number
window.formatNumber = (num) => {
  return new Intl.NumberFormat('en-US').format(num);
};

// Get device type
window.getDeviceType = () => {
  const width = window.innerWidth;
  if (width < 768) return 'mobile';
  if (width < 1024) return 'tablet';
  return 'desktop';
};

// Check if touch device
window.isTouchDevice = () => {
  return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
};

// ========================================
// Initialize on DOM ready or immediately if already loaded
// ========================================
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () {
    // Wait longer for property data to be set
    setTimeout(initializeModules, 500);
  });
} else {
  // DOM already loaded, wait longer for property data
  setTimeout(initializeModules, 500);
}
