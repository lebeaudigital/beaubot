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
    private const SNIPPET_MAX_WORDS = 12;

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

        $snippet = $this->extract_best_snippet($body, $query);
        $preview = $this->build_preview($body, $snippet);

        $anchor_url = $this->build_text_fragment_url($url, $snippet);

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
        $body = trim(preg_replace('/\s+/u', ' ', $body));
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
     * Construire une URL avec Text Fragment (#:~:text=...).
     * RFC : https://wicg.github.io/scroll-to-text-fragment/
     *
     * @param string $page_url URL de la page source.
     * @param string $snippet  Texte à surligner.
     * @return string URL finale (avec ou sans Text Fragment selon l'unicité du snippet).
     */
    private function build_text_fragment_url(string $page_url, string $snippet): string {
        $word_count = $snippet ? count(preg_split('/\s+/u', trim($snippet))) : 0;

        // Si le snippet est trop court, on n'ancre pas pour éviter les faux positifs
        if ($word_count < self::SNIPPET_MIN_WORDS) {
            return $page_url;
        }

        // Le format Text Fragment : #:~:text=... ; remplace les fragments existants
        // L'encodage URL doit être conforme à RFC 3986. rawurlencode() encode aussi
        // les caractères réservés (=, &, #) ce qui est exactement ce qu'on veut.
        $encoded = rawurlencode($snippet);

        // Si une URL contient déjà un fragment classique, on l'écrase volontairement
        // car le Text Fragment a la priorité pour le scroll-to-text.
        $base = strtok($page_url, '#');

        return $base . '#:~:text=' . $encoded;
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
