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
