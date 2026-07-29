<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable named prompts/procedures ("skills") stored as WordPress
 * content. `enable_prompt` skills get registered as real MCP prompts by
 * the Node MCP server at startup (Task 3); `enable_agentic` skills are
 * fetched on demand by the agent via the wp_get_skill MCP tool (Task 2).
 */
class Ikoeh_Connect_Rest_Skills {

    const POST_TYPE = 'ikoeh_skill';
    const META_ENABLE_PROMPT = '_ikoeh_skill_enable_prompt';
    const META_ENABLE_AGENTIC = '_ikoeh_skill_enable_agentic';
    const MAX_BODY_BYTES = 1048576;

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'iKOEH Skills',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'supports' => ['title', 'editor', 'excerpt'],
        ]);
    }

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/skill', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_or_update'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('skills'),
            ],
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_skill'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('skills'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [__CLASS__, 'delete_skill'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('skills'),
            ],
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/skills', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_skills'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('skills'),
        ]);
    }

    private static function find_by_slug($slug) {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft'],
            'name' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);
        return $posts[0] ?? null;
    }

    private static function find_free_suffix($slug) {
        $i = 2;
        while (self::find_by_slug($slug . '-' . $i) !== null) {
            $i++;
            if ($i > 9999) {
                return $slug . '-' . time();
            }
        }
        return $slug . '-' . $i;
    }

    private static function shape($post) {
        return [
            'id' => $post->ID,
            'slug' => $post->post_name,
            'description' => $post->post_excerpt,
            'enable_prompt' => (bool) get_post_meta($post->ID, self::META_ENABLE_PROMPT, true),
            'enable_agentic' => (bool) get_post_meta($post->ID, self::META_ENABLE_AGENTIC, true),
        ];
    }

    public static function create_or_update(WP_REST_Request $request) {
        $title = sanitize_title((string) ($request->get_param('title') ?: ''));
        if ('' === $title) {
            return new WP_Error('ikoeh_connect_invalid_title', 'title is required and must contain at least one letter or digit.', ['status' => 400]);
        }

        $description = trim((string) ($request->get_param('description') ?: ''));
        $content = (string) ($request->get_param('content') ?: '');
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return new WP_Error('ikoeh_connect_body_too_large', 'content exceeds 1 MB.', ['status' => 400]);
        }

        $on_conflict = (string) ($request->get_param('on_conflict') ?: 'fail');
        if (!in_array($on_conflict, ['fail', 'replace', 'rename'], true)) {
            $on_conflict = 'fail';
        }

        $existing = self::find_by_slug($title);
        $slug = $title;
        $action = 'created';
        $post_id_to_update = null;

        if ($existing) {
            if ('fail' === $on_conflict) {
                return new WP_Error('ikoeh_connect_slug_exists', 'A skill with this title already exists.', [
                    'status' => 409,
                    'slug' => $title,
                    'suggested_slug' => self::find_free_suffix($title),
                ]);
            }
            if ('rename' === $on_conflict) {
                $slug = self::find_free_suffix($title);
                $action = 'renamed';
            } else {
                $post_id_to_update = $existing->ID;
                $action = 'updated';
            }
        }

        $enable_prompt = null === $request->get_param('enable_prompt') ? true : filter_var($request->get_param('enable_prompt'), FILTER_VALIDATE_BOOLEAN);
        $enable_agentic = null === $request->get_param('enable_agentic') ? true : filter_var($request->get_param('enable_agentic'), FILTER_VALIDATE_BOOLEAN);

        $postarr = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => wp_slash($slug),
            'post_name' => $slug,
            'post_excerpt' => wp_slash($description),
            'post_content' => wp_slash($content),
        ];

        if ($post_id_to_update) {
            $postarr['ID'] = $post_id_to_update;
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, self::META_ENABLE_PROMPT, $enable_prompt);
        update_post_meta($post_id, self::META_ENABLE_AGENTIC, $enable_agentic);

        return new WP_REST_Response(['success' => true, 'slug' => $slug, 'action' => $action], 200);
    }

    public static function list_skills(WP_REST_Request $request) {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);
        return new WP_REST_Response(array_map([self::class, 'shape'], $posts), 200);
    }

    public static function get_skill(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_skill_not_found', 'Skill not found.', ['status' => 404]);
        }
        $data = self::shape($post);
        $data['content'] = $post->post_content;
        return new WP_REST_Response($data, 200);
    }

    public static function delete_skill(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $post = self::find_by_slug($slug);
        if (!$post) {
            return new WP_Error('ikoeh_connect_skill_not_found', 'Skill not found.', ['status' => 404]);
        }
        wp_delete_post($post->ID, true);
        return new WP_REST_Response(['deleted' => $slug], 200);
    }
}
