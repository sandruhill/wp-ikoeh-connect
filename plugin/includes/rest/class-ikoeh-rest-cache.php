<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Cache {

    // Route is /cache, not /cache/flush: this host blocks any REST path with
    // 4+ segments before WordPress even sees the request (confirmed against
    // WordPress core's own routes too). Flush is the only cache action this
    // plugin exposes, so POST /cache alone is unambiguous.
    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/cache', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('logs_cache'),
        ]);
    }

    public static function handle() {
        $flushed = wp_cache_flush();
        return new WP_REST_Response(['flushed' => (bool) $flushed], 200);
    }
}
