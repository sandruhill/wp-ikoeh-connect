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
        global $wp_version, $wpdb;

        return new WP_REST_Response([
            'wp_version'            => $wp_version,
            'php_version'           => phpversion(),
            'active_theme'          => get_stylesheet(),
            'active_plugins'        => get_option('active_plugins', []),
            'ikoeh_connect_version' => IKOEH_CONNECT_VERSION,
            // Table prefix is NOT always "wp_": always read this instead of
            // assuming, before writing any raw SQL against this site.
            'db_prefix'             => $wpdb->prefix,
            'php_limits'            => [
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit'       => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'      => ini_get('post_max_size'),
                'disable_functions'  => ini_get('disable_functions'),
            ],
            // If these differ, rename() between the temp dir (used to
            // extract uploaded plugin zips) and wp-content/plugins fails
            // silently across the filesystem boundary, which was the real
            // cause of plugin installs sometimes leaving an empty/missing
            // directory. Same device number = same filesystem = rename()
            // is safe; different = the copy fallback is what's actually
            // moving files into place.
            'fs_same_device'        => @stat(get_temp_dir())['dev'] === @stat(WP_PLUGIN_DIR)['dev'],
        ], 200);
    }
}
