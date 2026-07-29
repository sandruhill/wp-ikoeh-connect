# Gutenberg Pending-Batch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a staged draft-then-finalize workflow for editing WordPress block-editor (Gutenberg) content through wp-ikoeh-connect, so an agent can queue changes that only become live once a human-operated browser tab validates and serializes them with WordPress's real block-editor JavaScript.

**Architecture:** A custom post type (`ikoeh_gb_change`) stores batches (parent posts) and items (child posts, one per edited target), driven through an explicit state machine (`draft -> ready -> running -> prepared -> finalized`, with `failed`/`conflicted`/`canceled`/`stale` off-ramps). Agent-facing REST routes create/read/cancel batches and items. A separate set of internal REST routes, reachable only by a logged-in wp-admin session, back a new "Fila de Blocos" admin page whose JavaScript polls for ready batches, claims them, validates/serializes each item's blocks with `wp.blocks`, and reports the result back. A daily cron job retires stale/old records.

**Tech Stack:** PHP (WordPress plugin, no external dependencies), vanilla JS (loads WordPress's own `wp-blocks`/`wp-block-library`/`wp-element` scripts, no build step), Node.js MCP server (existing `@modelcontextprotocol/sdk`).

## Global Constraints

- REST routes are flat, maximum 3 URL segments (`ikoeh-connect/v1/<route>`) -- this host silently blocks any REST route with 4+ segments before WordPress sees the request. Any per-item ID goes in a query string (`?id=`), never a path segment.
- New scope: `gutenberg`. Off by default like every other scope. Added to `Ikoeh_Connect_Auth::ALL_SCOPES` (`plugin/includes/class-ikoeh-auth.php`) and `Ikoeh_Connect_Admin::SCOPE_LABELS` (`plugin/includes/class-ikoeh-admin.php`).
- Agent-facing routes authenticate via `Ikoeh_Connect_Auth::require_scope('gutenberg')` (Bearer token), same as every other resource. Internal finalizer routes (claim/complete/heartbeat) authenticate via `current_user_can('edit_posts')` + a WordPress REST nonce (`X-WP-Nonce` header, WordPress's own cookie-based REST auth) -- they are called only by the admin page's own JS, running in a logged-in browser session, never by the external Bearer-token agent.
- No PHPUnit tests exist in this repo. All test coverage is curl+wp-cli integration steps appended to `.github/workflows/ci.yml`, run against a Docker Compose WordPress instance. Each task's "test" step adds a new CI step matching the existing style in that file, and is also verified live via curl against `doctorbeats.com.br` using the deploy script at `/private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs` (self-updates the plugin via `POST /plugins`) since Docker does not run on this development machine.
- PHP files must pass `php -l` (no syntax errors). JS files must pass `node --check`.
- Every new REST-registering file follows the existing style: a static class with a `register_routes()` method, called from the `rest_api_init` callback in `plugin/wp-ikoeh-connect.php`, alongside a `require_once` near the top of that file.
- `wp_slash()` must wrap any string written via `update_post_meta()`/`wp_update_post()` whose value came from `json_decode()` or a REST request body -- `update_post_meta()`/`wp_update_post()` run `wp_unslash()` internally, so real backslashes (e.g. inside serialized block JSON) get silently stripped without it. This bug has bitten this codebase twice already (see `class-ikoeh-rest-content.php` and `class-ikoeh-rest-elementor.php`).
- Every `WP_REST_Response` returned from a route whose result must never be cached (any route touched by the finalizer flow) must call `Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response)` (defined in Task 1) -- confirmed in production that `Cache-Control` headers alone do not stop LiteSpeed Cache from replaying a cached response; the plugin's own `litespeed_control_set_nocache` action must also be called.

---

### Task 1: Custom post type, state machine core, and lease/lock primitives

**Files:**
- Create: `plugin/includes/class-ikoeh-gutenberg-store.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require the new file, hook `register_post_type` on `init`)

**Interfaces:**
- Produces (used by every later task):
  - `class Ikoeh_Connect_Gutenberg_Store`
  - `const POST_TYPE = 'ikoeh_gb_change'`
  - `const KIND_BATCH = 'batch'`, `const KIND_ITEM = 'item'`
  - `const STATUS_DRAFT = 'draft'`, `STATUS_READY = 'ready'`, `STATUS_RUNNING = 'running'`, `STATUS_PREPARED = 'prepared'`, `STATUS_FINALIZED = 'finalized'`, `STATUS_FAILED = 'failed'`, `STATUS_CONFLICTED = 'conflicted'`, `STATUS_CANCELED = 'canceled'`, `STATUS_STALE = 'stale'`
  - `const NON_TERMINAL_STATUSES` (array of the first 6 above), `const TERMINAL_STATUSES` (array of the last 3)
  - `const DRAFT_STALE_SECONDS = 86400`, `const LEASE_SECONDS = 300`, `const RETENTION_SECONDS = 1209600`
  - `Ikoeh_Connect_Gutenberg_Store::register_post_type(): void`
  - `Ikoeh_Connect_Gutenberg_Store::create_batch(string $label, string $agent_note): int|WP_Error`
  - `Ikoeh_Connect_Gutenberg_Store::create_item(int $batch_id, int $target_id, string $target_type, string $operation, array $blocks): int|WP_Error`
  - `Ikoeh_Connect_Gutenberg_Store::find_batch(int $batch_id): ?WP_Post`
  - `Ikoeh_Connect_Gutenberg_Store::find_item(int $item_id): ?WP_Post`
  - `Ikoeh_Connect_Gutenberg_Store::get_batches(?array $statuses, int $posts_per_page = 50): array` (list of `WP_Post`)
  - `Ikoeh_Connect_Gutenberg_Store::get_items(int $batch_id, ?array $statuses = null): array` (list of `WP_Post`)
  - `Ikoeh_Connect_Gutenberg_Store::status(int $post_id): string`
  - `Ikoeh_Connect_Gutenberg_Store::set_status(int $post_id, string $status): void`
  - `Ikoeh_Connect_Gutenberg_Store::atomic_status_transition(int $post_id, array $from_statuses, string $to_status): bool`
  - `Ikoeh_Connect_Gutenberg_Store::set_lease(int $post_id, string $owner): void`
  - `Ikoeh_Connect_Gutenberg_Store::clear_lease(int $post_id): void`
  - `Ikoeh_Connect_Gutenberg_Store::lease_is_valid(int $post_id, string $owner): bool`
  - `Ikoeh_Connect_Gutenberg_Store::content_hash(string $content): string` (`hash('sha256', $content)`)
  - `Ikoeh_Connect_Gutenberg_Store::normalize_blocks(mixed $value): array|WP_Error`
  - `Ikoeh_Connect_Gutenberg_Store::shape_batch(WP_Post $batch): array`
  - `Ikoeh_Connect_Gutenberg_Store::shape_item(WP_Post $item): array`
  - `Ikoeh_Connect_Gutenberg_Store::no_cache_headers(WP_REST_Response $response): void`
- Consumes: nothing from other tasks (this is the foundation).

- [ ] **Step 1: Write the store file**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage and state machine for Gutenberg pending changes: a batch (parent
 * post) groups one or more items (child posts, one per edited target). An
 * item moves draft -> ready -> running -> prepared -> finalized, or off into
 * failed/conflicted/canceled/stale. This class owns only data and state
 * transitions; REST routes (Task 2/3) and the finalizer admin page (Task 4)
 * are thin callers of these methods.
 */
class Ikoeh_Connect_Gutenberg_Store {

    const POST_TYPE = 'ikoeh_gb_change';

    const KIND_BATCH = 'batch';
    const KIND_ITEM = 'item';

    const META_KIND = '_ikoeh_gb_kind';
    const META_STATUS = '_ikoeh_gb_status';
    const META_STATUS_UPDATED_AT = '_ikoeh_gb_status_updated_at';
    const META_AGENT_NOTE = '_ikoeh_gb_agent_note';
    const META_LEASE_OWNER = '_ikoeh_gb_lease_owner';
    const META_LEASE_EXPIRES_AT = '_ikoeh_gb_lease_expires_at';
    const META_LAST_ERROR = '_ikoeh_gb_last_error';
    const META_TARGET_ID = '_ikoeh_gb_target_id';
    const META_TARGET_TYPE = '_ikoeh_gb_target_type';
    const META_OPERATION = '_ikoeh_gb_operation';
    const META_BASE_CONTENT_HASH = '_ikoeh_gb_base_content_hash';
    const META_BASE_CONTENT = '_ikoeh_gb_base_content';
    const META_BLOCK_SPEC = '_ikoeh_gb_block_spec';
    const META_FINALIZED_CONTENT = '_ikoeh_gb_finalized_content';
    const META_VALIDATION_ERRORS = '_ikoeh_gb_validation_errors';

    const STATUS_DRAFT = 'draft';
    const STATUS_READY = 'ready';
    const STATUS_RUNNING = 'running';
    const STATUS_PREPARED = 'prepared';
    const STATUS_FINALIZED = 'finalized';
    const STATUS_FAILED = 'failed';
    const STATUS_CONFLICTED = 'conflicted';
    const STATUS_CANCELED = 'canceled';
    const STATUS_STALE = 'stale';

    const NON_TERMINAL_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_READY, self::STATUS_RUNNING,
        self::STATUS_PREPARED, self::STATUS_FAILED, self::STATUS_CONFLICTED,
    ];
    const TERMINAL_STATUSES = [self::STATUS_FINALIZED, self::STATUS_CANCELED, self::STATUS_STALE];

    const DRAFT_STALE_SECONDS = 86400;
    const LEASE_SECONDS = 300;
    const RETENTION_SECONDS = 1209600;

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'Gutenberg pending changes',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'excerpt'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    /**
     * Anti-cache headers set directly on the response object. Response
     * headers alone were confirmed insufficient against this host's
     * LiteSpeed Cache plugin (it made its own caching decision and replayed
     * a cached 302 despite Cache-Control/X-LiteSpeed-Cache-Control being
     * set) -- litespeed_control_set_nocache is the plugin's own documented
     * hook for marking a request genuinely uncacheable.
     */
    public static function no_cache_headers(WP_REST_Response $response) {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        do_action('litespeed_control_set_nocache', 'ikoeh-connect gutenberg pending-batch');
    }

    private static function now_mysql() {
        return gmdate('Y-m-d H:i:s');
    }

    private static function meta_string($post_id, $key) {
        $value = get_post_meta($post_id, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    private static function meta_int($post_id, $key) {
        $value = get_post_meta($post_id, $key, true);
        return is_scalar($value) ? (int) $value : 0;
    }

    public static function status($post_id) {
        $status = self::meta_string($post_id, self::META_STATUS);
        return '' !== $status ? $status : self::STATUS_DRAFT;
    }

    public static function set_status($post_id, $status) {
        update_post_meta($post_id, self::META_STATUS, $status);
        update_post_meta($post_id, self::META_STATUS_UPDATED_AT, self::now_mysql());
    }

    public static function clear_lease($post_id) {
        delete_post_meta($post_id, self::META_LEASE_OWNER);
        delete_post_meta($post_id, self::META_LEASE_EXPIRES_AT);
    }

    public static function set_lease($post_id, $owner) {
        update_post_meta($post_id, self::META_LEASE_OWNER, $owner);
        update_post_meta($post_id, self::META_LEASE_EXPIRES_AT, time() + self::LEASE_SECONDS);
    }

    public static function lease_is_valid($post_id, $owner) {
        if ('' === $owner || self::meta_string($post_id, self::META_LEASE_OWNER) !== $owner) {
            return false;
        }
        $expires_at = self::meta_int($post_id, self::META_LEASE_EXPIRES_AT);
        return $expires_at > time();
    }

    /**
     * Atomic compare-and-swap status transition using add_option() as a
     * mutex: add_option() fails atomically at the database layer if the
     * option row already exists, so two concurrent callers racing to claim
     * the same post can never both succeed.
     */
    public static function atomic_status_transition($post_id, array $from_statuses, $to_status) {
        if (empty($from_statuses)) {
            return false;
        }

        $lock_option = 'ikoeh_gb_lock_' . $post_id;
        $lock_owner = wp_generate_password(20, false);
        $lock_payload = $lock_owner . '|' . (time() + 30);

        $acquired = add_option($lock_option, $lock_payload, '', false);
        if (!$acquired) {
            $existing = get_option($lock_option);
            $parts = is_string($existing) ? explode('|', $existing, 2) : [];
            $stale = count($parts) !== 2 || (int) $parts[1] <= time();
            if (!$stale) {
                return false;
            }
            delete_option($lock_option);
            if (!add_option($lock_option, $lock_payload, '', false)) {
                return false;
            }
        }

        try {
            if (!in_array(self::status($post_id), $from_statuses, true)) {
                return false;
            }
            self::set_status($post_id, $to_status);
            return true;
        } finally {
            $current = get_option($lock_option);
            if (is_string($current) && 0 === strpos($current, $lock_owner . '|')) {
                delete_option($lock_option);
            }
        }
    }

    public static function find_batch($batch_id) {
        $post = get_post($batch_id);
        if (!$post || self::POST_TYPE !== $post->post_type || self::KIND_BATCH !== self::meta_string($post->ID, self::META_KIND)) {
            return null;
        }
        return $post;
    }

    public static function find_item($item_id) {
        $post = get_post($item_id);
        if (!$post || self::POST_TYPE !== $post->post_type || self::KIND_ITEM !== self::meta_string($post->ID, self::META_KIND)) {
            return null;
        }
        return $post;
    }

    public static function get_batches($statuses = null, $posts_per_page = 50) {
        $meta_query = [['key' => self::META_KIND, 'value' => self::KIND_BATCH]];
        if (null !== $statuses) {
            $meta_query[] = ['key' => self::META_STATUS, 'value' => $statuses, 'compare' => 'IN'];
        }
        return get_posts([
            'post_type' => self::POST_TYPE, 'post_status' => 'any',
            'posts_per_page' => $posts_per_page, 'orderby' => 'ID', 'order' => 'DESC',
            'meta_query' => $meta_query,
        ]);
    }

    public static function get_items($batch_id, $statuses = null) {
        $meta_query = [['key' => self::META_KIND, 'value' => self::KIND_ITEM]];
        if (null !== $statuses) {
            $meta_query[] = ['key' => self::META_STATUS, 'value' => $statuses, 'compare' => 'IN'];
        }
        return get_posts([
            'post_type' => self::POST_TYPE, 'post_status' => 'any', 'post_parent' => $batch_id,
            'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => $meta_query,
        ]);
    }

    public static function content_hash($content) {
        return hash('sha256', $content);
    }

    /**
     * Structural validation only (name/attributes/innerBlocks shape) -- real
     * block validity (does this attribute schema match the registered block
     * type) is the finalizer JS's job, since that needs the real
     * wp.blocks registry running in a browser.
     */
    public static function normalize_blocks($value) {
        if (!is_array($value)) {
            return new WP_Error('ikoeh_connect_invalid_block_spec', 'block_spec must be an array of block objects.', ['status' => 400]);
        }

        if (isset($value['name']) && is_string($value['name'])) {
            $value = [$value];
        }

        $blocks = [];
        foreach (array_values($value) as $index => $raw_block) {
            $normalized = self::normalize_block($raw_block, "block_spec[{$index}]");
            if (is_wp_error($normalized)) {
                return $normalized;
            }
            $blocks[] = $normalized;
        }

        if (empty($blocks)) {
            return new WP_Error('ikoeh_connect_empty_block_spec', 'block_spec must contain at least one block.', ['status' => 400]);
        }

        return $blocks;
    }

    private static function normalize_block($value, $path) {
        if (!is_array($value)) {
            return new WP_Error('ikoeh_connect_invalid_block_spec', "{$path} must be an object.", ['status' => 400]);
        }
        if (empty($value['name']) || !is_string($value['name'])) {
            return new WP_Error('ikoeh_connect_invalid_block_spec', "{$path}.name must be a non-empty block name.", ['status' => 400]);
        }

        $attributes = isset($value['attributes']) && is_array($value['attributes']) ? $value['attributes'] : [];
        $inner_blocks_raw = isset($value['innerBlocks']) && is_array($value['innerBlocks']) ? array_values($value['innerBlocks']) : [];

        $inner_blocks = [];
        foreach ($inner_blocks_raw as $index => $inner) {
            $normalized = self::normalize_block($inner, "{$path}.innerBlocks[{$index}]");
            if (is_wp_error($normalized)) {
                return $normalized;
            }
            $inner_blocks[] = $normalized;
        }

        return ['name' => trim($value['name']), 'attributes' => $attributes, 'innerBlocks' => $inner_blocks];
    }

    public static function create_batch($label, $agent_note) {
        $label = '' !== trim($label) ? sanitize_text_field($label) : 'Untitled Gutenberg batch';

        $result = wp_insert_post([
            'post_type' => self::POST_TYPE, 'post_status' => 'publish',
            'post_title' => wp_slash($label), 'post_excerpt' => wp_slash(wp_strip_all_tags($agent_note)),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        $batch_id = (int) $result;
        update_post_meta($batch_id, self::META_KIND, self::KIND_BATCH);
        self::set_status($batch_id, self::STATUS_DRAFT);

        return $batch_id;
    }

    public static function create_item($batch_id, $target_id, $target_type, $operation, array $blocks) {
        $target = get_post($target_id);
        if (!$target) {
            return new WP_Error('ikoeh_connect_target_not_found', "Target post {$target_id} was not found.", ['status' => 404]);
        }

        $encoded = wp_json_encode($blocks);
        if (!is_string($encoded)) {
            return new WP_Error('ikoeh_connect_invalid_block_spec', 'block_spec could not be encoded as JSON.', ['status' => 400]);
        }

        $title = '' !== trim($target->post_title) ? $target->post_title : "(no title) #{$target->ID}";
        $result = wp_insert_post([
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_parent' => $batch_id,
            'post_title' => wp_slash($title), 'post_excerpt' => wp_slash("{$operation} for {$target->post_type} #{$target->ID}"),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        $item_id = (int) $result;
        update_post_meta($item_id, self::META_KIND, self::KIND_ITEM);
        self::set_status($item_id, self::STATUS_DRAFT);
        update_post_meta($item_id, self::META_TARGET_ID, $target_id);
        update_post_meta($item_id, self::META_TARGET_TYPE, wp_slash($target_type));
        update_post_meta($item_id, self::META_OPERATION, wp_slash($operation));
        update_post_meta($item_id, self::META_BASE_CONTENT_HASH, self::content_hash($target->post_content));
        update_post_meta($item_id, self::META_BASE_CONTENT, wp_slash($target->post_content));
        update_post_meta($item_id, self::META_BLOCK_SPEC, wp_slash($encoded));

        return $item_id;
    }

    private static function item_blocks(WP_Post $item) {
        $encoded = self::meta_string($item->ID, self::META_BLOCK_SPEC);
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            return new WP_Error('ikoeh_connect_invalid_stored_block_spec', "Item {$item->ID} has an invalid stored block_spec.", ['status' => 500]);
        }
        return self::normalize_blocks($decoded);
    }

    public static function shape_item(WP_Post $item) {
        $target_id = self::meta_int($item->ID, self::META_TARGET_ID);
        $target = get_post($target_id);
        $blocks = self::item_blocks($item);

        return [
            'item_id' => $item->ID,
            'batch_id' => $item->post_parent,
            'target_id' => $target_id,
            'target_type' => self::meta_string($item->ID, self::META_TARGET_TYPE),
            'target_title' => $target ? $target->post_title : "Missing target #{$target_id}",
            'operation' => self::meta_string($item->ID, self::META_OPERATION),
            'status' => self::status($item->ID),
            'top_level_block_count' => is_wp_error($blocks) ? 0 : count($blocks),
            'change_summary' => $item->post_excerpt,
            'validation_errors' => self::validation_errors($item->ID),
        ];
    }

    public static function validation_errors($item_id) {
        $value = get_post_meta($item_id, self::META_VALIDATION_ERRORS, true);
        return is_array($value) ? array_values($value) : [];
    }

    public static function shape_batch(WP_Post $batch) {
        $items = self::get_items($batch->ID);
        $counts = [];
        foreach ($items as $item) {
            $item_status = self::status($item->ID);
            $counts[$item_status] = ($counts[$item_status] ?? 0) + 1;
        }

        return [
            'batch_id' => $batch->ID,
            'label' => '' !== trim($batch->post_title) ? $batch->post_title : "Gutenberg batch #{$batch->ID}",
            'agent_note' => $batch->post_excerpt,
            'status' => self::status($batch->ID),
            'created_at' => $batch->post_date_gmt,
            'item_count' => count($items),
            'item_counts' => $counts,
            'last_error' => self::meta_string($batch->ID, self::META_LAST_ERROR),
            'finalization_required' => !in_array(self::status($batch->ID), self::TERMINAL_STATUSES, true),
            'finalization_url' => add_query_arg(['page' => 'ikoeh-connect-gutenberg-queue'], admin_url('admin.php')),
            'items' => array_map([self::class, 'shape_item'], $items),
        ];
    }
}
```

- [ ] **Step 2: Wire it into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other `require_once` lines:

```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-gutenberg-store.php';
```

Add a new top-level `add_action` call (not inside the existing `rest_api_init` closure, since this registers a post type, not a REST route):

```php
add_action('init', ['Ikoeh_Connect_Gutenberg_Store', 'register_post_type']);
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-gutenberg-store.php` and `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Deploy and verify the post type registers without fataling the site**

Run the deploy script (`node /private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs`), then:
```bash
source ~/.config/wp-ikoeh-connect/doctorbeats-full.conf
curl -s "$url/wp-json/ikoeh-connect/v1/site-info?_cb=$(date +%s)" -H "Authorization: Bearer $token" | python3 -c "import json,sys; print(json.load(sys.stdin)['active_plugins'])"
```
Expected: `wp-ikoeh-connect/wp-ikoeh-connect.php` still present in `active_plugins` (proves the plugin didn't fatal and self-deactivate on load).

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-gutenberg-store.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add Gutenberg pending-change post type and state machine core"
```

---

### Task 2: Agent-facing REST routes (batch/item CRUD, content read, enable-finalization)

**Files:**
- Create: `plugin/includes/rest/class-ikoeh-rest-gutenberg.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + register routes + register the new scope)
- Modify: `plugin/includes/class-ikoeh-auth.php` (add `'gutenberg'` to `ALL_SCOPES`)
- Modify: `plugin/includes/class-ikoeh-admin.php` (add `'gutenberg' => 'Gutenberg (mudancas pendentes)'` to `SCOPE_LABELS`)
- Modify: `.github/workflows/ci.yml` (append integration steps)

**Interfaces:**
- Consumes: every public method on `Ikoeh_Connect_Gutenberg_Store` from Task 1 (exact names/signatures listed in Task 1's Interfaces block).
- Produces: REST routes `POST /gutenberg-batch`, `GET /gutenberg-batches`, `GET /gutenberg-batch`, `DELETE /gutenberg-batch`, `POST /gutenberg-item`, `DELETE /gutenberg-item`, `POST /gutenberg-enable-finalization`, `GET /gutenberg-content` under `IKOEH_CONNECT_REST_NAMESPACE`. Task 3 adds sibling routes in the same file (`register_routes()` gets more entries there, not a new file) and calls `Ikoeh_Connect_Gutenberg_Store::cancel_batch()`/`cancel_item()`/`enable_finalization()` which this task defines as thin static wrappers below.

- [ ] **Step 1: Add cancel/enable-finalization methods to the store**

Add to `Ikoeh_Connect_Gutenberg_Store` (append inside the class body, after `shape_batch`):

```php
    public static function cancel_batch($batch_id) {
        $batch = self::find_batch($batch_id);
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', "Gutenberg batch {$batch_id} was not found.", ['status' => 404]);
        }
        if (self::STATUS_FINALIZED === self::status($batch->ID)) {
            return new WP_Error('ikoeh_connect_batch_already_finalized', 'Finalized Gutenberg batches cannot be canceled.', ['status' => 409]);
        }

        self::set_status($batch->ID, self::STATUS_CANCELED);
        self::clear_lease($batch->ID);
        foreach (self::get_items($batch->ID) as $item) {
            if (self::STATUS_FINALIZED === self::status($item->ID)) {
                continue;
            }
            self::set_status($item->ID, self::STATUS_CANCELED);
            self::clear_lease($item->ID);
        }

        return self::shape_batch(self::find_batch($batch->ID));
    }

    public static function cancel_item($item_id) {
        $item = self::find_item($item_id);
        if (!$item) {
            return new WP_Error('ikoeh_connect_item_not_found', "Gutenberg item {$item_id} was not found.", ['status' => 404]);
        }
        if (in_array(self::status($item->ID), self::TERMINAL_STATUSES, true)) {
            return new WP_Error('ikoeh_connect_item_not_cancelable', 'This Gutenberg pending item is already terminal.', ['status' => 409]);
        }

        self::set_status($item->ID, self::STATUS_CANCELED);
        self::clear_lease($item->ID);

        $batch = self::find_batch($item->post_parent);
        if ($batch && empty(self::get_items($batch->ID, self::NON_TERMINAL_STATUSES))) {
            self::set_status($batch->ID, self::STATUS_CANCELED);
            self::clear_lease($batch->ID);
        }

        return self::shape_item(self::find_item($item->ID));
    }

    public static function enable_finalization($batch_id) {
        $batch = self::find_batch($batch_id);
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', "Gutenberg batch {$batch_id} was not found.", ['status' => 404]);
        }

        if (empty(self::get_items($batch->ID))) {
            return new WP_Error('ikoeh_connect_batch_empty', 'Add at least one item before enabling finalization.', ['status' => 400]);
        }

        foreach (self::get_items($batch->ID, [self::STATUS_DRAFT]) as $item) {
            self::set_status($item->ID, self::STATUS_READY);
        }

        if (!self::atomic_status_transition($batch->ID, [self::STATUS_DRAFT], self::STATUS_READY)) {
            return new WP_Error('ikoeh_connect_batch_not_draft', 'Only a draft batch can have finalization enabled.', ['status' => 409]);
        }

        return self::shape_batch(self::find_batch($batch->ID));
    }

    /** Read a target's current blocks without involving the batch system. */
    public static function get_target_blocks($target_id) {
        $target = get_post($target_id);
        if (!$target) {
            return new WP_Error('ikoeh_connect_target_not_found', "Target post {$target_id} was not found.", ['status' => 404]);
        }
        return ['target_id' => $target_id, 'blocks' => parse_blocks($target->post_content)];
    }
