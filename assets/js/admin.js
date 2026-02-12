/**
 * BeauBot Admin JavaScript
 * Gère les interactions dans la page de paramètres admin
 */

(function($) {
    'use strict';

    const BeauBotAdmin = {
        init() {
            this.bindEvents();
        },

        bindEvents() {
            // Toggle visibilité API key
            $('#beaubot-toggle-api-key').on('click', this.toggleApiKeyVisibility);

            // Test API
            $('#beaubot-test-api').on('click', this.testApiConnection);

            // Temperature slider
            $('#beaubot_temperature').on('input', this.updateTemperatureValue);

            // Confirmation de suppression
            $('.beaubot-delete-conversation').on('click', this.confirmDelete);

            // Régénérer l'index
            $('#beaubot-reindex').on('click', this.reindexContent);

            // Synchronisation color picker
            $('#beaubot_primary_color').on('input', this.syncColorFromPicker);
            $('#beaubot_primary_color_text').on('input', this.syncColorFromText);

            // Gestion des URLs API WordPress
            $('#beaubot-add-url').on('click', this.addApiUrl);
            $(document).on('click', '.beaubot-remove-url', this.removeApiUrl);
        },

        /**
         * Toggle la visibilité de la clé API
         */
        toggleApiKeyVisibility() {
            const input = $('#beaubot_api_key');
            const button = $(this);

            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                button.text(beaubotAdmin.strings?.hide || 'Masquer');
            } else {
                input.attr('type', 'password');
                button.text(beaubotAdmin.strings?.show || 'Afficher');
            }
        },

        /**
         * Tester la connexion à l'API
         */
        testApiConnection() {
            const button = $(this);
            const originalText = button.text();
            
            button.prop('disabled', true).text('Test en cours...');

            $.ajax({
                url: beaubotAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'beaubot_test_api',
                    nonce: beaubotAdmin.nonce,
                    api_key: $('#beaubot_api_key').val()
                },
                success(response) {
                    if (response.success) {
                        BeauBotAdmin.showNotice('success', beaubotAdmin.strings?.testSuccess || 'Connexion réussie !');
                    } else {
                        BeauBotAdmin.showNotice('error', response.data?.message || beaubotAdmin.strings?.testError || 'Erreur de connexion');
                    }
                },
                error() {
                    BeauBotAdmin.showNotice('error', beaubotAdmin.strings?.testError || 'Erreur de connexion');
                },
                complete() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Mettre à jour l'affichage de la température
         */
        updateTemperatureValue() {
            const value = $(this).val();
            $('#beaubot_temperature_value').text(value);
        },

        /**
         * Synchroniser la couleur depuis le picker vers le champ texte
         */
        syncColorFromPicker() {
            const value = $(this).val();
            $('#beaubot_primary_color_text').val(value);
        },

        /**
         * Synchroniser la couleur depuis le champ texte vers le picker
         */
        syncColorFromText() {
            const value = $(this).val();
            if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
                $('#beaubot_primary_color').val(value);
            }
        },

        /**
         * Confirmation de suppression
         */
        confirmDelete(e) {
            if (!confirm(beaubotAdmin.strings?.confirmDelete || 'Êtes-vous sûr ?')) {
                e.preventDefault();
            }
        },

        /**
         * Ajouter un champ URL API WordPress
         */
        addApiUrl() {
            const row = $(`
                <div class="beaubot-api-url-row" style="display: flex; align-items: center; margin-bottom: 8px; gap: 8px;">
                    <input type="url" 
                           name="beaubot_settings[wp_api_urls][]" 
                           value="" 
                           class="regular-text"
                           placeholder="https://example.com/wp-json/wp/v2">
                    <button type="button" class="button beaubot-remove-url" title="Supprimer" style="color: #b91c1c;">
                        <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                    </button>
                </div>
            `);
            $('#beaubot-api-urls-wrapper').append(row);
            row.find('input').focus();
        },

        /**
         * Supprimer un champ URL API WordPress
         */
        removeApiUrl() {
            const wrapper = $('#beaubot-api-urls-wrapper');
            // Garder au moins un champ
            if (wrapper.find('.beaubot-api-url-row').length > 1) {
                $(this).closest('.beaubot-api-url-row').remove();
            } else {
                // Vider le dernier champ au lieu de le supprimer
                $(this).closest('.beaubot-api-url-row').find('input').val('');
            }
        },

        /**
         * Rafraîchir le cache du contenu
         */
        reindexContent() {
            const button = $(this);
            const status = $('#beaubot-reindex-status');
            const originalHtml = button.html();
            
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Chargement en cours...');
            status.html('<span style="color: #666;">Récupération via l\'API WordPress...</span>');

            $.ajax({
                url: beaubotAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'beaubot_reindex',
                    nonce: beaubotAdmin.nonce
                },
                success(response) {
                    if (response.success) {
                        status.html('<span style="color: #059669;">' + response.data.message + '</span>');
                        BeauBotAdmin.showNotice('success', 'Cache rafraîchi avec succès !');
                        
                        // Mettre à jour l'affichage des stats
                        const sourcesInfo = response.data.sources > 1 
                            ? `<li><strong>Sources:</strong> ${response.data.sources} API(s)</li>` 
                            : '';
                        $('#beaubot-index-status').html(`
                            <span class="beaubot-status beaubot-status-success">Cache actif</span>
                            <ul style="margin-top: 10px; color: #666;">
                                <li><strong>Pages récupérées:</strong> ${response.data.count}</li>
                                ${sourcesInfo}
                                <li><strong>Taille:</strong> ${response.data.size} Ko</li>
                                <li><strong>Chargé en:</strong> ${response.data.duration}s</li>
                            </ul>
                        `);
                    } else {
                        status.html('<span style="color: #dc2626;">' + (response.data?.message || 'Erreur') + '</span>');
                        BeauBotAdmin.showNotice('error', response.data?.message || 'Erreur lors du rafraîchissement');
                    }
                },
                error() {
                    status.html('<span style="color: #dc2626;">Erreur de connexion</span>');
                    BeauBotAdmin.showNotice('error', 'Erreur de connexion au serveur');
                },
                complete() {
                    button.prop('disabled', false).html(originalHtml);
                }
            });
        },

        /**
         * Afficher une notice
         * @param {string} type - 'success' ou 'error'
         * @param {string} message
         */
        showNotice(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const notice = $(`
                <div class="notice ${noticeClass} is-dismissible beaubot-notice">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Fermer</span>
                    </button>
                </div>
            `);

            $('.wrap h1').after(notice);

            // Auto-dismiss après 5 secondes
            setTimeout(() => {
                notice.fadeOut(300, () => notice.remove());
            }, 5000);

            // Click dismiss
            notice.find('.notice-dismiss').on('click', () => {
                notice.fadeOut(300, () => notice.remove());
            });
        }
    };

    // Handler AJAX pour le test API
    $(document).ready(() => {
        BeauBotAdmin.init();
    });

    // Ajouter le handler AJAX côté serveur
    if (typeof wp !== 'undefined' && wp.ajax) {
        // WordPress AJAX
    }

})(jQuery);

// Action AJAX PHP (à ajouter dans class-beaubot-admin.php si nécessaire)
/*
add_action('wp_ajax_beaubot_test_api', function() {
    check_ajax_referer('beaubot_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Accès non autorisé']);
    }
    
    $api_key = sanitize_text_field($_POST['api_key'] ?? '');
    
    // Test temporaire avec la clé fournie
    $temp_options = get_option('beaubot_settings', []);
    $temp_options['api_key'] = $api_key;
    
    $chatgpt = new BeauBot_API_ChatGPT();
    // Utiliser la clé temporaire pour le test
    
    $result = $chatgpt->test_connection();
    
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    
    wp_send_json_success($result);
});
*/
