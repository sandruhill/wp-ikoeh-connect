<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared path-safety boundary for every filesystem-touching route in the
 * "system" scope. This is the one real safety net that keeps unrestricted
 * PHP execution / WP-CLI / filesystem access from being simply reckless:
 * every path is confined to the WordPress root via realpath() (not naive
 * string-prefix matching, which has classic ../ bypass bugs), and writing
 * new PHP content is further confined to a sandbox directory.
 */
class Ikoeh_Connect_System_Path {

    const SANDBOX_DIR_NAME = 'ikoeh-sandbox';

    public static function sandbox_dir() {
        return WP_CONTENT_DIR . '/' . self::SANDBOX_DIR_NAME;
    }

    private static function normalize_boundary($path) {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function is_within($path, $directory) {
        $normalized_path = self::normalize_boundary($path);
        $normalized_directory = self::normalize_boundary($directory);
        if ($normalized_path === $normalized_directory) {
            return true;
        }
        return 0 === strpos($normalized_path, $normalized_directory . '/');
    }

    public static function resolve($path, $must_exist) {
        $path = (string) $path;
        if ('' === $path) {
            return new WP_Error('ikoeh_connect_invalid_path', 'path is required.', ['status' => 400]);
        }

        if (0 !== strpos($path, '/') && 0 !== strpos($path, '\\')) {
            $path = rtrim(ABSPATH, '/\\') . '/' . $path;
        }

        if ($must_exist) {
            $resolved = realpath($path);
            if (false === $resolved) {
                return new WP_Error('ikoeh_connect_path_not_found', "Path does not exist: {$path}", ['status' => 404]);
            }
        } else {
            $parent = realpath(dirname($path));
            if (false === $parent) {
                return new WP_Error('ikoeh_connect_parent_not_found', 'Parent directory does not exist: ' . dirname($path), ['status' => 404]);
            }
            $resolved = rtrim($parent, '/\\') . '/' . basename($path);
        }

        $real_root = realpath(ABSPATH);
        if (false === $real_root) {
            $real_root = rtrim(ABSPATH, '/\\');
        }

        if (!self::is_within($resolved, $real_root)) {
            return new WP_Error(
                'ikoeh_connect_path_outside_root',
                "Path \"{$resolved}\" is outside the allowed root \"{$real_root}\".",
                ['status' => 400]
            );
        }

        return $resolved;
    }

    public static function reject_symlink($resolved) {
        if (is_link($resolved)) {
            return new WP_Error('ikoeh_connect_symlink_write_rejected', "Refusing to write through symlink path: {$resolved}", ['status' => 400]);
        }
        return true;
    }

    public static function is_php_path($resolved) {
        return 'php' === strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    }

    public static function check_php_sandbox($resolved) {
        if (!self::is_php_path($resolved)) {
            return true;
        }

        $sandbox_real = realpath(self::sandbox_dir());
        if (false === $sandbox_real) {
            $sandbox_real = rtrim(self::sandbox_dir(), '/\\');
        }

        if (!self::is_within($resolved, $sandbox_real)) {
            return new WP_Error(
                'ikoeh_connect_php_write_outside_sandbox',
                'PHP files can only be written inside the sandbox directory: wp-content/' . self::SANDBOX_DIR_NAME . '/',
                ['status' => 400]
            );
        }

        return true;
    }
}
