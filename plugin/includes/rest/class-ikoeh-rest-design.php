<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Design {

    const POST_TYPE = 'ikoeh_design';
    const OPTION_ACTIVE = 'ikoeh_active_design';

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'iKOEH Designs',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'supports' => ['title', 'editor'],
        ]);
    }

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design', [
            [
                'methods' => 'POST',
                'callback' => [self::class, 'save_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'delete_design'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-library', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_library'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-activate', [
            'methods' => 'POST',
            'callback' => [self::class, 'activate_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-active', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_active_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/design-check', [
            'methods' => 'GET',
            'callback' => [self::class, 'check_design'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('design'),
        ]);
    }

    private static function find_by_slug($slug) {
        if ('' === $slug) {
            return null;
        }
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'name' => $slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);
        return $posts[0] ?? null;
    }

    private static function shape_full($post) {
        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($post->post_content);
        return [
            'slug' => $post->post_name,
            'name' => '' !== $meta['name'] ? $meta['name'] : $post->post_title,
            'description' => $meta['description'],
            'content' => $post->post_content,
            'tokens' => Ikoeh_Connect_Design_Tokens::extract($post->post_content),
        ];
    }

    private static function shape_summary($post) {
        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($post->post_content);
        return [
            'slug' => $post->post_name,
            'name' => '' !== $meta['name'] ? $meta['name'] : $post->post_title,
            'description' => $meta['description'],
        ];
    }

    public static function save_design(WP_REST_Request $request) {
        $content = (string) ($request->get_param('content') ?: '');
        if ('' === trim($content)) {
            return new WP_Error('ikoeh_connect_invalid_content', 'content is required.', ['status' => 400]);
        }

        $meta = Ikoeh_Connect_Design_Tokens::parse_name_description($content);
        $requested_slug = (string) ($request->get_param('slug') ?: '');
        $slug = sanitize_title('' !== $requested_slug ? $requested_slug : $meta['name']);
        if ('' === $slug) {
            return new WP_Error('ikoeh_connect_invalid_slug', 'Provide a slug, or a name: field in the content front matter.', ['status' => 400]);
        }

        $existing = self::find_by_slug($slug);
        $postarr = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => wp_slash('' !== $meta['name'] ? $meta['name'] : $slug),
            'post_name' => $slug,
            'post_content' => wp_slash($content),
        ];

        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return new WP_REST_Response(['slug' => $slug, 'name' => $meta['name']], 200);
    }

    public static function get_design(WP_REST_Request $request) {
        $post = self::find_by_slug(sanitize_title((string) $request->get_param('slug')));
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        return new WP_REST_Response(self::shape_full($post), 200);
    }

    public static function delete_design(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }

        $was_active = get_option(self::OPTION_ACTIVE, '') === $slug;
        wp_delete_post($post->ID, true);
        if ($was_active) {
            update_option(self::OPTION_ACTIVE, '');
        }

        return new WP_REST_Response(['deleted' => $slug, 'was_active' => $was_active], 200);
    }

    public static function list_library(WP_REST_Request $request) {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        return new WP_REST_Response(array_map([self::class, 'shape_summary'], $posts), 200);
    }

    public static function activate_design(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        update_option(self::OPTION_ACTIVE, $slug);
        return new WP_REST_Response(['active' => $slug], 200);
    }

    public static function get_active_design(WP_REST_Request $request) {
        $slug = (string) get_option(self::OPTION_ACTIVE, '');
        if ('' === $slug) {
            return new WP_REST_Response(['active' => null], 200);
        }
        $post = self::find_by_slug($slug);
        if (!$post) {
            // Active slug points at a deleted post -- self-heal rather than error.
            update_option(self::OPTION_ACTIVE, '');
            return new WP_REST_Response(['active' => null], 200);
        }
        return new WP_REST_Response(self::shape_full($post), 200);
    }

    public static function check_design(WP_REST_Request $request) {
        $post = self::find_by_slug(sanitize_title((string) $request->get_param('slug')));
        if (!$post) {
            return new WP_Error('ikoeh_connect_design_not_found', 'Design not found.', ['status' => 404]);
        }
        $tokens = Ikoeh_Connect_Design_Tokens::extract($post->post_content);
        return new WP_REST_Response(Ikoeh_Connect_Design_Tokens::readiness($tokens), 200);
    }
}
