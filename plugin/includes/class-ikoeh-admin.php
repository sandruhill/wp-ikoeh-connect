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
        'elementor'  => 'Elementor',
        'theme'      => 'Temas',
        'media'      => 'Mídia',
        'admin_access' => 'Acesso admin temporário',
        'gutenberg' => 'Gutenberg (mudancas pendentes)',
        'skills' => 'Skills',
        'design' => 'Design System',
        'system' => 'Sistema (arquivos/PHP/WP-CLI)',
    ];

    const NAME_OPTIONS = ['ChatGPT', 'Claude.ai', 'Claude Code', 'Outro'];

    const ACTIVE_WINDOW_SECONDS = 7 * DAY_IN_SECONDS;

    /**
     * Connection status relative to real API usage, not just "exists and
     * isn't revoked" -- a connection can be created and never actually
     * called by anything.
     */
    private static function connection_status($connection) {
        if (empty($connection['last_used_at'])) {
            return ['dot' => '#8c8f94', 'label' => 'Nunca usada'];
        }

        $age = time() - (int) $connection['last_used_at'];
        if ($age <= self::ACTIVE_WINDOW_SECONDS) {
            return ['dot' => '#00a32a', 'label' => 'Há ' . human_time_diff((int) $connection['last_used_at'], time())];
        }

        return ['dot' => '#dba617', 'label' => 'Há ' . human_time_diff((int) $connection['last_used_at'], time()) . ' (inativa)'];
    }

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
        add_action('wp_ajax_ikoeh_chat_save_setting', [__CLASS__, 'ajax_save_chat_setting']);
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

    public static function ajax_save_chat_setting() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
        $value = isset($_POST['value']) ? trim(wp_unslash($_POST['value'])) : '';

        switch ($field) {
            case 'model':
                update_option(Ikoeh_Connect_Chat::OPTION_MODEL, sanitize_text_field($value));
                break;
            case 'api_key':
                if ('' !== $value) {
                    update_option(Ikoeh_Connect_Chat::OPTION_API_KEY, $value);
                }
                break;
            case 'screenshot_api_key':
                if ('' !== $value) {
                    update_option(Ikoeh_Connect_Site_Inspector::OPTION_SCREENSHOT_API_KEY, $value);
                }
                break;
            default:
                wp_send_json_error(['message' => 'Campo inválido.']);
        }

        wp_send_json_success(['saved' => $field]);
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

        $recently_used_count = 0;
        foreach ($connections as $connection) {
            if (!empty($connection['last_used_at']) && (time() - (int) $connection['last_used_at']) <= self::ACTIVE_WINDOW_SECONDS) {
                $recently_used_count++;
            }
        }

        if (!$has_connections) {
            $banner = ['bg' => '#fcf0f1', 'border' => '#d63638', 'dot' => '#d63638', 'text' => 'Nenhuma conexão criada ainda'];
        } elseif ($recently_used_count > 0) {
            $banner = ['bg' => '#edfaef', 'border' => '#00a32a', 'dot' => '#00a32a', 'text' => "{$recently_used_count} de " . count($connections) . ' conexões em uso (últimos 7 dias)'];
        } else {
            $banner = ['bg' => '#fcf9e8', 'border' => '#dba617', 'dot' => '#dba617', 'text' => count($connections) . ' conexão(ões) criada(s), nenhuma usada nos últimos 7 dias'];
        }

        $active_tab = (isset($_GET['tab']) && 'chat' === $_GET['tab']) ? 'chat' : 'connections';
        ?>
        <style>
            .ikoeh-connect-status-card { background: <?php echo esc_attr($banner['bg']); ?>; border-left: 4px solid <?php echo esc_attr($banner['border']); ?>; border-radius: 2px; padding: 4px 16px 16px; margin: 16px 0; }
            .ikoeh-connect-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; }
            .ikoeh-connect-scopes-actions { margin: 4px 0 10px; }
            .ikoeh-connect-scopes-actions a { margin-right: 12px; font-size: 12px; }
        </style>
        <div class="wrap">
            <h1>WP iKOEH Connect</h1>
            <p>Conecta este site a IAs (Claude, ChatGPT, etc) para desenvolvimento e otimização assistida.</p>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($new_token) :
                $site_url = untrailingslashit(home_url());
                $conf_block = "url={$site_url}\ntoken={$new_token}";
            ?>
                <div class="notice notice-success">
                    <p><strong>Nova conexão criada.</strong> Copie o endereço e o token agora, o token não será mostrado novamente. Sem o endereço junto, o token sozinho não conecta a lugar nenhum. Guarde os dois juntos.</p>
                    <p>
                        <textarea id="ikoeh-connect-conf-block" readonly rows="2" style="width:100%;max-width:640px;font-family:monospace;font-size:13px;" onclick="this.select();"><?php echo esc_textarea($conf_block); ?></textarea>
                    </p>
                    <p>
                        <button type="button" class="button button-secondary" onclick="var t=document.getElementById('ikoeh-connect-conf-block'); t.select(); t.setSelectionRange(0, 99999); navigator.clipboard.writeText(t.value); this.textContent='Copiado!'; var b=this; setTimeout(function(){ b.textContent='Copiar endereço + token'; }, 2000);">Copiar endereço + token</button>
                    </p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'ikoeh-connect', 'tab' => 'connections'], admin_url('options-general.php'))); ?>" class="nav-tab <?php echo 'connections' === $active_tab ? 'nav-tab-active' : ''; ?>">Conexões</a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'ikoeh-connect', 'tab' => 'chat'], admin_url('options-general.php'))); ?>" class="nav-tab <?php echo 'chat' === $active_tab ? 'nav-tab-active' : ''; ?>">Chat</a>
            </h2>

            <?php if ('connections' === $active_tab) : ?>
                <div class="ikoeh-connect-status-card">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Status</th>
                            <td><span class="ikoeh-connect-dot" style="background:<?php echo esc_attr($banner['dot']); ?>"></span><?php echo esc_html($banner['text']); ?></td>
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
                                        <?php $status = self::connection_status($connection); ?>
                                        <span class="ikoeh-connect-dot" style="background:<?php echo esc_attr($status['dot']); ?>"></span><?php echo esc_html($status['label']); ?>
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
                                <p class="ikoeh-connect-scopes-actions">
                                    <a href="#" id="ikoeh-connect-select-all">Selecionar todos</a><a href="#" id="ikoeh-connect-select-none">Limpar seleção</a>
                                </p>
                                <?php foreach (self::SCOPE_LABELS as $scope => $label) : ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" class="ikoeh-connect-scope-checkbox" name="ikoeh_connect_scopes[]" value="<?php echo esc_attr($scope); ?>">
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
            <?php endif; ?>

            <?php if ('chat' === $active_tab) : ?>
                <?php
                $chat_key = get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');
                $chat_key_status = '' !== $chat_key ? ('Chave configurada (termina em ...' . esc_html(substr($chat_key, -4)) . ')') : 'Nenhuma chave configurada';
                $chat_model = get_option(Ikoeh_Connect_Chat::OPTION_MODEL, '') ?: Ikoeh_Connect_Chat::DEFAULT_MODEL;
                ?>
                <h2>Configurações do Chat</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Chave de API (Anthropic)</th>
                        <td>
                            <p><?php echo esc_html($chat_key_status); ?></p>
                            <input type="password" id="ikoeh-chat-api-key" data-field="api_key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ikoeh-chat-screenshot-key">Chave de API do Google (opcional)</label></th>
                        <td>
                            <input type="password" id="ikoeh-chat-screenshot-key" data-field="screenshot_api_key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                            <p class="description">Nao obrigatoria -- a clonagem de site funciona sem isso. Configure uma chave gratuita do Google Cloud (API PageSpeed Insights habilitada) so se precisar de um limite maior de requisicoes.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ikoeh-chat-model">Modelo</label></th>
                        <td>
                            <select id="ikoeh-chat-model" data-field="model">
                                <?php foreach (['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'] as $model_option) : ?>
                                    <option value="<?php echo esc_attr($model_option); ?>" <?php selected($chat_model, $model_option); ?>><?php echo esc_html($model_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p id="ikoeh-chat-autosave-status"></p>
                <script>
                (function () {
                    var nonce = '<?php echo esc_js(wp_create_nonce('ikoeh_chat_send')); ?>';
                    var statusEl = document.getElementById('ikoeh-chat-autosave-status');
                    var fields = document.querySelectorAll('[data-field]');

                    function saveField(el) {
                        var value = el.value;
                        if (el.type === 'password' && '' === value) {
                            return;
                        }

                        var data = new URLSearchParams();
                        data.append('action', 'ikoeh_chat_save_setting');
                        data.append('nonce', nonce);
                        data.append('field', el.getAttribute('data-field'));
                        data.append('value', value);

                        fetch(ajaxurl, { method: 'POST', body: data })
                            .then(function (response) { return response.json(); })
                            .then(function (json) {
                                if (json.success) {
                                    if (el.type === 'password') {
                                        el.value = '';
                                    }
                                    statusEl.style.color = '#00a32a';
                                    statusEl.textContent = 'Salvo';
                                    setTimeout(function () { statusEl.textContent = ''; }, 2000);
                                } else {
                                    statusEl.style.color = '#d63638';
                                    statusEl.textContent = 'Erro: ' + (json.data && json.data.message ? json.data.message : 'falha desconhecida');
                                }
                            })
                            .catch(function () {
                                statusEl.style.color = '#d63638';
                                statusEl.textContent = 'Erro ao salvar.';
                            });
                    }

                    fields.forEach(function (el) {
                        var eventName = el.tagName === 'SELECT' ? 'change' : 'blur';
                        el.addEventListener(eventName, function () { saveField(el); });
                    });
                })();
                </script>

                <h2>Conversa</h2>
                <?php Ikoeh_Connect_Chat_Admin::render_chat_ui(); ?>
            <?php endif; ?>
        </div>
        <?php if ('connections' === $active_tab) : ?>
        <script>
        (function () {
            var nameSelect = document.getElementById('ikoeh-connect-name');
            var nameCustom = document.getElementById('ikoeh-connect-name-custom');
            function toggleCustom() {
                nameCustom.style.display = nameSelect.value === 'Outro' ? 'inline-block' : 'none';
            }
            nameSelect.addEventListener('change', toggleCustom);
            toggleCustom();

            var scopeCheckboxes = document.querySelectorAll('.ikoeh-connect-scope-checkbox');
            function setAllScopes(checked) {
                scopeCheckboxes.forEach(function (checkbox) { checkbox.checked = checked; });
            }
            document.getElementById('ikoeh-connect-select-all').addEventListener('click', function (e) {
                e.preventDefault();
                setAllScopes(true);
            });
            document.getElementById('ikoeh-connect-select-none').addEventListener('click', function (e) {
                e.preventDefault();
                setAllScopes(false);
            });

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
        <?php endif; ?>
        <?php
    }
}
