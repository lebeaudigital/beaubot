/**
 * BeauBot Admin Conversations
 * Gère la page des conversations dans le backoffice WordPress
 */

(function($) {
    'use strict';

    const BeauBotConversations = {

        /** URL de base de l'API admin */
        apiBase: '',

        /** Nonce WP REST */
        nonce: '',

        /**
         * Initialiser le module
         */
        init() {
            if (typeof beaubotConversations === 'undefined') {
                return;
            }

            this.apiBase = beaubotConversations.apiBase;
            this.nonce = beaubotConversations.nonce;

            this.bindEvents();
        },

        /**
         * Lier les événements
         */
        bindEvents() {
            // Sélection individuelle
            $(document).on('change', '.beaubot-select-conversation', this.updateBulkState.bind(this));

            // Tout sélectionner / désélectionner
            $('#beaubot-select-all').on('change', this.toggleSelectAll.bind(this));

            // Actions en masse
            $('#beaubot-bulk-delete').on('click', () => this.bulkAction('delete'));
            $('#beaubot-bulk-archive').on('click', () => this.bulkAction('archive'));
            $('#beaubot-bulk-unarchive').on('click', () => this.bulkAction('unarchive'));

            // Voir une conversation
            $(document).on('click', '.beaubot-view-conversation', this.viewConversation.bind(this));

            // Supprimer une conversation
            $(document).on('click', '.beaubot-delete-conversation', this.deleteConversation.bind(this));

            // Archiver une conversation
            $(document).on('click', '.beaubot-archive-conversation', this.archiveConversation.bind(this));

            // Désarchiver une conversation
            $(document).on('click', '.beaubot-unarchive-conversation', this.unarchiveConversation.bind(this));

            // Fermer le modal
            $('.beaubot-modal-close, .beaubot-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#beaubot-conversation-modal').hide();
                }
            });

            // Filtres
            $('#beaubot-filter-status, #beaubot-filter-user').on('change', this.applyFilters.bind(this));
        },

        // =====================================================================
        // Sélection et actions en masse
        // =====================================================================

        /**
         * Tout sélectionner / désélectionner
         */
        toggleSelectAll() {
            const checked = $('#beaubot-select-all').is(':checked');
            $('.beaubot-select-conversation').prop('checked', checked);
            this.updateBulkState();
        },

        /**
         * Mettre à jour l'état des boutons d'actions en masse
         */
        updateBulkState() {
            const selected = this.getSelectedIds();
            const count = selected.length;
            const $toolbar = $('#beaubot-bulk-toolbar');
            const $count = $('#beaubot-selected-count');

            if (count > 0) {
                $toolbar.addClass('active');
                $count.text(count);
            } else {
                $toolbar.removeClass('active');
            }

            // Mettre à jour la checkbox "tout sélectionner"
            const total = $('.beaubot-select-conversation').length;
            const $selectAll = $('#beaubot-select-all');
            $selectAll.prop('checked', count > 0 && count === total);
            $selectAll.prop('indeterminate', count > 0 && count < total);
        },

        /**
         * Récupérer les IDs sélectionnés
         * @returns {number[]}
         */
        getSelectedIds() {
            return $('.beaubot-select-conversation:checked').map(function() {
                return parseInt($(this).val(), 10);
            }).get();
        },

        /**
         * Exécuter une action en masse
         * @param {string} action - 'delete', 'archive', 'unarchive'
         */
        bulkAction(action) {
            const ids = this.getSelectedIds();
            if (ids.length === 0) {
                return;
            }

            const messages = {
                delete: `Supprimer ${ids.length} conversation(s) ? Cette action est irréversible.`,
                archive: `Archiver ${ids.length} conversation(s) ?`,
                unarchive: `Désarchiver ${ids.length} conversation(s) ?`,
            };

            if (!confirm(messages[action])) {
                return;
            }

            const $toolbar = $('#beaubot-bulk-toolbar');
            $toolbar.addClass('loading');

            $.ajax({
                url: this.apiBase + 'admin/conversations/bulk',
                method: 'POST',
                headers: { 'X-WP-Nonce': this.nonce },
                contentType: 'application/json',
                data: JSON.stringify({ action, ids }),
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.message);
                        // Recharger la page pour refléter les changements
                        setTimeout(() => location.reload(), 500);
                    } else {
                        this.showNotice('error', response.message || 'Erreur lors de l\'action en masse.');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Erreur de connexion au serveur.');
                },
                complete: () => {
                    $toolbar.removeClass('loading');
                },
            });
        },

        // =====================================================================
        // Actions individuelles
        // =====================================================================

        /**
         * Voir une conversation (modal)
         * @param {Event} e
         */
        viewConversation(e) {
            const id = $(e.currentTarget).data('id');
            const $modal = $('#beaubot-conversation-modal');
            const $messages = $('#beaubot-modal-messages');
            const $title = $('#beaubot-modal-title');

            $messages.html('<p style="text-align:center;color:#64748b;">Chargement...</p>');
            $modal.show();

            $.ajax({
                url: this.apiBase + 'admin/conversations/' + id,
                headers: { 'X-WP-Nonce': this.nonce },
                success: (response) => {
                    if (response.success && response.conversation) {
                        const conv = response.conversation;
                        $title.text(conv.title + ' — ' + (conv.author_name || ''));

                        let html = '';
                        if (conv.messages && conv.messages.length > 0) {
                            conv.messages.forEach((msg) => {
                                html += '<div class="beaubot-modal-message ' + this.escapeAttr(msg.role) + '">';
                                html += '<strong>' + (msg.role === 'user' ? 'Utilisateur' : 'BeauBot') + '</strong><br>';
                                html += this.escapeHtml(msg.content).replace(/\n/g, '<br>');
                                if (msg.image_url) {
                                    html += '<br><img src="' + this.escapeAttr(msg.image_url) + '" alt="Image">';
                                }
                                html += '</div>';
                            });
                        } else {
                            html = '<p>Aucun message</p>';
                        }

                        $messages.html(html);
                    }
                },
                error: () => {
                    $messages.html('<p style="color:#ef4444;">Erreur lors du chargement de la conversation.</p>');
                },
            });
        },

        /**
         * Supprimer une conversation
         * @param {Event} e
         */
        deleteConversation(e) {
            if (!confirm('Êtes-vous sûr de vouloir supprimer cette conversation ? Cette action est irréversible.')) {
                return;
            }

            const $btn = $(e.currentTarget);
            const id = $btn.data('id');
            const $row = $btn.closest('tr');

            $btn.prop('disabled', true);

            $.ajax({
                url: this.apiBase + 'admin/conversations/' + id,
                method: 'DELETE',
                headers: { 'X-WP-Nonce': this.nonce },
                success: (response) => {
                    if (response.success) {
                        $row.fadeOut(300, function() { $(this).remove(); });
                        this.showNotice('success', 'Conversation supprimée.');
                        this.updateBulkState();
                    }
                },
                error: () => {
                    this.showNotice('error', 'Erreur lors de la suppression.');
                    $btn.prop('disabled', false);
                },
            });
        },

        /**
         * Archiver une conversation
         * @param {Event} e
         */
        archiveConversation(e) {
            const $btn = $(e.currentTarget);
            const id = $btn.data('id');

            $btn.prop('disabled', true);

            $.ajax({
                url: this.apiBase + 'admin/conversations/' + id,
                method: 'POST',
                headers: { 'X-WP-Nonce': this.nonce },
                contentType: 'application/json',
                data: JSON.stringify({ archived: true }),
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Conversation archivée.');
                        setTimeout(() => location.reload(), 500);
                    }
                },
                error: () => {
                    this.showNotice('error', 'Erreur lors de l\'archivage.');
                    $btn.prop('disabled', false);
                },
            });
        },

        /**
         * Désarchiver une conversation
         * @param {Event} e
         */
        unarchiveConversation(e) {
            const $btn = $(e.currentTarget);
            const id = $btn.data('id');

            $btn.prop('disabled', true);

            $.ajax({
                url: this.apiBase + 'admin/conversations/' + id,
                method: 'POST',
                headers: { 'X-WP-Nonce': this.nonce },
                contentType: 'application/json',
                data: JSON.stringify({ archived: false }),
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Conversation désarchivée.');
                        setTimeout(() => location.reload(), 500);
                    }
                },
                error: () => {
                    this.showNotice('error', 'Erreur lors de la désarchivage.');
                    $btn.prop('disabled', false);
                },
            });
        },

        // =====================================================================
        // Filtres
        // =====================================================================

        /**
         * Appliquer les filtres (status + utilisateur) via rechargement avec paramètres GET
         */
        applyFilters() {
            const status = $('#beaubot-filter-status').val();
            const userId = $('#beaubot-filter-user').val();
            const url = new URL(window.location.href);

            if (status && status !== 'all') {
                url.searchParams.set('conv_status', status);
            } else {
                url.searchParams.delete('conv_status');
            }

            if (userId && userId !== '0') {
                url.searchParams.set('conv_user', userId);
            } else {
                url.searchParams.delete('conv_user');
            }

            window.location.href = url.toString();
        },

        // =====================================================================
        // Utilitaires
        // =====================================================================

        /**
         * Échapper le HTML
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
         * Échapper un attribut HTML
         * @param {string} text
         * @returns {string}
         */
        escapeAttr(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },

        /**
         * Afficher une notice WordPress
         * @param {string} type - 'success' ou 'error'
         * @param {string} message
         */
        showNotice(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $(`
                <div class="notice ${noticeClass} is-dismissible beaubot-notice">
                    <p>${this.escapeHtml(message)}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Fermer</span>
                    </button>
                </div>
            `);

            // Supprimer les anciennes notices
            $('.beaubot-notice').remove();

            $('.wrap h1').first().after($notice);

            setTimeout(() => {
                $notice.fadeOut(300, () => $notice.remove());
            }, 5000);

            $notice.find('.notice-dismiss').on('click', () => {
                $notice.fadeOut(300, () => $notice.remove());
            });
        },
    };

    $(document).ready(() => {
        BeauBotConversations.init();
    });

})(jQuery);
