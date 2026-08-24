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

    // ── Build theme files ────────────────────────────────────────────────────
    $build = _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir);
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
    $wxr_result = _cfw_wp_generate_wxr($site_slug, $theme_name, $pkg_content . '/clickfuzz-content.xml');
    if (!$wxr_result['success']) {
        _cfw_wp_rm_dir($workspace);
        return _cfw_wp_err('WXR generation failed: ' . $wxr_result['error']);
    }

    // ── Manifest ─────────────────────────────────────────────────────────────
    $warnings = array_merge($parts['warnings'], $validation['warnings']);
    $manifest = [
        'format'               => 'clickfuzz-wordpress-export',
        'version'              => 1,
        'website_id'           => $website_id,
        'site_slug'            => $site_slug,
        'theme'                => [
            'slug'    => $theme_slug,
            'name'    => $theme_name,
            'version' => '1.0.0',
            'file'    => 'theme/' . $theme_slug . '.zip',
        ],
        'content'              => ['wxr' => 'content/clickfuzz-content.xml'],
        'assets'               => [],
        'runtime_dependencies' => $parts['runtime_deps'],
        'warnings'             => $warnings,
        'generated_at'         => date('c'),
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

    // ── Extract visual <header> ──────────────────────────────────────────────
    $header_info = _cfw_wp_extract_element('header', $body);
    if ($header_info['html'] === null) {
        return array_merge($result, ['error' => 'Could not locate <header> element in body. Cannot split theme template.']);
    }
    $result['header_html'] = $header_info['html'];
    $after_header_pos      = $header_info['end'];

    // ── Extract visual <footer> ──────────────────────────────────────────────
    $footer_info = _cfw_wp_extract_last_element('footer', $body);
    if ($footer_info['html'] === null) {
        return array_merge($result, ['error' => 'Could not locate <footer> element in body. Cannot split theme template.']);
    }
    $result['footer_html'] = $footer_info['html'];
    $footer_start_pos      = $footer_info['start'];

    // ── Main content between header and footer ───────────────────────────────
    if ($footer_start_pos <= $after_header_pos) {
        return array_merge($result, ['error' => '<footer> appears before end of <header> — unexpected HTML structure.']);
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

// ---------------------------------------------------------------------------
// Theme file generation
// ---------------------------------------------------------------------------

/**
 * Write all WordPress classic theme files into $theme_dir.
 */
function _cfw_wp_build_theme($parts, $site_slug, $theme_slug, $theme_name, $theme_dir)
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
    $functions = _cfw_wp_render_functions($theme_slug, $parts['font_links'], !empty($parts['css']));
    if (!_cfw_wp_write_file($theme_dir . '/functions.php', $functions)) {
        return _cfw_wp_err('Could not write functions.php.');
    }

    // ── header.php ───────────────────────────────────────────────────────────
    $header_php = _cfw_wp_render_header($parts['header_html']);
    if (!_cfw_wp_write_file($theme_dir . '/header.php', $header_php)) {
        return _cfw_wp_err('Could not write header.php.');
    }

    // ── footer.php ───────────────────────────────────────────────────────────
    $footer_php = _cfw_wp_render_footer($parts['footer_html']);
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

function _cfw_wp_render_functions($theme_slug, array $font_links, $has_theme_css)
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
    register_nav_menus(['primary' => __('Primary Menu', '{$theme_slug}')]);
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
<?php get_header(); ?>
<main style="max-width:760px;margin:4rem auto;padding:0 1.5rem;">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article>
        <h1 style="margin-bottom:1.5rem;"><?php the_title(); ?></h1>
        <?php the_content(); ?>
    </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
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
 * Generate a minimal valid WordPress WXR content export file.
 */
function _cfw_wp_generate_wxr($site_slug, $theme_name, $out_path)
{
    $now       = date('D, d M Y H:i:s +0000');
    $pub_date  = date('Y-m-d H:i:s');
    $safe_name = htmlspecialchars($theme_name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- generator="ClickFuzz Web WordPress Exporter/1.0" -->
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
  <channel>
    <title>{$safe_name}</title>
    <link>http://example.com</link>
    <description>Exported by ClickFuzz Web</description>
    <pubDate>{$now}</pubDate>
    <language>en-US</language>
    <wp:wxr_version>1.2</wp:wxr_version>
    <wp:base_site_url>http://example.com</wp:base_site_url>
    <wp:base_blog_url>http://example.com</wp:base_blog_url>
    <wp:author>
      <wp:author_id>1</wp:author_id>
      <wp:author_login><![CDATA[admin]]></wp:author_login>
      <wp:author_email><![CDATA[admin@example.com]]></wp:author_email>
      <wp:author_display_name><![CDATA[Admin]]></wp:author_display_name>
      <wp:author_first_name><![CDATA[]]></wp:author_first_name>
      <wp:author_last_name><![CDATA[]]></wp:author_last_name>
    </wp:author>
    <item>
      <title>Home</title>
      <link>http://example.com/home/</link>
      <pubDate>{$now}</pubDate>
      <dc:creator><![CDATA[admin]]></dc:creator>
      <guid isPermaLink="false">http://example.com/?page_id=2</guid>
      <description></description>
      <content:encoded><![CDATA[<!-- Homepage content is managed by the theme front-page.php -->]]></content:encoded>
      <excerpt:encoded><![CDATA[]]></excerpt:encoded>
      <wp:post_id>2</wp:post_id>
      <wp:post_date><![CDATA[{$pub_date}]]></wp:post_date>
      <wp:post_date_gmt><![CDATA[{$pub_date}]]></wp:post_date_gmt>
      <wp:post_modified><![CDATA[{$pub_date}]]></wp:post_modified>
      <wp:post_modified_gmt><![CDATA[{$pub_date}]]></wp:post_modified_gmt>
      <wp:comment_status><![CDATA[closed]]></wp:comment_status>
      <wp:ping_status><![CDATA[closed]]></wp:ping_status>
      <wp:post_name><![CDATA[home]]></wp:post_name>
      <wp:status><![CDATA[publish]]></wp:status>
      <wp:post_parent>0</wp:post_parent>
      <wp:menu_order>0</wp:menu_order>
      <wp:post_type><![CDATA[page]]></wp:post_type>
      <wp:post_password><![CDATA[]]></wp:post_password>
      <wp:is_sticky>0</wp:is_sticky>
    </item>
  </channel>
</rss>
XML;

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
    $required = ['style.css', 'index.php', 'functions.php', 'header.php', 'footer.php', 'front-page.php'];
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
    $header_php = file_get_contents($theme_dir . '/header.php');
    $footer_php = file_get_contents($theme_dir . '/footer.php');

    foreach (['wp_head()' => $header_php, 'wp_footer()' => $footer_php, 'body_class()' => $header_php] as $fn => $src) {
        if (stripos($src, $fn) === false) {
            return ['success' => false, 'error' => $fn . ' not found in generated template.', 'warnings' => $warnings];
        }
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

1. Log into your WordPress admin panel.
2. Go to Appearance → Themes → Add New → Upload Theme.
3. Upload the theme ZIP from /theme/{$theme_slug}.zip.
4. Activate the theme.
5. Go to Tools → Import → WordPress.
6. Install/launch WordPress Importer if prompted.
7. Import /content/clickfuzz-content.xml.
8. Select "Download and import file attachments" when prompted.
9. Go to Settings → Reading and set your Front page to a static page (Home).
10. Verify navigation and forms.

See manifest.json for a full list of exported assets and any runtime
dependencies that require attention after installation.

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
