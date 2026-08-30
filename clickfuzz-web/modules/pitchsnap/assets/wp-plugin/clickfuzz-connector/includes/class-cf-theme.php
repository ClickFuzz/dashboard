<?php
defined('ABSPATH') || exit;

/**
 * Theme deployment and activation for ClickFuzz-generated themes.
 *
 * Uses WordPress core upgrader APIs for install/update and switch_theme()
 * for activation. Never operates on themes that fail ClickFuzz identity
 * validation.
 */
class CF_Theme
{
    const OPTION_SLUG    = 'cf_managed_theme_slug';
    const SLUG_PREFIX    = 'clickfuzz-generated-';
    const AUTHOR_MARKER  = 'clickfuzz'; // lowercase for comparison

    // ── Deploy (install or update) ────────────────────────────────────────────

    /**
     * Install or replace a ClickFuzz-generated theme from an uploaded ZIP.
     *
     * Determines install vs. update by checking whether the theme slug already
     * exists in WordPress. Uses Theme_Upgrader with overwrite_package=true so
     * re-uploads replace the previous copy cleanly without leaving duplicates.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function deploy(string $zip_path)
    {
        // 1. Validate structure + ClickFuzz identity
        $valid = CF_Zip::validate($zip_path);
        if (!$valid['ok']) {
            return new WP_Error('cf_invalid_theme', $valid['error'], ['status' => $valid['status']]);
        }

        $slug   = $valid['theme_slug'];
        $action = wp_get_theme($slug)->exists() ? 'updated' : 'installed';

        // 2. Initialise WP filesystem + load upgrader
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }

        // 3. Run the upgrader
        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result   = $upgrader->install($zip_path, [
            'overwrite_package' => true,
            'clear_working'     => true,
        ]);

        if (is_wp_error($result)) {
            return new WP_Error(
                'cf_install_failed',
                $result->get_error_message(),
                ['status' => 500]
            );
        }
        if ($result === false || $result === null) {
            return new WP_Error(
                'cf_install_failed',
                'Theme installation failed. Check server filesystem permissions.',
                ['status' => 500]
            );
        }

        // 4. Verify presence
        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_Error(
                'cf_install_failed',
                'Installation reported success but theme could not be found afterward.',
                ['status' => 500]
            );
        }

        // 5. Record managed slug
        update_option(self::OPTION_SLUG, $slug);

        return [
            'action'     => $action,
            'theme_slug' => $slug,
            'theme_name' => $theme->get('Name'),
            'version'    => $theme->get('Version'),
        ];
    }

    // ── Activate ──────────────────────────────────────────────────────────────

    /**
     * Activate a ClickFuzz-managed theme by slug.
     *
     * Refuses slugs that do not follow the ClickFuzz naming convention and
     * refuses installed themes that fail the author identity check.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function activate(string $slug)
    {
        if (!self::is_cf_slug($slug)) {
            return new WP_Error(
                'cf_not_cf_theme',
                'Only ClickFuzz-generated themes can be activated through this endpoint.',
                ['status' => 403]
            );
        }

        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_Error('cf_not_found', "Theme not found: {$slug}.", ['status' => 404]);
        }

        if (!self::is_cf_theme($theme)) {
            return new WP_Error(
                'cf_not_cf_theme',
                'The installed theme with this slug does not pass ClickFuzz identity validation.',
                ['status' => 403]
            );
        }

        switch_theme($slug);
        update_option(self::OPTION_SLUG, $slug);

        return [
            'theme_slug' => $slug,
            'theme_name' => $theme->get('Name'),
            'version'    => $theme->get('Version'),
            'active'     => true,
        ];
    }

    // ── Predicates ────────────────────────────────────────────────────────────

    /** Whether a slug follows the ClickFuzz naming convention. */
    public static function is_cf_slug(string $slug): bool
    {
        return str_starts_with($slug, self::SLUG_PREFIX)
            && strlen($slug) > strlen(self::SLUG_PREFIX);
    }

    /** Whether an installed WP_Theme object passes ClickFuzz identity checks. */
    public static function is_cf_theme(WP_Theme $theme): bool
    {
        return self::is_cf_slug($theme->get_stylesheet())
            && strtolower($theme->get('Author')) === self::AUTHOR_MARKER;
    }

    // ── Status summary ────────────────────────────────────────────────────────

    /**
     * Active and managed theme state — used by the /status endpoint.
     *
     * @return array<string, mixed>
     */
    public static function status_info(): array
    {
        $active = wp_get_theme();
        $is_cf  = self::is_cf_theme($active);
        return [
            'active_theme_slug'       => $active->get_stylesheet(),
            'active_theme_name'       => $active->get('Name'),
            'connector_managed_theme' => $is_cf,
        ];
    }
}
