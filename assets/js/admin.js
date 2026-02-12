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
         * Indexer le contenu (RAG : chunks + embeddings)
         */
        reindexContent() {
            const button = $(this);
            const status = $('#beaubot-reindex-status');
            const originalHtml = button.html();
            
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Indexation en cours...');
            status.html('<span style="color: #666;">Récupération des pages, découpage en chunks et génération des embeddings...</span>');

            $.ajax({
                url: beaubotAdmin.ajaxUrl,
                type: 'POST',
                timeout: 300000, // 5 minutes (les embeddings peuvent prendre du temps)
                data: {
                    action: 'beaubot_reindex',
                    nonce: beaubotAdmin.nonce
                },
                success(response) {
                    if (response.success) {
                        const data = response.data;
                        const isRag = data.chunks_created !== undefined;
                        
                        if (isRag && !data.rag_fallback) {
                            // Mode RAG réussi
                            const errorsHtml = (data.errors && data.errors.length > 0) 
                                ? `<li style="color: #b45309;"><strong>Avertissements:</strong> ${data.errors.join(', ')}</li>` 
                                : '';
                            
                            status.html(`<span style="color: #059669;">Indexation RAG terminée en ${data.duration}s</span>`);
                            BeauBotAdmin.showNotice('success', `Indexation réussie : ${data.chunks_created} chunks, ${data.embeddings_generated} embeddings`);
                            
                            $('#beaubot-index-status').html(`
                                <span class="beaubot-status beaubot-status-success">RAG actif (recherche sémantique)</span>
                                <ul style="margin-top: 10px; color: #666;">
                                    <li><strong>Pages indexées:</strong> ${data.pages_fetched}</li>
                                    <li><strong>Chunks:</strong> ${data.chunks_created}</li>
                                    <li><strong>Embeddings:</strong> ${data.embeddings_generated}</li>
                                    <li><strong>Durée:</strong> ${data.duration}s</li>
                                    ${errorsHtml}
                                </ul>
                            `);
                        } else {
                            // Mode fallback
                            const warningHtml = data.rag_errors && data.rag_errors.length > 0
                                ? `<br><small style="color: #b45309;">RAG: ${data.rag_errors.join(', ')}</small>`
                                : '';
                            status.html(`<span style="color: #059669;">${data.message}</span>${warningHtml}`);
                            BeauBotAdmin.showNotice('success', 'Contenu récupéré (mode fallback)');
                            
                            $('#beaubot-index-status').html(`
                                <span class="beaubot-status beaubot-status-warning">Mode fallback (contenu complet)</span>
                                <ul style="margin-top: 10px; color: #666;">
                                    <li><strong>Pages:</strong> ${data.count || '?'}</li>
                                    <li><strong>Taille:</strong> ${data.size || '?'} Ko</li>
                                </ul>
                            `);
                        }
                    } else {
                        status.html('<span style="color: #dc2626;">' + (response.data?.message || response.data?.errors?.join(', ') || 'Erreur') + '</span>');
                        BeauBotAdmin.showNotice('error', response.data?.message || 'Erreur lors de l\'indexation');
                    }
                },
                error(xhr, status_text) {
                    const msg = status_text === 'timeout' ? 'Timeout (l\'indexation prend trop de temps)' : 'Erreur de connexion';
                    status.html(`<span style="color: #dc2626;">${msg}</span>`);
                    BeauBotAdmin.showNotice('error', msg);
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
                        contentDiv.html(BeauBotAdmin.formatDiagnostics(response.data));
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
         * Formater les résultats du diagnostic en HTML
         * @param {Object} data
         * @returns {string}
         */
        formatDiagnostics(data) {
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
                    
                    // Contenu total
                    if (source.total_content_chars !== undefined) {
                        const totalKb = (source.total_content_chars / 1024).toFixed(1);
                        html += `<br><strong>Contenu total :</strong> ${source.total_content_chars} caractères (${totalKb} Ko)`;
                        if (source.empty_pages > 0) {
                            html += ` <span style="color: #b45309;">&#9888; ${source.empty_pages} page(s) vide(s)</span>`;
                        }
                    }
                    
                    // Détail par page (si disponible)
                    if (source.pages_detail && source.pages_detail.length > 0) {
                        html += `<br><br><strong>Détail par page :</strong>`;
                        html += `<table style="width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 12px;">`;
                        html += `<tr style="background: #f0f0f0;"><th style="padding: 4px; text-align: left;">Page</th><th style="padding: 4px; text-align: right;">Contenu</th><th style="padding: 4px; text-align: left;">Aperçu</th></tr>`;
                        
                        for (const page of source.pages_detail) {
                            const rowColor = page.has_content ? '' : 'background: #fef3cd;';
                            const contentStatus = page.has_content 
                                ? `<span style="color: #059669;">${page.content_chars} car.</span>` 
                                : `<span style="color: #dc2626;">&#10060; ${page.content_chars} car.</span>`;
                            
                            html += `<tr style="${rowColor}">`;
                            html += `<td style="padding: 4px; border-bottom: 1px solid #eee;">${BeauBotAdmin.escapeHtml(page.title)}</td>`;
                            html += `<td style="padding: 4px; border-bottom: 1px solid #eee; text-align: right;">${contentStatus}</td>`;
                            html += `<td style="padding: 4px; border-bottom: 1px solid #eee; color: #888; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${BeauBotAdmin.escapeHtml(page.preview.substring(0, 100))}</td>`;
                            html += `</tr>`;
                            
                            if (page.warning) {
                                html += `<tr><td colspan="3" style="padding: 2px 4px; color: #b45309; font-style: italic;">&#9888; ${BeauBotAdmin.escapeHtml(page.warning)}</td></tr>`;
                            }
                        }
                        html += `</table>`;
                    }
                    
                    // Exemples (pour sources externes)
                    if (source.sample && source.sample.length > 0 && !source.pages_detail) {
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

            // RAG status
            if (data.rag) {
                const ragColor = data.rag.indexed ? '#059669' : '#b45309';
                const ragIcon = data.rag.indexed ? '&#9989;' : '&#9888;';
                html += `<div style="border-top: 1px solid #ddd; padding-top: 12px; margin-top: 12px;">`;
                html += `<strong>${ragIcon} Mode :</strong> <span style="color: ${ragColor};">${data.rag.mode}</span><br>`;
                if (data.rag.indexed) {
                    html += `<strong>Chunks :</strong> ${data.rag.total_chunks} (${data.rag.with_embeddings} avec embeddings)<br>`;
                    html += `<strong>Pages indexées :</strong> ${data.rag.unique_pages}<br>`;
                    html += `<strong>Paramètres :</strong> chunk_size=${data.rag.chunk_size}, overlap=${data.rag.chunk_overlap}, top_k=${data.rag.top_k}`;
                } else {
                    html += `<span style="color: #b45309;">Cliquez sur "Indexer le contenu" pour activer le RAG.</span>`;
                }
                html += `</div>`;
            }

            // Cache
            html += `<div style="border-top: 1px solid #ddd; padding-top: 12px; margin-top: 12px;">`;
            html += `<strong>Cache fallback :</strong> `;
            if (data.cache.exists) {
                html += `Actif (${data.cache.size_kb} Ko / ${data.cache.size} caractères)`;
            } else {
                html += `<span style="color: #888;">Vide (normal en mode RAG)</span>`;
            }
            html += `</div>`;

            // Test du contexte
            if (data.context_test) {
                const ctxColor = data.context_test.has_content ? '#059669' : '#dc2626';
                const ctxIcon = data.context_test.has_content ? '&#9989;' : '&#10060;';
                html += `<div style="border-top: 1px solid #ddd; padding-top: 12px; margin-top: 12px;">`;
                html += `<strong>${ctxIcon} Contexte envoyé à ChatGPT :</strong> `;
                html += `<span style="color: ${ctxColor};">${data.context_test.length} caractères</span>`;
                if (data.context_test.mode) {
                    const modeColor = data.context_test.mode === 'RAG' ? '#059669' : '#b45309';
                    html += ` <span style="background: ${modeColor}; color: white; padding: 1px 8px; border-radius: 10px; font-size: 11px;">${data.context_test.mode}</span>`;
                }
                if (data.context_test.query) {
                    html += `<br><strong>Requête test :</strong> <code>${BeauBotAdmin.escapeHtml(data.context_test.query)}</code>`;
                }
                
                // Infos tokens
                if (data.context_test.tokens_est !== undefined) {
                    const pct = ((data.context_test.tokens_est / data.context_test.max_tokens) * 100).toFixed(1);
                    const tokenColor = data.context_test.is_truncated ? '#dc2626' : '#059669';
                    html += `<br><strong>Tokens estimés :</strong> <span style="color: ${tokenColor};">${data.context_test.tokens_est.toLocaleString()} / ${data.context_test.max_tokens.toLocaleString()} (${pct}%)</span>`;
                    if (data.context_test.is_truncated) {
                        html += ` <span style="color: #dc2626;">&#9888; Contexte tronqué !</span>`;
                    }
                }
                
                if (data.context_test.preview_start) {
                    html += `<br><br><strong>Début du contexte :</strong>`;
                    html += `<pre style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; font-size: 11px; max-height: 150px; overflow-y: auto;">${BeauBotAdmin.escapeHtml(data.context_test.preview_start)}</pre>`;
                }
                if (data.context_test.preview_end) {
                    html += `<strong>Fin du contexte :</strong>`;
                    html += `<pre style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; font-size: 11px; max-height: 150px; overflow-y: auto;">${BeauBotAdmin.escapeHtml(data.context_test.preview_end)}</pre>`;
                }
                html += `</div>`;
            }

            // Vérification de termes
            if (data.term_check) {
                html += `<div style="border-top: 1px solid #ddd; padding-top: 12px; margin-top: 12px;">`;
                html += `<strong>Vérification de termes dans le contexte :</strong><br>`;
                for (const [term, found] of Object.entries(data.term_check)) {
                    const icon = found ? '&#9989;' : '&#10060;';
                    const color = found ? '#059669' : '#dc2626';
                    html += `<span style="display: inline-block; margin: 2px 8px 2px 0; padding: 2px 8px; background: ${found ? '#ecfdf5' : '#fef2f2'}; border-radius: 4px; color: ${color};">${icon} ${BeauBotAdmin.escapeHtml(term)}</span>`;
                }
                html += `</div>`;
            }

            return html;
        },

        /**
         * Échapper le HTML pour l'affichage sécurisé
         * @param {string} text
         * @returns {string}
         */
        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
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
