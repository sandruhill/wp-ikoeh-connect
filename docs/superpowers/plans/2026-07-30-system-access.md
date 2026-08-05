# System Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add unrestricted PHP execution, WP-CLI execution, and filesystem read/write/delete/list/enable/disable to wp-ikoeh-connect, gated behind a new, deliberately-named `system` scope, with the one real safety net Novamira itself uses: all filesystem operations confined to the WordPress root, symlink-write rejection, and PHP-file writes restricted to a crash-detecting sandbox.

**Architecture:** A shared path-resolution helper (`Ikoeh_Connect_System_Path`) enforces the ABSPATH boundary and PHP-sandbox rule for every file-touching route. A tiny standalone file gets installed into `wp-content/mu-plugins/` (WordPress's own always-loaded-first mechanism) that includes any non-disabled `.php` file in the sandbox directory on every request, with basic crash detection via a shutdown function and a `.crashed` marker file for self-preserving safe mode. Two REST classes hold the routes: one for process execution (execute-php, run-wp-cli), one for filesystem operations.

**Tech Stack:** PHP (WordPress plugin, `eval()`, `proc_open()`, `realpath()`-based path safety -- no external dependencies), Node.js MCP server.

## Global Constraints

- REST routes are flat, maximum 3 URL segments (`ikoeh-connect/v1/<route>`) -- this host silently blocks any REST route with 4+ segments. Per-file operations use `?path=` query params (GET/DELETE) or JSON body fields (POST), never a path segment.
- New scope: `system`. Off by default like every other scope, and the single most sensitive one in this plugin -- grants effectively total control of the WordPress install. Added to `Ikoeh_Connect_Auth::ALL_SCOPES` (`plugin/includes/class-ikoeh-auth.php`) and `Ikoeh_Connect_Admin::SCOPE_LABELS` (`plugin/includes/class-ikoeh-admin.php`).
- Every file-touching route MUST go through `Ikoeh_Connect_System_Path::resolve()` first -- this is the boundary check (confines to ABSPATH via `realpath()`, not naive string-prefix matching) that makes this feature "full risk, not reckless." No route should call raw `fopen()`/`file_put_contents()`/`unlink()`/`scandir()` on a user-supplied path without resolving it through this helper first.
- Writing/editing a path ending in `.php` additionally requires `Ikoeh_Connect_System_Path::check_php_sandbox()` to pass -- confines PHP writes to `wp-content/ikoeh-sandbox/`. This does NOT apply to reading, deleting, or listing `.php` files outside the sandbox -- only writing new PHP content is restricted.
- `run-wp-cli` must check `function_exists('proc_open') && function_exists('exec')` before attempting anything, and return a clear `501` error (not attempt and silently fail) if either is disabled -- a real, expected condition on shared hosting including this project's own doctorbeats.com.br (Hostinger).
- No PHPUnit exists in this repo. All test coverage is curl+wp-cli integration steps appended to `.github/workflows/ci.yml`, plus live curl checks against `doctorbeats.com.br` using the deploy script at `/private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs` (Docker does not run on this development machine).
- PHP files must pass `php -l`. JS files must pass `node --check`.
- Never use the `['self', 'method']` deprecated callable string form (confirmed to emit deprecation notices in this exact codebase, PHP 8.2+) -- use `[self::class, 'method']` or `[__CLASS__, 'method']`.

---

### Task 1: Path-boundary helper and scope wiring

**Files:**
- Create: `plugin/includes/class-ikoeh-system-path.php`
- Modify: `plugin/includes/class-ikoeh-auth.php` (add `'system'` to `ALL_SCOPES`)
- Modify: `plugin/includes/class-ikoeh-admin.php` (add `'system' => 'Sistema (arquivos/PHP/WP-CLI)'` to `SCOPE_LABELS`)
- Modify: `plugin/wp-ikoeh-connect.php` (require the new file)

**Interfaces:**
- Produces (used by every later task in this plan):
  - `class Ikoeh_Connect_System_Path`
  - `const Ikoeh_Connect_System_Path::SANDBOX_DIR_NAME = 'ikoeh-sandbox'`
  - `Ikoeh_Connect_System_Path::sandbox_dir(): string` -- returns the absolute sandbox directory path (`WP_CONTENT_DIR . '/ikoeh-sandbox'`), regardless of whether it currently exists.
  - `Ikoeh_Connect_System_Path::resolve(string $path, bool $must_exist): string|WP_Error` -- resolves a relative-to-ABSPATH-or-absolute path to a real, boundary-checked absolute path. Relative paths (not starting with `/` or `\`) are resolved against `ABSPATH`. If `$must_exist` is true, uses `realpath()` on the full path and errors `ikoeh_connect_path_not_found` (404) if it doesn't exist. If false, resolves the parent directory via `realpath()` (erroring `ikoeh_connect_parent_not_found`, 404, if the parent doesn't exist) and appends the basename -- this supports writing a new file that doesn't exist yet. Either way, the final resolved path is checked against `realpath(ABSPATH)`; anything outside returns `ikoeh_connect_path_outside_root` (400).
  - `Ikoeh_Connect_System_Path::reject_symlink(string $resolved): true|WP_Error` -- returns `WP_Error` `ikoeh_connect_symlink_write_rejected` (400) if `$resolved` is itself a symlink, `true` otherwise.
  - `Ikoeh_Connect_System_Path::is_php_path(string $resolved): bool` -- true if the path's extension (case-insensitive) is `php`.
  - `Ikoeh_Connect_System_Path::check_php_sandbox(string $resolved): true|WP_Error` -- if `$resolved` is not a `.php` path, returns `true` immediately (no restriction on non-PHP files). If it is `.php`, checks it's within `sandbox_dir()`; if not, returns `WP_Error` `ikoeh_connect_php_write_outside_sandbox` (400) with a message naming the sandbox path.
- Consumes: nothing from other tasks (foundation for this plan).

- [ ] **Step 1: Write the path helper**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared path-safety boundary for every filesystem-touching route in the
 * "system" scope. This is the one real safety net that keeps unrestricted
 * PHP execution / WP-CLI / filesystem access from being simply reckless:
 * every path is confined to the WordPress root via realpath() (not naive
 * string-prefix matching, which has classic ../ bypass bugs), and writing
 * new PHP content is further confined to a sandbox directory.
 */
class Ikoeh_Connect_System_Path {

    const SANDBOX_DIR_NAME = 'ikoeh-sandbox';

    public static function sandbox_dir() {
        return WP_CONTENT_DIR . '/' . self::SANDBOX_DIR_NAME;
    }

    private static function normalize_boundary($path) {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function is_within($path, $directory) {
        $normalized_path = self::normalize_boundary($path);
        $normalized_directory = self::normalize_boundary($directory);
        if ($normalized_path === $normalized_directory) {
            return true;
        }
        return 0 === strpos($normalized_path, $normalized_directory . '/');
    }

    public static function resolve($path, $must_exist) {
        $path = (string) $path;
        if ('' === $path) {
            return new WP_Error('ikoeh_connect_invalid_path', 'path is required.', ['status' => 400]);
        }

        if (0 !== strpos($path, '/') && 0 !== strpos($path, '\\')) {
            $path = rtrim(ABSPATH, '/\\') . '/' . $path;
        }

        if ($must_exist) {
            $resolved = realpath($path);
            if (false === $resolved) {
                return new WP_Error('ikoeh_connect_path_not_found', "Path does not exist: {$path}", ['status' => 404]);
            }
        } else {
            $base = basename($path);
            if ('.' === $base || '..' === $base) {
                return new WP_Error('ikoeh_connect_invalid_path', 'Invalid file name.', ['status' => 400]);
            }
            $parent = realpath(dirname($path));
            if (false === $parent) {
                return new WP_Error('ikoeh_connect_parent_not_found', 'Parent directory does not exist: ' . dirname($path), ['status' => 404]);
            }
            $resolved = rtrim($parent, '/\\') . '/' . $base;
        }

        $real_root = realpath(ABSPATH);
        if (false === $real_root) {
            $real_root = rtrim(ABSPATH, '/\\');
        }

        if (!self::is_within($resolved, $real_root)) {
            return new WP_Error(
                'ikoeh_connect_path_outside_root',
                "Path \"{$resolved}\" is outside the allowed root \"{$real_root}\".",
                ['status' => 400]
            );
        }

        return $resolved;
    }

    public static function reject_symlink($resolved) {
        if (is_link($resolved)) {
            return new WP_Error('ikoeh_connect_symlink_write_rejected', "Refusing to write through symlink path: {$resolved}", ['status' => 400]);
        }
        return true;
    }

    public static function is_php_path($resolved) {
        return 'php' === strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    }

    public static function check_php_sandbox($resolved) {
        if (!self::is_php_path($resolved)) {
            return true;
        }

        $sandbox_real = realpath(self::sandbox_dir());
        if (false === $sandbox_real) {
            $sandbox_real = rtrim(self::sandbox_dir(), '/\\');
        }

        if (!self::is_within($resolved, $sandbox_real)) {
            return new WP_Error(
                'ikoeh_connect_php_write_outside_sandbox',
                'PHP files can only be written inside the sandbox directory: wp-content/' . self::SANDBOX_DIR_NAME . '/',
                ['status' => 400]
            );
        }

        return true;
    }
}
```

- [ ] **Step 2: Add the scope**

In `plugin/includes/class-ikoeh-auth.php`, add `'system'` to the end of `ALL_SCOPES`.

In `plugin/includes/class-ikoeh-admin.php`, add to `SCOPE_LABELS`:
```php
        'system' => 'Sistema (arquivos/PHP/WP-CLI)',
```

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-system-path.php';
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-system-path.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 5: Deploy and verify live**

Run the deploy script, then verify the plugin is still active (source `~/.config/wp-ikoeh-connect/doctorbeats-full.conf`, curl `site-info` with the Bearer token, confirm `wp-ikoeh-connect/wp-ikoeh-connect.php` is in `active_plugins`).

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-system-path.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add system-access path boundary helper and scope"
```

---

### Task 2: Sandbox mu-plugin loader and installer

**Files:**
- Create: `plugin/mu-plugin-sandbox-loader.php` (the source file that gets copied into `wp-content/mu-plugins/` -- NOT loaded directly by the main plugin; it's a standalone file WordPress core auto-includes on every request)
- Create: `plugin/includes/class-ikoeh-system-installer.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require the installer, hook it on `init`)

**Interfaces:**
- Consumes: `Ikoeh_Connect_System_Path::sandbox_dir()` from Task 1.
- Produces: `Ikoeh_Connect_System_Installer::ensure_sandbox(): void` -- creates the sandbox directory if missing and (re-)installs the mu-plugin loader file, called on every `init`.

- [ ] **Step 1: Write the sandbox loader source file**

This file is never `require`'d by the main plugin's PHP -- it gets copied byte-for-byte into `wp-content/mu-plugins/ikoeh-sandbox-loader.php`, where WordPress core includes it directly (mu-plugins run in global scope, standalone, before regular plugins load). Variable names are prefixed to avoid colliding with other mu-plugins' globals.

```php
<?php
/**
 * Auto-installed by wp-ikoeh-connect. Do not edit directly -- this file is
 * overwritten from the plugin's bundled copy on every WordPress "init".
 * See plugin/includes/class-ikoeh-system-installer.php in the plugin source.
 *
 * Includes any non-disabled .php file placed in wp-content/ikoeh-sandbox/
 * on every request. If a sandbox file causes a fatal error, this loader
 * detects it via a shutdown function and writes a .crashed marker so the
 * NEXT request skips loading any sandbox file entirely (safe mode) instead
 * of taking the whole site down repeatedly. The agent (or a human) can
 * read/fix/delete the offending file and remove the marker via the
 * system-file API to resume normal loading.
 */

if (!defined('ABSPATH')) {
    exit;
}

$ikoeh_sandbox_dir = WP_CONTENT_DIR . '/ikoeh-sandbox';
$ikoeh_sandbox_crashed_marker = $ikoeh_sandbox_dir . '/.crashed';

if (!is_dir($ikoeh_sandbox_dir) || file_exists($ikoeh_sandbox_crashed_marker)) {
    return;
}

$ikoeh_sandbox_files = glob($ikoeh_sandbox_dir . '/*.php');
if (empty($ikoeh_sandbox_files)) {
    return;
}

$ikoeh_sandbox_loading_file = null;

register_shutdown_function(function () use ($ikoeh_sandbox_crashed_marker, &$ikoeh_sandbox_loading_file) {
    $ikoeh_last_error = error_get_last();
    $ikoeh_fatal_types = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR];
    if (
        $ikoeh_last_error
        && in_array($ikoeh_last_error['type'], $ikoeh_fatal_types, true)
        && null !== $ikoeh_sandbox_loading_file
    ) {
        file_put_contents(
            $ikoeh_sandbox_crashed_marker,
            $ikoeh_sandbox_loading_file . "\n" . $ikoeh_last_error['message']
        );
    }
});

foreach ($ikoeh_sandbox_files as $ikoeh_sandbox_file) {
    $ikoeh_sandbox_loading_file = $ikoeh_sandbox_file;
    include $ikoeh_sandbox_file;
    $ikoeh_sandbox_loading_file = null;
}
```

- [ ] **Step 2: Write the installer**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures the sandbox directory and its always-on mu-plugin loader exist
 * and are up to date. Runs on every "init" -- cheap (a file_exists + one
 * string comparison in the common case) and self-heals if a WordPress
 * update or manual cleanup ever removes the mu-plugin file.
 */
class Ikoeh_Connect_System_Installer {

    public static function ensure_sandbox() {
        $sandbox_dir = Ikoeh_Connect_System_Path::sandbox_dir();
        if (!is_dir($sandbox_dir)) {
            wp_mkdir_p($sandbox_dir);
        }

        $mu_plugins_dir = WPMU_PLUGIN_DIR;
        if (!is_dir($mu_plugins_dir)) {
            wp_mkdir_p($mu_plugins_dir);
        }

        $source = IKOEH_CONNECT_DIR . 'mu-plugin-sandbox-loader.php';
        if (!file_exists($source)) {
            return;
        }

        $target = $mu_plugins_dir . '/ikoeh-sandbox-loader.php';
        $source_contents = file_get_contents($source);
        $target_contents = file_exists($target) ? file_get_contents($target) : null;

        if ($target_contents !== $source_contents) {
            file_put_contents($target, $source_contents);
        }
    }
}
```

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-system-installer.php';
```
Add near the other `add_action('init', ...)` calls:
```php
add_action('init', ['Ikoeh_Connect_System_Installer', 'ensure_sandbox']);
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/mu-plugin-sandbox-loader.php plugin/includes/class-ikoeh-system-installer.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Deploy and verify live**

Run the deploy script (this triggers `init` on the next request, which installs the sandbox). Then verify:
```bash
source ~/.config/wp-ikoeh-connect/doctorbeats-full.conf
curl -s "$url/wp-json/ikoeh-connect/v1/site-info?_cb=$(date +%s)" -H "Authorization: Bearer $token"
```
Confirm the plugin is still active (proves the mu-plugin didn't fatal the site -- if it did, `active_plugins` would show the main plugin auto-deactivated). There's no REST endpoint yet to directly confirm the mu-plugin file exists on disk (that requires either the system-file API from Task 4, not yet built, or direct server access) -- the plugin-stays-active check is the available verification for this task; a full round-trip confirmation happens naturally once Task 4's `GET /system-file` route can read `wp-content/mu-plugins/ikoeh-sandbox-loader.php` back.

- [ ] **Step 6: Commit**

```bash
git add plugin/mu-plugin-sandbox-loader.php plugin/includes/class-ikoeh-system-installer.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add sandbox mu-plugin loader with crash detection"
```

---

### Task 3: execute-php and run-wp-cli REST routes

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-system-exec.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register routes)
- Modify: `.github/workflows/ci.yml` (append integration steps)

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::require_scope('system')` from Task 1's scope addition.
- Produces: REST routes `POST /system-execute-php`, `POST /system-wp-cli`.

- [ ] **Step 1: Write the REST class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_System_Exec {

    const MAX_EXECUTION_TIME = 30;
    const WP_CLI_TIMEOUT_SECONDS = 60;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-execute-php', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'execute_php'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-wp-cli', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'run_wp_cli'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);
    }

    public static function execute_php(WP_REST_Request $request) {
        $code = (string) ($request->get_param('code') ?: '');
        if ('' === trim($code)) {
            return new WP_Error('ikoeh_connect_invalid_code', 'code is required.', ['status' => 400]);
        }

        $errors = [];
        $error_types = [
            E_WARNING => 'Warning',
            E_NOTICE => 'Notice',
            E_DEPRECATED => 'Deprecated',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        $original_time_limit = (int) ini_get('max_execution_time');
        set_time_limit(self::MAX_EXECUTION_TIME);

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$errors, $error_types) {
            $errors[] = [
                'type' => $error_types[$errno] ?? ('Unknown (' . (int) $errno . ')'),
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline,
            ];
            return true;
        });

        ob_start();
        $start = microtime(true);
        $return_value = null;
        $success = true;
        $error_message = null;
        $error_class = null;

        try {
            $return_value = eval($code);
        } catch (\Throwable $e) {
            $success = false;
            $error_message = $e->getMessage();
            $error_class = get_class($e);
        }

        $execution_time_ms = round((microtime(true) - $start) * 1000, 2);
        $output = ob_get_clean();

        restore_error_handler();
        set_time_limit($original_time_limit);

        if (null !== $return_value && false === wp_json_encode($return_value)) {
            $return_value = print_r($return_value, true);
        }

        $result = [
            'success' => $success,
            'return_value' => $return_value,
            'output' => $output,
            'errors' => $errors,
            'execution_time_ms' => $execution_time_ms,
        ];
        if (null !== $error_message) {
            $result['error_message'] = $error_message;
            $result['error_class'] = $error_class;
        }

        return new WP_REST_Response($result, 200);
    }

    public static function run_wp_cli(WP_REST_Request $request) {
        if (!function_exists('proc_open') || !function_exists('exec')) {
            return new WP_Error(
                'ikoeh_connect_proc_disabled',
                'Process execution (proc_open/exec) is disabled in this PHP configuration. WP-CLI commands cannot run on this host.',
                ['status' => 501]
            );
        }

        $command = (string) ($request->get_param('command') ?: '');
        if ('' === trim($command)) {
            return new WP_Error('ikoeh_connect_invalid_command', 'command is required.', ['status' => 400]);
        }

        $wp_cli_binary = self::find_wp_cli_binary();
        if (null === $wp_cli_binary) {
            return new WP_Error('ikoeh_connect_wp_cli_not_found', 'Could not find the wp-cli binary on this host.', ['status' => 501]);
        }

        $full_command = escapeshellcmd($wp_cli_binary) . ' ' . $command
            . ' --path=' . escapeshellarg(rtrim(ABSPATH, '/\\'))
            . ' 2>&1';

        $descriptor_spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($full_command, $descriptor_spec, $pipes, ABSPATH);
        if (!is_resource($process)) {
            return new WP_Error('ikoeh_connect_wp_cli_exec_failed', 'Could not start the wp-cli process.', ['status' => 500]);
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], self::WP_CLI_TIMEOUT_SECONDS);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);

        return new WP_REST_Response([
            'command' => $command,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exit_code,
        ], 200);
    }

    private static function find_wp_cli_binary() {
        $vendor_bin = rtrim(ABSPATH, '/\\') . '/vendor/bin/wp';
        if (file_exists($vendor_bin) && is_executable($vendor_bin)) {
            return $vendor_bin;
        }

        $which = @exec('which wp 2>/dev/null');
        if (is_string($which) && '' !== trim($which)) {
            return trim($which);
        }

        return null;
    }
}
```

- [ ] **Step 2: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other REST requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-system-exec.php';
```
Inside the `rest_api_init` closure, add:
```php
    Ikoeh_Connect_Rest_System_Exec::register_routes();
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-system-exec.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Append CI integration steps**

Add to `.github/workflows/ci.yml`, after the last existing `integration` job step (before "Tear down"):

```yaml
      - name: Verify system-execute-php runs and returns a value
        run: |
          result=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-execute-php" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"code":"return 2 + 2;"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['return_value'])")
          test "$result" = "4"
      - name: Verify system-file write outside the sandbox rejects .php, but allows non-.php
        run: |
          php_status=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-file" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"path":"wp-content/ci-test-outside-sandbox.php","content":"<?php // test"}')
          test "$php_status" = "400"
          txt_status=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-file" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"path":"wp-content/ci-test.txt","content":"hello"}')
          test "$txt_status" = "200"
      - name: Verify system-file write rejects paths outside the WordPress root
        run: |
          status=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-file" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"path":"../../../../etc/ci-test-escape.txt","content":"escaped"}')
          test "$status" = "400"
      - name: Verify system-file write inside the sandbox succeeds for .php
        run: |
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-file" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"path":"wp-content/ikoeh-sandbox/ci-test.php","content":"<?php // sandboxed test file"}'
          content=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/system-file?path=wp-content/ikoeh-sandbox/ci-test.php" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['content'])")
          echo "$content" | grep -q "sandboxed test file"
      - name: Verify system unauthenticated request is rejected
        run: |
          code=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/system-execute-php" -d '{"code":"return 1;"}')
          test "$code" = "401"
