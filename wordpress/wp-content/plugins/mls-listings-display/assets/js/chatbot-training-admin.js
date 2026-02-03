/**
 * Chatbot Training Admin Interface
 *
 * Handles form submission and training examples display
 *
 * @package MLS_Listings_Display
 * @since 6.8.0
 */

(function($) {
    'use strict';

    // Initialize when DOM is ready
    $(document).ready(function() {
        TrainingAdmin.init();
    });

    const TrainingAdmin = {
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.loadTrainingExamples();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Form submission
            $('#mld-training-form').on('submit', this.handleFormSubmit.bind(this));

            // Delete training example
            $(document).on('click', '.delete-training-example', this.handleDelete.bind(this));

            // Old filter (kept for backward compatibility)
            $(document).on('change', '#training-filter', this.handleFilter.bind(this));

            // New search and filters (v6.9.0)
            $('#apply-filters').on('click', this.applyFilters.bind(this));
            $('#clear-filters').on('click', this.clearFilters.bind(this));
            $('#remove-all-filters').on('click', this.clearFilters.bind(this));

            // Apply filters on Enter key in search box
            $('#training-search').on('keypress', (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    this.applyFilters();
                }
            });

            // Pagination
            $(document).on('click', '.training-pagination a', this.handlePagination.bind(this));
        },

        /**
         * Handle form submission
         */
        handleFormSubmit: function(e) {
            e.preventDefault();

            const $form = $(e.target);
            const $button = $form.find('#save-training-btn');
            const $spinner = $form.find('.spinner');
            const $message = $('#training-save-message');

            // Disable button and show spinner
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.hide();

            // Collect form data
            const formData = {
                action: 'mld_save_training_example',
                nonce: mldTrainingAdmin.nonce,
                example_type: $form.find('#example_type').val(),
                user_message: $form.find('#user_message').val(),
                ai_response: $form.find('#ai_response').val(),
                feedback_notes: $form.find('#feedback_notes').val(),
                conversation_context: $form.find('#conversation_context').val(),
                rating: $form.find('#rating').val(),
                tags: $form.find('#tags').val()
            };

            // Send AJAX request
            $.ajax({
                url: mldTrainingAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                success: (response) => {
                    if (response.success) {
                        // Show success message
                        $message
                            .removeClass('notice-error')
                            .addClass('notice notice-success')
                            .html('<p>' + response.data.message + '</p>')
                            .slideDown();

                        // Reset form
                        $form[0].reset();

                        // Reload training examples
                        this.loadTrainingExamples();

                        // Hide message after 5 seconds
                        setTimeout(() => $message.slideUp(), 5000);
                    } else {
                        this.showError($message, response.data.message);
                    }
                },
                error: (xhr, status, error) => {
                    this.showError($message, 'Error saving training example: ' + error);
                },
                complete: () => {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Load training examples (v6.9.0 - enhanced with search and filters)
         */
        loadTrainingExamples: function(page = 1, filters = {}) {
            const $list = $('#training-examples-list');
            $list.html('<p>Loading training examples...</p>');

            // Build request data
            const requestData = {
                action: 'mld_get_training_examples',
                nonce: mldTrainingAdmin.nonce,
                page: page
            };

            // Add filters to request
            if (filters.type) requestData.filter_type = filters.type;
            if (filters.rating) requestData.filter_rating = filters.rating;
            if (filters.tag) requestData.filter_tag = filters.tag;
            if (filters.search) requestData.search = filters.search;
            if (filters.date_from) requestData.date_from = filters.date_from;
            if (filters.date_to) requestData.date_to = filters.date_to;

            // Store current filters for pagination
            this.currentFilters = filters;

            $.ajax({
                url: mldTrainingAdmin.ajaxUrl,
                type: 'GET',
                data: requestData,
                success: (response) => {
                    if (response.success) {
                        this.renderTrainingExamples(response.data);
                        this.updateActiveFiltersDisplay(response.data.filters);
                    } else {
                        $list.html('<p class="error">Failed to load training examples</p>');
                    }
                },
                error: () => {
                    $list.html('<p class="error">Error loading training examples</p>');
                }
            });
        },

        /**
         * Render training examples
         */
        renderTrainingExamples: function(data) {
            const $list = $('#training-examples-list');

            if (!data.examples || data.examples.length === 0) {
                $list.html('<p>No training examples found. Add your first example above!</p>');
                return;
            }

            let html = '';

            // Add filter
            html += '<div class="training-filter-bar">';
            html += '<label for="training-filter">Filter by rating:</label>';
            html += '<select id="training-filter">';
            html += '<option value="">All</option>';
            html += '<option value="good">Good</option>';
            html += '<option value="needs_improvement">Needs Improvement</option>';
            html += '<option value="bad">Bad</option>';
            html += '</select>';
            html += '<span class="training-count">Total: ' + data.total + ' examples</span>';
            html += '</div>';

            // Add examples
            html += '<div class="training-examples-grid">';
            data.examples.forEach(example => {
                html += this.renderExampleCard(example);
            });
            html += '</div>';

            // Add pagination
            if (data.total_pages > 1) {
                html += this.renderPagination(data);
            }

            $list.html(html);
        },

        /**
         * Render a single example card
         */
        renderExampleCard: function(example) {
            const ratingClass = example.example_type;
            const ratingLabel = this.getRatingLabel(example.example_type);
            const ratingIcon = this.getRatingIcon(example.example_type);
            const date = new Date(example.created_at).toLocaleDateString();

            let html = '<div class="training-example-card training-' + ratingClass + '">';

            // Header
            html += '<div class="training-card-header">';
            html += '<span class="training-rating ' + ratingClass + '">' + ratingIcon + ' ' + ratingLabel + '</span>';
            html += '<span class="training-date">' + date + '</span>';
            html += '<button class="delete-training-example" data-id="' + example.id + '" title="Delete">&times;</button>';
            html += '</div>';

            // User message
            html += '<div class="training-message">';
            html += '<strong>User:</strong>';
            html += '<div class="message-content">' + this.escapeHtml(example.user_message) + '</div>';
            html += '</div>';

            // AI response
            html += '<div class="training-message">';
            html += '<strong>AI:</strong>';
            html += '<div class="message-content">' + this.escapeHtml(example.ai_response) + '</div>';
            html += '</div>';

            // Feedback notes
            if (example.feedback_notes) {
                html += '<div class="training-feedback">';
                html += '<strong>Feedback:</strong> ' + this.escapeHtml(example.feedback_notes);
                html += '</div>';
            }

            // Footer metadata
            html += '<div class="training-card-footer">';
            if (example.rating) {
                html += '<span class="training-numeric-rating">Rating: ' + example.rating + '/5</span>';
            }
            if (example.tags) {
                const tags = example.tags.split(',').map(tag =>
                    '<span class="training-tag">' + this.escapeHtml(tag.trim()) + '</span>'
                ).join('');
                html += '<span class="training-tags">' + tags + '</span>';
            }
            html += '</div>';

            html += '</div>';
            return html;
        },

        /**
         * Render pagination
         */
        renderPagination: function(data) {
            let html = '<div class="training-pagination">';

            // Previous button
            if (data.page > 1) {
                html += '<a href="#" data-page="' + (data.page - 1) + '">&laquo; Previous</a>';
            }

            // Page numbers
            for (let i = 1; i <= data.total_pages; i++) {
                if (i === data.page) {
                    html += '<span class="current-page">' + i + '</span>';
                } else {
                    html += '<a href="#" data-page="' + i + '">' + i + '</a>';
                }
            }

            // Next button
            if (data.page < data.total_pages) {
                html += '<a href="#" data-page="' + (data.page + 1) + '">Next &raquo;</a>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Handle pagination click
         */
        handlePagination: function(e) {
            e.preventDefault();
            const page = $(e.target).data('page');
            // Use stored filters for pagination
            this.loadTrainingExamples(page, this.currentFilters || {});
        },

        /**
         * Handle filter change (old method, kept for backward compatibility)
         */
        handleFilter: function(e) {
            const filterType = $(e.target).val();
            this.loadTrainingExamples(1, { type: filterType });
        },

        /**
         * Apply filters (v6.9.0)
         */
        applyFilters: function() {
            const filters = {
                type: $('#filter-type').val(),
                rating: $('#filter-rating').val(),
                tag: $('#filter-tag').val().trim(),
                search: $('#training-search').val().trim(),
                date_from: $('#filter-date-from').val(),
                date_to: $('#filter-date-to').val()
            };

            this.loadTrainingExamples(1, filters);
        },

        /**
         * Clear all filters (v6.9.0)
         */
        clearFilters: function() {
            // Clear form inputs
            $('#filter-type').val('');
            $('#filter-rating').val('');
            $('#filter-tag').val('');
            $('#training-search').val('');
            $('#filter-date-from').val('');
            $('#filter-date-to').val('');

            // Hide active filters display
            $('#active-filters').hide();

            // Reload examples without filters
            this.loadTrainingExamples(1, {});
        },

        /**
         * Update active filters display (v6.9.0)
         */
        updateActiveFiltersDisplay: function(filters) {
            const $activeFilters = $('#active-filters');
            const $filtersList = $('#active-filters-list');
            const activeItems = [];

            if (filters.search) {
                activeItems.push('Search: "' + this.escapeHtml(filters.search) + '"');
            }

            if (filters.type) {
                const label = this.getRatingLabel(filters.type);
                activeItems.push('Type: ' + label);
            }

            if (filters.rating) {
                activeItems.push('Rating: ' + filters.rating + ' stars');
            }

            if (filters.tag) {
                activeItems.push('Tag: "' + this.escapeHtml(filters.tag) + '"');
            }

            if (filters.date_from) {
                activeItems.push('From: ' + filters.date_from);
            }

            if (filters.date_to) {
                activeItems.push('To: ' + filters.date_to);
            }

            if (activeItems.length > 0) {
                $filtersList.html(activeItems.join(' | '));
                $activeFilters.show();
            } else {
                $activeFilters.hide();
            }
        },

        /**
         * Handle delete button click
         */
        handleDelete: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to delete this training example?')) {
                return;
            }

            const $button = $(e.currentTarget);
            const id = $button.data('id');

            $.ajax({
                url: mldTrainingAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_delete_training_example',
                    nonce: mldTrainingAdmin.nonce,
                    id: id
                },
                success: (response) => {
                    if (response.success) {
                        // Reload examples
                        this.loadTrainingExamples();
                    } else {
                        alert('Failed to delete training example: ' + response.data.message);
                    }
                },
                error: () => {
                    alert('Error deleting training example');
                }
            });
        },

        /**
         * Get rating label
         */
        getRatingLabel: function(type) {
            const labels = {
                'good': 'Good',
                'needs_improvement': 'Needs Improvement',
                'bad': 'Bad'
            };
            return labels[type] || type;
        },

        /**
         * Get rating icon
         */
        getRatingIcon: function(type) {
            const icons = {
                'good': '✓',
                'needs_improvement': '⚠',
                'bad': '✗'
            };
            return icons[type] || '';
        },

        /**
         * Show error message
         */
        showError: function($message, text) {
            $message
                .removeClass('notice-success')
                .addClass('notice notice-error')
                .html('<p>' + text + '</p>')
                .slideDown();

            setTimeout(() => $message.slideUp(), 5000);
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

})(jQuery);
