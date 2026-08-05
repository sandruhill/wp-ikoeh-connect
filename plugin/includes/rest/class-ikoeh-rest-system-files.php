<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_System_Files {

    const DEFAULT_READ_LIMIT_BYTES = 1048576;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-file', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'read_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'write_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'delete_file'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-directory', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_directory'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-enable-file', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'enable_file'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/system-disable-file', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'disable_file'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('system'),
        ]);
    }

    public static function read_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_file($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_file', "Path is not a file: {$resolved}", ['status' => 400]);
        }
        if (!is_readable($resolved)) {
            return new WP_Error('ikoeh_connect_not_readable', "File is not readable: {$resolved}", ['status' => 403]);
        }

        $size = (int) filesize($resolved);
        $offset = (int) ($request->get_param('offset') ?: 0);
        $limit_param = $request->get_param('limit');
        $limit = null === $limit_param ? self::DEFAULT_READ_LIMIT_BYTES : (int) $limit_param;

        $handle = fopen($resolved, 'rb');
        if (false === $handle) {
            return new WP_Error('ikoeh_connect_read_failed', "Could not open file: {$resolved}", ['status' => 500]);
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $read_length = -1 === $limit ? max(1, $size - $offset) : max(1, $limit);
        $content = fread($handle, $read_length);
        fclose($handle);

        if (false === $content) {
            return new WP_Error('ikoeh_connect_read_failed', "Could not read file: {$resolved}", ['status' => 500]);
        }

        $bytes_read = strlen($content);
        $truncated = -1 !== $limit && ($offset + $bytes_read) < $size;
        $is_text = mb_check_encoding($content, 'UTF-8');
        $encoding = $is_text ? 'utf-8' : 'base64';
        if (!$is_text) {
            $content = base64_encode($content);
        }

        return new WP_REST_Response([
            'path' => $resolved,
            'content' => $content,
            'encoding' => $encoding,
            'size' => $size,
            'bytes_read' => $bytes_read,
            'truncated' => $truncated,
        ], 200);
    }

    public static function write_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $symlink_check = Ikoeh_Connect_System_Path::reject_symlink($resolved);
        if (is_wp_error($symlink_check)) {
            return $symlink_check;
        }

        $sandbox_check = Ikoeh_Connect_System_Path::check_php_sandbox($resolved);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }

        $content = (string) ($request->get_param('content') ?: '');
        $mode = (string) ($request->get_param('mode') ?: 'overwrite');

        $parent_dir = dirname($resolved);
        if (!is_dir($parent_dir)) {
            wp_mkdir_p($parent_dir);
        }

        $created = !file_exists($resolved);
        $flags = LOCK_EX;
        if ('append' === $mode) {
            $flags |= FILE_APPEND;
        }

        $bytes_written = file_put_contents($resolved, $content, $flags);
        if (false === $bytes_written) {
            return new WP_Error('ikoeh_connect_write_failed', "Failed to write file: {$resolved}", ['status' => 500]);
        }

        return new WP_REST_Response([
            'path' => $resolved,
            'bytes_written' => $bytes_written,
            'created' => $created,
            'size' => filesize($resolved),
        ], 200);
    }

    public static function delete_file(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_file($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_file', "Path is not a file: {$resolved}", ['status' => 400]);
        }
        if (!unlink($resolved)) {
            return new WP_Error('ikoeh_connect_delete_failed', "Failed to delete file: {$resolved}", ['status' => 500]);
        }
        return new WP_REST_Response(['deleted' => $resolved], 200);
    }

    public static function list_directory(WP_REST_Request $request) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_dir($resolved)) {
            return new WP_Error('ikoeh_connect_not_a_directory', "Path is not a directory: {$resolved}", ['status' => 400]);
        }

        $entries = [];
        foreach (scandir($resolved) as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $full = $resolved . '/' . $name;
            $entries[] = [
                'name' => $name,
                'is_directory' => is_dir($full),
                'size' => is_file($full) ? filesize($full) : null,
            ];
        }

        return new WP_REST_Response(['path' => $resolved, 'entries' => $entries], 200);
    }

    private static function require_sandboxed_php_path($path, $must_exist) {
        $resolved = Ikoeh_Connect_System_Path::resolve((string) $path, $must_exist);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!Ikoeh_Connect_System_Path::is_php_path($resolved)) {
            return new WP_Error('ikoeh_connect_not_php', 'enable/disable only applies to .php files.', ['status' => 400]);
        }
        $sandbox_check = Ikoeh_Connect_System_Path::check_php_sandbox($resolved);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }
        return $resolved;
    }

    public static function disable_file(WP_REST_Request $request) {
        $resolved = self::require_sandboxed_php_path($request->get_param('path'), true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $disabled_path = $resolved . '.disabled';
        if (file_exists($disabled_path)) {
            return new WP_Error('ikoeh_connect_disabled_exists', "A disabled version already exists: {$disabled_path}", ['status' => 409]);
        }
        if (!rename($resolved, $disabled_path)) {
            return new WP_Error('ikoeh_connect_disable_failed', "Failed to disable file: {$resolved}", ['status' => 500]);
        }
        return new WP_REST_Response(['original_path' => $resolved, 'disabled_path' => $disabled_path], 200);
    }

    public static function enable_file(WP_REST_Request $request) {
        $original = self::require_sandboxed_php_path($request->get_param('path'), false);
        if (is_wp_error($original)) {
            return $original;
        }

        $disabled_path = $original . '.disabled';
        if (!file_exists($disabled_path)) {
            return new WP_Error('ikoeh_connect_not_disabled', "No disabled version found: {$disabled_path}", ['status' => 404]);
        }
        if (file_exists($original)) {
            return new WP_Error('ikoeh_connect_original_exists', "An enabled version already exists: {$original}", ['status' => 409]);
        }
        if (!rename($disabled_path, $original)) {
            return new WP_Error('ikoeh_connect_enable_failed', "Failed to enable file: {$original}", ['status' => 500]);
        }
        return new WP_REST_Response(['original_path' => $original, 'disabled_path' => $disabled_path], 200);
    }
}
