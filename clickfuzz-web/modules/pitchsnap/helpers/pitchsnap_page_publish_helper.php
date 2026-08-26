<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Nav HTML markers — allow deterministic nav updates in static HTML files
define('CF_NAV_START', '<!-- cf-nav-start -->');
define('CF_NAV_END',   '<!-- cf-nav-end -->');

// ---------------------------------------------------------------------------
// Slug validation
// ---------------------------------------------------------------------------

/**
 * Returns true if the slug is safe for filesystem/URL use.
 * Accepts only lowercase alphanumeric characters and hyphens, no leading/trailing hyphens.
 */
function clickfuzz_web_validate_slug_for_publish($slug)
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$|^[a-z0-9]$/', (string) $slug);
}

// ---------------------------------------------------------------------------
// URL path building
// ---------------------------------------------------------------------------

/**
 * Resolves the URL path for a page by walking its parent chain.
 *
 * @param  object $page           The page row (needs ->id, ->slug, ->parent_page_id, ->site_id)
 * @param  array  $pages_indexed  All site pages keyed by id (for parent lookups)
 * @return array  ['path' => 'services/ac-repair', 'error' => null]
 *             or ['path' => null, 'error' => 'reason']
 */
function clickfuzz_web_page_url_path($page, array $pages_indexed)
{
    $segments = [];
    $visited  = [];
    $current  = $page;
    $site_id  = (int) $page->site_id;
    $max      = 10;

    for ($depth = 0; $depth < $max; $depth++) {
        $slug = trim((string) ($current->slug ?? ''));
        if (!clickfuzz_web_validate_slug_for_publish($slug)) {
            return ['path' => null, 'error' => 'Invalid slug "' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" in page hierarchy.'];
        }

        $id = (int) $current->id;
        if (in_array($id, $visited, true)) {
            return ['path' => null, 'error' => 'Loop detected in page hierarchy at page #' . $id . '.'];
        }
        $visited[] = $id;
        array_unshift($segments, $slug);

        if (empty($current->parent_page_id)) {
            break;
        }

        $parent_id = (int) $current->parent_page_id;
        if (!isset($pages_indexed[$parent_id])) {
            return ['path' => null, 'error' => 'Parent page #' . $parent_id . ' not found in site.'];
        }
        $parent = $pages_indexed[$parent_id];
        if ((int) $parent->site_id !== $site_id) {
            return ['path' => null, 'error' => 'Cross-site parent detected at page #' . $parent_id . '.'];
        }

        $current = $parent;
    }

    if (count($segments) >= $max) {
        return ['path' => null, 'error' => 'Page hierarchy exceeds maximum depth (' . $max . ' levels).'];
    }

    return ['path' => implode('/', $segments), 'error' => null];
}

// ---------------------------------------------------------------------------
// Publishing eligibility
// ---------------------------------------------------------------------------

/**
 * Returns null if the page is eligible for publishing, or an error string if not.
 *
 * @param  object      $page  Page row
 * @param  object      $site  Site row
 * @param  object|null $gen   Current generation row (from get_current_page_generation)
 * @return string|null
 */
function clickfuzz_web_page_publish_eligible($page, $site, $gen)
{
    if (!$site || $site->status !== 'published') {
        return 'The site must be published before internal pages can be published.';
    }
    if ($page->status === 'trash') {
        return 'Cannot publish a trashed page.';
    }
    if ($page->generation_status !== 'generated') {
        return 'Page must have a successful generation before publishing. Current status: ' . $page->generation_status . '.';
    }
    if (!$gen || empty($gen->html_content)) {
        return 'No valid generated content found for this page.';
    }
    if ((int) $gen->page_id !== (int) $page->id || (int) $gen->site_id !== (int) $site->id) {
        return 'Generation record does not belong to this page/site.';
    }
    if (empty($page->slug) || !clickfuzz_web_validate_slug_for_publish($page->slug)) {
        return 'Page slug is invalid or missing.';
    }
    if (empty($page->title)) {
        return 'Page title is required.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Navigation building
// ---------------------------------------------------------------------------

/**
 * Builds structured nav items from the page registry.
 * Returns: ['primary' => [...], 'footer' => [...]]
 * Each item: ['page' => $page, 'url' => '/path/', 'label' => 'text', 'children' => [...]]
 *
 * Only published pages are included. Draft and trash pages are excluded.
 * Sort: menu_order ASC, then title ASC.
 */
function clickfuzz_web_build_nav_items(array $pages_indexed, $base_url)
{
    $base_url = rtrim($base_url, '/');

    // Separate primary and footer candidates
    $primary_raw = [];
    $footer_raw  = [];
    foreach ($pages_indexed as $p) {
        if ($p->status !== 'published') { continue; }
        if ($p->menu_primary) { $primary_raw[] = $p; }
        if ($p->menu_footer)  { $footer_raw[]  = $p; }
    }

    $sort = function ($a, $b) {
        $diff = (int) $a->menu_order - (int) $b->menu_order;
        return $diff !== 0 ? $diff : strcmp($a->title, $b->title);
    };
    usort($primary_raw, $sort);
    usort($footer_raw,  $sort);

    $build_flat = function ($pages) use ($pages_indexed, $base_url) {
        $result = [];
        foreach ($pages as $p) {
            $path_result = clickfuzz_web_page_url_path($p, $pages_indexed);
            if ($path_result['error']) { continue; }
            $label    = !empty($p->menu_label) ? $p->menu_label : $p->title;
            $result[] = [
                'page'     => $p,
                'url'      => $base_url . '/' . $path_result['path'] . '/',
                'label'    => $label,
                'children' => [],
            ];
        }
        return $result;
    };

    // Build primary nav tree (hierarchy)
    $p_map = [];
    foreach ($primary_raw as $p) {
        $path_result = clickfuzz_web_page_url_path($p, $pages_indexed);
        if ($path_result['error']) { continue; }
        $label    = !empty($p->menu_label) ? $p->menu_label : $p->title;
        $p_map[(int) $p->id] = [
            'page'     => $p,
            'url'      => $base_url . '/' . $path_result['path'] . '/',
            'label'    => $label,
            'children' => [],
        ];
    }

    $primary_tree = [];
    foreach ($primary_raw as $p) {
        $id = (int) $p->id;
        if (!isset($p_map[$id])) { continue; }
        $parent_id = (int) ($p->parent_page_id ?? 0);
        if ($parent_id && isset($p_map[$parent_id])) {
            $p_map[$parent_id]['children'][] = &$p_map[$id];
        } else {
            $primary_tree[] = &$p_map[$id];
        }
    }

    return [
        'primary' => $primary_tree,
        'footer'  => $build_flat($footer_raw),
    ];
}

/**
 * Renders the primary nav as an HTML <nav> block with markers.
 * Supports one level of nesting (parent → children).
 */
function clickfuzz_web_render_primary_nav_html(array $primary_items, $home_url)
{
    $lines = [];
    $lines[] = CF_NAV_START;
    $lines[] = '<nav class="cf-site-nav" aria-label="Primary">';
    $lines[] = '<ul>';
    $lines[] = '<li><a href="' . htmlspecialchars(rtrim($home_url, '/') . '/', ENT_QUOTES, 'UTF-8') . '">Home</a></li>';

    foreach ($primary_items as $item) {
        $url   = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        if (!empty($item['children'])) {
            $lines[] = '<li class="cf-has-children"><a href="' . $url . '">' . $label . '</a><ul>';
            foreach ($item['children'] as $child) {
                $curl   = htmlspecialchars($child['url'], ENT_QUOTES, 'UTF-8');
                $clabel = htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8');
                $lines[] = '<li><a href="' . $curl . '">' . $clabel . '</a></li>';
            }
            $lines[] = '</ul></li>';
        } else {
            $lines[] = '<li><a href="' . $url . '">' . $label . '</a></li>';
        }
    }

    $lines[] = '</ul>';
    $lines[] = '</nav>';
    $lines[] = CF_NAV_END;

    return implode("\n", $lines);
}

/**
 * Renders a flat footer nav block with markers.
 */
function clickfuzz_web_render_footer_nav_html(array $footer_items)
{
    if (empty($footer_items)) { return ''; }
    $lines = [];
    $lines[] = '<!-- cf-footer-nav-start -->';
    $lines[] = '<nav class="cf-footer-nav" aria-label="Footer">';
    $lines[] = '<ul>';
    foreach ($footer_items as $item) {
        $url   = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $lines[] = '<li><a href="' . $url . '">' . $label . '</a></li>';
    }
    $lines[] = '</ul></nav>';
    $lines[] = '<!-- cf-footer-nav-end -->';
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// HTML nav injection
// ---------------------------------------------------------------------------

/**
 * Injects (or replaces) the ClickFuzz nav in an HTML string.
 *
 * Strategy:
 * 1. If CF markers exist, replace content between them.
 * 2. Otherwise find the first <nav> block and replace it (adding markers).
 * 3. Otherwise prepend nav at the start of <body> content.
 *
 * Does NOT modify page body content outside the nav.
 */
function clickfuzz_web_update_html_nav($html, $new_nav_html)
{
    // 1. Marker-based replacement (reliable for files we previously wrote)
    if (strpos($html, CF_NAV_START) !== false && strpos($html, CF_NAV_END) !== false) {
        return preg_replace(
            '/' . preg_quote(CF_NAV_START, '/') . '[\s\S]*?' . preg_quote(CF_NAV_END, '/') . '/s',
            $new_nav_html,
            $html,
            1
        );
    }

    // 2. Replace first <nav> block (for AI-generated HTML without markers)
    if (preg_match('/<nav[\s>]/i', $html)) {
        return preg_replace('/<nav[\s\S]*?<\/nav>/is', $new_nav_html, $html, 1);
    }

    // 3. Prepend after <body> tag (fallback)
    if (stripos($html, '<body') !== false) {
        return preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $new_nav_html, $html, 1);
    }

    return $new_nav_html . "\n" . $html;
}

// ---------------------------------------------------------------------------
// Full HTML document rendering
// ---------------------------------------------------------------------------

/**
 * Builds a complete HTML document from a page generation and its context.
 *
 * @param  object $page           Page row
 * @param  object $site           Site row
 * @param  object $gen            Page generation row
 * @param  string $canonical_url  Full canonical URL for this page
 * @param  string $nav_html       Ready-to-inject primary nav HTML (with markers)
 * @return string  Complete HTML document
 */
function clickfuzz_web_render_full_page_html($page, $site, $gen, $canonical_url, $nav_html)
{
    $meta_title = '';
    if (!empty($page->meta_title)) {
        $meta_title = $page->meta_title;
    } elseif (!empty($gen->meta_title_generated)) {
        $meta_title = $gen->meta_title_generated;
    } else {
        $meta_title = $page->title;
    }

    $meta_desc = '';
    if (!empty($page->meta_description)) {
        $meta_desc = $page->meta_description;
    } elseif (!empty($gen->meta_description_generated)) {
        $meta_desc = $gen->meta_description_generated;
    }

    $noindex = !(bool) ($page->index_page ?? 1);

    $css_block = !empty($gen->css_content)
        ? '<style>' . $gen->css_content . '</style>'
        : '';

    $js_block = !empty($gen->js_content)
        ? '<script>' . $gen->js_content . '</script>'
        : '';

    // Inject ClickFuzz nav into body content (replaces AI-generated nav)
    $body_html = clickfuzz_web_update_html_nav($gen->html_content, $nav_html);

    // Normalize copyright year for live page
    if (function_exists('clickfuzz_web_normalize_copyright_year')) {
        $body_html = clickfuzz_web_normalize_copyright_year($body_html);
    }

    $head_parts = [
        '<meta charset="UTF-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>' . htmlspecialchars($meta_title, ENT_QUOTES, 'UTF-8') . '</title>',
    ];

    if ($noindex) {
        $head_parts[] = '<meta name="robots" content="noindex, nofollow">';
    }
    if ($meta_desc) {
        $head_parts[] = '<meta name="description" content="' . htmlspecialchars($meta_desc, ENT_QUOTES, 'UTF-8') . '">';
    }
    if ($canonical_url) {
        $head_parts[] = '<link rel="canonical" href="' . htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') . '">';
    }
    if ($css_block) {
        $head_parts[] = $css_block;
    }

    return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
        . implode("\n", $head_parts)
        . "\n</head>\n<body>\n"
        . $body_html
        . ($js_block ? "\n" . $js_block : '')
        . "\n</body>\n</html>";
}

// ---------------------------------------------------------------------------
// Sitemap
// ---------------------------------------------------------------------------

/**
 * Builds and writes sitemap.xml for the site.
 * Includes canonical homepage and all published indexable internal pages.
 * Excludes draft, trash, and noindex pages.
 */
function clickfuzz_web_write_sitemap($site_dir, $site_base_url, array $published_pages, array $pages_indexed)
{
    $base = rtrim($site_base_url, '/');
    $now  = date('Y-m-d');

    $urls   = [];
    $urls[] = '<url><loc>' . htmlspecialchars($base . '/', ENT_XML1) . '</loc><lastmod>' . $now . '</lastmod><priority>1.0</priority></url>';

    foreach ($published_pages as $p) {
        if ($p->status !== 'published') { continue; }
        if (!(bool) ($p->index_page ?? 1)) { continue; }
        if (empty($p->published_path)) { continue; }

        $loc    = $base . '/' . ltrim($p->published_path, '/') . '/';
        $urls[] = '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><lastmod>' . $now . '</lastmod><priority>0.8</priority></url>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
         . implode("\n", $urls)
         . "\n</urlset>";

    return file_put_contents($site_dir . '/sitemap.xml', $xml) !== false;
}

// ---------------------------------------------------------------------------
// HTML publishing — full flow
// ---------------------------------------------------------------------------

/**
 * Publishes an internal page to the HTML filesystem.
 *
 * Steps (in order):
 *   1. Validate eligibility
 *   2. Resolve site directory and base URL
 *   3. Build URL path (with loop/traversal guard)
 *   4. Verify site dir exists
 *   5. Build nav from published page registry
 *   6. Render full HTML document
 *   7. Write page file
 *   8. Update nav in all published HTML files
 *   9. Rebuild sitemap
 *  10. Return success with published_path
 *
 * @return array ['success', 'url', 'published_path', 'error']
 */
function clickfuzz_web_publish_page_html($page, $site, $gen)
{
    $CI =& get_instance();

    // Resolve site directory
    $domain    = $site->domain ?? '';
    $site_slug = ltrim(strstr($domain, '/sites/'), '/sites/');
    if (!$site_slug || !preg_match('/^[a-z0-9\-]+$/', $site_slug)) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Invalid site slug — cannot resolve site directory.'];
    }

    $site_dir = dirname(FCPATH) . '/sites/' . $site_slug;
    if (!is_dir($site_dir)) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Site directory does not exist. Publish the main site first.'];
    }

    $real_site_dir = realpath($site_dir);
    if (!$real_site_dir) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Cannot resolve site directory path.'];
    }

    // Resolve base URL from platform hostname
    $domain_row   = $CI->pitchsnap_model->get_platform_domain_for_site($site->id);
    $site_base_url = $domain_row ? 'https://' . $domain_row->hostname : null;
    if (!$site_base_url) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Site platform hostname not found. Publish the main site first.'];
    }

    // Build URL path from page hierarchy
    $all_pages      = $CI->pitchsnap_model->get_pages_for_site($site->id, true);
    $pages_indexed  = [];
    foreach ($all_pages as $p) { $pages_indexed[(int) $p->id] = $p; }

    $path_result = clickfuzz_web_page_url_path($page, $pages_indexed);
    if ($path_result['error']) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => $path_result['error']];
    }
    $url_path = $path_result['path'];

    // Path traversal guard
    $page_dir      = $site_dir . '/' . $url_path;
    $real_page_dir = realpath($page_dir) ?: ($real_site_dir . '/' . $url_path);
    if (strpos(rtrim($real_page_dir, '/') . '/', rtrim($real_site_dir, '/') . '/') !== 0) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Path traversal detected.'];
    }

    // Build nav from published page registry (include the page being published, treat as published)
    $published_pages = $CI->pitchsnap_model->get_published_pages_for_site($site->id);
    // Temporarily include the current page in nav if it has menu_primary set
    $for_nav = $published_pages;
    $in_published = false;
    foreach ($for_nav as $pp) { if ((int)$pp->id === (int)$page->id) { $in_published = true; break; } }
    if (!$in_published && $page->menu_primary) {
        // Clone page object and mark as published for nav building
        $temp_page = clone $page;
        $temp_page->status = 'published';
        $temp_page->published_path = $url_path;
        $for_nav[] = $temp_page;
        // Reindex
        $nav_pages_indexed = $pages_indexed;
        $nav_pages_indexed[(int)$temp_page->id] = $temp_page;
    } else {
        $nav_pages_indexed = $pages_indexed;
    }

    $nav_data  = clickfuzz_web_build_nav_items($nav_pages_indexed, $site_base_url);
    $nav_html  = clickfuzz_web_render_primary_nav_html($nav_data['primary'], $site_base_url . '/');

    // Canonical URL
    $canonical_url = rtrim($site_base_url, '/') . '/' . $url_path . '/';

    // Render full HTML document
    if (!function_exists('clickfuzz_web_normalize_copyright_year')) {
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
    }
    $html = clickfuzz_web_render_full_page_html($page, $site, $gen, $canonical_url, $nav_html);

    // Write page file
    if (!is_dir($page_dir) && !mkdir($page_dir, 0755, true)) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Could not create page directory: ' . $url_path];
    }
    $page_file = $page_dir . '/index.html';
    if (file_put_contents($page_file, $html) === false) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Failed to write page HTML file.'];
    }

    // Verify write succeeded
    if (!file_exists($page_file)) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => 'Page file not found after write (filesystem error).'];
    }

    // Update nav in all other published HTML files (homepage + other pages)
    clickfuzz_web_update_all_site_navs($site_dir, $real_site_dir, $published_pages, $pages_indexed, $nav_html, $page_file);

    // Rebuild sitemap — include newly published page
    $sitemap_pages   = $published_pages;
    if (!$in_published) {
        $pub_page_temp            = clone $page;
        $pub_page_temp->status    = 'published';
        $pub_page_temp->published_path = $url_path;
        $pub_page_temp->index_page = $page->index_page ?? 1;
        $sitemap_pages[] = $pub_page_temp;
    }
    clickfuzz_web_write_sitemap($site_dir, $site_base_url, $sitemap_pages, $pages_indexed);

    return [
        'success'        => true,
        'url'            => $canonical_url,
        'published_path' => $url_path,
        'error'          => null,
    ];
}

