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
    $theme_name = 'ClickFuzz Generated - ' . $business_name;

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
    $has_footer_nav   = _cfw_wp_detect_footer_nav($parts['footer_html']);
    $footer_nav_items = $has_footer_nav ? _cfw_wp_extract_nav_items($parts['footer_html']) : [];

    // ── Build theme files ────────────────────────────────────────────────────
    $build = _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir, $has_footer_nav);
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
        'wordpress' => [
            'homepage'   => ['render_mode' => 'theme'],
            'page_modes' => ['wordpress', 'clickfuzz_generated'],
            'menus'      => array_values(array_filter([
                ['location' => 'primary', 'name' => 'Primary Menu'],
                $has_footer_nav ? ['location' => 'footer', 'name' => 'Footer Menu'] : null,
            ])),
        ],
        'generated_page_meta_keys' => [
            '_clickfuzz_generated_page' => 'marker (set to 1 for ClickFuzz-generated pages)',
            '_clickfuzz_generated_html' => 'body HTML (sections only, no html/head/body/header/footer)',
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
        'success'      => true,
        'zip_path'     => $outer_zip,
        'download_url' => $download_url,
        'site_slug'    => $site_slug,
        'theme_slug'   => $theme_slug,
        'warnings'     => $warnings,
        'error'        => null,
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

/**
 * Extract navigation links from HTML (from <nav> if present, otherwise full HTML).
 * Returns array of ['label'=>string, 'url'=>string, 'order'=>int].
 */
function _cfw_wp_extract_nav_items($html)
{
    $items = [];
    // Prefer links inside a <nav> element
    $search_html = $html;
    if (preg_match('/<nav\b[^>]*>([\s\S]*?)<\/nav>/i', $html, $nm)) {
        $search_html = $nm[1];
    }

    if (!preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $search_html, $matches, PREG_SET_ORDER)) {
        return $items;
    }

    $seen  = [];
    $order = 1;
    foreach ($matches as $m) {
        $url   = trim($m[1]);
        $label = trim(strip_tags($m[2]));
        if (!$label || !$url) continue;
        $key = strtolower($label) . '|' . $url;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = ['label' => $label, 'url' => $url, 'order' => $order++];
    }
    return $items;
}

/**
 * Return true if the footer HTML appears to contain a distinct navigation list.
 */
function _cfw_wp_detect_footer_nav($footer_html)
{
    if (preg_match('/<nav\b/i', $footer_html)) return true;
    $count = preg_match_all('/<li\b[^>]*>[\s\S]{0,300}?<a\b/i', $footer_html, $m);
    return $count >= 3;
}

/**
 * Inject wp_nav_menu() into a header HTML block, replacing the inner <nav> content
 * with a WordPress menu conditional that falls back to the static nav when no menu
 * is assigned to the 'primary' location. The <nav> tag's own attributes (class, id)
 * are preserved so site-specific JS hooks continue to work.
 */
function _cfw_wp_inject_nav_menu($header_html)
{
    // Match the first <nav ...> ... </nav> block (non-nested, single level)
    if (!preg_match('/(<nav\b[^>]*>)([\s\S]*?)(<\/nav>)/i', $header_html, $m, PREG_OFFSET_CAPTURE)) {
        return $header_html;  // No nav found — return as-is, menu registration still works
    }

    $full_match  = $m[0][0];
    $match_start = $m[0][1];
    $nav_open    = $m[1][0];  // e.g. <nav class="main-nav" id="nav">
    $nav_content = $m[2][0];  // the static ul/li/a content
    $nav_close   = $m[3][0];  // </nav>

    // Build the conditional: use WP menu if assigned, else fall back to static HTML
    $wp_menu_call = "wp_nav_menu(['theme_location'=>'primary','container'=>false,'items_wrap'=>'%3\$s','fallback_cb'=>false]);";
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
function _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir, $has_footer_nav = false)
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
    if (!empty($parts['css'])) {
        if (!_cfw_wp_write_file($theme_dir . '/assets/css/theme.css', $parts['css'])) {
            return _cfw_wp_err('Could not write assets/css/theme.css.');
        }
    }

    // ── functions.php ────────────────────────────────────────────────────────
    $functions = _cfw_wp_render_functions($theme_slug, $parts['font_links'], !empty($parts['css']), $has_footer_nav);
    if (!_cfw_wp_write_file($theme_dir . '/functions.php', $functions)) {
        return _cfw_wp_err('Could not write functions.php.');
    }

    // ── header.php ───────────────────────────────────────────────────────────
    $header_html_with_menu = _cfw_wp_inject_nav_menu($parts['header_html']);
    $header_php = _cfw_wp_render_header($header_html_with_menu);
    if (!_cfw_wp_write_file($theme_dir . '/header.php', $header_php)) {
        return _cfw_wp_err('Could not write header.php.');
    }

    // ── footer.php ───────────────────────────────────────────────────────────
    $footer_php = _cfw_wp_render_footer($parts['footer_html'], $has_footer_nav);
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

function _cfw_wp_render_footer($visual_footer_html, $has_footer_nav = false)
{
    $footer_menu = '';
    if ($has_footer_nav) {
        $footer_menu = "\n<?php if (has_nav_menu('footer')) : ?>\n"
            . "<nav class=\"footer-nav\" aria-label=\"Footer navigation\">\n"
            . "<?php wp_nav_menu(['theme_location'=>'footer','container'=>false,'items_wrap'=>'<ul>%3\$s</ul>','fallback_cb'=>false]); ?>\n"
            . "</nav>\n"
            . "<?php endif; ?>\n";
    }
    return <<<PHP
{$visual_footer_html}{$footer_menu}
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
        $xml .= _cfw_wp_wxr_menu_item($item_id, $item, 'primary-menu', 'Primary Menu', $now, $pub_date, $cd);
        $item_id++;
    }

    // ── Footer menu items ────────────────────────────────────────────────────
    $item_id = 200;
    foreach ($footer_nav_items as $item) {
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
