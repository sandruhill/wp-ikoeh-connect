# WP iKOEH Connect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the WP iKOEH Connect plugin (WordPress) and its companion MCP server (Node.js), deploy the plugin to icp-la.com.br, and verify the full chain end-to-end so it can later be used to fix and publish the OAT plugin.

**Architecture:** A WordPress plugin exposes a token-authenticated REST API (`/wp-json/ikoeh-connect/v1/...`) covering plugin management, content editing, DB queries, logs and cache. A Node.js MCP server wraps that API as MCP tools, configured per-site via a local `.conf` file.

**Tech Stack:** PHP 7.4+ (WordPress plugin API, `$wpdb`, `WP_REST_Request`/`WP_REST_Response`, `WP_Filesystem`), Node.js 18+ (native `fetch`), `@modelcontextprotocol/sdk`, `zod`.

## Global Constraints

- No automated test suite for PHP or Node beyond CI lint/smoke checks and the isolated Docker integration job (Tasks 13 to 14). Verify PHP syntax locally with `php -l`, verify behavior against the real site with `curl`, per spec's "Testes" section. (spec §Testes)
- The plugin must work identically whether placed in `wp-content/plugins/` (normal install, activated via wp-admin) or `wp-content/mu-plugins/` (auto-loads, no activation step). No code may depend on `register_activation_hook`. (spec §Plugin WordPress)
- Token is never stored in plaintext inside WordPress, only its SHA-256 hash. Plaintext exists only in the one-time API response and in the MCP server's local config file. (spec §Autenticação)
- No endpoint may allow arbitrary PHP execution or shell exec. (spec §Fora de escopo)
- All authenticated endpoints require HTTPS. (spec §Outras proteções)
- The wp-admin settings screen uses only native WordPress admin markup/classes (`.wrap`, `.form-table`, `button-primary`). No custom CSS framework or generic-dashboard look. (spec §Tela de configurações)
- Licensing: `plugin/` is GPLv2 or later, `mcp-server/` is MIT. (spec §Licenciamento)
- Target site for validation: **icp-la.com.br**, accessed via FTP only (credentials at `~/.config/ftp-credentials/`, create this file during Task 8 if it doesn't already exist, same format as the other sites' `.conf` files in this environment).

---

## Task 1: Plugin bootstrap + scaffolding

**Files:**
- Create: `plugin/wp-ikoeh-connect.php`
- Create: `plugin/readme.txt`
- Create: `plugin/LICENSE` (GPLv2)

**Interfaces:**
- Produces: constants `IKOEH_CONNECT_VERSION`, `IKOEH_CONNECT_DIR`, `IKOEH_CONNECT_URL`, `IKOEH_CONNECT_REST_NAMESPACE` (string `'ikoeh-connect/v1'`), used by every later task.

- [ ] **Step 1: Write the plugin bootstrap**

```php
<?php
/**
 * Plugin Name: WP iKOEH Connect
 * Plugin URI: https://github.com/sandruhill/wp-ikoeh-connect
 * Description: Conecta o Claude a este site WordPress para desenvolvimento e otimização assistida por IA, via uma API própria autenticada por token.
 * Version: 0.1.0
 * Author: ikoeh
 * Author URI: https://ikoeh.com
 * License: GPL v2 or later
 * Text Domain: ikoeh-connect
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IKOEH_CONNECT_VERSION', '0.1.0');
define('IKOEH_CONNECT_DIR', plugin_dir_path(__FILE__));
define('IKOEH_CONNECT_URL', plugin_dir_url(__FILE__));
define('IKOEH_CONNECT_REST_NAMESPACE', 'ikoeh-connect/v1');
```

Save as `plugin/wp-ikoeh-connect.php`.

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected in plugin/wp-ikoeh-connect.php`

- [ ] **Step 3: Write a minimal readme.txt**

```
=== WP iKOEH Connect ===
Contributors: ikoeh
Tags: ai, claude, rest-api, automation
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta o Claude a este site WordPress para desenvolvimento e otimização assistida por IA, via uma API própria autenticada por token.

== Description ==

WP iKOEH Connect expõe uma REST API própria (`/wp-json/ikoeh-connect/v1/...`), autenticada por token, para gestão de plugins, edição de conteúdo, consultas ao banco e diagnóstico. Veja https://github.com/sandruhill/wp-ikoeh-connect para documentação completa.

== Installation ==

1. Envie a pasta do plugin para `wp-content/plugins/` e ative em Plugins, OU solte o arquivo principal em `wp-content/mu-plugins/` para carregamento automático sem ativação.
2. Acesse "iKOEH Connect" no menu do wp-admin para gerar o token de acesso (instalações com wp-admin), ou use o endpoint `/setup` (instalações mu-plugin sem wp-admin, veja o README do repositório).

== Changelog ==

= 0.1.0 =
* Primeira versão.
```

Save as `plugin/readme.txt`.

- [ ] **Step 4: Add the GPLv2 license file**

Run: `curl -s https://www.gnu.org/licenses/gpl-2.0.txt -o plugin/LICENSE`
Expected: file downloaded, non-empty (`wc -l plugin/LICENSE` > 300 lines)

- [ ] **Step 5: Commit**

```bash
git add plugin/wp-ikoeh-connect.php plugin/readme.txt plugin/LICENSE
git commit -m "Add plugin bootstrap and scaffolding"
```

---

## Task 2: Auth core

