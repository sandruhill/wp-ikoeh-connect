<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Plugins {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list_plugins'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/install', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'install_plugin'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/activate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'activate_plugin'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)/deactivate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'deactivate_plugin'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins/(?P<slug>[^/]+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'delete_plugin'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
        ]);
    }

    private static function ensure_plugin_functions() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }
    }

    private static function find_plugin_file($slug) {
        self::ensure_plugin_functions();
        foreach (array_keys(get_plugins()) as $plugin_file) {
            if (strtok($plugin_file, '/') === $slug || $plugin_file === $slug) {
                return $plugin_file;
            }
        }
        return null;
    }

    public static function list_plugins() {
        self::ensure_plugin_functions();
        $active = get_option('active_plugins', []);
        $result = [];

        foreach (get_plugins() as $file => $data) {
            $result[] = [
                'slug'    => strtok($file, '/'),
                'file'    => $file,
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array($file, $active, true),
            ];
        }

        return new WP_REST_Response($result, 200);
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public static function install_plugin(WP_REST_Request $request) {
        self::ensure_plugin_functions();

        $body = $request->get_body();
        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'Request body must be the plugin zip bytes.', ['status' => 400]);
        }

        $is_mu = 'mu' === $request->get_param('target');
        $target = $is_mu ? WPMU_PLUGIN_DIR : WP_PLUGIN_DIR;

        if ($is_mu && !file_exists(WPMU_PLUGIN_DIR)) {
            wp_mkdir_p(WPMU_PLUGIN_DIR);
        }

        $tmp_zip = wp_tempnam('ikoeh-connect-plugin.zip');
        file_put_contents($tmp_zip, $body);

        $tmp_dir = trailingslashit(get_temp_dir()) . 'ikoeh-connect-' . wp_generate_password(8, false);
        wp_mkdir_p($tmp_dir);

        $unzip_result = unzip_file($tmp_zip, $tmp_dir);
        unlink($tmp_zip);

        if (is_wp_error($unzip_result)) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_unzip_failed', $unzip_result->get_error_message(), ['status' => 400]);
        }

        $entries = array_values(array_diff(scandir($tmp_dir), ['.', '..']));

        if (count($entries) !== 1) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_invalid_zip', 'Zip must contain exactly one top-level plugin folder or file.', ['status' => 400]);
        }

        $entry_name = sanitize_file_name($entries[0]);
        $source = trailingslashit($tmp_dir) . $entries[0];
        $destination = trailingslashit($target) . $entry_name;

        $real_source = realpath($source);
        $real_tmp = realpath($tmp_dir);

        if (false === $real_source || 0 !== strpos($real_source, $real_tmp)) {
            self::rrmdir($tmp_dir);
            return new WP_Error('ikoeh_connect_invalid_zip', 'Zip contents failed validation.', ['status' => 400]);
        }

        if (is_dir($destination)) {
            self::rrmdir($destination);
        } elseif (file_exists($destination)) {
            unlink($destination);
        }

        rename($source, $destination);
        self::rrmdir($tmp_dir);

        // On shared hosting, PHP's opcache can keep serving compiled bytecode
        // for the old files after they've been replaced on disk, especially
        // when opcache.validate_timestamps is off. Force a reset so an
        // in-place update (like this one) takes effect immediately instead
        // of only after opcache's own revalidation window elapses.
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return new WP_REST_Response(['installed' => $entry_name, 'target' => $is_mu ? 'mu-plugins' : 'plugins'], 200);
    }

    public static function activate_plugin(WP_REST_Request $request) {
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        $result = activate_plugin($plugin_file);
        if (is_wp_error($result)) {
            return new WP_Error('ikoeh_connect_activate_failed', $result->get_error_message(), ['status' => 400]);
        }
        return new WP_REST_Response(['activated' => $plugin_file], 200);
    }

    public static function deactivate_plugin(WP_REST_Request $request) {
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        deactivate_plugins($plugin_file);
        return new WP_REST_Response(['deactivated' => $plugin_file], 200);
    }

    public static function delete_plugin(WP_REST_Request $request) {
        self::ensure_plugin_functions();
        $plugin_file = self::find_plugin_file($request->get_param('slug'));
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        $result = delete_plugins([$plugin_file]);
        if (is_wp_error($result)) {
            return new WP_Error('ikoeh_connect_delete_failed', $result->get_error_message(), ['status' => 400]);
        }
        return new WP_REST_Response(['deleted' => $plugin_file], 200);
    }
}
