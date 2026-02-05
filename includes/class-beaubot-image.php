<?php
/**
 * Classe de gestion des images éphémères BeauBot
 * 
 * Gère l'upload, le stockage temporaire et la suppression automatique des images.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Image {
    
    /**
     * Table des images temporaires
     * @var string
     */
    private string $table_images;

    /**
     * Dossier de stockage
     * @var string
     */
    private string $upload_dir;

    /**
     * URL du dossier de stockage
     * @var string
     */
    private string $upload_url;

    /**
     * Durée de vie des images en heures
     */
    private const EXPIRATION_HOURS = 24;

    /**
     * Types MIME autorisés
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Taille maximale en octets (5MB)
     */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Constructeur
     */
    public function __construct() {
        global $wpdb;
        $this->table_images = $wpdb->prefix . 'beaubot_temp_images';
        
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/beaubot-temp';
        $this->upload_url = $upload_dir['baseurl'] . '/beaubot-temp';
    }

    /**
     * Uploader une image
     * @param array $file $_FILES data
     * @param int $user_id
     * @return array|WP_Error
     */
    public function upload(array $file, int $user_id): array|WP_Error {
        // Vérifier les erreurs d'upload
        if (!empty($file['error'])) {
            return new WP_Error('upload_error', $this->get_upload_error_message($file['error']));
        }

        // Vérifier la taille
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new WP_Error('file_too_large', __('Le fichier est trop volumineux (max 5MB).', 'beaubot'));
        }

        // Vérifier le type MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            return new WP_Error('invalid_type', __('Type de fichier non autorisé.', 'beaubot'));
        }

        // Créer le dossier si nécessaire
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            file_put_contents($this->upload_dir . '/.htaccess', 'Options -Indexes');
        }

        // Générer un nom de fichier unique
        $extension = $this->get_extension_from_mime($mime_type);
        $filename = $this->generate_unique_filename($user_id, $extension);
        $file_path = $this->upload_dir . '/' . $filename;

        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new WP_Error('move_failed', __('Impossible de sauvegarder le fichier.', 'beaubot'));
        }

        // Redimensionner si nécessaire (max 1920px)
        $this->resize_image($file_path, 1920);

        // Enregistrer en base de données
        $file_url = $this->upload_url . '/' . $filename;
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . self::EXPIRATION_HOURS . ' hours'));
        
        $image_id = $this->save_to_database($user_id, $file_path, $file_url, $expires_at);
        
        if (!$image_id) {
            unlink($file_path);
            return new WP_Error('db_error', __('Erreur de base de données.', 'beaubot'));
        }

        return [
            'id' => $image_id,
            'url' => $file_url,
            'path' => $file_path,
            'expires_at' => $expires_at,
        ];
    }

    /**
     * Uploader une image depuis base64
     * @param string $base64_data
     * @param int $user_id
     * @return array|WP_Error
     */
    public function upload_base64(string $base64_data, int $user_id): array|WP_Error {
        // Extraire le type MIME et les données
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $base64_data, $matches)) {
            $mime_type = $matches[1];
            $data = base64_decode($matches[2]);
        } else {
            return new WP_Error('invalid_data', __('Données d\'image invalides.', 'beaubot'));
        }

        if ($data === false) {
            return new WP_Error('decode_error', __('Impossible de décoder l\'image.', 'beaubot'));
        }

        // Vérifier le type MIME
        if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
            return new WP_Error('invalid_type', __('Type de fichier non autorisé.', 'beaubot'));
        }

        // Vérifier la taille
        if (strlen($data) > self::MAX_FILE_SIZE) {
            return new WP_Error('file_too_large', __('Le fichier est trop volumineux (max 5MB).', 'beaubot'));
        }

        // Créer le dossier si nécessaire
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            file_put_contents($this->upload_dir . '/.htaccess', 'Options -Indexes');
        }

        // Générer un nom de fichier unique
        $extension = $this->get_extension_from_mime($mime_type);
        $filename = $this->generate_unique_filename($user_id, $extension);
        $file_path = $this->upload_dir . '/' . $filename;

        // Écrire le fichier
        if (file_put_contents($file_path, $data) === false) {
            return new WP_Error('write_failed', __('Impossible de sauvegarder le fichier.', 'beaubot'));
        }

        // Redimensionner si nécessaire
        $this->resize_image($file_path, 1920);

        // Enregistrer en base de données
        $file_url = $this->upload_url . '/' . $filename;
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . self::EXPIRATION_HOURS . ' hours'));
        
        $image_id = $this->save_to_database($user_id, $file_path, $file_url, $expires_at);
        
        if (!$image_id) {
            unlink($file_path);
            return new WP_Error('db_error', __('Erreur de base de données.', 'beaubot'));
        }

        return [
            'id' => $image_id,
            'url' => $file_url,
            'path' => $file_path,
            'expires_at' => $expires_at,
        ];
    }

    /**
     * Sauvegarder en base de données
     * @param int $user_id
     * @param string $file_path
     * @param string $file_url
     * @param string $expires_at
     * @return int|false
     */
    private function save_to_database(int $user_id, string $file_path, string $file_url, string $expires_at): int|false {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_images,
            [
                'user_id' => $user_id,
                'file_path' => $file_path,
                'file_url' => $file_url,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Obtenir une image par ID
     * @param int $image_id
     * @param int $user_id
     * @return array|null
     */
    public function get(int $image_id, int $user_id): ?array {
        global $wpdb;

        $image = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_images} WHERE id = %d AND user_id = %d",
                $image_id,
                $user_id
            ),
            ARRAY_A
        );

        return $image ?: null;
    }

    /**
     * Supprimer une image
     * @param int $image_id
     * @param int $user_id
     * @return bool
     */
    public function delete(int $image_id, int $user_id): bool {
        global $wpdb;

        $image = $this->get($image_id, $user_id);
        
        if (!$image) {
            return false;
        }

        // Supprimer le fichier
        if (file_exists($image['file_path'])) {
            unlink($image['file_path']);
        }

        // Supprimer de la base de données
        return $wpdb->delete(
            $this->table_images,
            ['id' => $image_id],
            ['%d']
        ) !== false;
    }

    /**
     * Nettoyer les images expirées (appelé par CRON)
     */
    public static function cleanup_expired_images(): void {
        global $wpdb;

        $table_images = $wpdb->prefix . 'beaubot_temp_images';
        $upload_dir = wp_upload_dir();
        $beaubot_dir = $upload_dir['basedir'] . '/beaubot-temp';

        // Récupérer les images expirées
        $expired_images = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_images} WHERE expires_at < %s",
                current_time('mysql')
            ),
            ARRAY_A
        );

        foreach ($expired_images as $image) {
            // Supprimer le fichier
            if (file_exists($image['file_path'])) {
                unlink($image['file_path']);
            }

            // Supprimer de la base de données
            $wpdb->delete(
                $table_images,
                ['id' => $image['id']],
                ['%d']
            );
        }

        // Log du nettoyage
        if (!empty($expired_images)) {
            error_log(sprintf(
                '[BeauBot] Nettoyage: %d images expirées supprimées.',
                count($expired_images)
            ));
        }
    }

    /**
     * Redimensionner une image si nécessaire
     * @param string $file_path
     * @param int $max_dimension
     * @return bool
     */
    private function resize_image(string $file_path, int $max_dimension): bool {
        $editor = wp_get_image_editor($file_path);
        
        if (is_wp_error($editor)) {
            return false;
        }

        $size = $editor->get_size();
        
        if ($size['width'] > $max_dimension || $size['height'] > $max_dimension) {
            $editor->resize($max_dimension, $max_dimension, false);
            $editor->save($file_path);
        }

        return true;
    }

    /**
     * Générer un nom de fichier unique
     * @param int $user_id
     * @param string $extension
     * @return string
     */
    private function generate_unique_filename(int $user_id, string $extension): string {
        return sprintf(
            '%d_%s_%s.%s',
            $user_id,
            date('Ymd_His'),
            wp_generate_password(8, false),
            $extension
        );
    }

    /**
     * Obtenir l'extension depuis le type MIME
     * @param string $mime_type
     * @return string
     */
    private function get_extension_from_mime(string $mime_type): string {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $map[$mime_type] ?? 'jpg';
    }

    /**
     * Obtenir le message d'erreur d'upload
     * @param int $error_code
     * @return string
     */
    private function get_upload_error_message(int $error_code): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => __('Le fichier dépasse la taille maximale autorisée.', 'beaubot'),
            UPLOAD_ERR_FORM_SIZE => __('Le fichier dépasse la taille maximale du formulaire.', 'beaubot'),
            UPLOAD_ERR_PARTIAL => __('Le fichier n\'a été que partiellement téléchargé.', 'beaubot'),
            UPLOAD_ERR_NO_FILE => __('Aucun fichier n\'a été téléchargé.', 'beaubot'),
            UPLOAD_ERR_NO_TMP_DIR => __('Dossier temporaire manquant.', 'beaubot'),
            UPLOAD_ERR_CANT_WRITE => __('Échec de l\'écriture du fichier.', 'beaubot'),
            UPLOAD_ERR_EXTENSION => __('Extension PHP a arrêté l\'upload.', 'beaubot'),
        ];

        return $errors[$error_code] ?? __('Erreur inconnue lors de l\'upload.', 'beaubot');
    }

    /**
     * Convertir une image en base64 pour l'API OpenAI
     * @param string $file_path
     * @return string|null
     */
    public function to_base64(string $file_path): ?string {
        if (!file_exists($file_path)) {
            return null;
        }

        $data = file_get_contents($file_path);
        if ($data === false) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        return 'data:' . $mime_type . ';base64,' . base64_encode($data);
    }
}
