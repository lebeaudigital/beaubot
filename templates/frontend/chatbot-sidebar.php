<?php
/**
 * Template: Chatbot Sidebar Frontend
 * 
 * Ce template est affiché dans le footer pour les utilisateurs connectés.
 */

if (!defined('ABSPATH')) {
    exit;
}

$options = get_option('beaubot_settings', []);
$position = $options['sidebar_position'] ?? 'right';
$bot_name = $options['bot_name'] ?? 'BeauBot';
$primary_color = $options['primary_color'] ?? '#6366f1';

// Vérifier les préférences utilisateur
$user_id = get_current_user_id();
$user_prefs = get_user_meta($user_id, 'beaubot_preferences', true);
if (!empty($user_prefs['sidebar_position'])) {
    $position = $user_prefs['sidebar_position'];
}

// Calculer les variantes de couleur
$primary_rgb = sscanf($primary_color, "#%02x%02x%02x");
$primary_dark = sprintf("#%02x%02x%02x", 
    max(0, $primary_rgb[0] - 20), 
    max(0, $primary_rgb[1] - 20), 
    max(0, $primary_rgb[2] - 20)
);
$primary_light = sprintf("#%02x%02x%02x", 
    min(255, $primary_rgb[0] + 30), 
    min(255, $primary_rgb[1] + 30), 
    min(255, $primary_rgb[2] + 30)
);
?>

<!-- CSS Variables personnalisées -->
<style>
:root {
    --beaubot-primary: <?php echo esc_attr($primary_color); ?>;
    --beaubot-primary-dark: <?php echo esc_attr($primary_dark); ?>;
    --beaubot-primary-light: <?php echo esc_attr($primary_light); ?>;
    --beaubot-user-bg: <?php echo esc_attr($primary_color); ?>;
}
</style>

<!-- Toggle Button -->
<button id="beaubot-toggle" class="beaubot-<?php echo esc_attr($position); ?>" aria-label="<?php esc_attr_e('Ouvrir le chatbot', 'beaubot'); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>
</button>

<!-- Overlay (mobile) -->
<div id="beaubot-overlay"></div>

<!-- Sidebar -->
<div id="beaubot-sidebar" class="beaubot-<?php echo esc_attr($position); ?>">
    
    <!-- Header -->
    <header class="beaubot-header">
        <div class="beaubot-header-left">
            <div class="beaubot-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                </svg>
            </div>
            <span class="beaubot-title"><?php echo esc_html($bot_name); ?></span>
        </div>
        <div class="beaubot-header-actions">
            <button type="button" 
                    class="beaubot-header-btn" 
                    id="beaubot-new-conversation" 
                    title="<?php esc_attr_e('Nouvelle conversation', 'beaubot'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
            </button>
            <button type="button" 
                    class="beaubot-header-btn" 
                    id="beaubot-history-toggle" 
                    title="<?php esc_attr_e('Historique', 'beaubot'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </button>
            <button type="button" 
                    class="beaubot-header-btn beaubot-position-toggle" 
                    title="<?php esc_attr_e('Changer de côté', 'beaubot'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <polyline points="9 21 3 21 3 15"></polyline>
                    <line x1="21" y1="3" x2="14" y2="10"></line>
                    <line x1="3" y1="21" x2="10" y2="14"></line>
                </svg>
            </button>
            <button type="button" 
                    class="beaubot-header-btn beaubot-close" 
                    title="<?php esc_attr_e('Fermer', 'beaubot'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </header>

    <!-- History Panel -->
    <div id="beaubot-history-panel">
        <div class="beaubot-history-header">
            <h3><?php esc_html_e('Historique', 'beaubot'); ?></h3>
            <div class="beaubot-history-actions">
                <button type="button" 
                        class="beaubot-header-btn" 
                        id="beaubot-archived-toggle"
                        title="<?php esc_attr_e('Conversations archivées', 'beaubot'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="21 8 21 21 3 21 3 8"></polyline>
                        <rect x="1" y="3" width="22" height="5"></rect>
                        <line x1="10" y1="12" x2="14" y2="12"></line>
                    </svg>
                </button>
                <button type="button" 
                        class="beaubot-header-btn" 
                        id="beaubot-history-toggle"
                        title="<?php esc_attr_e('Fermer', 'beaubot'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>
        <div id="beaubot-history-list">
            <!-- Populated by JavaScript -->
        </div>
    </div>

    <!-- Messages Container -->
    <div id="beaubot-messages">
        <!-- Messages are added here dynamically -->
    </div>

    <!-- Typing Indicator -->
    <div id="beaubot-typing">
        <div class="beaubot-avatar">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
            </svg>
        </div>
        <div class="beaubot-typing-content">
            <div class="beaubot-typing-dots">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </div>

    <!-- Input Area -->
    <div id="beaubot-input-area">
        <!-- Image Preview -->
        <div id="beaubot-image-preview"></div>
        
        <div class="beaubot-input-container">
            <textarea id="beaubot-input" 
                      placeholder="<?php esc_attr_e('Posez votre question...', 'beaubot'); ?>" 
                      rows="1"
                      aria-label="<?php esc_attr_e('Message', 'beaubot'); ?>"></textarea>
            
            <div class="beaubot-input-actions">
                <button type="button" 
                        id="beaubot-upload-btn" 
                        class="beaubot-input-btn"
                        title="<?php esc_attr_e('Ajouter une image', 'beaubot'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                </button>
                <button type="button" 
                        id="beaubot-send" 
                        class="beaubot-input-btn"
                        title="<?php esc_attr_e('Envoyer', 'beaubot'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Hidden File Input -->
        <input type="file" 
               id="beaubot-file-input" 
               accept="image/jpeg,image/png,image/gif,image/webp"
               aria-hidden="true">
    </div>
</div>
