<?php
/**
 * Classe de mise à jour automatique via GitHub
 * 
 * Permet de vérifier et installer les mises à jour depuis un dépôt GitHub.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Updater {
    
    /**
     * Instance unique
     * @var BeauBot_Updater|null
     */
    private static ?BeauBot_Updater $instance = null;

    /**
     * Slug du plugin
     * @var string
     */
    private string $slug;

    /**
     * Chemin du fichier principal du plugin
     * @var string
     */
    private string $plugin_file;

    /**
     * Nom d'utilisateur GitHub
     * @var string
     */
    private string $github_username;

    /**
     * Nom du dépôt GitHub
     * @var string
     */
    private string $github_repo;

    /**
     * URL de l'API GitHub
     * @var string
     */
    private string $github_api_url;

    /**
     * Données du plugin
     * @var array
     */
    private array $plugin_data;

    /**
     * Réponse GitHub mise en cache
     * @var object|null
     */
    private ?object $github_response = null;

    /**
     * Token d'accès GitHub (optionnel, pour les repos privés)
     * @var string
     */
    private string $access_token;

    /**
     * Obtenir l'instance unique
     * @return BeauBot_Updater
     */
    public static function get_instance(): BeauBot_Updater {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur privé
     */
    private function __construct() {
        $this->slug = 'beaubot';
        $this->plugin_file = BEAUBOT_PLUGIN_BASENAME;
        $this->github_username = 'lebeaudigital';
        $this->github_repo = 'beaubot';
        $this->github_api_url = 'https://api.github.com/repos/' . $this->github_username . '/' . $this->github_repo;
        $this->access_token = ''; // Laisser vide pour un repo public
        
        // Récupérer les données du plugin
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data(BEAUBOT_PLUGIN_DIR . 'beaubot.php');

        $this->init_hooks();
    }

    /**
     * Initialiser les hooks WordPress
     */
    private function init_hooks(): void {
        // Vérifier les mises à jour
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        
        // Informations du plugin
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        
        // Après l'installation
        add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
        
        // Ajouter un lien dans la liste des plugins
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
    }

    /**
     * Récupérer les informations de la dernière release GitHub
     * @return object|null
     */
    private function get_github_release(): ?object {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        // Vérifier le cache transient
        $cached = get_transient('beaubot_github_release');
        if ($cached !== false) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        // Appel à l'API GitHub
        $url = $this->github_api_url . '/releases/latest';
        
        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'BeauBot-Updater',
            ],
        ];

        // Ajouter le token si configuré (pour les repos privés)
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || !isset($data->tag_name)) {
            return null;
        }

        // Mettre en cache pour 6 heures
        set_transient('beaubot_github_release', $data, 6 * HOUR_IN_SECONDS);
        
        $this->github_response = $data;
        return $this->github_response;
    }

    /**
     * Vérifier si une mise à jour est disponible
     * @param object $transient
     * @return object
     */
    public function check_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        
        if ($release === null) {
            return $transient;
        }

        // Extraire le numéro de version (enlever le 'v' si présent)
        $github_version = ltrim($release->tag_name, 'v');
        $current_version = $this->plugin_data['Version'] ?? BEAUBOT_VERSION;

        // Comparer les versions
        if (version_compare($github_version, $current_version, '>')) {
            $plugin_info = (object) [
                'slug' => $this->slug,
                'plugin' => $this->plugin_file,
                'new_version' => $github_version,
                'url' => 'https://github.com/' . $this->github_username . '/' . $this->github_repo,
                'package' => $this->get_download_url($release),
                'icons' => [
                    '1x' => BEAUBOT_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => BEAUBOT_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ],
                'banners' => [
                    'low' => BEAUBOT_PLUGIN_URL . 'assets/images/banner-772x250.png',
                    'high' => BEAUBOT_PLUGIN_URL . 'assets/images/banner-1544x500.png',
                ],
                'tested' => '6.4',
                'requires_php' => '7.4',
                'compatibility' => new stdClass(),
            ];

            $transient->response[$this->plugin_file] = $plugin_info;
        }

        return $transient;
    }

    /**
     * Obtenir l'URL de téléchargement
     * @param object $release
     * @return string
     */
    private function get_download_url(object $release): string {
        // Chercher un asset ZIP s'il existe
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Sinon, utiliser l'archive du code source
        return $release->zipball_url;
    }

    /**
     * Informations du plugin pour la popup de détails
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugin_info($result, string $action, object $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_github_release();
        
        if ($release === null) {
            return $result;
        }

        $github_version = ltrim($release->tag_name, 'v');

        $plugin_info = (object) [
            'name' => $this->plugin_data['Name'] ?? 'BeauBot',
            'slug' => $this->slug,
            'version' => $github_version,
            'author' => $this->plugin_data['Author'] ?? 'BeauBot Team',
            'author_profile' => 'https://github.com/' . $this->github_username,
            'homepage' => 'https://github.com/' . $this->github_username . '/' . $this->github_repo,
            'requires' => '5.8',
            'tested' => '6.4',
            'requires_php' => '7.4',
            'downloaded' => 0,
            'last_updated' => $release->published_at ?? '',
            'sections' => [
                'description' => $this->plugin_data['Description'] ?? '',
                'changelog' => $this->format_changelog($release),
                'installation' => $this->get_installation_instructions(),
            ],
            'download_link' => $this->get_download_url($release),
            'banners' => [
                'low' => BEAUBOT_PLUGIN_URL . 'assets/images/banner-772x250.png',
                'high' => BEAUBOT_PLUGIN_URL . 'assets/images/banner-1544x500.png',
            ],
        ];

        return $plugin_info;
    }

    /**
     * Formater le changelog depuis les notes de release
     * @param object $release
     * @return string
     */
    private function format_changelog(object $release): string {
        $changelog = '<h4>' . esc_html($release->tag_name) . '</h4>';
        
        if (!empty($release->body)) {
            // Convertir le Markdown basique en HTML
            $body = esc_html($release->body);
            $body = preg_replace('/^### (.+)$/m', '<h5>$1</h5>', $body);
            $body = preg_replace('/^## (.+)$/m', '<h4>$1</h4>', $body);
            $body = preg_replace('/^# (.+)$/m', '<h3>$1</h3>', $body);
            $body = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $body);
            $body = preg_replace('/^- (.+)$/m', '<li>$1</li>', $body);
            $body = nl2br($body);
            
            $changelog .= '<p>' . $body . '</p>';
        } else {
            $changelog .= '<p>' . __('Voir les détails sur GitHub.', 'beaubot') . '</p>';
        }

        $changelog .= '<p><a href="' . esc_url($release->html_url) . '" target="_blank">';
        $changelog .= __('Voir la release sur GitHub', 'beaubot');
        $changelog .= '</a></p>';

        return $changelog;
    }

    /**
     * Instructions d'installation
     * @return string
     */
    private function get_installation_instructions(): string {
        return '
            <ol>
                <li>' . __('Téléchargez le plugin depuis GitHub ou mettez à jour via WordPress', 'beaubot') . '</li>
                <li>' . __('Activez le plugin dans le menu Extensions', 'beaubot') . '</li>
                <li>' . __('Allez dans BeauBot > Paramètres pour configurer votre clé API OpenAI', 'beaubot') . '</li>
            </ol>
        ';
    }

    /**
     * Actions après l'installation de la mise à jour
     * @param bool $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function post_install(bool $response, array $hook_extra, array $result): array {
        global $wp_filesystem;

        // Vérifier si c'est notre plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $result;
        }

        // Le dossier peut avoir un nom différent après extraction
        $plugin_folder = WP_PLUGIN_DIR . '/' . $this->slug;
        
        // Renommer le dossier si nécessaire
        if ($result['destination'] !== $plugin_folder) {
            $wp_filesystem->move($result['destination'], $plugin_folder);
            $result['destination'] = $plugin_folder;
        }

        // Réactiver le plugin
        activate_plugin($this->plugin_file);

        // Vider le cache
        delete_transient('beaubot_github_release');

        return $result;
    }

    /**
     * Ajouter des liens dans la liste des plugins
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta(array $links, string $file): array {
        if ($file !== $this->plugin_file) {
            return $links;
        }

        $links[] = '<a href="https://github.com/' . $this->github_username . '/' . $this->github_repo . '" target="_blank">' . __('GitHub', 'beaubot') . '</a>';
        $links[] = '<a href="https://github.com/' . $this->github_username . '/' . $this->github_repo . '/issues" target="_blank">' . __('Signaler un bug', 'beaubot') . '</a>';

        return $links;
    }

    /**
     * Forcer la vérification des mises à jour
     */
    public static function force_check(): void {
        delete_transient('beaubot_github_release');
        delete_site_transient('update_plugins');
    }
}
