/**
 * BeauBot File Upload Module
 * Gère l'upload et l'affichage des images
 */

(function() {
    'use strict';

    window.BeauBotFileUpload = function(config) {
        this.config = config;
        this.currentImage = null;
        this.fileInput = null;
        this.previewContainer = null;
        this.dropZone = null;
        
        this.init();
    };

    BeauBotFileUpload.prototype.init = function() {
        this.createElements();
        this.bindEvents();
    };

    BeauBotFileUpload.prototype.createElements = function() {
        this.fileInput = document.getElementById('beaubot-file-input');
        this.previewContainer = document.getElementById('beaubot-image-preview');
        this.dropZone = document.getElementById('beaubot-input-area');
        this.uploadButton = document.getElementById('beaubot-upload-btn');
    };

    BeauBotFileUpload.prototype.bindEvents = function() {
        var self = this;

        if (this.uploadButton) {
            this.uploadButton.addEventListener('click', function() {
                self.triggerFileSelect();
            });
        }

        if (this.fileInput) {
            this.fileInput.addEventListener('change', function(e) {
                self.handleFileSelect(e);
            });
        }

        if (this.dropZone) {
            this.dropZone.addEventListener('dragover', function(e) {
                self.handleDragOver(e);
            });
            this.dropZone.addEventListener('dragleave', function(e) {
                self.handleDragLeave(e);
            });
            this.dropZone.addEventListener('drop', function(e) {
                self.handleDrop(e);
            });
        }

        document.addEventListener('paste', function(e) {
            self.handlePaste(e);
        });

        if (this.previewContainer) {
            this.previewContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('beaubot-remove-image')) {
                    self.removeImage();
                }
            });
        }
    };

    BeauBotFileUpload.prototype.triggerFileSelect = function() {
        if (this.fileInput) this.fileInput.click();
    };

    BeauBotFileUpload.prototype.handleFileSelect = function(e) {
        var file = e.target.files ? e.target.files[0] : null;
        if (file) {
            this.processFile(file);
        }
    };

    BeauBotFileUpload.prototype.handleDragOver = function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (this.dropZone) this.dropZone.classList.add('beaubot-drag-over');
    };

    BeauBotFileUpload.prototype.handleDragLeave = function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (this.dropZone) this.dropZone.classList.remove('beaubot-drag-over');
    };

    BeauBotFileUpload.prototype.handleDrop = function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (this.dropZone) this.dropZone.classList.remove('beaubot-drag-over');

        var file = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files[0] : null;
        if (file) {
            this.processFile(file);
        }
    };

    BeauBotFileUpload.prototype.handlePaste = function(e) {
        var activeElement = document.activeElement;
        var isChatInput = activeElement && activeElement.classList.contains('beaubot-input');
        
        if (!isChatInput) return;

        var items = e.clipboardData ? e.clipboardData.items : null;
        if (!items) return;

        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image/') === 0) {
                e.preventDefault();
                var file = items[i].getAsFile();
                if (file) {
                    this.processFile(file);
                }
                break;
            }
        }
    };

    BeauBotFileUpload.prototype.processFile = function(file) {
        var self = this;

        if (this.config.allowedMimeTypes.indexOf(file.type) === -1) {
            this.showError(this.config.strings.invalidFileType);
            return;
        }

        if (file.size > this.config.maxFileSize) {
            this.showError(this.config.strings.fileTooLarge);
            return;
        }

        this.fileToBase64(file).then(function(base64) {
            self.setImage(base64, file.name);
        }).catch(function(error) {
            self.showError(self.config.strings.error);
            console.error('BeauBot: File processing error', error);
        });
    };

    BeauBotFileUpload.prototype.fileToBase64 = function(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    };

    BeauBotFileUpload.prototype.setImage = function(base64, filename) {
        this.currentImage = {
            data: base64,
            filename: filename || 'image',
        };

        this.showPreview(base64, filename);
        this.dispatchEvent('imageSelected', { image: this.currentImage });
    };

    BeauBotFileUpload.prototype.showPreview = function(base64, filename) {
        if (!this.previewContainer) return;

        this.previewContainer.innerHTML = 
            '<div class="beaubot-image-preview-item">' +
                '<img src="' + base64 + '" alt="' + filename + '">' +
                '<button type="button" class="beaubot-remove-image" aria-label="Supprimer">' +
                    '<svg viewBox="0 0 24 24" width="16" height="16">' +
                        '<path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>' +
                    '</svg>' +
                '</button>' +
                '<span class="beaubot-image-filename">' + this.truncateFilename(filename) + '</span>' +
            '</div>';
        this.previewContainer.classList.add('beaubot-has-image');
    };

    BeauBotFileUpload.prototype.truncateFilename = function(filename, maxLength) {
        maxLength = maxLength || 20;
        if (filename.length <= maxLength) return filename;
        
        var ext = filename.split('.').pop();
        var name = filename.slice(0, -(ext.length + 1));
        var truncatedName = name.slice(0, maxLength - ext.length - 4) + '...';
        
        return truncatedName + '.' + ext;
    };

    BeauBotFileUpload.prototype.removeImage = function() {
        this.currentImage = null;
        
        if (this.previewContainer) {
            this.previewContainer.innerHTML = '';
            this.previewContainer.classList.remove('beaubot-has-image');
        }

        if (this.fileInput) {
            this.fileInput.value = '';
        }

        this.dispatchEvent('imageRemoved');
    };

    BeauBotFileUpload.prototype.getImage = function() {
        return this.currentImage;
    };

    BeauBotFileUpload.prototype.getImageData = function() {
        return this.currentImage ? this.currentImage.data : null;
    };

    BeauBotFileUpload.prototype.hasImage = function() {
        return this.currentImage !== null;
    };

    BeauBotFileUpload.prototype.showError = function(message) {
        this.dispatchEvent('error', { message: message });
        
        var toast = document.createElement('div');
        toast.className = 'beaubot-toast beaubot-toast-error';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('beaubot-toast-visible');
        }, 10);

        setTimeout(function() {
            toast.classList.remove('beaubot-toast-visible');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    };

    BeauBotFileUpload.prototype.dispatchEvent = function(name, detail) {
        var event = new CustomEvent('beaubot:' + name, {
            detail: Object.assign({ fileUpload: this }, detail || {}),
        });
        document.dispatchEvent(event);
    };

})();
