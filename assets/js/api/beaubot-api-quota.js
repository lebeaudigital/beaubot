/**
 * BeauBot API - Quota
 * ES6 module exposant les appels REST liés au quota quotidien.
 *
 * Expose : window.BeauBotApiQuota = { fetchStatus }
 */
(function () {
    'use strict';

    /**
     * Récupère l'état du quota pour l'utilisateur courant.
     * @param {object} config - Doit contenir restUrl + nonce (window.beaubotQuotaConfig)
     * @returns {Promise<object>} Promise résolue avec l'objet quota
     */
    function fetchStatus(config) {
        const cfg = config || window.beaubotQuotaConfig || {};
        if (!cfg.restUrl) {
            return Promise.reject(new Error('beaubotQuotaConfig.restUrl manquant'));
        }

        return fetch(cfg.restUrl + 'quota', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce || '',
            },
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    const err = new Error((data && data.message) || 'HTTP ' + response.status);
                    err.status = response.status;
                    err.data = data;
                    throw err;
                }
                return data && data.quota ? data.quota : data;
            });
        });
    }

    window.BeauBotApiQuota = {
        fetchStatus: fetchStatus,
    };
})();
