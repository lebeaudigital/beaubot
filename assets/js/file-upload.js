/**
 * BeauBot File Upload Module
 * Gère l'upload et l'affichage des images
 */

export class BeauBotFileUpload {
    constructor(config) {
        this.config = config;
        this.currentImage = null;
        this.fileInput = null;
        this.previewContainer = null;
        this.dropZone = null;
        
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
        this.fileInput = document.getElementById('beaubot-file-input');
        this.previewContainer = document.getElementById('beaubot-image-preview');
        this.dropZone = document.getElementById('beaubot-input-area');
        this.uploadButton = document.getElementById('beaubot-upload-btn');
    }

    /**
     * Lier les événements
     */
    bindEvents() {
        // Click sur le bouton upload
        if (this.uploadButton) {
            this.uploadButton.addEventListener('click', () => this.triggerFileSelect());
        }

        // Changement de fichier
        if (this.fileInput) {
            this.fileInput.addEventListener('change', (e) => this.handleFileSelect(e));
        }

        // Drag & Drop
        if (this.dropZone) {
            this.dropZone.addEventListener('dragover', (e) => this.handleDragOver(e));
            this.dropZone.addEventListener('dragleave', (e) => this.handleDragLeave(e));
            this.dropZone.addEventListener('drop', (e) => this.handleDrop(e));
        }

        // Paste (Ctrl+V)
        document.addEventListener('paste', (e) => this.handlePaste(e));

        // Supprimer l'image preview
        if (this.previewContainer) {
            this.previewContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('beaubot-remove-image')) {
                    this.removeImage();
                }
            });
        }
    }

    /**
     * Ouvrir le sélecteur de fichiers
     */
    triggerFileSelect() {
        this.fileInput?.click();
    }

    /**
     * Gérer la sélection de fichier
     * @param {Event} e
     */
    handleFileSelect(e) {
        const file = e.target.files?.[0];
        if (file) {
            this.processFile(file);
        }
    }

    /**
     * Gérer le drag over
     * @param {DragEvent} e
     */
    handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        this.dropZone?.classList.add('beaubot-drag-over');
    }

    /**
     * Gérer le drag leave
     * @param {DragEvent} e
     */
    handleDragLeave(e) {
        e.preventDefault();
        e.stopPropagation();
        this.dropZone?.classList.remove('beaubot-drag-over');
    }

    /**
     * Gérer le drop
     * @param {DragEvent} e
     */
    handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        this.dropZone?.classList.remove('beaubot-drag-over');

        const file = e.dataTransfer?.files?.[0];
        if (file) {
            this.processFile(file);
        }
    }

    /**
     * Gérer le paste
     * @param {ClipboardEvent} e
     */
    handlePaste(e) {
        // Vérifier si le focus est dans la zone de chat
        const activeElement = document.activeElement;
        const isChatInput = activeElement?.classList.contains('beaubot-input');
        
        if (!isChatInput) return;

        const items = e.clipboardData?.items;
        if (!items) return;

        for (const item of items) {
            if (item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) {
                    this.processFile(file);
                }
                break;
            }
        }
    }

    /**
     * Traiter un fichier
     * @param {File} file
     */
    async processFile(file) {
        // Valider le type
        if (!this.config.allowedMimeTypes.includes(file.type)) {
            this.showError(this.config.strings.invalidFileType);
            return;
        }

        // Valider la taille
        if (file.size > this.config.maxFileSize) {
            this.showError(this.config.strings.fileTooLarge);
            return;
        }

        // Convertir en base64
        try {
            const base64 = await this.fileToBase64(file);
            this.setImage(base64, file.name);
        } catch (error) {
            this.showError(this.config.strings.error);
            console.error('BeauBot: File processing error', error);
        }
    }

    /**
     * Convertir un fichier en base64
     * @param {File} file
     * @returns {Promise<string>}
     */
    fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    /**
     * Définir l'image actuelle
     * @param {string} base64
     * @param {string} filename
     */
    setImage(base64, filename = 'image') {
        this.currentImage = {
            data: base64,
            filename: filename,
        };

        this.showPreview(base64, filename);
        this.dispatchEvent('imageSelected', { image: this.currentImage });
    }

    /**
     * Afficher la preview
     * @param {string} base64
     * @param {string} filename
     */
    showPreview(base64, filename) {
        if (!this.previewContainer) return;

        this.previewContainer.innerHTML = `
            <div class="beaubot-image-preview-item">
                <img src="${base64}" alt="${filename}">
                <button type="button" class="beaubot-remove-image" aria-label="Supprimer">
                    <svg viewBox="0 0 24 24" width="16" height="16">
                        <path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                    </svg>
                </button>
                <span class="beaubot-image-filename">${this.truncateFilename(filename)}</span>
            </div>
        `;
        this.previewContainer.classList.add('beaubot-has-image');
    }

    /**
     * Tronquer le nom de fichier
     * @param {string} filename
     * @param {number} maxLength
     * @returns {string}
     */
    truncateFilename(filename, maxLength = 20) {
        if (filename.length <= maxLength) return filename;
        
        const ext = filename.split('.').pop();
        const name = filename.slice(0, -(ext.length + 1));
        const truncatedName = name.slice(0, maxLength - ext.length - 4) + '...';
        
        return `${truncatedName}.${ext}`;
    }

    /**
     * Supprimer l'image
     */
    removeImage() {
        this.currentImage = null;
        
        if (this.previewContainer) {
            this.previewContainer.innerHTML = '';
            this.previewContainer.classList.remove('beaubot-has-image');
        }

        if (this.fileInput) {
            this.fileInput.value = '';
        }

        this.dispatchEvent('imageRemoved');
    }

    /**
     * Obtenir l'image actuelle
     * @returns {object|null}
     */
    getImage() {
        return this.currentImage;
    }

    /**
     * Obtenir les données base64 de l'image
     * @returns {string|null}
     */
    getImageData() {
        return this.currentImage?.data || null;
    }

    /**
     * Vérifier si une image est sélectionnée
     * @returns {boolean}
     */
    hasImage() {
        return this.currentImage !== null;
    }

    /**
     * Afficher une erreur
     * @param {string} message
     */
    showError(message) {
        this.dispatchEvent('error', { message });
        
        // Toast notification simple
        const toast = document.createElement('div');
        toast.className = 'beaubot-toast beaubot-toast-error';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('beaubot-toast-visible');
        }, 10);

        setTimeout(() => {
            toast.classList.remove('beaubot-toast-visible');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Dispatch un événement personnalisé
     * @param {string} name
     * @param {object} detail
     */
    dispatchEvent(name, detail = {}) {
        const event = new CustomEvent(`beaubot:${name}`, {
            detail: { fileUpload: this, ...detail },
        });
        document.dispatchEvent(event);
    }
}

export default BeauBotFileUpload;
