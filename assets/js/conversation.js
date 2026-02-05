/**
 * BeauBot Conversation Module
 * Gère l'historique et la navigation entre les conversations
 */

(function() {
    'use strict';

    window.BeauBotConversation = function(config) {
        this.config = config;
        this.currentConversationId = null;
        this.conversations = [];
        this.historyPanel = null;
        this.isHistoryOpen = false;
        
        this.init();
    };

    BeauBotConversation.prototype.init = function() {
        this.createElements();
        this.bindEvents();
    };

    BeauBotConversation.prototype.createElements = function() {
        this.historyPanel = document.getElementById('beaubot-history-panel');
        this.historyList = document.getElementById('beaubot-history-list');
        this.historyToggle = document.getElementById('beaubot-history-toggle');
        this.newConversationBtn = document.getElementById('beaubot-new-conversation');
        this.archivedToggle = document.getElementById('beaubot-archived-toggle');
    };

    BeauBotConversation.prototype.bindEvents = function() {
        var self = this;

        if (this.historyToggle) {
            this.historyToggle.addEventListener('click', function() {
                self.toggleHistory();
            });
        }

        if (this.newConversationBtn) {
            this.newConversationBtn.addEventListener('click', function() {
                self.createNew();
            });
        }

        if (this.archivedToggle) {
            this.archivedToggle.addEventListener('click', function() {
                self.toggleArchived();
            });
        }

        if (this.historyList) {
            this.historyList.addEventListener('click', function(e) {
                self.handleHistoryClick(e);
            });
        }
    };

    BeauBotConversation.prototype.toggleHistory = function() {
        this.isHistoryOpen = !this.isHistoryOpen;
        
        if (this.historyPanel) {
            this.historyPanel.classList.toggle('beaubot-open', this.isHistoryOpen);
        }

        if (this.isHistoryOpen) {
            this.loadConversations();
        }
    };

    BeauBotConversation.prototype.toggleArchived = function() {
        var showArchived = this.archivedToggle ? this.archivedToggle.classList.toggle('beaubot-active') : false;
        this.loadConversations(showArchived);
    };

    BeauBotConversation.prototype.loadConversations = function(archived) {
        var self = this;
        archived = archived || false;

        fetch(this.config.restUrl + 'conversations?archived=' + archived, {
            headers: {
                'X-WP-Nonce': this.config.nonce,
            },
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to load conversations');
            return response.json();
        })
        .then(function(data) {
            self.conversations = data.conversations || [];
            self.renderConversations();
        })
        .catch(function(error) {
            console.error('BeauBot: Error loading conversations', error);
            self.showError(self.config.strings.error);
        });
    };

    BeauBotConversation.prototype.renderConversations = function() {
        if (!this.historyList) return;

        if (this.conversations.length === 0) {
            this.historyList.innerHTML = '<div class="beaubot-no-conversations">' + this.config.strings.noConversations + '</div>';
            return;
        }

        var grouped = this.groupByDate(this.conversations);
        var html = '';

        for (var group in grouped) {
            if (!grouped.hasOwnProperty(group)) continue;
            var items = grouped[group];
            if (items.length === 0) continue;
            
            html += '<div class="beaubot-history-group">' +
                '<div class="beaubot-history-group-title">' + group + '</div>' +
                '<ul class="beaubot-history-items">';

            for (var i = 0; i < items.length; i++) {
                var conv = items[i];
                var isActive = conv.id === this.currentConversationId;
                var archiveIcon = conv.archived 
                    ? 'M20.55 5.22l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.15.55L3.46 5.22C3.17 5.57 3 6.01 3 6.5V19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.49-.17-.93-.45-1.28zM12 9.5l5.5 5.5H14v2h-4v-2H6.5L12 9.5zM5.12 5l.82-1h12l.93 1H5.12z'
                    : 'M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z';

                html += '<li class="beaubot-history-item ' + (isActive ? 'beaubot-active' : '') + '" data-id="' + conv.id + '">' +
                    '<span class="beaubot-history-title">' + this.escapeHtml(conv.title) + '</span>' +
                    '<div class="beaubot-history-actions">' +
                        '<button class="beaubot-archive-btn" data-action="archive" data-id="' + conv.id + '" title="' + (conv.archived ? 'Désarchiver' : this.config.strings.archive) + '">' +
                            '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="' + archiveIcon + '"/></svg>' +
                        '</button>' +
                        '<button class="beaubot-delete-btn" data-action="delete" data-id="' + conv.id + '" title="' + this.config.strings.delete + '">' +
                            '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</li>';
            }

            html += '</ul></div>';
        }

        this.historyList.innerHTML = html;
    };

    BeauBotConversation.prototype.groupByDate = function(conversations) {
        var groups = {};
        groups[this.config.strings.today] = [];
        groups[this.config.strings.yesterday] = [];
        groups[this.config.strings.thisWeek] = [];
        groups[this.config.strings.older] = [];

        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        var weekAgo = new Date(today);
        weekAgo.setDate(weekAgo.getDate() - 7);

        for (var i = 0; i < conversations.length; i++) {
            var conv = conversations[i];
            var date = new Date(conv.updated_at);
            
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
    };

    BeauBotConversation.prototype.handleHistoryClick = function(e) {
        var item = e.target.closest('.beaubot-history-item');
        var actionBtn = e.target.closest('[data-action]');

        if (actionBtn) {
            e.stopPropagation();
            var action = actionBtn.dataset.action;
            var id = parseInt(actionBtn.dataset.id);

            if (action === 'delete') {
                this.deleteConversation(id);
            } else if (action === 'archive') {
                this.archiveConversation(id);
            }
            return;
        }

        if (item) {
            var id = parseInt(item.dataset.id);
            this.loadConversation(id);
        }
    };

    BeauBotConversation.prototype.createNew = function() {
        this.currentConversationId = null;
        this.dispatchEvent('newConversation');
        
        if (this.isHistoryOpen) {
            this.toggleHistory();
        }
    };

    BeauBotConversation.prototype.loadConversation = function(id) {
        var self = this;

        fetch(this.config.restUrl + 'conversations/' + id, {
            headers: {
                'X-WP-Nonce': this.config.nonce,
            },
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to load conversation');
            return response.json();
        })
        .then(function(data) {
            self.currentConversationId = id;
            self.dispatchEvent('conversationLoaded', { conversation: data.conversation });
            self.updateActiveItem(id);
            
            if (window.innerWidth < 768 && self.isHistoryOpen) {
                self.toggleHistory();
            }
        })
        .catch(function(error) {
            console.error('BeauBot: Error loading conversation', error);
            self.showError(self.config.strings.error);
        });
    };

    BeauBotConversation.prototype.archiveConversation = function(id) {
        var self = this;
        var conv = null;
        
        for (var i = 0; i < this.conversations.length; i++) {
            if (this.conversations[i].id === id) {
                conv = this.conversations[i];
                break;
            }
        }
        
        var newArchived = conv ? !conv.archived : true;

        fetch(this.config.restUrl + 'conversations/' + id, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.config.nonce,
            },
            body: JSON.stringify({ archived: newArchived }),
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to archive conversation');
            var showArchived = self.archivedToggle ? self.archivedToggle.classList.contains('beaubot-active') : false;
            self.loadConversations(showArchived);
            self.dispatchEvent('conversationArchived', { id: id, archived: newArchived });
        })
        .catch(function(error) {
            console.error('BeauBot: Error archiving conversation', error);
            self.showError(self.config.strings.error);
        });
    };

    BeauBotConversation.prototype.deleteConversation = function(id) {
        var self = this;

        if (!confirm(this.config.strings.confirmDelete)) {
            return;
        }

        fetch(this.config.restUrl + 'conversations/' + id, {
            method: 'DELETE',
            headers: {
                'X-WP-Nonce': this.config.nonce,
            },
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to delete conversation');
            
            if (id === self.currentConversationId) {
                self.createNew();
            }
            
            var showArchived = self.archivedToggle ? self.archivedToggle.classList.contains('beaubot-active') : false;
            self.loadConversations(showArchived);
            self.dispatchEvent('conversationDeleted', { id: id });
        })
        .catch(function(error) {
            console.error('BeauBot: Error deleting conversation', error);
            self.showError(self.config.strings.error);
        });
    };

    BeauBotConversation.prototype.updateActiveItem = function(id) {
        if (!this.historyList) return;

        var activeItems = this.historyList.querySelectorAll('.beaubot-active');
        for (var i = 0; i < activeItems.length; i++) {
            activeItems[i].classList.remove('beaubot-active');
        }

        var item = this.historyList.querySelector('[data-id="' + id + '"]');
        if (item) item.classList.add('beaubot-active');
    };

    BeauBotConversation.prototype.getCurrentId = function() {
        return this.currentConversationId;
    };

    BeauBotConversation.prototype.setCurrentId = function(id) {
        this.currentConversationId = id;
        if (id) {
            this.updateActiveItem(id);
        }
    };

    BeauBotConversation.prototype.escapeHtml = function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    BeauBotConversation.prototype.showError = function(message) {
        this.dispatchEvent('error', { message: message });
    };

    BeauBotConversation.prototype.dispatchEvent = function(name, detail) {
        var event = new CustomEvent('beaubot:' + name, {
            detail: Object.assign({ conversation: this }, detail || {}),
        });
        document.dispatchEvent(event);
    };

})();
