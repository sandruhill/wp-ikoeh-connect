<?php
/**
 * Auto-installed by wp-ikoeh-connect. Do not edit directly -- this file is
 * overwritten from the plugin's bundled copy on every WordPress "init".
 * See plugin/includes/class-ikoeh-system-installer.php in the plugin source.
 *
 * Includes any non-disabled .php file placed in wp-content/ikoeh-sandbox/
 * on every request. If a sandbox file causes a fatal error, this loader
 * detects it via a shutdown function and writes a .crashed marker so the
 * NEXT request skips loading any sandbox file entirely (safe mode) instead
 * of taking the whole site down repeatedly. The agent (or a human) can
 * read/fix/delete the offending file and remove the marker via the
 * system-file API to resume normal loading.
 */

if (!defined('ABSPATH')) {
    exit;
}

$ikoeh_sandbox_dir = WP_CONTENT_DIR . '/ikoeh-sandbox';
$ikoeh_sandbox_crashed_marker = $ikoeh_sandbox_dir . '/.crashed';

if (!is_dir($ikoeh_sandbox_dir) || file_exists($ikoeh_sandbox_crashed_marker)) {
    return;
}

$ikoeh_sandbox_files = glob($ikoeh_sandbox_dir . '/*.php');
if (empty($ikoeh_sandbox_files)) {
    return;
}

$ikoeh_sandbox_loading_file = null;

register_shutdown_function(function () use ($ikoeh_sandbox_crashed_marker, &$ikoeh_sandbox_loading_file) {
    $ikoeh_last_error = error_get_last();
    $ikoeh_fatal_types = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR];
    if (
        $ikoeh_last_error
        && in_array($ikoeh_last_error['type'], $ikoeh_fatal_types, true)
        && null !== $ikoeh_sandbox_loading_file
    ) {
        file_put_contents(
            $ikoeh_sandbox_crashed_marker,
            $ikoeh_sandbox_loading_file . "\n" . $ikoeh_last_error['message']
        );
    }
});

foreach ($ikoeh_sandbox_files as $ikoeh_sandbox_file) {
    $ikoeh_sandbox_loading_file = $ikoeh_sandbox_file;
    include $ikoeh_sandbox_file;
    $ikoeh_sandbox_loading_file = null;
}