/**
 * Updates the ClickFuzz nav block in every published HTML file for the site.
 * Updates the homepage index.html and all published page directories.
 * Skips the page file that was just written (already has the updated nav).
 * Fails silently per file — partial nav update is better than blocking publish.
 */
function clickfuzz_web_update_all_site_navs($site_dir, $real_site_dir, array $published_pages, array $pages_indexed, $nav_html, $skip_file = null)
{
    // Homepage
    $homepage_file = $site_dir . '/index.html';
    if (file_exists($homepage_file) && realpath($homepage_file) !== $skip_file) {
        clickfuzz_web_update_nav_in_file($homepage_file, $real_site_dir, $nav_html);
    }

    // Each published internal page
    foreach ($published_pages as $p) {
        if (empty($p->published_path)) { continue; }
        $file = $site_dir . '/' . $p->published_path . '/index.html';
        $real = realpath($file);
        if (!$real || $real === $skip_file) { continue; }
        if (strpos(rtrim($real, '/') . '/', rtrim($real_site_dir, '/') . '/') !== 0) { continue; } // traversal guard
        clickfuzz_web_update_nav_in_file($file, $real_site_dir, $nav_html);
    }
}

/**
 * Updates the nav in a single HTML file using marker or <nav> fallback.
 * Returns true on success, false if the file could not be updated.
 */
