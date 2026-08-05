# Chat Site Clone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer paste an external site's URL into the wp-admin Chat iKOEH and have it clone that page into their own site via native Elementor blocks, using a background job that fetches a screenshot of the reference and iteratively compares it against the published result.

**Architecture:** A new CPT (`ikoeh_clone_job`) plus state machine (copied pattern from `Ikoeh_Connect_Gutenberg_Store`) tracks one job per clone request. A WP-Cron tick (every 1 minute) advances the oldest non-terminal job through fetch -> generate -> publish -> compare -> refine, calling the Anthropic Messages API itself with a small set of tools (create_page, write_page_content, upload_media, list_pages) that wrap already-existing, already-tested REST logic via direct in-process PHP calls (no HTTP round-trip). The interactive chat gains exactly one new tool, `start_site_clone`, used only to create the job row and return immediately; the heavy multi-minute work never blocks a chat request. A new snapshot/undo mechanism protects every write the clone tools make.

**Tech Stack:** PHP (WordPress plugin, no new Composer/npm dependency), `DOMDocument` (PHP core) for HTML parsing, `wp_remote_get`/`wp_remote_post` for all HTTP, WP-Cron for background processing, vanilla JS (no framework) for the chat UI polling addition.

## Global Constraints

- No new PHP or JS dependency may be added (matches every other feature in this plugin: `wp_remote_get`/`wp_remote_post`, `DOMDocument`, core WP APIs only).
- Every postmeta write containing arbitrary string/JSON content must go through `wp_slash()` before `update_post_meta()`/`wp_insert_post()`/`wp_update_post()` (WordPress core calls `wp_unslash()` internally on those functions; skipping this silently corrupts real backslashes in stored JSON, a bug this project hit twice already).
- Every Elementor content write must clear all three cache keys together: `_elementor_css`, `_elementor_page_assets`, `_elementor_element_cache` (clearing only some leaves the page serving stale rendered HTML).
- Max 4 iterations per clone job (`MAX_ITERATIONS = 4`), enforced mechanically by iteration count, never by interpreting the model's text.
- Permanently forbidden as chat tools, in this feature and forever: plugin install/activate/deactivate, theme switching, `/database`, any system/file route, execute-php, wp-cli, delete-page. None of these may ever be added to any tool list this plan builds.
- All new code is session-authenticated (`current_user_can('manage_options')`), consistent with the rest of the Chat iKOEH feature. No new Bearer-token scope is introduced.
- Sem travessão (—) em nenhum texto novo (comentários, strings, admin UI), consistente com o resto do projeto.

---

### Task 1: Clone job CPT and state machine

**Files:**
- Create: `plugin/includes/class-ikoeh-clone-store.php`
- Modify: `plugin/wp-ikoeh-connect.php` (require + `register_post_type` on `init` + cron schedule/unschedule)

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `Ikoeh_Connect_Clone_Store::create_job($source_url, $created_by)` returns int job ID or `WP_Error`. `::status($job_id)` returns string. `::set_status($job_id, $status)`. `::get_job($job_id)` returns `WP_Post|null`. `::shape_job(WP_Post $job)` returns assoc array `['job_id', 'source_url', 'target_post_id', 'status', 'iteration', 'max_iterations', 'created_at', 'error_message', 'log']`. `::append_log($job_id, $step, $note, $reference_screenshot_url = '', $result_screenshot_url = '')`. `::set_target_post($job_id, $post_id)`. `::increment_iteration($job_id)`. `::atomic_status_transition($job_id, array $from_statuses, $to_status)` (copy of the `add_option()` mutex pattern from `Ikoeh_Connect_Gutenberg_Store::atomic_status_transition()`, own lock option prefix `ikoeh_clone_lock_`). `::get_oldest_non_terminal_job()` returns `WP_Post|null`. Status constants: `STATUS_QUEUED`, `STATUS_FETCHING`, `STATUS_GENERATING`, `STATUS_PUBLISHING`, `STATUS_COMPARING`, `STATUS_REFINING`, `STATUS_DONE`, `STATUS_PARTIAL`, `STATUS_FAILED`. `TERMINAL_STATUSES = [STATUS_DONE, STATUS_PARTIAL, STATUS_FAILED]`.

- [ ] **Step 1: Write the CPT + state machine class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage and state machine for chat-driven site-clone jobs. One post per
 * job. A WP-Cron tick (Ikoeh_Connect_Chat_Clone_Runner, Task 5) advances a
 * job through the states below; this class owns only data and transitions.
 */
class Ikoeh_Connect_Clone_Store {

    const POST_TYPE = 'ikoeh_clone_job';

    const META_SOURCE_URL = '_ikoeh_clone_source_url';
    const META_TARGET_POST_ID = '_ikoeh_clone_target_post_id';
    const META_STATUS = '_ikoeh_clone_status';
    const META_ITERATION = '_ikoeh_clone_iteration';
    const META_ERROR_MESSAGE = '_ikoeh_clone_error_message';
    const META_LOG = '_ikoeh_clone_log';
    const META_CREATED_BY = '_ikoeh_clone_created_by';

    const STATUS_QUEUED = 'queued';
    const STATUS_FETCHING = 'fetching';
    const STATUS_GENERATING = 'generating';
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_COMPARING = 'comparing';
    const STATUS_REFINING = 'refining';
    const STATUS_DONE = 'done';
    const STATUS_PARTIAL = 'partial';
    const STATUS_FAILED = 'failed';