```

(Note: the sandbox-write and outside-root CI steps reference `/system-file`, which Task 4 creates -- these steps are added now per this task's file so they land in the right place in the CI history, but they'll only pass once Task 4 is also merged. This is acceptable within this plan since both tasks land before the plan's own final review.)

- [ ] **Step 5: Deploy and verify live**

Run the deploy script, then:
```bash
source ~/.config/wp-ikoeh-connect/doctorbeats-full.conf
curl -s -X POST "$url/wp-json/ikoeh-connect/v1/system-execute-php" -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d '{"code":"return 2 + 2;"}'
```
Expected (once a connection with the `system` scope exists -- the existing `doctorbeats-full` connection predates this task and will 403; that's expected and sufficient proof the route is live and scope-gated, matching the pattern used for every prior feature in this plan set): `{"success":true,"return_value":4,...}` with a scoped connection, or a 403 naming "system" with the existing one.

Also test `run_wp_cli` if a scoped connection is available:
```bash
curl -s -X POST "$url/wp-json/ikoeh-connect/v1/system-wp-cli" -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d '{"command":"plugin list --format=count"}'
```
Document whether `proc_open`/`exec` are actually enabled on doctorbeats.com.br (Hostinger) -- this is a real environment fact worth recording, not assumed.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-system-exec.php plugin/wp-ikoeh-connect.php .github/workflows/ci.yml
git commit -m "feat: add system-execute-php and system-wp-cli REST routes"
```

