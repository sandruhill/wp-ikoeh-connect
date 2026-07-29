# Design System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Design System" feature to wp-ikoeh-connect: a single source of truth per site for brand design tokens (colors, typography, spacing, rounded corners, components, style dials), stored as a structured markdown document, with save/activate/list/delete/readiness-check REST routes and MCP tools.

**Architecture:** A new custom post type stores each design document (`post_content` = the full markdown, front-matter holds the structured tokens). A token-extraction parser reads the front-matter block into a structured array, falling back to simple prose heuristics (hex codes, font-name mentions) when front-matter is absent. A WordPress option tracks which design is currently "active." REST routes follow the exact conventions already established by `class-ikoeh-rest-skills.php` (this plugin's most recently-built simple CRUD resource).

**Tech Stack:** PHP (WordPress plugin, no external dependencies), Node.js MCP server.

## Global Constraints

- REST routes are flat, maximum 3 URL segments (`ikoeh-connect/v1/<route>`) -- this host silently blocks any REST route with 4+ segments. Per-design lookups use a `?slug=` query string, never a path segment.
- New scope: `design`. Off by default like every other scope. Added to `Ikoeh_Connect_Auth::ALL_SCOPES` (`plugin/includes/class-ikoeh-auth.php`) and `Ikoeh_Connect_Admin::SCOPE_LABELS` (`plugin/includes/class-ikoeh-admin.php`).
- Every string sourced from a REST request body and written via `update_post_meta()`/`wp_insert_post()`/`wp_update_post()`/`update_option()` must be wrapped in `wp_slash()` first for the two `wp_*_post()` functions specifically (they call `wp_unslash()` internally, silently stripping real backslashes otherwise; `update_option()` does NOT do this and needs no `wp_slash()`). This bug has been found and fixed multiple times already in this codebase.
- Never use the `['self', 'method']` callable string form (deprecated PHP 8.2+) -- use `[self::class, 'method']` or `[__CLASS__, 'method']`.
- No PHPUnit exists in this repo. All test coverage is curl+wp-cli integration steps appended to `.github/workflows/ci.yml`, plus live curl checks against `doctorbeats.com.br` using the deploy script at `/private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs` (Docker does not run on this development machine).
- PHP files must pass `php -l`. JS files must pass `node --check`.
- Out of scope, per the spec: the preflight anti-slop code scanner (depends on a generated-frontend-code pipeline this plugin doesn't have), `sync_ready` distinction, design revision history, and any wp-admin visual panel.

---

### Task 1: Design custom post type, token parser, and REST routes

**Files:**
- Create: `plugin/includes/class-ikoeh-design-tokens.php` (the parser -- separated from the REST class since it's a distinct, independently-testable piece of logic: markdown-in, structured-array-out)
- Create: `plugin/includes/rest/class-ikoeh-rest-design.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require both new files, register post type on `init`, register routes in `rest_api_init`)
- Modify: `plugin/includes/class-ikoeh-auth.php` (add `'design'` to `ALL_SCOPES`)
- Modify: `plugin/includes/class-ikoeh-admin.php` (add `'design' => 'Design System'` to `SCOPE_LABELS`)
- Modify: `.github/workflows/ci.yml` (append integration steps)

**Interfaces:**
- Produces:
  - `Ikoeh_Connect_Design_Tokens::extract(string $content): array` -- returns `['colors' => [...], 'typography' => [...], 'spacing' => [...], 'rounded' => [...], 'components' => [...], 'dials' => [...]]`, all associative arrays of string=>string except `typography` which is string=>array{fontFamily, fontWeight}.
  - `Ikoeh_Connect_Design_Tokens::parse_name_description(string $content): array` -- returns `['name' => string, 'description' => string]` read from the front-matter's `name:`/`description:` lines (both flat scalars at indent 0).
  - `Ikoeh_Connect_Design_Tokens::readiness(array $tokens): array` -- returns `['ready' => bool, 'errors' => list<string>, 'warnings' => list<string>]`. `ready` requires at least one color AND at least one typography entry; missing spacing/rounded/components/dials are warnings, not errors.
  - REST routes `POST /design`, `GET /design`, `DELETE /design`, `GET /design-library`, `POST /design-activate`, `GET /design-active`, `GET /design-check` under `IKOEH_CONNECT_REST_NAMESPACE`.
- Consumes: nothing from other tasks (foundation for this plan).

- [ ] **Step 1: Write the token parser**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts structured design tokens from a design document's markdown.
 * Targets the documented front-matter shape (single-level maps for
 * colors/spacing/rounded/components/dials, two-level for typography,
 * 2-space indentation per level) -- not a general YAML parser. Falls back
 * to simple prose heuristics (hex codes, a "fonte:"/"font:" mention) when
 * front-matter has no colors/typography, since real-world pasted brand
 * descriptions won't always follow the structured format.
 */
class Ikoeh_Connect_Design_Tokens {

    private static function front_matter_lines($raw) {
        if (0 !== strpos(ltrim($raw), '---')) {
            return [];
        }
        $trimmed = ltrim($raw);
        $end = strpos($trimmed, '---', 3);
        if (false === $end) {
            return [];
        }
        $block = substr($trimmed, 3, $end - 3);
        return explode("\n", $block);
    }

    private static function unquote($value) {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    public static function parse_name_description($content) {
        $name = '';
        $description = '';
        foreach (self::front_matter_lines($content) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || 0 === strpos($trimmed, '#')) {
                continue;
            }
            $colon = strpos($trimmed, ':');
            if (false === $colon) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            if (0 !== $indent) {
                continue;
            }
            $key = self::unquote(substr($trimmed, 0, $colon));
            $value = self::unquote(substr($trimmed, $colon + 1));
            if ('name' === $key) {
                $name = $value;
            }
            if ('description' === $key) {
                $description = $value;
            }
        }
        return ['name' => $name, 'description' => $description];
    }

    public static function extract($content) {
        $colors = [];
        $typography = [];
        $spacing = [];
        $rounded = [];
        $components = [];
        $dials = [];

        $section = null;
        $token = null;

        foreach (self::front_matter_lines($content) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || 0 === strpos($trimmed, '#')) {
                continue;
            }
            $colon = strpos($trimmed, ':');
            if (false === $colon) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            $key = self::unquote(substr($trimmed, 0, $colon));
            $value = self::unquote(substr($trimmed, $colon + 1));

            if (0 === $indent) {
                if ('' !== $value && 'rounded' === $key) {
                    $rounded['md'] = $value;
                    $section = null;
                    $token = null;
                    continue;
                }
                if ('' !== $value && 'spacing' === $key) {
                    $spacing['md'] = $value;
                    $section = null;
                    $token = null;
                    continue;
                }
                $section = ('' === $value && in_array($key, ['colors', 'typography', 'spacing', 'rounded', 'components', 'dials'], true)) ? $key : null;
                $token = null;
                continue;
            }

            if ('colors' === $section && '' !== $value) {
                $colors[$key] = $value;
                continue;
            }
            if ('spacing' === $section && '' !== $value) {
                $spacing[$key] = $value;
                continue;
            }
            if ('rounded' === $section && '' !== $value) {
                $rounded[$key] = $value;
                continue;
            }
            if ('components' === $section && '' !== $value) {
                $components[$key] = $value;
                continue;
            }
            if ('dials' === $section && '' !== $value) {
                $dials[$key] = $value;
                continue;
            }
            if ('typography' !== $section) {
                continue;
            }
            if ($indent <= 2 && '' === $value) {
                $token = $key;
                $typography[$key] = [];
                continue;
            }
            if ($indent <= 2) {
                $token = null;
                $matches = [];
                if (1 === preg_match('/^(.*\S)\s+([1-9]00)$/', $value, $matches)) {
                    $typography[$key] = ['fontFamily' => $matches[1], 'fontWeight' => $matches[2]];
                    continue;
                }
                $typography[$key] = ['fontFamily' => $value];
                continue;
            }
            if ($indent >= 4 && null !== $token && '' !== $value) {
                $typography[$token][$key] = $value;
            }
        }

        if (empty($colors)) {
            $colors = self::prose_colors($content);
        }
        if (empty($typography)) {
            $typography = self::prose_typography($content);
        }

        return [
            'colors' => $colors,
            'typography' => $typography,
            'spacing' => $spacing,
            'rounded' => $rounded,
            'components' => $components,
            'dials' => $dials,
        ];
    }

    private static function prose_colors($content) {
        $matches = [];
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $content, $matches);
        $colors = [];
        foreach (array_values(array_unique($matches[0])) as $index => $hex) {
            $colors['color' . ($index + 1)] = $hex;
        }
        return $colors;
    }

    private static function prose_typography($content) {
        $matches = [];
        if (1 === preg_match('/\b(?:font(?:e)?|tipografia)[:\s]+([A-Z][A-Za-z0-9 ]{2,30})/u', $content, $matches)) {
            return ['body' => ['fontFamily' => trim($matches[1])]];
        }
        return [];
    }

    public static function readiness(array $tokens) {
        $errors = [];
        $warnings = [];

        if (empty($tokens['colors'])) {
            $errors[] = 'No colors defined.';
        }
        if (empty($tokens['typography'])) {
            $errors[] = 'No typography defined.';
        }
        if (empty($tokens['spacing'])) {
            $warnings[] = 'No spacing tokens defined.';
        }
        if (empty($tokens['rounded'])) {
            $warnings[] = 'No rounded-corner tokens defined.';
        }
        if (empty($tokens['components'])) {
            $warnings[] = 'No component guidance defined.';
        }
        if (empty($tokens['dials'])) {
            $warnings[] = 'No style dials (variance/density/motion) defined.';
        }

        return ['ready' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }
}
```

- [ ] **Step 2: Write the REST class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Design {

    const POST_TYPE = 'ikoeh_design';
    const OPTION_ACTIVE = 'ikoeh_active_design';

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'iKOEH Designs',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'supports' => ['title', 'editor'],
        ]);
    }

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'save_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'delete_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-library', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_library'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-activate', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'activate_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-active', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_active_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-check', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'check_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);
    }

    private static function find_by_slug($slug) {
        if ('' === $slug) {
            return null;
        }
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'name' => $slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);
        return $posts[0] ?? null;
    }

    private static function shape_full($post) {
        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($post->post_content);
        return [
            'slug' => $post->post_name,
            'name' => '' !== $meta['name'] ? $meta['name'] : $post->post_title,
            'description' => $meta['description'],
            'content' => $post->post_content,
            'tokens' => Ikoeh_Connect_Design_Tokens::extract($post->post_content),
        ];
    }

    private static function shape_summary($post) {
        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($post->post_content);
        return [
            'slug' => $post->post_name,
            'name' => '' !== $meta['name'] ? $meta['name'] : $post->post_title,
            'description' => $meta['description'],
        ];
    }

    public static function save_design(WP_REST_Request $request) {
        $content = (string) ($request->get_param('content') ?: '');
        if ('' === trim($content)) {
            return new WP_Error('ikoeh_connect_invalid_content', 'content is required.', ['status' => 400]);
        }

        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($content);
        $requested_slug = (string) ($request->get_param('slug') ?: '');
        $slug = sanitize_title('' !== $requested_slug ? $requested_slug : $meta['name']);
        if ('' === $slug) {
            return new WP_Error('ikoeh_connect_invalid_slug', 'Provide a slug, or a name: field in the content front matter.', ['status' => 400]);
        }

        $existing = self::find_by_slug($slug);
        $postarr = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => wp_slash('' !== $meta['name'] ? $meta['name'] : $slug),
            'post_name' => $slug,
            'post_content' => wp_slash($content),
        ];

        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return new WP_REST_Response(['slug' => $slug, 'name' => $meta['name']], 200);
    }

    public static function get_design(WP_REST_Request $request) {
        $post = self::find_by_slug(sanitize_title((string) $request->get_param('slug')));
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        return new WP_REST_Response(self::shape_full($post), 200);
    }

    public static function delete_design(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }

        $was_active = get_option(self::OPTION_ACTIVE, '') === $slug;
        wp_delete_post($post->ID, true);
        if ($was_active) {
            update_option(self::OPTION_ACTIVE, '');
        }

        return new WP_REST_Response(['deleted' => $slug, 'was_active' => $was_active], 200);
    }

    public static function list_library(WP_REST_Request $request) {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        return new WP_REST_Response(array_map([self::class, 'shape_summary'], $posts), 200);
    }

    public static function activate_design(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        update_option(self::OPTION_ACTIVE, $slug);
        return new WP_REST_Response(['active' => $slug], 200);
    }

    public static function get_active_design(WP_REST_Request $request) {
        $slug = (string) get_option(self::OPTION_ACTIVE, '');
        if ('' === $slug) {
            return new WP_REST_Response(['active' => null], 200);
        }
        $post = self::find_by_slug($slug);
        if (!$post) {
            // Active slug points at a deleted post -- self-heal rather than error.
            update_option(self::OPTION_ACTIVE, '');
            return new WP_REST_Response(['active' => null], 200);
        }
        return new WP_REST_Response(self::shape_full($post), 200);
    }

    public static function check_design(WP_REST_Request $request) {
        $post = self::find_by_slug(sanitize_title((string) $request->get_param('slug')));
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        $tokens = Ikoeh_Connect_Design_Tokens::extract($post->post_content);
        return new WP_REST_Response(Ikoeh_Connect_Design_Tokens::readiness($tokens), 200);
    }
}
```

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-design-tokens.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-design.php';
```
Add a top-level `init` hook:
```php
add_action('init', ['Ikoeh_Connect_Rest_Design', 'register_post_type']);
```
Inside the existing `rest_api_init` closure, add:
```php
    Ikoeh_Connect_Rest_Design::register_routes();
```

