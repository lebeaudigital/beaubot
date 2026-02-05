/**
 * BeauBot Sidebar Module
 * Gère l'affichage et le positionnement de la sidebar
 */

export class BeauBotSidebar {
    constructor(config) {
        this.config = config;
        this.isOpen = false;
        this.position = config.sidebarPosition || 'right';
        this.container = null;
        this.overlay = null;
        this.toggleButton = null;
        
        this.init();
    }

    /**
     * Initialiser la sidebar
     */
    init() {
        this.createElements();
        this.bindEvents();
        this.loadUserPreference();
    }

    /**
     * Créer les éléments DOM
     */
    createElements() {
        // Conteneur principal
        this.container = document.getElementById('beaubot-sidebar');
        if (!this.container) {
            console.error('BeauBot: Sidebar container not found');
            return;
        }

        // Overlay pour mobile
        this.overlay = document.getElementById('beaubot-overlay');

        // Bouton toggle
        this.toggleButton = document.getElementById('beaubot-toggle');

        // Appliquer la position initiale
        this.setPosition(this.position);
    }

    /**
     * Lier les événements
     */
    bindEvents() {
        // Toggle button
        if (this.toggleButton) {
            this.toggleButton.addEventListener('click', () => this.toggle());
        }

        // Close button
        const closeBtn = this.container?.querySelector('.beaubot-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }

        // Overlay click
        if (this.overlay) {
            this.overlay.addEventListener('click', () => this.close());
        }

        // Keyboard shortcut (Ctrl/Cmd + Shift + B)
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'B') {
                e.preventDefault();
                this.toggle();
            }
        });

        // Position toggle
        const positionToggle = this.container?.querySelector('.beaubot-position-toggle');
        if (positionToggle) {
            positionToggle.addEventListener('click', () => this.togglePosition());
        }

        // Responsive
        this.handleResize();
        window.addEventListener('resize', () => this.handleResize());
    }

    /**
     * Charger la préférence utilisateur
     */
    async loadUserPreference() {
        try {
            const response = await fetch(`${this.config.restUrl}preferences`, {
                headers: {
                    'X-WP-Nonce': this.config.nonce,
                },
            });

            if (response.ok) {
                const data = await response.json();
                if (data.preferences?.sidebar_position) {
                    this.setPosition(data.preferences.sidebar_position);
                }
            }
        } catch (error) {
            console.log('BeauBot: Could not load preferences', error);
        }
    }

    /**
     * Sauvegarder la préférence utilisateur
     */
    async saveUserPreference() {
        try {
            await fetch(`${this.config.restUrl}preferences`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce,
                },
                body: JSON.stringify({
                    sidebar_position: this.position,
                }),
            });
        } catch (error) {
            console.log('BeauBot: Could not save preferences', error);
        }
    }

    /**
     * Ouvrir la sidebar
     */
    open() {
        if (!this.container) return;

        this.isOpen = true;
        this.container.classList.add('beaubot-open');
        this.toggleButton?.classList.add('beaubot-hidden');
        this.overlay?.classList.add('beaubot-visible');
        
        // Focus sur l'input
        const input = this.container.querySelector('.beaubot-input');
        if (input) {
            setTimeout(() => input.focus(), 300);
        }

        // Dispatch event
        this.dispatchEvent('open');
    }

    /**
     * Fermer la sidebar
     */
    close() {
        if (!this.container) return;

        this.isOpen = false;
        this.container.classList.remove('beaubot-open');
        this.toggleButton?.classList.remove('beaubot-hidden');
        this.overlay?.classList.remove('beaubot-visible');

        // Dispatch event
        this.dispatchEvent('close');
    }

    /**
     * Toggle la sidebar
     */
    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Définir la position
     * @param {string} position - 'left' ou 'right'
     */
    setPosition(position) {
        if (!this.container) return;
        
        this.position = position;
        this.container.classList.remove('beaubot-left', 'beaubot-right');
        this.container.classList.add(`beaubot-${position}`);
        
        if (this.toggleButton) {
            this.toggleButton.classList.remove('beaubot-left', 'beaubot-right');
            this.toggleButton.classList.add(`beaubot-${position}`);
        }

        // Mettre à jour le texte du bouton de position
        const positionText = this.container.querySelector('.beaubot-position-text');
        if (positionText) {
            positionText.textContent = position === 'left' 
                ? this.config.strings.positionRight 
                : this.config.strings.positionLeft;
        }

        // Dispatch event
        this.dispatchEvent('positionChange', { position });
    }

    /**
     * Toggle la position
     */
    togglePosition() {
        const newPosition = this.position === 'left' ? 'right' : 'left';
        this.setPosition(newPosition);
        this.saveUserPreference();
    }

    /**
     * Gérer le redimensionnement
     */
    handleResize() {
        const isMobile = window.innerWidth < 768;
        
        if (this.container) {
            this.container.classList.toggle('beaubot-mobile', isMobile);
        }

        // Fermer automatiquement sur mobile si ouvert
        if (isMobile && this.isOpen && !this.container?.classList.contains('beaubot-mobile-open')) {
            // Ne pas fermer, juste adapter
        }
    }

    /**
     * Dispatch un événement personnalisé
     * @param {string} name
     * @param {object} detail
     */
    dispatchEvent(name, detail = {}) {
        const event = new CustomEvent(`beaubot:${name}`, {
            detail: { sidebar: this, ...detail },
        });
        document.dispatchEvent(event);
    }

    /**
     * Obtenir l'état
     * @returns {boolean}
     */
    getIsOpen() {
        return this.isOpen;
    }

    /**
     * Obtenir la position
     * @returns {string}
     */
    getPosition() {
        return this.position;
    }
}

export default BeauBotSidebar;
