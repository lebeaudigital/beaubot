<?php
/**
 * Classe d'indexation du contenu BeauBot
 * 
 * Génère et maintient un fichier d'index du contenu du site pour ChatGPT.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Content_Indexer {
    
    /**
     * Chemin du fichier d'index
     * @var string
     */
    private string $index_file;

    /**
     * Chemin du fichier d'index JSON
     * @var string
     */
    private string $index_json_file;

    /**
     * Nombre maximum de tokens pour le contexte
     */
    private const MAX_CONTEXT_TOKENS = 12000;

    /**
     * Instance unique
     * @var BeauBot_Content_Indexer|null
     */
    private static ?BeauBot_Content_Indexer $instance = null;

    /**
     * Obtenir l'instance unique
     * @return BeauBot_Content_Indexer
     */
    public static function get_instance(): BeauBot_Content_Indexer {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->index_file = $upload_dir['basedir'] . '/beaubot-index.txt';
        $this->index_json_file = $upload_dir['basedir'] . '/beaubot-index.json';
        
        // Hooks pour mise à jour automatique de l'index
        add_action('save_post', [$this, 'schedule_reindex'], 10, 2);
        add_action('delete_post', [$this, 'schedule_reindex']);
        add_action('beaubot_reindex_content', [$this, 'generate_index']);
    }

    /**
     * Planifier une réindexation (avec délai pour éviter les multiples appels)
     * @param int $post_id
     * @param WP_Post|null $post
     */
    public function schedule_reindex(int $post_id, ?WP_Post $post = null): void {
        // Ignorer les révisions et auto-drafts
        if ($post && ($post->post_status === 'auto-draft' || $post->post_type === 'revision')) {
            return;
        }
        
        // Ignorer les conversations BeauBot
        if ($post && $post->post_type === 'beaubot_conversation') {
            return;
        }

        // Planifier la réindexation dans 30 secondes (évite les appels multiples)
        if (!wp_next_scheduled('beaubot_reindex_content')) {
            wp_schedule_single_event(time() + 30, 'beaubot_reindex_content');
        }
    }

    /**
     * Obtenir le contexte du site pour ChatGPT
     * @param string|null $query Question de l'utilisateur pour filtrer le contexte
     * @return string
     */
    public function get_site_context(?string $query = null): string {
        // Vérifier si le fichier d'index existe, sinon le générer
        if (!file_exists($this->index_file)) {
            $this->generate_index();
        }

        // Lire le fichier d'index
        $context = file_get_contents($this->index_file);
        
        if (empty($context)) {
            // Fallback si le fichier est vide
            $context = $this->get_site_info() . "\n\n" . $this->get_all_content_live();
        }

        return $this->truncate_context($context);
    }

    /**
     * Générer le fichier d'index complet du site
     * @return bool
     */
    public function generate_index(): bool {
        $content = [];
        $json_data = [
            'generated_at' => current_time('mysql'),
            'site_info' => [],
            'content' => [],
        ];

        // 1. Informations du site
        $site_info = $this->get_site_info();
        $content[] = $site_info;
        $json_data['site_info'] = [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
        ];

        // 2. Récupérer TOUT le contenu
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        foreach ($post_types as $post_type) {
            $posts = get_posts([
                'post_type' => $post_type->name,
                'post_status' => 'publish',
                'posts_per_page' => -1, // TOUT récupérer
                'orderby' => 'menu_order date',
                'order' => 'ASC',
            ]);

            if (empty($posts)) continue;

            $content[] = "\n=== " . strtoupper($post_type->labels->name) . " ===\n";

            foreach ($posts as $post) {
                $formatted = $this->format_post_content($post);
                $content[] = $formatted;

                // JSON data
                $json_data['content'][] = [
                    'id' => $post->ID,
                    'type' => $post->post_type,
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'content' => $this->clean_content($post->post_content),
                    'excerpt' => wp_trim_words($this->clean_content($post->post_content), 50),
                ];
            }
        }

        // 3. Sauvegarder les fichiers
        $full_content = implode("\n", $content);
        
        $txt_saved = file_put_contents($this->index_file, $full_content);
        $json_saved = file_put_contents($this->index_json_file, json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Log
        if ($txt_saved && $json_saved) {
            $count = count($json_data['content']);
            error_log("[BeauBot] Index généré: {$count} contenus indexés.");
            return true;
        }

        error_log("[BeauBot] Erreur lors de la génération de l'index.");
        return false;
    }

    /**
     * Obtenir le contenu live (fallback si pas d'index)
     * @return string
     */
    private function get_all_content_live(): string {
        $content = "=== CONTENU DU SITE ===\n";
        
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        foreach ($post_types as $post_type) {
            $posts = get_posts([
                'post_type' => $post_type->name,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'menu_order date',
                'order' => 'ASC',
            ]);

            if (!empty($posts)) {
                $content .= "\n--- " . strtoupper($post_type->labels->name) . " ---\n";
                foreach ($posts as $post) {
                    $content .= $this->format_post_content($post);
                }
            }
        }

        return $content;
    }

    /**
     * Forcer la régénération de l'index
     * @return array
     */
    public function force_reindex(): array {
        $start = microtime(true);
        $success = $this->generate_index();
        $duration = round(microtime(true) - $start, 2);

        if ($success && file_exists($this->index_json_file)) {
            $json = json_decode(file_get_contents($this->index_json_file), true);
            $count = count($json['content'] ?? []);
            $size = round(filesize($this->index_file) / 1024, 2);
            
            return [
                'success' => true,
                'message' => sprintf(
                    __('%d contenus indexés en %ss (%s Ko)', 'beaubot'),
                    $count,
                    $duration,
                    $size
                ),
                'count' => $count,
                'size' => $size,
                'duration' => $duration,
            ];
        }

        return [
            'success' => false,
            'message' => __('Erreur lors de la génération de l\'index.', 'beaubot'),
        ];
    }

    /**
     * Obtenir les statistiques de l'index
     * @return array
     */
    public function get_index_stats(): array {
        if (!file_exists($this->index_file)) {
            return [
                'exists' => false,
                'message' => __('Index non généré', 'beaubot'),
            ];
        }

        $json = [];
        if (file_exists($this->index_json_file)) {
            $json = json_decode(file_get_contents($this->index_json_file), true);
        }

        return [
            'exists' => true,
            'generated_at' => $json['generated_at'] ?? null,
            'count' => count($json['content'] ?? []),
            'size' => round(filesize($this->index_file) / 1024, 2),
            'size_json' => round(filesize($this->index_json_file) / 1024, 2),
        ];
    }

    /**
     * Obtenir les informations générales du site
     * @return string
     */
    private function get_site_info(): string {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $site_url = home_url();

        $info = "=== INFORMATIONS DU SITE ===\n";
        $info .= "Nom: {$site_name}\n";
        
        if (!empty($site_description)) {
            $info .= "Description: {$site_description}\n";
        }
        
        $info .= "URL: {$site_url}\n";

        // Menus de navigation
        $menus = $this->get_navigation_structure();
        if (!empty($menus)) {
            $info .= "\nStructure de navigation:\n{$menus}";
        }

        return $info;
    }

    /**
     * Obtenir la structure de navigation
     * @return string
     */
    private function get_navigation_structure(): string {
        $menus = wp_get_nav_menus();
        $structure = [];

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (!$items) continue;

            $menu_items = [];
            foreach ($items as $item) {
                $menu_items[] = "- {$item->title}";
            }

            if (!empty($menu_items)) {
                $structure[] = "{$menu->name}:\n" . implode("\n", $menu_items);
            }
        }

        return implode("\n\n", $structure);
    }


    /**
     * Formater le contenu d'un post
     * @param WP_Post $post
     * @return string
     */
    private function format_post_content(WP_Post $post): string {
        $title = $post->post_title;
        $url = get_permalink($post->ID);
        $type = $post->post_type === 'page' ? 'Page' : 'Article';
        
        // Nettoyer le contenu
        $content = $this->clean_content($post->post_content);
        
        // Limiter la longueur
        if (strlen($content) > 4000) {
            $content = substr($content, 0, 4000) . '...';
        }

        // Catégories et tags pour les articles
        $meta = '';
        if ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            $tags = get_the_tags($post->ID);
            
            if ($categories) {
                $cat_names = array_map(fn($c) => $c->name, $categories);
                $meta .= "\nCatégories: " . implode(', ', $cat_names);
            }
            
            if ($tags) {
                $tag_names = array_map(fn($t) => $t->name, $tags);
                $meta .= "\nTags: " . implode(', ', $tag_names);
            }
        }

        $formatted = "\n[{$type}] {$title}\n";
        $formatted .= "URL: {$url}{$meta}\n";
        $formatted .= "Contenu:\n{$content}\n";
        $formatted .= str_repeat('-', 50) . "\n";

        return $formatted;
    }

    /**
     * Nettoyer le contenu HTML
     * @param string $content
     * @return string
     */
    private function clean_content(string $content): string {
        // Supprimer les shortcodes
        $content = strip_shortcodes($content);
        
        // Supprimer uniquement les commentaires Gutenberg (pas le contenu entre eux)
        $content = preg_replace('/<!-- \/?wp:[^>]*-->/s', '', $content);
        
        // Supprimer les scripts et styles
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
        
        // Convertir les balises de bloc en retours à la ligne
        $content = preg_replace('/<\/(p|div|h[1-6]|li|tr|br)>/i', "\n", $content);
        
        // Supprimer le HTML restant
        $content = wp_strip_all_tags($content);
        
        // Décoder les entités HTML
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        
        // Supprimer les espaces multiples (mais garder les retours à la ligne)
        $content = preg_replace('/[ \t]+/', ' ', $content);
        
        // Supprimer les lignes vides multiples
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Nettoyer les espaces en début/fin de ligne
        $content = preg_replace('/^ +| +$/m', '', $content);
        
        return trim($content);
    }

    /**
     * Obtenir le contenu d'une page spécifique
     * @param int $post_id
     * @return string|null
     */
    public function get_specific_content(int $post_id): ?string {
        $post = get_post($post_id);
        
        if (!$post || $post->post_status !== 'publish') {
            return null;
        }

        return $this->format_post_content($post);
    }

    /**
     * Rechercher dans le contenu
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function search_content(string $query, int $limit = 5): array {
        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => $limit,
        ];

        $results = [];
        $query_obj = new WP_Query($args);

        while ($query_obj->have_posts()) {
            $query_obj->the_post();
            $post = get_post();
            
            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'url' => get_permalink($post->ID),
                'excerpt' => wp_trim_words($this->clean_content($post->post_content), 30),
            ];
        }

        wp_reset_postdata();
        return $results;
    }

    /**
     * Estimer le nombre de tokens
     * @param string $text
     * @return int
     */
    public function estimate_tokens(string $text): int {
        // Estimation approximative: 1 token ≈ 4 caractères en français
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Tronquer le contexte pour respecter la limite de tokens
     * @param string $context
     * @param int $max_tokens
     * @return string
     */
    public function truncate_context(string $context, int $max_tokens = null): string {
        $max_tokens = $max_tokens ?? self::MAX_CONTEXT_TOKENS;
        
        $current_tokens = $this->estimate_tokens($context);
        
        if ($current_tokens <= $max_tokens) {
            return $context;
        }

        // Tronquer de manière intelligente
        $max_chars = $max_tokens * 4;
        $truncated = substr($context, 0, $max_chars);
        
        // Couper proprement à la dernière phrase complète
        $last_period = strrpos($truncated, '.');
        if ($last_period !== false && $last_period > $max_chars * 0.8) {
            $truncated = substr($truncated, 0, $last_period + 1);
        }

        return $truncated . "\n\n[Contenu tronqué pour respecter les limites]";
    }
}