    const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_PARTIAL, self::STATUS_FAILED];
    const MAX_ITERATIONS = 4;

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'Chat site-clone jobs',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    private static function meta_string($job_id, $key) {
        $value = get_post_meta($job_id, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    private static function meta_int($job_id, $key) {
        $value = get_post_meta($job_id, $key, true);
        return is_scalar($value) ? (int) $value : 0;
    }

    public static function status($job_id) {
        $status = self::meta_string($job_id, self::META_STATUS);
        return '' !== $status ? $status : self::STATUS_QUEUED;
    }

    public static function set_status($job_id, $status) {
        update_post_meta($job_id, self::META_STATUS, $status);
    }

    /**
     * Atomic compare-and-swap using add_option() as a mutex, same pattern as
     * Ikoeh_Connect_Gutenberg_Store::atomic_status_transition() -- add_option()
     * fails atomically at the DB layer if the row already exists, so two
     * concurrent cron ticks can never both claim the same job.
     */
    public static function atomic_status_transition($job_id, array $from_statuses, $to_status) {
        if (empty($from_statuses)) {
            return false;
        }

        $lock_option = 'ikoeh_clone_lock_' . $job_id;
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
            if (!in_array(self::status($job_id), $from_statuses, true)) {
                return false;
            }
            self::set_status($job_id, $to_status);
            return true;
        } finally {
            $current = get_option($lock_option);
            if (is_string($current) && 0 === strpos($current, $lock_owner . '|')) {
                delete_option($lock_option);
            }
        }
    }

    public static function create_job($source_url, $created_by) {
        $result = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => wp_slash('Clone: ' . $source_url),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        $job_id = (int) $result;
        update_post_meta($job_id, self::META_SOURCE_URL, wp_slash($source_url));
        update_post_meta($job_id, self::META_CREATED_BY, (int) $created_by);
        update_post_meta($job_id, self::META_ITERATION, 0);
        update_post_meta($job_id, self::META_LOG, []);
        self::set_status($job_id, self::STATUS_QUEUED);

        return $job_id;
    }

    public static function get_job($job_id) {
        $post = get_post($job_id);
        if (!$post || self::POST_TYPE !== $post->post_type) {
            return null;
        }
        return $post;
    }

    public static function set_target_post($job_id, $post_id) {
        update_post_meta($job_id, self::META_TARGET_POST_ID, (int) $post_id);
    }

    public static function target_post_id($job_id) {
        return self::meta_int($job_id, self::META_TARGET_POST_ID);
    }

    public static function increment_iteration($job_id) {
        $current = self::meta_int($job_id, self::META_ITERATION);
        update_post_meta($job_id, self::META_ITERATION, $current + 1);
        return $current + 1;
    }

    public static function iteration($job_id) {
        return self::meta_int($job_id, self::META_ITERATION);
    }

    public static function set_error($job_id, $message) {
        update_post_meta($job_id, self::META_ERROR_MESSAGE, wp_slash($message));
    }

    public static function append_log($job_id, $step, $note, $reference_screenshot_url = '', $result_screenshot_url = '') {
        $log = get_post_meta($job_id, self::META_LOG, true);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'timestamp' => time(),
            'step' => $step,
            'note' => $note,
            'reference_screenshot_url' => $reference_screenshot_url,
            'result_screenshot_url' => $result_screenshot_url,
        ];
        update_post_meta($job_id, self::META_LOG, wp_slash($log));
    }

    public static function log($job_id) {
        $log = get_post_meta($job_id, self::META_LOG, true);
        return is_array($log) ? $log : [];
    }

    public static function shape_job(WP_Post $job) {
        return [
            'job_id' => $job->ID,
            'source_url' => self::meta_string($job->ID, self::META_SOURCE_URL),
            'target_post_id' => self::target_post_id($job->ID) ?: null,
            'status' => self::status($job->ID),
            'iteration' => self::iteration($job->ID),
            'max_iterations' => self::MAX_ITERATIONS,
            'created_at' => $job->post_date_gmt,
            'error_message' => self::meta_string($job->ID, self::META_ERROR_MESSAGE),
            'log' => self::log($job->ID),
        ];
    }

    /** Oldest job not yet in a terminal status, or null if none is pending. */
    public static function get_oldest_non_terminal_job() {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 50,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($posts as $post) {
            if (!in_array(self::status($post->ID), self::TERMINAL_STATUSES, true)) {
                return $post;
            }
        }
        return null;
    }

    public static function schedule_tick() {
        if (false === wp_next_scheduled('ikoeh_clone_job_tick')) {
            wp_schedule_event(time() + 60, 'ikoeh_clone_minute', 'ikoeh_clone_job_tick');
        }
    }

    public static function unschedule_tick() {
        wp_clear_scheduled_hook('ikoeh_clone_job_tick');
    }

    public static function register_cron_interval($schedules) {
        $schedules['ikoeh_clone_minute'] = ['interval' => 60, 'display' => 'Every minute (iKOEH clone jobs)'];
        return $schedules;
    }
}
```

- [ ] **Step 2: Wire into the plugin bootstrap**

In `plugin/wp-ikoeh-connect.php`, add near the other class requires:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-clone-store.php';
```
Add near the other `add_action('init', ...)` calls:
```php
add_action('init', ['Ikoeh_Connect_Clone_Store', 'register_post_type']);
add_action('init', ['Ikoeh_Connect_Clone_Store', 'schedule_tick']);
```
Add near any existing `add_filter('cron_schedules', ...)` (there is none yet, add a new one):
```php
add_filter('cron_schedules', ['Ikoeh_Connect_Clone_Store', 'register_cron_interval']);
```
Add near `register_deactivation_hook`:
```php
register_deactivation_hook(__FILE__, ['Ikoeh_Connect_Clone_Store', 'unschedule_tick']);
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-clone-store.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Runnable harness proving the state machine**

Since this class has no WP-independent pure-logic seam wide enough for a standalone script (every method touches `get_post_meta`/`update_post_meta`), verification for this task happens live after deploy in Task 7. For now, confirm via a static read-through that every status constant used in `atomic_status_transition` calls elsewhere in this file matches one of the 9 declared constants (manual check, no tooling needed): `queued`, `fetching`, `generating`, `publishing`, `comparing`, `refining`, `done`, `partial`, `failed`. Confirmed above.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-clone-store.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add clone job CPT and state machine"
```

---

### Task 2: Site Inspector (external HTML + screenshot fetch)

**Files:**
- Create: `plugin/includes/class-ikoeh-site-inspector.php`
- Modify: `plugin/includes/class-ikoeh-admin.php` (add a settings field for the screenshot API key, same pattern as the existing chat API key field)

**Interfaces:**
- Consumes: nothing new (uses core WP HTTP API only).
- Produces: `Ikoeh_Connect_Site_Inspector::fetch_html_summary($url)` returns `string|WP_Error` (a compact text summary: headings + visible text + image URLs, NOT raw HTML). `::fetch_screenshot_bytes($url)` returns `string|WP_Error` (raw image bytes). Option constant `Ikoeh_Connect_Site_Inspector::OPTION_SCREENSHOT_API_KEY = 'ikoeh_chat_screenshot_api_key'`.

- [ ] **Step 1: Write the Site Inspector class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches a compact text summary and a screenshot of any URL (the external
 * reference site being cloned, or the customer's own freshly-published page,
 * for comparison). v1 supports a single screenshot vendor, urlbox.io, using
 * a per-site API key the customer configures themselves (same write-only,
 * masked pattern as the existing Anthropic key).
 */
class Ikoeh_Connect_Site_Inspector {

    const OPTION_SCREENSHOT_API_KEY = 'ikoeh_chat_screenshot_api_key';
    const URLBOX_API_URL = 'https://api.urlbox.io/v1/render';
    const MAX_SUMMARY_CHARS = 6000;

    /**
     * Compact text summary, not raw HTML: a full page dump can easily be
     * tens of thousands of tokens, most of it scripts/styles/nav noise the
     * model does not need to clone a page's content and structure.
     */
    public static function fetch_html_summary($url) {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ikoeh_connect_fetch_failed', "Failed to fetch {$url} (HTTP {$code}).", ['status' => 502]);
        }