function clickfuzz_web_update_nav_in_file($file, $real_site_dir, $nav_html)
{
    $real = realpath($file);
    if (!$real || strpos(rtrim($real, '/') . '/', rtrim($real_site_dir, '/') . '/') !== 0) {
        return false; // path guard
    }
    $html = @file_get_contents($file);
    if ($html === false) { return false; }
    $updated = clickfuzz_web_update_html_nav($html, $nav_html);
    return file_put_contents($file, $updated) !== false;
}

// ---------------------------------------------------------------------------
// WordPress API helper
// ---------------------------------------------------------------------------

/**
 * Makes an authenticated WP REST API call.
 *
 * @param  object $site      Site row (wp_site_url, wp_username, wp_app_password)
 * @param  string $method    HTTP method: GET, POST, PUT, PATCH
 * @param  string $endpoint  WP REST path starting with /wp-json/...
 * @param  array  $body      Request body (JSON-encoded)
 * @param  int    $timeout   cURL timeout in seconds
 * @return array  ['ok' => bool, 'code' => int, 'body' => array|null, 'error' => string|null]
 */
function clickfuzz_web_wp_call($site, $method, $endpoint, array $body = [], $timeout = 30)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'cURL not available.'];
    }

    $wp_url      = rtrim($site->wp_site_url, '/');
    $credentials = base64_encode($site->wp_username . ':' . $site->wp_app_password);
    $headers     = [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/json',
        'User-Agent: ClickFuzz-Web/1.0',
    ];

    $url = $wp_url . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $method = strtoupper($method);
    if ($method === 'GET') {
        // Default
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $resp_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'cURL error: ' . $curl_err];
    }

    $decoded = json_decode($resp_body, true);
    $ok      = in_array($http_code, [200, 201], true);

    return [
        'ok'    => $ok,
        'code'  => $http_code,
        'body'  => $decoded,
        'error' => $ok ? null : ('HTTP ' . $http_code . ': ' . (isset($decoded['message']) ? $decoded['message'] : substr($resp_body, 0, 200))),
    ];
}

