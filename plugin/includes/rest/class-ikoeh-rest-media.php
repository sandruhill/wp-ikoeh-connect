<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Media {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/media', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'upload'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('media'),
        ]);
    }

    public static function upload(WP_REST_Request $request) {
        $filename = $request->get_header('x-filename');
        if (empty($filename)) {
            return new WP_Error('ikoeh_connect_invalid_request', 'Missing X-Filename header.', ['status' => 400]);
        }

        $body = $request->get_body();
        if (empty($body)) {
            return new WP_Error('ikoeh_connect_empty_body', 'No image bytes provided.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = wp_tempnam(sanitize_file_name($filename));
        file_put_contents($tmp, $body);

        $file_array = [
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        // media_handle_sideload() runs WordPress's own mime/extension
        // validation (wp_check_filetype_and_ext) before accepting the file,
        // so a renamed non-image can't sneak in as an "image" upload.
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            return new WP_Error('ikoeh_connect_upload_failed', $attachment_id->get_error_message(), ['status' => 400]);
        }

        return new WP_REST_Response([
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ], 201);
    }
}