        $html = wp_remote_retrieve_body($response);
        if ('' === trim($html)) {
            return new WP_Error('ikoeh_connect_empty_response', "Fetched {$url} but the response body was empty.", ['status' => 502]);
        }

        return self::summarize_html($html);
    }

    private static function summarize_html($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach (['script', 'style', 'noscript'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode->removeChild($node);
            }
        }

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $text = trim($node->textContent);
                if ('' !== $text) {
                    $headings[] = strtoupper($tag) . ': ' . $text;
                }
            }
        }

        $images = [];
        foreach ($doc->getElementsByTagName('img') as $node) {
            $src = $node->getAttribute('src');
            if ('' !== $src) {
                $images[] = $src;
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $body_text = $body ? trim(preg_replace('/\s+/', ' ', $body->textContent)) : '';

        $summary = "HEADINGS:\n" . implode("\n", array_slice($headings, 0, 40))
            . "\n\nIMAGES:\n" . implode("\n", array_slice($images, 0, 30))
            . "\n\nBODY TEXT:\n" . $body_text;

        return mb_substr($summary, 0, self::MAX_SUMMARY_CHARS);
    }

    public static function fetch_screenshot_bytes($url) {
        $api_key = get_option(self::OPTION_SCREENSHOT_API_KEY, '');
        if ('' === $api_key) {
            return new WP_Error('ikoeh_connect_no_screenshot_key', 'Configure uma chave de API de screenshot (urlbox.io) primeiro.', ['status' => 400]);
        }

        $endpoint = add_query_arg([
            'url' => $url,
            'format' => 'png',
            'full_page' => 'true',
        ], self::URLBOX_API_URL);

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new WP_Error('ikoeh_connect_screenshot_failed', "Screenshot service returned HTTP {$code} for {$url}.", ['status' => 502]);
        }

        return wp_remote_retrieve_body($response);
    }
}
```

- [ ] **Step 2: Add the settings field**

In `plugin/includes/class-ikoeh-admin.php`, find the existing chat settings block (the one handling `$_POST['ikoeh_chat_save_settings']`) and add, right after the existing API key handling:
```php
$new_screenshot_key = isset($_POST['ikoeh_chat_screenshot_api_key']) ? trim(wp_unslash($_POST['ikoeh_chat_screenshot_api_key'])) : '';
if ('' !== $new_screenshot_key) {
    update_option(Ikoeh_Connect_Site_Inspector::OPTION_SCREENSHOT_API_KEY, $new_screenshot_key);
}
```
And in the settings form HTML (right after the existing API key `<input type="password" name="ikoeh_chat_api_key" ...>` row), add a matching row:
```php
<tr>
    <th scope="row"><label for="ikoeh-chat-screenshot-key">Chave de API de screenshot (urlbox.io)</label></th>
    <td><input type="password" name="ikoeh_chat_screenshot_api_key" id="ikoeh-chat-screenshot-key" placeholder="Deixe em branco para manter a atual" style="width:400px;" autocomplete="off"></td>
</tr>
```
Add `require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-site-inspector.php';` to `plugin/wp-ikoeh-connect.php` near the other requires (must load before `class-ikoeh-admin.php` is used, so place it above the chat requires).

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/class-ikoeh-site-inspector.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 4: Runnable harness for summarize_html()**

`summarize_html()` is pure (no WP DB calls, only `DOMDocument` + string functions), so it can be exercised directly with plain `php` CLI. Create a throwaway script (do not commit it) at `/tmp/test-summarize.php`:
```php
<?php
define('ABSPATH', '/tmp/');
require '/absolute/path/to/plugin/includes/class-ikoeh-site-inspector.php';

$html = '<html><body><script>alert(1)</script><h1>Title</h1><p>Hello world</p><img src="https://example.com/a.png"></body></html>';
$reflection = new ReflectionClass('Ikoeh_Connect_Site_Inspector');
$method = $reflection->getMethod('summarize_html');
$method->setAccessible(true);
$result = $method->invoke(null, $html);
echo $result . "\n";
assert(strpos($result, 'H1: Title') !== false, 'heading not extracted');
assert(strpos($result, 'alert(1)') === false, 'script content leaked into summary');
assert(strpos($result, 'https://example.com/a.png') !== false, 'image url not extracted');
echo "ALL ASSERTIONS PASSED\n";
```
Run: `php /tmp/test-summarize.php`
Expected: `ALL ASSERTIONS PASSED`.

Delete `/tmp/test-summarize.php` after confirming (throwaway, not part of the repo).

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-site-inspector.php plugin/includes/class-ikoeh-admin.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add Site Inspector for external HTML summary and screenshots"
```

---

### Task 3: Snapshot and undo mechanism

**Files:**
- Create: `plugin/includes/class-ikoeh-chat-snapshots.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Ikoeh_Connect_Chat_Snapshots::save_snapshot($post_id)` (void, pushes current state onto history, caps at 5). `::undo_last_change($post_id)` returns `true|WP_Error` (pops last entry, restores it, clears caches). `::has_history($post_id)` returns bool (used by the UI to decide whether to show the undo button).

- [ ] **Step 1: Write the snapshot class**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable undo mechanism for any chat-tool write to a page's Elementor
 * content, not specific to site-cloning. Every write pushes the PREVIOUS
 * state onto a capped history before overwriting; undo pops the most recent
 * entry and restores it, clearing the same three Elementor cache keys that
 * must always be cleared together (a lesson from this project's own bug
 * history: clearing only some of them leaves stale rendered HTML).
 */
class Ikoeh_Connect_Chat_Snapshots {

    const META_HISTORY = '_ikoeh_chat_snapshot_history';
    const MAX_SNAPSHOTS = 5;

    public static function save_snapshot($post_id) {
        $history = get_post_meta($post_id, self::META_HISTORY, true);
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'timestamp' => time(),
            'elementor_data' => get_post_meta($post_id, '_elementor_data', true),
            'elementor_css' => get_post_meta($post_id, '_elementor_css', true),
            'elementor_page_assets' => get_post_meta($post_id, '_elementor_page_assets', true),
        ];

        if (count($history) > self::MAX_SNAPSHOTS) {
            $history = array_slice($history, -self::MAX_SNAPSHOTS);
        }

        update_post_meta($post_id, self::META_HISTORY, wp_slash($history));
    }

    public static function has_history($post_id) {
        $history = get_post_meta($post_id, self::META_HISTORY, true);
        return is_array($history) && count($history) > 0;
    }

    public static function undo_last_change($post_id) {
        $history = get_post_meta($post_id, self::META_HISTORY, true);
        if (!is_array($history) || empty($history)) {
            return new WP_Error('ikoeh_connect_no_history', 'No previous version to restore for this page.', ['status' => 404]);
        }

        $last = array_pop($history);
        update_post_meta($post_id, self::META_HISTORY, wp_slash($history));

        update_post_meta($post_id, '_elementor_data', wp_slash((string) $last['elementor_data']));
        update_post_meta($post_id, '_elementor_css', wp_slash((string) $last['elementor_css']));
        update_post_meta($post_id, '_elementor_page_assets', wp_slash((string) $last['elementor_page_assets']));
        delete_post_meta($post_id, '_elementor_element_cache');

        return true;
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l plugin/includes/class-ikoeh-chat-snapshots.php`
Expected: `No syntax errors detected`.

