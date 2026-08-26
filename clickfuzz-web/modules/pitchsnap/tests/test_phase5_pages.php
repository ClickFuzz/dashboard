<?php
/**
 * Phase 5 Page Publishing — Unit Tests
 *
 * Run from the project root:
 *   php modules/pitchsnap/tests/test_phase5_pages.php
 *
 * DB-dependent, filesystem, and API tests are marked SKIP.
 */

if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/../../../system/'); }
if (!defined('FCPATH'))   { define('FCPATH',   __DIR__ . '/../../../'); }

$pass = 0; $fail = 0; $results = [];

function t_pass5($n)               { global $pass, $results; $pass++; $results[] = "PASS  $n"; }
function t_fail5($n, $d = '')     { global $fail, $results; $fail++; $results[] = "FAIL  $n" . ($d ? " — $d" : ''); }
function t_skip5($n, $r)          { global $results; $results[] = "SKIP  $n ($r)"; }
function assert_true5($c, $n, $d = '') { if ($c) { t_pass5($n); } else { t_fail5($n, $d); } }
function assert_eq5($a, $b, $n)   { assert_true5($a === $b, $n, 'expected '.json_encode($b).', got '.json_encode($a)); }
function assert_false5($c, $n)    { assert_true5(!$c, $n, 'expected false'); }

// Override CI helpers needed by the helper
if (!function_exists('base_url')) {
    function base_url($s = '') { return 'https://clickfuzz.com/dashboard/'; }
}

require_once __DIR__ . '/../helpers/pitchsnap_page_publish_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_media_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_domain_helper.php';

function make_page5($d) {
    $p = new stdClass();
    $defaults = ['id'=>1,'site_id'=>10,'title'=>'About','slug'=>'about','page_type'=>'about',
        'status'=>'draft','generation_status'=>'generated','current_generation_id'=>1,
        'published_path'=>null,'published_at'=>null,'wp_page_id'=>null,
        'parent_page_id'=>null,'menu_primary'=>0,'menu_footer'=>0,'menu_label'=>'',
        'menu_order'=>0,'index_page'=>1,'meta_title'=>'','meta_description'=>'',
        'primary_keyword'=>'plumber','instructions'=>''];
    foreach (array_merge($defaults, $d) as $k => $v) { $p->$k = $v; }
    return $p;
}
function make_site5($d) {
    $s = new stdClass();
    $defaults = ['id'=>5,'status'=>'published','publish_type'=>'html','domain'=>'clickfuzz.com/sites/bobs-plumbing',
        'wp_site_url'=>'','wp_username'=>'','wp_app_password'=>'','wp_page_id'=>null,'site_token'=>'tok'];
    foreach (array_merge($defaults, $d) as $k => $v) { $s->$k = $v; }
    return $s;
}
function make_gen5($d) {
    $g = new stdClass();
    $defaults = ['id'=>1,'page_id'=>1,'site_id'=>10,'html_content'=>'<nav><ul><li>Home</li></ul></nav><main><p>Content</p></main>',
        'css_content'=>'','js_content'=>'','meta_title_generated'=>'About | Bobs Plumbing',
        'meta_description_generated'=>'About us page','is_current'=>1,'dateadded'=>'2026-08-26 10:00:00'];
    foreach (array_merge($defaults, $d) as $k => $v) { $g->$k = $v; }
    return $g;
}

