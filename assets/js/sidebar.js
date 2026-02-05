/**
 * BeauBot Sidebar Module
 * Gère l'affichage et le positionnement de la sidebar
 */

(function() {
    'use strict';

    window.BeauBotSidebar = function(config) {
        this.config = config;
        this.isOpen = false;
        this.position = config.sidebarPosition || 'right';
        this.container = null;
        this.overlay = null;
        this.toggleButton = null;
        
        this.init();
    };

    BeauBotSidebar.prototype.init = function() {
        this.createElements();
        this.bindEvents();
        this.loadUserPreference();
    };

    BeauBotSidebar.prototype.createElements = function() {
        this.container = document.getElementById('beaubot-sidebar');
        if (!this.container) {
            console.error('BeauBot: Sidebar container not found');
            return;
        }
        this.overlay = document.getElementById('beaubot-overlay');
        this.toggleButton = document.getElementById('beaubot-toggle');
        this.setPosition(this.position);
    };

    BeauBotSidebar.prototype.bindEvents = function() {
        var self = this;

        if (this.toggleButton) {
            this.toggleButton.addEventListener('click', function() {
                self.toggle();
            });
        }

        var closeBtn = this.container ? this.container.querySelector('.beaubot-close') : null;
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                self.close();
            });
        }

        if (this.overlay) {
            this.overlay.addEventListener('click', function() {
                self.close();
            });
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'B') {
                e.preventDefault();
                self.toggle();
            }
        });

        var positionToggle = this.container ? this.container.querySelector('.beaubot-position-toggle') : null;
        if (positionToggle) {
            positionToggle.addEventListener('click', function() {
                self.togglePosition();
            });
        }

        this.handleResize();
        window.addEventListener('resize', function() {
            self.handleResize();
        });
    };

    BeauBotSidebar.prototype.loadUserPreference = function() {
        var self = this;
        
        fetch(this.config.restUrl + 'preferences', {
            headers: {
                'X-WP-Nonce': this.config.nonce,
            },
        })
        .then(function(response) {
            if (response.ok) return response.json();
            throw new Error('Failed');
        })
        .then(function(data) {
            if (data.preferences && data.preferences.sidebar_position) {
                self.setPosition(data.preferences.sidebar_position);
            }
        })
        .catch(function(error) {
            console.log('BeauBot: Could not load preferences', error);
        });
    };

    BeauBotSidebar.prototype.saveUserPreference = function() {
        fetch(this.config.restUrl + 'preferences', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.config.nonce,
            },
            body: JSON.stringify({
                sidebar_position: this.position,
            }),
        }).catch(function(error) {
            console.log('BeauBot: Could not save preferences', error);
        });
    };

    BeauBotSidebar.prototype.open = function() {
        if (!this.container) return;

        this.isOpen = true;
        this.container.classList.add('beaubot-open');
        if (this.toggleButton) this.toggleButton.classList.add('beaubot-hidden');
        if (this.overlay) this.overlay.classList.add('beaubot-visible');
        
        var input = this.container.querySelector('.beaubot-input');
        if (input) {
            setTimeout(function() { input.focus(); }, 300);
        }

        this.dispatchEvent('open');
    };

    BeauBotSidebar.prototype.close = function() {
        if (!this.container) return;

        this.isOpen = false;
        this.container.classList.remove('beaubot-open');
        if (this.toggleButton) this.toggleButton.classList.remove('beaubot-hidden');
        if (this.overlay) this.overlay.classList.remove('beaubot-visible');

        this.dispatchEvent('close');
    };

    BeauBotSidebar.prototype.toggle = function() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    };

    BeauBotSidebar.prototype.setPosition = function(position) {
        if (!this.container) return;
        
        this.position = position;
        this.container.classList.remove('beaubot-left', 'beaubot-right');
        this.container.classList.add('beaubot-' + position);
        
        if (this.toggleButton) {
            this.toggleButton.classList.remove('beaubot-left', 'beaubot-right');
            this.toggleButton.classList.add('beaubot-' + position);
        }

        var positionText = this.container.querySelector('.beaubot-position-text');
        if (positionText) {
            positionText.textContent = position === 'left' 
                ? this.config.strings.positionRight 
                : this.config.strings.positionLeft;
        }

        this.dispatchEvent('positionChange', { position: position });
    };

    BeauBotSidebar.prototype.togglePosition = function() {
        var newPosition = this.position === 'left' ? 'right' : 'left';
        this.setPosition(newPosition);
        this.saveUserPreference();
    };

    BeauBotSidebar.prototype.handleResize = function() {
        var isMobile = window.innerWidth < 768;
        if (this.container) {
            this.container.classList.toggle('beaubot-mobile', isMobile);
        }
    };

    BeauBotSidebar.prototype.dispatchEvent = function(name, detail) {
        var event = new CustomEvent('beaubot:' + name, {
            detail: Object.assign({ sidebar: this }, detail || {}),
        });
        document.dispatchEvent(event);
    };

    BeauBotSidebar.prototype.getIsOpen = function() {
        return this.isOpen;
    };

    BeauBotSidebar.prototype.getPosition = function() {
        return this.position;
    };

})();
