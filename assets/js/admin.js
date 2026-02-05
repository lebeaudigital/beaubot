/**
 * BeauBot Admin JavaScript
 * Gère les interactions dans la page de paramètres admin
 */

(function($) {
    'use strict';

    const BeauBotAdmin = {
        init() {
            this.bindEvents();
        },

        bindEvents() {
            // Toggle visibilité API key
            $('#beaubot-toggle-api-key').on('click', this.toggleApiKeyVisibility);

            // Test API
            $('#beaubot-test-api').on('click', this.testApiConnection);

            // Temperature slider
            $('#beaubot_temperature').on('input', this.updateTemperatureValue);

            // Confirmation de suppression
            $('.beaubot-delete-conversation').on('click', this.confirmDelete);
        },

        /**
         * Toggle la visibilité de la clé API
         */
        toggleApiKeyVisibility() {
            const input = $('#beaubot_api_key');
            const button = $(this);

            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                button.text(beaubotAdmin.strings?.hide || 'Masquer');
            } else {
                input.attr('type', 'password');
                button.text(beaubotAdmin.strings?.show || 'Afficher');
            }
        },

        /**
         * Tester la connexion à l'API
         */
        testApiConnection() {
            const button = $(this);
            const originalText = button.text();
            
            button.prop('disabled', true).text('Test en cours...');

            $.ajax({
                url: beaubotAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'beaubot_test_api',
                    nonce: beaubotAdmin.nonce,
                    api_key: $('#beaubot_api_key').val()
                },
                success(response) {
                    if (response.success) {
                        BeauBotAdmin.showNotice('success', beaubotAdmin.strings?.testSuccess || 'Connexion réussie !');
                    } else {
                        BeauBotAdmin.showNotice('error', response.data?.message || beaubotAdmin.strings?.testError || 'Erreur de connexion');
                    }
                },
                error() {
                    BeauBotAdmin.showNotice('error', beaubotAdmin.strings?.testError || 'Erreur de connexion');
                },
                complete() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Mettre à jour l'affichage de la température
         */
        updateTemperatureValue() {
            const value = $(this).val();
            $('#beaubot_temperature_value').text(value);
        },

        /**
         * Confirmation de suppression
         */
        confirmDelete(e) {
            if (!confirm(beaubotAdmin.strings?.confirmDelete || 'Êtes-vous sûr ?')) {
                e.preventDefault();
            }
        },

        /**
         * Afficher une notice
         * @param {string} type - 'success' ou 'error'
         * @param {string} message
         */
        showNotice(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const notice = $(`
                <div class="notice ${noticeClass} is-dismissible beaubot-notice">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Fermer</span>
                    </button>
                </div>
            `);

            $('.wrap h1').after(notice);

            // Auto-dismiss après 5 secondes
            setTimeout(() => {
                notice.fadeOut(300, () => notice.remove());
            }, 5000);

            // Click dismiss
            notice.find('.notice-dismiss').on('click', () => {
                notice.fadeOut(300, () => notice.remove());
            });
        }
    };

    // Handler AJAX pour le test API
    $(document).ready(() => {
        BeauBotAdmin.init();
    });

    // Ajouter le handler AJAX côté serveur
    if (typeof wp !== 'undefined' && wp.ajax) {
        // WordPress AJAX
    }

})(jQuery);

// Action AJAX PHP (à ajouter dans class-beaubot-admin.php si nécessaire)
/*
add_action('wp_ajax_beaubot_test_api', function() {
    check_ajax_referer('beaubot_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Accès non autorisé']);
    }
    
    $api_key = sanitize_text_field($_POST['api_key'] ?? '');
    
    // Test temporaire avec la clé fournie
    $temp_options = get_option('beaubot_settings', []);
    $temp_options['api_key'] = $api_key;
    
    $chatgpt = new BeauBot_API_ChatGPT();
    // Utiliser la clé temporaire pour le test
    
    $result = $chatgpt->test_connection();
    
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    
    wp_send_json_success($result);
});
*/