// ---------------------------------------------------------------------------
// WordPress menu management
// ---------------------------------------------------------------------------

/**
 * Adds or updates a ClickFuzz page as a menu item in the specified WP menu.
 * Uses the WP REST API menus/menu-items endpoint (WordPress 5.9+).
 *
 * @param  object $site         Site row
 * @param  int    $menu_id      WP menu ID
 * @param  int    $wp_page_id   WP page ID for this page
 * @param  string $label        Menu item label
 * @param  int    $parent_item  WP menu-item ID of the parent item (0 = top-level)
 * @param  int    $order        Menu item order
 * @return array  ['ok', 'item_id', 'error']
 */
function clickfuzz_web_wp_upsert_menu_item($site, $menu_id, $wp_page_id, $label, $parent_item = 0, $order = 0)
{
    // Find existing menu item for this WP page in this menu
    $existing = clickfuzz_web_wp_call($site, 'GET',
        '/wp-json/wp/v2/menu-items?menus=' . $menu_id . '&object_id=' . $wp_page_id . '&per_page=5');

    $item_id   = null;
    $put_method = 'POST';
    $endpoint   = '/wp-json/wp/v2/menu-items';

    if ($existing['ok'] && !empty($existing['body'])) {
        foreach ($existing['body'] as $mi) {
            if ((int)($mi['object_id'] ?? 0) === (int) $wp_page_id) {
                $item_id    = (int) $mi['id'];
                $put_method = 'POST'; // PATCH not always available; POST to /{id} acts as update
                $endpoint   = '/wp-json/wp/v2/menu-items/' . $item_id;
                break;
            }
        }
    }

    $item_body = [
        'title'            => $label,
        'menus'            => (int) $menu_id,
        'object'           => 'page',
        'object_id'        => (int) $wp_page_id,
        'type'             => 'post_type',
        'status'           => 'publish',
        'menu_item_parent' => (int) $parent_item,
        'menu_order'       => (int) $order,
    ];

    $resp = clickfuzz_web_wp_call($site, $put_method, $endpoint, $item_body, 20);
    return [
        'ok'      => $resp['ok'],
        'item_id' => $resp['ok'] ? (int)($resp['body']['id'] ?? 0) : 0,
        'error'   => $resp['error'],
    ];
}

