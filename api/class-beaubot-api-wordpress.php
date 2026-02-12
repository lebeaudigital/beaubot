<?php
/**
 * Classe API WordPress de BeauBot
 * 
 * Récupère le contenu des pages via l'API REST WordPress
 * au lieu de l'indexation locale.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_API_WordPress {

    /**
     * URLs de base des API WordPress REST
     * @var array
     */
    private array $api_base_urls;

    /**
     * Durée du cache en secondes (1 heure par défaut)
     */
    private const CACHE_DURATION = 3600;

    /**
     * Clé du transient pour le cache
     */
    private const CACHE_KEY = 'beaubot_wp_api_pages_cache';

    /**
     * Nombre maximum de tokens pour le contexte
     */
    private const MAX_CONTEXT_TOKENS = 20000;

    /**
     * Map des IDs parents pour la hiérarchie
     * @var array
     */
    private array $parent_map = [];

    /**
     * Instance unique
     * @var BeauBot_API_WordPress|null
     */
    private static ?BeauBot_API_WordPress $instance = null;

    /**
     * Obtenir l'instance unique
     * @return BeauBot_API_WordPress
     */
    public static function get_instance(): BeauBot_API_WordPress {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    public function __construct() {
        $options = get_option('beaubot_settings', []);
        $urls = $options['wp_api_urls'] ?? ['https://ifip.lebeaudigital.fr/memento/wp-json/wp/v2'];
        
        // S'assurer que c'est un tableau et supprimer les slashs finaux
        if (!is_array($urls)) {
            $urls = [$urls];
        }
        
        $this->api_base_urls = array_map(function($url) {
            return rtrim(trim($url), '/');
        }, array_filter($urls));
        
        // Fallback si aucune URL
        if (empty($this->api_base_urls)) {
            $this->api_base_urls = ['https://ifip.lebeaudigital.fr/memento/wp-json/wp/v2'];
        }
    }

    /**
     * Obtenir le contexte du site pour ChatGPT via l'API WordPress
     * @param string|null $query Question de l'utilisateur (réservé pour usage futur)
     * @return string
     */
    public function get_site_context(?string $query = null): string {
        error_log("[BeauBot WP API] get_site_context called");

        // Vérifier le cache
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            error_log("[BeauBot WP API] Using cached context (" . strlen($cached) . " chars)");
            return $this->truncate_context($cached);
        }

        // Récupérer les pages de toutes les sources API
        $all_pages = $this->fetch_all_sources();

        if (empty($all_pages)) {
            error_log("[BeauBot WP API] No pages returned from any API source");
            return '';
        }

        error_log("[BeauBot WP API] Total pages from all sources: " . count($all_pages));

        // Construire la map des parents pour la hiérarchie
        $this->build_parent_map($all_pages);

        // Formater le contenu
        $context = $this->format_pages_context($all_pages);

        // Mettre en cache
        set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);

        error_log("[BeauBot WP API] Context generated: " . strlen($context) . " chars");

        return $this->truncate_context($context);
    }

    /**
     * Récupérer les pages de toutes les sources API configurées
     * @return array
     */
    private function fetch_all_sources(): array {
        $all_pages = [];

        foreach ($this->api_base_urls as $api_url) {
            error_log("[BeauBot WP API] Fetching from source: {$api_url}");
            
            $pages = $this->fetch_pages_from_url($api_url);
            
            if (is_wp_error($pages)) {
                error_log("[BeauBot WP API] Error for {$api_url}: " . $pages->get_error_message());
                // Continuer avec les autres sources même si une échoue
                continue;
            }

            error_log("[BeauBot WP API] Got " . count($pages) . " pages from {$api_url}");
            
            // Ajouter la source à chaque page pour le contexte
            foreach ($pages as &$page) {
                $page['_source_api'] = $api_url;
            }
            unset($page);

            $all_pages = array_merge($all_pages, $pages);
        }

        return $all_pages;
    }

    /**
     * Récupérer toutes les pages d'une URL API WordPress REST (avec pagination)
     * @param string $api_base_url
     * @return array|WP_Error
     */
    private function fetch_pages_from_url(string $api_base_url): array|WP_Error {
        $all_pages = [];
        $page_num = 1;
        $per_page = 100; // Maximum autorisé par l'API WP REST

        do {
            $url = $api_base_url . '/pages?' . http_build_query([
                'per_page' => $per_page,
                'page'     => $page_num,
                'status'   => 'publish',
                'orderby'  => 'menu_order',
                'order'    => 'asc',
                '_fields'  => 'id,title,content,link,parent,slug,menu_order',
            ]);

            error_log("[BeauBot WP API] Fetching page {$page_num}: {$url}");

            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if (is_wp_error($response)) {
                error_log("[BeauBot WP API] HTTP Error: " . $response->get_error_message());
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                $body = wp_remote_retrieve_body($response);
                error_log("[BeauBot WP API] API returned status {$status_code}: {$body}");
                return new WP_Error(
                    'wp_api_error',
                    sprintf(__('L\'API WordPress (%s) a retourné le code %d', 'beaubot'), $api_base_url, $status_code)
                );
            }

            $body = wp_remote_retrieve_body($response);
            $pages = json_decode($body, true);

            if (!is_array($pages)) {
                error_log("[BeauBot WP API] Invalid JSON response");
                return new WP_Error('wp_api_error', __('Réponse JSON invalide de l\'API WordPress', 'beaubot'));
            }

            $all_pages = array_merge($all_pages, $pages);

            // Vérifier s'il y a d'autres pages
            $total_pages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');
            
            error_log("[BeauBot WP API] Page {$page_num}/{$total_pages}, got " . count($pages) . " items");

            $page_num++;
        } while ($page_num <= $total_pages);

        return $all_pages;
    }

    /**
     * Formater les pages en contexte texte pour ChatGPT
     * @param array $pages
     * @return string
     */
    private function format_pages_context(array $pages): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $content = "=== INFORMATIONS DU SITE ===\n";
        $content .= "Nom: {$site_name}\n";
        $content .= "URL: {$site_url}\n";
        $content .= "Sources: " . count($this->api_base_urls) . " API(s) WordPress REST\n\n";

        // Construire un index parent → titre pour le breadcrumb
        $titles_by_id = [];
        foreach ($pages as $page) {
            $page_id = $page['id'] ?? 0;
            $page_title = $page['title']['rendered'] ?? '';
            $titles_by_id[$page_id] = html_entity_decode($page_title, ENT_QUOTES, 'UTF-8');
        }

        $content .= "=== PAGES DU SITE ===\n\n";

        foreach ($pages as $page) {
            $title = html_entity_decode($page['title']['rendered'] ?? 'Sans titre', ENT_QUOTES, 'UTF-8');
            $url = $page['link'] ?? '';
            $raw_content = $page['content']['rendered'] ?? '';
            $parent_id = $page['parent'] ?? 0;

            // Nettoyer le contenu HTML
            $clean = $this->clean_html_content($raw_content);

            // Construire le breadcrumb
            $breadcrumb = $this->build_breadcrumb($page, $titles_by_id);

            $content .= "[Page] {$title}\n";

            if (!empty($breadcrumb)) {
                $content .= "Section: {$breadcrumb}\n";
            }
            if ($parent_id && isset($titles_by_id[$parent_id])) {
                $content .= "Page parente: {$titles_by_id[$parent_id]}\n";
            }

            $content .= "URL: {$url}\n";

            if (!empty($clean)) {
                // Limiter la longueur par page
                if (strlen($clean) > 15000) {
                    $clean = substr($clean, 0, 15000) . '... [contenu tronqué]';
                }
                $content .= "Contenu:\n{$clean}\n";
            }

            $content .= str_repeat('-', 50) . "\n\n";
        }

        return $content;
    }

    /**
     * Construire le breadcrumb d'une page à partir des données de l'API
     * @param array $page
     * @param array $titles_by_id
     * @return string
     */
    private function build_breadcrumb(array $page, array $titles_by_id): string {
        $parts = [];
        $parent_id = $page['parent'] ?? 0;

        // Remonter la hiérarchie des parents
        $visited = [];
        while ($parent_id > 0 && isset($titles_by_id[$parent_id]) && !in_array($parent_id, $visited)) {
            $visited[] = $parent_id;
            array_unshift($parts, $titles_by_id[$parent_id]);

            // Chercher le parent du parent dans les pages
            $parent_id = $this->find_parent_id($parent_id, $titles_by_id);
        }

        // Ajouter le titre de la page courante
        $current_title = html_entity_decode($page['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8');
        if (!empty($current_title)) {
            $parts[] = $current_title;
        }

        return count($parts) > 1 ? implode(' > ', $parts) : '';
    }

    /**
     * Trouver l'ID parent d'une page via la map en mémoire
     * @param int $page_id
     * @param array $titles_by_id
     * @return int
     */
    private function find_parent_id(int $page_id, array $titles_by_id): int {
        return $this->parent_map[$page_id] ?? 0;
    }

    /**
     * Stocker la map des parents lors du formatage
     * @param array $pages
     */
    private function build_parent_map(array $pages): void {
        $this->parent_map = [];
        foreach ($pages as $page) {
            $this->parent_map[$page['id'] ?? 0] = $page['parent'] ?? 0;
        }
    }

    /**
     * Nettoyer le contenu HTML renvoyé par l'API
     * @param string $html
     * @return string
     */
    private function clean_html_content(string $html): string {
        // Supprimer les scripts et styles
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // Supprimer les commentaires HTML (y compris Gutenberg)
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convertir les balises de bloc en retours à la ligne
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|br|blockquote|figure|figcaption)>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Supprimer le HTML restant
        $html = wp_strip_all_tags($html);

        // Décoder les entités HTML
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // Supprimer les espaces multiples (garder les retours à la ligne)
        $html = preg_replace('/[ \t]+/', ' ', $html);

        // Supprimer les lignes vides multiples
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        // Nettoyer les espaces en début/fin de ligne
        $html = preg_replace('/^ +| +$/m', '', $html);

        return trim($html);
    }

    /**
     * Estimer le nombre de tokens
     * @param string $text
     * @return int
     */
    private function estimate_tokens(string $text): int {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Tronquer le contexte pour respecter la limite de tokens
     * @param string $context
     * @return string
     */
    private function truncate_context(string $context): string {
        $current_tokens = $this->estimate_tokens($context);

        if ($current_tokens <= self::MAX_CONTEXT_TOKENS) {
            return $context;
        }

        $max_chars = self::MAX_CONTEXT_TOKENS * 4;
        $truncated = substr($context, 0, $max_chars);

        // Couper proprement à la dernière phrase complète
        $last_period = strrpos($truncated, '.');
        if ($last_period !== false && $last_period > $max_chars * 0.8) {
            $truncated = substr($truncated, 0, $last_period + 1);
        }

        return $truncated . "\n\n[Contenu tronqué pour respecter les limites]";
    }

    /**
     * Vider le cache des pages
     * @return bool
     */
    public function clear_cache(): bool {
        error_log("[BeauBot WP API] Cache cleared");
        return delete_transient(self::CACHE_KEY);
    }

    /**
     * Forcer le rafraîchissement du contexte
     * @return array
     */
    public function force_refresh(): array {
        $start = microtime(true);
        
        $this->clear_cache();
        
        $pages = $this->fetch_all_sources();
        $duration = round(microtime(true) - $start, 2);

        if (empty($pages)) {
            return [
                'success' => false,
                'message' => __('Aucune page récupérée. Vérifiez les URLs des API.', 'beaubot'),
            ];
        }

        $this->build_parent_map($pages);
        $context = $this->format_pages_context($pages);
        set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);

        $size = round(strlen($context) / 1024, 2);
        $sources_count = count($this->api_base_urls);

        return [
            'success' => true,
            'message' => sprintf(
                __('%d pages récupérées depuis %d source(s) en %ss (%s Ko)', 'beaubot'),
                count($pages),
                $sources_count,
                $duration,
                $size
            ),
            'count'    => count($pages),
            'sources'  => $sources_count,
            'size'     => $size,
            'duration' => $duration,
        ];
    }

    /**
     * Obtenir les statistiques du cache
     * @return array
     */
    public function get_cache_stats(): array {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached === false) {
            return [
                'cached'  => false,
                'message' => __('Aucun cache disponible', 'beaubot'),
            ];
        }

        return [
            'cached'   => true,
            'size'     => round(strlen($cached) / 1024, 2),
            'api_urls' => $this->api_base_urls,
        ];
    }
}
