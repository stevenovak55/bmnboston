/**
 * MLD Script Loader
 * Ensures proper script loading order and initialization
 * Version: 1.0.0
 */

(function () {
  'use strict';

  // Check if all required modules are loaded
  let checkCount = 0;
  const MAX_CHECKS = 50; // 5 seconds max wait time

  function checkDependencies() {
    const requiredModules = ['MLD_Map_App', 'MLD_Core', 'MLD_API', 'MLD_Filters', 'MLD_Utils'];

    const missingModules = [];

    for (const module of requiredModules) {
      if (typeof window[module] === 'undefined') {
        missingModules.push(module);
      }
    }

    if (missingModules.length > 0) {
      checkCount++;

      // Only log warning on first check and every 10th check to reduce spam
      if (checkCount === 1 || checkCount % 10 === 0) {
        MLDLogger.debug(`MLD: Waiting for modules (${checkCount}/${MAX_CHECKS}):`, missingModules);
      }

      // Stop checking after maximum attempts
      if (checkCount >= MAX_CHECKS) {
        MLDLogger.warning('MLD: Modules failed to load after maximum retries:', missingModules);
        return false;
      }

      return false;
    }

    if (checkCount > 0) {
      MLDLogger.debug(`MLD: All modules loaded successfully after ${checkCount} checks`);
    }

    return true;
  }

  // Initialize when all dependencies are ready
  function initializeWhenReady() {
    if (checkDependencies()) {
      MLDLogger.debug('MLD: All modules loaded successfully');

      // Trigger custom event to signal ready state
      const event = new CustomEvent('mld:ready', {
        detail: {
          modules: {
            MapApp: window.MLD_Map_App,
            Core: window.MLD_Core,
            API: window.MLD_API,
            Filters: window.MLD_Filters,
            Utils: window.MLD_Utils,
          },
        },
      });
      document.dispatchEvent(event);

      // Also trigger jQuery event for compatibility
      if (typeof jQuery !== 'undefined') {
        jQuery(document).trigger('mld:ready');
      }
    } else {
      // Retry after a short delay
      setTimeout(initializeWhenReady, 100);
    }
  }

  // Start checking when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWhenReady);
  } else {
    // DOM is already loaded
    setTimeout(initializeWhenReady, 0);
  }

  // Also provide a manual initialization function
  window.MLD_Initialize = function () {
    if (checkDependencies()) {
      MLDLogger.debug('MLD: Manual initialization successful');
      return true;
    }
    MLDLogger.error('MLD: Cannot initialize - missing dependencies');
    return false;
  };
})();
