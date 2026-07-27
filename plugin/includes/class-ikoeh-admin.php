<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Admin {

    public static function register_menu() {
        add_options_page(
            'WP iKOEH Connect',
            'iKOEH Connect',
            'manage_options',
            'ikoeh-connect',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $new_token = null;

        if (
            isset($_POST['ikoeh_connect_regenerate']) &&
            check_admin_referer('ikoeh_connect_regenerate_action', 'ikoeh_connect_nonce')
        ) {
            $new_token = Ikoeh_Connect_Auth::generate_token();
            Ikoeh_Connect_Auth::store_token($new_token);
            update_option(Ikoeh_Connect_Setup::CLAIMED_OPTION, true, false);
        }

        $has_token = Ikoeh_Connect_Auth::has_token();
        $last_used = Ikoeh_Connect_Auth::last_used_at();
        ?>
        <div class="wrap">
            <h1>WP iKOEH Connect</h1>
            <p>Conecta este site ao Claude para desenvolvimento e otimização assistida por IA.</p>

            <?php if ($new_token) : ?>
                <div class="notice notice-success">
                    <p><strong>Novo token gerado.</strong> Copie agora, ele não será mostrado novamente:</p>
                    <p><code id="ikoeh-connect-token"><?php echo esc_html($new_token); ?></code></p>
                </div>
            <?php endif; ?>

            <div style="
                background: <?php echo $has_token ? '#edfaef' : '#fcf0f1'; ?>;
                border-left: 4px solid <?php echo $has_token ? '#00a32a' : '#d63638'; ?>;
                padding: 1px 12px;
                margin: 16px 0;
            ">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Status da conexão</th>
                        <td>
                            <?php if ($has_token) : ?>
                                <span style="color:#00a32a;">&#9679;</span> Token configurado
                            <?php else : ?>
                                <span style="color:#d63638;">&#9679;</span> Nenhum token configurado
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($has_token) : ?>
                        <tr>
                            <th scope="row">Última atividade</th>
                            <td>
                                <?php if ($last_used) : ?>
                                    Há <?php echo esc_html(human_time_diff($last_used, time())); ?>
                                    (<?php echo esc_html(date_i18n('d/m/Y H:i', $last_used)); ?>)
                                <?php else : ?>
                                    Nenhuma chamada recebida ainda
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <form method="post">
                <?php wp_nonce_field('ikoeh_connect_regenerate_action', 'ikoeh_connect_nonce'); ?>
                <p class="submit">
                    <button type="submit" name="ikoeh_connect_regenerate" class="button button-primary">
                        <?php echo $has_token ? 'Regenerar token' : 'Gerar token'; ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
}
