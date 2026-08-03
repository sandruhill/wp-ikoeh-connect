<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool definitions and dispatch for the chat's two tool-calling surfaces:
 * QUICK_TOOL_DEFINITIONS (exposed on every interactive chat turn, currently
 * just start_site_clone, which only creates a job row and returns fast) and
 * CLONE_TOOL_DEFINITIONS (exposed only inside the background cron loop that
 * actually builds a page, never in the interactive chat request). Nothing
 * else is ever exposed: no plugin/theme/database/system/eval/wp-cli/delete
 * tool exists anywhere in this class, by design, permanently.
 */
class Ikoeh_Connect_Chat_Tools {

    const QUICK_TOOL_DEFINITIONS = [
        [
            'name' => 'start_site_clone',
            'description' => 'Start cloning an external site\'s page into a new page on this site. This only queues the job and returns immediately -- the actual cloning happens in the background over the next few minutes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The full URL of the page to clone.'],
                ],
                'required' => ['url'],
            ],
        ],
    ];

    const CLONE_TOOL_DEFINITIONS = [
        [
            'name' => 'create_page',
            'description' => 'Create a new WordPress page (empty content, to be filled in by write_page_content next). Returns the new page id.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
        ],
        [
            'name' => 'write_page_content',
            'description' => 'Write the full Elementor element tree for a page. Overwrites any existing content for that page. Every call is automatically reversible (undo_last_change tool exists separately for the site owner, not for you).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'elements' => ['type' => 'array', 'description' => 'Elementor element tree, same shape as the /elementor-content PUT body.'],
                ],
                'required' => ['post_id', 'elements'],
            ],
        ],
        [
            'name' => 'upload_media',
            'description' => 'Download an image from a URL (e.g. from the reference site being cloned) and upload it to this site\'s own media library. Never hotlink external images -- always call this first and use the returned url in write_page_content.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'image_url' => ['type' => 'string'],
                ],
                'required' => ['image_url'],
            ],
        ],
        [
            'name' => 'list_pages',
            'description' => 'List existing pages on this site, for context (e.g. to avoid duplicate titles).',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ],
    ];

    public static function execute_quick_tool($name, $input, $created_by) {
        if ('start_site_clone' !== $name) {
            return ['error' => "Unknown tool: {$name}"];
        }

        $url = isset($input['url']) ? esc_url_raw($input['url']) : '';
        if ('' === $url) {
            return ['error' => 'Missing or invalid url.'];
        }

        $job_id = Ikoeh_Connect_Clone_Store::create_job($url, $created_by);
        if (is_wp_error($job_id)) {
            return ['error' => $job_id->get_error_message()];
        }

        return ['job_id' => $job_id, 'status' => 'queued'];
    }

    public static function execute_clone_tool($name, $input, $job_id) {
        switch ($name) {
            case 'create_page':
                return self::tool_create_page($input, $job_id);
            case 'write_page_content':
                return self::tool_write_page_content($input);
            case 'upload_media':
                return self::tool_upload_media($input);
            case 'list_pages':
                return self::tool_list_pages();
            default:
                return ['error' => "Unknown tool: {$name}"];
        }
    }

    private static function tool_create_page($input, $job_id) {
        $title = isset($input['title']) ? sanitize_text_field($input['title']) : '';
        if ('' === $title) {
            return ['error' => 'Missing title.'];
        }

        $post_id = wp_insert_post([
            'post_title' => wp_slash($title),
            'post_type' => 'page',
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($post_id)) {
            return ['error' => $post_id->get_error_message()];
        }

        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        Ikoeh_Connect_Clone_Store::set_target_post($job_id, $post_id);

        return ['post_id' => $post_id, 'edit_url' => get_edit_post_link($post_id, 'raw'), 'view_url' => get_permalink($post_id)];
    }

    private static function tool_write_page_content($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        $elements = isset($input['elements']) && is_array($input['elements']) ? $input['elements'] : null;

        if (!$post_id || null === $elements) {
            return ['error' => 'Missing post_id or elements.'];
        }

        Ikoeh_Connect_Chat_Snapshots::save_snapshot($post_id);

        $result = Ikoeh_Connect_Rest_Elementor::write_content($post_id, $elements);
        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message()];
        }

        return ['written' => true, 'post_id' => $post_id];
    }

    private static function tool_upload_media($input) {
        $image_url = isset($input['image_url']) ? esc_url_raw($input['image_url']) : '';
        if ('' === $image_url) {
            return ['error' => 'Missing image_url.'];
        }

        $response = wp_remote_get($image_url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => "Failed to download {$image_url} (HTTP {$code})."];
        }

        $bytes = wp_remote_retrieve_body($response);
        $filename = basename(parse_url($image_url, PHP_URL_PATH)) ?: 'image.jpg';

        $attachment_id = Ikoeh_Connect_Rest_Media::sideload_bytes($filename, $bytes);
        if (is_wp_error($attachment_id)) {
            return ['error' => $attachment_id->get_error_message()];
        }

        return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
    }

    private static function tool_list_pages() {
        $query = new WP_Query(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 50]);
        $pages = [];
        foreach ($query->posts as $post) {
            $pages[] = ['id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink($post->ID)];
        }
        return ['pages' => $pages];
    }
}
