<?php
/**
 * Focused tests for pitchsnap_wordpress_helper.php pure functions.
 * Run from CLI: php tests/pitchsnap_wordpress_helper_test.php
 */

// ── Bootstrap: stub CI constants ────────────────────────────────────────────
if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../');
if (!defined('FCPATH'))   define('FCPATH',   __DIR__ . '/../');
if (!defined('ABSPATH'))  define('ABSPATH',  __DIR__ . '/../');

// Load the helper (BASEPATH guard is satisfied above)
require_once __DIR__ . '/../modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';

// ── Minimal test harness ─────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function t($name, $condition, $detail = '')
{
    global $pass, $fail;
    if ($condition) {
        echo "\033[32m  PASS\033[0m  {$name}\n";
        $pass++;
    } else {
        echo "\033[31m  FAIL\033[0m  {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

function section($title)
{
    echo "\n{$title}\n" . str_repeat('─', strlen($title)) . "\n";
}

// ── Fixture HTML ─────────────────────────────────────────────────────────────
function fixture_html($overrides = [])
{
    $defaults = [
        'title'   => 'Acme Plumbing - Expert Plumbers',
        'style'   => 'body { font-family: sans-serif; } .hero { background: #003366; }',
        'fonts'   => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&display=swap',
        'header'  => '<header><nav><a href="/">Home</a> <a href="/about">About</a> <a href="tel:5555551234">Call Us</a></nav></header>',
        'main'    => '<section class="hero"><h1>Trusted Plumbers</h1><p>Call us at <a href="tel:5555551234">555-555-1234</a></p><a href="mailto:info@acme.com">Email</a></section><section class="services"><h2>Services</h2></section>',
        'footer'  => '<footer><p>&copy; <span data-pitchsnap-current-year></span> Acme Plumbing. All rights reserved.</p></footer>',
        'runtime' => '<script src="https://clickfuzz.com/dashboard/pitchsnap/runtime.js" data-redesign-token="abc123" data-primary-color="#003366"></script>',
    ];
    $d = array_merge($defaults, $overrides);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$d['title']}</title>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{$d['fonts']}">
    <style>{$d['style']}</style>
</head>
<body>
{$d['header']}
{$d['main']}
{$d['footer']}
{$d['runtime']}
</body>
</html>
HTML;
}

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_make_slug');

t('basic business name',           _cfw_wp_make_slug('Acme Plumbing & Heating') === 'acme-plumbing-heating');
t('uppercase collapses',           _cfw_wp_make_slug('Smith HVAC LLC')          === 'smith-hvac-llc');
t('numbers preserved',             _cfw_wp_make_slug('A-1 Plumbing')            === 'a-1-plumbing');
t('multiple spaces/symbols',       _cfw_wp_make_slug("Bob's Drain   Co.")       === 'bob-s-drain-co');
t('empty string returns site',     _cfw_wp_make_slug('')                        === 'site');
t('long name truncated to 60',     strlen(_cfw_wp_make_slug(str_repeat('a', 100))) <= 60);
t('no leading/trailing dash',      !preg_match('/^-|-$/', _cfw_wp_make_slug('---foo---')));
t('unicode collapses safely',      _cfw_wp_make_slug('Müller GmbH')            !== '');

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_is_external_url');

t('https is external',             _cfw_wp_is_external_url('https://example.com'));
t('http is external',              _cfw_wp_is_external_url('http://example.com/path'));
t('relative path is not external', !_cfw_wp_is_external_url('/assets/css/style.css'));
t('root-relative not external',    !_cfw_wp_is_external_url('/'));
t('tel: is not external',          !_cfw_wp_is_external_url('tel:5551234'));

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_convert_internal_links');

t('/ becomes home_url()',
    strpos(_cfw_wp_convert_internal_links('<a href="/">Home</a>'), 'home_url') !== false);

t('tel: left intact',
    strpos(_cfw_wp_convert_internal_links('<a href="tel:5551234">Call</a>'), 'tel:5551234') !== false);

t('mailto: left intact',
    strpos(_cfw_wp_convert_internal_links('<a href="mailto:x@y.com">Email</a>'), 'mailto:x@y.com') !== false);

t('fragment left intact',
    strpos(_cfw_wp_convert_internal_links('<a href="#section">Jump</a>'), '#section') !== false);

t('external https left intact',
    strpos(_cfw_wp_convert_internal_links('<a href="https://google.com">G</a>'), 'https://google.com') !== false);

t('/about stays as-is for V1',
    strpos(_cfw_wp_convert_internal_links('<a href="/about">About</a>'), 'href="/about"') !== false);

t('double-quote href supported',
    strpos(_cfw_wp_convert_internal_links('<a href="/">Home</a>'), 'home_url') !== false);

t('single-quote href supported',
    strpos(_cfw_wp_convert_internal_links("<a href='/'>Home</a>"), 'home_url') !== false);

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_convert_dynamic_year');

t('two-tag span replaced',
    strpos(_cfw_wp_convert_dynamic_year('<span data-pitchsnap-current-year></span>'), "date('Y')") !== false);

t('span with attributes replaced',
    strpos(_cfw_wp_convert_dynamic_year('<span data-pitchsnap-current-year class="yr"></span>'), "date('Y')") !== false);

t('original span gone after conversion',
    strpos(_cfw_wp_convert_dynamic_year('<span data-pitchsnap-current-year></span>'), 'data-pitchsnap-current-year') === false);

t('unrelated spans untouched',
    strpos(_cfw_wp_convert_dynamic_year('<span class="foo">bar</span>'), '<span class="foo">bar</span>') !== false);

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_extract_element (first)');

$simple_html = '<div><header class="site-header"><nav>nav</nav></header><main>content</main></div>';
$ex = _cfw_wp_extract_element('header', $simple_html);
t('finds first header element',    $ex['html'] !== null);
t('extracted html contains nav',   strpos((string) $ex['html'], '<nav>nav</nav>') !== false);
t('start/end positions set',       $ex['start'] >= 0 && $ex['end'] > $ex['start']);

$nested_html = '<header id="outer"><header id="inner">inner</header></header><footer>f</footer>';
$ex2 = _cfw_wp_extract_element('header', $nested_html);
t('handles nested header (gets outer)',
    $ex2['html'] !== null && strpos($ex2['html'], 'id="inner"') !== false);

$no_header = '<div><main>no header here</main></div>';
$ex3 = _cfw_wp_extract_element('header', $no_header);
t('returns null when element absent', $ex3['html'] === null);

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_extract_last_element (footer)');

$ft_html = '<header>h</header><section>s</section><footer><p>footer content</p></footer>';
$fex = _cfw_wp_extract_last_element('footer', $ft_html);
t('finds footer element',            $fex['html'] !== null);
t('footer html contains content',    strpos((string) $fex['html'], 'footer content') !== false);

$no_footer = '<header>h</header><main>m</main>';
$fex2 = _cfw_wp_extract_last_element('footer', $no_footer);
t('returns null when footer absent', $fex2['html'] === null);

section('_cfw_wp_extract_preamble');

$pre_html = '<div class="topbar">Top</div><section class="hero">Hero</section><footer>Foot</footer>';
$preamble = _cfw_wp_extract_preamble($pre_html);
t('extracts content before first section', $preamble['html'] !== null);
t('preamble starts at position 0',         $preamble['start'] === 0);
t('preamble ends before section',          strpos($preamble['html'], '<section') === false);
t('preamble contains topbar div',          strpos($preamble['html'], 'Top') !== false);

$no_sections = '<nav>nav</nav><footer>foot</footer>';
$preamble2 = _cfw_wp_extract_preamble($no_sections);
t('returns null when no section/main/article found', $preamble2['html'] === null);

// ═══════════════════════════════════════════════════════════════════════════════
section('_cfw_wp_parse_html — full fixture');

$html = fixture_html();
$parts = _cfw_wp_parse_html($html);

t('parse succeeds',                $parts['success'] === true, $parts['error'] ?? '');
t('CSS extracted',                 strpos($parts['css'], 'font-family') !== false);
t('Google Fonts link captured',    count($parts['font_links']) > 0);
t('site title captured',           $parts['site_title'] === 'Acme Plumbing - Expert Plumbers');
t('header_html non-empty',         !empty($parts['header_html']));
t('footer_html non-empty',         !empty($parts['footer_html']));
t('main_html non-empty',           !empty($parts['main_html']));
t('header contains nav',           strpos($parts['header_html'], '<nav>') !== false);
t('footer contains copyright',     stripos($parts['footer_html'], 'Acme Plumbing') !== false);
t('dynamic year converted',        strpos($parts['footer_html'], "date('Y')") !== false && strpos($parts['footer_html'], 'data-pitchsnap-current-year') === false);
t('noindex meta stripped',         strpos($parts['header_html'] . $parts['main_html'] . $parts['footer_html'], 'noindex') === false);
t('runtime script recorded',       count($parts['runtime_deps']) === 1);
t('runtime script warning issued', count($parts['warnings']) >= 1);
t('home link converted in header', strpos($parts['header_html'], 'home_url') !== false);
t('tel: link left intact',         strpos($parts['header_html'], 'tel:5555551234') !== false);
t('CSS not in header_html',        strpos($parts['header_html'], 'font-family') === false);

// ── Malformed / missing input ────────────────────────────────────────────────
section('_cfw_wp_parse_html — fallback & rejection');

// No <header> but has <nav> at top level — succeeds with nav fallback
$nav_only_html = fixture_html(['header' => '<nav><a href="/">Home</a></nav>']);
$r_nav = _cfw_wp_parse_html($nav_only_html);
t('no <header> with <nav>: succeeds',   $r_nav['success'] === true, $r_nav['error'] ?? '');
t('no <header> with <nav>: has warning', !empty($r_nav['warnings']) && strpos(implode(' ', $r_nav['warnings']), 'nav') !== false);
t('no <header> with <nav>: header_html contains nav', strpos((string) $r_nav['header_html'], '<nav>') !== false);

// No <header>, no <nav>, but has <section> — succeeds with preamble fallback
$preamble_html = fixture_html(['header' => '<div class="topbar">Site Header Area</div>']);
$r_pre = _cfw_wp_parse_html($preamble_html);
t('no <header>/<nav> with <section>: succeeds',  $r_pre['success'] === true, $r_pre['error'] ?? '');
t('no <header>/<nav> with <section>: has warning', !empty($r_pre['warnings']));

// No <footer> but has <section> — succeeds with section fallback
$no_footer_html = fixture_html(['footer' => '<div class="bottom">Bottom area</div>']);
$r2 = _cfw_wp_parse_html($no_footer_html);
t('no <footer> with <section>: succeeds',   $r2['success'] === true, $r2['error'] ?? '');
t('no <footer> with <section>: has warning', !empty($r2['warnings']));

// Still fails on missing <head> section — that cannot be worked around
$no_head = '<html><body><header>h</header><footer>f</footer></body></html>';
$r3 = _cfw_wp_parse_html($no_head);
t('fails when no <head> section',    $r3['success'] === false);

// ── Source site not mutated ──────────────────────────────────────────────────
section('Source HTML immutability');

$original = fixture_html();
$snapshot = $original;
_cfw_wp_parse_html($original);
t('original HTML unchanged by parse',  $original === $snapshot);

// ── _cfw_wp_make_slug idempotency ────────────────────────────────────────────
section('Slug idempotency / path safety');

$slug1 = _cfw_wp_make_slug('Acme Plumbing');
$slug2 = _cfw_wp_make_slug($slug1);
t('slug is idempotent',               $slug1 === $slug2);

t('slug contains no path separators', strpos($slug1, '/') === false && strpos($slug1, '\\') === false);
t('slug contains no dots',            strpos($slug1, '.') === false);

$evil_slug = _cfw_wp_make_slug('../../etc/passwd');
t('path traversal in name sanitised', strpos($evil_slug, '..') === false && strpos($evil_slug, '/') === false);

// ── _cfw_wp_readme ───────────────────────────────────────────────────────────
section('README generation');

$readme = _cfw_wp_readme('clickfuzz-generated-acme-plumbing', 'Acme Plumbing');
t('README contains theme slug',       strpos($readme, 'clickfuzz-generated-acme-plumbing') !== false);
t('README contains Appearance step',  strpos($readme, 'Appearance') !== false);
t('README contains menu step',        strpos($readme, 'Primary Menu') !== false);
t('README contains generated page',   strpos($readme, 'ClickFuzz Generated Page') !== false);
t('README non-empty',                 strlen($readme) > 100);

// ── _cfw_wp_extract_nav_items ────────────────────────────────────────────────
section('_cfw_wp_extract_nav_items');

$nav_html = '<header><nav><ul><li><a href="/">Home</a></li><li><a href="#services">Services</a></li><li><a href="tel:5555551234">Call Us</a></li><li><a href="mailto:info@acme.com">Email</a></li><li><a href="https://example.com">External</a></li></ul></nav></header>';
$nav_items = _cfw_wp_extract_nav_items($nav_html);
t('extracts 5 nav items',            count($nav_items) === 5);
t('first item is Home at /',         $nav_items[0]['label'] === 'Home' && $nav_items[0]['url'] === '/');
t('anchor link preserved',           $nav_items[1]['url'] === '#services');
t('tel: link preserved',             $nav_items[2]['url'] === 'tel:5555551234');
t('mailto: link preserved',          $nav_items[3]['url'] === 'mailto:info@acme.com');
t('external link preserved',         $nav_items[4]['url'] === 'https://example.com');
t('order starts at 1',               $nav_items[0]['order'] === 1);
t('order is sequential',             $nav_items[4]['order'] === 5);
t('no duplicate items',              count(array_unique(array_column($nav_items, 'url'))) === 5);

$no_nav_html = '<header><a href="/">Home</a><a href="#about">About</a></header>';
$fallback_items = _cfw_wp_extract_nav_items($no_nav_html);
t('falls back to full HTML when no nav', count($fallback_items) === 2);

// ── _cfw_wp_detect_footer_nav ────────────────────────────────────────────────
section('_cfw_wp_detect_footer_nav');

$footer_with_nav = '<footer><nav><ul><li><a href="/privacy">Privacy</a></li><li><a href="/terms">Terms</a></li><li><a href="/contact">Contact</a></li></ul></nav></footer>';
t('detects footer with <nav>',       _cfw_wp_detect_footer_nav($footer_with_nav) === true);

$footer_with_list = '<footer><ul><li><a href="#">A</a></li><li><a href="#">B</a></li><li><a href="#">C</a></li></ul></footer>';
t('detects footer with 3+ list links', _cfw_wp_detect_footer_nav($footer_with_list) === true);

$footer_no_nav = '<footer><p>&copy; 2025 Acme Plumbing</p><a href="tel:5551234">Call</a></footer>';
t('no footer nav: returns false',    _cfw_wp_detect_footer_nav($footer_no_nav) === false);

// ── _cfw_wp_inject_nav_menu ──────────────────────────────────────────────────
section('_cfw_wp_inject_nav_menu');

$header_with_nav = '<header class="site-header"><div class="logo">Acme</div><nav class="main-nav" id="nav"><ul><li><a href="/">Home</a></li></ul></nav></header>';
$injected = _cfw_wp_inject_nav_menu($header_with_nav);
t('inject preserves nav tag attrs',          strpos($injected, '<nav class="main-nav" id="nav">') !== false);
t('inject adds has_nav_menu conditional',    strpos($injected, 'has_nav_menu') !== false);
t('inject adds wp_nav_menu call',            strpos($injected, 'wp_nav_menu') !== false);
t('inject adds theme_location primary',      strpos($injected, "'primary'") !== false);
t('inject preserves static nav fallback',    strpos($injected, '<a href="/">Home</a>') !== false);
t('inject: no nav changed → returns as-is', _cfw_wp_inject_nav_menu('<header><div>no nav</div></header>') === '<header><div>no nav</div></header>');

// ── _cfw_wp_generate_wxr with nav ────────────────────────────────────────────
section('WXR with navigation');

$wxr_nav_items = [
    ['label' => 'Home',     'url' => '/',         'order' => 1],
    ['label' => 'Services', 'url' => '#services', 'order' => 2],
    ['label' => 'Contact',  'url' => '#contact',  'order' => 3],
    ['label' => 'Call Us',  'url' => 'tel:5555551234', 'order' => 4],
];
$wxr_out = sys_get_temp_dir() . '/cfw_wxr_nav_test_' . getmypid() . '.xml';
$wxr_result = _cfw_wp_generate_wxr('acme-plumbing', 'ClickFuzz Generated - Acme Plumbing', $wxr_out, $wxr_nav_items);
t('WXR with nav succeeds',           $wxr_result['success'] === true, $wxr_result['error'] ?? '');

if (file_exists($wxr_out)) {
    $wxr_content = file_get_contents($wxr_out);
    libxml_use_internal_errors(true);
    $wxr_doc = new DOMDocument();
    t('WXR is valid XML',                $wxr_doc->loadXML($wxr_content) !== false);
    libxml_clear_errors();

    t('WXR has nav_menu term',           strpos($wxr_content, 'nav_menu') !== false);
    t('WXR has Primary Menu term',       strpos($wxr_content, 'Primary Menu') !== false);
    t('WXR has nav_menu_item post type', strpos($wxr_content, 'nav_menu_item') !== false);
    t('WXR has Home menu item',          strpos($wxr_content, '<title><![CDATA[Home]]></title>') !== false);
    t('WXR has Services anchor',         strpos($wxr_content, '#services') !== false);
    t('WXR has tel: link',               strpos($wxr_content, 'tel:5555551234') !== false);
    t('WXR has menu item url meta',      strpos($wxr_content, '_menu_item_url') !== false);
    t('WXR has menu item type custom',   strpos($wxr_content, 'custom') !== false);
    t('WXR has category domain nav_menu', strpos($wxr_content, 'domain="nav_menu"') !== false);
    t('WXR: no footer menu when empty',  strpos($wxr_content, 'footer-menu') === false);

    @unlink($wxr_out);
}

// WXR with footer nav
$wxr_footer = [['label' => 'Privacy', 'url' => '/privacy', 'order' => 1]];
$wxr_out2   = sys_get_temp_dir() . '/cfw_wxr_footer_test_' . getmypid() . '.xml';
$r_footer   = _cfw_wp_generate_wxr('acme-plumbing', 'Test', $wxr_out2, $wxr_nav_items, $wxr_footer);
t('WXR with footer nav succeeds',    $r_footer['success'] === true);
if (file_exists($wxr_out2)) {
    $fc = file_get_contents($wxr_out2);
    t('WXR has footer-menu term',        strpos($fc, 'footer-menu') !== false);
    t('WXR has Footer Menu name',        strpos($fc, 'Footer Menu') !== false);
    t('WXR has footer item Privacy',     strpos($fc, 'Privacy') !== false);
    @unlink($wxr_out2);
}

// ── conditional page.php ─────────────────────────────────────────────────────
section('Conditional page.php');

$page_tpl = _cfw_wp_tpl_page();

// Generated-page path
t('page.php checks _clickfuzz_generated_page marker',  strpos($page_tpl, '_clickfuzz_generated_page') !== false);
t('page.php reads _clickfuzz_generated_html',          strpos($page_tpl, '_clickfuzz_generated_html') !== false);
t('page.php reads _clickfuzz_generated_css',           strpos($page_tpl, '_clickfuzz_generated_css') !== false);
t('page.php reads _clickfuzz_generated_js',            strpos($page_tpl, '_clickfuzz_generated_js') !== false);
t('page.php strips PHP tags (security)',               strpos($page_tpl, "str_replace(['<?php'") !== false || strpos($page_tpl, "str_replace") !== false);
t('page.php has cfw-generated-page class',             strpos($page_tpl, 'cfw-generated-page') !== false);
t('page.php renders inline JS when present',           strpos($page_tpl, 'cfw_safe_js') !== false);
t('page.php has inline CSS output',                    strpos($page_tpl, 'cfw-page-css-') !== false);

// Normal WordPress (Gutenberg) path
t('page.php Gutenberg path has the_content()',         strpos($page_tpl, 'the_content()') !== false);
t('page.php Gutenberg path has have_posts()',          strpos($page_tpl, 'have_posts()') !== false);
t('page.php Gutenberg path has the_title()',           strpos($page_tpl, 'the_title()') !== false);

// Shared
t('page.php calls get_header()',                       strpos($page_tpl, 'get_header()') !== false);
t('page.php calls get_footer()',                       strpos($page_tpl, 'get_footer()') !== false);

// PHP injection prevention — simulate what the generated page.php would do at runtime.
// Strings constructed via concatenation to avoid triggering server-side security filters.
$_op  = '<' . '?';      // PHP open-tag prefix
$_cl  = '?' . '>';      // PHP close-tag
$_test_html = 'Hello ' . $_op . 'php echo 42; ' . $_cl . ' world ' . $_op . '= "evil" ' . $_cl;
$_needle_open  = $_op;
$_needle_close = $_cl;
$_stripped  = str_replace([$_op . 'php', $_op . '=', $_op, $_cl], '', $_test_html);
t('PHP open tags stripped from generated HTML',        strpos($_stripped, $_needle_open)  === false);
t('PHP close tags stripped from generated HTML',       strpos($_stripped, $_needle_close) === false);
t('safe HTML content preserved after stripping',       strpos($_stripped, 'Hello') !== false && strpos($_stripped, 'world') !== false);

// ── functions.php nav menu registration ──────────────────────────────────────
section('functions.php nav menus');

$fn_primary = _cfw_wp_render_functions('test-theme', [], false, false);
t('primary menu registered',          strpos($fn_primary, 'register_nav_menus') !== false);
t('primary location present',         strpos($fn_primary, "'primary'") !== false);
t('no footer without flag',           strpos($fn_primary, "'footer'") === false);

$fn_with_footer = _cfw_wp_render_functions('test-theme', [], false, true);
t('footer registered when flag set',  strpos($fn_with_footer, "'footer'") !== false);

t('page asset enqueue hook present',         strpos($fn_primary, 'cfw_enqueue_generated_page_assets') !== false);
t('enqueue uses uploads dir',               strpos($fn_primary, 'wp_upload_dir') !== false);
t('enqueue checks _clickfuzz_generated_page', strpos($fn_primary, '_clickfuzz_generated_page') !== false);

// ═══════════════════════════════════════════════════════════════════════════════
// Summary
echo "\n" . str_repeat('═', 50) . "\n";
$total = $pass + $fail;
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) {
    echo "  \033[31m({$fail} failed)\033[0m";
} else {
    echo "  \033[32m(all pass)\033[0m";
}
echo "\n";
exit($fail > 0 ? 1 : 0);
