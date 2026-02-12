<?php
/**
 * Classe API WordPress de BeauBot
 * 
 * Récupère le contenu des pages pour le contexte ChatGPT :
 * - Contenu LOCAL : via WP_Query directement (fiable à 100%, sans HTTP)
 * - Contenu EXTERNE : via wp_remote_get() vers les API REST WordPress configurées
 * 
 * Détecte automatiquement si une URL configurée pointe vers le même site
 * pour éviter les requêtes HTTP loopback.
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
     * GPT-4o supporte 128K tokens, on utilise 60K pour laisser de la place
     * au system prompt, à l'historique de conversation et à la réponse.
     */
    private const MAX_CONTEXT_TOKENS = 60000;

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
        
        // S'assurer que c'est un tableau et nettoyer les URLs
        if (!is_array($urls)) {
            $urls = [$urls];
        }
        
        $this->api_base_urls = array_map(function($url) {
            $url = rtrim(trim($url), '/');
            // Supprimer /pages à la fin si l'utilisateur l'a ajouté par erreur
            $url = preg_replace('#/pages$#i', '', $url);
            return $url;
        }, array_filter($urls));
        
        // Fallback si aucune URL
        if (empty($this->api_base_urls)) {
            $this->api_base_urls = ['https://ifip.lebeaudigital.fr/memento/wp-json/wp/v2'];
        }
    }

    /**
     * Vérifier si une URL API pointe vers le même site WordPress (même installation)
     * Cela évite les requêtes HTTP loopback qui échouent souvent.
     * 
     * Détection par 2 méthodes :
     * 1. Comparaison domaine + chemin (même environnement)
     * 2. Comparaison du chemin seul (dev local vs production, ex:
     *    https://monsite.com/memento/wp-json/wp/v2 = http://localhost:8888/memento/wp-json/wp/v2)
     * 
     * @param string $api_url
     * @return bool
     */
    private function is_local_api(string $api_url): bool {
        $local_rest_url = rest_url('wp/v2');
        
        error_log("[BeauBot WP API] Comparing URLs - Configured: {$api_url} | Local: {$local_rest_url}");
        
        // Méthode 1 : comparaison complète sans protocole (même domaine)
        $normalize = function(string $url): string {
            $url = preg_replace('#^https?://#i', '', $url);
            return rtrim($url, '/');
        };
        
        if ($normalize($api_url) === $normalize($local_rest_url)) {
            error_log("[BeauBot WP API] URL locale détectée (même domaine): {$api_url}");
            return true;
        }
        
        // Méthode 2 : comparaison des chemins uniquement (dev local vs production)
        // Ex: /memento/wp-json/wp/v2 est identique sur localhost:8888 et monsite.com
        $get_path = function(string $url): string {
            $parsed = parse_url($url);
            return rtrim($parsed['path'] ?? '', '/');
        };
        
        $api_path = $get_path($api_url);
        $local_path = $get_path($local_rest_url);
        
        if (!empty($api_path) && str_contains($api_path, 'wp-json') && $api_path === $local_path) {
            error_log("[BeauBot WP API] URL locale détectée (même chemin WP): {$api_url} → path: {$api_path}");
            return true;
        }
        
        error_log("[BeauBot WP API] URL détectée comme EXTERNE: {$api_url}");
        return false;
    }

    /**
     * Obtenir le contexte du site pour ChatGPT via l'API WordPress
     * @param string|null $query Question de l'utilisateur (réservé pour usage futur)
     * @return string
     */
    public function get_site_context(?string $query = null): string {
        error_log("[BeauBot WP API] get_site_context called");

        // Vérifier le cache (avec validation : le cache doit contenir du vrai contenu)
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && strlen($cached) > 500) {
            error_log("[BeauBot WP API] Using cached context (" . strlen($cached) . " chars)");
            return $this->truncate_context($cached);
        }
        
        // Si le cache existe mais est trop petit, le supprimer (cache invalide/vide)
        if ($cached !== false) {
            error_log("[BeauBot WP API] Cache found but too small (" . strlen($cached) . " chars), clearing...");
            delete_transient(self::CACHE_KEY);
        }

        // Récupérer les pages de toutes les sources
        $all_pages = $this->fetch_all_sources();

        if (empty($all_pages)) {
            error_log("[BeauBot WP API] No pages returned from any source");
            return '';
        }

        error_log("[BeauBot WP API] Total pages from all sources: " . count($all_pages));

        // Construire la map des parents pour la hiérarchie
        $this->build_parent_map($all_pages);

        // Formater le contenu
        $context = $this->format_pages_context($all_pages);

        // Ne cacher que si le contenu est substantiel
        if (strlen($context) > 500) {
            set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);
            error_log("[BeauBot WP API] Context cached: " . strlen($context) . " chars");
        } else {
            error_log("[BeauBot WP API] Context too small to cache: " . strlen($context) . " chars");
        }

        return $this->truncate_context($context);
    }

    /**
     * Récupérer les pages de toutes les sources configurées.
     * Étape 1 : TOUJOURS récupérer les pages locales via REST interne (fiable à 100%)
     * Étape 2 : Ajouter les pages des sources externes configurées
     * 
     * @return array
     */
    private function fetch_all_sources(): array {
        $all_pages = [];

        // === ÉTAPE 1 : Toujours récupérer le contenu LOCAL via WP_Query ===
        error_log("[BeauBot WP API] Step 1: Fetching LOCAL pages via WP_Query...");
        $local_pages = $this->fetch_pages_internal();
        
        if (!empty($local_pages)) {
            error_log("[BeauBot WP API] LOCAL: Got " . count($local_pages) . " pages");
            foreach ($local_pages as &$page) {
                $page['_source_api'] = 'local';
            }
            unset($page);
            $all_pages = $local_pages;
        } else {
            error_log("[BeauBot WP API] LOCAL: No published pages found");
        }

        // === ÉTAPE 2 : Ajouter les sources EXTERNES (URLs qui ne pointent pas vers ce site) ===
        foreach ($this->api_base_urls as $api_url) {
            // Ignorer les URLs locales (déjà couvert par l'étape 1)
            if ($this->is_local_api($api_url)) {
                error_log("[BeauBot WP API] Skipping {$api_url} (local, already fetched)");
                continue;
            }
            
            error_log("[BeauBot WP API] Step 2: Fetching EXTERNAL from: {$api_url}");
            $pages = $this->fetch_pages_external($api_url);
            
            if (is_wp_error($pages)) {
                error_log("[BeauBot WP API] EXTERNAL error for {$api_url}: " . $pages->get_error_message());
                continue;
            }

            error_log("[BeauBot WP API] EXTERNAL: Got " . count($pages) . " pages from {$api_url}");
            foreach ($pages as &$page) {
                $page['_source_api'] = $api_url;
            }
            unset($page);

            $all_pages = array_merge($all_pages, $pages);
        }

        error_log("[BeauBot WP API] Total: " . count($all_pages) . " pages from all sources");
        return $all_pages;
    }

    /**
     * Récupérer les pages locales via WP_Query (fiable à 100%, fonctionne dans tous les contextes)
     * Plus fiable que rest_do_request() car ne dépend pas de l'initialisation du serveur REST.
     * 
     * @return array
     */
    private function fetch_pages_internal(): array {
        error_log("[BeauBot WP API] Using WP_Query to fetch LOCAL pages");
        
        $all_pages = [];
        $paged = 1;
        $per_page = 100;

        do {
            $query = new WP_Query([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
                'no_found_rows'  => false, // Nécessaire pour la pagination
            ]);

            if (!$query->have_posts()) {
                if ($paged === 1) {
                    error_log("[BeauBot WP API] WP_Query: No published pages found");
                }
                break;
            }

            foreach ($query->posts as $post) {
                $all_pages[] = $this->normalize_wp_post($post);
            }

            $total_pages = $query->max_num_pages;
            error_log("[BeauBot WP API] WP_Query page {$paged}/{$total_pages}, got " . count($query->posts) . " items");

            $paged++;
            wp_reset_postdata();
        } while ($paged <= $total_pages);

        error_log("[BeauBot WP API] WP_Query total: " . count($all_pages) . " pages");
        return $all_pages;
    }

    /**
     * Normaliser un objet WP_Post pour correspondre au format JSON de l'API REST
     * @param WP_Post $post
     * @return array
     */
    private function normalize_wp_post(WP_Post $post): array {
        // Appliquer the_content filters pour obtenir le HTML final (comme l'API REST le fait)
        $content = apply_filters('the_content', $post->post_content);
        
        return [
            'id'         => $post->ID,
            'title'      => ['rendered' => get_the_title($post)],
            'content'    => ['rendered' => $content],
            'link'       => get_permalink($post),
            'parent'     => $post->post_parent,
            'slug'       => $post->post_name,
            'menu_order' => $post->menu_order,
        ];
    }

    /**
     * Récupérer les pages via un appel HTTP externe (site distant)
     * @param string $api_base_url
     * @return array|WP_Error
     */
    private function fetch_pages_external(string $api_base_url): array|WP_Error {
        error_log("[BeauBot WP API] Using EXTERNAL HTTP call to: {$api_base_url}");
        
        $all_pages = [];
        $page_num = 1;
        $per_page = 100;

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
                'timeout'    => 30,
                'sslverify'  => false,
                'user-agent' => 'BeauBot/' . BEAUBOT_VERSION . ' (WordPress Plugin)',
                'headers'    => [
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
                // Limiter la longueur par page pour que TOUTES les pages puissent être incluses
                // Avec 17+ pages, 8000 chars/page × 17 = ~136K chars ≈ 34K tokens (< 60K max)
                if (strlen($clean) > 8000) {
                    $clean = substr($clean, 0, 8000) . '... [contenu tronqué]';
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
     * Retourne un résultat détaillé avec diagnostics pour l'interface admin.
     * 
     * @return array
     */
    public function force_refresh(): array {
        $start = microtime(true);
        $diagnostics = [];
        
        $this->clear_cache();
        
        // Diagnostic : infos sur l'environnement
        $diagnostics['local_rest_url'] = rest_url('wp/v2');
        $diagnostics['home_url'] = home_url();
        $diagnostics['configured_urls'] = $this->api_base_urls;
        
        // Récupérer les pages
        $pages = $this->fetch_all_sources();
        $duration = round(microtime(true) - $start, 2);

        if (empty($pages)) {
            // Diagnostic détaillé en cas d'échec
            $diagnostics['local_pages_count'] = wp_count_posts('page')->publish ?? 0;
            
            return [
                'success'     => false,
                'message'     => sprintf(
                    __('Aucune page récupérée. %d pages publiées existent sur ce site. Vérifiez les URLs des API.', 'beaubot'),
                    $diagnostics['local_pages_count']
                ),
                'diagnostics' => $diagnostics,
            ];
        }

        $this->build_parent_map($pages);
        $context = $this->format_pages_context($pages);
        set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);

        $size = round(strlen($context) / 1024, 2);
        $sources_count = count($this->api_base_urls);
        
        // Compter les pages par source
        $pages_by_source = [];
        foreach ($pages as $page) {
            $source = $page['_source_api'] ?? 'unknown';
            $pages_by_source[$source] = ($pages_by_source[$source] ?? 0) + 1;
        }

        return [
            'success'         => true,
            'message'         => sprintf(
                __('%d pages récupérées depuis %d source(s) en %ss (%s Ko)', 'beaubot'),
                count($pages),
                $sources_count,
                $duration,
                $size
            ),
            'count'           => count($pages),
            'sources'         => $sources_count,
            'size'            => $size,
            'duration'        => $duration,
            'pages_by_source' => $pages_by_source,
            'diagnostics'     => $diagnostics,
        ];
    }

    /**
     * Obtenir les statistiques du cache et l'état des sources
     * @return array
     */
    public function get_cache_stats(): array {
        $cached = get_transient(self::CACHE_KEY);
        $local_published = wp_count_posts('page')->publish ?? 0;

        $base = [
            'api_urls'        => $this->api_base_urls,
            'sources_count'   => count($this->api_base_urls),
            'local_pages'     => (int) $local_published,
            'home_url'        => home_url(),
        ];

        if ($cached === false) {
            return array_merge($base, [
                'cached'  => false,
                'message' => __('Aucun cache disponible', 'beaubot'),
            ]);
        }

        return array_merge($base, [
            'cached' => true,
            'size'   => round(strlen($cached) / 1024, 2),
            'chars'  => strlen($cached),
        ]);
    }

    /**
     * Exécuter un diagnostic complet des sources API
     * Utile pour débugger les problèmes de récupération de contenu.
     * 
     * @return array
     */
    public function run_diagnostics(): array {
        $results = [
            'timestamp'       => current_time('mysql'),
            'home_url'        => home_url(),
            'rest_url'        => rest_url('wp/v2'),
            'wp_version'      => get_bloginfo('version'),
            'configured_urls' => $this->api_base_urls,
            'sources'         => [],
        ];

        // Test 1 : Contenu local via WP_Query
        $start = microtime(true);
        $local_pages = $this->fetch_pages_internal();
        $duration = round(microtime(true) - $start, 3);

        // Analyser le contenu de chaque page locale pour détecter les pages vides
        $pages_detail = [];
        $total_content_chars = 0;
        $empty_pages_count = 0;

        foreach ($local_pages as $page) {
            $title = $page['title']['rendered'] ?? '(sans titre)';
            $raw_content = $page['content']['rendered'] ?? '';
            $clean_content = $this->clean_html_content($raw_content);
            $content_length = strlen($clean_content);
            $total_content_chars += $content_length;

            $detail = [
                'title'          => $title,
                'content_chars'  => $content_length,
                'has_content'    => $content_length > 50,
                'preview'        => $content_length > 0 
                    ? mb_substr($clean_content, 0, 200) . ($content_length > 200 ? '...' : '')
                    : '(vide)',
            ];

            if ($content_length <= 50) {
                $empty_pages_count++;
                // Vérifier si post_content brut contient des shortcodes ou du contenu page builder
                $raw_length = strlen($raw_content);
                if ($raw_length > 0 && $content_length === 0) {
                    $detail['warning'] = "HTML brut = {$raw_length} caractères mais contenu nettoyé = 0 (possible page builder ?)";
                }
            }

            $pages_detail[] = $detail;
        }

        $results['sources']['local'] = [
            'method'              => 'WP_Query',
            'success'             => !empty($local_pages),
            'count'               => count($local_pages),
            'duration'            => $duration . 's',
            'total_content_chars' => $total_content_chars,
            'empty_pages'         => $empty_pages_count,
            'pages_detail'        => $pages_detail,
        ];

        // Test 2 : Sources externes configurées
        foreach ($this->api_base_urls as $api_url) {
            $is_local = $this->is_local_api($api_url);
            
            if ($is_local) {
                $results['sources'][$api_url] = [
                    'method'   => 'Skipped (détecté comme local)',
                    'is_local' => true,
                    'success'  => !empty($local_pages),
                    'count'    => count($local_pages),
                    'note'     => 'Contenu récupéré via WP_Query (étape 1)',
                ];
                continue;
            }

            $start = microtime(true);
            $pages = $this->fetch_pages_external($api_url);
            $duration = round(microtime(true) - $start, 3);

            if (is_wp_error($pages)) {
                $results['sources'][$api_url] = [
                    'method'   => 'HTTP externe (wp_remote_get)',
                    'is_local' => false,
                    'success'  => false,
                    'error'    => $pages->get_error_message(),
                    'duration' => $duration . 's',
                ];
            } else {
                // Analyser le contenu des pages externes aussi
                $ext_total = 0;
                $ext_empty = 0;
                foreach ($pages as $p) {
                    $cl = strlen($this->clean_html_content($p['content']['rendered'] ?? ''));
                    $ext_total += $cl;
                    if ($cl <= 50) $ext_empty++;
                }

                $results['sources'][$api_url] = [
                    'method'              => 'HTTP externe (wp_remote_get)',
                    'is_local'            => false,
                    'success'             => true,
                    'count'               => count($pages),
                    'duration'            => $duration . 's',
                    'total_content_chars' => $ext_total,
                    'empty_pages'         => $ext_empty,
                    'sample'              => array_map(fn($p) => $p['title']['rendered'] ?? '(sans titre)', array_slice($pages, 0, 5)),
                ];
            }
        }

        // Test 3 : État du cache
        $cached = get_transient(self::CACHE_KEY);
        $results['cache'] = [
            'exists'  => $cached !== false,
            'size'    => $cached !== false ? strlen($cached) : 0,
            'size_kb' => $cached !== false ? round(strlen($cached) / 1024, 2) : 0,
            'preview' => ($cached !== false && strlen($cached) > 0)
                ? mb_substr($cached, 0, 500) . '...'
                : '(vide)',
        ];

        // Test 4 : Simuler get_site_context() pour vérifier le résultat final
        // Vider le cache d'abord pour forcer un rechargement frais
        delete_transient(self::CACHE_KEY);
        $context = $this->get_site_context('test diagnostic');
        $results['context_test'] = [
            'length'        => strlen($context),
            'tokens_est'    => $this->estimate_tokens($context),
            'max_tokens'    => self::MAX_CONTEXT_TOKENS,
            'max_chars_page' => 8000,
            'is_truncated'  => $this->estimate_tokens($context) >= self::MAX_CONTEXT_TOKENS * 0.95,
            'preview_start' => mb_substr($context, 0, 300) . '...',
            'preview_end'   => '...' . mb_substr($context, -300),
            'has_content'   => strlen($context) > 500,
        ];

        // Test 5 : Vérifier si des termes spécifiques sont dans le contexte final
        $test_terms = ['CIA', 'environnement', 'génétique', 'GEEP', 'lisier'];
        $term_check = [];
        foreach ($test_terms as $term) {
            $term_check[$term] = stripos($context, $term) !== false;
        }
        $results['term_check'] = $term_check;

        return $results;
    }
}
