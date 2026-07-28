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

    }

    // Task 2 adds a third register_rest_route() call here for /elementor-content
    // (GET get_content, PUT update_content), plus the get_content/update_content/
    // normalize_elements methods below require_elementor_active().

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
}
