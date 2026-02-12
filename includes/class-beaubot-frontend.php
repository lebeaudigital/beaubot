<?php
/**
 * Classe Frontend de BeauBot
 * 
 * Gère l'affichage de la sidebar chatbot côté frontend.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Frontend {
    
    /**
     * Instance unique
     * @var BeauBot_Frontend|null
     */
    private static ?BeauBot_Frontend $instance = null;

    /**
     * Options du plugin
     * @var array
     */
    private array $options;

    /**
     * Obtenir l'instance unique
     * @return BeauBot_Frontend
     */
    public static function get_instance(): BeauBot_Frontend {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur privé
     */
    private function __construct() {
        $this->options = get_option('beaubot_settings', []);
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chatbot']);
    }

    /**
     * Charger les assets frontend
     */
    public function enqueue_assets(): void {
        // CSS
        wp_enqueue_style(
            'beaubot-frontend',
            BEAUBOT_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            BEAUBOT_VERSION
        );

        // JS - Modules ES6
        wp_enqueue_script(
            'beaubot-sidebar',
            BEAUBOT_PLUGIN_URL . 'assets/js/sidebar.js',
            [],
            BEAUBOT_VERSION,
            true
        );

        wp_enqueue_script(
            'beaubot-file-upload',
            BEAUBOT_PLUGIN_URL . 'assets/js/file-upload.js',
            [],
            BEAUBOT_VERSION,
            true
        );

        wp_enqueue_script(
            'beaubot-conversation',
            BEAUBOT_PLUGIN_URL . 'assets/js/conversation.js',
            [],
            BEAUBOT_VERSION,
            true
        );

        wp_enqueue_script(
            'beaubot-chatbot',
            BEAUBOT_PLUGIN_URL . 'assets/js/chatbot.js',
            ['beaubot-sidebar', 'beaubot-file-upload', 'beaubot-conversation'],
            BEAUBOT_VERSION,
            true
        );

        // Variables JS
        wp_localize_script('beaubot-chatbot', 'beaubotConfig', $this->get_js_config());
    }

    /**
     * Obtenir la configuration JS
     * @return array
     */
    private function get_js_config(): array {
        $user = wp_get_current_user();
        $bot_name = $this->options['bot_name'] ?? 'BeauBot';
        
        // Préparer les profils utilisateur (seulement ceux avec un label)
        $user_profiles = $this->options['user_profiles'] ?? [];
        $profiles_for_js = [];
        foreach ($user_profiles as $profile) {
            if (!empty($profile['label'])) {
                $profiles_for_js[] = [
                    'label' => $profile['label'],
                    'level' => $profile['level'] ?? 'beginner',
                ];
            }
        }
        
        return [
            'restUrl' => rest_url('beaubot/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => $user->ID,
            'userName' => $user->display_name,
            'userAvatar' => get_avatar_url($user->ID, ['size' => 40]),
            'botName' => $bot_name,
            'sidebarPosition' => $this->options['sidebar_position'] ?? 'right',
            'maxFileSize' => wp_max_upload_size(),
            'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'userProfiles' => $profiles_for_js,
            'profileQuestion' => $this->options['profile_question'] ?? __('Quel est votre profil ?', 'beaubot'),
            'strings' => [
                'placeholder' => __('Posez votre question...', 'beaubot'),
                'send' => __('Envoyer', 'beaubot'),
                'uploadImage' => __('Ajouter une image', 'beaubot'),
                'newConversation' => __('Nouvelle conversation', 'beaubot'),
                'history' => __('Historique', 'beaubot'),
                'archive' => __('Archiver', 'beaubot'),
                'delete' => __('Supprimer', 'beaubot'),
                /* translators: %s is the bot name */
                'typing' => sprintf(__('%s réfléchit...', 'beaubot'), $bot_name),
                'error' => __('Une erreur est survenue. Veuillez réessayer.', 'beaubot'),
                'networkError' => __('Erreur de connexion. Vérifiez votre connexion internet.', 'beaubot'),
                'fileTooLarge' => __('Le fichier est trop volumineux.', 'beaubot'),
                'invalidFileType' => __('Type de fichier non autorisé. Utilisez JPG, PNG, GIF ou WebP.', 'beaubot'),
                'confirmDelete' => __('Êtes-vous sûr de vouloir supprimer cette conversation ?', 'beaubot'),
                'noConversations' => __('Aucune conversation', 'beaubot'),
                'today' => __('Aujourd\'hui', 'beaubot'),
                'yesterday' => __('Hier', 'beaubot'),
                'thisWeek' => __('Cette semaine', 'beaubot'),
                'older' => __('Plus ancien', 'beaubot'),
                'archived' => __('Archivées', 'beaubot'),
                'settings' => __('Paramètres', 'beaubot'),
                'positionLeft' => __('Sidebar à gauche', 'beaubot'),
                'positionRight' => __('Sidebar à droite', 'beaubot'),
            ],
        ];
    }

    /**
     * Rendre le chatbot
     */
    public function render_chatbot(): void {
        // Vérifier que l'utilisateur est connecté
        if (!is_user_logged_in()) {
            return;
        }

        // Vérifier que l'API est configurée
        if (empty($this->options['api_key'])) {
            return;
        }

        include BEAUBOT_PLUGIN_DIR . 'templates/frontend/chatbot-sidebar.php';
    }
}
