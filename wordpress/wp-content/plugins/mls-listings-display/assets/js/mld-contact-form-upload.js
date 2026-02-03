/**
 * MLD Contact Form File Upload Handler
 *
 * Handles drag-drop file uploads with progress indicators.
 *
 * @package MLS_Listings_Display
 * @since 6.24.0
 */

(function($) {
    'use strict';

    /**
     * File Upload Handler
     */
    class MLDFileUpload {
        constructor(wrapper) {
            this.$wrapper = $(wrapper);
            this.$dropzone = this.$wrapper.find('.mld-cf-dropzone');
            this.$fileInput = this.$wrapper.find('.mld-cf-file-input');
            this.$fileList = this.$wrapper.find('.mld-cf-file-list');
            this.$tokensInput = this.$wrapper.find('[name$="_tokens"]');
            this.$browseBtn = this.$wrapper.find('.mld-cf-dropzone-browse');
            this.$form = this.$wrapper.closest('form');

            this.fieldId = this.$wrapper.data('field-id');
            this.maxFiles = parseInt(this.$wrapper.data('max-files'), 10) || 3;
            this.maxSize = parseInt(this.$wrapper.data('max-size'), 10) || 5242880; // 5MB default
            this.allowedTypes = (this.$wrapper.data('allowed-types') || 'pdf,jpg,jpeg,png').split(',');

            this.uploads = []; // Track current uploads
            this.uploadTokens = []; // Store tokens for form submission

            this.init();
        }

        init() {
            this.bindEvents();
        }

        bindEvents() {
            // Browse button click
            this.$browseBtn.on('click', (e) => {
                e.preventDefault();
                this.$fileInput.trigger('click');
            });

            // File input change
            this.$fileInput.on('change', (e) => {
                const files = e.target.files;
                if (files.length > 0) {
                    this.handleFiles(files);
                }
                // Reset input so same file can be selected again
                this.$fileInput.val('');
            });

            // Drag events
            this.$dropzone.on('dragover dragenter', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.$dropzone.addClass('mld-cf-dropzone-active');
            });

            this.$dropzone.on('dragleave dragend', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.$dropzone.removeClass('mld-cf-dropzone-active');
            });

            this.$dropzone.on('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.$dropzone.removeClass('mld-cf-dropzone-active');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    this.handleFiles(files);
                }
            });
        }

        handleFiles(files) {
            // Check max files limit
            const remainingSlots = this.maxFiles - this.uploads.length;
            if (remainingSlots <= 0) {
                this.showError(`Maximum of ${this.maxFiles} files allowed.`);
                return;
            }

            // Limit files to remaining slots
            const filesToUpload = Array.from(files).slice(0, remainingSlots);

            for (const file of filesToUpload) {
                // Validate file
                const error = this.validateFile(file);
                if (error) {
                    this.showError(error);
                    continue;
                }

                // Upload file
                this.uploadFile(file);
            }
        }

        validateFile(file) {
            // Check file size
            if (file.size > this.maxSize) {
                return `File "${file.name}" exceeds the maximum size of ${this.formatFileSize(this.maxSize)}.`;
            }

            // Check file type
            const ext = file.name.split('.').pop().toLowerCase();
            if (!this.allowedTypes.includes(ext)) {
                return `File type "${ext}" is not allowed. Allowed types: ${this.allowedTypes.join(', ').toUpperCase()}.`;
            }

            return null;
        }

        uploadFile(file) {
            // Create upload item UI
            const uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            const $uploadItem = this.createUploadItem(file, uploadId);
            this.$fileList.append($uploadItem);

            // Get form data
            const formData = new FormData();
            formData.append('action', 'mld_upload_file');
            formData.append('nonce', this.$form.find('input[name="nonce"]').val());
            formData.append('form_id', this.$form.data('form-id'));
            formData.append('field_id', this.fieldId);
            formData.append('file', file);

            // Track upload
            this.uploads.push({
                id: uploadId,
                file: file,
                status: 'uploading'
            });

            // Perform upload
            $.ajax({
                url: mldContactFormData?.ajaxUrl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: () => {
                    const xhr = new XMLHttpRequest();
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            this.updateProgress($uploadItem, percent);
                        }
                    });
                    return xhr;
                },
                success: (response) => {
                    if (response.success) {
                        this.handleUploadSuccess($uploadItem, uploadId, response.data);
                    } else {
                        this.handleUploadError($uploadItem, uploadId, response.data?.message || 'Upload failed');
                    }
                },
                error: (xhr, status, error) => {
                    this.handleUploadError($uploadItem, uploadId, 'Network error. Please try again.');
                }
            });
        }

        createUploadItem(file, uploadId) {
            const isImage = /^image\/(jpeg|png|gif|webp)$/.test(file.type);
            const iconClass = this.getFileIcon(file.type);

            const $item = $(`
                <div class="mld-cf-file-item" data-upload-id="${uploadId}">
                    <div class="mld-cf-file-icon">
                        ${isImage ? '<img class="mld-cf-file-thumbnail" src="" alt="">' : `<span class="dashicons ${iconClass}"></span>`}
                    </div>
                    <div class="mld-cf-file-info">
                        <span class="mld-cf-file-name">${this.escapeHtml(file.name)}</span>
                        <span class="mld-cf-file-size">${this.formatFileSize(file.size)}</span>
                        <div class="mld-cf-file-progress">
                            <div class="mld-cf-file-progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                    <button type="button" class="mld-cf-file-remove" title="Remove">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            `);

            // Show thumbnail preview for images
            if (isImage) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    $item.find('.mld-cf-file-thumbnail').attr('src', e.target.result);
                };
                reader.readAsDataURL(file);
            }

            // Remove button click
            $item.find('.mld-cf-file-remove').on('click', () => {
                this.removeUpload(uploadId);
            });

            return $item;
        }

        updateProgress($item, percent) {
            $item.find('.mld-cf-file-progress-bar').css('width', percent + '%');
        }

        handleUploadSuccess($item, uploadId, data) {
            // Update upload tracking
            const upload = this.uploads.find(u => u.id === uploadId);
            if (upload) {
                upload.status = 'complete';
                upload.uploadId = data.upload_id;
                upload.uploadToken = data.upload_token;
            }

            // Add token to list
            this.uploadTokens.push(data.upload_token);
            this.updateTokensInput();

            // Update UI
            $item.addClass('mld-cf-file-item-complete');
            $item.find('.mld-cf-file-progress').hide();

            // Update thumbnail if available
            if (data.thumbnail_url) {
                $item.find('.mld-cf-file-thumbnail').attr('src', data.thumbnail_url);
            }
        }

        handleUploadError($item, uploadId, message) {
            // Update upload tracking
            const upload = this.uploads.find(u => u.id === uploadId);
            if (upload) {
                upload.status = 'error';
            }

            // Update UI
            $item.addClass('mld-cf-file-item-error');
            $item.find('.mld-cf-file-progress').hide();
            $item.find('.mld-cf-file-info').append(`<span class="mld-cf-file-error">${this.escapeHtml(message)}</span>`);
        }

        removeUpload(uploadId) {
            const uploadIndex = this.uploads.findIndex(u => u.id === uploadId);
            if (uploadIndex === -1) return;

            const upload = this.uploads[uploadIndex];

            // If upload was successful, remove from server
            if (upload.status === 'complete' && upload.uploadToken) {
                $.ajax({
                    url: mldContactFormData?.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'mld_remove_upload',
                        nonce: this.$form.find('input[name="nonce"]').val(),
                        upload_id: upload.uploadId,
                        upload_token: upload.uploadToken
                    }
                });

                // Remove token from list
                const tokenIndex = this.uploadTokens.indexOf(upload.uploadToken);
                if (tokenIndex > -1) {
                    this.uploadTokens.splice(tokenIndex, 1);
                }
            }

            // Remove from tracking
            this.uploads.splice(uploadIndex, 1);

            // Remove UI element
            this.$fileList.find(`[data-upload-id="${uploadId}"]`).remove();

            // Update tokens input
            this.updateTokensInput();
        }

        updateTokensInput() {
            this.$tokensInput.val(this.uploadTokens.join(','));
        }

        showError(message) {
            const $fieldWrapper = this.$wrapper.closest('.mld-cf-field');
            const $errorContainer = $fieldWrapper.find('.mld-cf-field-error');

            $errorContainer.text(message).addClass('active');

            // Clear error after 5 seconds
            setTimeout(() => {
                $errorContainer.removeClass('active').text('');
            }, 5000);
        }

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        getFileIcon(mimeType) {
            if (mimeType.startsWith('image/')) return 'dashicons-format-image';
            if (mimeType === 'application/pdf') return 'dashicons-pdf';
            if (mimeType.includes('word') || mimeType.includes('document')) return 'dashicons-media-document';
            if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'dashicons-media-spreadsheet';
            if (mimeType.startsWith('text/')) return 'dashicons-media-text';
            return 'dashicons-media-default';
        }

        escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }

    /**
     * Initialize file upload handlers
     */
    function initFileUploads() {
        $('.mld-cf-file-upload-wrapper').each(function() {
            if (!$(this).data('mld-upload-init')) {
                new MLDFileUpload(this);
                $(this).data('mld-upload-init', true);
            }
        });
    }

    // Initialize on DOM ready
    $(document).ready(function() {
        initFileUploads();
    });

    // Re-initialize for dynamically loaded forms
    $(document).on('mld-form-loaded', function() {
        initFileUploads();
    });

    // Export for external use
    window.MLDFileUpload = MLDFileUpload;
    window.initMLDFileUploads = initFileUploads;

})(jQuery);
