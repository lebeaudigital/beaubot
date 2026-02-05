<?php
/**
 * Classe de gestion des conversations BeauBot
 * 
 * Gère la création, récupération et archivage des conversations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Conversation {
    
    /**
     * Table des messages
     * @var string
     */
    private string $table_messages;

    /**
     * Constructeur
     */
    public function __construct() {
        global $wpdb;
        $this->table_messages = $wpdb->prefix . 'beaubot_messages';
    }

    /**
     * Créer une nouvelle conversation
     * @param int $user_id
     * @param string $title
     * @return int|WP_Error
     */
    public function create(int $user_id, string $title = ''): int|WP_Error {
        if (empty($title)) {
            $title = __('Nouvelle conversation', 'beaubot') . ' - ' . current_time('d/m/Y H:i');
        }

        $conversation_id = wp_insert_post([
            'post_type' => 'beaubot_conversation',
            'post_title' => $title,
            'post_status' => 'publish',
            'post_author' => $user_id,
        ], true);

        if (is_wp_error($conversation_id)) {
            return $conversation_id;
        }

        // Métadonnées
        update_post_meta($conversation_id, '_beaubot_archived', 0);
        update_post_meta($conversation_id, '_beaubot_message_count', 0);

        return $conversation_id;
    }

    /**
     * Obtenir une conversation par ID
     * @param int $conversation_id
     * @param int $user_id
     * @return array|null
     */
    public function get(int $conversation_id, int $user_id): ?array {
        $post = get_post($conversation_id);
        
        if (!$post || $post->post_type !== 'beaubot_conversation') {
            return null;
        }

        // Vérifier que l'utilisateur est propriétaire
        if ((int) $post->post_author !== $user_id) {
            return null;
        }

        return $this->format_conversation($post);
    }

    /**
     * Obtenir les conversations d'un utilisateur
     * @param int $user_id
     * @param bool $archived
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_user_conversations(
        int $user_id, 
        bool $archived = false, 
        int $limit = 20, 
        int $offset = 0
    ): array {
        $args = [
            'post_type' => 'beaubot_conversation',
            'author' => $user_id,
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_beaubot_archived',
                    'value' => $archived ? 1 : 0,
                    'compare' => '=',
                ],
            ],
        ];

        $query = new WP_Query($args);
        $conversations = [];

        foreach ($query->posts as $post) {
            $conversations[] = $this->format_conversation($post);
        }

        return $conversations;
    }

    /**
     * Formater une conversation
     * @param WP_Post $post
     * @return array
     */
    private function format_conversation(WP_Post $post): array {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'created_at' => $post->post_date,
            'updated_at' => $post->post_modified,
            'archived' => (bool) get_post_meta($post->ID, '_beaubot_archived', true),
            'message_count' => (int) get_post_meta($post->ID, '_beaubot_message_count', true),
        ];
    }

    /**
     * Mettre à jour le titre d'une conversation
     * @param int $conversation_id
     * @param string $title
     * @param int $user_id
     * @return bool
     */
    public function update_title(int $conversation_id, string $title, int $user_id): bool {
        $conversation = $this->get($conversation_id, $user_id);
        
        if (!$conversation) {
            return false;
        }

        $result = wp_update_post([
            'ID' => $conversation_id,
            'post_title' => sanitize_text_field($title),
        ]);

        return !is_wp_error($result);
    }

    /**
     * Archiver/Désarchiver une conversation
     * @param int $conversation_id
     * @param int $user_id
     * @param bool $archive
     * @return bool
     */
    public function archive(int $conversation_id, int $user_id, bool $archive = true): bool {
        $conversation = $this->get($conversation_id, $user_id);
        
        if (!$conversation) {
            return false;
        }

        update_post_meta($conversation_id, '_beaubot_archived', $archive ? 1 : 0);
        return true;
    }

    /**
     * Supprimer une conversation
     * @param int $conversation_id
     * @param int $user_id
     * @return bool
     */
    public function delete(int $conversation_id, int $user_id): bool {
        $conversation = $this->get($conversation_id, $user_id);
        
        if (!$conversation) {
            return false;
        }

        // Supprimer les messages associés
        $this->delete_messages($conversation_id);

        // Supprimer la conversation
        return wp_delete_post($conversation_id, true) !== false;
    }

    /**
     * Ajouter un message à une conversation
     * @param int $conversation_id
     * @param int $user_id
     * @param string $role
     * @param string $content
     * @param string|null $image_url
     * @return int|false
     */
    public function add_message(
        int $conversation_id, 
        int $user_id, 
        string $role, 
        string $content, 
        ?string $image_url = null
    ): int|false {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_messages,
            [
                'conversation_id' => $conversation_id,
                'user_id' => $user_id,
                'role' => $role,
                'content' => $content,
                'image_url' => $image_url,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return false;
        }

        // Mettre à jour le compteur et la date de modification
        $count = (int) get_post_meta($conversation_id, '_beaubot_message_count', true);
        update_post_meta($conversation_id, '_beaubot_message_count', $count + 1);
        
        wp_update_post([
            'ID' => $conversation_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
        ]);

        // Générer un titre automatique si c'est le premier message utilisateur
        if ($role === 'user' && $count === 0) {
            $this->generate_title($conversation_id, $content, $user_id);
        }

        return $wpdb->insert_id;
    }

    /**
     * Générer un titre automatique basé sur le premier message
     * @param int $conversation_id
     * @param string $content
     * @param int $user_id
     */
    private function generate_title(int $conversation_id, string $content, int $user_id): void {
        // Prendre les 50 premiers caractères du message
        $title = wp_trim_words($content, 8, '...');
        if (empty($title)) {
            $title = __('Conversation', 'beaubot') . ' #' . $conversation_id;
        }
        $this->update_title($conversation_id, $title, $user_id);
    }

    /**
     * Obtenir les messages d'une conversation
     * @param int $conversation_id
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_messages(
        int $conversation_id, 
        int $user_id, 
        int $limit = 50, 
        int $offset = 0
    ): array {
        global $wpdb;

        // Vérifier l'accès
        $conversation = $this->get($conversation_id, $user_id);
        if (!$conversation) {
            return [];
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_messages} 
                WHERE conversation_id = %d 
                ORDER BY created_at ASC 
                LIMIT %d OFFSET %d",
                $conversation_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Obtenir tous les messages pour le contexte ChatGPT
     * @param int $conversation_id
     * @param int $user_id
     * @return array
     */
    public function get_messages_for_context(int $conversation_id, int $user_id): array {
        $messages = $this->get_messages($conversation_id, $user_id, 100, 0);
        $formatted = [];

        foreach ($messages as $message) {
            $formatted[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $formatted;
    }

    /**
     * Supprimer tous les messages d'une conversation
     * @param int $conversation_id
     * @return bool
     */
    private function delete_messages(int $conversation_id): bool {
        global $wpdb;

        return $wpdb->delete(
            $this->table_messages,
            ['conversation_id' => $conversation_id],
            ['%d']
        ) !== false;
    }
}
