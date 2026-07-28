<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ikoeh_Connect_Rest_Elementor {

    public static function register_routes() {
        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-widgets', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_widgets'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-templates', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_templates'],
            'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
        ]);

        register_rest_route(IKOEH_CONNECT_REST_NAMESPACE, '/elementor-content', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_content'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [__CLASS__, 'update_content'],
                'permission_callback' => Ikoeh_Connect_Auth::require_scope('elementor'),
            ],
        ]);

    }

    /**
     * Shared guard: every Elementor endpoint needs Elementor active. Task 2's
     * get_content()/update_content() call this too.
     */
    private static function require_elementor_active() {
        if (!class_exists('\Elementor\Plugin')) {
            return new WP_Error('ikoeh_connect_elementor_missing', 'Elementor is not active on this site.', ['status' => 400]);
        }
        return null;
    }

    public static function get_widgets(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $manager = \Elementor\Plugin::$instance->widgets_manager;
        $type = $request->get_param('type');

        if ($type) {
            $widget = $manager->get_widget_types($type);
            if (!$widget) {
                return new WP_Error('ikoeh_connect_not_found', "Widget type '{$type}' not found.", ['status' => 404]);
            }
            return new WP_REST_Response([
                'name'       => $widget->get_name(),
                'title'      => $widget->get_title(),
                'icon'       => $widget->get_icon(),
                'categories' => $widget->get_categories(),
                'controls'   => $widget->get_controls(),
            ], 200);
        }

        $list = [];
        foreach ($manager->get_widget_types() as $name => $widget) {
            $list[] = [
                'name'  => $name,
                'title' => $widget->get_title(),
                'icon'  => $widget->get_icon(),
            ];
        }
        return new WP_REST_Response($list, 200);
    }

    public static function get_templates(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $query = new WP_Query([
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        $templates = [];
        foreach ($query->posts as $post) {
            $templates[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'type'  => get_post_meta($post->ID, '_elementor_template_type', true),
            ];
        }
        return new WP_REST_Response($templates, 200);
    }

    public static function get_content(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $raw = get_post_meta($id, '_elementor_data', true);
        $elements = $raw ? json_decode($raw, true) : [];
        if (!is_array($elements)) {
            $elements = [];
        }

        return new WP_REST_Response([
            'id'        => $id,
            'elements'  => $elements,
            'edit_mode' => get_post_meta($id, '_elementor_edit_mode', true),
        ], 200);
    }

    public static function update_content(WP_REST_Request $request) {
        $guard = self::require_elementor_active();
        if ($guard) {
            return $guard;
        }

        $id = (int) $request->get_param('id');
        if (!get_post($id)) {
            return new WP_Error('ikoeh_connect_not_found', 'Post not found.', ['status' => 404]);
        }

        $params = $request->get_json_params();
        if (!isset($params['elements']) || !is_array($params['elements'])) {
            return new WP_Error('ikoeh_connect_invalid_params', 'Body must include an "elements" array.', ['status' => 400]);
        }

        $normalized = self::normalize_elements($params['elements']);
        $encoded = wp_json_encode($normalized);

        // wp_slash() before update_post_meta(): WordPress core runs
        // wp_unslash() internally and assumes slashed input, so real
        // backslashes in the encoded JSON (\n, \" inside string values) get
        // silently stripped without this, corrupting the stored data.
        update_post_meta($id, '_elementor_data', wp_slash($encoded));
        update_post_meta($id, '_elementor_edit_mode', 'builder');

        // Elementor caches rendered output in three places. Clearing only
        // some of them leaves the page serving stale content even though
        // _elementor_data is correct.
        delete_post_meta($id, '_elementor_css');
        delete_post_meta($id, '_elementor_page_assets');
        delete_post_meta($id, '_elementor_element_cache');

        return new WP_REST_Response(['updated' => $id], 200);
    }

    /**
     * Recursively fix the two schema issues that make Elementor 4.x silently
     * discard a manually-written element tree:
     * - every node needs an "elements" key, even if empty ([] not absent)
     * - top-level containers need settings.content_width = "full", or they
     *   render boxed at 1140px regardless of inner widget config
     */
    private static function normalize_elements(array $elements) {
        foreach ($elements as &$element) {
            if (!isset($element['elements']) || !is_array($element['elements'])) {
                $element['elements'] = [];
            }

            if (($element['elType'] ?? null) === 'container') {
                if (!isset($element['settings']) || !is_array($element['settings'])) {
                    $element['settings'] = [];
                }
                if (!isset($element['settings']['content_width'])) {
                    $element['settings']['content_width'] = 'full';
                }
            }

            if (!empty($element['elements'])) {
                $element['elements'] = self::normalize_elements($element['elements']);
            }
        }
        return $elements;
    }
}
