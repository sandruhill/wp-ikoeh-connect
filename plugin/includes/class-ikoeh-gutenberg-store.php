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
            'post_title' => $label, 'post_excerpt' => wp_strip_all_tags($agent_note),
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
            'post_title' => $title, 'post_excerpt' => "{$operation} for {$target->post_type} #{$target->ID}",
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        $item_id = (int) $result;
        update_post_meta($item_id, self::META_KIND, self::KIND_ITEM);
        self::set_status($item_id, self::STATUS_DRAFT);
        update_post_meta($item_id, self::META_TARGET_ID, $target_id);
        update_post_meta($item_id, self::META_TARGET_TYPE, $target_type);
        update_post_meta($item_id, self::META_OPERATION, $operation);
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
            'items' => array_map(['self', 'shape_item'], $items),
        ];
    }
}