```

- [ ] **Step 2: Write the REST class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Gutenberg {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-batch', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'cancel_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-batches', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_batches'],
            // Dual-auth: the external Bearer-token agent lists its own batches,
            // AND the Fila de Blocos page's own JS (a real wp-admin session,
            // no Bearer token) needs this same route to find READY batches to
            // claim -- confirmed live that a plain require_scope() 401s the
            // browser session outright.
            'permission_callback' => Ikoeh_Connect_Auth::require_scope_or_admin_session('gutenberg'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-item', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_item'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'cancel_item'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-enable-finalization', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'enable_finalization'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-content', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_content'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);
    }

    private static function respond($result) {
        if (is_wp_error($result)) {
            return $result;
        }
        return new WP_REST_Response($result, 200);
    }

    public static function create_batch(WP_REST_Request $request) {
        $label = (string) ($request->get_param('label') ?: '');
        $agent_note = (string) ($request->get_param('agent_note') ?: '');
        $batch_id = Ikoeh_Connect_Gutenberg_Store::create_batch($label, $agent_note);
        if (is_wp_error($batch_id)) {
            return $batch_id;
        }
        return new WP_REST_Response(['batch_id' => $batch_id], 200);
    }

    public static function list_batches(WP_REST_Request $request) {
        $status = $request->get_param('status');
        $statuses = $status ? [sanitize_key($status)] : null;
        $batches = Ikoeh_Connect_Gutenberg_Store::get_batches($statuses);
        return new WP_REST_Response(array_map(['Ikoeh_Connect_Gutenberg_Store', 'shape_batch'], $batches), 200);
    }

    public static function get_batch(WP_REST_Request $request) {
        $batch = Ikoeh_Connect_Gutenberg_Store::find_batch((int) $request->get_param('id'));
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', 'Gutenberg batch not found.', ['status' => 404]);
        }
        return new WP_REST_Response(Ikoeh_Connect_Gutenberg_Store::shape_batch($batch), 200);
    }

    public static function cancel_batch(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::cancel_batch((int) $request->get_param('id')));
    }

    public static function create_item(WP_REST_Request $request) {
        $blocks = Ikoeh_Connect_Gutenberg_Store::normalize_blocks($request->get_param('block_spec'));
        if (is_wp_error($blocks)) {
            return $blocks;
        }

        $item_id = Ikoeh_Connect_Gutenberg_Store::create_item(
            (int) $request->get_param('batch_id'),
            (int) $request->get_param('target_id'),
            (string) ($request->get_param('target_type') ?: 'post'),
            (string) ($request->get_param('operation') ?: 'update'),
            $blocks
        );
        if (is_wp_error($item_id)) {
            return $item_id;
        }
        return new WP_REST_Response(['item_id' => $item_id], 200);
    }

    public static function cancel_item(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::cancel_item((int) $request->get_param('id')));
    }

    public static function enable_finalization(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::enable_finalization((int) $request->get_param('batch_id')));
    }

    public static function get_content(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::get_target_blocks((int) $request->get_param('id')));
    }
}
```

