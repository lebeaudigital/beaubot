/**
 * BeauBot Conversation Module
 * Gère l'historique et la navigation entre les conversations
 */

export class BeauBotConversation {
    constructor(config) {
        this.config = config;
        this.currentConversationId = null;
        this.conversations = [];
        this.historyPanel = null;
        this.isHistoryOpen = false;
        
        this.init();
    }

    /**
     * Initialiser le module
     */
    init() {
        this.createElements();
        this.bindEvents();
    }

    /**
     * Créer/récupérer les éléments DOM
     */
    createElements() {
        this.historyPanel = document.getElementById('beaubot-history-panel');
        this.historyList = document.getElementById('beaubot-history-list');
        this.historyToggle = document.getElementById('beaubot-history-toggle');
        this.newConversationBtn = document.getElementById('beaubot-new-conversation');
        this.archivedToggle = document.getElementById('beaubot-archived-toggle');
    }

    /**
     * Lier les événements
     */
    bindEvents() {
        // Toggle history panel
        if (this.historyToggle) {
            this.historyToggle.addEventListener('click', () => this.toggleHistory());
        }

        // Nouvelle conversation
        if (this.newConversationBtn) {
            this.newConversationBtn.addEventListener('click', () => this.createNew());
        }

        // Toggle archived
        if (this.archivedToggle) {
            this.archivedToggle.addEventListener('click', () => this.toggleArchived());
        }

        // Click sur une conversation dans la liste
        if (this.historyList) {
            this.historyList.addEventListener('click', (e) => this.handleHistoryClick(e));
        }
    }

    /**
     * Toggle le panneau d'historique
     */
    toggleHistory() {
        this.isHistoryOpen = !this.isHistoryOpen;
        
        if (this.historyPanel) {
            this.historyPanel.classList.toggle('beaubot-open', this.isHistoryOpen);
        }

        if (this.isHistoryOpen) {
            this.loadConversations();
        }
    }

    /**
     * Toggle les conversations archivées
     */
    async toggleArchived() {
        const showArchived = this.archivedToggle?.classList.toggle('beaubot-active');
        await this.loadConversations(showArchived);
    }

    /**
     * Charger les conversations
     * @param {boolean} archived
     */
    async loadConversations(archived = false) {
        try {
            const response = await fetch(
                `${this.config.restUrl}conversations?archived=${archived}`,
                {
                    headers: {
                        'X-WP-Nonce': this.config.nonce,
                    },
                }
            );

            if (!response.ok) throw new Error('Failed to load conversations');

            const data = await response.json();
            this.conversations = data.conversations || [];
            this.renderConversations();
        } catch (error) {
            console.error('BeauBot: Error loading conversations', error);
            this.showError(this.config.strings.error);
        }
    }

    /**
     * Afficher les conversations
     */
    renderConversations() {
        if (!this.historyList) return;

        if (this.conversations.length === 0) {
            this.historyList.innerHTML = `
                <div class="beaubot-no-conversations">
                    ${this.config.strings.noConversations}
                </div>
            `;
            return;
        }

        // Grouper par date
        const grouped = this.groupByDate(this.conversations);
        let html = '';

        for (const [group, items] of Object.entries(grouped)) {
            if (items.length === 0) continue;
            
            html += `<div class="beaubot-history-group">
                <div class="beaubot-history-group-title">${group}</div>
                <ul class="beaubot-history-items">`;

            for (const conv of items) {
                const isActive = conv.id === this.currentConversationId;
                html += `
                    <li class="beaubot-history-item ${isActive ? 'beaubot-active' : ''}" 
                        data-id="${conv.id}">
                        <span class="beaubot-history-title">${this.escapeHtml(conv.title)}</span>
                        <div class="beaubot-history-actions">
                            <button class="beaubot-archive-btn" 
                                    data-action="archive" 
                                    data-id="${conv.id}"
                                    title="${conv.archived ? 'Désarchiver' : this.config.strings.archive}">
                                <svg viewBox="0 0 24 24" width="16" height="16">
                                    <path fill="currentColor" d="${conv.archived 
                                        ? 'M20.55 5.22l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.15.55L3.46 5.22C3.17 5.57 3 6.01 3 6.5V19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.49-.17-.93-.45-1.28zM12 9.5l5.5 5.5H14v2h-4v-2H6.5L12 9.5zM5.12 5l.82-1h12l.93 1H5.12z'
                                        : 'M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z'}"/>
                                </svg>
                            </button>
                            <button class="beaubot-delete-btn" 
                                    data-action="delete" 
                                    data-id="${conv.id}"
                                    title="${this.config.strings.delete}">
                                <svg viewBox="0 0 24 24" width="16" height="16">
                                    <path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                </svg>
                            </button>
                        </div>
                    </li>
                `;
            }

            html += '</ul></div>';
        }

        this.historyList.innerHTML = html;
    }

