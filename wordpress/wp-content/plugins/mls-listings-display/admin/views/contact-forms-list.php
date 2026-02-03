<?php
/**
 * Contact Forms List Admin View
 *
 * Displays all contact forms with management options.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$manager = MLD_Contact_Form_Manager::get_instance();

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Get forms
$forms = $manager->get_forms([
    'status' => $status_filter,
    'search' => $search,
    'orderby' => 'updated_at',
    'order' => 'DESC'
]);

// Get counts for filter tabs
$all_count = $manager->get_form_count();
$active_count = $manager->get_form_count('active');
$draft_count = $manager->get_form_count('draft');
$archived_count = $manager->get_form_count('archived');

$base_url = admin_url('admin.php?page=mld_contact_forms');
?>

<div class="wrap mld-contact-forms-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Contact Forms', 'mls-listings-display'); ?></h1>
    <a href="<?php echo esc_url(add_query_arg('action', 'new', $base_url)); ?>" class="page-title-action">
        <?php esc_html_e('Add New Form', 'mls-listings-display'); ?>
    </a>
    <button type="button" class="page-title-action mld-template-library-btn" id="mld-open-template-library">
        <span class="dashicons dashicons-layout" style="font-size: 16px; width: 16px; height: 16px; margin-top: 3px; margin-right: 3px;"></span>
        <?php esc_html_e('From Template', 'mls-listings-display'); ?>
    </button>

    <hr class="wp-header-end">

    <!-- Status Filter Tabs -->
    <ul class="subsubsub">
        <li class="all">
            <a href="<?php echo esc_url($base_url); ?>" <?php echo empty($status_filter) ? 'class="current"' : ''; ?>>
                <?php esc_html_e('All', 'mls-listings-display'); ?>
                <span class="count">(<?php echo esc_html($all_count); ?>)</span>
            </a> |
        </li>
        <li class="active">
            <a href="<?php echo esc_url(add_query_arg('status', 'active', $base_url)); ?>" <?php echo $status_filter === 'active' ? 'class="current"' : ''; ?>>
                <?php esc_html_e('Active', 'mls-listings-display'); ?>
                <span class="count">(<?php echo esc_html($active_count); ?>)</span>
            </a> |
        </li>
        <li class="draft">
            <a href="<?php echo esc_url(add_query_arg('status', 'draft', $base_url)); ?>" <?php echo $status_filter === 'draft' ? 'class="current"' : ''; ?>>
                <?php esc_html_e('Draft', 'mls-listings-display'); ?>
                <span class="count">(<?php echo esc_html($draft_count); ?>)</span>
            </a> |
        </li>
        <li class="archived">
            <a href="<?php echo esc_url(add_query_arg('status', 'archived', $base_url)); ?>" <?php echo $status_filter === 'archived' ? 'class="current"' : ''; ?>>
                <?php esc_html_e('Archived', 'mls-listings-display'); ?>
                <span class="count">(<?php echo esc_html($archived_count); ?>)</span>
            </a>
        </li>
    </ul>

    <!-- Search Form -->
    <form method="get" class="search-form" style="float: right; margin-top: -5px;">
        <input type="hidden" name="page" value="mld_contact_forms">
        <?php if ($status_filter): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
        <?php endif; ?>
        <p class="search-box">
            <label class="screen-reader-text" for="form-search-input"><?php esc_html_e('Search Forms', 'mls-listings-display'); ?></label>
            <input type="search" id="form-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search forms...', 'mls-listings-display'); ?>">
            <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search', 'mls-listings-display'); ?>">
        </p>
    </form>

    <div class="clear"></div>

    <?php if (empty($forms)): ?>
        <div class="mld-no-forms">
            <div class="mld-no-forms-icon">
                <span class="dashicons dashicons-feedback"></span>
            </div>
            <h2><?php esc_html_e('No Contact Forms Yet', 'mls-listings-display'); ?></h2>
            <p><?php esc_html_e('Create your first contact form to start collecting leads from your website.', 'mls-listings-display'); ?></p>
            <a href="<?php echo esc_url(add_query_arg('action', 'new', $base_url)); ?>" class="button button-primary button-hero">
                <span class="dashicons dashicons-plus-alt2" style="margin-top: 4px;"></span>
                <?php esc_html_e('Create Your First Form', 'mls-listings-display'); ?>
            </a>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped mld-contact-forms-table">
            <thead>
                <tr>
                    <th scope="col" class="column-name" style="width: 25%;"><?php esc_html_e('Form Name', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-shortcode" style="width: 25%;"><?php esc_html_e('Shortcode', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-submissions" style="width: 12%;"><?php esc_html_e('Submissions', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-status" style="width: 10%;"><?php esc_html_e('Status', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-date" style="width: 18%;"><?php esc_html_e('Last Modified', 'mls-listings-display'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): ?>
                    <?php
                    $edit_url = add_query_arg(['action' => 'edit', 'form_id' => $form->id], $base_url);
                    $delete_url = wp_nonce_url(
                        add_query_arg(['action' => 'delete', 'form_id' => $form->id], $base_url),
                        'delete_contact_form_' . $form->id
                    );
                    $duplicate_url = wp_nonce_url(
                        add_query_arg(['action' => 'duplicate', 'form_id' => $form->id], $base_url),
                        'duplicate_contact_form_' . $form->id
                    );
                    $shortcode = $manager->generate_shortcode($form->id);

                    // Status badge class
                    $status_class = 'mld-status-' . $form->status;
                    $status_label = ucfirst($form->status);
                    ?>
                    <tr>
                        <td class="column-name">
                            <strong>
                                <a href="<?php echo esc_url($edit_url); ?>" class="row-title">
                                    <?php echo esc_html($form->form_name); ?>
                                </a>
                            </strong>
                            <?php if (!empty($form->description)): ?>
                                <p class="description" style="margin: 2px 0 0;"><?php echo esc_html(wp_trim_words($form->description, 10)); ?></p>
                            <?php endif; ?>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'mls-listings-display'); ?></a> |
                                </span>
                                <span class="duplicate">
                                    <a href="<?php echo esc_url($duplicate_url); ?>"><?php esc_html_e('Duplicate', 'mls-listings-display'); ?></a> |
                                </span>
                                <span class="preview">
                                    <a href="#" class="mld-preview-form" data-form-id="<?php echo esc_attr($form->id); ?>"><?php esc_html_e('Preview', 'mls-listings-display'); ?></a> |
                                </span>
                                <span class="trash">
                                    <a href="<?php echo esc_url($delete_url); ?>" class="submitdelete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this form? This cannot be undone.', 'mls-listings-display'); ?>');">
                                        <?php esc_html_e('Delete', 'mls-listings-display'); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td class="column-shortcode">
                            <div class="mld-shortcode-container">
                                <code class="mld-shortcode-text"><?php echo esc_html($shortcode); ?></code>
                                <button type="button" class="button button-small mld-copy-shortcode" data-shortcode="<?php echo esc_attr($shortcode); ?>" title="<?php esc_attr_e('Copy to clipboard', 'mls-listings-display'); ?>">
                                    <span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span>
                                </button>
                            </div>
                        </td>
                        <td class="column-submissions">
                            <span class="mld-submission-count"><?php echo number_format_i18n($form->submission_count); ?></span>
                        </td>
                        <td class="column-status">
                            <span class="mld-status-badge <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td class="column-date">
                            <?php
                            $modified_time = strtotime($form->updated_at);
                            $time_diff = human_time_diff($modified_time, current_time('timestamp'));
                            ?>
                            <span title="<?php echo esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $modified_time)); ?>">
                                <?php
                                /* translators: %s: Human-readable time difference */
                                printf(esc_html__('%s ago', 'mls-listings-display'), $time_diff);
                                ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" class="column-name"><?php esc_html_e('Form Name', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-shortcode"><?php esc_html_e('Shortcode', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-submissions"><?php esc_html_e('Submissions', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-status"><?php esc_html_e('Status', 'mls-listings-display'); ?></th>
                    <th scope="col" class="column-date"><?php esc_html_e('Last Modified', 'mls-listings-display'); ?></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <!-- Quick Tips -->
    <div class="mld-contact-forms-tips" style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h3 style="margin-top: 0;">
            <span class="dashicons dashicons-lightbulb" style="color: #f0b849;"></span>
            <?php esc_html_e('Quick Tips', 'mls-listings-display'); ?>
        </h3>
        <ul style="margin-left: 20px;">
            <li><?php esc_html_e('Copy the shortcode and paste it into any page or post to display the form.', 'mls-listings-display'); ?></li>
            <li><?php esc_html_e('You can also add the shortcode to widgets or your theme templates.', 'mls-listings-display'); ?></li>
            <li><?php esc_html_e('Customize the form appearance via Appearance > Customize > MLD Contact Forms.', 'mls-listings-display'); ?></li>
            <li><?php esc_html_e('Form submissions can be viewed in Form Submissions page.', 'mls-listings-display'); ?></li>
        </ul>
    </div>
</div>

<style>
.mld-contact-forms-wrap .mld-no-forms {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-top: 20px;
}

.mld-contact-forms-wrap .mld-no-forms-icon {
    font-size: 64px;
    color: #dcdcde;
    margin-bottom: 20px;
}

.mld-contact-forms-wrap .mld-no-forms-icon .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
}

