<?php
/**
 * Template: Page de paramètres admin
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap beaubot-admin-wrap">
    <h1>
        <span class="dashicons dashicons-format-chat"></span>
        <?php esc_html_e('BeauBot - Paramètres', 'beaubot'); ?>
    </h1>

    <form method="post" action="options.php">
        <?php settings_fields('beaubot_settings_group'); ?>

        <!-- Section API -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Configuration API OpenAI', 'beaubot'); ?></h2>
            <p class="description"><?php esc_html_e('Configurez votre connexion à l\'API OpenAI/ChatGPT.', 'beaubot'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_api_key"><?php esc_html_e('Clé API OpenAI', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <div class="beaubot-api-field">
                            <?php 
                            $options = get_option('beaubot_settings');
                            $api_key = $options['api_key'] ?? '';
                            ?>
                            <input type="password" 
                                   id="beaubot_api_key" 
                                   name="beaubot_settings[api_key]" 
                                   value="<?php echo esc_attr($api_key); ?>" 
                                   class="regular-text"
                                   autocomplete="off">
                            <button type="button" class="button" id="beaubot-toggle-api-key">
                                <?php esc_html_e('Afficher', 'beaubot'); ?>
                            </button>
                            <button type="button" class="button" id="beaubot-test-api">
                                <?php esc_html_e('Tester', 'beaubot'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php 
                            printf(
                                esc_html__('Obtenez votre clé API sur %s', 'beaubot'),
                                '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>'
                            ); 
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_model"><?php esc_html_e('Modèle ChatGPT', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php 
                        $model = $options['model'] ?? 'gpt-4o';
                        $models = [
                            'gpt-4o' => 'GPT-4o (Recommandé - Vision)',
                            'gpt-4o-mini' => 'GPT-4o Mini (Économique - Vision)',
                            'gpt-4-turbo' => 'GPT-4 Turbo (Vision)',
                            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Pas de vision)',
                        ];
                        ?>
                        <select id="beaubot_model" name="beaubot_settings[model]">
                            <?php foreach ($models as $model_id => $model_name): ?>
                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($model, $model_id); ?>>
                                    <?php echo esc_html($model_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('GPT-4o est recommandé pour l\'analyse d\'images.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Interface -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Configuration Interface', 'beaubot'); ?></h2>
            <p class="description"><?php esc_html_e('Personnalisez l\'apparence du chatbot.', 'beaubot'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_bot_name"><?php esc_html_e('Nom du bot', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $bot_name = $options['bot_name'] ?? 'BeauBot'; ?>
                        <input type="text" 
                               id="beaubot_bot_name" 
                               name="beaubot_settings[bot_name]" 
                               value="<?php echo esc_attr($bot_name); ?>" 
                               class="regular-text"
                               placeholder="BeauBot">
                        <p class="description">
                            <?php esc_html_e('Le nom affiché dans l\'en-tête du chatbot.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_primary_color"><?php esc_html_e('Couleur principale', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $primary_color = $options['primary_color'] ?? '#6366f1'; ?>
                        <input type="color" 
                               id="beaubot_primary_color" 
                               name="beaubot_settings[primary_color]" 
                               value="<?php echo esc_attr($primary_color); ?>"
                               style="width: 60px; height: 40px; padding: 0; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;">
                        <input type="text" 
                               id="beaubot_primary_color_text" 
                               value="<?php echo esc_attr($primary_color); ?>" 
                               class="small-text"
                               style="margin-left: 10px;"
                               pattern="^#[0-9A-Fa-f]{6}$">
                        <p class="description">
                            <?php esc_html_e('Couleur du bouton, de l\'en-tête et des messages utilisateur.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Position de la sidebar', 'beaubot'); ?></th>
                    <td>
                        <?php $position = $options['sidebar_position'] ?? 'right'; ?>
                        <div class="beaubot-radio-group">
                            <label>
                                <input type="radio" 
                                       name="beaubot_settings[sidebar_position]" 
                                       value="left" 
                                       <?php checked($position, 'left'); ?>>
                                <?php esc_html_e('Gauche', 'beaubot'); ?>
                            </label>
                            <label>
                                <input type="radio" 
                                       name="beaubot_settings[sidebar_position]" 
                                       value="right" 
                                       <?php checked($position, 'right'); ?>>
                                <?php esc_html_e('Droite', 'beaubot'); ?>
                            </label>
                        </div>
                        <p class="description">
                            <?php esc_html_e('Position par défaut. Les utilisateurs peuvent la modifier.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Avancée -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Paramètres Avancés', 'beaubot'); ?></h2>
            <p class="description"><?php esc_html_e('Paramètres avancés pour le comportement de l\'IA.', 'beaubot'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_max_tokens"><?php esc_html_e('Tokens maximum', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $max_tokens = $options['max_tokens'] ?? 2000; ?>
                        <input type="number" 
                               id="beaubot_max_tokens" 
                               name="beaubot_settings[max_tokens]" 
                               value="<?php echo esc_attr($max_tokens); ?>" 
                               min="100" 
                               max="16384" 
                               step="100"
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('Longueur maximale des réponses (100-16384 tokens). Recommandé : 2000+.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_temperature"><?php esc_html_e('Température', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $temperature = $options['temperature'] ?? 0.7; ?>
                        <div class="beaubot-range-field">
                            <input type="range" 
                                   id="beaubot_temperature" 
                                   name="beaubot_settings[temperature]" 
                                   value="<?php echo esc_attr($temperature); ?>" 
                                   min="0" 
                                   max="2" 
                                   step="0.1"
                                   class="beaubot-range">
                            <span id="beaubot_temperature_value"><?php echo esc_html($temperature); ?></span>
                        </div>
                        <p class="description">
                            <?php esc_html_e('0 = Réponses précises et factuelles, 2 = Réponses créatives et variées.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_system_prompt"><?php esc_html_e('Prompt système', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $system_prompt = $options['system_prompt'] ?? ''; ?>
                        <textarea id="beaubot_system_prompt" 
                                  name="beaubot_settings[system_prompt]" 
                                  rows="5" 
                                  class="large-text"><?php echo esc_textarea($system_prompt); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Instructions données à l\'IA pour définir son comportement et sa personnalité.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Sources API WordPress -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Sources de contenu (API WordPress)', 'beaubot'); ?></h2>
            <p class="description"><?php esc_html_e('Ajoutez les URLs des API REST WordPress dont le chatbot doit récupérer le contenu des pages.', 'beaubot'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('URLs des API', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php 
                        $wp_api_urls = $options['wp_api_urls'] ?? ['https://ifip.lebeaudigital.fr/memento/wp-json/wp/v2'];
                        if (empty($wp_api_urls)) {
                            $wp_api_urls = [''];
                        }
                        ?>
                        <div id="beaubot-api-urls-wrapper">
                            <?php foreach ($wp_api_urls as $index => $url): ?>
                                <div class="beaubot-api-url-row" style="display: flex; align-items: center; margin-bottom: 8px; gap: 8px;">
                                    <input type="url" 
                                           name="beaubot_settings[wp_api_urls][]" 
                                           value="<?php echo esc_attr($url); ?>" 
                                           class="regular-text"
                                           placeholder="https://example.com/wp-json/wp/v2">
                                    <button type="button" class="button beaubot-remove-url" title="<?php esc_attr_e('Supprimer', 'beaubot'); ?>" style="color: #b91c1c;">
                                        <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="beaubot-add-url" style="margin-top: 4px;">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
                            <?php esc_html_e('Ajouter une URL', 'beaubot'); ?>
                        </button>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e('Chaque URL doit pointer vers la base de l\'API REST WordPress (ex: https://monsite.com/wp-json/wp/v2). Le chatbot récupérera les pages de chaque source.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Enregistrer les modifications', 'beaubot')); ?>
    </form>

    <!-- Section Cache API WordPress -->
    <div class="beaubot-card">
        <h2><?php esc_html_e('Cache du contenu', 'beaubot'); ?></h2>
        <p class="description"><?php esc_html_e('Le chatbot récupère le contenu des pages via les API REST WordPress configurées ci-dessus. Le cache se rafraîchit automatiquement toutes les heures.', 'beaubot'); ?></p>
        
        <?php
        $wp_api = new BeauBot_API_WordPress();
        $stats = $wp_api->get_cache_stats();
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Statut du cache', 'beaubot'); ?></th>
                <td>
                    <div id="beaubot-index-status">
                        <?php if ($stats['cached']): ?>
                            <span class="beaubot-status beaubot-status-success">
                                <?php esc_html_e('Cache actif', 'beaubot'); ?>
                            </span>
                            <ul style="margin-top: 10px; color: #666;">
                                <li><strong><?php esc_html_e('Taille:', 'beaubot'); ?></strong> <?php echo esc_html($stats['size']); ?> Ko</li>
                                <li><strong><?php esc_html_e('Sources:', 'beaubot'); ?></strong> <?php echo esc_html($stats['sources_count']); ?> API(s) configurée(s)</li>
                                <li><strong><?php esc_html_e('Pages locales publiées:', 'beaubot'); ?></strong> <?php echo esc_html($stats['local_pages']); ?></li>
                            </ul>
                        <?php else: ?>
                            <span class="beaubot-status beaubot-status-warning">
                                <?php esc_html_e('Aucun cache', 'beaubot'); ?>
                            </span>
                            <p style="color: #b45309; margin-top: 10px;">
                                <?php 
                                printf(
                                    esc_html__('%d pages publiées détectées sur ce site. Cliquez sur "Rafraîchir le cache" pour récupérer le contenu.', 'beaubot'),
                                    $stats['local_pages']
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Actions', 'beaubot'); ?></th>
                <td>
                    <button type="button" class="button button-primary" id="beaubot-reindex">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Rafraîchir le cache', 'beaubot'); ?>
                    </button>
                    <button type="button" class="button" id="beaubot-diagnostics" style="margin-left: 8px;">
                        <span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Diagnostic', 'beaubot'); ?>
                    </button>
                    <span id="beaubot-reindex-status" style="margin-left: 10px;"></span>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e('Le cache se met à jour automatiquement toutes les heures. Le diagnostic permet de vérifier chaque source individuellement.', 'beaubot'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <!-- Résultats du diagnostic (caché par défaut) -->
        <div id="beaubot-diagnostics-results" style="display: none; margin-top: 15px;">
            <h3 style="margin-bottom: 10px;"><?php esc_html_e('Résultats du diagnostic', 'beaubot'); ?></h3>
            <div id="beaubot-diagnostics-content" style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 4px; padding: 15px; font-family: monospace; font-size: 13px; max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>

    <div class="beaubot-admin-footer">
        <p>
            BeauBot v<?php echo esc_html(BEAUBOT_VERSION); ?> | 
            <?php esc_html_e('Propulsé par', 'beaubot'); ?> 
            <a href="https://openai.com" target="_blank" rel="noopener">OpenAI</a>
        </p>
    </div>
</div>
