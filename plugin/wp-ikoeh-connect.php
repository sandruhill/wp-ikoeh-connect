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
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-site-info.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-plugins.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-content.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-db.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-logs.php';
require_once IKOEH_CONNECT_DIR . 'includes/rest/class-ikoeh-rest-cache.php';

add_action('init', ['Ikoeh_Connect_Auth', 'maybe_migrate']);

add_action('admin_menu', ['Ikoeh_Connect_Admin', 'register_menu']);
add_action('init', ['Ikoeh_Connect_Admin', 'register_ajax']);

add_action('rest_api_init', function () {
    Ikoeh_Connect_Setup::register_routes();
    Ikoeh_Connect_Rest_Site_Info::register_routes();
    Ikoeh_Connect_Rest_Plugins::register_routes();
    Ikoeh_Connect_Rest_Content::register_routes();
    Ikoeh_Connect_Rest_Db::register_routes();
    Ikoeh_Connect_Rest_Logs::register_routes();
    Ikoeh_Connect_Rest_Cache::register_routes();
});
