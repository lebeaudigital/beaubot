<?php
/**
 * Classe Quota de BeauBot
 *
 * Gère la limite quotidienne de requêtes (jetons) par utilisateur.
 * - Suivi de la consommation par jour (table dédiée)
 * - Coût différencié texte / image
 * - Limite configurable par l'admin
 * - Activation/désactivation globale
 */

if (!defined('ABSPATH')) {
    exit;
}

class BeauBot_Quota {

    /**
     * Instance unique
     * @var BeauBot_Quota|null
     */
    private static ?BeauBot_Quota $instance = null;

    /**
     * Nom de l'option WP
     */
    public const OPTION_NAME = 'beaubot_quota_settings';

    /**
     * Obtenir l'instance unique
     */
    public static function get_instance(): BeauBot_Quota {
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
     * Nom complet de la table
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'beaubot_quota';
    }

    /**
     * Réglages par défaut
     * @return array
     */
    public static function get_default_settings(): array {
        return [
            'enabled'        => 1,
            'daily_limit'    => 100,
            'token_name'     => __('Demande', 'beaubot'),
            'token_name_plural' => __('Demandes', 'beaubot'),
            'short_label'    => __('DEMANDES/J', 'beaubot'),
            'cost_text'      => 1,
            'cost_image'     => 3,
            'target_selector'=> '.header-right',
            'position'       => 'before', // before | after | append | prepend
        ];
    }

    /**
     * Récupérer les réglages fusionnés avec les valeurs par défaut
     */
    public static function get_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge(self::get_default_settings(), $saved);
    }

    /**
     * Sanitiser les réglages avant sauvegarde
     */
    public static function sanitize_settings($input): array {
        $defaults = self::get_default_settings();
        if (!is_array($input)) {
            $input = [];
        }

        $sanitized = [];
        $sanitized['enabled']           = !empty($input['enabled']) ? 1 : 0;
        $sanitized['daily_limit']       = max(1, (int) ($input['daily_limit'] ?? $defaults['daily_limit']));
        $sanitized['token_name']        = sanitize_text_field($input['token_name'] ?? $defaults['token_name']);
        $sanitized['token_name_plural'] = sanitize_text_field($input['token_name_plural'] ?? $defaults['token_name_plural']);
        $sanitized['short_label']       = sanitize_text_field($input['short_label'] ?? $defaults['short_label']);
        $sanitized['cost_text']         = max(0, (int) ($input['cost_text'] ?? $defaults['cost_text']));
        $sanitized['cost_image']        = max(0, (int) ($input['cost_image'] ?? $defaults['cost_image']));
        $sanitized['target_selector']   = sanitize_text_field($input['target_selector'] ?? $defaults['target_selector']);

        $allowed_positions = ['before', 'after', 'append', 'prepend'];
        $position = $input['position'] ?? $defaults['position'];
        $sanitized['position'] = in_array($position, $allowed_positions, true) ? $position : 'before';

        return $sanitized;
    }

    /**
     * Créer la table SQL (appelée via dbDelta dans le plugin principal)
     */
    public static function create_table(): void {
        global $wpdb;

        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            usage_date date NOT NULL,
            tokens_used int unsigned NOT NULL DEFAULT 0,
            requests_count int unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id, usage_date),
            KEY usage_date (usage_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Date "aujourd'hui" selon le fuseau du site
     */
    public static function today(): string {
        return wp_date('Y-m-d');
    }

    /**
     * Obtenir la consommation du jour pour un utilisateur
     */
    public function get_today_usage(int $user_id, ?string $date = null): array {
        global $wpdb;
        $date = $date ?: self::today();
        $table = self::get_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tokens_used, requests_count FROM $table WHERE user_id = %d AND usage_date = %s",
                $user_id,
                $date
            ),
            ARRAY_A
        );

        return [
            'tokens_used'    => (int) ($row['tokens_used'] ?? 0),
            'requests_count' => (int) ($row['requests_count'] ?? 0),
        ];
    }

    /**
     * Obtenir un état complet pour le frontend
     */
    public function get_status(int $user_id): array {
        $settings = self::get_settings();
        $usage = $this->get_today_usage($user_id);

        $limit = (int) $settings['daily_limit'];
        $used  = (int) $usage['tokens_used'];
        $remaining = max(0, $limit - $used);

        return [
            'enabled'         => (bool) $settings['enabled'],
            'limit'           => $limit,
            'used'            => $used,
            'remaining'       => $remaining,
            'requests_count'  => (int) $usage['requests_count'],
            'percent'         => $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0,
            'reached'         => $settings['enabled'] ? ($used >= $limit) : false,
            'token_name'      => $settings['token_name'],
            'token_name_plural' => $settings['token_name_plural'],
            'short_label'     => $settings['short_label'],
            'cost_text'       => (int) $settings['cost_text'],
            'cost_image'      => (int) $settings['cost_image'],
        ];
    }

    /**
     * Calculer le coût d'une requête en fonction du contenu
     */
    public function calculate_cost(bool $has_image): int {
        $settings = self::get_settings();
        return $has_image ? (int) $settings['cost_image'] : (int) $settings['cost_text'];
    }

    /**
     * Vérifier si l'utilisateur peut consommer N jetons
     */
    public function can_consume(int $user_id, int $cost): bool {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return true;
        }
        $usage = $this->get_today_usage($user_id);
        return ($usage['tokens_used'] + $cost) <= (int) $settings['daily_limit'];
    }

    /**
     * Consommer N jetons pour l'utilisateur (incrémente la table)
     */
    public function consume(int $user_id, int $cost): bool {
        if ($cost <= 0) {
            return true;
        }

        global $wpdb;
        $table = self::get_table_name();
        $date  = self::today();

        $sql = $wpdb->prepare(
            "INSERT INTO $table (user_id, usage_date, tokens_used, requests_count, updated_at)
             VALUES (%d, %s, %d, 1, %s)
             ON DUPLICATE KEY UPDATE
                tokens_used = tokens_used + VALUES(tokens_used),
                requests_count = requests_count + 1,
                updated_at = VALUES(updated_at)",
            $user_id,
            $date,
            $cost,
            current_time('mysql')
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * Réinitialiser la consommation d'un utilisateur (jour donné ou aujourd'hui)
     */
    public function reset_user(int $user_id, ?string $date = null): bool {
        global $wpdb;
        $table = self::get_table_name();
        $date  = $date ?: self::today();
        return $wpdb->delete($table, [
            'user_id' => $user_id,
            'usage_date' => $date,
        ], ['%d', '%s']) !== false;
    }

    /**
     * Réinitialiser pour TOUS les utilisateurs sur le jour courant
     */
    public function reset_all_today(): int {
        global $wpdb;
        $table = self::get_table_name();
        $date  = self::today();
        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM $table WHERE usage_date = %s", $date)
        );
    }

    /**
     * Obtenir le top des consommateurs du jour (pour l'admin)
     */
    public function get_today_top(int $limit = 20): array {
        global $wpdb;
        $table = self::get_table_name();
        $date  = self::today();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.user_id, q.tokens_used, q.requests_count, u.display_name, u.user_email
                 FROM $table q
                 LEFT JOIN {$wpdb->users} u ON u.ID = q.user_id
                 WHERE q.usage_date = %s
                 ORDER BY q.tokens_used DESC
                 LIMIT %d",
                $date,
                $limit
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }
}
