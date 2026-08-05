<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * All routes stay at exactly 3 path segments (namespace/version/plugins)
 * because this host blocks any REST path with 4+ segments before it ever
 * reaches WordPress (confirmed by testing WordPress core's own routes,
 * e.g. /wp-json/wp/v2/pages/1 also 404s on this host while /wp-json/wp/v2/pages
 * does not). install/activate/deactivate/delete are dispatched by HTTP
 * method and body content instead of by extra path segments.
 */
class Ikoeh_Connect_Rest_Plugins {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/plugins', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_plugins'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle_post'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [__CLASS__, 'delete_plugin'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('plugins'),
            ],
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

    /** Recursive copy, used as a fallback when rename() fails (e.g. source and destination on different filesystems). */
    private static function rcopy($src, $dst) {
        if (is_dir($src)) {
            if (!is_dir($dst) && !wp_mkdir_p($dst)) {
                return false;
            }
            foreach (array_diff(scandir($src), ['.', '..']) as $file) {
                if (!self::rcopy("$src/$file", "$dst/$file")) {
                    return false;
                }
            }
            return true;
        }
        return copy($src, $dst);
    }

    public static function handle_post(WP_REST_Request $request) {
        $content_type = (string) $request->get_header('content-type');

        if (0 === stripos($content_type, 'application/zip')) {
            $is_mu = 'mu' === $request->get_param('target');
            return self::install_from_bytes($request->get_body(), $is_mu);
        }

        $params = $request->get_json_params();
        $action = isset($params['action']) ? sanitize_key($params['action']) : '';
        $slug = isset($params['slug']) ? sanitize_text_field($params['slug']) : '';

        if ('activate' === $action) {
            return self::activate_plugin($slug);
        }

        if ('deactivate' === $action) {
            return self::deactivate_plugin($slug);
        }

        if ('install' === $action) {
            $is_mu = isset($params['target']) && 'mu' === $params['target'];

            if (!empty($slug)) {
                return self::install_from_wordpress_org($slug, $is_mu);
            }

            if (!empty($params['url'])) {
                return self::install_from_url(esc_url_raw($params['url']), $is_mu);
            }

            return new WP_Error(
                'ikoeh_connect_invalid_request',
                'JSON install requires "slug" (a WordPress.org plugin) or "url" (a direct zip URL).',
                ['status' => 400]
            );
        }

        return new WP_Error(
            'ikoeh_connect_invalid_request',
            'Send zip bytes with Content-Type: application/zip to install from a local file, or JSON ' .
                '{"action":"install","slug":"..."} / {"action":"install","url":"..."} to install server-side, ' .
                'or {"action":"activate|deactivate","slug":"..."}.',
            ['status' => 400]
        );
    }

    /**
     * Installs a plugin straight from WordPress.org by slug: the server
     * looks up the current download URL itself via plugins_api() (WP
     * core's own function for this) and fetches the zip server-side, so no
     * zip needs to be downloaded locally and re-uploaded through the API.
     */
    private static function install_from_wordpress_org($slug, $is_mu) {
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        $info = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($info)) {
            return new WP_Error('ikoeh_connect_lookup_failed', $info->get_error_message(), ['status' => 400]);
        }

        if (empty($info->download_link)) {
            return new WP_Error('ikoeh_connect_lookup_failed', 'No download link found for that slug.', ['status' => 400]);
        }

        return self::install_from_url($info->download_link, $is_mu);
    }

    /** Fetches an arbitrary zip URL server-side, then installs it the same way an uploaded zip would be. */
    private static function install_from_url($url, $is_mu) {
        self::ensure_plugin_functions();

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = download_url($url);

        if (is_wp_error($tmp_file)) {
            return new WP_Error('ikoeh_connect_download_failed', $tmp_file->get_error_message(), ['status' => 400]);
        }

        $bytes = file_get_contents($tmp_file);
        @unlink($tmp_file);

        return self::install_from_bytes($bytes, $is_mu);
    }

    public static function install_from_bytes($body, $is_mu) {
        self::ensure_plugin_functions();

        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'No plugin zip bytes to install.', ['status' => 400]);
        }

        // Large plugins (multi-MB zips, thousands of files) can exceed the
        // default max_execution_time mid-extraction, leaving the plugin
        // directory partially written. @-suppressed because some hosts
        // disable these via disable_functions; if so, this is a no-op and
        // the underlying host limit still applies.
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

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

        // Atomic-as-possible swap: renaming the old destination out of the
        // way and the new source into place are both single, fast directory
        // renames on the same filesystem. Deleting the old destination in
        // place first (rrmdir, which walks and unlinks every file) used to
        // leave the plugin missing from disk for however long that walk
        // took; any request landing in that window (including WordPress's
        // own admin-ajax/heartbeat traffic) found the active plugin's main
        // file gone and triggered WordPress's fatal-error protection, which
        // auto-deactivates the plugin. The backup-then-swap below shrinks
        // that window from "time to delete N files" to "time between two
        // rename() syscalls".
        $backup = null;

        if (is_dir($destination)) {
            $backup = trailingslashit(dirname($destination)) . '.' . $entry_name . '-backup-' . wp_generate_password(6, false);
            rename($destination, $backup);
        } elseif (file_exists($destination)) {
            unlink($destination);
        }

        // rename() across filesystems (e.g. the temp dir used for extraction
        // living on a different mount than wp-content, which varies by host
        // and even by request) fails silently returning false rather than
        // throwing. That failure was never checked before, so a failed move
        // still reported "installed" while the destination was actually
        // empty or missing. Now: verify the move, fall back to a recursive
        // copy if rename() failed, and restore the previous version rather
        // than leaving the site with a half-installed plugin.
        $moved = @rename($source, $destination);

        if (!$moved) {
            $moved = self::rcopy($source, $destination)
                && is_dir($destination)
                && count(array_diff(scandir($destination), ['.', '..'])) > 0;
        }

        if (!$moved) {
            self::rrmdir($destination);
            if ($backup) {
                rename($backup, $destination);
            }
            self::rrmdir($tmp_dir);
            return new WP_Error(
                'ikoeh_connect_install_failed',
                'Could not move the new plugin files into place. The previous version, if any, was restored.',
                ['status' => 500]
            );
        }

        if ($backup) {
            self::rrmdir($backup);
        }

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

    public static function activate_plugin($slug) {
        $plugin_file = self::find_plugin_file($slug);
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        $result = activate_plugin($plugin_file);
        if (is_wp_error($result)) {
            return new WP_Error('ikoeh_connect_activate_failed', $result->get_error_message(), ['status' => 400]);
        }
        return new WP_REST_Response(['activated' => $plugin_file], 200);
    }

    public static function deactivate_plugin($slug) {
        $plugin_file = self::find_plugin_file($slug);
        if (!$plugin_file) {
            return new WP_Error('ikoeh_connect_not_found', 'Plugin not found.', ['status' => 404]);
        }
        deactivate_plugins($plugin_file);
        return new WP_REST_Response(['deactivated' => $plugin_file], 200);
    }

    public static function delete_plugin(WP_REST_Request $request) {
        self::ensure_plugin_functions();
        $plugin_file = self::find_plugin_file(sanitize_text_field($request->get_param('slug')));
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