Add `require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-snapshots.php';` to `plugin/wp-ikoeh-connect.php` near the other requires, then re-run:
Run: `php -l plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Runnable harness**

This class only touches `get_post_meta`/`update_post_meta`, which need a live WP database to exercise meaningfully. Verification happens live in Task 7 (write content, call `save_snapshot`, overwrite again, call `undo_last_change`, confirm the post's `_elementor_data` matches the first write, not the second).

- [ ] **Step 4: Commit**

```bash
git add plugin/includes/class-ikoeh-chat-snapshots.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add reusable snapshot/undo mechanism for chat-tool page writes"
```

---

### Task 4: Refactor existing write logic into reusable internal methods

**Files:**
- Modify: `plugin/includes/rest/class-ikoeh-rest-elementor.php`
- Modify: `plugin/includes/rest/class-ikoeh-rest-media.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Ikoeh_Connect_Rest_Elementor::write_content($post_id, array $elements)` returns `true|WP_Error` (public static, extracted from `update_content()`'s body). `Ikoeh_Connect_Rest_Media::sideload_bytes($filename, $bytes)` returns `int|WP_Error` (attachment ID, public static, extracted from `upload()`'s body).

This task exists so Task 5's chat tools call the EXACT SAME validated write logic the REST API already uses (normalization, `wp_slash()`, three-key cache clearing, `media_handle_sideload()` mime validation) instead of duplicating it -- duplicating this logic is exactly the kind of divergence that caused real bugs earlier in this project (the `wp_slash()` gaps, the cache-clearing gaps).

- [ ] **Step 1: Extract `write_content()` in the Elementor REST class**

In `plugin/includes/rest/class-ikoeh-rest-elementor.php`, replace the body of `update_content()` (everything from `$normalized = self::normalize_elements(...)` through the `return new WP_REST_Response(['updated' => $id], 200);` line) so that `update_content()` becomes a thin wrapper:

```php
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

        $result = self::write_content($id, $params['elements']);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['updated' => $id], 200);
    }

    /**
     * Shared write path for both the REST route above and the chat-tool
     * clone feature: normalizes the element tree, wp_slash()es it before
     * update_post_meta() (WordPress core runs wp_unslash() internally, so
     * real backslashes in the encoded JSON get silently stripped without
     * this), and clears all three Elementor render-cache keys together.
     */
    public static function write_content($post_id, array $elements) {
        if (!get_post($post_id)) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $normalized = self::normalize_elements($elements);
        $encoded = wp_json_encode($normalized);

        update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        delete_post_meta($post_id, '_elementor_css');
        delete_post_meta($post_id, '_elementor_page_assets');
        delete_post_meta($post_id, '_elementor_element_cache');

        return true;
    }
```

Change `private static function normalize_elements` to `public static function normalize_elements` (Task 5's chat tools do not need to call it directly since `write_content()` already does, but keeping it public matches the class's other now-public helper and avoids a second visibility change later if ever needed).

- [ ] **Step 2: Extract `sideload_bytes()` in the Media REST class**

In `plugin/includes/rest/class-ikoeh-rest-media.php`, replace `upload()`'s body so it becomes a thin wrapper:

```php
    public static function upload(WP_REST_Request $request) {
        $filename = $request->get_header('x-filename');
        if (empty($filename)) {
            return new WP_Error('ikoeh_connect_invalid_request', 'Missing X-Filename header.', ['status' => 400]);
        }

        $body = $request->get_body();
        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'No image bytes provided.', ['status' => 400]);
        }

        $attachment_id = self::sideload_bytes($filename, $body);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        return new WP_REST_Response([
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ], 201);
    }

    /**
     * Shared sideload path for both the REST route above and the chat-tool
     * clone feature (which downloads a reference-site image over HTTP first,
     * then calls this with the downloaded bytes).
     */
    public static function sideload_bytes($filename, $bytes) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = wp_tempnam(sanitize_file_name($filename));
        file_put_contents($tmp, $bytes);

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

        return $attachment_id;
    }
```

- [ ] **Step 3: Lint**

Run: `php -l plugin/includes/rest/class-ikoeh-rest-elementor.php plugin/includes/rest/class-ikoeh-rest-media.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Deploy and verify the existing REST routes still work unchanged**

```bash
node /private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs
```
Then, using the `doctorbeats-full` connection token, PUT to `/elementor-content?id=<any existing Elementor page id>` with a small valid `elements` array and confirm `200 {"updated": <id>}`, and POST an image to `/media` with an `X-Filename` header and confirm `201` with an `id`/`url`. These are the exact same routes as before this refactor; a passing result here confirms the extraction did not change external behavior.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/rest/class-ikoeh-rest-elementor.php plugin/includes/rest/class-ikoeh-rest-media.php
git commit -m "refactor: extract reusable write_content/sideload_bytes for chat-tool reuse"
```

---

### Task 5: Chat tools and the Anthropic tool-calling loop

**Files:**
- Create: `plugin/includes/class-ikoeh-chat-tools.php`
- Create: `plugin/includes/class-ikoeh-chat-clone-runner.php`
- Modify: `plugin/includes/class-ikoeh-chat.php`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Clone_Store` (Task 1), `Ikoeh_Connect_Site_Inspector` (Task 2), `Ikoeh_Connect_Chat_Snapshots` (Task 3), `Ikoeh_Connect_Rest_Elementor::write_content()`/`::normalize_elements()`, `Ikoeh_Connect_Rest_Posts` list/create logic, `Ikoeh_Connect_Rest_Media::sideload_bytes()` (Task 4).
- Produces: `Ikoeh_Connect_Chat_Tools::QUICK_TOOL_DEFINITIONS` (array, one tool: `start_site_clone`), `::CLONE_TOOL_DEFINITIONS` (array, four tools: `create_page`, `write_page_content`, `upload_media`, `list_pages`). `::execute_quick_tool($name, $input, $created_by)` returns array (tool_result content). `::execute_clone_tool($name, $input, $job_id)` returns array. `Ikoeh_Connect_Chat_Clone_Runner::run_tick()` (called by the `ikoeh_clone_job_tick` cron hook, no return value used).

