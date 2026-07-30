<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures the sandbox directory and its always-on mu-plugin loader exist
 * and are up to date. Runs on every "init" -- cheap (a file_exists + one
 * string comparison in the common case) and self-heals if a WordPress
 * update or manual cleanup ever removes the mu-plugin file.
 */
class Ikoeh_Connect_System_Installer {

    public static function ensure_sandbox() {
        $sandbox_dir = Ikoeh_Connect_System_Path::sandbox_dir();
        if (!is_dir($sandbox_dir)) {
            wp_mkdir_p($sandbox_dir);
        }

        $mu_plugins_dir = WPMU_PLUGIN_DIR;
        if (!is_dir($mu_plugins_dir)) {
            wp_mkdir_p($mu_plugins_dir);
        }

        $source = IKOEH_CONNECT_DIR . 'mu-plugin-sandbox-loader.php';
        if (!file_exists($source)) {
            return;
        }

        $target = $mu_plugins_dir . '/ikoeh-sandbox-loader.php';
        $source_contents = file_get_contents($source);
        $target_contents = file_exists($target) ? file_get_contents($target) : null;

        if ($target_contents !== $source_contents) {
            file_put_contents($target, $source_contents);
        }
    }
}
