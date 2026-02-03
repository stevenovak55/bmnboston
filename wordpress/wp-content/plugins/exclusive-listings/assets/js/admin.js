/**
 * Exclusive Listings Admin JavaScript
 *
 * @package Exclusive_Listings
 * @since 1.2.0
 */

(function($) {
    'use strict';

    // Photo Manager
    var PhotoManager = {
        listingId: null,
        maxPhotos: 50,

        init: function() {
            var $container = $('#el-photo-manager');
            if (!$container.length) {
                return;
            }

            this.listingId = $container.data('listing-id');
            this.maxPhotos = elAdmin.maxPhotos || 50;

            this.initSortable();
            this.bindEvents();
        },

        initSortable: function() {
            var self = this;

            $('#el-photo-grid').sortable({
                items: '.el-photo-item',
                cursor: 'move',
                opacity: 0.7,
                placeholder: 'el-photo-item ui-sortable-placeholder',
                update: function(event, ui) {
                    self.saveOrder();
                    self.updateOrderNumbers();
                }
            });
        },

        bindEvents: function() {
            var self = this;

            // Add photos button
            $('#el-add-photos').on('click', function(e) {
                e.preventDefault();
                $('#el-photo-input').click();
            });

            // File input change
            $('#el-photo-input').on('change', function(e) {
                var files = e.target.files;
                if (files.length > 0) {
                    self.uploadFiles(files);
                }
                // Reset input
                $(this).val('');
            });

            // Delete photo
            $(document).on('click', '.el-photo-delete', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $item = $(this).closest('.el-photo-item');
                var photoId = $(this).data('id');

                if (confirm('Delete this photo?')) {
                    self.deletePhoto(photoId, $item);
                }
            });

            // Drag and drop
            var $uploadArea = $('.el-photo-upload');

            $uploadArea.on('dragover dragenter', function(e) {
                e.preventDefault();
                $(this).addClass('dragging');
            });

            $uploadArea.on('dragleave dragend drop', function(e) {
                e.preventDefault();
                $(this).removeClass('dragging');
            });

            $uploadArea.on('drop', function(e) {
                e.preventDefault();
                var files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    self.uploadFiles(files);
                }
            });
        },

        uploadFiles: function(files) {
            var self = this;
            var currentCount = $('#el-photo-grid .el-photo-item').length;
            var remaining = this.maxPhotos - currentCount;

            if (remaining <= 0) {
                this.showStatus('Maximum ' + this.maxPhotos + ' photos allowed', 'error');
                return;
            }

            // Limit files to remaining slots
            var filesToUpload = Array.from(files).slice(0, remaining);
            var uploadCount = filesToUpload.length;
            var completed = 0;
            var errors = [];

            this.showStatus('Uploading ' + uploadCount + ' photo(s)...', 'uploading');

            filesToUpload.forEach(function(file) {
                // Check file size
                if (file.size > elAdmin.maxFileSize) {
                    completed++;
                    errors.push(file.name + ' is too large');
                    self.checkUploadComplete(completed, uploadCount, errors);
                    return;
                }

                // Check file type
                if (!file.type.match(/^image\//)) {
                    completed++;
                    errors.push(file.name + ' is not an image');
                    self.checkUploadComplete(completed, uploadCount, errors);
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'el_upload_photo');
                formData.append('nonce', elAdmin.nonce);
                formData.append('listing_id', self.listingId);
                formData.append('photo', file);

                $.ajax({
                    url: elAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            self.addPhotoToGrid(response.data);
                        } else {
                            errors.push(response.data || 'Upload failed');
                        }
                    },
                    error: function() {
                        errors.push('Network error');
                    },
                    complete: function() {
                        completed++;
                        self.checkUploadComplete(completed, uploadCount, errors);
                    }
                });
            });
        },

        checkUploadComplete: function(completed, total, errors) {
            if (completed === total) {
                if (errors.length > 0) {
                    this.showStatus(errors.join(', '), 'error');
                } else {
                    this.showStatus('Upload complete!', 'success');
                    setTimeout(function() {
                        $('#el-upload-status').text('');
                    }, 3000);
                }
                this.updateOrderNumbers();
            }
        },

        addPhotoToGrid: function(data) {
            var count = $('#el-photo-grid .el-photo-item').length;
            var isPrimary = count === 0;

            var html = '<div class="el-photo-item" data-id="' + data.id + '">' +
                '<img src="' + data.url + '" alt="">' +
                '<div class="el-photo-overlay">' +
                '<span class="el-photo-order">' + (count + 1) + '</span>' +
                '<button type="button" class="el-photo-delete" data-id="' + data.id + '" title="Delete">' +
                '<span class="dashicons dashicons-trash"></span>' +
                '</button>' +
                '</div>' +
                (isPrimary ? '<span class="el-photo-primary">Primary</span>' : '') +
                '</div>';

            $('#el-photo-grid').append(html);
        },

        deletePhoto: function(photoId, $item) {
            var self = this;

            $.ajax({
                url: elAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'el_delete_photo',
                    nonce: elAdmin.nonce,
                    listing_id: this.listingId,
                    photo_id: photoId
                },
                success: function(response) {
                    if (response.success) {
                        $item.fadeOut(300, function() {
                            $(this).remove();
                            self.updateOrderNumbers();
                            self.updatePrimaryBadge();
                        });
                    } else {
                        alert(response.data || 'Failed to delete photo');
                    }
                },
                error: function() {
                    alert('Network error');
                }
            });
        },

        saveOrder: function() {
            var order = [];

            $('#el-photo-grid .el-photo-item').each(function() {
                order.push($(this).data('id'));
            });

            $.ajax({
                url: elAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'el_reorder_photos',
                    nonce: elAdmin.nonce,
                    listing_id: this.listingId,
                    order: order
                },
                success: function(response) {
                    if (!response.success) {
                        console.error('Failed to save order:', response.data);
                    }
                },
                error: function() {
                    console.error('Network error saving order');
                }
            });
        },

        updateOrderNumbers: function() {
            $('#el-photo-grid .el-photo-item').each(function(index) {
                $(this).find('.el-photo-order').text(index + 1);
            });
            this.updatePrimaryBadge();
        },

        updatePrimaryBadge: function() {
            // Remove all primary badges
            $('#el-photo-grid .el-photo-primary').remove();

            // Add primary badge to first photo
            var $first = $('#el-photo-grid .el-photo-item').first();
            if ($first.length && !$first.find('.el-photo-primary').length) {
                $first.append('<span class="el-photo-primary">Primary</span>');
            }
        },

        showStatus: function(message, type) {
            var $status = $('#el-upload-status');
            $status.removeClass('uploading error success').addClass(type);

            if (type === 'uploading') {
                $status.html('<span class="el-loading"></span>' + message);
            } else {
                $status.text(message);
            }
        }
    };

    // Bulk Actions Manager
    var BulkActions = {
        init: function() {
            var $form = $('#el-bulk-form');
            if (!$form.length) {
                return;
            }

            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // Select all checkboxes (header and footer)
            $('#cb-select-all-1, #cb-select-all-2').on('change', function() {
                var isChecked = $(this).prop('checked');
                // Sync both select-all checkboxes
                $('#cb-select-all-1, #cb-select-all-2').prop('checked', isChecked);
                // Check/uncheck all row checkboxes
                $('input[name="listing_ids[]"]').prop('checked', isChecked);
                self.updateSelectedCount();
            });

            // Individual row checkboxes
            $(document).on('change', 'input[name="listing_ids[]"]', function() {
                self.updateSelectAllState();
                self.updateSelectedCount();
            });

            // Form submission - confirmation for delete
            $('#el-bulk-form').on('submit', function(e) {
                var action = $('#bulk-action-selector-top').val();
                if (action === '-1') {
                    action = $('#bulk-action-selector-bottom').val();
                }

                var selectedCount = $('input[name="listing_ids[]"]:checked').length;

                if (selectedCount === 0) {
                    e.preventDefault();
                    alert('Please select at least one listing.');
                    return false;
                }

                if (action === 'delete') {
                    if (!confirm('Are you sure you want to PERMANENTLY DELETE ' + selectedCount + ' listing(s)? This action cannot be undone.')) {
                        e.preventDefault();
                        return false;
                    }
                } else if (action === 'archive') {
                    if (!confirm('Archive ' + selectedCount + ' listing(s)?')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });

            // Sync top and bottom dropdowns
            $('#bulk-action-selector-top').on('change', function() {
                $('#bulk-action-selector-bottom').val($(this).val());
            });

            $('#bulk-action-selector-bottom').on('change', function() {
                $('#bulk-action-selector-top').val($(this).val());
            });
        },

        updateSelectAllState: function() {
            var total = $('input[name="listing_ids[]"]').length;
            var checked = $('input[name="listing_ids[]"]:checked').length;

            var isAllChecked = total > 0 && total === checked;
            $('#cb-select-all-1, #cb-select-all-2').prop('checked', isAllChecked);
        },

        updateSelectedCount: function() {
            var checked = $('input[name="listing_ids[]"]:checked').length;
            // Update display if we add a counter element later
            // For now, just visual feedback
        }
    };

    // Form validation
    var FormValidator = {
        init: function() {
            var $form = $('#el-listing-form');
            if (!$form.length) {
                return;
            }

            $form.on('submit', function(e) {
                var isValid = FormValidator.validate();
                if (!isValid) {
                    e.preventDefault();
                }
            });

            // Price formatting
            $('#list_price').on('blur', function() {
                var value = $(this).val().replace(/[^0-9.]/g, '');
                if (value) {
                    $(this).val(value);
                }
            });
        },

        validate: function() {
            var isValid = true;
            var errors = [];

            // Check required fields
            $('[required]').each(function() {
                if (!$(this).val().trim()) {
                    isValid = false;
                    var label = $('label[for="' + $(this).attr('id') + '"]').text().replace(' *', '');
                    errors.push(label + ' is required');
                    $(this).addClass('error');
                } else {
                    $(this).removeClass('error');
                }
            });

            // Validate price
            var price = $('#list_price').val();
            if (price && parseFloat(price) <= 0) {
                isValid = false;
                errors.push('Price must be greater than 0');
                $('#list_price').addClass('error');
            }

            // Validate state
            var state = $('#state_or_province').val();
            if (state && state.length !== 2) {
                isValid = false;
                errors.push('State must be a 2-letter code');
                $('#state_or_province').addClass('error');
            }

            // Validate postal code
            var postal = $('#postal_code').val();
            if (postal && !/^\d{5}(-\d{4})?$/.test(postal)) {
                isValid = false;
                errors.push('Invalid postal code format');
                $('#postal_code').addClass('error');
            }

            if (!isValid) {
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            }

            return isValid;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        PhotoManager.init();
        FormValidator.init();
        BulkActions.init();
    });

})(jQuery);
