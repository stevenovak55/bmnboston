/**
 * MLD Admin Settings JavaScript
 * Handles API testing and validation
 */

jQuery(document).ready(function ($) {
  // Test API connections
  $('.mld-test-api').on('click', function () {
    const button = $(this);
    const api = button.data('api');
    const statusCell = $(`.mld-status[data-api="${api}"]`);

    // Show loading
    button.prop('disabled', true).text('Testing...');
    statusCell.html('<span class="dashicons dashicons-update spin"></span> Testing...');

    // Make AJAX request
    $.post(mldAdmin.ajaxurl, {
      action: 'mld_test_api',
      api,
      _wpnonce: mldAdmin.nonce,
    })
      .done(function (response) {
        if (response.success) {
          statusCell.html(
            '<span class="dashicons dashicons-yes" style="color: #46b450;"></span> Connected'
          );
          button.text('Test Again');
        } else {
          statusCell.html(
            '<span class="dashicons dashicons-no" style="color: #dc3232;"></span> ' +
              response.data.message
          );
          button.text('Retry');
        }
      })
      .fail(function () {
        statusCell.html(
          '<span class="dashicons dashicons-warning" style="color: #ffb900;"></span> Connection failed'
        );
        button.text('Retry');
      })
      .always(function () {
        button.prop('disabled', false);
      });
  });

  // Auto-test on page load if API keys are present
  $(window).on('load', function () {
    $('.mld-test-api').each(function () {
      const api = $(this).data('api');
      const hasKey = checkIfApiKeyExists(api);

      if (hasKey) {
        // Auto-test after a short delay
        setTimeout(
          () => {
            $(this).trigger('click');
          },
          500 + Math.random() * 1000
        ); // Stagger the tests
      }
    });
  });

  // Check if API key exists
  function checkIfApiKeyExists(api) {
    switch (api) {
      case 'walkscore':
        return $('input[name="mld_settings[walk_score_api_key]"]').val() !== '';
      case 'googlemaps':
        return $('input[name="mld_settings[google_maps_api_key]"]').val() !== '';
      // Mapbox removed for performance optimization
      default:
        return false;
    }
  }

  // Show/hide fields based on selections
  $('input[name="mld_settings[enable_walk_score]"]')
    .on('change', function () {
      const walkScoreRow = $('input[name="mld_settings[walk_score_api_key]"]').closest('tr');
      if ($(this).is(':checked')) {
        walkScoreRow.show();
      } else {
        walkScoreRow.hide();
      }
    })
    .trigger('change');

  // Map provider selection removed - Google Maps only for performance optimization

  // Add spinning animation
  const style = $('<style>')
    .text(
      '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .spin { animation: spin 1s linear infinite; }'
    )
    .appendTo('head');
});