- [ ] **Step 1: Write the tool definitions and dispatcher**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool definitions and dispatch for the chat's two tool-calling surfaces:
 * QUICK_TOOL_DEFINITIONS (exposed on every interactive chat turn, currently
 * just start_site_clone, which only creates a job row and returns fast) and
 * CLONE_TOOL_DEFINITIONS (exposed only inside the background cron loop that
 * actually builds a page, never in the interactive chat request). Nothing
 * else is ever exposed: no plugin/theme/database/system/eval/wp-cli/delete
 * tool exists anywhere in this class, by design, permanently.
 */
class Ikoeh_Connect_Chat_Tools {

    const QUICK_TOOL_DEFINITIONS = [
        [
            'name' => 'start_site_clone',
            'description' => 'Start cloning an external site\'s page into a new page on this site. This only queues the job and returns immediately -- the actual cloning happens in the background over the next few minutes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The full URL of the page to clone.'],
                ],
                'required' => ['url'],
            ],
        ],
    ];

    const CLONE_TOOL_DEFINITIONS = [
        [
            'name' => 'create_page',
            'description' => 'Create a new WordPress page (empty content, to be filled in by write_page_content next). Returns the new page id.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
        ],
        [
            'name' => 'write_page_content',
            'description' => 'Write the full Elementor element tree for a page. Overwrites any existing content for that page. Every call is automatically reversible (undo_last_change tool exists separately for the site owner, not for you).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'elements' => ['type' => 'array', 'description' => 'Elementor element tree, same shape as the /elementor-content PUT body.'],
                ],
                'required' => ['post_id', 'elements'],
            ],
        ],
        [
            'name' => 'upload_media',
            'description' => 'Download an image from a URL (e.g. from the reference site being cloned) and upload it to this site\'s own media library. Never hotlink external images -- always call this first and use the returned url in write_page_content.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'image_url' => ['type' => 'string'],
                ],
                'required' => ['image_url'],
            ],
        ],
        [
            'name' => 'list_pages',
            'description' => 'List existing pages on this site, for context (e.g. to avoid duplicate titles).',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ],
    ];

    public static function execute_quick_tool($name, $input, $created_by) {
        if ('start_site_clone' !== $name) {
            return ['error' => "Unknown tool: {$name}"];
        }

        $url = isset($input['url']) ? esc_url_raw($input['url']) : '';
        if ('' === $url) {
            return ['error' => 'Missing or invalid url.'];
        }

        $job_id = Ikoeh_Connect_Clone_Store::create_job($url, $created_by);
        if (is_wp_error($job_id)) {
            return ['error' => $job_id->get_error_message()];
        }

        return ['job_id' => $job_id, 'status' => 'queued'];
    }

    public static function execute_clone_tool($name, $input, $job_id) {
        switch ($name) {
            case 'create_page':
                return self::tool_create_page($input, $job_id);
            case 'write_page_content':
                return self::tool_write_page_content($input);
            case 'upload_media':
                return self::tool_upload_media($input);
            case 'list_pages':
                return self::tool_list_pages();
            default:
                return ['error' => "Unknown tool: {$name}"];
        }
    }

    private static function tool_create_page($input, $job_id) {
        $title = isset($input['title']) ? sanitize_text_field($input['title']) : '';
        if ('' === $title) {
            return ['error' => 'Missing title.'];
        }

        $post_id = wp_insert_post([
            'post_title' => wp_slash($title),
            'post_type' => 'page',
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($post_id)) {
            return ['error' => $post_id->get_error_message()];
        }

        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        Ikoeh_Connect_Clone_Store::set_target_post($job_id, $post_id);

        return ['post_id' => $post_id, 'edit_url' => get_edit_post_link($post_id, 'raw'), 'view_url' => get_permalink($post_id)];
    }

    private static function tool_write_page_content($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        $elements = isset($input['elements']) && is_array($input['elements']) ? $input['elements'] : null;

        if (!$post_id || null === $elements) {
            return ['error' => 'Missing post_id or elements.'];
        }

        Ikoeh_Connect_Chat_Snapshots::save_snapshot($post_id);

        $result = Ikoeh_Connect_Rest_Elementor::write_content($post_id, $elements);
        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message()];
        }

        return ['written' => true, 'post_id' => $post_id];
    }

    private static function tool_upload_media($input) {
        $image_url = isset($input['image_url']) ? esc_url_raw($input['image_url']) : '';
        if ('' === $image_url) {
            return ['error' => 'Missing image_url.'];
        }

        $response = wp_remote_get($image_url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => "Failed to download {$image_url} (HTTP {$code})."];
        }

        $bytes = wp_remote_retrieve_body($response);
        $filename = basename(parse_url($image_url, PHP_URL_PATH)) ?: 'image.jpg';

        $attachment_id = Ikoeh_Connect_Rest_Media::sideload_bytes($filename, $bytes);
        if (is_wp_error($attachment_id)) {
            return ['error' => $attachment_id->get_error_message()];
        }

        return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
    }

    private static function tool_list_pages() {
        $query = new WP_Query(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 50]);
        $pages = [];
        foreach ($query->posts as $post) {
            $pages[] = ['id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink($post->ID)];
        }
        return ['pages' => $pages];
    }
}
```

- [ ] **Step 2: Wire `start_site_clone` into the interactive chat turn**

In `plugin/includes/class-ikoeh-chat.php`, modify `ajax_send()`. Find this block:
```php
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
```
Replace with:
```php
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
                'tools' => Ikoeh_Connect_Chat_Tools::QUICK_TOOL_DEFINITIONS,
            ]),
        ]);
```

Then find where `$reply_text` is built from `$body['content']` and replace that whole block:
```php
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
```
with:
```php
        $reply_text = '';
        $tool_use_block = null;
        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (isset($block['type']) && 'text' === $block['type'] && isset($block['text'])) {
                    $reply_text .= $block['text'];
                } elseif (isset($block['type']) && 'tool_use' === $block['type']) {
                    $tool_use_block = $block;
                }
            }
        }

        if (null !== $tool_use_block) {
            $tool_result = Ikoeh_Connect_Chat_Tools::execute_quick_tool(
                $tool_use_block['name'],
                is_array($tool_use_block['input'] ?? null) ? $tool_use_block['input'] : [],
                get_current_user_id()
            );

            $follow_up_messages = array_merge($api_messages, [
                ['role' => 'assistant', 'content' => $body['content']],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $tool_use_block['id'],
                    'content' => wp_json_encode($tool_result),
                ]]],
            ]);

            $follow_up = wp_remote_post(self::ANTHROPIC_API_URL, [
                'timeout' => 60,
                'headers' => [
                    'x-api-key' => $api_key,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages' => $follow_up_messages,
                ]),
            ]);

            if (!is_wp_error($follow_up)) {
                $follow_up_body = json_decode(wp_remote_retrieve_body($follow_up), true);
                if (is_array($follow_up_body) && isset($follow_up_body['content']) && is_array($follow_up_body['content'])) {
                    foreach ($follow_up_body['content'] as $block) {
                        if (isset($block['type'], $block['text']) && 'text' === $block['type']) {
                            $reply_text .= $block['text'];
                        }
                    }
                }
            }
        }

        if ('' === $reply_text) {
            $reply_text = '(resposta vazia)';
        }
