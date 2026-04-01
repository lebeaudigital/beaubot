/**
 * BeauBot Sidebar Module
 * Gère l'affichage et le positionnement de la sidebar
 */

(function() {
    'use strict';

    window.BeauBotSidebar = function(config) {
        this.config = config;
        this.isOpen = false;
        this.container = null;
        this.overlay = null;
        this.toggleButton = null;
        
        this.init();
    };

    BeauBotSidebar.prototype.init = function() {
        this.createElements();
        this.bindEvents();
        this.loadUserPreference();
        this.restoreOpenState();
    };

    /**
     * Restaurer l'état ouvert/fermé depuis localStorage
     * Désactive temporairement les transitions CSS pour éviter l'animation
     */
    BeauBotSidebar.prototype.restoreOpenState = function() {
        if (localStorage.getItem('beaubot_sidebar_open') === 'true') {
            // Désactiver les transitions pour une ouverture instantanée
            if (this.container) this.container.style.transition = 'none';
            if (this.toggleButton) this.toggleButton.style.transition = 'none';
            if (this.overlay) this.overlay.style.transition = 'none';

            this.open();

            // Réactiver les transitions après le rendu
            var self = this;
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    if (self.container) self.container.style.transition = '';
                    if (self.toggleButton) self.toggleButton.style.transition = '';
                    if (self.overlay) self.overlay.style.transition = '';
                });
            });
        }
    };

    BeauBotSidebar.prototype.createElements = function() {
        this.container = document.getElementById('beaubot-sidebar');
        if (!this.container) {
            console.error('BeauBot: Sidebar container not found');
            return;
        }
        this.overlay = document.getElementById('beaubot-overlay');
        this.toggleButton = document.getElementById('beaubot-toggle');
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

        this.handleResize();
        window.addEventListener('resize', function() {
            self.handleResize();
        });
    };

    BeauBotSidebar.prototype.loadUserPreference = function() {
        // Preferences loading (reserved for future use)
    };

    BeauBotSidebar.prototype.open = function() {
        if (!this.container) return;

        this.isOpen = true;
        this.container.classList.add('beaubot-open');
        if (this.toggleButton) this.toggleButton.classList.add('beaubot-hidden');
        if (this.overlay) this.overlay.classList.add('beaubot-visible');
        
        localStorage.setItem('beaubot_sidebar_open', 'true');
        
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

        localStorage.setItem('beaubot_sidebar_open', 'false');

        this.dispatchEvent('close');
    };

    BeauBotSidebar.prototype.toggle = function() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
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

})();
