/**
 * BeauBot Deep Link - Scroll vers le paragraphe cité.
 *
 * Lit le paramètre ?beaubot_hl=... (et le Text Fragment s'il est encore
 * présent dans le hash) puis fait défiler la page jusqu'au bloc qui
 * contient ce texte. Sert de secours quand #:~:text= n'est pas appliqué.
 *
 * Expose : window.BeauBotDeepLink = { highlight }
 */
(function () {
    'use strict';

    const QUERY_KEY = 'beaubot_hl';
    const SELECTORS = [
        'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'td', 'th', 'blockquote', 'figcaption', 'dt', 'dd',
        '.wp-block-paragraph', '.wp-block-heading',
        '.elementor-widget-text-editor',
    ].join(',');

    const RETRY_DELAYS = [0, 250, 800, 1800];

    function init() {
        const needle = readNeedle();
        if (!needle) {
            return;
        }
        highlight(needle);
    }

    /**
     * Trouver et surligner le bloc contenant le texte.
     * @param {string} needle
     * @returns {HTMLElement|null}
     */
    function highlight(needle) {
        let attempt = 0;

        const tryFind = function () {
            const el = findBestElement(needle);
            if (el) {
                applyHighlight(el);
                return;
            }
            attempt += 1;
            if (attempt < RETRY_DELAYS.length) {
                setTimeout(tryFind, RETRY_DELAYS[attempt]);
            }
        };

        tryFind();
        return null;
    }

    /**
     * @returns {string}
     */
    function readNeedle() {
        try {
            const params = new URLSearchParams(window.location.search);
            const fromQuery = params.get(QUERY_KEY);
            if (fromQuery && fromQuery.trim()) {
                return fromQuery.trim();
            }
        } catch (e) {
            // ignore
        }

        const hash = window.location.hash || '';
        const match = hash.match(/:~:text=([^&]+)/);
        if (match && match[1]) {
            try {
                const raw = decodeURIComponent(match[1].replace(/\+/g, ' '));
                return raw.split(',')[0].trim();
            } catch (e) {
                return match[1];
            }
        }

        return '';
    }

    /**
     * @param {string} needle
     * @returns {HTMLElement|null}
     */
    function findBestElement(needle) {
        const variants = buildVariants(needle);
        if (variants.length === 0) {
            return null;
        }

        const nodes = document.querySelectorAll(SELECTORS);
        let best = null;
        let bestScore = Infinity;

        for (let i = 0; i < nodes.length; i++) {
            const el = nodes[i];
            if (!el || el.closest('#beaubot-sidebar')) {
                continue;
            }
            const hay = normalize(el.textContent || '');
            if (!hay) {
                continue;
            }
            for (let v = 0; v < variants.length; v++) {
                if (hay.indexOf(variants[v]) !== -1 && hay.length < bestScore) {
                    best = el;
                    bestScore = hay.length;
                    break;
                }
            }
        }

        return best;
    }

    /**
     * Variantes de plus en plus courtes pour tolérer un texte légèrement différent.
     * @param {string} needle
     * @returns {string[]}
     */
    function buildVariants(needle) {
        const norm = normalize(needle);
        if (!norm) {
            return [];
        }

        const variants = [norm];
        const words = norm.split(' ').filter(Boolean);

        if (words.length > 8) {
            variants.push(words.slice(0, 8).join(' '));
        }
        if (words.length > 5) {
            variants.push(words.slice(0, 5).join(' '));
        }

        const noPunct = norm.replace(/[«»"'()[\].,;:!?…]/g, '').replace(/\s+/g, ' ').trim();
        if (noPunct && noPunct !== norm) {
            variants.push(noPunct);
        }

        return variants.filter(function (v) {
            return v.length >= 12 || v.split(' ').length >= 3;
        });
    }

    /**
     * @param {string} text
     * @returns {string}
     */
    function normalize(text) {
        return String(text)
            .replace(/\u00a0/g, ' ')
            .replace(/[\u2018\u2019]/g, "'")
            .replace(/[\u201c\u201d]/g, '"')
            .replace(/[‐‑–—]/g, '-')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    /**
     * @param {HTMLElement} el
     */
    function applyHighlight(el) {
        el.classList.add('beaubot-hl-target');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        window.setTimeout(function () {
            el.classList.add('beaubot-hl-fade');
        }, 3200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.BeauBotDeepLink = {
        highlight: highlight,
    };
})();
