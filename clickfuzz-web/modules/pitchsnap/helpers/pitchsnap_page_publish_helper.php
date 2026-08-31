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
// Canonical site chrome extraction
// ---------------------------------------------------------------------------

/**
 * Extracts the canonical header, footer, and shared head content from the
 * approved site's homepage HTML.
 *
 * Returns:
 *   head_inner — <head> content with page-specific meta stripped (fonts/stylesheets)
 *   header     — body content from start to end of first nav block (site header + nav)
 *   footer     — body content from last site footer signal to end
 *
 * The header is used for internal page rendering after its nav is updated with
 * clickfuzz_web_update_html_nav(). The footer is appended verbatim.
 * head_inner is merged into the internal page's <head> for shared assets.
 *
 * If any section cannot be extracted, an empty string is returned for that section
 * so callers can fall back gracefully.
 *
 * @param  string $homepage_html  Full HTML of the published homepage
 * @return array  ['head_inner' => string, 'header' => string, 'footer' => string]
 */
function clickfuzz_web_extract_site_chrome($homepage_html)
{
    $result = ['head_inner' => '', 'header' => '', 'footer' => ''];
    if (empty($homepage_html)) { return $result; }

    // Extract shared <head> content (shared assets: fonts, stylesheets, etc.)
    if (preg_match('/<head[^>]*>([\s\S]*?)<\/head>/i', $homepage_html, $m)) {
        $head_inner = $m[1];
        // Strip elements we replace with page-specific versions
        $head_inner = preg_replace('/<title[^>]*>[\s\S]*?<\/title>/i', '', $head_inner);
        $head_inner = preg_replace('/<meta\s+name=["\']description["\'][^>]*>/i', '', $head_inner);
        $head_inner = preg_replace('/<meta\s+name=["\']robots["\'][^>]*>/i', '', $head_inner);
        $head_inner = preg_replace('/<link\s+rel=["\']canonical["\'][^>]*>/i', '', $head_inner);
        $head_inner = preg_replace('/<meta\s+charset[^>]*>/i', '', $head_inner);
        $head_inner = preg_replace('/<meta\s+name=["\']viewport["\'][^>]*>/i', '', $head_inner);
        // Strip inline <style> blocks — we use page-specific CSS from gen->css_content
        $head_inner = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $head_inner);
        $result['head_inner'] = trim($head_inner);
    }

    // Extract <body> inner content
    if (!preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i', $homepage_html, $m)) {
        return $result;
    }
    $body = $m[1];

    // --- Header block: from body start to end of primary nav ---
    // Strategy 1: CF nav end marker (reliable for CF-published sites)
    if (strpos($body, CF_NAV_END) !== false) {
        $pos = strpos($body, CF_NAV_END);
        $result['header'] = substr($body, 0, $pos + strlen(CF_NAV_END));
    } elseif (preg_match('/<\/nav>/i', $body, $nm, PREG_OFFSET_CAPTURE)) {
        // Strategy 2: end of first </nav> tag
        $result['header'] = substr($body, 0, $nm[0][1] + 6); // strlen('</nav>') = 6
    }
    // else: no nav found — $result['header'] remains ''

    // --- Footer block: from last site-footer signal to end of body ---
    // Strategy 1: CF footer nav marker
    $cf_footer_pos = strpos($body, '<!-- cf-footer-nav-start -->');
    if ($cf_footer_pos !== false) {
        $result['footer'] = substr($body, $cf_footer_pos);
    } else {
        // Strategy 2: last <footer> element (site footer is typically the outermost/last)
        $search_pos   = 0;
        $last_footer  = null;
        while (($found = stripos($body, '<footer', $search_pos)) !== false) {
            $next = isset($body[$found + 7]) ? $body[$found + 7] : '';
            if ($next === '>' || ctype_space($next)) {
                $last_footer = $found;
            }
            $search_pos = $found + 1;
        }
        if ($last_footer !== null) {
            $result['footer'] = substr($body, $last_footer);
        }
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Full HTML document rendering
// ---------------------------------------------------------------------------

/**
 * Builds a complete HTML document for an internal page.
 *
 * When $use_ssi is true, header and footer are emitted as SSI include directives
 * (<!--#include virtual="/_cf/header.html"-->) rather than inlined HTML. The
 * hosted-runtime processes these at serve time, enabling one-file nav updates.
 *
 * When $css_url is provided, a <link> tag is emitted before any inline <style>
 * block so shared site styles (assets/style.css) are loaded from the hosted server.
 *
 * @param  object $page          Page row
 * @param  object $site          Site row
 * @param  object $gen           Page generation row
 * @param  string $canonical_url Full canonical URL for this page
 * @param  string $header_html   Canonical site header (used when $use_ssi is false)
 * @param  string $footer_html   Canonical site footer (used when $use_ssi is false)
 * @param  string $shared_head   Shared <head> content from approved homepage
 * @param  bool   $use_ssi       Emit SSI include tags instead of inlining header/footer
 * @param  string $css_url       URL for shared site stylesheet (emits <link> when set)
 * @return string Complete HTML document
 */
function clickfuzz_web_render_full_page_html($page, $site, $gen, $canonical_url, $header_html, $footer_html = '', $shared_head = '', $use_ssi = false, $css_url = '')
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

    $css_link  = $css_url
        ? '<link rel="stylesheet" href="' . htmlspecialchars($css_url, ENT_QUOTES, 'UTF-8') . '">'
        : '';
    $css_block = !empty($gen->css_content)
        ? '<style>' . $gen->css_content . '</style>'
        : '';
    $js_block = !empty($gen->js_content)
        ? '<script>' . $gen->js_content . '</script>'
        : '';

    // Normalize body content at render time — handles existing stored generations
    // that may contain site chrome from the old prompt.
    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';
    $body_content = clickfuzz_web_normalize_page_body_html($gen->html_content);

    // Normalize copyright year in page body
    if (function_exists('clickfuzz_web_normalize_copyright_year')) {
        $body_content = clickfuzz_web_normalize_copyright_year($body_content);
    }

    // Build <head>: page-specific meta first, then shared assets, then page CSS
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
    if ($shared_head) {
        $head_parts[] = $shared_head;
    }
    if ($css_link)  { $head_parts[] = $css_link; }
    if ($css_block) { $head_parts[] = $css_block; }

    // Build <body>: header + page content wrapper + footer.
    // In SSI mode, header/footer are loaded at serve time from _cf/ partials.
    $body_parts = [];
    if ($use_ssi) {
        $body_parts[] = '<!--#include virtual="/_cf/header.html"-->';
    } elseif ($header_html) {
        $body_parts[] = $header_html;
    }
    $body_parts[] = '<main class="cf-page-content">';
    $body_parts[] = $body_content;
    $body_parts[] = '</main>';
    if ($use_ssi) {
        $body_parts[] = '<!--#include virtual="/_cf/footer.html"-->';
    } elseif ($footer_html) {
        $body_parts[] = $footer_html;
    }

    return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
        . implode("\n", $head_parts)
        . "\n</head>\n<body>\n"
        . implode("\n", $body_parts)
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
        $temp_page = clone $page;
        $temp_page->status = 'published';
        $temp_page->published_path = $url_path;
        $for_nav[] = $temp_page;
        $nav_pages_indexed = $pages_indexed;
        $nav_pages_indexed[(int)$temp_page->id] = $temp_page;
    } else {
        $nav_pages_indexed = $pages_indexed;
    }

    $nav_data = clickfuzz_web_build_nav_items($nav_pages_indexed, $site_base_url);
    $nav_html = clickfuzz_web_render_primary_nav_html($nav_data['primary'], $site_base_url . '/');

    // Canonical URL
    $canonical_url = rtrim($site_base_url, '/') . '/' . $url_path . '/';

    // Load shared generation helper for copyright normalization (used inside render_full_page_html)
    if (!function_exists('clickfuzz_web_normalize_copyright_year')) {
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
    }

    // Load chrome from pre-separated _cf/ partials if available (fast path),
    // otherwise fall back to parsing index.html on each page publish.
    $cf_dir  = $site_dir . '/_cf';
    $use_ssi = false;
    $css_url = '';

    if (file_exists($cf_dir . '/header.html')) {
        // _cf/ partials exist — render with SSI includes.
        // Nav is served live from _cf/header.html; update_all_site_navs keeps it current.
        $canonical_head_ext = @file_get_contents($cf_dir . '/head.html') ?: '';
        $canonical_header   = ''; // SSI handles header at serve time
        $canonical_footer   = ''; // SSI handles footer at serve time
        $use_ssi = true;
        $css_url = rtrim($site_base_url, '/') . '/assets/style.css';
    } else {
        // Fall back: parse index.html for chrome, render with baked-in header/footer.
        $homepage_html = '';
        $homepage_file = $site_dir . '/index.html';
        if (file_exists($homepage_file)) {
            $homepage_html = @file_get_contents($homepage_file);
        }
        $chrome = clickfuzz_web_extract_site_chrome((string) $homepage_html);
        $canonical_header = !empty($chrome['header'])
            ? clickfuzz_web_update_html_nav($chrome['header'], $nav_html)
            : $nav_html;
        $canonical_footer   = $chrome['footer'];
        $canonical_head_ext = $chrome['head_inner'];
    }

    // Render full HTML document using canonical approved site chrome
    $html = clickfuzz_web_render_full_page_html(
        $page, $site, $gen, $canonical_url,
        $canonical_header,
        $canonical_footer,
        $canonical_head_ext,
        $use_ssi,
        $css_url
    );

    // Write page file locally and push to FTP when configured
    if (!function_exists('clickfuzz_web_site_put')) {
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
    }
    $page_result = clickfuzz_web_site_put($site_slug, $url_path . '/index.html', $html);
    if (!$page_result['success']) {
        return ['success' => false, 'url' => null, 'published_path' => null, 'error' => $page_result['error']];
    }
    $page_file = $site_dir . '/' . $url_path . '/index.html';
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
 * Updates the ClickFuzz nav block across all published HTML files for the site.
 *
 * SSI mode (when _cf/header.html exists): updates _cf/header.html only and pushes
 * it to the hosted server — all SSI pages pick up the new nav at serve time.
 * Also updates the monolithic homepage (index.html) which does not use SSI.
 *
 * Legacy mode (no _cf/ partials): updates index.html and every published page file.
 *
 * Fails silently per file — partial nav update is better than blocking publish.
 */
