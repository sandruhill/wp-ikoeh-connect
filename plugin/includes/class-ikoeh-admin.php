<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Admin {

    const SCOPE_LABELS = [
        'plugins'    => 'Gestão de plugins',
        'content'    => 'Conteúdo',
        'db'         => 'Banco de dados',
        'logs_cache' => 'Logs e cache',
    ];

    const NAME_OPTIONS = ['ChatGPT', 'Claude.ai', 'Claude Code', 'Outro'];

    public static function register_menu() {
        add_options_page(
            'WP iKOEH Connect',
            'iKOEH Connect',
            'manage_options',
            'ikoeh-connect',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_ajax() {
        add_action('wp_ajax_ikoeh_connect_test_api', [__CLASS__, 'ajax_test_api']);
    }

    public static function ajax_test_api() {
        check_ajax_referer('ikoeh_connect_test_api', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $response = wp_remote_get(rest_url('ikoeh-connect/v1/site-info'), ['timeout' => 10]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);

        if (401 === $code) {
            wp_send_json_success(['message' => 'API respondendo normalmente (401 sem token, como esperado).']);
        }

        wp_send_json_error(['message' => "Resposta inesperada da API: HTTP {$code}"]);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $new_token = null;
        $notice = null;

        if (
            isset($_POST['ikoeh_connect_create']) &&
            check_admin_referer('ikoeh_connect_create_action', 'ikoeh_connect_nonce')
        ) {
            $name_choice = isset($_POST['ikoeh_connect_name']) ? sanitize_text_field(wp_unslash($_POST['ikoeh_connect_name'])) : '';
            $name_custom = isset($_POST['ikoeh_connect_name_custom']) ? sanitize_text_field(wp_unslash($_POST['ikoeh_connect_name_custom'])) : '';
            $name = ('Outro' === $name_choice && $name_custom) ? $name_custom : $name_choice;

            $scopes = isset($_POST['ikoeh_connect_scopes']) && is_array($_POST['ikoeh_connect_scopes'])
                ? array_map('sanitize_key', wp_unslash($_POST['ikoeh_connect_scopes']))
                : [];

            if ($name && !empty($scopes)) {
                $new_token = Ikoeh_Connect_Auth::create_connection($name, $scopes);
            } else {
                $notice = ['type' => 'error', 'text' => 'Escolha um nome e pelo menos um escopo.'];
            }
        }

        if (
            isset($_POST['ikoeh_connect_revoke']) &&
            check_admin_referer('ikoeh_connect_revoke_action', 'ikoeh_connect_revoke_nonce')
        ) {
            Ikoeh_Connect_Auth::revoke_connection(sanitize_text_field(wp_unslash($_POST['ikoeh_connect_revoke'])));
            $notice = ['type' => 'success', 'text' => 'Conexão revogada.'];
        }

        $connections = Ikoeh_Connect_Auth::get_connections();
        $has_connections = count($connections) > 0;
        ?>
        <div class="wrap">
            <h1>WP iKOEH Connect</h1>
            <p>Conecta este site a IAs (Claude, ChatGPT, etc) para desenvolvimento e otimização assistida.</p>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($new_token) : ?>
                <div class="notice notice-success">
                    <p><strong>Nova conexão criada.</strong> Copie o token agora, ele não será mostrado novamente:</p>
                    <p><code id="ikoeh-connect-token"><?php echo esc_html($new_token); ?></code></p>
                </div>
            <?php endif; ?>

            <div style="
                background: <?php echo $has_connections ? '#edfaef' : '#fcf0f1'; ?>;
                border-left: 4px solid <?php echo $has_connections ? '#00a32a' : '#d63638'; ?>;
                padding: 1px 12px;
                margin: 16px 0;
            ">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <?php if ($has_connections) : ?>
                                <span style="color:#00a32a;">&#9679;</span> <?php echo count($connections); ?> conexão(ões) ativa(s)
                            <?php else : ?>
                                <span style="color:#d63638;">&#9679;</span> Nenhuma conexão criada ainda
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="ikoeh-connect-test-api" class="button">Testar API</button>
                    <span id="ikoeh-connect-test-result"></span>
                </p>
            </div>

            <?php if ($has_connections) : ?>
                <h2>Conexões</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Escopos</th>
                            <th>Última atividade</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($connections as $connection) : ?>
                            <tr>
                                <td><?php echo esc_html($connection['name']); ?></td>
                                <td>
                                    <?php
                                    $labels = array_map(function ($scope) {
                                        return self::SCOPE_LABELS[$scope] ?? $scope;
                                    }, $connection['scopes']);
                                    echo esc_html(implode(', ', $labels));
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($connection['last_used_at'])) : ?>
                                        Há <?php echo esc_html(human_time_diff((int) $connection['last_used_at'], time())); ?>
                                    <?php else : ?>
                                        Nenhuma chamada ainda
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Revogar esta conexão? Quem estiver usando esse token perde acesso imediatamente.');">
                                        <?php wp_nonce_field('ikoeh_connect_revoke_action', 'ikoeh_connect_revoke_nonce'); ?>
                                        <input type="hidden" name="ikoeh_connect_revoke" value="<?php echo esc_attr($connection['id']); ?>">
                                        <button type="submit" class="button button-link-delete">Revogar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Nova conexão</h2>
            <form method="post">
                <?php wp_nonce_field('ikoeh_connect_create_action', 'ikoeh_connect_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ikoeh-connect-name">Nome</label></th>
                        <td>
                            <select name="ikoeh_connect_name" id="ikoeh-connect-name">
                                <?php foreach (self::NAME_OPTIONS as $option) : ?>
                                    <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="ikoeh_connect_name_custom" id="ikoeh-connect-name-custom" placeholder="Nome customizado" style="display:none; margin-left:8px;">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Escopos</th>
                        <td>
                            <?php foreach (self::SCOPE_LABELS as $scope => $label) : ?>
                                <label style="display:block; margin-bottom:4px;">
                                    <input type="checkbox" name="ikoeh_connect_scopes[]" value="<?php echo esc_attr($scope); ?>">
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="ikoeh_connect_create" class="button button-primary">Gerar conexão</button>
                </p>
            </form>
        </div>
        <script>
        (function () {
            var nameSelect = document.getElementById('ikoeh-connect-name');
            var nameCustom = document.getElementById('ikoeh-connect-name-custom');
            function toggleCustom() {
                nameCustom.style.display = nameSelect.value === 'Outro' ? 'inline-block' : 'none';
            }
            nameSelect.addEventListener('change', toggleCustom);
            toggleCustom();

            var testButton = document.getElementById('ikoeh-connect-test-api');
            var testResult = document.getElementById('ikoeh-connect-test-result');
            testButton.addEventListener('click', function () {
                testResult.textContent = 'Testando...';
                var data = new URLSearchParams();
                data.append('action', 'ikoeh_connect_test_api');
                data.append('nonce', '<?php echo esc_js(wp_create_nonce('ikoeh_connect_test_api')); ?>');
                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        testResult.textContent = json.data.message;
                        testResult.style.color = json.success ? '#00a32a' : '#d63638';
                    })
                    .catch(function () {
                        testResult.textContent = 'Falha ao testar.';
                        testResult.style.color = '#d63638';
                    });
            });
        })();
        </script>
        <?php
    }
}
