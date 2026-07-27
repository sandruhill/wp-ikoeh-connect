<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Auth {

    const TOKEN_HASH_OPTION = 'ikoeh_connect_token_hash';

    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }

    public static function hash_token($token) {
        return hash('sha256', $token);
    }

    public static function store_token($token) {
        update_option(self::TOKEN_HASH_OPTION, self::hash_token($token), false);
    }

    public static function has_token() {
        return (bool) get_option(self::TOKEN_HASH_OPTION, false);
    }

    public static function verify_request(WP_REST_Request $request) {
        if (!is_ssl()) {
            return new WP_Error('ikoeh_connect_https_required', 'HTTPS required.', ['status' => 400]);
        }

        $header = $request->get_header('authorization');
        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            return new WP_Error('ikoeh_connect_unauthorized', 'Missing bearer token.', ['status' => 401]);
        }

        $provided = trim(substr($header, 7));
        $stored_hash = get_option(self::TOKEN_HASH_OPTION, '');

        if (empty($stored_hash) || !hash_equals($stored_hash, self::hash_token($provided))) {
            return new WP_Error('ikoeh_connect_unauthorized', 'Invalid token.', ['status' => 401]);
        }

        return true;
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