    /**
     * Grouper les conversations par date
     * @param {array} conversations
     * @returns {object}
     */
    groupByDate(conversations) {
        const groups = {
            [this.config.strings.today]: [],
            [this.config.strings.yesterday]: [],
            [this.config.strings.thisWeek]: [],
            [this.config.strings.older]: [],
        };

        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        const weekAgo = new Date(today);
        weekAgo.setDate(weekAgo.getDate() - 7);

        for (const conv of conversations) {
            const date = new Date(conv.updated_at);
            
            if (date >= today) {
                groups[this.config.strings.today].push(conv);
            } else if (date >= yesterday) {
                groups[this.config.strings.yesterday].push(conv);
            } else if (date >= weekAgo) {
                groups[this.config.strings.thisWeek].push(conv);
            } else {
                groups[this.config.strings.older].push(conv);
            }
        }

        return groups;
    }

    /**
     * Gérer le click sur l'historique
     * @param {Event} e
     */
    handleHistoryClick(e) {
        const item = e.target.closest('.beaubot-history-item');
        const actionBtn = e.target.closest('[data-action]');

        if (actionBtn) {
            e.stopPropagation();
            const action = actionBtn.dataset.action;
            const id = parseInt(actionBtn.dataset.id);

            if (action === 'delete') {
                this.deleteConversation(id);
            } else if (action === 'archive') {
                this.archiveConversation(id);
            }
            return;
        }

        if (item) {
            const id = parseInt(item.dataset.id);
            this.loadConversation(id);
        }
    }

    /**
     * Créer une nouvelle conversation
     */
    async createNew() {
        this.currentConversationId = null;
        this.dispatchEvent('newConversation');
        
        // Fermer l'historique
        if (this.isHistoryOpen) {
            this.toggleHistory();
        }
    }

    /**
     * Charger une conversation
     * @param {number} id
     */
    async loadConversation(id) {
        try {
            const response = await fetch(
                `${this.config.restUrl}conversations/${id}`,
                {
                    headers: {
                        'X-WP-Nonce': this.config.nonce,
                    },
                }
            );

            if (!response.ok) throw new Error('Failed to load conversation');

            const data = await response.json();
            this.currentConversationId = id;
            
            this.dispatchEvent('conversationLoaded', { 
                conversation: data.conversation 
            });

            // Mettre à jour l'UI
            this.updateActiveItem(id);
            
            // Fermer l'historique sur mobile
            if (window.innerWidth < 768 && this.isHistoryOpen) {
                this.toggleHistory();
            }
        } catch (error) {
            console.error('BeauBot: Error loading conversation', error);
            this.showError(this.config.strings.error);
        }
    }

    /**
     * Archiver/Désarchiver une conversation
     * @param {number} id
     */
    async archiveConversation(id) {
        try {
            const conv = this.conversations.find(c => c.id === id);
            const newArchived = !conv?.archived;

            const response = await fetch(
                `${this.config.restUrl}conversations/${id}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce,
                    },
                    body: JSON.stringify({ archived: newArchived }),
                }
            );

            if (!response.ok) throw new Error('Failed to archive conversation');

            // Recharger la liste
            await this.loadConversations(this.archivedToggle?.classList.contains('beaubot-active'));
            
            this.dispatchEvent('conversationArchived', { id, archived: newArchived });
        } catch (error) {
            console.error('BeauBot: Error archiving conversation', error);
            this.showError(this.config.strings.error);
        }
    }

    /**
     * Supprimer une conversation
     * @param {number} id
     */
    async deleteConversation(id) {
        if (!confirm(this.config.strings.confirmDelete)) {
            return;
        }

        try {
            const response = await fetch(
                `${this.config.restUrl}conversations/${id}`,
                {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': this.config.nonce,
                    },
                }
            );

            if (!response.ok) throw new Error('Failed to delete conversation');

            // Si c'est la conversation actuelle, créer une nouvelle
            if (id === this.currentConversationId) {
                this.createNew();
            }

            // Recharger la liste
            await this.loadConversations(this.archivedToggle?.classList.contains('beaubot-active'));
            
            this.dispatchEvent('conversationDeleted', { id });
        } catch (error) {
            console.error('BeauBot: Error deleting conversation', error);
            this.showError(this.config.strings.error);
        }
    }

    /**
     * Mettre à jour l'item actif
     * @param {number} id
     */
    updateActiveItem(id) {
        if (!this.historyList) return;

        // Retirer l'ancienne sélection
        this.historyList.querySelectorAll('.beaubot-active').forEach(el => {
            el.classList.remove('beaubot-active');
        });

        // Ajouter la nouvelle
        const item = this.historyList.querySelector(`[data-id="${id}"]`);
        item?.classList.add('beaubot-active');
    }

    /**
     * Obtenir l'ID de la conversation courante
     * @returns {number|null}
     */
    getCurrentId() {
        return this.currentConversationId;
    }

    /**
     * Définir l'ID de la conversation courante
     * @param {number|null} id
     */
    setCurrentId(id) {
        this.currentConversationId = id;
        if (id) {
            this.updateActiveItem(id);
        }
    }

    /**
     * Échapper le HTML
     * @param {string} text
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Afficher une erreur
     * @param {string} message
     */
    showError(message) {
        this.dispatchEvent('error', { message });
    }

    /**
     * Dispatch un événement personnalisé
     * @param {string} name
     * @param {object} detail
     */
    dispatchEvent(name, detail = {}) {
        const event = new CustomEvent(`beaubot:${name}`, {
            detail: { conversation: this, ...detail },
        });
        document.dispatchEvent(event);
    }
}

export default BeauBotConversation;
