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
            'parent'         => $is_child && $theme->parent() ? $theme->parent()->get_stylesheet() : null,
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
