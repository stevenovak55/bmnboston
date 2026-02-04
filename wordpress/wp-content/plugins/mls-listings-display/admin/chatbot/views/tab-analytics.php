<?php
/**
 * Analytics Tab View
 *
 * Display chatbot usage statistics and AI cost tracking
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot/Views
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_render_analytics_tab() {
    global $wpdb;

    // Check if viewing a specific conversation
    $conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;

    if ($conversation_id > 0) {
        mld_render_conversation_detail($conversation_id);
        return;
    }

    // Get conversation stats
    $conversations_table = $wpdb->prefix . 'mld_chat_conversations';
    $messages_table = $wpdb->prefix . 'mld_chat_messages';

    $total_conversations = $wpdb->get_var("SELECT COUNT(*) FROM {$conversations_table}");
    $active_conversations = $wpdb->get_var("SELECT COUNT(*) FROM {$conversations_table} WHERE conversation_status = 'active'");
    $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table}");
    $user_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table} WHERE sender_type = 'user'");
    $ai_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table} WHERE sender_type = 'assistant'");

    // Get AI provider usage
    $provider_stats = $wpdb->get_results(
        "SELECT ai_provider, ai_model, COUNT(*) as count, SUM(ai_tokens_used) as total_tokens
         FROM {$messages_table}
         WHERE ai_provider IS NOT NULL
         GROUP BY ai_provider, ai_model
         ORDER BY count DESC",
        ARRAY_A
    );

    // Get recent conversations
    $recent_conversations = $wpdb->get_results(
        "SELECT id, user_email, user_name, total_messages, conversation_status, started_at
         FROM {$conversations_table}
         ORDER BY started_at DESC
         LIMIT 10",
        ARRAY_A
    );

    ?>
    <div class="mld-analytics-dashboard">
        <!-- Overview Stats -->
        <div class="mld-stats-grid">
            <div class="mld-stat-card">
                <h3><?php _e('Total Conversations', 'mls-listings-display'); ?></h3>
                <div class="mld-stat-value"><?php echo esc_html($total_conversations ? $total_conversations : 0); ?></div>
                <div class="mld-stat-label">
                    <?php echo esc_html($active_conversations ? $active_conversations : 0); ?> <?php _e('active', 'mls-listings-display'); ?>
                </div>
            </div>

            <div class="mld-stat-card">
                <h3><?php _e('Total Messages', 'mls-listings-display'); ?></h3>
                <div class="mld-stat-value"><?php echo esc_html($total_messages ? $total_messages : 0); ?></div>
                <div class="mld-stat-label">
                    <?php echo esc_html($user_messages ? $user_messages : 0); ?> <?php _e('from users', 'mls-listings-display'); ?> /
                    <?php echo esc_html($ai_messages ? $ai_messages : 0); ?> <?php _e('from AI', 'mls-listings-display'); ?>
                </div>
            </div>

            <div class="mld-stat-card">
                <h3><?php _e('Avg. Messages/Conversation', 'mls-listings-display'); ?></h3>
                <div class="mld-stat-value">
                    <?php
                    $avg = $total_conversations > 0 ? round($total_messages / $total_conversations, 1) : 0;
                    echo esc_html($avg);
                    ?>
                </div>
                <div class="mld-stat-label"><?php _e('messages per chat', 'mls-listings-display'); ?></div>
            </div>

            <div class="mld-stat-card">
                <h3><?php _e('AI Usage', 'mls-listings-display'); ?></h3>
                <div class="mld-stat-value">
                    <?php
                    $total_tokens = 0;
                    foreach ($provider_stats as $stat) {
                        $total_tokens += $stat['total_tokens'];
                    }
                    echo esc_html(number_format($total_tokens));
                    ?>
                </div>
                <div class="mld-stat-label"><?php _e('tokens used', 'mls-listings-display'); ?></div>
            </div>
        </div>

        <!-- AI Provider Breakdown -->
        <div class="mld-settings-section">
            <h2><?php _e('AI Provider Usage', 'mls-listings-display'); ?></h2>
            <?php if (empty($provider_stats)) : ?>
                <p class="description"><?php _e('No AI usage data yet. Start using the chatbot to see statistics.', 'mls-listings-display'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Provider', 'mls-listings-display'); ?></th>
                            <th><?php _e('Model', 'mls-listings-display'); ?></th>
                            <th><?php _e('Messages', 'mls-listings-display'); ?></th>
                            <th><?php _e('Tokens Used', 'mls-listings-display'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($provider_stats as $stat) : ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst($stat['ai_provider'])); ?></strong></td>
                                <td><?php echo esc_html($stat['ai_model']); ?></td>
                                <td><?php echo esc_html($stat['count']); ?></td>
                                <td><?php echo esc_html(number_format($stat['total_tokens'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Conversations -->
        <div class="mld-settings-section">
            <h2><?php _e('Recent Conversations', 'mls-listings-display'); ?></h2>
            <?php if (empty($recent_conversations)) : ?>
                <p class="description"><?php _e('No conversations yet. Your chatbot conversations will appear here.', 'mls-listings-display'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('User', 'mls-listings-display'); ?></th>
                            <th><?php _e('Email', 'mls-listings-display'); ?></th>
                            <th><?php _e('Messages', 'mls-listings-display'); ?></th>
                            <th><?php _e('Status', 'mls-listings-display'); ?></th>
                            <th><?php _e('Started', 'mls-listings-display'); ?></th>
                            <th><?php _e('Actions', 'mls-listings-display'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_conversations as $conv) : ?>
                            <tr>
                                <td><?php echo esc_html($conv['user_name'] ? $conv['user_name'] : __('Anonymous', 'mls-listings-display')); ?></td>
                                <td><?php echo esc_html($conv['user_email'] ? $conv['user_email'] : '—'); ?></td>
                                <td><?php echo esc_html($conv['total_messages']); ?></td>
                                <td>
                                    <span class="conversation-status-<?php echo esc_attr($conv['conversation_status']); ?>">
                                        <?php echo esc_html(ucfirst($conv['conversation_status'])); ?>
                                    </span>
                                </td>
                                <td><?php
    // v6.75.4: Database stores in WP timezone, not UTC - use DateTime with wp_timezone()
    $local_time = (new DateTime($conv['started_at'], wp_timezone()))->getTimestamp();
    echo esc_html(human_time_diff($local_time, current_time('timestamp')));
?> <?php _e('ago', 'mls-listings-display'); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=mld-chatbot-settings&tab=analytics&conversation_id=' . $conv['id'])); ?>" class="button button-small">
                                        <?php _e('View', 'mls-listings-display'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .mld-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .mld-stat-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            text-align: center;
        }
        .mld-stat-card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #646970;
            font-weight: 500;
        }
        .mld-stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #1d2327;
            margin: 10px 0;
        }
        .mld-stat-label {
            font-size: 12px;
            color: #646970;
        }
        .conversation-status-active {
            color: #46b450;
            font-weight: bold;
        }
        .conversation-status-closed {
            color: #646970;
        }
    </style>
    <?php
}

/**
 * Render conversation detail view
 *
 * @param int $conversation_id Conversation ID
 */