**Files:**
- Create: `plugin/includes/class-ikoeh-auth.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: nothing (first internal class).
- Produces: `Ikoeh_Connect_Auth::generate_token()`, `::hash_token($token)`, `::store_token($token)`, `::has_token()`, `::verify_request(WP_REST_Request $request)` (returns `true` or `WP_Error`), `::setup_rate_limit_ok()`. Used by every REST endpoint task (as `permission_callback`) and by Tasks 3–4.

- [ ] **Step 1: Write the auth class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Auth {

    const TOKEN_HASH_OPTION = 'ikoeh_connect_token_hash';

    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }

    public static function hash_token($token) {
        return hash('sha256', $token);
    }

    public static function store_token($token) {
        update_option(self::TOKEN_HASH_OPTION, self::hash_token($token), false);
    }

    public static function has_token() {
        return (bool) get_option(self::TOKEN_HASH_OPTION, false);
    }

    public static function verify_request(WP_REST_Request $request) {
        // IKOEH_CONNECT_SKIP_HTTPS_CHECK exists only for the isolated local/CI
        // Docker environment (docker-compose.yml), which deliberately runs
        // plain HTTP with no TLS termination. It is never defined on a real
        // deployment, so HTTPS stays mandatory everywhere else.
        $skip_https_check = defined('IKOEH_CONNECT_SKIP_HTTPS_CHECK') && IKOEH_CONNECT_SKIP_HTTPS_CHECK;

        if (!is_ssl() && !$skip_https_check) {
            return new WP_Error('ikoeh_connect_https_required', 'HTTPS required.', ['status' => 400]);
        }

        $header = $request->get_header('authorization');
        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            return new WP_Error('ikoeh_connect_unauthorized', 'Missing bearer token.', ['status' => 401]);
        }

        $provided = trim(substr($header, 7));
        $stored_hash = get_option(self::TOKEN_HASH_OPTION, '');

        if (empty($stored_hash) || !hash_equals($stored_hash, self::hash_token($provided))) {
            return new WP_Error('ikoeh_connect_unauthorized', 'Invalid token.', ['status' => 401]);
        }

        return true;
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

Save as `plugin/includes/class-ikoeh-auth.php`.

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-auth.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Require it from the bootstrap**

In `plugin/wp-ikoeh-connect.php`, after the `define()` block, add:

```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-auth.php';
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-auth.php plugin/wp-ikoeh-connect.php
git commit -m "Add auth core: token generation, hashing, verification"
```

---

## Task 3: One-time setup endpoint

**Files:**
- Create: `plugin/includes/class-ikoeh-setup.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::generate_token()`, `::store_token()`, `::setup_rate_limit_ok()` (Task 2).
- Produces: `Ikoeh_Connect_Setup::register_routes()`, constant `Ikoeh_Connect_Setup::CLAIMED_OPTION` (used by Task 4's admin screen). REST route `POST /wp-json/ikoeh-connect/v1/setup`.

- [ ] **Step 1: Write the setup class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Setup {

    const CLAIMED_OPTION = 'ikoeh_connect_setup_claimed';

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/setup', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_setup'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_setup(WP_REST_Request $request) {
        if (!defined('IKOEH_CONNECT_SETUP_KEY')) {
            return new WP_Error('ikoeh_connect_setup_disabled', 'Setup is not enabled on this install.', ['status' => 404]);
        }

        if (get_option(self::CLAIMED_OPTION, false)) {
            return new WP_Error('ikoeh_connect_setup_claimed', 'Setup key already claimed.', ['status' => 410]);
        }

        if (!Ikoeh_Connect_Auth::setup_rate_limit_ok()) {
            return new WP_Error('ikoeh_connect_rate_limited', 'Too many attempts. Try again later.', ['status' => 429]);
        }

        $provided_key = $request->get_header('x-setup-key');

        if (empty($provided_key) || !hash_equals(IKOEH_CONNECT_SETUP_KEY, $provided_key)) {
            return new WP_Error('ikoeh_connect_invalid_setup_key', 'Invalid setup key.', ['status' => 401]);
        }

        $token = Ikoeh_Connect_Auth::generate_token();
        Ikoeh_Connect_Auth::store_token($token);
        update_option(self::CLAIMED_OPTION, true, false);

        return new WP_REST_Response(['token' => $token], 200);
    }
}
```

Save as `plugin/includes/class-ikoeh-setup.php`. Note: `IKOEH_CONNECT_SETUP_KEY` is deliberately **not** defined by this plugin file. It's provided per-install (Task 8 defines it in a small separate file dropped alongside the plugin, so the plugin code distributed on GitHub stays identical for everyone).

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-setup.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Require and wire it into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add after the auth require:

```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-setup.php';

add_action('rest_api_init', function () {
    Ikoeh_Connect_Setup::register_routes();
});
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-setup.php plugin/wp-ikoeh-connect.php
git commit -m "Add one-time /setup endpoint for token bootstrap"
```

---

## Task 4: Admin settings screen

**Files:**
- Create: `plugin/includes/class-ikoeh-admin.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::generate_token()`, `::store_token()`, `::has_token()` (Task 2); `Ikoeh_Connect_Setup::CLAIMED_OPTION` (Task 3).
- Produces: `Ikoeh_Connect_Admin::register_menu()`. No REST surface, wp-admin only.