- [ ] **Step 4: Add the scope**

In `plugin/includes/class-ikoeh-auth.php`, add `'design'` to the end of `ALL_SCOPES`.

In `plugin/includes/class-ikoeh-admin.php`, add to `SCOPE_LABELS`:
```php
        'design' => 'Design System',
```

- [ ] **Step 5: Lint**

Run: `php -l plugin/includes/class-ikoeh-design-tokens.php plugin/includes/rest/class-ikoeh-rest-design.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all five.

- [ ] **Step 6: Append CI integration steps**

Add to `.github/workflows/ci.yml`, after the last existing `integration` job step (before "Tear down"):

```yaml
      - name: Verify design save/check/activate/library/delete round-trips
        run: |
          DESIGN_MD=$'---\nname: CI Test Brand\ndescription: a test brand\ncolors:\n  primary: "#112233"\ntypography:\n  body:\n    fontFamily: Inter\n---\n\nSome guidance text.'
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/design" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            --data-raw "$(python3 -c "import json,sys; print(json.dumps({'content': sys.argv[1]}))" "$DESIGN_MD")"
          ready=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/design-check?slug=ci-test-brand" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['ready'])")
          test "$ready" = "True"
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/design-activate" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"slug":"ci-test-brand"}'
          active_slug=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/design-active" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['slug'])")
          test "$active_slug" = "ci-test-brand"
          count=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/design-library" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))")
          test "$count" -gt "0"
          curl -sS -X DELETE "http://localhost:8080/wp-json/ikoeh-connect/v1/design?slug=ci-test-brand" \
            -H "Authorization: Bearer $TOKEN"
          active_after=$(curl -sS "http://localhost:8080/wp-json/ikoeh-connect/v1/design-active" \
            -H "Authorization: Bearer $TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin)['active'])")
          test "$active_after" = "None"
      - name: Verify design unauthenticated request is rejected
        run: |
          code=$(curl -sS -o /dev/null -w "%{http_code}" "http://localhost:8080/wp-json/ikoeh-connect/v1/design-library")
          test "$code" = "401"