- [ ] **Step 3: Add the scope**

In `plugin/includes/class-ikoeh-auth.php`, change:
```php
const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache', 'elementor', 'theme', 'media', 'admin_access'];
```
to:
```php
const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache', 'elementor', 'theme', 'media', 'admin_access', 'gutenberg'];
```

In `plugin/includes/class-ikoeh-admin.php`, add to `SCOPE_LABELS` (after the `admin_access` line):
```php
        'gutenberg' => 'Gutenberg (mudancas pendentes)',
```

Also add this new helper to `plugin/includes/class-ikoeh-auth.php`, right before `setup_rate_limit_ok()` (needed by `/gutenberg-batches` below and by Task 4's finalizer JS, which calls that same route from a real wp-admin session with no Bearer token):
```php
    /**
     * Some routes are legitimately called by both the external Bearer-token
     * agent AND a logged-in wp-admin browser session (e.g. GET
     * /gutenberg-batches -- the agent lists batches it created, and the
     * Fila de Blocos page's own JS needs this same route to find READY
     * batches to claim, but a browser session never carries a Bearer
     * token). Accept either: a real WP session with edit_posts, or the
     * normal scope check.
     */
    public static function require_scope_or_admin_session($scope = null) {
        $scope_check = self::require_scope($scope);
        return function (WP_REST_Request $request) use ($scope_check) {
            if (current_user_can('edit_posts')) {
                return true;
            }
            return $scope_check($request);
        };
    }
```

- [ ] **Step 4: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add the require near the other REST requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-gutenberg.php';
```
and inside the `rest_api_init` closure, add:
```php
    Ikoeh_Connect_Rest_Gutenberg::register_routes();
```

- [ ] **Step 5: Lint**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-gutenberg.php plugin/includes/class-ikoeh-gutenberg-store.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all five.

- [ ] **Step 6: Append CI integration steps**

Add to `.github/workflows/ci.yml`, after the last existing `integration` job step (before "Tear down"):

```yaml
      - name: Verify Gutenberg batch create/read/enable-finalization round-trips
        run: |
          POST_ID=$(docker compose run --rm wp-cli wp post create --post_type=page \
            --post_title="CI Gutenberg Test" --post_status=publish --post_content="<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->" \
            --porcelain --path=/var/www/html)
          BATCH_ID=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-batch" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d '{"label":"CI batch","agent_note":"ci"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['batch_id'])")
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-item" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d "{\"batch_id\":$BATCH_ID,\"target_id\":$POST_ID,\"target_type\":\"page\",\"operation\":\"update\",\"block_spec\":[{\"name\":\"core/paragraph\",\"attributes\":{\"content\":\"updated\"}}]}"
          status=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-enable-finalization" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d "{\"batch_id\":$BATCH_ID}" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])")
          test "$status" = "ready"
      - name: Verify Gutenberg unauthenticated request is rejected
        run: |
          code=$(curl -sS -o /dev/null -w "%{http_code}" "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-batches")
          test "$code" = "401"
