/**
 * BeauBot Chatbot - Main Module
 * Orchestre tous les modules et gère l'interface de chat
 */

import { BeauBotSidebar } from './sidebar.js';
import { BeauBotFileUpload } from './file-upload.js';
import { BeauBotConversation } from './conversation.js';

class BeauBot {
    constructor() {
        this.config = window.beaubotConfig || {};
        this.sidebar = null;
        this.fileUpload = null;
        this.conversation = null;
        this.isLoading = false;
        
        // Éléments DOM
        this.messagesContainer = null;
        this.inputField = null;
        this.sendButton = null;
        this.typingIndicator = null;

        this.init();
    }

    /**
     * Initialiser le chatbot
     */
    init() {
        // Attendre que le DOM soit prêt
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    /**
     * Configurer le chatbot
     */
    setup() {
        // Initialiser les modules
        this.sidebar = new BeauBotSidebar(this.config);
        this.fileUpload = new BeauBotFileUpload(this.config);
        this.conversation = new BeauBotConversation(this.config);

        // Récupérer les éléments DOM
        this.messagesContainer = document.getElementById('beaubot-messages');
        this.inputField = document.getElementById('beaubot-input');
        this.sendButton = document.getElementById('beaubot-send');
        this.typingIndicator = document.getElementById('beaubot-typing');

        // Lier les événements
        this.bindEvents();
        
        // Message de bienvenue
        this.showWelcomeMessage();
    }

    /**
     * Lier les événements
     */
    bindEvents() {
        // Envoi de message
        this.sendButton?.addEventListener('click', () => this.sendMessage());
        
        // Envoi avec Enter (Shift+Enter pour nouvelle ligne)
        this.inputField?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        // Auto-resize du textarea
        this.inputField?.addEventListener('input', () => this.autoResizeInput());

        // Événements des modules
        document.addEventListener('beaubot:newConversation', () => this.handleNewConversation());
        document.addEventListener('beaubot:conversationLoaded', (e) => this.handleConversationLoaded(e.detail));
        document.addEventListener('beaubot:imageSelected', (e) => this.handleImageSelected(e.detail));
        document.addEventListener('beaubot:imageRemoved', () => this.handleImageRemoved());
        document.addEventListener('beaubot:error', (e) => this.showError(e.detail.message));
    }

    /**
     * Envoyer un message
     */
    async sendMessage() {
        const message = this.inputField?.value?.trim();
        const imageData = this.fileUpload?.getImageData();

        // Validation
        if (!message && !imageData) return;
        if (this.isLoading) return;

        // Afficher le message utilisateur
        this.addMessage('user', message, imageData);

        // Réinitialiser l'input
        this.inputField.value = '';
        this.autoResizeInput();
        this.fileUpload?.removeImage();

        // Envoyer à l'API
        await this.sendToAPI(message, imageData);
    }

    /**
     * Envoyer à l'API
     * @param {string} message
     * @param {string|null} imageData
     */
    async sendToAPI(message, imageData) {
        this.setLoading(true);

        try {
            const body = {
                message: message,
                conversation_id: this.conversation?.getCurrentId(),
            };

            if (imageData) {
                body.image = imageData;
            }

            const response = await fetch(`${this.config.restUrl}chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce,
                },
                body: JSON.stringify(body),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || this.config.strings.error);
            }

            // Mettre à jour l'ID de conversation
            if (data.conversation_id) {
                this.conversation?.setCurrentId(data.conversation_id);
            }

            // Afficher la réponse
            this.addMessage('assistant', data.message.content);

        } catch (error) {
            console.error('BeauBot: API Error', error);
            this.showError(error.message || this.config.strings.networkError);
        } finally {
            this.setLoading(false);
        }
    }

    /**
     * Ajouter un message à l'affichage
     * @param {string} role - 'user' ou 'assistant'
     * @param {string} content
     * @param {string|null} imageData
     */
    addMessage(role, content, imageData = null) {
        if (!this.messagesContainer) return;

        const messageEl = document.createElement('div');
        messageEl.className = `beaubot-message beaubot-${role}`;

        // Avatar
        const avatar = role === 'user' 
            ? `<img src="${this.config.userAvatar}" alt="${this.config.userName}">`
            : `<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>`;

        // Contenu
        let contentHtml = '';
        
        if (imageData) {
            contentHtml += `<div class="beaubot-message-image"><img src="${imageData}" alt="Image"></div>`;
        }
        
        if (content) {
            contentHtml += `<div class="beaubot-message-text">${this.formatMessage(content)}</div>`;
        }

        messageEl.innerHTML = `
            <div class="beaubot-avatar">${avatar}</div>
            <div class="beaubot-message-content">${contentHtml}</div>
        `;

        this.messagesContainer.appendChild(messageEl);
        this.scrollToBottom();
    }

    /**
     * Formater le message (Markdown basique)
     * @param {string} text
     * @returns {string}
     */
    formatMessage(text) {
        // Échapper le HTML
        text = this.escapeHtml(text);

        // Code blocks
        text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>');
        
        // Inline code
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        
        // Bold
        text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        
        // Italic
        text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        
        // Links
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        
        // Line breaks
        text = text.replace(/\n/g, '<br>');

        return text;
    }

    /**
     * Afficher le message de bienvenue
     */
    showWelcomeMessage() {
        const welcomeMessage = `Bonjour ${this.config.userName} ! 👋

Je suis BeauBot, votre assistant virtuel. Je peux répondre à vos questions sur le contenu de ce site.

Vous pouvez également m'envoyer des images pour que je les analyse.

Comment puis-je vous aider ?`;

        this.addMessage('assistant', welcomeMessage);
    }

    /**
     * Gérer une nouvelle conversation
     */
    handleNewConversation() {
        if (this.messagesContainer) {
            this.messagesContainer.innerHTML = '';
        }
        this.showWelcomeMessage();
    }

    /**
     * Gérer le chargement d'une conversation
     * @param {object} detail
     */
    handleConversationLoaded(detail) {
        const conversation = detail.conversation;
        
        if (this.messagesContainer) {
            this.messagesContainer.innerHTML = '';
        }

        // Afficher les messages
        if (conversation.messages) {
            for (const msg of conversation.messages) {
                this.addMessage(msg.role, msg.content, msg.image_url);
            }
        }
    }

    /**
     * Gérer la sélection d'image
     * @param {object} detail
     */
    handleImageSelected(detail) {
        // Mettre à jour l'UI si nécessaire
        this.inputField?.focus();
    }

    /**
     * Gérer la suppression d'image
     */
    handleImageRemoved() {
        // Mettre à jour l'UI si nécessaire
    }

    /**
     * Définir l'état de chargement
     * @param {boolean} loading
     */
    setLoading(loading) {
        this.isLoading = loading;
        
        if (this.sendButton) {
            this.sendButton.disabled = loading;
        }

        if (this.typingIndicator) {
            this.typingIndicator.classList.toggle('beaubot-visible', loading);
        }

        if (loading) {
            this.scrollToBottom();
        }
    }

    /**
     * Auto-resize du textarea
     */
    autoResizeInput() {
        if (!this.inputField) return;

        this.inputField.style.height = 'auto';
        this.inputField.style.height = Math.min(this.inputField.scrollHeight, 150) + 'px';
    }

    /**
     * Scroller vers le bas
     */
    scrollToBottom() {
        if (this.messagesContainer) {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }
    }

    /**
     * Afficher une erreur
     * @param {string} message
     */
    showError(message) {
        const errorEl = document.createElement('div');
        errorEl.className = 'beaubot-message beaubot-error';
        errorEl.innerHTML = `
            <div class="beaubot-error-content">
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                <span>${this.escapeHtml(message)}</span>
            </div>
        `;

        this.messagesContainer?.appendChild(errorEl);
        this.scrollToBottom();
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
}

// Initialiser
new BeauBot();

export default BeauBot;
