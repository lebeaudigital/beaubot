/**
 * BeauBot Chatbot - Main Module
 * Orchestre tous les modules et gère l'interface de chat
 */

(function() {
    'use strict';

    window.BeauBot = function() {
        this.config = window.beaubotConfig || {};
        this.sidebar = null;
        this.fileUpload = null;
        this.conversation = null;
        this.isLoading = false;
        
        this.messagesContainer = null;
        this.inputField = null;
        this.sendButton = null;
        this.typingIndicator = null;

        this.init();
    };

    BeauBot.prototype.init = function() {
        var self = this;
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                self.setup();
            });
        } else {
            this.setup();
        }
    };

    BeauBot.prototype.setup = function() {
        // Initialiser les modules
        this.sidebar = new window.BeauBotSidebar(this.config);
        this.fileUpload = new window.BeauBotFileUpload(this.config);
        this.conversation = new window.BeauBotConversation(this.config);

        // Récupérer les éléments DOM
        this.messagesContainer = document.getElementById('beaubot-messages');
        this.inputField = document.getElementById('beaubot-input');
        this.sendButton = document.getElementById('beaubot-send');
        this.typingIndicator = document.getElementById('beaubot-typing');

        // Lier les événements
        this.bindEvents();
        
        // Restaurer la conversation sauvegardée ou afficher le message de bienvenue
        var savedConversationId = localStorage.getItem('beaubot_conversation_id');
        if (savedConversationId) {
            this.conversation.loadConversation(parseInt(savedConversationId));
        } else {
            this.showWelcomeMessage();
        }
    };

    BeauBot.prototype.bindEvents = function() {
        var self = this;

        if (this.sendButton) {
            this.sendButton.addEventListener('click', function() {
                self.sendMessage();
            });
        }
        
        if (this.inputField) {
            this.inputField.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            this.inputField.addEventListener('input', function() {
                self.autoResizeInput();
            });
        }

        document.addEventListener('beaubot:newConversation', function() {
            self.handleNewConversation();
        });
        
        document.addEventListener('beaubot:conversationLoaded', function(e) {
            self.handleConversationLoaded(e.detail);
        });
        
        document.addEventListener('beaubot:imageSelected', function(e) {
            self.handleImageSelected(e.detail);
        });
        
        document.addEventListener('beaubot:imageRemoved', function() {
            self.handleImageRemoved();
        });
        
        document.addEventListener('beaubot:error', function(e) {
            self.showError(e.detail.message);
        });
    };

    BeauBot.prototype.sendMessage = function() {
        var message = this.inputField ? this.inputField.value.trim() : '';
        var imageData = this.fileUpload ? this.fileUpload.getImageData() : null;

        if (!message && !imageData) return;
        if (this.isLoading) return;

        this.addMessage('user', message, imageData);

        this.inputField.value = '';
        this.autoResizeInput();
        if (this.fileUpload) this.fileUpload.removeImage();

        this.sendToAPI(message, imageData);
    };

    BeauBot.prototype.sendToAPI = function(message, imageData) {
        var self = this;
        this.setLoading(true);

        var body = {
            message: message,
            conversation_id: this.conversation ? this.conversation.getCurrentId() : null,
        };

        if (imageData) {
            body.image = imageData;
        }

        fetch(this.config.restUrl + 'chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.config.nonce,
            },
            body: JSON.stringify(body),
        })
        .then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok) {
                    throw new Error(data.message || self.config.strings.error);
                }
                return data;
            });
        })
        .then(function(data) {
            if (data.conversation_id && self.conversation) {
                self.conversation.setCurrentId(data.conversation_id);
            }
            self.addMessage('assistant', data.message.content);
        })
        .catch(function(error) {
            console.error('BeauBot: API Error', error);
            self.showError(error.message || self.config.strings.networkError);
        })
        .finally(function() {
            self.setLoading(false);
        });
    };

    BeauBot.prototype.addMessage = function(role, content, imageData) {
        if (!this.messagesContainer) return;

        var messageEl = document.createElement('div');
        messageEl.className = 'beaubot-message beaubot-' + role;

        var avatar = role === 'user' 
            ? '<img src="' + this.config.userAvatar + '" alt="' + this.config.userName + '">'
            : '<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>';

        var contentHtml = '';
        
        if (imageData) {
            contentHtml += '<div class="beaubot-message-image"><img src="' + imageData + '" alt="Image"></div>';
        }
        
        if (content) {
            contentHtml += '<div class="beaubot-message-text">' + this.formatMessage(content) + '</div>';
        }

        messageEl.innerHTML = 
            '<div class="beaubot-avatar">' + avatar + '</div>' +
            '<div class="beaubot-message-content">' + contentHtml + '</div>';

        this.messagesContainer.appendChild(messageEl);
        this.scrollToBottom();
    };

    BeauBot.prototype.formatMessage = function(text) {
        text = this.escapeHtml(text);
        text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>');
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        text = text.replace(/\n/g, '<br>');
        return text;
    };

    BeauBot.prototype.showWelcomeMessage = function() {
        var botName = this.config.botName || 'BeauBot';
        var welcomeMessage = 'Bonjour ' + this.config.userName + ' ! 👋\n\n' +
            'Je suis ' + botName + ', votre assistant IA. Je peux répondre à vos questions sur le contenu de ce site.\n\n' +
            'Comment puis-je vous aider aujourd\'hui ?';

        this.addMessage('assistant', welcomeMessage);
    };

    BeauBot.prototype.handleNewConversation = function() {
        if (this.messagesContainer) {
            this.messagesContainer.innerHTML = '';
        }
        this.showWelcomeMessage();
    };

    BeauBot.prototype.handleConversationLoaded = function(detail) {
        var conversation = detail.conversation;
        
        if (this.messagesContainer) {
            this.messagesContainer.innerHTML = '';
        }

        if (conversation.messages) {
            for (var i = 0; i < conversation.messages.length; i++) {
                var msg = conversation.messages[i];
                this.addMessage(msg.role, msg.content, msg.image_url);
            }
        }
    };

    BeauBot.prototype.handleImageSelected = function(detail) {
        if (this.inputField) this.inputField.focus();
    };

    BeauBot.prototype.handleImageRemoved = function() {
        // Placeholder
    };

    BeauBot.prototype.setLoading = function(loading) {
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
    };

    BeauBot.prototype.autoResizeInput = function() {
        if (!this.inputField) return;

        this.inputField.style.height = 'auto';
        this.inputField.style.height = Math.min(this.inputField.scrollHeight, 150) + 'px';
    };

    BeauBot.prototype.scrollToBottom = function() {
        if (this.messagesContainer) {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }
    };

    BeauBot.prototype.showError = function(message) {
        var errorEl = document.createElement('div');
        errorEl.className = 'beaubot-message beaubot-error';
        errorEl.innerHTML = 
            '<div class="beaubot-error-content">' +
                '<svg viewBox="0 0 24 24" width="20" height="20">' +
                    '<path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>' +
                '</svg>' +
                '<span>' + this.escapeHtml(message) + '</span>' +
            '</div>';

        if (this.messagesContainer) {
            this.messagesContainer.appendChild(errorEl);
        }
        this.scrollToBottom();
    };

    BeauBot.prototype.escapeHtml = function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    // Auto-initialisation
    document.addEventListener('DOMContentLoaded', function() {
        if (window.beaubotConfig) {
            new window.BeauBot();
        }
    });

})();
