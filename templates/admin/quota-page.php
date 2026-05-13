<?php
/**
 * Template : Page d'administration "Limites & Quota"
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = BeauBot_Quota::get_settings();
$quota    = BeauBot_Quota::get_instance();
$top      = $quota->get_today_top(20);
?>

<div class="wrap beaubot-admin-wrap">
    <h1>
        <span class="dashicons dashicons-chart-pie"></span>
        <?php esc_html_e('BeauBot - Limites & Quota', 'beaubot'); ?>
    </h1>

    <p class="description" style="max-width: 720px;">
        <?php esc_html_e('Configurez la limite quotidienne de jetons par utilisateur, le coût d\'une requête texte et le coût d\'une requête avec image. Le compteur s\'affiche dans le header du site, à l\'emplacement choisi via un sélecteur CSS.', 'beaubot'); ?>
    </p>

    <form method="post" action="options.php">
        <?php settings_fields('beaubot_quota_group'); ?>

        <!-- Section Activation -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Activation', 'beaubot'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_enabled"><?php esc_html_e('Activer la limite', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <label class="beaubot-switch">
                            <input type="hidden" name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[enabled]" value="0">
                            <input type="checkbox"
                                   id="beaubot_quota_enabled"
                                   name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[enabled]"
                                   value="1"
                                   <?php checked(!empty($settings['enabled'])); ?>>
                            <span class="beaubot-switch-slider"></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Quand activé, chaque utilisateur connecté est limité au nombre de jetons défini ci-dessous. Désactivé : l\'usage est illimité (aucun blocage), mais le compteur reste affiché à titre indicatif.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Limite & Coûts -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Limite quotidienne et coûts', 'beaubot'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_daily_limit"><?php esc_html_e('Limite par jour', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="beaubot_quota_daily_limit"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[daily_limit]"
                               value="<?php echo esc_attr($settings['daily_limit']); ?>"
                               min="1"
                               step="1"
                               class="small-text">
                        <span><?php esc_html_e('jetons / utilisateur / jour', 'beaubot'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Nombre maximal de jetons qu\'un utilisateur peut consommer chaque jour (réinitialisation à minuit selon le fuseau horaire du site).', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_cost_text"><?php esc_html_e('Coût d\'une requête texte', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="beaubot_quota_cost_text"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[cost_text]"
                               value="<?php echo esc_attr($settings['cost_text']); ?>"
                               min="0"
                               step="1"
                               class="small-text">
                        <span><?php esc_html_e('jeton(s)', 'beaubot'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Coût d\'une question envoyée sans image.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_cost_image"><?php esc_html_e('Coût d\'une requête avec image', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="beaubot_quota_cost_image"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[cost_image]"
                               value="<?php echo esc_attr($settings['cost_image']); ?>"
                               min="0"
                               step="1"
                               class="small-text">
                        <span><?php esc_html_e('jeton(s)', 'beaubot'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Coût supérieur recommandé (par défaut : 3) car l\'analyse d\'image consomme plus de ressources.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Nommage -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Nom du jeton', 'beaubot'); ?></h2>
            <p class="description"><?php esc_html_e('Personnalisez le vocabulaire affiché à l\'utilisateur (ex : « Demande », « Crédit », « Question »).', 'beaubot'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_token_name"><?php esc_html_e('Singulier', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="beaubot_quota_token_name"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[token_name]"
                               value="<?php echo esc_attr($settings['token_name']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Demande', 'beaubot'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_token_name_plural"><?php esc_html_e('Pluriel', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="beaubot_quota_token_name_plural"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[token_name_plural]"
                               value="<?php echo esc_attr($settings['token_name_plural']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Demandes', 'beaubot'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_short_label"><?php esc_html_e('Libellé court (badge header)', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="beaubot_quota_short_label"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[short_label]"
                               value="<?php echo esc_attr($settings['short_label']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('DEMANDES/J', 'beaubot'); ?>">
                        <p class="description"><?php esc_html_e('Ex : "DEMANDES/J", "JETONS/JOUR"…', 'beaubot'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section Affichage -->
        <div class="beaubot-card">
            <h2><?php esc_html_e('Affichage du compteur dans le header', 'beaubot'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_target_selector"><?php esc_html_e('Sélecteur CSS cible', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="beaubot_quota_target_selector"
                               name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[target_selector]"
                               value="<?php echo esc_attr($settings['target_selector']); ?>"
                               class="regular-text code"
                               placeholder=".header-right">
                        <p class="description">
                            <?php esc_html_e('Sélecteur CSS de l\'élément du header dans lequel injecter le compteur (ex : ".header-right", "#site-header .nav", "header .search-wrap").', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="beaubot_quota_position"><?php esc_html_e('Position', 'beaubot'); ?></label>
                    </th>
                    <td>
                        <?php $pos = $settings['position'] ?? 'before'; ?>
                        <select id="beaubot_quota_position" name="<?php echo esc_attr(BeauBot_Quota::OPTION_NAME); ?>[position]">
                            <option value="prepend" <?php selected($pos, 'prepend'); ?>><?php esc_html_e('Au début (à l\'intérieur)', 'beaubot'); ?></option>
                            <option value="append"  <?php selected($pos, 'append'); ?>><?php esc_html_e('À la fin (à l\'intérieur)', 'beaubot'); ?></option>
                            <option value="before"  <?php selected($pos, 'before'); ?>><?php esc_html_e('Juste avant (à gauche)', 'beaubot'); ?></option>
                            <option value="after"   <?php selected($pos, 'after'); ?>><?php esc_html_e('Juste après (à droite)', 'beaubot'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Position du compteur par rapport à l\'élément sélectionné. Choisissez "Juste avant" pour placer le badge à gauche de la barre de recherche.', 'beaubot'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Enregistrer les modifications', 'beaubot')); ?>
    </form>

    <!-- Section Top consommateurs du jour -->
    <div class="beaubot-card">
        <h2><?php esc_html_e('Consommation du jour', 'beaubot'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s = aujourd'hui formatée */
                esc_html__('Top 20 des utilisateurs ayant consommé le plus de jetons aujourd\'hui (%s).', 'beaubot'),
                esc_html(wp_date(get_option('date_format')))
            );
            ?>
        </p>

        <?php if (empty($top)): ?>
            <p><em><?php esc_html_e('Aucune consommation enregistrée aujourd\'hui.', 'beaubot'); ?></em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width: 860px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Utilisateur', 'beaubot'); ?></th>
                        <th><?php esc_html_e('Email', 'beaubot'); ?></th>
                        <th style="width: 130px;"><?php esc_html_e('Jetons utilisés', 'beaubot'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Requêtes', 'beaubot'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Action', 'beaubot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $row): ?>
                        <?php
                        $percent = $settings['daily_limit'] > 0
                            ? min(100, round(((int) $row['tokens_used'] / (int) $settings['daily_limit']) * 100))
                            : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html($row['display_name'] ?: ('#' . $row['user_id'])); ?></td>
                            <td><?php echo esc_html($row['user_email'] ?? ''); ?></td>
                            <td>
                                <strong><?php echo esc_html($row['tokens_used']); ?></strong>
                                / <?php echo esc_html($settings['daily_limit']); ?>
                                <div class="beaubot-quota-mini-bar">
                                    <span style="width: <?php echo (int) $percent; ?>%;"></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($row['requests_count']); ?></td>
                            <td>
                                <button type="button" class="button button-small beaubot-reset-quota" data-user-id="<?php echo (int) $row['user_id']; ?>">
                                    <?php esc_html_e('Réinitialiser', 'beaubot'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 12px;">
                <button type="button" class="button button-secondary" id="beaubot-reset-all-quota">
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Réinitialiser TOUS les utilisateurs (aujourd\'hui)', 'beaubot'); ?>
                </button>
                <span id="beaubot-reset-quota-status" style="margin-left: 10px;"></span>
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    function resetQuota(userId, btn, statusEl) {
        if (!window.beaubotAdmin) return;
        var formData = new FormData();
        formData.append('action', 'beaubot_reset_quota');
        formData.append('nonce', window.beaubotAdmin.nonce);
        if (userId) formData.append('user_id', userId);

        btn.disabled = true;
        if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Réinitialisation…', 'beaubot')); ?>';

        fetch(window.beaubotAdmin.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (statusEl) statusEl.textContent = (data && data.data && data.data.message) || '';
                setTimeout(function() { window.location.reload(); }, 600);
            })
            .catch(function() {
                btn.disabled = false;
                if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Erreur', 'beaubot')); ?>';
            });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.beaubot-reset-quota');
        if (btn) {
            e.preventDefault();
            resetQuota(parseInt(btn.getAttribute('data-user-id'), 10) || 0, btn, null);
        }

        var allBtn = e.target.closest('#beaubot-reset-all-quota');
        if (allBtn) {
            e.preventDefault();
            if (!confirm('<?php echo esc_js(__('Réinitialiser le quota de TOUS les utilisateurs pour aujourd\'hui ?', 'beaubot')); ?>')) return;
            resetQuota(0, allBtn, document.getElementById('beaubot-reset-quota-status'));
        }
    });
})();
</script>
