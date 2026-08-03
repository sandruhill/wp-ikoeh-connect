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
