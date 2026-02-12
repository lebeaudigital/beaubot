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
     * Nombre maximum de tokens pour le contexte en mode fallback (sans RAG)
     * Utilisé uniquement quand aucun chunk n'est indexé en BDD.
     */
    private const MAX_CONTEXT_TOKENS = 60000;

    /**
     * Nombre maximum de tokens pour le contexte RAG
     * En mode RAG, on envoie uniquement les chunks pertinents.
     * 15K tokens laisse de la place pour ~8 chunks de 1500 chars + métadonnées.
     */
    private const RAG_MAX_CONTEXT_TOKENS = 15000;

    /**
     * Taille d'un chunk en caractères
     */
    private const CHUNK_SIZE = 1500;

    /**
     * Chevauchement entre les chunks en caractères
     */
    private const CHUNK_OVERLAP = 200;

    /**
     * Nombre de chunks les plus pertinents à retourner par méthode de recherche
     */
    private const TOP_K_RESULTS = 8;

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
     * Obtenir le contexte du site pour ChatGPT.
     * 
     * Mode RAG (si chunks indexés en BDD) : recherche sémantique des chunks pertinents.
     * Mode Fallback (sinon) : envoie tout le contenu (ancien comportement).
     * 
     * @param string|null $query Question de l'utilisateur pour la recherche RAG
     * @return string
     */
    public function get_site_context(?string $query = null): string {
        error_log("[BeauBot WP API] get_site_context called" . ($query ? " with query: " . substr($query, 0, 100) : ""));

        // === MODE RAG : si des chunks sont indexés et qu'on a une question ===
        if (!empty($query) && $this->has_indexed_chunks()) {
            error_log("[BeauBot WP API] Using RAG mode (indexed chunks available)");
            return $this->search_relevant_chunks($query);
        }

        // === MODE FALLBACK : ancien comportement (tout le contenu) ===
        error_log("[BeauBot WP API] Using FALLBACK mode (no indexed chunks or no query)");

        // Vérifier le cache
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && strlen($cached) > 500) {
            error_log("[BeauBot WP API] Using cached context (" . strlen($cached) . " chars)");
            return $this->truncate_context($cached);
        }
        
        if ($cached !== false) {
            error_log("[BeauBot WP API] Cache found but too small (" . strlen($cached) . " chars), clearing...");
            delete_transient(self::CACHE_KEY);
        }

        $all_pages = $this->fetch_all_sources();

        if (empty($all_pages)) {
            error_log("[BeauBot WP API] No content returned from any source");
            return '';
        }

        error_log("[BeauBot WP API] Total content from all sources: " . count($all_pages) . " items (pages + posts)");

        $this->build_parent_map($all_pages);
        $context = $this->format_pages_context($all_pages);

        if (strlen($context) > 500) {
            set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);
            error_log("[BeauBot WP API] Context cached: " . strlen($context) . " chars");
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

        error_log("[BeauBot WP API] Total: " . count($all_pages) . " items (pages + posts) from all sources");
        return $all_pages;
    }

    /**
     * Récupérer les pages ET les articles locaux via WP_Query (fiable à 100%, fonctionne dans tous les contextes)
     * Plus fiable que rest_do_request() car ne dépend pas de l'initialisation du serveur REST.
     * 
     * @return array
     */
    private function fetch_pages_internal(): array {
        error_log("[BeauBot WP API] Using WP_Query to fetch LOCAL pages and posts");
        
        $all_pages = [];
        $paged = 1;
        $per_page = 100;

        do {
            $query = new WP_Query([
                'post_type'      => ['page', 'post'],
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => false, // Nécessaire pour la pagination
            ]);

            if (!$query->have_posts()) {
                if ($paged === 1) {
                    error_log("[BeauBot WP API] WP_Query: No published pages or posts found");
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

        error_log("[BeauBot WP API] WP_Query total: " . count($all_pages) . " pages and posts");
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
            'type'       => $post->post_type,
        ];
    }

    /**
     * Récupérer les pages via un appel HTTP externe (site distant)
     * @param string $api_base_url
     * @return array|WP_Error
     */
    private function fetch_pages_external(string $api_base_url): array|WP_Error {
        error_log("[BeauBot WP API] Using EXTERNAL HTTP call to: {$api_base_url}");
        
        $all_items = [];

        // Récupérer les pages ET les articles depuis l'API externe
        $endpoints = ['pages', 'posts'];

        foreach ($endpoints as $endpoint) {
            $page_num = 1;
            $per_page = 100;

            do {
                $query_params = [
                    'per_page' => $per_page,
                    'page'     => $page_num,
                    'status'   => 'publish',
                    '_fields'  => 'id,title,content,link,parent,slug,menu_order,type',
                ];

                // Tri par menu_order pour les pages, par date pour les articles
                if ($endpoint === 'pages') {
                    $query_params['orderby'] = 'menu_order';
                    $query_params['order'] = 'asc';
                } else {
                    $query_params['orderby'] = 'date';
                    $query_params['order'] = 'desc';
                }

                $url = $api_base_url . '/' . $endpoint . '?' . http_build_query($query_params);

                error_log("[BeauBot WP API] Fetching {$endpoint} page {$page_num}: {$url}");

                $response = wp_remote_get($url, [
                    'timeout'    => 30,
                    'sslverify'  => false,
                    'user-agent' => 'BeauBot/' . BEAUBOT_VERSION . ' (WordPress Plugin)',
                    'headers'    => [
                        'Accept' => 'application/json',
                    ],
                ]);

                if (is_wp_error($response)) {
                    error_log("[BeauBot WP API] HTTP Error for {$endpoint}: " . $response->get_error_message());
                    break; // Passer au prochain endpoint au lieu d'échouer complètement
                }

                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code !== 200) {
                    $body = wp_remote_retrieve_body($response);
                    error_log("[BeauBot WP API] API returned status {$status_code} for {$endpoint}: {$body}");
                    break; // Passer au prochain endpoint
                }

                $body = wp_remote_retrieve_body($response);
                $items = json_decode($body, true);

                if (!is_array($items)) {
                    error_log("[BeauBot WP API] Invalid JSON response for {$endpoint}");
                    break;
                }

                // Ajouter le type pour les items qui ne l'ont pas
                foreach ($items as &$item) {
                    if (!isset($item['type'])) {
                        $item['type'] = ($endpoint === 'pages') ? 'page' : 'post';
                    }
                }
                unset($item);

                $all_items = array_merge($all_items, $items);

                // Vérifier s'il y a d'autres pages
                $total_pages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');
                
                error_log("[BeauBot WP API] {$endpoint} page {$page_num}/{$total_pages}, got " . count($items) . " items");

                $page_num++;
            } while ($page_num <= $total_pages);
        }

        if (empty($all_items)) {
            return new WP_Error('wp_api_error', __('Aucun contenu récupéré depuis l\'API externe', 'beaubot'));
        }

        return $all_items;
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
            $diagnostics['local_posts_count'] = wp_count_posts('post')->publish ?? 0;
            
            return [
                'success'     => false,
                'message'     => sprintf(
                    __('Aucun contenu récupéré. %d pages et %d articles publiés existent sur ce site. Vérifiez les URLs des API.', 'beaubot'),
                    $diagnostics['local_pages_count'],
                    $diagnostics['local_posts_count']
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
        $local_pages_published = wp_count_posts('page')->publish ?? 0;
        $local_posts_published = wp_count_posts('post')->publish ?? 0;

        $base = [
            'api_urls'        => $this->api_base_urls,
            'sources_count'   => count($this->api_base_urls),
            'local_pages'     => (int) $local_pages_published,
            'local_posts'     => (int) $local_posts_published,
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

    // =========================================================================
    // MÉTHODES RAG (Retrieval-Augmented Generation)
    // =========================================================================

    /**
     * Obtenir le nom de la table des chunks
     * @return string
     */
    private function get_chunks_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'beaubot_content_chunks';
    }

    /**
     * Vérifier si des chunks indexés avec embeddings existent en BDD
     * @return bool
     */
    public function has_indexed_chunks(): bool {
        global $wpdb;
        $table = $this->get_chunks_table();
        
        // Vérifier que la table existe
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return false;
        }
        
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL");
        return $count > 0;
    }

    /**
     * Obtenir les statistiques des chunks indexés
     * @return array
     */
    public function get_chunks_stats(): array {
        global $wpdb;
        $table = $this->get_chunks_table();
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [
                'total_chunks'     => 0,
                'with_embeddings'  => 0,
                'unique_pages'     => 0,
                'indexed'          => false,
            ];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $with_embeddings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL");
        $unique_pages = (int) $wpdb->get_var("SELECT COUNT(DISTINCT page_id) FROM {$table}");

        return [
            'total_chunks'     => $total,
            'with_embeddings'  => $with_embeddings,
            'unique_pages'     => $unique_pages,
            'indexed'          => $with_embeddings > 0,
        ];
    }

    /**
     * Découper un texte en chunks avec chevauchement
     * @param string $text Texte à découper
     * @param int $size Taille de chaque chunk en caractères
     * @param int $overlap Chevauchement entre chunks en caractères
     * @return array Tableau de strings (chunks)
     */
    public function chunk_text(string $text, int $size = self::CHUNK_SIZE, int $overlap = self::CHUNK_OVERLAP): array {
        $text = trim($text);
        
        if (empty($text)) {
            return [];
        }

        // Si le texte est plus court que la taille d'un chunk, retourner tel quel
        if (strlen($text) <= $size) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $text_length = strlen($text);

        while ($start < $text_length) {
            $end = $start + $size;

            if ($end >= $text_length) {
                // Dernier chunk : prendre tout le reste
                $chunks[] = substr($text, $start);
                break;
            }

            // Essayer de couper à un retour à la ligne ou une fin de phrase
            $chunk = substr($text, $start, $size);
            $cut_pos = $this->find_best_cut_position($chunk);
            
            if ($cut_pos > 0) {
                $chunks[] = substr($text, $start, $cut_pos);
                $start += $cut_pos - $overlap;
            } else {
                $chunks[] = $chunk;
                $start += $size - $overlap;
            }

            // Éviter les boucles infinies
            if ($start <= 0) {
                $start = $size;
            }
        }

        return $chunks;
    }

    /**
     * Trouver la meilleure position de coupure dans un texte
     * Préfère couper à : fin de paragraphe > fin de phrase > fin de mot
     * @param string $text
     * @return int Position de coupure (0 si non trouvée)
     */
    private function find_best_cut_position(string $text): int {
        $len = strlen($text);
        $min_pos = (int) ($len * 0.6); // Ne pas couper trop tôt

        // Chercher un retour à la ligne (fin de paragraphe)
        $pos = strrpos($text, "\n\n");
        if ($pos !== false && $pos > $min_pos) {
            return $pos + 2;
        }

        // Chercher un retour à la ligne simple
        $pos = strrpos($text, "\n");
        if ($pos !== false && $pos > $min_pos) {
            return $pos + 1;
        }

        // Chercher un point suivi d'un espace (fin de phrase)
        $pos = strrpos($text, '. ');
        if ($pos !== false && $pos > $min_pos) {
            return $pos + 2;
        }

        // Chercher un espace (fin de mot)
        $pos = strrpos($text, ' ');
        if ($pos !== false && $pos > $min_pos) {
            return $pos + 1;
        }

        return 0;
    }

    /**
     * Pipeline complet d'indexation du contenu pour le RAG.
     * 
     * 1. Récupère toutes les pages (locales + externes)
     * 2. Découpe chaque page en chunks
     * 3. Génère les embeddings via l'API OpenAI
     * 4. Stocke les chunks + embeddings en BDD
     * 
     * @return array Résultat détaillé de l'indexation
     */
    public function index_content(): array {
        $start = microtime(true);
        $results = [
            'success'          => false,
            'pages_fetched'    => 0,
            'chunks_created'   => 0,
            'embeddings_generated' => 0,
            'errors'           => [],
        ];

        // Étape 1 : Récupérer toutes les pages et articles
        error_log("[BeauBot RAG] Step 1: Fetching all pages and posts...");
        $all_pages = $this->fetch_all_sources();

        if (empty($all_pages)) {
            $results['errors'][] = 'Aucun contenu récupéré (pages et articles).';
            return $results;
        }

        $results['pages_fetched'] = count($all_pages);
        error_log("[BeauBot RAG] Fetched " . count($all_pages) . " items (pages + posts)");

        // Construire la map des parents pour les breadcrumbs
        $this->build_parent_map($all_pages);
        $titles_by_id = [];
        foreach ($all_pages as $page) {
            $titles_by_id[$page['id'] ?? 0] = html_entity_decode($page['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8');
        }

        // Étape 2 : Découper en chunks
        error_log("[BeauBot RAG] Step 2: Chunking pages...");
        $all_chunks = []; // [['page_id' => ..., 'page_title' => ..., 'page_url' => ..., 'parent_title' => ..., 'content' => ..., 'chunk_index' => ..., 'source_api' => ...]]

        foreach ($all_pages as $page) {
            $page_id = $page['id'] ?? 0;
            $title = html_entity_decode($page['title']['rendered'] ?? 'Sans titre', ENT_QUOTES, 'UTF-8');
            $url = $page['link'] ?? '';
            $raw_content = $page['content']['rendered'] ?? '';
            $source = $page['_source_api'] ?? 'local';
            $parent_id = $page['parent'] ?? 0;
            $parent_title = ($parent_id && isset($titles_by_id[$parent_id])) ? $titles_by_id[$parent_id] : null;

            // Nettoyer le contenu HTML
            $clean = $this->clean_html_content($raw_content);

            if (empty($clean) || strlen($clean) < 50) {
                error_log("[BeauBot RAG] Skipping page '{$title}' (empty or too short)");
                continue;
            }

            // Construire le breadcrumb
            $breadcrumb = $this->build_breadcrumb($page, $titles_by_id);

            // Découper en chunks
            $text_chunks = $this->chunk_text($clean);

            foreach ($text_chunks as $idx => $chunk_content) {
                // Préfixer chaque chunk avec le contexte de la page
                $prefix = "Page: {$title}";
                if (!empty($breadcrumb)) {
                    $prefix .= " | Section: {$breadcrumb}";
                }
                $prefix .= "\nURL: {$url}\n\n";

                $full_chunk = $prefix . $chunk_content;
                $content_hash = md5($full_chunk);

                $all_chunks[] = [
                    'page_id'      => $page_id,
                    'page_title'   => $title,
                    'page_url'     => $url,
                    'parent_title' => $parent_title,
                    'content'      => $full_chunk,
                    'content_hash' => $content_hash,
                    'chunk_index'  => $idx,
                    'source_api'   => $source,
                ];
            }
        }

        $results['chunks_created'] = count($all_chunks);
        error_log("[BeauBot RAG] Created " . count($all_chunks) . " chunks from " . count($all_pages) . " pages");

        if (empty($all_chunks)) {
            $results['errors'][] = 'Aucun chunk créé (contenu vide ?).';
            return $results;
        }

        // Étape 3 : Générer les embeddings
        error_log("[BeauBot RAG] Step 3: Generating embeddings...");
        $embeddings_api = new BeauBot_API_Embeddings();

        if (!$embeddings_api->is_configured()) {
            $results['errors'][] = 'Clé API OpenAI non configurée. Les embeddings n\'ont pas été générés.';
            // On stocke quand même les chunks sans embeddings
            $this->store_chunks($all_chunks, []);
            $results['duration'] = round(microtime(true) - $start, 2);
            return $results;
        }

        // Extraire les textes pour le batch embedding
        $texts = array_map(fn($c) => $c['content'], $all_chunks);
        $embeddings = $embeddings_api->generate_embeddings($texts);

        if (is_wp_error($embeddings)) {
            $results['errors'][] = 'Erreur embeddings: ' . $embeddings->get_error_message();
            error_log("[BeauBot RAG] Embeddings error: " . $embeddings->get_error_message());
            // Stocker les chunks sans embeddings
            $this->store_chunks($all_chunks, []);
            $results['duration'] = round(microtime(true) - $start, 2);
            return $results;
        }

        $results['embeddings_generated'] = count($embeddings);
        error_log("[BeauBot RAG] Generated " . count($embeddings) . " embeddings");

        // Étape 4 : Stocker en BDD
        error_log("[BeauBot RAG] Step 4: Storing chunks in database...");
        $this->store_chunks($all_chunks, $embeddings);

        // Vider l'ancien cache transient (plus nécessaire en mode RAG)
        $this->clear_cache();

        $results['success'] = true;
        $results['duration'] = round(microtime(true) - $start, 2);

        error_log("[BeauBot RAG] Indexation complete: {$results['chunks_created']} chunks, {$results['embeddings_generated']} embeddings in {$results['duration']}s");

        return $results;
    }

    /**
     * Stocker les chunks (et embeddings) en BDD
     * Supprime les anciens chunks avant d'insérer les nouveaux.
     * 
     * @param array $chunks Tableau de chunks
     * @param array $embeddings Tableau d'embeddings (même ordre que $chunks)
     */
    private function store_chunks(array $chunks, array $embeddings): void {
        global $wpdb;
        $table = $this->get_chunks_table();

        // Supprimer tous les anciens chunks
        $wpdb->query("TRUNCATE TABLE {$table}");

        foreach ($chunks as $i => $chunk) {
            $embedding_json = isset($embeddings[$i]) ? wp_json_encode($embeddings[$i]) : null;

            $wpdb->insert($table, [
                'page_id'      => $chunk['page_id'],
                'page_title'   => $chunk['page_title'],
                'page_url'     => $chunk['page_url'],
                'parent_title' => $chunk['parent_title'],
                'chunk_index'  => $chunk['chunk_index'],
                'content'      => $chunk['content'],
                'content_hash' => $chunk['content_hash'],
                'embedding'    => $embedding_json,
                'source_api'   => $chunk['source_api'],
                'created_at'   => current_time('mysql'),
            ], [
                '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s',
            ]);
        }

        error_log("[BeauBot RAG] Stored " . count($chunks) . " chunks in database");
    }

    /**
     * Recherche HYBRIDE : sémantique (embeddings) + mots-clés.
     * 
     * Étape 1 : Recherche sémantique via embeddings (comprend le sens)
     * Étape 2 : Recherche par mots-clés (trouve les termes exacts, acronymes, etc.)
     * Étape 3 : Fusion et dédoublonnage des résultats
     * 
     * @param string $query Question de l'utilisateur
     * @param int $top_k Nombre de résultats (défaut: TOP_K_RESULTS)
     * @return string Contexte formaté pour ChatGPT
     */
    public function search_relevant_chunks(string $query, int $top_k = self::TOP_K_RESULTS): string {
        $start = microtime(true);

        // Charger tous les chunks depuis la BDD
        $chunk_data = $this->load_chunks_with_embeddings();

        if (empty($chunk_data)) {
            error_log("[BeauBot RAG] No chunks with embeddings found in database");
            return $this->get_fallback_context();
        }

        // === ÉTAPE 1 : Recherche sémantique (embeddings) ===
        $semantic_results = [];
        $embeddings_api = new BeauBot_API_Embeddings();

        if ($embeddings_api->is_configured()) {
            $query_embedding = $embeddings_api->generate_embedding($query);

            if (!is_wp_error($query_embedding)) {
                $chunk_embeddings = [];
                foreach ($chunk_data as $chunk) {
                    if (!empty($chunk['embedding'])) {
                        $chunk_embeddings[$chunk['id']] = json_decode($chunk['embedding'], true);
                    }
                }
                $semantic_results = $embeddings_api->find_most_similar($query_embedding, $chunk_embeddings, $top_k);
                error_log("[BeauBot RAG] Semantic search: " . count($semantic_results) . " results");
            } else {
                error_log("[BeauBot RAG] Query embedding error: " . $query_embedding->get_error_message());
            }
        }

        // === ÉTAPE 2 : Recherche par mots-clés ===
        $keyword_results = $this->keyword_search($chunk_data, $query, $top_k);
        error_log("[BeauBot RAG] Keyword search: " . count($keyword_results) . " results");

        // === ÉTAPE 3 : Fusion des résultats (dédoublonnage, score combiné) ===
        $merged = $this->merge_search_results($semantic_results, $keyword_results, $top_k);
        error_log("[BeauBot RAG] Merged results: " . count($merged) . " unique chunks");

        $duration = round(microtime(true) - $start, 3);
        error_log("[BeauBot RAG] Hybrid search completed in {$duration}s");

        if (empty($merged)) {
            error_log("[BeauBot RAG] No results found, falling back to full context");
            return $this->get_fallback_context();
        }

        return $this->format_rag_context($chunk_data, $merged, $query);
    }

    /**
     * Recherche par mots-clés dans les chunks.
     * Score basé sur : nombre de termes trouvés, fréquence, correspondance exacte.
     * 
     * @param array $chunks Tous les chunks
     * @param string $query Question de l'utilisateur
     * @param int $top_k Nombre max de résultats
     * @return array [chunk_id => score] trié par score décroissant
     */
    private function keyword_search(array $chunks, string $query, int $top_k): array {
        // Extraire les termes de recherche (mots de 2+ caractères)
        $query_lower = mb_strtolower($query, 'UTF-8');
        $terms = preg_split('/\s+/', $query_lower);
        $terms = array_filter($terms, fn($t) => mb_strlen($t) >= 2);
        $terms = array_values($terms);

        if (empty($terms)) {
            return [];
        }

        // Ajouter la requête complète comme terme (pour les phrases exactes)
        $search_phrases = [$query_lower];
        // Ajouter aussi les termes individuels
        $search_phrases = array_merge($search_phrases, $terms);
        // Supprimer les doublons
        $search_phrases = array_unique($search_phrases);

        $scores = [];

        foreach ($chunks as $chunk) {
            $chunk_id = $chunk['id'];
            $content_lower = mb_strtolower($chunk['content'], 'UTF-8');
            $score = 0.0;

            // Score pour la phrase exacte complète (bonus fort)
            if (mb_strlen($query_lower) >= 3 && str_contains($content_lower, $query_lower)) {
                $score += 0.5;
            }

            // Score pour chaque terme individuel
            $terms_found = 0;
            foreach ($terms as $term) {
                $count = mb_substr_count($content_lower, $term);
                if ($count > 0) {
                    $terms_found++;
                    // Score pondéré par la longueur du terme (les mots longs sont plus significatifs)
                    $term_weight = min(mb_strlen($term) / 10, 1.0);
                    $score += $term_weight * min($count / 3, 1.0); // Plafonné à 3 occurrences
                }
            }

            // Bonus si plusieurs termes trouvés ensemble
            if (count($terms) > 1 && $terms_found > 1) {
                $score += ($terms_found / count($terms)) * 0.3;
            }

            if ($score > 0) {
                // Normaliser le score entre 0 et 1
                $scores[$chunk_id] = min($score / 2.0, 1.0);
            }
        }

        arsort($scores);
        return array_slice($scores, 0, $top_k, true);
    }

    /**
     * Fusionner les résultats de la recherche sémantique et par mots-clés.
     * Utilise le score le plus élevé de chaque source, avec un bonus si un chunk
     * apparaît dans les deux recherches.
     * 
     * @param array $semantic [chunk_id => score]
     * @param array $keyword [chunk_id => score]
     * @param int $top_k Nombre max de résultats
     * @return array [chunk_id => score] fusionné et trié
     */
    private function merge_search_results(array $semantic, array $keyword, int $top_k): array {
        $all_ids = array_unique(array_merge(array_keys($semantic), array_keys($keyword)));
        $merged = [];

        foreach ($all_ids as $id) {
            $sem_score = $semantic[$id] ?? 0.0;
            $kw_score = $keyword[$id] ?? 0.0;

            // Score combiné : moyenne pondérée + bonus si dans les deux
            $combined = max($sem_score, $kw_score);
            if ($sem_score > 0 && $kw_score > 0) {
                // Bonus de 20% si trouvé par les deux méthodes
                $combined = ($sem_score * 0.6 + $kw_score * 0.4) * 1.2;
            }

            $merged[$id] = min($combined, 1.0);
        }

        arsort($merged);

        // Retourner plus de résultats que top_k pour permettre une meilleure couverture
        // On prend top_k + quelques résultats keyword bonus
        $max_results = min($top_k + 3, count($merged));
        return array_slice($merged, 0, $max_results, true);
    }

    /**
     * Charger tous les chunks avec embeddings depuis la BDD
     * @return array
     */
    private function load_chunks_with_embeddings(): array {
        global $wpdb;
        $table = $this->get_chunks_table();

        return $wpdb->get_results(
            "SELECT id, page_id, page_title, page_url, parent_title, chunk_index, content, embedding 
             FROM {$table} 
             WHERE embedding IS NOT NULL",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Formater le contexte RAG à partir des chunks pertinents
     * @param array $all_chunks Tous les chunks chargés (avec metadata)
     * @param array $top_results Résultats triés [chunk_id => score]
     * @param string $query Question originale
     * @return string
     */
    private function format_rag_context(array $all_chunks, array $top_results, string $query): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        // Indexer les chunks par ID pour accès rapide
        $chunks_by_id = [];
        foreach ($all_chunks as $chunk) {
            $chunks_by_id[$chunk['id']] = $chunk;
        }

        $context = "=== INFORMATIONS DU SITE ===\n";
        $context .= "Nom: {$site_name}\n";
        $context .= "URL: {$site_url}\n\n";
        $context .= "=== CONTENU PERTINENT (recherche hybride : sémantique + mots-clés) ===\n";
        $context .= "Question: {$query}\n";
        $context .= "Résultats: " . count($top_results) . " extraits les plus pertinents\n\n";

        $rank = 1;
        foreach ($top_results as $chunk_id => $score) {
            if (!isset($chunks_by_id[$chunk_id])) {
                continue;
            }

            $chunk = $chunks_by_id[$chunk_id];
            $relevance = round($score * 100, 1);

            $context .= "--- Extrait {$rank} (pertinence: {$relevance}%) ---\n";
            $context .= $chunk['content'] . "\n\n";

            $rank++;
        }

        $context .= "=== FIN DU CONTENU PERTINENT ===\n";

        // Tronquer si nécessaire pour respecter la limite RAG
        $tokens = $this->estimate_tokens($context);
        error_log("[BeauBot RAG] Context: " . strlen($context) . " chars, ~{$tokens} tokens (limit: " . self::RAG_MAX_CONTEXT_TOKENS . ")");

        if ($tokens > self::RAG_MAX_CONTEXT_TOKENS) {
            $max_chars = self::RAG_MAX_CONTEXT_TOKENS * 4;
            $context = substr($context, 0, $max_chars);
            // Couper proprement
            $last_newline = strrpos($context, "\n");
            if ($last_newline !== false && $last_newline > $max_chars * 0.8) {
                $context = substr($context, 0, $last_newline);
            }
            $context .= "\n\n[Contenu tronqué pour respecter les limites]\n";
            error_log("[BeauBot RAG] Context truncated to " . strlen($context) . " chars");
        }

        return $context;
    }

    /**
     * Obtenir le contexte en mode fallback (tout le contenu, ancien comportement)
     * Utilisé quand le RAG n'est pas disponible.
     * @return string
     */
    private function get_fallback_context(): string {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && strlen($cached) > 500) {
            return $this->truncate_context($cached);
        }

        $all_pages = $this->fetch_all_sources();
        if (empty($all_pages)) {
            return '';
        }

        $this->build_parent_map($all_pages);
        $context = $this->format_pages_context($all_pages);

        if (strlen($context) > 500) {
            set_transient(self::CACHE_KEY, $context, self::CACHE_DURATION);
        }

        return $this->truncate_context($context);
    }

    /**
     * Vider les chunks indexés de la BDD
     * @return int Nombre de chunks supprimés
     */
    public function clear_chunks(): int {
        global $wpdb;
        $table = $this->get_chunks_table();
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return 0;
        }
        
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $wpdb->query("TRUNCATE TABLE {$table}");
        error_log("[BeauBot RAG] Cleared {$count} chunks from database");
        return $count;
    }

    // =========================================================================
    // DIAGNOSTICS
    // =========================================================================

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

        // Test 1 : Contenu local via WP_Query (pages + articles)
        $start = microtime(true);
        $local_pages = $this->fetch_pages_internal();
        $duration = round(microtime(true) - $start, 3);

        // Analyser le contenu de chaque élément local pour détecter les contenus vides
        $pages_detail = [];
        $total_content_chars = 0;
        $empty_pages_count = 0;
        $local_pages_count = 0;
        $local_posts_count = 0;

        foreach ($local_pages as $page) {
            $title = $page['title']['rendered'] ?? '(sans titre)';
            $type = $page['type'] ?? 'page';
            $raw_content = $page['content']['rendered'] ?? '';
            $clean_content = $this->clean_html_content($raw_content);
            $content_length = strlen($clean_content);
            $total_content_chars += $content_length;

            if ($type === 'post') {
                $local_posts_count++;
            } else {
                $local_pages_count++;
            }

            $detail = [
                'title'          => $title,
                'type'           => $type,
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
            'method'              => 'WP_Query (pages + posts)',
            'success'             => !empty($local_pages),
            'count'               => count($local_pages),
            'pages_count'         => $local_pages_count,
            'posts_count'         => $local_posts_count,
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

        // Test 4 : État du RAG (chunks et embeddings)
        $chunks_stats = $this->get_chunks_stats();
        $results['rag'] = [
            'indexed'          => $chunks_stats['indexed'],
            'total_chunks'     => $chunks_stats['total_chunks'],
            'with_embeddings'  => $chunks_stats['with_embeddings'],
            'unique_pages'     => $chunks_stats['unique_pages'],
            'mode'             => $chunks_stats['indexed'] ? 'RAG hybride (sémantique + mots-clés)' : 'Fallback (contenu complet)',
            'chunk_size'       => self::CHUNK_SIZE,
            'chunk_overlap'    => self::CHUNK_OVERLAP,
            'top_k'            => self::TOP_K_RESULTS,
        ];

        // Test 5 : Simuler get_site_context() pour vérifier le résultat
        delete_transient(self::CACHE_KEY);
        $test_query = 'test diagnostic CIA génétique';
        $context = $this->get_site_context($test_query);
        $max_tokens = $chunks_stats['indexed'] ? self::RAG_MAX_CONTEXT_TOKENS : self::MAX_CONTEXT_TOKENS;
        $results['context_test'] = [
            'mode'          => $chunks_stats['indexed'] ? 'RAG' : 'Fallback',
            'query'         => $test_query,
            'length'        => strlen($context),
            'tokens_est'    => $this->estimate_tokens($context),
            'max_tokens'    => $max_tokens,
            'is_truncated'  => $this->estimate_tokens($context) >= $max_tokens * 0.95,
            'preview_start' => mb_substr($context, 0, 300) . '...',
            'preview_end'   => '...' . mb_substr($context, -300),
            'has_content'   => strlen($context) > 100,
        ];

        // Test 6 : Vérifier si des termes spécifiques sont dans le contexte final
        $test_terms = ['CIA', 'environnement', 'génétique', 'GEEP', 'lisier'];
        $term_check = [];
        foreach ($test_terms as $term) {
            $term_check[$term] = stripos($context, $term) !== false;
        }
        $results['term_check'] = $term_check;

        return $results;
    }
}