/**
 * Gets the WP menu ID for a named location (primary, footer).
 * Returns menu ID int or 0 if not found/endpoint not available.
 */
function clickfuzz_web_wp_get_menu_id($site, $location = 'primary')
{
    $resp = clickfuzz_web_wp_call($site, 'GET', '/wp-json/wp/v2/menus?per_page=20', [], 15);
    if (!$resp['ok'] || empty($resp['body'])) { return 0; }
    foreach ($resp['body'] as $menu) {
        $locs = $menu['locations'] ?? [];
        if (in_array($location, $locs, true)) {
            return (int) $menu['id'];
        }
    }
    return 0;
}

// ---------------------------------------------------------------------------
// WordPress publishing — full flow
// ---------------------------------------------------------------------------

/**
 * Publishes an internal page to WordPress via REST API.
 *
 * Steps:
 *   1. Validate WP credentials configured
 *   2. Validate parent WP page ID if page has a parent
 *   3. Create or update the WP page
 *   4. Store page meta (CSS, JS, meta title, meta description, noindex)
 *   5. Update WP menu if menu_primary or menu_footer is set
 *   6. Return success with wp_page_id and URL
 *
 * @param  object   $page             ClickFuzz page row
 * @param  object   $site             Site row
 * @param  object   $gen              Current generation row
 * @param  int|null $parent_wp_page_id WP page ID of parent (null if no parent)
 * @return array    ['success', 'url', 'wp_page_id', 'error']
 */