.mld-contact-forms-wrap .mld-no-forms h2 {
    margin: 0 0 10px;
    font-size: 23px;
    font-weight: 400;
}

.mld-contact-forms-wrap .mld-no-forms p {
    color: #646970;
    margin-bottom: 20px;
}

.mld-contact-forms-table .column-shortcode {
    font-size: 12px;
}

.mld-shortcode-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.mld-shortcode-text {
    padding: 4px 8px;
    background: #f0f0f1;
    border-radius: 3px;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}

.mld-copy-shortcode {
    padding: 2px 6px !important;
    min-height: 24px !important;
}

.mld-copy-shortcode.copied {
    background: #00a32a !important;
    border-color: #00a32a !important;
    color: #fff !important;
}

.mld-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.mld-status-active {
    background: #d1fae5;
    color: #065f46;
}

.mld-status-draft {
    background: #fef3c7;
    color: #92400e;
}

.mld-status-archived {
    background: #e5e7eb;
    color: #374151;
}

.mld-submission-count {
    font-weight: 600;
    color: #1e40af;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Copy shortcode to clipboard
    $('.mld-copy-shortcode').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var shortcode = $btn.data('shortcode');

        // Use modern clipboard API if available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showCopiedFeedback($btn);
            });
        } else {
            // Fallback for older browsers
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();
            showCopiedFeedback($btn);
        }
    });

    function showCopiedFeedback($btn) {
        $btn.addClass('copied');
        $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');

        setTimeout(function() {
            $btn.removeClass('copied');
            $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
        }, 2000);
    }

    // Preview form from list
    $('.mld-preview-form').on('click', function(e) {
        e.preventDefault();
        var formId = $(this).data('form-id');

        // Fetch form data via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_get_contact_form',
                form_id: formId,
                nonce: '<?php echo wp_create_nonce('mld_contact_form_admin'); ?>'
            },
            success: function(response) {
                if (response.success && response.data.form) {
                    showFormPreview(response.data.form);
                } else {
                    alert('Could not load form preview.');
                }
            },
            error: function() {
                alert('Error loading form preview.');
            }
        });
    });

    function showFormPreview(form) {
        var fields = form.fields && form.fields.fields ? form.fields.fields : [];

        if (fields.length === 0) {
            alert('This form has no fields to preview.');
            return;
        }

        var fieldsHtml = '';
        fields.forEach(function(field) {
            var widthClass = field.width === 'half' ? 'mld-cf-field-half' : 'mld-cf-field-full';
            var requiredMark = field.required ? '<span class="mld-cf-required">*</span>' : '';

            var inputHtml = '';
            switch (field.type) {
                case 'text':
                    inputHtml = '<input type="text" class="mld-cf-input" placeholder="' + escapeHtml(field.placeholder || '') + '" disabled>';
                    break;
                case 'email':
                    inputHtml = '<input type="email" class="mld-cf-input" placeholder="' + escapeHtml(field.placeholder || '') + '" disabled>';
                    break;
                case 'phone':
                    inputHtml = '<input type="tel" class="mld-cf-input" placeholder="' + escapeHtml(field.placeholder || '') + '" disabled>';
                    break;
                case 'textarea':
                    inputHtml = '<textarea class="mld-cf-textarea" placeholder="' + escapeHtml(field.placeholder || '') + '" rows="4" disabled></textarea>';
                    break;
                case 'dropdown':
                    var options = '<option value="">Select...</option>';
                    if (field.options && field.options.length) {
                        field.options.forEach(function(opt) {
                            options += '<option>' + escapeHtml(opt) + '</option>';
                        });
                    }
                    inputHtml = '<select class="mld-cf-select" disabled>' + options + '</select>';
                    break;
                case 'checkbox':
                    if (field.options && field.options.length) {
                        inputHtml = '<div class="mld-cf-checkbox-group">';
                        field.options.forEach(function(opt) {
                            inputHtml += '<label class="mld-cf-checkbox-label"><input type="checkbox" disabled> ' + escapeHtml(opt) + '</label>';
                        });
                        inputHtml += '</div>';
                    }
                    break;
                case 'radio':
                    if (field.options && field.options.length) {
                        inputHtml = '<div class="mld-cf-radio-group">';
                        field.options.forEach(function(opt) {
                            inputHtml += '<label class="mld-cf-radio-label"><input type="radio" disabled> ' + escapeHtml(opt) + '</label>';
                        });
                        inputHtml += '</div>';
                    }
                    break;
                case 'date':
                    inputHtml = '<input type="date" class="mld-cf-input" disabled>';
                    break;
            }

            fieldsHtml += '<div class="mld-cf-field ' + widthClass + '">' +
                '<label class="mld-cf-label">' + escapeHtml(field.label) + requiredMark + '</label>' +
                inputHtml +
                '</div>';
        });

        var previewHtml = '<div id="mld-form-preview-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center;">' +
            '<div style="background: #fff; border-radius: 12px; max-width: 700px; width: 90%; max-height: 90vh; overflow: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.3);">' +
                '<div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; z-index: 1;">' +
                    '<h2 style="margin: 0; font-size: 18px; color: #1f2937;">Preview: ' + escapeHtml(form.form_name) + '</h2>' +
                    '<button id="mld-close-preview" style="background: none; border: none; cursor: pointer; font-size: 24px; color: #6b7280; line-height: 1;">&times;</button>' +
                '</div>' +
                '<div style="padding: 32px;">' +
                    '<div class="mld-contact-form" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">' +
                        '<div class="mld-cf-fields" style="display: flex; flex-wrap: wrap; gap: 16px;">' +
                            fieldsHtml +
                        '</div>' +
                        '<div style="margin-top: 24px;">' +
                            '<button type="button" class="mld-cf-submit" style="background: #0891B2; color: #fff; border: none; padding: 12px 32px; border-radius: 6px; font-size: 16px; font-weight: 500; cursor: pointer;">Send Message</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<style>' +
            '#mld-form-preview-overlay .mld-cf-field { box-sizing: border-box; }' +
            '#mld-form-preview-overlay .mld-cf-field-full { width: 100%; }' +
            '#mld-form-preview-overlay .mld-cf-field-half { width: calc(50% - 8px); }' +
            '#mld-form-preview-overlay .mld-cf-label { display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 14px; }' +
            '#mld-form-preview-overlay .mld-cf-required { color: #ef4444; margin-left: 2px; }' +
            '#mld-form-preview-overlay .mld-cf-input, #mld-form-preview-overlay .mld-cf-select, #mld-form-preview-overlay .mld-cf-textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff; box-sizing: border-box; }' +
            '#mld-form-preview-overlay .mld-cf-checkbox-group, #mld-form-preview-overlay .mld-cf-radio-group { display: flex; flex-direction: column; gap: 8px; }' +
            '#mld-form-preview-overlay .mld-cf-checkbox-label, #mld-form-preview-overlay .mld-cf-radio-label { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #374151; cursor: pointer; }' +
            '@media (max-width: 600px) { #mld-form-preview-overlay .mld-cf-field-half { width: 100%; } }' +
        '</style>';

        // Remove any existing preview
        $('#mld-form-preview-overlay').remove();

        // Add preview to body
        $('body').append(previewHtml);

        // Close button handler
        $('#mld-close-preview, #mld-form-preview-overlay').on('click', function(e) {
            if (e.target === this) {
                $('#mld-form-preview-overlay').remove();
            }
        });

        // ESC key to close
        $(document).on('keydown.previewModal', function(e) {
            if (e.key === 'Escape') {
                $('#mld-form-preview-overlay').remove();
                $(document).off('keydown.previewModal');
            }
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Template Library Modal (v6.24.0)
    var templateLibraryHtml = `
        <div id="mld-template-library-modal" class="mld-modal" style="display: none;">
            <div class="mld-modal-backdrop"></div>
            <div class="mld-modal-content mld-template-library-content">
                <div class="mld-modal-header">
                    <h2><?php esc_html_e('Template Library', 'mls-listings-display'); ?></h2>
                    <button type="button" class="mld-modal-close">&times;</button>
                </div>
                <div class="mld-modal-body">
                    <div class="mld-template-filters">
                        <button type="button" class="mld-template-filter active" data-category=""><?php esc_html_e('All Templates', 'mls-listings-display'); ?></button>
                        <button type="button" class="mld-template-filter" data-category="real-estate"><?php esc_html_e('Real Estate', 'mls-listings-display'); ?></button>
                        <button type="button" class="mld-template-filter" data-category="general"><?php esc_html_e('General', 'mls-listings-display'); ?></button>
                    </div>
                    <div class="mld-template-grid" id="mld-template-grid">
                        <div class="mld-template-loading">
                            <span class="spinner is-active"></span>
                            <p><?php esc_html_e('Loading templates...', 'mls-listings-display'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('body').append(templateLibraryHtml);

    // Open template library
    $('#mld-open-template-library').on('click', function() {
        $('#mld-template-library-modal').show();
        loadTemplates('');
    });

    // Close template library
    $('#mld-template-library-modal .mld-modal-close, #mld-template-library-modal .mld-modal-backdrop').on('click', function() {
        $('#mld-template-library-modal').hide();
    });

    // Filter templates
    $(document).on('click', '.mld-template-filter', function() {
        $('.mld-template-filter').removeClass('active');
        $(this).addClass('active');
        loadTemplates($(this).data('category'));
    });

    // Load templates
    function loadTemplates(category) {
        var $grid = $('#mld-template-grid');
        $grid.html('<div class="mld-template-loading"><span class="spinner is-active"></span><p><?php esc_html_e('Loading templates...', 'mls-listings-display'); ?></p></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_get_templates',
                category: category,
                nonce: '<?php echo wp_create_nonce('mld_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success && response.data.templates) {
                    renderTemplates(response.data.templates);
                } else {
                    $grid.html('<p class="mld-template-error"><?php esc_html_e('Could not load templates.', 'mls-listings-display'); ?></p>');
                }
            },
            error: function() {
                $grid.html('<p class="mld-template-error"><?php esc_html_e('Error loading templates.', 'mls-listings-display'); ?></p>');
            }
        });
    }

    // Render templates
    function renderTemplates(templates) {
        var $grid = $('#mld-template-grid');

        if (templates.length === 0) {
            $grid.html('<p class="mld-no-templates"><?php esc_html_e('No templates found.', 'mls-listings-display'); ?></p>');
            return;
        }

        var html = '';
        templates.forEach(function(template) {
            var badges = '';
            if (template.is_multistep) {
                badges += '<span class="mld-template-badge mld-badge-multistep">' + template.step_count + ' <?php esc_html_e('Steps', 'mls-listings-display'); ?></span>';
            }
            if (template.is_system) {
                badges += '<span class="mld-template-badge mld-badge-system"><?php esc_html_e('Built-in', 'mls-listings-display'); ?></span>';
            }

            html += '<div class="mld-template-card" data-template-id="' + template.id + '">' +
                '<div class="mld-template-icon">' +
                    '<span class="dashicons dashicons-feedback"></span>' +
                '</div>' +
                '<div class="mld-template-info">' +
                    '<h3 class="mld-template-name">' + escapeHtml(template.name) + '</h3>' +
                    '<p class="mld-template-description">' + escapeHtml(template.description) + '</p>' +
                    '<div class="mld-template-meta">' +
                        '<span class="mld-template-fields">' + template.field_count + ' <?php esc_html_e('fields', 'mls-listings-display'); ?></span>' +
                        '<span class="mld-template-category">' + escapeHtml(template.category_label) + '</span>' +
                    '</div>' +
                    '<div class="mld-template-badges">' + badges + '</div>' +
                '</div>' +
                '<button type="button" class="button button-primary mld-use-template" data-template-id="' + template.id + '" data-template-name="' + escapeHtml(template.name) + '">' +
                    '<?php esc_html_e('Use Template', 'mls-listings-display'); ?>' +
                '</button>' +
            '</div>';
        });

        $grid.html(html);
    }

    // Use template
    $(document).on('click', '.mld-use-template', function(e) {
        e.stopPropagation();
        var templateId = $(this).data('template-id');
        var templateName = $(this).data('template-name');
        var $btn = $(this);

        $btn.prop('disabled', true).text('<?php esc_html_e('Creating...', 'mls-listings-display'); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_apply_template',
                template_id: templateId,
                form_name: templateName,
                nonce: '<?php echo wp_create_nonce('mld_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                } else {
                    alert(response.data?.message || '<?php esc_html_e('Could not create form from template.', 'mls-listings-display'); ?>');
                    $btn.prop('disabled', false).text('<?php esc_html_e('Use Template', 'mls-listings-display'); ?>');
                }
            },
            error: function() {
                alert('<?php esc_html_e('Error creating form.', 'mls-listings-display'); ?>');
                $btn.prop('disabled', false).text('<?php esc_html_e('Use Template', 'mls-listings-display'); ?>');
            }
        });
    });

    // ESC to close template library
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#mld-template-library-modal').is(':visible')) {
            $('#mld-template-library-modal').hide();
        }
    });
});
</script>