```

- [ ] **Step 7: Deploy and verify live**

Run the deploy script, then run the equivalent of the CI steps above directly against `doctorbeats.com.br` using a connection with the `design` scope (create a fresh connection with that scope checked via wp-admin if the existing one predates this task).
Expected: design saved, `design-check` reports `ready: true`, activation round-trips through `design-active`, library lists it, delete clears the active slug.

- [ ] **Step 8: Commit**

```bash
git add plugin/includes/class-ikoeh-design-tokens.php plugin/includes/rest/class-ikoeh-rest-design.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php .github/workflows/ci.yml
git commit -m "feat: add Design System custom post type, token parser, and REST routes"
```

---

### Task 2: MCP tools

**Files:**
- Create: `mcp-server/src/tools/design.js`
- Modify: `mcp-server/src/index.js` (import + registration call)

**Interfaces:**
- Consumes: REST routes from Task 1 (`/design` POST/GET/DELETE, `/design-library`, `/design-activate`, `/design-active`, `/design-check`).
- Produces: `registerDesignTools(server, client)`, called from `index.js` alongside the other `register*Tools` calls.

- [ ] **Step 1: Write the MCP tool file**

```js
import { z } from "zod";

export function registerDesignTools(server, client) {
  server.registerTool(
    "wp_save_design",
    {
      title: "Save Design",
      description:
        "Save a design system document (brand colors, typography, spacing, and guidance as structured markdown) to this site. Provide the full markdown content; the slug is derived from the front matter's name: field unless explicitly given.",
      inputSchema: { content: z.string(), slug: z.string().optional() },
    },
    async ({ content, slug }) => {
      const data = await client.request("POST", "/design", { json: { content, slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_design",
    {
      title: "Get Design",
      description: "Fetch one design document by slug, including its raw markdown and already-extracted tokens.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("GET", "/design", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_design_library",
    {
      title: "List Design Library",
      description: "List all saved design documents on this site (slug, name, description -- not the full markdown).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/design-library");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_design",
    {
      title: "Delete Design",
      description: "Delete a design document by slug. If it was the active design, no design is left active.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("DELETE", "/design", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_design",
    {
      title: "Activate Design",
      description: "Mark a saved design as the active one for this site.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/design-activate", { json: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_active_design",
    {
      title: "Get Active Design",
      description: "Fetch the currently-active design document (tokens + guidance), or null if none is active. Check this before building new pages so new content matches the brand.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/design-active");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_check_design",
    {
      title: "Check Design Readiness",
      description: "Check whether a saved design has the minimum tokens (at least one color and one typography entry) needed before activating it.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("GET", "/design-check", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 2: Register in index.js**

In `mcp-server/src/index.js`, add the import near the other tool imports:
```js
import { registerDesignTools } from "./tools/design.js";
```
and the registration call near the others:
```js
registerDesignTools(server, client);
```

- [ ] **Step 3: Syntax check**

Run: `node --check mcp-server/src/tools/design.js` and `node --check mcp-server/src/index.js`
Expected: no output (success) for both.

- [ ] **Step 4: Commit**

```bash
git add mcp-server/src/tools/design.js mcp-server/src/index.js
git commit -m "feat: add Design System MCP tools"
```
