<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Db {

    private static $read_prefixes = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC ', 'EXPLAIN'];
    const AUTO_LIMIT = 1000;

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/db/query', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('db'),
        ]);
    }

    private static function is_read_query($sql) {
        $sql = ltrim($sql);
        foreach (self::$read_prefixes as $prefix) {
            if (stripos($sql, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function apply_auto_limit($sql) {
        if (preg_match('/\bLIMIT\s+\d/i', $sql)) {
            return $sql;
        }
        return rtrim(rtrim($sql), ';') . ' LIMIT ' . self::AUTO_LIMIT;
    }

    public static function handle(WP_REST_Request $request) {
        global $wpdb;

        $params = $request->get_json_params();
        $sql = isset($params['sql']) ? trim($params['sql']) : '';

        if (empty($sql)) {
            return new WP_Error('ikoeh_connect_empty_query', 'No SQL provided.', ['status' => 400]);
        }

        if (self::is_read_query($sql)) {
            // Memory/processing safety: an unbounded SELECT against a large table
            // can exhaust the PHP memory_limit on shared hosting. A missing LIMIT
            // gets one added automatically instead of running unbounded.
            $sql = self::apply_auto_limit($sql);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (null === $rows && $wpdb->last_error) {
                return new WP_Error('ikoeh_connect_query_failed', $wpdb->last_error, ['status' => 400]);
            }
            return new WP_REST_Response(['rows' => $rows, 'sql_executed' => $sql], 200);
        }

        if (empty($params['confirm_write'])) {
            return new WP_Error(
                'ikoeh_connect_write_not_confirmed',
                'This looks like a write query. Resend with "confirm_write": true to proceed.',
                ['status' => 400]
            );
        }

        $affected = $wpdb->query($sql);

        if (false === $affected) {
            return new WP_Error('ikoeh_connect_query_failed', $wpdb->last_error, ['status' => 400]);
        }

        return new WP_REST_Response(['affected_rows' => $affected], 200);
    }
}
