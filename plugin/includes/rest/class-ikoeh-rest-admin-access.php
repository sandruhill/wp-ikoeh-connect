<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Temporary passwordless admin login for browser automation tools.
 *
 * Three-hop flow, adapted from the pattern the Novamira plugin uses for the
 * same purpose (novamira/create-admin-access-link, AGPL-3.0). Novamira's
 * version assumes the caller is already logged into wp-admin
 * (get_current_user_id()); ours is called by an external Bearer token with
 * no WP session at all, so step 1 always targets the site's primary
 * Administrator instead of taking a user parameter.
 *
 * Step 1 (POST /admin-access, scope-gated) issues a token+nonce pair valid
 * up to 600s, sent only in headers. Step 2 (POST /admin-access-exchange,
 * public but requires possession of that token+nonce) trades it for a
 * one-time login nonce valid at most 60s -- this is the hop that limits how
 * long a secret can sit inside something that ends up in a URL (browser
 * history, proxy logs). Step 3 (GET /admin-access-login?nonce=, public,
 * one-time) redeems that nonce and sets the auth cookie.
 */
class Ikoeh_Connect_Rest_Admin_Access {

    const TOKEN_HMAC_KEY_PREFIX = 'ikoeh_connect_admin_access_';
    const LOGIN_HMAC_KEY_PREFIX = 'ikoeh_connect_admin_access_login_';
    const NONCE_HASH_SALT = 'ikoeh-connect-admin-access';

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/admin-access', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('admin_access'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/admin-access-exchange', [
            'methods'             => 'POST',
            // Public on purpose: this route is protected by possession of the
            // one-time token+nonce issued by create(), not by a Bearer token.
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'exchange'],
        ]);

        // Query param, not a path segment ({nonce} would push this route to
        // 4 segments -- ikoeh-connect/v1/admin-access-login/{nonce} -- and
        // this host silently blocks any REST route with 4+ segments before
        // WordPress even sees the request (see class-ikoeh-rest-content.php).
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/admin-access-login', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'login'],
        ]);
    }

    /**
     * Step 1: issue a token+nonce pair for the primary administrator.
     */
    public static function create(WP_REST_Request $request) {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
        if (empty($admins)) {
            return new WP_Error('ikoeh_connect_no_admin', 'No administrator user found on this site.', ['status' => 404]);
        }
        $user_id = $admins[0]->ID;

        $expires_in = max(30, min(600, (int) ($request->get_param('expires_in') ?: 300)));
        $session_expires_in = max(60, min(3600, (int) ($request->get_param('session_expires_in') ?: 1800)));

        $redirect_url = self::resolve_redirect((string) ($request->get_param('admin_path') ?: ''));
        if (is_wp_error($redirect_url)) {
            return $redirect_url;
        }

        $token = wp_generate_password(64, false, false);
        $nonce = wp_generate_password(32, false, false);
        $expires_at = time() + $expires_in;

        $stored = set_transient(self::token_key($token), [
            'user_id'      => $user_id,
            'redirect_url' => $redirect_url,
            'expires_at'   => $expires_at,
            'session_expires_in' => $session_expires_in,
            'nonce_hash'   => self::nonce_hash($nonce),
        ], $expires_in);

        if (!$stored) {
            return new WP_Error('ikoeh_connect_admin_access_store_failed', 'Could not store admin access token.', ['status' => 500]);
        }

        error_log(sprintf(
            '[ikoeh-connect] admin-access link created for user #%d, expires_at=%d',
            $user_id,
            $expires_at
        ));

        $token_header = 'X-Ikoeh-Connect-Admin-Access-Token';
        $nonce_header = 'X-Ikoeh-Connect-Admin-Access-Nonce';
        $exchange_url = rest_url(IKOEH_CONNECT_REST_NAMESPACE . '/admin-access-exchange');

        return new WP_REST_Response([
            'exchange_url'        => $exchange_url,
            'exchange_method'     => 'POST',
            'access_token'        => $token,
            'token_header'        => $token_header,
            'access_nonce'        => $nonce,
            'nonce_header'        => $nonce_header,
            'expires_at'          => $expires_at,
            'session_expires_in'  => $session_expires_in,
            'redirect_url'        => $redirect_url,
            'one_time'            => true,
            'curl_example'        => sprintf(
                'curl -s -X POST -H "%s: $access_token" -H "%s: $access_nonce" %s',
                $token_header,
                $nonce_header,
                escapeshellarg($exchange_url)
            ),
        ], 200);
    }

    /**
     * Step 2: trade the header token+nonce for a short-lived, one-time login URL.
     */
    public static function exchange(WP_REST_Request $request) {
        $token = trim((string) $request->get_header('x-ikoeh-connect-admin-access-token'));
        $nonce = trim((string) $request->get_header('x-ikoeh-connect-admin-access-nonce'));

        if ('' === $token) {
            return new WP_Error('ikoeh_connect_missing_token', 'Missing admin access token.', ['status' => 401]);
        }
        if ('' === $nonce) {
            return new WP_Error('ikoeh_connect_missing_nonce', 'Missing admin access nonce.', ['status' => 401]);
        }

        $payload = get_transient(self::token_key($token));
        delete_transient(self::token_key($token)); // one-time: consume regardless of outcome

        if (!is_array($payload) || empty($payload['nonce_hash'])) {
            return new WP_Error('ikoeh_connect_invalid_token', 'Invalid or expired admin access token.', ['status' => 401]);
        }

        if (!hash_equals($payload['nonce_hash'], self::nonce_hash($nonce))) {
            return new WP_Error('ikoeh_connect_invalid_token', 'Invalid or expired admin access token.', ['status' => 401]);
        }

        $access = self::validate_payload($payload);
        if (is_wp_error($access)) {
            return $access;
        }

        $login_nonce = wp_generate_password(48, false, false);
        $login_expires_at = min($access['expires_at'], time() + 60);
        $login_expires_in = max(1, $login_expires_at - time());

        $stored = set_transient(self::login_key($login_nonce), [
            'user_id'             => $access['user_id'],
            'redirect_url'        => $access['redirect_url'],
            'expires_at'          => $login_expires_at,
            'session_expires_in'  => $access['session_expires_in'],
        ], $login_expires_in);

        if (!$stored) {
            return new WP_Error('ikoeh_connect_admin_access_store_failed', 'Could not store admin access login nonce.', ['status' => 500]);
        }

        $login_url = add_query_arg('nonce', $login_nonce, rest_url(IKOEH_CONNECT_REST_NAMESPACE . '/admin-access-login'));

        $response = new WP_REST_Response([
            'login_url'           => $login_url,
            'expires_at'          => $login_expires_at,
            'session_expires_in'  => $access['session_expires_in'],
            'redirect_url'        => $access['redirect_url'],
            'one_time'            => true,
        ], 200);
        self::no_cache_headers($response);
        return $response;
    }

    /**
     * Step 3: redeem the one-time login nonce and log the browser in.
     */
    public static function login(WP_REST_Request $request) {
        // Plain response headers (Cache-Control, X-LiteSpeed-Cache-Control)
        // were not enough: LiteSpeed Cache's WordPress plugin makes its own
        // caching decision, not just the webserver, and confirmed in
        // production it still cached this exact URL (x-litespeed-cache:
        // hit on a second request) even with those headers set. This is the
        // plugin's own documented hook for marking the current request
        // uncacheable, called as early as possible in case its decision
        // point runs before this method returns.
        do_action('litespeed_control_set_nocache', 'ikoeh-connect admin-access one-time login');

        $nonce = trim((string) $request->get_param('nonce'));
        if ('' === $nonce) {
            return new WP_Error('ikoeh_connect_missing_nonce', 'Missing admin access login nonce.', ['status' => 401]);
        }

        $payload = get_transient(self::login_key($nonce));
        delete_transient(self::login_key($nonce)); // one-time: consume regardless of outcome

        if (!is_array($payload)) {
            return new WP_Error('ikoeh_connect_invalid_nonce', 'Invalid or expired admin access login nonce.', ['status' => 401]);
        }

        $access = self::validate_payload($payload);
        if (is_wp_error($access)) {
            return $access;
        }

        $session_expires_in = $access['session_expires_in'];
        $limit_session = function () use ($session_expires_in) {
            return $session_expires_in;
        };

        add_filter('auth_cookie_expiration', $limit_session);
        try {
            wp_set_current_user($access['user_id']);
            wp_set_auth_cookie($access['user_id'], false, is_ssl());
        } finally {
            remove_filter('auth_cookie_expiration', $limit_session);
        }

        error_log(sprintf(
            '[ikoeh-connect] admin-access login redeemed for user #%d',
            $access['user_id']
        ));

        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $access['redirect_url']);
        $response->header('Referrer-Policy', 'no-referrer');
        self::no_cache_headers($response);
        return $response;
    }

    /**
     * Set anti-cache headers directly on the response object rather than
     * relying only on the global rest_pre_serve_request filter in
     * wp-ikoeh-connect.php: that filter calls header() itself and skips if
     * headers_sent() is already true, which happens for this route because
     * WP_REST_Server::send_headers() flushes the Location/status line for a
     * redirect before the filter runs. Confirmed in production: without
     * this, LiteSpeed cached the 302 by URL (x-litespeed-cache: hit on a
     * second request), replaying the redirect instead of running the
     * one-time-use check that should reject a reused nonce -- the exact
     * same class of bug already documented for GET responses on this host
     * (see the Authorization-unaware caching note in wp-ikoeh-connect.php).
     */
    private static function no_cache_headers(WP_REST_Response $response) {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
    }

    /**
     * Shared validation for both the exchange and login transient payloads:
     * not expired, target user still exists and is still an administrator,
     * redirect still points inside wp-admin.
     */
    private static function validate_payload(array $payload) {
        $expires_at = (int) ($payload['expires_at'] ?? 0);
        if ($expires_at < time()) {
            return new WP_Error('ikoeh_connect_invalid_token', 'Invalid or expired admin access token.', ['status' => 401]);
        }

        $user_id = (int) ($payload['user_id'] ?? 0);
        $user = get_user_by('id', $user_id);
        if (!$user || !user_can($user, 'administrator')) {
            return new WP_Error('ikoeh_connect_invalid_token', 'Invalid or expired admin access token.', ['status' => 401]);
        }

        $redirect_url = (string) ($payload['redirect_url'] ?? '');
        if ('' === $redirect_url || 0 !== strpos($redirect_url, admin_url())) {
            return new WP_Error('ikoeh_connect_invalid_token', 'Invalid or expired admin access token.', ['status' => 401]);
        }

        return [
            'user_id'             => $user_id,
            'redirect_url'        => $redirect_url,
            'expires_at'          => $expires_at,
            'session_expires_in'  => max(60, min(3600, (int) ($payload['session_expires_in'] ?? 1800))),
        ];
    }

    /**
     * Resolve an optional admin-relative redirect path; rejects anything
     * that could be used as an open redirect (absolute URL, protocol-relative,
     * CRLF injection).
     */
    private static function resolve_redirect($admin_path) {
        $admin_path = trim($admin_path);
        if ('' === $admin_path) {
            return admin_url();
        }

        if (
            false !== strpos($admin_path, "\r")
            || false !== strpos($admin_path, "\n")
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $admin_path) === 1
            || 0 === strpos($admin_path, '//')
        ) {
            return new WP_Error(
                'ikoeh_connect_invalid_redirect',
                'admin_path must be relative to wp-admin, not an absolute URL.',
                ['status' => 400]
            );
        }

        $admin_path = ltrim($admin_path, '/');
        if (0 === strpos($admin_path, 'wp-admin/')) {
            $admin_path = substr($admin_path, strlen('wp-admin/'));
        }

        return admin_url($admin_path);
    }

    private static function token_key($token) {
        return self::TOKEN_HMAC_KEY_PREFIX . hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private static function login_key($nonce) {
        return self::LOGIN_HMAC_KEY_PREFIX . hash_hmac('sha256', $nonce, wp_salt('auth'));
    }

    private static function nonce_hash($nonce) {
        return hash_hmac('sha256', $nonce, wp_salt('nonce') . '|' . self::NONCE_HASH_SALT);
    }
}