- [ ] **Step 1: Write the admin class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Admin {

    public static function register_menu() {
        add_menu_page(
            'WP iKOEH Connect',
            'iKOEH Connect',
            'manage_options',
            'ikoeh-connect',
            [__CLASS__, 'render_page'],
            'dashicons-admin-plugins',
            80
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
            </table>

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
```

Save as `plugin/includes/class-ikoeh-admin.php`.

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/class-ikoeh-admin.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Require and wire it into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add:

```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-admin.php';

add_action('admin_menu', ['Ikoeh_Connect_Admin', 'register_menu']);
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php
git commit -m "Add native wp-admin settings screen for token management"
```

---

## Task 5: Site-info and plugin-management REST endpoints

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-site-info.php`
- Create: `plugin/includes/rest/class-ikoeh-rest-plugins.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::verify_request` (Task 2, as `permission_callback`).
- Produces: REST routes `GET /site-info`, `GET /plugins`, `POST /plugins/install`, `POST /plugins/{slug}/activate`, `POST /plugins/{slug}/deactivate`, `DELETE /plugins/{slug}`.

- [ ] **Step 1: Write the site-info endpoint**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Site_Info {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/site-info', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    public static function handle(WP_REST_Request $request) {
        global $wp_version;

        return new WP_REST_Response([
            'wp_version'            => $wp_version,
            'php_version'           => phpversion(),
            'active_theme'          => get_stylesheet(),
            'active_plugins'        => get_option('active_plugins', []),
            'ikoeh_connect_version' => IKOEH_CONNECT_VERSION,
        ], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-site-info.php`.

- [ ] **Step 2: Write the plugin-management endpoints**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Plugins {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list_plugins'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/install', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'install_plugin'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/activate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'activate_plugin'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/deactivate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'deactivate_plugin'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'delete_plugin'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    private static function ensure_plugin_functions() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }
    }

    private static function find_plugin_file($slug) {
        self::ensure_plugin_functions();
        foreach (array_keys(get_plugins()) as $plugin_file) {
            if (strtok($plugin_file, '/') === $slug || $plugin_file === $slug) {
                return $plugin_file;
            }
        }
        return null;
    }

    public static function list_plugins() {
        self::ensure_plugin_functions();
        $active = get_option('active_plugins', []);
        $result = [];

        foreach (get_plugins() as $file => $data) {
            $result[] = [
                'slug'    => strtok($file, '/'),
                'file'    => $file,
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array($file, $active, true),
            ];
        }

        return new WP_REST_Response($result, 200);
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public static function install_plugin(WP_REST_Request $request) {
        self::ensure_plugin_functions();

        $body = $request->get_body();
        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'Request body must be the plugin zip bytes.', ['status' => 400]);
        }

        $is_mu = 'mu' === $request->get_param('target');
        $target = $is_mu ? WPMU_PLUGIN_DIR : WP_PLUGIN_DIR;

        if ($is_mu && !file_exists(WPMU_PLUGIN_DIR)) {
            wp_mkdir_p(WPMU_PLUGIN_DIR);
        }

        $tmp_zip = wp_tempnam('ikoeh-connect-plugin.zip');
        file_put_contents($tmp_zip, $body);

        $tmp_dir = trailingslashit(get_temp_dir()) . 'ikoeh-connect-' . wp_generate_password(8, false);
        wp_mkdir_p($tmp_dir);

        $unzip_result = unzip_file($tmp_zip, $tmp_dir);
        unlink($tmp_zip);

        if (is_wp_error($unzip_result)) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_unzip_failed', $unzip_result->get_error_message(), ['status' => 400]);
        }

        $entries = array_values(array_diff(scandir($tmp_dir), ['.', '..']));

        if (count($entries) !== 1) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_invalid_zip', 'Zip must contain exactly one top-level plugin folder or file.', ['status' => 400]);
        }

        $entry_name = sanitize_file_name($entries[0]);
        $source = trailingslashit($tmp_dir) . $entries[0];
        $destination = trailingslashit($target) . $entry_name;

        $real_source = realpath($source);
        $real_tmp = realpath($tmp_dir);

        if (false === $real_source || 0 !== strpos($real_source, $real_tmp)) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_invalid_zip', 'Zip contents failed validation.', ['status' => 400]);
        }

        if (is_dir($destination)) {
            self::rrmdir($destination);
        } elseif (file_exists($destination)) {
            unlink($destination);
        }

        rename($source, $destination);
        self::rrmdir($tmp_dir);

        return new WP_REST_Response(['installed' => $entry_name, 'target' => $is_mu ? 'mu-plugins' : 'plugins'], 200);
    }

    public static function activate_plugin(WP_REST_Request $request) {
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        $result = activate_plugin($plugin_file);
        if (is_wp_error($result)) {
            return new WP_Error('ikoeh_connect_activate_failed', $result->get_error_message(), ['status' => 400]);
        }
        return new WP_REST_Response(['activated' => $plugin_file], 200);
    }

    public static function deactivate_plugin(WP_REST_Request $request) {
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        deactivate_plugins($plugin_file);
        return new WP_REST_Response(['deactivated' => $plugin_file], 200);
    }

    public static function delete_plugin(WP_REST_Request $request) {
        self::ensure_plugin_functions();
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        $result = delete_plugins([$plugin_file]);
        if (is_wp_error($result)) {
            return new WP_Error('ikoeh_connect_delete_failed', $result->get_error_message(), ['status' => 400]);
        }
        return new WP_REST_Response(['deleted' => $plugin_file], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-plugins.php`.

- [ ] **Step 3: Verify PHP syntax of both files**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-site-info.php && php -l plugin/includes/rest/class-ikoeh-rest-plugins.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 4: Require and wire both into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add:

```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-site-info.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-plugins.php';
```

And inside the existing `add_action('rest_api_init', function () { ... });` block (from Task 3), add two lines so it reads:

```php
add_action('rest_api_init', function () {
    Ikoeh_Connect_Setup::register_routes();
    Ikoeh_Connect_Rest_Site_Info::register_routes();
    Ikoeh_Connect_Rest_Plugins::register_routes();
});
```

- [ ] **Step 5: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-site-info.php plugin/includes/rest/class-ikoeh-rest-plugins.php plugin/wp-ikoeh-connect.php
git commit -m "Add site-info and plugin-management REST endpoints"
```

---

## Task 6: Content REST endpoints

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-content.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::verify_request` (Task 2).
- Produces: REST routes `GET /content/{id}`, `PUT /content/{id}`.

- [ ] **Step 1: Write the content endpoints**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Content {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_content'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [__CLASS__, 'update_content'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    public static function get_content(WP_REST_Request $request) {
        $post = get_post((int) $request->get_param('id'));

        if (!$post) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        return new WP_REST_Response([
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'status'  => $post->post_status,
            'type'    => $post->post_type,
            'meta'    => get_post_meta($post->ID),
        ], 200);
    }

    public static function update_content(WP_REST_Request $request) {
        $id = (int) $request->get_param('id');

        if (!get_post($id)) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $params = $request->get_json_params();
        $update = ['ID' => $id];

        if (isset($params['title'])) {
            $update['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $update['post_content'] = wp_slash($params['content']);
        }

        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                return new WP_Error('ikoeh_connect_update_failed', $result->get_error_message(), ['status' => 400]);
            }
        }

        if (isset($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($id, sanitize_key($key), $value);
            }
        }

        return new WP_REST_Response(['updated' => $id], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-content.php`.

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-content.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Require and wire it into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add the require next to the other REST requires:

```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-content.php';
```

And add a line inside the `rest_api_init` callback:

```php
    Ikoeh_Connect_Rest_Content::register_routes();
```

- [ ] **Step 4: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-content.php plugin/wp-ikoeh-connect.php
git commit -m "Add content read/update REST endpoints"
```

---

## Task 7: DB query, logs and cache REST endpoints

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-db.php`
- Create: `plugin/includes/rest/class-ikoeh-rest-logs.php`
- Create: `plugin/includes/rest/class-ikoeh-rest-cache.php`
- Modify: `plugin/wp-ikoeh-connect.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::verify_request` (Task 2).
- Produces: REST routes `POST /db/query`, `GET /logs/debug`, `POST /cache/flush`.

- [ ] **Step 1: Write the DB query endpoint**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Db {

    private static $read_prefixes = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC ', 'EXPLAIN'];
    const AUTO_LIMIT = 1000;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/db/query', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    private static function is_read_query($sql) {
        $sql = ltrim($sql);
        foreach (self::$read_prefixes as $prefix) {
            if (stripos($sql, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function apply_auto_limit($sql) {
        if (preg_match('/\bLIMIT\s+\d/i', $sql)) {
            return $sql;
        }
        return rtrim(rtrim($sql), ';') . ' LIMIT ' . self::AUTO_LIMIT;
    }

    public static function handle(WP_REST_Request $request) {
        global $wpdb;

        $params = $request->get_json_params();
        $sql = isset($params['sql']) ? trim($params['sql']) : '';

        if (empty($sql)) {
            return new WP_Error('ikoeh_connect_empty_query', 'No SQL provided.', ['status' => 400]);
        }

        if (self::is_read_query($sql)) {
            // Memory/processing safety: an unbounded SELECT against a large table
            // can exhaust the PHP memory_limit on shared hosting. A missing LIMIT
            // gets one added automatically instead of running unbounded.
            $sql = self::apply_auto_limit($sql);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (null === $rows && $wpdb->last_error) {
                return new WP_Error('ikoeh_connect_query_failed', $wpdb->last_error, ['status' => 400]);
            }
            return new WP_REST_Response(['rows' => $rows, 'sql_executed' => $sql], 200);
        }

        if (empty($params['confirm_write'])) {
            return new WP_Error(
                'ikoeh_connect_write_not_confirmed',
                'This looks like a write query. Resend with "confirm_write": true to proceed.',
                ['status' => 400]
            );
        }

        $affected = $wpdb->query($sql);

        if (false === $affected) {
            return new WP_Error('ikoeh_connect_query_failed', $wpdb->last_error, ['status' => 400]);
        }

        return new WP_REST_Response(['affected_rows' => $affected], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-db.php`.

- [ ] **Step 2: Write the logs endpoint**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Logs {

    const READ_CHUNK_BYTES = 8192;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/logs/debug', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    /**
     * Memory/processing safety: debug.log on a neglected site can reach
     * hundreds of MB. Loading it whole with file() just to return the last
     * N lines risks hitting the PHP memory_limit on shared hosting. This
     * seeks backward from the end of the file in fixed-size chunks and
     * stops as soon as enough newlines have been found, so memory use stays
     * proportional to the requested tail size, not to the file size.
     */
    private static function tail_lines($path, $limit) {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $file_size = filesize($path);
        $buffer = '';
        $lines_found = 0;
        $position = $file_size;

        while ($position > 0 && $lines_found <= $limit) {
            $read_size = min(self::READ_CHUNK_BYTES, $position);
            $position -= $read_size;

            fseek($handle, $position);
            $buffer = fread($handle, $read_size) . $buffer;
            $lines_found = substr_count($buffer, "\n");
        }

        fclose($handle);

        $all_lines = explode("\n", rtrim($buffer, "\n"));
        return array_slice($all_lines, -$limit);
    }

    public static function handle(WP_REST_Request $request) {
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_file)) {
            return new WP_REST_Response(['lines' => []], 200);
        }

        $requested = (int) $request->get_param('lines');
        $limit = $requested > 0 ? min($requested, 1000) : 100;

        $tail = self::tail_lines($log_file, $limit);

        return new WP_REST_Response(['lines' => $tail], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-logs.php`.

- [ ] **Step 3: Write the cache endpoint**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Cache {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/cache/flush', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => ['Ikoeh_Connect_Auth', 'verify_request'],
        ]);
    }

    public static function handle() {
        $flushed = wp_cache_flush();
        return new WP_REST_Response(['flushed' => (bool) $flushed], 200);
    }
}
```

Save as `plugin/includes/rest/class-ikoeh-rest-cache.php`.

- [ ] **Step 4: Verify PHP syntax of all three files**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-db.php && php -l plugin/includes/rest/class-ikoeh-rest-logs.php && php -l plugin/includes/rest/class-ikoeh-rest-cache.php`
Expected: `No syntax errors detected` for all three

- [ ] **Step 5: Require and wire all three into the bootstrap**

In `plugin/wp-ikoeh-connect.php`, add:

```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-db.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-logs.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-cache.php';
```

And add inside the `rest_api_init` callback (it should now register all seven route groups):

```php
add_action('rest_api_init', function () {
    Ikoeh_Connect_Setup::register_routes();
    Ikoeh_Connect_Rest_Site_Info::register_routes();
    Ikoeh_Connect_Rest_Plugins::register_routes();
    Ikoeh_Connect_Rest_Content::register_routes();
    Ikoeh_Connect_Rest_Db::register_routes();
    Ikoeh_Connect_Rest_Logs::register_routes();
    Ikoeh_Connect_Rest_Cache::register_routes();
});
```

- [ ] **Step 6: Verify PHP syntax of the bootstrap**

Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-db.php plugin/includes/rest/class-ikoeh-rest-logs.php plugin/includes/rest/class-ikoeh-rest-cache.php plugin/wp-ikoeh-connect.php
git commit -m "Add db query, debug log and cache-flush REST endpoints"
```

---

## Task 8: Deploy to icp-la.com.br and verify live

**Files:**
- Create (locally, gitignored): `plugin/wp-ikoeh-connect-setup-key.php`
- Create: `.gitignore`
- Create (locally, not committed): `~/.config/wp-ikoeh-connect/icp-la.conf`

**Interfaces:**
- Consumes: every endpoint from Tasks 1–7, live over HTTPS.
- Produces: a working, reachable installation at `https://icp-la.com.br/wp-json/ikoeh-connect/v1/...`, plus a saved local config file consumed by Task 12.

- [ ] **Step 1: Add a .gitignore so the setup-key file is never committed**

```
wp-ikoeh-connect-setup-key.php
node_modules/
```

Save as `.gitignore` at the repo root.

- [ ] **Step 2: Generate a random setup key and write the site-specific loader file**

Run: `SETUP_KEY=$(openssl rand -hex 32) && echo "$SETUP_KEY"`

Save the printed value, then create `plugin/wp-ikoeh-connect-setup-key.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}
define('IKOEH_CONNECT_SETUP_KEY', 'PASTE_THE_GENERATED_VALUE_HERE');
```

Replace `PASTE_THE_GENERATED_VALUE_HERE` with the value printed by the `openssl` command. This file only defines a constant; it must be uploaded to `wp-content/mu-plugins/` alongside the main plugin file so mu-plugins autoloading picks up both.

- [ ] **Step 3: Verify PHP syntax**

Run: `php -l plugin/wp-ikoeh-connect-setup-key.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Confirm FTP access to icp-la.com.br**

If `~/.config/ftp-credentials/icp-la.conf` does not already exist, ask the user for the Hostinger FTP host/user/password for icp-la.com.br and create it in the same format as the other sites in this environment:

```
# Credenciais FTP - icp-la.com.br (Hostinger)
user = "USUARIO:SENHA"
```

Set permissions: `chmod 600 ~/.config/ftp-credentials/icp-la.conf`

- [ ] **Step 5: Upload the plugin as an mu-plugin**

```bash
CONF=~/.config/ftp-credentials/icp-la.conf
FTP_HOST="<host from the conf file's comment>"
BASE="ftp://$FTP_HOST/domains/icp-la.com.br/public_html/wp-content/mu-plugins"

curl -sS -K "$CONF" --ftp-create-dirs -T plugin/wp-ikoeh-connect.php "$BASE/wp-ikoeh-connect.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/wp-ikoeh-connect-setup-key.php "$BASE/wp-ikoeh-connect-setup-key.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/class-ikoeh-auth.php "$BASE/includes/class-ikoeh-auth.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/class-ikoeh-setup.php "$BASE/includes/class-ikoeh-setup.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/class-ikoeh-admin.php "$BASE/includes/class-ikoeh-admin.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-site-info.php "$BASE/includes/rest/class-ikoeh-rest-site-info.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-plugins.php "$BASE/includes/rest/class-ikoeh-rest-plugins.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-content.php "$BASE/includes/rest/class-ikoeh-rest-content.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-db.php "$BASE/includes/rest/class-ikoeh-rest-db.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-logs.php "$BASE/includes/rest/class-ikoeh-rest-logs.php"
curl -sS -K "$CONF" --ftp-create-dirs -T plugin/includes/rest/class-ikoeh-rest-cache.php "$BASE/includes/rest/class-ikoeh-rest-cache.php"
```

Expected: each `curl` call exits 0 with no error output.

- [ ] **Step 6: Verify site-info is unreachable without a token (sanity check auth is active)**

Run: `curl -sS -o /dev/null -w "%{http_code}\n" https://icp-la.com.br/wp-json/ikoeh-connect/v1/site-info`
Expected: `401`

- [ ] **Step 7: Claim the token via /setup**

```bash
SETUP_KEY="<the value generated in Step 2>"
curl -sS -X POST https://icp-la.com.br/wp-json/ikoeh-connect/v1/setup \
  -H "X-Setup-Key: $SETUP_KEY"
```

Expected: JSON body `{"token":"<64 hex chars>"}`. Copy the token.

- [ ] **Step 8: Save the token locally for the MCP server (Task 12)**

```bash
mkdir -p ~/.config/wp-ikoeh-connect
cat > ~/.config/wp-ikoeh-connect/icp-la.conf << 'EOF'
url = https://icp-la.com.br
token = PASTE_THE_TOKEN_HERE
EOF
chmod 600 ~/.config/wp-ikoeh-connect/icp-la.conf
```

Replace `PASTE_THE_TOKEN_HERE` with the token from Step 7.

- [ ] **Step 9: Verify /setup is now locked**

Run: `curl -sS -o /dev/null -w "%{http_code}\n" -X POST https://icp-la.com.br/wp-json/ikoeh-connect/v1/setup -H "X-Setup-Key: $SETUP_KEY"`
Expected: `410`

- [ ] **Step 10: Verify authenticated site-info works**

```bash
TOKEN="<the token from Step 7>"
curl -sS https://icp-la.com.br/wp-json/ikoeh-connect/v1/site-info \
  -H "Authorization: Bearer $TOKEN"
```

Expected: JSON with `wp_version`, `php_version`, `active_theme`, `active_plugins`.

- [ ] **Step 11: Verify plugin listing works**

Run: `curl -sS https://icp-la.com.br/wp-json/ikoeh-connect/v1/plugins -H "Authorization: Bearer $TOKEN"`
Expected: a JSON array (may be empty if no regular plugins are installed yet; mu-plugins don't appear here, which is correct WordPress behavior).

- [ ] **Step 12: Commit the gitignore (the setup-key file itself must NOT be committed)**

```bash
git add .gitignore
git status
```

Confirm `plugin/wp-ikoeh-connect-setup-key.php` does **not** appear under "Changes to be committed" (it must be ignored).

```bash
git commit -m "Add .gitignore to keep per-install setup keys out of the repo"
git push
```

---

## Task 9: MCP server scaffold, config loader and HTTP client

**Files:**
- Create: `mcp-server/package.json`
- Create: `mcp-server/LICENSE` (MIT)
- Create: `mcp-server/src/config.js`
- Create: `mcp-server/src/client.js`

**Interfaces:**
- Produces: `loadSiteConfig(siteName)` → `{ url, token }` (Task 12 relies on the file format documented here); `class IkoehClient { constructor({url, token}); async request(method, path, {params, json, rawBody}) }` (used by every tool task).

- [ ] **Step 1: Write package.json**

```json
{
  "name": "wp-ikoeh-connect-mcp",
  "version": "0.1.0",
  "description": "MCP server for WP iKOEH Connect",
  "type": "module",
  "main": "src/index.js",
  "bin": {
    "wp-ikoeh-connect-mcp": "src/index.js"
  },
  "scripts": {
    "start": "node src/index.js"
  },
  "license": "MIT",
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.0.0",
    "zod": "^3.23.8"
  }
}
```

Save as `mcp-server/package.json`.

- [ ] **Step 2: Install dependencies**

Run: `cd mcp-server && npm install`
Expected: exits 0, creates `mcp-server/node_modules` and `mcp-server/package-lock.json`

- [ ] **Step 3: Write the config loader**

```js
import { readFileSync, existsSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

export function loadSiteConfig(siteName) {
  const path = join(homedir(), ".config", "wp-ikoeh-connect", `${siteName}.conf`);

  if (!existsSync(path)) {
    throw new Error(`Config file not found: ${path}`);
  }

  const raw = readFileSync(path, "utf8");
  const config = {};

  for (const line of raw.split("\n")) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) {
      continue;
    }
    const [key, ...rest] = trimmed.split("=");
    config[key.trim()] = rest.join("=").trim();
  }

  if (!config.url || !config.token) {
    throw new Error(`Config file ${path} must define "url" and "token".`);
  }

  return { url: config.url.replace(/\/$/, ""), token: config.token };
}
```

Save as `mcp-server/src/config.js`.

- [ ] **Step 4: Write the HTTP client**

```js
export class IkoehClient {
  constructor({ url, token }) {
    this.baseUrl = `${url}/wp-json/ikoeh-connect/v1`;
    this.token = token;
  }

  async request(method, path, { params, json, rawBody } = {}) {
    let fullUrl = `${this.baseUrl}${path}`;

    if (params) {
      const query = new URLSearchParams(params).toString();
      if (query) {
        fullUrl += `?${query}`;
      }
    }

    const headers = { Authorization: `Bearer ${this.token}` };
    let body;

    if (json !== undefined) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(json);
    } else if (rawBody !== undefined) {
      headers["Content-Type"] = "application/zip";
      body = rawBody;
    }

    const response = await fetch(fullUrl, { method, headers, body });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const message = data && data.message ? data.message : response.statusText;
      throw new Error(`WP iKOEH Connect API error (${response.status}): ${message}`);
    }

    return data;
  }
}
```

Save as `mcp-server/src/client.js`.

- [ ] **Step 5: Verify the client against the live site**

```bash
node -e '
import("./mcp-server/src/config.js").then(async ({ loadSiteConfig }) => {
  const { IkoehClient } = await import("./mcp-server/src/client.js");
  const config = loadSiteConfig("icp-la");
  const client = new IkoehClient(config);
  const data = await client.request("GET", "/site-info");
  console.log(JSON.stringify(data, null, 2));
});
'
```

Expected: prints the same site-info JSON seen in Task 8 Step 10.

- [ ] **Step 6: Add the MIT license**

```bash
curl -s https://raw.githubusercontent.com/git/git-scm.com/main/LICENSE -o mcp-server/LICENSE
```

If that mirror is unavailable, write it manually:

```
MIT License

Copyright (c) 2026 ikoeh

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

Save as `mcp-server/LICENSE`.

- [ ] **Step 7: Commit**

```bash
cd /Users/macbook/wp-ikoeh-connect
git add mcp-server/package.json mcp-server/package-lock.json mcp-server/LICENSE mcp-server/src/config.js mcp-server/src/client.js
git commit -m "Add MCP server scaffold: config loader and HTTP client"
```

---

## Task 10: MCP tools: site-info and plugin management

**Files:**
- Create: `mcp-server/src/tools/site-info.js`
- Create: `mcp-server/src/tools/plugins.js`

**Interfaces:**
- Consumes: `IkoehClient` (Task 9).
- Produces: `registerSiteInfoTools(server, client)`, `registerPluginTools(server, client)`, both called from Task 12's `index.js`.

- [ ] **Step 1: Write the site-info tool**

```js
export function registerSiteInfoTools(server, client) {
  server.registerTool(
    "wp_site_info",
    {
      title: "WP Site Info",
      description: "Get WordPress/PHP version, active theme and active plugins for the connected site.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/site-info");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/site-info.js`.

- [ ] **Step 2: Write the plugin-management tools**

```js
import { z } from "zod";
import { readFileSync } from "node:fs";

export function registerPluginTools(server, client) {
  server.registerTool(
    "wp_list_plugins",
    {
      title: "List WordPress Plugins",
      description: "List all installed plugins on the connected site, with active status.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/plugins");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_deploy_plugin",
    {
      title: "Deploy WordPress Plugin",
      description: "Upload a local plugin zip file and install it on the connected site.",
      inputSchema: {
        zipPath: z.string().describe("Absolute local path to the plugin .zip file"),
        target: z.enum(["plugins", "mu"]).default("plugins").describe("Install into wp-content/plugins or wp-content/mu-plugins"),
      },
    },
    async ({ zipPath, target }) => {
      const bytes = readFileSync(zipPath);
      const data = await client.request("POST", "/plugins/install", {
        params: target === "mu" ? { target: "mu" } : undefined,
        rawBody: bytes,
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_plugin",
    {
      title: "Activate WordPress Plugin",
      description: "Activate an installed plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", `/plugins/${encodeURIComponent(slug)}/activate`);
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_deactivate_plugin",
    {
      title: "Deactivate WordPress Plugin",
      description: "Deactivate an active plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", `/plugins/${encodeURIComponent(slug)}/deactivate`);
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_plugin",
    {
      title: "Delete WordPress Plugin",
      description: "Permanently delete an installed plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("DELETE", `/plugins/${encodeURIComponent(slug)}`);
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/plugins.js`.

- [ ] **Step 3: Commit**

```bash
git add mcp-server/src/tools/site-info.js mcp-server/src/tools/plugins.js
git commit -m "Add MCP tools for site info and plugin management"
```

---

## Task 11: MCP tools: content, db, logs, cache

**Files:**
- Create: `mcp-server/src/tools/content.js`
- Create: `mcp-server/src/tools/db.js`
- Create: `mcp-server/src/tools/logs.js`
- Create: `mcp-server/src/tools/cache.js`

**Interfaces:**
- Consumes: `IkoehClient` (Task 9).
- Produces: `registerContentTools`, `registerDbTools`, `registerLogTools`, `registerCacheTools`, all called from Task 12's `index.js`.

- [ ] **Step 1: Write the content tools**

```js
import { z } from "zod";

export function registerContentTools(server, client) {
  server.registerTool(
    "wp_get_content",
    {
      title: "Get WordPress Content",
      description: "Get a post/page's title, content, status and meta (including Elementor data) by ID.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", `/content/${id}`);
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_update_content",
    {
      title: "Update WordPress Content",
      description: "Update a post/page's title, content and/or meta by ID.",
      inputSchema: {
        id: z.number().int().positive(),
        title: z.string().optional(),
        content: z.string().optional(),
        meta: z.record(z.any()).optional(),
      },
    },
    async ({ id, title, content, meta }) => {
      const data = await client.request("PUT", `/content/${id}`, {
        json: { title, content, meta },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/content.js`.

- [ ] **Step 2: Write the DB query tool**

```js
import { z } from "zod";

export function registerDbTools(server, client) {
  server.registerTool(
    "wp_db_query",
    {
      title: "Run WordPress DB Query",
      description:
        "Run a SQL query against the site's database. Read queries (SELECT/SHOW/DESCRIBE/EXPLAIN) run directly. " +
        "Write queries (INSERT/UPDATE/DELETE/etc) are rejected unless confirmWrite is true.",
      inputSchema: {
        sql: z.string(),
        confirmWrite: z.boolean().default(false),
      },
    },
    async ({ sql, confirmWrite }) => {
      const data = await client.request("POST", "/db/query", {
        json: { sql, confirm_write: confirmWrite },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/db.js`.

- [ ] **Step 3: Write the logs tool**

```js
import { z } from "zod";

export function registerLogTools(server, client) {
  server.registerTool(
    "wp_read_debug_log",
    {
      title: "Read WordPress Debug Log",
      description: "Tail the last N lines of wp-content/debug.log.",
      inputSchema: { lines: z.number().int().positive().max(1000).default(100) },
    },
    async ({ lines }) => {
      const data = await client.request("GET", "/logs/debug", { params: { lines } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/logs.js`.

- [ ] **Step 4: Write the cache tool**

```js
export function registerCacheTools(server, client) {
  server.registerTool(
    "wp_flush_cache",
    {
      title: "Flush WordPress Cache",
      description: "Flush the WordPress object cache on the connected site.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("POST", "/cache/flush");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

Save as `mcp-server/src/tools/cache.js`.

- [ ] **Step 5: Commit**

```bash
git add mcp-server/src/tools/content.js mcp-server/src/tools/db.js mcp-server/src/tools/logs.js mcp-server/src/tools/cache.js
git commit -m "Add MCP tools for content, db query, logs and cache"
```

---

## Task 12: Wire the MCP server entrypoint and verify end-to-end

**Files:**
- Create: `mcp-server/src/index.js`
- Modify: Claude Code MCP server configuration (project or user scope, ask the user which they prefer if unclear)

**Interfaces:**
- Consumes: everything from Tasks 9–11.
- Produces: a runnable MCP server exposing all 10 tools over stdio.

- [ ] **Step 1: Write the entrypoint**

```js
#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";

import { loadSiteConfig } from "./config.js";
import { IkoehClient } from "./client.js";
import { registerSiteInfoTools } from "./tools/site-info.js";
import { registerPluginTools } from "./tools/plugins.js";
import { registerContentTools } from "./tools/content.js";
import { registerDbTools } from "./tools/db.js";
import { registerLogTools } from "./tools/logs.js";
import { registerCacheTools } from "./tools/cache.js";

const siteName = process.argv[2];

if (!siteName) {
  console.error("Usage: node src/index.js <site-name>");
  console.error("Expects a config file at ~/.config/wp-ikoeh-connect/<site-name>.conf");
  process.exit(1);
}

const config = loadSiteConfig(siteName);
const client = new IkoehClient(config);

const server = new McpServer({ name: `wp-ikoeh-connect-${siteName}`, version: "0.1.0" });

registerSiteInfoTools(server, client);
registerPluginTools(server, client);
registerContentTools(server, client);
registerDbTools(server, client);
registerLogTools(server, client);
registerCacheTools(server, client);

const transport = new StdioServerTransport();
await server.connect(transport);
```

Save as `mcp-server/src/index.js`.

- [ ] **Step 2: Smoke-test the server starts without crashing**

Run: `cd mcp-server && timeout 3 node src/index.js icp-la; echo "exit code: $?"`
Expected: no stack trace printed before timeout; exit code `124` (timeout killed it, meaning it was running and waiting on stdio, which is correct behavior for an MCP stdio server with no client attached). If instead you see an import error or thrown exception, fix it before continuing. This is exactly the kind of SDK API mismatch that Task 14's CI smoke test also catches on every push.

- [ ] **Step 3: Register the MCP server in Claude Code**

Ask the user whether this should be a user-level or project-level MCP server registration, then add an entry pointing at `node /Users/macbook/wp-ikoeh-connect/mcp-server/src/index.js icp-la` (adjust the config file/command per how this Claude Code installation registers MCP servers, check existing entries in `~/.claude/.mcp.json` for the expected format before adding).

- [ ] **Step 4: Restart/reload so the new MCP server is picked up, then verify end-to-end**

Call the `wp_list_plugins` tool (now available from this new MCP server) and confirm it returns real plugin data from icp-la.com.br, matching what Task 8 Step 11's `curl` call returned.

- [ ] **Step 5: Commit**

```bash
cd /Users/macbook/wp-ikoeh-connect
git add mcp-server/src/index.js
git commit -m "Add MCP server entrypoint wiring all tools together"
git push
```

---

## Task 13: Isolated local environment (Docker Compose)

**Files:**
- Create: `docker-compose.yml`

**Interfaces:**
- Consumes: `plugin/` (Tasks 1 to 7), mounted read-write into the container.
- Produces: a disposable WordPress + MySQL environment on `http://localhost:8080`, reused as-is by Task 14's CI integration job.

This environment lets the plugin be exercised end-to-end (install, REST calls) without touching icp-la.com.br or any other real site. It uses a fixed, non-secret setup key, since it is throwaway infrastructure, not a real deployment: nobody's actual token security depends on this value.

- [ ] **Step 1: Write the compose file**

```yaml
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    volumes:
      - db_data:/var/lib/mysql

  wordpress:
    image: wordpress:latest
    depends_on:
      - db
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: "1"
      WORDPRESS_CONFIG_EXTRA: "define('IKOEH_CONNECT_SETUP_KEY', 'local-dev-not-a-real-secret'); define('IKOEH_CONNECT_SKIP_HTTPS_CHECK', true);"
    volumes:
      - wp_data:/var/www/html
      - ./plugin:/var/www/html/wp-content/mu-plugins

  wp-cli:
    image: wordpress:cli
    depends_on:
      - wordpress
      - db
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wp_data:/var/www/html

volumes:
  db_data:
  wp_data:
```

Save as `docker-compose.yml` at the repository root. Mounting `./plugin` straight onto `wp-content/mu-plugins` works because `plugin/wp-ikoeh-connect.php` sits at the top level of that folder (where WordPress scans for mu-plugins), while `plugin/includes/` becomes `wp-content/mu-plugins/includes/`, a subdirectory WordPress ignores for autoloading but which `IKOEH_CONNECT_DIR` (computed from `plugin_dir_path(__FILE__)`) still resolves correctly for the `require_once` calls.

- [ ] **Step 2: Start the environment**

Run: `docker compose up -d`
Expected: both `db` and `wordpress` containers report `Started`

- [ ] **Step 3: Wait for WordPress to respond**

Run: `for i in $(seq 1 30); do code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-login.php); [ "$code" != "000" ] && echo "up: $code" && break; sleep 2; done`
Expected: prints `up: 200` (or a redirect code) within the 30 tries

- [ ] **Step 4: Wait for MySQL to accept connections**

Run: `for i in $(seq 1 30); do docker compose exec -T db mysqladmin ping -uwordpress -pwordpress --silent && break; sleep 2; done`
Expected: loop exits once MySQL responds to ping, well before the 30-try limit. The `wordpress` container responding to HTTP (Step 3) does not guarantee MySQL has finished initializing on first boot, this is a separate wait.

- [ ] **Step 5: Install WordPress core non-interactively**

```bash
docker compose run --rm wp-cli wp core install \
  --url=http://localhost:8080 \
  --title="iKOEH Connect Dev" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=dev@example.com \
  --path=/var/www/html
```

Expected: `Success: WordPress installed successfully.`

- [ ] **Step 6: Verify the plugin loaded and rejects unauthenticated requests**

Run: `curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/wp-json/ikoeh-connect/v1/site-info`
Expected: `401`

- [ ] **Step 7: Verify the full setup and authenticated call work locally**

```bash
TOKEN=$(curl -sS -X POST http://localhost:8080/wp-json/ikoeh-connect/v1/setup \
  -H "X-Setup-Key: local-dev-not-a-real-secret" | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")
curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/site-info -H "Authorization: Bearer $TOKEN"
```

Expected: JSON with `wp_version`, `php_version`, `active_theme`, `active_plugins`.

- [ ] **Step 8: Tear down**

Run: `docker compose down -v`
Expected: containers and volumes removed, exits 0

- [ ] **Step 9: Commit**

```bash
git add docker-compose.yml
git commit -m "Add isolated Docker Compose environment for local development"
```

---

## Task 14: CI/CD pipeline (GitHub Actions)

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `plugin/` (Tasks 1 to 7), `mcp-server/` (Tasks 9 to 12), `docker-compose.yml` (Task 13).
- Produces: automated checks on every push and pull request; no live-site credentials are used or required.

- [ ] **Step 1: Write the workflow**

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  php-lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Lint all plugin PHP files
        run: find plugin -name "*.php" -print -exec php -l {} \;

  mcp-server:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: "20"
      - name: Install dependencies
        working-directory: mcp-server
        run: npm ci
      - name: Syntax-check all source files
        working-directory: mcp-server
        run: find src -name "*.js" -print -exec node --check {} \;

  integration:
    runs-on: ubuntu-latest
    needs: [php-lint, mcp-server]
    steps:
      - uses: actions/checkout@v4
      - name: Start isolated WordPress environment
        run: docker compose up -d
      - name: Wait for WordPress to respond
        run: |
          for i in $(seq 1 30); do
            code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-login.php || true)
            if [ "$code" != "000" ]; then
              echo "WordPress is up (HTTP $code)"
              exit 0
            fi
            sleep 2
          done
          echo "WordPress did not come up in time"
          exit 1
      - name: Wait for MySQL to accept connections
        run: |
          for i in $(seq 1 30); do
            if docker compose exec -T db mysqladmin ping -uwordpress -pwordpress --silent; then
              echo "MySQL is ready"
              exit 0
            fi
            sleep 2
          done
          echo "MySQL did not become ready in time"
          exit 1
      - name: Install WordPress core
        run: |
          for i in $(seq 1 5); do
            docker compose run --rm wp-cli wp core install \
              --url=http://localhost:8080 \
              --title="iKOEH Connect CI" \
              --admin_user=admin \
              --admin_password=admin \
              --admin_email=ci@example.com \
              --path=/var/www/html && exit 0
            echo "Retrying wp core install..."
            sleep 3
          done
          exit 1
      - name: Verify REST API rejects unauthenticated requests
        run: |
          code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/site-info)
          test "$code" = "401"
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

Save as `.github/workflows/ci.yml`.

- [ ] **Step 2: Verify YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" 2>&1 || python3 -m json.tool < /dev/null`

If `pyyaml` is not installed locally, skip local validation and rely on Step 3 instead.

- [ ] **Step 3: Commit and push, then confirm the workflow runs**

```bash
git add .github/workflows/ci.yml
git commit -m "Add CI/CD pipeline: PHP lint, MCP syntax check, isolated integration test"
git push
```

Then run: `gh run watch --exit-status $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')`
Expected: all three jobs (`php-lint`, `mcp-server`, `integration`) complete with conclusion `success`. If `integration` fails, read the failing step's log; it is running the exact same checks as Task 13's manual steps, just against a fresh container each time.

---

## After this plan

With Task 12 verified, the channel is live: `wp_deploy_plugin` can install/update the OAT plugin on icp-la.com.br directly, `wp_read_debug_log` can help diagnose issues, and `wp_db_query` can inspect the `oat_*` tables, no more manual FTP for this site. Task 13 and 14 mean every future change to the plugin or MCP server gets checked automatically, in an isolated environment, before it ever touches a real site. Fixing and publishing OAT itself is a separate follow-up, not part of this plan.
