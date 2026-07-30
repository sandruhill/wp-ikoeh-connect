# Embedded wp-admin Chat Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a wp-admin page where the site owner (not the external agent) can chat directly with Claude, using their own Anthropic API key, entirely within WordPress.

**Architecture:** A new settings section (in the existing Connections page) stores the site owner's own Anthropic API key and model choice. A new admin-ajax handler receives a chat message, appends it to a WP-option-backed conversation history, calls Anthropic's Messages API server-side via `wp_remote_post()`, appends the reply, and returns the updated history. A new wp-admin page renders the conversation and a send box, following the same enqueue/localize pattern already used for the Gutenberg "Fila de Blocos" page.

**Tech Stack:** PHP (WordPress plugin, `wp_remote_post()` -- no HTTP client dependency), vanilla JS (no build step).

## Global Constraints

- This feature is entirely wp-admin-session-authenticated: `check_ajax_referer()` + `current_user_can('manage_options')`, exactly like the existing "Testar API" button in `class-ikoeh-admin.php`. It does NOT use `Ikoeh_Connect_Auth::require_scope()` (no new REST scope, no MCP tool file) -- the external Bearer-token agent has no involvement in this feature at all.
- The Anthropic API key is write-only from the browser's perspective: once saved, it is never returned in any AJAX/page response, not even masked. The settings UI shows only whether a key is configured (yes/no + last 4 characters) and an empty input to optionally replace it.
- `update_option()` does not need `wp_slash()` -- unlike `wp_insert_post()`/`wp_update_post()`, it doesn't call `wp_unslash()` internally, so wrapping it would double-slash and corrupt the stored value. This exact distinction has already caused a real bug in an earlier feature in this codebase (Design System's `activate_design()` correctly leaves its `update_option()` call unwrapped) -- don't wrap any `update_option()` call in this plan either.
- PHP files must pass `php -l`. JS files must pass `node --check`.
- No PHPUnit exists in this repo. This feature can't be meaningfully covered by the existing curl+wp-cli CI pattern (calling the real Anthropic API from CI would require a real API key and incur real cost) -- verification is `php -l`/`node --check` plus a live manual smoke test against `doctorbeats.com.br` (deploy, confirm the settings section and chat page render without a fatal error; a real end-to-end message send requires an actual Anthropic API key, which is out of scope for automated verification and is instead a manual step for whoever configures the feature).
- Script/style enqueues must use `filemtime()` for cache-busting, not the static `IKOEH_CONNECT_VERSION` constant -- that constant never changes across self-updates, and a stale cached copy of a JS asset was a real, confirmed bug in an earlier feature in this codebase (Gutenberg's `gutenberg-queue.js`).

---

### Task 1: Chat settings storage and message-send AJAX handler

**Files:**
- Create: `plugin/includes/class-ikoeh-chat.php`
- Modify: `plugin/includes/class-ikoeh-admin.php` (add a settings section + POST handler to the existing Connections page)
- Modify: `plugin/wp-ikoeh-connect.php` (require the new file, hook the AJAX action)

**Interfaces:**
- Produces:
  - `class Ikoeh_Connect_Chat`
  - `const Ikoeh_Connect_Chat::OPTION_API_KEY = 'ikoeh_chat_api_key'`
  - `const Ikoeh_Connect_Chat::OPTION_MODEL = 'ikoeh_chat_model'`
  - `const Ikoeh_Connect_Chat::OPTION_HISTORY = 'ikoeh_chat_history'`
  - `const Ikoeh_Connect_Chat::DEFAULT_MODEL = 'claude-sonnet-5'`
  - `Ikoeh_Connect_Chat::register_ajax(): void` -- hooks `wp_ajax_ikoeh_chat_send`
  - `Ikoeh_Connect_Chat::get_history(): array` -- returns the stored conversation as a list of `['role' => 'user'|'assistant', 'content' => string, 'timestamp' => int]`
  - AJAX action `ikoeh_chat_send` (POST `message`, nonce action `ikoeh_chat_send`) -- on success, `wp_send_json_success(['history' => array])`; on failure, `wp_send_json_error(['message' => string])`.
- Consumes: nothing from other tasks (foundation for this plan).

- [ ] **Step 1: Write the chat class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side chat: the site owner's own Anthropic API key, a single
 * continuing conversation history, and a plain (no tool-calling) message
 * exchange. Entirely wp-admin-session-authenticated -- not part of the
 * external Bearer-token/MCP system at all.
 */
class Ikoeh_Connect_Chat {

    const OPTION_API_KEY = 'ikoeh_chat_api_key';
    const OPTION_MODEL = 'ikoeh_chat_model';
    const OPTION_HISTORY = 'ikoeh_chat_history';
    const DEFAULT_MODEL = 'claude-sonnet-5';
    const MAX_HISTORY_MESSAGES = 50;
    const MAX_TOKENS = 1024;
    const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    public static function register_ajax() {
        add_action('wp_ajax_ikoeh_chat_send', [__CLASS__, 'ajax_send']);
    }

    public static function get_history() {
        $history = get_option(self::OPTION_HISTORY, []);
        return is_array($history) ? $history : [];
    }

    private static function save_history(array $history) {
        $trimmed = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        update_option(self::OPTION_HISTORY, $trimmed, false);
        return $trimmed;
    }

    public static function ajax_send() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $api_key = get_option(self::OPTION_API_KEY, '');
        if ('' === $api_key) {
            wp_send_json_error(['message' => 'Configure uma chave de API da Anthropic primeiro.']);
        }

        $message = isset($_POST['message']) ? trim(wp_unslash($_POST['message'])) : '';
        if ('' === $message) {
            wp_send_json_error(['message' => 'Mensagem vazia.']);
        }

        $history = self::get_history();
        $history[] = ['role' => 'user', 'content' => $message, 'timestamp' => time()];

        $model = get_option(self::OPTION_MODEL, '') ?: self::DEFAULT_MODEL;

        $api_messages = array_map(function ($entry) {
            return ['role' => $entry['role'], 'content' => $entry['content']];
        }, $history);

        $response = wp_remote_post(self::ANTHROPIC_API_URL, [
            'timeout' => 60,
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => $api_messages,
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            $error_message = (is_array($body) && isset($body['error']['message']))
                ? $body['error']['message']
                : "Erro da API da Anthropic (HTTP {$code}).";
            wp_send_json_error(['message' => $error_message]);
        }

        $reply_text = '';
        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (isset($block['type'], $block['text']) && 'text' === $block['type']) {
                    $reply_text .= $block['text'];
                }
            }
        }
        if ('' === $reply_text) {
            $reply_text = '(resposta vazia)';
        }

        $history[] = ['role' => 'assistant', 'content' => $reply_text, 'timestamp' => time()];
        $history = self::save_history($history);

        wp_send_json_success(['history' => $history]);
    }
}
```

- [ ] **Step 2: Add the settings section to the existing Connections page**

In `plugin/includes/class-ikoeh-admin.php`, add a new POST-handling block inside `render_page()`, right after the existing revoke-connection block (find the `if (isset($_POST['ikoeh_connect_revoke']) ...)` block and add this immediately after its closing `}`):

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
            $notice = ['type' => 'success', 'text' => 'Configurações do chat salvas.'];
        }
```

