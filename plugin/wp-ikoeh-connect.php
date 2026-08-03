<?php
/**
 * Plugin Name: WP iKOEH Connect
 * Plugin URI: https://github.com/sandruhill/wp-ikoeh-connect
 * Description: Conecta o Claude a este site WordPress para desenvolvimento e otimização assistida por IA, via uma API própria autenticada por token.
 * Version: 0.1.0
 * Author: ikoeh
 * Author URI: https://ikoeh.com
 * License: GPL v2 or later
 * Text Domain: ikoeh-connect
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IKOEH_CONNECT_VERSION', '0.1.0');
define('IKOEH_CONNECT_DIR', plugin_dir_path(__FILE__));
define('IKOEH_CONNECT_URL', plugin_dir_url(__FILE__));
define('IKOEH_CONNECT_REST_NAMESPACE', 'ikoeh-connect/v1');

require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-auth.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-setup.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-admin.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-chat-admin.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-site-info.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-plugins.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-content.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-db.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-logs.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-cache.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-elementor.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-theme.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-media.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-posts.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-admin-access.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-gutenberg-store.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-clone-store.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-gutenberg.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-gutenberg-admin.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-skills.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-design-tokens.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-design.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-system-path.php';
require_once IKOEH_CONNECT_DIR . 'includes/class-ikoeh-system-installer.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-system-files.php';

add_action('init', ['Ikoeh_Connect_Auth', 'maybe_migrate']);
add_action('init', ['Ikoeh_Connect_System_Installer', 'ensure_sandbox']);
add_action('init', ['Ikoeh_Connect_Gutenberg_Store', 'register_post_type']);
add_action('init', ['Ikoeh_Connect_Rest_Skills', 'register_post_type']);
add_action('init', ['Ikoeh_Connect_Rest_Design', 'register_post_type']);
add_action('init', ['Ikoeh_Connect_Clone_Store', 'register_post_type']);
add_action('init', ['Ikoeh_Connect_Gutenberg_Store', 'schedule_cleanup']);
add_action('init', ['Ikoeh_Connect_Clone_Store', 'schedule_tick']);
add_action('ikoeh_gb_cleanup', ['Ikoeh_Connect_Gutenberg_Store', 'cleanup']);
register_deactivation_hook(__FILE__, ['Ikoeh_Connect_Gutenberg_Store', 'unschedule_cleanup']);
register_deactivation_hook(__FILE__, ['Ikoeh_Connect_Clone_Store', 'unschedule_tick']);

add_filter('cron_schedules', ['Ikoeh_Connect_Clone_Store', 'register_cron_interval']);

add_action('admin_menu', ['Ikoeh_Connect_Admin', 'register_menu']);
add_action('admin_menu', ['Ikoeh_Connect_Gutenberg_Admin', 'register_menu']);
add_action('admin_menu', ['Ikoeh_Connect_Chat_Admin', 'register_menu']);
add_action('init', ['Ikoeh_Connect_Admin', 'register_ajax']);
add_action('init', ['Ikoeh_Connect_Chat', 'register_ajax']);

add_action('rest_api_init', function () {
    Ikoeh_Connect_Setup::register_routes();
    Ikoeh_Connect_Rest_Site_Info::register_routes();
    Ikoeh_Connect_Rest_Plugins::register_routes();
    Ikoeh_Connect_Rest_Content::register_routes();
    Ikoeh_Connect_Rest_Db::register_routes();
    Ikoeh_Connect_Rest_Logs::register_routes();
    Ikoeh_Connect_Rest_Cache::register_routes();
    Ikoeh_Connect_Rest_Elementor::register_routes();
    Ikoeh_Connect_Rest_Theme::register_routes();
    Ikoeh_Connect_Rest_Media::register_routes();
    Ikoeh_Connect_Rest_Posts::register_routes();
    Ikoeh_Connect_Rest_Admin_Access::register_routes();
    Ikoeh_Connect_Rest_Gutenberg::register_routes();
    Ikoeh_Connect_Rest_Skills::register_routes();
    Ikoeh_Connect_Rest_Design::register_routes();
    Ikoeh_Connect_Rest_System_Files::register_routes();
});

/**
 * LiteSpeed Cache (and possibly other page-cache layers on this host) was
 * caching GET responses from this namespace WITHOUT varying by the
 * Authorization header, so an unauthenticated request could receive a
 * cached response from an earlier authenticated one (confirmed via
 * `x-litespeed-cache: hit` on a token-less request returning full data).
 * This almost certainly explains much of the "state doesn't match what the
 * API says" inconsistency seen across this whole integration: the API
 * itself was sometimes reading real WordPress state, sometimes replaying a
 * stale cached response. Every response from our namespace must be marked
 * uncacheable at the LiteSpeed layer specifically, since it apparently
 * does not fully honor the standard Cache-Control headers WordPress's own
 * REST API already sends for this.
 */
add_filter('rest_pre_serve_request', function ($served, $result, $request) {
    if (0 === strpos($request->get_route(), '/' . IKOEH_CONNECT_REST_NAMESPACE)) {
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }
    return $served;
}, 10, 3);