```

- [ ] **Step 7: Deploy and verify live**

Run the deploy script, then run the equivalent of the two CI steps above directly against `doctorbeats.com.br` with `~/.config/wp-ikoeh-connect/doctorbeats-full.conf` (needs the `gutenberg` scope -- create a fresh connection with that scope checked via wp-admin if the existing one predates this task, same as was done for `admin_access`).
Expected: batch created, item created, `enable_finalization` returns `status: "ready"`; a request with no `Authorization` header returns 401.

- [ ] **Step 8: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-gutenberg.php plugin/includes/class-ikoeh-gutenberg-store.php plugin/includes/class-ikoeh-auth.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php .github/workflows/ci.yml
git commit -m "feat: add agent-facing Gutenberg pending-batch REST routes"
```

---

### Task 3: Finalizer routes (claim, complete, heartbeat, runtime status) and commit logic

**Files:**
- Modify: `plugin/includes/class-ikoeh-gutenberg-store.php` (add claim/complete/commit/runtime methods)
- Modify: `plugin/includes/rest/class-ikoeh-rest-gutenberg.php` (add the 4 internal routes)
- Modify: `.github/workflows/ci.yml` (append integration steps)

**Interfaces:**
- Consumes: `Ikoeh_Connect_Gutenberg_Store` methods from Task 1 and 2 (`find_batch`, `find_item`, `get_items`, `status`, `set_status`, `atomic_status_transition`, `set_lease`, `clear_lease`, `lease_is_valid`, `content_hash`, `shape_batch`, `shape_item`, `no_cache_headers`, plus the `META_*` constants).
- Produces:
  - `Ikoeh_Connect_Gutenberg_Store::claim_batch(int $batch_id): array|WP_Error` (`['lease_owner' => string, 'batch' => array]`)
  - `Ikoeh_Connect_Gutenberg_Store::claim_next_item(int $batch_id, string $lease_owner): array|WP_Error` (`['done' => bool, 'item' => ?array, 'batch' => array]`)
  - `Ikoeh_Connect_Gutenberg_Store::complete_item(int $item_id, string $lease_owner, string $content, mixed $validations): array|WP_Error`
  - `Ikoeh_Connect_Gutenberg_Store::heartbeat(): void`
  - `Ikoeh_Connect_Gutenberg_Store::runtime_status(int $batch_id): array` (`['online' => bool, 'can_finalize' => bool]`)
  - REST routes `POST /gutenberg-claim-batch`, `POST /gutenberg-claim-item`, `POST /gutenberg-complete-item`, `POST /gutenberg-heartbeat`, `GET /gutenberg-runtime` -- all gated by `current_user_can('edit_posts')` (checked inline in each callback, not via `Ikoeh_Connect_Auth::require_scope`), for Task 4's admin-page JS to call. These are NOT added to the MCP server (Task 6 skips them, per the spec).

