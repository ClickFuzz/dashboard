<?php
defined('BASEPATH') or exit('No direct script access allowed');

// ---------------------------------------------------------------------------
// Main orchestrator
// ---------------------------------------------------------------------------

/**
 * Convert a ClickFuzz-generated website to a WordPress export package.
 *
 * @param  int   $website_id  tblpitchsnap_redesigns.id
 * @return array ['success'=>bool, 'zip_path'=>string|null, 'download_url'=>string|null,
 *               'site_slug'=>string|null, 'theme_slug'=>string|null,
 *               'warnings'=>array, 'error'=>string|null]
 */
function clickfuzz_web_export_wordpress_site($website_id)
{
    $website_id = (int) $website_id;
    if (!$website_id) {
        return _cfw_wp_err('Invalid website ID.');
    }

    // ── Load model ──────────────────────────────────────────────────────────
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $website = $CI->pitchsnap_model->get($website_id);
    if (!$website) {
        return _cfw_wp_err('Website #' . $website_id . ' not found.');
    }

    $html = (string) ($website->generation_result ?? '');
    if (strlen($html) < 500 || stripos($html, '<html') === false) {
        return _cfw_wp_err('Website has no valid generated HTML. Generate the site first.');
    }

    // ── Derive business name ─────────────────────────────────────────────────
    $business_name = 'Business';
    if (!empty($website->lead_id)) {
        if (!isset($CI->leads_model)) {
            $CI->load->model('leads_model');
        }
        $lead = $CI->leads_model->get((int) $website->lead_id);
        if ($lead) {
            $business_name = !empty($lead->company) ? $lead->company
                           : (!empty($lead->name)   ? $lead->name : 'Business');
        }
    }

    $site_slug  = _cfw_wp_make_slug($business_name);
    $theme_slug = 'clickfuzz-generated-' . $site_slug;
    $theme_name = $business_name . ' - by ClickFuzz';

    // ── Create export workspace ──────────────────────────────────────────────
    $exports_base = dirname(FCPATH) . '/exports/wordpress';
    $export_id    = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $workspace    = $exports_base . '/' . $website_id . '/' . $export_id;
    $theme_dir    = $workspace . '/theme-src/' . $theme_slug;
    $package_dir  = $workspace . '/package';

    foreach ([$workspace, $theme_dir, $package_dir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return _cfw_wp_err('Could not create export workspace directory.');
        }
    }

    // ── Parse HTML ──────────────────────────────────────────────────────────
    $parts = _cfw_wp_parse_html($html);
    if (!$parts['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('HTML parse failed: ' . $parts['error']);
    }

    // ── Extract navigation items for WXR and header.php ─────────────────────
    $nav_items        = _cfw_wp_extract_nav_items($parts['header_html']);
    $footer_nav_items = _cfw_wp_extract_footer_nav_items($parts['footer_html']);
    $has_footer_nav   = count($footer_nav_items) >= 2;

    // ── Detect and download logo ─────────────────────────────────────────────
    $logo_info      = null;
    $logo_manifest  = null;
    $logo_detected  = _cfw_wp_detect_logo($html);
    if ($logo_detected) {
        $logo_filename = _cfw_wp_make_logo_filename($site_slug, $logo_detected['src']);
        if ($logo_filename !== null) {
            if (!is_dir($package_dir . '/media')) {
                mkdir($package_dir . '/media', 0755, true);
            }
            $logo_dest = $package_dir . '/media/' . $logo_filename;
            $downloaded = _cfw_wp_download_logo($logo_detected['src'], $logo_dest);
            if ($downloaded) {
                $logo_info     = $logo_detected;
                $logo_manifest = [
                    'detected'           => true,
                    'business_name'      => $business_name,
                    'preferred_filename' => $logo_filename,
                    'source_url'         => $logo_detected['src'],
                    'asset_path'         => 'media/' . $logo_filename,
                ];
            }
        }
    }

    // ── Build theme files ────────────────────────────────────────────────────
    $build = _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir, $has_footer_nav, $logo_info);
    if (!$build['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('Theme build failed: ' . $build['error']);
    }

    // ── Validate theme ───────────────────────────────────────────────────────
    $validation = _cfw_wp_validate($theme_dir, $theme_slug);
    // Validation errors are fatal; warnings are collected.
    if (!$validation['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('Validation failed: ' . $validation['error']);
    }

    // ── ZIP theme ────────────────────────────────────────────────────────────
    $theme_zip_path = $workspace . '/' . $theme_slug . '.zip';
    $zip_result     = _cfw_wp_zip_dir($theme_dir, $theme_slug, $theme_zip_path);
    if (!$zip_result['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('Theme ZIP failed: ' . $zip_result['error']);
    }

    // ── Assemble package directory ───────────────────────────────────────────
    $pkg_theme   = $package_dir . '/theme';
    $pkg_content = $package_dir . '/content';
    $pkg_media   = $package_dir . '/media';
    foreach ([$pkg_theme, $pkg_content, $pkg_media] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    rename($theme_zip_path, $pkg_theme . '/' . $theme_slug . '.zip');

    // ── Generate WXR ─────────────────────────────────────────────────────────
    $wxr_result = _cfw_wp_generate_wxr(
        $site_slug, $theme_name,
        $pkg_content . '/clickfuzz-content.xml',
        $nav_items, $footer_nav_items
    );
    if (!$wxr_result['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('WXR generation failed: ' . $wxr_result['error']);
    }

    // ── Manifest ─────────────────────────────────────────────────────────────
    $warnings = array_merge($parts['warnings'], $validation['warnings']);
    $manifest = [
        'format'     => 'clickfuzz-wordpress-export',
        'version'    => 2,
        'website_id' => $website_id,
        'site_slug'  => $site_slug,
        'theme'      => [
            'slug'    => $theme_slug,
            'name'    => $theme_name,
            'version' => '1.0.0',
            'file'    => 'theme/' . $theme_slug . '.zip',
        ],
        'content'   => ['wxr' => 'content/clickfuzz-content.xml'],
        'wordpress' => array_filter([
            'homepage'   => ['render_mode' => 'theme'],
            'page_modes' => ['wordpress', 'clickfuzz_generated'],
            'menus'      => array_values(array_filter([
                ['location' => 'primary', 'name' => 'Primary Menu'],
                $has_footer_nav ? ['location' => 'footer', 'name' => 'Footer Menu'] : null,
            ])),
            'site_logo'  => $logo_manifest,
        ]),
        'generated_page_meta_keys' => [
            '_clickfuzz_generated_page' => 'marker (set to 1 for ClickFuzz-generated pages)',
            '_clickfuzz_generated_html' => 'complete page body HTML including site header and footer; chrome is stripped at render time by page.php',
            '_clickfuzz_generated_css'  => 'page-specific inline CSS (optional)',
            '_clickfuzz_generated_js'   => 'page-specific inline JS (optional)',
        ],
        'generated_page_assets'  => 'uploads/clickfuzz/pages/{page_id}/',
        'menu_locations'         => array_filter(['primary' => 'Primary Menu', 'footer' => $has_footer_nav ? 'Footer Menu' : null]),
        'imported_menus'         => array_filter(['primary' => count($nav_items) > 0 ? 'Primary Menu' : null, 'footer' => count($footer_nav_items) > 0 ? 'Footer Menu' : null]),
        'nav_item_count'         => ['primary' => count($nav_items), 'footer' => count($footer_nav_items)],
        'assets'                 => [],
        'runtime_dependencies'   => $parts['runtime_deps'],
        'warnings'               => $warnings,
        'generated_at'           => date('c'),
    ];
    _cfw_wp_write_file(
        $package_dir . '/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // ── README ───────────────────────────────────────────────────────────────
    _cfw_wp_write_file($package_dir . '/README.txt', _cfw_wp_readme($theme_slug, $business_name));

    // ── Outer package ZIP ────────────────────────────────────────────────────
    $outer_slug   = 'clickfuzz-' . $site_slug . '-wordpress';
    $outer_zip    = $exports_base . '/' . $website_id . '/' . $outer_slug . '.zip';

    // Remove previous export for this website_id if it exists.
    if (file_exists($outer_zip)) {
        unlink($outer_zip);
    }

    $outer_result = _cfw_wp_zip_dir($package_dir, $outer_slug, $outer_zip);
    if (!$outer_result['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('Package ZIP failed: ' . $outer_result['error']);
    }

    // ── Cleanup workspace (keep only the output ZIP) ─────────────────────────
    _cfw_wp_rm_dir($workspace);

    $download_url = 'https://clickfuzz.com/exports/wordpress/' . $website_id . '/' . rawurlencode($outer_slug) . '.zip';

    log_activity('ClickFuzz Web: WordPress export created [Website ID: ' . $website_id . ']');

    return [
        'success'       => true,
        'zip_path'      => $outer_zip,
        'download_url'  => $download_url,
        'site_slug'     => $site_slug,
        'theme_slug'    => $theme_slug,
        'business_name' => $business_name,
        'warnings'      => $warnings,
        'error'         => null,
    ];
}

// ---------------------------------------------------------------------------
// HTML parsing
// ---------------------------------------------------------------------------

/**
 * Parse a ClickFuzz-generated single-file HTML document into structural parts.
 *
 * @return array [
 *   'success'      => bool,
 *   'error'        => string|null,
 *   'warnings'     => string[],
 *   'runtime_deps' => array[],
 *   'css'          => string,       // extracted from <style> blocks
 *   'font_links'   => string[],     // Google Fonts / external stylesheet hrefs
 *   'site_title'   => string,
 *   'header_html'  => string,       // visual <header>...</header>
 *   'footer_html'  => string,       // visual <footer>...</footer>
 *   'main_html'    => string,       // content between </header> and <footer>
 * ]
 */
function _cfw_wp_parse_html($html)
{
    $result = [
        'success'      => false,
        'error'        => null,
        'warnings'     => [],
        'runtime_deps' => [],
        'css'          => '',
        'font_links'   => [],
        'site_title'   => '',
        'header_html'  => '',
        'footer_html'  => '',
        'main_html'    => '',
    ];

    // ── Strip preview-only artefacts ─────────────────────────────────────────
    $html = preg_replace('/<meta\b[^>]*\bnoindex\b[^>]*>/i', '', $html);

    // ── ClickFuzz runtime script → record dependency, strip ─────────────────
    if (preg_match('/<script\b[^>]*pitchsnap\/runtime\.js[^>]*>.*?<\/script>/si', $html, $m)) {
        $result['runtime_deps'][] = [
            'id'   => 'clickfuzz_runtime',
            'note' => 'ClickFuzz runtime (runtime.js) was stripped. It requires the ClickFuzz CRM and cannot be directly ported to WordPress.',
        ];
        $result['warnings'][] = 'ClickFuzz runtime script removed. Re-integrate lead capture on WordPress separately.';
        $html = str_ireplace($m[0], '', $html);
    }

    // ── Convert dynamic year spans ───────────────────────────────────────────
    $html = _cfw_wp_convert_dynamic_year($html);

    // ── Extract <head> section ───────────────────────────────────────────────
    if (!preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $head_m)) {
        return array_merge($result, ['error' => 'Could not locate <head> section.']);
    }
    $head = $head_m[1];

    // Title
    if (preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $head, $t_m)) {
        $result['site_title'] = trim(strip_tags($t_m[1]));
    }

    // Extract CSS from all <style> blocks in head (and body)
    $css_parts = [];
    if (preg_match_all('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $html, $s_m)) {
        foreach ($s_m[1] as $block) {
            $css_parts[] = trim($block);
        }
        // Strip style tags from html so they don't end up in theme templates
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html);
    }
    $result['css'] = implode("\n\n", array_filter($css_parts));

    // External font / stylesheet links from head
    if (preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $head, $link_m)) {
        foreach ($link_m[0] as $tag) {
            if (preg_match('/\bhref=["\']([^"\']+)["\']/', $tag, $href_m)) {
                $href = $href_m[1];
                if (_cfw_wp_is_external_url($href)) {
                    $result['font_links'][] = $href;
                }
            }
        }
    }

    // ── Extract <body> content ───────────────────────────────────────────────
    if (!preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', $html, $body_m)) {
        return array_merge($result, ['error' => 'Could not locate <body> section.']);
    }
    $body = $body_m[1];

    // ── Extract visual <header> — with fallbacks for non-semantic layouts ────
    $header_info = _cfw_wp_extract_element('header', $body);
    if ($header_info['html'] === null) {
        // Fallback 1: use outermost <nav> as the header proxy
        $nav_info = _cfw_wp_extract_element('nav', $body);
        if ($nav_info['html'] !== null) {
            $header_info = $nav_info;
            $result['warnings'][] = 'No <header> element found; using <nav> as header template. Regenerate the site for a cleaner WordPress export.';
        } else {
            // Fallback 2: everything before the first <section>, <main>, or <article>
            $preamble = _cfw_wp_extract_preamble($body);
            if ($preamble['html'] !== null && strlen(trim($preamble['html'])) > 0) {
                $header_info = $preamble;
                $result['warnings'][] = 'No <header> or <nav> element found; using page preamble as header template. Regenerate the site for a cleaner WordPress export.';
            } else {
                // Fallback 3: empty header — full body goes into main content
                $header_info = ['html' => '', 'start' => 0, 'end' => 0];
                $result['warnings'][] = 'No header structure found; header template will be empty. Regenerate the site for a proper WordPress export.';
            }
        }
    }
    $result['header_html'] = $header_info['html'];
    $after_header_pos      = $header_info['end'];

    // ── Extract visual <footer> — with fallback for non-semantic layouts ──────
    $footer_info = _cfw_wp_extract_last_element('footer', $body);
    if ($footer_info['html'] === null) {
        // Fallback: use last <section> as footer proxy
        $last_section = _cfw_wp_extract_last_element('section', $body);
        if ($last_section['html'] !== null) {
            $footer_info = $last_section;
            $result['warnings'][] = 'No <footer> element found; using last <section> as footer template. Regenerate the site for a cleaner WordPress export.';
        } else {
            // Last resort: empty footer at end of body
            $body_len    = strlen($body);
            $footer_info = ['html' => '', 'start' => $body_len, 'end' => $body_len];
            $result['warnings'][] = 'No <footer> or <section> element found; footer template will be empty. Regenerate the site for a proper WordPress export.';
        }
    }
    $result['footer_html'] = $footer_info['html'];
    $footer_start_pos      = $footer_info['start'];

    // ── Main content between header and footer ───────────────────────────────
    if ($footer_start_pos < $after_header_pos) {
        return array_merge($result, ['error' => 'Footer position appears before header end — unexpected HTML structure.']);
    }
    $result['main_html'] = trim(substr($body, $after_header_pos, $footer_start_pos - $after_header_pos));

    // ── Convert internal links ───────────────────────────────────────────────
    $result['header_html'] = _cfw_wp_convert_internal_links($result['header_html']);
    $result['main_html']   = _cfw_wp_convert_internal_links($result['main_html']);
    $result['footer_html'] = _cfw_wp_convert_internal_links($result['footer_html']);

    $result['success'] = true;
    return $result;
}

// ---------------------------------------------------------------------------
// Element extraction helpers
// ---------------------------------------------------------------------------

/**
 * Extract the first occurrence of <tag>...</tag> from $html (nesting-aware).
 * Returns ['html'=>string|null, 'start'=>int, 'end'=>int].
 */
function _cfw_wp_extract_element($tag, $html)
{
    $tn = preg_quote($tag, '/');
    // Find first opening tag
    if (!preg_match('/<' . $tn . '\b[^>]*>/i', $html, $om, PREG_OFFSET_CAPTURE)) {
        return ['html' => null, 'start' => -1, 'end' => -1];
    }
    $open_pos = $om[0][1];
    $open_end = $open_pos + strlen($om[0][0]);

    // Walk forward tracking nesting
    $depth = 1;
    $pos   = $open_end;
    $len   = strlen($html);

    while ($pos < $len && $depth > 0) {
        $has_open  = preg_match('/<' . $tn . '\b[^>]*>/i', $html, $io, PREG_OFFSET_CAPTURE, $pos);
        $has_close = preg_match('/<\/' . $tn . '\s*>/i',   $html, $ic, PREG_OFFSET_CAPTURE, $pos);

        if (!$has_close) break;

        $next_open  = $has_open  ? $io[0][1] : PHP_INT_MAX;
        $next_close = $ic[0][1];
        $close_end  = $next_close + strlen($ic[0][0]);

        if ($next_open < $next_close) {
            $depth++;
            $pos = $next_open + strlen($io[0][0]);
        } else {
            $depth--;
            $pos = $close_end;
        }
    }

    if ($depth !== 0) {
        return ['html' => null, 'start' => -1, 'end' => -1];
    }

    return [
        'html'  => substr($html, $open_pos, $pos - $open_pos),
        'start' => $open_pos,
        'end'   => $pos,
    ];
}

/**
 * Extract the LAST occurrence of <tag>...</tag> from $html (nesting-aware).
 */
function _cfw_wp_extract_last_element($tag, $html)
{
    $tn  = preg_quote($tag, '/');
    $len = strlen($html);

    // Collect all opening tag positions
    preg_match_all('/<' . $tn . '\b[^>]*>/i', $html, $all_opens, PREG_OFFSET_CAPTURE);
    if (empty($all_opens[0])) {
        return ['html' => null, 'start' => -1, 'end' => -1];
    }

    // Try from the last opening tag backward to find the outermost occurrence
    foreach (array_reverse($all_opens[0]) as [$open_tag, $open_pos]) {
        $open_end = $open_pos + strlen($open_tag);
        $depth    = 1;
        $pos      = $open_end;

        while ($pos < $len && $depth > 0) {
            $has_open  = preg_match('/<' . $tn . '\b[^>]*>/i', $html, $io, PREG_OFFSET_CAPTURE, $pos);
            $has_close = preg_match('/<\/' . $tn . '\s*>/i',   $html, $ic, PREG_OFFSET_CAPTURE, $pos);

            if (!$has_close) { $depth = -1; break; }

            $next_open  = $has_open ? $io[0][1] : PHP_INT_MAX;
            $next_close = $ic[0][1];
            $close_end  = $next_close + strlen($ic[0][0]);

            if ($next_open < $next_close) {
                $depth++;
                $pos = $next_open + strlen($io[0][0]);
            } else {
                $depth--;
                $pos = $close_end;
            }
        }

        if ($depth === 0) {
            return [
                'html'  => substr($html, $open_pos, $pos - $open_pos),
                'start' => $open_pos,
                'end'   => $pos,
            ];
        }
    }

    return ['html' => null, 'start' => -1, 'end' => -1];
}

/**
 * Extract everything before the first <section>, <main>, or <article> as a header "preamble".
 * Returns ['html'=>string|null, 'start'=>0, 'end'=>int] or html===null if nothing useful found.
 */
function _cfw_wp_extract_preamble($html)
{
    $first_pos = PHP_INT_MAX;
    foreach (['section', 'main', 'article'] as $tag) {
        if (preg_match('/<' . $tag . '\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            if ($m[0][1] < $first_pos) {
                $first_pos = $m[0][1];
            }
        }
    }

    if ($first_pos === PHP_INT_MAX || $first_pos === 0) {
        return ['html' => null, 'start' => -1, 'end' => -1];
    }

    return [
        'html'  => substr($html, 0, $first_pos),
        'start' => 0,
        'end'   => $first_pos,
    ];
}

// ---------------------------------------------------------------------------
// Custom Logo — detection, download, injection
// ---------------------------------------------------------------------------

/**
 * Extract a single attribute value from an HTML tag's attributes string.
 */
function _cfw_wp_attr(string $attrs, string $name): string
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*["\']([^"\']*)["\']/', $attrs, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Detect the primary logo in generated HTML.
 * Searches within <header> only; falls back to full HTML if no header found.
 * Priority:
 *   1. <img> whose alt, class, or src filename contains logo|wordmark|brand
 *   2. Sole <img> inside a home-pointing <a> (href that starts with "/")
 *   3. First <img> not inside a <ul>
 * Only http(s):// src values are accepted (not SVG data URIs, not relative paths).
 *
 * @return array|null ['src','alt','width','height','img_tag','anchor_tag']|null
 */
function _cfw_wp_detect_logo(string $html): ?array
{
    if (preg_match('/<header\b[^>]*>([\s\S]*?)<\/header>/i', $html, $hm)) {
        $scope = $hm[0];
    } else {
        $scope = $html;
    }

    if (!preg_match_all('/<img\b([^>]+)>/i', $scope, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $candidates = [];
    foreach ($matches[0] as $idx => $match) {
        $img_tag  = $match[0];
        $img_pos  = $match[1];
        $attr_str = $matches[1][$idx][0];
        $src      = _cfw_wp_attr($attr_str, 'src');
        if (!preg_match('#^https?://#i', $src)) {
            continue;
        }
        $candidates[] = [
            'src'     => $src,
            'alt'     => _cfw_wp_attr($attr_str, 'alt'),
            'width'   => _cfw_wp_attr($attr_str, 'width'),
            'height'  => _cfw_wp_attr($attr_str, 'height'),
            'img_tag' => $img_tag,
            'img_pos' => $img_pos,
        ];
    }

    if (empty($candidates)) {
        return null;
    }

    // Priority 1: keyword in alt, class, or src filename
    foreach ($candidates as $c) {
        $filename = basename(parse_url($c['src'], PHP_URL_PATH) ?? '');
        preg_match('/<img\b([^>]+)>/i', $c['img_tag'], $im);
        $class = _cfw_wp_attr($im[1] ?? '', 'class');
        if (preg_match('/\b(logo|wordmark|brand)\b/i', $c['alt'])
         || preg_match('/\b(logo|wordmark|brand)\b/i', $class)
         || preg_match('/\b(logo|wordmark|brand)\b/i', $filename)) {
            return array_merge($c, ['anchor_tag' => _cfw_wp_find_logo_anchor($scope, $c['img_tag'])]);
        }
    }

    // Priority 2: sole img inside a home-pointing <a>
    if (preg_match_all('/<a\b([^>]*)>([\s\S]*?)<\/a>/i', $scope, $am, PREG_OFFSET_CAPTURE)) {
        foreach ($am[0] as $aidx => $amatch) {
            $a_tag   = $amatch[0];
            $a_attrs = $am[1][$aidx][0];
            $a_inner = $am[2][$aidx][0];
            $href    = rtrim(_cfw_wp_attr($a_attrs, 'href'), '/');
            if (!in_array($href, ['', '#', '/'], true) && !preg_match('#^/#', $href)) {
                continue;
            }
            preg_match_all('/<img\b[^>]+>/i', $a_inner, $inner_imgs);
            if (count($inner_imgs[0]) !== 1) {
                continue;
            }
            $sole_img = $inner_imgs[0][0];
            foreach ($candidates as $c) {
                if ($c['img_tag'] === $sole_img) {
                    return array_merge($c, ['anchor_tag' => $a_tag]);
                }
            }
        }
    }

    // Priority 3: first img not inside a <ul>
    foreach ($candidates as $c) {
        $before    = substr($scope, 0, $c['img_pos']);
        $ul_open   = substr_count(strtolower($before), '<ul');
        $ul_close  = substr_count(strtolower($before), '</ul');
        if ($ul_open <= $ul_close) {
            return array_merge($c, ['anchor_tag' => _cfw_wp_find_logo_anchor($scope, $c['img_tag'])]);
        }
    }

    return null;
}

/**
 * Find the <a>...</a> that directly wraps the given img_tag using positional search.
 * Returns null if not found or if there are intervening elements between <a> and the img.
 */
function _cfw_wp_find_logo_anchor(string $html, string $img_tag): ?string
{
    $img_pos = strpos($html, $img_tag);
    if ($img_pos === false) {
        return null;
    }
    $before     = substr($html, 0, $img_pos);
    $a_open_pos = strrpos($before, '<a');
    if ($a_open_pos === false) {
        return null;
    }
    $after_img   = $img_pos + strlen($img_tag);
    $a_close_pos = stripos($html, '</a>', $after_img);
    if ($a_close_pos === false) {
        return null;
    }
    $a_tag = substr($html, $a_open_pos, $a_close_pos + 4 - $a_open_pos);
    if (strpos($a_tag, $img_tag) === false) {
        return null;
    }
    return $a_tag;
}

/**
 * Detect a logo in the footer whose src exactly matches the header logo src.
 *
 * @return array|null ['img_tag','anchor_tag']|null
 */
function _cfw_wp_detect_footer_logo(string $footer_html, string $header_logo_src): ?array
{
    if (!preg_match_all('/<img\b([^>]+)>/i', $footer_html, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    foreach ($matches[0] as $idx => $match) {
        $img_tag = $match[0];
        $src     = _cfw_wp_attr($matches[1][$idx][0], 'src');
        if ($src !== $header_logo_src) {
            continue;
        }
        return ['img_tag' => $img_tag, 'anchor_tag' => _cfw_wp_find_logo_anchor($footer_html, $img_tag)];
    }
    return null;
}

/**
 * Build a safe logo filename: "{site_slug}-logo.{ext}".
 * Returns null if the URL has an extension not in the allowed list.
 */
function _cfw_wp_make_logo_filename(string $site_slug, string $logo_src): ?string
{
    $path    = parse_url($logo_src, PHP_URL_PATH) ?? '';
    $ext     = strtolower(ltrim(strrchr(basename($path), '.'), '.'));
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    return $site_slug . '-logo.' . $ext;
}

/**
 * Download a remote http(s):// URL to a local file.
 * Returns false on failure or if the downloaded content is under 100 bytes.
 */
function _cfw_wp_download_logo(string $url, string $dest_path): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $ctx  = stream_context_create([
        'http' => [
            'timeout'         => 15,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'user_agent'      => 'ClickFuzz-WordPress-Exporter/1.0',
            'ignore_errors'   => false,
        ],
        'ssl'  => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 100) {
        return false;
    }
    return file_put_contents($dest_path, $data) !== false;
}

/**
 * Inject WordPress custom logo conditional around the logo's anchor or img tag.
 * Produces:
 *   <?php if (has_custom_logo()) : the_custom_logo(); else : ?>
 *   [original markup]
 *   <?php endif; ?>
 *
 * Uses positional splice — no regex replacement.
 */
function _cfw_wp_inject_custom_logo(string $html, ?array $logo_info): string
{
    if (empty($logo_info)) {
        return $html;
    }
    $target = !empty($logo_info['anchor_tag']) ? $logo_info['anchor_tag'] : ($logo_info['img_tag'] ?? '');
    if ($target === '') {
        return $html;
    }
    $pos = strpos($html, $target);
    if ($pos === false) {
        return $html;
    }
    $before      = substr($html, 0, $pos);
    $after       = substr($html, $pos + strlen($target));
    $replacement = "<?php if (has_custom_logo()) : the_custom_logo(); else : ?>\n"
                 . $target . "\n"
                 . "<?php endif; ?>";
    return $before . $replacement . $after;
}

// ---------------------------------------------------------------------------
// URL normalisation
// ---------------------------------------------------------------------------

/**
 * Normalise a menu URL: bare fragment (#section) → absolute fragment (/#section).
 * All other URLs are returned unchanged.
 */
function _cfw_wp_normalize_menu_url($url)
{
    $url = trim($url);
    if ($url !== '' && $url[0] === '#') {
        return '/' . $url;
    }
    return $url;
}

/**
 * Return true if an <a> tag's class attribute contains a CTA indicator word
 * (btn, cta, button) as a whole word (word-boundary match).
 */
function _cfw_wp_is_cta_link($a_tag)
{
    if (!preg_match('/\bclass=["\']([^"\']*)["\']/', $a_tag, $cm)) {
        return false;
    }
    return (bool) preg_match('/\b(?:btn|cta|button)\b/i', $cm[1]);
}

/**
 * Return true if a footer link should be excluded from the WP nav menu.
 * Excludes: tel:, mailto:, javascript: schemes; icon-only (empty visible text).
 */
function _cfw_wp_is_excluded_footer_link($url, $text)
{
    $url  = strtolower(trim($url));
    $text = trim(strip_tags($text));
    if ($text === '') return true;
    foreach (['tel:', 'mailto:', 'javascript:'] as $scheme) {
        if (strncmp($url, $scheme, strlen($scheme)) === 0) return true;
    }
    return false;
}

/**
 * Detect and return the footer navigation group element.
 *
 * Priority: <nav> → <ul class=nav/menu/link> → leaf <div class=nav/menu/link> with 2+ links.
 * Returns ['tag','class','inner','full'] or null if nothing suitable found.
 */
function _cfw_wp_find_footer_nav_group($footer_html)
{
    // Priority 1: <nav>
    if (preg_match('/<nav\b([^>]*)>([\s\S]*?)<\/nav>/i', $footer_html, $m)) {
        $cls = '';
        if (preg_match('/\bclass=["\']([^"\']*)["\']/', $m[1], $cm)) {
            $cls = $cm[1];
        }
        return ['tag' => 'nav', 'class' => $cls, 'inner' => $m[2], 'full' => $m[0]];
    }

    // Priority 2: <ul> with a nav/menu/link class
    if (preg_match_all('/<ul\b([^>]*)>([\s\S]*?)<\/ul>/i', $footer_html, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $cls = '';
            if (preg_match('/\bclass=["\']([^"\']*)["\']/', $m[1], $cm)) {
                $cls = $cm[1];
            }
            if (preg_match('/\b(?:nav|menu|link)/i', $cls)) {
                return ['tag' => 'ul', 'class' => $cls, 'inner' => $m[2], 'full' => $m[0]];
            }
        }
    }

    // Priority 3: leaf <div> with nav/menu/link class containing 2+ <a> tags.
    // "Leaf" = no nested div/section/ul/ol elements (tempered greedy token).
    if (preg_match_all('/<div\b([^>]*)>((?:(?!<(?:div|section|ul|ol)\b)[\s\S])*?)<\/div>/i', $footer_html, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $cls = '';
            if (preg_match('/\bclass=["\']([^"\']*)["\']/', $m[1], $cm)) {
                $cls = $cm[1];
            }
            if (!preg_match('/\b(?:nav|menu|link)/i', $cls)) continue;
            if (preg_match_all('/<a\b/i', $m[2]) < 2) continue;
            return ['tag' => 'div', 'class' => $cls, 'inner' => $m[2], 'full' => $m[0]];
        }
    }

    return null;
}

/**
 * Extract footer navigation links from the detected nav group.
 * Excludes tel:, mailto:, javascript: links and icon-only links (no visible text).
 */
function _cfw_wp_extract_footer_nav_items($footer_html)
{
    $group = _cfw_wp_find_footer_nav_group($footer_html);
    if ($group === null) return [];

    $inner = $group['inner'];
    $items = [];
    $seen  = [];
    $order = 1;

    if (!preg_match_all('/<a\b([^>]*)\bhref=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $inner, $matches, PREG_SET_ORDER)) {
        return $items;
    }
    foreach ($matches as $m) {
        $url   = trim($m[2]);
        $label = trim(strip_tags($m[3]));
        if (_cfw_wp_is_excluded_footer_link($url, $label)) continue;
        $key = strtolower($label) . '|' . $url;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = ['label' => $label, 'url' => $url, 'order' => $order++];
    }
    return $items;
}

/**
 * Extract navigation links from the primary <ul> inside the first <nav> element,
 * skipping CTA links (those whose <a> has a btn/cta/button class).
 *
 * Falls back to scanning all <a> tags inside <nav> (minus CTAs) when no <ul>
 * is present. Returns array of ['label'=>string, 'url'=>string, 'order'=>int].
 */
function _cfw_wp_extract_nav_items($html)
{
    $items = [];

    // Scope to first <nav> content
    $nav_inner = $html;
    if (preg_match('/<nav\b[^>]*>([\s\S]*?)<\/nav>/i', $html, $nm)) {
        $nav_inner = $nm[1];
    }

    // Prefer <li> items from the first <ul>
    $use_li = false;
    if (preg_match('/<ul\b[^>]*>([\s\S]*?)<\/ul>/i', $nav_inner, $um)) {
        $ul_inner = $um[1];
        // Extract each <li>...</li>
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $ul_inner, $lis, PREG_SET_ORDER)) {
            $use_li  = true;
            $seen    = [];
            $order   = 1;
            foreach ($lis as $li) {
                $li_html = $li[1];
                // Find the <a> inside this <li>
                if (!preg_match('/<a\b([^>]*)>([\s\S]*?)<\/a>/i', $li_html, $am)) continue;
                $a_attrs = $am[1];
                // Skip CTA links
                if (_cfw_wp_is_cta_link('<a' . $a_attrs . '>')) continue;
                if (!preg_match('/\bhref=["\']([^"\']+)["\']/', $a_attrs, $hm)) continue;
                $url   = trim($hm[1]);
                $label = trim(strip_tags($am[2]));
                if (!$label || !$url) continue;
                $key = strtolower($label) . '|' . $url;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $items[] = ['label' => $label, 'url' => $url, 'order' => $order++];
            }
        }
    }

    if ($use_li) {
        return $items;
    }

    // Fallback: scan all <a> tags in the nav inner content
    if (!preg_match_all('/<a\b([^>]*)\bhref=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $nav_inner, $matches, PREG_SET_ORDER)) {
        return $items;
    }
    $seen  = [];
    $order = 1;
    foreach ($matches as $m) {
        if (_cfw_wp_is_cta_link('<a' . $m[1] . '>')) continue;
        $url   = trim($m[2]);
        $label = trim(strip_tags($m[3]));
        if (!$label || !$url) continue;
        $key = strtolower($label) . '|' . $url;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = ['label' => $label, 'url' => $url, 'order' => $order++];
    }
    return $items;
}

/**
 * Return true if the footer HTML contains a detectable nav group with 2+ items.
 */
function _cfw_wp_detect_footer_nav($footer_html)
{
    return count(_cfw_wp_extract_footer_nav_items($footer_html)) >= 2;
}

/**
 * Inject wp_nav_menu() into footer HTML, replacing only the detected nav group.
 *
 * The source nav group (div/ul/nav) is replaced with a PHP conditional:
 *   - WP branch: wp_nav_menu() with items_wrap preserving the original class.
 *     Excluded links (tel:, mailto:, icon-only) are appended as static <li> items.
 *   - Fallback branch: original source group unchanged.
 * Surrounding footer markup (logo, tagline, copyright) is preserved.
 */
function _cfw_wp_inject_footer_nav($footer_html)
{
    $group = _cfw_wp_find_footer_nav_group($footer_html);
    if ($group === null) return $footer_html;

    $cls   = $group['class'];
    $inner = $group['inner'];
    $full  = $group['full'];

    // Collect excluded links (tel:, mailto:, icon-only) as static <li> items.
    $excluded_lis = '';
    if (preg_match_all('/<a\b([^>]*)\bhref=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $inner, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $url   = trim($m[2]);
            $label = trim(strip_tags($m[3]));
            if (_cfw_wp_is_excluded_footer_link($url, $label)) {
                $excluded_lis .= '<li>' . $m[0] . '</li>';
            }
        }
    }

    // Build items_wrap: <ul class="ORIG_CLASS" style="list-style:none">%3$s[EXCLUDED]</ul>
    $ul_attrs_str = $cls !== '' ? ' class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" style="list-style:none"' : ' style="list-style:none"';
    $items_wrap   = '<ul' . $ul_attrs_str . '>%3$s' . $excluded_lis . '</ul>';
    $items_wrap_escaped = str_replace("'", "\\'", $items_wrap);

    $wp_menu_call = "wp_nav_menu(['theme_location'=>'footer','container'=>false,'items_wrap'=>'" . $items_wrap_escaped . "','fallback_cb'=>false]);";

    $injected = "<?php if (has_nav_menu('footer')) : ?>\n"
        . "<?php " . $wp_menu_call . " ?>\n"
        . "<?php else : ?>\n"
        . $full . "\n"
        . "<?php endif; ?>";

    // Replace the exact group in footer_html using positional splice (not regex).
    $pos = strpos($footer_html, $full);
    if ($pos === false) return $footer_html;
    return substr($footer_html, 0, $pos) . $injected . substr($footer_html, $pos + strlen($full));
}

/**
 * Inject wp_nav_menu() into a header HTML block.
 *
 * Only the primary navigation <ul> is replaced — logo, hamburger, and any
 * structurally distinct CTA links remain untouched in all branches.
 *
 * Strategy:
 *  1. Find the first <ul> inside the first <nav>.
 *  2. Split its <li> items into nav items and CTA items (btn/cta/button class).
 *  3. Build items_wrap = <ul class="ORIG_CLASS">%3$s[CTA_LI_HTML]</ul> so that:
 *     - WordPress injects menu <li>s via %3$s
 *     - The CTA <li> is appended verbatim (keeps its class/style)
 *     - The original <ul> class is preserved → generated CSS continues to apply
 *  4. Replace only the <ul>…</ul> in the source; everything else in <nav> is kept.
 *  5. Fallback branch shows the original complete <ul> unchanged.
 *
 * Falls back to _cfw_wp_inject_nav_menu_no_ul() when no <ul> is found in <nav>.
 */
function _cfw_wp_inject_nav_menu($header_html)
{
    // Locate the first <nav> block (non-nested match)
    if (!preg_match('/(<nav\b[^>]*>)([\s\S]*?)(<\/nav>)/i', $header_html, $nav_m, PREG_OFFSET_CAPTURE)) {
        return $header_html;
    }

    $nav_inner       = $nav_m[2][0];
    $nav_block_start = $nav_m[0][1];
    $nav_block_len   = strlen($nav_m[0][0]);

    // Find first <ul> inside <nav>
    if (!preg_match('/(<ul\b([^>]*)>)([\s\S]*?)(<\/ul>)/i', $nav_inner, $ul_m, PREG_OFFSET_CAPTURE)) {
        // No <ul> — delegate to simpler injector
        return _cfw_wp_inject_nav_menu_no_ul($header_html);
    }

    $ul_open_tag   = $ul_m[1][0];              // e.g. <ul class="nav-links">
    $ul_attrs      = $ul_m[2][0];              // e.g.  class="nav-links"
    $ul_inner      = $ul_m[3][0];              // the <li> content
    $ul_close_tag  = $ul_m[4][0];             // </ul>
    $ul_offset     = $ul_m[0][1];             // offset within $nav_inner
    $ul_full_len   = strlen($ul_m[0][0]);

    // Extract original <ul> class for items_wrap
    $ul_class = '';
    if (preg_match('/\bclass=["\']([^"\']*)["\']/', $ul_attrs, $cm)) {
        $ul_class = $cm[1];
    }

    // Split <li> items: CTA vs nav
    $cta_lis = '';
    if (preg_match_all('/(<li\b[^>]*>[\s\S]*?<\/li>)/i', $ul_inner, $li_matches)) {
        foreach ($li_matches[1] as $li_html) {
            if (preg_match('/<a\b([^>]*)>/i', $li_html, $am) && _cfw_wp_is_cta_link('<a' . $am[1] . '>')) {
                $cta_lis .= $li_html;
            }
        }
    }

    // Build items_wrap: <ul class="ORIG_CLASS">%3$s[CTA_LI]</ul>
    // %3$s is the placeholder WordPress fills with <li> elements.
    $ul_open_built = '<ul' . ($ul_class !== '' ? ' class="' . htmlspecialchars($ul_class, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    $items_wrap    = $ul_open_built . '%3$s' . $cta_lis . '</ul>';
    // Escape for use inside a PHP string literal (single-quoted via array notation)
    $items_wrap_escaped = str_replace("'", "\\'", $items_wrap);

    $wp_menu_call = "wp_nav_menu(['theme_location'=>'primary','container'=>false,'items_wrap'=>'" . $items_wrap_escaped . "','fallback_cb'=>false]);";

    // Replace only the <ul>…</ul> inside nav_inner
    $original_ul = $ul_m[0][0];
    $injected_ul = "<?php if (has_nav_menu('primary')) : ?>\n"
        . "<?php " . $wp_menu_call . " ?>\n"
        . "<?php else : ?>\n"
        . $original_ul . "\n"
        . "<?php endif; ?>";

    $new_nav_inner = substr($nav_inner, 0, $ul_offset)
        . $injected_ul
        . substr($nav_inner, $ul_offset + $ul_full_len);

    // Rebuild the nav block with the same open/close tags
    $new_nav_block = $nav_m[1][0] . $new_nav_inner . $nav_m[3][0];

    return substr($header_html, 0, $nav_block_start)
        . $new_nav_block
        . substr($header_html, $nav_block_start + $nav_block_len);
}

/**
 * Fallback injector for headers that have a <nav> but no <ul> inside it.
 * Replaces the entire <nav> inner content with wp_nav_menu() (original behaviour).
 */
function _cfw_wp_inject_nav_menu_no_ul($header_html)
{
    if (!preg_match('/(<nav\b[^>]*>)([\s\S]*?)(<\/nav>)/i', $header_html, $m, PREG_OFFSET_CAPTURE)) {
        return $header_html;
    }

    $full_match  = $m[0][0];
    $match_start = $m[0][1];
    $nav_open    = $m[1][0];
    $nav_content = $m[2][0];
    $nav_close   = $m[3][0];

    $wp_menu_call = "wp_nav_menu(['theme_location'=>'primary','container'=>false,'fallback_cb'=>false]);";
    $injected = $nav_open . "\n"
        . "<?php if (has_nav_menu('primary')) : ?>\n"
        . "<?php " . $wp_menu_call . " ?>\n"
        . "<?php else : ?>\n"
        . $nav_content . "\n"
        . "<?php endif; ?>\n"
        . $nav_close;

    return substr($header_html, 0, $match_start)
        . $injected
        . substr($header_html, $match_start + strlen($full_match));
}

// ---------------------------------------------------------------------------
// Theme file generation
// ---------------------------------------------------------------------------

/**
 * Write all WordPress classic theme files into $theme_dir.
 */
function _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir, $has_footer_nav = false, $logo_info = null)
{
    // ── style.css ────────────────────────────────────────────────────────────
    $style_css = <<<CSS
/*
Theme Name: {$theme_name}
Author: ClickFuzz
Description: Generated by ClickFuzz Web. Do not place custom CSS here — use assets/css/theme.css.
Version: 1.0.0
Text Domain: {$theme_slug}
*/
CSS;
    if (!_cfw_wp_write_file($theme_dir . '/style.css', $style_css)) {
        return _cfw_wp_err('Could not write style.css.');
    }

    // ── assets/css/theme.css ─────────────────────────────────────────────────
    $theme_css = $parts['css'] ?? '';
    if (!empty($logo_info)) {
        $logo_css  = "\n/* ClickFuzz custom logo */\n"
                   . ".custom-logo-link { display:inline-block; line-height:0; }\n"
                   . ".custom-logo { display:block; max-width:100%; height:auto; }\n";
        $max_h = (int) ($logo_info['height'] ?? 0);
        if ($max_h > 0) {
            $logo_css .= ".custom-logo { max-height:{$max_h}px; width:auto; }\n";
        }
        $theme_css .= $logo_css;
    }
    if ($theme_css !== '') {
        if (!_cfw_wp_write_file($theme_dir . '/assets/css/theme.css', $theme_css)) {
            return _cfw_wp_err('Could not write assets/css/theme.css.');
        }
    }

    // ── functions.php ────────────────────────────────────────────────────────
    $functions = _cfw_wp_render_functions($theme_slug, $parts['font_links'], $theme_css !== '', $has_footer_nav);
    if (!_cfw_wp_write_file($theme_dir . '/functions.php', $functions)) {
        return _cfw_wp_err('Could not write functions.php.');
    }

    // ── header.php ───────────────────────────────────────────────────────────
    $header_html_with_menu = _cfw_wp_inject_nav_menu($parts['header_html']);
    $header_html_with_logo = _cfw_wp_inject_custom_logo($header_html_with_menu, $logo_info);
    $header_php = _cfw_wp_render_header($header_html_with_logo);
    if (!_cfw_wp_write_file($theme_dir . '/header.php', $header_php)) {
        return _cfw_wp_err('Could not write header.php.');
    }

    // ── footer.php ───────────────────────────────────────────────────────────
    $footer_html_injected = $has_footer_nav
        ? _cfw_wp_inject_footer_nav($parts['footer_html'])
        : $parts['footer_html'];
    // Replace footer logo only when it exactly matches the header logo src
    if (!empty($logo_info['src'])) {
        $footer_logo = _cfw_wp_detect_footer_logo($footer_html_injected, $logo_info['src']);
        $footer_html_injected = _cfw_wp_inject_custom_logo($footer_html_injected, $footer_logo);
    }
    $footer_php = _cfw_wp_render_footer($footer_html_injected);
    if (!_cfw_wp_write_file($theme_dir . '/footer.php', $footer_php)) {
        return _cfw_wp_err('Could not write footer.php.');
    }

    // ── front-page.php ───────────────────────────────────────────────────────
    $front = "<?php get_header(); ?>\n\n"
           . $parts['main_html']
           . "\n\n<?php get_footer(); ?>\n";
    if (!_cfw_wp_write_file($theme_dir . '/front-page.php', $front)) {
        return _cfw_wp_err('Could not write front-page.php.');
    }

    // ── index.php (blog list) ────────────────────────────────────────────────
    if (!_cfw_wp_write_file($theme_dir . '/index.php', _cfw_wp_tpl_index())) {
        return _cfw_wp_err('Could not write index.php.');
    }

    // ── single.php ───────────────────────────────────────────────────────────
    if (!_cfw_wp_write_file($theme_dir . '/single.php', _cfw_wp_tpl_single())) {
        return _cfw_wp_err('Could not write single.php.');
    }

    // ── archive.php ──────────────────────────────────────────────────────────
    if (!_cfw_wp_write_file($theme_dir . '/archive.php', _cfw_wp_tpl_archive())) {
        return _cfw_wp_err('Could not write archive.php.');
    }

    // ── page.php ─────────────────────────────────────────────────────────────
    if (!_cfw_wp_write_file($theme_dir . '/page.php', _cfw_wp_tpl_page())) {
        return _cfw_wp_err('Could not write page.php.');
    }

    // ── 404.php ──────────────────────────────────────────────────────────────
    if (!_cfw_wp_write_file($theme_dir . '/404.php', _cfw_wp_tpl_404())) {
        return _cfw_wp_err('Could not write 404.php.');
    }

    // ── screenshot.png ───────────────────────────────────────────────────────
    $screenshot_src = FCPATH . 'modules/pitchsnap/assets/wp-theme-screenshot.png';
    if (file_exists($screenshot_src)) {
        copy($screenshot_src, $theme_dir . '/screenshot.png');
    }

    return ['success' => true, 'error' => null];
}

// ---------------------------------------------------------------------------
// Template renderers
// ---------------------------------------------------------------------------

function _cfw_wp_render_functions($theme_slug, array $font_links, $has_theme_css, $has_footer_nav = false)
{
    $enqueues = [];
    $i        = 1;
    foreach ($font_links as $href) {
        $enqueues[] = '    wp_enqueue_style(\'clickfuzz-fonts-' . $i . '\', \'' . _cfw_wp_esc_js_url($href) . '\', [], null);';
        $i++;
    }
    if ($has_theme_css) {
        $enqueues[] = '    wp_enqueue_style(\'clickfuzz-theme\', get_theme_file_uri(\'/assets/css/theme.css\'), [], \'1.0.0\');';
    }
    $enqueue_block = implode("\n", $enqueues);

    $nav_menus = "'primary' => __('" . addslashes('Primary Menu') . "', '{$theme_slug}')";
    if ($has_footer_nav) {
        $nav_menus .= ",\n        'footer'  => __('" . addslashes('Footer Menu') . "', '{$theme_slug}')";
    }

    return <<<PHP
<?php
defined('ABSPATH') or exit;

add_action('wp_enqueue_scripts', 'clickfuzz_theme_enqueue');
function clickfuzz_theme_enqueue()
{
{$enqueue_block}
}

add_action('after_setup_theme', 'clickfuzz_theme_setup');
function clickfuzz_theme_setup()
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['script', 'style', 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    add_theme_support('custom-logo', ['flex-width' => true, 'flex-height' => true, 'unlink-homepage-logo' => false]);
    register_nav_menus([
        {$nav_menus}
    ]);
}

// Enqueue per-page assets for ClickFuzz-generated Pages.
// CSS/JS are stored in uploads/clickfuzz/pages/{page_id}/ and only loaded
// for the current generated Page (not globally).
add_action('wp_enqueue_scripts', 'cfw_enqueue_generated_page_assets');
function cfw_enqueue_generated_page_assets()
{
    if (!is_singular('page')) {
        return;
    }
    \$page_id = get_the_ID();
    if (!get_post_meta(\$page_id, '_clickfuzz_generated_page', true)) {
        return;
    }
    \$upload   = wp_upload_dir();
    \$base_url = \$upload['baseurl'] . '/clickfuzz/pages/' . \$page_id;
    \$base_dir = \$upload['basedir'] . '/clickfuzz/pages/' . \$page_id;

    if (file_exists(\$base_dir . '/style.css')) {
        wp_enqueue_style(
            'cfw-page-' . \$page_id,
            \$base_url . '/style.css',
            ['clickfuzz-theme'],
            filemtime(\$base_dir . '/style.css')
        );
    }
    if (file_exists(\$base_dir . '/script.js')) {
        wp_enqueue_script(
            'cfw-page-js-' . \$page_id,
            \$base_url . '/script.js',
            [],
            filemtime(\$base_dir . '/script.js'),
            true
        );
    }
}
PHP;
}

function _cfw_wp_render_header($visual_header_html)
{
    return <<<PHP
<?php defined('ABSPATH') or exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

{$visual_header_html}
PHP;
}

function _cfw_wp_render_footer($visual_footer_html)
{
    return <<<PHP
{$visual_footer_html}
<?php wp_footer(); ?>
</body>
</html>
PHP;
}

function _cfw_wp_tpl_index()
{
    return <<<'PHP'
<?php get_header(); ?>
<main style="max-width:900px;margin:4rem auto;padding:0 1.5rem;">
    <?php if (have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
    <article style="margin-bottom:2.5rem;padding-bottom:2.5rem;border-bottom:1px solid #eee;">
        <h2 style="margin-bottom:.5rem;"><a href="<?php the_permalink(); ?>" style="text-decoration:none;color:inherit;"><?php the_title(); ?></a></h2>
        <p style="margin:0 0 .75rem;opacity:.6;font-size:.9em;"><?php echo esc_html(get_the_date()); ?></p>
        <?php the_excerpt(); ?>
        <a href="<?php the_permalink(); ?>">Read more &rarr;</a>
    </article>
    <?php endwhile; ?>
    <div style="margin-top:2rem;"><?php the_posts_pagination(); ?></div>
    <?php else : ?>
    <p>No posts found.</p>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
PHP;
}

function _cfw_wp_tpl_single()
{
    return <<<'PHP'
<?php get_header(); ?>
<main style="max-width:760px;margin:4rem auto;padding:0 1.5rem;">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article>
        <h1 style="margin-bottom:.5rem;"><?php the_title(); ?></h1>
        <p style="margin:0 0 2rem;opacity:.6;font-size:.9em;"><?php echo esc_html(get_the_date()); ?> &middot; <?php the_author(); ?></p>
        <?php the_content(); ?>
    </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
PHP;
}

function _cfw_wp_tpl_archive()
{
    return <<<'PHP'
<?php get_header(); ?>
<main style="max-width:900px;margin:4rem auto;padding:0 1.5rem;">
    <h1 style="margin-bottom:2rem;"><?php the_archive_title(); ?></h1>
    <?php if (have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
    <article style="margin-bottom:2.5rem;padding-bottom:2.5rem;border-bottom:1px solid #eee;">
        <h2 style="margin-bottom:.5rem;"><a href="<?php the_permalink(); ?>" style="text-decoration:none;color:inherit;"><?php the_title(); ?></a></h2>
        <p style="margin:0 0 .75rem;opacity:.6;font-size:.9em;"><?php echo esc_html(get_the_date()); ?></p>
        <?php the_excerpt(); ?>
    </article>
    <?php endwhile; ?>
    <div style="margin-top:2rem;"><?php the_posts_pagination(); ?></div>
    <?php else : ?>
    <p>No posts found.</p>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
PHP;
}

function _cfw_wp_tpl_page()
{
    return <<<'PHP'
<?php
defined('ABSPATH') or exit;
get_header();

if (get_post_meta(get_the_ID(), '_clickfuzz_generated_page', true)) {
    $cfw_id        = get_the_ID();
    // Strip PHP tags: generated content must be HTML/CSS/JS only — never server-side PHP.
    $cfw_safe_css  = str_replace(['<?php', '<?=', '<?', '?>'], '', (string) get_post_meta($cfw_id, '_clickfuzz_generated_css',  true));
    $cfw_safe_html = str_replace(['<?php', '<?=', '<?', '?>'], '', (string) get_post_meta($cfw_id, '_clickfuzz_generated_html', true));
    $cfw_safe_js   = str_replace(['<?php', '<?=', '<?', '?>'], '', (string) get_post_meta($cfw_id, '_clickfuzz_generated_js',   true));
    // Strip site chrome — get_header()/get_footer() already render header and footer
    $cfw_safe_html = preg_replace('/<header\b[^>]*>[\s\S]*?<\/header>\s*/i', '', $cfw_safe_html, 1);
    $cfw_safe_html = preg_replace('/\s*<footer\b[^>]*>[\s\S]*?<\/footer>/i', '', $cfw_safe_html);
    $cfw_safe_html = trim($cfw_safe_html);
    if ($cfw_safe_css) {
        echo '<style id="cfw-page-css-' . (int) $cfw_id . '">' . $cfw_safe_css . '</style>' . "\n";
    }
    echo '<main id="cfw-page-' . (int) $cfw_id . '" class="cfw-generated-page" data-cfw-page="1">' . "\n";
    echo $cfw_safe_html;
    echo '</main>' . "\n";
    if ($cfw_safe_js) {
        echo '<script id="cfw-page-js-' . (int) $cfw_id . '">' . $cfw_safe_js . '</script>' . "\n";
    }
} else {
    ?>
    <main style="max-width:760px;margin:4rem auto;padding:0 1.5rem;">
        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <article>
            <h1 style="margin-bottom:1.5rem;"><?php the_title(); ?></h1>
            <?php the_content(); ?>
        </article>
        <?php endwhile; endif; ?>
    </main>
    <?php
}

get_footer();
PHP;
}

function _cfw_wp_tpl_404()
{
    return <<<'PHP'
<?php get_header(); ?>
<main style="max-width:760px;margin:6rem auto;padding:0 1.5rem;text-align:center;">
    <h1 style="font-size:3rem;margin-bottom:1rem;">404</h1>
    <p style="margin-bottom:2rem;">The page you were looking for could not be found.</p>
    <a href="<?php echo esc_url(home_url('/')); ?>">Return Home</a>
</main>
<?php get_footer(); ?>
PHP;
}

// ---------------------------------------------------------------------------
// WXR generation
// ---------------------------------------------------------------------------

/**
 * Generate a WordPress WXR content export file with Home page and primary navigation menu.
 *
 * @param string $site_slug         URL-safe site slug
 * @param string $theme_name        Human-readable theme name
 * @param string $out_path          Absolute path to write the .xml file
 * @param array  $nav_items         Items from _cfw_wp_extract_nav_items() — primary header nav
 * @param array  $footer_nav_items  Items from footer nav, or []
 */
function _cfw_wp_generate_wxr($site_slug, $theme_name, $out_path, array $nav_items = [], array $footer_nav_items = [])
{
    $now      = date('D, d M Y H:i:s +0000');
    $pub_date = date('Y-m-d H:i:s');

    $cd = function($text) {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $text) . ']]>';
    };

    // Post ID allocation: 2=Home page, 10+ = menu items (primary), 200+ = footer menu items
    $home_id       = 2;
    $menu_item_start = 10;

    // ── Channel header ───────────────────────────────────────────────────────
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<!-- generator="ClickFuzz Web WordPress Exporter/2.0" -->' . "\n";
    $xml .= '<rss version="2.0"' . "\n";
    $xml .= '    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"' . "\n";
    $xml .= '    xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
    $xml .= '    xmlns:wfw="http://wellformedweb.org/CommentAPI/"' . "\n";
    $xml .= '    xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
    $xml .= '    xmlns:wp="http://wordpress.org/export/1.2/">' . "\n";
    $xml .= '  <channel>' . "\n";
    $xml .= '    <title>' . $cd($theme_name) . '</title>' . "\n";
    $xml .= '    <link>http://example.com</link>' . "\n";
    $xml .= '    <description>Exported by ClickFuzz Web</description>' . "\n";
    $xml .= '    <pubDate>' . $now . '</pubDate>' . "\n";
    $xml .= '    <language>en-US</language>' . "\n";
    $xml .= '    <wp:wxr_version>1.2</wp:wxr_version>' . "\n";
    $xml .= '    <wp:base_site_url>http://example.com</wp:base_site_url>' . "\n";
    $xml .= '    <wp:base_blog_url>http://example.com</wp:base_blog_url>' . "\n";
    $xml .= '    <wp:author>' . "\n";
    $xml .= '      <wp:author_id>1</wp:author_id>' . "\n";
    $xml .= '      <wp:author_login>' . $cd('admin') . '</wp:author_login>' . "\n";
    $xml .= '      <wp:author_email>' . $cd('admin@example.com') . '</wp:author_email>' . "\n";
    $xml .= '      <wp:author_display_name>' . $cd('Admin') . '</wp:author_display_name>' . "\n";
    $xml .= '      <wp:author_first_name><![CDATA[]]></wp:author_first_name>' . "\n";
    $xml .= '      <wp:author_last_name><![CDATA[]]></wp:author_last_name>' . "\n";
    $xml .= '    </wp:author>' . "\n";

    // ── nav_menu terms ───────────────────────────────────────────────────────
    if (!empty($nav_items)) {
        $xml .= '    <wp:term>' . "\n";
        $xml .= '      <wp:term_id>1</wp:term_id>' . "\n";
        $xml .= '      <wp:term_taxonomy>nav_menu</wp:term_taxonomy>' . "\n";
        $xml .= '      <wp:term_slug>' . $cd('primary-menu') . '</wp:term_slug>' . "\n";
        $xml .= '      <wp:term_name>' . $cd('Primary Menu') . '</wp:term_name>' . "\n";
        $xml .= '      <wp:term_parent></wp:term_parent>' . "\n";
        $xml .= '      <wp:term_description><![CDATA[]]></wp:term_description>' . "\n";
        $xml .= '    </wp:term>' . "\n";
    }
    if (!empty($footer_nav_items)) {
        $xml .= '    <wp:term>' . "\n";
        $xml .= '      <wp:term_id>2</wp:term_id>' . "\n";
        $xml .= '      <wp:term_taxonomy>nav_menu</wp:term_taxonomy>' . "\n";
        $xml .= '      <wp:term_slug>' . $cd('footer-menu') . '</wp:term_slug>' . "\n";
        $xml .= '      <wp:term_name>' . $cd('Footer Menu') . '</wp:term_name>' . "\n";
        $xml .= '      <wp:term_parent></wp:term_parent>' . "\n";
        $xml .= '      <wp:term_description><![CDATA[]]></wp:term_description>' . "\n";
        $xml .= '    </wp:term>' . "\n";
    }

    // ── Home Page item ───────────────────────────────────────────────────────
    $xml .= _cfw_wp_wxr_page_item($home_id, 'Home', 'home', $now, $pub_date, $cd);

    // ── Primary menu items ───────────────────────────────────────────────────
    $item_id = $menu_item_start;
    foreach ($nav_items as $item) {
        $item['url'] = _cfw_wp_normalize_menu_url($item['url']);
        $xml .= _cfw_wp_wxr_menu_item($item_id, $item, 'primary-menu', 'Primary Menu', $now, $pub_date, $cd);
        $item_id++;
    }

    // ── Footer menu items ────────────────────────────────────────────────────
    $item_id = 200;
    foreach ($footer_nav_items as $item) {
        $item['url'] = _cfw_wp_normalize_menu_url($item['url']);
        $xml .= _cfw_wp_wxr_menu_item($item_id, $item, 'footer-menu', 'Footer Menu', $now, $pub_date, $cd);
        $item_id++;
    }

    $xml .= '  </channel>' . "\n";
    $xml .= '</rss>' . "\n";

    // Validate XML before writing
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        $errs = array_map(fn($e) => $e->message, libxml_get_errors());
        libxml_clear_errors();
        return _cfw_wp_err('WXR XML is malformed: ' . implode('; ', $errs));
    }
    libxml_clear_errors();

    if (!_cfw_wp_write_file($out_path, $xml)) {
        return _cfw_wp_err('Could not write WXR file.');
    }

    return ['success' => true, 'error' => null];
}

/**
 * Build a WXR <item> block for a WordPress Page.
 */
function _cfw_wp_wxr_page_item($post_id, $title, $slug, $now, $pub_date, callable $cd)
{
    $s  = '    <item>' . "\n";
    $s .= '      <title>' . $cd($title) . '</title>' . "\n";
    $s .= '      <link>http://example.com/' . htmlspecialchars($slug, ENT_XML1, 'UTF-8') . '/</link>' . "\n";
    $s .= '      <pubDate>' . $now . '</pubDate>' . "\n";
    $s .= '      <dc:creator>' . $cd('admin') . '</dc:creator>' . "\n";
    $s .= '      <guid isPermaLink="false">http://example.com/?page_id=' . (int) $post_id . '</guid>' . "\n";
    $s .= '      <description></description>' . "\n";
    $s .= '      <content:encoded>' . $cd('<!-- Homepage content is managed by the theme front-page.php -->') . '</content:encoded>' . "\n";
    $s .= '      <excerpt:encoded><![CDATA[]]></excerpt:encoded>' . "\n";
    $s .= '      <wp:post_id>' . (int) $post_id . '</wp:post_id>' . "\n";
    $s .= '      <wp:post_date>' . $cd($pub_date) . '</wp:post_date>' . "\n";
    $s .= '      <wp:post_date_gmt>' . $cd($pub_date) . '</wp:post_date_gmt>' . "\n";
    $s .= '      <wp:post_modified>' . $cd($pub_date) . '</wp:post_modified>' . "\n";
    $s .= '      <wp:post_modified_gmt>' . $cd($pub_date) . '</wp:post_modified_gmt>' . "\n";
    $s .= '      <wp:comment_status>' . $cd('closed') . '</wp:comment_status>' . "\n";
    $s .= '      <wp:ping_status>' . $cd('closed') . '</wp:ping_status>' . "\n";
    $s .= '      <wp:post_name>' . $cd($slug) . '</wp:post_name>' . "\n";
    $s .= '      <wp:status>' . $cd('publish') . '</wp:status>' . "\n";
    $s .= '      <wp:post_parent>0</wp:post_parent>' . "\n";
    $s .= '      <wp:menu_order>0</wp:menu_order>' . "\n";
    $s .= '      <wp:post_type>' . $cd('page') . '</wp:post_type>' . "\n";
    $s .= '      <wp:post_password><![CDATA[]]></wp:post_password>' . "\n";
    $s .= '      <wp:is_sticky>0</wp:is_sticky>' . "\n";
    $s .= '    </item>' . "\n";
    return $s;
}

/**
 * Build a WXR <item> block for a nav_menu_item (custom link type).
 * All links are stored as custom links — anchor (#section), tel:, mailto:,
 * external, and internal — preserving the original structure without inventing Pages.
 */
function _cfw_wp_wxr_menu_item($post_id, array $item, $menu_nicename, $menu_name, $now, $pub_date, callable $cd)
{
    $label = $item['label'];
    $url   = $item['url'];
    $order = (int) ($item['order'] ?? 1);

    $s  = '    <item>' . "\n";
    $s .= '      <title>' . $cd($label) . '</title>' . "\n";
    $s .= '      <link>http://example.com/?post_type=nav_menu_item&amp;p=' . (int) $post_id . '</link>' . "\n";
    $s .= '      <pubDate>' . $now . '</pubDate>' . "\n";
    $s .= '      <dc:creator>' . $cd('admin') . '</dc:creator>' . "\n";
    $s .= '      <guid isPermaLink="false">http://example.com/?post_type=nav_menu_item&amp;p=' . (int) $post_id . '</guid>' . "\n";
    $s .= '      <description></description>' . "\n";
    $s .= '      <content:encoded><![CDATA[]]></content:encoded>' . "\n";
    $s .= '      <excerpt:encoded><![CDATA[]]></excerpt:encoded>' . "\n";
    $s .= '      <wp:post_id>' . (int) $post_id . '</wp:post_id>' . "\n";
    $s .= '      <wp:post_date>' . $cd($pub_date) . '</wp:post_date>' . "\n";
    $s .= '      <wp:post_date_gmt>' . $cd($pub_date) . '</wp:post_date_gmt>' . "\n";
    $s .= '      <wp:post_modified>' . $cd($pub_date) . '</wp:post_modified>' . "\n";
    $s .= '      <wp:post_modified_gmt>' . $cd($pub_date) . '</wp:post_modified_gmt>' . "\n";
    $s .= '      <wp:comment_status>' . $cd('closed') . '</wp:comment_status>' . "\n";
    $s .= '      <wp:ping_status>' . $cd('closed') . '</wp:ping_status>' . "\n";
    $s .= '      <wp:post_name>' . $cd('menu-item-' . (int) $post_id) . '</wp:post_name>' . "\n";
    $s .= '      <wp:status>' . $cd('publish') . '</wp:status>' . "\n";
    $s .= '      <wp:post_parent>0</wp:post_parent>' . "\n";
    $s .= '      <wp:menu_order>' . $order . '</wp:menu_order>' . "\n";
    $s .= '      <wp:post_type>' . $cd('nav_menu_item') . '</wp:post_type>' . "\n";
    $s .= '      <wp:post_password><![CDATA[]]></wp:post_password>' . "\n";
    $s .= '      <wp:is_sticky>0</wp:is_sticky>' . "\n";
    $s .= '      <category domain="nav_menu" nicename="' . htmlspecialchars($menu_nicename, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">' . $cd($menu_name) . '</category>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_type') . '</wp:meta_key><wp:meta_value>' . $cd('custom') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_menu_item_parent') . '</wp:meta_key><wp:meta_value>' . $cd('0') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_object_id') . '</wp:meta_key><wp:meta_value>' . $cd('0') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_object') . '</wp:meta_key><wp:meta_value>' . $cd('custom') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_target') . '</wp:meta_key><wp:meta_value>' . $cd('') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_classes') . '</wp:meta_key><wp:meta_value>' . $cd('a:1:{i:0;s:0:"";}') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_xfn') . '</wp:meta_key><wp:meta_value>' . $cd('') . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '      <wp:postmeta><wp:meta_key>' . $cd('_menu_item_url') . '</wp:meta_key><wp:meta_value>' . $cd($url) . '</wp:meta_value></wp:postmeta>' . "\n";
    $s .= '    </item>' . "\n";
    return $s;
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * Validate the generated theme directory.
 * Returns ['success'=>bool, 'error'=>string|null, 'warnings'=>string[]].
 */
function _cfw_wp_validate($theme_dir, $theme_slug)
{
    $warnings = [];

    // Required files
    $required = ['style.css', 'index.php', 'functions.php', 'header.php', 'footer.php', 'front-page.php', 'page.php'];
    foreach ($required as $f) {
        if (!file_exists($theme_dir . '/' . $f)) {
            return ['success' => false, 'error' => 'Required file missing: ' . $f, 'warnings' => $warnings];
        }
    }

    // WordPress theme header in style.css
    $style = file_get_contents($theme_dir . '/style.css');
    if (stripos($style, 'Theme Name:') === false) {
        return ['success' => false, 'error' => 'style.css is missing WordPress Theme Name header.', 'warnings' => $warnings];
    }

    // wp_head / wp_footer / body_class in header/footer
    $header_php  = file_get_contents($theme_dir . '/header.php');
    $footer_php  = file_get_contents($theme_dir . '/footer.php');
    $functions_php = file_get_contents($theme_dir . '/functions.php');

    foreach (['wp_head()' => $header_php, 'wp_footer()' => $footer_php, 'body_class()' => $header_php] as $fn => $src) {
        if (stripos($src, $fn) === false) {
            return ['success' => false, 'error' => $fn . ' not found in generated template.', 'warnings' => $warnings];
        }
    }

    if (stripos($functions_php, 'register_nav_menus') === false) {
        return ['success' => false, 'error' => 'register_nav_menus() not found in functions.php.', 'warnings' => $warnings];
    }

    // Validate page.php implements the ClickFuzz conditional generated-page logic
    $page_php = file_get_contents($theme_dir . '/page.php');
    if (stripos($page_php, '_clickfuzz_generated_page') === false) {
        return ['success' => false, 'error' => 'page.php is missing _clickfuzz_generated_page conditional.', 'warnings' => $warnings];
    }
    if (stripos($page_php, '_clickfuzz_generated_html') === false) {
        return ['success' => false, 'error' => 'page.php is missing _clickfuzz_generated_html meta read.', 'warnings' => $warnings];
    }

    // No hard-coded absolute server paths or preview tokens in theme PHP files
    $php_files = glob($theme_dir . '/*.php') ?: [];
    foreach ($php_files as $fp) {
        $src = file_get_contents($fp);
        if (preg_match('#/home/[a-z]|/var/www|FCPATH|previews/[a-f0-9]{64}#', $src)) {
            $warnings[] = basename($fp) . ' may contain a server path or preview token.';
        }
    }

    // PHP lint all generated PHP files (if php is available)
    $php_bin = trim((string) shell_exec('which php 2>/dev/null'));
    if ($php_bin && is_executable($php_bin)) {
        foreach ($php_files as $fp) {
            $out = [];
            $rc  = 0;
            exec(escapeshellcmd($php_bin) . ' -l ' . escapeshellarg($fp) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                return [
                    'success'  => false,
                    'error'    => 'PHP syntax error in ' . basename($fp) . ': ' . implode(' ', $out),
                    'warnings' => $warnings,
                ];
            }
        }
    } else {
        $warnings[] = 'PHP CLI not found; skipped PHP syntax lint.';
    }

    // front-page.php exists (mandatory for generated sites)
    if (!file_exists($theme_dir . '/front-page.php')) {
        return ['success' => false, 'error' => 'front-page.php is missing.', 'warnings' => $warnings];
    }

    return ['success' => true, 'error' => null, 'warnings' => $warnings];
}

// ---------------------------------------------------------------------------
// ZIP operations
// ---------------------------------------------------------------------------

/**
 * ZIP the contents of $source_dir into $zip_path, prefixing all entries with $zip_prefix/.
 * Prevents path traversal (Zip Slip).
 */
function _cfw_wp_zip_dir($source_dir, $zip_prefix, $zip_path)
{
    if (!class_exists('ZipArchive')) {
        return _cfw_wp_err('ZipArchive PHP extension is not available.');
    }

    $source_real = realpath($source_dir);
    if (!$source_real || !is_dir($source_real)) {
        return _cfw_wp_err('ZIP source directory not found: ' . $source_dir);
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return _cfw_wp_err('Could not create ZIP file at: ' . $zip_path);
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_real, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if (!$file->isFile()) continue;

        $real_path = $file->getRealPath();

        // Zip Slip guard
        if (strpos($real_path . '/', $source_real . '/') !== 0) {
            $zip->close();
            return _cfw_wp_err('Path traversal detected during ZIP creation.');
        }

        $relative  = substr($real_path, strlen($source_real) + 1);
        $zip_entry = $zip_prefix . '/' . str_replace('\\', '/', $relative);
        $zip->addFile($real_path, $zip_entry);
    }

    $zip->close();

    if (!file_exists($zip_path)) {
        return _cfw_wp_err('ZIP file was not written to disk.');
    }

    // Verify: open and check top-level directory
    $check = new ZipArchive();
    if ($check->open($zip_path) !== true) {
        return _cfw_wp_err('Verification failed: ZIP cannot be reopened.');
    }
    $first_entry = $check->getNameIndex(0);
    $check->close();

    if (strpos((string) $first_entry, $zip_prefix . '/') !== 0) {
        return _cfw_wp_err('ZIP top-level directory mismatch. Expected: ' . $zip_prefix . '/');
    }

    return ['success' => true, 'error' => null];
}

// ---------------------------------------------------------------------------
// String / HTML utility functions (pure — no CI dependency)
// ---------------------------------------------------------------------------

/**
 * Convert a business name to a WordPress-safe slug.
 */
function _cfw_wp_make_slug($name)
{
    $slug = strtolower((string) $name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('/-{2,}/', '-', $slug);
    if (strlen($slug) > 60) {
        $slug = substr($slug, 0, 60);
        $slug = rtrim($slug, '-');
    }
    return $slug ?: 'site';
}

/**
 * Return true if $url is an external (absolute) URL.
 */
function _cfw_wp_is_external_url($url)
{
    return (bool) preg_match('#^https?://#i', $url);
}

/**
 * Convert href="/" to WordPress home_url() call.
 * Leaves tel:, mailto:, #fragment, and external URLs unchanged.
 */
function _cfw_wp_convert_internal_links($html)
{
    return preg_replace_callback(
        '/\bhref=(["\'])([^"\']+)\1/i',
        function ($m) {
            $quote = $m[1];
            $href  = $m[2];

            // Leave external URLs, tel:, mailto:, #fragments, and data: alone
            if (preg_match('~^(https?://|tel:|mailto:|#|data:)~i', $href)) {
                return $m[0];
            }

            // Homepage root
            if ($href === '/') {
                return 'href=' . $quote . '<?php echo esc_url(home_url(\'/\')); ?>' . $quote;
            }

            // Other absolute-path internal links — leave as relative for V1
            // (no imported pages to resolve against)
            return $m[0];
        },
        $html
    );
}

/**
 * Replace <span data-pitchsnap-current-year></span> with PHP date('Y').
 */
function _cfw_wp_convert_dynamic_year($html)
{
    // Two-tag form: <span data-pitchsnap-current-year></span>
    $html = preg_replace(
        '/<span\s+data-pitchsnap-current-year[^>]*>\s*<\/span>/i',
        '<?php echo esc_html(date(\'Y\')); ?>',
        $html
    );
    // Self-closing / void form (non-standard but defensive)
    $html = preg_replace(
        '/<span\s+data-pitchsnap-current-year\s*\/>/i',
        '<?php echo esc_html(date(\'Y\')); ?>',
        $html
    );
    return $html;
}

/**
 * Safely escape a URL for use as a PHP string literal in enqueue calls.
 * Not the WP esc_js — just escapes single quotes.
 */
function _cfw_wp_esc_js_url($url)
{
    return str_replace("'", "\\'", (string) $url);
}

// ---------------------------------------------------------------------------
// File I/O helpers
// ---------------------------------------------------------------------------

/**
 * Write $content to $path, creating parent directories as needed.
 * Returns true on success.
 */
function _cfw_wp_write_file($path, $content)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    return file_put_contents($path, $content) !== false;
}

/**
 * Recursively remove a directory and its contents.
 * Refuses to operate outside a safe base path.
 */
function _cfw_wp_rm_dir($dir)
{
    $dir = rtrim((string) $dir, '/\\');
    if (!is_dir($dir)) return;

    // Safety: must be inside server's export area or /tmp
    $real = realpath($dir);
    if (!$real) return;
    if (!preg_match('#/(exports|tmp|var/tmp)/#', $real . '/')) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

// ---------------------------------------------------------------------------
// README generation
// ---------------------------------------------------------------------------

function _cfw_wp_readme($theme_slug, $business_name = '')
{
    $label = $business_name ? $business_name . ' — ' : '';
    return <<<TXT
{$label}ClickFuzz WordPress Package
================================================

INSTALLATION INSTRUCTIONS

STEP 1 — Install the theme
  a. Go to Appearance → Themes → Add New → Upload Theme.
  b. Upload the theme ZIP from /theme/{$theme_slug}.zip.
  c. Activate the theme.

STEP 2 — Import content
  a. Go to Tools → Import → WordPress.
  b. Install/launch the WordPress Importer plugin if prompted.
  c. Import /content/clickfuzz-content.xml.
  d. Select "Download and import file attachments" when prompted.
  e. Assign imported content to an existing admin user.

STEP 3 — Set the homepage
  a. Go to Settings → Reading.
  b. Set "Your homepage displays" to "A static page".
  c. Select "Home" as the Homepage.
  d. Save.

STEP 4 — Assign navigation menus
  a. Go to Appearance → Menus.
  b. Find the imported "Primary Menu".
  c. Under "Menu Settings", check "Primary Menu" display location.
  d. Save the menu.
  e. If a Footer Menu was imported, assign it to the "Footer Menu" location.

STEP 5 — Verify
  a. Visit the site homepage — it should match the ClickFuzz preview exactly.
  b. Click navigation links to confirm they work.
  c. Check the footer.

ADDING AI-GENERATED PAGES (future workflow)
  a. Create a new WordPress Page (use default template — no special template needed).
  b. Add post meta _clickfuzz_generated_page = 1 to mark it as ClickFuzz-generated.
  c. Add post meta _clickfuzz_generated_html = <your HTML body (sections only)>.
     Do NOT include <html>, <head>, <body>, <header>, or <footer> — those come from the theme.
  d. Optionally add _clickfuzz_generated_css for page-specific inline styles.
  e. Optionally add _clickfuzz_generated_js for page-specific inline JS.
  f. File assets can go in uploads/clickfuzz/pages/{page_id}/style.css or script.js.
  g. Publish. The page inherits the shared header, navigation, and footer automatically.

NOTES
  - Homepage HTML is managed by the theme (front-page.php). Edit the theme to change it.
  - Normal WordPress Pages (no _clickfuzz_generated_page meta) use the Gutenberg editor.
  - ClickFuzz Generated Pages store HTML in post meta — Gutenberg is bypassed for them.
  - Elementor works normally on any page not marked with _clickfuzz_generated_page.
  - ClickFuzz runtime (lead capture / chat) must be re-integrated via a separate plugin.

See manifest.json for exported menus, assets, and runtime dependency warnings.

Generated by ClickFuzz Web.
TXT;
}

// ---------------------------------------------------------------------------
// Internal shorthand
// ---------------------------------------------------------------------------

function _cfw_wp_err($msg)
{
    return ['success' => false, 'error' => $msg, 'warnings' => [], 'zip_path' => null, 'download_url' => null];
}

// ---------------------------------------------------------------------------
// WordPress Connector — server-side HTTP client
// ---------------------------------------------------------------------------

/**
 * Make an authenticated request to a ClickFuzz Connector REST endpoint.
 *
 * @param  int    $website_id  pitchsnap_redesigns.id — credentials source
 * @param  string $method      'GET' or 'POST'
 * @param  string $endpoint    e.g. '/clickfuzz/v1/status'
 * @param  array  $body        POST body fields (JSON when no $files; form fields otherwise)
 * @param  array  $files       ['field_name' => '/absolute/path/to/file'] for multipart upload
 * @return array ['success'=>bool, 'code'=>int, 'body'=>array|null, 'error'=>string|null]
 */
function clickfuzz_web_wordpress_connector_request($website_id, $method, $endpoint, $body = [], $files = [])
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $site = $CI->pitchsnap_model->get_site_by_website_id((int) $website_id);
    if (!$site || empty($site->wp_site_url) || empty($site->wp_api_key)) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'WordPress connection not configured.'];
    }

    $api_key = $CI->encryption->decrypt($site->wp_api_key);
    if (empty($api_key)) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'Could not decrypt API key.'];
    }

    $url = rtrim($site->wp_site_url, '/') . '/wp-json' . $endpoint;
    $ch  = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-CF-Key: ' . $api_key],
    ]);

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif (!empty($files)) {
        // Multipart form data
        $post_fields = [];
        foreach ($body as $k => $v) {
            $post_fields[$k] = (string) $v;
        }
        foreach ($files as $field => $path) {
            if (!is_file($path)) {
                curl_close($ch);
                return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'File not found: ' . basename($path)];
            }
            $post_fields[$field] = new CURLFile($path);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    } else {
        // JSON body
        $json = json_encode($body);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Content-Length: ' . strlen($json), 'X-CF-Key: ' . $api_key]);
    }

    $response   = curl_exec($ch);
    $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'Connection error: ' . $curl_error];
    }

    $decoded = json_decode($response, true);

    // WP REST API error envelope: has 'code' key but not 'success'
    if (is_array($decoded) && isset($decoded['code']) && !array_key_exists('success', $decoded)) {
        return [
            'success' => false,
            'code'    => $http_code,
            'body'    => $decoded,
            'error'   => $decoded['message'] ?? ('HTTP ' . $http_code),
        ];
    }

    if ($http_code < 200 || $http_code >= 300) {
        if (is_array($decoded) && isset($decoded['message'])) {
            $msg = $decoded['message'];
        } elseif (is_string($response) && strlen($response) > 0) {
            // Surface raw body (HTML from WAF/security plugin) truncated to 200 chars
            $msg = ': ' . substr(strip_tags($response), 0, 200);
        } else {
            $msg = '';
        }
        return [
            'success' => false,
            'code'    => $http_code,
            'body'    => $decoded,
            'error'   => 'HTTP ' . $http_code . ($msg ? (is_array($decoded) ? ': ' . $msg : $msg) : ''),
        ];
    }

    return ['success' => true, 'code' => $http_code, 'body' => $decoded, 'error' => null];
}

// ---------------------------------------------------------------------------
// WordPress Connector — deployment orchestration
// ---------------------------------------------------------------------------

/**
 * Full deployment: export → upload theme → activate theme → import WXR content.
 * Each step is logged. Fails fast at the first error.
 *
 * @return array ['success'=>bool, 'steps'=>array, 'error'=>string|null]
 */
function clickfuzz_web_deploy_to_wordpress($website_id)
{
    $website_id = (int) $website_id;
    $steps      = [];

    // Step 1: Verify connection
    $r = clickfuzz_web_wordpress_connector_request($website_id, 'GET', '/clickfuzz/v1/status');
    if (!$r['success']) {
        $steps[] = ['label' => 'Verify connection', 'ok' => false, 'message' => $r['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Connection failed: ' . $r['error']];
    }
    $steps[] = ['label' => 'Verify connection', 'ok' => true, 'message' => 'Connected.'];

    // Step 2: Generate WordPress package
    $export = clickfuzz_web_export_wordpress_site($website_id);
    if (!$export['success']) {
        $steps[] = ['label' => 'Generate WordPress package', 'ok' => false, 'message' => $export['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Export failed: ' . $export['error']];
    }
    $steps[] = ['label' => 'Generate WordPress package', 'ok' => true, 'message' => 'Package built.'];

    // Step 3: Extract theme ZIP and WXR from the outer package ZIP
    $extracted = _cfw_wp_extract_package_files($export['zip_path']);
    if (!$extracted['success']) {
        $steps[] = ['label' => 'Extract package files', 'ok' => false, 'message' => $extracted['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Extract failed: ' . $extracted['error']];
    }
    $steps[] = ['label' => 'Extract package files', 'ok' => true, 'message' => 'Files ready.'];

    try {
        // Step 4: Upload theme — WP pulls ZIP from a signed ClickFuzz URL (avoids rate-limited file POSTs)
        $theme_url = _cfw_wp_make_theme_download_url($extracted['theme_zip']);
        $r = _cfw_wp_request_with_retry(
            $website_id, 'POST', '/clickfuzz/v1/theme',
            ['theme_url' => $theme_url]
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Upload theme', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Theme upload failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Upload theme', 'ok' => true, 'message' => ($r['body']['action'] ?? 'installed') . '.'];

        // Step 5: Activate theme
        $r = _cfw_wp_request_with_retry(
            $website_id, 'POST', '/clickfuzz/v1/theme/activate',
            ['slug' => $export['theme_slug']]
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Activate theme', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Theme activation failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Activate theme', 'ok' => true, 'message' => 'Active.'];

        // Step 6: Import WXR content (and logo if present)
        $import_params = [
            'replace_existing' => 'true',
            'site_slug'        => $export['site_slug'],
            'business_name'    => $export['business_name'],
        ];
        $import_files = ['xml' => $extracted['wxr_file']];
        if (!empty($extracted['logo_file'])) {
            $import_files['logo'] = $extracted['logo_file'];
        }
        $r = clickfuzz_web_wordpress_connector_request(
            $website_id, 'POST', '/clickfuzz/v1/import',
            $import_params,
            $import_files
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Import content', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Content import failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Import content', 'ok' => true, 'message' => 'Imported.'];

        // Step 7: Refresh status and save
        _cfw_wp_save_connection_status($website_id);

        // Mark site and redesign as published
        $CI =& get_instance();
        $site = $CI->pitchsnap_model->get_site_by_website_id((int) $website_id);
        if ($site) {
            $CI->pitchsnap_model->update_site($site->id, [
                'status'       => 'published',
                'publish_type' => 'wordpress',
                'dateupdated'  => date('Y-m-d H:i:s'),
            ]);
            $CI->pitchsnap_model->update((int) $website_id, ['status' => 'published']);

            // Ensure a homepage page row exists for the pages tab
            if (!function_exists('clickfuzz_web_ensure_homepage_page')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
            }
            clickfuzz_web_ensure_homepage_page($CI, $site->id);
        }

        return ['success' => true, 'steps' => $steps, 'error' => null];
    } finally {
        _cfw_wp_rm_dir($extracted['tmp_dir']);
    }
}

/**
 * Redeploy theme only (no WXR import).
 *
 * @return array ['success'=>bool, 'steps'=>array, 'error'=>string|null]
 */
function clickfuzz_web_redeploy_wp_theme($website_id)
{
    $website_id = (int) $website_id;
    $steps      = [];

    $r = clickfuzz_web_wordpress_connector_request($website_id, 'GET', '/clickfuzz/v1/status');
    if (!$r['success']) {
        $steps[] = ['label' => 'Verify connection', 'ok' => false, 'message' => $r['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Connection failed: ' . $r['error']];
    }
    $steps[] = ['label' => 'Verify connection', 'ok' => true, 'message' => 'Connected.'];

    $export = clickfuzz_web_export_wordpress_site($website_id);
    if (!$export['success']) {
        $steps[] = ['label' => 'Generate WordPress package', 'ok' => false, 'message' => $export['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Export failed: ' . $export['error']];
    }
    $steps[] = ['label' => 'Generate WordPress package', 'ok' => true, 'message' => 'Package built.'];

    $extracted = _cfw_wp_extract_package_files($export['zip_path']);
    if (!$extracted['success']) {
        $steps[] = ['label' => 'Extract package files', 'ok' => false, 'message' => $extracted['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Extract failed: ' . $extracted['error']];
    }
    $steps[] = ['label' => 'Extract package files', 'ok' => true, 'message' => 'Files ready.'];

    try {
        $theme_url = _cfw_wp_make_theme_download_url($extracted['theme_zip']);
        $r = _cfw_wp_request_with_retry(
            $website_id, 'POST', '/clickfuzz/v1/theme',
            ['theme_url' => $theme_url]
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Upload theme', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Theme upload failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Upload theme', 'ok' => true, 'message' => ($r['body']['action'] ?? 'installed') . '.'];

        $r = _cfw_wp_request_with_retry(
            $website_id, 'POST', '/clickfuzz/v1/theme/activate',
            ['slug' => $export['theme_slug']]
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Activate theme', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Theme activation failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Activate theme', 'ok' => true, 'message' => 'Active.'];

        _cfw_wp_save_connection_status($website_id);

        return ['success' => true, 'steps' => $steps, 'error' => null];
    } finally {
        _cfw_wp_rm_dir($extracted['tmp_dir']);
    }
}

/**
 * Reimport WXR content only (theme unchanged).
 *
 * @return array ['success'=>bool, 'steps'=>array, 'error'=>string|null]
 */
function clickfuzz_web_reimport_wp_content($website_id)
{
    $website_id = (int) $website_id;
    $steps      = [];

    $r = clickfuzz_web_wordpress_connector_request($website_id, 'GET', '/clickfuzz/v1/status');
    if (!$r['success']) {
        $steps[] = ['label' => 'Verify connection', 'ok' => false, 'message' => $r['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Connection failed: ' . $r['error']];
    }
    $steps[] = ['label' => 'Verify connection', 'ok' => true, 'message' => 'Connected.'];

    $export = clickfuzz_web_export_wordpress_site($website_id);
    if (!$export['success']) {
        $steps[] = ['label' => 'Generate WordPress package', 'ok' => false, 'message' => $export['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Export failed: ' . $export['error']];
    }
    $steps[] = ['label' => 'Generate WordPress package', 'ok' => true, 'message' => 'Package built.'];

    $extracted = _cfw_wp_extract_package_files($export['zip_path']);
    if (!$extracted['success']) {
        $steps[] = ['label' => 'Extract package files', 'ok' => false, 'message' => $extracted['error']];
        return ['success' => false, 'steps' => $steps, 'error' => 'Extract failed: ' . $extracted['error']];
    }
    $steps[] = ['label' => 'Extract package files', 'ok' => true, 'message' => 'Files ready.'];

    try {
        $reimport_params = [
            'replace_existing' => 'true',
            'site_slug'        => $export['site_slug'],
            'business_name'    => $export['business_name'],
        ];
        $reimport_files = ['xml' => $extracted['wxr_file']];
        if (!empty($extracted['logo_file'])) {
            $reimport_files['logo'] = $extracted['logo_file'];
        }
        $r = clickfuzz_web_wordpress_connector_request(
            $website_id, 'POST', '/clickfuzz/v1/import',
            $reimport_params,
            $reimport_files
        );
        if (!$r['success']) {
            $steps[] = ['label' => 'Import content', 'ok' => false, 'message' => $r['error']];
            return ['success' => false, 'steps' => $steps, 'error' => 'Content import failed: ' . $r['error']];
        }
        $steps[] = ['label' => 'Import content', 'ok' => true, 'message' => 'Imported.'];

        return ['success' => true, 'steps' => $steps, 'error' => null];
    } finally {
        _cfw_wp_rm_dir($extracted['tmp_dir']);
    }
}

/**
 * Generate a one-time signed token that lets WordPress pull a theme ZIP from ClickFuzz.
 * Writes a .meta file to /tmp; the Pitchsnap_runtime::wp_theme_download() endpoint reads it.
 *
 * @return string  Full public URL for the WordPress plugin to GET the ZIP from.
 */
function _cfw_wp_make_theme_download_url($theme_zip_path)
{
    $token     = bin2hex(random_bytes(16)); // 32 hex chars
    $meta_file = sys_get_temp_dir() . '/cf_td_' . $token . '.meta';
    file_put_contents($meta_file, json_encode([
        'zip'    => $theme_zip_path,
        'expiry' => time() + 900, // 15 minutes
    ]));
    return base_url('pitchsnap/wp_theme_download/' . $token);
}

/**
 * Wrapper around clickfuzz_web_wordpress_connector_request that retries on HTTP 429.
 * Sleeps $delays[i] seconds before each attempt (including the first).
 */
function _cfw_wp_request_with_retry($website_id, $method, $endpoint, $body = [], $files = [])
{
    $r = clickfuzz_web_wordpress_connector_request($website_id, $method, $endpoint, $body, $files);
    if ($r['success'] || $r['code'] !== 429) {
        return $r;
    }
    // Single retry after a short pause
    sleep(5);
    return clickfuzz_web_wordpress_connector_request($website_id, $method, $endpoint, $body, $files);
}

/**
 * Pull /status from the Connector and save connector_version, wp_version, active_theme_slug.
 */
function _cfw_wp_save_connection_status($website_id)
{
    $r = clickfuzz_web_wordpress_connector_request($website_id, 'GET', '/clickfuzz/v1/status');
    if (!$r['success']) {
        return;
    }
    $body = (array) $r['body'];
    $CI   =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        $CI->pitchsnap_model = new Pitchsnap_model();
    }
    $CI->pitchsnap_model->save_wp_status((int) $website_id, [
        'wp_connected_at'      => date('Y-m-d H:i:s'),
        'wp_connector_version' => $body['version']             ?? null,
        'wp_wp_version'        => $body['wp']                  ?? null,
        'wp_active_theme_slug' => $body['active_theme_slug']   ?? null,
    ]);
}

/**
 * Extract the theme ZIP and WXR file from an outer ClickFuzz WordPress package ZIP.
 * Writes them to a /tmp/ directory. Caller must clean up ['tmp_dir'] when done.
 *
 * @return array ['success'=>bool, 'tmp_dir'=>string, 'theme_zip'=>string, 'wxr_file'=>string, 'error'=>string|null]
 */
function _cfw_wp_extract_package_files($zip_path)
{
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'ZipArchive extension not available.', 'tmp_dir' => '', 'theme_zip' => '', 'wxr_file' => ''];
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return ['success' => false, 'error' => 'Could not open package ZIP.', 'tmp_dir' => '', 'theme_zip' => '', 'wxr_file' => ''];
    }

    $theme_entry = null;
    $wxr_entry   = null;
    $logo_entry  = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($theme_entry === null && preg_match('#^[^/]+/theme/[^/]+\.zip$#', $name)) {
            $theme_entry = $name;
        } elseif ($wxr_entry === null && preg_match('#^[^/]+/content/[^/]+\.xml$#', $name)) {
            $wxr_entry = $name;
        } elseif ($logo_entry === null && preg_match('#^[^/]+/media/[^/]+\.(png|jpg|jpeg|webp|gif)$#i', $name)) {
            $logo_entry = $name;
        }
        if ($theme_entry && $wxr_entry && $logo_entry) {
            break;
        }
    }

    if (!$theme_entry || !$wxr_entry) {
        $zip->close();
        return ['success' => false, 'error' => 'Package ZIP is missing theme or WXR file.', 'tmp_dir' => '', 'theme_zip' => '', 'wxr_file' => '', 'logo_file' => null];
    }

    $tmp_dir = '/tmp/cf_deploy_' . bin2hex(random_bytes(8)) . '/';
    if (!mkdir($tmp_dir, 0750, true)) {
        $zip->close();
        return ['success' => false, 'error' => 'Could not create temp directory.', 'tmp_dir' => '', 'theme_zip' => '', 'wxr_file' => '', 'logo_file' => null];
    }

    $theme_zip = $tmp_dir . basename($theme_entry);
    $wxr_file  = $tmp_dir . 'clickfuzz-content.xml';
    $logo_file = null;

    $ok = (file_put_contents($theme_zip, $zip->getFromName($theme_entry)) !== false)
       && (file_put_contents($wxr_file,  $zip->getFromName($wxr_entry))   !== false);

    if ($ok && $logo_entry !== null) {
        $logo_dest = $tmp_dir . basename($logo_entry);
        if (file_put_contents($logo_dest, $zip->getFromName($logo_entry)) !== false) {
            $logo_file = $logo_dest;
        }
    }

    $zip->close();

    if (!$ok) {
        _cfw_wp_rm_dir($tmp_dir);
        return ['success' => false, 'error' => 'Could not extract files from package ZIP.', 'tmp_dir' => '', 'theme_zip' => '', 'wxr_file' => '', 'logo_file' => null];
    }

    return [
        'success'   => true,
        'tmp_dir'   => $tmp_dir,
        'theme_zip' => $theme_zip,
        'wxr_file'  => $wxr_file,
        'logo_file' => $logo_file,
        'error'     => null,
    ];
}

/**
 * ZIP the ClickFuzz Connector plugin from the server's wp-plugin directory and
 * POST it to the connected WordPress site's /clickfuzz/v1/plugin/update endpoint.
 *
 * @return array ['success'=>bool, 'code'=>int, 'body'=>array|null, 'error'=>string|null]
 */
function clickfuzz_web_update_wp_plugin($website_id)
{
    $plugin_src = FCPATH . 'wp-plugin/clickfuzz-connector/';
    if (!is_dir($plugin_src)) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'Plugin source directory not found on server.'];
    }

    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'ZipArchive extension not available.'];
    }

    $tmp_zip = sys_get_temp_dir() . '/cf-plugin-' . bin2hex(random_bytes(6)) . '.zip';
    $zip     = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'code' => 0, 'body' => null, 'error' => 'Could not create plugin ZIP.'];
    }

    $src_real = realpath($plugin_src);
    $it       = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $item_real = realpath((string) $item);
        $relative  = 'clickfuzz-connector/' . ltrim(str_replace($src_real, '', $item_real), DIRECTORY_SEPARATOR);
        $relative  = str_replace('\\', '/', $relative);
        if (is_dir($item_real)) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($item_real, $relative);
        }
    }
    $zip->close();

    // Use pull model: WP fetches the ZIP from a signed ClickFuzz URL (avoids rate-limited multipart POSTs)
    $plugin_url = _cfw_wp_make_theme_download_url($tmp_zip);

    $result = clickfuzz_web_wordpress_connector_request(
        $website_id, 'POST', '/clickfuzz/v1/plugin/update',
        ['plugin_url' => $plugin_url]
    );

    @unlink($tmp_zip);
    return $result;
}
