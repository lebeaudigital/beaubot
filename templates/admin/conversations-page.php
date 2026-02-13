<?php
/**
 * Template: Page des conversations admin
 * 
 * Affiche toutes les conversations de tous les utilisateurs avec :
 * - Filtres par statut (Active/Archivée) et par utilisateur
 * - Sélection multiple + actions en masse (supprimer, archiver, désarchiver)
 * - Boutons individuels : Voir, Archiver/Désarchiver, Supprimer
 */

if (!defined('ABSPATH')) {
    exit;
}

// Récupérer les filtres depuis les paramètres GET
$filter_status = isset($_GET['conv_status']) ? sanitize_text_field($_GET['conv_status']) : 'all';
$filter_user = isset($_GET['conv_user']) ? absint($_GET['conv_user']) : 0;

// Récupérer les conversations via la classe
$conversation_handler = new BeauBot_Conversation();
$conversations = $conversation_handler->admin_get_all_conversations(100, 0, $filter_status, $filter_user);

// Récupérer la liste de tous les auteurs de conversations
global $wpdb;
$table_messages = $wpdb->prefix . 'beaubot_messages';
$authors = $wpdb->get_results(
    "SELECT DISTINCT p.post_author as user_id, u.display_name 
     FROM {$wpdb->posts} p 
     LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID 
     WHERE p.post_type = 'beaubot_conversation' 
     ORDER BY u.display_name ASC"
);
?>
<div class="wrap beaubot-admin-wrap">
    <h1>
        <span class="dashicons dashicons-format-chat"></span>
        <?php esc_html_e('BeauBot - Conversations', 'beaubot'); ?>
    </h1>

    <!-- Barre de filtres -->
    <div class="beaubot-card beaubot-filters-bar">
        <div class="beaubot-filters">
            <div class="beaubot-filter-group">
                <label for="beaubot-filter-status"><?php esc_html_e('Statut :', 'beaubot'); ?></label>
                <select id="beaubot-filter-status">
                    <option value="all" <?php selected($filter_status, 'all'); ?>>
                        <?php esc_html_e('Toutes', 'beaubot'); ?>
                    </option>
                    <option value="active" <?php selected($filter_status, 'active'); ?>>
                        <?php esc_html_e('Actives', 'beaubot'); ?>
                    </option>
                    <option value="archived" <?php selected($filter_status, 'archived'); ?>>
                        <?php esc_html_e('Archivées', 'beaubot'); ?>
                    </option>
                </select>
            </div>
            <div class="beaubot-filter-group">
                <label for="beaubot-filter-user"><?php esc_html_e('Utilisateur :', 'beaubot'); ?></label>
                <select id="beaubot-filter-user">
                    <option value="0"><?php esc_html_e('Tous les utilisateurs', 'beaubot'); ?></option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?php echo esc_attr($author->user_id); ?>" 
                                <?php selected($filter_user, (int) $author->user_id); ?>>
                            <?php echo esc_html($author->display_name ?: __('Utilisateur supprimé', 'beaubot')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="beaubot-filter-count">
                <?php
                printf(
                    esc_html(_n('%d conversation', '%d conversations', count($conversations), 'beaubot')),
                    count($conversations)
                );
                ?>
            </div>
        </div>
    </div>

    <!-- Barre d'actions en masse -->
    <div id="beaubot-bulk-toolbar" class="beaubot-bulk-toolbar">
        <span class="beaubot-bulk-info">
            <strong id="beaubot-selected-count">0</strong>
            <?php esc_html_e('sélectionnée(s)', 'beaubot'); ?>
        </span>
        <div class="beaubot-bulk-actions">
            <button type="button" id="beaubot-bulk-archive" class="button">
                <span class="dashicons dashicons-archive"></span>
                <?php esc_html_e('Archiver', 'beaubot'); ?>
            </button>
            <button type="button" id="beaubot-bulk-unarchive" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e('Désarchiver', 'beaubot'); ?>
            </button>
            <button type="button" id="beaubot-bulk-delete" class="button beaubot-btn-danger">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Supprimer', 'beaubot'); ?>
            </button>
        </div>
    </div>

    <!-- Tableau des conversations -->
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
                        <th class="beaubot-col-check">
                            <input type="checkbox" id="beaubot-select-all" title="<?php esc_attr_e('Tout sélectionner', 'beaubot'); ?>">
                        </th>
                        <th><?php esc_html_e('Conversation', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Utilisateur', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Messages', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Dernière activité', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Statut', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Actions', 'beaubot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conversations as $conv): 
                        $user = get_user_by('id', $conv['author_id']);
                        $modified = human_time_diff(strtotime($conv['updated_at']), current_time('timestamp'));
                    ?>
                        <tr>
                            <td class="beaubot-col-check">
                                <input type="checkbox" 
                                       class="beaubot-select-conversation" 
                                       value="<?php echo esc_attr($conv['id']); ?>">
                            </td>
                            <td>
                                <span class="beaubot-conversation-title">
                                    <?php echo esc_html($conv['title']); ?>
                                </span>
                                <div class="beaubot-conversation-meta">
                                    ID: <?php echo esc_html($conv['id']); ?>
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
                            <td><?php echo esc_html($conv['message_count']); ?></td>
                            <td>
                                <?php 
                                printf(
                                    esc_html__('Il y a %s', 'beaubot'),
                                    $modified
                                ); 
                                ?>
                            </td>
                            <td>
                                <?php if ($conv['archived']): ?>
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
                                            data-id="<?php echo esc_attr($conv['id']); ?>"
                                            title="<?php esc_attr_e('Voir les messages', 'beaubot'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <?php if ($conv['archived']): ?>
                                        <button type="button" 
                                                class="button beaubot-unarchive-conversation" 
                                                data-id="<?php echo esc_attr($conv['id']); ?>"
                                                title="<?php esc_attr_e('Désarchiver', 'beaubot'); ?>">
                                            <span class="dashicons dashicons-update"></span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" 
                                                class="button beaubot-archive-conversation" 
                                                data-id="<?php echo esc_attr($conv['id']); ?>"
                                                title="<?php esc_attr_e('Archiver', 'beaubot'); ?>">
                                            <span class="dashicons dashicons-archive"></span>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" 
                                            class="button beaubot-delete-conversation beaubot-btn-danger-icon" 
                                            data-id="<?php echo esc_attr($conv['id']); ?>"
                                            title="<?php esc_attr_e('Supprimer', 'beaubot'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Statistiques -->
    <div class="beaubot-card">
        <h2><?php esc_html_e('Statistiques', 'beaubot'); ?></h2>
        <?php
        $total_conversations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'beaubot_conversation'"
        );
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM {$table_messages}");
        $active_users = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'beaubot_conversation'"
        );
        $archived_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p 
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                 WHERE p.post_type = 'beaubot_conversation' 
                 AND pm.meta_key = '_beaubot_archived' AND pm.meta_value = %s",
                '1'
            )
        );
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Total des conversations', 'beaubot'); ?></th>
                <td><strong><?php echo esc_html($total_conversations); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Conversations archivées', 'beaubot'); ?></th>
                <td><strong><?php echo esc_html($archived_count); ?></strong></td>
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
