<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Logs {

    const READ_CHUNK_BYTES = 8192;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/logs/debug', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('logs_cache'),
        ]);
    }

    /**
     * Memory/processing safety: debug.log on a neglected site can reach
     * hundreds of MB. Loading it whole with file() just to return the last
     * N lines risks hitting the PHP memory_limit on shared hosting. This
     * seeks backward from the end of the file in fixed-size chunks and
     * stops as soon as enough newlines have been found, so memory use stays
     * proportional to the requested tail size, not to the file size.
     */
    private static function tail_lines($path, $limit) {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $file_size = filesize($path);
        $buffer = '';
        $lines_found = 0;
        $position = $file_size;

        while ($position > 0 && $lines_found <= $limit) {
            $read_size = min(self::READ_CHUNK_BYTES, $position);
            $position -= $read_size;

            fseek($handle, $position);
            $buffer = fread($handle, $read_size) . $buffer;
            $lines_found = substr_count($buffer, "\n");
        }

        fclose($handle);

        $all_lines = explode("\n", rtrim($buffer, "\n"));
        return array_slice($all_lines, -$limit);
    }

    public static function handle(WP_REST_Request $request) {
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_file)) {
            return new WP_REST_Response(['lines' => []], 200);
        }

        $requested = (int) $request->get_param('lines');
        $limit = $requested > 0 ? min($requested, 1000) : 100;

        $tail = self::tail_lines($log_file, $limit);

        return new WP_REST_Response(['lines' => $tail], 200);
    }
}
