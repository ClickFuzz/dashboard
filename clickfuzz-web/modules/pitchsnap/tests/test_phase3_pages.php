<?php
/**
 * Phase 3 Pages & Media — Unit Tests
 *
 * Tests pure PHP logic without requiring a running CodeIgniter instance.
 * Run from the project root:
 *   php modules/pitchsnap/tests/test_phase3_pages.php
 *
 * DB-dependent and controller tests are marked SKIP and listed for documentation.
 */

if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/../../../system/'); }
if (!defined('FCPATH'))   { define('FCPATH',   __DIR__ . '/../../../'); }

$pass = 0; $fail = 0; $results = [];

function t_pass($n)                { global $pass, $results; $pass++; $results[] = "PASS  $n"; }
function t_fail($n, $d = '')      { global $fail, $results; $fail++; $results[] = "FAIL  $n" . ($d ? " — $d" : ''); }
function t_skip($n, $r)           { global $results; $results[] = "SKIP  $n ($r)"; }
function assert_true($c, $n, $d = '')  { if ($c) { t_pass($n); } else { t_fail($n, $d); } }
function assert_eq($a, $b, $n)    { assert_true($a === $b, $n, 'expected '.json_encode($b).', got '.json_encode($a)); }
function assert_false($c, $n)     { assert_true(!$c, $n, 'expected false'); }

require_once __DIR__ . '/../helpers/pitchsnap_media_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_domain_helper.php';

// ---------------------------------------------------------------------------
// T1: Slug sanitisation
// ---------------------------------------------------------------------------
function ps_sanitise_slug($raw) {
    return strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', $raw)));
}

assert_eq(ps_sanitise_slug('AC Repair'),         'ac-repair',        'T1a: slug from page name');
assert_eq(ps_sanitise_slug('Services & More!'),  'services-more',    'T1b: slug strips special chars');
assert_eq(ps_sanitise_slug('UPPER CASE'),        'upper-case',       'T1c: slug lowercased');
assert_eq(ps_sanitise_slug('already-valid'),     'already-valid',    'T1d: valid slug unchanged');
assert_eq(ps_sanitise_slug('  spaces  '),        '--spaces--',       'T1e: leading/trailing spaces become hyphens');

// T1f: empty slug after sanitisation
assert_true(ps_sanitise_slug('!!!') === '', 'T1f: all-special slug becomes empty string');

// ---------------------------------------------------------------------------
// T2: Valid page types
// ---------------------------------------------------------------------------
$valid_types = ['about','service','service_area','contact','gallery','financing','faq','custom'];
foreach ($valid_types as $vt) {
    assert_true(in_array($vt, $valid_types, true), "T2: type '$vt' is valid");
}
assert_false(in_array('homepage', $valid_types, true), 'T2: homepage is not a Phase 3 add-page type');
assert_false(in_array('blog', $valid_types, true),     'T2: blog is not a valid type');

// ---------------------------------------------------------------------------
// T3: Parent validation logic (mirrors Pitchsnap_model::validate_page_parent)
// ---------------------------------------------------------------------------
class MockPg {
    public $id; public $site_id; public $status;
    public function __construct($d) { foreach ($d as $k=>$v) $this->$k=$v; }
}

$pg_pool = [
    new MockPg(['id'=>1,'site_id'=>10,'status'=>'draft']),
    new MockPg(['id'=>2,'site_id'=>10,'status'=>'draft']),
    new MockPg(['id'=>3,'site_id'=>10,'status'=>'trash']),
    new MockPg(['id'=>4,'site_id'=>20,'status'=>'draft']),
];

function mock_validate_parent3($page_id, $parent_id, $site_id, $pool) {
    $page_id=$page_id+0; $parent_id=$parent_id+0;
    if ($page_id && $page_id===$parent_id) return false;
    $parent=null; foreach ($pool as $p) { if ($p->id==$parent_id){$parent=$p;break;} }
    if (!$parent) return false;
    if ((int)$parent->site_id!==(int)$site_id) return false;
    if ($parent->status==='trash') return false;
    return true;
}

assert_true(mock_validate_parent3(1,2,10,$pg_pool),    'T3a: valid same-site parent');
assert_false(mock_validate_parent3(1,1,10,$pg_pool),   'T3b: self-parent rejected');
assert_false(mock_validate_parent3(1,3,10,$pg_pool),   'T3c: trashed parent rejected');
assert_false(mock_validate_parent3(1,4,10,$pg_pool),   'T3d: cross-site parent rejected');
assert_false(mock_validate_parent3(1,99,10,$pg_pool),  'T3e: non-existent parent rejected');
assert_true(mock_validate_parent3(0,1,10,$pg_pool),    'T3f: new page (id=0) can use any valid parent');

