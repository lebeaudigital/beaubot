/**
 * BeauBot Quota Counter
 *
 * Affiche un badge "DEMANDES/J 50/100" dans le header du site,
 * à côté du sélecteur CSS configuré dans l'admin.
 * Écoute l'événement `beaubot:quotaUpdated` pour rafraîchir l'affichage
 * sans refaire d'appel réseau (le détail = objet quota).
 */
(function () {
    'use strict';

    const WIDGET_ID = 'beaubot-quota-widget';
    const config = window.beaubotQuotaConfig || {};

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function getColor(percent, reached) {
        if (reached) return '#dc2626';
        if (percent >= 90) return '#dc2626';
        if (percent >= 70) return '#f59e0b';
        return '#22c55e';
    }

    function buildWidget() {
        const wrap = document.createElement('div');
        wrap.id = WIDGET_ID;
        wrap.className = 'beaubot-quota-widget';
        wrap.setAttribute('role', 'status');
        wrap.setAttribute('aria-live', 'polite');

        const infoLabel = (config.strings && config.strings.infoAria) || 'Détails du quota';

        wrap.innerHTML = ''
            + '<span class="beaubot-quota-label"></span>'
            + '<span class="beaubot-quota-value">'
            +   '<span class="beaubot-quota-used">0</span>'
            +   '<span class="beaubot-quota-sep">/</span>'
            +   '<span class="beaubot-quota-limit">0</span>'
            + '</span>'
            + '<span class="beaubot-quota-bar"><span class="beaubot-quota-bar-fill"></span></span>'
            + '<button type="button" class="beaubot-quota-info" tabindex="0" aria-label="' + escapeHtml(infoLabel) + '">'
            +   '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            +     '<circle cx="12" cy="12" r="10"></circle>'
            +     '<line x1="12" y1="16" x2="12" y2="12"></line>'
            +     '<line x1="12" y1="8" x2="12.01" y2="8"></line>'
            +   '</svg>'
            +   '<span class="beaubot-quota-tooltip" role="tooltip"></span>'
            + '</button>';
        return wrap;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function tokenNoun(count, quota) {
        const strings = (config.strings) || {};
        const singular = quota.token_name || strings.tokenSingular || 'jeton';
        const plural   = quota.token_name_plural || strings.tokenPlural || (singular + 's');
        return count > 1 ? plural : singular;
    }

    function buildTooltipHtml(quota) {
        const strings = config.strings || {};
        const period = (quota && quota.period) || config.period || 'day';
        const used   = (quota && quota.used)   || 0;
        const limit  = (quota && quota.limit)  || 0;

        const costText  = (quota && quota.cost_text  != null) ? quota.cost_text  : 1;
        const costImage = (quota && quota.cost_image != null) ? quota.cost_image : 3;

        const labelText  = strings.tooltipText  || 'Texte';
        const labelImage = strings.tooltipImage || 'Image';
        const labelUsage = strings.tooltipUsage || 'Utilisation';
        const labelTitle = strings.tooltipTitle || 'Coût d\'une requête';
        const labelReset = period === 'month'
            ? (strings.tooltipResetMonth || 'Remise à zéro le 1er de chaque mois.')
            : (strings.tooltipResetDay   || 'Remise à zéro chaque jour à minuit.');

        const nounText  = tokenNoun(costText,  quota || {});
        const nounImage = tokenNoun(costImage, quota || {});
        const nounUsed  = tokenNoun(limit,     quota || {});

        return ''
            + '<span class="beaubot-quota-tooltip-title">' + escapeHtml(labelTitle) + '</span>'
            + '<span class="beaubot-quota-tooltip-row">'
            +   '<span class="beaubot-quota-tooltip-key">'
            +     '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>'
            +     escapeHtml(labelText)
            +   '</span>'
            +   '<span class="beaubot-quota-tooltip-val"><strong>' + escapeHtml(String(costText))  + '</strong> ' + escapeHtml(nounText)  + '</span>'
            + '</span>'
            + '<span class="beaubot-quota-tooltip-row">'
            +   '<span class="beaubot-quota-tooltip-key">'
            +     '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>'
            +     escapeHtml(labelImage)
            +   '</span>'
            +   '<span class="beaubot-quota-tooltip-val"><strong>' + escapeHtml(String(costImage)) + '</strong> ' + escapeHtml(nounImage) + '</span>'
            + '</span>'
            + '<span class="beaubot-quota-tooltip-divider"></span>'
            + '<span class="beaubot-quota-tooltip-row">'
            +   '<span class="beaubot-quota-tooltip-key">' + escapeHtml(labelUsage) + '</span>'
            +   '<span class="beaubot-quota-tooltip-val"><strong>' + escapeHtml(String(used)) + '</strong> / ' + escapeHtml(String(limit)) + ' ' + escapeHtml(nounUsed) + '</span>'
            + '</span>'
            + '<span class="beaubot-quota-tooltip-foot">' + escapeHtml(labelReset) + '</span>';
    }

    function render(quota) {
        if (!quota) return;
        const widget = document.getElementById(WIDGET_ID);
        if (!widget) return;

        const used = quota.used || 0;
        const limit = quota.limit || 0;
        const percent = clamp(quota.percent != null
            ? quota.percent
            : (limit > 0 ? Math.round((used / limit) * 100) : 0),
            0,
            100
        );

        const labelEl = widget.querySelector('.beaubot-quota-label');
        const usedEl  = widget.querySelector('.beaubot-quota-used');
        const limitEl = widget.querySelector('.beaubot-quota-limit');
        const fillEl  = widget.querySelector('.beaubot-quota-bar-fill');
        const tipEl   = widget.querySelector('.beaubot-quota-tooltip');

        if (labelEl) labelEl.textContent = quota.short_label || 'DEMANDES/J';
        if (usedEl)  usedEl.textContent  = String(used);
        if (limitEl) limitEl.textContent = String(limit);

        const color = getColor(percent, !!quota.reached);
        if (fillEl) {
            fillEl.style.width = percent + '%';
            fillEl.style.backgroundColor = color;
        }

        widget.classList.toggle('beaubot-quota-reached', !!quota.reached);
        widget.classList.toggle('beaubot-quota-disabled', !quota.enabled);

        // Tooltip riche (HTML)
        if (tipEl) {
            tipEl.innerHTML = buildTooltipHtml(quota);
        }

        // Title de secours (au cas où le tooltip CSS ne s'affiche pas)
        const noun = tokenNoun(used, quota);
        const fallback = used + ' / ' + limit + ' ' + noun;
        widget.title = fallback;
    }

    function inject() {
        if (document.getElementById(WIDGET_ID)) return true;

        const selector = config.targetSelector || '.header-right';
        const target = document.querySelector(selector);
        if (!target) return false;

        const widget = buildWidget();
        const position = config.position || 'before';

        switch (position) {
            case 'append':
                target.appendChild(widget);
                break;
            case 'prepend':
                target.insertBefore(widget, target.firstChild);
                break;
            case 'after':
                target.parentNode.insertBefore(widget, target.nextSibling);
                break;
            case 'before':
            default:
                target.parentNode.insertBefore(widget, target);
                break;
        }
        return true;
    }

    function loadAndRender() {
        if (!window.BeauBotApiQuota) return;
        window.BeauBotApiQuota.fetchStatus(config)
            .then(function (quota) {
                document.dispatchEvent(new CustomEvent('beaubot:quotaUpdated', { detail: quota }));
            })
            .catch(function (err) {
                if (window.console && console.warn) {
                    console.warn('[BeauBot] Quota fetch failed:', err);
                }
            });
    }

    function ensureInjected() {
        if (inject()) return;
        // Le sélecteur n'existe peut-être pas encore (DOM tardif) : on observe.
        const observer = new MutationObserver(function () {
            if (inject()) {
                observer.disconnect();
                loadAndRender();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        // Sécurité : on arrête au bout de 10s.
        setTimeout(function () { observer.disconnect(); }, 10000);
    }

    function start() {
        if (!config.userLoggedIn) return;
        ensureInjected();
        loadAndRender();

        document.addEventListener('beaubot:quotaUpdated', function (e) {
            render(e.detail);
        });

        // Permet à d'autres modules (ex: chatbot.js) de demander un refresh distant.
        document.addEventListener('beaubot:quotaRefresh', function () {
            loadAndRender();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
