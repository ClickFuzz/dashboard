<?php
defined('ABSPATH') || exit;

/**
 * WXR import orchestrator — imports ClickFuzz-exported content into WordPress.
 *
 * Handles: Home page, nav menus, nav menu items, static homepage setting,
 * and primary/footer menu location assignment.
 *
 * All operations are idempotent via two ownership meta keys:
 *   _clickfuzz_imported  = '1'    marks the object as CF-owned
 *   _clickfuzz_source_id = <int>  maps WP object back to source WXR ID
 *
 * Re-running with replace_existing=false is always safe to retry.
 * Re-running with replace_existing=true refreshes title/status on owned objects.
 */
class CF_Import
{
    const META_IMPORTED   = '_clickfuzz_imported';
    const META_SOURCE_ID  = '_clickfuzz_source_id';
    const URL_PLACEHOLDER = 'http://example.com';

    /** WXR slug → WordPress nav_menus_locations key. */
    const MENU_LOCATION_MAP = [
        'primary-menu' => 'primary',
        'footer-menu'  => 'footer',
    ];

    /**
     * Run the full WXR import pipeline, with optional logo import.
     *
     * @param string      $xml_content     Raw XML string (the WXR file).
     * @param bool        $replace_existing When true, re-imports content already owned by CF.
     * @param string|null $logo_file_path  Absolute path to the logo file (from multipart upload), or null.
     * @param string      $site_slug       Used as the idempotency key for the logo attachment.
     * @param string      $business_name   Used to build the logo alt/title.
     * @return array|WP_Error
     */
    public static function run(
        string $xml_content,
        bool $replace_existing = false,
        ?string $logo_file_path = null,
        string $site_slug = '',
        string $business_name = ''
    ) {
        $parsed = CF_Xml::parse($xml_content);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $result = [
            'pages_imported'     => 0,
            'pages_skipped'      => 0,
            'menus_imported'     => 0,
            'menus_skipped'      => 0,
            'menu_items_created' => 0,
            'homepage_set'       => false,
            'menus_assigned'     => [],
            'logo_action'        => null,
            'logo_attachment_id' => null,
        ];

        // ── Import pages ──────────────────────────────────────────────────────
        $home_wp_id  = 0;

        foreach ($parsed['pages'] as $page) {
            [$action, $wp_id] = self::import_page($page, $replace_existing);
            if (is_wp_error($action)) {
                return $action;
            }
            if ($action === 'skipped') {
                $result['pages_skipped']++;
            } else {
                $result['pages_imported']++;
            }
            if ($page['slug'] === 'home') {
                $home_wp_id = (int) $wp_id;
            }
        }

        // ── Set static homepage ───────────────────────────────────────────────
        if ($home_wp_id > 0) {
            $front_already_set = get_option('show_on_front') === 'page'
                && (int) get_option('page_on_front') === $home_wp_id;

            if ($replace_existing || !$front_already_set) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $home_wp_id);
                $result['homepage_set'] = true;
            }
        }

        // ── Import menus ──────────────────────────────────────────────────────
        $menu_id_map = []; // source_term_id => wp term_id

        foreach ($parsed['menus'] as $menu) {
            [$action, $term_id] = self::import_menu($menu, $replace_existing);
            if (is_wp_error($action)) {
                return $action;
            }
            $menu_id_map[$menu['source_term_id']] = $term_id;
            if ($action === 'skipped') {
                $result['menus_skipped']++;
            } else {
                $result['menus_imported']++;
            }
        }

        // ── Import menu items ─────────────────────────────────────────────────
        foreach ($parsed['menus'] as $menu) {
            $term_id = $menu_id_map[$menu['source_term_id']] ?? 0;
            if (!$term_id) continue;

            $items = array_values(array_filter(
                $parsed['menu_items'],
                fn($i) => $i['menu_nicename'] === $menu['slug']
            ));

            $count = self::import_menu_items($term_id, $items, $replace_existing);
            if (is_wp_error($count)) {
                return $count;
            }
            $result['menu_items_created'] += $count;
        }

        // ── Assign menus to theme locations ───────────────────────────────────
        $locations = get_theme_mod('nav_menus_locations', []);
        if (!is_array($locations)) {
            $locations = [];
        }

        foreach ($parsed['menus'] as $menu) {
            $loc_key = self::MENU_LOCATION_MAP[$menu['slug']] ?? null;
            if (!$loc_key) continue;

            $term_id = $menu_id_map[$menu['source_term_id']] ?? 0;
            if (!$term_id) continue;

            if ($replace_existing || empty($locations[$loc_key])) {
                $locations[$loc_key]            = $term_id;
                $result['menus_assigned'][]      = $loc_key;
            }
        }

        if (!empty($result['menus_assigned'])) {
            set_theme_mod('nav_menus_locations', $locations);
        }

        // ── Import logo (optional) ────────────────────────────────────────────
        if ($logo_file_path !== null && $site_slug !== '') {
            $ext              = strtolower(pathinfo($logo_file_path, PATHINFO_EXTENSION));
            $preferred_name   = $site_slug . '-logo.' . $ext;
            $title            = ($business_name !== '' ? $business_name : $site_slug) . ' Logo';
            $logo_result      = CF_Logo::import($logo_file_path, $preferred_name, $title, $site_slug);
            if (!is_wp_error($logo_result)) {
                $result['logo_action']        = $logo_result['logo_action'];
                $result['logo_attachment_id'] = $logo_result['logo_attachment_id'];
            }
        }

        return $result;
    }

    // ── URL normalization (public for testing) ────────────────────────────────

    /**
     * Replace the WXR placeholder base URL with the actual WordPress home URL.
     * Non-placeholder URLs (external, anchors, tel:, mailto:) are returned as-is.
     */
    public static function normalize_url(string $url): string
    {
        if (str_starts_with($url, self::URL_PLACEHOLDER)) {
            return rtrim(home_url(), '/') . substr($url, strlen(self::URL_PLACEHOLDER));
        }
        return $url;
    }

    // ── Page import ───────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int}   [action, wp_post_id]  action = created|updated|skipped
     *       | array{0: WP_Error, 1: 0}
     */
    private static function import_page(array $page, bool $replace_existing): array
    {
        // Look for an existing CF-owned page with this source_id
        $q = new WP_Query([
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => self::META_IMPORTED,  'value' => '1'],
                ['key' => self::META_SOURCE_ID, 'value' => (string) $page['source_id']],
            ],
        ]);

        if (!empty($q->posts)) {
            $wp_id = (int) $q->posts[0]->ID;
            if (!$replace_existing) {
                return ['skipped', $wp_id];
            }
            wp_update_post([
                'ID'          => $wp_id,
                'post_title'  => $page['title'],
                'post_name'   => $page['slug'],
                'post_status' => $page['status'],
            ]);
            return ['updated', $wp_id];
        }

        // Create new
        $wp_id = wp_insert_post([
            'post_title'   => $page['title'],
            'post_name'    => $page['slug'],
            'post_type'    => 'page',
            'post_status'  => $page['status'],
            'post_content' => '',
        ], true);

        if (is_wp_error($wp_id)) {
            return [$wp_id, 0];
        }

        update_post_meta((int) $wp_id, self::META_IMPORTED, '1');
        update_post_meta((int) $wp_id, self::META_SOURCE_ID, (string) $page['source_id']);

        return ['created', (int) $wp_id];
    }

    // ── Menu import ───────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int}   [action, wp_term_id]  action = created|updated|skipped
     *       | array{0: WP_Error, 1: 0}
     */
    private static function import_menu(array $menu, bool $replace_existing): array
    {
        // Look for an existing CF-owned nav_menu with this source_term_id
        $existing = get_terms([
            'taxonomy'   => 'nav_menu',
            'hide_empty' => false,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_IMPORTED,  'value' => '1'],
                ['key' => self::META_SOURCE_ID, 'value' => (string) $menu['source_term_id']],
            ],
        ]);

        if (!is_wp_error($existing) && !empty($existing)) {
            $term_id = (int) $existing[0]->term_id;
            if (!$replace_existing) {
                return ['skipped', $term_id];
            }
            return ['updated', $term_id];
        }

        // Create new nav menu
        $term_id = wp_create_nav_menu($menu['name']);
        if (is_wp_error($term_id)) {
            return [$term_id, 0];
        }

        update_term_meta((int) $term_id, self::META_IMPORTED, '1');
        update_term_meta((int) $term_id, self::META_SOURCE_ID, (string) $menu['source_term_id']);

        return ['created', (int) $term_id];
    }

    // ── Menu item import ──────────────────────────────────────────────────────

    /**
     * @return int|WP_Error  Count of items created or updated.
     */
    private static function import_menu_items(int $menu_id, array $items, bool $replace_existing)
    {
        // Map source_id → existing menu item post ID for idempotency
        $existing_map   = [];
        $current_items  = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
        if (is_array($current_items)) {
            foreach ($current_items as $item) {
                $src = get_post_meta($item->ID, self::META_SOURCE_ID, true);
                if ($src !== '') {
                    $existing_map[(int) $src] = $item->ID;
                }
            }
        }

        $count = 0;
        foreach ($items as $item) {
            $url            = self::normalize_url($item['url']);
            $existing_db_id = $existing_map[$item['source_id']] ?? 0;

            if ($existing_db_id && !$replace_existing) {
                continue; // already imported, not replacing
            }

            $result = wp_update_nav_menu_item($menu_id, (int) $existing_db_id, [
                'menu-item-title'            => $item['title'],
                'menu-item-url'              => $url,
                'menu-item-status'           => 'publish',
                'menu-item-type'             => $item['type'],
                'menu-item-menu-item-parent' => 0,
                'menu-item-target'           => $item['target'],
                'menu-item-classes'          => '',
                'menu-item-xfn'              => $item['xfn'],
                'menu-item-position'         => $item['menu_order'],
            ]);

            if (is_wp_error($result)) {
                continue; // skip failed items; don't abort the whole run
            }

            $db_id = (int) $result;
            if ($db_id > 0) {
                update_post_meta($db_id, self::META_IMPORTED, '1');
                update_post_meta($db_id, self::META_SOURCE_ID, (string) $item['source_id']);
                $count++;
            }
        }

        return $count;
    }
}
