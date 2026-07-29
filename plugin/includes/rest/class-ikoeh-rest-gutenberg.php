<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Gutenberg {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-batch', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'cancel_batch'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-batches', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_batches'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-item', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_item'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'cancel_item'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-enable-finalization', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'enable_finalization'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-content', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_content'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-claim-batch', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'claim_batch'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-claim-item', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'claim_item'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-complete-item', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'complete_item'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-heartbeat', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'heartbeat'],
            'permission_callback' => [__CLASS__, 'require_admin_session'],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/gutenberg-runtime', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'runtime'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('gutenberg'),
        ]);
    }

    private static function respond($result) {
        if (is_wp_error($result)) {
            return $result;
        }
        return new WP_REST_Response($result, 200);
    }

    public static function create_batch(WP_REST_Request $request) {
        $label = (string) ($request->get_param('label') ?: '');
        $agent_note = (string) ($request->get_param('agent_note') ?: '');
        $batch_id = Ikoeh_Connect_Gutenberg_Store::create_batch($label, $agent_note);
        if (is_wp_error($batch_id)) {
            return $batch_id;
        }
        return new WP_REST_Response(['batch_id' => $batch_id], 200);
    }

    public static function list_batches(WP_REST_Request $request) {
        $status = $request->get_param('status');
        $statuses = $status ? [sanitize_key($status)] : null;
        $batches = Ikoeh_Connect_Gutenberg_Store::get_batches($statuses);
        return new WP_REST_Response(array_map(['Ikoeh_Connect_Gutenberg_Store', 'shape_batch'], $batches), 200);
    }

    public static function get_batch(WP_REST_Request $request) {
        $batch = Ikoeh_Connect_Gutenberg_Store::find_batch((int) $request->get_param('id'));
        if (!$batch) {
            return new WP_Error('ikoeh_connect_batch_not_found', 'Gutenberg batch not found.', ['status' => 404]);
        }
        return new WP_REST_Response(Ikoeh_Connect_Gutenberg_Store::shape_batch($batch), 200);
    }

    public static function cancel_batch(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::cancel_batch((int) $request->get_param('id')));
    }

    public static function create_item(WP_REST_Request $request) {
        $blocks = Ikoeh_Connect_Gutenberg_Store::normalize_blocks($request->get_param('block_spec'));
        if (is_wp_error($blocks)) {
            return $blocks;
        }

        $item_id = Ikoeh_Connect_Gutenberg_Store::create_item(
            (int) $request->get_param('batch_id'),
            (int) $request->get_param('target_id'),
            (string) ($request->get_param('target_type') ?: 'post'),
            (string) ($request->get_param('operation') ?: 'update'),
            $blocks
        );
        if (is_wp_error($item_id)) {
            return $item_id;
        }
        return new WP_REST_Response(['item_id' => $item_id], 200);
    }

    public static function cancel_item(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::cancel_item((int) $request->get_param('id')));
    }

    public static function enable_finalization(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::enable_finalization((int) $request->get_param('batch_id')));
    }

    public static function get_content(WP_REST_Request $request) {
        return self::respond(Ikoeh_Connect_Gutenberg_Store::get_target_blocks((int) $request->get_param('id')));
    }

    /**
     * Gated by a logged-in wp-admin session with a valid REST nonce, not the
     * Bearer-token scope system -- these routes are called only by the Fila
     * de Blocos admin page's own JS, running in the operator's browser.
     */
    public static function require_admin_session() {
        return current_user_can('edit_posts');
    }

    public static function claim_batch(WP_REST_Request $request) {
        $result = Ikoeh_Connect_Gutenberg_Store::claim_batch((int) $request->get_param('id'));
        if (is_wp_error($result)) {
            return $result;
        }
        $response = new WP_REST_Response($result, 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function claim_item(WP_REST_Request $request) {
        $result = Ikoeh_Connect_Gutenberg_Store::claim_next_item(
            (int) $request->get_param('batch_id'),
            (string) $request->get_param('lease_owner')
        );
        if (is_wp_error($result)) {
            return $result;
        }
        $response = new WP_REST_Response($result, 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function complete_item(WP_REST_Request $request) {
        $result = Ikoeh_Connect_Gutenberg_Store::complete_item(
            (int) $request->get_param('item_id'),
            (string) $request->get_param('lease_owner'),
            (string) $request->get_param('content'),
            $request->get_param('validations')
        );
        if (is_wp_error($result)) {
            return $result;
        }
        $response = new WP_REST_Response($result, 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function heartbeat() {
        Ikoeh_Connect_Gutenberg_Store::heartbeat();
        $response = new WP_REST_Response(['ok' => true], 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }

    public static function runtime(WP_REST_Request $request) {
        $response = new WP_REST_Response(Ikoeh_Connect_Gutenberg_Store::runtime_status((int) $request->get_param('id')), 200);
        Ikoeh_Connect_Gutenberg_Store::no_cache_headers($response);
        return $response;
    }
}