- [ ] **Step 1: Add claim/complete/commit/heartbeat methods to the store**

Append inside the `Ikoeh_Connect_Gutenberg_Store` class body (after `get_target_blocks`):

```php
    const HEARTBEAT_TRANSIENT = 'ikoeh_gb_finalizer_heartbeat';
    const HEARTBEAT_STALE_SECONDS = 15;

    public static function heartbeat() {
        set_transient(self::HEARTBEAT_TRANSIENT, time(), self::HEARTBEAT_STALE_SECONDS);
    }

    public static function runtime_status($batch_id) {
        $last_beat = get_transient(self::HEARTBEAT_TRANSIENT);
        $online = is_int($last_beat) && (time() - $last_beat) <= self::HEARTBEAT_STALE_SECONDS;

        $batch = self::find_batch($batch_id);
        $can_finalize = false;
        if ($online && $batch) {
            $can_finalize = true;
            foreach (self::get_items($batch->ID) as $item) {
                $target_id = self::meta_int($item->ID, self::META_TARGET_ID);
                if ($target_id <= 0 || !current_user_can('edit_post', $target_id)) {
                    $can_finalize = false;
                    break;
                }
            }
        }

        return ['online' => $online, 'can_finalize' => $can_finalize];
    }

    private static function release_expired_lease_if_any($batch) {
        if (self::STATUS_RUNNING === self::status($batch->ID) && !self::lease_is_valid($batch->ID, self::meta_string($batch->ID, self::META_LEASE_OWNER))) {
            self::set_status($batch->ID, self::STATUS_FAILED);
            update_post_meta($batch->ID, self::META_LAST_ERROR, 'A previous Fila de Blocos tab stopped before renewing its lease.');
            self::clear_lease($batch->ID);
        }
        foreach (self::get_items($batch->ID, [self::STATUS_RUNNING]) as $item) {
            if (self::lease_is_valid($item->ID, self::meta_string($item->ID, self::META_LEASE_OWNER))) {
                continue;
            }
            self::set_status($item->ID, self::STATUS_FAILED);
            update_post_meta($item->ID, self::META_VALIDATION_ERRORS, [['message' => 'A previous Fila de Blocos tab stopped before completing this item.']]);
            self::clear_lease($item->ID);
        }
    }

    public static function claim_batch($batch_id) {
        $batch = self::find_batch($batch_id);
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', "Gutenberg batch {$batch_id} was not found.", ['status' => 404]);
        }

        self::release_expired_lease_if_any($batch);
        $batch = self::find_batch($batch_id);
        $current_status = self::status($batch->ID);

        if (self::STATUS_DRAFT === $current_status) {
            return new WP_Error('ikoeh_connect_batch_not_ready', 'Draft batches cannot be finalized until gutenberg-enable-finalization is called.', ['status' => 409]);
        }
        if (!in_array($current_status, [self::STATUS_READY, self::STATUS_FAILED], true)) {
            return new WP_Error('ikoeh_connect_batch_not_claimable', "Batch {$batch->ID} is {$current_status} and cannot be claimed.", ['status' => 409]);
        }

        $lease_owner = wp_generate_password(24, false);
        if (!self::atomic_status_transition($batch->ID, [self::STATUS_READY, self::STATUS_FAILED], self::STATUS_RUNNING)) {
            return new WP_Error('ikoeh_connect_batch_claim_raced', 'Another Fila de Blocos tab claimed this batch first.', ['status' => 409]);
        }

        self::set_lease($batch->ID, $lease_owner);
        update_post_meta($batch->ID, self::META_LAST_ERROR, '');
        foreach (self::get_items($batch->ID, [self::STATUS_FAILED, self::STATUS_CONFLICTED]) as $item) {
            self::set_status($item->ID, self::STATUS_READY);
            update_post_meta($item->ID, self::META_VALIDATION_ERRORS, []);
            self::clear_lease($item->ID);
        }

        return ['lease_owner' => $lease_owner, 'batch' => self::shape_batch(self::find_batch($batch->ID))];
    }

    public static function claim_next_item($batch_id, $lease_owner) {
        $batch = self::find_batch($batch_id);
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', "Gutenberg batch {$batch_id} was not found.", ['status' => 404]);
        }
        if (self::STATUS_RUNNING !== self::status($batch->ID) || !self::lease_is_valid($batch->ID, $lease_owner)) {
            return new WP_Error('ikoeh_connect_batch_lease_invalid', 'The batch finalization lease is no longer active.', ['status' => 409]);
        }

        self::set_lease($batch->ID, $lease_owner);
        $ready_items = self::get_items($batch->ID, [self::STATUS_READY]);
        if (empty($ready_items)) {
            return self::finish_batch_if_complete($batch);
        }

        $item = $ready_items[0];
        if (!self::atomic_status_transition($item->ID, [self::STATUS_READY], self::STATUS_RUNNING)) {
            return new WP_Error('ikoeh_connect_item_claim_raced', 'Another Fila de Blocos request claimed this item first.', ['status' => 409]);
        }
        self::set_lease($item->ID, $lease_owner);

        $item = self::find_item($item->ID);
        $blocks = self::item_blocks($item);
        if (is_wp_error($blocks)) {
            return self::fail_item($item->ID, $lease_owner, [['message' => $blocks->get_error_message()]]);
        }

        return ['done' => false, 'item' => self::shape_item($item) + ['block_spec' => $blocks], 'batch' => self::shape_batch($batch)];
    }

    private static function fail_item($item_id, $lease_owner, $errors, $message = 'One or more Gutenberg items failed validation.') {
        $item = self::find_item($item_id);
        if (!$item) {
            return new WP_Error('ikoeh_connect_item_not_found', "Gutenberg item {$item_id} was not found.", ['status' => 404]);
        }
        if (self::STATUS_RUNNING !== self::status($item->ID) || !self::lease_is_valid($item->ID, $lease_owner)) {
            return new WP_Error('ikoeh_connect_item_lease_invalid', 'The item finalization lease is no longer active.', ['status' => 409]);
        }

        self::set_status($item->ID, self::STATUS_FAILED);
        self::clear_lease($item->ID);
        update_post_meta($item->ID, self::META_VALIDATION_ERRORS, $errors);

        $batch = self::find_batch($item->post_parent);
        if ($batch) {
            self::set_status($batch->ID, self::STATUS_FAILED);
            self::clear_lease($batch->ID);
            update_post_meta($batch->ID, self::META_LAST_ERROR, $message);
        }

        return ['item' => self::shape_item(self::find_item($item->ID)), 'batch' => $batch ? self::shape_batch(self::find_batch($batch->ID)) : null, 'done' => true];
    }

    /**
     * Unconditional commit-time failure, distinct from fail_item(): by the
     * time commit_prepared_items() runs, every item is already PREPARED
     * with its lease already cleared (complete_item() did that), so
     * fail_item()'s "must be RUNNING with a valid lease" guard always trips
     * and silently does nothing -- leaving the batch stuck at RUNNING
     * forever with no recovery path. This helper mirrors conflict_item()'s
     * unconditional approach instead.
     */
    private static function fail_prepared_item(WP_Post $item, $errors, $message) {
        self::set_status($item->ID, self::STATUS_FAILED);
        self::clear_lease($item->ID);
        update_post_meta($item->ID, self::META_VALIDATION_ERRORS, $errors);

        $batch = self::find_batch($item->post_parent);
        if ($batch) {
            self::set_status($batch->ID, self::STATUS_FAILED);
            self::clear_lease($batch->ID);
            update_post_meta($batch->ID, self::META_LAST_ERROR, $message);
        }

        return new WP_Error('ikoeh_connect_gutenberg_prepared_item_failed', $message, [
            'status' => 500, 'item' => self::shape_item(self::find_item($item->ID)),
        ]);
    }

    private static function conflict_item(WP_Post $item) {
        self::set_status($item->ID, self::STATUS_CONFLICTED);
        self::clear_lease($item->ID);
        update_post_meta($item->ID, self::META_VALIDATION_ERRORS, [['message' => 'The target content changed after this item was queued.']]);

        $batch = self::find_batch($item->post_parent);
        if ($batch) {
            self::set_status($batch->ID, self::STATUS_FAILED);
            self::clear_lease($batch->ID);
            update_post_meta($batch->ID, self::META_LAST_ERROR, 'At least one target changed after it was queued; live content was left unchanged.');
        }

        return new WP_Error('ikoeh_connect_target_changed', 'The target content changed after this item was queued. Live content was left unchanged.', [
            'status' => 409, 'item' => self::shape_item(self::find_item($item->ID)),
        ]);
    }

    public static function complete_item($item_id, $lease_owner, $content, $validations) {
        $item = self::find_item($item_id);
        if (!$item) {
            return new WP_Error('ikoeh_connect_item_not_found', "Gutenberg item {$item_id} was not found.", ['status' => 404]);
        }
        if (self::STATUS_RUNNING !== self::status($item->ID) || !self::lease_is_valid($item->ID, $lease_owner)) {
            return new WP_Error('ikoeh_connect_item_lease_invalid', 'The item finalization lease is no longer active.', ['status' => 409]);
        }

        $has_failures = !is_array($validations) || array_filter($validations, function ($v) {
            return !is_array($v) || true !== ($v['isValid'] ?? false);
        }) !== [];
        if ($has_failures) {
            // wp_slash(): $validations is REST-request-derived and reaches update_post_meta()
            // inside fail_item(), which wp_unslash()es internally -- same recurring bug class
            // as every other REST-sourced string written in this class.
            $safe_validations = is_array($validations) ? wp_slash($validations) : [['message' => 'JS validation failed.']];
            return self::fail_item($item->ID, $lease_owner, $safe_validations, 'JS validation failed; canonical content was not written.');
        }

        $target_id = self::meta_int($item->ID, self::META_TARGET_ID);
        $target = get_post($target_id);
        if (!$target) {
            return self::fail_item($item->ID, $lease_owner, [['message' => 'The target post no longer exists.']], 'Target post missing.');
        }

        $base_hash = self::meta_string($item->ID, self::META_BASE_CONTENT_HASH);
        if ('' !== $base_hash && !hash_equals($base_hash, self::content_hash($target->post_content))) {
            return self::conflict_item($item);
        }

        update_post_meta($item->ID, self::META_FINALIZED_CONTENT, wp_slash($content));
        self::set_status($item->ID, self::STATUS_PREPARED);
        self::clear_lease($item->ID);
        update_post_meta($item->ID, self::META_VALIDATION_ERRORS, []);

        $batch = self::find_batch($item->post_parent);
        $batch_result = $batch ? self::finish_batch_if_complete($batch) : ['done' => true, 'batch' => null];
        if (is_wp_error($batch_result)) {
            return $batch_result;
        }

        return ['item' => self::shape_item(self::find_item($item->ID)), 'batch' => $batch_result['batch'], 'done' => $batch_result['done'] ?? false];
    }

    private static function finish_batch_if_complete(WP_Post $batch) {
        if (!empty(self::get_items($batch->ID, [self::STATUS_READY]))) {
            return ['done' => false, 'batch' => self::shape_batch($batch)];
        }
        if (!empty(self::get_items($batch->ID, [self::STATUS_FAILED, self::STATUS_CONFLICTED]))) {
            self::set_status($batch->ID, self::STATUS_FAILED);
            self::clear_lease($batch->ID);
            return ['done' => true, 'batch' => self::shape_batch(self::find_batch($batch->ID))];
        }
        if (!empty(self::get_items($batch->ID, [self::STATUS_RUNNING]))) {
            return ['done' => false, 'batch' => self::shape_batch($batch)];
        }

        return self::commit_prepared_items($batch, self::get_items($batch->ID, [self::STATUS_PREPARED]));
    }

    private static function commit_prepared_items(WP_Post $batch, array $prepared_items) {
        if (empty($prepared_items)) {
            self::set_status($batch->ID, self::STATUS_FINALIZED);
            self::clear_lease($batch->ID);
            return ['done' => true, 'batch' => self::shape_batch(self::find_batch($batch->ID))];
        }

        foreach ($prepared_items as $item) {
            $target = get_post(self::meta_int($item->ID, self::META_TARGET_ID));
            $base_hash = self::meta_string($item->ID, self::META_BASE_CONTENT_HASH);
            if (!$target) {
                return self::fail_prepared_item($item, [['message' => 'The target post no longer exists.']], 'Target post missing; live content was left unchanged.');
            }
            if ('' !== $base_hash && !hash_equals($base_hash, self::content_hash($target->post_content))) {
                return self::conflict_item($item);
            }
        }

        $written = [];
        foreach ($prepared_items as $item) {
            $target = get_post(self::meta_int($item->ID, self::META_TARGET_ID));
            $updated = wp_update_post([
                'ID' => $target->ID,
                'post_content' => wp_slash(self::meta_string($item->ID, self::META_FINALIZED_CONTENT)),
            ], true);

            if (is_wp_error($updated)) {
                $restored_ok = true;
                foreach (array_reverse($written) as $written_item) {
                    $written_target = get_post(self::meta_int($written_item->ID, self::META_TARGET_ID));
                    if ($written_target) {
                        $restore = wp_update_post(['ID' => $written_target->ID, 'post_content' => wp_slash(self::meta_string($written_item->ID, self::META_BASE_CONTENT))], true);
                        if (is_wp_error($restore)) {
                            $restored_ok = false;
                        }
                    } else {
                        $restored_ok = false;
                    }
                }
                $message = $restored_ok
                    ? 'WordPress failed to write post_content; live content was left unchanged.'
                    : 'WordPress failed to write post_content and rollback failed; inspect the affected targets before retrying.';
                return self::fail_prepared_item($item, [['message' => $updated->get_error_message()]], $message);
            }

            $written[] = $item;
        }

        foreach ($prepared_items as $item) {
            self::set_status($item->ID, self::STATUS_FINALIZED);
            self::clear_lease($item->ID);
            delete_post_meta($item->ID, self::META_BASE_CONTENT);
            delete_post_meta($item->ID, self::META_FINALIZED_CONTENT);
        }

        self::set_status($batch->ID, self::STATUS_FINALIZED);
        self::clear_lease($batch->ID);

        return ['done' => true, 'batch' => self::shape_batch(self::find_batch($batch->ID))];
    }
```

