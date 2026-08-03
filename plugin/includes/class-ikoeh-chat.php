<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side chat: the site owner's own Anthropic API key, a single
 * continuing conversation history, and a plain (no tool-calling) message
 * exchange. Entirely wp-admin-session-authenticated -- not part of the
 * external Bearer-token/MCP system at all.
 */
class Ikoeh_Connect_Chat {

    const OPTION_API_KEY = 'ikoeh_chat_api_key';
    const OPTION_MODEL = 'ikoeh_chat_model';
    const OPTION_HISTORY = 'ikoeh_chat_history';
    const DEFAULT_MODEL = 'claude-sonnet-5';
    const MAX_HISTORY_MESSAGES = 50;
    const MAX_TOKENS = 1024;
    const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    public static function register_ajax() {
        add_action('wp_ajax_ikoeh_chat_send', [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_ikoeh_chat_clone_status', [__CLASS__, 'ajax_clone_status']);
        add_action('wp_ajax_ikoeh_chat_undo', [__CLASS__, 'ajax_undo']);
    }

    public static function get_history() {
        $history = get_option(self::OPTION_HISTORY, []);
        return is_array($history) ? $history : [];
    }

    public static function save_history_public(array $history) {
        return self::save_history($history);
    }

    private static function save_history(array $history) {
        $trimmed = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        update_option(self::OPTION_HISTORY, $trimmed, false);
        return $trimmed;
    }

    public static function ajax_send() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $api_key = get_option(self::OPTION_API_KEY, '');
        if ('' === $api_key) {
            wp_send_json_error(['message' => 'Configure uma chave de API da Anthropic primeiro.']);
        }

        $message = isset($_POST['message']) ? trim(wp_unslash($_POST['message'])) : '';
        if ('' === $message) {
            wp_send_json_error(['message' => 'Mensagem vazia.']);
        }

        $history = self::get_history();
        $history[] = ['role' => 'user', 'content' => $message, 'timestamp' => time()];

        $model = get_option(self::OPTION_MODEL, '') ?: self::DEFAULT_MODEL;

        $api_messages = array_map(function ($entry) {
            return ['role' => $entry['role'], 'content' => $entry['content']];
        }, $history);

        $response = wp_remote_post(self::ANTHROPIC_API_URL, [
            'timeout' => 60,
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => $api_messages,
                'tools' => Ikoeh_Connect_Chat_Tools::QUICK_TOOL_DEFINITIONS,
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            $error_message = (is_array($body) && isset($body['error']['message']))
                ? $body['error']['message']
                : "Erro da API da Anthropic (HTTP {$code}).";
            wp_send_json_error(['message' => $error_message]);
        }

        $reply_text = '';
        $tool_use_block = null;
        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (isset($block['type']) && 'text' === $block['type'] && isset($block['text'])) {
                    $reply_text .= $block['text'];
                } elseif (isset($block['type']) && 'tool_use' === $block['type']) {
                    $tool_use_block = $block;
                }
            }
        }

        if (null !== $tool_use_block) {
            $tool_result = Ikoeh_Connect_Chat_Tools::execute_quick_tool(
                $tool_use_block['name'],
                is_array($tool_use_block['input'] ?? null) ? $tool_use_block['input'] : [],
                get_current_user_id()
            );

            $follow_up_messages = array_merge($api_messages, [
                ['role' => 'assistant', 'content' => $body['content']],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $tool_use_block['id'],
                    'content' => wp_json_encode($tool_result),
                ]]],
            ]);

            $follow_up = wp_remote_post(self::ANTHROPIC_API_URL, [
                'timeout' => 60,
                'headers' => [
                    'x-api-key' => $api_key,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages' => $follow_up_messages,
                ]),
            ]);

            if (!is_wp_error($follow_up)) {
                $follow_up_body = json_decode(wp_remote_retrieve_body($follow_up), true);
                if (is_array($follow_up_body) && isset($follow_up_body['content']) && is_array($follow_up_body['content'])) {
                    foreach ($follow_up_body['content'] as $block) {
                        if (isset($block['type'], $block['text']) && 'text' === $block['type']) {
                            $reply_text .= $block['text'];
                        }
                    }
                }
            }
        }

        if ('' === $reply_text) {
            $reply_text = '(resposta vazia)';
        }

        $history[] = ['role' => 'assistant', 'content' => $reply_text, 'timestamp' => time()];
        $history = self::save_history($history);

        wp_send_json_success(['history' => $history]);
    }

    public static function ajax_clone_status() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $job = Ikoeh_Connect_Clone_Store::get_oldest_non_terminal_job();
        if (!$job) {
            $recent = get_posts([
                'post_type' => Ikoeh_Connect_Clone_Store::POST_TYPE,
                'posts_per_page' => 1,
                'orderby' => 'ID',
                'order' => 'DESC',
            ]);
            $job = $recent[0] ?? null;
        }

        if (!$job) {
            wp_send_json_success(['job' => null, 'history' => Ikoeh_Connect_Chat::get_history()]);
        }

        wp_send_json_success(['job' => Ikoeh_Connect_Clone_Store::shape_job($job), 'history' => Ikoeh_Connect_Chat::get_history()]);
    }

    public static function ajax_undo() {
        check_ajax_referer('ikoeh_chat_send', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.']);
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'post_id ausente.']);
        }

        $result = Ikoeh_Connect_Chat_Snapshots::undo_last_change($post_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['undone' => true]);
    }
}
