/**
 * BeauBot Stream - Affichage progressif des réponses à vitesse de lecture.
 *
 * Révèle le HTML mot à mot à ~220 mots/minute (lecture silencieuse moyenne).
 * Un clic sur le message affiche le reste immédiatement.
 *
 * Expose : window.BeauBotStream = { play, WORDS_PER_MINUTE }
 */
(function () {
    'use strict';

    /** Lecture silencieuse moyenne (français / textes informatifs). */
    const WORDS_PER_MINUTE = 220;

    /**
     * Streamer du HTML dans un conteneur.
     * @param {HTMLElement} container
     * @param {string} html
     * @param {object} [options]
     * @param {number} [options.wpm]
     * @param {function} [options.onTick]
     * @param {function} [options.onDone]
     * @returns {{ finish: function, cancel: function }}
     */
    function play(container, html, options) {
        const opts = options || {};
        const wpm = opts.wpm > 0 ? opts.wpm : WORDS_PER_MINUTE;
        const baseDelay = Math.max(50, Math.round(60000 / wpm));
        let doneCalled = false;

        const finishNow = function () {
            if (doneCalled) {
                return;
            }
            doneCalled = true;
            if (typeof opts.onDone === 'function') {
                opts.onDone();
            }
        };

        if (!container) {
            finishNow();
            return { finish: finishNow, cancel: finishNow };
        }

        if (shouldSkipAnimation()) {
            container.innerHTML = html;
            finishNow();
            return { finish: finishNow, cancel: finishNow };
        }

        const staging = document.createElement('div');
        staging.innerHTML = html;

        const jobs = collectTextJobs(staging);
        container.innerHTML = '';
        while (staging.firstChild) {
            container.appendChild(staging.firstChild);
        }

        if (jobs.length === 0) {
            finishNow();
            return { finish: finishNow, cancel: finishNow };
        }

        const caret = document.createElement('span');
        caret.className = 'beaubot-stream-caret';
        caret.setAttribute('aria-hidden', 'true');

        container.classList.add('beaubot-is-streaming');
        container.setAttribute('aria-busy', 'true');

        let timer = null;
        let jobIndex = 0;
        let wordIndex = 0;
        let stopped = false;

        const placeCaret = function (node) {
            if (!caret.parentNode) {
                container.appendChild(caret);
            }
            const parent = node.parentNode;
            if (parent) {
                parent.insertBefore(caret, node.nextSibling);
            }
        };

        const revealAll = function () {
            jobs.forEach(function (job) {
                job.node.textContent = job.full;
            });
        };

        const complete = function () {
            if (stopped) {
                return;
            }
            stopped = true;
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            revealAll();
            if (caret.parentNode) {
                caret.parentNode.removeChild(caret);
            }
            container.classList.remove('beaubot-is-streaming');
            container.removeAttribute('aria-busy');
            container.removeEventListener('click', onSkip);
            finishNow();
        };

        const onSkip = function (event) {
            if (event.target && event.target.closest('a')) {
                return;
            }
            complete();
        };

        const tick = function () {
            if (stopped) {
                return;
            }

            while (jobIndex < jobs.length && jobs[jobIndex].immediate) {
                jobs[jobIndex].node.textContent = jobs[jobIndex].full;
                placeCaret(jobs[jobIndex].node);
                jobIndex += 1;
                wordIndex = 0;
            }

            if (jobIndex >= jobs.length) {
                complete();
                return;
            }

            const job = jobs[jobIndex];
            wordIndex += 1;
            job.node.textContent = job.words.slice(0, wordIndex).join(' ');
            placeCaret(job.node);

            if (typeof opts.onTick === 'function') {
                opts.onTick();
            }

            if (wordIndex >= job.words.length) {
                jobIndex += 1;
                wordIndex = 0;
            }

            const lastWord = job.words[Math.max(0, wordIndex - 1)] || '';
            timer = setTimeout(tick, delayForWord(lastWord, baseDelay));
        };

        container.addEventListener('click', onSkip);
        timer = setTimeout(tick, 40);

        return {
            finish: complete,
            cancel: complete,
        };
    }

    /**
     * @returns {boolean}
     */
    function shouldSkipAnimation() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /**
     * Collecter les nœuds texte à révéler.
     * @param {HTMLElement} root
     * @returns {Array<{node: Text, full: string, words: string[], immediate: boolean}>}
     */
    function collectTextJobs(root) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        const jobs = [];
        let node;

        while ((node = walker.nextNode())) {
            const full = node.textContent;
            if (!full || !full.trim()) {
                continue;
            }

            const immediate = !!(node.parentElement && node.parentElement.closest('pre, code'));
            const words = immediate ? [full] : full.trim().split(/\s+/).filter(Boolean);

            node.textContent = '';
            jobs.push({
                node: node,
                full: full,
                words: words,
                immediate: immediate,
            });
        }

        return jobs;
    }

    /**
     * Pause un peu plus longue après la ponctuation, comme à la lecture.
     * @param {string} word
     * @param {number} baseDelay
     * @returns {number}
     */
    function delayForWord(word, baseDelay) {
        if (/[.!?…]$/.test(word)) {
            return Math.round(baseDelay * 2.1);
        }
        if (/[,;:]$/.test(word)) {
            return Math.round(baseDelay * 1.35);
        }
        return baseDelay;
    }

    window.BeauBotStream = {
        play: play,
        WORDS_PER_MINUTE: WORDS_PER_MINUTE,
    };
})();
