<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage and state machine for chat-driven site-clone jobs. One post per
 * job. A WP-Cron tick (Ikoeh_Connect_Chat_Clone_Runner, Task 5) advances a
 * job through the states below; this class owns only data and transitions.
 */
class Ikoeh_Connect_Clone_Store {

    const POST_TYPE = 'ikoeh_clone_job';

    const META_SOURCE_URL = '_ikoeh_clone_source_url';
    const META_TARGET_POST_ID = '_ikoeh_clone_target_post_id';
    const META_STATUS = '_ikoeh_clone_status';
    const META_ITERATION = '_ikoeh_clone_iteration';
    const META_ERROR_MESSAGE = '_ikoeh_clone_error_message';
    const META_LOG = '_ikoeh_clone_log';
    const META_CREATED_BY = '_ikoeh_clone_created_by';

    const STATUS_QUEUED = 'queued';
    const STATUS_FETCHING = 'fetching';
    const STATUS_GENERATING = 'generating';
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_COMPARING = 'comparing';
    const STATUS_REFINING = 'refining';
    const STATUS_DONE = 'done';
    const STATUS_PARTIAL = 'partial';
    const STATUS_FAILED = 'failed';

    const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_PARTIAL, self::STATUS_FAILED];
    const MAX_ITERATIONS = 4;

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'label' => 'Chat site-clone jobs',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    private static function meta_string($job_id, $key) {
        $value = get_post_meta($job_id, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    private static function meta_int($job_id, $key) {
        $value = get_post_meta($job_id, $key, true);
        return is_scalar($value) ? (int) $value : 0;
    }

    public static function status($job_id) {
        $status = self::meta_string($job_id, self::META_STATUS);
        return '' !== $status ? $status : self::STATUS_QUEUED;
    }

    public static function set_status($job_id, $status) {
        update_post_meta($job_id, self::META_STATUS, $status);
    }

    /**
     * Atomic compare-and-swap using add_option() as a mutex, same pattern as
     * Ikoeh_Connect_Gutenberg_Store::atomic_status_transition() -- add_option()
     * fails atomically at the DB layer if the row already exists, so two
     * concurrent cron ticks can never both claim the same job.
     */
    public static function atomic_status_transition($job_id, array $from_statuses, $to_status) {
        if (empty($from_statuses)) {
            return false;
        }

        $lock_option = 'ikoeh_clone_lock_' . $job_id;
        $lock_owner = wp_generate_password(20, false);
        $lock_payload = $lock_owner . '|' . (time() + 30);

        $acquired = add_option($lock_option, $lock_payload, '', false);
        if (!$acquired) {
            $existing = get_option($lock_option);
            $parts = is_string($existing) ? explode('|', $existing, 2) : [];
            $stale = count($parts) !== 2 || (int) $parts[1] <= time();
            if (!$stale) {
                return false;
            }
            delete_option($lock_option);
            if (!add_option($lock_option, $lock_payload, '', false)) {
                return false;
            }
        }

        try {
            if (!in_array(self::status($job_id), $from_statuses, true)) {
                return false;
            }
            self::set_status($job_id, $to_status);
            return true;
        } finally {
            $current = get_option($lock_option);
            if (is_string($current) && 0 === strpos($current, $lock_owner . '|')) {
                delete_option($lock_option);
            }
        }
    }

    public static function create_job($source_url, $created_by) {
        $result = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => wp_slash('Clone: ' . $source_url),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        $job_id = (int) $result;
        update_post_meta($job_id, self::META_SOURCE_URL, wp_slash($source_url));
        update_post_meta($job_id, self::META_CREATED_BY, (int) $created_by);
        update_post_meta($job_id, self::META_ITERATION, 0);
        update_post_meta($job_id, self::META_LOG, []);
        self::set_status($job_id, self::STATUS_QUEUED);

        return $job_id;
    }

    public static function get_job($job_id) {
        $post = get_post($job_id);
        if (!$post || self::POST_TYPE !== $post->post_type) {
            return null;
        }
        return $post;
    }

    public static function set_target_post($job_id, $post_id) {
        update_post_meta($job_id, self::META_TARGET_POST_ID, (int) $post_id);
    }

    public static function target_post_id($job_id) {
        return self::meta_int($job_id, self::META_TARGET_POST_ID);
    }

    public static function increment_iteration($job_id) {
        $current = self::meta_int($job_id, self::META_ITERATION);
        update_post_meta($job_id, self::META_ITERATION, $current + 1);
        return $current + 1;
    }

    public static function iteration($job_id) {
        return self::meta_int($job_id, self::META_ITERATION);
    }

    public static function set_error($job_id, $message) {
        update_post_meta($job_id, self::META_ERROR_MESSAGE, wp_slash($message));
    }

    public static function append_log($job_id, $step, $note, $reference_screenshot_url = '', $result_screenshot_url = '') {
        $log = get_post_meta($job_id, self::META_LOG, true);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'timestamp' => time(),
            'step' => $step,
            'note' => $note,
            'reference_screenshot_url' => $reference_screenshot_url,
            'result_screenshot_url' => $result_screenshot_url,
        ];
        update_post_meta($job_id, self::META_LOG, wp_slash($log));
    }

    public static function log($job_id) {
        $log = get_post_meta($job_id, self::META_LOG, true);
        return is_array($log) ? $log : [];
    }

    public static function shape_job(WP_Post $job) {
        return [
            'job_id' => $job->ID,
            'source_url' => self::meta_string($job->ID, self::META_SOURCE_URL),
            'target_post_id' => self::target_post_id($job->ID) ?: null,
            'status' => self::status($job->ID),
            'iteration' => self::iteration($job->ID),
            'max_iterations' => self::MAX_ITERATIONS,
            'created_at' => $job->post_date_gmt,
            'error_message' => self::meta_string($job->ID, self::META_ERROR_MESSAGE),
            'log' => self::log($job->ID),
        ];
    }

    /** Oldest job not yet in a terminal status, or null if none is pending. */
    public static function get_oldest_non_terminal_job() {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 50,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($posts as $post) {
            if (!in_array(self::status($post->ID), self::TERMINAL_STATUSES, true)) {
                return $post;
            }
        }
        return null;
    }

    public static function schedule_tick() {
        if (false === wp_next_scheduled('ikoeh_clone_job_tick')) {
            wp_schedule_event(time() + 60, 'ikoeh_clone_minute', 'ikoeh_clone_job_tick');
        }
    }

    public static function unschedule_tick() {
        wp_clear_scheduled_hook('ikoeh_clone_job_tick');
    }

    public static function register_cron_interval($schedules) {
        $schedules['ikoeh_clone_minute'] = ['interval' => 60, 'display' => 'Every minute (iKOEH clone jobs)'];
        return $schedules;
    }
}
