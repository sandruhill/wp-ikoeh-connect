<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches a compact text summary and a screenshot of any URL (the external
 * reference site being cloned, or the customer's own freshly-published page,
 * for comparison). Screenshots come from Google's PageSpeed Insights API,
 * free and requiring no signup for basic use (an optional Google API key
 * just raises the rate limit) -- chosen specifically to avoid requiring
 * every end customer to create a third-party paid account before their
 * first clone.
 */
class Ikoeh_Connect_Site_Inspector {

    const OPTION_SCREENSHOT_API_KEY = 'ikoeh_chat_screenshot_api_key';
    const PAGESPEED_API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    const MAX_SUMMARY_CHARS = 6000;

    /**
     * Compact text summary, not raw HTML: a full page dump can easily be
     * tens of thousands of tokens, most of it scripts/styles/nav noise the
     * model does not need to clone a page's content and structure.
     */
    public static function fetch_html_summary($url) {
        $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 5]);
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

    /**
     * Uses Google's PageSpeed Insights API to obtain a screenshot, not a
     * dedicated screenshot vendor: PageSpeed Insights is free and requires
     * no signup for basic use (an optional Google API key just raises the
     * rate limit), which matters a lot for a product aimed at non-technical
     * end customers who should not have to create a third-party account
     * before cloning their first page. This is an unofficial repurposing of
     * a performance-auditing tool (the screenshot is a side effect of the
     * Lighthouse run PageSpeed Insights performs), not a purpose-built
     * screenshot API, so it is slower (a full Lighthouse run, not just a
     * render) and offers less control (no explicit full-page/format options)
     * than a dedicated vendor would -- accepted as the right tradeoff here.
     */
    public static function fetch_screenshot_bytes($url) {
        $api_key = get_option(self::OPTION_SCREENSHOT_API_KEY, '');

        $args = ['url' => $url, 'category' => 'performance'];
        if ('' !== $api_key) {
            $args['key'] = $api_key;
        }

        // add_query_arg()/build_query() does NOT urlencode values, so a $url
        // with its own query string (UTM params etc.) would otherwise inject
        // extra top-level params into the outer request and get truncated.
        $endpoint = add_query_arg(array_map('rawurlencode', $args), self::PAGESPEED_API_URL);

        $response = wp_remote_get($endpoint, ['timeout' => 60]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new WP_Error('ikoeh_connect_screenshot_failed', "PageSpeed Insights returned HTTP {$code} for {$url}.", ['status' => 502]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data_uri = $body['lighthouseResult']['audits']['final-screenshot']['details']['data'] ?? '';

        if ('' === $data_uri || !preg_match('/^data:image\/[a-zA-Z]+;base64,(.+)$/', $data_uri, $matches)) {
            return new WP_Error('ikoeh_connect_screenshot_missing', "PageSpeed Insights did not return a screenshot for {$url}.", ['status' => 502]);
        }

        return base64_decode($matches[1]);
    }
}
