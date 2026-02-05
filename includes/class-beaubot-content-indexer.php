<?php
/**
 * Classe d'indexation du contenu BeauBot
 * 
 * Récupère et formate le contenu des pages et articles pour le contexte ChatGPT.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Content_Indexer {
    
    /**
     * Table de cache
     * @var string
     */
    private string $table_cache;

    /**
     * Nombre maximum de tokens pour le contexte
     */
    private const MAX_CONTEXT_TOKENS = 8000;

    /**
     * Constructeur
     */
    public function __construct() {
        global $wpdb;
        $this->table_cache = $wpdb->prefix . 'beaubot_content_cache';
    }

    /**
     * Obtenir le contexte du site pour ChatGPT
     * @param string|null $query Question de l'utilisateur pour filtrer le contexte
     * @return string
     */
    public function get_site_context(?string $query = null): string {
        $context = [];
        
        // Informations générales du site
        $context[] = $this->get_site_info();
        
        // Contenu pertinent
        if ($query) {
            $context[] = $this->get_relevant_content($query);
        } else {
            $context[] = $this->get_all_content_summary();
        }

        return implode("\n\n", array_filter($context));
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
     * Obtenir le contenu pertinent pour une question
     * @param string $query
     * @return string
     */
    private function get_relevant_content(string $query): string {
        // Recherche dans les posts et pages
        $search_args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => 10,
            'orderby' => 'relevance',
        ];

        $search_query = new WP_Query($search_args);
        $content = "=== CONTENU PERTINENT ===\n";
        
        if ($search_query->have_posts()) {
            while ($search_query->have_posts()) {
                $search_query->the_post();
                $content .= $this->format_post_content(get_post());
            }
            wp_reset_postdata();
        }

        // Si pas de résultats de recherche, prendre les pages principales
        if (!$search_query->have_posts() || $search_query->post_count < 3) {
            $content .= $this->get_main_pages_content();
        }

        return $content;
    }

    /**
     * Obtenir un résumé de tout le contenu
     * @return string
     */
    private function get_all_content_summary(): string {
        $content = "=== RÉSUMÉ DU CONTENU ===\n";
        
        // Pages principales
        $content .= "\n--- PAGES ---\n";
        $content .= $this->get_main_pages_content();
        
        // Articles récents
        $content .= "\n--- ARTICLES RÉCENTS ---\n";
        $content .= $this->get_recent_posts_content();

        return $content;
    }

    /**
     * Obtenir le contenu des pages principales
     * @return string
     */
    private function get_main_pages_content(): string {
        $args = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'menu_order date',
            'order' => 'ASC',
        ];

        $pages = get_posts($args);
        $content = '';

        foreach ($pages as $page) {
            $content .= $this->format_post_content($page);
        }

        return $content;
    }

    /**
     * Obtenir le contenu des articles récents
     * @return string
     */
    private function get_recent_posts_content(): string {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $posts = get_posts($args);
        $content = '';

        foreach ($posts as $post) {
            $content .= $this->format_post_content($post);
        }

        return $content;
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
