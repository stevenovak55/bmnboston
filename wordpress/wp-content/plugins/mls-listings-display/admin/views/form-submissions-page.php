<?php
/**
 * Admin view for form submissions management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Extract data from results
$submissions = $results['items'];
$total_items = $results['total'];
$total_pages = $results['pages'];
$current_page = $results['page'];
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Form Submissions</h1>
    
    <!-- Statistics Cards -->
    <div style="display: flex; gap: 15px; margin: 20px 0;">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1;">
            <h3 style="margin: 0 0 10px 0; color: #0073aa;"><?php echo number_format($stats['total']); ?></h3>
            <p style="margin: 0; color: #666;">Total Submissions</p>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1;">
            <h3 style="margin: 0 0 10px 0; color: #00a32a;"><?php echo number_format($stats['new']); ?></h3>
            <p style="margin: 0; color: #666;">New Submissions</p>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1;">
            <h3 style="margin: 0 0 10px 0; color: #d63638;"><?php echo number_format($stats['today']); ?></h3>
            <p style="margin: 0; color: #666;">Today</p>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1;">
            <h3 style="margin: 0 0 10px 0; color: #2271b1;"><?php echo number_format($stats['this_week']); ?></h3>
            <p style="margin: 0; color: #666;">This Week</p>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="mld_form_submissions">
            
            <div class="alignleft actions">
                <select name="form_type">
                    <option value="">All Form Types</option>
                    <option value="contact" <?php selected($form_type, 'contact'); ?>>Contact Form</option>
                    <option value="tour" <?php selected($form_type, 'tour'); ?>>Tour Request</option>
                </select>
                
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="new" <?php selected($status, 'new'); ?>>New</option>
                    <option value="read" <?php selected($status, 'read'); ?>>Read</option>
                    <option value="contacted" <?php selected($status, 'contacted'); ?>>Contacted</option>
                    <option value="converted" <?php selected($status, 'converted'); ?>>Converted</option>
                </select>
                
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="From Date">
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="To Date">
                
                <input type="submit" class="button" value="Filter">
                
                <?php if ($form_type || $status || $date_from || $date_to || $search): ?>
                    <a href="?page=mld_form_submissions" class="button">Clear Filters</a>
                <?php endif; ?>
            </div>
            
            <div class="alignright">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search submissions...">
                <input type="submit" class="button" value="Search">
            </div>
        </form>
    </div>
    
    <form method="post" action="">
        <?php wp_nonce_field('bulk_delete_submissions'); ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all">
                    </td>
                    <th scope="col" class="manage-column">Date</th>
                    <th scope="col" class="manage-column">Type</th>
                    <th scope="col" class="manage-column">Name</th>
                    <th scope="col" class="manage-column">Email</th>
                    <th scope="col" class="manage-column">Phone</th>
                    <th scope="col" class="manage-column">Property</th>
                    <th scope="col" class="manage-column">Status</th>
                    <th scope="col" class="manage-column">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 20px;">
                            No submissions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="submission_ids[]" value="<?php echo $submission['id']; ?>">
                            </th>
                            <td>
                                <?php echo date('M j, Y g:i A', strtotime($submission['created_at'])); ?>
                            </td>
                            <td>
                                <?php 
                                $type_label = $submission['form_type'] === 'tour' ? 'Tour Request' : 'Contact';
                                $type_color = $submission['form_type'] === 'tour' ? '#2271b1' : '#00a32a';
                                ?>
                                <span style="color: <?php echo $type_color; ?>; font-weight: 500;">
                                    <?php echo esc_html($type_label); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo esc_html($submission['first_name'] . ' ' . $submission['last_name']); ?></strong>
                            </td>
                            <td>
                                <a href="mailto:<?php echo esc_attr($submission['email']); ?>">
                                    <?php echo esc_html($submission['email']); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($submission['phone']): ?>
                                    <a href="tel:<?php echo esc_attr($submission['phone']); ?>">
                                        <?php echo esc_html($submission['phone']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($submission['property_mls']): ?>
                                    <a href="/property/<?php echo esc_attr($submission['property_mls']); ?>/" target="_blank">
                                        <?php echo esc_html($submission['property_address'] ?: 'MLS #' . $submission['property_mls']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_colors = [
                                    'new' => '#d63638',
                                    'read' => '#2271b1',
                                    'contacted' => '#f0b849',
                                    'converted' => '#00a32a'
                                ];
                                $status_color = $status_colors[$submission['status']] ?? '#666';
                                ?>
                                <span style="color: <?php echo $status_color; ?>; font-weight: 500;">
                                    <?php echo ucfirst($submission['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button button-small view-details" 
                                        data-submission-id="<?php echo esc_attr($submission['id']); ?>">
                                    View
                                </button>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mld_form_submissions&action=delete&id=' . $submission['id']), 'delete_submission_' . $submission['id']); ?>" 
                                   class="button button-small button-link-delete"
                                   onclick="return confirm('Are you sure you want to delete this submission?');">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-2">
                    </td>
                    <th scope="col" class="manage-column">Date</th>
                    <th scope="col" class="manage-column">Type</th>
                    <th scope="col" class="manage-column">Name</th>
                    <th scope="col" class="manage-column">Email</th>
                    <th scope="col" class="manage-column">Phone</th>
                    <th scope="col" class="manage-column">Property</th>
                    <th scope="col" class="manage-column">Status</th>
                    <th scope="col" class="manage-column">Actions</th>
                </tr>
            </tfoot>
        </table>
        
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action">
                    <option value="">Bulk Actions</option>
                    <option value="bulk_delete">Delete</option>
                </select>
                <input type="submit" class="button action" value="Apply"
                       onclick="return confirm('Are you sure you want to delete the selected submissions?');">
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_items; ?> items</span>
                    <span class="pagination-links">
                        <?php
                        $base_url = admin_url('admin.php?page=mld_form_submissions');
                        if ($form_type) $base_url .= '&form_type=' . $form_type;
                        if ($status) $base_url .= '&status=' . $status;
                        if ($search) $base_url .= '&s=' . $search;
                        if ($date_from) $base_url .= '&date_from=' . $date_from;
                        if ($date_to) $base_url .= '&date_to=' . $date_to;
                        
                        if ($current_page > 1): ?>
                            <a class="first-page button" href="<?php echo $base_url; ?>&paged=1">«</a>
                            <a class="prev-page button" href="<?php echo $base_url; ?>&paged=<?php echo $current_page - 1; ?>">‹</a>
                        <?php endif; ?>
                        
                        <span class="paging-input">
                            <?php echo $current_page; ?> of <span class="total-pages"><?php echo $total_pages; ?></span>
                        </span>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a class="next-page button" href="<?php echo $base_url; ?>&paged=<?php echo $current_page + 1; ?>">›</a>
                            <a class="last-page button" href="<?php echo $base_url; ?>&paged=<?php echo $total_pages; ?>">»</a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Modal for viewing submission details -->
<div id="submission-details-modal" style="display: none;">
    <div style="background: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 99998;"
         onclick="document.getElementById('submission-details-modal').style.display='none';"></div>
    <div style="background: #fff; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; padding: 20px; 
                box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 99999; border-radius: 4px;">
        <h2 style="margin-top: 0;">Submission Details</h2>
        <div id="submission-details-content"></div>
        <button type="button" class="button button-primary" 
                onclick="document.getElementById('submission-details-modal').style.display='none';">
            Close
        </button>
    </div>
</div>

<script>
// Store submissions data in JavaScript to avoid JSON encoding issues in HTML attributes
var submissionsData = <?php echo json_encode($submissions); ?>;

// Store forms data for field label lookups
var formsData = <?php
    global $wpdb;
    $forms_table = $wpdb->prefix . 'mld_contact_forms';
    $forms = $wpdb->get_results("SELECT id, form_name, fields FROM {$forms_table}", ARRAY_A);
    $forms_lookup = [];
    foreach ($forms as $form) {
        $form['fields'] = json_decode($form['fields'], true);
        $forms_lookup[$form['id']] = $form;
    }
    echo json_encode($forms_lookup);
?>;

jQuery(document).ready(function($) {
    // Handle "select all" checkboxes
    $('#cb-select-all, #cb-select-all-2').on('click', function() {
        $('input[name="submission_ids[]"]').prop('checked', this.checked);
    });
    
    // Handle view details button
    $('.view-details').on('click', function() {
        var $button = $(this);
        var submissionId = $button.data('submission-id');
        
        // Find submission data from the JavaScript array
        var submission = null;
        for (var i = 0; i < submissionsData.length; i++) {
            if (submissionsData[i].id == submissionId) {
                submission = submissionsData[i];
                break;
            }
        }
        
        // Check if submission data is valid
        if (!submission || typeof submission !== 'object') {
            console.error('Could not find submission data for ID:', submissionId);
            alert('Error loading submission details. Please refresh the page and try again.');
            return;
        }
        
        var content = '<table class="form-table">';
        
        // Basic info
        content += '<tr><th>Date:</th><td>' + new Date(submission.created_at).toLocaleString() + '</td></tr>';
        content += '<tr><th>Form Type:</th><td>' + (submission.form_type === 'tour' ? 'Tour Request' : 'Contact Form') + '</td></tr>';
        content += '<tr><th>Status:</th><td>' + submission.status.charAt(0).toUpperCase() + submission.status.slice(1) + '</td></tr>';
        
        // Contact info
        content += '<tr><th>Name:</th><td>' + submission.first_name + ' ' + submission.last_name + '</td></tr>';
        content += '<tr><th>Email:</th><td><a href="mailto:' + submission.email + '">' + submission.email + '</a></td></tr>';
        if (submission.phone) {
            content += '<tr><th>Phone:</th><td><a href="tel:' + submission.phone + '">' + submission.phone + '</a></td></tr>';
        }
        
        // Property info
        if (submission.property_mls) {
            content += '<tr><th>Property:</th><td>';
            var propertyText = submission.property_address || 'MLS #' + submission.property_mls;
            content += $('<div>').text(propertyText).html();
            content += ' <a href="/property/' + submission.property_mls + '/" target="_blank">View →</a>';
            content += '</td></tr>';
        }
        
        // Message (for legacy forms)
        if (submission.message && !submission.form_data) {
            // Escape HTML and convert line breaks
            var escapedMessage = $('<div>').text(submission.message).html().replace(/\n/g, '<br>');
            content += '<tr><th>Message:</th><td>' + escapedMessage + '</td></tr>';
        }

        // Custom form data (universal contact forms)
        if (submission.form_data && submission.form_id) {
            content += '<tr><th colspan="2" style="background: #f0f0f1; padding: 10px;"><strong>Form Responses</strong></th></tr>';

            var formData = submission.form_data;
            if (typeof formData === 'string') {
                try {
                    formData = JSON.parse(formData);
                } catch (e) {
                    formData = {};
                }
            }

            // Get field labels from form definition if available
            var formDef = formsData && formsData[submission.form_id] ? formsData[submission.form_id] : null;
            var fieldLabels = {};
            if (formDef && formDef.fields && formDef.fields.fields) {
                formDef.fields.fields.forEach(function(field) {
                    fieldLabels[field.id] = field.label;
                });
            }

            // Display each field
            for (var fieldId in formData) {
                // Skip internal fields
                if (fieldId.startsWith('_')) continue;

                var value = formData[fieldId];
                var label = fieldLabels[fieldId] || fieldId;

                // Format array values (checkboxes)
                if (Array.isArray(value)) {
                    value = value.join(', ');
                }

                // Escape and display
                var escapedValue = $('<div>').text(value || '').html().replace(/\n/g, '<br>');
                if (!escapedValue) {
                    escapedValue = '<em style="color: #999;">Not provided</em>';
                }

                content += '<tr><th>' + $('<div>').text(label).html() + ':</th><td>' + escapedValue + '</td></tr>';
            }
        }

        // Tour specific fields
        if (submission.form_type === 'tour') {
            if (submission.tour_type) {
                content += '<tr><th>Tour Type:</th><td>' + submission.tour_type.replace('_', ' ').charAt(0).toUpperCase() + submission.tour_type.slice(1).replace('_', ' ') + '</td></tr>';
            }
            if (submission.preferred_date) {
                content += '<tr><th>Preferred Date:</th><td>' + new Date(submission.preferred_date).toLocaleDateString() + '</td></tr>';
            }
            if (submission.preferred_time) {
                content += '<tr><th>Preferred Time:</th><td>' + submission.preferred_time + '</td></tr>';
            }
        }
        
        // Technical info
        content += '<tr><th>IP Address:</th><td>' + (submission.ip_address || 'Unknown') + '</td></tr>';
        
        content += '</table>';
        
        // Update status buttons
        content += '<hr><h3>Update Status</h3>';
        content += '<div style="display: flex; gap: 10px;">';
        ['new', 'read', 'contacted', 'converted'].forEach(function(status) {
            if (submission.status !== status) {
                content += '<button type="button" class="button update-status" data-id="' + submission.id + '" data-status="' + status + '">';
                content += status.charAt(0).toUpperCase() + status.slice(1);
                content += '</button>';
            }
        });
        content += '</div>';
        
        $('#submission-details-content').html(content);
        $('#submission-details-modal').show();
    });
    
    // Handle status update
    $(document).on('click', '.update-status', function() {
        var $button = $(this);
        var id = $button.data('id');
        var status = $button.data('status');
        
        $.post(ajaxurl, {
            action: 'mld_update_submission_status',
            id: id,
            status: status,
            _wpnonce: '<?php echo wp_create_nonce('update_submission_status'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error updating status: ' + response.data);
            }
        });
    });
});
</script>