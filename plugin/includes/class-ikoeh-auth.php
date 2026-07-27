<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Auth {

    const CONNECTIONS_OPTION = 'ikoeh_connect_connections';
    const MIGRATED_OPTION = 'ikoeh_connect_migrated_v2';
    const LEGACY_TOKEN_HASH_OPTION = 'ikoeh_connect_token_hash';
    const LEGACY_LAST_USED_OPTION = 'ikoeh_connect_last_used_at';

    const ALL_SCOPES = ['plugins', 'content', 'db', 'logs_cache'];

    public static function maybe_migrate() {
        if (get_option(self::MIGRATED_OPTION, false)) {
            return;
        }

        $legacy_hash = get_option(self::LEGACY_TOKEN_HASH_OPTION, '');

        if ($legacy_hash) {
            $connections = self::get_connections();
            $connections[] = [
                'id'           => wp_generate_password(12, false),
                'name'         => 'Claude Code',
                'token_hash'   => $legacy_hash,
                'scopes'       => self::ALL_SCOPES,
                'created_at'   => time(),
                'last_used_at' => get_option(self::LEGACY_LAST_USED_OPTION, 0) ?: null,
            ];
            self::save_connections($connections);
        }

        update_option(self::MIGRATED_OPTION, true, false);
    }

    public static function get_connections() {
        $connections = get_option(self::CONNECTIONS_OPTION, []);
        return is_array($connections) ? $connections : [];
    }

    private static function save_connections($connections) {
        update_option(self::CONNECTIONS_OPTION, array_values($connections), false);
    }

    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }

    public static function hash_token($token) {
        return hash('sha256', $token);
    }

    public static function create_connection($name, array $scopes) {
        $token = self::generate_token();
        $connections = self::get_connections();
        $connections[] = [
            'id'           => wp_generate_password(12, false),
            'name'         => sanitize_text_field($name),
            'token_hash'   => self::hash_token($token),
            'scopes'       => array_values(array_intersect($scopes, self::ALL_SCOPES)),
            'created_at'   => time(),
            'last_used_at' => null,
        ];
        self::save_connections($connections);
        return $token;
    }

    public static function revoke_connection($id) {
        $connections = array_values(array_filter(
            self::get_connections(),
            function ($connection) use ($id) {
                return $connection['id'] !== $id;
            }
        ));
        self::save_connections($connections);
    }

    public static function has_connections() {
        return count(self::get_connections()) > 0;
    }

    private static function find_connection_index_by_token($token) {
        $hash = self::hash_token($token);
        foreach (self::get_connections() as $index => $connection) {
            if (hash_equals($connection['token_hash'], $hash)) {
                return $index;
            }
        }
        return null;
    }

    private static function touch_last_used($index) {
        $connections = self::get_connections();
        if (isset($connections[$index])) {
            $connections[$index]['last_used_at'] = time();
            self::save_connections($connections);
        }
    }

    public static function require_scope($scope = null) {
        return function (WP_REST_Request $request) use ($scope) {
            $skip_https_check = defined('IKOEH_CONNECT_SKIP_HTTPS_CHECK') && IKOEH_CONNECT_SKIP_HTTPS_CHECK;

            if (!is_ssl() && !$skip_https_check) {
                return new WP_Error('ikoeh_connect_https_required', 'HTTPS required.', ['status' => 400]);
            }

            $header = $request->get_header('authorization');
            if (empty($header) || stripos($header, 'Bearer ') !== 0) {
                return new WP_Error('ikoeh_connect_unauthorized', 'Missing bearer token.', ['status' => 401]);
            }

            $provided = trim(substr($header, 7));
            $index = self::find_connection_index_by_token($provided);

            if (null === $index) {
                return new WP_Error('ikoeh_connect_unauthorized', 'Invalid token.', ['status' => 401]);
            }

            $connection = self::get_connections()[$index];

            if (null !== $scope && !in_array($scope, $connection['scopes'], true)) {
                return new WP_Error(
                    'ikoeh_connect_forbidden',
                    "This connection does not have the required scope: {$scope}",
                    ['status' => 403]
                );
            }

            self::touch_last_used($index);

            return true;
        };
    }

    public static function setup_rate_limit_ok() {
        $key = 'ikoeh_connect_setup_attempts';
        $attempts = (int) get_transient($key);

        if ($attempts >= 10) {
            return false;
        }

        set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        return true;
    }
}