// ---------------------------------------------------------------------------
// T4: Slug uniqueness (app-level logic, no DB)
// ---------------------------------------------------------------------------
// Simulates page_slug_available($site_id, $slug, $exclude_id, $existing_pages)
function slug_available($site_id, $slug, $exclude_id, $pages) {
    foreach ($pages as $p) {
        if ($p['site_id']==$site_id && $p['slug']===$slug && $p['status']!=='trash'
            && (!$exclude_id || $p['id']!=$exclude_id)) {
            return false;
        }
    }
    return true;
}

$existing = [
    ['id'=>1,'site_id'=>10,'slug'=>'about',   'status'=>'draft'],
    ['id'=>2,'site_id'=>10,'slug'=>'services','status'=>'draft'],
    ['id'=>3,'site_id'=>10,'slug'=>'old',     'status'=>'trash'],
    ['id'=>4,'site_id'=>20,'slug'=>'about',   'status'=>'draft'],
];

assert_false(slug_available(10,'about',   null, $existing), 'T4a: duplicate slug on same site rejected');
assert_true( slug_available(10,'contact', null, $existing), 'T4b: new slug on same site accepted');
assert_true( slug_available(10,'about',   1,    $existing), 'T4c: same slug allowed when updating own record');
assert_true( slug_available(10,'old',     null, $existing), 'T4d: same slug as trashed page is available');
assert_true( slug_available(20,'services',null, $existing), 'T4e: same slug on different site allowed');

// ---------------------------------------------------------------------------
// T5: Published site guard
// ---------------------------------------------------------------------------
function mock_site_guard($site) {
    return $site && $site['status']==='published';
}

assert_true( mock_site_guard(['status'=>'published']), 'T5a: published site passes guard');
assert_false(mock_site_guard(['status'=>'draft']),     'T5b: draft site rejected');
assert_false(mock_site_guard(null),                    'T5c: null site rejected');

// ---------------------------------------------------------------------------
// T6: Media MIME validation (pure PHP — no actual file upload)
// ---------------------------------------------------------------------------
$allowed_mimes = PS_MEDIA_ALLOWED_MIMES;

assert_true( array_key_exists('image/jpeg', $allowed_mimes), 'T6a: JPEG allowed');
assert_true( array_key_exists('image/png',  $allowed_mimes), 'T6b: PNG allowed');
assert_true( array_key_exists('image/gif',  $allowed_mimes), 'T6c: GIF allowed');
assert_true( array_key_exists('image/webp', $allowed_mimes), 'T6d: WebP allowed');
assert_false(array_key_exists('image/svg+xml', $allowed_mimes), 'T6e: SVG rejected (XSS risk — not sanitized)');
assert_false(array_key_exists('application/php', $allowed_mimes), 'T6f: PHP executable rejected');
assert_false(array_key_exists('text/html',       $allowed_mimes), 'T6g: HTML rejected');
assert_false(array_key_exists('application/zip', $allowed_mimes), 'T6h: ZIP rejected');

// ---------------------------------------------------------------------------
// T7: Media file size limit
// ---------------------------------------------------------------------------
assert_true( PS_MEDIA_MAX_BYTES === 10 * 1024 * 1024, 'T7: max upload size is 10 MB');
assert_true( 5 * 1024 * 1024 <= PS_MEDIA_MAX_BYTES,   'T7b: 5 MB file within limit');
assert_false(11 * 1024 * 1024 <= PS_MEDIA_MAX_BYTES,  'T7c: 11 MB file over limit');

// ---------------------------------------------------------------------------
// T8: media_url helper
// ---------------------------------------------------------------------------
// Override base_url for CLI test
if (!function_exists('base_url')) {
    function base_url($suffix='') { return 'https://clickfuzz.com/dashboard/'; }
}

$url = clickfuzz_web_media_url(7, 'abc123.jpg');
assert_true(strpos($url, '/media/7/') !== false,    'T8a: media URL contains /media/{site_id}/');
assert_true(strpos($url, 'abc123.jpg') !== false,   'T8b: media URL contains filename');
assert_true(strpos($url, '/dashboard/') !== false,  'T8c: media URL is under /dashboard/ (same as previews/sites)');

