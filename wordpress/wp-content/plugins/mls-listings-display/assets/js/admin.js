/**
 * Admin JavaScript for MLS Listings Display
 * - REFACTOR: Generalizes the media uploader to work with any button that has the `.mld-upload-button` class and corresponding data attributes.
 * - This allows it to be used for the main logo and the new dynamic icon manager.
 */
jQuery(document).ready(function ($) {
  'use strict';

  let mediaFrame;

  // Use event delegation for dynamically added buttons
  $(document).on('click', '.mld-upload-button', function (e) {
    e.preventDefault();

    const $button = $(this);
    const targetInput = $button.data('target-input');
    const targetPreview = $button.data('target-preview');

    // If the frame already exists, re-open it.
    if (mediaFrame) {
      mediaFrame.open();
      return;
    }

    // Sets up the media library frame.
    mediaFrame = wp.media.frames.file_frame = wp.media({
      title: 'Choose an Icon or Logo',
      button: {
        text: 'Use this image',
      },
      multiple: false, // Do not allow multiple files to be selected
    });

    // Runs when an image is selected.
    mediaFrame.on('select', function () {
      const attachment = mediaFrame.state().get('selection').first().toJSON();

      // Send the attachment URL to the target input field.
      $(targetInput).val(attachment.url);

      // Display the image preview.
      $(targetPreview).html('<img src="' + attachment.url + '" />');
    });

    // Opens the media library frame.
    mediaFrame.open();
  });

  // Import Schools
  $('#mld-import-schools').on('submit', function(e) {
      e.preventDefault();

      const state = $('#schools-state').val();
      const $button = $(this).find('button[type="submit"]');
      const $progress = $('#schools-progress');
      const $results = $('#import-results');
      const originalText = $button.text();
      const clearExisting = $(this).find('input[name="clear_existing"]').is(':checked');

      if (!state) {
          alert('Please select a state');
          return;
      }

      $button.prop('disabled', true).text('Importing...');
      $progress.show();
      $results.hide();

      // Create progress elements
      $progress.html(`
          <div class="notice notice-info">
              <h3>Import Progress</h3>
              <div class="progress-bar" style="background: #f0f0f0; height: 30px; border-radius: 3px; margin: 10px 0;">
                  <div class="progress-bar-fill" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s; border-radius: 3px;"></div>
              </div>
              <p class="progress-text">Starting import for ${state}...</p>
              <div class="progress-details" style="margin-top: 10px;">
                  <ul>
                      <li>Imported: <span class="imported-count">0</span></li>
                      <li>Skipped: <span class="skipped-count">0</span></li>
                      <li>Errors: <span class="error-count">0</span></li>
                  </ul>
              </div>
              <div class="current-item" style="margin-top: 10px; font-style: italic;"></div>
          </div>
      `);

      // Simulate progress updates (since we don't have SSE setup yet)
      let progressInterval = setInterval(function() {
          const currentWidth = parseFloat($progress.find('.progress-bar-fill').css('width'));
          const maxWidth = parseFloat($progress.find('.progress-bar').css('width'));
          const percent = (currentWidth / maxWidth) * 100;

          if (percent < 90) {
              $progress.find('.progress-bar-fill').css('width', (percent + 5) + '%');
              $progress.find('.progress-text').text(`Processing schools for ${state}... ${Math.round(percent + 5)}%`);
          }
      }, 500);

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_import_schools',
              state: state,
              clear_existing: clearExisting ? '1' : '0',
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              clearInterval(progressInterval);

              if (response.success) {
                  // Update progress to 100%
                  $progress.find('.progress-bar-fill').css('width', '100%');
                  $progress.find('.progress-text').text('Import completed!');

                  // Show success message
                  $progress.html(`
                      <div class="notice notice-success">
                          <p><strong>${response.data.message}</strong></p>
                          <ul>
                              <li>Total Imported: ${response.data.imported}</li>
                              <li>Duplicates Skipped: ${response.data.skipped || 0}</li>
                          </ul>
                      </div>
                  `);

                  // Show detailed results if available
                  if (response.data.details_html) {
                      $results.show().html(`
                          <div class="mld-import-results">
                              ${response.data.details_html}
                          </div>
                      `);
                  }

                  // Refresh statistics
                  loadStatistics();
              } else {
                  $progress.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Import failed') + '</p></div>');
              }
          },
          error: function(xhr, status, error) {
              clearInterval(progressInterval);
              $progress.html(`
                  <div class="notice notice-error">
                      <p>Import failed: ${error}</p>
                      <p>Please check your connection and try again.</p>
                  </div>
              `);
          },
          complete: function() {
              $button.prop('disabled', false).text(originalText);
          }
      });
  });


  // Import City Boundaries
  $('#mld-import-boundaries').on('submit', function(e) {
      e.preventDefault();

      const state = $('#boundaries-state').val();
      const $button = $(this).find('button[type="submit"]');
      const $progress = $('#boundaries-progress');
      const originalText = $button.text();

      if (!state) {
          alert('Please select a state');
          return;
      }

      $button.prop('disabled', true).text('Importing...');
      $progress.show().html('<div class="notice notice-info"><p>Starting import for ' + state + '...</p></div>');

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_import_boundaries',
              state: state,
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              if (response.success) {
                  $progress.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                  // Refresh statistics
                  loadStatistics();
              } else {
                  $progress.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
              }
          },
          error: function() {
              $progress.html('<div class="notice notice-error"><p>Import failed. Please try again.</p></div>');
          },
          complete: function() {
              $button.prop('disabled', false).text(originalText);
          }
      });
  });

  // Load statistics on page load
  function loadStatistics() {
      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_get_statistics',
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              if (response.success && response.data) {
                  const stats = response.data;
                  $('.mld-stat-schools .stat-value').text(stats.schools || 0);
                  $('.mld-stat-boundaries .stat-value').text(stats.boundaries || 0);

                  // Update last import times if available
                  if (stats.last_import) {
                      if (stats.last_import.schools) {
                          $('.mld-stat-schools .stat-label').append('<br><small>Last import: ' + stats.last_import.schools + '</small>');
                      }
                      if (stats.last_import.boundaries) {
                          $('.mld-stat-boundaries .stat-label').append('<br><small>Last import: ' + stats.last_import.boundaries + '</small>');
                      }
                  }
              }
          }
      });
  }

  // Load statistics on statistics page
  if ($('.mld-statistics-grid').length) {
      loadStatistics();
  }

  // Clear data functionality
  $('.clear-data-btn').on('click', function(e) {
      e.preventDefault();

      const dataType = $(this).data('type');
      const typeName = dataType.charAt(0).toUpperCase() + dataType.slice(1);

      if (!confirm('Are you sure you want to clear all ' + typeName + ' data? This cannot be undone.')) {
          return;
      }

      const $button = $(this);
      const originalText = $button.text();

      $button.prop('disabled', true).text('Clearing...');

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_clear_data',
              data_type: dataType,
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              if (response.success) {
                  alert(response.data.message);
                  loadStatistics();
              } else {
                  alert('Error: ' + response.data);
              }
          },
          error: function() {
              alert('Failed to clear data. Please try again.');
          },
          complete: function() {
              $button.prop('disabled', false).text(originalText);
          }
      });
  });

  // Toggle notifications functionality for saved searches admin
  $(document).on('change', '.toggle-notifications', function() {
      const $toggle = $(this);
      const searchId = $toggle.data('id');
      const enabled = $toggle.is(':checked');
      const $statusSpan = $toggle.closest('td').find('.notifications-status');

      // Update status text immediately for better UX
      if ($statusSpan.length) {
          $statusSpan.text(enabled ? 'On' : 'Off');
      }

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_toggle_search_notifications',
              search_id: searchId,
              enabled: enabled ? 1 : 0,
              nonce: mldAdmin.nonce || (typeof mldSavedSearchAdmin !== 'undefined' ? mldSavedSearchAdmin.nonce : '')
          },
          success: function(response) {
              if (!response.success) {
                  // Revert if failed
                  $toggle.prop('checked', !enabled);
                  if ($statusSpan.length) {
                      $statusSpan.text(!enabled ? 'On' : 'Off');
                  }
                  alert('Failed to update notification settings: ' + (response.data || 'Unknown error'));
              }
          },
          error: function() {
              // Revert if failed
              $toggle.prop('checked', !enabled);
              if ($statusSpan.length) {
                  $statusSpan.text(!enabled ? 'On' : 'Off');
              }
              alert('Failed to update notification settings. Please try again.');
          }
      });
  });

  // Global notification system toggle (used in notification status dashboard)
  $(document).on('click', '.toggle-notifications-global', function() {
      const $button = $(this);
      const enabled = $button.data('enabled') === 'true';
      const originalText = $button.text();

      $button.prop('disabled', true).text('Updating...');

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_toggle_notifications',
              enabled: enabled,
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              if (response.success) {
                  // Reload page to show updated status
                  location.reload();
              } else {
                  alert('Error: ' + response.data);
              }
          },
          error: function() {
              alert('Failed to update notification settings');
          },
          complete: function() {
              $button.prop('disabled', false).text(originalText);
          }
      });
  });

  // Send test notification functionality
  $(document).on('click', '.send-test-notification', function() {
      const $button = $(this);
      const originalHtml = $button.html();

      $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Sending...');

      $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
              action: 'mld_send_test_notification',
              nonce: mldAdmin.nonce
          },
          success: function(response) {
              if (response.success) {
                  alert('Test notification sent successfully!');
              } else {
                  alert('Error: ' + response.data);
              }
          },
          error: function() {
              alert('Failed to send test notification');
          },
          complete: function() {
              $button.prop('disabled', false).html(originalHtml);
          }
      });
  });

  // Add CSS for spinning animation if not already present
  if (!document.querySelector('#mld-admin-styles')) {
      const style = document.createElement('style');
      style.id = 'mld-admin-styles';
      style.textContent = `
          .spin {
              animation: mld-spin 1s linear infinite;
          }
          @keyframes mld-spin {
              0% { transform: rotate(0deg); }
              100% { transform: rotate(360deg); }
          }
          .switch {
              position: relative;
              display: inline-block;
              width: 44px;
              height: 24px;
              margin-right: 10px;
          }
          .switch input {
              opacity: 0;
              width: 0;
              height: 0;
          }
          .slider {
              position: absolute;
              cursor: pointer;
              top: 0;
              left: 0;
              right: 0;
              bottom: 0;
              background-color: #ccc;
              transition: 0.4s;
          }
          .slider:before {
              position: absolute;
              content: "";
              height: 18px;
              width: 18px;
              left: 3px;
              bottom: 3px;
              background-color: white;
              transition: 0.4s;
          }
          input:checked + .slider {
              background-color: #4CAF50;
          }
          input:checked + .slider:before {
              transform: translateX(20px);
          }
          .slider.round {
              border-radius: 24px;
          }
          .slider.round:before {
              border-radius: 50%;
          }
      `;
      document.head.appendChild(style);
  }
});