---

### Task 4: Filesystem REST routes (read/write/delete/list/enable/disable)

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-system-files.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register routes)

**Interfaces:**
- Consumes: `Ikoeh_Connect_System_Path::resolve()`, `::reject_symlink()`, `::is_php_path()`, `::check_php_sandbox()` from Task 1.
- Produces: REST routes `GET/POST/DELETE /system-file`, `GET /system-directory`, `POST /system-enable-file`, `POST /system-disable-file`.

- [ ] **Step 1: Write the REST class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_System_Files {

    const DEFAULT_READ_LIMIT_BYTES = 1048576;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-file', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'read_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'write_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'delete_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-directory', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_directory'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-enable-file', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'enable_file'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-disable-file', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'disable_file'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);
    }

    public static function read_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_file($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_file', "Path is not a file: {$resolved}", ['status' => 400]);
        }
        if (!is_readable($resolved)) {
            return new WP_Error('ikoeh_connect_not_readable', "File is not readable: {$resolved}", ['status' => 403]);
        }

        $size = (int) filesize($resolved);
        $offset = (int) ($request->get_param('offset') ?: 0);
        $limit_param = $request->get_param('limit');
        $limit = null === $limit_param ? self::DEFAULT_READ_LIMIT_BYTES : (int) $limit_param;

        $handle = fopen($resolved, 'rb');
        if (false === $handle) {
            return new WP_Error('ikoeh_connect_read_failed', "Could not open file: {$resolved}", ['status' => 500]);
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $read_length = -1 === $limit ? max(1, $size - $offset) : max(1, $limit);
        $content = fread($handle, $read_length);
        fclose($handle);

        if (false === $content) {
            return new WP_Error('ikoeh_connect_read_failed', "Could not read file: {$resolved}", ['status' => 500]);
        }

        $bytes_read = strlen($content);
        $truncated = -1 !== $limit && ($offset + $bytes_read) < $size;
        $is_text = mb_check_encoding($content, 'UTF-8');
        $encoding = $is_text ? 'utf-8' : 'base64';
        if (!$is_text) {
            $content = base64_encode($content);
        }

        return new WP_REST_Response([
            'path' => $resolved,
            'content' => $content,
            'encoding' => $encoding,
            'size' => $size,
            'bytes_read' => $bytes_read,
            'truncated' => $truncated,
        ], 200);
    }

    public static function write_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $symlink_check = Ikoeh_Connect_System_Path::reject_symlink($resolved);
        if (is_wp_error($symlink_check)) {
            return $symlink_check;
        }

        $sandbox_check = Ikoeh_Connect_System_Path::check_php_sandbox($resolved);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }

        $content = (string) ($request->get_param('content') ?: '');
        $mode = (string) ($request->get_param('mode') ?: 'overwrite');

        $parent_dir = dirname($resolved);
        if (!is_dir($parent_dir)) {
            wp_mkdir_p($parent_dir);
        }

        $created = !file_exists($resolved);
        $flags = LOCK_EX;
        if ('append' === $mode) {
            $flags |= FILE_APPEND;
        }

        $bytes_written = file_put_contents($resolved, $content, $flags);
        if (false === $bytes_written) {
            return new WP_Error('ikoeh_connect_write_failed', "Failed to write file: {$resolved}", ['status' => 500]);
        }

        return new WP_REST_Response([
            'path' => $resolved,
            'bytes_written' => $bytes_written,
            'created' => $created,
            'size' => filesize($resolved),
        ], 200);
    }

    public static function delete_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_file($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_file', "Path is not a file: {$resolved}", ['status' => 400]);
        }
        if (!unlink($resolved)) {
            return new WP_Error('ikoeh_connect_delete_failed', "Failed to delete file: {$resolved}", ['status' => 500]);
        }
        return new WP_REST_Response(['deleted' => $resolved], 200);
    }

    public static function list_directory(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_dir($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_directory', "Path is not a directory: {$resolved}", ['status' => 400]);
        }

        $entries = [];
        foreach (scandir($resolved) as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $full = $resolved . '/' . $name;
            $entries[] = [
                'name' => $name,
                'is_directory' => is_dir($full),
                'size' => is_file($full) ? filesize($full) : null,
            ];
        }

        return new WP_REST_Response(['path' => $resolved, 'entries' => $entries], 200);
    }

    private static function require_sandboxed_php_path($path, $must_exist) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $path, $must_exist);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!Ikoeh_Connect_System_Path::is_php_path($resolved)) {
            return new WP_Error('ikoeh_connect_not_php', 'enable/disable only applies to .php files.', ['status' => 400]);
        }
        $sandbox_check = Ikoeh_Connect_System_Path::check_php_sandbox($resolved);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }
        return $resolved;
    }

    public static function disable_file(WP_REST_Request $request) {
        $resolved = self::require_sandboxed_php_path($request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $disabled_path = $resolved . '.disabled';
        if (file_exists($disabled_path)) {
            return new WP_Error('ikoeh_connect_disabled_exists', "A disabled version already exists: {$disabled_path}", ['status' => 409]);
        }
        if (!rename($resolved, $disabled_path)) {
            return new WP_Error('ikoeh_connect_disable_failed', "Failed to disable file: {$resolved}", ['status' => 500]);
        }
        return new WP_REST_Response(['original_path' => $resolved, 'disabled_path' => $disabled_path], 200);
    }

    public static function enable_file(WP_REST_Request $request) {
        $original = self::require_sandboxed_php_path($request->get_param('path'), false);
        if (is_wp_error($original)) {
            return $original;
        }

        $disabled_path = $original . '.disabled';
        if (!file_exists($disabled_path)) {
            return new WP_Error('ikoeh_connect_not_disabled', "No disabled version found: {$disabled_path}", ['status' => 404]);
        }
        if (file_exists($original)) {
            return new WP_Error('ikoeh_connect_original_exists', "An enabled version already exists: {$original}", ['status' => 409]);
        }
        if (!rename($disabled_path, $original)) {
            return new WP_Error('ikoeh_connect_enable_failed', "Failed to enable file: {$original}", ['status' => 500]);
        }
        return new WP_REST_Response(['original_path' => $original, 'disabled_path' => $disabled_path], 200);
    }
}
```

- [ ] **Step 2: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other REST requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-system-files.php';
```
Inside the `rest_api_init` closure, add:
```php
    Ikoeh_Connect_Rest_System_Files::register_routes();
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-system-files.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Deploy and verify live**

Run the deploy script, then re-run the CI-equivalent checks from Task 3's Step 4 directly against `doctorbeats.com.br` with a `system`-scoped connection (create one via wp-admin if needed): write a `.php` file outside the sandbox (expect 400), write a non-`.php` file anywhere (expect 200), write a path escaping the WordPress root via `../../../../etc/...` (expect 400), write a `.php` file inside the sandbox (expect 200) and read it back to confirm the content round-trips, then `GET /system-directory?path=wp-content/mu-plugins` and confirm `ikoeh-sandbox-loader.php` is listed (closing the loop on Task 2's installer, whose live verification was limited to "plugin stays active" until this route existed). Clean up any CI-named test files/directories created outside the sandbox afterward using `DELETE /system-file`.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-system-files.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add system filesystem REST routes (read/write/delete/list/enable/disable)"
```

