/**
 * BeauBot Stream - Affichage progressif style ChatGPT.
 *
 * Le markdown est révélé mot à mot : le bloc grandit au fur et à mesure,
 * sans réserver d'espace vide à l'avance.
 *
 * Expose : window.BeauBotStream = { play }
 */
(function () {
    'use strict';

    /** Délai entre deux mots (ms) — rythme proche de ChatGPT, pas de la lecture. */
    const TICK_MS = 32;

    /**
     * Streamer du markdown dans un conteneur.
     * @param {HTMLElement} container
     * @param {string} text - Markdown source (pas le HTML final).
     * @param {object} [options]
     * @param {function(string): string} [options.format] - Convertit un préfixe markdown en HTML.
     * @param {function} [options.onTick]
     * @param {function} [options.onDone]
     * @returns {{ finish: function, cancel: function }}
     */
    function play(container, text, options) {
        const opts = options || {};
        const format = typeof opts.format === 'function'
            ? opts.format
            : function (chunk) { return chunk; };

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

        const source = text == null ? '' : String(text);
        const tokens = tokenize(source);

        const render = function (partial, withCaret) {
            const html = format(stripTrailingBreaks(partial));
            container.innerHTML = html + (withCaret ? '<span class="beaubot-stream-caret" aria-hidden="true"></span>' : '');
        };

        if (shouldSkipAnimation() || tokens.length === 0) {
            render(source, false);
            finishNow();
            return { finish: finishNow, cancel: finishNow };
        }

        container.innerHTML = '';
        container.classList.add('beaubot-is-streaming');
        container.setAttribute('aria-busy', 'true');

        let timer = null;
        let index = 0;
        let buffer = '';
        let stopped = false;

        const complete = function () {
            if (stopped) {
                return;
            }
            stopped = true;
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            render(source, false);
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

            buffer += tokens[index];
            index += 1;
            render(buffer, true);

            if (typeof opts.onTick === 'function') {
                opts.onTick();
            }

            if (index >= tokens.length) {
                complete();
                return;
            }

            timer = setTimeout(tick, delayForToken(tokens[index - 1]));
        };

        container.addEventListener('click', onSkip);
        timer = setTimeout(tick, 16);

        return {
            finish: complete,
            cancel: complete,
        };
    }

    /**
     * Couper le markdown en mots, en conservant espaces et retours à la ligne.
     * @param {string} text
     * @returns {string[]}
     */
    function tokenize(text) {
        const tokens = text.match(/\S+\s*/g);
        return tokens && tokens.length ? tokens : (text ? [text] : []);
    }

    /**
     * Éviter qu'un \n\n final ouvre un paragraphe vide avant le mot suivant.
     * @param {string} text
     * @returns {string}
     */
    function stripTrailingBreaks(text) {
        return text.replace(/[\n\r]+$/g, '');
    }

    /**
     * @param {string} token
     * @returns {number}
     */
    function delayForToken(token) {
        const word = token.trim();
        if (/[.!?…]$/.test(word)) {
            return TICK_MS + 40;
        }
        if (/[,;:]$/.test(word)) {
            return TICK_MS + 16;
        }
        return TICK_MS;
    }

    /**
     * @returns {boolean}
     */
    function shouldSkipAnimation() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    window.BeauBotStream = {
        play: play,
    };
})();
