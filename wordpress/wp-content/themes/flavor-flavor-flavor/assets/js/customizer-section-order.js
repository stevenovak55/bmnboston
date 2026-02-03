/**
 * Homepage Section Order - Customizer Control JavaScript
 *
 * Handles drag-and-drop reordering and visibility toggles
 * in the WordPress Customizer.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.3
 */

(function($, api) {
    'use strict';

    // Register control constructor
    api.controlConstructor['section_order'] = api.Control.extend({

        ready: function() {
            var control = this;
            var $container = control.container;
            var $list = $container.find('.bne-customizer-sections');

            // Initialize sortable
            $list.sortable({
                handle: '.bne-customizer-section__drag',
                placeholder: 'bne-customizer-section--placeholder',
                tolerance: 'pointer',
                update: function() {
                    control.updateValue();
                }
            });

            // Handle toggle changes
            $list.on('change', 'input[type="checkbox"]', function() {
                var $item = $(this).closest('.bne-customizer-section');
                $item.toggleClass('bne-customizer-section--disabled', !this.checked);
                control.updateValue();
            });

            // Set initial value if empty
            if (!control.setting.get()) {
                control.updateValue();
            }
        },

        /**
         * Update the setting value with current order and visibility
         */
        updateValue: function() {
            var control = this;
            var $list = control.container.find('.bne-customizer-sections');
            var sections = [];

            $list.find('.bne-customizer-section').each(function() {
                var $item = $(this);
                sections.push({
                    id: $item.data('section-id'),
                    enabled: $item.find('input[type="checkbox"]').is(':checked')
                });
            });

            var value = JSON.stringify(sections);

            // Set the value - this triggers the Publish button
            if (control.setting) {
                control.setting.set(value);
            }
        }
    });

})(jQuery, wp.customize);
