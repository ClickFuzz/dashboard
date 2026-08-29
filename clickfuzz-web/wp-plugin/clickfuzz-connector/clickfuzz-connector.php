<?php
/**
 * Plugin Name: ClickFuzz Connector
 * Plugin URI:  https://clickfuzz.com
 * Description: Secure API bridge between ClickFuzz Web and WordPress.
 * Version:     2.0.0
 * Author:      ClickFuzz
 * License:     GPL-2.0-or-later
 * Text Domain: clickfuzz-connector
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('CF_CONNECTOR_VERSION', '2.0.0');
define('CF_CONNECTOR_FILE', __FILE__);
define('CF_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once CF_CONNECTOR_DIR . 'includes/class-cf-sanitize.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-zip.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-pages.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-theme.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-xml.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-logo.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-import.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-api.php';
require_once CF_CONNECTOR_DIR . 'includes/class-cf-settings.php';

register_activation_hook(__FILE__, 'cf_connector_activate');
function cf_connector_activate(): void
{
    // Generate a one-time setup token used for the initial pairing with ClickFuzz.
    // ClickFuzz sends its own generated API key during /setup; the token is then deleted.
    if (!get_option('cf_setup_token') && !get_option('cf_connector_api_key')) {
        add_option('cf_setup_token', wp_generate_password(12, false));
    }
}

add_action('rest_api_init', ['CF_API', 'register_routes']);
add_action('admin_menu', ['CF_Settings', 'register_menu']);
add_action('admin_post_cf_reset_pairing', ['CF_Settings', 'handle_reset_pairing']);
