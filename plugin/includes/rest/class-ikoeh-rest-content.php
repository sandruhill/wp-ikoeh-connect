<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Content {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_content'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [__CLASS__, 'update_content'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('content'),
        ]);
    }

    public static function get_content(WP_REST_Request $request) {
        $post = get_post((int) $request->get_param('id'));

        if (!$post) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        return new WP_REST_Response([
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'status'  => $post->post_status,
            'type'    => $post->post_type,
            'meta'    => get_post_meta($post->ID),
        ], 200);
    }

    public static function update_content(WP_REST_Request $request) {
        $id = (int) $request->get_param('id');

        if (!get_post($id)) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $params = $request->get_json_params();
        $update = ['ID' => $id];

        if (isset($params['title'])) {
            $update['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $update['post_content'] = wp_slash($params['content']);
        }

        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                return new WP_Error('ikoeh_connect_update_failed', $result->get_error_message(), ['status' => 400]);
            }
        }

        if (isset($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($id, sanitize_key($key), $value);
            }
        }

        return new WP_REST_Response(['updated' => $id], 200);
    }
}
