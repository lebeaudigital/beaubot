<?php
/**
 * Classe API Sources de BeauBot
 *
 * Construit la liste des sources (chunks) utilisées pour répondre à une question.
 *
 * Responsabilités :
 * - Extraire le meilleur snippet d'un chunk pour ancrer le scroll dans la page
 * - Construire une URL utilisant les Text Fragments (#:~:text=...) du navigateur
 * - Préparer la structure JSON renvoyée au frontend
 *
 * Les Text Fragments sont supportés nativement par Chrome, Edge, Safari 16.1+
 * et Firefox 131+. Aucune modification de la page cible n'est nécessaire :
 * le navigateur scrolle automatiquement et surligne le texte.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_API_Sources {

    /**
     * Nombre minimum de mots pour qu'un extrait soit considéré comme suffisamment
     * unique pour être ancré via Text Fragment.
     */
    private const SNIPPET_MIN_WORDS = 4;

    /**
     * Nombre maximum de mots dans le snippet ancré (équilibre unicité / robustesse).
     */
    private const SNIPPET_MAX_WORDS = 16;

    /**
     * Longueur max du paramètre ?beaubot_hl= (recherche DOM sur la page cible).
     */
    private const HIGHLIGHT_MAX_CHARS = 180;

    /**
     * Longueur maximum (caractères) de l'extrait visible dans la chip tooltip.
     */
    private const PREVIEW_MAX_CHARS = 220;

    /**
     * Construire le tableau des sources à partir des chunks utilisés.
     *
     * @param array  $chunks  Liste des chunks BDD utilisés (issus de load_chunks_with_embeddings).
     *                        Chaque chunk doit contenir : id, page_id, page_title,
     *                        page_url, parent_title, content.
     * @param array  $top_results [chunk_id => score] trié par pertinence décroissante.
     * @param string $query   Question originale de l'utilisateur (pour extraire le snippet).
     * @param int    $limit   Nombre maximum de sources à renvoyer.
     * @return array Liste de sources structurées prêtes pour le frontend.
     */
    public function build_sources(array $chunks, array $top_results, string $query, int $limit = 3): array {
        if (empty($chunks) || empty($top_results)) {
            return [];
        }

        // Indexer par id pour accès O(1)
        $by_id = [];
        foreach ($chunks as $chunk) {
            $by_id[$chunk['id']] = $chunk;
        }

        $sources = [];
        $seen_pages = [];
        $rank = 1;

        foreach ($top_results as $chunk_id => $score) {
            if (count($sources) >= $limit) {
                break;
            }

            if (!isset($by_id[$chunk_id])) {
                continue;
            }

            $chunk = $by_id[$chunk_id];
            $page_id = (int) ($chunk['page_id'] ?? 0);

            // Éviter d'afficher plusieurs chunks de la même page
            if ($page_id > 0 && isset($seen_pages[$page_id])) {
                continue;
            }
            $seen_pages[$page_id] = true;

            $source = $this->build_single_source($chunk, $query, $rank);
            if ($source !== null) {
                $sources[] = $source;
                $rank++;
            }
        }

        return $sources;
    }

    /**
     * Construire une source unique à partir d'un chunk.
     *
     * @param array  $chunk Chunk BDD (avec page_title, page_url, content...).
     * @param string $query Question utilisateur (pour cibler le snippet pertinent).
     * @param int    $rank  Numéro de la source (1, 2, 3...).
     * @return array|null
     */
    private function build_single_source(array $chunk, string $query, int $rank): ?array {
        $url = trim($chunk['page_url'] ?? '');
        if (empty($url)) {
            return null;
        }

        // Le contenu stocké contient un préfixe "Page: ... URL: ... \n\n<texte>"
        // ajouté par index_content(). On extrait uniquement la partie texte.
        $body = $this->strip_chunk_prefix($chunk['content'] ?? '');

        $paragraph = $this->extract_best_paragraph($body, $query);
        $snippet = $this->extract_best_snippet($paragraph !== '' ? $paragraph : $body, $query);
        $preview = $this->build_preview($body, $snippet);

        $anchor_url = $this->build_anchored_url($url, $paragraph, $snippet);

        return [
            'rank'         => $rank,
            'title'        => $chunk['page_title'] ?? __('Page sans titre', 'beaubot'),
            'parent_title' => $chunk['parent_title'] ?? null,
            'url'          => $anchor_url,
            'page_url'     => $url,
            'snippet'      => $snippet,
            'preview'      => $preview,
            'is_external'  => $this->is_external_url($url),
        ];
    }

    /**
     * Retirer le préfixe "Page: ... | Section: ... \nURL: ...\n\n" injecté
     * lors de l'indexation pour ne garder que le texte utile au snippet.
     *
     * @param string $content
     * @return string
     */
    private function strip_chunk_prefix(string $content): string {
        // Le préfixe se termine par deux retours à la ligne consécutifs
        $pos = strpos($content, "\n\n");
        if ($pos !== false && $pos < 300) {
            return trim(substr($content, $pos + 2));
        }
        return trim($content);
    }

    /**
     * Extraire le paragraphe le plus pertinent (pour ancrer start,end).
     *
     * @param string $body
     * @param string $query
     * @return string
     */
    private function extract_best_paragraph(string $body, string $query): string {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}|\n+/u', $body) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), function ($p) {
            return mb_strlen($p) >= 20;
        }));

        if (empty($paragraphs)) {
            return $this->normalize_for_fragment($body);
        }

        $keywords = $this->extract_keywords($query);
        $best = $paragraphs[0];
        $best_score = -1;

        foreach ($paragraphs as $paragraph) {
            $score = $this->score_sentence($paragraph, $keywords);
            if ($score > $best_score) {
                $best_score = $score;
                $best = $paragraph;
            }
        }

        return $this->normalize_for_fragment($best);
    }

    /**
     * Extraire le meilleur snippet (suite de mots) à utiliser comme ancre.
     * Stratégie :
     * 1. Découper le texte en phrases.
     * 2. Scorer chaque phrase selon le nombre de mots de la question présents.
     * 3. Sur la meilleure phrase, garder une portion de SNIPPET_MAX_WORDS centrée
     *    sur les mots-clés trouvés.
     *
     * @param string $body  Texte du chunk (sans préfixe).
     * @param string $query Question utilisateur.
     * @return string Snippet (suite de mots à matcher avec Text Fragments).
     */
    private function extract_best_snippet(string $body, string $query): string {
        $body = $this->normalize_for_fragment($body);
        if (empty($body)) {
            return '';
        }

        // Découper en phrases (point, point d'interrogation, point d'exclamation, retour ligne)
        $sentences = preg_split('/(?<=[\.!?])\s+|\n+/u', $body);
        $sentences = array_filter(array_map('trim', $sentences), fn($s) => mb_strlen($s) > 0);

        if (empty($sentences)) {
            $sentences = [$body];
        }

        // Extraire les mots-clés significatifs de la question (mots de 3+ caractères, hors stop-words)
        $keywords = $this->extract_keywords($query);

        // Scorer chaque phrase
        $best_sentence = '';
        $best_score = -1;

        foreach ($sentences as $sentence) {
            $score = $this->score_sentence($sentence, $keywords);

            if ($score > $best_score) {
                $best_score = $score;
                $best_sentence = $sentence;
            }
        }

        // Si aucun mot-clé trouvé, prendre la première phrase non triviale
        if ($best_score <= 0) {
            $best_sentence = $sentences[array_key_first($sentences)] ?? $body;
        }

        return $this->trim_to_snippet($best_sentence, $keywords);
    }

    /**
     * Extraire les mots-clés d'une question, en filtrant les stop-words français.
     *
     * @param string $query
     * @return array Liste de mots-clés en minuscules.
     */
    private function extract_keywords(string $query): array {
        $stop_words = [
            'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'au', 'aux',
            'et', 'ou', 'est', 'sont', 'que', 'qui', 'quoi', 'pour', 'par',
            'sur', 'dans', 'avec', 'sans', 'mais', 'comme', 'pas', 'ne',
            'ce', 'cet', 'cette', 'ces', 'son', 'sa', 'ses', 'leur', 'leurs',
            'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'votre', 'vos', 'notre', 'nos',
            'il', 'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'je', 'tu',
            'comment', 'pourquoi', 'quand', 'where', 'what', 'how', 'why',
            'puis', 'peut', 'peux', 'doit', 'fait', 'a', 'ai', 'avoir', 'être',
        ];

        $query = mb_strtolower($query, 'UTF-8');
        $words = preg_split('/[\s,;:!?\.\(\)\[\]\'"]+/u', $query) ?: [];
        $words = array_filter($words, function ($w) use ($stop_words) {
            return mb_strlen($w) >= 3 && !in_array($w, $stop_words, true);
        });

        return array_values(array_unique($words));
    }

    /**
     * Scorer une phrase en fonction du nombre de mots-clés présents.
     *
     * @param string $sentence
     * @param array  $keywords
     * @return int
     */
    private function score_sentence(string $sentence, array $keywords): int {
        if (empty($keywords)) {
            return 0;
        }

        $sentence_lower = mb_strtolower($sentence, 'UTF-8');
        $score = 0;
        foreach ($keywords as $kw) {
            if (str_contains($sentence_lower, $kw)) {
                // Pondération : les mots longs comptent plus
                $score += mb_strlen($kw);
            }
        }
        return $score;
    }

    /**
     * Limiter une phrase à un nombre de mots, en centrant sur les mots-clés.
     *
     * @param string $sentence
     * @param array  $keywords
     * @return string
     */
    private function trim_to_snippet(string $sentence, array $keywords): string {
        $sentence = trim($sentence);
        $words = preg_split('/\s+/u', $sentence) ?: [];

        if (count($words) <= self::SNIPPET_MAX_WORDS) {
            return $sentence;
        }

        // Trouver l'index du premier mot-clé dans la phrase
        $anchor_index = 0;
        if (!empty($keywords)) {
            foreach ($words as $i => $word) {
                $word_lower = mb_strtolower($word, 'UTF-8');
                foreach ($keywords as $kw) {
                    if (str_contains($word_lower, $kw)) {
                        $anchor_index = $i;
                        break 2;
                    }
                }
            }
        }

        // Centrer le snippet sur le mot-clé trouvé
        $half = (int) floor(self::SNIPPET_MAX_WORDS / 2);
        $start = max(0, $anchor_index - $half);
        $end = min(count($words), $start + self::SNIPPET_MAX_WORDS);
        $start = max(0, $end - self::SNIPPET_MAX_WORDS);

        $slice = array_slice($words, $start, $end - $start);
        $snippet = implode(' ', $slice);

        // Nettoyer la ponctuation finale qui peut casser les Text Fragments
        return rtrim($snippet, " ,;:.\t\n\r");
    }

    /**
     * Construire la preview affichée dans la tooltip (texte plus large autour du snippet).
     *
     * @param string $body
     * @param string $snippet
     * @return string
     */
    private function build_preview(string $body, string $snippet): string {
        if (empty($body)) {
            return '';
        }

        $body = trim(preg_replace('/\s+/u', ' ', $body));

        if (mb_strlen($body) <= self::PREVIEW_MAX_CHARS) {
            return $body;
        }

        // Tenter de centrer la preview autour du snippet
        $pos = !empty($snippet) ? mb_stripos($body, $snippet, 0, 'UTF-8') : false;

        if ($pos === false) {
            return mb_substr($body, 0, self::PREVIEW_MAX_CHARS, 'UTF-8') . '…';
        }

        $half = (int) floor(self::PREVIEW_MAX_CHARS / 2);
        $start = max(0, $pos - $half);
        $preview = mb_substr($body, $start, self::PREVIEW_MAX_CHARS, 'UTF-8');

        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + self::PREVIEW_MAX_CHARS) < mb_strlen($body) ? '…' : '';

        return $prefix . trim($preview) . $suffix;
    }

    /**
     * Construire une URL ancrée : ?beaubot_hl= + Text Fragment start,end.
     * RFC Text Fragments : https://wicg.github.io/scroll-to-text-fragment/
     *
     * start,end permet de cibler tout le paragraphe même si le milieu varie légèrement.
     * ?beaubot_hl= sert de secours JS sur le même site.
     *
     * @param string $page_url   URL de la page source.
     * @param string $paragraph  Paragraphe pertinent (pour le range).
     * @param string $snippet    Extrait court (recherche DOM).
     * @return string
     */
    public function build_anchored_url(string $page_url, string $paragraph, string $snippet): string {
        $base = explode('#', $page_url, 2)[0];

        $highlight = $this->normalize_for_fragment($snippet !== '' ? $snippet : $paragraph);
        if (mb_strlen($highlight) > self::HIGHLIGHT_MAX_CHARS) {
            $highlight = mb_substr($highlight, 0, self::HIGHLIGHT_MAX_CHARS, 'UTF-8');
            $highlight = trim((string) preg_replace('/\s+\S*$/u', '', $highlight));
        }

        $word_count = $highlight !== '' ? count(preg_split('/\s+/u', $highlight)) : 0;
        if ($word_count >= self::SNIPPET_MIN_WORDS) {
            $base = add_query_arg('beaubot_hl', $highlight, $base);
        }

        $range = $this->fragment_range($paragraph !== '' ? $paragraph : $highlight);
        if ($range['start'] === '') {
            return $base;
        }

        $fragment = '#:~:text=' . rawurlencode($range['start']);
        if ($range['end'] !== '') {
            $fragment .= ',' . rawurlencode($range['end']);
        }

        return $base . $fragment;
    }

    /**
     * Enrichir les liens Markdown d'une réponse avec les URL ancrées des sources.
     *
     * @param string $content Texte Markdown renvoyé par le modèle.
     * @param array  $sources Sources déjà construites (avec url + page_url + title).
     * @return string
     */
    public function enrich_message_links(string $content, array $sources): string {
        if ($content === '' || empty($sources)) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function (array $matches) use ($sources) {
                $label = $matches[1];
                $href = $matches[2];
                $resolved = $this->resolve_source_url($href, $label, $sources);
                return '[' . $label . '](' . $resolved . ')';
            },
            $content
        );
    }

    /**
     * Trouver l'URL ancrée correspondant à un lien (URL ou titre de page).
     *
     * @param string $href
     * @param string $label
     * @param array  $sources
     * @return string
     */
    private function resolve_source_url(string $href, string $label, array $sources): string {
        if (str_contains($href, ':~:text=') || str_contains($href, 'beaubot_hl=')) {
            return $href;
        }

        foreach ($sources as $source) {
            $page_url = $source['page_url'] ?? '';
            $anchored = $source['url'] ?? '';
            if ($anchored === '') {
                continue;
            }
            if ($page_url !== '' && $this->urls_match($href, $page_url)) {
                return $anchored;
            }
        }

        $label_norm = mb_strtolower(trim($label), 'UTF-8');
        if ($label_norm !== '') {
            foreach ($sources as $source) {
                $title = mb_strtolower(trim((string) ($source['title'] ?? '')), 'UTF-8');
                if ($title !== '' && (str_contains($label_norm, $title) || str_contains($title, $label_norm))) {
                    return $source['url'];
                }
            }
        }

        return $href;
    }

    /**
     * Comparer deux URL (hôte sans www, chemin sans slash final).
     */
    private function urls_match(string $a, string $b): bool {
        return $this->normalize_url($a) !== '' && $this->normalize_url($a) === $this->normalize_url($b);
    }

    /**
     * @param string $url
     * @return string
     */
    private function normalize_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = explode('#', $url, 2)[0];
        $url = preg_replace('/([?&])beaubot_hl=[^&]*/', '$1', $url) ?? $url;
        $url = preg_replace('/[?&]$/', '', $url) ?? $url;

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return mb_strtolower($url, 'UTF-8');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $path = untrailingslashit($path);
        if ($path === '') {
            $path = '/';
        }

        return $host . $path;
    }

    /**
     * Normaliser un extrait pour qu'il corresponde au texte visible de la page.
     */
    private function normalize_for_fragment(string $text): string {
        $text = str_replace(["\xc2\xa0", "\xe2\x80\x89", "\xe2\x80\xaf"], ' ', $text);
        $text = strtr($text, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201c}" => '"',
            "\u{201d}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
        ]);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Déduire start / end d'un Text Fragment à partir d'un paragraphe.
     *
     * @param string $paragraph
     * @return array{start: string, end: string}
     */
    private function fragment_range(string $paragraph): array {
        $paragraph = $this->normalize_for_fragment($paragraph);
        $words = preg_split('/\s+/u', $paragraph) ?: [];
        $words = array_values(array_filter($words, fn($w) => $w !== ''));
        $count = count($words);

        if ($count === 0) {
            return ['start' => '', 'end' => ''];
        }

        if ($count <= 8) {
            $start = rtrim(implode(' ', $words), " ,;:.\t");
            $start = ltrim($start, '-');
            return ['start' => $start, 'end' => ''];
        }

        $edge = min(7, max(4, (int) floor($count / 5)));
        $start = rtrim(implode(' ', array_slice($words, 0, $edge)), " ,;:.\t");
        $end = rtrim(implode(' ', array_slice($words, -$edge)), " ,;:.\t");
        $start = ltrim($start, '-');

        if ($start === '' || $start === $end) {
            return ['start' => $start, 'end' => ''];
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Détecter si une URL est externe au site WordPress courant.
     *
     * @param string $url
     * @return bool
     */
    private function is_external_url(string $url): bool {
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        $url_host = parse_url($url, PHP_URL_HOST);

        if (empty($site_host) || empty($url_host)) {
            return false;
        }

        return strcasecmp($site_host, $url_host) !== 0;
    }
}
