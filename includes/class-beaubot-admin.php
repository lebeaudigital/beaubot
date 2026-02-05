<?php
/**
 * Classe d'administration de BeauBot
 * 
 * Gère la page de paramètres et les options du plugin dans l'admin WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Admin {
    
    /**
     * Instance unique
     * @var BeauBot_Admin|null
     */
    private static ?BeauBot_Admin $instance = null;

    /**
     * Slug de la page de paramètres
     */
    private const MENU_SLUG = 'beaubot-settings';

    /**
     * Groupe d'options
     */
    private const OPTION_GROUP = 'beaubot_settings_group';

    /**
     * Nom de l'option
     */
    private const OPTION_NAME = 'beaubot_settings';

    /**
     * Obtenir l'instance unique
     * @return BeauBot_Admin
     */
    public static function get_instance(): BeauBot_Admin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur privé
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX pour tester l'API
        add_action('wp_ajax_beaubot_test_api', [$this, 'ajax_test_api']);
        
        // AJAX pour régénérer l'index
        add_action('wp_ajax_beaubot_reindex', [$this, 'ajax_reindex']);
    }

    /**
     * Tester la connexion API via AJAX
     */
    public function ajax_test_api(): void {
        check_ajax_referer('beaubot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'beaubot')]);
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Clé API manquante.', 'beaubot')]);
        }
        
        // Test direct avec la clé fournie
        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            wp_send_json_success(['message' => __('Connexion réussie !', 'beaubot')]);
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $body['error']['message'] ?? __('Erreur de connexion', 'beaubot');
            wp_send_json_error(['message' => $error_message]);
        }
    }

    /**
     * Régénérer l'index du contenu via AJAX
     */
    public function ajax_reindex(): void {
        check_ajax_referer('beaubot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès non autorisé.', 'beaubot')]);
        }
        
        $indexer = new BeauBot_Content_Indexer();
        $result = $indexer->force_reindex();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Ajouter le menu admin
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('BeauBot Settings', 'beaubot'),
            __('BeauBot', 'beaubot'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-format-chat',
            30
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Conversations', 'beaubot'),
            __('Conversations', 'beaubot'),
            'manage_options',
            'beaubot-conversations',
            [$this, 'render_conversations_page']
        );
    }

    /**
     * Enregistrer les paramètres
     */
    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->get_default_settings(),
            ]
        );

        // Section API
        add_settings_section(
            'beaubot_api_section',
            __('Configuration API OpenAI', 'beaubot'),
            [$this, 'render_api_section'],
            self::MENU_SLUG
        );

        // Clé API
        add_settings_field(
            'api_key',
            __('Clé API OpenAI', 'beaubot'),
            [$this, 'render_api_key_field'],
            self::MENU_SLUG,
            'beaubot_api_section'
        );

        // Modèle
        add_settings_field(
            'model',
            __('Modèle ChatGPT', 'beaubot'),
            [$this, 'render_model_field'],
            self::MENU_SLUG,
            'beaubot_api_section'
        );

        // Section Interface
        add_settings_section(
            'beaubot_interface_section',
            __('Configuration Interface', 'beaubot'),
            [$this, 'render_interface_section'],
            self::MENU_SLUG
        );

        // Position sidebar
        add_settings_field(
            'sidebar_position',
            __('Position de la sidebar', 'beaubot'),
            [$this, 'render_sidebar_position_field'],
            self::MENU_SLUG,
            'beaubot_interface_section'
        );

        // Section Avancée
        add_settings_section(
            'beaubot_advanced_section',
            __('Paramètres Avancés', 'beaubot'),
            [$this, 'render_advanced_section'],
            self::MENU_SLUG
        );

        // Max tokens
        add_settings_field(
            'max_tokens',
            __('Tokens maximum', 'beaubot'),
            [$this, 'render_max_tokens_field'],
            self::MENU_SLUG,
            'beaubot_advanced_section'
        );

        // Température
        add_settings_field(
            'temperature',
            __('Température', 'beaubot'),
            [$this, 'render_temperature_field'],
            self::MENU_SLUG,
            'beaubot_advanced_section'
        );

        // System prompt
        add_settings_field(
            'system_prompt',
            __('Prompt système', 'beaubot'),
            [$this, 'render_system_prompt_field'],
            self::MENU_SLUG,
            'beaubot_advanced_section'
        );
    }

    /**
     * Obtenir les paramètres par défaut
     * @return array
     */
    private function get_default_settings(): array {
        return [
            'api_key' => '',
            'model' => 'gpt-4o',
            'sidebar_position' => 'right',
            'max_tokens' => 1000,
            'temperature' => 0.7,
            'system_prompt' => __('Tu es un assistant virtuel pour ce site web. Tu réponds aux questions en te basant sur le contenu du site.', 'beaubot'),
        ];
    }

    /**
     * Sanitiser les paramètres
     * @param array $input
     * @return array
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];
        
        $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $sanitized['model'] = sanitize_text_field($input['model'] ?? 'gpt-4o');
        $sanitized['sidebar_position'] = in_array($input['sidebar_position'] ?? '', ['left', 'right']) 
            ? $input['sidebar_position'] 
            : 'right';
        $sanitized['max_tokens'] = absint($input['max_tokens'] ?? 1000);
        $sanitized['temperature'] = floatval($input['temperature'] ?? 0.7);
        $sanitized['system_prompt'] = sanitize_textarea_field($input['system_prompt'] ?? '');
        
        // Valider les limites
        $sanitized['max_tokens'] = max(100, min(4000, $sanitized['max_tokens']));
        $sanitized['temperature'] = max(0, min(2, $sanitized['temperature']));
        
        return $sanitized;
    }

    /**
     * Charger les assets admin
     * @param string $hook
     */
    public function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'beaubot') === false) {
            return;
        }

        wp_enqueue_style(
            'beaubot-admin',
            BEAUBOT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BEAUBOT_VERSION
        );

        wp_enqueue_script(
            'beaubot-admin',
            BEAUBOT_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            BEAUBOT_VERSION,
            true
        );

        wp_localize_script('beaubot-admin', 'beaubotAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('beaubot_admin_nonce'),
            'strings' => [
                'confirmDelete' => __('Êtes-vous sûr de vouloir supprimer cette conversation ?', 'beaubot'),
                'testSuccess' => __('Connexion à l\'API réussie !', 'beaubot'),
                'testError' => __('Erreur de connexion à l\'API', 'beaubot'),
            ],
        ]);
    }

    /**
     * Rendre la page de paramètres
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Afficher les messages de succès/erreur
        settings_errors('beaubot_messages');
        
        include BEAUBOT_PLUGIN_DIR . 'templates/admin/settings-page.php';
    }

    /**
     * Rendre la page des conversations
     */
    public function render_conversations_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        include BEAUBOT_PLUGIN_DIR . 'templates/admin/conversations-page.php';
    }

    // Méthodes de rendu des sections
    
    public function render_api_section(): void {
        echo '<p>' . esc_html__('Configurez votre connexion à l\'API OpenAI.', 'beaubot') . '</p>';
    }

    public function render_interface_section(): void {
        echo '<p>' . esc_html__('Personnalisez l\'apparence du chatbot.', 'beaubot') . '</p>';
    }

    public function render_advanced_section(): void {
        echo '<p>' . esc_html__('Paramètres avancés pour le comportement de l\'IA.', 'beaubot') . '</p>';
    }

    // Méthodes de rendu des champs

    public function render_api_key_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['api_key'] ?? '';
        ?>
        <input type="password" 
               id="beaubot_api_key" 
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[api_key]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               autocomplete="off">
        <button type="button" class="button" id="beaubot-toggle-api-key">
            <?php esc_html_e('Afficher', 'beaubot'); ?>
        </button>
        <button type="button" class="button" id="beaubot-test-api">
            <?php esc_html_e('Tester la connexion', 'beaubot'); ?>
        </button>
        <p class="description">
            <?php esc_html_e('Votre clé API OpenAI. Vous pouvez la trouver sur platform.openai.com', 'beaubot'); ?>
        </p>
        <?php
    }

    public function render_model_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['model'] ?? 'gpt-4o';
        $models = [
            'gpt-4o' => 'GPT-4o (Recommandé)',
            'gpt-4o-mini' => 'GPT-4o Mini (Économique)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        ];
        ?>
        <select id="beaubot_model" name="<?php echo esc_attr(self::OPTION_NAME); ?>[model]">
            <?php foreach ($models as $model_id => $model_name): ?>
                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($value, $model_id); ?>>
                    <?php echo esc_html($model_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('GPT-4o est recommandé pour l\'analyse d\'images.', 'beaubot'); ?>
        </p>
        <?php
    }

    public function render_sidebar_position_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['sidebar_position'] ?? 'right';
        ?>
        <label>
            <input type="radio" 
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[sidebar_position]" 
                   value="left" 
                   <?php checked($value, 'left'); ?>>
            <?php esc_html_e('Gauche', 'beaubot'); ?>
        </label>
        <label style="margin-left: 20px;">
            <input type="radio" 
                   name="<?php echo esc_attr(self::OPTION_NAME); ?>[sidebar_position]" 
                   value="right" 
                   <?php checked($value, 'right'); ?>>
            <?php esc_html_e('Droite', 'beaubot'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Position par défaut de la sidebar. Les utilisateurs peuvent la changer.', 'beaubot'); ?>
        </p>
        <?php
    }

    public function render_max_tokens_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['max_tokens'] ?? 1000;
        ?>
        <input type="number" 
               id="beaubot_max_tokens" 
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[max_tokens]" 
               value="<?php echo esc_attr($value); ?>" 
               min="100" 
               max="4000" 
               step="100"
               class="small-text">
        <p class="description">
            <?php esc_html_e('Nombre maximum de tokens pour les réponses (100-4000).', 'beaubot'); ?>
        </p>
        <?php
    }

    public function render_temperature_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['temperature'] ?? 0.7;
        ?>
        <input type="range" 
               id="beaubot_temperature" 
               name="<?php echo esc_attr(self::OPTION_NAME); ?>[temperature]" 
               value="<?php echo esc_attr($value); ?>" 
               min="0" 
               max="2" 
               step="0.1"
               class="beaubot-range">
        <span id="beaubot_temperature_value"><?php echo esc_html($value); ?></span>
        <p class="description">
            <?php esc_html_e('0 = Réponses précises, 2 = Réponses créatives.', 'beaubot'); ?>
        </p>
        <?php
    }

    public function render_system_prompt_field(): void {
        $options = get_option(self::OPTION_NAME);
        $value = $options['system_prompt'] ?? '';
        ?>
        <textarea id="beaubot_system_prompt" 
                  name="<?php echo esc_attr(self::OPTION_NAME); ?>[system_prompt]" 
                  rows="5" 
                  class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('Instructions données à l\'IA pour définir son comportement.', 'beaubot'); ?>
        </p>
        <?php
    }
}
