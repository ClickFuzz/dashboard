<?php
defined('ABSPATH') || exit;

/**
 * CRUD operations for ClickFuzz-managed WordPress pages.
 *
 * Only standard WordPress Pages are used (no custom post type).
 * Every write operation guards against modifying pages that are not
 * ClickFuzz-managed.  PHP in generated content is rejected, not stripped.
 */
class CF_Pages
{
    // Meta keys — must match the ClickFuzz WordPress exporter contract.
    const META_MARKER  = '_clickfuzz_generated_page';
    const META_HTML    = '_clickfuzz_generated_html';
    const META_CSS     = '_clickfuzz_generated_css';
    const META_JS      = '_clickfuzz_generated_js';
    const META_VERSION = '_clickfuzz_version';

    // ── Predicates ────────────────────────────────────────────────────────────

    public static function is_cf_page(int $post_id): bool
    {
        return (string) get_post_meta($post_id, self::META_MARKER, true) === '1';
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public static function get_list(): array
    {
        $query = new WP_Query([
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => self::META_MARKER, 'value' => '1'],
            ],
            'no_found_rows'  => true,
        ]);

        $pages = [];
        foreach ($query->posts as $post) {
            $pages[] = self::format_page($post);
        }
        return $pages;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function get_one(int $id)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('cf_not_found', 'Page not found.', ['status' => 404]);
        }
        if (!self::is_cf_page($id)) {
            return new WP_Error('cf_not_cf_page', 'This page is not managed by ClickFuzz.', ['status' => 403]);
        }
        return self::format_page($post);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>|WP_Error
     */
    public static function create(array $params)
    {
        $check = self::validate_content_fields($params);
        if (is_wp_error($check)) {
            return $check;
        }

        $post_data = [
            'post_type'    => 'page',
            'post_status'  => isset($params['status']) ? sanitize_key($params['status']) : 'draft',
            'post_title'   => sanitize_text_field($params['title'] ?? ''),
            'post_name'    => sanitize_title($params['slug'] ?? ($params['title'] ?? '')),
            'post_content' => '',
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, self::META_MARKER, '1');
        update_post_meta($post_id, self::META_HTML, $params['html'] ?? '');
        update_post_meta($post_id, self::META_CSS, $params['css'] ?? '');
        update_post_meta($post_id, self::META_JS, $params['js'] ?? '');
        update_post_meta($post_id, self::META_VERSION, 1);

        return self::format_page(get_post($post_id));
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>|WP_Error
     */
    public static function update(int $id, array $params)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('cf_not_found', 'Page not found.', ['status' => 404]);
        }
        if (!self::is_cf_page($id)) {
            return new WP_Error('cf_not_cf_page', 'This page is not managed by ClickFuzz. Refusing to modify.', ['status' => 403]);
        }

        // ── Staleness checks (either version or modified_gmt, or both) ────────
        if (isset($params['version'])) {
            $stored_version = (int) get_post_meta($id, self::META_VERSION, true);
            if ((int) $params['version'] !== $stored_version) {
                return new WP_Error(
                    'cf_stale',
                    sprintf('Stale update: client has version %d, server is at version %d.', (int) $params['version'], $stored_version),
                    ['status' => 409]
                );
            }
        }
        if (isset($params['modified_gmt'])) {
            $server_ts = strtotime($post->post_modified_gmt);
            $client_ts = strtotime($params['modified_gmt']);
            if ($server_ts !== false && $client_ts !== false && $server_ts > $client_ts) {
                return new WP_Error(
                    'cf_stale',
                    'Stale update: page was modified on the server after the client last read it.',
                    ['status' => 409]
                );
            }
        }

        // ── Validate generated content ─────────────────────────────────────────
        $check = self::validate_content_fields($params);
        if (is_wp_error($check)) {
            return $check;
        }

        // ── Update WP post fields ─────────────────────────────────────────────
        $post_data = ['ID' => $id];
        if (isset($params['title']))  $post_data['post_title']  = sanitize_text_field($params['title']);
        if (isset($params['slug']))   $post_data['post_name']   = sanitize_title($params['slug']);
        if (isset($params['status'])) $post_data['post_status'] = sanitize_key($params['status']);

        if (count($post_data) > 1) {
            $result = wp_update_post($post_data, true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        // ── Update generated content meta ─────────────────────────────────────
        if (array_key_exists('html', $params)) update_post_meta($id, self::META_HTML, $params['html']);
        if (array_key_exists('css', $params))  update_post_meta($id, self::META_CSS, $params['css']);
        if (array_key_exists('js', $params))   update_post_meta($id, self::META_JS, $params['js']);

        $new_version = (int) get_post_meta($id, self::META_VERSION, true) + 1;
        update_post_meta($id, self::META_VERSION, $new_version);

        return self::format_page(get_post($id));
    }

    /**
     * Permanently delete a ClickFuzz-managed page (bypasses trash).
     *
     * @return true|WP_Error
     */
    public static function delete(int $id)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('cf_not_found', 'Page not found.', ['status' => 404]);
        }
        if (!self::is_cf_page($id)) {
            return new WP_Error('cf_not_cf_page', 'This page is not managed by ClickFuzz. Refusing to delete.', ['status' => 403]);
        }

        $result = wp_delete_post($id, true);
        if (!$result) {
            return new WP_Error('cf_delete_failed', 'Failed to delete page.', ['status' => 500]);
        }
        return true;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Check html/css/js fields for PHP where they are present in $params.
     *
     * @return true|WP_Error
     */
    private static function validate_content_fields(array $params)
    {
        foreach (['html', 'css', 'js'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $check = CF_Sanitize::validate_no_php((string) $params[$field]);
                if (is_wp_error($check)) {
                    return $check;
                }
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function format_page(WP_Post $post): array
    {
        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'status'       => $post->post_status,
            'link'         => get_permalink($post->ID),
            'modified_gmt' => $post->post_modified_gmt,
            'version'      => (int) get_post_meta($post->ID, self::META_VERSION, true),
            'html'         => (string) get_post_meta($post->ID, self::META_HTML, true),
            'css'          => (string) get_post_meta($post->ID, self::META_CSS, true),
            'js'           => (string) get_post_meta($post->ID, self::META_JS, true),
        ];
    }
}
