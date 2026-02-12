<?php
/**
 * Classe API Embeddings de BeauBot
 * 
 * Gère les appels à l'API OpenAI Embeddings pour le système RAG.
 * Utilise le modèle text-embedding-3-small (1536 dimensions).
 * 
 * Responsabilités :
 * - Générer des embeddings pour du texte (unitaire ou batch)
 * - Calculer la similarité cosinus entre vecteurs
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_API_Embeddings {

    /**
     * URL de base de l'API OpenAI
     */
    private const API_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Modèle d'embedding à utiliser
     * text-embedding-3-small : 1536 dimensions, $0.02/1M tokens
     */
    private const EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Nombre de dimensions des embeddings
     */
    public const EMBEDDING_DIMENSIONS = 1536;

    /**
     * Nombre maximum d'inputs par appel batch
     */
    private const MAX_BATCH_SIZE = 2048;

    /**
     * Clé API OpenAI
     * @var string
     */
    private string $api_key;

    /**
     * Constructeur
     */
    public function __construct() {
        $options = get_option('beaubot_settings', []);
        $this->api_key = $options['api_key'] ?? '';
    }

    /**
     * Vérifier si l'API est configurée
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Générer un embedding pour un texte unique
     * @param string $text Texte à encoder
     * @return array|WP_Error Vecteur embedding (array de floats) ou erreur
     */
    public function generate_embedding(string $text): array|WP_Error {
        $result = $this->generate_embeddings([$text]);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result[0] ?? new WP_Error('empty_result', 'Aucun embedding retourné.');
    }

    /**
     * Générer des embeddings pour plusieurs textes en batch
     * @param array $texts Tableau de textes à encoder
     * @return array|WP_Error Tableau de vecteurs embeddings ou erreur
     */
    public function generate_embeddings(array $texts): array|WP_Error {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Clé API OpenAI non configurée.', 'beaubot'));
        }

        if (empty($texts)) {
            return [];
        }

        // Nettoyer les textes (supprimer les retours à la ligne excessifs, limiter la taille)
        $cleaned = array_map(function (string $text): string {
            $text = preg_replace('/\s+/', ' ', trim($text));
            // Limiter à ~8000 tokens (~32000 chars) pour rester dans les limites de l'API
            if (strlen($text) > 32000) {
                $text = substr($text, 0, 32000);
            }
            return $text;
        }, $texts);

        $all_embeddings = [];

        // Traiter par batches si nécessaire
        $batches = array_chunk($cleaned, self::MAX_BATCH_SIZE);

        foreach ($batches as $batch_index => $batch) {
            error_log("[BeauBot Embeddings] Batch " . ($batch_index + 1) . "/" . count($batches) . " : " . count($batch) . " textes");

            $response = $this->make_request('/embeddings', [
                'model' => self::EMBEDDING_MODEL,
                'input' => $batch,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            if (!isset($response['data']) || !is_array($response['data'])) {
                return new WP_Error('invalid_response', __('Réponse invalide de l\'API Embeddings.', 'beaubot'));
            }

            // Trier par index pour garantir l'ordre
            $data = $response['data'];
            usort($data, fn($a, $b) => ($a['index'] ?? 0) - ($b['index'] ?? 0));

            foreach ($data as $item) {
                $all_embeddings[] = $item['embedding'];
            }

            // Log usage
            if (isset($response['usage'])) {
                error_log("[BeauBot Embeddings] Usage: " . $response['usage']['total_tokens'] . " tokens");
            }
        }

        return $all_embeddings;
    }

    /**
     * Calculer la similarité cosinus entre deux vecteurs
     * @param array $a Premier vecteur
     * @param array $b Second vecteur
     * @return float Similarité entre -1 et 1 (1 = identique)
     */
    public function cosine_similarity(array $a, array $b): float {
        $dot = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;

        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $norm_a += $a[$i] * $a[$i];
            $norm_b += $b[$i] * $b[$i];
        }

        $norm_a = sqrt($norm_a);
        $norm_b = sqrt($norm_b);

        if ($norm_a == 0.0 || $norm_b == 0.0) {
            return 0.0;
        }

        return $dot / ($norm_a * $norm_b);
    }

    /**
     * Trouver les N vecteurs les plus similaires à un vecteur de requête
     * @param array $query_embedding Vecteur de la requête
     * @param array $chunk_embeddings Tableau associatif [chunk_id => embedding]
     * @param int $top_k Nombre de résultats à retourner
     * @return array Tableau de [chunk_id => similarity_score] trié par score décroissant
     */
    public function find_most_similar(array $query_embedding, array $chunk_embeddings, int $top_k = 5): array {
        $scores = [];

        foreach ($chunk_embeddings as $chunk_id => $embedding) {
            $scores[$chunk_id] = $this->cosine_similarity($query_embedding, $embedding);
        }

        // Trier par score décroissant
        arsort($scores);

        // Retourner les top_k
        return array_slice($scores, 0, $top_k, true);
    }

    /**
     * Effectuer une requête à l'API OpenAI Embeddings
     * @param string $endpoint
     * @param array $body
     * @return array|WP_Error
     */
    private function make_request(string $endpoint, array $body): array|WP_Error {
        $url = self::API_BASE_URL . $endpoint;

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'api_error',
                sprintf(__('Erreur de connexion à l\'API Embeddings: %s', 'beaubot'), $response->get_error_message())
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code !== 200) {
            $error_message = $data['error']['message'] ?? __('Erreur inconnue', 'beaubot');
            error_log("[BeauBot Embeddings] API error {$status_code}: {$error_message}");

            $error_messages = [
                401 => __('Clé API invalide ou expirée.', 'beaubot'),
                429 => __('Limite de requêtes OpenAI atteinte. Réessayez dans quelques secondes.', 'beaubot'),
                500 => __('Erreur serveur OpenAI.', 'beaubot'),
            ];

            if (isset($error_messages[$status_code])) {
                $error_message = $error_messages[$status_code];
            }

            return new WP_Error('api_error', $error_message, [
                'status_code' => $status_code,
                'response'    => $data,
            ]);
        }

        return $data;
    }
}
