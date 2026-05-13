/**
 * BeauBot API - Chat
 * Module ES6 exposant l'appel REST POST /chat.
 *
 * Expose : window.BeauBotApiChat = { sendMessage }
 */
(function () {
    'use strict';

    /**
     * Envoyer un message au chatbot et récupérer la réponse complète.
     * @param {object} config - Doit contenir restUrl + nonce.
     * @param {object} payload - { message, conversation_id?, image?, user_profile_level? }
     * @returns {Promise<object>} Réponse REST : { success, conversation_id, message, sources, usage, quota }
     */
    function sendMessage(config, payload) {
        const cfg = config || window.beaubotConfig || {};
        if (!cfg.restUrl) {
            return Promise.reject(new Error('beaubotConfig.restUrl manquant'));
        }

        return fetch(cfg.restUrl + 'chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce || '',
            },
            body: JSON.stringify(payload || {}),
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    const err = new Error((data && data.message) || 'HTTP ' + response.status);
                    err.status = response.status;
                    err.data = data;
                    throw err;
                }
                return data;
            });
        });
    }

    window.BeauBotApiChat = {
        sendMessage: sendMessage,
    };
})();
