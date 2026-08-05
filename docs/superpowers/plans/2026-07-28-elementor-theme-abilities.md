# Elementor + Theme Abilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Claude typed, safe REST endpoints (and matching MCP tools) to read/write Elementor page content and manage WordPress block themes, on top of the existing wp-ikoeh-connect plugin + MCP server.

**Architecture:** Two new PHP REST controller classes (`Ikoeh_Connect_Rest_Elementor`, `Ikoeh_Connect_Rest_Theme`) follow the exact pattern of the existing `class-ikoeh-rest-content.php`: static class, `register_routes()` called from `rest_api_init`, permission callbacks via `Ikoeh_Connect_Auth::require_scope()`. Two new scopes (`elementor`, `theme`) are added to `Ikoeh_Connect_Auth::ALL_SCOPES`. Two new MCP tool modules (`elementor.js`, `theme.js`) mirror `content.js`, calling the new REST routes through the existing `IkoehClient`.

**Tech Stack:** PHP 7.4+ (WordPress plugin), Node.js + `@modelcontextprotocol/sdk` + `zod` (MCP server), Docker Compose + GitHub Actions (integration tests via curl/wp-cli, no PHPUnit/Jest in this repo).

## Global Constraints

- REST routes stay flat at exactly 3 path segments (`/wp-json/ikoeh-connect/v1/<route>`), post ID passed as `?id=` query param, never a path segment — this host blocks 4+ segment REST paths (see `class-ikoeh-rest-content.php:8-13`).
- No PHP eval / shell-exec endpoint of any kind — every new ability is a typed, narrow operation.
- Every new scoped endpoint's `permission_callback` must be `Ikoeh_Connect_Auth::require_scope('<scope>')` — never `__return_true` except for `/setup` (already existing, out of scope here).
- Any endpoint that writes WordPress postmeta with a JSON-encoded string value must `wp_slash()` the encoded string before `update_post_meta()` (WordPress core silently strips real backslashes otherwise — this corrupted `_elementor_data` in production before, see project memory bug #7).
- MCP tool names use the existing `wp_<verb>_<noun>` convention (`wp_get_content`, `wp_update_content`, etc — see `mcp-server/src/tools/content.js`).
- This repo has no PHPUnit/Jest test suite — "tests" are curl/wp-cli integration steps run against a local Docker Compose WordPress instance and mirrored into `.github/workflows/ci.yml`. Follow that convention, don't introduce a new test framework.

---

### Task 1: Elementor scopes + read-only endpoints (widgets, templates)

**Files:**
- Modify: `plugin/includes/class-ikoeh-auth.php:13` (add `'elementor'`, `'theme'` to `ALL_SCOPES` — both scopes are added here since Task 3 needs `theme` too and this is the natural place to change the shared constant once)
- Modify: `plugin/includes/class-ikoeh-admin.php:8-13` (add labels for both new scopes to `SCOPE_LABELS`)
- Create: `plugin/includes/rest/class-ikoeh-rest-elementor.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require the new file, register its routes)
- Create: `mcp-server/src/tools/elementor.js`
- Modify: `mcp-server/src/index.js` (register the new tool module)
- Modify: `.github/workflows/ci.yml` (install + activate Elementor in the `integration` job, add curl checks for the two new endpoints)

**Interfaces:**
- Produces: PHP class `Ikoeh_Connect_Rest_Elementor` with public statics `register_routes()`, `get_widgets(WP_REST_Request $request)`, `get_templates(WP_REST_Request $request)`, and a private helper `require_elementor_active()` returning `WP_Error|null` (used by every method in this class and reused by Task 2).
- Produces: REST routes `GET /wp-json/ikoeh-connect/v1/elementor-widgets` (optional `?type=<widget_name>`), `GET /wp-json/ikoeh-connect/v1/elementor-templates`, both gated on scope `elementor`.
- Produces: MCP tools `wp_list_elementor_widgets({ type? })`, `wp_list_elementor_templates()`.
- Consumes: `Ikoeh_Connect_Auth::require_scope($scope)` (existing, `class-ikoeh-auth.php:102`), `IkoehClient.request(method, path, opts)` (existing, `mcp-server/src/client.js`).

- [ ] **Step 1: Add the two new scopes and their admin labels**

Edit `plugin/includes/class-ikoeh-auth.php` line 13:

```php
    const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache', 'elementor', 'theme'];
```

Edit `plugin/includes/class-ikoeh-admin.php` lines 8-13:

```php
    const SCOPE_LABELS = [
        'plugins'    => 'Gestão de plugins',
        'content'    => 'Conteúdo',
        'db'         => 'Banco de dados',
        'logs_cache' => 'Logs e cache',
        'elementor'  => 'Elementor',
        'theme'      => 'Temas',
    ];
```

- [ ] **Step 2: Create the Elementor REST controller with the two read endpoints**

Create `plugin/includes/rest/class-ikoeh-rest-elementor.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Elementor {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-widgets', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_widgets'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-templates', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_templates'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
        ]);

    }

    // Task 2 adds a third register_rest_route() call here for /elementor-content
    // (GET get_content, PUT update_content), plus the get_content/update_content/
    // normalize_elements methods below require_elementor_active().

    /**
     * Shared guard: every Elementor endpoint needs Elementor active. Task 2's
     * get_content()/update_content() call this too.
     */
    private static function require_elementor_active() {
        if (!class_exists('\Elementor\Plugin')) {
            return new WP_Error('ikoeh_connect_elementor_missing', 'Elementor is not active on this site.', ['status' => 400]);
        }
        return null;
    }

    public static function get_widgets(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $manager = \Elementor\Plugin::$instance->widgets_manager;
        $type = $request->get_param('type');

        if ($type) {
            $widget = $manager->get_widget_types($type);
            if (!$widget) {
                return new WP_Error('ikoeh_connect_not_found', "Widget type '{$type}' not found.", ['status' => 404]);
            }
            return new WP_REST_Response([
                'name'       => $widget->get_name(),
                'title'      => $widget->get_title(),
                'icon'       => $widget->get_icon(),
                'categories' => $widget->get_categories(),
                'controls'   => $widget->get_controls(),
            ], 200);
        }

        $list = [];
        foreach ($manager->get_widget_types() as $name => $widget) {
            $list[] = [
                'name'  => $name,
                'title' => $widget->get_title(),
                'icon'  => $widget->get_icon(),
            ];
        }
        return new WP_REST_Response($list, 200);
    }

    public static function get_templates(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $query = new WP_Query([
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        $templates = [];
        foreach ($query->posts as $post) {
            $templates[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'type'  => get_post_meta($post->ID, '_elementor_template_type', true),
            ];
        }
        return new WP_REST_Response($templates, 200);
    }
}
```

- [ ] **Step 3: Wire the new controller into the plugin bootstrap**

Edit `plugin/wp-ikoeh-connect.php`, add after line 30:

```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-elementor.php';
```

And in the `rest_api_init` callback (after line 44):

```php
    Ikoeh_Connect_Rest_Elementor::register_routes();
```

- [ ] **Step 4: Verify locally with Docker Compose**

From the repo root:

```bash
docker compose up -d
# wait ~20s for WordPress + MySQL, or poll like ci.yml does
docker compose run --rm wp-cli wp core install \
  --url=http://localhost:8080 --title="Local Dev" \
  --admin_user=admin --admin_password=admin --admin_email=dev@example.com \
  --path=/var/www/html
docker compose run --rm wp-cli wp plugin install elementor --activate --path=/var/www/html

TOKEN=$(curl -sS -X POST http://localhost:8080/wp-json/ikoeh-connect/v1/setup \
  -H "X-Setup-Key: local-dev-not-a-real-secret" | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")

curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-widgets \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | head -30

curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-widgets?type=heading" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-templates \
  -H "Authorization: Bearer $TOKEN"
```

Expected: first call returns a non-empty JSON array of widgets (e.g. includes `"name": "heading"`); second call returns a single object with a non-empty `controls` object; third call returns `[]` (no templates exist yet on a fresh install) with HTTP 200, not an error.

If `controls` comes back empty or the request 500s, read the actual installed Elementor version's `widgets-manager.php` and `widget-base.php` inside the container (`docker compose exec wordpress cat wp-content/plugins/elementor/includes/managers/widgets.php`) to confirm `get_widget_types()` and `get_controls()` signatures match what's assumed above, and adjust.

- [ ] **Step 5: Create the MCP tool module**

Create `mcp-server/src/tools/elementor.js`:

```js
import { z } from "zod";

export function registerElementorTools(server, client) {
  server.registerTool(
    "wp_list_elementor_widgets",
    {
      title: "List Elementor Widgets",
      description: "List all Elementor widget types on this site, or get the full control schema for one widget by passing its name.",
      inputSchema: { type: z.string().optional() },
    },
    async ({ type }) => {
      const data = await client.request("GET", "/elementor-widgets", { params: type ? { type } : undefined });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_elementor_templates",
    {
      title: "List Elementor Templates",
      description: "List saved Elementor Library templates (pages, sections, containers).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/elementor-templates");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 6: Register the tool module in the MCP server entrypoint**

Edit `mcp-server/src/index.js`, add import after line 9:

```js
import { registerElementorTools } from "./tools/elementor.js";
```

And after line 29 (`registerContentTools(server, client);`):

```js
registerElementorTools(server, client);
```

Run `node --check mcp-server/src/index.js` and `node --check mcp-server/src/tools/elementor.js` to confirm syntax is valid (mirrors the CI `mcp-server` job).

- [ ] **Step 7: Add Elementor install + curl checks to CI**

Edit `.github/workflows/ci.yml`, after the "Set pretty permalinks..." step (line 76) and before "Verify REST API rejects unauthenticated requests":

```yaml
      - name: Install and activate Elementor
        run: docker compose run --rm wp-cli wp plugin install elementor --activate --path=/var/www/html
```

After the existing "Claim setup token..." step (which already sets `$TOKEN` in `$GITHUB_ENV`), add:

```yaml
      - name: Verify Elementor widgets endpoint returns data
        run: |
          count=$(curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-widgets \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))")
          test "$count" -gt "0"
      - name: Verify Elementor templates endpoint responds 200 on a fresh install
        run: |
          code=$(curl -sS -o /dev/null -w "%{http_code}" http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-templates \
            -H "Authorization: Bearer $TOKEN")
          test "$code" = "200"
```

- [ ] **Step 8: Commit**

```bash
cd ~/wp-ikoeh-connect/.claude/worktrees/wp-ikoeh-connect-impl
git add plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php \
  plugin/includes/rest/class-ikoeh-rest-elementor.php plugin/wp-ikoeh-connect.php \
  mcp-server/src/tools/elementor.js mcp-server/src/index.js .github/workflows/ci.yml
git commit -m "feat: add Elementor widget/template read endpoints and MCP tools"
```

---

### Task 2: Elementor content read/write with normalization and cache invalidation

**Files:**
- Modify: `plugin/includes/rest/class-ikoeh-rest-elementor.php` (add `/elementor-content` to `register_routes()`, add `get_content()`, `update_content()`, `normalize_elements()`)
- Modify: `mcp-server/src/tools/elementor.js` (add two more tools)
- Modify: `.github/workflows/ci.yml` (add a write/read-back check)

**Interfaces:**
- Consumes: `require_elementor_active()` from Task 1 (same file).
- Produces: `GET /wp-json/ikoeh-connect/v1/elementor-content?id=<id>` → `{ id, elements: array, edit_mode: string }`. `PUT /wp-json/ikoeh-connect/v1/elementor-content?id=<id>` body `{ elements: array }` → `{ updated: id }`.
- Produces: MCP tools `wp_get_elementor_page({ id })`, `wp_write_elementor_page({ id, elements })`.

- [ ] **Step 1: Add the content routes to `register_routes()`**

In `plugin/includes/rest/class-ikoeh-rest-elementor.php`, inside `register_routes()`, after the `/elementor-templates` block, add:

```php
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-content', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_content'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [__CLASS__, 'update_content'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
            ],
        ]);
```

- [ ] **Step 2: Implement `get_content`, `update_content`, `normalize_elements`**

Add to the class (after `get_templates`):

```php
    public static function get_content(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $raw = get_post_meta($id, '_elementor_data', true);
        $elements = $raw ? json_decode($raw, true) : [];
        if (!is_array($elements)) {
            $elements = [];
        }

        return new WP_REST_Response([
            'id'        => $id,
            'elements'  => $elements,
            'edit_mode' => get_post_meta($id, '_elementor_edit_mode', true),
        ], 200);
    }

    public static function update_content(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $id = (int) $request->get_param('id');
        if (!get_post($id)) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $params = $request->get_json_params();
        if (!isset($params['elements']) || !is_array($params['elements'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Body must include an "elements" array.', ['status' => 400]);
        }

        $normalized = self::normalize_elements($params['elements']);
        $encoded = wp_json_encode($normalized);

        // wp_slash() before update_post_meta(): WordPress core runs
        // wp_unslash() internally and assumes slashed input, so real
        // backslashes in the encoded JSON (\n, \" inside string values) get
        // silently stripped without this, corrupting the stored data.
        update_post_meta($id, '_elementor_data', wp_slash($encoded));
        update_post_meta($id, '_elementor_edit_mode', 'builder');

        // Elementor caches rendered output in three places. Clearing only
        // some of them leaves the page serving stale content even though
        // _elementor_data is correct.
        delete_post_meta($id, '_elementor_css');
        delete_post_meta($id, '_elementor_page_assets');
        delete_post_meta($id, '_elementor_element_cache');

        return new WP_REST_Response(['updated' => $id], 200);
    }

    /**
     * Recursively fix the two schema issues that make Elementor 4.x silently
     * discard a manually-written element tree:
     * - every node needs an "elements" key, even if empty ([] not absent)
     * - top-level containers need settings.content_width = "full", or they
     *   render boxed at 1140px regardless of inner widget config
     */
    private static function normalize_elements(array $elements) {
        foreach ($elements as &$element) {
            if (!isset($element['elements']) || !is_array($element['elements'])) {
                $element['elements'] = [];
            }

            if (($element['elType'] ?? null) === 'container') {
                if (!isset($element['settings']) || !is_array($element['settings'])) {
                    $element['settings'] = [];
                }
                if (!isset($element['settings']['content_width'])) {
                    $element['settings']['content_width'] = 'full';
                }
            }

            if (!empty($element['elements'])) {
                $element['elements'] = self::normalize_elements($element['elements']);
            }
        }
        return $elements;
    }
```

- [ ] **Step 3: Verify locally with Docker Compose**

Continuing from Task 1's running stack (`docker compose up -d` still active, `$TOKEN` still valid):

```bash
POST_ID=$(docker compose run --rm wp-cli wp post create --post_type=page --post_title="Elementor Test" \
  --post_status=publish --porcelain --path=/var/www/html)

curl -sS -X PUT "http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-content?id=$POST_ID" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"elements":[{"id":"abc123","elType":"container","settings":[],"elements":[{"id":"def456","elType":"widget","widgetType":"heading","settings":{"title":"Hello"},"elements":[]}]}]}'

curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-content?id=$POST_ID" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

Expected: the read-back response shows `settings.content_width` set to `"full"` on the container even though the write sent `settings: []`, and every node (including the nested heading widget) has an `"elements"` key. Then confirm the cache keys are gone:

```bash
docker compose run --rm wp-cli wp post meta get $POST_ID _elementor_css --path=/var/www/html
```

Expected: empty output (meta key doesn't exist), confirming it was deleted by the write.

- [ ] **Step 4: Add the two MCP tools**

In `mcp-server/src/tools/elementor.js`, add inside `registerElementorTools`:

```js
  server.registerTool(
    "wp_get_elementor_page",
    {
      title: "Get Elementor Page",
      description: "Get a post/page's Elementor element tree, already decoded from JSON.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/elementor-content", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_write_elementor_page",
    {
      title: "Write Elementor Page",
      description: "Write a post/page's Elementor element tree (containers/widgets). Server-side normalization fills in required schema fields and clears Elementor's render caches automatically.",
      inputSchema: {
        id: z.number().int().positive(),
        elements: z.array(z.record(z.any())),
      },
    },
    async ({ id, elements }) => {
      const data = await client.request("PUT", "/elementor-content", { params: { id }, json: { elements } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
```

Run `node --check mcp-server/src/tools/elementor.js` to confirm syntax is valid.

- [ ] **Step 5: Add a write/read-back check to CI**

In `.github/workflows/ci.yml`, after the "Verify Elementor templates endpoint..." step added in Task 1, add:

```yaml
      - name: Verify Elementor content write normalizes and clears cache
        run: |
          POST_ID=$(docker compose run --rm wp-cli wp post create --post_type=page \
            --post_title="CI Elementor Test" --post_status=publish --porcelain --path=/var/www/html)
          curl -sS -X PUT "http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-content?id=$POST_ID" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"elements":[{"id":"abc123","elType":"container","settings":[],"elements":[]}]}'
          width=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/elementor-content?id=$POST_ID" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['elements'][0]['settings']['content_width'])")
          test "$width" = "full"
          css_meta=$(docker compose run --rm wp-cli wp post meta get "$POST_ID" _elementor_css --path=/var/www/html || true)
          test -z "$css_meta"
```

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-elementor.php mcp-server/src/tools/elementor.js .github/workflows/ci.yml
git commit -m "feat: add Elementor content read/write with schema normalization and cache invalidation"
```

---

### Task 3: Theme scope + endpoints (info, theme.json, activate)

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-theme.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register)
- Create: `mcp-server/src/tools/theme.js`
- Modify: `mcp-server/src/index.js` (register)
- Modify: `.github/workflows/ci.yml` (curl checks)

**Interfaces:**
- Consumes: `Ikoeh_Connect_Auth::require_scope('theme')` (scope already added in Task 1, Step 1).
- Produces: `GET /theme-info` → `{ name, stylesheet, is_block_theme, is_child_theme, parent }`. `GET /theme-json` → decoded `theme.json` contents. `PUT /theme-json` body is the full theme.json object → `{ updated: true }`. `POST /theme-activate` body `{ slug }` → `{ activated: slug }`.
- Produces: MCP tools `wp_get_theme_info()`, `wp_get_theme_json()`, `wp_update_theme_json({ theme_json })`, `wp_activate_theme({ slug })`.

- [ ] **Step 1: Create the Theme REST controller**

Create `plugin/includes/rest/class-ikoeh-rest-theme.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Theme {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/theme-info', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_info'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('theme'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/theme-json', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_theme_json'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('theme'),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [__CLASS__, 'update_theme_json'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('theme'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/theme-activate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'activate_theme'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('theme'),
        ]);
    }

    public static function get_info() {
        $theme = wp_get_theme();
        $is_child = is_child_theme();

        return new WP_REST_Response([
            'name'           => $theme->get('Name'),
            'stylesheet'     => $theme->get_stylesheet(),
            'is_block_theme' => wp_is_block_theme(),
            'is_child_theme' => $is_child,
            'parent'         => $is_child ? $theme->parent()->get_stylesheet() : null,
        ], 200);
    }

    public static function get_theme_json() {
        if (!wp_is_block_theme()) {
            return new WP_Error('ikoeh_connect_not_block_theme', 'Active theme is not a block theme.', ['status' => 400]);
        }

        $path = get_stylesheet_directory() . '/theme.json';
        if (!file_exists($path)) {
            return new WP_Error('ikoeh_connect_not_found', 'theme.json not found for the active theme.', ['status' => 404]);
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            return new WP_Error('ikoeh_connect_parse_failed', 'theme.json is not valid JSON.', ['status' => 500]);
        }

        return new WP_REST_Response($decoded, 200);
    }

    public static function update_theme_json(WP_REST_Request $request) {
        if (!wp_is_block_theme()) {
            return new WP_Error('ikoeh_connect_not_block_theme', 'Active theme is not a block theme.', ['status' => 400]);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Body must be a JSON object.', ['status' => 400]);
        }

        $path = get_stylesheet_directory() . '/theme.json';
        $encoded = wp_json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === file_put_contents($path, $encoded)) {
            return new WP_Error('ikoeh_connect_write_failed', 'Could not write theme.json.', ['status' => 500]);
        }

        if (function_exists('wp_clean_theme_json_cache')) {
            wp_clean_theme_json_cache();
        }

        return new WP_REST_Response(['updated' => true], 200);
    }

    public static function activate_theme(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $slug = isset($params['slug']) ? sanitize_key($params['slug']) : '';

        if (!$slug) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Missing "slug".', ['status' => 400]);
        }

        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_Error('ikoeh_connect_not_found', "Theme '{$slug}' is not installed.", ['status' => 404]);
        }

        switch_theme($slug);

        return new WP_REST_Response(['activated' => $slug], 200);
    }
}
```

- [ ] **Step 2: Wire it into the plugin bootstrap**

Edit `plugin/wp-ikoeh-connect.php`, add after the Elementor require line:

```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-theme.php';
```

And in `rest_api_init`, after `Ikoeh_Connect_Rest_Elementor::register_routes();`:

```php
    Ikoeh_Connect_Rest_Theme::register_routes();
```

- [ ] **Step 3: Verify locally with Docker Compose**

Continuing the running stack:

```bash
curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-info \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | head -20

# Change a value and write it back
curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json -H "Authorization: Bearer $TOKEN" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); d['version']=3; print(json.dumps(d))" > /tmp/theme.json
curl -sS -X PUT http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data @/tmp/theme.json

docker compose run --rm wp-cli wp theme list --path=/var/www/html
docker compose run --rm wp-cli wp theme activate twentytwentyfour --path=/var/www/html || \
  docker compose run --rm wp-cli wp theme install twentytwentyfour --activate --path=/var/www/html

curl -sS -X POST http://localhost:8080/wp-json/ikoeh-connect/v1/theme-activate \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"slug":"twentytwentyfour"}'
```

Expected: `theme-info` reports `is_block_theme: true` for the default theme; `theme-json` returns the real file contents; the PUT succeeds and a follow-up GET shows the edited value; `theme-activate` returns `{"activated":"twentytwentyfour"}` and `wp theme list` confirms it's active.

- [ ] **Step 4: Create the MCP tool module**

Create `mcp-server/src/tools/theme.js`:

```js
import { z } from "zod";

export function registerThemeTools(server, client) {
  server.registerTool(
    "wp_get_theme_info",
    {
      title: "Get Theme Info",
      description: "Get info about the active WordPress theme: name, whether it's a block theme, and child-theme parentage.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/theme-info");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_theme_json",
    {
      title: "Get theme.json",
      description: "Get the active block theme's theme.json (colors, typography, spacing, layout).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/theme-json");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_update_theme_json",
    {
      title: "Update theme.json",
      description: "Overwrite the active block theme's theme.json with a full replacement object.",
      inputSchema: { theme_json: z.record(z.any()) },
    },
    async ({ theme_json }) => {
      const data = await client.request("PUT", "/theme-json", { json: theme_json });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_theme",
    {
      title: "Activate Theme",
      description: "Activate an already-installed theme by its slug.",
      inputSchema: { slug: z.string().min(1) },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/theme-activate", { json: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 5: Register the tool module**

Edit `mcp-server/src/index.js`, add import after the Elementor import:

```js
import { registerThemeTools } from "./tools/theme.js";
```

And after `registerElementorTools(server, client);`:

```js
registerThemeTools(server, client);
```

Run `node --check mcp-server/src/index.js` and `node --check mcp-server/src/tools/theme.js`.

- [ ] **Step 6: Add CI checks**

In `.github/workflows/ci.yml`, after the Elementor content-write check added in Task 2, add:

```yaml
      - name: Verify theme-info reports a block theme
        run: |
          is_block=$(curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-info \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['is_block_theme'])")
          test "$is_block" = "True"
      - name: Verify theme-json round-trips a written value
        run: |
          curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json -H "Authorization: Bearer $TOKEN" \
            | python3 -c "import sys,json; d=json.load(sys.stdin); d['version']=3; print(json.dumps(d))" > /tmp/theme.json
          curl -sS -X PUT http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data @/tmp/theme.json
          version=$(curl -sS http://localhost:8080/wp-json/ikoeh-connect/v1/theme-json \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['version'])")
          test "$version" = "3"
```

- [ ] **Step 7: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-theme.php plugin/wp-ikoeh-connect.php \
  mcp-server/src/tools/theme.js mcp-server/src/index.js .github/workflows/ci.yml
git commit -m "feat: add theme-info, theme.json and theme-activate endpoints and MCP tools"
```

---

### Task 4: Full end-to-end verification and teardown

**Files:** none (verification only).

**Interfaces:** none new — this task confirms Tasks 1-3 work together in one clean run, matching how CI will actually execute them in sequence.

- [ ] **Step 1: Tear down and restart the stack clean**

```bash
docker compose down -v
docker compose up -d
```

- [ ] **Step 2: Run the full sequence from a clean install**

Repeat, in order: `wp core install`, `wp rewrite structure` + `wp rewrite flush`, `wp plugin install elementor --activate`, claim `/setup` token, then every curl check from Tasks 1-3 (widgets list, widget schema by type, templates list, content write + read-back + cache-key check, theme-info, theme-json round-trip, theme-activate). All must return the expected status codes/values with no manual intervention.

- [ ] **Step 3: Run the full CI workflow locally if `act` is available, otherwise push and watch Actions**

```bash
git push origin worktree-wp-ikoeh-connect-impl
gh run watch
```

Expected: `php-lint`, `mcp-server`, and `integration` jobs all pass.

- [ ] **Step 4: Tear down**

```bash
docker compose down -v
```

---

### Task 5: Media upload (raw bytes) and MCP tool

**Files:**
- Modify: `plugin/includes/class-ikoeh-auth.php` (add `'media'` to `ALL_SCOPES`)
- Modify: `plugin/includes/class-ikoeh-admin.php` (add label for `'media'` to `SCOPE_LABELS`)
- Create: `plugin/includes/rest/class-ikoeh-rest-media.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register)
- Create: `mcp-server/src/tools/media.js`
- Modify: `mcp-server/src/index.js` (register)

**Interfaces:**
- Produces: `POST /wp-json/ikoeh-connect/v1/media` — request body is the raw image bytes (any `Content-Type: image/*`), with the desired filename in an `X-Filename` header. Response `{ id, url }` on success (HTTP 201).
- Produces: MCP tool `wp_upload_media({ filename, content_base64 })` — the MCP layer accepts base64 (MCP tool args are JSON, can't carry raw binary), decodes it, and sends raw bytes to the REST endpoint.
- Consumes: `Ikoeh_Connect_Auth::require_scope('media')`.

- [ ] **Step 1: Add the scope**

`plugin/includes/class-ikoeh-auth.php`, `ALL_SCOPES` becomes:

```php
    const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache', 'elementor', 'theme', 'media'];
```

`plugin/includes/class-ikoeh-admin.php`, `SCOPE_LABELS` gets one more entry:

```php
        'media'      => 'Mídia',
```

- [ ] **Step 2: Create the Media REST controller**

Create `plugin/includes/rest/class-ikoeh-rest-media.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Media {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/media', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'upload'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('media'),
        ]);
    }

    public static function upload(WP_REST_Request $request) {
        $filename = $request->get_header('x-filename');
        if (empty($filename)) {
            return new WP_Error('ikoeh_connect_invalid_request', 'Missing X-Filename header.', ['status' => 400]);
        }

        $body = $request->get_body();
        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'No image bytes provided.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = wp_tempnam(sanitize_file_name($filename));
        file_put_contents($tmp, $body);

        $file_array = [
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        // media_handle_sideload() runs WordPress's own mime/extension
        // validation (wp_check_filetype_and_ext) before accepting the file,
        // so a renamed non-image can't sneak in as an "image" upload.
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            return new WP_Error('ikoeh_connect_upload_failed', $attachment_id->get_error_message(), ['status' => 400]);
        }

        return new WP_REST_Response([
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ], 201);
    }
}
```

- [ ] **Step 3: Wire it into the plugin bootstrap**

`plugin/wp-ikoeh-connect.php`: add `require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-media.php';` after the theme require, and `Ikoeh_Connect_Rest_Media::register_routes();` after `Ikoeh_Connect_Rest_Theme::register_routes();` in `rest_api_init`.

- [ ] **Step 4: Create the MCP tool**

Create `mcp-server/src/tools/media.js`:

```js
import { z } from "zod";

export function registerMediaTools(server, client) {
  server.registerTool(
    "wp_upload_media",
    {
      title: "Upload Media",
      description: "Upload an image to the WordPress media library. Provide the image as base64-encoded content; returns the new attachment's ID and public URL for use in Elementor widgets or post content.",
      inputSchema: {
        filename: z.string().min(1),
        content_base64: z.string().min(1),
      },
    },
    async ({ filename, content_base64 }) => {
      const buffer = Buffer.from(content_base64, "base64");
      const data = await client.requestRaw("POST", "/media", {
        body: buffer,
        headers: { "X-Filename": filename },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

This requires a small addition to `mcp-server/src/client.js`: the existing `request()` method only supports `json` and `rawBody` bodies with fixed content-types (`application/json`, `application/zip`) and no way to pass extra headers like `X-Filename`. Add a sibling method `requestRaw(method, path, { body, headers })` that sends `body` as-is with the caller's headers merged in on top of the `Authorization` header, content-type driven entirely by the caller (no default). Add it to `mcp-server/src/client.js` right after the existing `request()` method:

```js
  async requestRaw(method, path, { body, headers = {} } = {}) {
    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: { Authorization: `Bearer ${this.token}`, ...headers },
      body,
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const message = data && data.message ? data.message : response.statusText;
      throw new Error(`WP iKOEH Connect API error (${response.status}): ${message}`);
    }

    return data;
  }
```

- [ ] **Step 5: Register the tool module**

`mcp-server/src/index.js`: import `registerMediaTools` after the theme import, call `registerMediaTools(server, client);` after `registerThemeTools(server, client);`. Run `node --check` on both modified/created JS files.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php \
  plugin/includes/rest/class-ikoeh-rest-media.php plugin/wp-ikoeh-connect.php \
  mcp-server/src/tools/media.js mcp-server/src/client.js mcp-server/src/index.js
git commit -m "feat: add media upload endpoint and MCP tool"
```

Live verification (against a real site, not Docker — see Tasks 1-3's note on this repo's actual verification method): upload a small real image (base64-encode a tiny PNG), confirm the response has a valid `id` and `url`, fetch the `url` and confirm it 200s and is a real image.

---

### Task 6: Create and list posts/pages

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-posts.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register)
- Create: `mcp-server/src/tools/posts.js`
- Modify: `mcp-server/src/index.js` (register)

**Interfaces:**
- Produces: `GET /wp-json/ikoeh-connect/v1/posts` (optional `?type=page&status=publish`, defaults to `any` post type / `any` status) → array of `{ id, title, type, status }`.
- Produces: `POST /wp-json/ikoeh-connect/v1/posts` body `{ title, type, status?, content? }` (`status` defaults to `draft`) → `{ id }` (HTTP 201). This is a NEW file rather than adding to the existing `/content` endpoint (which only handles an already-known ID) — keeps this task's diff isolated from `class-ikoeh-rest-content.php`, which has unrelated uncommitted changes sitting in the working tree.
- Consumes: `Ikoeh_Connect_Auth::require_scope('content')` — reuses the existing `content` scope rather than adding a new one, since this is the same capability domain as `/content` and `/elementor-content`.
- Produces: MCP tools `wp_list_posts({ type?, status? })`, `wp_create_post({ title, type, status?, content? })`.

- [ ] **Step 1: Create the Posts REST controller**

Create `plugin/includes/rest/class-ikoeh-rest-posts.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Posts {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/posts', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_posts'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create_post'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
            ],
        ]);
    }

    public static function list_posts(WP_REST_Request $request) {
        $type = $request->get_param('type') ?: 'any';
        $status = $request->get_param('status') ?: 'any';

        $query = new WP_Query([
            'post_type'      => sanitize_key($type) === 'any' ? 'any' : sanitize_key($type),
            'post_status'    => $status === 'any' ? 'any' : sanitize_key($status),
            'posts_per_page' => 100,
        ]);

        $result = [];
        foreach ($query->posts as $post) {
            $result[] = [
                'id'     => $post->ID,
                'title'  => $post->post_title,
                'type'   => $post->post_type,
                'status' => $post->post_status,
            ];
        }
        return new WP_REST_Response($result, 200);
    }

    public static function create_post(WP_REST_Request $request) {
        $params = $request->get_json_params();

        if (empty($params['title'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Missing "title".', ['status' => 400]);
        }
        if (empty($params['type'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Missing "type" (e.g. "post" or "page").', ['status' => 400]);
        }

        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($params['title']),
            'post_type'    => sanitize_key($params['type']),
            'post_status'  => isset($params['status']) ? sanitize_key($params['status']) : 'draft',
            'post_content' => isset($params['content']) ? wp_slash($params['content']) : '',
        ], true);

        if (is_wp_error($post_id)) {
            return new WP_Error('ikoeh_connect_create_failed', $post_id->get_error_message(), ['status' => 400]);
        }

        return new WP_REST_Response(['id' => $post_id], 201);
    }
}
```

- [ ] **Step 2: Wire it into the plugin bootstrap**

`plugin/wp-ikoeh-connect.php`: add `require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-posts.php';` after the media require, and `Ikoeh_Connect_Rest_Posts::register_routes();` after `Ikoeh_Connect_Rest_Media::register_routes();` in `rest_api_init`.

- [ ] **Step 3: Create the MCP tool module**

Create `mcp-server/src/tools/posts.js`:

```js
import { z } from "zod";

export function registerPostsTools(server, client) {
  server.registerTool(
    "wp_list_posts",
    {
      title: "List Posts/Pages",
      description: "List existing posts/pages by type and status (defaults to any type, any status). Returns id, title, type, status for up to 100 results.",
      inputSchema: {
        type: z.string().optional(),
        status: z.string().optional(),
      },
    },
    async ({ type, status }) => {
      const data = await client.request("GET", "/posts", { params: { type, status } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_create_post",
    {
      title: "Create Post/Page",
      description: "Create a new post or page from scratch. status defaults to draft.",
      inputSchema: {
        title: z.string().min(1),
        type: z.string().min(1),
        status: z.string().optional(),
        content: z.string().optional(),
      },
    },
    async ({ title, type, status, content }) => {
      const data = await client.request("POST", "/posts", { json: { title, type, status, content } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 4: Register the tool module**

`mcp-server/src/index.js`: import `registerPostsTools` after the media import, call `registerPostsTools(server, client);` after `registerMediaTools(server, client);`. Run `node --check` on both files.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-posts.php plugin/wp-ikoeh-connect.php \
  mcp-server/src/tools/posts.js mcp-server/src/index.js
git commit -m "feat: add post/page list and create endpoints and MCP tools"
```

Live verification: create a draft page via `POST /posts`, confirm it appears in `GET /posts?type=page&status=draft`, clean up (delete) the test post afterward.

---

### Deferred to a future spec (not part of this plan)

Per user request during execution: navigation menus, SEO tooling, and broader security hardening are each substantial enough to warrant their own brainstorming/spec pass (same reasoning already applied to Metform in the original spec) rather than being appended here without scoping. Revisit as separate plans when prioritized.
