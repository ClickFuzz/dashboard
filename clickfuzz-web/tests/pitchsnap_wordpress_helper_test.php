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
section('_cfw_wp_parse_html — rejection');

$no_header = fixture_html(['header' => '<div>No header tag here</div>']);
$r1 = _cfw_wp_parse_html($no_header);
t('fails when no <header> element',  $r1['success'] === false);

$no_footer_html = fixture_html(['footer' => '<div>No footer tag</div>']);
$r2 = _cfw_wp_parse_html($no_footer_html);
t('fails when no <footer> element',  $r2['success'] === false);

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
t('README contains install step',     strpos($readme, 'Appearance') !== false);
t('README non-empty',                 strlen($readme) > 100);

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