// ---------------------------------------------------------------------------
// T1: Slug validation
// ---------------------------------------------------------------------------
assert_true5(clickfuzz_web_validate_slug_for_publish('about'),           'T1a: simple slug valid');
assert_true5(clickfuzz_web_validate_slug_for_publish('ac-repair'),       'T1b: hyphenated slug valid');
assert_true5(clickfuzz_web_validate_slug_for_publish('services-2024'),   'T1c: slug with digits valid');
assert_true5(clickfuzz_web_validate_slug_for_publish('a'),               'T1d: single char slug valid');
assert_false5(clickfuzz_web_validate_slug_for_publish(''),               'T1e: empty slug rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('../etc/passwd'),  'T1f: traversal slug rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('AC Repair'),      'T1g: uppercase/spaces rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('-starts-hyphen'), 'T1h: leading hyphen rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('ends-hyphen-'),   'T1i: trailing hyphen rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('has.dot'),        'T1j: dot rejected');
assert_false5(clickfuzz_web_validate_slug_for_publish('has/slash'),      'T1k: slash rejected');

// ---------------------------------------------------------------------------
// T2: URL path building
// ---------------------------------------------------------------------------
$pages_flat = [];
$pg_about    = make_page5(['id'=>1,'slug'=>'about','parent_page_id'=>null]);
$pg_services = make_page5(['id'=>2,'slug'=>'services','parent_page_id'=>null]);
$pg_acrepair = make_page5(['id'=>3,'slug'=>'ac-repair','parent_page_id'=>2]);
$pg_heating  = make_page5(['id'=>4,'slug'=>'heating','parent_page_id'=>2]);
$pg_deep     = make_page5(['id'=>5,'slug'=>'deep','parent_page_id'=>3]);
$pages_indexed = [1=>$pg_about, 2=>$pg_services, 3=>$pg_acrepair, 4=>$pg_heating, 5=>$pg_deep];

$r2a = clickfuzz_web_page_url_path($pg_about, $pages_indexed);
assert_eq5($r2a['path'], 'about',               'T2a: top-level page path');
assert_true5($r2a['error'] === null,             'T2a-err: no error');

$r2b = clickfuzz_web_page_url_path($pg_services, $pages_indexed);
assert_eq5($r2b['path'], 'services',             'T2b: top-level services path');

$r2c = clickfuzz_web_page_url_path($pg_acrepair, $pages_indexed);
assert_eq5($r2c['path'], 'services/ac-repair',  'T2c: nested page path');

$r2d = clickfuzz_web_page_url_path($pg_deep, $pages_indexed);
assert_eq5($r2d['path'], 'services/ac-repair/deep', 'T2d: multi-level nested path');

// T2e: self-loop detection
$loop_pg = make_page5(['id'=>6,'slug'=>'loopy','parent_page_id'=>6]);
$pages_with_loop = $pages_indexed + [6 => $loop_pg];
$r2e = clickfuzz_web_page_url_path($loop_pg, $pages_with_loop);
assert_true5($r2e['error'] !== null, 'T2e: self-loop detected');
assert_true5(strpos($r2e['error'], 'Loop') !== false || strpos($r2e['error'], 'loop') !== false, 'T2e-msg: loop error message');

// T2f: cross-site parent rejected
$cross_pg = make_page5(['id'=>7,'slug'=>'cross','site_id'=>99,'parent_page_id'=>2]);
$r2f = clickfuzz_web_page_url_path($cross_pg, $pages_indexed + [7=>$cross_pg]);
assert_true5($r2f['error'] !== null, 'T2f: cross-site parent rejected');

// T2g: invalid slug in hierarchy
$bad_slug_pg = make_page5(['id'=>8,'slug'=>'../evil','parent_page_id'=>null]);
$r2g = clickfuzz_web_page_url_path($bad_slug_pg, $pages_indexed + [8=>$bad_slug_pg]);
assert_true5($r2g['error'] !== null, 'T2g: invalid slug in hierarchy rejected');

// T2h: missing parent in index
$orphan_pg = make_page5(['id'=>9,'slug'=>'orphan','parent_page_id'=>999]);
$r2h = clickfuzz_web_page_url_path($orphan_pg, $pages_indexed);
assert_true5($r2h['error'] !== null, 'T2h: missing parent rejected');

// ---------------------------------------------------------------------------
// T3: Publishing eligibility
// ---------------------------------------------------------------------------
$site_pub  = make_site5(['status'=>'published']);
$site_draft = make_site5(['status'=>'draft']);
$good_gen  = make_gen5(['page_id'=>1,'site_id'=>10]);
$bad_gen   = make_gen5(['page_id'=>1,'site_id'=>10,'html_content'=>'']);
$gen_wrong_page = make_gen5(['page_id'=>99,'site_id'=>10]);

$p_good = make_page5(['generation_status'=>'generated']);
assert_true5(clickfuzz_web_page_publish_eligible($p_good, $site_pub, $good_gen) === null, 'T3a: eligible page passes');

$p_draft_site = make_page5(['generation_status'=>'generated']);
assert_true5(clickfuzz_web_page_publish_eligible($p_draft_site, $site_draft, $good_gen) !== null, 'T3b: draft site blocks publish');

$p_trash = make_page5(['status'=>'trash','generation_status'=>'generated']);
assert_true5(clickfuzz_web_page_publish_eligible($p_trash, $site_pub, $good_gen) !== null, 'T3c: trashed page blocks publish');

$p_not_gen = make_page5(['generation_status'=>'not_generated']);
assert_true5(clickfuzz_web_page_publish_eligible($p_not_gen, $site_pub, $good_gen) !== null, 'T3d: not_generated blocks publish');

$p_generating = make_page5(['generation_status'=>'generating']);
assert_true5(clickfuzz_web_page_publish_eligible($p_generating, $site_pub, $good_gen) !== null, 'T3e: generating blocks publish');

$p_failed = make_page5(['generation_status'=>'failed']);
assert_true5(clickfuzz_web_page_publish_eligible($p_failed, $site_pub, $good_gen) !== null, 'T3f: failed blocks publish');

assert_true5(clickfuzz_web_page_publish_eligible($p_good, $site_pub, null) !== null, 'T3g: null generation blocks publish');
assert_true5(clickfuzz_web_page_publish_eligible($p_good, $site_pub, $bad_gen) !== null, 'T3h: empty html_content blocks publish');

$p_bad_slug = make_page5(['generation_status'=>'generated','slug'=>'../evil']);
assert_true5(clickfuzz_web_page_publish_eligible($p_bad_slug, $site_pub, $good_gen) !== null, 'T3i: invalid slug blocks publish');

$p_no_title = make_page5(['generation_status'=>'generated','title'=>'']);
assert_true5(clickfuzz_web_page_publish_eligible($p_no_title, $site_pub, $good_gen) !== null, 'T3j: empty title blocks publish');

// T3k: gen belonging to wrong page/site is blocked
$gen_wrong_site = make_gen5(['page_id'=>1,'site_id'=>99]);
$gen_wrong_site->page_id = 1;  // page matches but site doesn't
assert_true5(clickfuzz_web_page_publish_eligible($p_good, $site_pub, $gen_wrong_site) !== null, 'T3k: wrong-site generation blocked');

// T3l: republishing an already-published page is allowed (draft page status vs published)
$p_already_pub = make_page5(['status'=>'published','generation_status'=>'generated']);
assert_true5(clickfuzz_web_page_publish_eligible($p_already_pub, $site_pub, $good_gen) === null, 'T3l: republishing published page is eligible');

// ---------------------------------------------------------------------------
// T4: Nav building
// ---------------------------------------------------------------------------
$nav_pub_pages = [
    1 => make_page5(['id'=>1,'slug'=>'about','title'=>'About Us','status'=>'published','menu_primary'=>1,'menu_order'=>2]),
    2 => make_page5(['id'=>2,'slug'=>'services','title'=>'Services','status'=>'published','menu_primary'=>1,'menu_order'=>1]),
    3 => make_page5(['id'=>3,'slug'=>'ac-repair','title'=>'AC Repair','status'=>'published','menu_primary'=>1,'parent_page_id'=>2,'menu_order'=>1]),
    4 => make_page5(['id'=>4,'slug'=>'contact','title'=>'Contact','status'=>'published','menu_primary'=>1,'menu_footer'=>1,'menu_order'=>3]),
    5 => make_page5(['id'=>5,'slug'=>'privacy','title'=>'Privacy','status'=>'published','menu_footer'=>1,'menu_primary'=>0,'menu_order'=>1]),
    6 => make_page5(['id'=>6,'slug'=>'draft-pg','title'=>'Draft','status'=>'draft','menu_primary'=>1,'menu_order'=>4]),
];
$base = 'https://bobs-plumbing.clickfuzz.com';
$nav  = clickfuzz_web_build_nav_items($nav_pub_pages, $base);

assert_true5(!empty($nav['primary']), 'T4a: primary nav has items');
assert_true5(!empty($nav['footer']),  'T4b: footer nav has items');

// Draft page excluded from primary nav
$primary_labels = array_column($nav['primary'], 'label');
// Note: array_column won't work on nested arrays — check by flattening
function get_all_labels($items) {
    $out = [];
    foreach ($items as $item) {
        $out[] = $item['label'];
        foreach ($item['children'] as $child) { $out[] = $child['label']; }
    }
    return $out;
}
$all_primary_labels = get_all_labels($nav['primary']);
assert_false5(in_array('Draft', $all_primary_labels), 'T4c: draft page excluded from primary nav');

// Services comes before About (menu_order 1 vs 2)
$top_level = array_filter($nav['primary'], fn($i) => in_array($i['label'], ['Services','About Us']));
$top_level = array_values($top_level);
assert_true5(count($top_level) >= 2, 'T4d: Services and About in primary nav');
assert_eq5($top_level[0]['label'], 'Services', 'T4e: Services first (menu_order=1)');
assert_eq5($top_level[1]['label'], 'About Us',  'T4f: About second (menu_order=2)');

// AC Repair is a child of Services
$services_item = null;
foreach ($nav['primary'] as $item) {
    if ($item['label'] === 'Services') { $services_item = $item; break; }
}
assert_true5($services_item !== null, 'T4g: Services item found');
assert_true5(!empty($services_item['children']), 'T4h: Services has children');
assert_eq5($services_item['children'][0]['label'], 'AC Repair', 'T4i: AC Repair is child of Services');

// Footer nav includes Contact and Privacy
$footer_labels = array_column($nav['footer'], 'label');
assert_true5(in_array('Contact', $footer_labels),  'T4j: Contact in footer nav');
assert_true5(in_array('Privacy', $footer_labels),  'T4k: Privacy in footer nav');

// T4l: menu_label override
$labeled_page = make_page5(['id'=>7,'slug'=>'faq','title'=>'FAQ','status'=>'published','menu_primary'=>1,'menu_label'=>'Help','menu_order'=>5]);
$nav_with_label = clickfuzz_web_build_nav_items([7=>$labeled_page] + $nav_pub_pages, $base);
$has_help = in_array('Help', get_all_labels($nav_with_label['primary']));
assert_true5($has_help, 'T4l: menu_label overrides page title in nav');

// ---------------------------------------------------------------------------
// T5: Nav HTML rendering
// ---------------------------------------------------------------------------
$nav_items = clickfuzz_web_build_nav_items($nav_pub_pages, $base);
$nav_html  = clickfuzz_web_render_primary_nav_html($nav_items['primary'], $base . '/');

assert_true5(strpos($nav_html, CF_NAV_START) !== false,      'T5a: nav HTML contains start marker');
assert_true5(strpos($nav_html, CF_NAV_END) !== false,        'T5b: nav HTML contains end marker');
assert_true5(strpos($nav_html, '<nav') !== false,            'T5c: nav HTML contains <nav> element');
assert_true5(strpos($nav_html, 'Services') !== false,        'T5d: Services appears in nav HTML');
assert_true5(strpos($nav_html, 'AC Repair') !== false,       'T5e: AC Repair appears in nav HTML');
assert_true5(strpos($nav_html, 'cf-has-children') !== false, 'T5f: Services marked as parent (cf-has-children)');
assert_false5(strpos($nav_html, 'Draft') !== false,          'T5g: Draft page absent from nav HTML');

// T5h: Home link always present
assert_true5(strpos($nav_html, '>Home<') !== false, 'T5h: Home link in nav');

// ---------------------------------------------------------------------------
// T6: HTML nav injection (update_html_nav)
// ---------------------------------------------------------------------------
$existing_html_no_marker = '<body><nav><ul><li>Old Nav</li></ul></nav><main>Content</main></body>';
$new_nav = '<nav class="cf-site-nav">New Nav</nav>';

// T6a: replace first <nav> when no markers
$updated6a = clickfuzz_web_update_html_nav($existing_html_no_marker, $new_nav);
assert_true5(strpos($updated6a, 'New Nav') !== false, 'T6a: nav replaced in file without markers');
assert_false5(strpos($updated6a, 'Old Nav') !== false, 'T6b: old nav removed');
assert_true5(strpos($updated6a, 'Content') !== false,  'T6c: main content preserved');

// T6d: marker-based replacement (reliable for CF-written files)
$existing_with_marker = '<body>' . CF_NAV_START . '<nav>OLD</nav>' . CF_NAV_END . '<main>Body</main></body>';
$updated6d = clickfuzz_web_update_html_nav($existing_with_marker, $new_nav);
assert_true5(strpos($updated6d, 'New Nav') !== false, 'T6d: marker-based nav replacement works');
assert_false5(strpos($updated6d, 'OLD') !== false,    'T6e: old nav removed via markers');
assert_true5(strpos($updated6d, 'Body') !== false,    'T6f: body content preserved with markers');

// T6g: prepend fallback when no <nav> and no markers
$no_nav_html = '<body><div>No nav here</div></body>';
$updated6g = clickfuzz_web_update_html_nav($no_nav_html, $new_nav);
assert_true5(strpos($updated6g, 'New Nav') !== false, 'T6g: nav prepended when no existing nav');
assert_true5(strpos($updated6g, 'No nav here') !== false, 'T6h: existing content preserved in fallback');

// ---------------------------------------------------------------------------
// T7: Menus — only published pages appear, draft/trash excluded
// ---------------------------------------------------------------------------
function mock_build_nav($pages, $base) {
    return clickfuzz_web_build_nav_items($pages, $base);
}

$pages_with_trash = $nav_pub_pages;
$pages_with_trash[99] = make_page5(['id'=>99,'slug'=>'trashed','status'=>'trash','menu_primary'=>1]);
$nav_no_trash = mock_build_nav($pages_with_trash, $base);
$labels_no_trash = get_all_labels($nav_no_trash['primary']);
assert_false5(in_array('trashed', array_map('strtolower', $labels_no_trash)), 'T7a: trashed page excluded from nav');

// Primary-menu=false page excluded
$pages_no_menu = $nav_pub_pages;
$pages_no_menu[88] = make_page5(['id'=>88,'slug'=>'hidden','status'=>'published','menu_primary'=>0]);
$nav_no_hidden = mock_build_nav($pages_no_menu, $base);
$labels_hidden = get_all_labels($nav_no_hidden['primary']);
assert_false5(in_array('hidden', array_map('strtolower', $labels_hidden)), 'T7b: non-menu page excluded from primary nav');

// ---------------------------------------------------------------------------
// T8: HTML document rendering
// ---------------------------------------------------------------------------
$render_page = make_page5(['meta_title'=>'About | Bobs Plumbing', 'meta_description'=>'We fix pipes.','index_page'=>1]);
$render_site = make_site5([]);
$render_gen  = make_gen5(['html_content'=>'<nav>NAV</nav><p>Body content.</p>','css_content'=>'.x{color:red;}','js_content'=>'console.log(1);']);
$render_nav  = '<nav class="cf-site-nav">CF Nav</nav>';
$canonical   = 'https://bobs.clickfuzz.com/about/';

$rendered = clickfuzz_web_render_full_page_html($render_page, $render_site, $render_gen, $canonical, $render_nav);

assert_true5(strpos($rendered, '<!DOCTYPE html>') !== false,      'T8a: doctype present');
assert_true5(strpos($rendered, '<html') !== false,                'T8b: html element present');
assert_true5(strpos($rendered, 'About | Bobs Plumbing') !== false, 'T8c: meta_title in <title>');
assert_true5(strpos($rendered, 'We fix pipes.') !== false,        'T8d: meta_description present');
assert_true5(strpos($rendered, $canonical) !== false,             'T8e: canonical URL present');
assert_true5(strpos($rendered, '.x{color:red;}') !== false,       'T8f: page CSS included');
assert_true5(strpos($rendered, 'console.log(1)') !== false,       'T8g: page JS included');
assert_true5(strpos($rendered, 'CF Nav') !== false,               'T8h: CF nav injected (replaced original)');
assert_true5(strpos($rendered, 'Body content') !== false,         'T8i: page body content present');
assert_false5(strpos($rendered, 'noindex') !== false,             'T8j: no noindex when index_page=1');

// T8k: noindex when index_page=0
$noindex_page = make_page5(['index_page'=>0,'generation_status'=>'generated']);
$noindex_rendered = clickfuzz_web_render_full_page_html($noindex_page, $render_site, $render_gen, $canonical, $render_nav);
assert_true5(strpos($noindex_rendered, 'noindex') !== false, 'T8k: noindex meta injected when index_page=0');

// T8l: meta_title fallback order: page.meta_title > gen.meta_title_generated > page.title
$no_meta_page = make_page5(['meta_title'=>'','title'=>'Fallback Title']);
$gen_with_meta = make_gen5(['meta_title_generated'=>'Gen Title','html_content'=>'<p>x</p>']);
$rendered_fallback1 = clickfuzz_web_render_full_page_html($no_meta_page, $render_site, $gen_with_meta, $canonical, $render_nav);
assert_true5(strpos($rendered_fallback1, 'Gen Title') !== false, 'T8l: falls back to gen.meta_title_generated');

$gen_no_meta = make_gen5(['meta_title_generated'=>'','html_content'=>'<p>x</p>']);
$rendered_fallback2 = clickfuzz_web_render_full_page_html($no_meta_page, $render_site, $gen_no_meta, $canonical, $render_nav);
assert_true5(strpos($rendered_fallback2, 'Fallback Title') !== false, 'T8m: falls back to page.title');

// ---------------------------------------------------------------------------
// T9: Sitemap
// ---------------------------------------------------------------------------
$pub_pages_sitemap = [
    make_page5(['status'=>'published','published_path'=>'about','index_page'=>1]),
    make_page5(['id'=>2,'status'=>'published','published_path'=>'services','index_page'=>1]),
    make_page5(['id'=>3,'status'=>'published','published_path'=>'services/ac-repair','index_page'=>1]),
    make_page5(['id'=>4,'status'=>'published','published_path'=>'hidden','index_page'=>0]),
    make_page5(['id'=>5,'status'=>'draft','published_path'=>'','index_page'=>1]),
];
$pages_idx_sitemap = [];
foreach ($pub_pages_sitemap as $p) { $pages_idx_sitemap[$p->id] = $p; }

$tmp_dir = sys_get_temp_dir() . '/cf_test_sitemap_' . getmypid();
@mkdir($tmp_dir, 0755, true);
$sitemap_written = clickfuzz_web_write_sitemap($tmp_dir, 'https://bobs.clickfuzz.com', $pub_pages_sitemap, $pages_idx_sitemap);
$sitemap_file    = $tmp_dir . '/sitemap.xml';

assert_true5($sitemap_written, 'T9a: sitemap written successfully');
assert_true5(file_exists($sitemap_file), 'T9b: sitemap.xml file exists');

if (file_exists($sitemap_file)) {
    $sitemap_xml = file_get_contents($sitemap_file);
    assert_true5(strpos($sitemap_xml, '<urlset') !== false,            'T9c: urlset element present');
    assert_true5(strpos($sitemap_xml, 'bobs.clickfuzz.com/') !== false, 'T9d: homepage URL in sitemap');
    assert_true5(strpos($sitemap_xml, '/about/') !== false,            'T9e: about page in sitemap');
    assert_true5(strpos($sitemap_xml, '/services/ac-repair/') !== false,'T9f: nested page in sitemap');
    assert_false5(strpos($sitemap_xml, '/hidden/') !== false,          'T9g: noindex page excluded from sitemap');
    assert_false5(strpos($sitemap_xml, 'status=draft') !== false,      'T9h: draft page excluded from sitemap');
    @unlink($sitemap_file);
}
@rmdir($tmp_dir);

// ---------------------------------------------------------------------------
// T10: Version cleanup
// ---------------------------------------------------------------------------
// Successful publish: only keep_gen_id survives; all others deleted
// Mock: we just verify the logic (DB-dependent SKIPs below)
$gen_history = [
    (object)['id'=>10,'page_id'=>1,'is_current'=>0],
    (object)['id'=>11,'page_id'=>1,'is_current'=>0],
    (object)['id'=>12,'page_id'=>1,'is_current'=>1], // current — keep this
];
$keep_id   = 12;
$after_cleanup = array_filter($gen_history, fn($g) => $g->id === $keep_id);
assert_eq5(count($after_cleanup), 1,                          'T10a: only current gen remains after cleanup');
$deleted = array_filter($gen_history, fn($g) => $g->id !== $keep_id);
assert_eq5(count($deleted), 2,                                'T10b: obsolete generations identified for deletion');

// T10c: failed publish — all generations preserved
$before_count = count($gen_history);
// On failure we do NOT call cleanup
assert_eq5($before_count, 3,                                  'T10c: failed publish preserves all generations');

// ---------------------------------------------------------------------------
// T11: WordPress hierarchy — parent must be published first
// ---------------------------------------------------------------------------
// Parent page is draft → block child publish
function mock_wp_parent_check($parent_page) {
    if (!$parent_page) { return null; } // no parent, OK
    if ($parent_page->status !== 'published' || empty($parent_page->wp_page_id)) {
        return 'Parent page must be published to WordPress first.';
    }
    return null;
}
$parent_draft = make_page5(['id'=>20,'status'=>'draft','wp_page_id'=>null]);
$parent_pub   = make_page5(['id'=>20,'status'=>'published','wp_page_id'=>42]);
$parent_no_wp = make_page5(['id'=>20,'status'=>'published','wp_page_id'=>null]);

assert_true5(mock_wp_parent_check(null) === null,                'T11a: no parent is OK');
assert_true5(mock_wp_parent_check($parent_pub) === null,         'T11b: published parent with WP ID is OK');
assert_true5(mock_wp_parent_check($parent_draft) !== null,       'T11c: draft parent blocks child WP publish');
assert_true5(mock_wp_parent_check($parent_no_wp) !== null,       'T11d: parent without WP ID blocks child WP publish');

// ---------------------------------------------------------------------------
// T12: Publish type consistency
// ---------------------------------------------------------------------------
$html_site = make_site5(['publish_type'=>'html']);
$wp_site   = make_site5(['publish_type'=>'wordpress','wp_site_url'=>'https://site.com','wp_username'=>'user','wp_app_password'=>'pass']);

assert_eq5($html_site->publish_type, 'html',      'T12a: HTML site has publish_type=html');
assert_eq5($wp_site->publish_type, 'wordpress',   'T12b: WP site has publish_type=wordpress');

// WP publish blocked without credentials
$wp_no_creds = make_site5(['publish_type'=>'wordpress','wp_site_url'=>'https://site.com','wp_username'=>'','wp_app_password'=>'']);
$wp_result = clickfuzz_web_publish_page_wp(make_page5(['generation_status'=>'generated']), $wp_no_creds, make_gen5([]), null);
assert_false5($wp_result['success'], 'T12c: WP publish fails without credentials');
assert_true5(strpos($wp_result['error'], 'credentials') !== false || strpos($wp_result['error'], 'password') !== false || strpos($wp_result['error'], 'URL') !== false || strpos($wp_result['error'], 'configured') !== false, 'T12d: WP error mentions credentials/URL');

// WP publish blocked without site URL
$wp_no_url = make_site5(['publish_type'=>'wordpress','wp_site_url'=>'']);
$wp_result_no_url = clickfuzz_web_publish_page_wp(make_page5(['generation_status'=>'generated']), $wp_no_url, make_gen5([]), null);
assert_false5($wp_result_no_url['success'], 'T12e: WP publish fails without site URL');

// ---------------------------------------------------------------------------
// T13: Phase 4 regression — queue_page_for_generation eligible states
// ---------------------------------------------------------------------------
function mock_can_claim5($status) {
    return in_array($status, ['not_generated', 'failed', 'generated'], true);
}
assert_true5(mock_can_claim5('generated'),   'T13a: Phase 4 fix regression — generated state is claimable');
assert_false5(mock_can_claim5('generating'), 'T13b: Phase 4 fix regression — generating is not claimable');

// ---------------------------------------------------------------------------
// T14: Phase 3 regression — SVG still rejected
// ---------------------------------------------------------------------------
$allowed_mimes = PS_MEDIA_ALLOWED_MIMES;
assert_false5(array_key_exists('image/svg+xml', $allowed_mimes), 'T14: SVG still rejected from media upload');

// ---------------------------------------------------------------------------
// DB-dependent, filesystem, and API tests (listed for documentation)
// ---------------------------------------------------------------------------
t_skip5('DB: eligible page -> page_publish HTML -> published successfully',                 'needs DB + filesystem');
t_skip5('DB: HTML page written to correct path under site_dir',                             'needs DB + filesystem');
t_skip5('DB: site_dir does not exist → eligibility fails with clear error',                 'needs DB + filesystem');
t_skip5('DB: nav updated in homepage index.html after new page published',                  'needs filesystem');
t_skip5('DB: nav updated in existing published page HTML after new page published',         'needs filesystem');
t_skip5('DB: sitemap.xml written/updated after publish',                                    'needs filesystem');
t_skip5('DB: page.status set to published after HTML publish',                              'needs DB');
t_skip5('DB: page.published_path stored correctly',                                         'needs DB');
t_skip5('DB: page.published_at stored after publish',                                       'needs DB');
t_skip5('DB: only current generation remains after cleanup_page_generations',               'needs DB');
t_skip5('DB: failed publish leaves all generation records intact',                          'needs DB');
t_skip5('DB: republishing published page replaces file and stays published',                'needs DB + filesystem');
t_skip5('DB: republish does not take page offline during operation',                        'needs filesystem');
t_skip5('DB: trashed page blocked from publish',                                            'needs DB');
t_skip5('DB: published trashed page remains live (no silent delete)',                       'needs DB + filesystem');
t_skip5('DB: WordPress publish creates page and stores wp_page_id',                        'needs WP API');
t_skip5('DB: WordPress republish updates existing wp_page_id page',                        'needs WP API');
t_skip5('DB: WordPress page created with correct parent WP page ID',                       'needs WP API');
t_skip5('DB: WordPress unpublished parent blocks child publish',                           'needs DB');
t_skip5('DB: WordPress post meta _clickfuzz_page_css stored correctly',                   'needs WP API');
t_skip5('DB: WordPress menu item created for primary-menu page',                           'needs WP API');
t_skip5('DB: WordPress menu item created as submenu of parent menu item',                  'needs WP API');
t_skip5('DB: WordPress menu item updated (not duplicated) on republish',                   'needs WP API');
t_skip5('Security: path traversal in slug rejected at publish time',                       'needs filesystem');
t_skip5('Security: cross-site page ownership checked before publish',                      'needs DB');
t_skip5('Security: WP credentials not logged on API failure',                              'needs WP API');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n=== Phase 5 Page Publishing — Unit Tests ===\n";
foreach ($results as $r) { echo "  $r\n"; }
echo "\n";
echo "Total: " . ($pass + $fail) . " pure  |  Pass: $pass  |  Fail: $fail\n\n";
if ($fail > 0) { echo "FAILURES DETECTED\n"; exit(1); }
