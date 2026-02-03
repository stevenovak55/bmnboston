/**
 * Homepage Section Manager - Admin JavaScript
 *
 * Handles drag-and-drop reordering, modal editing,
 * and AJAX save operations for homepage sections.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.3
 */

(function($) {
    'use strict';

    var SectionManager = {
        // Cache DOM elements
        $list: null,
        $form: null,
        $modal: null,
        $saveBtn: null,
        $saveStatus: null,

        // Current editing state
        currentSection: null,

        /**
         * Initialize the section manager
         */
        init: function() {
            this.$list = $('#bne-sections-list');
            this.$form = $('#bne-sections-form');
            this.$modal = $('#bne-editor-modal');
            this.$saveBtn = $('#bne-save-sections');
            this.$saveStatus = $('.bne-save-status');

            this.bindEvents();
            this.initSortable();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Form submit
            this.$form.on('submit', function(e) {
                e.preventDefault();
                self.saveAllSections();
            });

            // Add custom section
            $('#bne-add-section').on('click', function() {
                self.addCustomSection();
            });

            // Edit section (custom)
            this.$list.on('click', '.bne-edit-section', function() {
                var $item = $(this).closest('.bne-section-item');
                self.openEditor($item.data('section-id'), 'custom');
            });

            // Edit override (built-in)
            this.$list.on('click', '.bne-edit-override', function() {
                var $item = $(this).closest('.bne-section-item');
                self.openEditor($item.data('section-id'), 'builtin');
            });

            // Delete section
            this.$list.on('click', '.bne-delete-section', function() {
                var $item = $(this).closest('.bne-section-item');
                self.deleteSection($item);
            });

            // Toggle enabled state
            this.$list.on('change', '.bne-toggle input', function() {
                var $item = $(this).closest('.bne-section-item');
                $item.toggleClass('bne-section-item--disabled', !this.checked);
            });

            // Modal close
            this.$modal.on('click', '.bne-modal__close, .bne-modal__overlay, #bne-editor-cancel', function() {
                self.closeEditor();
            });

            // Modal save
            $('#bne-editor-save').on('click', function() {
                self.saveEditorContent();
            });

            // Clear override
            $('#bne-editor-clear-override').on('click', function() {
                if (confirm(bneSectionManager.strings.confirmClearOverride)) {
                    $('#bne-editor-html').val('');
                    self.saveEditorContent();
                }
            });

            // Escape key closes modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.$modal.is(':visible')) {
                    self.closeEditor();
                }
            });
        },

        /**
         * Initialize jQuery UI Sortable
         */
        initSortable: function() {
            this.$list.sortable({
                handle: '.bne-section-item__drag',
                placeholder: 'bne-section-item--placeholder',
                tolerance: 'pointer',
                cursor: 'grabbing',
                opacity: 0.8,
                update: function() {
                    // Order changed - form becomes dirty
                }
            });
        },

        /**
         * Collect all section data from the list
         */
        collectSectionsData: function() {
            var sections = [];

            this.$list.find('.bne-section-item').each(function() {
                var $item = $(this);
                var sectionId = $item.data('section-id');
                var sectionType = $item.data('section-type');
                var $checkbox = $item.find('.bne-toggle input');

                var section = {
                    id: sectionId,
                    type: sectionType,
                    name: $item.find('.bne-section-item__name').text().replace('Custom', '').replace('Override', '').trim(),
                    enabled: $checkbox.is(':checked')
                };

                // Get HTML content from data attribute (updated by editor)
                if (sectionType === 'custom') {
                    section.html = $item.data('html') || '';
                } else {
                    section.override_html = $item.data('override-html') || '';
                }

                sections.push(section);
            });

            return sections;
        },

        /**
         * Save all sections via AJAX
         */
        saveAllSections: function() {
            var self = this;
            var sections = this.collectSectionsData();

            this.$saveBtn.prop('disabled', true);
            this.$saveStatus.text(bneSectionManager.strings.saving).removeClass('success error');

            $.ajax({
                url: bneSectionManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bne_save_sections',
                    nonce: bneSectionManager.nonce,
                    sections: sections
                },
                success: function(response) {
                    if (response.success) {
                        self.$saveStatus.text(bneSectionManager.strings.saved).addClass('success');
                        setTimeout(function() {
                            self.$saveStatus.text('');
                        }, 3000);
                    } else {
                        self.$saveStatus.text(response.data.message || bneSectionManager.strings.error).addClass('error');
                    }
                },
                error: function() {
                    self.$saveStatus.text(bneSectionManager.strings.error).addClass('error');
                },
                complete: function() {
                    self.$saveBtn.prop('disabled', false);
                }
            });
        },

        /**
         * Add a new custom section
         */
        addCustomSection: function() {
            var self = this;
            var name = bneSectionManager.strings.newSectionName;

            $.ajax({
                url: bneSectionManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bne_add_custom_section',
                    nonce: bneSectionManager.nonce,
                    name: name,
                    html: ''
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the page to show new section
                        location.reload();
                    } else {
                        alert(response.data.message || bneSectionManager.strings.error);
                    }
                },
                error: function() {
                    alert(bneSectionManager.strings.error);
                }
            });
        },

        /**
         * Delete a custom section
         */
        deleteSection: function($item) {
            var self = this;
            var sectionId = $item.data('section-id');

            if (!confirm(bneSectionManager.strings.confirmDelete)) {
                return;
            }

            $.ajax({
                url: bneSectionManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bne_delete_section',
                    nonce: bneSectionManager.nonce,
                    section_id: sectionId
                },
                success: function(response) {
                    if (response.success) {
                        $item.slideUp(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data.message || bneSectionManager.strings.error);
                    }
                },
                error: function() {
                    alert(bneSectionManager.strings.error);
                }
            });
        },

        /**
         * Open the HTML editor modal
         */
        openEditor: function(sectionId, sectionType) {
            var self = this;
            var $item = this.$list.find('[data-section-id="' + sectionId + '"]');

            // Set current section info
            this.currentSection = {
                id: sectionId,
                type: sectionType,
                $item: $item
            };

            $('#bne-editor-section-id').val(sectionId);
            $('#bne-editor-section-type').val(sectionType);

            // Set title
            var title = sectionType === 'custom' ? 'Edit Custom Section' : 'Edit Section Override';
            this.$modal.find('.bne-modal__title').text(title);

            // Show/hide name field (only for custom sections)
            var $nameField = this.$modal.find('.bne-modal__field--name');
            if (sectionType === 'custom') {
                $nameField.show();
                $('#bne-editor-name').val($item.find('.bne-section-item__name').text().replace('Custom', '').trim());
            } else {
                $nameField.hide();
            }

            // Show/hide clear override button
            var $clearBtn = $('#bne-editor-clear-override');
            if (sectionType === 'builtin' && $item.data('override-html')) {
                $clearBtn.show();
            } else {
                $clearBtn.hide();
            }

            // Get HTML content
            var html = '';
            if (sectionType === 'custom') {
                html = $item.data('html') || '';
            } else {
                html = $item.data('override-html') || '';
            }
            $('#bne-editor-html').val(html);

            // Show modal
            this.$modal.fadeIn(200);
            $('body').addClass('modal-open');

            // Focus textarea
            setTimeout(function() {
                $('#bne-editor-html').focus();
            }, 300);
        },

        /**
         * Close the editor modal
         */
        closeEditor: function() {
            this.$modal.fadeOut(200);
            $('body').removeClass('modal-open');
            this.currentSection = null;
        },

        /**
         * Save editor content to the list item
         */
        saveEditorContent: function() {
            if (!this.currentSection) {
                return;
            }

            var sectionId = this.currentSection.id;
            var sectionType = this.currentSection.type;
            var $item = this.currentSection.$item;

            var html = $('#bne-editor-html').val();
            var name = $('#bne-editor-name').val();

            // Update data attributes
            if (sectionType === 'custom') {
                $item.data('html', html);
                if (name) {
                    $item.find('.bne-section-item__name').contents().filter(function() {
                        return this.nodeType === 3; // Text node
                    }).last().replaceWith(name);
                }
            } else {
                $item.data('override-html', html);

                // Update override badge
                var $badge = $item.find('.bne-section-item__badge--override');
                var $editBtn = $item.find('.bne-edit-override');

                if (html) {
                    if (!$badge.length) {
                        $item.find('.bne-section-item__name').append(' <span class="bne-section-item__badge bne-section-item__badge--override">Override</span>');
                    }
                    $item.addClass('bne-section-item--override');
                    $editBtn.text('Edit Override');
                } else {
                    $badge.remove();
                    $item.removeClass('bne-section-item--override');
                    $editBtn.text('Add Override');
                }
            }

            this.closeEditor();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        if ($('#bne-sections-list').length) {
            SectionManager.init();
        }
    });

})(jQuery);
