/**
 * Blog Agent Admin JavaScript
 *
 * Handles the admin interface interactions for the Blog Agent.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

(function($) {
    'use strict';

    // State management
    const state = {
        currentStep: 1,
        selectedTopic: null,
        generatedArticle: null,
        savedPostId: null
    };

    // Initialize
    $(document).ready(function() {
        initEventListeners();
        initCharCounters();
    });

    /**
     * Initialize event listeners
     */
    function initEventListeners() {
        // Step 1: Topic Selection
        $('#mld-research-topics').on('click', researchTopics);
        $('#mld-custom-topic').on('click', showCustomTopicForm);
        $('#mld-use-custom-topic').on('click', useCustomTopic);
        $(document).on('click', '.mld-topic-card', selectTopic);

        // Step 2: Configuration
        $('#mld-change-topic').on('click', changeTopic);
        $('#mld-generate-article').on('click', generateArticle);

        // Step 3: Preview & Publish
        $('#mld-regenerate-article').on('click', regenerateArticle);
        $('#mld-view-seo-issues').on('click', showSeoIssues);
        $('#mld-save-draft').on('click', saveDraft);
        $('#mld-publish-now').on('click', publishNow);

        // Modal
        $('.mld-modal-close').on('click', closeModal);
        $('.mld-modal').on('click', function(e) {
            if ($(e.target).hasClass('mld-modal')) {
                closeModal();
            }
        });

        // Topics list
        $(document).on('click', '.mld-use-topic', function() {
            const topicId = $(this).data('topic-id');
            loadAndSelectTopic(topicId);
        });

        $('#mld-refresh-topics').on('click', researchTopics);
    }

    /**
     * Initialize character counters
     */
    function initCharCounters() {
        $('#mld-preview-title').on('input', function() {
            const len = $(this).val().length;
            $('#mld-title-chars').text(len);
            $(this).closest('.mld-title-preview').find('.mld-char-count')
                .toggleClass('warning', len > 60);
        });

        $('#mld-preview-meta').on('input', function() {
            const len = $(this).val().length;
            $('#mld-meta-chars').text(len);
            $(this).closest('.mld-meta-preview').find('.mld-char-count')
                .toggleClass('warning', len > 155);
        });
    }

    /**
     * Research trending topics
     */
    function researchTopics() {
        const $container = $('#mld-topics-container');
        const $grid = $container.find('.mld-topics-grid');
        const $loading = $container.find('.mld-topics-loading');

        $container.show();
        $loading.show();
        $grid.empty();

        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_blog_research_topics',
                nonce: mldBlogAgent.nonce
            },
            success: function(response) {
                console.log('Blog Agent AJAX response:', response);
                console.log('Response type:', typeof response);
                console.log('Response.success:', response.success);
                console.log('Response.data:', response.data);
                $loading.hide();

                if (response.success && response.data && response.data.topics) {
                    console.log('Topics found:', response.data.topics.length);
                    renderTopicCards(response.data.topics, $grid);
                } else {
                    console.log('Error condition - response.success:', response.success, 'response.data:', response.data);
                    showError(response.data?.message || 'Failed to research topics.');
                }
            },
            error: function(xhr, status, error) {
                console.log('Blog Agent AJAX error:', status, error);
                console.log('XHR response:', xhr.responseText);
                $loading.hide();
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Render topic cards
     */
    function renderTopicCards(topics, $container) {
        topics.forEach(function(topic, index) {
            const scoreClass = topic.total_score >= 80 ? 'good' :
                              (topic.total_score >= 60 ? 'ok' : 'poor');

            const $card = $(`
                <div class="mld-topic-card" data-topic-index="${index}">
                    <h4>${escapeHtml(topic.title)}</h4>
                    <p>${escapeHtml(topic.description || '').substring(0, 150)}...</p>
                    <div class="mld-topic-card-meta">
                        <div class="mld-topic-score">
                            <span class="mld-score-badge mld-score-${scoreClass}">${Math.round(topic.total_score)}</span>
                        </div>
                        <span class="mld-topic-source">${escapeHtml(topic.source || 'AI')}</span>
                    </div>
                </div>
            `);

            $card.data('topic', topic);
            $container.append($card);
        });
    }

    /**
     * Show custom topic form
     */
    function showCustomTopicForm() {
        $('#mld-topics-container').hide();
        $('#mld-custom-topic-form').show();
    }

    /**
     * Use custom topic
     */
    function useCustomTopic() {
        const title = $('#mld-topic-title').val().trim();
        const description = $('#mld-topic-description').val().trim();
        const keywords = $('#mld-topic-keywords').val().trim();
        const cities = $('#mld-topic-cities').val().trim();

        if (!title) {
            showError('Please enter a topic title.');
            return;
        }

        // Create custom topic
        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_blog_create_topic',
                nonce: mldBlogAgent.nonce,
                title: title,
                description: description,
                keywords: keywords,
                cities: cities
            },
            success: function(response) {
                if (response.success && response.data.topic) {
                    state.selectedTopic = response.data.topic;
                    goToStep(2);
                } else {
                    showError(response.data?.message || 'Failed to create topic.');
                }
            },
            error: function() {
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Select a topic from the grid
     */
    function selectTopic() {
        const $card = $(this);
        $('.mld-topic-card').removeClass('selected');
        $card.addClass('selected');

        state.selectedTopic = $card.data('topic');
        goToStep(2);
    }

    /**
     * Load and select a topic by ID
     */
    function loadAndSelectTopic(topicId) {
        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_blog_get_topic',
                nonce: mldBlogAgent.nonce,
                topic_id: topicId
            },
            success: function(response) {
                if (response.success && response.data.topic) {
                    state.selectedTopic = response.data.topic;
                    goToStep(2);
                    // Switch to create tab
                    window.location.href = window.location.pathname + '?page=mld-blog-agent&tab=create';
                } else {
                    showError(response.data?.message || 'Failed to load topic.');
                }
            },
            error: function() {
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Change topic (go back to step 1)
     */
    function changeTopic() {
        state.selectedTopic = null;
        goToStep(1);
    }

    /**
     * Generate article from topic
     */
    function generateArticle() {
        console.log('generateArticle called');
        console.log('selectedTopic:', state.selectedTopic);

        if (!state.selectedTopic) {
            showError('Please select a topic first.');
            return;
        }

        const $progress = $('#mld-generation-progress');
        const $fill = $progress.find('.mld-progress-fill');
        const $text = $progress.find('.mld-progress-text');

        $progress.show();
        $fill.css('width', '0%');
        $('#mld-generate-article').prop('disabled', true);

        // Simulate progress
        let progress = 0;
        const progressSteps = [
            { percent: 20, text: 'Generating article structure...' },
            { percent: 40, text: 'Gathering local market data...' },
            { percent: 60, text: 'Writing article sections...' },
            { percent: 80, text: 'Optimizing for SEO...' },
            { percent: 90, text: 'Finding images...' }
        ];

        const progressInterval = setInterval(function() {
            if (progress < progressSteps.length) {
                const step = progressSteps[progress];
                $fill.css('width', step.percent + '%');
                $text.text(step.text);
                progress++;
            }
        }, 3000);

        const requestData = {
            action: 'mld_blog_generate_article',
            nonce: mldBlogAgent.nonce,
            topic_id: state.selectedTopic.id || 0,
            topic: state.selectedTopic,
            target_length: $('#mld-target-length').val(),
            cta_type: $('#mld-cta-type').val(),
            include_market_data: $('#mld-include-market-data').is(':checked'),
            include_school_data: $('#mld-include-school-data').is(':checked')
        };

        console.log('AJAX request data:', requestData);

        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('generateArticle AJAX response:', response);
                clearInterval(progressInterval);
                $fill.css('width', '100%');
                $text.text('Complete!');

                setTimeout(function() {
                    $progress.hide();
                    $('#mld-generate-article').prop('disabled', false);

                    if (response.success && response.data && response.data.article) {
                        state.generatedArticle = response.data.article;
                        goToStep(3);
                        renderArticlePreview(state.generatedArticle);
                    } else {
                        console.log('Error condition - response:', response);
                        showError(response.data?.message || 'Failed to generate article.');
                    }
                }, 500);
            },
            error: function(xhr, status, error) {
                console.log('generateArticle AJAX error:', status, error);
                console.log('XHR response:', xhr.responseText);
                clearInterval(progressInterval);
                $progress.hide();
                $('#mld-generate-article').prop('disabled', false);
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Render article preview
     */
    function renderArticlePreview(article) {
        // Update scores
        $('#mld-seo-score').text(Math.round(article.seo_score || 0));
        $('#mld-geo-score').text(Math.round(article.geo_score || 0));
        $('#mld-word-count').text(article.word_count || 0);

        // Update title
        $('#mld-preview-title').val(article.title || '');
        $('#mld-title-chars').text((article.title || '').length);

        // Update meta description
        $('#mld-preview-meta').val(article.meta_description || '');
        $('#mld-meta-chars').text((article.meta_description || '').length);

        // Update content preview
        $('#mld-preview-content-area').html(article.content || '');
    }

    /**
     * Regenerate article
     */
    function regenerateArticle() {
        if (confirm('This will regenerate the entire article. Are you sure?')) {
            generateArticle();
        }
    }

    /**
     * Show SEO issues modal
     */
    function showSeoIssues() {
        const $list = $('#mld-seo-issues-list');
        $list.empty();

        if (!state.generatedArticle?.seo_analysis?.recommendations) {
            $list.html('<p>No SEO issues found.</p>');
        } else {
            const recommendations = state.generatedArticle.seo_analysis.recommendations;

            recommendations.forEach(function(issue) {
                const $issue = $(`
                    <div class="mld-seo-issue">
                        <div class="mld-seo-issue-severity ${issue.severity}"></div>
                        <div class="mld-seo-issue-content">
                            <strong>${escapeHtml(issue.category)}</strong>
                            <p>${escapeHtml(issue.issue)}</p>
                            <p><em>${escapeHtml(issue.recommendation)}</em></p>
                        </div>
                    </div>
                `);
                $list.append($issue);
            });
        }

        $('#mld-seo-modal').show();
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('.mld-modal').hide();
    }

    /**
     * Save as draft
     */
    function saveDraft() {
        if (!state.generatedArticle) {
            showError('No article to save.');
            return;
        }

        // Update article with any edits
        state.generatedArticle.title = $('#mld-preview-title').val();
        state.generatedArticle.meta_description = $('#mld-preview-meta').val();

        const $btn = $('#mld-save-draft');
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_blog_save_draft',
                nonce: mldBlogAgent.nonce,
                article: state.generatedArticle,
                category_id: $('#mld-post-category').val()
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Save as Draft');

                if (response.success) {
                    state.savedPostId = response.data.post_id;
                    showSuccess('Article saved as draft. <a href="' + response.data.edit_url + '" target="_blank">Edit in WordPress</a>');
                } else {
                    showError(response.data?.message || 'Failed to save draft.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Save as Draft');
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Publish now
     */
    function publishNow() {
        if (!confirm(mldBlogAgent.strings.confirm_publish)) {
            return;
        }

        // Update article with any edits
        if (state.generatedArticle) {
            state.generatedArticle.title = $('#mld-preview-title').val();
            state.generatedArticle.meta_description = $('#mld-preview-meta').val();
        }

        const $btn = $('#mld-publish-now');
        $btn.prop('disabled', true).text('Publishing...');

        const data = {
            action: 'mld_blog_publish',
            nonce: mldBlogAgent.nonce
        };

        if (state.savedPostId) {
            data.post_id = state.savedPostId;
        } else {
            data.article = state.generatedArticle;
            data.category_id = $('#mld-post-category').val();
        }

        $.ajax({
            url: mldBlogAgent.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                $btn.prop('disabled', false).text('Publish Now');

                if (response.success) {
                    showSuccess('Article published! <a href="' + response.data.url + '" target="_blank">View Article</a>');
                } else {
                    showError(response.data?.message || 'Failed to publish.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Publish Now');
                showError('Network error. Please try again.');
            }
        });
    }

    /**
     * Navigate to a step
     */
    function goToStep(step) {
        state.currentStep = step;

        $('.mld-step').hide();
        $(`.mld-step[data-step="${step}"]`).show();

        if (step === 2 && state.selectedTopic) {
            $('#mld-selected-topic-display').html(`
                <strong>${escapeHtml(state.selectedTopic.title)}</strong>
                <p style="margin: 5px 0 0; color: #666; font-size: 14px;">
                    ${escapeHtml(state.selectedTopic.description || '').substring(0, 200)}
                </p>
            `);
        }
    }

    /**
     * Show error message
     */
    function showError(message) {
        // Use WordPress admin notices
        const $notice = $(`
            <div class="notice notice-error is-dismissible">
                <p>${message}</p>
            </div>
        `);

        $('.mld-blog-agent-wrap h1').after($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        const $notice = $(`
            <div class="notice notice-success is-dismissible">
                <p>${message}</p>
            </div>
        `);

        $('.mld-blog-agent-wrap h1').after($notice);

        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 8000);
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

})(jQuery);
