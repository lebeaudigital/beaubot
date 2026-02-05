<?php
/**
 * Template: Page des conversations admin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Récupérer les conversations
global $wpdb;
$table_messages = $wpdb->prefix . 'beaubot_messages';

$conversations = get_posts([
    'post_type' => 'beaubot_conversation',
    'posts_per_page' => 50,
    'orderby' => 'modified',
    'order' => 'DESC',
]);
?>
<div class="wrap beaubot-admin-wrap">
    <h1>
        <span class="dashicons dashicons-format-chat"></span>
        <?php esc_html_e('BeauBot - Conversations', 'beaubot'); ?>
    </h1>

    <div class="beaubot-card">
        <?php if (empty($conversations)): ?>
            <div class="beaubot-empty-state">
                <span class="dashicons dashicons-format-chat"></span>
                <h3><?php esc_html_e('Aucune conversation', 'beaubot'); ?></h3>
                <p><?php esc_html_e('Les conversations des utilisateurs apparaîtront ici.', 'beaubot'); ?></p>
            </div>
        <?php else: ?>
            <table class="beaubot-conversations-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Conversation', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Utilisateur', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Messages', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Dernière activité', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Statut', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Actions', 'beaubot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conversations as $conversation): 
                        $user = get_user_by('id', $conversation->post_author);
                        $message_count = (int) get_post_meta($conversation->ID, '_beaubot_message_count', true);
                        $archived = (bool) get_post_meta($conversation->ID, '_beaubot_archived', true);
                        $modified = human_time_diff(strtotime($conversation->post_modified), current_time('timestamp'));
                    ?>
                        <tr>
                            <td>
                                <span class="beaubot-conversation-title">
                                    <?php echo esc_html($conversation->post_title); ?>
                                </span>
                                <div class="beaubot-conversation-meta">
                                    ID: <?php echo esc_html($conversation->ID); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($user): ?>
                                    <?php echo get_avatar($user->ID, 24); ?>
                                    <?php echo esc_html($user->display_name); ?>
                                <?php else: ?>
                                    <em><?php esc_html_e('Utilisateur supprimé', 'beaubot'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($message_count); ?></td>
                            <td>
                                <?php 
                                printf(
                                    esc_html__('Il y a %s', 'beaubot'),
                                    $modified
                                ); 
                                ?>
                            </td>
                            <td>
                                <?php if ($archived): ?>
                                    <span class="beaubot-status beaubot-status-warning">
                                        <?php esc_html_e('Archivée', 'beaubot'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="beaubot-status beaubot-status-success">
                                        <?php esc_html_e('Active', 'beaubot'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="beaubot-conversation-actions">
                                    <button type="button" 
                                            class="button beaubot-view-conversation" 
                                            data-id="<?php echo esc_attr($conversation->ID); ?>">
                                        <?php esc_html_e('Voir', 'beaubot'); ?>
                                    </button>
                                    <button type="button" 
                                            class="button beaubot-delete-conversation" 
                                            data-id="<?php echo esc_attr($conversation->ID); ?>">
                                        <?php esc_html_e('Supprimer', 'beaubot'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="beaubot-card">
        <h2><?php esc_html_e('Statistiques', 'beaubot'); ?></h2>
        <?php
        $total_conversations = count($conversations);
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$table_messages}");
        $active_users = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'beaubot_conversation'"
        );
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Total des conversations', 'beaubot'); ?></th>
                <td><strong><?php echo esc_html($total_conversations); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Total des messages', 'beaubot'); ?></th>
                <td><strong><?php echo esc_html($total_messages); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Utilisateurs actifs', 'beaubot'); ?></th>
                <td><strong><?php echo esc_html($active_users); ?></strong></td>
            </tr>
        </table>
    </div>
</div>

<!-- Modal pour voir une conversation -->
<div id="beaubot-conversation-modal" class="beaubot-modal" style="display:none;">
    <div class="beaubot-modal-content">
        <span class="beaubot-modal-close">&times;</span>
        <h2 id="beaubot-modal-title"></h2>
        <div id="beaubot-modal-messages"></div>
    </div>
</div>

<style>
.beaubot-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}
.beaubot-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 800px;
    max-height: 80vh;
    overflow-y: auto;
}
.beaubot-modal-close {
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}
.beaubot-modal-message {
    padding: 12px;
    margin: 8px 0;
    border-radius: 8px;
}
.beaubot-modal-message.user {
    background: #6366f1;
    color: white;
    margin-left: 20%;
}
.beaubot-modal-message.assistant {
    background: #f1f5f9;
    margin-right: 20%;
}
.beaubot-modal-message img {
    max-width: 200px;
    border-radius: 4px;
    margin-top: 8px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Voir une conversation
    $('.beaubot-view-conversation').on('click', function() {
        var id = $(this).data('id');
        
        $.ajax({
            url: '<?php echo esc_url(rest_url('beaubot/v1/conversations/')); ?>' + id,
            headers: {
                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
            },
            success: function(response) {
                if (response.success && response.conversation) {
                    var conv = response.conversation;
                    $('#beaubot-modal-title').text(conv.title);
                    
                    var html = '';
                    if (conv.messages) {
                        conv.messages.forEach(function(msg) {
                            html += '<div class="beaubot-modal-message ' + msg.role + '">';
                            html += '<strong>' + (msg.role === 'user' ? 'Utilisateur' : 'BeauBot') + '</strong><br>';
                            html += msg.content.replace(/\n/g, '<br>');
                            if (msg.image_url) {
                                html += '<br><img src="' + msg.image_url + '" alt="Image">';
                            }
                            html += '</div>';
                        });
                    }
                    
                    $('#beaubot-modal-messages').html(html || '<p>Aucun message</p>');
                    $('#beaubot-conversation-modal').show();
                }
            }
        });
    });
    
    // Fermer le modal
    $('.beaubot-modal-close, .beaubot-modal').on('click', function(e) {
        if (e.target === this) {
            $('#beaubot-conversation-modal').hide();
        }
    });
    
    // Supprimer une conversation
    $('.beaubot-delete-conversation').on('click', function() {
        if (!confirm('<?php esc_html_e('Êtes-vous sûr de vouloir supprimer cette conversation ?', 'beaubot'); ?>')) {
            return;
        }
        
        var id = $(this).data('id');
        var row = $(this).closest('tr');
        
        $.ajax({
            url: '<?php echo esc_url(rest_url('beaubot/v1/conversations/')); ?>' + id,
            method: 'DELETE',
            headers: {
                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() { $(this).remove(); });
                }
            }
        });
    });
});
</script>
