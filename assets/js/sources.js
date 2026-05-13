/**
 * BeauBot Sources - Rendu des sources sous les réponses de l'IA
 *
 * Affiche des chips numérotés [1] [2] [3] cliquables qui ouvrent la page source
 * en utilisant les Text Fragments du navigateur (#:~:text=...) pour scroller
 * directement vers le passage qui répond le mieux à la question.
 *
 * Expose : window.BeauBotSources = { render }
 */
(function () {
    'use strict';

    /**
     * Construire le bloc DOM des sources à partir du tableau renvoyé par l'API.
     * @param {Array<object>} sources - Liste de sources renvoyée par /chat.
     * @returns {HTMLElement|null} Élément DOM prêt à être inséré, ou null si pas de sources.
     */
    function render(sources) {
        if (!Array.isArray(sources) || sources.length === 0) {
            return null;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'beaubot-sources';

        const label = document.createElement('span');
        label.className = 'beaubot-sources-label';
        label.textContent = sources.length > 1 ? 'Sources' : 'Source';
        wrapper.appendChild(label);

        const list = document.createElement('div');
        list.className = 'beaubot-sources-list';

        sources.forEach(function (source) {
            const chip = buildChip(source);
            if (chip) {
                list.appendChild(chip);
            }
        });

        wrapper.appendChild(list);
        return wrapper;
    }

    /**
     * Construire un chip cliquable pour une source unique.
     * @param {object} source - { rank, title, parent_title, url, page_url, snippet, preview, is_external }
     * @returns {HTMLAnchorElement|null}
     */
    function buildChip(source) {
        if (!source || !source.url) {
            return null;
        }

        const chip = document.createElement('a');
        chip.className = 'beaubot-source-chip';
        chip.href = source.url;
        chip.target = '_blank';
        chip.rel = 'noopener noreferrer';

        if (source.is_external) {
            chip.classList.add('beaubot-source-chip-external');
        }

        // Numéro de la source
        const rank = document.createElement('span');
        rank.className = 'beaubot-source-chip-rank';
        rank.textContent = String(source.rank || '');
        chip.appendChild(rank);

        // Titre court de la page (avec parent si dispo)
        const title = document.createElement('span');
        title.className = 'beaubot-source-chip-title';
        title.textContent = formatTitle(source);
        chip.appendChild(title);

        // Tooltip : preview du passage
        chip.setAttribute('aria-label', buildTooltipText(source));
        chip.appendChild(buildTooltip(source));

        return chip;
    }

    /**
     * Formater le titre affiché dans le chip.
     * @param {object} source
     * @returns {string}
     */
    function formatTitle(source) {
        const title = (source.title || '').trim();
        return title || 'Voir la page';
    }

    /**
     * Construire le texte ARIA / tooltip lisible.
     * @param {object} source
     * @returns {string}
     */
    function buildTooltipText(source) {
        const parts = [];
        if (source.parent_title) parts.push(source.parent_title);
        if (source.title) parts.push(source.title);
        const path = parts.join(' › ');
        const preview = source.preview || source.snippet || '';
        return path + (preview ? ' — ' + preview : '');
    }

    /**
     * Construire le DOM de la tooltip affichée au survol.
     * @param {object} source
     * @returns {HTMLElement}
     */
    function buildTooltip(source) {
        const tip = document.createElement('span');
        tip.className = 'beaubot-source-chip-tooltip';

        if (source.parent_title) {
            const breadcrumb = document.createElement('span');
            breadcrumb.className = 'beaubot-source-chip-breadcrumb';
            breadcrumb.textContent = source.parent_title;
            tip.appendChild(breadcrumb);
        }

        if (source.title) {
            const heading = document.createElement('strong');
            heading.className = 'beaubot-source-chip-heading';
            heading.textContent = source.title;
            tip.appendChild(heading);
        }

        const preview = (source.preview || source.snippet || '').trim();
        if (preview) {
            const quote = document.createElement('span');
            quote.className = 'beaubot-source-chip-quote';
            quote.textContent = '« ' + preview + ' »';
            tip.appendChild(quote);
        }

        return tip;
    }

    window.BeauBotSources = {
        render: render,
    };
})();
