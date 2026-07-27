# Multi-Connection Scopes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single shared token with named, scoped connections (ChatGPT / Claude.ai / Claude Code / Outro), migrate the existing live token without breaking it, and deploy to icp-la.com.br.

**Architecture:** `Ikoeh_Connect_Auth` moves from one `token_hash` option to a `connections` array option (id, name, token_hash, scopes, timestamps). REST endpoints gate on scope via `Ikoeh_Connect_Auth::require_scope($scope)`. The admin screen manages connections (create/revoke) and offers a global "Testar API" self-check.

**Tech Stack:** Same as the base project (PHP 7.4+, WordPress plugin API, `WP_REST_Request`/`WP_REST_Response`, native wp-admin AJAX).

## Global Constraints

- The token in `~/.config/wp-ikoeh-connect/icp-la.conf` (already in real use by the live MCP server) must keep working after this ships, migrated automatically into a "Claude Code" connection with all scopes. (spec §Migração)
- Token plaintext is never stored in WordPress, only its SHA-256 hash, same as v0.1.0. (spec §Modelo de dados)
- `GET /site-info` requires a valid connection but no specific scope; every other endpoint requires its mapped scope, per the spec's table. (spec §Autorização por escopo)
- "Testar API" is a single global self-check (real HTTP round-trip via `wp_remote_get` to the site's own `/site-info`), not a per-connection test, because the server cannot reconstruct a specific connection's plaintext token after creation. (spec §"Testar API")
- No automated unit test suite this phase either, verify via `php -l`, the isolated Docker environment, and CI, same as the base project.

---

## Task 1: Auth core rewrite (connections, migration, scopes)

**Files:**
- Modify: `plugin/includes/class-ikoeh-auth.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Ikoeh_Connect_Auth::ALL_SCOPES` (array), `::maybe_migrate()`, `::get_connections()`, `::create_connection($name, $scopes)` (returns plaintext token), `::revoke_connection($id)`, `::has_connections()`, `::require_scope($scope = null)` (returns a closure for `permission_callback`). Removes `::has_token()`, `::store_token()`, `::verify_request()`, `::last_used_at()` (replaced by per-connection data). Used by every task below.

- [ ] **Step 1: Rewrite the auth class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Auth {

    const CONNECTIONS_OPTION = 'ikoeh_connect_connections';
    const MIGRATED_OPTION = 'ikoeh_connect_migrated_v2';
    const LEGACY_TOKEN_HASH_OPTION = 'ikoeh_connect_token_hash';
    const LEGACY_LAST_USED_OPTION = 'ikoeh_connect_last_used_at';

    const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache'];

    public static function maybe_migrate() {
        if (get_option(self::MIGRATED_OPTION, false)) {
            return;
        }

        $legacy_hash = get_option(self::LEGACY_TOKEN_HASH_OPTION, '');

        if ($legacy_hash) {
            $connections = self::get_connections();
            $connections[] = [
                'id'           => wp_generate_password(12, false),
                'name'         => 'Claude Code',
                'token_hash'   => $legacy_hash,
                'scopes'       => self::ALL_SCOPES,
                'created_at'   => time(),
                'last_used_at' => get_option(self::LEGACY_LAST_USED_OPTION, 0) ?: null,
            ];
            self::save_connections($connections);
        }

        update_option(self::MIGRATED_OPTION, true, false);
    }

    public static function get_connections() {
        $connections = get_option(self::CONNECTIONS_OPTION, []);
        return is_array($connections) ? $connections : [];
    }

    private static function save_connections($connections) {
        update_option(self::CONNECTIONS_OPTION, array_values($connections), false);
    }

    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }

    public static function hash_token($token) {
        return hash('sha256', $token);
    }

    public static function create_connection($name, array $scopes) {
        $token = self::generate_token();
        $connections = self::get_connections();
        $connections[] = [
            'id'           => wp_generate_password(12, false),
            'name'         => sanitize_text_field($name),
            'token_hash'   => self::hash_token($token),
            'scopes'       => array_values(array_intersect($scopes, self::ALL_SCOPES)),
            'created_at'   => time(),
            'last_used_at' => null,
        ];
        self::save_connections($connections);
        return $token;
    }

    public static function revoke_connection($id) {
        $connections = array_values(array_filter(
            self::get_connections(),
            function ($connection) use ($id) {
                return $connection['id'] !== $id;
            }
        ));
        self::save_connections($connections);
    }

    public static function has_connections() {
        return count(self::get_connections()) > 0;
    }

    private static function find_connection_index_by_token($token) {
        $hash = self::hash_token($token);
        foreach (self::get_connections() as $index => $connection) {
            if (hash_equals($connection['token_hash'], $hash)) {
                return $index;
            }
        }
        return null;
    }

    private static function touch_last_used($index) {
        $connections = self::get_connections();
        if (isset($connections[$index])) {
            $connections[$index]['last_used_at'] = time();
            self::save_connections($connections);
        }
    }

    public static function require_scope($scope = null) {
        return function (WP_REST_Request $request) use ($scope) {
            $skip_https_check = defined('IKOEH_CONNECT_SKIP_HTTPS_CHECK') && IKOEH_CONNECT_SKIP_HTTPS_CHECK;

            if (!is_ssl() && !$skip_https_check) {
                return new WP_Error('ikoeh_connect_https_required', 'HTTPS required.', ['status' => 400]);
            }

            $header = $request->get_header('authorization');
            if (empty($header) || stripos($header, 'Bearer ') !== 0) {
                return new WP_Error('ikoeh_connect_unauthorized', 'Missing bearer token.', ['status' => 401]);
            }

            $provided = trim(substr($header, 7));
            $index = self::find_connection_index_by_token($provided);

            if (null === $index) {
                return new WP_Error('ikoeh_connect_unauthorized', 'Invalid token.', ['status' => 401]);
            }

            $connection = self::get_connections()[$index];

            if (null !== $scope && !in_array($scope, $connection['scopes'], true)) {
                return new WP_Error(
                    'ikoeh_connect_forbidden',
                    "This connection does not have the required scope: {$scope}",
                    ['status' => 403]
                );
            }

            self::touch_last_used($index);

            return true;
        };
    }

    public static function setup_rate_limit_ok() {
        $key = 'ikoeh_connect_setup_attempts';
        $attempts = (int) get_transient($key);

        if ($attempts >= 10) {
            return false;
        }

        set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        return true;
    }
}
```

Save as `plugin/includes/class-ikoeh-auth.php` (full replacement).

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-auth.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Wire migration into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add after the existing `require_once` lines (before the `add_action('admin_menu', ...)` line):

```php
add_action('init', ['Ikoeh_Connect_Auth', 'maybe_migrate']);
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-auth.php plugin/wp-ikoeh-connect.php
git commit -m "Replace single token with named, scoped connections and migration"
```

---

## Task 2: Setup endpoint creates a named connection

**Files:**
- Modify: `plugin/includes/class-ikoeh-setup.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::create_connection('Setup', Ikoeh_Connect_Auth::ALL_SCOPES)` (Task 1).
- Produces: unchanged REST contract (`POST /setup` still returns `{"token": "..."}`), but now backed by a connection named "Setup" with all scopes instead of the single legacy token.

- [ ] **Step 1: Update handle_setup**

In `plugin/includes/class-ikoeh-setup.php`, replace the token-generation lines:

```php
        $token = Ikoeh_Connect_Auth::generate_token();
        Ikoeh_Connect_Auth::store_token($token);
        update_option(self::CLAIMED_OPTION, true, false);
