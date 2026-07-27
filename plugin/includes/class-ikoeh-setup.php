<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Setup {

    const CLAIMED_OPTION = 'ikoeh_connect_setup_claimed';

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/setup', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_setup'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_setup(WP_REST_Request $request) {
        if (!defined('IKOEH_CONNECT_SETUP_KEY')) {
            return new WP_Error('ikoeh_connect_setup_disabled', 'Setup is not enabled on this install.', ['status' => 404]);
        }

        if (get_option(self::CLAIMED_OPTION, false)) {
            return new WP_Error('ikoeh_connect_setup_claimed', 'Setup key already claimed.', ['status' => 410]);
        }

        if (!Ikoeh_Connect_Auth::setup_rate_limit_ok()) {
            return new WP_Error('ikoeh_connect_rate_limited', 'Too many attempts. Try again later.', ['status' => 429]);
        }

        $provided_key = $request->get_header('x-setup-key');

        if (empty($provided_key) || !hash_equals(IKOEH_CONNECT_SETUP_KEY, $provided_key)) {
            return new WP_Error('ikoeh_connect_invalid_setup_key', 'Invalid setup key.', ['status' => 401]);
        }

        $token = Ikoeh_Connect_Auth::create_connection('Setup', Ikoeh_Connect_Auth::ALL_SCOPES);
        update_option(self::CLAIMED_OPTION, true, false);

        return new WP_REST_Response(['token' => $token], 200);
    }
}
