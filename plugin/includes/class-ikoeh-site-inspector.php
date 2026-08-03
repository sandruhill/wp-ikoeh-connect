<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches a compact text summary and a screenshot of any URL (the external
 * reference site being cloned, or the customer's own freshly-published page,
 * for comparison). v1 supports a single screenshot vendor, urlbox.io, using
 * a per-site API key the customer configures themselves (same write-only,
 * masked pattern as the existing Anthropic key).
 */
class Ikoeh_Connect_Site_Inspector {

    const OPTION_SCREENSHOT_API_KEY = 'ikoeh_chat_screenshot_api_key';
    const URLBOX_API_URL = 'https://api.urlbox.io/v1/render';
    const MAX_SUMMARY_CHARS = 6000;

    /**
     * Compact text summary, not raw HTML: a full page dump can easily be
     * tens of thousands of tokens, most of it scripts/styles/nav noise the
     * model does not need to clone a page's content and structure.
     */
    public static function fetch_html_summary($url) {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ikoeh_connect_fetch_failed', "Failed to fetch {$url} (HTTP {$code}).", ['status' => 502]);
        }

        $html = wp_remote_retrieve_body($response);
        if ('' === trim($html)) {
            return new WP_Error('ikoeh_connect_empty_response', "Fetched {$url} but the response body was empty.", ['status' => 502]);
        }

        return self::summarize_html($html);
    }

    private static function summarize_html($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach (['script', 'style', 'noscript'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode->removeChild($node);
            }
        }

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $text = trim($node->textContent);
                if ('' !== $text) {
                    $headings[] = strtoupper($tag) . ': ' . $text;
                }
            }
        }

        $images = [];
        foreach ($doc->getElementsByTagName('img') as $node) {
            $src = $node->getAttribute('src');
            if ('' !== $src) {
                $images[] = $src;
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $body_text = $body ? trim(preg_replace('/\s+/', ' ', $body->textContent)) : '';

        $summary = "HEADINGS:\n" . implode("\n", array_slice($headings, 0, 40))
            . "\n\nIMAGES:\n" . implode("\n", array_slice($images, 0, 30))
            . "\n\nBODY TEXT:\n" . $body_text;

        return mb_substr($summary, 0, self::MAX_SUMMARY_CHARS);
    }

    public static function fetch_screenshot_bytes($url) {
        $api_key = get_option(self::OPTION_SCREENSHOT_API_KEY, '');
        if ('' === $api_key) {
            return new WP_Error('ikoeh_connect_no_screenshot_key', 'Configure uma chave de API de screenshot (urlbox.io) primeiro.', ['status' => 400]);
        }

        // add_query_arg()/build_query() does NOT urlencode values, so a $url
        // with its own query string (UTM params etc.) would otherwise inject
        // extra top-level params into the outer request and get truncated.
        $endpoint = add_query_arg([
            'url' => rawurlencode($url),
            'format' => 'png',
            'full_page' => 'true',
        ], self::URLBOX_API_URL);

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new WP_Error('ikoeh_connect_screenshot_failed', "Screenshot service returned HTTP {$code} for {$url}.", ['status' => 502]);
        }

        return wp_remote_retrieve_body($response);
    }
}