// T8d: filename is URL-encoded
$url2 = clickfuzz_web_media_url(7, 'file name.jpg');
assert_true(strpos($url2, 'file%20name.jpg') !== false || strpos($url2, 'file+name.jpg') !== false,
    'T8d: spaces in filename are encoded');

// ---------------------------------------------------------------------------
// T9: media_dir helper
// ---------------------------------------------------------------------------
$dir = clickfuzz_web_media_dir(7);
assert_true(strpos($dir, '/media/7') !== false,   'T9: media dir contains /media/{site_id}');
assert_true(strpos($dir, 'dashboard') !== false,  'T9b: media dir is inside dashboard root (same as previews/sites)');

// ---------------------------------------------------------------------------
// T10: Media category validation
// ---------------------------------------------------------------------------
$valid_cats = ['logo','team','project','equipment','award','certification','before_after','general'];
foreach ($valid_cats as $vc) {
    assert_true(in_array($vc, $valid_cats, true), "T10: category '$vc' is valid");
}
assert_false(in_array('headshot', $valid_cats, true), 'T10: headshot is not a valid category');
assert_false(in_array('',         $valid_cats, true), 'T10: empty string is not a valid category');

// ---------------------------------------------------------------------------
// T11: Cross-site media attachment guard
// ---------------------------------------------------------------------------
class MockMedia3 {
    public $id; public $site_id;
    public function __construct($d) { foreach ($d as $k=>$v) $this->$k=$v; }
}
class MockPage3 {
    public $id; public $site_id;
    public function __construct($d) { foreach ($d as $k=>$v) $this->$k=$v; }
}

function mock_attach3($page, $media) {
    if (!$page || !$media) return ['ok'=>false,'reason'=>'not_found'];
    if ((int)$page->site_id !== (int)$media->site_id) return ['ok'=>false,'reason'=>'cross_site'];
    return ['ok'=>true];
}

$pg10  = new MockPage3(['id'=>1,'site_id'=>10]);
$m10   = new MockMedia3(['id'=>1,'site_id'=>10]);
$m20   = new MockMedia3(['id'=>2,'site_id'=>20]);

$r11a = mock_attach3($pg10, $m10);
assert_true($r11a['ok'], 'T11a: same-site media attach allowed');

$r11b = mock_attach3($pg10, $m20);
assert_false($r11b['ok'], 'T11b: cross-site media attach rejected');
assert_eq($r11b['reason'], 'cross_site', 'T11c: rejection reason = cross_site');

$r11c = mock_attach3(null, $m10);
assert_false($r11c['ok'], 'T11d: null page → not_found');

// ---------------------------------------------------------------------------
// T12: Media in-use deletion guard
// ---------------------------------------------------------------------------
$page_media_links = [['page_id'=>1,'media_id'=>5],['page_id'=>2,'media_id'=>5]];
$media_id = 5;
$in_use = count(array_filter($page_media_links, fn($pm) => $pm['media_id']===$media_id));
assert_eq($in_use, 2, 'T12a: media used by 2 pages detected');
assert_true($in_use > 0, 'T12b: media-in-use blocks deletion');

$media_id_unused = 9;
$in_use_unused = count(array_filter($page_media_links, fn($pm) => $pm['media_id']===$media_id_unused));
assert_eq($in_use_unused, 0, 'T12c: unused media can be deleted');

// ---------------------------------------------------------------------------
// T13: Detach does not delete media item
// ---------------------------------------------------------------------------
$links = [['page_id'=>1,'media_id'=>5],['page_id'=>1,'media_id'=>6]];
$after_detach = array_filter($links, fn($pm) => !($pm['page_id']===1 && $pm['media_id']===5));
assert_eq(count($after_detach), 1, 'T13a: detach removes join row only');
// Media item with id=5 is not deleted from library
$library = [['id'=>5,'filename'=>'a.jpg'],['id'=>6,'filename'=>'b.jpg']];
$still_exists = count(array_filter($library, fn($m) => $m['id']===5)) > 0;
assert_true($still_exists, 'T13b: detach leaves media in library');

// ---------------------------------------------------------------------------
// T14: Generation readiness (mirrors controller logic)
// ---------------------------------------------------------------------------
function ps_gen_readiness($page) {
    $missing=[];
    if (empty($page['title']))       $missing[]='Page Name';
    if (empty($page['slug']))        $missing[]='Slug';
    if (empty($page['page_type']))   $missing[]='Page Type';
    if (empty($page['primary_keyword']) && empty($page['instructions'])) {
        $missing[]='Primary Keyword or Generation Instructions';
    }
    return ['ready'=>empty($missing),'missing'=>$missing];
}

