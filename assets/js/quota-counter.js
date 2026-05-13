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
        wrap.innerHTML = ''
            + '<span class="beaubot-quota-label"></span>'
            + '<span class="beaubot-quota-value">'
            +   '<span class="beaubot-quota-used">0</span>'
            +   '<span class="beaubot-quota-sep">/</span>'
            +   '<span class="beaubot-quota-limit">0</span>'
            + '</span>'
            + '<span class="beaubot-quota-bar"><span class="beaubot-quota-bar-fill"></span></span>';
        return wrap;
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

        const titleParts = [];
        const noun = used > 1 ? (quota.token_name_plural || quota.token_name || '') : (quota.token_name || '');
        titleParts.push(used + ' / ' + limit + ' ' + noun);
        if (!quota.enabled) titleParts.push('(' + (config.strings && config.strings.disabled || 'limite désactivée') + ')');
        if (quota.reached)  titleParts.push('(' + (config.strings && config.strings.reached  || 'limite atteinte')  + ')');
        widget.title = titleParts.join(' ');
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
