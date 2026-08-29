<?php
defined('ABSPATH') || exit;

/**
 * WordPress Custom Logo importer.
 *
 * Imports a logo file into the WordPress Media Library and sets it as the
 * active Custom Logo via set_theme_mod('custom_logo', $attachment_id).
 *
 * All operations are idempotent:
 *   _clickfuzz_imported  = '1'         marks the attachment as CF-owned
 *   _clickfuzz_source_id = <source_id> maps back to the originating export
 *   _clickfuzz_asset_role = 'site_logo' distinguishes it from other CF media
 *
 * Re-running always reuses the existing CF-owned logo attachment — no duplicates.
 */
class CF_Logo
{
    const META_IMPORTED   = '_clickfuzz_imported';
    const META_SOURCE_ID  = '_clickfuzz_source_id';
    const META_ASSET_ROLE = '_clickfuzz_asset_role';
    const ASSET_ROLE      = 'site_logo';

    const SUPPORTED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /**
     * Import a logo file into the WordPress Media Library and set it as the
     * Custom Logo for the active theme.
     *
     * @param string $file_path      Absolute path to the uploaded/extracted logo file.
     * @param string $preferred_name Filename to use in the Media Library (e.g. "acme-logo.png").
     * @param string $title          Human-readable title (typically the business name + " Logo").
     * @param string $source_id      Idempotency key — use the site_slug from the export manifest.
     * @return array|WP_Error  ['logo_imported'=>bool, 'logo_attachment_id'=>int, 'logo_action'=>string]
     */
    public static function import(string $file_path, string $preferred_name, string $title, string $source_id)
    {
        if (!file_exists($file_path)) {
            return new WP_Error('cf_logo_missing', 'Logo file not found.', ['status' => 400]);
        }

        // Validate MIME type before touching the Media Library.
        if (!function_exists('finfo_open')) {
            return new WP_Error('cf_logo_finfo', 'fileinfo extension is required for logo import.', ['status' => 500]);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        if (!in_array($mime, self::SUPPORTED_TYPES, true)) {
            return new WP_Error(
                'cf_logo_type',
                'Unsupported logo MIME type: ' . $mime . '. Supported: ' . implode(', ', self::SUPPORTED_TYPES),
                ['status' => 422]
            );
        }

        // ── Idempotency check ─────────────────────────────────────────────────
        $existing_id = self::find_existing($source_id);
        if ($existing_id > 0) {
            set_theme_mod('custom_logo', $existing_id);
            return [
                'logo_imported'      => false,
                'logo_attachment_id' => $existing_id,
                'logo_action'        => 'reused',
            ];
        }

        // ── Sideload into Media Library ───────────────────────────────────────
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!function_exists('wp_insert_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file_array = [
            'tmp_name' => $file_path,
            'name'     => $preferred_name,
            'error'    => 0,
            'size'     => filesize($file_path),
        ];

        $overrides = ['test_form' => false, 'test_size' => true, 'mimes' => array_flip(self::mime_ext_map())];
        $sideloaded = wp_handle_sideload($file_array, $overrides);

        if (isset($sideloaded['error'])) {
            return new WP_Error('cf_logo_sideload', 'Logo sideload failed: ' . $sideloaded['error'], ['status' => 500]);
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_title'     => $title,
                'post_mime_type' => $sideloaded['type'],
                'post_status'    => 'inherit',
            ],
            $sideloaded['file']
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;

        $meta = wp_generate_attachment_metadata($attachment_id, $sideloaded['file']);
        wp_update_attachment_metadata($attachment_id, $meta);

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
        update_post_meta($attachment_id, self::META_IMPORTED,   '1');
        update_post_meta($attachment_id, self::META_SOURCE_ID,  $source_id);
        update_post_meta($attachment_id, self::META_ASSET_ROLE, self::ASSET_ROLE);

        set_theme_mod('custom_logo', $attachment_id);

        return [
            'logo_imported'      => true,
            'logo_attachment_id' => $attachment_id,
            'logo_action'        => 'imported',
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function find_existing(string $source_id): int
    {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => self::META_IMPORTED,   'value' => '1'],
                ['key' => self::META_ASSET_ROLE, 'value' => self::ASSET_ROLE],
                ['key' => self::META_SOURCE_ID,  'value' => $source_id],
            ],
        ]);

        return !empty($q->posts) ? (int) $q->posts[0]->ID : 0;
    }

    /** Map MIME types to extensions for wp_handle_sideload allowed-types override. */
    private static function mime_ext_map(): array
    {
        return [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
    }
}