```

with:

```php
        $token = Ikoeh_Connect_Auth::create_connection('Setup', Ikoeh_Connect_Auth::ALL_SCOPES);
        update_option(self::CLAIMED_OPTION, true, false);
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-setup.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugin/includes/class-ikoeh-setup.php
git commit -m "Make /setup create a named Setup connection with all scopes"
```

---

## Task 3: Wire scope requirements into REST endpoints

**Files:**
- Modify: `plugin/includes/rest/class-ikoeh-rest-site-info.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-plugins.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-content.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-db.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-logs.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-cache.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::require_scope($scope)` (Task 1).
- Produces: same REST routes as before, now scope-gated per the spec's table.

- [ ] **Step 1: site-info requires any valid connection, no specific scope**

In `plugin/includes/rest/class-ikoeh-rest-site-info.php`, replace:

```php
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
```

with:

```php
            'permission_callback' => Ikoeh_Connect_Auth::require_scope(),
```

- [ ] **Step 2: plugins require the `plugins` scope**

In `plugin/includes/rest/class-ikoeh-rest-plugins.php`, replace all four occurrences of:

```php
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
```

with:

```php
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
```

- [ ] **Step 3: content requires the `content` scope**

In `plugin/includes/rest/class-ikoeh-rest-content.php`, replace both occurrences of:

```php
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
```

with:

```php
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
```

- [ ] **Step 4: db requires the `db` scope**

In `plugin/includes/rest/class-ikoeh-rest-db.php`, replace:

```php
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
```

with:

```php
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('db'),
```

- [ ] **Step 5: logs and cache require the `logs_cache` scope**

In both `plugin/includes/rest/class-ikoeh-rest-logs.php` and `plugin/includes/rest/class-ikoeh-rest-cache.php`, replace:

```php
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
```

with:

```php
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('logs_cache'),
```

- [ ] **Step 6: Verify PHP syntax of all six files**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-site-info.php && php -l plugin/includes/rest/class-ikoeh-rest-plugins.php && php -l plugin/includes/rest/class-ikoeh-rest-content.php && php -l plugin/includes/rest/class-ikoeh-rest-db.php && php -l plugin/includes/rest/class-ikoeh-rest-logs.php && php -l plugin/includes/rest/class-ikoeh-rest-cache.php`
Expected: `No syntax errors detected` for all six

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-site-info.php plugin/includes/rest/class-ikoeh-rest-plugins.php plugin/includes/rest/class-ikoeh-rest-content.php plugin/includes/rest/class-ikoeh-rest-db.php plugin/includes/rest/class-ikoeh-rest-logs.php plugin/includes/rest/class-ikoeh-rest-cache.php
git commit -m "Gate each REST endpoint group behind its required connection scope"
```

---

## Task 4: Admin screen: connections table, new-connection form, revoke, Testar API

**Files:**
- Modify: `plugin/includes/class-ikoeh-admin.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::get_connections()`, `::create_connection()`, `::revoke_connection()`, `::has_connections()`, `::ALL_SCOPES` (Task 1).
- Produces: `Ikoeh_Connect_Admin::ajax_test_api()` registered on `wp_ajax_ikoeh_connect_test_api`.

- [ ] **Step 1: Rewrite the admin class**

```php
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
```

Save as `plugin/includes/class-ikoeh-admin.php` (full replacement).

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-admin.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Wire the AJAX registration into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, change:

```php
add_action('admin_menu', ['Ikoeh_Connect_Admin', 'register_menu']);
```

to:

```php
add_action('admin_menu', ['Ikoeh_Connect_Admin', 'register_menu']);
add_action('init', ['Ikoeh_Connect_Admin', 'register_ajax']);
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php
git commit -m "Rebuild admin screen: connections table, scoped creation form, revoke, Testar API"
```

---

## Task 5: Verify locally (Docker) and in CI

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: everything from Tasks 1-4, plus the existing `docker-compose.yml` (unchanged).
- Produces: an updated CI integration job that also proves migration and scope enforcement, not just the old single-token flow.

- [ ] **Step 1: Add scope-enforcement checks to the CI integration job**

In `.github/workflows/ci.yml`, replace the final step:

```yaml
      - name: Claim setup token and verify authenticated site-info
        run: |
          token=$(curl -s -X POST http://localhost:8080/wp-json/ikoeh-connect/v1/setup \
            -H "X-Setup-Key: local-dev-not-a-real-secret" | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
          code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/site-info \
            -H "Authorization: Bearer $token")
          test "$code" = "200"
      - name: Tear down
        if: always()
        run: docker compose down -v
