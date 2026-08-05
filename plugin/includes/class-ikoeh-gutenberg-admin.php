<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Gutenberg_Admin {

    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            'Fila de Blocos - iKOEH Connect',
            'iKOEH Fila de Blocos',
            'edit_posts',
            'ikoeh-connect-gutenberg-queue',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_script('wp-blocks');
        wp_enqueue_script('wp-block-library');
        wp_enqueue_script('wp-element');
        $queue_js_path = IKOEH_CONNECT_DIR . 'assets/gutenberg-queue.js';
        wp_enqueue_script(
            'ikoeh-connect-gutenberg-queue',
            IKOEH_CONNECT_URL . 'assets/gutenberg-queue.js',
            ['wp-blocks', 'wp-block-library', 'wp-element'],
            // filemtime(), not the static IKOEH_CONNECT_VERSION: that constant
            // never changes between self-updates, so a browser that already
            // cached this script keeps serving the stale copy across every
            // future fix -- confirmed live during testing (a real bug fix
            // required a manual hard-reload to actually take effect).
            file_exists($queue_js_path) ? (string) filemtime($queue_js_path) : IKOEH_CONNECT_VERSION,
            true
        );
        wp_localize_script('ikoeh-connect-gutenberg-queue', 'ikoehGutenbergQueue', [
            'restUrl' => rest_url(IKOEH_CONNECT_REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
        ?>
        <div class="wrap">
            <h1>Fila de Blocos</h1>
            <p>Mantenha esta pagina aberta para que mudancas de Gutenberg pendentes sejam validadas e aplicadas automaticamente.</p>
            <div id="ikoeh-gutenberg-queue-status">Conectando...</div>
        </div>
        <?php
    }
}
