<?php
/**
 * Classe des endpoints REST API de BeauBot
 * 
 * Définit tous les endpoints REST pour le chatbot.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_API_Endpoints {
    
    /**
     * Instance unique
     * @var BeauBot_API_Endpoints|null
     */
    private static ?BeauBot_API_Endpoints $instance = null;

    /**
     * Namespace de l'API
     */
    private const NAMESPACE = 'beaubot/v1';

    /**
     * Obtenir l'instance unique
     * @return BeauBot_API_Endpoints
     */
    public static function get_instance(): BeauBot_API_Endpoints {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur privé
     */
    private function __construct() {}

    /**
     * Enregistrer les routes
     */
    public function register_routes(): void {
        // Chat - Envoyer un message
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_chat'],
            'permission_callback' => [$this, 'check_user_permission'],
            'args' => [
                'message' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'conversation_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'image' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);

        // Conversations - CRUD
        register_rest_route(self::NAMESPACE, '/conversations', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_conversations'],
                'permission_callback' => [$this, 'check_user_permission'],
                'args' => [
                    'archived' => [
                        'required' => false,
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'limit' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 20,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_conversation'],
                'permission_callback' => [$this, 'check_user_permission'],
                'args' => [
                    'title' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Conversation unique
        register_rest_route(self::NAMESPACE, '/conversations/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_conversation'],
                'permission_callback' => [$this, 'check_user_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_conversation'],
                'permission_callback' => [$this, 'check_user_permission'],
                'args' => [
                    'title' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'archived' => [
                        'required' => false,
                        'type' => 'boolean',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_conversation'],
                'permission_callback' => [$this, 'check_user_permission'],
            ],
        ]);

        // Messages d'une conversation
        register_rest_route(self::NAMESPACE, '/conversations/(?P<id>\d+)/messages', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_messages'],
            'permission_callback' => [$this, 'check_user_permission'],
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ],
                'offset' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Upload d'image
        register_rest_route(self::NAMESPACE, '/upload-image', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload_image'],
            'permission_callback' => [$this, 'check_user_permission'],
        ]);

        // Préférences utilisateur
        register_rest_route(self::NAMESPACE, '/preferences', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_preferences'],
                'permission_callback' => [$this, 'check_user_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_preferences'],
                'permission_callback' => [$this, 'check_user_permission'],
                'args' => [
                    'sidebar_position' => [
                        'required' => false,
                        'type' => 'string',
                        'enum' => ['left', 'right'],
                    ],
                ],
            ],
        ]);

        // Test API (admin uniquement)
        register_rest_route(self::NAMESPACE, '/test-api', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'test_api'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Vérifier les permissions utilisateur
     * @return bool|WP_Error
     */
    public function check_user_permission(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('Vous devez être connecté pour utiliser le chatbot.', 'beaubot'),
                ['status' => 401]
            );
        }
        return true;
    }

    /**
     * Vérifier les permissions admin
     * @return bool|WP_Error
     */
    public function check_admin_permission(): bool|WP_Error {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('Accès non autorisé.', 'beaubot'),
                ['status' => 403]
            );
        }
        return true;
    }

    /**
     * Gérer l'envoi d'un message au chatbot
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_chat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $message = $request->get_param('message');
        $conversation_id = $request->get_param('conversation_id');
        $image_data = $request->get_param('image');

        $conversation_handler = new BeauBot_Conversation();
        $image_handler = new BeauBot_Image();
        $chatgpt = new BeauBot_API_ChatGPT();
        $indexer = new BeauBot_Content_Indexer();

        // Créer une nouvelle conversation si nécessaire
        if (!$conversation_id) {
            $conversation_id = $conversation_handler->create($user_id);
            if (is_wp_error($conversation_id)) {
                return $conversation_id;
            }
        } else {
            // Vérifier l'accès à la conversation
            $conversation = $conversation_handler->get($conversation_id, $user_id);
            if (!$conversation) {
                return new WP_Error(
                    'conversation_not_found',
                    __('Conversation non trouvée.', 'beaubot'),
                    ['status' => 404]
                );
            }
        }

        // Gérer l'image si présente
        $image_url = null;
        $image_base64 = null;
        
        if ($image_data) {
            // Si c'est une URL d'image déjà uploadée
            if (filter_var($image_data, FILTER_VALIDATE_URL)) {
                $image_url = $image_data;
                // Convertir en base64 pour l'API
                $image_path = str_replace(
                    wp_upload_dir()['baseurl'], 
                    wp_upload_dir()['basedir'], 
                    $image_data
                );
                $image_base64 = $image_handler->to_base64($image_path);
            } 
            // Si c'est du base64 directement
            elseif (strpos($image_data, 'data:image') === 0) {
                $upload_result = $image_handler->upload_base64($image_data, $user_id);
                if (is_wp_error($upload_result)) {
                    return $upload_result;
                }
                $image_url = $upload_result['url'];
                $image_base64 = $image_data;
            }
        }

        // Enregistrer le message utilisateur
        $conversation_handler->add_message($conversation_id, $user_id, 'user', $message, $image_url);

        // Obtenir l'historique des messages pour le contexte
        $messages = $conversation_handler->get_messages_for_context($conversation_id, $user_id);

        // Obtenir le contexte du site
        $site_context = $indexer->get_site_context($message);
        $site_context = $indexer->truncate_context($site_context);

        // Envoyer à ChatGPT
        $response = $chatgpt->send_message($messages, $image_base64, $site_context);

        if (is_wp_error($response)) {
            return $response;
        }

        // Enregistrer la réponse de l'assistant
        $conversation_handler->add_message(
            $conversation_id, 
            $user_id, 
            'assistant', 
            $response['content']
        );

        return new WP_REST_Response([
            'success' => true,
            'conversation_id' => $conversation_id,
            'message' => [
                'role' => 'assistant',
                'content' => $response['content'],
            ],
            'usage' => $response['usage'],
        ], 200);
    }

    /**
     * Obtenir les conversations
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_conversations(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $archived = (bool) $request->get_param('archived');
        $limit = (int) $request->get_param('limit');
        $offset = (int) $request->get_param('offset');

        $conversation_handler = new BeauBot_Conversation();
        $conversations = $conversation_handler->get_user_conversations($user_id, $archived, $limit, $offset);

        return new WP_REST_Response([
            'success' => true,
            'conversations' => $conversations,
        ], 200);
    }

    /**
     * Créer une conversation
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $title = $request->get_param('title') ?? '';

        $conversation_handler = new BeauBot_Conversation();
        $conversation_id = $conversation_handler->create($user_id, $title);

        if (is_wp_error($conversation_id)) {
            return $conversation_id;
        }

        $conversation = $conversation_handler->get($conversation_id, $user_id);

        return new WP_REST_Response([
            'success' => true,
            'conversation' => $conversation,
        ], 201);
    }

    /**
     * Obtenir une conversation
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $conversation_id = (int) $request->get_param('id');

        $conversation_handler = new BeauBot_Conversation();
        $conversation = $conversation_handler->get($conversation_id, $user_id);

        if (!$conversation) {
            return new WP_Error(
                'not_found',
                __('Conversation non trouvée.', 'beaubot'),
                ['status' => 404]
            );
        }

        // Inclure les messages
        $messages = $conversation_handler->get_messages($conversation_id, $user_id);
        $conversation['messages'] = $messages;

        return new WP_REST_Response([
            'success' => true,
            'conversation' => $conversation,
        ], 200);
    }

    /**
     * Mettre à jour une conversation
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $conversation_id = (int) $request->get_param('id');

        $conversation_handler = new BeauBot_Conversation();

        // Mettre à jour le titre
        $title = $request->get_param('title');
        if ($title !== null) {
            $result = $conversation_handler->update_title($conversation_id, $title, $user_id);
            if (!$result) {
                return new WP_Error(
                    'update_failed',
                    __('Impossible de mettre à jour la conversation.', 'beaubot'),
                    ['status' => 400]
                );
            }
        }

        // Archiver/Désarchiver
        $archived = $request->get_param('archived');
        if ($archived !== null) {
            $conversation_handler->archive($conversation_id, $user_id, (bool) $archived);
        }

        $conversation = $conversation_handler->get($conversation_id, $user_id);

        return new WP_REST_Response([
            'success' => true,
            'conversation' => $conversation,
        ], 200);
    }

    /**
     * Supprimer une conversation
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_conversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $conversation_id = (int) $request->get_param('id');

        $conversation_handler = new BeauBot_Conversation();
        $result = $conversation_handler->delete($conversation_id, $user_id);

        if (!$result) {
            return new WP_Error(
                'delete_failed',
                __('Impossible de supprimer la conversation.', 'beaubot'),
                ['status' => 400]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Conversation supprimée.', 'beaubot'),
        ], 200);
    }

    /**
     * Obtenir les messages d'une conversation
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_messages(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $conversation_id = (int) $request->get_param('id');
        $limit = (int) $request->get_param('limit');
        $offset = (int) $request->get_param('offset');

        $conversation_handler = new BeauBot_Conversation();
        
        // Vérifier l'accès
        $conversation = $conversation_handler->get($conversation_id, $user_id);
        if (!$conversation) {
            return new WP_Error(
                'not_found',
                __('Conversation non trouvée.', 'beaubot'),
                ['status' => 404]
            );
        }

        $messages = $conversation_handler->get_messages($conversation_id, $user_id, $limit, $offset);

        return new WP_REST_Response([
            'success' => true,
            'messages' => $messages,
        ], 200);
    }

    /**
     * Upload d'image
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upload_image(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $files = $request->get_file_params();

        if (empty($files['image'])) {
            return new WP_Error(
                'no_file',
                __('Aucun fichier envoyé.', 'beaubot'),
                ['status' => 400]
            );
        }

        $image_handler = new BeauBot_Image();
        $result = $image_handler->upload($files['image'], $user_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'image' => $result,
        ], 201);
    }

    /**
     * Obtenir les préférences utilisateur
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_preferences(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        
        $preferences = get_user_meta($user_id, 'beaubot_preferences', true);
        if (!$preferences) {
            $global_options = get_option('beaubot_settings', []);
            $preferences = [
                'sidebar_position' => $global_options['sidebar_position'] ?? 'right',
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'preferences' => $preferences,
        ], 200);
    }

    /**
     * Mettre à jour les préférences utilisateur
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_preferences(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        
        $preferences = get_user_meta($user_id, 'beaubot_preferences', true);
        if (!$preferences) {
            $preferences = [];
        }

        $sidebar_position = $request->get_param('sidebar_position');
        if ($sidebar_position !== null) {
            $preferences['sidebar_position'] = $sidebar_position;
        }

        update_user_meta($user_id, 'beaubot_preferences', $preferences);

        return new WP_REST_Response([
            'success' => true,
            'preferences' => $preferences,
        ], 200);
    }

    /**
     * Tester l'API ChatGPT
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function test_api(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $chatgpt = new BeauBot_API_ChatGPT();
        $result = $chatgpt->test_connection();

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }
}