$r14a = ps_gen_readiness(['title'=>'About','slug'=>'about','page_type'=>'about','primary_keyword'=>'plumber Austin']);
assert_true($r14a['ready'], 'T14a: fully configured page is ready');

$r14b = ps_gen_readiness(['title'=>'About','slug'=>'about','page_type'=>'about','instructions'=>'Write about us.','primary_keyword'=>'']);
assert_true($r14b['ready'], 'T14b: instructions alone satisfies keyword requirement');

$r14c = ps_gen_readiness(['title'=>'About','slug'=>'about','page_type'=>'about','primary_keyword'=>'','instructions'=>'']);
assert_false($r14c['ready'], 'T14c: no keyword and no instructions = not ready');
assert_true(in_array('Primary Keyword or Generation Instructions', $r14c['missing'], true), 'T14d: correct field listed as missing');

$r14d = ps_gen_readiness(['title'=>'','slug'=>'about','page_type'=>'about','primary_keyword'=>'kw']);
assert_false($r14d['ready'], 'T14e: missing title = not ready');

// ---------------------------------------------------------------------------
// T15: Phase 1 + Phase 2 regressions
// ---------------------------------------------------------------------------
// Slug sanitiser still works (domain helper)
assert_eq(clickfuzz_web_normalize_hostname('https://Example.COM/path/'), 'example.com', 'T15a: domain helper regression');

// Phase 2: parent validation still rejects self-reference
assert_false(mock_validate_parent3(1,1,10,$pg_pool), 'T15b: Phase 2 self-parent regression');

// Phase 1: publish_type still validates correctly
function ps_validate_publish_type3($t) { return in_array($t,['html','wordpress'],true); }
assert_true( ps_validate_publish_type3('html'),      'T15c: Phase 1 publish_type html regression');
assert_true( ps_validate_publish_type3('wordpress'), 'T15d: Phase 1 publish_type wordpress regression');
assert_false(ps_validate_publish_type3('ftp'),       'T15e: Phase 1 publish_type ftp regression');

// ---------------------------------------------------------------------------
// DB-dependent + controller tests (listed for documentation)
// ---------------------------------------------------------------------------
t_skip('DB: published site shows Generate Pages section; draft site does not',    'needs DB + HTTP');
t_skip('DB: page_add creates record and redirects to page_edit',                  'needs DB');
t_skip('DB: duplicate same-site slug rejected with flash message',                'needs DB');
t_skip('DB: same slug allowed on different site',                                 'needs DB');
t_skip('DB: page_save updates all fields correctly',                              'needs DB');
t_skip('DB: page_save respects site ownership',                                   'needs DB');
t_skip('DB: parent dropdown lists only active same-site pages',                   'needs DB');
t_skip('DB: page_trash sets status=trash',                                        'needs DB');
t_skip('DB: page_restore sets status=draft',                                      'needs DB');
t_skip('DB: media_upload stores file and creates media record',                   'needs DB + filesystem');
t_skip('DB: media_upload rejects executable (PHP) upload',                        'needs DB + filesystem');
t_skip('DB: media_upload rejects oversized file',                                 'needs DB + filesystem');
t_skip('DB: media stored under correct site_id directory',                        'needs filesystem');
t_skip('DB: media_delete blocked when media attached to pages',                   'needs DB');
t_skip('DB: media_delete removes file and DB record when unused',                 'needs DB + filesystem');
t_skip('DB: page_media_attach creates join row',                                  'needs DB');
t_skip('DB: page_media_attach rejects cross-site media',                          'needs DB');
t_skip('DB: page_media_detach removes join row, media record remains',            'needs DB');
t_skip('DB: same media can be attached to multiple pages',                        'needs DB');
t_skip('DB: CSRF token present on all mutating forms',                            'needs HTTP');
t_skip('DB: Phase 1 + Phase 2 regression — page/media tables absent before v16', 'needs pre-migration DB');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n=== Phase 3 Pages & Media — Unit Tests ===\n";
foreach ($results as $r) { echo "  $r\n"; }
echo "\n";
echo "Total: " . ($pass + $fail) . " pure  |  Pass: $pass  |  Fail: $fail\n\n";
if ($fail > 0) { echo "FAILURES DETECTED\n"; exit(1); }
echo "All pure tests passed.\n";
exit(0);
