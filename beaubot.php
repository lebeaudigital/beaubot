<?php
/**
 * Plugin Name: BeauBot - ChatGPT Assistant
 * Plugin URI: https://github.com/lebeaudigital/beaubot
 * Description: Un chatbot intelligent alimenté par ChatGPT qui répond aux questions sur le contenu de votre site WordPress.
 * Version: 1.0.3
 * Author: LeBeauDigital
 * Author URI: https://github.com/lebeaudigital
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beaubot
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0 +
 * GitHub Plugin URI: lebeaudigital/beaubot
 * GitHub Branch: main
 */

 // https://github.com/lebeaudigital/beaubot/archive/refs/heads/main.zip
 // https://github.com/lebeaudigital/beaubot/releases/new

// Empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Constantes du plugin
define('BEAUBOT_VERSION', '1.0.3');
define('BEAUBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BEAUBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BEAUBOT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principale du plugin BeauBot
 */
class BeauBot {
    
    /**
     * Instance unique du plugin
     * @var BeauBot|null
     */
    private static ?BeauBot $instance = null;

    /**
     * Obtenir l'instance unique du plugin (Singleton)
     * @return BeauBot
     */
    public static function get_instance(): BeauBot {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur privé
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Charger les dépendances
     */
    private function load_dependencies(): void {
        // Classes principales
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-admin.php';
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-frontend.php';
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-conversation.php';
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-image.php';
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-content-indexer.php';
        require_once BEAUBOT_PLUGIN_DIR . 'includes/class-beaubot-updater.php';
        
        // Classes API
        require_once BEAUBOT_PLUGIN_DIR . 'api/class-beaubot-api-chatgpt.php';
        require_once BEAUBOT_PLUGIN_DIR . 'api/class-beaubot-api-endpoints.php';
    }

    /**
     * Initialiser les hooks
     */
    private function init_hooks(): void {
        // Activation/Désactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialisation
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        // Admin
        if (is_admin()) {
            BeauBot_Admin::get_instance();
            // Initialiser le système de mise à jour GitHub
            BeauBot_Updater::get_instance();
        }
        
        // Frontend (uniquement pour les utilisateurs connectés)
        add_action('wp', function() {
            if (is_user_logged_in()) {
                BeauBot_Frontend::get_instance();
            }
        });
        
        // API REST
        add_action('rest_api_init', function() {
            BeauBot_API_Endpoints::get_instance()->register_routes();
        });
        
        // Cron pour nettoyer les images éphémères
        add_action('beaubot_cleanup_images', [BeauBot_Image::class, 'cleanup_expired_images']);
    }

    /**
     * Initialisation
     */
    public function init(): void {
        // Enregistrer le type de post pour les conversations
        $this->register_conversation_post_type();
        
        // Vérifier si les tables existent, sinon les créer
        $this->maybe_create_tables();
    }
    
    /**
     * Vérifier et créer les tables si nécessaire
     */
    private function maybe_create_tables(): void {
        global $wpdb;
        
        // Vérifier si la table messages existe
        $table_messages = $wpdb->prefix . 'beaubot_messages';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_messages
        ));
        
        if (!$table_exists) {
            error_log("[BeauBot] Tables not found, creating them now...");
            $this->create_tables();
            error_log("[BeauBot] Tables created successfully.");
        }
    }

    /**
     * Charger les traductions
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'beaubot',
            false,
            dirname(BEAUBOT_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Enregistrer le type de post pour les conversations
     */
    private function register_conversation_post_type(): void {
        register_post_type('beaubot_conversation', [
            'labels' => [
                'name' => __('Conversations', 'beaubot'),
                'singular_name' => __('Conversation', 'beaubot'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title', 'author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    /**
     * Activation du plugin
     */
    public function activate(): void {
        // Créer les tables personnalisées
        $this->create_tables();
        
        // Créer le dossier pour les images temporaires
        $upload_dir = wp_upload_dir();
        $beaubot_dir = $upload_dir['basedir'] . '/beaubot-temp';
        if (!file_exists($beaubot_dir)) {
            wp_mkdir_p($beaubot_dir);
            // Ajouter un fichier .htaccess pour protéger le dossier
            file_put_contents($beaubot_dir . '/.htaccess', 'Options -Indexes');
        }
        
        // Planifier le cron pour le nettoyage des images
        if (!wp_next_scheduled('beaubot_cleanup_images')) {
            wp_schedule_event(time(), 'hourly', 'beaubot_cleanup_images');
        }
        
        // Options par défaut
        $default_options = [
            'api_key' => '',
            'model' => 'gpt-4o',
            'sidebar_position' => 'right',
            'max_tokens' => 1000,
            'temperature' => 0.7,
            'system_prompt' => __('Tu es un assistant virtuel pour ce site web. Tu réponds aux questions en te basant sur le contenu du site.', 'beaubot'),
        ];
        
        if (!get_option('beaubot_settings')) {
            add_option('beaubot_settings', $default_options);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Désactivation du plugin
     */
    public function deactivate(): void {
        // Supprimer le cron
        wp_clear_scheduled_hook('beaubot_cleanup_images');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Créer les tables personnalisées
     */
    private function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table des messages de conversation
        $table_messages = $wpdb->prefix . 'beaubot_messages';
        $sql_messages = "CREATE TABLE $table_messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'user',
            content longtext NOT NULL,
            image_url varchar(500) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Table des images temporaires
        $table_images = $wpdb->prefix . 'beaubot_temp_images';
        $sql_images = "CREATE TABLE $table_images (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Table pour le cache du contenu indexé
        $table_content = $wpdb->prefix . 'beaubot_content_cache';
        $sql_content = "CREATE TABLE $table_content (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            content_hash varchar(64) NOT NULL,
            indexed_content longtext NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY content_hash (content_hash)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_messages);
        dbDelta($sql_images);
        dbDelta($sql_content);
    }
}

// Initialiser le plugin
add_action('plugins_loaded', function() {
    BeauBot::get_instance();
}, 0);
