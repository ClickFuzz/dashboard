<?php
/**
 * Plugin Name: ClickFuzz Connector
 * Plugin URI:  https://clickfuzz.com
 * Description: Secure API bridge between ClickFuzz Web and WordPress.
 * Version:     2.1.0
 * Author:      ClickFuzz
 * License:     GPL-2.0-or-later
 * Text Domain: clickfuzz-connector
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('CF_CONNECTOR_VERSION', '2.1.0');
define('CF_CONNECTOR_FILE', __FILE__);
define('CF_CONNECTOR_DIR', plugin_dir_path(__FILE__));

// ClickFuzz Web callback URL — the plugin POSTs {token, site_url} here to establish connection.
define('CF_DASHBOARD_CALLBACK_URL', 'https://clickfuzz.com/dashboard/pitchsnap/wp_pair_callback');

require_once CF_CONNECTOR_DIR . 'includes/class-cf-sanitize.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-zip.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-pages.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-theme.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-xml.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-logo.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-import.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-api.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-settings.php';

add_action('rest_api_init', ['CF_API', 'register_routes']);
add_action('admin_menu', ['CF_Settings', 'register_menu']);
add_action('admin_post_cf_save_token',  ['CF_Settings', 'handle_save_token']);
add_action('admin_post_cf_disconnect',  ['CF_Settings', 'handle_disconnect']);