function clickfuzz_web_update_all_site_navs($site_dir, $real_site_dir, array $published_pages, array $pages_indexed, $nav_html, $skip_file = null)
{
    if (!function_exists('clickfuzz_web_site_put')) {
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
    }

    $slug    = basename($site_dir);
    $cf_file = $site_dir . '/_cf/header.html';

    if (file_exists($cf_file)) {
        // SSI mode: update _cf/header.html and push — page files pick it up at serve time.
        $header = @file_get_contents($cf_file) ?: '';
        if ($header) {
            $updated = clickfuzz_web_update_html_nav($header, $nav_html);
            clickfuzz_web_site_put($slug, '_cf/header.html', $updated);
        }

        // Homepage is monolithic — patch it directly and push.
        $homepage_file = $site_dir . '/index.html';
        if (file_exists($homepage_file) && realpath($homepage_file) !== $skip_file) {
            clickfuzz_web_update_nav_in_file($homepage_file, $real_site_dir, $nav_html);
            if (get_option('pitchsnap_publish_ftp_host')) {
                $homepage_html = @file_get_contents($homepage_file);
                if ($homepage_html !== false) {
                    clickfuzz_web_remote_put($slug . '/index.html', $homepage_html);
                }
            }
        }
    } else {
        // Legacy mode: update every published HTML file individually.
        $homepage_file = $site_dir . '/index.html';
        if (file_exists($homepage_file) && realpath($homepage_file) !== $skip_file) {
            clickfuzz_web_update_nav_in_file($homepage_file, $real_site_dir, $nav_html);
        }

        foreach ($published_pages as $p) {
            if (empty($p->published_path)) { continue; }
            $file = $site_dir . '/' . $p->published_path . '/index.html';
            $real = realpath($file);
            if (!$real || $real === $skip_file) { continue; }
            if (strpos(rtrim($real, '/') . '/', rtrim($real_site_dir, '/') . '/') !== 0) { continue; }
            clickfuzz_web_update_nav_in_file($file, $real_site_dir, $nav_html);
        }
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
 * If $existing_item_id > 0 (a stored WP menu-item ID), it is used directly
 * for the update request — the search-by-object_id query is skipped, preventing
 * duplicate menu items on republish.
 *
 * @param  object $site             Site row
 * @param  int    $menu_id          WP menu ID
 * @param  int    $wp_page_id       WP page ID for this page
 * @param  string $label            Menu item label
 * @param  int    $parent_item      WP menu-item ID of the parent item (0 = top-level)
 * @param  int    $order            Menu item order
 * @param  int    $existing_item_id Previously stored WP menu-item ID (0 = unknown/new)
 * @return array  ['ok', 'item_id', 'error']
 */
function clickfuzz_web_wp_upsert_menu_item($site, $menu_id, $wp_page_id, $label, $parent_item = 0, $order = 0, $existing_item_id = 0)
{
    $method   = 'POST';
    $endpoint = '/wp-json/wp/v2/menu-items';

    if ($existing_item_id > 0) {
        // Use the stored ID directly — deterministic update, no duplicate risk
        $endpoint = '/wp-json/wp/v2/menu-items/' . (int) $existing_item_id;
    } else {
        // Search for an existing menu item for this WP page in this menu
        $existing = clickfuzz_web_wp_call($site, 'GET',
            '/wp-json/wp/v2/menu-items?menus=' . $menu_id . '&object_id=' . $wp_page_id . '&per_page=5', [], 15);

        if ($existing['ok'] && !empty($existing['body'])) {
            foreach ($existing['body'] as $mi) {
                if ((int)($mi['object_id'] ?? 0) === (int) $wp_page_id) {
                    $endpoint = '/wp-json/wp/v2/menu-items/' . (int) $mi['id'];
                    break;
                }
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

    $resp = clickfuzz_web_wp_call($site, $method, $endpoint, $item_body, 20);
    return [
        'ok'      => $resp['ok'],
        'item_id' => $resp['ok'] ? (int)($resp['body']['id'] ?? 0) : 0,
        'error'   => $resp['error'],
    ];
}

/**
 * Resolves a WP menu ID by trying multiple registered location slugs in order.
 *
 * WordPress themes register menu locations using arbitrary slugs. This function
 * accepts a prioritised list of candidate slugs and returns the first menu that
 * is assigned to any of them.
 *
 * Returns:
 *   menu_id   — WP menu ID (0 if not found)
 *   location  — the location slug that matched, or null
 *   error     — human-readable reason if not found, or null on success
 *
 * @param  object $site        Site row
 * @param  array  $candidates  Ordered list of location slugs to try
 * @return array  ['menu_id' => int, 'location' => string|null, 'error' => string|null]
 */
function clickfuzz_web_wp_resolve_menu_location($site, array $candidates)
{
    $resp = clickfuzz_web_wp_call($site, 'GET', '/wp-json/wp/v2/menus?per_page=50', [], 15);
    if (!$resp['ok'] || empty($resp['body'])) {
        $code = $resp['code'] ?? 0;
        return ['menu_id' => 0, 'location' => null,
            'error' => 'Could not fetch WP menus' . ($code ? ' (HTTP ' . $code . ')' : '') . '. WP 5.9+ required for menu REST support.'];
    }

    foreach ($candidates as $loc) {
        foreach ($resp['body'] as $menu) {
            $locs = $menu['locations'] ?? [];
            if (in_array($loc, $locs, true)) {
                return ['menu_id' => (int) $menu['id'], 'location' => $loc, 'error' => null];
            }
        }
    }

    return [
        'menu_id'  => 0,
        'location' => null,
        'error'    => 'No menu is assigned to any of the candidate locations: ' . implode(', ', $candidates) . '. Assign a menu to one of those locations in the WordPress Appearance → Menus screen.',
    ];
}

/**
 * Gets the WP menu ID for a named location — backward-compat thin wrapper.
 * Prefer clickfuzz_web_wp_resolve_menu_location() for new code.
 *
 * @return int  Menu ID, or 0 if not found.
 */
function clickfuzz_web_wp_get_menu_id($site, $location = 'primary')
{
    $result = clickfuzz_web_wp_resolve_menu_location($site, [$location]);
    return $result['menu_id'];
}

// ---------------------------------------------------------------------------
// WordPress publishing — full flow
// ---------------------------------------------------------------------------

/**
 * Publishes an internal page to WordPress via REST API.
 *
 * Steps:
 *   1. Validate WP credentials configured
 *   2. Normalize page body (strip any site chrome from stored generation)
 *   3. Create or update the WP page (WP theme provides header/footer/nav)
 *   4. Store page meta (CSS, JS, meta title, meta description, noindex)
 *   5. Update WP primary menu via resolved location (fallback chain), using stored
 *      menu-item ID to prevent duplicate items on republish
 *   6. Update WP footer menu via resolved location (fallback chain)
 *   7. Return success with wp_page_id, menu item IDs, and URL
 *
 * @param  object   $page                      ClickFuzz page row
 * @param  object   $site                      Site row
 * @param  object   $gen                       Current generation row
 * @param  int|null $parent_wp_page_id         WP page ID of CF parent page (null if no parent)
 * @param  int      $parent_primary_menu_item  WP menu-item ID of parent in primary menu (0 if unknown)
 * @return array    ['success', 'url', 'wp_page_id', 'wp_primary_menu_item_id', 'wp_footer_menu_item_id', 'error']
 */
function clickfuzz_web_publish_page_wp($page, $site, $gen, $parent_wp_page_id = null, $parent_primary_menu_item = 0)
{
    if (empty($site->wp_site_url)) {
        return ['success' => false, 'url' => null, 'wp_page_id' => null,
            'wp_primary_menu_item_id' => null, 'wp_footer_menu_item_id' => null,
            'error' => 'WordPress site URL not configured on this site.'];
    }
    if (empty($site->wp_username) || empty($site->wp_app_password)) {
        return ['success' => false, 'url' => null, 'wp_page_id' => null,
            'wp_primary_menu_item_id' => null, 'wp_footer_menu_item_id' => null,
            'error' => 'WordPress credentials not configured.'];
    }

    $meta_title = !empty($page->meta_title) ? $page->meta_title
        : (!empty($gen->meta_title_generated) ? $gen->meta_title_generated : $page->title);
    $meta_desc  = !empty($page->meta_description) ? $page->meta_description
        : ($gen->meta_description_generated ?? '');

    // Normalize body content — strip any site chrome in stored generation records.
    // WP theme provides header/footer/nav; only page-specific body content goes to WP.
    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';
    $body_content = clickfuzz_web_normalize_page_body_html($gen->html_content);

    // Build WP page payload (normalized body only — WP theme owns header/footer/nav)
    $page_payload = [
        'title'   => ['raw' => $meta_title],
        'content' => ['raw' => $body_content],
        'status'  => 'publish',
        'slug'    => $page->slug,
    ];
    if ($parent_wp_page_id) {
        // WP page parent ID (hierarchy in WP page tree — separate from menu-item parent)
        $page_payload['parent'] = (int) $parent_wp_page_id;
    }

    // Create or update WP page
    $existing_wp_id = !empty($page->wp_page_id) ? (int) $page->wp_page_id : 0;
    $endpoint = $existing_wp_id
        ? '/wp-json/wp/v2/pages/' . $existing_wp_id
        : '/wp-json/wp/v2/pages';

    $resp = clickfuzz_web_wp_call($site, 'POST', $endpoint, $page_payload, 30);
    if (!$resp['ok']) {
        return ['success' => false, 'url' => null, 'wp_page_id' => $existing_wp_id ?: null,
            'wp_primary_menu_item_id' => null, 'wp_footer_menu_item_id' => null,
            'error' => 'WordPress API error: ' . $resp['error']];
    }

    $new_wp_id   = (int) ($resp['body']['id'] ?? 0);
    $wp_page_url = $resp['body']['link'] ?? (rtrim($site->wp_site_url, '/') . '/' . $page->slug . '/');
    if (!$new_wp_id) {
        return ['success' => false, 'url' => null, 'wp_page_id' => $existing_wp_id ?: null,
            'wp_primary_menu_item_id' => null, 'wp_footer_menu_item_id' => null,
            'error' => 'WordPress API did not return a page ID.'];
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
            ['key' => $key, 'value' => (string) $value], 15);
    }

    // Primary menu candidates — ordered by likelihood for the ClickFuzz WP theme
    $primary_candidates = ['primary', 'primary-navigation', 'header-menu', 'main-navigation', 'main-menu', 'clickfuzz-primary'];
    // Footer menu candidates
    $footer_candidates  = ['footer', 'footer-menu', 'footer-nav', 'footer-navigation', 'clickfuzz-footer'];

    $wp_primary_menu_item_id = null;
    $wp_footer_menu_item_id  = null;

    // Update primary menu (best-effort — failure does not block publish; accurate error logged)
    if ($page->menu_primary) {
        $label       = !empty($page->menu_label) ? $page->menu_label : $page->title;
        $loc_result  = clickfuzz_web_wp_resolve_menu_location($site, $primary_candidates);

        if ($loc_result['menu_id']) {
            // Use the stored WP menu-item parent ID from the ClickFuzz parent page if available.
            // This is the WP menu-item ID of the parent, NOT the WP page ID of the parent.
            $parent_menu_item_id = (int) $parent_primary_menu_item;

            // Fall back to API lookup if no stored parent menu-item ID
            if (!$parent_menu_item_id && $parent_wp_page_id) {
                $pi = clickfuzz_web_wp_call($site, 'GET',
                    '/wp-json/wp/v2/menu-items?menus=' . $loc_result['menu_id']
                    . '&object_id=' . $parent_wp_page_id . '&per_page=5', [], 15);
                if ($pi['ok'] && !empty($pi['body'])) {
                    $parent_menu_item_id = (int) ($pi['body'][0]['id'] ?? 0);
                }
            }

            // Use stored ClickFuzz menu-item ID for deterministic update (no duplicate creation)
            $existing_primary_item = !empty($page->wp_primary_menu_item_id) ? (int) $page->wp_primary_menu_item_id : 0;
            $upsert = clickfuzz_web_wp_upsert_menu_item(
                $site, $loc_result['menu_id'], $new_wp_id, $label,
                $parent_menu_item_id, (int) $page->menu_order, $existing_primary_item
            );
            if ($upsert['ok'] && $upsert['item_id']) {
                $wp_primary_menu_item_id = $upsert['item_id'];
            }
        } else {
            // Menu location not found — log accurately; do not silently succeed
            log_activity('ClickFuzz Web: WP primary menu update skipped [Page #' . $page->id . '] ' . $loc_result['error']);
        }
    }

    // Update footer menu (best-effort)
    if ($page->menu_footer) {
        $label      = !empty($page->menu_label) ? $page->menu_label : $page->title;
        $loc_result = clickfuzz_web_wp_resolve_menu_location($site, $footer_candidates);

        if ($loc_result['menu_id']) {
            $existing_footer_item = !empty($page->wp_footer_menu_item_id) ? (int) $page->wp_footer_menu_item_id : 0;
            $upsert = clickfuzz_web_wp_upsert_menu_item(
                $site, $loc_result['menu_id'], $new_wp_id, $label,
                0, (int) $page->menu_order, $existing_footer_item
            );
            if ($upsert['ok'] && $upsert['item_id']) {
                $wp_footer_menu_item_id = $upsert['item_id'];
            }
        } else {
            log_activity('ClickFuzz Web: WP footer menu update skipped [Page #' . $page->id . '] ' . $loc_result['error']);
        }
    }

    return [
        'success'                 => true,
        'url'                     => $wp_page_url,
        'wp_page_id'              => $new_wp_id,
        'wp_primary_menu_item_id' => $wp_primary_menu_item_id,
        'wp_footer_menu_item_id'  => $wp_footer_menu_item_id,
        'error'                   => null,
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