```

- [ ] **Step 3: Write the cron-tick runner**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advances one clone job per cron tick through its state machine, making
 * its own calls to the Anthropic Messages API (same model/key the customer
 * already configured for chat) with CLONE_TOOL_DEFINITIONS. Runs entirely
 * outside any single HTTP request, so there is no PHP execution time limit
 * concern even though a full clone can take several minutes end to end.
 */
class Ikoeh_Connect_Chat_Clone_Runner {

    const MAX_TOKENS = 4096;

    public static function run_tick() {
        $job = Ikoeh_Connect_Clone_Store::get_oldest_non_terminal_job();
        if (!$job) {
            return;
        }

        $job_id = $job->ID;
        $status = Ikoeh_Connect_Clone_Store::status($job_id);

        try {
            switch ($status) {
                case Ikoeh_Connect_Clone_Store::STATUS_QUEUED:
                    self::step_fetch($job_id);
                    break;
                case Ikoeh_Connect_Clone_Store::STATUS_FETCHING:
                case Ikoeh_Connect_Clone_Store::STATUS_GENERATING:
                case Ikoeh_Connect_Clone_Store::STATUS_REFINING:
                    self::step_generate_or_refine($job_id);
                    break;
                case Ikoeh_Connect_Clone_Store::STATUS_PUBLISHING:
                case Ikoeh_Connect_Clone_Store::STATUS_COMPARING:
                    self::step_compare($job_id);
                    break;
            }
        } catch (Exception $e) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $e->getMessage());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $e->getMessage());
        }
    }

    private static function step_fetch($job_id) {
        if (!Ikoeh_Connect_Clone_Store::atomic_status_transition(
            $job_id, [Ikoeh_Connect_Clone_Store::STATUS_QUEUED], Ikoeh_Connect_Clone_Store::STATUS_FETCHING
        )) {
            return;
        }

        $source_url = get_post_meta($job_id, Ikoeh_Connect_Clone_Store::META_SOURCE_URL, true);
        $summary = Ikoeh_Connect_Site_Inspector::fetch_html_summary($source_url);
        if (is_wp_error($summary)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $summary->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui acessar o site de referencia: " . $summary->get_error_message());
            return;
        }

        $screenshot = Ikoeh_Connect_Site_Inspector::fetch_screenshot_bytes($source_url);
        if (is_wp_error($screenshot)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $screenshot->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui tirar um print do site de referencia: " . $screenshot->get_error_message());
            return;
        }

        update_post_meta($job_id, '_ikoeh_clone_reference_summary', wp_slash($summary));
        update_post_meta($job_id, '_ikoeh_clone_reference_screenshot_b64', base64_encode($screenshot));

        Ikoeh_Connect_Clone_Store::append_log($job_id, 'fetching', 'Site de referencia analisado.');
        Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_GENERATING);
    }

    /** Shared loop body for both the first generation and every refine round. */
    private static function step_generate_or_refine($job_id) {
        $messages = self::build_conversation($job_id);
        $result = self::run_tool_loop($job_id, $messages);

        if (is_wp_error($result)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result->get_error_message());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $result->get_error_message());
            return;
        }

        Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_PUBLISHING);
    }

    private static function step_compare($job_id) {
        $target_post_id = Ikoeh_Connect_Clone_Store::target_post_id($job_id);
        if (!$target_post_id) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, 'No page was created.');
            self::post_final_chat_message($job_id, "A clonagem falhou: nenhuma pagina foi criada.");
            return;
        }

        $result_url = get_permalink($target_post_id);
        $result_screenshot = Ikoeh_Connect_Site_Inspector::fetch_screenshot_bytes($result_url);
        if (is_wp_error($result_screenshot)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result_screenshot->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui tirar um print do resultado publicado: " . $result_screenshot->get_error_message());
            return;
        }

        update_post_meta($job_id, '_ikoeh_clone_result_screenshot_b64', base64_encode($result_screenshot));
        Ikoeh_Connect_Clone_Store::append_log($job_id, 'comparing', 'Resultado publicado comparado com a referencia.');

        $iteration = Ikoeh_Connect_Clone_Store::iteration($job_id);
        if ($iteration >= Ikoeh_Connect_Clone_Store::MAX_ITERATIONS) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_PARTIAL);
            self::post_final_chat_message($job_id, "Cheguei ao limite de " . Ikoeh_Connect_Clone_Store::MAX_ITERATIONS . " ajustes. A pagina esta em {$result_url}, recomendo uma revisao manual dos detalhes finais.");
            return;
        }

        $messages = self::build_conversation($job_id, true);
        $result = self::run_tool_loop($job_id, $messages);

        if (is_wp_error($result)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result->get_error_message());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $result->get_error_message());
            return;
        }

        if ($result['called_tool']) {
            Ikoeh_Connect_Clone_Store::increment_iteration($job_id);
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_COMPARING);
        } else {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_DONE);
            self::post_final_chat_message($job_id, $result['final_text'] . " Pagina publicada em {$result_url}.");
        }
    }

    private static function build_conversation($job_id, $with_comparison = false) {
        $source_url = get_post_meta($job_id, Ikoeh_Connect_Clone_Store::META_SOURCE_URL, true);
        $summary = get_post_meta($job_id, '_ikoeh_clone_reference_summary', true);
        $reference_b64 = get_post_meta($job_id, '_ikoeh_clone_reference_screenshot_b64', true);

        $content = [
            ['type' => 'text', 'text' => "Clone this page ({$source_url}) into a new native Elementor page on this WordPress site. Reference summary:\n\n{$summary}"],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $reference_b64]],
        ];

        if ($with_comparison) {
            $result_b64 = get_post_meta($job_id, '_ikoeh_clone_result_screenshot_b64', true);
            $content[] = ['type' => 'text', 'text' => 'Here is a screenshot of what was just published. Compare it against the reference above. If it is close enough, reply with plain text only (no tool call) summarizing what you built. If it needs adjustment, call write_page_content again with a corrected element tree.'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $result_b64]];
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * Runs the Anthropic tool-calling loop until the model responds with no
     * more tool_use blocks (done, this round is over) or MAX_ITERATIONS is
     * reached (the caller in step_compare() checks the cap before calling
     * this again -- this method itself only guards against a single
     * runaway turn by capping at 6 tool calls within one build_conversation
     * round, independent of the job-level iteration cap).
     */
    private static function run_tool_loop($job_id, array $messages) {
        $api_key = get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');
        $model = get_option(Ikoeh_Connect_Chat::OPTION_MODEL, '') ?: Ikoeh_Connect_Chat::DEFAULT_MODEL;

        $called_tool = false;
        $final_text = '';

        for ($round = 0; $round < 6; $round++) {
            $response = wp_remote_post(Ikoeh_Connect_Chat::ANTHROPIC_API_URL, [
                'timeout' => 90,
                'headers' => [
                    'x-api-key' => $api_key,
                    'anthropic-version' => Ikoeh_Connect_Chat::ANTHROPIC_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages' => $messages,
                    'tools' => Ikoeh_Connect_Chat_Tools::CLONE_TOOL_DEFINITIONS,
                ]),
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
                return new WP_Error('ikoeh_connect_bad_response', 'Anthropic API returned an unexpected response.');
            }

            $tool_use_blocks = [];
            foreach ($body['content'] as $block) {
                if (isset($block['type']) && 'text' === $block['type'] && isset($block['text'])) {
                    $final_text .= $block['text'];
                } elseif (isset($block['type']) && 'tool_use' === $block['type']) {
                    $tool_use_blocks[] = $block;
                }
            }

            if (empty($tool_use_blocks)) {
                return ['called_tool' => $called_tool, 'final_text' => $final_text];
            }

            $called_tool = true;
            $messages[] = ['role' => 'assistant', 'content' => $body['content']];

            $tool_results = [];
            foreach ($tool_use_blocks as $block) {
                $result = Ikoeh_Connect_Chat_Tools::execute_clone_tool(
                    $block['name'],
                    is_array($block['input'] ?? null) ? $block['input'] : [],
                    $job_id
                );
                $tool_results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => wp_json_encode($result),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $tool_results];
        }

        return ['called_tool' => $called_tool, 'final_text' => $final_text ?: 'Limite de rodadas de ferramentas atingido nesta etapa.'];
    }

    private static function post_final_chat_message($job_id, $text) {
        $history = Ikoeh_Connect_Chat::get_history();
        $history[] = ['role' => 'assistant', 'content' => $text, 'timestamp' => time()];
        Ikoeh_Connect_Chat::save_history_public($history);
    }
}
```

