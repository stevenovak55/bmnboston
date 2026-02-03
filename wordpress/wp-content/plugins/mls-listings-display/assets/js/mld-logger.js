/**
 * MLD JavaScript Logger
 * Centralized logging system for client-side debugging
 * Only logs in development environments
 */

const MLDLogger = {
  // Log levels
  DEBUG: 0,
  INFO: 1,
  WARNING: 2,
  ERROR: 3,

  // Current log level (set by server-side configuration)
  currentLevel: window.mldConfig && window.mldConfig.debug ? 0 : 2,

  /**
   * Log debug messages (only in development)
   * @param message
   * @param context
   */
  debug(message, context = {}) {
    this.log(this.DEBUG, message, context);
  },

  /**
   * Log info messages
   * @param message
   * @param context
   */
  info(message, context = {}) {
    this.log(this.INFO, message, context);
  },

  /**
   * Log warning messages
   * @param message
   * @param context
   */
  warning(message, context = {}) {
    this.log(this.WARNING, message, context);
  },

  /**
   * Log error messages
   * @param message
   * @param context
   */
  error(message, context = {}) {
    this.log(this.ERROR, message, context);
  },

  /**
   * Internal log method
   * @param level
   * @param message
   * @param context
   */
  log(level, message, context = {}) {
    // Only log if level is high enough
    if (level < this.currentLevel) {
      return;
    }

    const levelNames = {
      [this.DEBUG]: 'DEBUG',
      [this.INFO]: 'INFO',
      [this.WARNING]: 'WARNING',
      [this.ERROR]: 'ERROR',
    };

    const levelName = levelNames[level] || 'UNKNOWN';
    const timestamp = new Date().toISOString().substr(11, 8);

    // Format the message
    const logMessage = `[${timestamp}] MLD ${levelName}: ${message}`;

    // Choose console method based on level
    if (level >= this.ERROR) {
      // ERROR: commented
    } else if (level >= this.WARNING) {
      // WARN: commented
    } else if (level >= this.INFO) {
      // INFO: commented
    } else {
      // DEBUG: commented
    }

    // Send errors to server (only in production)
    if (level >= this.ERROR && this.currentLevel > this.DEBUG) {
      this.sendToServer(levelName, message, context);
    }
  },

  /**
   * Send critical errors to server
   * @param level
   * @param message
   * @param context
   */
  sendToServer(level, message, context) {
    if (!window.mldAjax || !window.mldAjax.ajaxurl) {
      return;
    }

    // Use fetch with timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000);

    fetch(window.mldAjax.ajaxurl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'mld_log_js_error',
        security: window.mldAjax.nonce,
        level,
        message,
        context: JSON.stringify(context),
        url: window.location.href,
        user_agent: navigator.userAgent,
      }),
      signal: controller.signal,
    })
      .then((response) => {
        clearTimeout(timeoutId);
        return response.json();
      })
      .catch((error) => {
        clearTimeout(timeoutId);
        // Silently fail - don't create infinite loops
      });
  },

  /**
   * Performance logging
   * @param operation
   * @param duration
   * @param additionalData
   */
  performance(operation, duration, additionalData = {}) {
    const context = {
      operation,
      duration_ms: Math.round(duration),
      ...additionalData,
    };

    if (duration > 1000) {
      this.warning(`Slow operation detected: ${operation}`, context);
    } else {
      this.debug(`Performance: ${operation}`, context);
    }
  },

  /**
   * AJAX request logging
   * @param action
   * @param data
   * @param responseTime
   */
  ajax(action, data = {}, responseTime = null) {
    const context = {
      action,
      data_size: JSON.stringify(data).length,
    };

    if (responseTime !== null) {
      context.response_time_ms = Math.round(responseTime);
    }

    this.info(`AJAX request: ${action}`, context);
  },
};

// Global error handler
window.addEventListener('error', function (event) {
  MLDLogger.error('Uncaught JavaScript error', {
    message: event.message,
    filename: event.filename,
    lineno: event.lineno,
    colno: event.colno,
    stack: event.error ? event.error.stack : 'No stack trace available',
  });
});

// Promise rejection handler
window.addEventListener('unhandledrejection', function (event) {
  MLDLogger.error('Unhandled promise rejection', {
    reason: event.reason,
    stack: event.reason && event.reason.stack ? event.reason.stack : 'No stack trace available',
  });
});

// Make globally available
window.MLDLogger = MLDLogger;
