<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Cache {

    // Route is /purge, not /cache or /cache/flush: this host's security layer
    // appears to block REST paths containing the word "cache" specifically
    // (confirmed by testing: /logs and /content resolve fine while /cache
    // does not, even alone with no extra path segments).
    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/purge', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('logs_cache'),
        ]);
    }

    public static function handle() {
        $flushed = wp_cache_flush();

        // wp_cache_flush() only clears WP's runtime/object cache. It does
        // NOT touch LiteSpeed's page cache (the HTML output cache served to
        // anonymous visitors), so without this, edited content kept being
        // served stale after a "purge" that silently did nothing for it.
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        } elseif (class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
            \LiteSpeed\Purge::purge_all();
        }

        return new WP_REST_Response(['flushed' => (bool) $flushed], 200);
    }
}
