<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Chat_Admin {

    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            'Chat iKOEH',
            'iKOEH Chat',
            'manage_options',
            'ikoeh-connect-chat',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key_configured = '' !== get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');

        if (!$api_key_configured) {
            $settings_url = admin_url('options-general.php?page=ikoeh-connect');
            ?>
            <div class="wrap">
                <h1>Chat iKOEH</h1>
                <p>Configure uma chave de API da Anthropic em <a href="<?php echo esc_url($settings_url); ?>">Ajustes &gt; iKOEH Connect</a> antes de usar o chat.</p>
            </div>
            <?php
            return;
        }

        $chat_js_path = IKOEH_CONNECT_DIR . 'assets/chat.js';
        wp_enqueue_script(
            'ikoeh-connect-chat',
            IKOEH_CONNECT_URL . 'assets/chat.js',
            [],
            file_exists($chat_js_path) ? (string) filemtime($chat_js_path) : IKOEH_CONNECT_VERSION,
            true
        );
        wp_localize_script('ikoeh-connect-chat', 'ikoehChat', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ikoeh_chat_send'),
            'history' => Ikoeh_Connect_Chat::get_history(),
        ]);
        ?>
        <div class="wrap">
            <h1>Chat iKOEH</h1>
            <div id="ikoeh-chat-messages" style="max-width:700px;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:12px;min-height:300px;max-height:500px;overflow-y:auto;background:#fff;"></div>
            <div style="max-width:700px;display:flex;gap:8px;">
                <textarea id="ikoeh-chat-input" rows="2" style="flex:1;" placeholder="Digite sua mensagem..."></textarea>
                <button type="button" id="ikoeh-chat-send" class="button button-primary">Enviar</button>
            </div>
            <p id="ikoeh-chat-status" style="color:#d63638;"></p>
        </div>
        <?php
    }
}