function mld_render_conversation_detail($conversation_id) {
    global $wpdb;

    // Get conversation details
    $conversation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mld_chat_conversations WHERE id = %d",
        $conversation_id
    ), ARRAY_A);

    if (!$conversation) {
        echo '<div class="notice notice-error"><p>' . __('Conversation not found.', 'mls-listings-display') . '</p></div>';
        return;
    }

    // Get all messages for this conversation
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mld_chat_messages
         WHERE conversation_id = %d
         ORDER BY created_at ASC",
        $conversation_id
    ), ARRAY_A);

    ?>
    <div class="mld-conversation-detail">
        <!-- Back button -->
        <p>
            <a href="?page=mld-chatbot-settings&tab=analytics" class="button">
                &larr; <?php _e('Back to Analytics', 'mls-listings-display'); ?>
            </a>
        </p>

        <!-- Conversation Header -->
        <div class="mld-conversation-header">
            <h2><?php _e('Conversation Details', 'mls-listings-display'); ?></h2>
            <div class="mld-conversation-meta">
                <p><strong><?php _e('User:', 'mls-listings-display'); ?></strong>
                    <?php echo esc_html($conversation['user_name'] ?: __('Anonymous', 'mls-listings-display')); ?>
                    <?php if ($conversation['user_email']): ?>
                        (<?php echo esc_html($conversation['user_email']); ?>)
                    <?php endif; ?>
                </p>
                <p><strong><?php _e('Status:', 'mls-listings-display'); ?></strong>
                    <span class="conversation-status-<?php echo esc_attr($conversation['conversation_status']); ?>">
                        <?php echo esc_html(ucfirst($conversation['conversation_status'])); ?>
                    </span>
                </p>
                <p><strong><?php _e('Started:', 'mls-listings-display'); ?></strong>
                    <?php
    // v6.75.4: Database stores in WP timezone, not UTC - use DateTime with wp_timezone()
    $local_timestamp = (new DateTime($conversation['started_at'], wp_timezone()))->getTimestamp();
    echo esc_html(wp_date('F j, Y g:i A', $local_timestamp));
    ?>
                </p>
                <p><strong><?php _e('Total Messages:', 'mls-listings-display'); ?></strong>
                    <?php echo esc_html($conversation['total_messages']); ?>
                </p>
            </div>
        </div>

        <!-- Messages -->
        <div class="mld-conversation-messages">
            <h3><?php _e('Conversation History', 'mls-listings-display'); ?></h3>
            <?php if (empty($messages)): ?>
                <p class="description"><?php _e('No messages in this conversation.', 'mls-listings-display'); ?></p>
            <?php else: ?>
                <div class="mld-messages-list">
                    <?php foreach ($messages as $message): ?>
                        <div class="mld-message mld-message-<?php echo esc_attr($message['sender_type']); ?>">
                            <div class="mld-message-header">
                                <strong>
                                    <?php echo $message['sender_type'] === 'user' ? __('User', 'mls-listings-display') : __('AI Assistant', 'mls-listings-display'); ?>
                                </strong>
                                <span class="mld-message-time">
                                    <?php
    // v6.75.4: Database stores in WP timezone, not UTC - use DateTime with wp_timezone()
    $msg_local_time = (new DateTime($message['created_at'], wp_timezone()))->getTimestamp();
    echo esc_html(wp_date('g:i A', $msg_local_time));
    ?>
                                </span>
                            </div>
                            <div class="mld-message-content">
                                <?php echo nl2br(esc_html($message['message_text'])); ?>
                            </div>
                            <?php if ($message['ai_provider']): ?>
                                <div class="mld-message-meta">
                                    <small>
                                        <?php _e('Provider:', 'mls-listings-display'); ?>
                                        <?php echo esc_html(ucfirst($message['ai_provider'])); ?>
                                        <?php if ($message['ai_model']): ?>
                                            (<?php echo esc_html($message['ai_model']); ?>)
                                        <?php endif; ?>
                                        <?php if ($message['ai_tokens_used']): ?>
                                            - <?php echo esc_html($message['ai_tokens_used']); ?> <?php _e('tokens', 'mls-listings-display'); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .mld-conversation-detail {
            max-width: 1000px;
            margin: 20px 0;
        }
        .mld-conversation-header {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .mld-conversation-header h2 {
            margin-top: 0;
        }
        .mld-conversation-meta p {
            margin: 5px 0;
        }
        .mld-conversation-messages {
            margin: 20px 0;
        }
        .mld-messages-list {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .mld-message {
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border-left: 4px solid #ccc;
        }
        .mld-message-user {
            background: #f0f6fc;
            border-left-color: #0073aa;
        }
        .mld-message-assistant {
            background: #f0fff4;
            border-left-color: #46b450;
        }
        .mld-message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .mld-message-header strong {
            color: #1d2327;
            font-size: 14px;
        }
        .mld-message-time {
            color: #646970;
            font-size: 12px;
        }
        .mld-message-content {
            color: #1d2327;
            line-height: 1.6;
            font-size: 14px;
        }
        .mld-message-meta {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0,0,0,0.1);
            color: #646970;
        }
        .conversation-status-active {
            color: #46b450;
            font-weight: bold;
        }
        .conversation-status-closed {
            color: #646970;
        }
    </style>
    <?php
}