Then, near the end of the page's HTML (right before the closing `</div>` of `<div class="wrap">`, after the "Nova conexão" form and before the `<script>` block), add this new section:

```php
            <h2>Chat iKOEH</h2>
            <?php
            $chat_key = get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');
            $chat_key_status = '' !== $chat_key ? ('Chave configurada (termina em ...' . esc_html(substr($chat_key, -4)) . ')') : 'Nenhuma chave configurada';
            $chat_model = get_option(Ikoeh_Connect_Chat::OPTION_MODEL, '') ?: Ikoeh_Connect_Chat::DEFAULT_MODEL;
            ?>
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

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat.php';
```
Add near the other `add_action('init', ...)` calls:
```php
add_action('init', ['Ikoeh_Connect_Chat', 'register_ajax']);
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-chat.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Deploy and verify live**

Run the deploy script, then confirm the Connections page (`Ajustes > iKOEH Connect`) still renders and now shows the new "Chat iKOEH" settings section with "Nenhuma chave configurada" (since no key exists yet on production). Confirm the plugin is still active after deploy (same check used in every prior task in this repo).

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-chat.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add chat settings storage and Anthropic message-send handler"
```

---

### Task 2: Chat wp-admin page and JS

**Files:**
- Create: `plugin/assets/chat.js`
- Create: `plugin/includes/class-ikoeh-chat-admin.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + hook `admin_menu`)

**Interfaces:**
- Consumes: `Ikoeh_Connect_Chat::OPTION_API_KEY`, `Ikoeh_Connect_Chat::get_history()` from Task 1; AJAX action `ikoeh_chat_send` from Task 1.
- Produces: wp-admin page at `admin.php?page=ikoeh-connect-chat`.

- [ ] **Step 1: Write the admin page class**

```php
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
```

- [ ] **Step 2: Write the chat JS**

```js
(function () {
    "use strict";

    var messagesEl = document.getElementById("ikoeh-chat-messages");
    var inputEl = document.getElementById("ikoeh-chat-input");
    var sendBtn = document.getElementById("ikoeh-chat-send");
    var statusEl = document.getElementById("ikoeh-chat-status");

    function renderHistory(history) {
        messagesEl.innerHTML = "";
        history.forEach(function (entry) {
            var row = document.createElement("div");
            row.style.marginBottom = "10px";
            row.style.textAlign = entry.role === "user" ? "right" : "left";

            var bubble = document.createElement("span");
            bubble.style.display = "inline-block";
            bubble.style.padding = "8px 12px";
            bubble.style.borderRadius = "12px";
            bubble.style.background = entry.role === "user" ? "#2271b1" : "#f0f0f1";
            bubble.style.color = entry.role === "user" ? "#fff" : "#1d2327";
            bubble.textContent = entry.content;

            row.appendChild(bubble);
            messagesEl.appendChild(row);
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendMessage() {
        var message = inputEl.value.trim();
        if (!message) {
            return;
        }

        inputEl.value = "";
        sendBtn.disabled = true;
        statusEl.textContent = "Enviando...";

        var data = new URLSearchParams();
        data.append("action", "ikoeh_chat_send");
        data.append("nonce", window.ikoehChat.nonce);
        data.append("message", message);

        fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.success) {
                    renderHistory(json.data.history);
                    statusEl.textContent = "";
                } else {
                    statusEl.textContent = "Erro: " + (json.data && json.data.message ? json.data.message : "falha desconhecida");
                }
            })
            .catch(function () {
                statusEl.textContent = "Erro: falha ao conectar.";
            })
            .finally(function () {
                sendBtn.disabled = false;
            });
    }

    sendBtn.addEventListener("click", sendMessage);
    inputEl.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    renderHistory(window.ikoehChat.history || []);
})();
```

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-admin.php';
```
```php
add_action('admin_menu', ['Ikoeh_Connect_Chat_Admin', 'register_menu']);
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-chat-admin.php plugin/wp-ikoeh-connect.php` and `node --check plugin/assets/chat.js`
Expected: no errors from any of the three.

- [ ] **Step 5: Deploy and verify live**

Deploy, then confirm `Ajustes > iKOEH Chat` renders. Since no API key is configured on production yet, expect the "configure a key first" message, not the chat interface itself -- that's the correct behavior per Task 1's Step 5 state. If a real Anthropic API key is available to test with, configure one via the settings section and confirm a real message round-trips and the reply renders; otherwise this manual full round-trip is left as a note for whoever configures a production key later, per this plan's Global Constraints.

- [ ] **Step 6: Commit**

```bash
git add plugin/assets/chat.js plugin/includes/class-ikoeh-chat-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add Chat iKOEH admin page and JS"
```
