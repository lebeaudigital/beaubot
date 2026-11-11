/**
 * BeauBot File Upload Module
 * Gère l'ajout d'images : fichier, glisser-déposer et collage depuis le presse-papiers.
 */

(function() {
    'use strict';

    window.BeauBotFileUpload = function(config) {
        this.config = config || {};
        this.images = [];
        this.maxImages = this.config.maxImages || 4;
        this.limitErrorShown = false;
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
        }, true);

        if (this.previewContainer) {
            this.previewContainer.addEventListener('click', function(e) {
                var button = e.target.closest ? e.target.closest('.beaubot-remove-image') : null;
                if (!button) return;
                var index = parseInt(button.getAttribute('data-index'), 10);
                self.removeImage(index);
            });
        }
    };

    BeauBotFileUpload.prototype.triggerFileSelect = function() {
        if (this.fileInput) this.fileInput.click();
    };

    BeauBotFileUpload.prototype.handleFileSelect = function(e) {
        var files = e.target.files ? e.target.files : [];
        this.addFiles(files);
        e.target.value = '';
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

        var files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : [];
        this.addFiles(files);
    };

    BeauBotFileUpload.prototype.handlePaste = function(e) {
        if (!this.shouldAcceptPaste(e)) return;

        var clipboard = e.clipboardData;
        if (!clipboard) return;

        var files = this.extractClipboardImages(clipboard);
        if (!files.length) return;

        var text = '';
        try {
            text = clipboard.getData('text/plain') || '';
        } catch (err) {
            text = '';
        }

        if (!String(text).trim()) {
            e.preventDefault();
        }

        return this.addFiles(files);
    };

    BeauBotFileUpload.prototype.shouldAcceptPaste = function(e) {
        var sidebar = document.getElementById('beaubot-sidebar');
        if (!sidebar || !sidebar.classList.contains('beaubot-open')) {
            return false;
        }

        var active = document.activeElement;
        var target = e.target;
        if (active && sidebar.contains(active)) return true;
        if (target && target.nodeType === 1 && sidebar.contains(target)) return true;

        return !this.isTextEntry(active);
    };

    BeauBotFileUpload.prototype.isTextEntry = function(el) {
        if (!el || el === document.body || el === document.documentElement) return false;

        var tag = (el.tagName || '').toUpperCase();
        if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (tag === 'INPUT') {
            var type = (el.type || 'text').toLowerCase();
            var textual = ['text', 'search', 'email', 'url', 'tel', 'password', 'number', 'date', 'datetime-local', 'time', 'month', 'week'];
            return textual.indexOf(type) !== -1;
        }

        return !!el.isContentEditable;
    };

    BeauBotFileUpload.prototype.extractClipboardImages = function(clipboard) {
        var found = [];
        var items = clipboard.items;

        if (items && items.length) {
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var kind = item.kind || '';
                var type = item.type || '';
                if (kind && kind !== 'file') continue;
                if (type && type.indexOf('image/') !== 0) continue;

                var file = item.getAsFile ? item.getAsFile() : null;
                if (!file) continue;

                var fileType = file.type || type;
                if (!fileType || fileType.indexOf('image/') === 0) {
                    found.push(file);
                }
            }
        }

        if (!found.length && clipboard.files && clipboard.files.length) {
            for (var j = 0; j < clipboard.files.length; j++) {
                var dropped = clipboard.files[j];
                if (!dropped.type || dropped.type.indexOf('image/') === 0) {
                    found.push(dropped);
                }
            }
        }

        return found;
    };

    BeauBotFileUpload.prototype.addFiles = function(fileList) {
        var self = this;
        var files = [];

        for (var i = 0; i < fileList.length; i++) {
            if (fileList[i]) files.push(fileList[i]);
        }
        if (!files.length) return Promise.resolve();

        this.limitErrorShown = false;
        var chain = Promise.resolve();
        files.forEach(function(file) {
            chain = chain.then(function() {
                return self.processFile(file);
            });
        });
        return chain;
    };

    BeauBotFileUpload.prototype.processFile = function(file) {
        var self = this;

        if (this.images.length >= this.maxImages) {
            this.notifyImageLimit();
            return Promise.resolve();
        }

        return this.toUploadableFile(file).then(function(prepared) {
            if (!prepared) return;
            if (self.images.length >= self.maxImages) {
                self.notifyImageLimit();
                return;
            }
            if ((self.config.allowedMimeTypes || []).indexOf(prepared.type) === -1) {
                self.showError(self.config.strings.invalidFileType);
                return;
            }
            if (prepared.size > self.config.maxFileSize) {
                self.showError(self.config.strings.fileTooLarge);
                return;
            }
            return self.fileToBase64(prepared).then(function(base64) {
                self.addImage(base64, prepared.name);
            });
        }).catch(function(error) {
            self.showError(self.config.strings.invalidFileType || self.config.strings.error);
            console.error('BeauBot: File processing error', error);
        });
    };

    BeauBotFileUpload.prototype.notifyImageLimit = function() {
        if (this.limitErrorShown) return;
        this.limitErrorShown = true;
        var message = (this.config.strings && this.config.strings.tooManyImages)
            ? this.config.strings.tooManyImages
            : 'Vous pouvez joindre 4 images maximum.';
        this.showError(message);
    };

    BeauBotFileUpload.prototype.ensureFileName = function(file) {
        var type = file.type === 'image/jpg' ? 'image/jpeg' : (file.type || '');
        var name = file.name || '';

        if (!name || name === 'blob') {
            var ext = 'png';
            if (type === 'image/jpeg') ext = 'jpg';
            else if (type === 'image/webp') ext = 'webp';
            else if (type === 'image/gif') ext = 'gif';
            name = 'capture.' + ext;
        }

        if (name !== file.name || (type && type !== file.type)) {
            return new File([file], name, { type: type || file.type || 'image/png' });
        }

        return file;
    };

    BeauBotFileUpload.prototype.toUploadableFile = function(file) {
        var self = this;
        var named = this.ensureFileName(file);
        var allowed = this.config.allowedMimeTypes || [];

        if (named.type === 'image/gif' && allowed.indexOf(named.type) !== -1) {
            return Promise.resolve(named);
        }

        return new Promise(function(resolve, reject) {
            var url = URL.createObjectURL(named);
            var img = new Image();

            img.onload = function() {
                var width = img.naturalWidth || img.width;
                var height = img.naturalHeight || img.height;
                URL.revokeObjectURL(url);

                if (!width || !height) {
                    reject(new Error('empty image'));
                    return;
                }

                var typeOk = allowed.indexOf(named.type) !== -1;
                var sizeOk = named.size <= self.config.maxFileSize;
                var dimsOk = width <= 1920 && height <= 1920;
                if (typeOk && sizeOk && dimsOk) {
                    resolve(named);
                    return;
                }

                var scale = Math.min(1, 1920 / Math.max(width, height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(width * scale));
                canvas.height = Math.max(1, Math.round(height * scale));
                var context = canvas.getContext('2d');
                if (!context) {
                    reject(new Error('canvas'));
                    return;
                }
                context.drawImage(img, 0, 0, canvas.width, canvas.height);

                var mime = named.type === 'image/jpeg' ? 'image/jpeg' : 'image/png';
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error('blob'));
                        return;
                    }
                    var baseName = named.name.replace(/\.[^.]+$/, '') || 'capture';
                    var ext = mime === 'image/jpeg' ? 'jpg' : 'png';
                    resolve(new File([blob], baseName + '.' + ext, { type: mime }));
                }, mime, mime === 'image/jpeg' ? 0.85 : undefined);
            };

            img.onerror = function() {
                URL.revokeObjectURL(url);
                reject(new Error('decode'));
            };

            img.src = url;
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

    BeauBotFileUpload.prototype.addImage = function(base64, filename) {
        if (this.images.length >= this.maxImages) {
            this.notifyImageLimit();
            return;
        }

        this.images.push({
            data: base64,
            filename: filename || 'capture.png',
        });

        this.renderPreviews();
        this.dispatchEvent('imageSelected', {
            image: this.images[this.images.length - 1],
            images: this.images.slice(),
        });
    };

    BeauBotFileUpload.prototype.renderPreviews = function() {
        if (!this.previewContainer) return;

        if (!this.images.length) {
            this.previewContainer.innerHTML = '';
            this.previewContainer.classList.remove('beaubot-has-image');
            return;
        }

        var html = '';
        for (var i = 0; i < this.images.length; i++) {
            var image = this.images[i];
            html +=
                '<div class="beaubot-image-preview-item">' +
                    '<img src="' + this.escapeHtml(image.data) + '" alt="' + this.escapeHtml(image.filename) + '">' +
                    '<button type="button" class="beaubot-remove-image" data-index="' + i + '" aria-label="Supprimer">' +
                        '<svg viewBox="0 0 24 24" width="16" height="16">' +
                            '<path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>' +
                        '</svg>' +
                    '</button>' +
                    '<span class="beaubot-image-filename">' + this.escapeHtml(this.truncateFilename(image.filename)) + '</span>' +
                '</div>';
        }

        this.previewContainer.innerHTML = html;
        this.previewContainer.classList.add('beaubot-has-image');
    };

    BeauBotFileUpload.prototype.truncateFilename = function(filename, maxLength) {
        maxLength = maxLength || 20;
        filename = filename || 'capture.png';
        if (filename.length <= maxLength) return filename;

        var ext = filename.split('.').pop();
        var name = filename.slice(0, -(ext.length + 1));
        var truncatedName = name.slice(0, Math.max(1, maxLength - ext.length - 4)) + '...';

        return truncatedName + '.' + ext;
    };

    BeauBotFileUpload.prototype.removeImage = function(index) {
        if (typeof index === 'number' && !isNaN(index)) {
            this.images.splice(index, 1);
        } else {
            this.images = [];
        }

        this.renderPreviews();

        if (this.fileInput) {
            this.fileInput.value = '';
        }

        if (!this.images.length) {
            this.dispatchEvent('imageRemoved');
        } else {
            this.dispatchEvent('imageSelected', { images: this.images.slice() });
        }
    };

    BeauBotFileUpload.prototype.getImage = function() {
        return this.images.length ? this.images[0] : null;
    };

    BeauBotFileUpload.prototype.getImageData = function() {
        return this.images.length ? this.images[0].data : null;
    };

    BeauBotFileUpload.prototype.getImagesData = function() {
        return this.images.map(function(image) {
            return image.data;
        });
    };

    BeauBotFileUpload.prototype.hasImage = function() {
        return this.images.length > 0;
    };

    BeauBotFileUpload.prototype.escapeHtml = function(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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
