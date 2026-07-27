<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Site_Info {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/site-info', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope(),
        ]);
    }

    public static function handle(WP_REST_Request $request) {
        global $wp_version;

        return new WP_REST_Response([
            'wp_version'            => $wp_version,
            'php_version'           => phpversion(),
            'active_theme'          => get_stylesheet(),
            'active_plugins'        => get_option('active_plugins', []),
            'ikoeh_connect_version' => IKOEH_CONNECT_VERSION,
        ], 200);
    }
}
