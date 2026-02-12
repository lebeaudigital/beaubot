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

            // Diagnostic
            $('#beaubot-diagnostics').on('click', this.runDiagnostics);

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
         * Exécuter le diagnostic des sources API WordPress
         */
        runDiagnostics() {
            const button = $(this);
            const resultsDiv = $('#beaubot-diagnostics-results');
            const contentDiv = $('#beaubot-diagnostics-content');
            const originalHtml = button.html();

            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Diagnostic...');
            resultsDiv.show();
            contentDiv.html('<p style="color: #666;">Analyse des sources en cours...</p>');

            $.ajax({
                url: beaubotAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'beaubot_diagnostics',
                    nonce: beaubotAdmin.nonce
                },
                success(response) {
                    if (response.success) {
                        const data = response.data;
                        let html = '';

                        // Infos générales
                        html += `<div style="margin-bottom: 12px;">`;
                        html += `<strong>URL du site :</strong> ${data.home_url}<br>`;
                        html += `<strong>URL REST locale :</strong> ${data.rest_url}<br>`;
                        html += `<strong>WordPress :</strong> ${data.wp_version}<br>`;
                        html += `<strong>Date :</strong> ${data.timestamp}`;
                        html += `</div>`;

                        // Sources
                        html += `<div style="border-top: 1px solid #ddd; padding-top: 12px;">`;
                        html += `<strong>Sources configurées :</strong> ${data.configured_urls.join(', ')}<br><br>`;
                        
                        for (const [key, source] of Object.entries(data.sources)) {
                            const statusIcon = source.success ? '&#9989;' : '&#10060;';
                            const statusColor = source.success ? '#059669' : '#dc2626';
                            
                            html += `<div style="margin-bottom: 10px; padding: 8px; background: #fff; border-radius: 4px; border-left: 3px solid ${statusColor};">`;
                            html += `<strong>${statusIcon} ${key}</strong><br>`;
                            html += `<span style="color: #666;">Méthode : ${source.method}</span><br>`;
                            
                            if (source.success) {
                                html += `<span style="color: ${statusColor};">${source.count} page(s) récupérée(s)</span>`;
                                if (source.duration) {
                                    html += ` <span style="color: #666;">en ${source.duration}</span>`;
                                }
                                if (source.sample && source.sample.length > 0) {
                                    html += `<br><span style="color: #888; font-size: 12px;">Exemples : ${source.sample.join(', ')}</span>`;
                                }
                            } else {
                                html += `<span style="color: ${statusColor};">Échec</span>`;
                                if (source.error) {
                                    html += ` : ${source.error}`;
                                }
                                if (source.note) {
                                    html += `<br><span style="color: #888;">${source.note}</span>`;
                                }
                            }
                            
                            html += `</div>`;
                        }
                        html += `</div>`;

                        // Cache
                        html += `<div style="border-top: 1px solid #ddd; padding-top: 12px; margin-top: 12px;">`;
                        html += `<strong>Cache :</strong> `;
                        if (data.cache.exists) {
                            html += `Actif (${data.cache.size_kb} Ko / ${data.cache.size} caractères)`;
                        } else {
                            html += `<span style="color: #b45309;">Vide</span>`;
                        }
                        html += `</div>`;

                        contentDiv.html(html);
                    } else {
                        contentDiv.html('<p style="color: #dc2626;">Erreur lors du diagnostic</p>');
                    }
                },
                error() {
                    contentDiv.html('<p style="color: #dc2626;">Erreur de connexion au serveur</p>');
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

    $(document).ready(() => {
        BeauBotAdmin.init();
    });

})(jQuery);
