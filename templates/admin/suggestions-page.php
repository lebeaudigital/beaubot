<?php
/**
 * Template: Page de gestion des suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

$suggestions = get_option('beaubot_suggestions', []);
$placeholders = [
    __('Comment contacter le support ?', 'beaubot'),
    __('Quels services proposez-vous ?', 'beaubot'),
    __('Quelles sont les dernières actualités ?', 'beaubot'),
    __('Quels sont vos tarifs ?', 'beaubot'),
];
$default_icons = ['message-circle', 'bulb', 'news', 'search'];
?>
<div class="wrap beaubot-admin-wrap">
    <h1>
        <span class="dashicons dashicons-lightbulb"></span>
        <?php esc_html_e('BeauBot - Suggestions', 'beaubot'); ?>
    </h1>

    <form method="post" action="options.php">
        <?php settings_fields('beaubot_suggestions_group'); ?>

        <div class="beaubot-card">
            <h2><?php esc_html_e('Suggestions de questions', 'beaubot'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configurez jusqu\'à 4 suggestions affichées sous la zone de saisie pour guider les utilisateurs. Chaque suggestion est un bouton cliquable qui envoie la question au chatbot.', 'beaubot'); ?>
            </p>

            <table class="form-table">
                <?php for ($i = 0; $i < 4; $i++):
                    $text = $suggestions[$i]['text'] ?? '';
                    $icon = $suggestions[$i]['icon'] ?? '';
                ?>
                <tr>
                    <th scope="row">
                        <label><?php printf(esc_html__('Suggestion %d', 'beaubot'), $i + 1); ?></label>
                    </th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 60px;">
                                <span class="beaubot-icon-preview" data-target="beaubot_suggestion_icon_<?php echo $i; ?>" style="width: 36px; height: 36px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #6366f1;">
                                    <i class="ti ti-<?php echo esc_attr($icon ?: $default_icons[$i]); ?>"></i>
                                </span>
                                <input type="text"
                                       id="beaubot_suggestion_icon_<?php echo $i; ?>"
                                       name="beaubot_suggestions[<?php echo $i; ?>][icon]"
                                       value="<?php echo esc_attr($icon); ?>"
                                       placeholder="<?php echo esc_attr($default_icons[$i]); ?>"
                                       class="beaubot-icon-input"
                                       style="width: 60px; text-align: center; font-size: 11px; padding: 4px;">
                            </div>
                            <input type="text"
                                   name="beaubot_suggestions[<?php echo $i; ?>][text]"
                                   value="<?php echo esc_attr($text); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($placeholders[$i]); ?>"
                                   style="flex: 1; max-width: 400px;">
                        </div>
                    </td>
                </tr>
                <?php endfor; ?>
            </table>

            <p class="description" style="margin-top: 10px;">
                <?php 
                printf(
                    esc_html__('Nom d\'icône Tabler Icons (ex: message-circle, bulb, search). Parcourez les icônes sur %s. Laissez le texte vide pour masquer une suggestion.', 'beaubot'),
                    '<a href="https://tabler.io/icons" target="_blank" rel="noopener">tabler.io/icons</a>'
                );
                ?>
            </p>
        </div>

        <?php submit_button(__('Enregistrer les suggestions', 'beaubot')); ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.beaubot-icon-input').forEach(function(input) {
        input.addEventListener('input', function() {
            var preview = document.querySelector('.beaubot-icon-preview[data-target="' + this.id + '"]');
            if (preview) {
                var iconName = this.value.trim() || this.placeholder;
                preview.innerHTML = '<i class="ti ti-' + iconName.replace(/[^a-z0-9-]/g, '') + '"></i>';
            }
        });
    });
});
</script>
