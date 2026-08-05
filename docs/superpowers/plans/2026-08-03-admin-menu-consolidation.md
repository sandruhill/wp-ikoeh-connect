# Admin Menu Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce the plugin's wp-admin menu from 3 items to 2 by merging the standalone "iKOEH Chat" page into the main "iKOEH Connect" settings page as a tab, and replace the chat settings' "Salvar" button with per-field auto-save.

**Architecture:** `class-ikoeh-admin.php`'s single settings page gains a standard WP `nav-tab-wrapper` with two tabs (Conexões, Chat). The Chat tab renders the existing chat settings fields (now auto-saving via a new AJAX action) followed by the chat conversation UI, extracted from `class-ikoeh-chat-admin.php` into a public method the settings page calls directly. Fila de Blocos and the connection create/revoke forms are untouched.

**Tech Stack:** PHP (WordPress admin page, no new dependency), vanilla JS (inline `<script>`, matching this file's existing pattern for the Connections tab's "Testar API" button), WordPress core `ajaxurl` global (already relied on elsewhere in this same file).

## Global Constraints

- No new PHP or JS dependency.
- Fila de Blocos (`plugin/includes/class-ikoeh-gutenberg-admin.php`) is not modified at all.
- The connection-creation form and the revoke button in `class-ikoeh-admin.php` keep their existing explicit-submit behavior — they are not converted to auto-save.
- `plugin/assets/chat.js` requires no code changes — it must keep working once its HTML lives inside a tab, by preserving the exact element IDs it already expects: `ikoeh-chat-messages`, `ikoeh-chat-input`, `ikoeh-chat-send`, `ikoeh-chat-status`.
- Sem travessão em nenhum texto novo (comentários, strings, UI), consistente com o resto do projeto.

---

### Task 1: Tab navigation, move chat UI into the Chat tab, remove the old menu item

