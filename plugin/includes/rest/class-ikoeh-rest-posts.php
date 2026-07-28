<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Posts {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/posts', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_posts'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create_post'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
            ],
        ]);
    }

    public static function list_posts(WP_REST_Request $request) {
        $type = $request->get_param('type') ?: 'any';
        $status = $request->get_param('status') ?: 'any';

        $query = new WP_Query([
            'post_type'      => sanitize_key($type) === 'any' ? 'any' : sanitize_key($type),
            'post_status'    => $status === 'any' ? 'any' : sanitize_key($status),
            'posts_per_page' => 100,
        ]);

        $result = [];
        foreach ($query->posts as $post) {
            $result[] = [
                'id'     => $post->ID,
                'title'  => $post->post_title,
                'type'   => $post->post_type,
                'status' => $post->post_status,
            ];
        }
        return new WP_REST_Response($result, 200);
    }

    public static function create_post(WP_REST_Request $request) {
        $params = $request->get_json_params();

        if (empty($params['title'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Missing "title".', ['status' => 400]);
        }
        if (empty($params['type'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Missing "type" (e.g. "post" or "page").', ['status' => 400]);
        }

        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($params['title']),
            'post_type'    => sanitize_key($params['type']),
            'post_status'  => isset($params['status']) ? sanitize_key($params['status']) : 'draft',
            'post_content' => isset($params['content']) ? wp_slash($params['content']) : '',
        ], true);

        if (is_wp_error($post_id)) {
            return new WP_Error('ikoeh_connect_create_failed', $post_id->get_error_message(), ['status' => 400]);
        }

        return new WP_REST_Response(['id' => $post_id], 201);
    }
}