---

### Task 5: MCP tools

**Files:**
- Create: `mcp-server/src/tools/system.js`
- Modify: `mcp-server/src/index.js` (import + registration call)

**Interfaces:**
- Consumes: REST routes from Task 3 (`/system-execute-php`, `/system-wp-cli`) and Task 4 (`/system-file` GET/POST/DELETE, `/system-directory`, `/system-enable-file`, `/system-disable-file`).
- Produces: `registerSystemTools(server, client)`, called from `index.js` alongside the other `register*Tools` calls.

- [ ] **Step 1: Write the MCP tool file**

```js
import { z } from "zod";

export function registerSystemTools(server, client) {
  server.registerTool(
    "wp_execute_php",
    {
      title: "Execute PHP",
      description:
        "Execute arbitrary PHP code on the WordPress server with the full WordPress environment loaded ($wpdb, all core functions, active plugins). Do NOT include <?php tags. Use 'return $value;' to get a value back. Never call exit()/die() -- that kills the whole PHP process. There is a 30-second execution time limit.",
      inputSchema: { code: z.string().min(1) },
    },
    async ({ code }) => {
      const data = await client.request("POST", "/system-execute-php", { json: { code } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_run_wp_cli",
    {
      title: "Run WP-CLI Command",
      description:
        "Run a WP-CLI command against this site (e.g. 'plugin list', 'theme activate twentytwentyfour'). Do not include the leading 'wp'. Returns stdout/stderr/exit_code. Fails clearly if proc_open/exec are disabled by the host (common on shared hosting).",
      inputSchema: { command: z.string().min(1) },
    },
    async ({ command }) => {
      const data = await client.request("POST", "/system-wp-cli", { json: { command } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_read_system_file",
    {
      title: "Read System File",
      description: "Read a file from the WordPress server filesystem (confined to the WordPress root). Binary/non-UTF-8 content is returned base64-encoded.",
      inputSchema: {
        path: z.string(),
        offset: z.number().int().min(0).optional(),
        limit: z.number().int().optional(),
      },
    },
    async ({ path, offset, limit }) => {
      const data = await client.request("GET", "/system-file", { params: { path, offset, limit } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_write_system_file",
    {
      title: "Write System File",
      description:
        "Write UTF-8 text content to a file on the WordPress server filesystem (confined to the WordPress root). Writing a .php file is only allowed inside the sandbox directory (wp-content/ikoeh-sandbox/), which is auto-loaded on every request with basic crash detection -- other files can go anywhere under the WordPress root.",
      inputSchema: {
        path: z.string(),
        content: z.string(),
        mode: z.enum(["overwrite", "append"]).optional(),
      },
    },
    async ({ path, content, mode }) => {
      const data = await client.request("POST", "/system-file", { json: { path, content, mode } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_system_file",
    {
      title: "Delete System File",
      description: "Delete a file from the WordPress server filesystem (confined to the WordPress root).",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("DELETE", "/system-file", { params: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_system_directory",
    {
      title: "List System Directory",
      description: "List the files and subdirectories of a directory on the WordPress server filesystem (confined to the WordPress root).",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("GET", "/system-directory", { params: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_disable_system_file",
    {
      title: "Disable Sandbox PHP File",
      description: "Rename a sandboxed PHP file (wp-content/ikoeh-sandbox/) to add a .disabled suffix, so it stops being loaded on each request without deleting it.",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("POST", "/system-disable-file", { json: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_enable_system_file",
    {
      title: "Enable Sandbox PHP File",
      description: "Reverse wp_disable_system_file: rename a previously-disabled sandboxed PHP file back to its original name so it resumes being loaded on each request.",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("POST", "/system-enable-file", { json: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 2: Register in index.js**

In `mcp-server/src/index.js`, add the import near the other tool imports:
```js
import { registerSystemTools } from "./tools/system.js";
```
and the registration call near the others:
```js
registerSystemTools(server, client);
```

- [ ] **Step 3: Syntax check**

Run: `node --check mcp-server/src/tools/system.js` and `node --check mcp-server/src/index.js`
Expected: no output (success) for both.

- [ ] **Step 4: Commit**

```bash
git add mcp-server/src/tools/system.js mcp-server/src/index.js
git commit -m "feat: add system-access MCP tools"
```