**Files:**
- Modify: `plugin/includes/class-ikoeh-admin.php`
- Modify: `plugin/includes/class-ikoeh-chat-admin.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Ikoeh_Connect_Chat_Admin::render_chat_ui()` (public static, no params, echoes the chat conversation markup or a "configure a key" notice — callable from `class-ikoeh-admin.php`'s Chat tab). `class-ikoeh-admin.php`'s tab query param: `?page=ikoeh-connect&tab=chat` (default when absent or any other value: `connections`).

This task makes zero functional change to the chat settings form itself (still the same fields, same "Salvar" button, same POST handler) — it only relocates existing markup behind tabs. Task 2 converts the settings form to auto-save.

- [ ] **Step 1: Extract the chat conversation UI into a reusable method**

In `plugin/includes/class-ikoeh-chat-admin.php`, replace the entire file content with:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Chat_Admin {

    /**
     * Renders the chat conversation UI (message history + input + send
     * button) or a "configure a key first" notice. Called directly from
     * class-ikoeh-admin.php's Chat tab -- this class no longer registers
     * its own wp-admin menu page.
     */
    public static function render_chat_ui() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key_configured = '' !== get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');

        if (!$api_key_configured) {
            echo '<p>Configure uma chave de API da Anthropic acima antes de usar o chat.</p>';
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
        <div id="ikoeh-chat-messages" style="max-width:700px;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:12px;min-height:300px;max-height:500px;overflow-y:auto;background:#fff;"></div>
        <div style="max-width:700px;display:flex;gap:8px;">
            <textarea id="ikoeh-chat-input" rows="2" style="flex:1;" placeholder="Digite sua mensagem..."></textarea>
            <button type="button" id="ikoeh-chat-send" class="button button-primary">Enviar</button>
        </div>
        <p id="ikoeh-chat-status" style="color:#d63638;"></p>
        <?php
    }
}
```

(This drops the old `register_menu()` method entirely and the old `render_page()` method's `current_user_can`/`api_key_configured` check duplication is intentional — `render_chat_ui()` stays self-contained so any future caller gets correct behavior without depending on the caller's own checks.)

- [ ] **Step 2: Add tab navigation and move markup in the main settings page**

In `plugin/includes/class-ikoeh-admin.php`, find `public static function render_page() {` and read through to the method's closing `}` (the whole method, roughly from `if (!current_user_can('manage_options')) {` through the final `<?php }` before the class's closing brace). Replace that entire method with:

```php
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

        if (
            isset($_POST['ikoeh_chat_save_settings']) &&
            check_admin_referer('ikoeh_chat_settings_action', 'ikoeh_chat_settings_nonce')
        ) {
            $model = sanitize_text_field(wp_unslash($_POST['ikoeh_chat_model'] ?? Ikoeh_Connect_Chat::DEFAULT_MODEL));
            update_option(Ikoeh_Connect_Chat::OPTION_MODEL, $model);

            $new_key = isset($_POST['ikoeh_chat_api_key']) ? trim(wp_unslash($_POST['ikoeh_chat_api_key'])) : '';
            if ('' !== $new_key) {
                update_option(Ikoeh_Connect_Chat::OPTION_API_KEY, $new_key);
            }

            $new_screenshot_key = isset($_POST['ikoeh_chat_screenshot_api_key']) ? trim(wp_unslash($_POST['ikoeh_chat_screenshot_api_key'])) : '';
            if ('' !== $new_screenshot_key) {
                update_option(Ikoeh_Connect_Site_Inspector::OPTION_SCREENSHOT_API_KEY, $new_screenshot_key);
            }
            $notice = ['type' => 'success', 'text' => 'Configurações do chat salvas.'];
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

            <?php if ($new_token) : ?>
                <div class="notice notice-success">
                    <p><strong>Nova conexão criada.</strong> Copie o token agora, ele não será mostrado novamente:</p>
                    <p><code id="ikoeh-connect-token"><?php echo esc_html($new_token); ?></code></p>
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
                <form method="post">
                    <?php wp_nonce_field('ikoeh_chat_settings_action', 'ikoeh_chat_settings_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Chave de API (Anthropic)</th>
                            <td>
                                <p><?php echo esc_html($chat_key_status); ?></p>
                                <input type="password" name="ikoeh_chat_api_key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ikoeh-chat-screenshot-key">Chave de API do Google (opcional)</label></th>
                            <td>
                                <input type="password" name="ikoeh_chat_screenshot_api_key" id="ikoeh-chat-screenshot-key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                                <p class="description">Nao obrigatoria -- a clonagem de site funciona sem isso. Configure uma chave gratuita do Google Cloud (API PageSpeed Insights habilitada) so se precisar de um limite maior de requisicoes.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ikoeh-chat-model">Modelo</label></th>
                            <td>
                                <select name="ikoeh_chat_model" id="ikoeh-chat-model">
                                    <?php foreach (['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'] as $model_option) : ?>
                                        <option value="<?php echo esc_attr($model_option); ?>" <?php selected($chat_model, $model_option); ?>><?php echo esc_html($model_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="ikoeh_chat_save_settings" class="button button-primary">Salvar configurações do chat</button>
                    </p>
                </form>

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
```

Note: this step keeps the chat settings form and its POST handler completely intact (still the same "Salvar configurações do chat" button) -- Task 2 replaces that specific piece. The only new things here are the tab links, the `$active_tab` computation, wrapping the existing connections markup/script in a `connections`-tab conditional, wrapping the chat settings form + a new `<h2>Conversa</h2>` + `Ikoeh_Connect_Chat_Admin::render_chat_ui()` call in a `chat`-tab conditional.

- [ ] **Step 3: Remove the old standalone menu registration**

In `plugin/wp-ikoeh-connect.php`, find and delete this line entirely:
```php
add_action('admin_menu', ['Ikoeh_Connect_Chat_Admin', 'register_menu']);
```
Do not remove the `require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-admin.php';` line -- that class is still used (via `render_chat_ui()`).

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-admin.php plugin/includes/class-ikoeh-chat-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Deploy and verify live**

```bash
node /private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs
```
Expected: HTTP 200, `{"installed":"wp-ikoeh-connect","target":"plugins"}`.

Then, via a real wp-admin session (use the admin-access-link flow already shipped in this plugin, or a direct login), visit Ajustes > iKOEH Connect. Confirm: (1) the standalone "iKOEH Chat" menu item under Ajustes no longer exists, (2) the iKOEH Connect page shows two tabs, "Conexões" (active by default) and "Chat", (3) clicking "Chat" navigates to `?page=ikoeh-connect&tab=chat` and shows the chat settings form followed by the conversation UI (message box, textarea, send button) with the same visual structure as before, (4) the Connections tab still works exactly as before (status card, test API button, connections table, new connection form), (5) Fila de Blocos still appears in the menu and is unaffected.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-admin.php plugin/includes/class-ikoeh-chat-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: merge Chat page into iKOEH Connect settings as a tab"
```

---

### Task 2: Auto-save for chat settings fields

**Files:**
- Modify: `plugin/includes/class-ikoeh-admin.php`

**Interfaces:**
- Consumes: the Chat tab's settings fields from Task 1 (will be modified in place).
- Produces: AJAX action `ikoeh_chat_save_setting` (POST `field` one of `model`/`api_key`/`screenshot_api_key`, `value` string; returns `{saved: field}` on success).

- [ ] **Step 1: Replace the old POST-based chat settings handling with the new AJAX handler**

In `plugin/includes/class-ikoeh-admin.php`, find this block inside `render_page()` (added/kept as-is in Task 1) and delete it entirely:
```php
        if (
            isset($_POST['ikoeh_chat_save_settings']) &&
            check_admin_referer('ikoeh_chat_settings_action', 'ikoeh_chat_settings_nonce')
        ) {
            $model = sanitize_text_field(wp_unslash($_POST['ikoeh_chat_model'] ?? Ikoeh_Connect_Chat::DEFAULT_MODEL));
            update_option(Ikoeh_Connect_Chat::OPTION_MODEL, $model);

            $new_key = isset($_POST['ikoeh_chat_api_key']) ? trim(wp_unslash($_POST['ikoeh_chat_api_key'])) : '';
            if ('' !== $new_key) {
                update_option(Ikoeh_Connect_Chat::OPTION_API_KEY, $new_key);
            }

            $new_screenshot_key = isset($_POST['ikoeh_chat_screenshot_api_key']) ? trim(wp_unslash($_POST['ikoeh_chat_screenshot_api_key'])) : '';
            if ('' !== $new_screenshot_key) {
                update_option(Ikoeh_Connect_Site_Inspector::OPTION_SCREENSHOT_API_KEY, $new_screenshot_key);
            }
            $notice = ['type' => 'success', 'text' => 'Configurações do chat salvas.'];
        }
```

Add this new method to the class (anywhere, e.g. right after `ajax_test_api()`):
```php
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
```

Update `register_ajax()` to also wire the new action:
```php
    public static function register_ajax() {
        add_action('wp_ajax_ikoeh_connect_test_api', [__CLASS__, 'ajax_test_api']);
        add_action('wp_ajax_ikoeh_chat_save_setting', [__CLASS__, 'ajax_save_chat_setting']);
    }
```

- [ ] **Step 2: Replace the chat settings form with auto-saving fields**

Still in `plugin/includes/class-ikoeh-admin.php`, find this block (the `chat`-tab section added in Task 1) and replace it entirely:
```php
                <h2>Configurações do Chat</h2>
                <form method="post">
                    <?php wp_nonce_field('ikoeh_chat_settings_action', 'ikoeh_chat_settings_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Chave de API (Anthropic)</th>
                            <td>
                                <p><?php echo esc_html($chat_key_status); ?></p>
                                <input type="password" name="ikoeh_chat_api_key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ikoeh-chat-screenshot-key">Chave de API do Google (opcional)</label></th>
                            <td>
                                <input type="password" name="ikoeh_chat_screenshot_api_key" id="ikoeh-chat-screenshot-key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off">
                                <p class="description">Nao obrigatoria -- a clonagem de site funciona sem isso. Configure uma chave gratuita do Google Cloud (API PageSpeed Insights habilitada) so se precisar de um limite maior de requisicoes.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ikoeh-chat-model">Modelo</label></th>
                            <td>
                                <select name="ikoeh_chat_model" id="ikoeh-chat-model">
                                    <?php foreach (['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'] as $model_option) : ?>
                                        <option value="<?php echo esc_attr($model_option); ?>" <?php selected($chat_model, $model_option); ?>><?php echo esc_html($model_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="ikoeh_chat_save_settings" class="button button-primary">Salvar configurações do chat</button>
                    </p>
                </form>
```
with:
```php
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
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-admin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Deploy and verify live**

```bash
node /private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs
```
Then on the Chat tab: change the model dropdown and confirm "Salvo" appears near the fields without a page reload and without clicking any button; type a value into one of the key fields, click/tab away from it (blur), and confirm the same "Salvo" behavior, and confirm the field visually clears back to empty afterward (matching the "write-only, never re-displayed" pattern already used for these keys). Confirm the old "Salvar configurações do chat" button no longer exists anywhere on the page.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-admin.php
git commit -m "feat: auto-save chat settings fields instead of a Salvar button"
```

---

### Task 3: End-to-end live verification

**Files:** none (verification only, may produce fix commits if issues are found).

- [ ] **Step 1: Full page walkthrough**

Deploy (if not already deployed from Task 2) and, via a real wp-admin session:
1. Confirm Ajustes now shows exactly 2 iKOEH-related items: "iKOEH Connect" and "iKOEH Fila de Blocos" (no standalone "iKOEH Chat" entry).
2. On the Connections tab: confirm the status card, test API button, connections table (if any connections exist), and new-connection form all still work exactly as before.
3. On the Chat tab: confirm the settings fields auto-save (per Task 2's verification), and confirm the chat conversation UI renders below them with the same message box / textarea / send button structure `plugin/assets/chat.js` expects.
4. If an Anthropic API key is configured on this site by the time this task runs, send a real message in the chat and confirm it round-trips correctly (this exercises `plugin/assets/chat.js` unchanged, running inside the tab instead of its own page). If no key is configured yet, skip this specific check and note it as not-yet-testable in the report rather than blocking on it -- the tab/auto-save structure can be fully verified without it.
5. Confirm Fila de Blocos still opens and behaves exactly as before (unrelated to this plan's changes, quick sanity check only).

- [ ] **Step 2: Fix any bugs found live**

If any check above fails, fix the underlying code, redeploy, and re-verify before considering this task (and the plan) complete. Commit each fix separately with a clear message describing the bug and the fix.
