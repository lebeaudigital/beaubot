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
                        <?php $max_tokens = $options['max_tokens'] ?? 1000; ?>
                        <input type="number" 
                               id="beaubot_max_tokens" 
                               name="beaubot_settings[max_tokens]" 
                               value="<?php echo esc_attr($max_tokens); ?>" 
                               min="100" 
                               max="4000" 
                               step="100"
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('Longueur maximale des réponses (100-4000 tokens).', 'beaubot'); ?>
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

        <?php submit_button(__('Enregistrer les modifications', 'beaubot')); ?>
    </form>

    <div class="beaubot-admin-footer">
        <p>
            BeauBot v<?php echo esc_html(BEAUBOT_VERSION); ?> | 
            <?php esc_html_e('Propulsé par', 'beaubot'); ?> 
            <a href="https://openai.com" target="_blank" rel="noopener">OpenAI</a>
        </p>
    </div>
</div>