Note: `post_final_chat_message()` calls `Ikoeh_Connect_Chat::save_history_public()`, which does not exist yet -- `save_history()` in `class-ikoeh-chat.php` is currently `private`. In `plugin/includes/class-ikoeh-chat.php`, change:
```php
    private static function save_history(array $history) {
```
to:
```php
    public static function save_history_public(array $history) {
        return self::save_history($history);
    }

    private static function save_history(array $history) {
```
(kept as a thin public wrapper rather than just making `save_history` itself public, so the method's existing internal-use name and call sites elsewhere in the class stay unchanged.)

- [ ] **Step 4: Wire the cron hook**

In `plugin/wp-ikoeh-connect.php`, add:
```php
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-tools.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-clone-runner.php';
```
near the other requires (after `class-ikoeh-clone-store.php` and `class-ikoeh-site-inspector.php`, before `wp-ikoeh-connect.php`'s closing sections), and:
```php
add_action('ikoeh_clone_job_tick', ['Ikoeh_Connect_Chat_Clone_Runner', 'run_tick']);
```
near the other `add_action` calls.

- [ ] **Step 5: Lint**

Run: `php -l plugin/includes/class-ikoeh-chat-tools.php plugin/includes/class-ikoeh-chat-clone-runner.php plugin/includes/class-ikoeh-chat.php plugin/wp-ikoeh-connect.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/class-ikoeh-chat-tools.php plugin/includes/class-ikoeh-chat-clone-runner.php plugin/includes/class-ikoeh-chat.php plugin/wp-ikoeh-connect.php
git commit -m "feat: add chat tool-calling loop and clone job cron runner"
```

---

### Task 6: wp-admin chat UI (progress polling, undo button)

**Files:**
- Modify: `plugin/includes/class-ikoeh-chat.php` (two new AJAX actions)
- Modify: `plugin/includes/class-ikoeh-chat-admin.php` (localize new data)
- Modify: `plugin/assets/chat.js`

**Interfaces:**
- Consumes: `Ikoeh_Connect_Clone_Store::shape_job()` (Task 1), `Ikoeh_Connect_Chat_Snapshots::undo_last_change()`/`::has_history()` (Task 3).
- Produces: AJAX action `ikoeh_chat_clone_status` (GET latest non-terminal job's shaped status, or the latest terminal job if none pending), AJAX action `ikoeh_chat_undo` (POST `post_id`, calls undo, returns success/error).

- [ ] **Step 1: Add the two AJAX handlers**

In `plugin/includes/class-ikoeh-chat.php`, add to `register_ajax()`:
```php
    public static function register_ajax() {
        add_action('wp_ajax_ikoeh_chat_send', [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_ikoeh_chat_clone_status', [__CLASS__, 'ajax_clone_status']);
        add_action('wp_ajax_ikoeh_chat_undo', [__CLASS__, 'ajax_undo']);
    }
```
Add the two new methods (anywhere in the class):
```php
    public static function ajax_clone_status() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $job = Ikoeh_Connect_Clone_Store::get_oldest_non_terminal_job();
        if (!$job) {
            $recent = get_posts([
                'post_type' => Ikoeh_Connect_Clone_Store::POST_TYPE,
                'posts_per_page' => 1,
                'orderby' => 'ID',
                'order' => 'DESC',
            ]);
            $job = $recent[0] ?? null;
        }

        if (!$job) {
            wp_send_json_success(['job' => null]);
        }

        wp_send_json_success(['job' => Ikoeh_Connect_Clone_Store::shape_job($job)]);
    }

    public static function ajax_undo() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'post_id ausente.']);
        }

        $result = Ikoeh_Connect_Chat_Snapshots::undo_last_change($post_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['undone' => true]);
    }
```

- [ ] **Step 2: Localize the extra data the JS needs**

In `plugin/includes/class-ikoeh-chat-admin.php`, extend the existing `wp_localize_script` call:
```php
        wp_localize_script('ikoeh-connect-chat', 'ikoehChat', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ikoeh_chat_send'),
            'history' => Ikoeh_Connect_Chat::get_history(),
        ]);
```
add nothing new here (the existing `ajaxUrl`/`nonce` already cover the two new actions, since they share the same nonce action name `ikoeh_chat_send`).

- [ ] **Step 3: Add polling and the undo button to chat.js**

In `plugin/assets/chat.js`, add after the existing `sendMessage` function and before the event listener wiring at the bottom:
```javascript
    var POLL_INTERVAL_MS = 5000;
    var jobStatusEl = document.createElement("div");
    jobStatusEl.id = "ikoeh-chat-job-status";
    jobStatusEl.style.marginBottom = "10px";
    jobStatusEl.style.fontSize = "13px";
    jobStatusEl.style.color = "#646970";
    messagesEl.parentNode.insertBefore(jobStatusEl, messagesEl);

    var TERMINAL_STATUSES = ["done", "partial", "failed"];
    var STEP_LABELS = {
        queued: "Na fila...",
        fetching: "Analisando o site de referencia...",
        generating: "Gerando a pagina...",
        publishing: "Publicando...",
        comparing: "Comparando com a referencia...",
        refining: "Ajustando...",
        done: "Concluido.",
        partial: "Concluido parcialmente, revise manualmente.",
        failed: "Falhou."
    };

    function pollCloneStatus() {
        var data = new URLSearchParams();
        data.append("action", "ikoeh_chat_clone_status");
        data.append("nonce", window.ikoehChat.nonce);

        fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json.success || !json.data.job) {
                    jobStatusEl.textContent = "";
                    return;
                }

                var job = json.data.job;
                var label = STEP_LABELS[job.status] || job.status;
                jobStatusEl.textContent = "Clonagem (" + job.iteration + "/" + job.max_iterations + "): " + label;

                if (job.target_post_id && TERMINAL_STATUSES.indexOf(job.status) !== -1) {
                    renderUndoButton(job.target_post_id);
                }

                if (TERMINAL_STATUSES.indexOf(job.status) === -1) {
                    setTimeout(pollCloneStatus, POLL_INTERVAL_MS);
                } else {
                    renderHistory(window.ikoehChat.history || []);
                }
            })
            .catch(function () {
                setTimeout(pollCloneStatus, POLL_INTERVAL_MS);
            });
    }

    function renderUndoButton(postId) {
        var existing = document.getElementById("ikoeh-chat-undo-btn");
        if (existing) {
            existing.remove();
        }

        var btn = document.createElement("button");
        btn.id = "ikoeh-chat-undo-btn";
        btn.type = "button";
        btn.className = "button";
        btn.textContent = "Desfazer ultima alteracao";
        btn.style.marginBottom = "10px";
        btn.addEventListener("click", function () {
            btn.disabled = true;
            var data = new URLSearchParams();
            data.append("action", "ikoeh_chat_undo");
            data.append("nonce", window.ikoehChat.nonce);
            data.append("post_id", postId);

            fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    statusEl.textContent = json.success ? "Alteracao desfeita." : "Erro: " + json.data.message;
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });

        jobStatusEl.parentNode.insertBefore(btn, jobStatusEl.nextSibling);
    }

    pollCloneStatus();
```

Note: `sendMessage()` currently calls `renderHistory(json.data.history)` after every send, which will also need to trigger `pollCloneStatus()` in case `start_site_clone` was just called. Find the existing success branch inside `sendMessage()`:
```javascript
                if (json.success) {
                    renderHistory(json.data.history);
                    statusEl.textContent = "";
```
and change it to:
```javascript
                if (json.success) {
                    renderHistory(json.data.history);
                    statusEl.textContent = "";
                    pollCloneStatus();
```

- [ ] **Step 4: Lint**

Run: `php -l plugin/includes/class-ikoeh-chat.php plugin/includes/class-ikoeh-chat-admin.php`
Expected: `No syntax errors detected` for both.

Run: `node --check plugin/assets/chat.js`
Expected: no output (success).

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/class-ikoeh-chat.php plugin/includes/class-ikoeh-chat-admin.php plugin/assets/chat.js
git commit -m "feat: add clone job progress polling and undo button to chat UI"
```

---

### Task 7: End-to-end live verification

**Files:** none (verification only, may produce fix commits if issues are found).

**Interfaces:**
- Consumes: everything from Tasks 1-6.
- Produces: a working, live-verified feature, or a list of concrete bugs to fix before this plan can be marked complete.

- [ ] **Step 1: Deploy**

```bash
node /private/tmp/claude-501/-Users-macbook/f393f06e-c08f-47d4-ab12-73c94bca1b32/scratchpad/deploy-doctorbeats-full.mjs
```
Expected: HTTP 200, `{"installed":"wp-ikoeh-connect","target":"plugins"}`.

- [ ] **Step 2: Configure the screenshot API key**

Via the admin-access-link flow (already shipped, see `class-ikoeh-rest-admin-access.php`) or a direct wp-admin login, go to Ajustes > iKOEH Connect, and paste a real urlbox.io API key into the new "Chave de API de screenshot" field alongside the existing Anthropic key. Confirm both are already configured (the chat page will refuse to load otherwise, per its existing `api_key_configured` guard).

- [ ] **Step 3: Trigger a real clone end to end**

Open Chat iKOEH (Ajustes menu), send a message like "Clone esta pagina: https://example.com" (pick a real, simple, small public page for the first test, not a JS-heavy site, to keep the first run's cost and complexity low). Confirm:
- The chat responds quickly (a few seconds) acknowledging the job started, not blocking for minutes.
- The job-status line below the message list appears and updates every 5 seconds (`queued` -> `fetching` -> `generating` -> `publishing` -> `comparing` -> eventually `done` or `partial`).
- Within a few minutes (up to ~4 cron ticks plus tool-loop rounds), a new WordPress page exists with Elementor content resembling the source page's headings/text/images.
- Images from the source page were sideloaded into this site's own media library (check Mídia in wp-admin), not hotlinked.
- The final chat message includes a link to the new page.

- [ ] **Step 4: Verify undo**

With the clone job's target page now published, click "Desfazer ultima alteracao" in the chat. Confirm the page's Elementor content reverts to whatever it was one write before the last one (for a freshly-created page with only one or two writes, this may mean reverting to an empty/near-empty state -- if so, note that as expected for a page this new rather than a bug).

- [ ] **Step 5: Verify the permanent blacklist**

Confirm, by reading `Ikoeh_Connect_Chat_Tools::QUICK_TOOL_DEFINITIONS` and `::CLONE_TOOL_DEFINITIONS` one more time after deploy, that no tool named anything related to plugins, themes, database, system/file access, execute-php, wp-cli, or page deletion exists anywhere in either array. This should already be true from Task 5 and is a final confirmation, not new work.

- [ ] **Step 6: Fix any bugs found live**

If any step above fails, fix the underlying code, redeploy, and re-verify before considering this task (and the plan) complete. Commit each fix separately with a clear message describing the bug and the fix.
