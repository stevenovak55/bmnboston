<?php
/**
 * FAQ Library Tab View
 *
 * Manage FAQ entries for fallback responses when AI fails
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot/Views
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_render_faq_tab() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mld_chat_faq_library';

    // Get all FAQs
    $faqs = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY priority DESC, category, id",
        ARRAY_A
    );

    ?>
    <div class="mld-faq-manager">
        <div class="mld-faq-header">
            <h2><?php _e('FAQ Library', 'mls-listings-display'); ?></h2>
            <p class="description">
                <?php _e('These FAQs are used as fallback responses when the AI provider is unavailable or rate limits are exceeded.', 'mls-listings-display'); ?>
            </p>
            <button type="button" class="button button-primary mld-add-faq">
                <?php _e('+ Add New FAQ', 'mls-listings-display'); ?>
            </button>
        </div>

        <!-- FAQ List -->
        <table class="wp-list-table widefat fixed striped mld-faq-table">
            <thead>
                <tr>
                    <th class="column-question"><?php _e('Question', 'mls-listings-display'); ?></th>
                    <th class="column-category"><?php _e('Category', 'mls-listings-display'); ?></th>
                    <th class="column-priority"><?php _e('Priority', 'mls-listings-display'); ?></th>
                    <th class="column-usage"><?php _e('Usage', 'mls-listings-display'); ?></th>
                    <th class="column-status"><?php _e('Status', 'mls-listings-display'); ?></th>
                    <th class="column-actions"><?php _e('Actions', 'mls-listings-display'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($faqs)) : ?>
                    <tr>
                        <td colspan="6" class="no-items">
                            <?php _e('No FAQ entries found. Add your first FAQ to get started.', 'mls-listings-display'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($faqs as $faq) : ?>
                        <tr data-faq-id="<?php echo esc_attr($faq['id']); ?>">
                            <td class="column-question">
                                <strong><?php echo esc_html(wp_trim_words($faq['question'], 10)); ?></strong>
                                <?php if (!empty($faq['keywords'])) : ?>
                                    <div class="faq-keywords">
                                        <small><?php _e('Keywords:', 'mls-listings-display'); ?> <?php echo esc_html($faq['keywords']); ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="column-category">
                                <span class="faq-category-badge"><?php echo esc_html($faq['category']); ?></span>
                            </td>
                            <td class="column-priority">
                                <?php echo esc_html($faq['priority']); ?>
                            </td>
                            <td class="column-usage">
                                <?php echo esc_html($faq['usage_count']); ?> <?php _e('times', 'mls-listings-display'); ?>
                                <?php if ($faq['last_used_at']) : ?>
                                    <br><small><?php echo esc_html(human_time_diff(strtotime($faq['last_used_at']), current_time('timestamp'))); ?> <?php _e('ago', 'mls-listings-display'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="column-status">
                                <?php if ($faq['is_active']) : ?>
                                    <span class="faq-status-active"><?php _e('Active', 'mls-listings-display'); ?></span>
                                <?php else : ?>
                                    <span class="faq-status-inactive"><?php _e('Inactive', 'mls-listings-display'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-actions">
                                <button type="button" class="button button-small mld-edit-faq" data-faq-id="<?php echo esc_attr($faq['id']); ?>">
                                    <?php _e('Edit', 'mls-listings-display'); ?>
                                </button>
                                <button type="button" class="button button-small mld-delete-faq" data-faq-id="<?php echo esc_attr($faq['id']); ?>">
                                    <?php _e('Delete', 'mls-listings-display'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- FAQ Edit Modal (Hidden by default) -->
    <div id="mld-faq-modal" class="mld-modal" style="display:none;">
        <div class="mld-modal-content">
            <span class="mld-modal-close">&times;</span>
            <h2 id="mld-faq-modal-title"><?php _e('Add FAQ Entry', 'mls-listings-display'); ?></h2>
            <form id="mld-faq-form">
                <input type="hidden" id="faq_id" name="faq_id" value="0">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="faq_question"><?php _e('Question', 'mls-listings-display'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <textarea id="faq_question"
                                      name="faq_question"
                                      rows="2"
                                      class="large-text"
                                      required></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="faq_answer"><?php _e('Answer', 'mls-listings-display'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <textarea id="faq_answer"
                                      name="faq_answer"
                                      rows="5"
                                      class="large-text"
                                      required></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="faq_keywords"><?php _e('Keywords', 'mls-listings-display'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="faq_keywords"
                                   name="faq_keywords"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e('comma, separated, keywords', 'mls-listings-display'); ?>">
                            <p class="description">
                                <?php _e('Keywords help match user questions to this FAQ', 'mls-listings-display'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="faq_category"><?php _e('Category', 'mls-listings-display'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="faq_category"
                                   name="faq_category"
                                   class="regular-text"
                                   value="general">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="faq_priority"><?php _e('Priority', 'mls-listings-display'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="faq_priority"
                                   name="faq_priority"
                                   min="0"
                                   max="10"
                                   value="5"
                                   class="small-text">
                            <p class="description">
                                <?php _e('Higher priority FAQs are shown first (0-10)', 'mls-listings-display'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('Save FAQ', 'mls-listings-display'); ?></button>
                    <button type="button" class="button mld-modal-close"><?php _e('Cancel', 'mls-listings-display'); ?></button>
                </p>
            </form>
        </div>
    </div>

    <style>
        .mld-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .mld-modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 5px;
        }
        .mld-modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .mld-modal-close:hover {
            color: #000;
        }
        .faq-category-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #e0e0e0;
            border-radius: 3px;
            font-size: 12px;
        }
        .faq-status-active {
            color: #46b450;
            font-weight: bold;
        }
        .faq-status-inactive {
            color: #dc3232;
        }
        .required {
            color: #dc3232;
        }
    </style>
    <?php
}
