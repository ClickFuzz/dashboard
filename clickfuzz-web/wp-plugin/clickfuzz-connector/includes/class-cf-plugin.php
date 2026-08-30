<?php
defined('ABSPATH') || exit;

/**
 * Self-update for the ClickFuzz Connector plugin.
 *
 * Uses WordPress core Plugin_Upgrader to replace the plugin's own files
 * from a ZIP uploaded by ClickFuzz Web. PHP keeps the current request's
 * in-memory code; the new version takes effect on the next request.
 */
class CF_Plugin
{
    const PLUGIN_SLUG = 'clickfuzz-connector/clickfuzz-connector.php';

    /**
     * Install a new plugin version from the given ZIP file path.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function update(string $zip_path): array|WP_Error
    {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->install($zip_path, [
            'overwrite_package' => true,
            'clear_working'     => true,
        ]);

        if (is_wp_error($result)) {
            return new WP_Error('cf_update_failed', $result->get_error_message(), ['status' => 500]);
        }
        if ($result === false || $result === null) {
            return new WP_Error('cf_update_failed', 'Plugin update failed. Check server filesystem permissions.', ['status' => 500]);
        }

        $plugin_file = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG;
        $plugin_data = file_exists($plugin_file) ? get_plugin_data($plugin_file, false, false) : [];
        $new_version = $plugin_data['Version'] ?? null;

        return [
            'updated' => true,
            'version' => $new_version,
        ];
    }
}