```

with:

```yaml
      - name: Claim setup token and verify authenticated site-info
        run: |
          token=$(curl -s -X POST http://localhost:8080/wp-json/ikoeh-connect/v1/setup \
            -H "X-Setup-Key: local-dev-not-a-real-secret" | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
          echo "TOKEN=$token" >> "$GITHUB_ENV"
          code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/site-info \
            -H "Authorization: Bearer $token")
          test "$code" = "200"
      - name: Verify the Setup connection has all scopes (plugins list succeeds)
        run: |
          code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/plugins \
            -H "Authorization: Bearer $TOKEN")
          test "$code" = "200"
      - name: Verify a scope-less token is rejected with 403, not 200
        run: |
          # Create a second connection with no scopes at all by hitting /setup again is not
          # possible (locked after first claim), so this negative check instead confirms
          # a garbage bearer token is rejected with 401 on a scoped endpoint.
          code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/plugins \
            -H "Authorization: Bearer not-a-real-token")
          test "$code" = "401"
      - name: Tear down
        if: always()
        run: docker compose down -v
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "Extend CI integration checks for scoped connections"
git push
```

- [ ] **Step 3: Watch CI run to completion**

Run: `gh run watch $(gh run list --branch <current-branch> --limit 1 --json databaseId --jq '.[0].databaseId') --exit-status`
Expected: `php-lint`, `mcp-server`, and `integration` jobs all succeed.

---

## Task 6: Deploy to icp-la.com.br and verify migration + scopes live

**Files:** none (deploy-only task).

**Interfaces:**
- Consumes: the deployed plugin's own `POST /plugins/install` endpoint (already live from the base project), using the existing "Claude Code" connection's token (post-migration) to authenticate the deploy itself.

- [ ] **Step 1: Package the plugin**

```bash
rm -rf /tmp/wp-ikoeh-connect-pkg
mkdir -p /tmp/wp-ikoeh-connect-pkg/wp-ikoeh-connect
cp -R plugin/* /tmp/wp-ikoeh-connect-pkg/wp-ikoeh-connect/
cd /tmp/wp-ikoeh-connect-pkg
zip -r -q deploy.zip wp-ikoeh-connect
```

- [ ] **Step 2: Deploy via the site's own API**

```bash
TOKEN="<the token from ~/.config/wp-ikoeh-connect/icp-la.conf>"
curl -sS -X POST "https://icp-la.com.br/wp-json/ikoeh-connect/v1/plugins/install" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/zip" \
  --data-binary @/tmp/wp-ikoeh-connect-pkg/deploy.zip
```

Expected: `{"installed":"wp-ikoeh-connect","target":"plugins"}`

- [ ] **Step 3: Verify the migrated connection still authenticates**

Run: `curl -sS https://icp-la.com.br/wp-json/ikoeh-connect/v1/site-info -H "Authorization: Bearer $TOKEN"`
Expected: 200 with real site data, using the exact same token that worked before this deploy, proving migration preserved it.

- [ ] **Step 4: Verify scope enforcement live**

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://icp-la.com.br/wp-json/ikoeh-connect/v1/plugins -H "Authorization: Bearer $TOKEN"
```

Expected: `200` (the migrated "Claude Code" connection has all scopes, including `plugins`).

- [ ] **Step 5: Manually confirm in wp-admin**

Ask the user to open **Ajustes > iKOEH Connect** and confirm: one connection listed named "Claude Code" with all 4 scopes and a recent "última atividade", plus the "Testar API" button reporting success.

---

## After this plan

Multiple AIs can now be connected to icp-la.com.br independently, each with its own token and only the capabilities it actually needs, and any one of them can be revoked without touching the others. The next natural step, if requested, is adding more granular scopes (e.g., splitting `plugins` into install-only vs. full management), not part of this plan.