- [ ] **Step 2: Add the 4 internal REST routes**

In `plugin/includes/rest/class-ikoeh-rest-gutenberg.php`, add to `register_routes()`:

```php
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-claim-batch', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'claim_batch'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-claim-item', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'claim_item'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-complete-item', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'complete_item'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-heartbeat', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'heartbeat'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-runtime', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'runtime'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);
```

Add these methods to the class:

```php
    /**
     * Gated by a logged-in wp-admin session with a valid REST nonce, not the
     * Bearer-token scope system -- these routes are called only by the Fila
     * de Blocos admin page's own JS, running in the operator's browser.
     */
    public static function require_admin_session() {
        return current_user_can('edit_posts');
    }

    /**
     * A returned WP_Error has no header() method, so wrapping it in our own
     * WP_REST_Response (instead of letting WordPress convert it later) is
     * the only way to guarantee no_cache_headers() actually runs on error
     * paths too -- confirmed necessary in production: this state machine's
     * whole point is to produce 409/404/500 responses under contention,
     * and any one of those getting cached by LiteSpeed and replayed to a
     * later legitimate retry would be exactly the bug this helper exists
     * to prevent.
     */
    private static function respond_no_cache($result) {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            $response = new WP_REST_Response([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data' => $data,
            ], $status);
        } else {
            $response = new WP_REST_Response($result, 200);
        }
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function claim_batch(WP_REST_Request $request) {
        return self::respond_no_cache(Ikoeh_Connect_Gutenberg_Store::claim_batch((int) $request->get_param('id')));
    }

    public static function claim_item(WP_REST_Request $request) {
        return self::respond_no_cache(Ikoeh_Connect_Gutenberg_Store::claim_next_item(
            (int) $request->get_param('batch_id'),
            (string) $request->get_param('lease_owner')
        ));
    }

    public static function complete_item(WP_REST_Request $request) {
        return self::respond_no_cache(Ikoeh_Connect_Gutenberg_Store::complete_item(
            (int) $request->get_param('item_id'),
            (string) $request->get_param('lease_owner'),
            (string) $request->get_param('content'),
            $request->get_param('validations')
        ));
    }

    public static function heartbeat() {
        Ikoeh_Connect_Gutenberg_Store::heartbeat();
        $response = new WP_REST_Response(['ok' => true], 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function runtime(WP_REST_Request $request) {
        $response = new WP_REST_Response(Ikoeh_Connect_Gutenberg_Store::runtime_status((int) $request->get_param('id')), 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-gutenberg-store.php plugin/includes/rest/class-ikoeh-rest-gutenberg.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Append a CI integration step for the full claim/complete/finalize cycle**

Add to `.github/workflows/ci.yml` after the Task 2 steps:

```yaml
      - name: Verify Gutenberg claim/complete/finalize cycle (simulating the Fila de Blocos JS)
        run: |
          POST_ID=$(docker compose run --rm wp-cli wp post create --post_type=page \
            --post_title="CI Gutenberg Finalize" --post_status=publish --post_content="<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->" \
            --porcelain --path=/var/www/html)
          BATCH_ID=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-batch" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"label":"CI finalize"}' \
            | python3 -c "import sys,json; print(json.load(sys.stdin)['batch_id'])")
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-item" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
            -d "{\"batch_id\":$BATCH_ID,\"target_id\":$POST_ID,\"target_type\":\"page\",\"operation\":\"update\",\"block_spec\":[{\"name\":\"core/paragraph\",\"attributes\":{\"content\":\"updated\"}}]}"
          curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-enable-finalization" \
            -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d "{\"batch_id\":$BATCH_ID}"
          # Simulate the browser-side finalizer using an admin cookie instead of the Bearer token,
          # matching how the internal routes are actually gated.
          docker compose run --rm wp-cli wp user application-password create admin ci --porcelain --path=/var/www/html > /tmp/apppass.txt
          APPPASS=$(cat /tmp/apppass.txt)
          AUTH=$(python3 -c "import base64; print(base64.b64encode(b'admin:$APPPASS').decode())")
          LEASE_OWNER=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-claim-batch?id=$BATCH_ID" \
            -H "Authorization: Basic $AUTH" | python3 -c "import sys,json; print(json.load(sys.stdin)['lease_owner'])")
          ITEM_ID=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-claim-item" \
            -H "Authorization: Basic $AUTH" -H "Content-Type: application/json" \
            -d "{\"batch_id\":$BATCH_ID,\"lease_owner\":\"$LEASE_OWNER\"}" | python3 -c "import sys,json; print(json.load(sys.stdin)['item']['item_id'])")
          DONE=$(curl -sS -X POST "http://localhost:8080/wp-json/ikoeh-connect/v1/gutenberg-complete-item" \
            -H "Authorization: Basic $AUTH" -H "Content-Type: application/json" \
            -d "{\"item_id\":$ITEM_ID,\"lease_owner\":\"$LEASE_OWNER\",\"content\":\"<!-- wp:paragraph --><p>updated</p><!-- /wp:paragraph -->\",\"validations\":[{\"isValid\":true}]}" \
            | python3 -c "import sys,json; print(json.load(sys.stdin)['batch']['status'])")
          test "$DONE" = "finalized"
          content=$(docker compose run --rm wp-cli wp post get "$POST_ID" --field=post_content --path=/var/www/html)
          echo "$content" | grep -q "updated"
```

- [ ] **Step 5: Deploy and verify live**

Note: the internal routes need a real logged-in wp-admin session or WordPress Application Password to test outside CI, since they are gated by `current_user_can`, not a Bearer token. If a live end-to-end check isn't practical from the command line, this task's live verification can be limited to confirming the routes are registered (`OPTIONS` request to each returns 200 with the right methods listed) and deferred fully to Task 4, where the actual admin page provides real session auth for a true end-to-end run.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-gutenberg-store.php plugin/includes/rest/class-ikoeh-rest-gutenberg.php .github/workflows/ci.yml
git commit -m "feat: add Gutenberg finalizer claim/complete/heartbeat routes and commit logic"
```

---

### Task 4: "Fila de Blocos" wp-admin page and finalizer JS

**Files:**
- Create: `plugin/assets/gutenberg-queue.js`
- Create: `plugin/includes/class-ikoeh-gutenberg-admin.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + hook `admin_menu`)

**Interfaces:**
- Consumes: REST routes from Task 3 (`/gutenberg-claim-batch`, `/gutenberg-claim-item`, `/gutenberg-complete-item`, `/gutenberg-heartbeat`) and Task 2 (`/gutenberg-batches?status=ready`).
- Produces: wp-admin page at `admin.php?page=ikoeh-connect-gutenberg-queue` (the `finalization_url` from `shape_batch` in Task 1 already points here).

- [ ] **Step 1: Write the admin page class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Gutenberg_Admin {

    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            'Fila de Blocos - iKOEH Connect',
            'iKOEH Fila de Blocos',
            'edit_posts',
            'ikoeh-connect-gutenberg-queue',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_script('wp-blocks');
        wp_enqueue_script('wp-block-library');
        wp_enqueue_script('wp-element');
        wp_enqueue_script(
            'ikoeh-connect-gutenberg-queue',
            IKOEH_CONNECT_URL . 'assets/gutenberg-queue.js',
            ['wp-blocks', 'wp-block-library', 'wp-element'],
            IKOEH_CONNECT_VERSION,
            true
        );
        wp_localize_script('ikoeh-connect-gutenberg-queue', 'ikoehGutenbergQueue', [
            'restUrl' => rest_url(IKOEH_CONNECT_REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
        ?>
        <div class="wrap">
            <h1>Fila de Blocos</h1>
            <p>Mantenha esta pagina aberta para que mudancas de Gutenberg pendentes sejam validadas e aplicadas automaticamente.</p>
            <div id="ikoeh-gutenberg-queue-status">Conectando...</div>
        </div>
        <?php
    }
}
```

- [ ] **Step 2: Write the finalizer JS**

```js
(function () {
    "use strict";

    var POLL_INTERVAL_MS = 5000;
    var restUrl = window.ikoehGutenbergQueue.restUrl;
    var nonce = window.ikoehGutenbergQueue.nonce;

    function apiFetch(path, options) {
        options = options || {};
        options.headers = Object.assign({ "X-WP-Nonce": nonce, "Content-Type": "application/json" }, options.headers || {});
        return fetch(restUrl + path, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || "Request failed");
                }
                return body;
            });
        });
    }

    function setStatus(text) {
        var el = document.getElementById("ikoeh-gutenberg-queue-status");
        if (el) {
            el.textContent = text;
        }
    }

    function collectBlockNames(specs) {
        var names = [];
        specs.forEach(function (spec) {
            names.push(spec.name);
            if (spec.innerBlocks && spec.innerBlocks.length) {
                names = names.concat(collectBlockNames(spec.innerBlocks));
            }
        });
        return names;
    }

    function specToBlock(spec) {
        var innerBlocks = (spec.innerBlocks || []).map(specToBlock);
        return wp.blocks.createBlock(spec.name, spec.attributes || {}, innerBlocks);
    }

    function validateAndSerialize(blockSpecs) {
        // wp.blocks.createBlock() internally looks up the block type and
        // throws/dereferences on an unregistered name instead of failing
        // gracefully -- so every name (recursively, through innerBlocks)
        // must be checked with getBlockType() BEFORE any createBlock() call,
        // never after. Checking after was confirmed live to let an unknown
        // block name crash out of the whole polling loop instead of
        // reporting a normal isValid:false failure back to the server.
        var unknownNames = collectBlockNames(blockSpecs).filter(function (name) {
            return !wp.blocks.getBlockType(name);
        });
        if (unknownNames.length > 0) {
            return {
                content: "",
                validations: unknownNames.map(function (name) {
                    return { isValid: false, message: "Unknown block type: " + name };
                }),
            };
        }

        var blocks = blockSpecs.map(specToBlock);
        var content = wp.blocks.serialize(blocks);
        return { content: content, validations: blocks.map(function () { return { isValid: true }; }) };
    }

    function processItem(batchId, leaseOwner) {
        return apiFetch("/gutenberg-claim-item", {
            method: "POST",
            body: JSON.stringify({ batch_id: batchId, lease_owner: leaseOwner }),
        }).then(function (result) {
            // claim_next_item() can return {done:false, batch:...} with no
            // "item" key (e.g. another item in the same batch is still
            // RUNNING under a still-valid lease) -- treat that the same as
            // done for this tick rather than crashing on result.item.block_spec;
            // the outer tick() loop retries on its next poll regardless.
            if (result.done || !result.item) {
                return result;
            }
            var result2 = validateAndSerialize(result.item.block_spec);
            return apiFetch("/gutenberg-complete-item", {
                method: "POST",
                body: JSON.stringify({
                    item_id: result.item.item_id,
                    lease_owner: leaseOwner,
                    content: result2.content,
                    validations: result2.validations,
                }),
            }).then(function (completed) {
                if (completed.done) {
                    return completed;
                }
                return processItem(batchId, leaseOwner);
            });
        });
    }

    function processBatch(batch) {
        setStatus("Processando lote #" + batch.batch_id + ": " + batch.label);
        return apiFetch("/gutenberg-claim-batch?id=" + batch.batch_id, { method: "POST" }).then(function (claimed) {
            return processItem(batch.batch_id, claimed.lease_owner);
        });
    }

    function tick() {
        apiFetch("/gutenberg-heartbeat", { method: "POST" })
            .then(function () {
                return apiFetch("/gutenberg-batches?status=ready");
            })
            .then(function (batches) {
                if (batches.length === 0) {
                    setStatus("Nenhuma mudanca pendente. Aguardando...");
                    return;
                }
                return processBatch(batches[0]);
            })
            .catch(function (error) {
                setStatus("Erro: " + error.message);
            })
            .finally(function () {
                setTimeout(tick, POLL_INTERVAL_MS);
            });
    }

    tick();
})();
```

- [ ] **Step 3: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-gutenberg-admin.php';
```
```php
add_action('admin_menu', ['Ikoeh_Connect_Gutenberg_Admin', 'register_menu']);
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-gutenberg-admin.php plugin/wp-ikoeh-connect.php` and `node --check plugin/assets/gutenberg-queue.js`
Expected: no errors from any of the three.

- [ ] **Step 5: Deploy and verify the page loads**

Deploy, then log into `doctorbeats.com.br/wp-admin` and open Ajustes > iKOEH Fila de Blocos. Confirm the page renders without a fatal error and the status line updates from "Conectando..." to "Nenhuma mudanca pendente. Aguardando..." within ~5 seconds (proves the JS successfully called heartbeat + list-batches). Create a real batch+item+enable-finalization via curl (as in Task 2/3) while the tab is open, and confirm the status line shows "Processando lote #N" and the target page's content actually changes.

- [ ] **Step 6: Commit**

```bash
git add plugin/assets/gutenberg-queue.js plugin/includes/class-ikoeh-gutenberg-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add Fila de Blocos admin page and browser-side block finalizer"
```

---

### Task 5: Cron cleanup

**Files:**
- Modify: `plugin/includes/class-ikoeh-gutenberg-store.php` (add `cleanup()` and the stale-marking helpers)
- Modify: `plugin/wp-ikoeh-connect.php` (schedule the cron hook)

**Interfaces:**
- Consumes: `get_batches`, `get_items`, `set_status`, `clear_lease` from Task 1/3.
- Produces: `Ikoeh_Connect_Gutenberg_Store::cleanup(): void`, WP-Cron hook `ikoeh_gb_cleanup`.

- [ ] **Step 1: Add cleanup methods to the store**

Append inside the class body:

```php
    public static function cleanup() {
        self::mark_stale_drafts();
        self::mark_old_failed_batches_stale();

        $cutoff = time() - self::RETENTION_SECONDS;
        foreach (self::get_batches(self::TERMINAL_STATUSES, -1) as $batch) {
            $updated_at = self::meta_string($batch->ID, self::META_STATUS_UPDATED_AT);
            $updated_ts = $updated_at ? strtotime($updated_at . ' UTC') : 0;
            if ($updated_ts && $updated_ts > $cutoff) {
                continue;
            }
            foreach (self::get_items($batch->ID) as $item) {
                wp_delete_post($item->ID, true);
            }
            wp_delete_post($batch->ID, true);
        }
    }

    private static function mark_stale_drafts() {
        $cutoff = time() - self::DRAFT_STALE_SECONDS;
        foreach (self::get_batches([self::STATUS_DRAFT], -1) as $batch) {
            $created = strtotime($batch->post_date_gmt . ' UTC');
            if ($created > $cutoff) {
                continue;
            }
            self::set_status($batch->ID, self::STATUS_STALE);
            foreach (self::get_items($batch->ID, [self::STATUS_DRAFT]) as $item) {
                self::set_status($item->ID, self::STATUS_STALE);
            }
        }
    }

    private static function mark_old_failed_batches_stale() {
        $cutoff = time() - self::RETENTION_SECONDS;
        foreach (self::get_batches([self::STATUS_FAILED], -1) as $batch) {
            $updated_at = self::meta_string($batch->ID, self::META_STATUS_UPDATED_AT);
            $updated_ts = $updated_at ? strtotime($updated_at . ' UTC') : 0;
            if ($updated_ts && $updated_ts > $cutoff) {
                continue;
            }
            self::set_status($batch->ID, self::STATUS_STALE);
            foreach (self::get_items($batch->ID, self::NON_TERMINAL_STATUSES) as $item) {
                self::set_status($item->ID, self::STATUS_STALE);
            }
        }
    }

    public static function schedule_cleanup() {
        if (false === wp_next_scheduled('ikoeh_gb_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'ikoeh_gb_cleanup');
        }
    }
```

- [ ] **Step 2: Schedule the cron hook**

In `plugin/wp-ikoeh-connect.php`, add:
```php
add_action('init', ['Ikoeh_Connect_Gutenberg_Store', 'schedule_cleanup']);
add_action('ikoeh_gb_cleanup', ['Ikoeh_Connect_Gutenberg_Store', 'cleanup']);
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-gutenberg-store.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Deploy and verify the cron event registers**

Deploy, then:
```bash
source ~/.config/wp-ikoeh-connect/doctorbeats-full.conf
curl -s -X POST "$url/wp-json/ikoeh-connect/v1/database" -H "Authorization: Bearer $token" -H "Content-Type: application/json" \
  -d '{"sql":"SELECT option_value FROM dctrbeats_options WHERE option_name = \"cron\""}'
```
Expected: the serialized cron array includes an `ikoeh_gb_cleanup` key. (If the `/database` route is blocked by a local safety classifier when run from this machine, ask the user to run the equivalent `wp cron event list` via SSH/hosting panel, or confirm indirectly by checking that no fatal error appeared in `GET /logs` after the deploy.)

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-gutenberg-store.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add daily cleanup cron for stale Gutenberg pending changes"
```

---

### Task 6: MCP tools

**Files:**
- Create: `mcp-server/src/tools/gutenberg.js`
- Modify: `mcp-server/src/index.js`

**Interfaces:**
- Consumes: REST routes from Task 2 (`/gutenberg-batch`, `/gutenberg-batches`, `/gutenberg-item`, `/gutenberg-enable-finalization`, `/gutenberg-content`). Does NOT wrap the Task 3 internal routes (claim/complete/heartbeat/runtime) -- those are browser-only per the spec.
- Produces: `registerGutenbergTools(server, client)`, called from `index.js` alongside the other `register*Tools` calls.

- [ ] **Step 1: Write the MCP tool file**

```js
import { z } from "zod";

const blockSpecSchema = z.object({
  name: z.string(),
  attributes: z.record(z.any()).optional(),
  innerBlocks: z.array(z.any()).optional(),
});

export function registerGutenbergTools(server, client) {
  server.registerTool(
    "wp_create_gutenberg_batch",
    {
      title: "Create Gutenberg Pending Batch",
      description:
        "Create a new pending-change batch for Gutenberg (block editor) content. Nothing goes live until wp_enable_gutenberg_finalization is called AND a human keeps the 'Fila de Blocos' wp-admin page open to validate and apply it.",
      inputSchema: { label: z.string().optional(), agent_note: z.string().optional() },
    },
    async ({ label, agent_note }) => {
      const data = await client.request("POST", "/gutenberg-batch", { json: { label, agent_note } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_add_gutenberg_change",
    {
      title: "Add Gutenberg Pending Change",
      description: "Queue a block-content change for one target post/page inside an existing pending batch.",
      inputSchema: {
        batch_id: z.number().int().positive(),
        target_id: z.number().int().positive(),
        target_type: z.string().optional(),
        operation: z.string().optional(),
        block_spec: z.array(blockSpecSchema),
      },
    },
    async ({ batch_id, target_id, target_type, operation, block_spec }) => {
      const data = await client.request("POST", "/gutenberg-item", {
        json: { batch_id, target_id, target_type, operation, block_spec },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_gutenberg_batches",
    {
      title: "List Gutenberg Pending Batches",
      description: "List Gutenberg pending-change batches, optionally filtered by status.",
      inputSchema: { status: z.string().optional() },
    },
    async ({ status }) => {
      const data = await client.request("GET", "/gutenberg-batches", { params: status ? { status } : undefined });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_gutenberg_batch",
    {
      title: "Get Gutenberg Pending Batch",
      description: "Get one Gutenberg pending-change batch and its items.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/gutenberg-batch", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_gutenberg_batch",
    {
      title: "Cancel Gutenberg Pending Batch",
      description: "Cancel a Gutenberg pending-change batch and every non-terminal item in it.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("DELETE", "/gutenberg-batch", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_gutenberg_change",
    {
      title: "Cancel Gutenberg Pending Change",
      description: "Cancel a single pending Gutenberg item.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("DELETE", "/gutenberg-item", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_enable_gutenberg_finalization",
    {
      title: "Enable Gutenberg Batch Finalization",
      description:
        "Mark a Gutenberg pending batch ready for finalization. A human must keep the 'Fila de Blocos' wp-admin page open for it to actually be validated and applied.",
      inputSchema: { batch_id: z.number().int().positive() },
    },
    async ({ batch_id }) => {
      const data = await client.request("POST", "/gutenberg-enable-finalization", { json: { batch_id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_gutenberg_content",
    {
      title: "Get Gutenberg Content",
      description: "Read a post/page's current Gutenberg blocks (parsed, not the pending-change queue).",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/gutenberg-content", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
```

- [ ] **Step 2: Register in index.js**

In `mcp-server/src/index.js`, add the import near the other tool imports:
```js
import { registerGutenbergTools } from "./tools/gutenberg.js";
```
and the registration call near the others:
```js
registerGutenbergTools(server, client);
```

- [ ] **Step 3: Syntax check**

Run: `node --check mcp-server/src/tools/gutenberg.js` and `node --check mcp-server/src/index.js`
Expected: no output (success) for both.

- [ ] **Step 4: Commit**

```bash
git add mcp-server/src/tools/gutenberg.js mcp-server/src/index.js
git commit -m "feat: add Gutenberg pending-batch MCP tools"
```
