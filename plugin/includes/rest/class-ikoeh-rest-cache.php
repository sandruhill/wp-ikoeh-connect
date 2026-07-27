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