function clickfuzz_web_publish_page_wp($page, $site, $gen, $parent_wp_page_id = null)
{
    if (empty($site->wp_site_url)) {
        return ['success' => false, 'url' => null, 'wp_page_id' => null, 'error' => 'WordPress site URL not configured on this site.'];
    }
    if (empty($site->wp_username) || empty($site->wp_app_password)) {
        return ['success' => false, 'url' => null, 'wp_page_id' => null, 'error' => 'WordPress credentials not configured.'];
    }

    $meta_title = !empty($page->meta_title) ? $page->meta_title
        : (!empty($gen->meta_title_generated) ? $gen->meta_title_generated : $page->title);
    $meta_desc  = !empty($page->meta_description) ? $page->meta_description
        : ($gen->meta_description_generated ?? '');

    // Build WP page payload (body content only — WP theme owns header/footer/nav)
    $page_payload = [
        'title'   => ['raw' => $meta_title],
        'content' => ['raw' => $gen->html_content],
        'status'  => 'publish',
        'slug'    => $page->slug,
    ];
    if ($parent_wp_page_id) {
        $page_payload['parent'] = (int) $parent_wp_page_id;
    }

    // Create or update WP page
    $existing_wp_id = !empty($page->wp_page_id) ? (int) $page->wp_page_id : 0;
    if ($existing_wp_id) {
        $endpoint = '/wp-json/wp/v2/pages/' . $existing_wp_id;
        $method   = 'POST'; // REST API uses POST to endpoint/{id} for update
    } else {
        $endpoint = '/wp-json/wp/v2/pages';
        $method   = 'POST';
    }

    $resp = clickfuzz_web_wp_call($site, $method, $endpoint, $page_payload, 30);
    if (!$resp['ok']) {
        return ['success' => false, 'url' => null, 'wp_page_id' => $existing_wp_id ?: null, 'error' => 'WordPress API error: ' . $resp['error']];
    }

    $new_wp_id  = (int) ($resp['body']['id'] ?? 0);
    $wp_page_url = $resp['body']['link'] ?? (rtrim($site->wp_site_url, '/') . '/' . $page->slug . '/');
    if (!$new_wp_id) {
        return ['success' => false, 'url' => null, 'wp_page_id' => $existing_wp_id ?: null, 'error' => 'WordPress API did not return a page ID.'];
    }

    // Store ClickFuzz page metadata via WP post meta API
    $meta_payload = [
        '_clickfuzz_page_css'         => $gen->css_content ?? '',
        '_clickfuzz_page_js'          => $gen->js_content ?? '',
        '_clickfuzz_meta_title'       => $meta_title,
        '_clickfuzz_meta_description' => $meta_desc,
        '_clickfuzz_noindex'          => (bool) !($page->index_page ?? 1),
    ];
    foreach ($meta_payload as $key => $value) {
        clickfuzz_web_wp_call($site, 'POST',
            '/wp-json/wp/v2/pages/' . $new_wp_id . '/meta',
            ['key' => $key, 'value' => (string) $value],
            15
        );
    }

    // Update WP menus (best-effort — failures do not block publish)
    if ($page->menu_primary) {
        $label       = !empty($page->menu_label) ? $page->menu_label : $page->title;
        $primary_id  = clickfuzz_web_wp_get_menu_id($site, 'primary');
        if ($primary_id) {
            // Find parent menu item if applicable
            $parent_menu_item_id = 0;
            if ($parent_wp_page_id) {
                $parent_items = clickfuzz_web_wp_call($site, 'GET',
                    '/wp-json/wp/v2/menu-items?menus=' . $primary_id . '&object_id=' . $parent_wp_page_id . '&per_page=5', [], 15);
                if ($parent_items['ok'] && !empty($parent_items['body'])) {
                    $parent_menu_item_id = (int) ($parent_items['body'][0]['id'] ?? 0);
                }
            }
            clickfuzz_web_wp_upsert_menu_item($site, $primary_id, $new_wp_id, $label, $parent_menu_item_id, (int) $page->menu_order);
        }
    }

    if ($page->menu_footer) {
        $label     = !empty($page->menu_label) ? $page->menu_label : $page->title;
        $footer_id = clickfuzz_web_wp_get_menu_id($site, 'footer');
        if (!$footer_id) { $footer_id = clickfuzz_web_wp_get_menu_id($site, 'footer-nav'); }
        if ($footer_id) {
            clickfuzz_web_wp_upsert_menu_item($site, $footer_id, $new_wp_id, $label, 0, (int) $page->menu_order);
        }
    }

    return [
        'success'    => true,
        'url'        => $wp_page_url,
        'wp_page_id' => $new_wp_id,
        'error'      => null,
    ];
}

// ---------------------------------------------------------------------------
// Post-publish cleanup
// ---------------------------------------------------------------------------

/**
 * Deletes all page generation records for a page EXCEPT the specified one.
 * Called after successful page publication to prune obsolete draft generations.
 *
 * @param  int $page_id     Page ID
 * @param  int $keep_gen_id Generation ID to preserve
 * @return int Number of generation records deleted
 */
function clickfuzz_web_cleanup_page_generations($page_id, $keep_gen_id)
{
    $CI =& get_instance();
    $table = db_prefix() . 'pitchsnap_page_generations';

    $to_delete = $CI->db
        ->where('page_id', (int) $page_id)
        ->where('id !=', (int) $keep_gen_id)
        ->get($table)->result();

    if (empty($to_delete)) { return 0; }

    $CI->db
        ->where('page_id', (int) $page_id)
        ->where('id !=', (int) $keep_gen_id)
        ->delete($table);

    return count($to_delete);
}
