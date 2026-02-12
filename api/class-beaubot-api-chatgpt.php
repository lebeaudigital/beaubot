<?php
/**
 * Classe API ChatGPT de BeauBot
 * 
 * Gère les appels à l'API OpenAI/ChatGPT.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_API_ChatGPT {
    
    /**
     * URL de base de l'API OpenAI
     */
    private const API_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Clé API
     * @var string
     */
    private string $api_key;

    /**
     * Modèle à utiliser
     * @var string
     */
    private string $model;

    /**
     * Options du plugin
     * @var array
     */
    private array $options;

    /**
     * Constructeur
     */
    public function __construct() {
        $this->options = get_option('beaubot_settings', []);
        $this->api_key = $this->options['api_key'] ?? '';
        $this->model = $this->options['model'] ?? 'gpt-4o';
    }

    /**
     * Vérifier si l'API est configurée
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Envoyer un message à ChatGPT
     * @param array $messages Historique des messages
     * @param string|null $image_base64 Image en base64 (optionnel)
     * @param string|null $site_context Contexte du site (optionnel)
     * @return array|WP_Error
     */
    public function send_message(
        array $messages, 
        ?string $image_base64 = null, 
        ?string $site_context = null
    ): array|WP_Error {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('L\'API ChatGPT n\'est pas configurée.', 'beaubot'));
        }

        // Construire le prompt système
        $system_prompt = $this->build_system_prompt($site_context);

        // Préparer les messages pour l'API
        $api_messages = $this->prepare_messages($messages, $system_prompt, $image_base64);

        // Appel API
        $response = $this->make_request('/chat/completions', [
            'model' => $this->model,
            'messages' => $api_messages,
            'max_tokens' => (int) ($this->options['max_tokens'] ?? 2000),
            'temperature' => (float) ($this->options['temperature'] ?? 0.7),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extraire la réponse
        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Réponse invalide de l\'API.', 'beaubot'));
        }

        return [
            'content' => $response['choices'][0]['message']['content'],
            'usage' => $response['usage'] ?? null,
            'model' => $response['model'] ?? $this->model,
        ];
    }

    /**
     * Construire le prompt système
     * @param string|null $site_context
     * @return string
     */
    private function build_system_prompt(?string $site_context): string {
        $site_name = get_bloginfo('name');
        
        $base_prompt = $this->options['system_prompt'] ?? '';
        
        $prompt = "Tu es l'assistant pédagogique du site \"{$site_name}\". ";
        $prompt .= "Tu adoptes le rôle d'un professeur bienveillant et passionné qui explique des concepts à ses étudiants. ";
        $prompt .= "Tu t'appuies sur le contenu du site fourni ci-dessous pour répondre.\n\n";
        
        $prompt .= "TON ET STYLE:\n";
        $prompt .= "- Explique comme un professeur qui veut faire comprendre, pas comme une encyclopédie.\n";
        $prompt .= "- Utilise un langage clair et accessible, sans jargon inutile.\n";
        $prompt .= "- Quand un terme technique apparaît, explique-le simplement.\n";
        $prompt .= "- Structure tes explications de façon progressive : d'abord le concept général, puis les détails.\n";
        $prompt .= "- Tu peux utiliser des analogies ou des exemples concrets pour faciliter la compréhension.\n";
        $prompt .= "- Sois chaleureux et encourageant dans tes réponses.\n\n";
        
        $prompt .= "FORMAT DE RÉPONSE:\n";
        $prompt .= "- Commence par une phrase d'accroche qui introduit le sujet naturellement.\n";
        $prompt .= "- Développe l'explication de façon fluide et pédagogique, en paragraphes lisibles.\n";
        $prompt .= "- Tu peux utiliser des listes pour structurer les points importants, mais privilégie le texte explicatif.\n";
        $prompt .= "- Termine en indiquant la source (page du site) pour approfondir.\n";
        $prompt .= "- Réponds en français.\n";
        $prompt .= "- Si l'utilisateur veut en savoir plus, propose-lui d'approfondir un aspect.\n\n";
        
        $prompt .= "RÈGLES:\n";
        $prompt .= "- Base-toi UNIQUEMENT sur le contenu du site fourni ci-dessous.\n";
        $prompt .= "- Cherche les termes même avec des variantes (accents, singulier/pluriel, acronymes).\n";
        $prompt .= "- Ne dis jamais que tu n'as pas trouvé l'info si le terme existe dans le contenu.\n";
        $prompt .= "- Si le terme n'existe vraiment pas dans le contenu, dis-le simplement et propose des sujets proches disponibles.\n";
        
        if (!empty($base_prompt)) {
            $prompt .= "\nInstructions supplémentaires du propriétaire du site:\n{$base_prompt}\n";
        }

        if ($site_context) {
            $prompt .= "\n" . str_repeat('=', 50) . "\n";
            $prompt .= "BASE DE DONNÉES DU SITE - CHERCHE ICI:\n";
            $prompt .= str_repeat('=', 50) . "\n\n";
            $prompt .= $site_context;
            $prompt .= "\n\n" . str_repeat('=', 50) . "\n";
            $prompt .= "FIN DE LA BASE DE DONNÉES\n";
            $prompt .= str_repeat('=', 50) . "\n";
        } else {
            $prompt .= "\n[ATTENTION: Aucun contenu du site n'a été fourni. Indique à l'utilisateur de régénérer l'index.]\n";
        }

        // Log pour debug
        error_log("[BeauBot] System prompt length: " . strlen($prompt) . " chars");
        if ($site_context) {
            error_log("[BeauBot] Site context length: " . strlen($site_context) . " chars");
        } else {
            error_log("[BeauBot] WARNING: No site context provided!");
        }

        return $prompt;
    }

    /**
     * Préparer les messages pour l'API
     * @param array $messages
     * @param string $system_prompt
     * @param string|null $image_base64
     * @return array
     */
    private function prepare_messages(array $messages, string $system_prompt, ?string $image_base64): array {
        $api_messages = [
            [
                'role' => 'system',
                'content' => $system_prompt,
            ],
        ];

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            // Si c'est le dernier message utilisateur et qu'il y a une image
            if ($image_base64 && $role === 'user' && $index === count($messages) - 1) {
                error_log("[BeauBot API] Adding image to message, base64 length: " . strlen($image_base64));
                $api_messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $content,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $image_base64,
                                'detail' => 'high',
                            ],
                        ],
                    ],
                ];
            } else {
                $api_messages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }

        return $api_messages;
    }

    /**
     * Nombre maximum de tentatives pour les erreurs 429 (rate limit)
     */
    private const MAX_RETRIES = 2;

    /**
     * Effectuer une requête à l'API OpenAI avec retry automatique sur erreur 429
     * @param string $endpoint
     * @param array $body
     * @return array|WP_Error
     */
    private function make_request(string $endpoint, array $body): array|WP_Error {
        $url = self::API_BASE_URL . $endpoint;
        $last_error = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Attendre avant de réessayer (backoff exponentiel)
            if ($attempt > 0) {
                $wait = min(pow(2, $attempt), 8); // 2s, 4s max
                error_log("[BeauBot ChatGPT] Rate limit hit, retry {$attempt}/" . self::MAX_RETRIES . " after {$wait}s");
                sleep($wait);
            }

            $response = wp_remote_post($url, [
                'timeout' => 90,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                return new WP_Error(
                    'api_error',
                    sprintf(__('Erreur de connexion à l\'API: %s', 'beaubot'), $response->get_error_message())
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            // Succès
            if ($status_code === 200) {
                return $data;
            }

            // Rate limit (429) : réessayer automatiquement
            if ($status_code === 429 && $attempt < self::MAX_RETRIES) {
                $last_error = $data;
                // Vérifier si OpenAI fournit un header Retry-After
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after && is_numeric($retry_after) && (int) $retry_after <= 30) {
                    error_log("[BeauBot ChatGPT] OpenAI Retry-After header: {$retry_after}s");
                    sleep((int) $retry_after);
                }
                continue;
            }

            // Autre erreur ou dernière tentative 429
            $error_message = $data['error']['message'] ?? __('Erreur inconnue', 'beaubot');

            // Log détaillé pour le debug
            error_log("[BeauBot ChatGPT] API error {$status_code}: {$error_message}");

            // Messages d'erreur personnalisés
            $error_messages = [
                401 => __('Clé API invalide ou expirée.', 'beaubot'),
                429 => __('Limite de requêtes OpenAI atteinte. Vérifiez votre quota sur platform.openai.com. Réessayez dans quelques secondes.', 'beaubot'),
                500 => __('Erreur serveur OpenAI. Réessayez plus tard.', 'beaubot'),
                503 => __('Service OpenAI temporairement indisponible.', 'beaubot'),
            ];

            if (isset($error_messages[$status_code])) {
                $error_message = $error_messages[$status_code];
            }

            return new WP_Error('api_error', $error_message, [
                'status_code' => $status_code,
                'response' => $data,
            ]);
        }

        // Ne devrait jamais arriver, mais par sécurité
        return new WP_Error('api_error', __('Limite de requêtes OpenAI atteinte après plusieurs tentatives.', 'beaubot'));
    }

    /**
     * Tester la connexion à l'API
     * @return array|WP_Error
     */
    public function test_connection(): array|WP_Error {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Clé API non configurée.', 'beaubot'));
        }

        $response = $this->make_request('/models', []);
        
        // Pour les modèles, on fait un GET, pas un POST
        $url = self::API_BASE_URL . '/models';
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            return [
                'success' => true,
                'message' => __('Connexion réussie!', 'beaubot'),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $error_message = $body['error']['message'] ?? __('Erreur de connexion', 'beaubot');

        return new WP_Error('connection_failed', $error_message);
    }

    /**
     * Obtenir les modèles disponibles
     * @return array|WP_Error
     */
    public function get_available_models(): array|WP_Error {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Clé API non configurée.', 'beaubot'));
        }

        $url = self::API_BASE_URL . '/models';
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['data'])) {
            return new WP_Error('invalid_response', __('Réponse invalide', 'beaubot'));
        }

        // Filtrer uniquement les modèles GPT
        $gpt_models = array_filter($body['data'], function($model) {
            return strpos($model['id'], 'gpt') !== false;
        });

        return array_values($gpt_models);
    }

    /**
     * Analyser une image
     * @param string $image_base64
     * @param string $prompt
     * @return array|WP_Error
     */
    public function analyze_image(string $image_base64, string $prompt = ''): array|WP_Error {
        if (empty($prompt)) {
            $prompt = __('Décris cette image en détail.', 'beaubot');
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        return $this->send_message($messages, $image_base64);
    }
}
