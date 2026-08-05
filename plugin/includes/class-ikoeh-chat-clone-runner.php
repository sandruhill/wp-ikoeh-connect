<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advances one clone job per cron tick through its state machine, making
 * its own calls to the Anthropic Messages API (same model/key the customer
 * already configured for chat) with CLONE_TOOL_DEFINITIONS. Runs entirely
 * outside any single HTTP request, so there is no PHP execution time limit
 * concern even though a full clone can take several minutes end to end.
 */
class Ikoeh_Connect_Chat_Clone_Runner {

    const MAX_TOKENS = 4096;

    public static function run_tick() {
        $job = Ikoeh_Connect_Clone_Store::get_oldest_non_terminal_job();
        if (!$job) {
            return;
        }

        $job_id = $job->ID;
        $status = Ikoeh_Connect_Clone_Store::status($job_id);

        try {
            switch ($status) {
                case Ikoeh_Connect_Clone_Store::STATUS_QUEUED:
                    self::step_fetch($job_id);
                    break;
                case Ikoeh_Connect_Clone_Store::STATUS_FETCHING:
                case Ikoeh_Connect_Clone_Store::STATUS_GENERATING:
                case Ikoeh_Connect_Clone_Store::STATUS_REFINING:
                    self::step_generate_or_refine($job_id);
                    break;
                case Ikoeh_Connect_Clone_Store::STATUS_PUBLISHING:
                case Ikoeh_Connect_Clone_Store::STATUS_COMPARING:
                    self::step_compare($job_id);
                    break;
            }
        } catch (Exception $e) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $e->getMessage());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $e->getMessage());
        }
    }

    private static function step_fetch($job_id) {
        if (!Ikoeh_Connect_Clone_Store::atomic_status_transition(
            $job_id, [Ikoeh_Connect_Clone_Store::STATUS_QUEUED], Ikoeh_Connect_Clone_Store::STATUS_FETCHING
        )) {
            return;
        }

        $source_url = get_post_meta($job_id, Ikoeh_Connect_Clone_Store::META_SOURCE_URL, true);
        $summary = Ikoeh_Connect_Site_Inspector::fetch_html_summary($source_url);
        if (is_wp_error($summary)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $summary->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui acessar o site de referencia: " . $summary->get_error_message());
            return;
        }

        $screenshot = Ikoeh_Connect_Site_Inspector::fetch_screenshot_bytes($source_url);
        if (is_wp_error($screenshot)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $screenshot->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui tirar um print do site de referencia: " . $screenshot->get_error_message());
            return;
        }

        update_post_meta($job_id, '_ikoeh_clone_reference_summary', wp_slash($summary));
        update_post_meta($job_id, '_ikoeh_clone_reference_screenshot_b64', base64_encode($screenshot));

        Ikoeh_Connect_Clone_Store::append_log($job_id, 'fetching', 'Site de referencia analisado.');
        Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_GENERATING);
    }

    /** Shared loop body for both the first generation and every refine round. */
    private static function step_generate_or_refine($job_id) {
        $messages = self::build_conversation($job_id);
        $result = self::run_tool_loop($job_id, $messages);

        if (is_wp_error($result)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result->get_error_message());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $result->get_error_message());
            return;
        }

        Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_PUBLISHING);
    }

    private static function step_compare($job_id) {
        $target_post_id = Ikoeh_Connect_Clone_Store::target_post_id($job_id);
        if (!$target_post_id) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, 'No page was created.');
            self::post_final_chat_message($job_id, "A clonagem falhou: nenhuma pagina foi criada.");
            return;
        }

        $result_url = get_permalink($target_post_id);
        $result_screenshot = Ikoeh_Connect_Site_Inspector::fetch_screenshot_bytes($result_url);
        if (is_wp_error($result_screenshot)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result_screenshot->get_error_message());
            self::post_final_chat_message($job_id, "Nao consegui tirar um print do resultado publicado: " . $result_screenshot->get_error_message());
            return;
        }

        update_post_meta($job_id, '_ikoeh_clone_result_screenshot_b64', base64_encode($result_screenshot));
        Ikoeh_Connect_Clone_Store::append_log($job_id, 'comparing', 'Resultado publicado comparado com a referencia.');

        $iteration = Ikoeh_Connect_Clone_Store::iteration($job_id);
        if ($iteration >= Ikoeh_Connect_Clone_Store::MAX_ITERATIONS) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_PARTIAL);
            self::post_final_chat_message($job_id, "Cheguei ao limite de " . Ikoeh_Connect_Clone_Store::MAX_ITERATIONS . " ajustes. A pagina esta em {$result_url}, recomendo uma revisao manual dos detalhes finais.");
            return;
        }

        $messages = self::build_conversation($job_id, true);
        $result = self::run_tool_loop($job_id, $messages);

        if (is_wp_error($result)) {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_FAILED);
            Ikoeh_Connect_Clone_Store::set_error($job_id, $result->get_error_message());
            self::post_final_chat_message($job_id, "A clonagem falhou: " . $result->get_error_message());
            return;
        }

        if ($result['called_tool']) {
            Ikoeh_Connect_Clone_Store::increment_iteration($job_id);
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_COMPARING);
        } else {
            Ikoeh_Connect_Clone_Store::set_status($job_id, Ikoeh_Connect_Clone_Store::STATUS_DONE);
            self::post_final_chat_message($job_id, $result['final_text'] . " Pagina publicada em {$result_url}.");
        }
    }

    private static function build_conversation($job_id, $with_comparison = false) {
        $source_url = get_post_meta($job_id, Ikoeh_Connect_Clone_Store::META_SOURCE_URL, true);
        $summary = get_post_meta($job_id, '_ikoeh_clone_reference_summary', true);
        $reference_b64 = get_post_meta($job_id, '_ikoeh_clone_reference_screenshot_b64', true);

        $content = [
            ['type' => 'text', 'text' => "Clone this page ({$source_url}) into a new native Elementor page on this WordPress site. Reference summary:\n\n{$summary}"],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $reference_b64]],
        ];

        if ($with_comparison) {
            $result_b64 = get_post_meta($job_id, '_ikoeh_clone_result_screenshot_b64', true);
            $content[] = ['type' => 'text', 'text' => 'Here is a screenshot of what was just published. Compare it against the reference above. If it is close enough, reply with plain text only (no tool call) summarizing what you built. If it needs adjustment, call write_page_content again with a corrected element tree.'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $result_b64]];
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * Runs the Anthropic tool-calling loop until the model responds with no
     * more tool_use blocks (done, this round is over) or MAX_ITERATIONS is
     * reached (the caller in step_compare() checks the cap before calling
     * this again -- this method itself only guards against a single
     * runaway turn by capping at 6 tool calls within one build_conversation
     * round, independent of the job-level iteration cap).
     */
    private static function run_tool_loop($job_id, array $messages) {
        $api_key = get_option(Ikoeh_Connect_Chat::OPTION_API_KEY, '');
        $model = get_option(Ikoeh_Connect_Chat::OPTION_MODEL, '') ?: Ikoeh_Connect_Chat::DEFAULT_MODEL;

        $called_tool = false;
        $final_text = '';

        for ($round = 0; $round < 6; $round++) {
            $response = wp_remote_post(Ikoeh_Connect_Chat::ANTHROPIC_API_URL, [
                'timeout' => 90,
                'headers' => [
                    'x-api-key' => $api_key,
                    'anthropic-version' => Ikoeh_Connect_Chat::ANTHROPIC_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages' => $messages,
                    'tools' => Ikoeh_Connect_Chat_Tools::CLONE_TOOL_DEFINITIONS,
                ]),
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
                return new WP_Error('ikoeh_connect_bad_response', 'Anthropic API returned an unexpected response.');
            }

            $tool_use_blocks = [];
            foreach ($body['content'] as $block) {
                if (isset($block['type']) && 'text' === $block['type'] && isset($block['text'])) {
                    $final_text .= $block['text'];
                } elseif (isset($block['type']) && 'tool_use' === $block['type']) {
                    $tool_use_blocks[] = $block;
                }
            }

            if (empty($tool_use_blocks)) {
                return ['called_tool' => $called_tool, 'final_text' => $final_text];
            }

            $called_tool = true;
            $messages[] = ['role' => 'assistant', 'content' => $body['content']];

            $tool_results = [];
            foreach ($tool_use_blocks as $block) {
                $result = Ikoeh_Connect_Chat_Tools::execute_clone_tool(
                    $block['name'],
                    is_array($block['input'] ?? null) ? $block['input'] : [],
                    $job_id
                );
                $tool_results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => wp_json_encode($result),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $tool_results];
        }

        return ['called_tool' => $called_tool, 'final_text' => $final_text ?: 'Limite de rodadas de ferramentas atingido nesta etapa.'];
    }

    private static function post_final_chat_message($job_id, $text) {
        $history = Ikoeh_Connect_Chat::get_history();
        $history[] = ['role' => 'assistant', 'content' => $text, 'timestamp' => time()];
        Ikoeh_Connect_Chat::save_history_public($history);
    }
}
